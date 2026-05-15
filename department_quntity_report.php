<?php
/**
 * department_quntity_report.php
 *
 * Отчет по подразделениям компании:
 * - Подразделение
 * - Руководитель
 * - Кол-во сотрудников (с распределением по кабинетам UF_CABINET)
 *
 * Пример:
 * /pub/company/department_quntity_report.php
 */

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('NOT_CHECK_PERMISSIONS', true);

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use Bitrix\Main\Loader;

if (!Loader::includeModule('iblock')) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Ошибка: не удалось подключить модуль iblock.';
    exit;
}

if (!Loader::includeModule('main')) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Ошибка: не удалось подключить модуль main.';
    exit;
}

if (!Loader::includeModule('highloadblock')) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Ошибка: не удалось подключить модуль highloadblock.';
    exit;
}

$iblockId = (int)\COption::GetOptionInt('intranet', 'iblock_structure', 0);
if ($iblockId <= 0) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Ошибка: не найден ID инфоблока структуры компании (опция intranet: iblock_structure).';
    exit;
}

$departments = [];
$rsSections = \CIBlockSection::GetList(
    ['LEFT_MARGIN' => 'ASC'],
    [
        'IBLOCK_ID' => $iblockId,
        'GLOBAL_ACTIVE' => 'Y',
    ],
    false,
    ['ID', 'NAME', 'IBLOCK_SECTION_ID', 'DEPTH_LEVEL', 'LEFT_MARGIN', 'SORT', 'UF_HEAD']
);

while ($section = $rsSections->Fetch()) {
    $departmentId = (int)$section['ID'];
    $departments[$departmentId] = [
        'ID' => $departmentId,
        'NAME' => (string)$section['NAME'],
        'SORT' => (int)$section['SORT'],
        'IBLOCK_SECTION_ID' => (int)$section['IBLOCK_SECTION_ID'],
        'LEFT_MARGIN' => (int)$section['LEFT_MARGIN'],
        'DEPTH_LEVEL' => (int)$section['DEPTH_LEVEL'],
        'UF_HEAD' => (int)$section['UF_HEAD'],
    ];
}

if (empty($departments)) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Подразделения не найдены.';
    exit;
}

$diagnosticDepartmentId = isset($_GET['diagnostic_department_id']) ? (int)$_GET['diagnostic_department_id'] : 0;
$diagnostics = [];

$headIds = [];
foreach ($departments as $department) {
    if ($department['UF_HEAD'] > 0) {
        $headIds[$department['UF_HEAD']] = true;
    }
}

