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
foreach ($departments as $departmentId => $_department) {
    $departmentEmployees[$departmentId] = [
        'TOTAL' => 0,
        'CABINETS' => [],
    ];
}

$headToDepartment = [];
foreach ($departments as $departmentId => $department) {
    if ($department['UF_HEAD'] > 0) {
        $headId = (int)$department['UF_HEAD'];
        if (!isset($headToDepartment[$headId])) {
            $headToDepartment[$headId] = [];
        }
        $headToDepartment[$headId][] = $departmentId;
    }
}

if (!empty($headToDepartment)) {
    $rsUsers = \CUser::GetList(
        $by = 'id',
        $order = 'asc',
        ['ACTIVE' => 'Y'],
        ['SELECT' => ['UF_HEAD', 'UF_CABINET'], 'FIELDS' => ['ID', 'LOGIN', 'UF_HEAD', 'UF_CABINET']]
    );

    while ($user = $rsUsers->Fetch()) {
        $rawUserHeads = $user['UF_HEAD'];
        $userHeads = [];

        if (is_array($rawUserHeads)) {
            foreach ($rawUserHeads as $rawValue) {
                if (preg_match_all('/\d+/', (string)$rawValue, $matches)) {
                    foreach ($matches[0] as $idValue) {
                        $idValue = (int)$idValue;
                        if ($idValue > 0) {
                            $userHeads[$idValue] = true;
                        }
                    }
                }
            }
        } else {
            if (preg_match_all('/\d+/', (string)$rawUserHeads, $matches)) {
                foreach ($matches[0] as $idValue) {
                    $idValue = (int)$idValue;
                    if ($idValue > 0) {
                        $userHeads[$idValue] = true;
                    }
                }
            }
        }

        if (empty($userHeads)) {
            continue;
        }

        $cabinet = trim((string)$user['UF_CABINET']);
        if ($cabinet === '') {
            $cabinet = 'Не указан';
        }

        foreach (array_keys($userHeads) as $headId) {
            if (!isset($headToDepartment[$headId])) {
                continue;
            }

            foreach ($headToDepartment[$headId] as $departmentId) {
                $departmentEmployees[$departmentId]['TOTAL']++;

                if (!isset($departmentEmployees[$departmentId]['CABINETS'][$cabinet])) {
                    $departmentEmployees[$departmentId]['CABINETS'][$cabinet] = 0;
                }
                $departmentEmployees[$departmentId]['CABINETS'][$cabinet]++;

                if ($diagnosticDepartmentId > 0 && $departmentId === $diagnosticDepartmentId) {
                    if (!isset($diagnostics[$departmentId])) {
                        $diagnostics[$departmentId] = [];
                    }
                    $diagnostics[$departmentId][] = [
                        'USER_ID' => (int)$user['ID'],
                        'LOGIN' => (string)$user['LOGIN'],
                        'RAW_UF_HEAD' => $rawUserHeads,
                        'PARSED_HEADS' => array_keys($userHeads),
                        'CABINET' => $cabinet,
                    ];
                }
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
        <div><strong>Найдено подчиненных:</strong> <?= count($diagRows) ?></div>

        <?php if (!empty($diagRows)): ?>
            <table style="margin-top:10px; margin-bottom:20px;">
                <thead>
                <tr>
                    <th>User ID</th>
                    <th>Login</th>
                    <th>RAW UF_HEAD</th>
                    <th>Parsed IDs</th>
                    <th>UF_CABINET</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($diagRows as $row): ?>
                    <tr>
                        <td><?= (int)$row['USER_ID'] ?></td>
                        <td><?= htmlspecialcharsbx($row['LOGIN']) ?></td>
                        <td><pre style="margin:0;white-space:pre-wrap;"><?= htmlspecialcharsbx(print_r($row['RAW_UF_HEAD'], true)) ?></pre></td>
                        <td><?= htmlspecialcharsbx(implode(', ', $row['PARSED_HEADS'])) ?></td>
                        <td><?= htmlspecialcharsbx($row['CABINET']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="muted" style="margin-bottom:20px;">Совпадений не найдено. Проверьте формат пользовательского поля UF_HEAD у сотрудников.</div>
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
</body>
</html>
