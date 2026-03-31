<?php
/**
 * export_company_structure.php
 * Версия: v1.0
 *
 * Экспорт структуры компании Битрикс24 без сотрудников:
 * - format=drawio  -> файл .drawio (совместим с draw.io / diagrams.net)
 * - format=svg     -> SVG-файл
 * - format=html    -> предпросмотр в браузере
 *
 * Пример:
 * /pub/company/export_company_structure.php?format=drawio
 * /pub/company/export_company_structure.php?format=svg
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
    'nodeWidth'      => 260,
    'nodeHeight'     => 70,
    'horizontalGap'  => 40,
    'verticalGap'    => 90,
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
$format = isset($_GET['format']) ? trim((string)$_GET['format']) : 'drawio';
$allowedFormats = ['drawio', 'svg', 'html'];
if (!in_array($format, $allowedFormats, true)) {
    $format = 'drawio';
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
        'subtree_w'   => 0,
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

/**
 * Вычисляем ширину поддерева в "ячейках"
 */
function calcSubtreeWidth(array &$sections, int $nodeId): int
{
    $children = $sections[$nodeId]['children'];
    if (empty($children)) {
        $sections[$nodeId]['subtree_w'] = 1;
        return 1;
    }

    $sum = 0;
    foreach ($children as $childId) {
        $sum += calcSubtreeWidth($sections, $childId);
    }

    $sections[$nodeId]['subtree_w'] = max(1, $sum);
    return $sections[$nodeId]['subtree_w'];
}

foreach ($rootIds as $rootId) {
    calcSubtreeWidth($sections, $rootId);
}

/**
 * Расстановка узлов по координатам
 */
function layoutTree(array &$sections, int $nodeId, float $leftCell, array $cfg): void
{
    $nodeWidth = $cfg['nodeWidth'];
    $nodeHeight = $cfg['nodeHeight'];
    $horizontalGap = $cfg['horizontalGap'];
    $verticalGap = $cfg['verticalGap'];
    $padding = $cfg['padding'];

    $subtreeWidthCells = $sections[$nodeId]['subtree_w'];
    $totalWidthPx = $subtreeWidthCells * $nodeWidth + max(0, $subtreeWidthCells - 1) * $horizontalGap;

    $sections[$nodeId]['x'] = $padding + $leftCell + ($totalWidthPx - $nodeWidth) / 2;
    $sections[$nodeId]['y'] = $padding + $sections[$nodeId]['level'] * ($nodeHeight + $verticalGap);

    $currentLeft = $leftCell;
    foreach ($sections[$nodeId]['children'] as $childId) {
        $childCells = $sections[$childId]['subtree_w'];
        $childWidthPx = $childCells * $nodeWidth + max(0, $childCells - 1) * $horizontalGap;

        layoutTree($sections, $childId, $currentLeft, $cfg);
        $currentLeft += $childWidthPx + $horizontalGap;
    }
}

$currentLeft = 0;
foreach ($rootIds as $rootId) {
    $rootCells = $sections[$rootId]['subtree_w'];
    $rootWidthPx = $rootCells * $CONFIG['nodeWidth'] + max(0, $rootCells - 1) * $CONFIG['horizontalGap'];

    layoutTree($sections, $rootId, $currentLeft, $CONFIG);
    $currentLeft += $rootWidthPx + $CONFIG['horizontalGap'];
}

/**
 * Рассчитываем размеры холста
 */
$maxX = 0;
$maxY = 0;
$maxLevel = 0;
foreach ($sections as $node) {
    $maxX = max($maxX, $node['x'] + $CONFIG['nodeWidth']);
    $maxY = max($maxY, $node['y'] + $CONFIG['nodeHeight']);
    $maxLevel = max($maxLevel, $node['level']);
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

/**
 * Генерация draw.io XML
 */
function buildDrawioXml(array $sections, array $rootIds, array $cfg, int $canvasWidth, int $canvasHeight): string
{
    $cells = [];

    // Базовые ячейки
    $cells[] = '<mxCell id="0"/>';
    $cells[] = '<mxCell id="1" parent="0"/>';

    // Узлы
    foreach ($sections as $node) {
        $cellId = 'node_' . $node['id'];

        $fill = ($node['level'] === 0) ? $cfg['rootFill'] : $cfg['nodeFill'];

        $style = implode(';', [
            'rounded=1',
            'whiteSpace=wrap',
            'html=1',
            'fontSize=' . (int)$cfg['fontSize'],
            'fontColor=' . ltrim($cfg['textColor'], '#'),
            'strokeColor=' . ltrim($cfg['nodeStroke'], '#'),
            'fillColor=' . ltrim($fill, '#'),
            'align=center',
            'verticalAlign=middle',
            'spacing=8',
        ]) . ';';

        $cells[] =
            '<mxCell id="' . xml($cellId) . '" value="' . xml($node['name']) . '" style="' . xml($style) . '" vertex="1" parent="1">' .
                '<mxGeometry x="' . (int)$node['x'] . '" y="' . (int)$node['y'] . '" width="' . (int)$cfg['nodeWidth'] . '" height="' . (int)$cfg['nodeHeight'] . '" as="geometry"/>' .
            '</mxCell>';
    }

    // Связи
    foreach ($sections as $node) {
        foreach ($node['children'] as $childId) {
            $edgeId = 'edge_' . $node['id'] . '_' . $childId;
            $style = implode(';', [
                'edgeStyle=orthogonalEdgeStyle',
                'rounded=0',
                'orthogonalLoop=1',
                'jettySize=auto',
                'html=1',
                'strokeColor=' . ltrim($cfg['lineColor'], '#'),
                'endArrow=none',
                'startArrow=none',
            ]) . ';';

            $cells[] =
                '<mxCell id="' . xml($edgeId) . '" style="' . xml($style) . '" edge="1" parent="1" source="node_' . (int)$node['id'] . '" target="node_' . (int)$childId . '">' .
                    '<mxGeometry relative="1" as="geometry"/>' .
                '</mxCell>';
        }
    }

    $xml =
        '<?xml version="1.0" encoding="UTF-8"?>' .
        '<mxfile host="app.diagrams.net" modified="' . date('c') . '" agent="Bitrix24 export_company_structure.php v1.0" version="24.7.17" type="device">' .
            '<diagram id="company-structure" name="Company Structure">' .
                '<mxGraphModel dx="1600" dy="900" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="' . (int)$canvasWidth . '" pageHeight="' . (int)$canvasHeight . '" math="0" shadow="0">' .
                    '<root>' . implode('', $cells) . '</root>' .
                '</mxGraphModel>' .
            '</diagram>' .
        '</mxfile>';

    return $xml;
}

/**
 * Генерация SVG
 */
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

$drawioXml = buildDrawioXml($sections, $rootIds, $CONFIG, $canvasWidth, $canvasHeight);
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
                .toolbar a {
                    display: inline-block;
                    margin-right: 10px;
                    padding: 8px 12px;
                    background: #2f74d0;
                    color: #fff;
                    text-decoration: none;
                    border-radius: 6px;
                }
                .toolbar a:hover { background: #235dae; }
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
                <a href="?format=drawio">Скачать draw.io</a>
                <a href="?format=svg">Скачать SVG</a>
            </div>
            <div class="canvas">
                <?php echo $svg; ?>
            </div>
        </body>
        </html>
        <?php
        break;

    case 'drawio':
    default:
        header('Content-Type: application/xml; charset=UTF-8');
        header('Content-Disposition: attachment; filename="company_structure.drawio"');
        echo $drawioXml;
        break;
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php');