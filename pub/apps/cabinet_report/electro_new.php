<?
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Отчет по сотрудникам и доступам");

global $USER;
CModule::IncludeModule("intranet");
CModule::IncludeModule('iblock');

// Настройки
$searchKeyword = "Моск";
$address = "Московский пр.";
$user_exclude = array(1, 2767, 2769, 2770, 2771, 2774, 2780, 3037, 3609, 3866, 3874, 3985, 3682);

// Ключевые слова для отбора (регистронезависимо)
$filterKeywords = array('моск', 'москва');

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

// Получаем всех сотрудников
$rsUsers = CUser::GetList(
    ($by = "UF_CABINET"),
    ($order = "ASC"),
    array('ACTIVE' => 'Y', '!ID' => $user_exclude),
    array(
        "SELECT" => array("UF_CABINET", "PERSONAL_PHOTO"),
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
        'ACCESSES' => array(),
        'WEEKEND_WORK' => in_array($userId, $weekendWorkers)
    );
}

// Получаем доступы
if (!empty($userIds)) {
    $arFilter = array("IBLOCK_ID" => 214, "PROPERTY_UZ_SOTRUDNIKA" => $userIds, "ACTIVE" => "Y");
    $arSelect = array("ID", "PROPERTY_UZ_SOTRUDNIKA", "PROPERTY_DOPUSK");
    $res = CIBlockElement::GetList(array(), $arFilter, false, false, $arSelect);

    $accessIds = array();
    $userAccessMap = array();

    while ($ob = $res->Fetch()) {
        $userId = $ob['PROPERTY_UZ_SOTRUDNIKA_VALUE'];
        $currentAccessIds = is_array($ob['PROPERTY_DOPUSK_VALUE']) ? $ob['PROPERTY_DOPUSK_VALUE'] : [$ob['PROPERTY_DOPUSK_VALUE']];
        foreach ($currentAccessIds as $accessId) {
            $userAccessMap[$userId][] = $accessId;
            $accessIds[] = $accessId;
        }
    }

    $accessIds = array_unique($accessIds);

    if (!empty($accessIds)) {
        $resAccess = CIBlockElement::GetList(array(), array("ID" => $accessIds, "IBLOCK_ID" => 215), false, false, array("ID", "NAME"));
        $accessNames = array();
        while ($obAccess = $resAccess->Fetch()) {
            $accessNames[$obAccess['ID']] = $obAccess['NAME'];
        }

        foreach ($userAccessMap as $userId => $accesses) {
            foreach ($accesses as $accessId) {
                if (isset($accessNames[$accessId])) {
                    $usersData[$userId]['ACCESSES'][] = $accessNames[$accessId];
                }
            }
            $usersData[$userId]['ACCESSES'] = array_unique($usersData[$userId]['ACCESSES']);
        }
    }
}

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
               mb_strpos(mb_strtolower($employee['CABINET'], 'UTF-8'), $searchTermLower) !== false ||
               mb_strpos(mb_strtolower(implode(", ", $employee['ACCESSES']), 'UTF-8'), $searchTermLower) !== false;
    });
}

usort($filteredEmployees, function($a, $b) use ($sortBy, $sortOrder) {
    if ($sortBy === 'accesses') {
        $aValue = implode(', ', $a['ACCESSES']);
        $bValue = implode(', ', $b['ACCESSES']);
    } elseif ($sortBy === 'weekend_work') {
        $aValue = $a[$sortBy] ? 1 : 0;
        $bValue = $b[$sortBy] ? 1 : 0;
    } else {
        $aValue = $a[$sortBy];
        $bValue = $b[$sortBy];
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
    table th:nth-child(3), table td:nth-child(3) { width: 10px; }        /* Иконка i */
    table th:nth-child(4), table td:nth-child(4) { width: 150px; }       /* Должность */
    table th:nth-child(5), table td:nth-child(5) { width: 500px; }       /* Доступы */
    table th:nth-child(6), table td:nth-child(6) { width: 100px; }       /* Выходные */

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
        <input type="text" name="search" placeholder="Поиск по ФИО, кабинету или доступу" value="<?= htmlspecialchars($searchTerm) ?>">
        <button type="submit">Искать</button>
    </form>
    <table>
        <thead>
            <tr>
                <th><a href="?sort=cabinet&order=<?= ($sortBy == 'cabinet' && $sortOrder == 'asc') ? 'desc' : 'asc' ?>">Кабинет</a></th>
                <th><a href="?sort=fio&order=<?= ($sortBy == 'fio' && $sortOrder == 'asc') ? 'desc' : 'asc' ?>">ФИО</a></th>
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
