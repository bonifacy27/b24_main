<?php
/**
 * export_company_structure.php
 * Версия: v1.0
 *
 * Экспорт структуры компании Битрикс24 без сотрудников:
 * - format=svg     -> SVG-файл
 * - format=png     -> PNG-файл
 * - format=html    -> предпросмотр в браузере
 *
 * Пример:
 * /pub/company/export_company_structure.php?format=svg
 * /pub/company/export_company_structure.php?format=png
 * /pub/company/export_company_structure.php?format=html
 */

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('NOT_CHECK_PERMISSIONS', true);


require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Loader;

if (!Loader::includeModule('iblock')) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Ошибка: не удалось подключить модуль iblock.';
    exit;
}

/**
 * Настройки отрисовки
 */
$CONFIG = [
    'nodeWidth'      => 300,
    'nodeHeight'     => 64,
    'horizontalGap'  => 46,
    'verticalGap'    => 24,
    'padding'        => 30,
    'fontSize'       => 14,
    'lineColor'      => '#8aa4c8',
    'nodeFill'       => '#f5f9ff',
    'nodeStroke'     => '#5b87c5',
    'textColor'      => '#1f2d3d',
    'rootFill'       => '#e8f1ff',
];

/**
 * Формат экспорта
 */
$format = isset($_GET['format']) ? trim((string)$_GET['format']) : 'html';
$allowedFormats = ['svg', 'png', 'html'];
if (!in_array($format, $allowedFormats, true)) {
    $format = 'html';
}

$layoutMode = isset($_GET['layout']) ? trim((string)$_GET['layout']) : 'vertical';
$allowedLayouts = ['vertical', 'horizontal', 'hybrid'];
if (!in_array($layoutMode, $allowedLayouts, true)) {
    $layoutMode = 'vertical';
}

/**
 * Получаем ID инфоблока структуры компании
 */
$iblockId = (int)\COption::GetOptionInt('intranet', 'iblock_structure', 0);
if ($iblockId <= 0) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Ошибка: не найден ID инфоблока структуры компании (опция intranet: iblock_structure).';
    exit;
}

/**
 * Читаем разделы инфоблока структуры компании
 */
$sections = [];
$rsSections = CIBlockSection::GetList(
    ['LEFT_MARGIN' => 'ASC'],
    [
        'IBLOCK_ID' => $iblockId,
        'GLOBAL_ACTIVE' => 'Y',
    ],
    false,
    [
        'ID',
        'IBLOCK_ID',
        'NAME',
        'IBLOCK_SECTION_ID',
        'DEPTH_LEVEL',
        'LEFT_MARGIN',
        'RIGHT_MARGIN',
        'SORT',
        'CODE',
        'XML_ID',
        'ACTIVE',
    ]
);

while ($section = $rsSections->Fetch()) {
    $id = (int)$section['ID'];
    $parentId = (int)$section['IBLOCK_SECTION_ID'];

    $sections[$id] = [
        'id'          => $id,
        'name'        => (string)$section['NAME'],
        'parent_id'   => $parentId > 0 ? $parentId : 0,
        'depth'       => (int)$section['DEPTH_LEVEL'],
        'left_margin' => (int)$section['LEFT_MARGIN'],
        'right_margin'=> (int)$section['RIGHT_MARGIN'],
        'sort'        => (int)$section['SORT'],
        'children'    => [],
        'x'           => 0,
        'y'           => 0,
        'level'       => 0,
    ];
}

if (empty($sections)) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Ошибка: подразделения не найдены.';
    exit;
}

/**
 * Строим дерево
 */
$rootIds = [];
foreach ($sections as $id => $node) {
    $parentId = $node['parent_id'];
    if ($parentId > 0 && isset($sections[$parentId])) {
        $sections[$parentId]['children'][] = $id;
    } else {
        $rootIds[] = $id;
    }
}

/**
 * Сортируем детей по SORT, LEFT_MARGIN, NAME
 */
foreach ($sections as $id => $node) {
    if (!empty($node['children'])) {
        usort($sections[$id]['children'], function($a, $b) use (&$sections) {
            $sa = $sections[$a]['sort'];
            $sb = $sections[$b]['sort'];
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }

            $la = $sections[$a]['left_margin'];
            $lb = $sections[$b]['left_margin'];
            if ($la !== $lb) {
                return $la <=> $lb;
            }

            return strcmp($sections[$a]['name'], $sections[$b]['name']);
        });
    }
}

/**
 * Если корней несколько — сортируем тоже
 */
