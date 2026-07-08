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

function userFieldEntityIds(int $iblockId): array
{
    return [
        'IBLOCK_' . $iblockId . '_ELEMENT',
        'IBLOCK_' . $iblockId . '_SECTION',
        'IBLOCK_' . $iblockId,
    ];
}

function userFieldLabel(array $field): string
{
    foreach (['EDIT_FORM_LABEL', 'LIST_COLUMN_LABEL', 'LIST_FILTER_LABEL'] as $labelKey) {
        if (is_array($field[$labelKey] ?? null)) {
            $label = trim((string)($field[$labelKey][LANGUAGE_ID] ?? reset($field[$labelKey]) ?: ''));
            if ($label !== '') { return $label; }
        } else {
            $label = trim((string)($field[$labelKey] ?? ''));
            if ($label !== '') { return $label; }
        }
    }

    return '';
}

function loadUserFieldsForEntity(string $entityId, int $valueId): array
{
    global $USER_FIELD_MANAGER;

    if (!is_object($USER_FIELD_MANAGER) || !method_exists($USER_FIELD_MANAGER, 'GetUserFields')) {
        return [];
    }

    $rows = $USER_FIELD_MANAGER->GetUserFields($entityId, $valueId, defined('LANGUAGE_ID') ? LANGUAGE_ID : false);
    if (!is_array($rows)) {
        return [];
    }

    $fields = [];
    foreach ($rows as $name => $field) {
        if (strpos((string)$name, 'UF_') !== 0 || !is_array($field)) { continue; }
        $fields[$name] = [
            'ENTITY_ID' => $entityId,
            'ID' => (int)($field['ID'] ?? 0),
            'NAME' => userFieldLabel($field),
            'USER_TYPE_ID' => (string)($field['USER_TYPE_ID'] ?? ''),
            'VALUE' => normalizeDiagnosticValue($field['VALUE'] ?? ''),
        ];
    }

    ksort($fields, SORT_NATURAL | SORT_FLAG_CASE);
    return $fields;
}

function loadElementUserFields(int $iblockId, int $elementId): array
{
    $result = [];
    foreach (userFieldEntityIds($iblockId) as $entityId) {
        $fields = loadUserFieldsForEntity($entityId, $elementId);
        if ($fields === []) { continue; }
        foreach ($fields as $name => $field) {
            $result[$entityId . ':' . $name] = $field;
        }
    }

    ksort($result, SORT_NATURAL | SORT_FLAG_CASE);
    return $result;
}

function extractUserIdsFromPropertyValue($value): array
{
    $ids = [];
    foreach ((array)$value as $item) {
        if (is_array($item)) { continue; }
        if (preg_match_all('/(?:user_)?(\d+)/i', (string)$item, $matches)) {
            foreach ($matches[1] as $match) {
                $id = (int)$match;
                if ($id > 0) { $ids[$id] = true; }
            }
        }
    }

    $ids = array_keys($ids);
    sort($ids, SORT_NUMERIC);
    return $ids;
}

function isUserLikeProperty(array $property): bool
{
    $userType = mb_strtolower((string)($property['USER_TYPE'] ?? ''));
    $type = mb_strtolower((string)($property['TYPE'] ?? ''));
    $code = mb_strtolower((string)($property['CODE'] ?? ''));
    $name = mb_strtolower((string)($property['NAME'] ?? ''));
    $haystack = $code . ' ' . $name;

    if (in_array($userType, ['userid', 'user', 'employee', 'employee_auto'], true)) {
        return true;
    }

    if ($userType !== '' && (strpos($userType, 'user') !== false || strpos($userType, 'employee') !== false)) {
        return true;
    }

    if ($type === 's' && preg_match('/(user|employee|польз|сотруд|работник|инициатор|заявител)/u', $haystack)) {
        return true;
    }

    return false;
}

