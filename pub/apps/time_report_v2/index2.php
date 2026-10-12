<?php
/**
* Отчет по рабочему времени сотрудников (HL91 + HL5) + Excel без шаблона.
* Версия: v1.3.7
*
* Правки v1.3.7:
* - Столбец "Формат работы по графику удаленной работы" переименован в
*   "Формат работы по графику работы".
* - Если у сотрудника UF_WORK_FORMAT=1929 ("Офис"), а в HL91 на дату:
*   UF_PRESENCE_TYPE = "Офис" и UF_STATUS = 1 ("Корректировка"),
*   то в поле формата работы выводим "Офис" без "(несогл.)".
*
* Правки v1.3.6:
* - Статус дня = "Работа", если "Формат работы по графику работы" = "Дистанционно" (по значению колонки, а не по норме).
*
* Правки v1.3.5:
* - Для удалённого формата: статус дня = "Работа", если формат работы по графику работы = "Дистанционно".
*
* Правки v1.3.4:
* - Для сотрудников с UF_WORK_FORMAT=1930 ("Дистанционно"):
*   - В поле "Формат работы по графику удаленной работы" всегда выводим "Дистанционно" (без "(несогл.)").
*   - В поле "Статус дня" всегда выводим "Работа" (если норма не 00:00), т.к. график не подлежит согласованию.
* - Добавлен столбец "Дата трудоустройства" (UF_WEBSLON_ABSENCE_DATE_OF_HIRING).
* - В поле "Статус дня" добавлено отображение "Болезнь", если UF_DAY_STATUS содержит
*   "Болезнь" или "Отсутствие по невыясненным причинам".
*
* Правки v1.3.3:
* - Добавлен экспорт в HTML-XLS формат (кнопка первая)
* - Экспорт в CSV остался второй кнопкой
* - Оба формата доступны для больших объемов данных
*
* Правки v1.3.2:
* - "(несогл.)" добавляем только для типов присутствия "Офис" или "Дистанционно".
* - Для удалёнщиков (UF_WORK_FORMAT=1930) "Дистанционно" тоже может получать "(несогл.)",
*   если по HL91 на дату есть UF_STATUS=1.
*/
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime as BxDateTime;
use Bitrix\Highloadblock as HL;
use Bitrix\Main\Application;
use Bitrix\Main\UserFieldTable;

Loader::includeModule('main');
Loader::includeModule('iblock');
Loader::includeModule('highloadblock');
Loader::includeModule('intranet');

// ----------------------------
// CONFIG
// ----------------------------
const ORG_IBLOCK_ID = 5;
const HL_SCHEDULES  = 91;
const HL_RECALC     = 5;
const HL_EVENTS     = 3;
const HL_EVENT_ENTRY = 45;
const HL_EVENT_EXIT  = 46;
const HL_PRES_TYPES = 93;

const DATE_FIELD_CANDIDATES = ['UF_DATE','UF_DAY','UF_WORK_DATE','UF_DATE_TIME','UF_DATETIME','UF_DAY_DATE'];
const USER_FIELD_CANDIDATES = ['UF_USER','UF_USER_ID','UF_EMPLOYEE','UF_PERSON','UF_STAFF','UF_EMP','UF_WORKER'];
const USER_ENUM_FIELDS = ['UF_WORK_BEGIN','UF_SCHEDULE_TYPE','UF_COMPANY','UF_MAIN_OFFICE','UF_WORK_FORMAT'];

const HTML_MAX_ROWS = 1500;
const HL91_UNAPPROVED_STATUS = 1;          // UF_STATUS = 1 -> несогласован / корректировка
const USER_WORK_FORMAT_OFFICE_ID = 1929;   // UF_WORK_FORMAT = 1929 -> "Офис"
const USER_WORK_FORMAT_REMOTE_ID = 1930;   // UF_WORK_FORMAT = 1930 -> "Дистанционно" всегда
const EXCLUDED_USER_IDS = [6054, 6278, 5983, 3418, 3377, 1, 5119, 6234, 4864];
const EXCEL_USER_CHUNK = 200;              // размер чанка пользователей для загрузки HL
const CSV_FORCE_THRESHOLD = 100000;        // если строк больше — экспортируем CSV

// ----------------------------
// INPUT
// ----------------------------
$exportFormat = $_GET['export'] ?? ''; // '', 'HTMLXLS', 'CSV', 'Y'

$defaultFrom = (new DateTime('first day of last month'))->format('Y-m-d');
$defaultTo   = (new DateTime('last day of last month'))->format('Y-m-d');

$dateFromStr = $_GET['date_from'] ?? $defaultFrom;
$dateToStr   = $_GET['date_to']   ?? $defaultTo;

$allDepartments     = (($_GET['ALL_DEPARTMENTS'] ?? '') === 'Y');
$selectedSectionId  = (int)($_GET['SECTION_ID'] ?? 0);

// ----------------------------
// VALIDATION (<= 6 months)
// ----------------------------
$dateErrors = [];

if (!$dateFromStr || !$dateToStr) {
    $dateErrors[] = 'Укажите обе даты — "с" и "по".';
} else {
    $from = DateTime::createFromFormat('Y-m-d', $dateFromStr);
    $to   = DateTime::createFromFormat('Y-m-d', $dateToStr);

    if (!$from || !$to) {
        $dateErrors[] = 'Некорректный формат даты.';
    } elseif ($from > $to) {
        $dateErrors[] = 'Дата начала позже даты окончания.';
    } else {
        $diff = $from->diff($to);
        $months = ($diff->y * 12) + $diff->m;
        if ($months > 6) {
            $dateErrors[] = 'Период отчета не может превышать 6 месяцев.';
        }
    }
}

if (!empty($dateErrors)) {
    if ($exportFormat === '') {
        foreach ($dateErrors as $e) echo "<p style='color:red;font-family:Arial'>".htmlspecialchars($e)."</p>";
    }
    return;
}

$fromStart = new BxDateTime($dateFromStr . ' 00:00:00', 'Y-m-d H:i:s');
$toEnd     = new BxDateTime($dateToStr   . ' 23:59:59', 'Y-m-d H:i:s');

$dateFromStrDisplay = (new DateTime($dateFromStr))->format('d.m.Y');
$dateToStrDisplay   = (new DateTime($dateToStr))->format('d.m.Y');

