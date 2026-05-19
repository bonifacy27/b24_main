<?php
/**
 * department_workplace_report.php
 *
 * Отчет по подразделениям за период с данными СКУД и профилей пользователей.
 * Пример:
 * /pub/company/department_workplace_report.php?date_from=2026-05-01&date_to=2026-05-19
 */

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('NOT_CHECK_PERMISSIONS', true);

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use Bitrix\Main\Loader;

if (!Loader::includeModule('iblock') || !Loader::includeModule('main') || !Loader::includeModule('highloadblock')) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Ошибка: не удалось подключить обязательные модули.';
    exit;
}

$iblockId = (int)\COption::GetOptionInt('intranet', 'iblock_structure', 0);
if ($iblockId <= 0) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Ошибка: не найден ID структуры компании.';
    exit;
}

$dateFromRaw = isset($_GET['date_from']) ? (string)$_GET['date_from'] : date('Y-m-d');
$dateToRaw = isset($_GET['date_to']) ? (string)$_GET['date_to'] : $dateFromRaw;
$dateFrom = \DateTime::createFromFormat('Y-m-d', $dateFromRaw) ?: new \DateTime();
$dateTo = \DateTime::createFromFormat('Y-m-d', $dateToRaw) ?: clone $dateFrom;
if ($dateFrom > $dateTo) { [$dateFrom, $dateTo] = [$dateTo, $dateFrom]; }

$normalizeDurationToMinutes = static function (string $value): int {
    $value = trim($value);
    if ($value === '' || $value === '00:00') { return 0; }
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $value, $m)) { return 0; }
    return ((int)$m[1] * 60) + (int)$m[2];
};

$departments = [];
$rsSections = \CIBlockSection::GetList(
    ['LEFT_MARGIN' => 'ASC'],
    ['IBLOCK_ID' => $iblockId, 'GLOBAL_ACTIVE' => 'Y'],
    false,
    ['ID', 'NAME', 'IBLOCK_SECTION_ID', 'DEPTH_LEVEL', 'LEFT_MARGIN', 'SORT', 'UF_HEAD']
);
while ($section = $rsSections->Fetch()) {
    $id = (int)$section['ID'];
    $departments[$id] = [
        'ID' => $id,
        'NAME' => (string)$section['NAME'],
        'IBLOCK_SECTION_ID' => (int)$section['IBLOCK_SECTION_ID'],
        'DEPTH_LEVEL' => (int)$section['DEPTH_LEVEL'],
        'UF_HEAD' => (int)$section['UF_HEAD'],
    ];
}

$departmentChildren = [];
foreach ($departments as $departmentId => $department) {
    $departmentChildren[$departmentId] = [];
}
foreach ($departments as $departmentId => $department) {
    $parentId = (int)$department['IBLOCK_SECTION_ID'];
    if ($parentId > 0 && isset($departmentChildren[$parentId])) {
        $departmentChildren[$parentId][] = $departmentId;
    }
}

$departmentResponsibleHead = [];
$assignResponsibleHead = static function (int $departmentId, int $inheritedHeadDepartmentId = 0) use (&$assignResponsibleHead, &$departmentResponsibleHead, $departments, $departmentChildren): void {
    $currentHeadDepartmentId = $inheritedHeadDepartmentId;
    if ((int)$departments[$departmentId]['UF_HEAD'] > 0) {
        $currentHeadDepartmentId = $departmentId;
    }
    $departmentResponsibleHead[$departmentId] = $currentHeadDepartmentId;
    foreach ($departmentChildren[$departmentId] as $childDepartmentId) {
        $assignResponsibleHead($childDepartmentId, $currentHeadDepartmentId);
    }
};

foreach ($departments as $departmentId => $department) {
    $parentId = (int)$department['IBLOCK_SECTION_ID'];
    if ($parentId <= 0 || !isset($departments[$parentId])) {
        $assignResponsibleHead($departmentId, 0);
    }
}