function collectLinkedUserIds(array $properties): array
{
    $ids = [];
    foreach ($properties as $property) {
        if (!isUserLikeProperty($property)) { continue; }
        foreach (extractUserIdsFromPropertyValue($property['VALUE'] ?? []) as $id) {
            $ids[$id] = true;
        }
    }

    $ids = array_keys($ids);
    sort($ids, SORT_NUMERIC);
    return $ids;
}

function userUfFieldNames(): array
{
    $names = [];
    $rows = \CUserTypeEntity::GetList(['FIELD_NAME' => 'ASC'], ['ENTITY_ID' => 'USER']);
    while ($row = $rows->Fetch()) {
        $fieldName = trim((string)($row['FIELD_NAME'] ?? ''));
        if (strpos($fieldName, 'UF_') === 0) { $names[] = $fieldName; }
    }

    return array_values(array_unique($names));
}

function loadLinkedUsers(array $userIds): array
{
    if ($userIds === []) { return []; }

    $ufNames = userUfFieldNames();
    $fields = ['ID', 'ACTIVE', 'LOGIN', 'EMAIL', 'NAME', 'LAST_NAME', 'SECOND_NAME'];
    $result = [];
    $rows = \CUser::GetList(
        $by = 'id',
        $order = 'asc',
        ['ID' => implode('|', $userIds)],
        ['FIELDS' => $fields, 'SELECT' => $ufNames]
    );
    while ($user = $rows->Fetch()) {
        $userId = (int)$user['ID'];
        $snapshot = [];
        foreach (array_merge($fields, $ufNames) as $fieldName) {
            if (array_key_exists($fieldName, $user)) {
                $snapshot[$fieldName] = normalizeDiagnosticValue($user[$fieldName]);
            }
        }
        $result[(string)$userId] = $snapshot;
    }

    ksort($result, SORT_NUMERIC);
    return $result;
}