$headsMap = [];
if (!empty($headIds)) {
    $rsHeads = \CUser::GetList(
        $by = 'id',
        $order = 'asc',
        ['ID' => implode('|', array_keys($headIds))],
        ['SELECT' => ['ID'], 'FIELDS' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN', 'ACTIVE']]
    );

    while ($head = $rsHeads->Fetch()) {
        $headId = (int)$head['ID'];
        $fio = trim((string)$head['LAST_NAME'] . ' ' . (string)$head['NAME'] . ' ' . (string)$head['SECOND_NAME']);
        if ($fio === '') {
            $fio = (string)$head['LOGIN'];
        }
        $headsMap[$headId] = $fio;
    }
}

$departmentEmployees = [];
$departmentChildren = [];
foreach ($departments as $departmentId => $_department) {
    $departmentEmployees[$departmentId] = [
        'TOTAL' => 0,
        'CABINETS' => [],
    ];
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

$normalizeCabinet = static function (string $cabinetRaw): string {
    $value = trim(mb_strtolower($cabinetRaw));
    if ($value === '') {
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

$cabinetUsageTotals = [];

foreach ($departments as $departmentId => $department) {
    $parentId = (int)$department['IBLOCK_SECTION_ID'];
    if ($parentId <= 0 || !isset($departments[$parentId])) {
        $assignResponsibleHead($departmentId, 0);
    }
}

$rsUsers = \CUser::GetList(
    $by = 'id',
    $order = 'asc',
    ['ACTIVE' => 'Y'],
    ['SELECT' => ['UF_DEPARTMENT', 'UF_CABINET'], 'FIELDS' => ['ID', 'LOGIN', 'UF_DEPARTMENT', 'UF_CABINET']]
);

while ($user = $rsUsers->Fetch()) {
    $userDepartments = $user['UF_DEPARTMENT'];
    if (!is_array($userDepartments)) {
        $userDepartments = [(int)$userDepartments];
    }

    $targetHeadDepartments = [];
    foreach ($userDepartments as $userDepartmentId) {
        $userDepartmentId = (int)$userDepartmentId;
        if ($userDepartmentId <= 0 || !isset($departmentResponsibleHead[$userDepartmentId])) {
            continue;
        }

        $headDepartmentId = (int)$departmentResponsibleHead[$userDepartmentId];
        if ($headDepartmentId > 0) {
            $targetHeadDepartments[$headDepartmentId] = $userDepartmentId;
        }
    }

    if (empty($targetHeadDepartments)) {
        continue;
    }

    $cabinet = trim((string)$user['UF_CABINET']);
    if ($cabinet === '') {
        $cabinet = 'Не указан';
    }

    foreach ($targetHeadDepartments as $headDepartmentId => $matchedDepartmentId) {
        if (!isset($departments[$headDepartmentId]) || (int)$departments[$headDepartmentId]['UF_HEAD'] <= 0) {
            continue;
        }

        $departmentEmployees[$headDepartmentId]['TOTAL']++;
        if (!isset($departmentEmployees[$headDepartmentId]['CABINETS'][$cabinet])) {
            $departmentEmployees[$headDepartmentId]['CABINETS'][$cabinet] = 0;
        }
        $departmentEmployees[$headDepartmentId]['CABINETS'][$cabinet]++;

        $normalizedCabinet = $normalizeCabinet($cabinet);
        if ($normalizedCabinet !== '') {
            if (!isset($cabinetUsageTotals[$normalizedCabinet])) {
                $cabinetUsageTotals[$normalizedCabinet] = ['count' => 0, 'sample' => $cabinet];
            }
            $cabinetUsageTotals[$normalizedCabinet]['count']++;
        }

        if ($diagnosticDepartmentId > 0 && $headDepartmentId === $diagnosticDepartmentId) {
            if (!isset($diagnostics[$headDepartmentId])) {
                $diagnostics[$headDepartmentId] = [];
            }
            $diagnostics[$headDepartmentId][] = [
                'USER_ID' => (int)$user['ID'],
                'LOGIN' => (string)$user['LOGIN'],
                'RAW_UF_DEPARTMENT' => $user['UF_DEPARTMENT'],
                'MATCHED_DESCENDANT_ID' => $matchedDepartmentId,
                'CABINET' => $cabinet,
            ];
        }
    }
}

$cabinetDirectory = [];
$hlBlockId = 74;
$hlBlock = \Bitrix\Highloadblock\HighloadBlockTable::getById($hlBlockId)->fetch();
if ($hlBlock) {
    $entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlBlock);
    $entityClass = $entity->getDataClass();
    $rsCabinets = $entityClass::getList([
        'select' => ['ID', 'UF_NAME', 'UF_WORKPLACES'],
        'order' => ['UF_NAME' => 'ASC'],
    ]);

    while ($cabinetRow = $rsCabinets->fetch()) {
        $dirName = trim((string)$cabinetRow['UF_NAME']);
        $normalizedKey = $normalizeDirectoryCabinet($dirName);
        if ($normalizedKey === '') {
            continue;
        }

        $cabinetDirectory[$normalizedKey] = [
            'name' => $dirName,
            'workplaces' => (int)$cabinetRow['UF_WORKPLACES'],
        ];
    }
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Отчет по подразделениям</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 14px; color: #1f2d3d; margin: 16px; }
        h1 { font-size: 20px; margin: 0 0 14px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #d8e0ea; padding: 8px 10px; vertical-align: top; }
        th { background: #f5f9ff; text-align: left; }
        .muted { color: #6f7c8a; }
        .department { white-space: nowrap; }
        ul { margin: 6px 0 0 18px; padding: 0; }
    </style>
</head>
<body>
<h1>Отчет по подразделениям</h1>
<?php if ($diagnosticDepartmentId > 0): ?>
    <h2>Диагностика для подразделения ID <?= (int)$diagnosticDepartmentId ?></h2>
    <?php if (!isset($departments[$diagnosticDepartmentId])): ?>
        <div class="muted">Подразделение с таким ID не найдено в структуре.</div>
    <?php else: ?>
        <?php
        $diagHeadId = (int)$departments[$diagnosticDepartmentId]['UF_HEAD'];
        $diagHeadName = isset($headsMap[$diagHeadId]) ? $headsMap[$diagHeadId] : 'Не найден';
        $diagRows = isset($diagnostics[$diagnosticDepartmentId]) ? $diagnostics[$diagnosticDepartmentId] : [];
        ?>
        <div><strong>Подразделение:</strong> <?= htmlspecialcharsbx($departments[$diagnosticDepartmentId]['NAME']) ?></div>
        <div><strong>UF_HEAD подразделения:</strong> <?= $diagHeadId ?> (<?= htmlspecialcharsbx($diagHeadName) ?>)</div>
        <div><strong>Подчиненные ищутся как непосредственные: сотрудник относится к ближайшему сверху подразделению с назначенным руководителем.</strong></div>
        <div><strong>Найдено подчиненных:</strong> <?= count($diagRows) ?></div>

        <?php if (!empty($diagRows)): ?>
            <table style="margin-top:10px; margin-bottom:20px;">
                <thead>
                <tr>
                    <th>User ID</th>
                    <th>Login</th>
                    <th>RAW UF_DEPARTMENT</th>
                    <th>Matched descendant dept</th>
                    <th>UF_CABINET</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($diagRows as $row): ?>
                    <tr>
                        <td><?= (int)$row['USER_ID'] ?></td>
                        <td><?= htmlspecialcharsbx($row['LOGIN']) ?></td>
                        <td><pre style="margin:0;white-space:pre-wrap;"><?= htmlspecialcharsbx(print_r($row['RAW_UF_DEPARTMENT'], true)) ?></pre></td>
                        <td><?= (int)$row['MATCHED_DESCENDANT_ID'] ?></td>
                        <td><?= htmlspecialcharsbx($row['CABINET']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="muted" style="margin-bottom:20px;">Совпадений не найдено. Проверьте привязку сотрудников к UF_DEPARTMENT и назначение руководителей в цепочке подразделений.</div>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<table>
    <thead>
    <tr>
        <th>Подразделение</th>
        <th>Руководитель</th>
        <th>Кол-во сотрудников</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($departments as $departmentId => $department): ?>
        <?php if ((int)$department['UF_HEAD'] <= 0) { continue; } ?>
        <?php
        $depthPrefix = str_repeat('— ', max(0, (int)$department['DEPTH_LEVEL'] - 1));
        $headName = isset($headsMap[$department['UF_HEAD']]) ? $headsMap[$department['UF_HEAD']] : 'Не назначен';
        $total = (int)$departmentEmployees[$departmentId]['TOTAL'];
        $cabinetStats = $departmentEmployees[$departmentId]['CABINETS'];
        ksort($cabinetStats, SORT_NATURAL | SORT_FLAG_CASE);
        ?>
        <tr>
            <td class="department"><?= htmlspecialcharsbx($depthPrefix . $department['NAME']) ?></td>
            <td><?= htmlspecialcharsbx($headName) ?></td>
            <td>
                <strong><?= $total ?></strong>
                <?php if (!empty($cabinetStats)): ?>
                    <ul>
                        <?php foreach ($cabinetStats as $cabinetName => $count): ?>
                            <li><?= htmlspecialcharsbx($cabinetName) ?> — <?= (int)$count ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="muted">Сотрудники не найдены</div>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<h2>Сводка по кабинетам</h2>
<table>
    <thead>
    <tr>
        <th>Кабинет (справочник)</th>
        <th>Пользователей</th>
        <th>Мест по справочнику</th>
        <th>Разница (места - пользователи)</th>
    </tr>
    </thead>
    <tbody>
    <?php
    $summaryKeys = array_unique(array_merge(array_keys($cabinetUsageTotals), array_keys($cabinetDirectory)));
    sort($summaryKeys, SORT_NATURAL | SORT_FLAG_CASE);
    ?>
    <?php foreach ($summaryKeys as $summaryKey): ?>
        <?php
        $userCount = isset($cabinetUsageTotals[$summaryKey]) ? (int)$cabinetUsageTotals[$summaryKey]['count'] : 0;
        $workplaces = isset($cabinetDirectory[$summaryKey]) ? (int)$cabinetDirectory[$summaryKey]['workplaces'] : 0;
        $cabinetTitle = isset($cabinetDirectory[$summaryKey])
            ? $cabinetDirectory[$summaryKey]['name']
            : ('Не найден в справочнике: ' . (isset($cabinetUsageTotals[$summaryKey]) ? $cabinetUsageTotals[$summaryKey]['sample'] : $summaryKey));
        $delta = $workplaces - $userCount;
        ?>
        <tr>
            <td><?= htmlspecialcharsbx($cabinetTitle) ?></td>
            <td><?= $userCount ?></td>
            <td><?= $workplaces ?></td>
            <td><?= $delta ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</body>
</html>