usort($rootIds, function($a, $b) use (&$sections) {
    $sa = $sections[$a]['sort'];
    $sb = $sections[$b]['sort'];
    if ($sa !== $sb) {
        return $sa <=> $sb;
    }

    $la = $sections[$a]['left_margin'];
    $lb = $sections[$b]['left_margin'];
    if ($la !== $lb) {
        return $la <=> $lb;
    }

    return strcmp($sections[$a]['name'], $sections[$b]['name']);
});

/**
 * Вычисляем уровни
 */
function assignLevels(array &$sections, int $nodeId, int $level = 0): void
{
    $sections[$nodeId]['level'] = $level;
    foreach ($sections[$nodeId]['children'] as $childId) {
        assignLevels($sections, $childId, $level + 1);
    }
}

foreach ($rootIds as $rootId) {
    assignLevels($sections, $rootId, 0);
}

$maxLevel = 0;
foreach ($sections as $node) {
    $maxLevel = max($maxLevel, $node['level']);
}

$maxAvailableLevels = $maxLevel + 1;
$requestedLevels = isset($_GET['levels']) ? (int)$_GET['levels'] : $maxAvailableLevels;
$requestedLevels = max(1, min($requestedLevels, $maxAvailableLevels));

foreach ($sections as $id => $node) {
    if ($node['level'] >= $requestedLevels) {
        unset($sections[$id]);
    }
}

foreach ($sections as $id => $node) {
    $sections[$id]['children'] = array_values(array_filter(
        $node['children'],
        static function (int $childId) use ($sections): bool {
            return isset($sections[$childId]);
        }
    ));
}

$filteredRootIds = array_values(array_filter(
    $rootIds,
    static function (int $rootId) use ($sections): bool {
        return isset($sections[$rootId]);
    }
));

function layoutTreeTopDownCompact(array &$sections, int $nodeId, int &$rowIndex, array $cfg): void
{
    $nodeWidth = $cfg['nodeWidth'];
    $nodeHeight = $cfg['nodeHeight'];
    $horizontalGap = $cfg['horizontalGap'];
    $verticalGap = $cfg['verticalGap'];
    $padding = $cfg['padding'];

    $sections[$nodeId]['x'] = $padding + $sections[$nodeId]['level'] * ($horizontalGap + ($nodeWidth * 0.22));
    $sections[$nodeId]['y'] = $padding + $rowIndex * ($nodeHeight + $verticalGap);
    $rowIndex++;

    foreach ($sections[$nodeId]['children'] as $childId) {
        layoutTreeTopDownCompact($sections, $childId, $rowIndex, $cfg);
    }
}

/**
 * Горизонтальная раскладка по уровням:
 * 1-й уровень в первой строке, 2-й — под ним и т.д.
 */
function layoutTreeByLevels(array &$sections, array $rootIds, array $cfg): void
{
    $nodeWidth = $cfg['nodeWidth'];
    $nodeHeight = $cfg['nodeHeight'];
    $horizontalGap = $cfg['horizontalGap'];
    $verticalGap = $cfg['verticalGap'];
    $padding = $cfg['padding'];

    $levels = [];
    $queue = $rootIds;
    while (!empty($queue)) {
        $nodeId = array_shift($queue);
        if (!isset($sections[$nodeId])) {
            continue;
        }

        $level = $sections[$nodeId]['level'];
        if (!isset($levels[$level])) {
            $levels[$level] = [];
        }
        $levels[$level][] = $nodeId;

        foreach ($sections[$nodeId]['children'] as $childId) {
            if (isset($sections[$childId])) {
                $queue[] = $childId;
            }
        }
    }

    ksort($levels);
    $maxNodesInLevel = 0;
    foreach ($levels as $nodeIds) {
        $maxNodesInLevel = max($maxNodesInLevel, count($nodeIds));
    }
    $maxRowWidth = ($maxNodesInLevel > 0)
        ? ($maxNodesInLevel * $nodeWidth + max(0, $maxNodesInLevel - 1) * $horizontalGap)
        : $nodeWidth;

    foreach ($levels as $level => $nodeIds) {
        $rowWidth = count($nodeIds) * $nodeWidth + max(0, count($nodeIds) - 1) * $horizontalGap;
        $x = $padding + max(0, ($maxRowWidth - $rowWidth) / 2);
        $y = $padding + $level * ($nodeHeight + ($verticalGap * 2));
        foreach ($nodeIds as $nodeId) {
            $sections[$nodeId]['x'] = $x;
            $sections[$nodeId]['y'] = $y;
            $x += $nodeWidth + $horizontalGap;
        }
    }
}