function loadUserPropertySnapshots(array $properties): array
{
    $result = [];
    foreach ($properties as $key => $property) {
        if (!isUserLikeProperty($property)) { continue; }
        $userIds = extractUserIdsFromPropertyValue($property['VALUE'] ?? []);
        $result[$key] = [
            'ID' => (int)($property['ID'] ?? 0),
            'CODE' => (string)($property['CODE'] ?? ''),
            'NAME' => (string)($property['NAME'] ?? ''),
            'TYPE' => (string)($property['TYPE'] ?? ''),
            'USER_TYPE' => (string)($property['USER_TYPE'] ?? ''),
            'RAW_VALUE' => normalizeDiagnosticValue($property['VALUE'] ?? []),
            'USER_IDS' => $userIds,
            'USERS' => loadLinkedUsers($userIds),
        ];
    }

    ksort($result, SORT_NATURAL | SORT_FLAG_CASE);
    return $result;
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

function loadRawPropertyStorageFields(int $iblockId, int $elementId): array
{
    $connection = \Bitrix\Main\Application::getConnection();
    $sqlHelper = $connection->getSqlHelper();
    $result = [];

    $singleTable = 'b_iblock_element_prop_s' . $iblockId;
    try {
        if ($connection->isTableExists($singleTable)) {
            $row = $connection->query(
                'SELECT * FROM ' . $sqlHelper->quote($singleTable) . ' WHERE IBLOCK_ELEMENT_ID = ' . $elementId
            )->fetch();
            if (is_array($row)) {
                foreach ($row as $column => $value) {
                    if (strpos((string)$column, 'PROPERTY_') !== 0) { continue; }
                    $result['S_TABLE.' . $column] = normalizeDiagnosticValue($value);
                }
            }
        }
    } catch (\Throwable $exception) {
        $result['S_TABLE.ERROR'] = $exception->getMessage();
    }

    $multiTable = 'b_iblock_element_prop_m' . $iblockId;
    try {
        if ($connection->isTableExists($multiTable)) {
            $rows = $connection->query(
                'SELECT * FROM ' . $sqlHelper->quote($multiTable) . ' WHERE IBLOCK_ELEMENT_ID = ' . $elementId . ' ORDER BY IBLOCK_PROPERTY_ID, ID'
            );
            while ($row = $rows->fetch()) {
                $propertyId = (int)($row['IBLOCK_PROPERTY_ID'] ?? 0);
                $key = 'M_TABLE.PROPERTY_' . $propertyId;
                if (!isset($result[$key])) { $result[$key] = []; }
                $result[$key][] = normalizeDiagnosticValue($row);
            }
        }
    } catch (\Throwable $exception) {
        $result['M_TABLE.ERROR'] = $exception->getMessage();
    }

    $commonTable = 'b_iblock_element_property';
    try {
        if ($connection->isTableExists($commonTable)) {
            $rows = $connection->query(
                'SELECT * FROM ' . $sqlHelper->quote($commonTable) . ' WHERE IBLOCK_ELEMENT_ID = ' . $elementId . ' ORDER BY IBLOCK_PROPERTY_ID, ID'
            );
            while ($row = $rows->fetch()) {
                $propertyId = (int)($row['IBLOCK_PROPERTY_ID'] ?? 0);
                $key = 'COMMON_TABLE.PROPERTY_' . $propertyId;
                if (!isset($result[$key])) { $result[$key] = []; }
                $result[$key][] = normalizeDiagnosticValue($row);
            }
        }
    } catch (\Throwable $exception) {
        $result['COMMON_TABLE.ERROR'] = $exception->getMessage();
    }

    ksort($result, SORT_NATURAL | SORT_FLAG_CASE);
    return $result;
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

    $rawPropertyFields = loadRawPropertyStorageFields(COMPARE_IBLOCK_ID, $elementId);

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
                'LINK_IBLOCK_ID' => (int)($property['LINK_IBLOCK_ID'] ?? 0),
                'USER_TYPE_SETTINGS' => normalizeDiagnosticValue($property['USER_TYPE_SETTINGS'] ?? []),
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
        'RAW_PROPERTY_FIELDS' => $rawPropertyFields,
        'PROPERTIES' => $properties,
        'ELEMENT_USER_FIELDS' => loadElementUserFields(COMPARE_IBLOCK_ID, $elementId),
        'USER_PROPERTIES' => loadUserPropertySnapshots($properties),
        'LINKED_USERS' => loadLinkedUsers(collectLinkedUserIds($properties)),
        'RIGHTS' => rightsForCompare(COMPARE_IBLOCK_ID, $elementId),
    ];
}



function rawPropertyComparableRows($value): array
{
    $rows = is_array($value) ? $value : [$value];
    $result = [];
    foreach ($rows as $row) {
        if (!is_array($row)) { $result[] = normalizeDiagnosticValue($row); continue; }
        $filtered = [];
        foreach (['DESCRIPTION', 'VALUE', 'VALUE_ENUM', 'VALUE_NUM', 'VALUE_TYPE'] as $fieldName) {
            if (array_key_exists($fieldName, $row)) {
                $filtered[$fieldName] = normalizeDiagnosticValue($row[$fieldName]);
            }
        }
        $result[] = $filtered;
    }

    return normalizeDiagnosticValue($result);
}

