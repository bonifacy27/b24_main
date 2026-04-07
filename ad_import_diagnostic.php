<?php
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
ini_set('display_errors', '0');

$errors = [];
$messages = [];
$results = [];

$defaults = [
    'ad_port' => '389',
    'ad_filter' => '(&(objectClass=user)(objectCategory=person))',
    'ad_fields' => 'distinguishedName,sAMAccountName,userPrincipalName,givenName,sn,displayName,mail,department,title,company,manager,userAccountControl,whenCreated,whenChanged',
];

foreach ($defaults as $key => $value) {
    if (!isset($_POST[$key])) {
        $_POST[$key] = $value;
    }
}

if (isset($_POST['load_basedn'])) {
    $conn = ldapConnect($errors);
    if ($conn) {
        $sr = @ldap_read($conn, '', 'objectClass=*', ['namingcontexts'], 0);
        if ($sr) {
            $entry = ldap_first_entry($conn, $sr);
            if ($entry) {
                $values = ldap_get_values_len($conn, $entry, 'namingcontexts');
                if (is_array($values)) {
                    unset($values['count']);
                    $_POST['dn_list'] = $values;
                }
            }
        }
        @ldap_unbind($conn);
    }
}

if (isset($_POST['run_diagnostic'])) {
    $conn = ldapConnect($errors);
    if ($conn) {
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_SIZELIMIT, 0);
        ldap_set_option($conn, LDAP_OPT_TIMELIMIT, 20);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 10);

        $baseDn = trim((string)$_POST['ad_basedn']);
        $filter = trim((string)$_POST['ad_filter']);
        $fieldsRaw = trim((string)$_POST['ad_fields']);

        if ($baseDn === '') {
            $errors[] = 'Не указан BaseDN.';
        }
        if ($filter === '') {
            $errors[] = 'Не указан LDAP фильтр.';
        }

        $fields = [];
        if ($fieldsRaw !== '') {
            foreach (explode(',', $fieldsRaw) as $field) {
                $field = trim($field);
                if ($field !== '') {
                    $fields[] = $field;
                }
            }
        }

        if (!$errors) {
            $sr = @ldap_search($conn, $baseDn, $filter, $fields);
            if (!$sr) {
                $errors[] = 'LDAP-запрос не выполнен. Проверьте BaseDN, фильтр и права LDAP-пользователя.';
            } else {
                $entries = ldap_get_entries($conn, $sr);
                if (!is_array($entries) || (int)$entries['count'] === 0) {
                    $errors[] = 'По текущему фильтру пользователи не найдены.';
                } else {
                    $messages[] = 'Найдено пользователей: ' . (int)$entries['count'];
                    $companyStructure = loadCompanyStructure($messages, $errors);
                    $results = buildDiagnosticRows($entries, $companyStructure);
                }
            }
        }

        @ldap_unbind($conn);
    }
}

function ldapConnect(array &$errors)
{
    $host = trim((string)$_POST['ad_host']);
    $port = trim((string)$_POST['ad_port']);
    $login = trim((string)$_POST['ad_login']);
    $password = (string)$_POST['ad_pass'];

    if ($host === '') {
        $errors[] = 'Не указан LDAP хост.';
        return false;
    }

    $portNum = (int)$port;
    if ($portNum <= 0) {
        $portNum = 389;
    }

    $conn = @ldap_connect($host, $portNum);
    if (!$conn) {
        $errors[] = 'Нет соединения с LDAP-сервером.';
        return false;
    }

    ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);

    $bind = @ldap_bind($conn, $login, $password);
    if (!$bind) {
        $errors[] = 'Ошибка авторизации на LDAP-сервере.';
        return false;
    }

    return $conn;
}

