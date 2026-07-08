<?php
/**
 * compare_list42_elements.php
 *
 * Диагностика отличий двух элементов списка 42, когда права совпадают,
 * но один элемент виден модулю учета рабочего времени, а другой нет.
 * По умолчанию сравнивает рабочий элемент 3619762 и проблемный 3613530.
 *
 * Примеры:
 *   php -f compare_list42_elements.php
 *   php -f compare_list42_elements.php -- --left=3619762 --right=3613530
 *   /compare_list42_elements.php?left=3619762&right=3613530
 */

declare(strict_types=1);

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('NOT_CHECK_PERMISSIONS', true);

if (PHP_SAPI === 'cli' && empty($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = __DIR__;
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use Bitrix\Main\Loader;

const COMPARE_IBLOCK_ID = 42;
const DEFAULT_LEFT_ELEMENT_ID = 3619762;
const DEFAULT_RIGHT_ELEMENT_ID = 3613530;

function diagOption(string $name, ?string $default = null): ?string
{
    if (PHP_SAPI === 'cli') {
        foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
            if (strpos($arg, '--' . $name . '=') === 0) {
                return substr($arg, strlen($name) + 3);
            }
        }
        return $default;
    }

    return isset($_REQUEST[$name]) ? (string)$_REQUEST[$name] : $default;
}

function diagOut(string $message = ''): void
{
    echo $message . PHP_EOL;
}

function normalizeDiagnosticValue($value)
{
    if ($value instanceof \Bitrix\Main\Type\Date || $value instanceof \Bitrix\Main\Type\DateTime) {
        return $value->toString();
    }
    if ($value instanceof \DateTimeInterface) {
        return $value->format('Y-m-d H:i:s');
    }
    if (is_array($value)) {
        $result = [];
        foreach ($value as $key => $item) {
            $result[$key] = normalizeDiagnosticValue($item);
        }
        ksort($result);
        return $result;
    }

    return trim((string)$value);
}

function valueToString($value): string
{
    $normalized = normalizeDiagnosticValue($value);
    if (is_array($normalized)) {
        $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded === false ? '' : $encoded;
    }

    return (string)$normalized;
}

function shortValue($value): string
{
    $text = valueToString($value);
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim((string)$text);

    return mb_strlen($text) > 500 ? mb_substr($text, 0, 500) . '…' : $text;
}

function rightsForCompare(int $iblockId, int $elementId): array
{
    $rightsObject = new \CIBlockElementRights($iblockId, $elementId);
    $rights = [];

    foreach ($rightsObject->GetRights() as $right) {
        if (!is_array($right)) { continue; }
        $groupCode = trim((string)($right['GROUP_CODE'] ?? ''));
        $taskId = (int)($right['TASK_ID'] ?? 0);
        if ($groupCode === '' || $taskId <= 0) { continue; }
        $rights[$groupCode . ':' . $taskId] = [
            'GROUP_CODE' => $groupCode,
            'TASK_ID' => $taskId,
        ];
    }

    ksort($rights);
    return $rights;
}

function loadElementSnapshot(int $elementId): array
{
    $element = \CIBlockElement::GetList(
        [],
        ['IBLOCK_ID' => COMPARE_IBLOCK_ID, 'ID' => $elementId],
        false,
        false,
        ['*']
    )->Fetch();

    if (!$element) {
        throw new \RuntimeException('Элемент ' . $elementId . ' не найден в списке ' . COMPARE_IBLOCK_ID . '.');
    }

    $importantFields = [
        'ID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID', 'NAME', 'ACTIVE', 'ACTIVE_FROM', 'ACTIVE_TO',
        'DATE_CREATE', 'TIMESTAMP_X', 'CREATED_BY', 'MODIFIED_BY', 'WF_STATUS_ID', 'WF_PARENT_ELEMENT_ID',
        'BP_PUBLISHED', 'SORT', 'CODE', 'XML_ID', 'TAGS', 'PREVIEW_TEXT', 'DETAIL_TEXT',
    ];

    $fields = [];
    foreach ($importantFields as $fieldName) {
        if (array_key_exists($fieldName, $element)) {
            $fields[$fieldName] = normalizeDiagnosticValue($element[$fieldName]);
        }
    }

    $sections = [];
    $sectionRows = \CIBlockElement::GetElementGroups($elementId, true, ['ID', 'NAME', 'IBLOCK_SECTION_ID']);
    while ($section = $sectionRows->Fetch()) {
        $sections[(string)$section['ID']] = trim((string)$section['NAME']);
    }
    ksort($sections);

    $properties = [];
    $propertyRows = \CIBlockElement::GetProperty(COMPARE_IBLOCK_ID, $elementId, ['SORT' => 'ASC', 'ID' => 'ASC']);
    while ($property = $propertyRows->Fetch()) {
        $code = trim((string)$property['CODE']);
        $key = $code !== '' ? $code : 'PROPERTY_' . (int)$property['ID'];
        $value = normalizeDiagnosticValue($property['VALUE']);
        $description = normalizeDiagnosticValue($property['DESCRIPTION']);
        if (!isset($properties[$key])) {
            $properties[$key] = [
                'ID' => (int)$property['ID'],
                'CODE' => $code,
                'NAME' => (string)$property['NAME'],
                'TYPE' => (string)$property['PROPERTY_TYPE'],
                'USER_TYPE' => (string)$property['USER_TYPE'],
                'MULTIPLE' => (string)$property['MULTIPLE'],
                'VALUE' => [],
                'DESCRIPTION' => [],
            ];
        }
        if ($value !== '') { $properties[$key]['VALUE'][] = $value; }
        if ($description !== '') { $properties[$key]['DESCRIPTION'][] = $description; }
    }

    foreach ($properties as &$property) {
        $property['VALUE'] = normalizeDiagnosticValue($property['VALUE']);
        $property['DESCRIPTION'] = normalizeDiagnosticValue($property['DESCRIPTION']);
    }
    unset($property);

    return [
        'FIELDS' => $fields,
        'SECTIONS' => $sections,
        'PROPERTIES' => $properties,
        'RIGHTS' => rightsForCompare(COMPARE_IBLOCK_ID, $elementId),
    ];
}

function printDiffBlock(string $title, array $left, array $right, int $leftId, int $rightId): void
{
    diagOut('');
    diagOut('== ' . $title . ' ==');

    $keys = array_unique(array_merge(array_keys($left), array_keys($right)));
    sort($keys, SORT_NATURAL | SORT_FLAG_CASE);
    $diffCount = 0;

    foreach ($keys as $key) {
        $leftExists = array_key_exists($key, $left);
        $rightExists = array_key_exists($key, $right);
        $leftValue = $leftExists ? normalizeDiagnosticValue($left[$key]) : null;
        $rightValue = $rightExists ? normalizeDiagnosticValue($right[$key]) : null;
        if ($leftExists && $rightExists && valueToString($leftValue) === valueToString($rightValue)) {
            continue;
        }

        $diffCount++;
        diagOut('[' . $key . ']');
        diagOut('  ' . $leftId . ': ' . ($leftExists ? shortValue($leftValue) : '<нет>'));
        diagOut('  ' . $rightId . ': ' . ($rightExists ? shortValue($rightValue) : '<нет>'));
    }

    if ($diffCount === 0) {
        diagOut('Отличий не найдено.');
    }
}

header('Content-Type: text/plain; charset=UTF-8');

if (!Loader::includeModule('iblock')) {
    diagOut('Ошибка: не удалось подключить модуль iblock.');
    exit(1);
}

$leftId = max(1, (int)diagOption('left', (string)DEFAULT_LEFT_ELEMENT_ID));
$rightId = max(1, (int)diagOption('right', (string)DEFAULT_RIGHT_ELEMENT_ID));

try {
    $left = loadElementSnapshot($leftId);
    $right = loadElementSnapshot($rightId);
} catch (\Throwable $exception) {
    diagOut('Ошибка: ' . $exception->getMessage());
    exit(1);
}

diagOut('Сравнение элементов списка ' . COMPARE_IBLOCK_ID . ': ' . $leftId . ' vs ' . $rightId);
diagOut('Если права совпадают, ищите отличия в ACTIVE/BP_PUBLISHED/WF_STATUS_ID, разделах и свойствах START_DATE/пользователь/статус.');

printDiffBlock('Поля элемента', $left['FIELDS'], $right['FIELDS'], $leftId, $rightId);
printDiffBlock('Разделы', $left['SECTIONS'], $right['SECTIONS'], $leftId, $rightId);
printDiffBlock('Свойства', $left['PROPERTIES'], $right['PROPERTIES'], $leftId, $rightId);
printDiffBlock('Права', $left['RIGHTS'], $right['RIGHTS'], $leftId, $rightId);
