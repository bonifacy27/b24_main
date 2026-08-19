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
const DSA_GROUP_SCHEDULE_HLBLOCK_ID = 2;
const DSA_WORK_SCHEDULE_ID = '6c24d01d-bff8-11e6-826d-001cc060580d';
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
    if (dsaCli()) {
        echo $message . PHP_EOL;
        return;
    }

    $class = 'dsa-line';
    if (strpos($message, '[OK]') === 0 || strpos($message, 'UPDATED:') === 0) {
        $class .= ' dsa-success';
    } elseif (strpos($message, '[MISMATCH]') === 0) {
        $class .= ' dsa-warning';
    } elseif (strpos($message, '[ERROR]') === 0 || strpos($message, 'Ошибка:') === 0) {
        $class .= ' dsa-error';
    } elseif (substr($message, -1) === ':') {
        $class .= ' dsa-heading';
    }
    echo '<div class="' . $class . '">' . htmlspecialcharsbx($message) . '</div>' . PHP_EOL;
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

/**
 * Возвращает записи HL-блока 2 для сотрудника строго на текущий день.
 * Этот источник используется только для определения членства в группе 46.
 */
function dsaTodayGroupSchedules(string $staffGuid): array
{
    if (!Loader::includeModule('highloadblock')) {
        throw new RuntimeException('Модуль highloadblock недоступен.');
    }
    $block = HighloadBlockTable::getById(DSA_GROUP_SCHEDULE_HLBLOCK_ID)->fetch();
    if (!$block) {
        throw new RuntimeException('HL-блок ID=' . DSA_GROUP_SCHEDULE_HLBLOCK_ID . ' не найден.');
    }

    $dataClass = HighloadBlockTable::compileEntity($block)->getDataClass();
    $today = date('Y-m-d');
    $rows = $dataClass::getList([
        'filter' => ['=UF_STAFF_ID' => $staffGuid],
        'order' => ['UF_DATE' => 'DESC', 'ID' => 'DESC'],
    ]);
    $result = [];
    while ($row = $rows->fetch()) {
        if (dsaDate($row['UF_DATE'] ?? '') === $today) {
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
    if (!dsaCli()) {
        echo '<section class="dsa-card"><h2>' . htmlspecialcharsbx($source) . '</h2><dl>';
        foreach ($values as $name => $value) {
            $displayValue = dsaValue($value) !== '' ? dsaValue($value) : '<empty>';
            echo '<div><dt>' . htmlspecialcharsbx((string)$name) . '</dt><dd>' .
                htmlspecialcharsbx($displayValue) . '</dd></div>';
        }
        echo '</dl></section>';
        return;
    }

    dsaLine($source . ':');
    foreach ($values as $name => $value) {
        dsaLine('  ' . $name . '=' . (dsaValue($value) !== '' ? dsaValue($value) : '<empty>'));
    }
}

function dsaRenderWebPageStart(int $selectedUserId): void
{
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Диагностика кадровых данных</title><style>
        :root{color-scheme:light;--bg:#f3f6fb;--surface:#fff;--text:#172033;--muted:#65708a;--line:#dfe5ef;--primary:#315efb;--ok:#16835b;--ok-bg:#eaf8f2;--warn:#a15c00;--warn-bg:#fff5df;--err:#c13232;--err-bg:#ffeded}
        *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.dsa-shell{max-width:1180px;margin:0 auto;padding:36px 24px 60px}.dsa-hero{margin-bottom:24px}.dsa-hero h1{margin:0 0 6px;font-size:30px;letter-spacing:-.02em}.dsa-hero p{margin:0;color:var(--muted)}.dsa-toolbar,.dsa-results{background:var(--surface);border:1px solid var(--line);border-radius:16px;box-shadow:0 8px 28px rgba(29,45,75,.07)}.dsa-toolbar{padding:20px;margin-bottom:22px}.dsa-form{display:grid;grid-template-columns:minmax(220px,1fr) minmax(320px,2fr) auto;gap:12px;align-items:end}.dsa-field label{display:block;margin:0 0 6px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}.dsa-field input,.dsa-field select{width:100%;height:44px;border:1px solid #cbd4e3;border-radius:9px;background:#fff;padding:0 12px;font:inherit;color:var(--text)}.dsa-button{height:44px;border:0;border-radius:9px;padding:0 22px;background:var(--primary);color:#fff;font-weight:700;cursor:pointer}.dsa-results{padding:22px}.dsa-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin:18px 0}.dsa-card{border:1px solid var(--line);border-radius:12px;padding:17px;background:#fbfcfe}.dsa-card h2{font-size:16px;margin:0 0 12px}.dsa-card dl{margin:0}.dsa-card dl div{display:grid;grid-template-columns:minmax(180px,.8fr) minmax(0,1.2fr);gap:12px;padding:7px 0;border-top:1px solid #edf0f5}.dsa-card dt{color:var(--muted)}.dsa-card dd{margin:0;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;overflow-wrap:anywhere}.dsa-line{padding:9px 12px;margin:7px 0;border-radius:8px;background:#f5f7fa;overflow-wrap:anywhere}.dsa-success{color:var(--ok);background:var(--ok-bg);border-left:4px solid var(--ok)}.dsa-warning{color:var(--warn);background:var(--warn-bg);border-left:4px solid #e49a20}.dsa-error{color:var(--err);background:var(--err-bg);border-left:4px solid var(--err)}.dsa-heading{margin-top:18px;font-weight:700;background:transparent;padding-left:0}.dsa-empty{padding:34px;text-align:center;color:var(--muted)}@media(max-width:760px){.dsa-shell{padding:22px 14px}.dsa-form{grid-template-columns:1fr}.dsa-grid{grid-template-columns:1fr}.dsa-card dl div{grid-template-columns:1fr;gap:2px}}
    </style></head><body><main class="dsa-shell"><header class="dsa-hero"><h1>Диагностика кадровых данных</h1><p>Проверка полей пользователя, SQL и кадровых расписаний HL-блоков</p></header>';
    echo '<section class="dsa-toolbar"><form class="dsa-form" method="get"><div class="dsa-field"><label for="user-search">Поиск</label><input id="user-search" type="search" placeholder="ФИО или логин"></div><div class="dsa-field"><label for="user-id">Пользователь</label><select id="user-id" name="user_id" required><option value="">Выберите пользователя</option>';
    $by = 'last_name';
    $order = 'asc';
    $users = \CUser::GetList($by, $order, ['ACTIVE' => 'Y'], ['FIELDS' => ['ID', 'LOGIN', 'NAME', 'LAST_NAME', 'SECOND_NAME']]);
    while ($item = $users->Fetch()) {
        $id = (int)$item['ID'];
        $label = trim(dsaValue($item['LAST_NAME']) . ' ' . dsaValue($item['NAME']) . ' ' . dsaValue($item['SECOND_NAME'])) . ' (' . dsaValue($item['LOGIN']) . ')';
        echo '<option value="' . $id . '"' . ($id === $selectedUserId ? ' selected' : '') . '>' . htmlspecialcharsbx($label) . '</option>';
    }
    echo '</select></div><button class="dsa-button" type="submit">Проверить</button></form></section><section class="dsa-results">';
    echo '<script>const q=document.getElementById("user-search"),s=document.getElementById("user-id");q.addEventListener("input",()=>{const v=q.value.toLocaleLowerCase("ru");for(const o of s.options){o.hidden=o.value!==""&&!o.text.toLocaleLowerCase("ru").includes(v)}});</script>';
}

function dsaRenderWebPageEnd(): void
{
    echo '</section></main></body></html>';
}

$arguments = dsaArguments();
if (!dsaCli()) {
    dsaRenderWebPageStart((int)$arguments['user_id']);
}
if ($arguments['user_id'] <= 0) {
    if (!dsaCli()) {
        echo '<div class="dsa-empty">Выберите сотрудника, чтобы запустить диагностику.</div>';
        dsaRenderWebPageEnd();
        exit;
    }
    dsaLine('Ошибка: укажите пользователя: --user-id=123 или ?user_id=123');
    exit(1);
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
    $todayGroupSchedules = dsaTodayGroupSchedules($guid);
    $schedule = $activeSchedules[0] ?? null;
    $birthDate = dsaDate($staff['Staff_Birthdate'] ?? '');
    $hiringDate = dsaDate($staff['Staff_HiringDate'] ?? '');
    $workFormat = $schedule ? dsaValue($schedule['UF_WORK_FORMAT'] ?? '') : '';
    $needsScheduleGroup = false;
    foreach ($todayGroupSchedules as $todayGroupSchedule) {
        if (strcasecmp(dsaValue($todayGroupSchedule['UF_SCHED_ID'] ?? ''), DSA_WORK_SCHEDULE_ID) === 0) {
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
    if (!$todayGroupSchedules) {
        dsaLine('Записи HL-блока 2 на текущий день: <не найдены>');
    }
    foreach ($todayGroupSchedules as $index => $todayGroupSchedule) {
        dsaDumpSource('Запись HL-блока 2 на текущий день #' . ($index + 1), [
            'ID' => $todayGroupSchedule['ID'] ?? '',
            'UF_STAFF_ID' => $todayGroupSchedule['UF_STAFF_ID'] ?? '',
            'UF_SCHED_ID' => $todayGroupSchedule['UF_SCHED_ID'] ?? '',
            'UF_DATE' => dsaDate($todayGroupSchedule['UF_DATE'] ?? ''),
        ]);
    }
    dsaLine('Условие группы 46 по HL-блоку 2: UF_SCHED_ID=' . DSA_WORK_SCHEDULE_ID .
        '; UF_DATE=' . date('Y-m-d') . '; результат=' . ($needsScheduleGroup ? 'Y' : 'N'));

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
    dsaRenderWebPageEnd();
}