$headIds = [];
foreach ($departments as $department) {
    if ((int)$department['UF_HEAD'] > 0) { $headIds[(int)$department['UF_HEAD']] = true; }
}
$headsMap = [];
if (!empty($headIds)) {
    $rsHeads = \CUser::GetList($by='id', $order='asc', ['ID' => implode('|', array_keys($headIds))], ['FIELDS' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN']]);
    while ($head = $rsHeads->Fetch()) {
        $fio = trim($head['LAST_NAME'] . ' ' . $head['NAME'] . ' ' . $head['SECOND_NAME']);
        $headsMap[(int)$head['ID']] = $fio !== '' ? $fio : (string)$head['LOGIN'];
    }
}

$departmentData = [];
$userDepartmentsMap = [];
foreach ($departments as $departmentId => $department) {
    if ((int)$department['UF_HEAD'] <= 0) { continue; }
    $departmentData[$departmentId] = ['TOTAL' => 0, 'WORK_FORMATS' => [], 'CABINETS' => []];
}

$rsUsers = \CUser::GetList($by='id', $order='asc', ['ACTIVE' => 'Y'], ['SELECT' => ['UF_DEPARTMENT', 'UF_CABINET', 'UF_WORK_FORMAT'], 'FIELDS' => ['ID', 'UF_DEPARTMENT', 'UF_CABINET', 'UF_WORK_FORMAT']]);
while ($user = $rsUsers->Fetch()) {
    $userId = (int)$user['ID'];
    $userDepartments = is_array($user['UF_DEPARTMENT']) ? $user['UF_DEPARTMENT'] : [(int)$user['UF_DEPARTMENT']];
    $headDepartments = [];
    foreach ($userDepartments as $departmentId) {
        $departmentId = (int)$departmentId;
        if ($departmentId <= 0 || !isset($departmentResponsibleHead[$departmentId])) { continue; }
        $headDepartmentId = (int)$departmentResponsibleHead[$departmentId];
        if ($headDepartmentId > 0 && isset($departmentData[$headDepartmentId])) {
            $headDepartments[$headDepartmentId] = true;
        }
    }

    if (empty($headDepartments)) { continue; }

    $userDepartmentsMap[$userId] = array_keys($headDepartments);
    $cabinet = trim((string)$user['UF_CABINET']);
    $cabinet = $cabinet !== '' ? $cabinet : 'Не указан';
    $workFormat = trim((string)$user['UF_WORK_FORMAT']);
    $workFormat = $workFormat !== '' ? $workFormat : 'Не указан';

    foreach (array_keys($headDepartments) as $headDepartmentId) {
        $departmentData[$headDepartmentId]['TOTAL']++;
        if (!isset($departmentData[$headDepartmentId]['WORK_FORMATS'][$workFormat])) {
            $departmentData[$headDepartmentId]['WORK_FORMATS'][$workFormat] = 0;
        }
        $departmentData[$headDepartmentId]['WORK_FORMATS'][$workFormat]++;
        if (!isset($departmentData[$headDepartmentId]['CABINETS'][$cabinet])) {
            $departmentData[$headDepartmentId]['CABINETS'][$cabinet] = 0;
        }
        $departmentData[$headDepartmentId]['CABINETS'][$cabinet]++;
    }
}

$cabinetDirectory = [];
$cabinetHl = \Bitrix\Highloadblock\HighloadBlockTable::getById(74)->fetch();
if ($cabinetHl) {
    $cabinetEntity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($cabinetHl);
    $cabinetClass = $cabinetEntity->getDataClass();
    $rsCabinets = $cabinetClass::getList(['select' => ['UF_NAME', 'UF_WORKPLACES']]);
    while ($row = $rsCabinets->fetch()) {
        $cabinetDirectory[trim((string)$row['UF_NAME'])] = (int)$row['UF_WORKPLACES'];
    }
}

$skudStats = [];
$period = new \DatePeriod($dateFrom, new \DateInterval('P1D'), (clone $dateTo)->modify('+1 day'));
foreach ($period as $day) {
    $dateKey = $day->format('Y-m-d');
    foreach ($departmentData as $departmentId => $_) {
        $skudStats[$departmentId][$dateKey] = ['OFFICE_LT_4' => 0, 'OFFICE_GT_4' => 0, 'REMOTE' => 0, 'ABSENT' => 0];
    }
}

$skudHl = \Bitrix\Highloadblock\HighloadBlockTable::getById(5)->fetch();
if ($skudHl) {
    $skudEntity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($skudHl);
    $skudClass = $skudEntity->getDataClass();
    $rows = $skudClass::getList([
        'select' => ['UF_USER_ID', 'UF_DATE', 'UF_DAY_STATUS', 'UF_ENTRY_TIME', 'UF_EXIT_TIME', 'UF_NORMA', 'UF_WT_TOTAL'],
        'filter' => ['>=UF_DATE' => $dateFrom->format('Y-m-d'), '<=UF_DATE' => $dateTo->format('Y-m-d')],
    ]);
    while ($row = $rows->fetch()) {
        $userId = (int)$row['UF_USER_ID'];
        if ($userId <= 0 || !isset($userDepartmentsMap[$userId])) { continue; }
        $dateKey = (new \DateTime((string)$row['UF_DATE']))->format('Y-m-d');
        $entry = trim((string)$row['UF_ENTRY_TIME']);
        $norma = $normalizeDurationToMinutes((string)$row['UF_NORMA']);
        $worked = $normalizeDurationToMinutes((string)$row['UF_WT_TOTAL']);
        $status = trim((string)$row['UF_DAY_STATUS']);

        foreach ($userDepartmentsMap[$userId] as $departmentId) {
            if (!isset($skudStats[$departmentId][$dateKey])) { continue; }

            if ($entry !== '') {
                if ($worked > 240) {
                    $skudStats[$departmentId][$dateKey]['OFFICE_GT_4']++;
                } else {
                    $skudStats[$departmentId][$dateKey]['OFFICE_LT_4']++;
                }
                continue;
            }

            if ($norma > 0 && $worked === $norma) {
                $skudStats[$departmentId][$dateKey]['REMOTE']++;
                continue;
            }

            if ($status !== '' && mb_strtolower($status) !== 'работа') {
                $skudStats[$departmentId][$dateKey]['ABSENT']++;
            }
        }
    }
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="ru">
<head><meta charset="UTF-8"><title>Отчет по подразделениям и рабочим местам</title></head>
<body style="font-family:Arial,sans-serif;font-size:13px;">
<h1>Отчет по подразделениям и рабочим местам</h1>
<div>Период: <strong><?=htmlspecialcharsbx($dateFrom->format('d.m.Y'))?></strong> — <strong><?=htmlspecialcharsbx($dateTo->format('d.m.Y'))?></strong></div>
<table border="1" cellspacing="0" cellpadding="6" style="margin-top:12px;border-collapse:collapse;width:100%;">
<tr>
<th>Дата</th><th>Подразделение</th><th>Руководитель</th><th>Кол-во сотрудников</th><th>Форматы работы сотрудников</th><th>Кабинеты</th><th>Кол-во мест в кабинетах</th><th>В офисе &lt;= 4ч</th><th>В офисе &gt; 4ч</th><th>Сотрудников на удаленке</th><th>Сотрудников отсутствует</th>
</tr>
<?php foreach ($departmentData as $departmentId => $departmentRow): ?>
    <?php
    $headName = isset($headsMap[$departments[$departmentId]['UF_HEAD']]) ? $headsMap[$departments[$departmentId]['UF_HEAD']] : 'Не назначен';
    $formats = $departmentRow['WORK_FORMATS']; ksort($formats, SORT_NATURAL | SORT_FLAG_CASE);
    $cabinets = $departmentRow['CABINETS']; ksort($cabinets, SORT_NATURAL | SORT_FLAG_CASE);
    ?>
    <?php foreach ($skudStats[$departmentId] as $dateKey => $stat): ?>
        <tr>
            <td><?=htmlspecialcharsbx((new \DateTime($dateKey))->format('d.m.Y'))?></td>
            <td><?=htmlspecialcharsbx($departments[$departmentId]['NAME'])?></td>
            <td><?=htmlspecialcharsbx($headName)?></td>
            <td><?=$departmentRow['TOTAL']?></td>
            <td><?php foreach ($formats as $name => $count) { echo htmlspecialcharsbx($name).' — '.(int)$count.'<br>'; } ?></td>
            <td><?php foreach ($cabinets as $name => $count) { echo htmlspecialcharsbx($name).' — '.(int)$count.'<br>'; } ?></td>
            <td><?php foreach ($cabinets as $name => $count) { $places = isset($cabinetDirectory[$name]) ? (int)$cabinetDirectory[$name] : 0; $delta = $places - (int)$count; echo htmlspecialcharsbx($name).': '.$places.' (Δ '.$delta.')<br>'; } ?></td>
            <td><?=(int)$stat['OFFICE_LT_4']?></td>
            <td><?=(int)$stat['OFFICE_GT_4']?></td>
            <td><?=(int)$stat['REMOTE']?></td>
            <td><?=(int)$stat['ABSENT']?></td>
        </tr>
    <?php endforeach; ?>
<?php endforeach; ?>
</table>
</body>
</html>