/**
 * Гибридная раскладка:
 * - первые 3 уровня (0,1,2) в горизонтальном режиме по центру;
 * - уровни от 3 и глубже — вертикальные ветки под узлами 3-го уровня.
 */
function layoutTreeHybrid(array &$sections, array $rootIds, array $cfg): void
{
    $nodeWidth = $cfg['nodeWidth'];
    $nodeHeight = $cfg['nodeHeight'];
    $horizontalGap = $cfg['horizontalGap'];
    $verticalGap = $cfg['verticalGap'];
    $padding = $cfg['padding'];

    $levels = [];
    $queue = $rootIds;
    while (!empty($queue)) {
        $nodeId = array_shift($queue);
        if (!isset($sections[$nodeId])) {
            continue;
        }

        $level = $sections[$nodeId]['level'];
        if (!isset($levels[$level])) {
            $levels[$level] = [];
        }
        $levels[$level][] = $nodeId;

        foreach ($sections[$nodeId]['children'] as $childId) {
            if (isset($sections[$childId])) {
                $queue[] = $childId;
            }
        }
    }

    $horizontalLevels = [0, 1, 2];
    $maxNodesInLevel = 0;
    foreach ($horizontalLevels as $lvl) {
        $maxNodesInLevel = max($maxNodesInLevel, isset($levels[$lvl]) ? count($levels[$lvl]) : 0);
    }
    $maxRowWidth = ($maxNodesInLevel > 0)
        ? ($maxNodesInLevel * $nodeWidth + max(0, $maxNodesInLevel - 1) * $horizontalGap)
        : $nodeWidth;

    foreach ($horizontalLevels as $lvl) {
        if (empty($levels[$lvl])) {
            continue;
        }

        $rowWidth = count($levels[$lvl]) * $nodeWidth + max(0, count($levels[$lvl]) - 1) * $horizontalGap;
        $x = $padding + max(0, ($maxRowWidth - $rowWidth) / 2);
        $y = $padding + $lvl * ($nodeHeight + ($verticalGap * 2));

        foreach ($levels[$lvl] as $nodeId) {
            $sections[$nodeId]['x'] = $x;
            $sections[$nodeId]['y'] = $y;
            $x += $nodeWidth + $horizontalGap;
        }
    }

    $indentStep = $horizontalGap + ($nodeWidth * 0.22);

    $layoutSubtreeVertical = static function (int $nodeId, float $baseX, float $baseY, int &$row) use (&$sections, &$layoutSubtreeVertical, $nodeHeight, $verticalGap, $indentStep): void {
        if (!isset($sections[$nodeId])) {
            return;
        }

        $levelOffset = max(0, $sections[$nodeId]['level'] - 3);
        $sections[$nodeId]['x'] = $baseX + ($levelOffset * $indentStep);
        $sections[$nodeId]['y'] = $baseY + $row * ($nodeHeight + $verticalGap);
        $row++;

        foreach ($sections[$nodeId]['children'] as $childId) {
            $layoutSubtreeVertical($childId, $baseX, $baseY, $row);
        }
    };

    if (!empty($levels[2])) {
        foreach ($levels[2] as $level2Id) {
            if (!isset($sections[$level2Id])) {
                continue;
            }

            $baseX = $sections[$level2Id]['x'];
            $baseY = $sections[$level2Id]['y'] + $nodeHeight + ($verticalGap * 2);
            $row = 0;
            foreach ($sections[$level2Id]['children'] as $childId) {
                $layoutSubtreeVertical($childId, $baseX, $baseY, $row);
            }
        }
    } elseif (!empty($levels[1])) {
        // Если есть только 2 уровня — продолжаем вертикально от второго уровня.
        foreach ($levels[1] as $level1Id) {
            if (!isset($sections[$level1Id])) {
                continue;
            }

            $baseX = $sections[$level1Id]['x'];
            $baseY = $sections[$level1Id]['y'] + $nodeHeight + ($verticalGap * 2);
            $row = 0;
            foreach ($sections[$level1Id]['children'] as $childId) {
                $layoutSubtreeVertical($childId, $baseX, $baseY, $row);
            }
        }
    } else {
        // Для очень мелких деревьев fallback к горизонтальному режиму.
        layoutTreeByLevels($sections, $rootIds, $cfg);
    }
}

if ($layoutMode === 'horizontal') {
    layoutTreeByLevels($sections, $filteredRootIds, $CONFIG);
} elseif ($layoutMode === 'hybrid') {
    layoutTreeHybrid($sections, $filteredRootIds, $CONFIG);
} else {
    $rowIndex = 0;
    foreach ($filteredRootIds as $rootId) {
        layoutTreeTopDownCompact($sections, $rootId, $rowIndex, $CONFIG);
    }
}

