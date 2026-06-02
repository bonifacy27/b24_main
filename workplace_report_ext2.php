<?php
/**
 * workplace_report_ext2.php
 *
 * Расширенный отчет по рабочим местам по данным турникетов Reverse:
 * строка = юр. лицо + подразделение + кабинет + дата.
 */

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('NOT_CHECK_PERMISSIONS', true);

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime as BitrixDateTime;

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

$normalizeLegalEntity = static function ($value): string {
    $legalEntity = trim((string)$value);
    return $legalEntity !== '' ? $legalEntity : 'НСК';
};

$parseReverseEventDateTime = static function ($rawValue): ?\DateTime {
    if ($rawValue instanceof \DateTimeInterface) {
        return (new \DateTime())->setTimestamp($rawValue->getTimestamp());
    }

    $raw = trim((string)$rawValue);
    if ($raw === '') { return null; }

    foreach (['Y-m-d H:i:s', 'd.m.Y H:i:s', 'Y-m-d', 'd.m.Y'] as $format) {
        $dt = \DateTime::createFromFormat($format, $raw);
        if ($dt instanceof \DateTime) { return $dt; }
    }

    $timestamp = strtotime($raw);
    return $timestamp !== false ? (new \DateTime())->setTimestamp($timestamp) : null;
};