// ----------------------------
// HELPERS
// ----------------------------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function buildUrlWithParams(array $overrides = []): string
{
    $params = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($params[$k]);
        else $params[$k] = $v;
    }
    $qs = http_build_query($params);
    $path = strtok($_SERVER['REQUEST_URI'], '?');
    return $path . ($qs ? ('?' . $qs) : '');
}

function getHLDataClass(int $hlId)
{
    $hlBlock = HL\HighloadBlockTable::getById($hlId)->fetch();
    if (!$hlBlock) throw new Exception("HL-блок {$hlId} не найден");
    $entity = HL\HighloadBlockTable::compileEntity($hlBlock);
    return $entity->getDataClass();
}

function getHLUserFieldsMap(int $hlId): array
{
    $entityId = 'HLBLOCK_' . $hlId;
    $map = [];
    $res = UserFieldTable::getList([
        'select' => ['FIELD_NAME'],
        'filter' => ['=ENTITY_ID' => $entityId]
    ]);
    while ($r = $res->fetch()) $map[(string)$r['FIELD_NAME']] = true;
    return $map;
}

function detectField(array $fieldsMap, array $candidates, string $fallback): string
{
    foreach ($candidates as $c) {
        if (isset($fieldsMap[$c])) return $c;
    }
    return $fallback;
}

function parseDepartments($raw): array
{
    if (is_array($raw)) return array_values(array_unique(array_map('intval', $raw)));
    if (!is_string($raw) || $raw === '') return [];

    $try = @unserialize($raw, ['allowed_classes' => false]);
    if ($try !== false && is_array($try)) {
        return array_values(array_unique(array_map('intval', $try)));
    }

    if (strpos($raw, ',') !== false) {
        $parts = array_map('trim', explode(',', $raw));
        return array_values(array_unique(array_map('intval', $parts)));
    }

    if (is_numeric($raw)) return [(int)$raw];
    return [];
}

function getDepartmentTree($parentId = 0, $level = 0): string
{
    $result = '';
    $rsSections = CIBlockSection::GetList(
        ['SORT' => 'ASC'],
        ['IBLOCK_ID' => ORG_IBLOCK_ID, 'SECTION_ID' => $parentId, 'ACTIVE' => 'Y'],
        false,
        ['ID','NAME']
    );

    while ($ar = $rsSections->Fetch()) {
        $indent   = str_repeat('&nbsp;&nbsp;&nbsp;', $level);
        $selected = (($_GET['SECTION_ID'] ?? 0) == $ar['ID']) ? 'selected' : '';
        $result .= "<option value=\"{$ar['ID']}\" {$selected}>{$indent}" . h($ar['NAME']) . "</option>";
        $result .= getDepartmentTree($ar['ID'], $level + 1);
    }
    return $result;
}

function getAllStructureSections(int $parentId): array
{
    $parent = CIBlockSection::GetList(
        [],
        ['IBLOCK_ID' => ORG_IBLOCK_ID, 'ID' => $parentId],
        false,
        ['ID','LEFT_MARGIN','RIGHT_MARGIN']
    )->Fetch();

    if (!$parent) return [];

    $result = [];
    $rsSections = CIBlockSection::GetList(
        ['LEFT_MARGIN' => 'ASC'],
        [
            'IBLOCK_ID' => ORG_IBLOCK_ID,
            '>LEFT_MARGIN' => $parent['LEFT_MARGIN'],
            '<RIGHT_MARGIN' => $parent['RIGHT_MARGIN'],
            'ACTIVE' => 'Y'
        ],
        false,
        ['ID']
    );
    while ($s = $rsSections->Fetch()) $result[] = (int)$s['ID'];
    return $result;
}

function getUsersByDepartments(array $departmentIds): array
{
    $departmentIds = array_values(array_unique(array_map('intval', $departmentIds)));
    if (empty($departmentIds)) return [];

    $serialized = [];
    foreach ($departmentIds as $id) {
        $serialized[] = "'" . Application::getConnection()->getSqlHelper()->forSql(serialize([$id])) . "'";
    }
    $in = implode(',', $serialized);

    $sql = "
SELECT
u.ID, u.LOGIN, u.NAME, u.LAST_NAME, u.SECOND_NAME, u.WORK_POSITION, u.WORK_DEPARTMENT,
uts.UF_DEPARTMENT,
uts.UF_PERSONNEL_NUMBER,
uts.UF_WEBSLON_ABSENCE_DATE_OF_HIRING,
uts.UF_WORK_BEGIN,
uts.UF_SCHEDULE_TYPE,
uts.UF_COMPANY,
uts.UF_MAIN_OFFICE,
uts.UF_WORK_FORMAT
FROM b_user u
JOIN b_uts_user uts ON uts.VALUE_ID = u.ID
WHERE u.ACTIVE='Y' AND uts.UF_DEPARTMENT IN ({$in})
ORDER BY u.LAST_NAME ASC, u.NAME ASC
";
    return Application::getConnection()->query($sql)->fetchAll();
}

function getAllActiveUsers(): array
{
    $sql = "
SELECT
u.ID, u.LOGIN, u.NAME, u.LAST_NAME, u.SECOND_NAME, u.WORK_POSITION, u.WORK_DEPARTMENT,
uts.UF_DEPARTMENT,
uts.UF_PERSONNEL_NUMBER,
uts.UF_WEBSLON_ABSENCE_DATE_OF_HIRING,
uts.UF_WORK_BEGIN,
uts.UF_SCHEDULE_TYPE,
uts.UF_COMPANY,
uts.UF_MAIN_OFFICE,
uts.UF_WORK_FORMAT
FROM b_user u
JOIN b_uts_user uts ON uts.VALUE_ID = u.ID
WHERE u.ACTIVE='Y'
ORDER BY u.LAST_NAME ASC, u.NAME ASC
";
    return Application::getConnection()->query($sql)->fetchAll();
}

function getUserFio(array $u): string
{
    $fio = trim(($u['LAST_NAME'] ?? '') . ' ' . ($u['NAME'] ?? '') . ' ' . ($u['SECOND_NAME'] ?? ''));
    return $fio ?: (string)($u['LOGIN'] ?? $u['ID'] ?? '');
}

