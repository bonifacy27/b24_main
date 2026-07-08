<?php
/**
 * fix_list42_element_rights.php
 *
 * Оснастка для проверки и исправления прав элементов списка 42 за период
 * 19.06.2026-07.07.2026 с комментарием "Создана автоматически по формату работы".
 *
 * Дату START_DATE скрипт проверяет в PHP, а не в фильтре CIBlockElement::GetList:
 * для свойств типа date/datetime в списках Bitrix фильтрация по PROPERTY_148 может
 * зависеть от внутреннего формата хранения и не находить подходящие элементы.
 * Права эталона перед применением пересобираются в новые ключи n0/n1/... без ID
 * записей прав эталонного элемента, иначе SetRights может вернуть успех, но не
 * заменить права целевого элемента.
 *
 * За эталон берутся права элемента 3619762. По умолчанию работает dry-run;
 * фактическое исправление выполняется только с ключом --run или GET-параметром run=Y.
 *
 * Примеры:
 *   php -f fix_list42_element_rights.php -- --dry-run
 *   php -f fix_list42_element_rights.php -- --run
 *   /fix_list42_element_rights.php?dry_run=Y
 *   /fix_list42_element_rights.php?run=Y
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

const TARGET_IBLOCK_ID = 42;
const REFERENCE_ELEMENT_ID = 3619762;
const DEFAULT_DATE_FROM = '19.06.2026';
const DEFAULT_DATE_TO = '07.07.2026';
const TARGET_COMMENT = 'Создана автоматически по формату работы';

function optionValue(string $name, ?string $default = null): ?string
{
    if (PHP_SAPI === 'cli') {
        foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
            if ($arg === '--' . $name) {
                return 'Y';
            }
            if (strpos($arg, '--' . $name . '=') === 0) {
                return substr($arg, strlen($name) + 3);
            }
        }
        return $default;
    }

    return isset($_REQUEST[$name]) ? (string)$_REQUEST[$name] : $default;
}

function isTruthy(?string $value): bool
{
    return in_array(mb_strtoupper(trim((string)$value)), ['Y', 'YES', 'TRUE', '1', 'ON', 'RUN'], true);
}

function normalizeComment($value): string
{
    if (is_array($value)) {
        $value = $value['TEXT'] ?? $value['VALUE'] ?? reset($value) ?: '';
    }

    $charset = defined('LANG_CHARSET') && LANG_CHARSET ? LANG_CHARSET : 'UTF-8';
    $text = html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, $charset);
    $text = preg_replace('/\s+/u', ' ', $text);

    return trim((string)$text);
}


function parseDateTimeValue($value): ?\DateTimeImmutable
{
    if (is_array($value)) {
        $value = $value['VALUE'] ?? $value['TEXT'] ?? reset($value) ?: '';
    }

    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    $formats = [
        'd.m.Y H:i:s',
        'd.m.Y H:i',
        'd.m.Y',
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'Y-m-d',
    ];

    foreach ($formats as $format) {
        $dt = \DateTimeImmutable::createFromFormat($format, $raw);
        if ($dt instanceof \DateTimeImmutable) {
            return $dt;
        }
    }

    $timestamp = strtotime($raw);
    if ($timestamp === false) {
        return null;
    }

    return (new \DateTimeImmutable())->setTimestamp($timestamp);
}

function dateRangeBoundary(string $value, bool $endOfDay): \DateTimeImmutable
{
    $dt = parseDateTimeValue($value);
    if (!$dt instanceof \DateTimeImmutable) {
        throw new \InvalidArgumentException('Некорректная дата: ' . $value);
    }

    return $endOfDay ? $dt->setTime(23, 59, 59) : $dt->setTime(0, 0, 0);
}

function normalizeRightsForElement(array $rights): array
{
    $normalized = [];

    foreach ($rights as $right) {
        if (!is_array($right)) {
            continue;
        }

        $groupCode = isset($right['GROUP_CODE']) ? trim((string)$right['GROUP_CODE']) : '';
        $taskId = isset($right['TASK_ID']) ? (int)$right['TASK_ID'] : 0;

        if ($groupCode === '' || $taskId <= 0) {
            continue;
        }

        $normalized[$groupCode . ':' . $taskId] = [
            'GROUP_CODE' => $groupCode,
            'TASK_ID' => $taskId,
        ];
    }

    ksort($normalized);

    $result = [];
    $index = 0;
    foreach ($normalized as $right) {
        $result['n' . $index] = $right;
        $index++;
    }

    return $result;
}

function rightsSignature(array $rights): string
{
    return md5(serialize(normalizeRightsForElement($rights)));
}

function out(string $message): void
{
    echo $message . PHP_EOL;
}

header('Content-Type: text/plain; charset=UTF-8');

if (!Loader::includeModule('iblock')) {
    out('Ошибка: не удалось подключить модуль iblock.');
    exit(1);
}

$run = isTruthy(optionValue('run'));
$dryRun = !$run || isTruthy(optionValue('dry-run')) || isTruthy(optionValue('dry_run'));
$dateFrom = optionValue('date-from', optionValue('date_from', DEFAULT_DATE_FROM));
$dateTo = optionValue('date-to', optionValue('date_to', DEFAULT_DATE_TO));
$limit = max(0, (int)optionValue('limit', '0'));

try {
    $dateFromBoundary = dateRangeBoundary((string)$dateFrom, false);
    $dateToBoundary = dateRangeBoundary((string)$dateTo, true);
} catch (\InvalidArgumentException $exception) {
    out('Ошибка: ' . $exception->getMessage());
    exit(1);
}

if ($dateFromBoundary > $dateToBoundary) {
    [$dateFromBoundary, $dateToBoundary] = [$dateToBoundary, $dateFromBoundary];
}

$referenceRightsObject = new \CIBlockElementRights(TARGET_IBLOCK_ID, REFERENCE_ELEMENT_ID);
$referenceRights = normalizeRightsForElement($referenceRightsObject->GetRights());
$referenceSignature = rightsSignature($referenceRights);

if (empty($referenceRights)) {
    out('Ошибка: не удалось получить применимые права эталонного элемента ' . REFERENCE_ELEMENT_ID . '.');
    exit(1);
}

out(($dryRun ? 'DRY-RUN' : 'RUN') . ': список ' . TARGET_IBLOCK_ID . ', эталонный элемент ' . REFERENCE_ELEMENT_ID . '.');
out('Период START_DATE: ' . $dateFrom . ' - ' . $dateTo . '.');
out('Комментарий: ' . TARGET_COMMENT . '.');

$filter = [
    'IBLOCK_ID' => TARGET_IBLOCK_ID,
    'ACTIVE' => 'Y',
];

$select = ['ID', 'NAME', 'IBLOCK_ID', 'PROPERTY_148', 'PROPERTY_164'];
$nav = $limit > 0 ? ['nTopCount' => $limit] : false;
$rsElements = \CIBlockElement::GetList(['ID' => 'ASC'], $filter, false, $nav, $select);

$checked = 0;
$matchedByDate = 0;
$matched = 0;
$alreadyCorrect = 0;
$fixed = 0;
$errors = 0;

while ($element = $rsElements->Fetch()) {
    $checked++;
    $elementId = (int)$element['ID'];
    $startDate = parseDateTimeValue($element['PROPERTY_148_VALUE'] ?? '');

    if (!$startDate instanceof \DateTimeImmutable || $startDate < $dateFromBoundary || $startDate > $dateToBoundary) {
        continue;
    }

    $matchedByDate++;
    $comment = normalizeComment($element['PROPERTY_164_VALUE'] ?? '');

    if ($comment !== TARGET_COMMENT) {
        continue;
    }

    $matched++;
    $rightsObject = new \CIBlockElementRights(TARGET_IBLOCK_ID, $elementId);
    $currentRights = $rightsObject->GetRights();

    if (rightsSignature($currentRights) === $referenceSignature) {
        $alreadyCorrect++;
        out('OK: элемент ' . $elementId . ' уже имеет корректные права.');
        continue;
    }

    if ($dryRun) {
        out('WOULD FIX: элемент ' . $elementId . ' отличается от эталона.');
        continue;
    }

    if ($rightsObject->SetRights($referenceRights)) {
        $fixed++;
        out('FIXED: элемент ' . $elementId . '.');
    } else {
        $errors++;
        out('ERROR: не удалось изменить права элемента ' . $elementId . '.');
    }
}

out('Итог: проверено кандидатов=' . $checked . ', совпало по дате=' . $matchedByDate . ', совпало по комментарию=' . $matched . ', уже корректно=' . $alreadyCorrect . ', исправлено=' . $fixed . ', ошибок=' . $errors . '.');

if (!$dryRun && $errors > 0) {
    exit(1);
}