function loadCompanyStructure(array &$messages, array &$errors)
{
    $result = [
        'sectionsByName' => [],
        'sections' => [],
        'loaded' => false,
    ];

    $prolog = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') . '/bitrix/modules/main/include/prolog_before.php';
    if (!is_file($prolog)) {
        $messages[] = 'Битрикс-окружение не найдено, диагностика структуры будет только по LDAP данным.';
        return $result;
    }

    define('NO_KEEP_STATISTIC', true);
    define('NO_AGENT_STATISTIC', true);
    define('NO_AGENT_CHECK', true);
    define('NOT_CHECK_PERMISSIONS', true);

    require_once $prolog;

    if (!class_exists('\\Bitrix\\Main\\Loader')) {
        $messages[] = 'Не удалось подключить Bitrix\\Main\\Loader, пропущено сопоставление отделов.';
        return $result;
    }

    if (!\Bitrix\Main\Loader::includeModule('intranet') || !\Bitrix\Main\Loader::includeModule('iblock')) {
        $messages[] = 'Модули intranet/iblock недоступны, пропущено сопоставление отделов.';
        return $result;
    }

    $iblockId = (int)\COption::GetOptionInt('intranet', 'iblock_structure', 0);
    if ($iblockId <= 0) {
        $errors[] = 'Не найден ID инфоблока структуры компании (intranet:iblock_structure).';
        return $result;
    }

    $rs = \CIBlockSection::GetList(
        ['LEFT_MARGIN' => 'ASC'],
        ['IBLOCK_ID' => $iblockId, 'GLOBAL_ACTIVE' => 'Y'],
        false,
        ['ID', 'NAME', 'IBLOCK_SECTION_ID']
    );

    while ($section = $rs->Fetch()) {
        $id = (int)$section['ID'];
        $name = trim((string)$section['NAME']);
        $key = mb_strtolower($name);

        $result['sections'][$id] = [
            'id' => $id,
            'name' => $name,
            'parent_id' => (int)$section['IBLOCK_SECTION_ID'],
        ];
        if (!isset($result['sectionsByName'][$key])) {
            $result['sectionsByName'][$key] = [];
        }
        $result['sectionsByName'][$key][] = $id;
    }

    $result['loaded'] = !empty($result['sections']);
    if ($result['loaded']) {
        $messages[] = 'Загружена структура компании Битрикс24: отделов ' . count($result['sections']) . '.';
    }

    return $result;
}

function buildDiagnosticRows(array $entries, array $structure)
{
    $rows = [];
    $usersByDn = [];

    for ($i = 0; $i < (int)$entries['count']; $i++) {
        $e = $entries[$i];
        $dn = firstAttr($e, 'distinguishedname');
        $sam = firstAttr($e, 'samaccountname');
        $display = firstAttr($e, 'displayname');

        $usersByDn[mb_strtolower($dn)] = [
            'sam' => $sam,
            'display' => $display,
        ];
    }

    for ($i = 0; $i < (int)$entries['count']; $i++) {
        $e = $entries[$i];

        $departmentRaw = firstAttr($e, 'department');
        $managerDn = firstAttr($e, 'manager');
        $uac = (int)firstAttr($e, 'useraccountcontrol');

        $departmentCandidates = splitDepartmentCandidates($departmentRaw);
        $matchedDepartments = matchDepartments($departmentCandidates, $structure);

        $managerResolved = '';
        if ($managerDn !== '') {
            $key = mb_strtolower($managerDn);
            if (isset($usersByDn[$key])) {
                $managerResolved = trim($usersByDn[$key]['sam'] . ' ' . $usersByDn[$key]['display']);
            }
        }

        $problems = [];
        if (firstAttr($e, 'samaccountname') === '') {
            $problems[] = 'Пустой sAMAccountName (логин пользователя).';
        }
        if (firstAttr($e, 'sn') === '' || firstAttr($e, 'givenname') === '') {
            $problems[] = 'Не заполнены Фамилия/Имя (sn/givenName).';
        }
        if ($departmentRaw === '') {
            $problems[] = 'Пустой department — пользователь не попадет в структуру компании (UF_DEPARTMENT).';
        } elseif ($structure['loaded'] && empty($matchedDepartments)) {
            $problems[] = 'Department не сопоставлен с отделом Битрикс24.';
        }
        if (($uac & 2) === 2) {
            $problems[] = 'Учетная запись в AD отключена (userAccountControl содержит флаг DISABLED).';
        }
        if ($managerDn !== '' && $managerResolved === '') {
            $problems[] = 'manager указан, но руководитель не найден среди выбранной LDAP-выборки.';
        }

        $rows[] = [
            'dn' => firstAttr($e, 'distinguishedname'),
            'sam' => firstAttr($e, 'samaccountname'),
            'upn' => firstAttr($e, 'userprincipalname'),
            'fio' => trim(firstAttr($e, 'sn') . ' ' . firstAttr($e, 'givenname')),
            'display' => firstAttr($e, 'displayname'),
            'department' => $departmentRaw,
            'department_candidates' => $departmentCandidates,
            'matched_departments' => $matchedDepartments,
            'manager_dn' => $managerDn,
            'manager_resolved' => $managerResolved,
            'uac' => $uac,
            'problems' => $problems,
        ];
    }

    return $rows;
}

