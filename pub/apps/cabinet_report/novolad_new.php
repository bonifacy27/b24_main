<?
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Отчет по сотрудникам и доступам");

global $USER;
CModule::IncludeModule("intranet");
CModule::IncludeModule('iblock');

// Настройки
$searchKeyword = "Ново";
$address = "ул. Новоладожская";
$user_exclude = array(1, 2767, 2769, 2770, 2771, 2774, 2780, 3037, 3609, 3866, 3874, 3985, 3682);

// Ключевые слова для отбора (регистронезависимо)
$filterKeywords = array('ново', 'москва');

// Получаем список сотрудников, работающих в выходные
$weekendWorkers = array();
$resWeekend = CIBlockElement::GetList(
    array(),
    array("IBLOCK_ID" => 373, "ACTIVE" => "Y"),
    false,
    false,
    array("PROPERTY_SOTRUDNIK")
);
while ($arWeekend = $resWeekend->Fetch()) {
    if ($arWeekend['PROPERTY_SOTRUDNIK_VALUE']) {
        $weekendWorkers[] = $arWeekend['PROPERTY_SOTRUDNIK_VALUE'];
    }
}

// Получаем сотрудников из учетных записей. Активность учетной записи используется
// только для сотрудников, которых нет в списке доступов.
$rsUsers = CUser::GetList(
    ($by = "UF_CABINET"),
    ($order = "ASC"),
    array('ACTIVE' => 'Y', '!ID' => $user_exclude),
    array(
        "SELECT" => array("UF_CABINET", "UF_COMPANY", "PERSONAL_PHOTO"),
        "FIELDS" => array("ID", "LAST_NAME", "NAME", "SECOND_NAME", "WORK_POSITION", "UF_CABINET", "PERSONAL_PHOTO")
    )
);

$userIds = array();
$usersData = array();

while ($arUser = $rsUsers->Fetch()) {
    $userId = $arUser['ID'];
    $userIds[] = $userId;
    $usersData[$userId] = array(
        'ID' => $userId,
        'FIO' => trim($arUser['LAST_NAME'] . ' ' . $arUser['NAME'] . ' ' . $arUser['SECOND_NAME']),
        'POSITION' => $arUser['WORK_POSITION'],
        'CABINET' => $arUser['UF_CABINET'],
        'PHOTO' => $arUser['PERSONAL_PHOTO'],
        'COMPANY_ID' => $arUser['UF_COMPANY'],
        'LEGAL_ENTITY' => '',
        'ACCESSES' => array(),
        'WEEKEND_WORK' => in_array($userId, $weekendWorkers)
    );
}

// Получаем действующие записи списка доступов независимо от наличия и активности
// привязанной учетной записи.
$arFilter = array("IBLOCK_ID" => 214, "ACTIVE" => "Y", "PROPERTY_STATUS" => 7394);
$arSelect = array("ID", "PROPERTY_UZ_SOTRUDNIKA", "PROPERTY_FIO", "PROPERTY_DOPUSK", "PROPERTY_STATUS", "PROPERTY_3160", "PROPERTY_YURIDICHESKOE_LITSO");
$res = CIBlockElement::GetList(array(), $arFilter, false, false, $arSelect);

$accessRows = array();
$linkedUserIds = array();
$accessIds = array();
$legalEntityIds = array();
$today = strtotime(date('Y-m-d'));

while ($ob = $res->Fetch()) {
    $expiresAt = $ob['PROPERTY_3160_VALUE'] ? MakeTimeStamp($ob['PROPERTY_3160_VALUE']) : false;
    if ($expiresAt && $expiresAt < $today) {
        continue;
    }

    $userId = (int)$ob['PROPERTY_UZ_SOTRUDNIKA_VALUE'];
    $accessId = (int)$ob['PROPERTY_DOPUSK_VALUE'];
    $legalEntityId = (int)$ob['PROPERTY_YURIDICHESKOE_LITSO_VALUE'];
    $accessRows[] = array(
        'ID' => (int)$ob['ID'],
        'USER_ID' => $userId,
        'FIO' => trim($ob['PROPERTY_FIO_VALUE']),
        'ACCESS_ID' => $accessId,
        'LEGAL_ENTITY_ID' => $legalEntityId
    );
    if ($userId) $linkedUserIds[$userId] = $userId;
    if ($accessId) $accessIds[$accessId] = $accessId;
    if ($legalEntityId) $legalEntityIds[$legalEntityId] = $legalEntityId;
}