function rawPropertyDiffSummary(array $leftRaw, array $rightRaw): array
{
    $summaries = [];
    $keys = array_unique(array_merge(array_keys($leftRaw), array_keys($rightRaw)));
    sort($keys, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($keys as $key) {
        $leftRows = rawPropertyComparableRows($leftRaw[$key] ?? []);
        $rightRows = rawPropertyComparableRows($rightRaw[$key] ?? []);
        if (valueToString($leftRows) === valueToString($rightRows)) { continue; }

        $changedFields = [];
        $leftFirst = $leftRows[0] ?? [];
        $rightFirst = $rightRows[0] ?? [];
        if (is_array($leftFirst) && is_array($rightFirst)) {
            foreach (['DESCRIPTION', 'VALUE', 'VALUE_ENUM', 'VALUE_NUM', 'VALUE_TYPE'] as $fieldName) {
                if (valueToString($leftFirst[$fieldName] ?? '') !== valueToString($rightFirst[$fieldName] ?? '')) {
                    $changedFields[] = $fieldName;
                }
            }
        }

        $summaries[] = $key . ($changedFields === [] ? '' : ' (' . implode(', ', $changedFields) . ')');
    }

    return $summaries;
}

function propertyValueString(array $properties, string $code, string $field): string
{
    if (!isset($properties[$code]) || !array_key_exists($field, $properties[$code])) {
        return '';
    }

    return valueToString($properties[$code][$field]);
}

function printDiagnosticFindings(array $left, array $right, int $leftId, int $rightId): void
{
    diagOut('');
    diagOut('== Предварительный вывод ==');

    $notes = [];
    $leftXmlId = valueToString($left['FIELDS']['XML_ID'] ?? '');
    $rightXmlId = valueToString($right['FIELDS']['XML_ID'] ?? '');
    if ($leftXmlId !== $rightXmlId) {
        $notes[] = 'XML_ID отличается: у ' . $leftId . ' «' . $leftXmlId . '», у ' . $rightId . ' «' . $rightXmlId . '». Если модуль исключает элементы с заполненным внешним кодом, это может быть причиной.';
    }

    $dateCodes = ['CREATED_DATE', 'START_DATE', 'FINISH_DATE'];
    foreach ($dateCodes as $code) {
        $leftValue = propertyValueString($left['PROPERTIES'], $code, 'VALUE');
        $rightValue = propertyValueString($right['PROPERTIES'], $code, 'VALUE');
        if ($leftValue !== '' && $rightValue !== '' && $leftValue !== $rightValue) {
            $notes[] = $code . ' отличается: ' . $leftId . ' = ' . $leftValue . ', ' . $rightId . ' = ' . $rightValue . '. Проверьте, попадает ли неработающий элемент в период отчета/обработки.';
        }
    }

    $descriptionOnly = [];
    foreach ($left['PROPERTIES'] as $code => $leftProperty) {
        if (!isset($right['PROPERTIES'][$code])) { continue; }
        $rightProperty = $right['PROPERTIES'][$code];
        if (valueToString($leftProperty['VALUE'] ?? []) !== valueToString($rightProperty['VALUE'] ?? [])) { continue; }
        if (valueToString($leftProperty['DESCRIPTION'] ?? []) === valueToString($rightProperty['DESCRIPTION'] ?? [])) { continue; }
        $descriptionOnly[] = (string)$code;
    }
    if ($descriptionOnly !== []) {
        $notes[] = 'У свойств совпадают значения, но отличаются DESCRIPTION: ' . implode(', ', $descriptionOnly) . '. У рабочего элемента виден маркер «OASIS DATA-GEN», у второго DESCRIPTION пустой; если модуль/агент отбирает автозаявки по DESCRIPTION, второй элемент будет пропущен.';
    }

    $rawDiffs = rawPropertyDiffSummary($left['RAW_PROPERTY_FIELDS'] ?? [], $right['RAW_PROPERTY_FIELDS'] ?? []);
    if ($rawDiffs !== []) {
        $notes[] = 'В сыром хранении свойств отличаются поля: ' . implode('; ', array_slice($rawDiffs, 0, 12)) . (count($rawDiffs) > 12 ? '; ...' : '') . '. Это важно, если модуль читает b_iblock_element_property напрямую или фильтрует по VALUE_NUM/VALUE_ENUM/точному VALUE.';
    }

    if (valueToString($left['RIGHTS'] ?? []) === valueToString($right['RIGHTS'] ?? [])) {
        $notes[] = 'Права совпадают, поэтому причина не в правах элемента.';
    }
    if (valueToString($left['USER_PROPERTIES'] ?? []) === valueToString($right['USER_PROPERTIES'] ?? [])) {
        $notes[] = 'Пользовательские свойства с привязкой к пользователям совпадают.';
    }
    if (valueToString($left['LINKED_USERS'] ?? []) === valueToString($right['LINKED_USERS'] ?? [])) {
        $notes[] = 'Пользовательские поля связанных пользователей совпадают.';
    }

    if ($notes === []) {
        diagOut('Существенных подсказок по найденным отличиям нет; смотрите полные блоки сравнения выше.');
        return;
    }

    foreach ($notes as $index => $note) {
        diagOut(($index + 1) . '. ' . $note);
    }
}

function printDiffBlock(string $title, array $left, array $right, int $leftId, int $rightId, bool $showEqual = false): void
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
        $isEqual = $leftExists && $rightExists && valueToString($leftValue) === valueToString($rightValue);
        if ($isEqual && !$showEqual) {
            continue;
        }

        if (!$isEqual) { $diffCount++; }
        diagOut(($isEqual ? '[= ' : '[') . $key . ']');
        diagOut('  ' . $leftId . ': ' . ($leftExists ? shortValue($leftValue) : '<нет>'));
        diagOut('  ' . $rightId . ': ' . ($rightExists ? shortValue($rightValue) : '<нет>'));
    }

    if ($diffCount === 0) {
        diagOut('Отличий не найдено. Проверено значений: ' . count($keys) . '.');
    }
}