function getUserByIdFull(int $userId, array $selectUf = ['UF_DEPARTMENT']): array
{
    if ($userId <= 0) return [];
    $rs = CUser::GetList(
        ($by='ID'), ($order='ASC'),
        ['ID_EQUAL_EXACT' => $userId],
        ['SELECT' => $selectUf]
    );
    $u = $rs->Fetch();
    return is_array($u) ? $u : [];
}

function getReliableManager(int $userId): array
{
    if ($userId <= 0) return [];
    if (!class_exists('CIntranetUtils')) return [];

    $arUser = getUserByIdFull($userId, ['UF_DEPARTMENT','WORK_POSITION','WORK_DEPARTMENT','NAME','LAST_NAME','SECOND_NAME','LOGIN']);
    if (empty($arUser) || empty($arUser['ID'])) return [];

    $deps = parseDepartments($arUser['UF_DEPARTMENT'] ?? []);
    if (empty($deps)) return [];

    $arManagers = \CIntranetUtils::GetDepartmentManager($deps, (int)$arUser['ID'], true);
    if (is_array($arManagers) && !empty($arManagers)) {
        foreach ($arManagers as $value) {
            if (!is_array($value) || empty($value['ID'])) continue;
            if ((int)$value['ID'] === (int)$arUser['ID']) continue;
            return $value;
        }
    }
    return [];
}

function safeGetClosestDirectorId(int $userId): int
{
    if ($userId <= 0) return 0;
    if (!function_exists('getClosestDirector')) return 0;

    try {
        $val = getClosestDirector($userId);
        $id = (int)$val;
        return $id > 0 ? $id : 0;
    } catch (\Throwable $e) {
        return 0;
    }
}

function getCeo1NameByUserId(int $userId): string
{
    $dirId = safeGetClosestDirectorId($userId);
    if ($dirId <= 0) return '';
    $dir = getUserByIdFull($dirId, ['WORK_DEPARTMENT']);
    return (string)($dir['WORK_DEPARTMENT'] ?? '');
}

function getCeo2NameByUserId(int $userId): string
{
    $ceo1Id = safeGetClosestDirectorId($userId);
    if ($ceo1Id <= 0) return '';

    $ceo1 = getUserByIdFull($ceo1Id, ['UF_DEPARTMENT']);
    $deps = parseDepartments($ceo1['UF_DEPARTMENT'] ?? []);
    if (empty($deps)) return '';

    foreach ($deps as $rootDep) {
        $queue = [$rootDep];
        $visited = [];

        while ($queue) {
            $depId = array_shift($queue);
            if (isset($visited[$depId])) continue;
            $visited[$depId] = true;

            $children = [];
            $rs = CIBlockSection::GetList(['SORT'=>'ASC'], ['IBLOCK_ID'=>ORG_IBLOCK_ID,'SECTION_ID'=>$depId,'ACTIVE'=>'Y'], false, ['ID']);
            while ($c = $rs->Fetch()) $children[] = (int)$c['ID'];

            foreach ($children as $childDep) {
                $rsUsers = CUser::GetList(
                    'ID','ASC',
                    ['ACTIVE'=>'Y','UF_DEPARTMENT'=>$childDep],
                    ['SELECT'=>['ID','WORK_POSITION','WORK_DEPARTMENT']]
                );

                while ($u = $rsUsers->Fetch()) {
                    $uid = (int)$u['ID'];
                    if ($uid === $ceo1Id) continue;
                    $pos = (string)($u['WORK_POSITION'] ?? '');
                    if (mb_stripos($pos, 'директор') !== false) {
                        return (string)($u['WORK_DEPARTMENT'] ?? '');
                    }
                }
            }

            foreach ($children as $childDep) $queue[] = $childDep;
        }
    }
    return '';
}

function dateRangeYmd(string $fromYmd, string $toYmd): array
{
    $out = [];
    $cur = new DateTime($fromYmd);
    $end = new DateTime($toYmd);

    while ($cur <= $end) {
        $out[] = $cur->format('Y-m-d');
        $cur->modify('+1 day');
    }
    return $out;
}

function normVal($v): string
{
    if ($v === null) return '';
    if ($v instanceof \Bitrix\Main\Type\DateTime) return $v->format('H:i');
    if ($v instanceof \Bitrix\Main\Type\Date) return $v->format('d.m.Y');
    if ($v instanceof \DateTime) return $v->format('H:i');
    if (is_array($v)) return implode(', ', array_map('strval', $v));
    return trim((string)$v);
}

function cleanAppsValue(string $val): string
{
    $val = trim($val);
    if ($val === '') return '';

    $parts = preg_split('/[,;]+/u', $val);
    $parts = array_map('trim', $parts);
    $parts = array_filter($parts, static function($p){
        return $p !== '' && mb_strtoupper($p) !== 'УР';
    });

    return implode(', ', $parts);
}

function loadUserEnumMaps(array $fieldNames): array
{
    $out = [];

    foreach ($fieldNames as $fieldName) {
        $out[$fieldName] = [];
        $uf = CUserTypeEntity::GetList([], ['ENTITY_ID'=>'USER', 'FIELD_NAME'=>$fieldName])->Fetch();
        if (!$uf || empty($uf['ID'])) continue;

        $rs = CUserFieldEnum::GetList(['SORT'=>'ASC'], ['USER_FIELD_ID' => (int)$uf['ID']]);
        while ($e = $rs->Fetch()) $out[$fieldName][(int)$e['ID']] = (string)$e['VALUE'];
    }
    return $out;
}

function enumToText(array $enumMap, $value): string
{
    if ($value === null || $value === '') return '';

    if (is_array($value)) {
        $vals = [];
        foreach ($value as $v) {
            $id = (int)$v;
            $vals[] = $enumMap[$id] ?? (string)$v;
        }
        return implode(', ', $vals);
    }

    $id = (int)$value;
    return $enumMap[$id] ?? (string)$value;
}

function isPresenceOffice(string $presenceName): bool
{
    return trim(mb_strtolower($presenceName)) === 'офис';
}

/**
* Добавляем "(несогл.)" только если тип присутствия "Офис" или "Дистанционно".
*/
function shouldAddUnapprovedSuffix(string $presenceName): bool
{
    $p = trim(mb_strtolower($presenceName));
    return ($p === 'офис' || $p === 'дистанционно');
}

function formatPresenceWithStatus(string $name, bool $unapproved): string
{
    $name = trim($name);
    if ($name === '') return '';

    if ($unapproved && shouldAddUnapprovedSuffix($name)) {
        return $name . ' (несогл.)';
    }
    return $name;
}