// Данные привязанных пользователей загружаются без фильтра ACTIVE.
if ($linkedUserIds) {
    $rsLinkedUsers = CUser::GetList(
        ($linkedBy = "ID"),
        ($linkedOrder = "ASC"),
        array('ID' => implode('|', $linkedUserIds)),
        array(
            "SELECT" => array("UF_CABINET", "UF_COMPANY", "PERSONAL_PHOTO"),
            "FIELDS" => array("ID", "LAST_NAME", "NAME", "SECOND_NAME", "WORK_POSITION", "UF_CABINET", "PERSONAL_PHOTO")
        )
    );
    while ($arUser = $rsLinkedUsers->Fetch()) {
        $userId = (int)$arUser['ID'];
        $usersData[$userId] = array(
            'ID' => $userId,
            'FIO' => trim($arUser['LAST_NAME'] . ' ' . $arUser['NAME'] . ' ' . $arUser['SECOND_NAME']),
            'POSITION' => $arUser['WORK_POSITION'],
            'CABINET' => $arUser['UF_CABINET'],
            'PHOTO' => $arUser['PERSONAL_PHOTO'],
            'COMPANY_ID' => $arUser['UF_COMPANY'],
            'LEGAL_ENTITY' => '',
            'ACCESSES' => array(),
            'WEEKEND_WORK' => in_array($userId, $weekendWorkers)
        );
    }
}

$accessNames = array();
if ($accessIds) {
    $resAccess = CIBlockElement::GetList(array(), array("ID" => $accessIds, "IBLOCK_ID" => 215), false, false, array("ID", "NAME"));
    while ($obAccess = $resAccess->Fetch()) {
        $accessNames[$obAccess['ID']] = $obAccess['NAME'];
    }
}

$legalEntityNames = array();
if ($legalEntityIds) {
    $resLegalEntities = CIBlockElement::GetList(array(), array("ID" => $legalEntityIds, "IBLOCK_ID" => 404), false, false, array("ID", "NAME"));
    while ($legalEntity = $resLegalEntities->Fetch()) {
        $legalEntityNames[$legalEntity['ID']] = $legalEntity['NAME'];
    }
}

$companyNames = array();
$companyEnum = new CUserFieldEnum;
$companyValues = $companyEnum->GetList(array(), array('USER_FIELD_NAME' => 'UF_COMPANY'));
while ($companyValue = $companyValues->Fetch()) {
    $companyNames[$companyValue['ID']] = $companyValue['VALUE'];
}

foreach ($accessRows as $row) {
    $employeeKey = $row['USER_ID'] && isset($usersData[$row['USER_ID']]) ? $row['USER_ID'] : 'access_' . $row['ID'];
    if (!isset($usersData[$employeeKey])) {
        $usersData[$employeeKey] = array(
            'ID' => 0,
            'FIO' => $row['FIO'],
            'POSITION' => '',
            'CABINET' => '',
            'PHOTO' => '',
            'COMPANY_ID' => '',
            'LEGAL_ENTITY' => '',
            'ACCESSES' => array(),
            'WEEKEND_WORK' => false
        );
    }
    if ($row['ACCESS_ID'] && isset($accessNames[$row['ACCESS_ID']])) {
        $usersData[$employeeKey]['ACCESSES'][] = $accessNames[$row['ACCESS_ID']];
    }
    if ($row['LEGAL_ENTITY_ID'] && isset($legalEntityNames[$row['LEGAL_ENTITY_ID']])) {
        $usersData[$employeeKey]['LEGAL_ENTITY'] = $legalEntityNames[$row['LEGAL_ENTITY_ID']];
    }
}

foreach ($usersData as &$user) {
    $user['ACCESSES'] = array_unique($user['ACCESSES']);
    if ($user['LEGAL_ENTITY'] === '' && $user['COMPANY_ID']) {
        $companyId = is_array($user['COMPANY_ID']) ? reset($user['COMPANY_ID']) : $user['COMPANY_ID'];
        $user['LEGAL_ENTITY'] = isset($companyNames[$companyId]) ? $companyNames[$companyId] : '';
    }
}
unset($user);

