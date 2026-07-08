<?php
/**
 * fix_list42_element_rights.php
 *
 * Оснастка для проверки и исправления прав элементов списка 42 за период
 * 19.06.2026-07.07.2026 с комментарием "Создана автоматически по формату работы".
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

function rightsSignature(array $rights): string
{
    ksort($rights);
    foreach ($rights as &$right) {
        if (is_array($right)) {
            ksort($right);
        }
    }
    unset($right);

    return md5(serialize($rights));
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

$referenceRightsObject = new \CIBlockElementRights(TARGET_IBLOCK_ID, REFERENCE_ELEMENT_ID);
$referenceRights = $referenceRightsObject->GetRights();
$referenceSignature = rightsSignature($referenceRights);

if (empty($referenceRights)) {
    out('Ошибка: не удалось получить права эталонного элемента ' . REFERENCE_ELEMENT_ID . '.');
    exit(1);
}

out(($dryRun ? 'DRY-RUN' : 'RUN') . ': список ' . TARGET_IBLOCK_ID . ', эталонный элемент ' . REFERENCE_ELEMENT_ID . '.');
out('Период START_DATE: ' . $dateFrom . ' - ' . $dateTo . '.');
out('Комментарий: ' . TARGET_COMMENT . '.');

$filter = [
    'IBLOCK_ID' => TARGET_IBLOCK_ID,
    'ACTIVE' => 'Y',
    '>=PROPERTY_148' => $dateFrom . ' 00:00:00',
    '<=PROPERTY_148' => $dateTo . ' 23:59:59',
];

$select = ['ID', 'NAME', 'IBLOCK_ID', 'PROPERTY_148', 'PROPERTY_164'];
$nav = $limit > 0 ? ['nTopCount' => $limit] : false;
$rsElements = \CIBlockElement::GetList(['ID' => 'ASC'], $filter, false, $nav, $select);

$checked = 0;
$matched = 0;
$alreadyCorrect = 0;
$fixed = 0;
$errors = 0;

while ($element = $rsElements->Fetch()) {
    $checked++;
    $elementId = (int)$element['ID'];
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

out('Итог: проверено по дате=' . $checked . ', совпало по комментарию=' . $matched . ', уже корректно=' . $alreadyCorrect . ', исправлено=' . $fixed . ', ошибок=' . $errors . '.');

if (!$dryRun && $errors > 0) {
    exit(1);
}
