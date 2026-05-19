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
$dateFrom->setTime(0,0,0);
$dateTo->setTime(0,0,0);
$showDiagnostics = isset($_GET['diagnostic']) && (string)$_GET['diagnostic'] === 'Y';
$reportMode = isset($_GET['report_mode']) && (string)$_GET['report_mode'] === 'summary' ? 'summary' : 'daily';
$cabinetFilterRaw = isset($_GET['cabinet_filter']) ? trim((string)$_GET['cabinet_filter']) : '';
$cabinetFilterNorm = '';


$parseSkudDateToKey = static function ($rawValue): string {
    if ($rawValue instanceof \DateTimeInterface) {
        return $rawValue->format('Y-m-d');
    }
    $raw = trim((string)$rawValue);
    if ($raw === '') { return ''; }

    $formats = ['Y-m-d H:i:s', 'Y-m-d', 'd.m.Y H:i:s', 'd.m.Y'];
    foreach ($formats as $format) {
        $dt = \DateTime::createFromFormat($format, $raw);
        if ($dt instanceof \DateTime) {
            return $dt->format('Y-m-d');
        }
    }

    $timestamp = strtotime($raw);
    if ($timestamp !== false) {
        return date('Y-m-d', $timestamp);
    }

    return '';
};

$normalizeDurationToMinutes = static function (string $value): int {
    $value = trim($value);
    if ($value === '' || $value === '00:00') { return 0; }
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $value, $m)) { return 0; }
    return ((int)$m[1] * 60) + (int)$m[2];
};

$normalizeCabinet = static function (string $cabinetRaw): string {
    $value = trim(mb_strtolower($cabinetRaw));
    if ($value === '' || $value === 'не указан') {
        return '';
    }

    $cabinetCode = '';
    if (preg_match('/каб\.?\s*([0-9]+[a-zа-я0-9\.-]*)/ui', $value, $match)) {
        $cabinetCode = (string)$match[1];
    } elseif (preg_match('/,\s*([0-9]+[a-zа-я0-9\.-]*)\s*$/ui', $value, $match)) {
        // Поддержка справочника вида "Московский, 601" без префикса "каб."
        $cabinetCode = (string)$match[1];
    } elseif (preg_match('/\b([0-9]+[a-zа-я0-9\.-]*)\b/ui', $value, $match)) {
        // Fallback на первый числовой токен (например, "офис 711-2")
        $cabinetCode = (string)$match[1];
    } else {
        return '';
    }

    $cabinetCode = str_replace([' ', ','], '', $cabinetCode);
    $cabinetCode = str_replace(['а', 'б', 'в', 'г'], ['a', 'b', 'v', 'g'], $cabinetCode);

    if (mb_strpos($value, 'москов') !== false) {
        return 'moskovskiy|' . $cabinetCode;
    }
    if (mb_strpos($value, 'новоладож') !== false) {
        return 'novoladozhskaya|' . $cabinetCode;
    }
    if (mb_strpos($value, 'рентген') !== false) {
        return 'rentgena|' . $cabinetCode;
    }

    return 'other|' . $cabinetCode;
};

if ($cabinetFilterRaw !== '') {
    $cabinetFilterNorm = $normalizeCabinet($cabinetFilterRaw);
}