header('Content-Type: text/plain; charset=UTF-8');

if (!Loader::includeModule('iblock')) {
    diagOut('Ошибка: не удалось подключить модуль iblock.');
    exit(1);
}

$leftId = max(1, (int)diagOption('left', (string)DEFAULT_LEFT_ELEMENT_ID));
$rightId = max(1, (int)diagOption('right', (string)DEFAULT_RIGHT_ELEMENT_ID));
$showEqual = strtoupper((string)diagOption('all', 'N')) === 'Y';

try {
    $left = loadElementSnapshot($leftId);
    $right = loadElementSnapshot($rightId);
} catch (\Throwable $exception) {
    diagOut('Ошибка: ' . $exception->getMessage());
    exit(1);
}

diagOut('Сравнение элементов списка ' . COMPARE_IBLOCK_ID . ': ' . $leftId . ' vs ' . $rightId);
diagOut('Если права совпадают, ищите отличия в ACTIVE/BP_PUBLISHED/WF_STATUS_ID, разделах, свойствах START_DATE/пользователь/статус и пользовательских полях элемента/связанного пользователя.');
diagOut('Чтобы вывести не только отличия, но и совпавшие значения, добавьте параметр all=Y.');
diagOut('Сырые PROPERTY_* сравниваются отдельным блоком напрямую из таблиц b_iblock_element_prop_s/m42 и b_iblock_element_property, без тяжелого SELECT PROPERTY_* в GetList.');
printDiagnosticFindings($left, $right, $leftId, $rightId);

printDiffBlock('Поля элемента', $left['FIELDS'], $right['FIELDS'], $leftId, $rightId, $showEqual);
printDiffBlock('Разделы', $left['SECTIONS'], $right['SECTIONS'], $leftId, $rightId, $showEqual);
printDiffBlock('Сырые PROPERTY_* из таблиц свойств инфоблока', $left['RAW_PROPERTY_FIELDS'], $right['RAW_PROPERTY_FIELDS'], $leftId, $rightId, $showEqual);
printDiffBlock('Свойства', $left['PROPERTIES'], $right['PROPERTIES'], $leftId, $rightId, $showEqual);
printDiffBlock('Пользовательские поля элемента', $left['ELEMENT_USER_FIELDS'], $right['ELEMENT_USER_FIELDS'], $leftId, $rightId, $showEqual);
printDiffBlock('Пользовательские свойства списка с привязкой к пользователям', $left['USER_PROPERTIES'], $right['USER_PROPERTIES'], $leftId, $rightId, $showEqual);
printDiffBlock('Пользовательские поля связанных пользователей', $left['LINKED_USERS'], $right['LINKED_USERS'], $leftId, $rightId, $showEqual);
printDiffBlock('Права', $left['RIGHTS'], $right['RIGHTS'], $leftId, $rightId, $showEqual);