$normalizeReverseEvent = static function ($value): string {
    if (is_array($value)) {
        $value = isset($value['VALUE']) ? $value['VALUE'] : (isset($value['ID']) ? $value['ID'] : reset($value));
    }

    $event = trim((string)$value);
    if ($event === '45' || mb_strtolower($event) === 'вход') { return 'in'; }
    if ($event === '46' || mb_strtolower($event) === 'выход') { return 'out'; }
    return '';
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
    $excludedRoots = ['НАО «Национальная спутниковая компания»', 'Управление'];
    $chain = [];
    $currentId = $headDepartmentId;
    $guard = 0;
    while ($currentId > 0 && isset($departments[$currentId]) && $guard < 100) {
        $name = (string)$departments[$currentId]['NAME'];
        if (!in_array($name, $excludedRoots, true)) {
            $chain[] = $name;
        }
        $currentId = (int)$departments[$currentId]['IBLOCK_SECTION_ID'];
        $guard++;
    }

    $chain = array_reverse($chain);

    if (count($chain) <= 5) {
        while (count($chain) < 5) {
            $chain[] = '';
        }
        return array_values($chain);
    }

    if (count($chain) === 6) {
        return array_values($chain);
    }

    $firstFive = array_slice($chain, 0, 5);
    $sixth = implode(' / ', array_slice($chain, 5));
    $firstFive[] = $sixth;
    return array_values($firstFive);
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

$reverseUsersByPass = [];
$reverseUsersHl = \Bitrix\Highloadblock\HighloadBlockTable::getById(100)->fetch();
if ($reverseUsersHl) {
    $reverseUsersEntity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($reverseUsersHl);
    $reverseUsersClass = $reverseUsersEntity->getDataClass();
    $reverseUsersRows = $reverseUsersClass::getList([
        'select' => ['UF_EXT_ID', 'UF_LAST_NAME', 'UF_FIRST_NAME', 'UF_SECOND_NAME', 'UF_TAB_NUM'],
        'filter' => ['=UF_SOURCE' => 'Reverse'],
    ]);
    while ($row = $reverseUsersRows->fetch()) {
        $passId = trim((string)$row['UF_EXT_ID']);
        if ($passId === '') { continue; }
        $reverseUsersByPass[$passId] = [
            'FIO' => trim((string)$row['UF_LAST_NAME'] . ' ' . (string)$row['UF_FIRST_NAME'] . ' ' . (string)$row['UF_SECOND_NAME']),
            'LEGAL_ENTITY' => $normalizeLegalEntity($row['UF_TAB_NUM']),
        ];
    }
}

$reverseEventsByDayAndPass = [];
$reverseHl = \Bitrix\Highloadblock\HighloadBlockTable::getById(3)->fetch();
if ($reverseHl) {
    $reverseEntity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($reverseHl);
    $reverseClass = $reverseEntity->getDataClass();
    $dateTimeFrom = (clone $dateFrom)->setTime(0, 0, 0);
    $dateTimeTo = (clone $dateTo)->setTime(23, 59, 59);
    $rows = $reverseClass::getList([
        'select' => ['UF_DATETIME', 'UF_USER_ID', 'UF_IDREVERSE', 'UF_EVENT'],
        'filter' => ['>=UF_DATETIME' => BitrixDateTime::createFromPhp($dateTimeFrom), '<=UF_DATETIME' => BitrixDateTime::createFromPhp($dateTimeTo)],
        'order' => ['UF_DATETIME' => 'ASC'],
    ]);
    while ($row = $rows->fetch()) {
        $eventDateTime = $parseReverseEventDateTime($row['UF_DATETIME']);
        if (!$eventDateTime) { continue; }

        $dateKey = $eventDateTime->format('Y-m-d');
        if (!isset($cabinetDailyOffice[$dateKey])) { continue; }

        $passId = trim((string)$row['UF_IDREVERSE']);
        if ($passId === '') { continue; }

        $eventType = $normalizeReverseEvent($row['UF_EVENT']);
        if ($eventType === '') { continue; }

        if (!isset($reverseEventsByDayAndPass[$dateKey])) { $reverseEventsByDayAndPass[$dateKey] = []; }
        if (!isset($reverseEventsByDayAndPass[$dateKey][$passId])) { $reverseEventsByDayAndPass[$dateKey][$passId] = []; }
        $reverseEventsByDayAndPass[$dateKey][$passId][] = [
            'TIME' => $eventDateTime,
            'EVENT' => $eventType,
            'USER_ID' => (int)$row['UF_USER_ID'],
        ];
    }
}

$officePresenceKeys = [];
$unknownEmployees = [];
foreach ($reverseEventsByDayAndPass as $dateKey => $passes) {
    foreach ($passes as $passId => $events) {
        $openEntry = null;
        $workedSeconds = 0;
        $portalUserId = 0;
        foreach ($events as $event) {
            if ((int)$event['USER_ID'] > 0) { $portalUserId = (int)$event['USER_ID']; }
            if ($event['EVENT'] === 'in') {
                if ($openEntry === null) { $openEntry = $event['TIME']; }
            } elseif ($event['EVENT'] === 'out' && $openEntry !== null) {
                $workedSeconds += max(0, $event['TIME']->getTimestamp() - $openEntry->getTimestamp());
                $openEntry = null;
            }
        }
        if ($openEntry !== null) {
            $dayEnd = (new \DateTime($dateKey))->setTime(23, 59, 59);
            $workedSeconds += max(0, $dayEnd->getTimestamp() - $openEntry->getTimestamp());
        }
        if ($workedSeconds <= 4 * 60 * 60) { continue; }

        $reverseUser = isset($reverseUsersByPass[$passId]) ? $reverseUsersByPass[$passId] : ['FIO' => '', 'LEGAL_ENTITY' => 'НСК'];
        $legalEntity = $normalizeLegalEntity($reverseUser['LEGAL_ENTITY']);
        $employeeKey = $portalUserId > 0 ? 'U' . $portalUserId : 'P' . $passId;
        if (isset($officePresenceKeys[$dateKey][$employeeKey])) { continue; }
        $officePresenceKeys[$dateKey][$employeeKey] = true;

        $userCabinetNorm = $portalUserId > 0 && isset($userCabinetMap[$portalUserId]) ? $normalizeCabinet((string)$userCabinetMap[$portalUserId]) : '';
        $userDepartmentIds = $portalUserId > 0 && isset($userDepartmentsMap[$portalUserId]) ? $userDepartmentsMap[$portalUserId] : [];

        if ($userCabinetNorm !== '' && !empty($userDepartmentIds)) {
            if (!isset($cabinetDailyOffice[$dateKey][$userCabinetNorm])) {
                $cabinetDailyOffice[$dateKey][$userCabinetNorm] = ['TOTAL' => 0, 'BY_DEPARTMENT' => [], 'BY_LEGAL_ENTITY' => []];
            }
            if (!isset($cabinetDailyOffice[$dateKey][$userCabinetNorm]['BY_LEGAL_ENTITY'][$legalEntity])) {
                $cabinetDailyOffice[$dateKey][$userCabinetNorm]['BY_LEGAL_ENTITY'][$legalEntity] = 0;
            }
            $cabinetDailyOffice[$dateKey][$userCabinetNorm]['TOTAL']++;
            $cabinetDailyOffice[$dateKey][$userCabinetNorm]['BY_LEGAL_ENTITY'][$legalEntity]++;
            foreach ($userDepartmentIds as $departmentId) {
                if (!isset($cabinetDailyOffice[$dateKey][$userCabinetNorm]['BY_DEPARTMENT'][$departmentId])) {
                    $cabinetDailyOffice[$dateKey][$userCabinetNorm]['BY_DEPARTMENT'][$departmentId] = [];
                }
                if (!isset($cabinetDailyOffice[$dateKey][$userCabinetNorm]['BY_DEPARTMENT'][$departmentId][$legalEntity])) {
                    $cabinetDailyOffice[$dateKey][$userCabinetNorm]['BY_DEPARTMENT'][$departmentId][$legalEntity] = 0;
                }
                $cabinetDailyOffice[$dateKey][$userCabinetNorm]['BY_DEPARTMENT'][$departmentId][$legalEntity]++;
            }
            continue;
        }

        if ($cabinetFilterNorm !== '') { continue; }
        $employeeName = trim((string)$reverseUser['FIO']);
        if ($employeeName === '') { $employeeName = 'Пропуск ' . $passId; }
        $unknownEmployees[] = [
            'LEGAL_ENTITY' => $legalEntity,
            'EMPLOYEE' => $employeeName,
            'DATE' => $dateKey,
        ];
    }
}

usort($unknownEmployees, static function (array $left, array $right): int {
    $legalCompare = strnatcasecmp((string)$left['LEGAL_ENTITY'], (string)$right['LEGAL_ENTITY']);
    if ($legalCompare !== 0) { return $legalCompare; }

    $employeeCompare = strnatcasecmp((string)$left['EMPLOYEE'], (string)$right['EMPLOYEE']);
    if ($employeeCompare !== 0) { return $employeeCompare; }

    return strcmp((string)$left['DATE'], (string)$right['DATE']);
});

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
    <title>Расширенный отчет по рабочим местам Reverse</title>
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
<h1>Расширенный отчет по рабочим местам Reverse</h1>
<form method="get" class="filters">
    <label>С даты: <input type="date" name="date_from" value="<?=htmlspecialcharsbx($dateFrom->format('Y-m-d'))?>"></label>
    <label style="margin-left:8px;">По дату: <input type="date" name="date_to" value="<?=htmlspecialcharsbx($dateTo->format('Y-m-d'))?>"></label>
    <label style="margin-left:8px;">Кабинет: <select name="cabinet_filter"><option value="">Все</option><?php foreach ($availableCabinets as $cabOpt): ?><option value="<?=htmlspecialcharsbx($cabOpt)?>" <?= $cabinetFilterRaw === $cabOpt ? 'selected' : '' ?>><?=htmlspecialcharsbx($cabOpt)?></option><?php endforeach; ?></select></label>
    <button type="submit" style="margin-left:8px;">Показать</button>
</form>

<table>
    <thead>
    <tr>
        <th>ЮЛ</th>
        <th>СЕО-1</th>
        <th>СЕО-2</th>
        <th>СЕО-3</th>
        <th>СЕО-4</th>
        <th>СЕО-5</th>
        <th>СЕО-6</th>
        <th>Руководитель</th>
        <th>Кабинет</th>
        <th class="col-narrow">Кол-во рабочих мест в кабинете</th>
        <th class="col-narrow">Кол-во закрепленных чел. за кабинетом</th>
        <th>Дата</th>
        <th class="col-narrow">Кол-во сотрудников в офисе (&gt;4ч)</th>
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
                $departmentLegalCounts = isset($dayData['BY_DEPARTMENT'][$departmentId]) && is_array($dayData['BY_DEPARTMENT'][$departmentId]) ? $dayData['BY_DEPARTMENT'][$departmentId] : [];
                if (empty($departmentLegalCounts)) { $departmentLegalCounts = ['НСК' => 0]; }
                ksort($departmentLegalCounts, SORT_NATURAL | SORT_FLAG_CASE);
                ?>
                <?php foreach ($departmentLegalCounts as $legalEntity => $depOfficeCount): ?>
                <?php $deptChain = $getDepartmentChainFromHead($departmentId); ?>
                <tr>
                    <td><?=htmlspecialcharsbx((string)$legalEntity)?></td>
                    <td><?=htmlspecialcharsbx($deptChain[0])?></td>
                    <td><?=htmlspecialcharsbx($deptChain[1])?></td>
                    <td><?=htmlspecialcharsbx($deptChain[2])?></td>
                    <td><?=htmlspecialcharsbx($deptChain[3])?></td>
                    <td><?=htmlspecialcharsbx($deptChain[4])?></td>
                    <td><?=htmlspecialcharsbx($deptChain[5])?></td>
                    <td><?=htmlspecialcharsbx($headName)?></td>
                    <td><?=htmlspecialcharsbx($cabTitle)?></td>
                    <td><?= $workplaces ?></td>
                    <td><?= $assignedCount ?></td>
                    <td><?=htmlspecialcharsbx((new \DateTime($dateKey))->format('d.m.Y'))?></td>
                    <td><?= (int)$depOfficeCount ?></td>
                </tr>
                <?php endforeach; ?>
                <?php
            }
        }
    }
    ?>
    </tbody>