/**
 * Рассчитываем размеры холста
 */
$maxX = 0;
$maxY = 0;
foreach ($sections as $node) {
    $maxX = max($maxX, $node['x'] + $CONFIG['nodeWidth']);
    $maxY = max($maxY, $node['y'] + $CONFIG['nodeHeight']);
}

$canvasWidth = (int)ceil($maxX + $CONFIG['padding']);
$canvasHeight = (int)ceil($maxY + $CONFIG['padding']);

/**
 * Безопасный XML
 */
function xml($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Безопасный текст для SVG
 */
function svgText($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Перенос длинных названий на несколько строк
 */
function splitTextToLines(string $text, int $maxLineLength = 28): array
{
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    if ($text === '') {
        return [''];
    }

    $words = preg_split('/\s+/u', $text);
    $lines = [];
    $current = '';

    foreach ($words as $word) {
        $candidate = ($current === '') ? $word : ($current . ' ' . $word);
        if (mb_strlen($candidate, 'UTF-8') <= $maxLineLength) {
            $current = $candidate;
        } else {
            if ($current !== '') {
                $lines[] = $current;
            }
            $current = $word;
        }
    }

    if ($current !== '') {
        $lines[] = $current;
    }

    return array_slice($lines, 0, 3);
}

function buildSvg(array $sections, array $cfg, int $canvasWidth, int $canvasHeight): string
{
    $svg = [];
    $svg[] = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>';
    $svg[] = '<svg xmlns="http://www.w3.org/2000/svg" width="' . (int)$canvasWidth . '" height="' . (int)$canvasHeight . '" viewBox="0 0 ' . (int)$canvasWidth . ' ' . (int)$canvasHeight . '">';
    $svg[] = '<defs>';
    $svg[] = '<style><![CDATA[
        .node { rx: 12; ry: 12; }
        .label {
            font-family: Arial, Helvetica, sans-serif;
            font-size: ' . (int)$cfg['fontSize'] . 'px;
            fill: ' . $cfg['textColor'] . ';
        }
        .line {
            stroke: ' . $cfg['lineColor'] . ';
            stroke-width: 2;
            fill: none;
        }
    ]]></style>';
    $svg[] = '</defs>';
    $svg[] = '<rect x="0" y="0" width="' . (int)$canvasWidth . '" height="' . (int)$canvasHeight . '" fill="#ffffff"/>';

    // Сначала связи
    foreach ($sections as $node) {
        $parentCenterX = $node['x'] + $cfg['nodeWidth'] / 2;
        $parentBottomY = $node['y'] + $cfg['nodeHeight'];

        foreach ($node['children'] as $childId) {
            $child = $sections[$childId];
            $childCenterX = $child['x'] + $cfg['nodeWidth'] / 2;
            $childTopY = $child['y'];

            $midY = $parentBottomY + ($childTopY - $parentBottomY) / 2;

            $path = sprintf(
                'M %.2f %.2f L %.2f %.2f L %.2f %.2f L %.2f %.2f',
                $parentCenterX, $parentBottomY,
                $parentCenterX, $midY,
                $childCenterX, $midY,
                $childCenterX, $childTopY
            );

            $svg[] = '<path class="line" d="' . $path . '"/>';
        }
    }

    // Потом узлы
    foreach ($sections as $node) {
        $fill = ($node['level'] === 0) ? $cfg['rootFill'] : $cfg['nodeFill'];

        $svg[] = '<rect class="node" x="' . (int)$node['x'] . '" y="' . (int)$node['y'] . '" width="' . (int)$cfg['nodeWidth'] . '" height="' . (int)$cfg['nodeHeight'] . '" fill="' . $fill . '" stroke="' . $cfg['nodeStroke'] . '" stroke-width="2"/>';

        $lines = splitTextToLines($node['name'], 28);
        $lineHeight = 18;
        $startY = $node['y'] + ($cfg['nodeHeight'] / 2) - ((count($lines) - 1) * $lineHeight / 2);

        foreach ($lines as $index => $line) {
            $textY = $startY + $index * $lineHeight;
            $svg[] = '<text class="label" x="' . (int)($node['x'] + $cfg['nodeWidth'] / 2) . '" y="' . (int)$textY . '" text-anchor="middle" dominant-baseline="middle">' . svgText($line) . '</text>';
        }
    }

    $svg[] = '</svg>';

    return implode("\n", $svg);
}