// Фильтрация по ключевым словам (кабинет или доступ содержит одно из слов)
$filteredEmployees = array();
foreach ($usersData as $userId => $user) {

    $cabinet = mb_strtolower((string)$user['CABINET'], 'UTF-8');
    $accessesText = mb_strtolower(implode(' ', (array)$user['ACCESSES']), 'UTF-8');

    $match = false;
    foreach ($filterKeywords as $kw) {
        $kw = trim(mb_strtolower((string)$kw, 'UTF-8'));
        if ($kw === '') continue;

        if (mb_strpos($cabinet, $kw, 0, 'UTF-8') !== false || mb_strpos($accessesText, $kw, 0, 'UTF-8') !== false) {
            $match = true;
            break;
        }
    }

    if ($match) {
        $filteredEmployees[] = $user;
    }
}

// Поиск и сортировка
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
$sortBy = isset($_GET['sort']) ? $_GET['sort'] : 'fio';
$sortOrder = isset($_GET['order']) ? $_GET['order'] : 'asc';

if ($searchTerm) {
    $searchTermLower = mb_strtolower($searchTerm, 'UTF-8');
    $filteredEmployees = array_filter($filteredEmployees, function($employee) use ($searchTermLower) {
        return mb_strpos(mb_strtolower($employee['FIO'], 'UTF-8'), $searchTermLower) !== false ||
               mb_strpos(mb_strtolower($employee['LEGAL_ENTITY'], 'UTF-8'), $searchTermLower) !== false ||
               mb_strpos(mb_strtolower($employee['CABINET'], 'UTF-8'), $searchTermLower) !== false ||
               mb_strpos(mb_strtolower(implode(", ", $employee['ACCESSES']), 'UTF-8'), $searchTermLower) !== false;
    });
}

usort($filteredEmployees, function($a, $b) use ($sortBy, $sortOrder) {
    if ($sortBy === 'accesses') {
        $aValue = implode(', ', $a['ACCESSES']);
        $bValue = implode(', ', $b['ACCESSES']);
    } elseif ($sortBy === 'weekend_work') {
        $aValue = $a['WEEKEND_WORK'] ? 1 : 0;
        $bValue = $b['WEEKEND_WORK'] ? 1 : 0;
    } else {
        $sortFields = array('cabinet' => 'CABINET', 'fio' => 'FIO', 'legal_entity' => 'LEGAL_ENTITY');
        $sortField = isset($sortFields[$sortBy]) ? $sortFields[$sortBy] : 'FIO';
        $aValue = $a[$sortField];
        $bValue = $b[$sortField];
    }
    $cmp = strnatcasecmp($aValue, $bValue);
    return ($sortOrder == 'asc') ? $cmp : -$cmp;
});
?>