function disableOutputBuffers(): void
{
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
}

function csvClean(string $s): string
{
    $s = str_replace(["\r\n", "\n", "\r"], ' ', $s);
    return $s;
}


/** Signal that the download is ready; downloads leave the report form on screen. */
function signalReportDownloadReady(): void
{
    $token = $_GET['download_token'] ?? '';
    if (!is_string($token) || !preg_match('/^[a-z0-9]{1,64}$/D', $token)) return;
    setcookie('time_report_download', $token, [
        'expires' => time() + 300,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

// ----------------------------
// UI FORM
// ----------------------------
if ($exportFormat === '') {
    echo "<h1 style='font-family:Arial'>Отчет по рабочему времени сотрудников</h1>";
    echo "
<form id='time-report-form' method='GET' style='margin:15px 0; font-family:Arial'>
<label><b>Период:</b></label><br>
<label>с <input type='date' name='date_from' value='".h($dateFromStr)."'></label>
&nbsp;&nbsp;
<label>по <input type='date' name='date_to' value='".h($dateToStr)."'></label>
<br><br>
<label>
<input type='checkbox' name='ALL_DEPARTMENTS' value='Y' ".($allDepartments ? 'checked' : '').">
Построить отчет по всем подразделениям
</label>
<br><br>
<label><b>Подразделение (если не выбрано «по всем»):</b></label><br>
<select name='SECTION_ID' ".($allDepartments ? 'disabled' : '').">
<option value='0'>-- не выбрано --</option>
".getDepartmentTree()."
</select>
<br><br>
<button type='submit'>Построить отчет</button>
<button type='submit' name='export' value='HTMLXLS'>Выгрузить (HTML-XLS)</button>
<button type='submit' name='export' value='CSV'>Выгрузить (CSV)</button>
</form>

<script>
(function () {
    var form = document.getElementById('time-report-form');
    function controls() { return document.querySelectorAll('#time-report-form button, a[data-report-export]'); }
    function lock() {
        busy = true;
        form.setAttribute('aria-busy', 'true');
        controls().forEach(function (control) {
            if (control.tagName === 'BUTTON') control.disabled = true;
            control.setAttribute('aria-disabled', 'true');
            control.style.opacity = '0.5';
            control.style.cursor = 'wait';
        });
    }
    var busy = false;
    var poll = null;
    var tokenInput = null;
    var exportInput = null;
    function reset() {
        busy = false;
        controls().forEach(function (button) {
            if (button.tagName === 'BUTTON') button.disabled = false;
            button.removeAttribute('aria-disabled');
            button.style.opacity = '';
            button.style.cursor = '';
        });
        form.removeAttribute('aria-busy');
        if (poll) { clearInterval(poll); poll = null; }
        if (tokenInput) { tokenInput.remove(); tokenInput = null; }
        if (exportInput) { exportInput.remove(); exportInput = null; }
    }
    form.addEventListener('submit', function (event) {
        if (busy) { event.preventDefault(); return; }
        var submitter = event.submitter || document.activeElement;
        // Disabled buttons are not submitted: preserve the selected export format.
        if (submitter && submitter.form === form && submitter.name === 'export') {
            exportInput = document.createElement('input');
            exportInput.type = 'hidden';
            exportInput.name = 'export';
            exportInput.value = submitter.value;
            form.appendChild(exportInput);
            var token = Date.now().toString(36) + Math.random().toString(36).slice(2);
            tokenInput = document.createElement('input');
            tokenInput.type = 'hidden';
            tokenInput.name = 'download_token';
            tokenInput.value = token;
            form.appendChild(tokenInput);
            poll = setInterval(function () {
                if (document.cookie.split('; ').indexOf('time_report_download=' + token) !== -1) {
                    document.cookie = 'time_report_download=; Max-Age=0; Path=/; SameSite=Lax';
                    reset();
                }
            }, 500);
        }
        lock();
    });
    document.addEventListener('click', function (event) {
        var link = event.target.closest('a[data-report-export]');
        if (!link) return;
        event.preventDefault();
        if (busy) return;
        var token = Date.now().toString(36) + Math.random().toString(36).slice(2);
        var url = new URL(link.href, window.location.href);
        url.searchParams.set('download_token', token);
        lock();
        poll = setInterval(function () {
            if (document.cookie.split('; ').indexOf('time_report_download=' + token) !== -1) {
                document.cookie = 'time_report_download=; Max-Age=0; Path=/; SameSite=Lax';
                reset();
            }
        }, 500);
        window.location.assign(url.href);
    });
    // A streamed response can show the form while the report is still running.
    var query = new URLSearchParams(window.location.search);
    if (query.get('ALL_DEPARTMENTS') === 'Y' || Number(query.get('SECTION_ID')) > 0) {
        lock();
        window.addEventListener('load', reset, { once: true });
    }
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) reset();
    });
})();
</script>
<hr>";
}

if (!$allDepartments && $selectedSectionId <= 0) {
    if ($exportFormat === '') echo "<p style='font-family:Arial'>Выберите подразделение или включите режим «по всем подразделениям».</p>";
    return;
}

// ----------------------------
// BUILD USERS LIST
// ----------------------------
$users = [];
$departmentIds = [];

if ($allDepartments) {
    $users = getAllActiveUsers();
} else {
    $departmentIds = getAllStructureSections($selectedSectionId);
    $departmentIds[] = $selectedSectionId;
    $departmentIds = array_values(array_unique(array_map('intval', $departmentIds)));
    $users = getUsersByDepartments($departmentIds);
}

if (empty($users)) {
    if ($exportFormat === '') echo "<p style='font-family:Arial'>Сотрудники не найдены.</p>";
    return;
}

// исключения применяем сразу
$excludedMap = array_fill_keys(EXCLUDED_USER_IDS, true);
$users = array_values(array_filter($users, static function($u) use ($excludedMap){
    $id = (int)($u['ID'] ?? 0);
    return $id > 0 && !isset($excludedMap[$id]);
}));

if (empty($users)) {
    if ($exportFormat === '') echo "<p style='font-family:Arial'>После исключений список сотрудников пуст.</p>";
    return;
}

$usersById = [];
$userIds = [];
foreach ($users as $u) {
    $uid = (int)$u['ID'];
    $usersById[$uid] = $u;
    $userIds[] = $uid;
}
$userIds = array_values(array_unique($userIds));