function splitDepartmentCandidates($departmentRaw)
{
    $departmentRaw = trim((string)$departmentRaw);
    if ($departmentRaw === '') {
        return [];
    }

    $parts = preg_split('/[\\\\\/>;|]/u', $departmentRaw);
    $candidates = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '') {
            $candidates[] = $part;
        }
    }

    if (!empty($candidates)) {
        $last = trim(end($candidates));
        if ($last !== '' && !in_array($last, $candidates, true)) {
            $candidates[] = $last;
        }
    }

    if (!in_array($departmentRaw, $candidates, true)) {
        $candidates[] = $departmentRaw;
    }

    return array_values(array_unique($candidates));
}

function matchDepartments(array $candidates, array $structure)
{
    if (empty($candidates) || empty($structure['sectionsByName'])) {
        return [];
    }

    $matched = [];
    foreach ($candidates as $candidate) {
        $key = mb_strtolower($candidate);
        if (isset($structure['sectionsByName'][$key])) {
            foreach ($structure['sectionsByName'][$key] as $sectionId) {
                $matched[] = [
                    'id' => $sectionId,
                    'name' => $structure['sections'][$sectionId]['name'],
                ];
            }
        }
    }

    $unique = [];
    foreach ($matched as $item) {
        $unique[$item['id']] = $item;
    }

    return array_values($unique);
}

