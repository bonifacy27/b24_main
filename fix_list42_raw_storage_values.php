<?php
/**
 * fix_list42_raw_storage_values.php
 *
 * Приводит сырое хранение ключевых свойств заявок списка 42 к формату,
 * который наблюдается у рабочих элементов: очищает DESCRIPTION, заполняет
 * VALUE_NUM/VALUE_ENUM и приводит START_DATE/FINISH_DATE к YYYY-MM-DD 00:00:00.
 * По умолчанию dry-run, запись только с --run / run=Y.
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

use Bitrix\Main\Application;
use Bitrix\Main\Loader;

const RAW_STORAGE_IBLOCK_ID = 42;
const RAW_STORAGE_COMMENT = 'Создана автоматически по формату работы';
const RAW_STORAGE_DATE_FROM = '18.06.2026';
const RAW_STORAGE_DATE_TO = '08.07.2026';
const RAW_STORAGE_PROP_CREATED = 145;
const RAW_STORAGE_PROP_FIO = 146;
const RAW_STORAGE_PROP_START = 148;
const RAW_STORAGE_PROP_FINISH = 149;
const RAW_STORAGE_PROP_CREATOR = 158;
const RAW_STORAGE_PROP_KIND = 160;
const RAW_STORAGE_PROP_STATUS = 162;
const RAW_STORAGE_PROP_COMMENT = 164;

function rawStorageOption(string $name, ?string $default = null): ?string
{
    if (PHP_SAPI === 'cli') {
        foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
            if ($arg === '--' . $name) { return 'Y'; }
            if (strpos($arg, '--' . $name . '=') === 0) { return substr($arg, strlen($name) + 3); }
        }
        return $default;
    }

    $httpName = str_replace('-', '_', $name);
    return isset($_REQUEST[$httpName]) ? (string)$_REQUEST[$httpName] : $default;
}

function rawStorageOut(string $message = ''): void
{
    echo $message . PHP_EOL;
}

function rawStorageIsRun(): bool
{
    return in_array(strtoupper((string)rawStorageOption('run', 'N')), ['Y', 'YES', '1', 'TRUE'], true);
}

function rawStorageParseDate(?string $value, bool $endOfDay = false): ?\DateTimeImmutable
{
    $value = trim((string)$value);
    if ($value === '') { return null; }
    foreach (['d.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
        $date = \DateTimeImmutable::createFromFormat($format, $value);
        if ($date instanceof \DateTimeImmutable) { return $endOfDay ? $date->setTime(23, 59, 59) : $date->setTime(0, 0, 0); }
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) { return null; }
    $date = (new \DateTimeImmutable())->setTimestamp($timestamp);
    return $endOfDay ? $date->setTime(23, 59, 59) : $date->setTime(0, 0, 0);
}

function rawStorageParseIds(?string $ids): array
{
    $result = [];
    foreach (preg_split('/[,;\s]+/', trim((string)$ids)) ?: [] as $id) {
        $id = (int)$id;
        if ($id > 0) { $result[$id] = true; }
    }
    $result = array_keys($result);
    sort($result, SORT_NUMERIC);
    return $result;
}

function rawStorageHtmlToText($value): string
{
    if (is_array($value)) { $value = (string)($value['TEXT'] ?? reset($value) ?: ''); }
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

function rawStorageCommentMatches(int $elementId): bool
{
    $rows = \CIBlockElement::GetProperty(RAW_STORAGE_IBLOCK_ID, $elementId, [], ['ID' => RAW_STORAGE_PROP_COMMENT]);
    while ($property = $rows->Fetch()) {
        if (rawStorageHtmlToText($property['VALUE'] ?? '') === RAW_STORAGE_COMMENT) { return true; }
    }
    return false;
}

function rawStorageYearNum(string $value): string
{
    if (preg_match('/^(\d{4})/', trim($value), $matches)) { return $matches[1] . '.0000'; }
    return '';
}

function rawStorageNumericValueNum(string $value): string
{
    $value = trim($value);
    return preg_match('/^-?\d+$/', $value) ? $value . '.0000' : '';
}

function rawStorageDateTimeValue(string $value): string
{
    $value = trim($value);
    if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:\s+00:00:00)?$/', $value, $matches)) {
        return $matches[1] . ' 00:00:00';
    }
    return $value;
}

function rawStorageTargetFields(array $row): array
{
    $propertyId = (int)$row['IBLOCK_PROPERTY_ID'];
    $value = (string)$row['VALUE'];
    $target = ['DESCRIPTION' => ''];

    if ($propertyId === RAW_STORAGE_PROP_CREATED) {
        $target['VALUE_NUM'] = rawStorageYearNum($value);
    } elseif (in_array($propertyId, [RAW_STORAGE_PROP_FIO, RAW_STORAGE_PROP_CREATOR], true)) {
        $target['VALUE_NUM'] = rawStorageNumericValueNum($value);
    } elseif (in_array($propertyId, [RAW_STORAGE_PROP_START, RAW_STORAGE_PROP_FINISH], true)) {
        $target['VALUE'] = rawStorageDateTimeValue($value);
        $target['VALUE_NUM'] = rawStorageYearNum($value);
    } elseif (in_array($propertyId, [RAW_STORAGE_PROP_KIND, RAW_STORAGE_PROP_STATUS], true)) {
        $target['VALUE_ENUM'] = $value;
    } elseif ($propertyId === RAW_STORAGE_PROP_COMMENT) {
        $target['VALUE_NUM'] = '0.0000';
    }

    return $target;
}

header('Content-Type: text/plain; charset=UTF-8');

if (!Loader::includeModule('iblock')) {
    rawStorageOut('Ошибка: не удалось подключить модуль iblock.');
    exit(1);
}

$run = rawStorageIsRun();
$ids = rawStorageParseIds(rawStorageOption('ids', ''));
$dateFrom = rawStorageParseDate(rawStorageOption('date-from', RAW_STORAGE_DATE_FROM));
$dateTo = rawStorageParseDate(rawStorageOption('date-to', RAW_STORAGE_DATE_TO), true);
$limit = max(0, (int)rawStorageOption('limit', '0'));
if (!$dateFrom || !$dateTo) { rawStorageOut('Ошибка: неверный период.'); exit(1); }
if ($dateFrom > $dateTo) { [$dateFrom, $dateTo] = [$dateTo, $dateFrom]; }

$connection = Application::getConnection();
$sqlHelper = $connection->getSqlHelper();
$table = 'b_iblock_element_property';
$dateFromSql = $sqlHelper->forSql($dateFrom->format('Y-m-d'));
$dateToSql = $sqlHelper->forSql($dateTo->modify('+1 day')->format('Y-m-d'));
$whereIds = $ids === [] ? '' : ' AND p.IBLOCK_ELEMENT_ID IN (' . implode(',', array_map('intval', $ids)) . ')';
$propertyIds = [
    RAW_STORAGE_PROP_CREATED, RAW_STORAGE_PROP_FIO, RAW_STORAGE_PROP_START, RAW_STORAGE_PROP_FINISH,
    RAW_STORAGE_PROP_CREATOR, RAW_STORAGE_PROP_KIND, RAW_STORAGE_PROP_STATUS, RAW_STORAGE_PROP_COMMENT,
];
$sql = 'SELECT p.ID, p.IBLOCK_ELEMENT_ID, p.IBLOCK_PROPERTY_ID, p.VALUE, p.VALUE_ENUM, p.VALUE_NUM, p.DESCRIPTION '
    . 'FROM ' . $sqlHelper->quote($table) . ' p '
    . 'WHERE p.IBLOCK_PROPERTY_ID IN (' . implode(',', $propertyIds) . ')' . $whereIds . ' '
    . 'AND p.IBLOCK_ELEMENT_ID IN ('
    . 'SELECT sd.IBLOCK_ELEMENT_ID FROM ' . $sqlHelper->quote($table) . ' sd '
    . 'WHERE sd.IBLOCK_PROPERTY_ID = ' . RAW_STORAGE_PROP_START . ' '
    . "AND sd.VALUE >= '$dateFromSql' AND sd.VALUE < '$dateToSql'"
    . ') '
    . 'ORDER BY p.IBLOCK_ELEMENT_ID, p.IBLOCK_PROPERTY_ID, p.ID';

rawStorageOut('Режим: ' . ($run ? 'RUN' : 'DRY-RUN'));
rawStorageOut('Период START_DATE: ' . $dateFrom->format('d.m.Y') . ' - ' . $dateTo->format('d.m.Y'));
rawStorageOut('Комментарий: ' . RAW_STORAGE_COMMENT);
if ($ids !== []) { rawStorageOut('Ограничение по ID: ' . implode(', ', $ids)); }

$allowedElements = [];
$checked = 0;
$matched = 0;
$updated = 0;
$skipped = 0;
$rows = $connection->query($sql);
while ($row = $rows->fetch()) {
    $checked++;
    $elementId = (int)$row['IBLOCK_ELEMENT_ID'];
    if (!array_key_exists($elementId, $allowedElements)) {
        $allowedElements[$elementId] = rawStorageCommentMatches($elementId);
    }
    if (!$allowedElements[$elementId]) { $skipped++; continue; }

    $target = rawStorageTargetFields($row);
    $sets = [];
    $changes = [];
    foreach ($target as $field => $value) {
        if ((string)($row[$field] ?? '') === (string)$value) { continue; }
        $sets[] = $field . " = '" . $sqlHelper->forSql((string)$value) . "'";
        $changes[] = $field . '=' . (string)($row[$field] ?? '') . ' -> ' . (string)$value;
    }
    if ($sets === []) { $skipped++; continue; }

    $matched++;
    rawStorageOut(($run ? 'FIX' : 'WOULD FIX') . ' element=' . $elementId . ' property=' . $row['IBLOCK_PROPERTY_ID'] . ' row=' . $row['ID'] . ' ' . implode(', ', $changes));
    if (!$run) { continue; }

    $connection->queryExecute('UPDATE ' . $sqlHelper->quote($table) . ' SET ' . implode(', ', $sets) . ' WHERE ID = ' . (int)$row['ID']);
    $updated++;
    if ($limit > 0 && $updated >= $limit) { rawStorageOut('Достигнут limit=' . $limit . ', остановка.'); break; }
}

rawStorageOut('Проверено raw-строк: ' . $checked);
rawStorageOut('Подходит к исправлению: ' . $matched);
rawStorageOut('Пропущено: ' . $skipped);
rawStorageOut('Обновлено: ' . $updated);