$normalizeDirectoryCabinet = static function (string $cabinetName) use ($normalizeCabinet): string {
    return $normalizeCabinet($cabinetName);
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
$departmentUsers = [];
$userCabinetMap = [];
$userWorkFormatCodeMap = [];
$cabinetUsageAll = [];
$workFormatMap = [
    '1929' => 'Офис',
    '1930' => 'Дистанционный',
    '1931' => 'Комбинированный',
];
foreach ($departments as $departmentId => $department) {
    if ((int)$department['UF_HEAD'] <= 0) { continue; }
    $departmentData[$departmentId] = ['TOTAL' => 0, 'WORK_FORMATS' => [], 'CABINETS' => []];
    $departmentUsers[$departmentId] = [];
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
    $workFormatCode = trim((string)$user['UF_WORK_FORMAT']);
    $workFormat = $workFormatCode;
    $userCabinetMap[$userId] = $cabinet;
    $userWorkFormatCodeMap[$userId] = $workFormatCode;
    $workFormat = $workFormat !== '' ? (isset($workFormatMap[$workFormat]) ? $workFormatMap[$workFormat] : ('Неизвестный формат (' . $workFormat . ')')) : 'Не указан';

    foreach (array_keys($headDepartments) as $headDepartmentId) {
$departmentData[$headDepartmentId]['TOTAL']++;
        $departmentUsers[$headDepartmentId][$userId] = true;
        if (!isset($departmentData[$headDepartmentId]['WORK_FORMATS'][$workFormat])) {
            $departmentData[$headDepartmentId]['WORK_FORMATS'][$workFormat] = 0;
        }
        $departmentData[$headDepartmentId]['WORK_FORMATS'][$workFormat]++;
        if (!isset($departmentData[$headDepartmentId]['CABINETS'][$cabinet])) {
            $departmentData[$headDepartmentId]['CABINETS'][$cabinet] = 0;
        }
        $departmentData[$headDepartmentId]['CABINETS'][$cabinet]++;

        $normalizedCabinet = $normalizeCabinet($cabinet);
        if ($normalizedCabinet !== '') {
            if (!isset($cabinetUsageAll[$normalizedCabinet])) {
                $cabinetUsageAll[$normalizedCabinet] = ['TOTAL' => 0, 'BY_DEPARTMENT' => [], 'TITLE' => $cabinet];
            }
            $cabinetUsageAll[$normalizedCabinet]['TOTAL']++;
            if (!isset($cabinetUsageAll[$normalizedCabinet]['BY_DEPARTMENT'][$headDepartmentId])) {
                $cabinetUsageAll[$normalizedCabinet]['BY_DEPARTMENT'][$headDepartmentId] = 0;
            }
            $cabinetUsageAll[$normalizedCabinet]['BY_DEPARTMENT'][$headDepartmentId]++;
        }
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

$skudStats = [];
$period = new \DatePeriod($dateFrom, new \DateInterval('P1D'), (clone $dateTo)->modify('+1 day'));
foreach ($period as $day) {
    $dateKey = $day->format('Y-m-d');
    foreach ($departmentData as $departmentId => $_) {
        $skudStats[$departmentId][$dateKey] = ['OFFICE_LT_4' => 0, 'OFFICE_GT_4' => 0, 'REMOTE' => 0, 'ABSENT' => 0];
    }
}


$cabinetDailyOffice = [];
foreach ($period as $day) {
    $dateKey = $day->format('Y-m-d');
    $cabinetDailyOffice[$dateKey] = [];
}

$skudUserStates = [];
$skudDiagnostics = ['rows' => 0, 'with_user_mapping' => 0, 'parsed_date' => 0, 'in_period' => 0, 'office_lt_4' => 0, 'office_gt_4' => 0, 'remote' => 0, 'absent' => 0, 'sample' => []];
$skudHl = \Bitrix\Highloadblock\HighloadBlockTable::getById(5)->fetch();
if ($skudHl) {
    $skudEntity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($skudHl);
    $skudClass = $skudEntity->getDataClass();
    $rows = $skudClass::getList([
        'select' => ['UF_USER_ID', 'UF_DATE', 'UF_DAY_STATUS', 'UF_ENTRY_TIME', 'UF_EXIT_TIME', 'UF_NORMA', 'UF_WT_TOTAL'],
        'filter' => ['>=UF_DATE' => Date::createFromPhp($dateFrom), '<=UF_DATE' => Date::createFromPhp($dateTo)],
    ]);
    while ($row = $rows->fetch()) {
        $skudDiagnostics['rows']++;
        $userId = (int)$row['UF_USER_ID'];
        if ($userId <= 0 || !isset($userDepartmentsMap[$userId])) { continue; }
        $skudDiagnostics['with_user_mapping']++;

        $dateKey = $parseSkudDateToKey($row['UF_DATE']);
        if ($dateKey === '') { continue; }
        $skudDiagnostics['parsed_date']++;

        if ($dateKey < $dateFrom->format('Y-m-d') || $dateKey > $dateTo->format('Y-m-d')) { continue; }
        $skudDiagnostics['in_period']++;
        $entry = trim((string)$row['UF_ENTRY_TIME']);
        $userCabinet = isset($userCabinetMap[$userId]) ? (string)$userCabinetMap[$userId] : '';
        $userCabinetNorm = $normalizeCabinet($userCabinet);
        $norma = $normalizeDurationToMinutes((string)$row['UF_NORMA']);
        $worked = $normalizeDurationToMinutes((string)$row['UF_WT_TOTAL']);
        $status = trim((string)$row['UF_DAY_STATUS']);

        if ($showDiagnostics && count($skudDiagnostics['sample']) < 20) {
            $skudDiagnostics['sample'][] = [
                'USER_ID' => $userId,
                'UF_DATE_RAW' => is_scalar($row['UF_DATE']) ? (string)$row['UF_DATE'] : gettype($row['UF_DATE']),
                'DATE_KEY' => $dateKey,
                'ENTRY' => $entry,
                'WT_TOTAL' => (string)$row['UF_WT_TOTAL'],
                'NORMA' => (string)$row['UF_NORMA'],
                'STATUS' => $status,
            ];
        }

        foreach ($userDepartmentsMap[$userId] as $departmentId) {
            if (!isset($skudStats[$departmentId][$dateKey])) { continue; }

            $state = '';
            if ($entry !== '') {
                $state = $worked > 240 ? 'OFFICE_GT_4' : 'OFFICE_LT_4';
                if ($userCabinetNorm !== '') {
                    if (!isset($cabinetDailyOffice[$dateKey][$userCabinetNorm])) {
                        $cabinetDailyOffice[$dateKey][$userCabinetNorm] = ['TOTAL' => 0, 'BY_DEPARTMENT' => []];
                    }
                    $cabinetDailyOffice[$dateKey][$userCabinetNorm]['TOTAL']++;
                    if (!isset($cabinetDailyOffice[$dateKey][$userCabinetNorm]['BY_DEPARTMENT'][$departmentId])) {
                        $cabinetDailyOffice[$dateKey][$userCabinetNorm]['BY_DEPARTMENT'][$departmentId] = 0;
                    }
                    $cabinetDailyOffice[$dateKey][$userCabinetNorm]['BY_DEPARTMENT'][$departmentId]++;
                }
            } elseif ($norma > 0 && $worked === $norma) {
                $state = 'REMOTE';
            } elseif ($status !== '' && mb_strtolower($status) !== 'работа') {
                $state = 'ABSENT';
            }

            if ($state !== '') {
                if (!isset($skudUserStates[$departmentId])) { $skudUserStates[$departmentId] = []; }
                if (!isset($skudUserStates[$departmentId][$dateKey])) { $skudUserStates[$departmentId][$dateKey] = []; }
                $prev = isset($skudUserStates[$departmentId][$dateKey][$userId]) ? $skudUserStates[$departmentId][$dateKey][$userId] : '';
                $priority = ['OFFICE_GT_4'=>4,'OFFICE_LT_4'=>3,'REMOTE'=>2,'ABSENT'=>1,''=>0];
                if ($priority[$state] > $priority[$prev]) {
                    $skudUserStates[$departmentId][$dateKey][$userId] = $state;
                }
            }
        }
    }
}


$cabinetFilterOptions = [];
foreach ($cabinetDirectory as $cabKey => $cabData) {
    $cabinetFilterOptions[$cabData['TITLE']] = true;
}
foreach ($departmentData as $dRow) {
    foreach ($dRow['CABINETS'] as $cabName => $_c) {
        $cabinetFilterOptions[$cabName] = true;
    }
}
$availableCabinets = array_keys($cabinetFilterOptions);
sort($availableCabinets, SORT_NATURAL | SORT_FLAG_CASE);

$filteredDepartmentIds = [];
foreach ($departmentData as $departmentId => $departmentRow) {
    if ($cabinetFilterNorm === '') {
        $filteredDepartmentIds[] = $departmentId;
        continue;
    }

    $hasCabinet = false;
    foreach ($departmentRow['CABINETS'] as $cabName => $_count) {
        if ($normalizeCabinet($cabName) === $cabinetFilterNorm) {
            $hasCabinet = true;
            break;
        }
    }
    if ($hasCabinet) {
        $filteredDepartmentIds[] = $departmentId;
    }
}


// Пересчет статистики: каждый сотрудник подразделения на каждую дату должен попасть в ровно один статус.
foreach ($departmentData as $departmentId => $_d) {
    $deptUserIds = isset($departmentUsers[$departmentId]) ? array_keys($departmentUsers[$departmentId]) : [];
    foreach (new \DatePeriod($dateFrom, new \DateInterval('P1D'), (clone $dateTo)->modify('+1 day')) as $d) {
        $dateKey = $d->format('Y-m-d');
        $skudStats[$departmentId][$dateKey] = ['OFFICE_LT_4' => 0, 'OFFICE_GT_4' => 0, 'REMOTE' => 0, 'ABSENT' => 0];
        foreach ($deptUserIds as $uid) {
            if (isset($skudUserStates[$departmentId][$dateKey][$uid])) {
                $state = $skudUserStates[$departmentId][$dateKey][$uid];
            } else {
                $userWorkFormatCode = isset($userWorkFormatCodeMap[$uid]) ? (string)$userWorkFormatCodeMap[$uid] : '';
                $state = $userWorkFormatCode === '1930' ? 'REMOTE' : 'ABSENT';
            }
            $skudStats[$departmentId][$dateKey][$state]++;
        }
    }
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Отчет по подразделениям и рабочим местам</title>
    <style>
        body { font-family: Arial,sans-serif; font-size:13px; margin:16px; }
        table { border-collapse: collapse; width:100%; }
        th, td { border:1px solid #d8e0ea; padding:6px 8px; vertical-align: top; }
        th { background:#f5f9ff; }
        .filters { margin: 10px 0 16px; }
        .filters input { padding:4px 6px; }
        .cab-line { margin-bottom: 6px; padding: 4px 6px; border-radius: 4px; }
        .delta-bad { background: #ffd9d9; }
        .delta-warn { background: #fff5cc; }
        .delta-good { background: #d9f7d9; }
    </style>
</head>
<body>
<h1>Отчет по подразделениям и рабочим местам</h1>
<form method="get" class="filters">
    <label>С даты: <input type="date" name="date_from" value="<?=htmlspecialcharsbx($dateFrom->format('Y-m-d'))?>"></label>
    <label style="margin-left:8px;">По дату: <input type="date" name="date_to" value="<?=htmlspecialcharsbx($dateTo->format('Y-m-d'))?>"></label>
    <label style="margin-left:8px;"><input type="checkbox" name="diagnostic" value="Y" <?= $showDiagnostics ? 'checked' : '' ?>> Диагностика</label>
    <label style="margin-left:8px;">Режим: <select name="report_mode"><option value="daily" <?= $reportMode === 'daily' ? 'selected' : '' ?>>По датам за период</option><option value="summary" <?= $reportMode === 'summary' ? 'selected' : '' ?>>Сводный за период</option></select></label>
    <label style="margin-left:8px;">Кабинет: <select name="cabinet_filter"><option value="">Все</option><?php foreach ($availableCabinets as $cabOpt): ?><option value="<?=htmlspecialcharsbx($cabOpt)?>" <?= $cabinetFilterRaw === $cabOpt ? 'selected' : '' ?>><?=htmlspecialcharsbx($cabOpt)?></option><?php endforeach; ?></select></label>
    <button type="submit" style="margin-left:8px;">Показать</button>
</form>
<div>Период: <strong><?=htmlspecialcharsbx($dateFrom->format('d.m.Y'))?></strong> — <strong><?=htmlspecialcharsbx($dateTo->format('d.m.Y'))?></strong></div>
<?php if ($showDiagnostics): ?>
<pre style="background:#f5f5f5;padding:10px;">СКУД диагностика:
<?=htmlspecialcharsbx(print_r($skudDiagnostics, true))?></pre>
<?php endif; ?>

<?php
$periodDays = [];
foreach (new \DatePeriod($dateFrom, new \DateInterval('P1D'), (clone $dateTo)->modify('+1 day')) as $d) {
    $periodDays[] = $d->format('Y-m-d');
}

$periodDaysCount = max(1, count($periodDays));
$summaryByDepartment = [];
foreach ($departmentData as $departmentId => $departmentRow) {
    $sumOffice = 0;
    $sumRemote = 0;
    $sumAbsent = 0;
    foreach ($periodDays as $dayKey) {
        $stat = isset($skudStats[$departmentId][$dayKey]) ? $skudStats[$departmentId][$dayKey] : ['OFFICE_LT_4'=>0,'OFFICE_GT_4'=>0,'REMOTE'=>0,'ABSENT'=>0];
        $sumOffice += (int)$stat['OFFICE_LT_4'] + (int)$stat['OFFICE_GT_4'];
        $sumRemote += (int)$stat['REMOTE'];
        $sumAbsent += (int)$stat['ABSENT'];
    }
    $summaryByDepartment[$departmentId] = [
        'AVG_OFFICE' => round($sumOffice / $periodDaysCount, 2),
        'AVG_REMOTE' => round($sumRemote / $periodDaysCount, 2),
        'AVG_ABSENT' => round($sumAbsent / $periodDaysCount, 2),
    ];
}
?>

<?php if ($reportMode === 'daily'): ?>
<table>
    <thead>
    <tr>
        <th rowspan="2">Подразделение</th>
        <th rowspan="2">Руководитель</th>
        <th rowspan="2">Кол-во сотрудников</th>
                <?php foreach ($periodDays as $dateKey): ?>
            <th colspan="5"><?=htmlspecialcharsbx((new \DateTime($dateKey))->format('d.m.Y'))?></th>
        <?php endforeach; ?>
    </tr>
    <tr>
        <?php foreach ($periodDays as $dateKey): ?>
            <th>В офисе &lt;= 4ч</th>
            <th>В офисе &gt; 4ч</th>
            <th>Сотрудников на удаленке</th>
            <th>Сотрудников отсутствует</th>
            <th>Кабинеты и места</th>
        <?php endforeach; ?>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($filteredDepartmentIds as $departmentId): $departmentRow = $departmentData[$departmentId]; ?>
        <?php
        $headName = isset($headsMap[$departments[$departmentId]['UF_HEAD']]) ? $headsMap[$departments[$departmentId]['UF_HEAD']] : 'Не назначен';
        $formats = $departmentRow['WORK_FORMATS']; ksort($formats, SORT_NATURAL | SORT_FLAG_CASE);
        $cabinets = $departmentRow['CABINETS']; ksort($cabinets, SORT_NATURAL | SORT_FLAG_CASE);
        ?>
        <tr>
            <td><?=htmlspecialcharsbx($departments[$departmentId]['NAME'])?></td>
            <td><?=htmlspecialcharsbx($headName)?></td>
            <td>
                <strong><?= (int)$departmentRow['TOTAL'] ?></strong><br>
                <?php foreach ($formats as $name => $count) { echo htmlspecialcharsbx($name).' — '.(int)$count.'<br>'; } ?>
            </td>
            <?php foreach ($periodDays as $dateKey): $stat = isset($skudStats[$departmentId][$dateKey]) ? $skudStats[$departmentId][$dateKey] : ['OFFICE_LT_4'=>0,'OFFICE_GT_4'=>0,'REMOTE'=>0,'ABSENT'=>0]; ?>
                <td><?= (int)$stat['OFFICE_LT_4'] ?></td>
                <td><?= (int)$stat['OFFICE_GT_4'] ?></td>
                <td><?= (int)$stat['REMOTE'] ?></td>
                <td><?= (int)$stat['ABSENT'] ?></td>
                <td>
                    <?php foreach ($cabinets as $cabName => $cabCnt): ?>
                        <?php
                        $cabNorm = $normalizeCabinet($cabName);
                        if ($cabinetFilterNorm !== '' && $cabNorm !== $cabinetFilterNorm) { continue; }
                        $places = 0;
                        $cabTitle = $cabName;
                        if ($cabNorm !== '' && isset($cabinetDirectory[$cabNorm])) {
                            $places = (int)$cabinetDirectory[$cabNorm]['WORKPLACES'];
                            $cabTitle = (string)$cabinetDirectory[$cabNorm]['TITLE'];
                        }
                        $dayCab = ($cabNorm !== '' && isset($cabinetDailyOffice[$dateKey][$cabNorm])) ? $cabinetDailyOffice[$dateKey][$cabNorm] : ['TOTAL' => 0, 'BY_DEPARTMENT' => []];
                        $thisDep = isset($dayCab['BY_DEPARTMENT'][$departmentId]) ? (int)$dayCab['BY_DEPARTMENT'][$departmentId] : 0;
                        $totalOcc = (int)$dayCab['TOTAL'];
                        $otherDep = max(0, $totalOcc - $thisDep);
                        $delta = $places - $totalOcc;
                        $deltaClass = $delta < 0 ? 'delta-bad' : ($delta <= 2 ? 'delta-warn' : 'delta-good');
                        ?>
                        <div class="<?= $deltaClass ?>" style="margin-top:3px;padding:2px 4px;">
                            <strong><?=htmlspecialcharsbx($cabTitle)?></strong>: мест <?= $places ?>, это подр. <?= $thisDep ?>, другие <?= $otherDep ?>, Δ <?= $delta ?>
                        </div>
                    <?php endforeach; ?>
                </td>
            <?php endforeach; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php else: ?>
<table>
    <thead>
    <tr>
        <th>Подразделение</th><th>Руководитель</th><th>Кол-во сотрудников</th><th>Кабинеты и загрузка</th><th>Рабочих мест (всего)</th><th>Среднее в офисе</th><th>Среднее на удаленке</th><th>Среднее отсутствующих</th><th>Утилизация рабочих мест, %</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($filteredDepartmentIds as $departmentId): $departmentRow = $departmentData[$departmentId]; ?>
        <?php
        $headName = isset($headsMap[$departments[$departmentId]['UF_HEAD']]) ? $headsMap[$departments[$departmentId]['UF_HEAD']] : 'Не назначен';
        $cabinets = $departmentRow['CABINETS']; ksort($cabinets, SORT_NATURAL | SORT_FLAG_CASE);
        $totalPlaces = 0;
        $cabinetLoadLines = [];
        foreach ($cabinets as $name => $count) {
            $normalizedCabinet = $normalizeCabinet($name);
            $places = 0;
            $title = $name;
            if ($normalizedCabinet !== '' && isset($cabinetDirectory[$normalizedCabinet])) {
                $places = (int)$cabinetDirectory[$normalizedCabinet]['WORKPLACES'];
                $title = (string)$cabinetDirectory[$normalizedCabinet]['TITLE'];
            }
            $totalPlaces += $places;
            $sumTotalOccupied = 0;
            foreach ($periodDays as $dayKey) {
                $sumTotalOccupied += ($normalizedCabinet !== '' && isset($cabinetDailyOffice[$dayKey][$normalizedCabinet])) ? (int)$cabinetDailyOffice[$dayKey][$normalizedCabinet]['TOTAL'] : 0;
            }
            $avgOccupied = round($sumTotalOccupied / $periodDaysCount, 2);
            $cabUtil = $places > 0 ? round(($avgOccupied / $places) * 100, 1) : 0;
            $cabinetLoadLines[] = htmlspecialcharsbx($title).': ср. занято '.$avgOccupied.' из '.$places.' ('.$cabUtil.'%)';
        }
        $avgOffice = (float)$summaryByDepartment[$departmentId]['AVG_OFFICE'];
        $avgRemote = (float)$summaryByDepartment[$departmentId]['AVG_REMOTE'];
        $avgAbsent = (float)$summaryByDepartment[$departmentId]['AVG_ABSENT'];
        $totalEmployees = max(1, (int)$departmentRow['TOTAL']);
        $avgOfficePercent = round(($avgOffice / $totalEmployees) * 100, 1);
        $avgRemotePercent = round(($avgRemote / $totalEmployees) * 100, 1);
        $avgAbsentPercent = round(($avgAbsent / $totalEmployees) * 100, 1);
        $util = $totalPlaces > 0 ? round(($avgOffice / $totalPlaces) * 100, 1) : 0;
        ?>
        <tr>
            <td><?=htmlspecialcharsbx($departments[$departmentId]['NAME'])?></td>
            <td><?=htmlspecialcharsbx($headName)?></td>
            <td><?= (int)$departmentRow['TOTAL'] ?></td>
            <td><?= !empty($cabinetLoadLines) ? implode('<br>', $cabinetLoadLines) : '—' ?></td>
            <td><?= $totalPlaces ?></td>
            <td><?= $avgOffice ?> (<?= $avgOfficePercent ?>%)</td>
            <td><?= $avgRemote ?> (<?= $avgRemotePercent ?>%)</td>
            <td><?= $avgAbsent ?> (<?= $avgAbsentPercent ?>%)</td>
            <td><?= $util ?>%</td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
</body>
</html>