$dates = dateRangeYmd($dateFromStr, $dateToStr);

// ----------------------------
// PRE-CHECK rows count for HTML
// ----------------------------
$estimatedRows = count($userIds) * count($dates);

// ----------------------------
// EXPORT decision: show warning for huge HTML
// ----------------------------
if ($exportFormat === '' && $estimatedRows > HTML_MAX_ROWS) {
    $htmlxlsUrl = buildUrlWithParams(['export' => 'HTMLXLS']);
    $csvUrl = buildUrlWithParams(['export' => 'CSV']);

    echo "<div style='font-family:Arial; padding:12px; background:#fff3cd; border:1px solid #ffeeba; border-radius:6px;'>";
    echo "<b>Слишком много строк для вывода в браузере.</b><br>";
    echo "Оценочно строк: <b>".(int)$estimatedRows."</b> (сотрудников: <b>".count($userIds)."</b>, дней: <b>".count($dates)."</b>).<br>";
    echo "<div style='margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;'>";
    echo "<a data-report-export href='".h($htmlxlsUrl)."' style='display:inline-block;padding:8px 12px;background:#007bff;color:#fff;text-decoration:none;border-radius:4px;'>
Выгрузить в HTML-XLS (рекомендуется)
</a>";
    echo "<a data-report-export href='".h($csvUrl)."' style='display:inline-block;padding:8px 12px;background:#28a745;color:#fff;text-decoration:none;border-radius:4px;'>
Выгрузить в CSV
</a>";
    echo "</div>";
    echo "</div>";

    require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/epilog_after.php");
    return;
}

// ----------------------------
// Общие справочники/поля HL
// ----------------------------
$enumMaps = loadUserEnumMaps(USER_ENUM_FIELDS);

$hl5Fields  = getHLUserFieldsMap(HL_RECALC);
$hl91Fields = getHLUserFieldsMap(HL_SCHEDULES);

$HL5_FIELD_DATE = detectField($hl5Fields, DATE_FIELD_CANDIDATES, 'UF_DATE');
$HL5_FIELD_USER = detectField($hl5Fields, USER_FIELD_CANDIDATES, 'UF_USER');

$HL91_FIELD_DATE = detectField($hl91Fields, DATE_FIELD_CANDIDATES, 'UF_DATE');
$HL91_FIELD_USER = detectField($hl91Fields, USER_FIELD_CANDIDATES, 'UF_USER');

// справочник типов присутствия HL93
$presenceTypeMap = [];
try {
    $PresenceClass = getHLDataClass(HL_PRES_TYPES);
    $res = $PresenceClass::getList([
        'select' => ['ID','UF_NAME'],
        'order'  => ['UF_NAME'=>'ASC'],
    ]);
    while ($r = $res->fetch()) $presenceTypeMap[(int)$r['ID']] = (string)($r['UF_NAME'] ?? '');
} catch (\Throwable $e) {}

// ----------------------------
// HEADERS
// ----------------------------
$headers = [
    'Табельный номер','Сотрудник','Дата трудоустройства','Дата','Норма','Начало дня',
    'Формат работы по графику работы','Статус дня','Время прихода','Время ухода',
    'Заявки','Отработано','Недоработка','Переработка','График','Компания','Должность',
    'Дирекция (СЕО-1)','Дирекция (СЕО-2)','Подразделение','Руководитель','Должность рук-ля','Офис'
];

