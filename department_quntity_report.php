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
foreach ($departments as $departmentId => $_department) {
    $departmentEmployees[$departmentId] = [
        'TOTAL' => 0,
        'CABINETS' => [],
    ];
}

$rsUsers = \CUser::GetList(
    $by = 'id',
    $order = 'asc',
    ['ACTIVE' => 'Y', '!UF_DEPARTMENT' => false],
    ['SELECT' => ['UF_DEPARTMENT', 'UF_CABINET'], 'FIELDS' => ['ID', 'UF_DEPARTMENT', 'UF_CABINET']]
);

while ($user = $rsUsers->Fetch()) {
    $userDepartments = $user['UF_DEPARTMENT'];
    if (!is_array($userDepartments)) {
        if ((string)$userDepartments === '' || (int)$userDepartments <= 0) {
            continue;
        }
        $userDepartments = [(int)$userDepartments];
    }

    $cabinet = trim((string)$user['UF_CABINET']);
    if ($cabinet === '') {
        $cabinet = 'Не указан';
    }

    foreach ($userDepartments as $departmentId) {
        $departmentId = (int)$departmentId;
        if (!isset($departmentEmployees[$departmentId])) {
            continue;
        }

        $departmentEmployees[$departmentId]['TOTAL']++;

        if (!isset($departmentEmployees[$departmentId]['CABINETS'][$cabinet])) {
            $departmentEmployees[$departmentId]['CABINETS'][$cabinet] = 0;
        }
        $departmentEmployees[$departmentId]['CABINETS'][$cabinet]++;
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
</body>
</html>
