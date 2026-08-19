<?php
/**
 * Диагностика и исправление кадровых полей/групп пользователя Битрикс24.
 *
 * CLI:
 *   php -f diagnose_staff_assignment.php -- --user-id=123
 *   php -f diagnose_staff_assignment.php -- --user-id=123 --run
 * Browser:
 *   /diagnose_staff_assignment.php?user_id=123
 *   /diagnose_staff_assignment.php?user_id=123&run=Y
 *
 * По умолчанию изменения не выполняются. Ключ --run (или run=Y) включает
 * исправление полей и состава групп.
 */

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('NOT_CHECK_PERMISSIONS', true);

/**
 * Находит корень сайта и при запуске из вложенного каталога, и из CLI.
 * Нельзя использовать __DIR__ как DOCUMENT_ROOT: в production скрипт лежит в
 * /pub/apps/tools, а каталог bitrix расположен в корне сайта.
 */
function dsaFindDocumentRoot(string $scriptDirectory): string
{
    $candidates = [];
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $candidates[] = (string)$_SERVER['DOCUMENT_ROOT'];
    }

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

    throw new RuntimeException(
        'Не найден корень сайта Bitrix: отсутствует bitrix/modules/main/include/prolog_before.php.'
    );
}

try {
    $DOCUMENT_ROOT = dsaFindDocumentRoot(__DIR__);
} catch (RuntimeException $exception) {
    http_response_code(500);
    die($exception->getMessage());
}

$_SERVER['DOCUMENT_ROOT'] = $DOCUMENT_ROOT;
require_once $DOCUMENT_ROOT . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;

const DSA_SCHEDULE_HLBLOCK_ID = 13;
const DSA_WORK_SCHEDULE_ID = 'b380491a-a5da-11ec-810e-00505690fbe1';
const DSA_WORK_SCHEDULE_GROUP_ID = 46;
const DSA_VACATION_GROUP_ID = 44;

if (PHP_SAPI !== 'cli' && (empty($GLOBALS['USER']) || !$GLOBALS['USER']->IsAdmin())) {
    http_response_code(403);
    die('Доступ разрешен только администратору.');
}

function dsaCli(): bool
{
    return PHP_SAPI === 'cli';
}

function dsaArguments(): array
{
    $result = ['user_id' => 0, 'run' => false];
    if (dsaCli()) {
        global $argv;
        foreach ((array)$argv as $argument) {
            if (strpos($argument, '--user-id=') === 0) {
                $result['user_id'] = (int)substr($argument, 10);
            } elseif ($argument === '--run') {
                $result['run'] = true;
            }
        }
    } else {
        $result['user_id'] = (int)($_GET['user_id'] ?? 0);
        $result['run'] = (string)($_GET['run'] ?? '') === 'Y';
    }
    return $result;
}

function dsaLine(string $message): void
{
    echo dsaCli() ? $message . PHP_EOL : htmlspecialcharsbx($message) . '<br>' . PHP_EOL;
}

function dsaValue($value): string
{
    return is_scalar($value) ? trim((string)$value) : '';
}