</table>

<h2>Сотрудники не определены</h2>
<table>
    <thead>
    <tr>
        <th>ЮЛ</th>
        <th>Сотрудник</th>
        <th>Дата</th>
    </tr>
    </thead>
    <tbody>
    <?php if (empty($unknownEmployees)): ?>
        <tr>
            <td colspan="3">Нет сотрудников без определенной структуры или кабинета.</td>
        </tr>
    <?php else: ?>
        <?php foreach ($unknownEmployees as $employee): ?>
            <tr>
                <td><?=htmlspecialcharsbx((string)$employee['LEGAL_ENTITY'])?></td>
                <td><?=htmlspecialcharsbx((string)$employee['EMPLOYEE'])?></td>
                <td><?=htmlspecialcharsbx((new \DateTime((string)$employee['DATE']))->format('d.m.Y'))?></td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>

<?php
$summaryCabinets = [];
foreach ($cabinetDirectory as $cabNorm => $cabData) {
    if ($cabinetFilterNorm !== '' && $cabNorm !== $cabinetFilterNorm) { continue; }
    $summaryCabinets[$cabNorm] = [
        'TITLE' => (string)$cabData['TITLE'],
        'WORKPLACES' => (int)$cabData['WORKPLACES'],
    ];
}
foreach ($userCabinetMap as $cabName) {
    $cabNorm = $normalizeCabinet((string)$cabName);
    if ($cabNorm === '' || ($cabinetFilterNorm !== '' && $cabNorm !== $cabinetFilterNorm)) { continue; }
    if (!isset($summaryCabinets[$cabNorm])) {
        $summaryCabinets[$cabNorm] = [
            'TITLE' => (string)$cabName,
            'WORKPLACES' => isset($cabinetDirectory[$cabNorm]) ? (int)$cabinetDirectory[$cabNorm]['WORKPLACES'] : 0,
        ];
    }
}
uasort($summaryCabinets, static function (array $left, array $right): int {
    return strnatcasecmp((string)$left['TITLE'], (string)$right['TITLE']);
});
?>