// ======================================================================
// Функция для генерации строк отчета
// ======================================================================
function generateReportRows(
    array $userIds,
    array $usersById,
    array $dates,
    array $enumMaps,
    array $presenceTypeMap,
    BxDateTime $fromStart,
    BxDateTime $toEnd,
    string $HL5_FIELD_DATE,
    string $HL5_FIELD_USER,
    string $HL91_FIELD_DATE,
    string $HL91_FIELD_USER,
    int $chunkSize = EXCEL_USER_CHUNK
): array
{
    $resultRows = [];
    $userIdChunks = array_chunk($userIds, $chunkSize);

    foreach ($userIdChunks as $chunkIds) {
        $schedulesByUserDate = [];

        try {
            $SchedulesClass = getHLDataClass(HL_SCHEDULES);
            $res = $SchedulesClass::getList([
                'select' => ['ID', $HL91_FIELD_DATE, $HL91_FIELD_USER, 'UF_PRESENCE_TYPE', 'UF_STATUS'],
                'filter' => [
                    '>=' . $HL91_FIELD_DATE => $fromStart,
                    '<=' . $HL91_FIELD_DATE => $toEnd,
                    '=' . $HL91_FIELD_USER => $chunkIds
                ],
            ]);

            while ($row = $res->fetch()) {
                $uid = (int)$row[$HL91_FIELD_USER];
                $d   = $row[$HL91_FIELD_DATE];
                $ymd = ($d instanceof \Bitrix\Main\Type\Date || $d instanceof \Bitrix\Main\Type\DateTime)
                    ? $d->format('Y-m-d')
                    : (new DateTime((string)$d))->format('Y-m-d');

                $ptId = (int)($row['UF_PRESENCE_TYPE'] ?? 0);
                $ptName = $presenceTypeMap[$ptId] ?? ($ptId ? ('ID '.$ptId) : '');
                $status = (int)($row['UF_STATUS'] ?? 0);
                $unapproved = ($status === HL91_UNAPPROVED_STATUS);

                $schedulesByUserDate[$uid][$ymd] = [
                    'NAME' => $ptName,
                    'UNAPPROVED' => $unapproved,
                ];
            }
        } catch (\Throwable $e) {}

        $recalcByUserDate = [];

        try {
            $RecalcClass = getHLDataClass(HL_RECALC);
            $res = $RecalcClass::getList([
                'select' => [
                    'ID',
                    $HL5_FIELD_DATE,
                    $HL5_FIELD_USER,
                    'UF_NORMA',
                    'UF_DAY_STATUS',
                    'UF_ENTRY_TIME',
                    'UF_EXIT_TIME',
                    'UF_APP',
                    'UF_WT_TOTAL',
                    'UF_UNDERWORK',
                    'UF_OVERWORK',
                ],
                'filter' => [
                    '>=' . $HL5_FIELD_DATE => $fromStart,
                    '<=' . $HL5_FIELD_DATE => $toEnd,
                    '=' . $HL5_FIELD_USER => $chunkIds
                ],
            ]);

            while ($row = $res->fetch()) {
                $uid = (int)$row[$HL5_FIELD_USER];
                $d   = $row[$HL5_FIELD_DATE];
                $ymd = ($d instanceof \Bitrix\Main\Type\Date || $d instanceof \Bitrix\Main\Type\DateTime)
                    ? $d->format('Y-m-d')
                    : (new DateTime((string)$d))->format('Y-m-d');

                $recalcByUserDate[$uid][$ymd] = $row;
            }
        } catch (\Throwable $e) {}

        // HL3 дополняет только дни, отсутствующие в основном отчете HL5.
        $eventsByUserDate = [];
        $EventsClass = getHLDataClass(HL_EVENTS);
        $events = $EventsClass::getList([
            'select' => ['ID', 'UF_DATETIME', 'UF_USER_ID', 'UF_EVENT'],
            'filter' => [
                '>=UF_DATETIME' => $fromStart,
                '<=UF_DATETIME' => $toEnd,
                '=UF_USER_ID' => $chunkIds,
                '=UF_EVENT' => [HL_EVENT_ENTRY, HL_EVENT_EXIT],
            ],
            'order' => ['UF_DATETIME' => 'ASC', 'ID' => 'ASC'],
        ]);
        while ($event = $events->fetch()) {
            $eventDate = $event['UF_DATETIME'];
            if (!$eventDate) continue;
            if (!($eventDate instanceof \Bitrix\Main\Type\DateTime) && !($eventDate instanceof \DateTimeInterface)) {
                try {
                    $eventDate = new DateTime((string)$eventDate);
                } catch (\Throwable $e) {
                    continue;
                }
            }
            $eventUid = (int)$event['UF_USER_ID'];
            $eventYmd = $eventDate->format('Y-m-d');
            $primary = $recalcByUserDate[$eventUid][$eventYmd] ?? null;
            if ($primary && trim(normVal($primary['UF_NORMA'] ?? '')) !== '00:00') continue;
            if ((int)$event['UF_EVENT'] === HL_EVENT_ENTRY) {
                if (!isset($eventsByUserDate[$eventUid][$eventYmd]['UF_ENTRY_TIME'])) {
                    $eventsByUserDate[$eventUid][$eventYmd]['UF_ENTRY_TIME'] = $eventDate->format('H:i');
                }
            } elseif ((int)$event['UF_EVENT'] === HL_EVENT_EXIT) {
                $eventsByUserDate[$eventUid][$eventYmd]['UF_EXIT_TIME'] = $eventDate->format('H:i');
            }
        }

        foreach ($chunkIds as $uid) {
            $u = $usersById[$uid] ?? null;
            if (!$u) continue;

            $fio = getUserFio($u);
            $personnel = $u['UF_PERSONNEL_NUMBER'] ?? '';

            $hiringRaw = $u['UF_WEBSLON_ABSENCE_DATE_OF_HIRING'] ?? '';
            $hiringDate = '';
            if ($hiringRaw instanceof \Bitrix\Main\Type\Date || $hiringRaw instanceof \Bitrix\Main\Type\DateTime) {
                $hiringDate = $hiringRaw->format('d.m.Y');
            } elseif (is_string($hiringRaw) && $hiringRaw !== '') {
                try {
                    $hiringDate = (new DateTime($hiringRaw))->format('d.m.Y');
                } catch (\Throwable $e) {
                    $hiringDate = (string)$hiringRaw;
                }
            }

            $workBegin    = enumToText($enumMaps['UF_WORK_BEGIN'] ?? [], $u['UF_WORK_BEGIN'] ?? '');
            $scheduleType = enumToText($enumMaps['UF_SCHEDULE_TYPE'] ?? [], $u['UF_SCHEDULE_TYPE'] ?? '');
            $company      = enumToText($enumMaps['UF_COMPANY'] ?? [], $u['UF_COMPANY'] ?? '');
            $office       = enumToText($enumMaps['UF_MAIN_OFFICE'] ?? [], $u['UF_MAIN_OFFICE'] ?? '');

            $workFormatId = (int)($u['UF_WORK_FORMAT'] ?? 0);
            $isUserOffice = ($workFormatId === USER_WORK_FORMAT_OFFICE_ID);
            $isUserRemote = ($workFormatId === USER_WORK_FORMAT_REMOTE_ID);

            $position = $u['WORK_POSITION'] ?? '';
            $workDepartment = $u['WORK_DEPARTMENT'] ?? '';

            $manager = getReliableManager($uid);
            $managerFio = $manager ? getUserFio($manager) : '';
            $managerPos = $manager['WORK_POSITION'] ?? '';
            if ($manager && (int)$manager['ID'] === (int)$uid) {
                $managerFio = '';
                $managerPos = '';
            }

            $ceo1 = getCeo1NameByUserId($uid);
            $ceo2 = ($ceo1 !== '') ? getCeo2NameByUserId($uid) : '';

            foreach ($dates as $ymd) {
                $r = $recalcByUserDate[$uid][$ymd] ?? null;
                $normaVal = $r ? trim(normVal($r['UF_NORMA'] ?? '')) : '';
                $fallback = $eventsByUserDate[$uid][$ymd] ?? null;
                if ($r && $normaVal === '00:00' && !$fallback) continue;
                if ($fallback) {
                    // События не содержат нормы, заявок и рассчитанного рабочего времени.
                    $r = array_merge($r ?? [], $fallback);
                }

                $dateDisplay = (new DateTime($ymd))->format('d.m.Y');
                $sch = $schedulesByUserDate[$uid][$ymd] ?? null;

                if ($isUserRemote) {
                    $presence = 'Дистанционно';
                } else {
                    $presence = '';
                    if (is_array($sch)) {
                        $schedulePresenceName = (string)($sch['NAME'] ?? '');
                        $scheduleUnapproved = (bool)($sch['UNAPPROVED'] ?? false);

                        // v1.3.7:
                        // Для офисных сотрудников UF_WORK_FORMAT=1929 при графике "Офис"
                        // не выводим "Офис (несогл.)", даже если UF_STATUS=1.
                        if ($isUserOffice && isPresenceOffice($schedulePresenceName)) {
                            $scheduleUnapproved = false;
                        }

                        $presence = formatPresenceWithStatus($schedulePresenceName, $scheduleUnapproved);
                    }
                }

                $dayStatus = $r ? normVal($r['UF_DAY_STATUS'] ?? '') : '';

                // Болезнь при определенных значениях
                if ($dayStatus !== '' && (
                    mb_stripos($dayStatus, 'Болезнь') !== false ||
                    mb_stripos($dayStatus, 'Отсутствие по невыясненным причинам') !== false
                )) {
                    $dayStatus = 'Болезнь';
                }

                // v1.3.6: если "Формат работы по графику работы" = "Дистанционно" → статус дня всегда "Работа"
                if (trim($presence) === 'Дистанционно') {
                    $dayStatus = 'Работа';
                }

                $apps = $r ? normVal($r['UF_APP'] ?? '') : '';
                $apps = cleanAppsValue($apps);

                $resultRows[] = [
                    'UF_PERSONNEL_NUMBER' => $personnel,
                    'FIO' => $fio,
                    'HIRING_DATE' => $hiringDate,
                    'DATE' => $dateDisplay,
                    'UF_NORMA' => $r ? normVal($r['UF_NORMA'] ?? '') : '',
                    'UF_WORK_BEGIN' => normVal($workBegin),
                    'PRESENCE_TYPE' => $presence,
                    'UF_DAY_STATUS' => $dayStatus,
                    'UF_ENTRY_TIME' => $r ? normVal($r['UF_ENTRY_TIME'] ?? '') : '',
                    'UF_EXIT_TIME'  => $r ? normVal($r['UF_EXIT_TIME'] ?? '') : '',
                    'UF_APP'        => $apps,
                    'UF_WT_TOTAL'   => $r ? normVal($r['UF_WT_TOTAL'] ?? '') : '',
                    'UF_UNDERWORK'  => $r ? normVal($r['UF_UNDERWORK'] ?? '') : '',
                    'UF_OVERWORK'   => $r ? normVal($r['UF_OVERWORK'] ?? '') : '',
                    'UF_SCHEDULE_TYPE' => normVal($scheduleType),
                    'UF_COMPANY' => normVal($company),
                    'WORK_POSITION' => normVal($position),
                    'CEO1' => normVal($ceo1),
                    'CEO2' => normVal($ceo2),
                    'WORK_DEPARTMENT' => normVal($workDepartment),
                    'MANAGER' => normVal($managerFio),
                    'MANAGER_POSITION' => normVal($managerPos),
                    'UF_MAIN_OFFICE' => normVal($office),
                ];
            }
        }

        unset($schedulesByUserDate, $recalcByUserDate, $eventsByUserDate);
    }

    return $resultRows;
}