<meta http-equiv="Content-type" content="text/html;charset=UTF-8" />
<style>
    body {
        font-family: Arial, sans-serif;
        margin: 0;
        padding: 0;
        background-color: #f9f9f9;
    }
    .report-container {
        max-width: 1200px;
        margin: 20px auto;
        padding: 20px;
        background-color: #fff;
        border-radius: 8px;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
    }
    h3 {
        color: #333;
        margin-top: 0;
        padding-bottom: 10px;
        border-bottom: 1px solid #ddd;
    }
    .search-form {
        margin-bottom: 20px;
    }
    .search-form input[type="text"] {
        padding: 8px 12px;
        width: 300px;
        border: 1px solid #ccc;
        border-radius: 4px;
        font-size: 14px;
    }
    .search-form button {
        padding: 8px 12px;
        border: 1px solid #007bff;
        background-color: #007bff;
        color: white;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
    }
    .search-form button:hover {
        background-color: #0056b3;
    }
    table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 20px;
    }
    table th, table td {
        padding: 10px;
        border: 1px solid #ddd;
        text-align: left;
    }
    table th {
        background-color: #f8f9fa;
        font-weight: bold;
    }
    table td {
        background-color: #fff;
    }
    table tr:hover td {
        background-color: #f1f1f1;
    }
    a {
        color: #007bff;
        text-decoration: none;
    }
    a:hover {
        text-decoration: underline;
    }
    .access-list {
        margin: 0;
        padding: 0;
        list-style-type: none;
    }
    .access-list li {
        padding: 4px 0;
    }

    /* Ширины колонок */
    table th:nth-child(1), table td:nth-child(1) { width: 80px; }         /* Кабинет */
    table th:nth-child(2), table td:nth-child(2) { width: 200px; }       /* ФИО */
    table th:nth-child(3), table td:nth-child(3) { width: 150px; }       /* ЮЛ */
    table th:nth-child(4), table td:nth-child(4) { width: 10px; }        /* Иконка i */
    table th:nth-child(5), table td:nth-child(5) { width: 150px; }       /* Должность */
    table th:nth-child(6), table td:nth-child(6) { width: 500px; }       /* Доступы */
    table th:nth-child(7), table td:nth-child(7) { width: 100px; }       /* Выходные */

    .info-icon {
        display: inline-block;
        width: 16px;
        height: 16px;
        background-color: #6c757d;
        color: white;
        border-radius: 50%;
        text-align: center;
        line-height: 16px;
        font-size: 10px;
        cursor: pointer;
        font-weight: bold;
    }

    .modal-card {
        display: none;
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        z-index: 1001;
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        width: 600px;
        overflow: hidden;
        animation: fadeIn 0.3s ease-in-out;
    }

    .modal-overlay {
        display: none;
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1000;
    }

    .card-header {
        background-color: #007bff;
        padding: 20px;
        color: white;
        text-align: center;
    }

    .card-body {
        padding: 20px;
    }

    .avatar {
        width: 240px;
        height: 240px;
        border-radius: 50%;
        object-fit: cover;
        margin: 0 auto 15px;
        display: block;
        border: 4px solid #dee2e6;
    }

    .gray-avatar {
        background-color: #e9ecef;
    }

    .card-title {
        font-size: 20px;
        font-weight: bold;
        margin-bottom: 10px;
        text-align: center;
    }

    .card-info {
        font-size: 14px;
        color: #555;
        margin-bottom: 8px;
    }

    .mini-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
    }

    .mini-table td {
        padding: 6px 8px;
        font-size: 13px;
        border: 1px solid #eee;
    }

    .mini-table tr td:nth-child(1) { width: 33%; }
    .mini-table tr td:nth-child(2) { width: 33%; }
    .mini-table tr td:nth-child(3) { width: 34%; }

    .weekend-badge {
        display: inline-block;
        padding: 4px 10px;
        background-color: #28a745;
        color: white;
        border-radius: 4px;
        font-size: 12px;
        font-weight: bold;
        margin-top: 5px;
    }

    .close-btn {
        position: absolute;
        top: 10px;
        right: 15px;
        font-size: 20px;
        color: white;
        cursor: pointer;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translate(-50%, -40%); }
        to { opacity: 1; transform: translate(-50%, -50%); }
    }
</style>

<script>
    function showModalCard(employee) {
        const modalOverlay = document.getElementById('modalOverlay');
        const card = document.getElementById('modalCard');

        // Заполняем данные
        document.getElementById('cardName').innerText = employee.fio;
        document.getElementById('cardPosition').innerText = employee.position;
        document.getElementById('cardCabinet').innerText = employee.cabinet;

        const accessTable = document.getElementById('cardAccesses');
        accessTable.innerHTML = '';
        if (employee.accesses.length > 0) {
            document.getElementById('accessHeader').style.display = 'block';
            let row = null;
            employee.accesses.forEach((access, index) => {
                if (index % 3 === 0) {
                    row = document.createElement('tr');
                    accessTable.appendChild(row);
                }
                const cell = document.createElement('td');
                cell.innerText = access;
                row.appendChild(cell);
            });
        } else {
            document.getElementById('accessHeader').style.display = 'none';
        }

        // Работа в выходные
        const weekendBadge = document.getElementById('weekendBadge');
        weekendBadge.style.display = employee.weekendWork ? 'inline-block' : 'none';

        // Устанавливаем фото
        const avatar = document.getElementById('cardAvatar');
        avatar.src = employee.photoUrl || '/local/templates/your_template/images/avatar_placeholder.png';
        avatar.classList.toggle('gray-avatar', !employee.photoUrl);

        modalOverlay.style.display = 'block';
        card.style.display = 'block';
    }

    function hideModalCard() {
        document.getElementById('modalOverlay').style.display = 'none';
        document.getElementById('modalCard').style.display = 'none';
    }