<h2>Сводная таблица по кабинетам</h2>
<table>
    <thead>
    <tr>
        <th>Кабинет</th>
        <th>Дата</th>
        <th class="col-narrow">Кол-во рабочих мест в кабинете</th>
        <th class="col-narrow">Кол-во сотрудников в офисе (&gt;4ч)</th>
        <th>% загрузки</th>
        <th class="col-narrow">Кол-во свободных рм</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($summaryCabinets as $cabNorm => $cabData): ?>
        <?php
        $cabTitle = (string)$cabData['TITLE'];
        $workplaces = (int)$cabData['WORKPLACES'];
        $rowspan = max(1, count($periodDays));
        ?>
        <?php foreach ($periodDays as $dateIndex => $dateKey): ?>
            <?php
            $dayData = isset($cabinetDailyOffice[$dateKey][$cabNorm]) ? $cabinetDailyOffice[$dateKey][$cabNorm] : ['TOTAL' => 0];
            $totalOccupied = (int)$dayData['TOTAL'];
            $utilization = $workplaces > 0 ? round(($totalOccupied / $workplaces) * 100, 1) : 0;
            $free = max(0, $workplaces - $totalOccupied);
            ?>
            <tr>
                <?php if ($dateIndex === 0): ?>
                    <td rowspan="<?= $rowspan ?>"><?=htmlspecialcharsbx($cabTitle)?></td>
                <?php endif; ?>
                <td><?=htmlspecialcharsbx((new \DateTime($dateKey))->format('d.m.Y'))?></td>
                <td><?= $workplaces ?></td>
                <td><?= $totalOccupied ?></td>
                <td><?= $utilization ?>%</td>
                <td><?= $free ?></td>
            </tr>
        <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
</table>
</body>
</html>
