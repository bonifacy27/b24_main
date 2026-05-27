<?php
/**
 * workplace_report_ext.php
 *
 * Расширенный отчет по рабочим местам:
 * строка = подразделение + кабинет + дата.
 */

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('NOT_CHECK_PERMISSIONS', true);

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use Bitrix\Main\Loader;
use Bitrix\Main\Type\Date;

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
$dateFrom->setTime(0, 0, 0);
$dateTo->setTime(0, 0, 0);

$cabinetFilterRaw = isset($_GET['cabinet_filter']) ? trim((string)$_GET['cabinet_filter']) : '';

$parseSkudDateToKey = static function ($rawValue): string {
    if ($rawValue instanceof \DateTimeInterface) { return $rawValue->format('Y-m-d'); }
    $raw = trim((string)$rawValue);
    if ($raw === '') { return ''; }
    foreach (['Y-m-d H:i:s', 'Y-m-d', 'd.m.Y H:i:s', 'd.m.Y'] as $format) {
        $dt = \DateTime::createFromFormat($format, $raw);
        if ($dt instanceof \DateTime) { return $dt->format('Y-m-d'); }
    }
    $timestamp = strtotime($raw);
    return $timestamp !== false ? date('Y-m-d', $timestamp) : '';
};

$normalizeDurationToMinutes = static function (string $value): int {
    $value = trim($value);
    if ($value === '' || $value === '00:00') { return 0; }
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $value, $m)) { return 0; }
    return ((int)$m[1] * 60) + (int)$m[2];
};

$normalizeCabinet = static function (string $cabinetRaw): string {
    $value = trim(mb_strtolower($cabinetRaw));
    if ($value === '' || $value === 'не указан') { return ''; }
    if (!preg_match('/каб\.?\s*([0-9]+[a-zа-я0-9\.-]*)/ui', $value, $match)) { return ''; }
    $cabinetCode = str_replace([' ', ','], '', $match[1]);
    $cabinetCode = str_replace(['а', 'б', 'в', 'г'], ['a', 'b', 'v', 'g'], $cabinetCode);
    if (mb_strpos($value, 'москов') !== false) { return 'moskovskiy|' . $cabinetCode; }
    if (mb_strpos($value, 'новоладож') !== false) { return 'novoladozhskaya|' . $cabinetCode; }
    if (mb_strpos($value, 'рентген') !== false) { return 'rentgena|' . $cabinetCode; }
    return 'other|' . $cabinetCode;
};

$normalizeDirectoryCabinet = static function (string $cabinetName): string {
    $value = trim(mb_strtolower($cabinetName));
    if ($value === '') { return ''; }
    $parts = array_map('trim', explode(',', $value, 2));
    if (count($parts) < 2) { return ''; }
    $location = $parts[0];
    $cabinetCode = str_replace([' ', ','], '', $parts[1]);
    $cabinetCode = str_replace(['а', 'б', 'в', 'г'], ['a', 'b', 'v', 'g'], $cabinetCode);
    if (mb_strpos($location, 'москов') !== false) { return 'moskovskiy|' . $cabinetCode; }
    if (mb_strpos($location, 'новоладож') !== false) { return 'novoladozhskaya|' . $cabinetCode; }
    if (mb_strpos($location, 'рентген') !== false) { return 'rentgena|' . $cabinetCode; }
    return 'other|' . $cabinetCode;
};

$cabinetFilterNorm = '';
if ($cabinetFilterRaw !== '') {
    $cabinetFilterNorm = $normalizeCabinet($cabinetFilterRaw);
    if ($cabinetFilterNorm === '') {
        $cabinetFilterNorm = $normalizeDirectoryCabinet($cabinetFilterRaw);
    }
}

$departments = [];
$rsSections = \CIBlockSection::GetList(
    ['LEFT_MARGIN' => 'ASC'],
    ['IBLOCK_ID' => $iblockId, 'GLOBAL_ACTIVE' => 'Y'],
    false,
    ['ID', 'NAME', 'IBLOCK_SECTION_ID', 'UF_HEAD']
);
while ($section = $rsSections->Fetch()) {
    $id = (int)$section['ID'];
    $departments[$id] = [
        'ID' => $id,
        'NAME' => (string)$section['NAME'],
        'IBLOCK_SECTION_ID' => (int)$section['IBLOCK_SECTION_ID'],
        'UF_HEAD' => (int)$section['UF_HEAD'],
    ];
}


