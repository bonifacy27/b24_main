<?php
/**
 * Синхронизирует STATUS элементов списка 214 с активностью привязанного сотрудника.
 *
 * CLI:
 *   php -f sync_list214_user_status.php -- --dry-run
 *   php -f sync_list214_user_status.php -- --run
 */

declare(strict_types=1);

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('NOT_CHECK_PERMISSIONS', true);

function l214FindDocumentRoot(string $scriptDirectory): string
{
    $candidates = !empty($_SERVER['DOCUMENT_ROOT']) ? [(string)$_SERVER['DOCUMENT_ROOT']] : [];
    $directory = $scriptDirectory;
    while ($directory !== '' && $directory !== dirname($directory)) {
        $candidates[] = $directory;
        $directory = dirname($directory);
    }
    $candidates[] = $directory;

    foreach (array_unique($candidates) as $candidate) {
        $root = realpath($candidate);
        if ($root !== false && is_file($root . '/bitrix/modules/main/include/prolog_before.php')) {
            return $root;
        }
    }
    throw new RuntimeException('Не найден корень сайта Битрикс.');
}

try {
    $_SERVER['DOCUMENT_ROOT'] = l214FindDocumentRoot(__DIR__);
} catch (RuntimeException $exception) {
    http_response_code(500);
    die($exception->getMessage());
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;

const L214_IBLOCK_ID = 214;
const L214_EMPLOYEE_PROPERTY_ID = 1126;
const L214_EMPLOYEE_PROPERTY_CODE = 'UZ_SOTRUDNIKA';
const L214_STATUS_PROPERTY_ID = 3166;
const L214_STATUS_PROPERTY_CODE = 'STATUS';
const L214_STATUS_ACTIVE_ID = 7394;
const L214_STATUS_INACTIVE_ID = 7395;

function l214IsCli(): bool
{
    return PHP_SAPI === 'cli';
}

function l214RunRequested(): bool
{
    if (l214IsCli()) {
        return in_array('--run', (array)($_SERVER['argv'] ?? []), true);
    }
    return strtoupper((string)($_GET['run'] ?? 'N')) === 'Y';
}

function l214Line(string $message): void
{
    echo l214IsCli() ? $message . PHP_EOL : htmlspecialcharsbx($message) . '<br>' . PHP_EOL;
}

function l214Property(int $propertyId, string $expectedCode): void
{
    $property = \CIBlockProperty::GetByID($propertyId, L214_IBLOCK_ID)->Fetch();
    if (!$property || (string)$property['CODE'] !== $expectedCode) {
        throw new RuntimeException(sprintf(
            'Не найдено поле %s [PROPERTY_%d] списка %d.',
            $expectedCode,
            $propertyId,
            L214_IBLOCK_ID
        ));
    }
}

function l214CheckEnum(int $enumId, string $expectedValue): void
{
    $enum = \CIBlockPropertyEnum::GetList([], [
        'ID' => $enumId,
        'IBLOCK_ID' => L214_IBLOCK_ID,
        'PROPERTY_ID' => L214_STATUS_PROPERTY_ID,
    ])->Fetch();
    if (!$enum || (string)$enum['VALUE'] !== $expectedValue) {
        throw new RuntimeException(sprintf('У поля STATUS отсутствует значение «%s» [%d].', $expectedValue, $enumId));
    }
}

/** @return int[] */
function l214EmployeeIds(int $elementId): array
{
    $ids = [];
    $rows = \CIBlockElement::GetProperty(
        L214_IBLOCK_ID,
        $elementId,
        ['sort' => 'asc', 'id' => 'asc'],
        ['ID' => L214_EMPLOYEE_PROPERTY_ID]
    );
    while ($row = $rows->Fetch()) {
        $value = is_scalar($row['VALUE'] ?? null) ? (string)$row['VALUE'] : '';
        if (preg_match('/^(?:user_)?(\d+)$/i', trim($value), $matches)) {
            $ids[] = (int)$matches[1];
        }
    }
    return array_values(array_unique(array_filter($ids)));
}

/** @param array<int, bool> $cache */
function l214UserIsActive(int $userId, array &$cache): bool
{
    if (!array_key_exists($userId, $cache)) {
        $user = \CUser::GetByID($userId)->Fetch();
        $cache[$userId] = $user !== false && (string)$user['ACTIVE'] === 'Y';
    }
    return $cache[$userId];
}

if (!l214IsCli()) {
    header('Content-Type: text/html; charset=UTF-8');
    if (empty($GLOBALS['USER']) || !$GLOBALS['USER']->IsAdmin()) {
        http_response_code(403);
        die('Доступ разрешен только администратору.');
    }
    echo '<pre style="white-space:pre-wrap">';
}

if (!Loader::includeModule('iblock')) {
    l214Line('Ошибка: не удалось подключить модуль iblock.');
    exit(1);
}

$run = l214RunRequested();
if ($run && !l214IsCli() && !check_bitrix_sessid()) {
    l214Line('Ошибка: неверный идентификатор сессии.');
    exit(1);
}

try {
    l214Property(L214_EMPLOYEE_PROPERTY_ID, L214_EMPLOYEE_PROPERTY_CODE);
    l214Property(L214_STATUS_PROPERTY_ID, L214_STATUS_PROPERTY_CODE);
    l214CheckEnum(L214_STATUS_ACTIVE_ID, 'Активна');
    l214CheckEnum(L214_STATUS_INACTIVE_ID, 'Не активна');
} catch (RuntimeException $exception) {
    l214Line('Ошибка конфигурации: ' . $exception->getMessage());
    exit(1);
}

$counters = ['processed' => 0, 'updated' => 0, 'would_update' => 0, 'actual' => 0, 'without_employee' => 0, 'errors' => 0];
$activeCache = [];
l214Line('Синхронизация STATUS списка 214. Режим: ' . ($run ? 'RUN' : 'DRY-RUN'));

$elements = \CIBlockElement::GetList(
    ['ID' => 'ASC'],
    ['IBLOCK_ID' => L214_IBLOCK_ID, 'CHECK_PERMISSIONS' => 'N'],
    false,
    false,
    ['ID', 'NAME', 'PROPERTY_' . L214_STATUS_PROPERTY_CODE]
);
while ($element = $elements->Fetch()) {
    $counters['processed']++;
    $elementId = (int)$element['ID'];
    $employeeIds = l214EmployeeIds($elementId);
    if (!$employeeIds) {
        $counters['without_employee']++;
        l214Line(sprintf('SKIP element=%d: сотрудник не привязан.', $elementId));
        continue;
    }

    $hasActiveUser = false;
    foreach ($employeeIds as $employeeId) {
        if (l214UserIsActive($employeeId, $activeCache)) {
            $hasActiveUser = true;
            break;
        }
    }
    $targetStatus = $hasActiveUser ? L214_STATUS_ACTIVE_ID : L214_STATUS_INACTIVE_ID;
    $currentStatus = (int)($element['PROPERTY_' . L214_STATUS_PROPERTY_CODE . '_ENUM_ID'] ?? 0);
    if ($currentStatus === $targetStatus) {
        $counters['actual']++;
        continue;
    }

    $description = sprintf('element=%d employees=%s STATUS: %d -> %d', $elementId, implode(',', $employeeIds), $currentStatus, $targetStatus);
    if (!$run) {
        $counters['would_update']++;
        l214Line('DRY-RUN ' . $description);
        continue;
    }

    try {
        \CIBlockElement::SetPropertyValuesEx($elementId, L214_IBLOCK_ID, [L214_STATUS_PROPERTY_CODE => $targetStatus]);
        $counters['updated']++;
        l214Line('UPDATED ' . $description);
    } catch (Throwable $exception) {
        $counters['errors']++;
        l214Line('ERROR ' . $description . ': ' . $exception->getMessage());
    }
}

l214Line(sprintf(
    'Итого: обработано=%d; актуально=%d; обновлено=%d; к обновлению=%d; без сотрудника=%d; ошибок=%d.',
    $counters['processed'],
    $counters['actual'],
    $counters['updated'],
    $counters['would_update'],
    $counters['without_employee'],
    $counters['errors']
));
exit($counters['errors'] > 0 ? 1 : 0);