function firstAttr(array $entry, $name)
{
    $key = mb_strtolower((string)$name);
    if (!isset($entry[$key])) {
        return '';
    }

    $value = $entry[$key];
    if (is_array($value)) {
        if (isset($value[0])) {
            return trim((string)$value[0]);
        }
        return '';
    }

    return trim((string)$value);
}

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Диагностика импорта пользователей AD -> Битрикс24</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 14px; margin: 20px; }
        table { border-collapse: collapse; width: 100%; }
        td, th { border: 1px solid #ccc; padding: 6px; vertical-align: top; }
        th { background: #f3f3f3; position: sticky; top: 0; }
        .ok { color: #197a2e; }
        .err { color: #a52323; }
        .msg { padding: 8px; margin: 10px 0; border: 1px solid #b9d7b9; background: #edf9ed; }
        .error { padding: 8px; margin: 10px 0; border: 1px solid #e0b7b7; background: #fff0f0; }
        input[type=text], input[type=password] { width: 100%; box-sizing: border-box; }
        .small { color: #666; font-size: 12px; }
    </style>
</head>
<body>
<h2>Оснастка диагностики импорта AD -> Битрикс24</h2>

<form method="post">
    <table>
        <tr>
            <td style="width: 180px;">LDAP хост</td>
            <td><input type="text" name="ad_host" value="<?=h($_POST['ad_host'])?>"></td>
            <td style="width: 120px;">Порт</td>
            <td style="width: 160px;"><input type="text" name="ad_port" value="<?=h($_POST['ad_port'])?>"></td>
        </tr>
        <tr>
            <td>Логин</td>
            <td colspan="3"><input type="text" name="ad_login" value="<?=h($_POST['ad_login'])?>"></td>
        </tr>
        <tr>
            <td>Пароль</td>
            <td colspan="3"><input type="password" name="ad_pass" value="<?=h($_POST['ad_pass'])?>"></td>
        </tr>
        <tr>
            <td>BaseDN</td>
            <td colspan="2"><input type="text" id="ad_basedn" name="ad_basedn" value="<?=h($_POST['ad_basedn'])?>"></td>
            <td>
                <button type="submit" name="load_basedn" value="1">Получить BaseDN</button>
            </td>
        </tr>
        <tr>
            <td>LDAP фильтр</td>
            <td colspan="3"><input type="text" name="ad_filter" value="<?=h($_POST['ad_filter'])?>"></td>
        </tr>
        <tr>
            <td>Поля (через запятую)</td>
            <td colspan="3"><input type="text" name="ad_fields" value="<?=h($_POST['ad_fields'])?>"></td>
        </tr>
    </table>

    <?php if (!isset($_POST['dn_list']) && !empty($_POST['temp_dn_list'])): ?>
        <?php $_POST['dn_list'] = @unserialize($_POST['temp_dn_list']); ?>
    <?php endif; ?>
    <input type="hidden" name="temp_dn_list" value="<?=h(serialize(isset($_POST['dn_list']) ? $_POST['dn_list'] : []))?>">

    <?php if (!empty($_POST['dn_list']) && is_array($_POST['dn_list'])): ?>
        <p>
            Быстрый выбор BaseDN:
            <select onchange="document.getElementById('ad_basedn').value=this.value">
                <option value=""></option>
                <?php foreach ($_POST['dn_list'] as $dn): ?>
                    <option value="<?=h($dn)?>"><?=h($dn)?></option>
                <?php endforeach; ?>
            </select>
        </p>
    <?php endif; ?>

    <p><button type="submit" name="run_diagnostic" value="1">Запустить диагностику</button></p>
    <p class="small">Логика диагностики: как минимум нужно проверить имя/фамилию, логин, department и manager. Для отображения в структуре критичен department (маппинг в UF_DEPARTMENT).</p>
</form>

<?php foreach ($messages as $message): ?>
    <div class="msg"><?=h($message)?></div>
<?php endforeach; ?>

<?php foreach ($errors as $error): ?>
    <div class="error"><?=h($error)?></div>
<?php endforeach; ?>

<?php if (!empty($results)): ?>
    <h3>Результаты диагностики</h3>
    <table>
        <tr>
            <th>#</th>
            <th>sAMAccountName</th>
            <th>ФИО/DisplayName</th>
            <th>department из AD</th>
            <th>Сопоставление с отделами Б24</th>
            <th>manager</th>
            <th>Риски / причина не попасть в структуру</th>
        </tr>
        <?php foreach ($results as $idx => $row): ?>
            <tr>
                <td><?=($idx + 1)?></td>
                <td>
                    <div><b><?=h($row['sam'])?></b></div>
                    <div class="small">UPN: <?=h($row['upn'])?></div>
                    <div class="small">DN: <?=h($row['dn'])?></div>
                </td>
                <td>
                    <div><?=h($row['fio'])?></div>
                    <div class="small"><?=h($row['display'])?></div>
                </td>
                <td>
                    <div><?=h($row['department'])?></div>
                    <?php if (!empty($row['department_candidates'])): ?>
                        <div class="small">Кандидаты: <?=h(implode(', ', $row['department_candidates']))?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($row['matched_departments'])): ?>
                        <?php foreach ($row['matched_departments'] as $item): ?>
                            <div class="ok">#<?=h($item['id'])?> <?=h($item['name'])?></div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span class="err">Не сопоставлен</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div><?=h($row['manager_dn'])?></div>
                    <div class="small">Резолв: <?=h($row['manager_resolved'])?></div>
                    <div class="small">UAC: <?=h($row['uac'])?></div>
                </td>
                <td>
                    <?php if (empty($row['problems'])): ?>
                        <span class="ok">Критичных проблем не выявлено</span>
                    <?php else: ?>
                        <?php foreach ($row['problems'] as $problem): ?>
                            <div class="err">• <?=h($problem)?></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>
</body>
</html>