$getDepartmentChainFromHead = static function (int $headDepartmentId) use (&$departments): array {
    $chain = [];
    $currentId = $headDepartmentId;
    $guard = 0;
    while ($currentId > 0 && isset($departments[$currentId]) && $guard < 100) {
        $chain[] = (string)$departments[$currentId]['NAME'];
        $currentId = (int)$departments[$currentId]['IBLOCK_SECTION_ID'];
        $guard++;
    }
    $chain = array_reverse($chain);
    if (count($chain) > 5) {
        $chain = array_slice($chain, -5);
    }
    while (count($chain) < 5) {
        array_unshift($chain, '');
    }
    return array_values($chain);
};

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

$headsMap = [];
$headIds = [];
foreach ($departments as $department) {
    if ((int)$department['UF_HEAD'] > 0) { $headIds[(int)$department['UF_HEAD']] = true; }
}
if (!empty($headIds)) {
    $rsHeads = \CUser::GetList($by='id', $order='asc', ['ID' => implode('|', array_keys($headIds))], ['FIELDS' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN']]);
    while ($head = $rsHeads->Fetch()) {
        $fio = trim($head['LAST_NAME'] . ' ' . $head['NAME'] . ' ' . $head['SECOND_NAME']);
        $headsMap[(int)$head['ID']] = $fio !== '' ? $fio : (string)$head['LOGIN'];
    }
}

$departmentUsers = [];
$userDepartmentsMap = [];
$userCabinetMap = [];
$cabinetAssignedTotal = [];

$rsUsers = \CUser::GetList($by='id', $order='asc', ['ACTIVE' => 'Y'], ['SELECT' => ['UF_DEPARTMENT', 'UF_CABINET'], 'FIELDS' => ['ID', 'UF_DEPARTMENT', 'UF_CABINET']]);
while ($user = $rsUsers->Fetch()) {
    $userId = (int)$user['ID'];
    $userDepartments = is_array($user['UF_DEPARTMENT']) ? $user['UF_DEPARTMENT'] : [(int)$user['UF_DEPARTMENT']];
    $headDepartments = [];
    foreach ($userDepartments as $departmentId) {
        $departmentId = (int)$departmentId;
        if ($departmentId <= 0 || !isset($departmentResponsibleHead[$departmentId])) { continue; }
        $headDepartmentId = (int)$departmentResponsibleHead[$departmentId];
        if ($headDepartmentId > 0 && isset($departments[$headDepartmentId]) && (int)$departments[$headDepartmentId]['UF_HEAD'] > 0) {
            $headDepartments[$headDepartmentId] = true;
        }
    }
    if (empty($headDepartments)) { continue; }

    $userDepartmentsMap[$userId] = array_keys($headDepartments);
    foreach ($userDepartmentsMap[$userId] as $headDepId) {
        if (!isset($departmentUsers[$headDepId])) { $departmentUsers[$headDepId] = []; }
        $departmentUsers[$headDepId][$userId] = true;
    }

    $cabinet = trim((string)$user['UF_CABINET']);
    $cabinet = $cabinet !== '' ? $cabinet : 'Не указан';
    $userCabinetMap[$userId] = $cabinet;

    $cabNorm = $normalizeCabinet($cabinet);
    if ($cabNorm !== '') {
        if (!isset($cabinetAssignedTotal[$cabNorm])) { $cabinetAssignedTotal[$cabNorm] = 0; }
        $cabinetAssignedTotal[$cabNorm]++;
    }
}

$cabinetDirectory = [];
$cabinetHl = \Bitrix\Highloadblock\HighloadBlockTable::getById(74)->fetch();
if ($cabinetHl) {
    $cabinetEntity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($cabinetHl);
    $cabinetClass = $cabinetEntity->getDataClass();
    $rsCabinets = $cabinetClass::getList(['select' => ['UF_NAME', 'UF_WORKPLACES']]);
    while ($row = $rsCabinets->fetch()) {
        $title = trim((string)$row['UF_NAME']);
        $normalized = $normalizeDirectoryCabinet($title);
        if ($normalized === '') { continue; }
        $cabinetDirectory[$normalized] = ['TITLE' => $title, 'WORKPLACES' => (int)$row['UF_WORKPLACES']];
    }
}

$periodDays = [];
foreach (new \DatePeriod($dateFrom, new \DateInterval('P1D'), (clone $dateTo)->modify('+1 day')) as $day) {
    $periodDays[] = $day->format('Y-m-d');
}

$cabinetDailyOffice = [];
foreach ($periodDays as $dateKey) {
    $cabinetDailyOffice[$dateKey] = [];
}

$skudHl = \Bitrix\Highloadblock\HighloadBlockTable::getById(5)->fetch();
if ($skudHl) {
    $skudEntity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($skudHl);
    $skudClass = $skudEntity->getDataClass();
    $rows = $skudClass::getList([
        'select' => ['UF_USER_ID', 'UF_DATE', 'UF_ENTRY_TIME', 'UF_WT_TOTAL'],
        'filter' => ['>=UF_DATE' => Date::createFromPhp($dateFrom), '<=UF_DATE' => Date::createFromPhp($dateTo)],
    ]);
    while ($row = $rows->fetch()) {
        $userId = (int)$row['UF_USER_ID'];
        if ($userId <= 0 || !isset($userDepartmentsMap[$userId])) { continue; }

        $dateKey = $parseSkudDateToKey($row['UF_DATE']);
        if ($dateKey === '' || !isset($cabinetDailyOffice[$dateKey])) { continue; }

        $entry = trim((string)$row['UF_ENTRY_TIME']);
        $worked = $normalizeDurationToMinutes((string)$row['UF_WT_TOTAL']);
        if ($entry === '' || $worked <= 240) { continue; }

        $userCabinetNorm = $normalizeCabinet((string)$userCabinetMap[$userId]);
        if ($userCabinetNorm === '') { continue; }

        if (!isset($cabinetDailyOffice[$dateKey][$userCabinetNorm])) {
            $cabinetDailyOffice[$dateKey][$userCabinetNorm] = ['TOTAL' => 0, 'BY_DEPARTMENT' => []];
        }
        $cabinetDailyOffice[$dateKey][$userCabinetNorm]['TOTAL']++;
        foreach ($userDepartmentsMap[$userId] as $departmentId) {
            if (!isset($cabinetDailyOffice[$dateKey][$userCabinetNorm]['BY_DEPARTMENT'][$departmentId])) {
                $cabinetDailyOffice[$dateKey][$userCabinetNorm]['BY_DEPARTMENT'][$departmentId] = 0;
            }
            $cabinetDailyOffice[$dateKey][$userCabinetNorm]['BY_DEPARTMENT'][$departmentId]++;
        }
    }
}

$allCabinets = [];
foreach ($cabinetDirectory as $cabKey => $cabData) { $allCabinets[$cabData['TITLE']] = true; }
foreach ($userCabinetMap as $cabName) { $allCabinets[(string)$cabName] = true; }
$availableCabinets = array_keys($allCabinets);
sort($availableCabinets, SORT_NATURAL | SORT_FLAG_CASE);

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Расширенный отчет по рабочим местам</title>
    <style>
        body { font-family: Arial,sans-serif; font-size:13px; margin:16px; }
        table { border-collapse: collapse; width:100%; }
        th, td { border:1px solid #d8e0ea; padding:6px 8px; vertical-align: top; }
        th { background:#f5f9ff; white-space: normal; word-break: break-word; line-height: 1.2; }
        .col-narrow { width: 70px; max-width: 70px; }
        .filters { margin: 10px 0 16px; }
    </style>
</head>
<body>
<h1>Расширенный отчет по рабочим местам</h1>
<form method="get" class="filters">
    <label>С даты: <input type="date" name="date_from" value="<?=htmlspecialcharsbx($dateFrom->format('Y-m-d'))?>"></label>
    <label style="margin-left:8px;">По дату: <input type="date" name="date_to" value="<?=htmlspecialcharsbx($dateTo->format('Y-m-d'))?>"></label>
    <label style="margin-left:8px;">Кабинет: <select name="cabinet_filter"><option value="">Все</option><?php foreach ($availableCabinets as $cabOpt): ?><option value="<?=htmlspecialcharsbx($cabOpt)?>" <?= $cabinetFilterRaw === $cabOpt ? 'selected' : '' ?>><?=htmlspecialcharsbx($cabOpt)?></option><?php endforeach; ?></select></label>
    <button type="submit" style="margin-left:8px;">Показать</button>
</form>

<table>
    <thead>
    <tr>
        <th>СЕО-1</th>
        <th>СЕО-2</th>
        <th>СЕО-3</th>
        <th>СЕО-4</th>
        <th>СЕО-5</th>
        <th>Руководитель</th>
        <th>Кабинет</th>
        <th class="col-narrow">Кол-во рабочих мест в кабинете</th>
        <th class="col-narrow">Кол-во закрепленных чел. за кабинетом</th>
        <th>Дата</th>
        <th class="col-narrow">Кол-во сотрудников в офисе (&gt;4ч)</th>
        <th>% загрузки</th>
        <th class="col-narrow">Кол-во свободных рм</th>
    </tr>
    </thead>
    <tbody>
    <?php
    foreach ($departments as $departmentId => $department) {
        if ((int)$department['UF_HEAD'] <= 0) { continue; }
        $headName = isset($headsMap[$department['UF_HEAD']]) ? $headsMap[$department['UF_HEAD']] : 'Не назначен';

        $departmentCabinets = [];
        foreach ($userCabinetMap as $userId => $cabName) {
            if (!isset($userDepartmentsMap[$userId]) || !in_array($departmentId, $userDepartmentsMap[$userId], true)) { continue; }
            $norm = $normalizeCabinet((string)$cabName);
            if ($norm === '') { continue; }
            $departmentCabinets[$norm] = true;
        }

        foreach (array_keys($departmentCabinets) as $cabNorm) {
            if ($cabinetFilterNorm !== '' && $cabNorm !== $cabinetFilterNorm) { continue; }

            $cabTitle = isset($cabinetDirectory[$cabNorm]) ? (string)$cabinetDirectory[$cabNorm]['TITLE'] : $cabNorm;
            $workplaces = isset($cabinetDirectory[$cabNorm]) ? (int)$cabinetDirectory[$cabNorm]['WORKPLACES'] : 0;
            $assignedCount = isset($cabinetAssignedTotal[$cabNorm]) ? (int)$cabinetAssignedTotal[$cabNorm] : 0;

            foreach ($periodDays as $dateKey) {
                $dayData = isset($cabinetDailyOffice[$dateKey][$cabNorm]) ? $cabinetDailyOffice[$dateKey][$cabNorm] : ['TOTAL' => 0, 'BY_DEPARTMENT' => []];
                $depOfficeCount = isset($dayData['BY_DEPARTMENT'][$departmentId]) ? (int)$dayData['BY_DEPARTMENT'][$departmentId] : 0;
                $totalOccupied = (int)$dayData['TOTAL'];
                $utilization = $workplaces > 0 ? round(($totalOccupied / $workplaces) * 100, 1) : 0;
                $free = max(0, $workplaces - $totalOccupied);
                ?>
                <?php $deptChain = $getDepartmentChainFromHead($departmentId); ?>
                <tr>
                    <td><?=htmlspecialcharsbx($deptChain[0])?></td>
                    <td><?=htmlspecialcharsbx($deptChain[1])?></td>
                    <td><?=htmlspecialcharsbx($deptChain[2])?></td>
                    <td><?=htmlspecialcharsbx($deptChain[3])?></td>
                    <td><?=htmlspecialcharsbx($deptChain[4])?></td>
                    <td><?=htmlspecialcharsbx($headName)?></td>
                    <td><?=htmlspecialcharsbx($cabTitle)?></td>
                    <td><?= $workplaces ?></td>
                    <td><?= $assignedCount ?></td>
                    <td><?=htmlspecialcharsbx((new \DateTime($dateKey))->format('d.m.Y'))?></td>
                    <td><?= $depOfficeCount ?></td>
                    <td><?= $utilization ?>%</td>
                    <td><?= $free ?></td>
                </tr>
                <?php
            }
        }
    }
    ?>
    </tbody>
</table>
</body>
</html>