function convertSvgToPng(string $svg, int $canvasWidth, int $canvasHeight): ?string
{
    if (!class_exists('Imagick')) {
        return null;
    }

    try {
        $imagick = new \Imagick();
        $imagick->setBackgroundColor(new \ImagickPixel('white'));
        $imagick->readImageBlob($svg);
        $imagick->setImageFormat('png32');
        $imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
        $imagick->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
        $imagick->resizeImage($canvasWidth, $canvasHeight, \Imagick::FILTER_LANCZOS, 1, true);
        $pngBlob = $imagick->getImageBlob();
        $imagick->clear();
        $imagick->destroy();

        return $pngBlob !== false ? $pngBlob : null;
    } catch (\Throwable $e) {
        return null;
    }
}

$svg = buildSvg($sections, $CONFIG, $canvasWidth, $canvasHeight);

/**
 * Отдача результата
 */
switch ($format) {
    case 'svg':
        header('Content-Type: image/svg+xml; charset=UTF-8');
        header('Content-Disposition: attachment; filename="company_structure.svg"');
        echo $svg;
        break;

    case 'png':
        $pngBlob = convertSvgToPng($svg, $canvasWidth, $canvasHeight);
        if ($pngBlob === null) {
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Ошибка: экспорт PNG недоступен. Требуется расширение Imagick с поддержкой SVG.';
            break;
        }

        header('Content-Type: image/png');
        header('Content-Disposition: attachment; filename="company_structure.png"');
        echo $pngBlob;
        break;

    case 'html':
        header('Content-Type: text/html; charset=UTF-8');
        ?>
        <!DOCTYPE html>
        <html lang="ru">
        <head>
            <meta charset="UTF-8">
            <title>Структура компании</title>
            <style>
                body { margin: 0; padding: 20px; font-family: Arial, sans-serif; background: #f5f7fa; }
                .toolbar { margin-bottom: 15px; }
                .toolbar .controls {
                    display: inline-flex;
                    align-items: center;
                    gap: 10px;
                    margin-right: 10px;
                }
                .toolbar select, .toolbar button, .toolbar a {
                    display: inline-block;
                    padding: 8px 12px;
                    font-size: 14px;
                    border-radius: 6px;
                    border: 1px solid #c7d3e6;
                    background: #fff;
                    color: #1f2d3d;
                }
                .toolbar button, .toolbar a.download {
                    background: #2f74d0;
                    color: #fff;
                    text-decoration: none;
                    border: none;
                    cursor: pointer;
                }
                .toolbar button:hover, .toolbar a.download:hover { background: #235dae; }
                .canvas {
                    background: #fff;
                    border: 1px solid #dce3ee;
                    overflow: auto;
                    padding: 10px;
                }
            </style>
        </head>
        <body>
            <div class="toolbar">
                <form method="get" class="controls">
                    <input type="hidden" name="format" value="html">
                    <label for="levels">Количество уровней:</label>
                    <select name="levels" id="levels">
                        <?php for ($level = 1; $level <= $maxAvailableLevels; $level++): ?>
                            <option value="<?php echo (int)$level; ?>" <?php echo $level === $requestedLevels ? 'selected' : ''; ?>>
                                <?php echo (int)$level; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                    <label for="layout">Отображение:</label>
                    <select name="layout" id="layout">
                        <option value="vertical" <?php echo $layoutMode === 'vertical' ? 'selected' : ''; ?>>Вертикально</option>
                        <option value="horizontal" <?php echo $layoutMode === 'horizontal' ? 'selected' : ''; ?>>Горизонтально по уровням</option>
                        <option value="hybrid" <?php echo $layoutMode === 'hybrid' ? 'selected' : ''; ?>>Гибрид (3 уровня + вертикально)</option>
                    </select>
                    <button type="submit">Показать</button>
                </form>
                <a class="download" href="?format=svg&amp;levels=<?php echo (int)$requestedLevels; ?>&amp;layout=<?php echo urlencode($layoutMode); ?>">Скачать SVG</a>
                <a class="download" href="?format=png&amp;levels=<?php echo (int)$requestedLevels; ?>&amp;layout=<?php echo urlencode($layoutMode); ?>">Скачать PNG</a>
            </div>
            <div class="canvas">
                <?php echo $svg; ?>
            </div>
        </body>
        </html>
        <?php
        break;

    default:
        header('Content-Type: image/svg+xml; charset=UTF-8');
        header('Content-Disposition: attachment; filename="company_structure.svg"');
        echo $svg;
        break;
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php');
