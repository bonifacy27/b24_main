<?php
/**
 * fix_list42_raw_date_values.php
 *
 * Нормализует сырые VALUE свойств START_DATE (148) и FINISH_DATE (149)
 * в b_iblock_element_property для заявок списка 42: YYYY-MM-DD 00:00:00 -> YYYY-MM-DD.
 * По умолчанию dry-run, запись только с --run / run=Y.
 *
 * Примеры:
 *   php -f fix_list42_raw_date_values.php
 *   php -f fix_list42_raw_date_values.php -- --ids=3606290 --run
 *   /fix_list42_raw_date_values.php?ids=3606290&run=Y
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

const RAW_DATE_IBLOCK_ID = 42;
const RAW_DATE_START_PROPERTY_ID = 148;
const RAW_DATE_FINISH_PROPERTY_ID = 149;
const RAW_DATE_COMMENT_PROPERTY_ID = 164;
const RAW_DATE_TARGET_COMMENT = 'Создана автоматически по формату работы';
const RAW_DATE_DEFAULT_DATE_FROM = '18.06.2026';
const RAW_DATE_DEFAULT_DATE_TO = '08.07.2026';

function rawDateOption(string $name, ?string $default = null): ?string
{
    if (PHP_SAPI === 'cli') {
        foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
            if ($arg === '--' . $name) { return 'Y'; }
            if (strpos($arg, '--' . $name . '=') === 0) {
                return substr($arg, strlen($name) + 3);
            }
        }
        return $default;
    }

    $httpName = str_replace('-', '_', $name);
    return isset($_REQUEST[$httpName]) ? (string)$_REQUEST[$httpName] : $default;
}

function rawDateOut(string $message = ''): void
{
    echo $message . PHP_EOL;
}

function rawDateIsRun(): bool
{
    return in_array(strtoupper((string)rawDateOption('run', 'N')), ['Y', 'YES', '1', 'TRUE'], true);
}

function rawDateParseDate(?string $value, bool $endOfDay = false): ?\DateTimeImmutable
{
    $value = trim((string)$value);
    if ($value === '') { return null; }

    foreach (['d.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
        $date = \DateTimeImmutable::createFromFormat($format, $value);
        if ($date instanceof \DateTimeImmutable) {
            return $endOfDay ? $date->setTime(23, 59, 59) : $date->setTime(0, 0, 0);
        }
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) { return null; }
    $date = (new \DateTimeImmutable())->setTimestamp($timestamp);
    return $endOfDay ? $date->setTime(23, 59, 59) : $date->setTime(0, 0, 0);
}

function rawDateNormalizeValue(string $value): ?string
{
    $value = trim($value);
    if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:\s+00:00:00)?$/', $value, $matches)) {
        return $matches[1];
    }

    return null;
}

function rawDateHtmlToText($value): string
{
    if (is_array($value)) {
        $value = (string)($value['TEXT'] ?? reset($value) ?: '');
    }

    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

function rawDateElementCommentMatches(int $elementId): bool
{
    $rows = \CIBlockElement::GetProperty(RAW_DATE_IBLOCK_ID, $elementId, [], ['ID' => RAW_DATE_COMMENT_PROPERTY_ID]);
    while ($property = $rows->Fetch()) {
        if (rawDateHtmlToText($property['VALUE'] ?? '') === RAW_DATE_TARGET_COMMENT) {
            return true;
        }
    }

    return false;
}

function rawDateElementStartDate(int $elementId): ?\DateTimeImmutable
{
    $rows = \CIBlockElement::GetProperty(RAW_DATE_IBLOCK_ID, $elementId, [], ['ID' => RAW_DATE_START_PROPERTY_ID]);
    while ($property = $rows->Fetch()) {
        $value = $property['VALUE'] ?? '';
        if (is_array($value)) { continue; }
        $date = rawDateParseDate((string)$value);
        if ($date instanceof \DateTimeImmutable) { return $date; }
    }

    return null;
}

function rawDateParseIds(?string $ids): array
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

header('Content-Type: text/plain; charset=UTF-8');

if (!Loader::includeModule('iblock')) {
    rawDateOut('Ошибка: не удалось подключить модуль iblock.');
    exit(1);
}

$run = rawDateIsRun();
$ids = rawDateParseIds(rawDateOption('ids', ''));
$dateFrom = rawDateParseDate(rawDateOption('date-from', RAW_DATE_DEFAULT_DATE_FROM));
$dateTo = rawDateParseDate(rawDateOption('date-to', RAW_DATE_DEFAULT_DATE_TO), true);
$limit = max(0, (int)rawDateOption('limit', '0'));

if (!$dateFrom || !$dateTo) {
    rawDateOut('Ошибка: неверный date-from/date-to.');
    exit(1);
}
if ($dateFrom > $dateTo) { [$dateFrom, $dateTo] = [$dateTo, $dateFrom]; }

$connection = Application::getConnection();
$sqlHelper = $connection->getSqlHelper();
$table = 'b_iblock_element_property';
$propertyIds = [RAW_DATE_START_PROPERTY_ID, RAW_DATE_FINISH_PROPERTY_ID];
$dateFromSql = $sqlHelper->forSql($dateFrom->format('Y-m-d'));
$dateToSql = $sqlHelper->forSql($dateTo->modify('+1 day')->format('Y-m-d'));
$whereIds = $ids === [] ? '' : ' AND p.IBLOCK_ELEMENT_ID IN (' . implode(',', array_map('intval', $ids)) . ')';
$sql = 'SELECT p.ID, p.IBLOCK_ELEMENT_ID, p.IBLOCK_PROPERTY_ID, p.VALUE, p.VALUE_NUM, p.DESCRIPTION '
    . 'FROM ' . $sqlHelper->quote($table) . ' p '
    . 'WHERE p.IBLOCK_PROPERTY_ID IN (' . implode(',', $propertyIds) . ') '
    . "AND p.VALUE LIKE '% 00:00:00'" . $whereIds . ' '
    . 'AND p.IBLOCK_ELEMENT_ID IN ('
    . 'SELECT sd.IBLOCK_ELEMENT_ID FROM ' . $sqlHelper->quote($table) . ' sd '
    . 'WHERE sd.IBLOCK_PROPERTY_ID = ' . RAW_DATE_START_PROPERTY_ID . ' '
    . "AND sd.VALUE >= '$dateFromSql' AND sd.VALUE < '$dateToSql'"
    . ') '
    . 'ORDER BY p.IBLOCK_ELEMENT_ID, p.IBLOCK_PROPERTY_ID, p.ID';

rawDateOut('Режим: ' . ($run ? 'RUN' : 'DRY-RUN'));
rawDateOut('Период START_DATE: ' . $dateFrom->format('d.m.Y') . ' - ' . $dateTo->format('d.m.Y') . ' (предфильтр в SQL)');
rawDateOut('Комментарий: ' . RAW_DATE_TARGET_COMMENT);
if ($ids !== []) { rawDateOut('Ограничение по ID: ' . implode(', ', $ids)); }

$seenElements = [];
$checked = 0;
$matched = 0;
$updated = 0;
$skipped = 0;
$rows = $connection->query($sql);
while ($row = $rows->fetch()) {
    $checked++;
    $elementId = (int)$row['IBLOCK_ELEMENT_ID'];
    if (!array_key_exists($elementId, $seenElements)) {
        $startDate = rawDateElementStartDate($elementId);
        $seenElements[$elementId] = $startDate instanceof \DateTimeImmutable
            && $startDate >= $dateFrom
            && $startDate <= $dateTo
            && rawDateElementCommentMatches($elementId);
    }
    if (!$seenElements[$elementId]) { $skipped++; continue; }

    $newValue = rawDateNormalizeValue((string)$row['VALUE']);
    if ($newValue === null || $newValue === (string)$row['VALUE']) { $skipped++; continue; }

    $matched++;
    $propertyName = (int)$row['IBLOCK_PROPERTY_ID'] === RAW_DATE_START_PROPERTY_ID ? 'START_DATE' : 'FINISH_DATE';
    rawDateOut(($run ? 'FIX' : 'WOULD FIX') . ' element=' . $elementId . ' property=' . $propertyName . ' row=' . $row['ID'] . ' value=' . $row['VALUE'] . ' -> ' . $newValue . ', VALUE_NUM -> NULL');

    if (!$run) { continue; }

    $connection->queryExecute(
        'UPDATE ' . $sqlHelper->quote($table)
        . " SET VALUE = '" . $sqlHelper->forSql($newValue) . "', VALUE_NUM = NULL"
        . ' WHERE ID = ' . (int)$row['ID']
    );
    $updated++;

    if ($limit > 0 && $updated >= $limit) {
        rawDateOut('Достигнут limit=' . $limit . ', остановка.');
        break;
    }
}

rawDateOut('Проверено raw-строк: ' . $checked);
rawDateOut('Подходит к исправлению: ' . $matched);
rawDateOut('Пропущено: ' . $skipped);
rawDateOut('Обновлено: ' . $updated);