// ======================================================================
// EXPORT: HTML-XLS
// ======================================================================
if ($exportFormat === 'HTMLXLS') {
    @set_time_limit(0);
    @ini_set('memory_limit', '1024M');
    @ini_set('max_execution_time', '0');
    @ignore_user_abort(true);
    disableOutputBuffers();

    $rows = generateReportRows(
        $userIds,
        $usersById,
        $dates,
        $enumMaps,
        $presenceTypeMap,
        $fromStart,
        $toEnd,
        $HL5_FIELD_DATE,
        $HL5_FIELD_USER,
        $HL91_FIELD_DATE,
        $HL91_FIELD_USER,
        EXCEL_USER_CHUNK
    );
    signalReportDownloadReady();

    $filename = "Отчет_рабочее_время_{$dateFromStr}_{$dateToStr}.xls";

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('X-Content-Type-Options: nosniff');

    echo '<html><head><meta charset="UTF-8"></head><body>';
    echo '<table border="1" cellpadding="3" cellspacing="0" style="border-collapse:collapse;font-size:11px;white-space:nowrap;">';
    echo '<tr>';
    foreach ($headers as $th) {
        echo '<th style="background:#efefef; font-weight:bold;">' . h($th) . '</th>';
    }
    echo '</tr>' . "\n";


    foreach ($rows as $row) {
        echo '<tr>';
        echo '<td>' . h($row['UF_PERSONNEL_NUMBER']) . '</td>';
        echo '<td>' . h($row['FIO']) . '</td>';
        echo '<td>' . h($row['HIRING_DATE']) . '</td>';
        echo '<td>' . h($row['DATE']) . '</td>';
        echo '<td>' . h($row['UF_NORMA']) . '</td>';
        echo '<td>' . h($row['UF_WORK_BEGIN']) . '</td>';
        echo '<td>' . h($row['PRESENCE_TYPE']) . '</td>';
        echo '<td>' . h($row['UF_DAY_STATUS']) . '</td>';
        echo '<td>' . h($row['UF_ENTRY_TIME']) . '</td>';
        echo '<td>' . h($row['UF_EXIT_TIME']) . '</td>';
        echo '<td>' . h($row['UF_APP']) . '</td>';
        echo '<td>' . h($row['UF_WT_TOTAL']) . '</td>';
        echo '<td>' . h($row['UF_UNDERWORK']) . '</td>';
        echo '<td>' . h($row['UF_OVERWORK']) . '</td>';
        echo '<td>' . h($row['UF_SCHEDULE_TYPE']) . '</td>';
        echo '<td>' . h($row['UF_COMPANY']) . '</td>';
        echo '<td>' . h($row['WORK_POSITION']) . '</td>';
        echo '<td>' . h($row['CEO1']) . '</td>';
        echo '<td>' . h($row['CEO2']) . '</td>';
        echo '<td>' . h($row['WORK_DEPARTMENT']) . '</td>';
        echo '<td>' . h($row['MANAGER']) . '</td>';
        echo '<td>' . h($row['MANAGER_POSITION']) . '</td>';
        echo '<td>' . h($row['UF_MAIN_OFFICE']) . '</td>';
        echo '</tr>' . "\n";
    }

    echo '</table></body></html>';
    exit;
}

