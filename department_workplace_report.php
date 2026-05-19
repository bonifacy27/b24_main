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

    if (!preg_match('/каб\.?\s*([0-9]+[a-zа-я0-9\.-]*)/ui', $value, $match)) {
        return '';
    }

    $cabinetCode = str_replace([' ', ','], '', $match[1]);
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

$normalizeDirectoryCabinet = static function (string $cabinetName): string {
    $value = trim(mb_strtolower($cabinetName));
    if ($value === '') {
        return '';
    }

    $parts = array_map('trim', explode(',', $value, 2));
    if (count($parts) < 2) {
        return '';
    }

    $location = $parts[0];
    $cabinetCode = str_replace([' ', ','], '', $parts[1]);
    $cabinetCode = str_replace(['а', 'б', 'в', 'г'], ['a', 'b', 'v', 'g'], $cabinetCode);

    if (mb_strpos($location, 'москов') !== false) {
        return 'moskovskiy|' . $cabinetCode;
    }
    if (mb_strpos($location, 'новоладож') !== false) {
        return 'novoladozhskaya|' . $cabinetCode;
    }
    if (mb_strpos($location, 'рентген') !== false) {
        return 'rentgena|' . $cabinetCode;
    }

    return 'other|' . $cabinetCode;
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
$userCabinetMap = [];
$cabinetUsageAll = [];
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
    $userCabinetMap[$userId] = $cabinet;
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

            if ($entry !== '') {
                if ($worked > 240) {
                    $skudStats[$departmentId][$dateKey]['OFFICE_GT_4']++;
                    $skudDiagnostics['office_gt_4']++;
                } else {
                    $skudStats[$departmentId][$dateKey]['OFFICE_LT_4']++;
                    $skudDiagnostics['office_lt_4']++;
                }

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
                continue;
            }

            if ($norma > 0 && $worked === $norma) {
                $skudStats[$departmentId][$dateKey]['REMOTE']++;
                $skudDiagnostics['remote']++;
                continue;
            }

            if ($status !== '' && mb_strtolower($status) !== 'работа') {
                $skudStats[$departmentId][$dateKey]['ABSENT']++;
                $skudDiagnostics['absent']++;
            }
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
?>

<table>
    <thead>
    <tr>
        <th rowspan="2">Подразделение</th>
        <th rowspan="2">Руководитель</th>
        <th rowspan="2">Кол-во сотрудников</th>
        <th rowspan="2">Форматы работы сотрудников</th>
        <th rowspan="2">Кабинеты и рабочие места</th>
        <?php foreach ($periodDays as $dateKey): ?>
            <th colspan="4"><?=htmlspecialcharsbx((new \DateTime($dateKey))->format('d.m.Y'))?></th>
        <?php endforeach; ?>
    </tr>
    <tr>
        <?php foreach ($periodDays as $dateKey): ?>
            <th>В офисе &lt;= 4ч</th>
            <th>В офисе &gt; 4ч</th>
            <th>Сотрудников на удаленке</th>
            <th>Сотрудников отсутствует</th>
        <?php endforeach; ?>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($departmentData as $departmentId => $departmentRow): ?>
        <?php
        $headName = isset($headsMap[$departments[$departmentId]['UF_HEAD']]) ? $headsMap[$departments[$departmentId]['UF_HEAD']] : 'Не назначен';
        $formats = $departmentRow['WORK_FORMATS']; ksort($formats, SORT_NATURAL | SORT_FLAG_CASE);
        $cabinets = $departmentRow['CABINETS']; ksort($cabinets, SORT_NATURAL | SORT_FLAG_CASE);
        ?>
        <tr>
            <td><?=htmlspecialcharsbx($departments[$departmentId]['NAME'])?></td>
            <td><?=htmlspecialcharsbx($headName)?></td>
            <td><?= (int)$departmentRow['TOTAL'] ?></td>
            <td><?php foreach ($formats as $name => $count) { echo htmlspecialcharsbx($name).' — '.(int)$count.'<br>'; } ?></td>
            <td>
                <?php foreach ($cabinets as $name => $count): ?>
                    <?php
                    $normalizedCabinet = $normalizeCabinet($name);
                    $places = 0;
                    $cabinetTitle = $name;
                    if ($normalizedCabinet !== '' && isset($cabinetDirectory[$normalizedCabinet])) {
                        $places = (int)$cabinetDirectory[$normalizedCabinet]['WORKPLACES'];
                        $cabinetTitle = (string)$cabinetDirectory[$normalizedCabinet]['TITLE'];
                    }
                    ?>
                    <div class="cab-line" style="border:1px solid #e1e7ef;">
                        <strong><?= htmlspecialcharsbx($cabinetTitle) ?></strong> (мест всего: <strong><?= $places ?></strong>)<br>
                        <?php foreach ($periodDays as $dayKey): ?>
                            <?php
                            $dayCab = ($normalizedCabinet !== '' && isset($cabinetDailyOffice[$dayKey][$normalizedCabinet])) ? $cabinetDailyOffice[$dayKey][$normalizedCabinet] : ['TOTAL' => 0, 'BY_DEPARTMENT' => []];
                            $thisDepartmentOccupied = isset($dayCab['BY_DEPARTMENT'][$departmentId]) ? (int)$dayCab['BY_DEPARTMENT'][$departmentId] : 0;
                            $totalOccupied = (int)$dayCab['TOTAL'];
                            $otherOccupied = max(0, $totalOccupied - $thisDepartmentOccupied);
                            $delta = $places - $totalOccupied;
                            $deltaClass = $delta < 0 ? 'delta-bad' : ($delta <= 2 ? 'delta-warn' : 'delta-good');
                            ?>
                            <div class="<?= $deltaClass ?>" style="margin-top:4px;padding:2px 4px;">
                                <?=htmlspecialcharsbx((new \DateTime($dayKey))->format('d.m.Y'))?>: 
                                это подразделение <strong><?= $thisDepartmentOccupied ?></strong>,
                                другие <strong><?= $otherOccupied ?></strong>,
                                всего занято <strong><?= $totalOccupied ?></strong>,
                                разница <strong><?= $delta ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </td>
            <?php foreach ($periodDays as $dateKey): $stat = isset($skudStats[$departmentId][$dateKey]) ? $skudStats[$departmentId][$dateKey] : ['OFFICE_LT_4'=>0,'OFFICE_GT_4'=>0,'REMOTE'=>0,'ABSENT'=>0]; ?>
                <td><?= (int)$stat['OFFICE_LT_4'] ?></td>
                <td><?= (int)$stat['OFFICE_GT_4'] ?></td>
                <td><?= (int)$stat['REMOTE'] ?></td>
                <td><?= (int)$stat['ABSENT'] ?></td>
            <?php endforeach; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</body>
</html>