</script>

<div class="report-container">
    <h3>Отчет по сотрудникам и доступам (<?=$address?>) на <?= date('d.m.Y') ?></h3>
    <form method="GET" action="" class="search-form">
        <input type="text" name="search" placeholder="Поиск по ФИО, ЮЛ, кабинету или доступу" value="<?= htmlspecialchars($searchTerm) ?>">
        <button type="submit">Искать</button>
    </form>
    <table>
        <thead>
            <tr>
                <th><a href="?sort=cabinet&order=<?= ($sortBy == 'cabinet' && $sortOrder == 'asc') ? 'desc' : 'asc' ?>">Кабинет</a></th>
                <th><a href="?sort=fio&order=<?= ($sortBy == 'fio' && $sortOrder == 'asc') ? 'desc' : 'asc' ?>">ФИО</a></th>
                <th><a href="?sort=legal_entity&order=<?= ($sortBy == 'legal_entity' && $sortOrder == 'asc') ? 'desc' : 'asc' ?>">ЮЛ</a></th>
                <th></th> <!-- Новый столбец для иконки i -->
                <th>Должность</th>
                <th><a href="?sort=accesses&order=<?= ($sortBy == 'accesses' && $sortOrder == 'asc') ? 'desc' : 'asc' ?>">Доступы</a></th>
                <th><a href="?sort=weekend_work&order=<?= ($sortBy == 'weekend_work' && $sortOrder == 'asc') ? 'desc' : 'asc' ?>">Работа в выходные</a></th>
            </tr>
        </thead>
        <tbody>
            <? foreach ($filteredEmployees as $employee): ?>
                <?
                $photoUrl = $employee['PHOTO'] ? CFile::GetPath($employee['PHOTO']) : '';
                ?>
                <tr>
                    <td><?= $employee['CABINET'] ?></td>
                    <td><?= $employee['FIO'] ?></td>
                    <td><?= $employee['LEGAL_ENTITY'] ?></td>
                    <td style="text-align: center;">
                        <span class="info-icon" onclick="showModalCard({
                            fio: '<?= addslashes($employee['FIO']) ?>',
                            position: '<?= addslashes($employee['POSITION']) ?>',
                            cabinet: '<?= addslashes($employee['CABINET']) ?>',
                            accesses: [<? foreach ($employee['ACCESSES'] as $acc) echo "'".addslashes($acc)."',"; ?>],
                            weekendWork: <?= $employee['WEEKEND_WORK'] ? 'true' : 'false' ?>,
                            photoUrl: '<?= $photoUrl ?>'
                        })">i</span>
                    </td>
                    <td><?= $employee['POSITION'] ?></td>
                    <td>
                        <ul class="access-list">
                            <? foreach ($employee['ACCESSES'] as $access): ?>
                                <li><?= $access ?></li>
                            <? endforeach; ?>
                        </ul>
                    </td>
                    <td style="text-align:center"><?= $employee['WEEKEND_WORK'] ? '<span class="weekend-badge">Выходные</span>' : '' ?></td>
                </tr>
            <? endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Модальное окно -->
<div id="modalOverlay" class="modal-overlay" onclick="hideModalCard()"></div>
<div id="modalCard" class="modal-card">
    <div class="card-header">
        <span class="close-btn" onclick="hideModalCard()">&times;</span>
        <img id="cardAvatar" src="" class="avatar gray-avatar">
    </div>
    <div class="card-body">
        <div class="card-title" id="cardName"></div>
        <div class="card-info"><strong>Должность:</strong> <span id="cardPosition"></span></div>
        <div class="card-info"><strong>Кабинет:</strong> <span id="cardCabinet"></span></div>
        <div class="card-info">
            <span id="weekendBadge" class="weekend-badge">Работает в выходные</span>
        </div>
        <div id="accessHeader">
            <strong>Доступы:</strong>
            <table class="mini-table">
                <tbody id="cardAccesses"></tbody>
            </table>
        </div>
    </div>
</div>

<? require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); ?>