// ======================================================================
// EXPORT: CSV (huge)
// ======================================================================
if ($exportFormat === 'CSV') {
    @set_time_limit(0);
    @ini_set('memory_limit', '1024M');
    @ini_set('max_execution_time', '0');
    @ignore_user_abort(true);
    disableOutputBuffers();

    $rows = generateReportRows(
        $userIds,
        $usersById,
        $dates,
        $enumMaps,
        $presenceTypeMap,
        $fromStart,
        $toEnd,
        $HL5_FIELD_DATE,
        $HL5_FIELD_USER,
        $HL91_FIELD_DATE,
        $HL91_FIELD_USER,
        EXCEL_USER_CHUNK
    );
    signalReportDownloadReady();

    $filename = "Отчет_рабочее_время_{$dateFromStr}_{$dateToStr}.csv";

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('X-Content-Type-Options: nosniff');

    $out = fopen('php://output', 'w');
    if (!$out) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Ошибка: не удалось открыть поток вывода.";
        exit;
    }

    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers, ';');

    $rowCounter = 0;

    foreach ($rows as $row) {
        $values = [
            csvClean((string)$row['UF_PERSONNEL_NUMBER']),
            csvClean((string)$row['FIO']),
            csvClean((string)$row['HIRING_DATE']),
            csvClean((string)$row['DATE']),
            csvClean((string)$row['UF_NORMA']),
            csvClean((string)$row['UF_WORK_BEGIN']),
            csvClean((string)$row['PRESENCE_TYPE']),
            csvClean((string)$row['UF_DAY_STATUS']),
            csvClean((string)$row['UF_ENTRY_TIME']),
            csvClean((string)$row['UF_EXIT_TIME']),
            csvClean((string)$row['UF_APP']),
            csvClean((string)$row['UF_WT_TOTAL']),
            csvClean((string)$row['UF_UNDERWORK']),
            csvClean((string)$row['UF_OVERWORK']),
            csvClean((string)$row['UF_SCHEDULE_TYPE']),
            csvClean((string)$row['UF_COMPANY']),
            csvClean((string)$row['WORK_POSITION']),
            csvClean((string)$row['CEO1']),
            csvClean((string)$row['CEO2']),
            csvClean((string)$row['WORK_DEPARTMENT']),
            csvClean((string)$row['MANAGER']),
            csvClean((string)$row['MANAGER_POSITION']),
            csvClean((string)$row['UF_MAIN_OFFICE']),
        ];

        fputcsv($out, $values, ';');
        $rowCounter++;
        if (($rowCounter % 5000) === 0) {
            fflush($out);
        }
    }

    fclose($out);
    exit;
}

// ======================================================================
// HTML PREVIEW
// ======================================================================
$rows = generateReportRows(
    $userIds,
    $usersById,
    $dates,
    $enumMaps,
    $presenceTypeMap,
    $fromStart,
    $toEnd,
    $HL5_FIELD_DATE,
    $HL5_FIELD_USER,
    $HL91_FIELD_DATE,
    $HL91_FIELD_USER,
    EXCEL_USER_CHUNK
);

if ($exportFormat === '' && count($rows) > HTML_MAX_ROWS) {
    $htmlxlsUrl = buildUrlWithParams(['export' => 'HTMLXLS']);
    $csvUrl = buildUrlWithParams(['export' => 'CSV']);

    echo "<div style='font-family:Arial; padding:12px; background:#fff3cd; border:1px solid #ffeeba; border-radius:6px;'>";
    echo "<b>Строк больше лимита для просмотра в браузере.</b><br>";
    echo "Фактически строк: <b>".count($rows)."</b>.<br>";
    echo "<div style='margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;'>";
    echo "<a data-report-export href='".h($htmlxlsUrl)."' style='display:inline-block;padding:8px 12px;background:#007bff;color:#fff;text-decoration:none;border-radius:4px;'>Выгрузить в HTML-XLS</a>";
    echo "<a data-report-export href='".h($csvUrl)."' style='display:inline-block;padding:8px 12px;background:#28a745;color:#fff;text-decoration:none;border-radius:4px;'>Выгрузить в CSV</a>";
    echo "</div>";
    echo "</div>";

    require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/epilog_after.php");
    return;
}

echo "<div style='font-family:Arial; margin:12px 0;'>";
echo "<b>Период:</b> " . h($dateFromStrDisplay) . " — " . h($dateToStrDisplay) . "<br>";
echo "<b>Сотрудников:</b> " . count($userIds) . "<br>";
echo "<b>Строк:</b> " . count($rows) . "<br>";
echo "</div>";

echo "<table border='1' cellpadding='4' cellspacing='0' style='border-collapse:collapse;font-family:Arial;font-size:12px;'>";
echo "<tr style='background:#efefef;'>";
foreach ($headers as $th) {
    echo "<th>" . h($th) . "</th>";
}
echo "</tr>";

foreach ($rows as $row) {
    echo "<tr>";
    echo "<td>" . h($row['UF_PERSONNEL_NUMBER']) . "</td>";
    echo "<td>" . h($row['FIO']) . "</td>";
    echo "<td>" . h($row['HIRING_DATE']) . "</td>";
    echo "<td>" . h($row['DATE']) . "</td>";
    echo "<td>" . h($row['UF_NORMA']) . "</td>";
    echo "<td>" . h($row['UF_WORK_BEGIN']) . "</td>";
    echo "<td>" . h($row['PRESENCE_TYPE']) . "</td>";
    echo "<td>" . h($row['UF_DAY_STATUS']) . "</td>";
    echo "<td>" . h($row['UF_ENTRY_TIME']) . "</td>";
    echo "<td>" . h($row['UF_EXIT_TIME']) . "</td>";
    echo "<td>" . h($row['UF_APP']) . "</td>";
    echo "<td>" . h($row['UF_WT_TOTAL']) . "</td>";
    echo "<td>" . h($row['UF_UNDERWORK']) . "</td>";
    echo "<td>" . h($row['UF_OVERWORK']) . "</td>";
    echo "<td>" . h($row['UF_SCHEDULE_TYPE']) . "</td>";
    echo "<td>" . h($row['UF_COMPANY']) . "</td>";
    echo "<td>" . h($row['WORK_POSITION']) . "</td>";
    echo "<td>" . h($row['CEO1']) . "</td>";
    echo "<td>" . h($row['CEO2']) . "</td>";
    echo "<td>" . h($row['WORK_DEPARTMENT']) . "</td>";
    echo "<td>" . h($row['MANAGER']) . "</td>";
    echo "<td>" . h($row['MANAGER_POSITION']) . "</td>";
    echo "<td>" . h($row['UF_MAIN_OFFICE']) . "</td>";
    echo "</tr>";
}

echo "</table>";

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/epilog_after.php");