function dsaDate($value): string
{
    if ($value instanceof \Bitrix\Main\Type\Date) {
        return $value->format('Y-m-d');
    }
    $value = dsaValue($value);
    if ($value === '') {
        return '';
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? '' : date('Y-m-d', $timestamp);
}

function dsaBitrixDate(string $isoDate): string
{
    return $isoDate === '' ? '' : ConvertTimeStamp(strtotime($isoDate), 'SHORT');
}

function dsaStaffByName(\Bitrix\Main\DB\Connection $connection, array $user): array
{
    $helper = $connection->getSqlHelper();
    $conditions = [
        "Staff_SurName = '" . $helper->forSql(dsaValue($user['LAST_NAME'])) . "'",
        "Staff_FirstName = '" . $helper->forSql(dsaValue($user['NAME'])) . "'",
        "Staff_MiddleName = '" . $helper->forSql(dsaValue($user['SECOND_NAME'])) . "'",
        'Staff_LeavingDate IS NULL',
    ];
    $sql = 'SELECT TOP (2) Staff_ID, Staff_Birthdate, Staff_HiringDate, ' .
        'Staff_Subdivision, Staff_Notes FROM GateDB.dbo.Staff_1CZUP WHERE ' .
        implode(' AND ', $conditions) . ' ORDER BY Staff_HiringDate DESC';
    $rows = [];
    $recordset = $connection->query($sql);
    while ($row = $recordset->fetch()) {
        $rows[] = $row;
    }
    return $rows;
}

function dsaActiveSchedules(string $staffGuid): array
{
    if (!Loader::includeModule('highloadblock')) {
        throw new RuntimeException('Модуль highloadblock недоступен.');
    }
    $block = HighloadBlockTable::getById(DSA_SCHEDULE_HLBLOCK_ID)->fetch();
    if (!$block) {
        throw new RuntimeException('HL-блок ID=' . DSA_SCHEDULE_HLBLOCK_ID . ' не найден.');
    }
    $dataClass = HighloadBlockTable::compileEntity($block)->getDataClass();
    $today = date('Y-m-d');
    $rows = $dataClass::getList([
        'filter' => ['=UF_STAFF_ID' => $staffGuid],
        'order' => ['UF_FORMAT_START_DATE' => 'DESC', 'ID' => 'DESC'],
    ]);
    $result = [];
    while ($row = $rows->fetch()) {
        $start = dsaDate($row['UF_FORMAT_START_DATE'] ?? '');
        $end = dsaDate($row['UF_FORMAT_END_DATE'] ?? '');
        if (($start === '' || $start <= $today) && ($end === '' || $end >= $today)) {
            $result[] = $row;
        }
    }
    return $result;
}

function dsaHasGroup(array $groups, int $groupId): bool
{
    return in_array($groupId, array_map('intval', $groups), true);
}

function dsaSetGroup(array &$groups, int $groupId, bool $required): void
{
    $groups = array_values(array_unique(array_map('intval', $groups)));
    $hasGroup = dsaHasGroup($groups, $groupId);
    if ($required && !$hasGroup) {
        $groups[] = $groupId;
    } elseif (!$required && $hasGroup) {
        $groups = array_values(array_diff($groups, [$groupId]));
    }
}

function dsaCheck(string $name, string $actual, string $expected): bool
{
    $ok = $actual === $expected;
    dsaLine(sprintf('[%s] %s: current=%s; expected=%s', $ok ? 'OK' : 'MISMATCH', $name, $actual ?: '<empty>', $expected ?: '<empty>'));
    return $ok;
}

/**
 * Возвращает значения списка пользовательского поля в двух представлениях:
 * ID => название и название => ID.
 */
function dsaUserFieldEnum(string $fieldName): array
{
    $field = \CUserTypeEntity::GetList([], [
        'ENTITY_ID' => 'USER',
        'FIELD_NAME' => $fieldName,
    ])->Fetch();
    if (!$field) {
        throw new RuntimeException('Пользовательское поле ' . $fieldName . ' не найдено.');
    }

    $byId = [];
    $byValue = [];
    $rows = \CUserFieldEnum::GetList(['SORT' => 'ASC'], ['USER_FIELD_ID' => (int)$field['ID']]);
    while ($row = $rows->Fetch()) {
        $id = (int)$row['ID'];
        $value = dsaValue($row['VALUE']);
        $byId[$id] = $value;
        $byValue[$value] = $id;
    }

    return ['by_id' => $byId, 'by_value' => $byValue];
}

function dsaDumpSource(string $source, array $values): void
{
    dsaLine($source . ':');
    foreach ($values as $name => $value) {
        dsaLine('  ' . $name . '=' . (dsaValue($value) !== '' ? dsaValue($value) : '<empty>'));
    }
}

$arguments = dsaArguments();
if ($arguments['user_id'] <= 0) {
    dsaLine('Ошибка: укажите пользователя: --user-id=123 или ?user_id=123');
    exit(1);
}

if (!dsaCli()) {
    echo '<pre style="white-space:pre-wrap">';
}

$by = 'id';
$order = 'asc';
$userRows = \CUser::GetList(
    $by,
    $order,
    ['ID' => $arguments['user_id']],
    ['FIELDS' => ['ID', 'LOGIN', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'PERSONAL_BIRTHDAY'], 'SELECT' => ['UF_1C_GUID', 'UF_WEBSLON_ABSENCE_DATE_OF_HIRING', 'UF_WORK_FORMAT']]
);
$user = $userRows->Fetch();
if (!$user) {
    dsaLine('Ошибка: пользователь не найден.');
    exit(2);
}

$fio = trim(dsaValue($user['LAST_NAME']) . ' ' . dsaValue($user['NAME']) . ' ' . dsaValue($user['SECOND_NAME']));
dsaLine('Пользователь: [' . (int)$user['ID'] . '] ' . $fio . ' (' . dsaValue($user['LOGIN']) . ')');
dsaLine('Режим: ' . ($arguments['run'] ? 'исправление' : 'диагностика (dry-run)'));

try {
    $staffRows = dsaStaffByName(Application::getConnection('gatedb'), $user);
    if (count($staffRows) === 0) {
        throw new RuntimeException('В Staff_1CZUP не найден действующий сотрудник с полным совпадением ФИО.');
    }
    if (count($staffRows) > 1) {
        throw new RuntimeException('В Staff_1CZUP найдено несколько действующих сотрудников с таким ФИО; исправление небезопасно.');
    }
    $staff = $staffRows[0];
    $guid = dsaValue($staff['Staff_ID']);
    $activeSchedules = dsaActiveSchedules($guid);
    $schedule = $activeSchedules[0] ?? null;
    $birthDate = dsaDate($staff['Staff_Birthdate'] ?? '');
    $hiringDate = dsaDate($staff['Staff_HiringDate'] ?? '');
    $workFormat = $schedule ? dsaValue($schedule['UF_WORK_FORMAT'] ?? '') : '';
    $needsScheduleGroup = false;
    foreach ($activeSchedules as $activeSchedule) {
        if (strcasecmp(dsaValue($activeSchedule['UF_SCHEDULE_ID'] ?? ''), DSA_WORK_SCHEDULE_ID) === 0) {
            $needsScheduleGroup = true;
            break;
        }
    }
    $subdivision = dsaValue($staff['Staff_Subdivision'] ?? '');
    $notes = dsaValue($staff['Staff_Notes'] ?? '');
    $needsVacationGroup = in_array($subdivision, ['Сотрудники НСК', 'Сотрудники НСК (бывшие КЦ)'], true) || mb_stripos($notes, 'ПЛОТПУСК') !== false;
    $groups = \CUser::GetUserGroup((int)$user['ID']);

    $workFormatEnums = dsaUserFieldEnum('UF_WORK_FORMAT');
    $currentWorkFormatId = (int)dsaValue($user['UF_WORK_FORMAT'] ?? 0);
    $currentWorkFormatName = $workFormatEnums['by_id'][$currentWorkFormatId] ?? '';
    $targetWorkFormatId = $workFormatEnums['by_value'][$workFormat] ?? 0;

    dsaDumpSource('Поля пользователя', [
        'UF_1C_GUID' => $user['UF_1C_GUID'] ?? '',
        'PERSONAL_BIRTHDAY' => dsaDate($user['PERSONAL_BIRTHDAY'] ?? ''),
        'UF_WEBSLON_ABSENCE_DATE_OF_HIRING' => dsaDate($user['UF_WEBSLON_ABSENCE_DATE_OF_HIRING'] ?? ''),
        'UF_WORK_FORMAT_ID' => $currentWorkFormatId,
        'UF_WORK_FORMAT_NAME' => $currentWorkFormatName,
        'GROUPS' => implode(',', array_map('intval', $groups)),
    ]);
    dsaDumpSource('SQL GateDB.dbo.Staff_1CZUP', [
        'Staff_ID' => $guid,
        'Staff_Birthdate' => $birthDate,
        'Staff_HiringDate' => $hiringDate,
        'Staff_Subdivision' => $subdivision,
        'Staff_Notes' => $notes,
    ]);
    if (!$activeSchedules) {
        dsaLine('Действующие записи HL-блока 13: <не найдены>');
    }
    foreach ($activeSchedules as $index => $activeSchedule) {
        dsaDumpSource('Действующая запись HL-блока 13 #' . ($index + 1), [
            'ID' => $activeSchedule['ID'] ?? '',
            'UF_STAFF_ID' => $activeSchedule['UF_STAFF_ID'] ?? '',
            'UF_SCHEDULE_ID' => $activeSchedule['UF_SCHEDULE_ID'] ?? '',
            'UF_WORK_FORMAT' => $activeSchedule['UF_WORK_FORMAT'] ?? '',
            'UF_FORMAT_START_DATE' => dsaDate($activeSchedule['UF_FORMAT_START_DATE'] ?? ''),
            'UF_FORMAT_END_DATE' => dsaDate($activeSchedule['UF_FORMAT_END_DATE'] ?? ''),
        ]);
    }
    dsaLine('Условие группы 46: UF_SCHEDULE_ID=' . DSA_WORK_SCHEDULE_ID .
        '; период включает ' . date('Y-m-d') . '; результат=' . ($needsScheduleGroup ? 'Y' : 'N'));

    $updates = [];
    $hasMismatch = false;
    if (!dsaCheck('UF_1C_GUID', dsaValue($user['UF_1C_GUID'] ?? ''), $guid)) {
        $hasMismatch = true;
        $updates['UF_1C_GUID'] = $guid;
    }
    if (!dsaCheck('PERSONAL_BIRTHDAY', dsaDate($user['PERSONAL_BIRTHDAY'] ?? ''), $birthDate)) {
        $hasMismatch = true;
        $updates['PERSONAL_BIRTHDAY'] = dsaBitrixDate($birthDate);
    }
    if (!dsaCheck('UF_WEBSLON_ABSENCE_DATE_OF_HIRING', dsaDate($user['UF_WEBSLON_ABSENCE_DATE_OF_HIRING'] ?? ''), $hiringDate)) {
        $hasMismatch = true;
        $updates['UF_WEBSLON_ABSENCE_DATE_OF_HIRING'] = dsaBitrixDate($hiringDate);
    }
    if (!dsaCheck('UF_WORK_FORMAT (название)', $currentWorkFormatName, $workFormat)) {
        $hasMismatch = true;
        if ($workFormat === '') {
            $updates['UF_WORK_FORMAT'] = '';
        } elseif ($targetWorkFormatId <= 0) {
            dsaLine('[ERROR] В списке UF_WORK_FORMAT не найдено значение с названием «' . $workFormat . '».');
        } else {
            $updates['UF_WORK_FORMAT'] = $targetWorkFormatId;
        }
    }
    if (!dsaCheck('Группа 46 «Графики работы»', dsaHasGroup($groups, DSA_WORK_SCHEDULE_GROUP_ID) ? 'Y' : 'N', $needsScheduleGroup ? 'Y' : 'N')) {
        $hasMismatch = true;
    }
    if (!dsaCheck('Группа 44 «Планирование отпуска»', dsaHasGroup($groups, DSA_VACATION_GROUP_ID) ? 'Y' : 'N', $needsVacationGroup ? 'Y' : 'N')) {
        $hasMismatch = true;
    }

    $newGroups = $groups;
    dsaSetGroup($newGroups, DSA_WORK_SCHEDULE_GROUP_ID, $needsScheduleGroup);
    dsaSetGroup($newGroups, DSA_VACATION_GROUP_ID, $needsVacationGroup);
    sort($newGroups);
    $oldGroups = array_map('intval', $groups);
    sort($oldGroups);
    $groupsChanged = $oldGroups !== $newGroups;

    if (!$hasMismatch) {
        dsaLine('Результат: все проверяемые значения актуальны.');
    } elseif (!$arguments['run']) {
        dsaLine('Результат: найдены расхождения. Для исправления повторите запуск с --run (или run=Y).');
    } else {
        if ($updates) {
            $updater = new \CUser();
            if (!$updater->Update((int)$user['ID'], $updates)) {
                throw new RuntimeException('Не удалось обновить поля: ' . trim((string)$updater->LAST_ERROR));
            }
            dsaLine('UPDATED: поля пользователя обновлены: ' . implode(', ', array_keys($updates)));
        }
        if ($groupsChanged) {
            \CUser::SetUserGroup((int)$user['ID'], $newGroups);
            dsaLine('UPDATED: состав групп пользователя обновлен.');
        }
        dsaLine('Результат: исправление завершено.');
    }
} catch (Throwable $exception) {
    dsaLine('Ошибка: ' . $exception->getMessage());
    exit(3);
}

if (!dsaCli()) {
    echo '</pre>';
}
