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

$reportRoles = [
    'AHS_ADMIN' => [4945, 3532, 5060],
    'HR_DIRECTOR' => [3696, 5209],
];

if (!Loader::includeModule('iblock') || !Loader::includeModule('main') || !Loader::includeModule('highloadblock')) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Ошибка: не удалось подключить обязательные модули.';
    exit;
}

global $USER;
$currentUserId = is_object($USER) ? (int)$USER->GetID() : 0;
$isAhsAdmin = in_array($currentUserId, $reportRoles['AHS_ADMIN'], true);
$isHrDirector = in_array($currentUserId, $reportRoles['HR_DIRECTOR'], true);
if (!$isAhsAdmin && !$isHrDirector) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Доступ запрещен.';
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

$defaultOfficeFilter = 'Московский пр., 139/1';
$cabinetFilterRaw = isset($_GET['cabinet_filter']) ? trim((string)$_GET['cabinet_filter']) : '';
$ceo1FilterRaw = isset($_GET['ceo1_filter']) ? trim((string)$_GET['ceo1_filter']) : '';
$departmentFilterRaw = isset($_GET['department_filter']) ? trim((string)$_GET['department_filter']) : '';
$officeFilterRaw = isset($_GET['office_filter']) ? trim((string)$_GET['office_filter']) : $defaultOfficeFilter;
$availableTabs = ['dashboard' => true, 'employees' => true, 'unknown' => true, 'temporary' => true, 'cabinet-summary' => true, 'legal-summary' => true];
$activeTab = isset($_GET['active_tab']) && isset($availableTabs[(string)$_GET['active_tab']]) ? (string)$_GET['active_tab'] : 'dashboard';

$undefinedLegalEntity = 'Не определено';
$normalizeLegalEntity = static function ($value): string {
    return trim((string)$value);
};

$normalizeListValue = static function ($value): string {
    if (is_array($value)) {
        $value = isset($value['VALUE']) ? $value['VALUE'] : reset($value);
    }
    return trim((string)$value);
};

$getEnumValueMap = static function (string $entityId, string $fieldName): array {
    $map = [];
    $field = \CUserTypeEntity::GetList([], ['ENTITY_ID' => $entityId, 'FIELD_NAME' => $fieldName])->Fetch();
    if (!$field || empty($field['ID'])) { return $map; }

    $enumRows = \CUserFieldEnum::GetList(['SORT' => 'ASC'], ['USER_FIELD_ID' => (int)$field['ID']]);
    while ($enum = $enumRows->Fetch()) {
        $map[(string)$enum['ID']] = trim((string)$enum['VALUE']);
    }
    return $map;
};

$resolveListDisplayValue = static function ($value, array $enumValueMap) use ($normalizeListValue): string {
    $normalizedValue = $normalizeListValue($value);
    return isset($enumValueMap[$normalizedValue]) ? $enumValueMap[$normalizedValue] : $normalizedValue;
};

$isTemporaryOrGuestPass = static function (string $name): bool {
    return preg_match('/(Временный|Гостевой|Vendex)/ui', $name) === 1;
};

$weekdayShortNames = [1 => 'пн', 2 => 'вт', 3 => 'ср', 4 => 'чт', 5 => 'пт', 6 => 'сб', 7 => 'вс'];
$formatReportDate = static function (string $dateKey, bool $withWeekday = false) use ($weekdayShortNames): string {
    $date = new \DateTime($dateKey);
    $formatted = $date->format('d.m.Y');
    if (!$withWeekday) { return $formatted; }
    return $formatted . ', ' . ($weekdayShortNames[(int)$date->format('N')] ?? '');
};
$getDateGroupLabel = static function (string $dateKey) use ($formatReportDate): string {
    return $formatReportDate($dateKey, true);
};

$companyLegalEntityMap = [
    '26' => 'НСК',
    'НСК' => 'НСК',
    '1723' => 'ТМХ',
    'УК ТМ' => 'ТМХ',
    '27' => 'ТТ',
    'ТТ' => 'ТТ',
    '28' => 'КЦ',
    'КЦ' => 'КЦ',
];

$resolveLegalEntityByUser = static function (array $user, array $companyLegalEntityMap, array $companyEnumValueMap) use ($resolveListDisplayValue, $normalizeLegalEntity): string {
    $company = $resolveListDisplayValue($user['UF_COMPANY'] ?? '', $companyEnumValueMap);
    if (isset($companyLegalEntityMap[$company])) { return $companyLegalEntityMap[$company]; }

    $email = mb_strtolower(trim((string)($user['EMAIL'] ?? '')));
    if ($email !== '') {
        if (substr($email, -12) === '@tricolor.ru') { return 'НСК'; }
        if (substr($email, -18) === '@tricolormedia.ru') { return 'ТМХ'; }
        if (substr($email, -16) === '@monobrand-tt.ru') { return 'ТТ'; }
        if (substr($email, -8) === '@n-l-e.ru') { return 'НЛЕ'; }
        if (substr($email, -11) === '@telemag.ru') { return 'СМ'; }
    }

    return $normalizeLegalEntity('');
};

$normalizeListValue = static function ($value): string {
    if (is_array($value)) {
        $value = isset($value['VALUE']) ? $value['VALUE'] : reset($value);
    }
    return trim((string)$value);
};

$getEnumValueMap = static function (string $entityId, string $fieldName): array {
    $map = [];
    $field = \CUserTypeEntity::GetList([], ['ENTITY_ID' => $entityId, 'FIELD_NAME' => $fieldName])->Fetch();
    if (!$field || empty($field['ID'])) { return $map; }

    $enumRows = \CUserFieldEnum::GetList(['SORT' => 'ASC'], ['USER_FIELD_ID' => (int)$field['ID']]);
    while ($enum = $enumRows->Fetch()) {
        $map[(string)$enum['ID']] = trim((string)$enum['VALUE']);
    }
    return $map;
};

$resolveListDisplayValue = static function ($value, array $enumValueMap) use ($normalizeListValue): string {
    $normalizedValue = $normalizeListValue($value);
    return isset($enumValueMap[$normalizedValue]) ? $enumValueMap[$normalizedValue] : $normalizedValue;
};

$isTemporaryOrGuestPass = static function (string $name): bool {
    return preg_match('/(Временный|Гостевой|Vendex)/ui', $name) === 1;
};

$companyLegalEntityMap = [
    'НСК' => 'НСК',
    'УК ТМ' => 'ТМХ',
    'ТМ' => 'ТМХ',
    'ТМХ' => 'ТМХ',
    'ТТ' => 'ТТ',
    'НЛЕ' => 'НЛЕ',
    'СМ' => 'СМ',
];

$resolveLegalEntityByUser = static function (array $user, array $companyLegalEntityMap, array $companyEnumValueMap, array $userOfficeEnumValueMap) use ($resolveListDisplayValue, $normalizeLegalEntity): string {
    $office = $resolveListDisplayValue($user['UF_OFFICE'] ?? '', $userOfficeEnumValueMap);
    if (isset($companyLegalEntityMap[$office])) { return $companyLegalEntityMap[$office]; }

    $company = $resolveListDisplayValue($user['UF_COMPANY'] ?? '', $companyEnumValueMap);
    if (isset($companyLegalEntityMap[$company])) { return $companyLegalEntityMap[$company]; }

    $email = mb_strtolower(trim((string)($user['EMAIL'] ?? '')));
    if ($email !== '') {
        if (substr($email, -12) === '@tricolor.ru') { return 'НСК'; }
        if (substr($email, -18) === '@tricolormedia.ru') { return 'ТМХ'; }
        if (substr($email, -16) === '@monobrand-tt.ru') { return 'ТТ'; }
        if (substr($email, -8) === '@n-l-e.ru') { return 'НЛЕ'; }
        if (substr($email, -11) === '@telemag.ru') { return 'СМ'; }
    }

    return $normalizeLegalEntity('');
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

$normalizeCabinetCode = static function (string $cabinetCode): string {
    $cabinetCode = trim(mb_strtolower($cabinetCode));
    $cabinetCode = str_replace([' ', ','], '', $cabinetCode);
    return str_replace(['а', 'б', 'в', 'г'], ['a', 'b', 'v', 'g'], $cabinetCode);
};

$resolveCabinetLocationKey = static function (string $value): string {
    if (mb_strpos($value, 'москов') !== false) { return 'moskovskiy'; }
    if (mb_strpos($value, 'новоладож') !== false) { return 'novoladozhskaya'; }
    if (mb_strpos($value, 'рентген') !== false) { return 'rentgena'; }
    return 'other';
};

$normalizeCabinet = static function (string $cabinetRaw) use ($normalizeCabinetCode, $resolveCabinetLocationKey): string {
    $value = trim(mb_strtolower($cabinetRaw));
    if ($value === '' || $value === 'не указан') { return ''; }

    if (preg_match('/каб\.?\s*([0-9]+[a-zа-я0-9\.-]*)/ui', $value, $match)) {
        return $resolveCabinetLocationKey($value) . '|' . $normalizeCabinetCode($match[1]);
    }

    if (preg_match('/(?:^|[,\s])([0-9]+[a-zа-я0-9\.-]*)(?:$|[,\s])/ui', $value, $match)) {
        return $resolveCabinetLocationKey($value) . '|' . $normalizeCabinetCode($match[1]);
    }

    return '';
};

$normalizeDirectoryCabinet = static function (string $cabinetName) use ($normalizeCabinet, $normalizeCabinetCode, $resolveCabinetLocationKey): string {
    $value = trim(mb_strtolower($cabinetName));
    if ($value === '') { return ''; }

    $normalized = $normalizeCabinet($value);
    if ($normalized !== '') { return $normalized; }

    $parts = array_map('trim', explode(',', $value));
    if (count($parts) < 2) { return ''; }

    $cabinetCode = (string)array_pop($parts);
    $location = implode(', ', $parts);
    return $resolveCabinetLocationKey($location) . '|' . $normalizeCabinetCode($cabinetCode);
};

$normalizeOrgNodeIds = static function ($rawValue): array {
    $values = is_array($rawValue) ? $rawValue : [$rawValue];
    $ids = [];
    foreach ($values as $value) {
        $id = (int)$value;
        if ($id > 0) { $ids[$id] = true; }
    }
    return array_keys($ids);
};

$cabinetFilterNorm = '';
if ($cabinetFilterRaw !== '') {
    $cabinetFilterNorm = $normalizeCabinet($cabinetFilterRaw);
    if ($cabinetFilterNorm === '') {
        $cabinetFilterNorm = $normalizeDirectoryCabinet($cabinetFilterRaw);
    }
}

$orgNodes = [];
$orgNodesHl = \Bitrix\Highloadblock\HighloadBlockTable::getById(99)->fetch();
if ($orgNodesHl) {
    $orgNodesEntity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($orgNodesHl);
    $orgNodesClass = $orgNodesEntity->getDataClass();
    $orgNodeRows = $orgNodesClass::getList(['select' => ['*']]);
    while ($row = $orgNodeRows->fetch()) {
        $id = (int)$row['ID'];
        if ($id <= 0) { continue; }

        $parentId = 0;
        foreach (['UF_PARENT_ID', 'UF_PARENT', 'UF_PARENT_NODE_ID', 'UF_PARENT_NODE'] as $parentField) {
            if (!isset($row[$parentField])) { continue; }
            $parentIds = $normalizeOrgNodeIds($row[$parentField]);
            if (!empty($parentIds)) {
                $parentId = (int)reset($parentIds);
                break;
            }
        }

        $orgNodes[$id] = [
            'LEVEL' => (int)$row['UF_LEVEL'],
            'NAME' => trim((string)$row['UF_NAME']),
            'PARENT_ID' => $parentId,
        ];
    }
}

$getOrgNodeChain = static function (int $nodeId) use ($orgNodes): array {
    $chain = [];
    $guard = 0;
    while ($nodeId > 0 && isset($orgNodes[$nodeId]) && $guard < 100) {
        $chain[] = $orgNodes[$nodeId];
        $nodeId = (int)$orgNodes[$nodeId]['PARENT_ID'];
        $guard++;
    }
    return array_reverse($chain);
};

$resolveUserOrgUnit = static function ($rawOrgNodeValue) use ($normalizeOrgNodeIds, $orgNodes, $getOrgNodeChain): array {
    $nodeIds = $normalizeOrgNodeIds($rawOrgNodeValue);
    $ceo1 = '';
    $department = '';
    $maxLevel = -1;

    foreach ($nodeIds as $nodeId) {
        if (!isset($orgNodes[$nodeId])) { continue; }

        $nodeChain = $getOrgNodeChain((int)$nodeId);
        if (empty($nodeChain)) { $nodeChain = [$orgNodes[$nodeId]]; }

        foreach ($nodeChain as $node) {
            $nodeName = (string)$node['NAME'];
            $nodeLevel = (int)$node['LEVEL'];
            if ($nodeName === '') { continue; }

            if ($nodeLevel === 1 && $ceo1 === '') {
                $ceo1 = $nodeName;
            }
            if ($nodeLevel >= $maxLevel) {
                $department = $nodeName;
                $maxLevel = $nodeLevel;
            }
        }
    }

    if ($department === '' && !empty($nodeIds)) {
        $lastNodeId = (int)end($nodeIds);
        if (isset($orgNodes[$lastNodeId])) {
            $department = (string)$orgNodes[$lastNodeId]['NAME'];
        }
    }
    if ($ceo1 === '' && $maxLevel === 1) {
        $ceo1 = $department;
    }

    return [
        'CEO1' => $ceo1,
        'DEPARTMENT' => $department,
    ];
};

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
$headOrgSummaryMap = [];
$headIds = [];
foreach ($departments as $department) {
    if ((int)$department['UF_HEAD'] > 0) { $headIds[(int)$department['UF_HEAD']] = true; }
}
if (!empty($headIds)) {
    $rsHeads = \CUser::GetList($by='id', $order='asc', ['ID' => implode('|', array_keys($headIds))], ['SELECT' => ['UF_1C_ORG_NODE'], 'FIELDS' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN']]);
    while ($head = $rsHeads->Fetch()) {
        $headId = (int)$head['ID'];
        $fio = trim($head['LAST_NAME'] . ' ' . $head['NAME'] . ' ' . $head['SECOND_NAME']);
        $headsMap[$headId] = $fio !== '' ? $fio : (string)$head['LOGIN'];
        $headOrgSummaryMap[$headId] = $resolveUserOrgUnit($head['UF_1C_ORG_NODE']);
    }
}

$departmentUsers = [];
$userDepartmentsMap = [];
$userCabinetMap = [];
$userLegalEntityMap = [];
$userLegalEntityByName = [];
$portalUserInfoById = [];
$cabinetAssignedTotal = [];
$departmentCabinetAssignedUsers = [];
$departmentCabinetLegalEntities = [];
$companyEnumValueMap = $getEnumValueMap('USER', 'UF_COMPANY');
$userOfficeEnumValueMap = $getEnumValueMap('USER', 'UF_OFFICE');

$rsUsers = \CUser::GetList($by='id', $order='asc', [], ['SELECT' => ['UF_DEPARTMENT', 'UF_CABINET', 'UF_COMPANY', 'UF_OFFICE'], 'FIELDS' => ['ID', 'ACTIVE', 'EMAIL', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN', 'UF_DEPARTMENT', 'UF_CABINET', 'UF_OFFICE']]);
while ($user = $rsUsers->Fetch()) {
    $userId = (int)$user['ID'];
    $userName = trim((string)$user['LAST_NAME'] . ' ' . (string)$user['NAME'] . ' ' . (string)$user['SECOND_NAME']);
    if ($userName === '') { $userName = (string)$user['LOGIN']; }
    $userLegalEntityMap[$userId] = $resolveLegalEntityByUser($user, $companyLegalEntityMap, $companyEnumValueMap, $userOfficeEnumValueMap);
    $portalUserInfoById[$userId] = [
        'ACTIVE' => (string)$user['ACTIVE'],
        'CABINET' => trim((string)$user['UF_CABINET']),
        'DEPARTMENTS_RAW' => is_array($user['UF_DEPARTMENT']) ? $user['UF_DEPARTMENT'] : [(int)$user['UF_DEPARTMENT']],
        'LEGAL_ENTITY' => $userLegalEntityMap[$userId],
    ];
    if ($userName !== '' && $userLegalEntityMap[$userId] !== '') {
        if (!isset($userLegalEntityByName[$userName])) {
            $userLegalEntityByName[$userName] = $userLegalEntityMap[$userId];
        } elseif ($userLegalEntityByName[$userName] !== $userLegalEntityMap[$userId]) {
            $userLegalEntityByName[$userName] = '';
        }
    }
    if ((string)$user['ACTIVE'] !== 'Y') { continue; }

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
    $portalUserInfoById[$userId]['HEAD_DEPARTMENTS'] = array_keys($headDepartments);
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
        foreach ($userDepartmentsMap[$userId] as $headDepId) {
            if (!isset($departmentCabinetAssignedUsers[$headDepId])) { $departmentCabinetAssignedUsers[$headDepId] = []; }
            if (!isset($departmentCabinetAssignedUsers[$headDepId][$cabNorm])) { $departmentCabinetAssignedUsers[$headDepId][$cabNorm] = []; }
            if (!isset($departmentCabinetLegalEntities[$headDepId])) { $departmentCabinetLegalEntities[$headDepId] = []; }
            if (!isset($departmentCabinetLegalEntities[$headDepId][$cabNorm])) { $departmentCabinetLegalEntities[$headDepId][$cabNorm] = []; }
            $departmentCabinetAssignedUsers[$headDepId][$cabNorm][$userId] = $userName;
            $assignedLegalEntity = trim((string)$userLegalEntityMap[$userId]);
            if ($assignedLegalEntity !== '') { $departmentCabinetLegalEntities[$headDepId][$cabNorm][$assignedLegalEntity] = true; }
        }
    }
}

$cabinetDirectory = [];
$cabinetDirectoryById = [];
$availableOfficesMap = [];
$officeEnumValueMap = $getEnumValueMap('HLBLOCK_74', 'UF_OFFICE');
$cabinetHl = \Bitrix\Highloadblock\HighloadBlockTable::getById(74)->fetch();
if ($cabinetHl) {
    $cabinetEntity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($cabinetHl);
    $cabinetClass = $cabinetEntity->getDataClass();
    $rsCabinets = $cabinetClass::getList(['select' => ['ID', 'UF_CABINET_ID', 'UF_NAME', 'UF_WORKPLACES', 'UF_OFFICE']]);
    while ($row = $rsCabinets->fetch()) {
        $title = trim((string)$row['UF_NAME']);
        if ($title === '') { continue; }
        $normalized = $normalizeDirectoryCabinet($title);
        $office = $resolveListDisplayValue(isset($row['UF_OFFICE']) ? $row['UF_OFFICE'] : '', $officeEnumValueMap);
        if ($office !== '') { $availableOfficesMap[$office] = true; }
        if ($officeFilterRaw !== '' && $office !== $officeFilterRaw) { continue; }
        $cabinetData = ['TITLE' => $title, 'WORKPLACES' => (int)$row['UF_WORKPLACES'], 'OFFICE' => $office];
        if ($normalized !== '') {
            $cabinetDirectory[$normalized] = $cabinetData;
        }
        $cabinetDirectoryById[(int)$row['ID']] = $cabinetData + ['NORM' => $normalized];
        $externalCabinetId = trim((string)($row['UF_CABINET_ID'] ?? ''));
        if ($externalCabinetId !== '' && ctype_digit($externalCabinetId)) {
            $cabinetDirectoryById[(int)$externalCabinetId] = $cabinetData + ['NORM' => $normalized];
        }
    }
}

$resolveReverseCabinet = static function ($cabinetValue) use (&$cabinetDirectoryById, $normalizeCabinet, $normalizeDirectoryCabinet): array {
    if (is_array($cabinetValue)) {
        $cabinetValue = isset($cabinetValue['VALUE']) ? $cabinetValue['VALUE'] : (isset($cabinetValue['ID']) ? $cabinetValue['ID'] : reset($cabinetValue));
    }

    $cabinetRaw = trim((string)$cabinetValue);
    if ($cabinetRaw === '') { return ['TITLE' => '', 'NORM' => '']; }

    if (ctype_digit($cabinetRaw) && isset($cabinetDirectoryById[(int)$cabinetRaw])) {
        return [
            'TITLE' => (string)$cabinetDirectoryById[(int)$cabinetRaw]['TITLE'],
            'NORM' => (string)$cabinetDirectoryById[(int)$cabinetRaw]['NORM'],
            'SOURCE' => 'Из привязки к кабинету',
        ];
    }

    $cabinetNorm = $normalizeCabinet($cabinetRaw);
    if ($cabinetNorm === '') { $cabinetNorm = $normalizeDirectoryCabinet($cabinetRaw); }

    return [
        'TITLE' => $cabinetNorm !== '' ? $cabinetRaw : '',
        'NORM' => $cabinetNorm,
        'SOURCE' => $cabinetNorm !== '' ? 'Другой источник' : '',
    ];
};

$periodDays = [];
foreach (new \DatePeriod($dateFrom, new \DateInterval('P1D'), (clone $dateTo)->modify('+1 day')) as $day) {
    $periodDays[] = $day->format('Y-m-d');
}

$prodCalendarSource = 'Srvr=srv-off-1c01;Ref=1c_Pay83_NSC;';
$prodCalendarByDate = [];
$workingPeriodDays = [];
if (!empty($periodDays)) {
    try {
        $connection = \Bitrix\Main\Application::getConnection('gatedb');
        $sqlHelper = $connection->getSqlHelper();
        $dateFromEscaped = $sqlHelper->forSql($dateFrom->format('Y-m-d'));
        $dateToEscaped = $sqlHelper->forSql($dateTo->format('Y-m-d'));
        $sourceEscaped = $sqlHelper->forSql($prodCalendarSource);
        $calendarSql = "
            SELECT CAST(Calend_Date AS date) AS CALEND_DATE, Calend_TimeType
            FROM GateDB.dbo.ProdCalendar_1CZUP
            WHERE CAST(Calend_Date AS date) BETWEEN '{$dateFromEscaped}' AND '{$dateToEscaped}'
                AND Source = '{$sourceEscaped}'
        ";
        $calendarRows = $connection->query($calendarSql);
        while ($calendarRow = $calendarRows->fetch()) {
            $calendarDate = $calendarRow['CALEND_DATE'];
            if ($calendarDate instanceof \DateTimeInterface) {
                $calendarDateKey = $calendarDate->format('Y-m-d');
            } else {
                $calendarDateKey = (new \DateTime((string)$calendarDate))->format('Y-m-d');
            }
            $timeType = trim((string)$calendarRow['Calend_TimeType']);
            $prodCalendarByDate[$calendarDateKey] = [
                'TYPE' => $timeType,
                'IS_WORKDAY' => in_array($timeType, ['Рабочий', 'Предпраздничный'], true),
            ];
        }
    } catch (\Throwable $e) {
        error_log('Ошибка загрузки производственного календаря 1C: ' . $e->getMessage());
    }
}
foreach ($periodDays as $dateKey) {
    if (!isset($prodCalendarByDate[$dateKey])) {
        $weekday = (int)(new \DateTime($dateKey))->format('N');
        $prodCalendarByDate[$dateKey] = [
            'TYPE' => $weekday >= 6 ? 'Выходной' : 'Рабочий',
            'IS_WORKDAY' => $weekday < 6,
        ];
    }
    if (!empty($prodCalendarByDate[$dateKey]['IS_WORKDAY'])) { $workingPeriodDays[] = $dateKey; }
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
        'select' => ['UF_EXT_ID', 'UF_LAST_NAME', 'UF_FIRST_NAME', 'UF_SECOND_NAME', 'UF_CABINET'],
        'filter' => ['=UF_SOURCE' => 'Reverse'],
    ]);
    while ($row = $reverseUsersRows->fetch()) {
        $passId = trim((string)$row['UF_EXT_ID']);
        if ($passId === '') { continue; }
        $reverseCabinet = $resolveReverseCabinet(isset($row['UF_CABINET']) ? $row['UF_CABINET'] : '');
        $reverseUsersByPass[$passId] = [
            'FIO' => trim((string)$row['UF_LAST_NAME'] . ' ' . (string)$row['UF_FIRST_NAME'] . ' ' . (string)$row['UF_SECOND_NAME']),
            'LEGAL_ENTITY' => '',
            'CABINET' => (string)$reverseCabinet['TITLE'],
            'CABINET_NORM' => (string)$reverseCabinet['NORM'],
            'CABINET_SOURCE' => (string)($reverseCabinet['SOURCE'] ?? ''),
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
$shortOfficePresenceKeys = [];
$unknownEmployees = [];
$temporaryGuestVisits = [];
foreach ($reverseEventsByDayAndPass as $dateKey => $passes) {
    foreach ($passes as $passId => $events) {
        $openEntry = null;
        $workedSeconds = 0;
        $portalUserId = 0;
        $inputEventsCount = 0;
        $outputEventsCount = 0;
        foreach ($events as $event) {
            if ((int)$event['USER_ID'] > 0) { $portalUserId = (int)$event['USER_ID']; }
            if ($event['EVENT'] === 'in') {
                $inputEventsCount++;
                if ($openEntry === null) { $openEntry = $event['TIME']; }
            } elseif ($event['EVENT'] === 'out') {
                $outputEventsCount++;
                if ($openEntry !== null) {
                    $workedSeconds += max(0, $event['TIME']->getTimestamp() - $openEntry->getTimestamp());
                    $openEntry = null;
                }
            }
        }
        if ($openEntry !== null) {
            $dayEnd = (new \DateTime($dateKey))->setTime(23, 59, 59);
            $workedSeconds += max(0, $dayEnd->getTimestamp() - $openEntry->getTimestamp());
        }
        $fourHoursSeconds = 4 * 60 * 60;
        $hasSingleInputWithoutOutput = $inputEventsCount === 1 && $outputEventsCount === 0;
        if ($workedSeconds <= 0 || (!$hasSingleInputWithoutOutput && $workedSeconds === $fourHoursSeconds)) { continue; }
        $isShortOfficePresence = $hasSingleInputWithoutOutput || $workedSeconds < $fourHoursSeconds;

        $reverseUser = isset($reverseUsersByPass[$passId]) ? $reverseUsersByPass[$passId] : ['FIO' => '', 'LEGAL_ENTITY' => '', 'CABINET' => '', 'CABINET_NORM' => '', 'CABINET_SOURCE' => ''];
        $reverseUserName = trim((string)$reverseUser['FIO']);
        if ($portalUserId > 0 && isset($userLegalEntityMap[$portalUserId]) && $userLegalEntityMap[$portalUserId] !== '') {
            $legalEntity = $userLegalEntityMap[$portalUserId];
        } elseif ($reverseUserName !== '' && isset($userLegalEntityByName[$reverseUserName]) && $userLegalEntityByName[$reverseUserName] !== '') {
            $legalEntity = $userLegalEntityByName[$reverseUserName];
        } else {
            $legalEntity = $normalizeLegalEntity($reverseUser['LEGAL_ENTITY']);
        }
        $employeeKey = $portalUserId > 0 ? 'U' . $portalUserId : 'P' . $passId;
        if ($isShortOfficePresence) {
            if (isset($shortOfficePresenceKeys[$dateKey][$employeeKey])) { continue; }
            $shortOfficePresenceKeys[$dateKey][$employeeKey] = true;
        } else {
            if (isset($officePresenceKeys[$dateKey][$employeeKey])) { continue; }
            $officePresenceKeys[$dateKey][$employeeKey] = true;
        }

        $userCabinetRaw = $portalUserId > 0 && isset($userCabinetMap[$portalUserId]) ? (string)$userCabinetMap[$portalUserId] : '';
        if ($userCabinetRaw === '' && $portalUserId > 0 && isset($portalUserInfoById[$portalUserId])) {
            $userCabinetRaw = (string)$portalUserInfoById[$portalUserId]['CABINET'];
        }
        $userCabinetNorm = $userCabinetRaw !== '' ? $normalizeCabinet($userCabinetRaw) : '';
        $userCabinetTitle = $userCabinetRaw !== '' ? $userCabinetRaw : '';
        $userCabinetSource = $userCabinetRaw !== '' ? 'Из поля UF_OFFICE (на основании данных AD)' : '';
        $userDepartmentIds = $portalUserId > 0 && isset($userDepartmentsMap[$portalUserId]) ? $userDepartmentsMap[$portalUserId] : [];
        $countedInCabinetDailyOffice = false;

        if ($userCabinetNorm !== '' && !empty($userDepartmentIds)) {
            if ($officeFilterRaw !== '' && !isset($cabinetDirectory[$userCabinetNorm])) { continue; }
            if (!isset($cabinetDailyOffice[$dateKey][$userCabinetNorm])) {
                $cabinetDailyOffice[$dateKey][$userCabinetNorm] = ['TOTAL' => 0, 'SHORT_TOTAL' => 0, 'BY_DEPARTMENT' => [], 'SHORT_BY_DEPARTMENT' => [], 'BY_LEGAL_ENTITY' => [], 'SHORT_BY_LEGAL_ENTITY' => []];
            }
            foreach (['SHORT_TOTAL', 'BY_DEPARTMENT', 'SHORT_BY_DEPARTMENT', 'BY_LEGAL_ENTITY', 'SHORT_BY_LEGAL_ENTITY'] as $officeKey) {
                if (!isset($cabinetDailyOffice[$dateKey][$userCabinetNorm][$officeKey])) {
                    $cabinetDailyOffice[$dateKey][$userCabinetNorm][$officeKey] = in_array($officeKey, ['SHORT_TOTAL'], true) ? 0 : [];
                }
            }

            $totalKey = $isShortOfficePresence ? 'SHORT_TOTAL' : 'TOTAL';
            $legalKey = $isShortOfficePresence ? 'SHORT_BY_LEGAL_ENTITY' : 'BY_LEGAL_ENTITY';
            $departmentKey = $isShortOfficePresence ? 'SHORT_BY_DEPARTMENT' : 'BY_DEPARTMENT';
            if (!isset($cabinetDailyOffice[$dateKey][$userCabinetNorm][$legalKey][$legalEntity])) {
                $cabinetDailyOffice[$dateKey][$userCabinetNorm][$legalKey][$legalEntity] = 0;
            }
            $cabinetDailyOffice[$dateKey][$userCabinetNorm][$totalKey]++;
            $cabinetDailyOffice[$dateKey][$userCabinetNorm][$legalKey][$legalEntity]++;
            foreach ($userDepartmentIds as $departmentId) {
                if (!isset($cabinetDailyOffice[$dateKey][$userCabinetNorm][$departmentKey][$departmentId])) {
                    $cabinetDailyOffice[$dateKey][$userCabinetNorm][$departmentKey][$departmentId] = [];
                }
                if (!isset($cabinetDailyOffice[$dateKey][$userCabinetNorm][$departmentKey][$departmentId][$legalEntity])) {
                    $cabinetDailyOffice[$dateKey][$userCabinetNorm][$departmentKey][$departmentId][$legalEntity] = 0;
                }
                $cabinetDailyOffice[$dateKey][$userCabinetNorm][$departmentKey][$departmentId][$legalEntity]++;
            }
            $countedInCabinetDailyOffice = true;
            continue;
        }

        $reverseCabinetNorm = isset($reverseUser['CABINET_NORM']) ? (string)$reverseUser['CABINET_NORM'] : '';
        $reverseCabinetTitle = isset($reverseUser['CABINET']) ? (string)$reverseUser['CABINET'] : '';
        if ($reverseCabinetNorm !== '') {
            if (!isset($cabinetDailyOffice[$dateKey][$reverseCabinetNorm])) {
                $cabinetDailyOffice[$dateKey][$reverseCabinetNorm] = ['TOTAL' => 0, 'SHORT_TOTAL' => 0, 'BY_DEPARTMENT' => [], 'SHORT_BY_DEPARTMENT' => [], 'BY_LEGAL_ENTITY' => [], 'SHORT_BY_LEGAL_ENTITY' => []];
            }
            foreach (['SHORT_TOTAL', 'BY_DEPARTMENT', 'SHORT_BY_DEPARTMENT', 'BY_LEGAL_ENTITY', 'SHORT_BY_LEGAL_ENTITY'] as $officeKey) {
                if (!isset($cabinetDailyOffice[$dateKey][$reverseCabinetNorm][$officeKey])) {
                    $cabinetDailyOffice[$dateKey][$reverseCabinetNorm][$officeKey] = in_array($officeKey, ['SHORT_TOTAL'], true) ? 0 : [];
                }
            }

            $totalKey = $isShortOfficePresence ? 'SHORT_TOTAL' : 'TOTAL';
            $legalKey = $isShortOfficePresence ? 'SHORT_BY_LEGAL_ENTITY' : 'BY_LEGAL_ENTITY';
            if (!isset($cabinetDailyOffice[$dateKey][$reverseCabinetNorm][$legalKey][$legalEntity])) {
                $cabinetDailyOffice[$dateKey][$reverseCabinetNorm][$legalKey][$legalEntity] = 0;
            }
            $cabinetDailyOffice[$dateKey][$reverseCabinetNorm][$totalKey]++;
            $cabinetDailyOffice[$dateKey][$reverseCabinetNorm][$legalKey][$legalEntity]++;
            $countedInCabinetDailyOffice = true;
        }

        $unknownCabinetNorm = $reverseCabinetNorm !== '' ? $reverseCabinetNorm : $userCabinetNorm;
        $unknownCabinetTitle = $reverseCabinetTitle !== '' ? $reverseCabinetTitle : $userCabinetTitle;
        $unknownCabinetSource = $reverseCabinetTitle !== '' ? (string)($reverseUser['CABINET_SOURCE'] ?? 'Другой источник') : $userCabinetSource;
        if ($unknownCabinetNorm !== '' && !$countedInCabinetDailyOffice && ($officeFilterRaw === '' || isset($cabinetDirectory[$unknownCabinetNorm]))) {
            if (!isset($cabinetDailyOffice[$dateKey][$unknownCabinetNorm])) {
                $cabinetDailyOffice[$dateKey][$unknownCabinetNorm] = ['TOTAL' => 0, 'SHORT_TOTAL' => 0, 'BY_DEPARTMENT' => [], 'SHORT_BY_DEPARTMENT' => [], 'BY_LEGAL_ENTITY' => [], 'SHORT_BY_LEGAL_ENTITY' => []];
            }
            foreach (['SHORT_TOTAL', 'BY_DEPARTMENT', 'SHORT_BY_DEPARTMENT', 'BY_LEGAL_ENTITY', 'SHORT_BY_LEGAL_ENTITY'] as $officeKey) {
                if (!isset($cabinetDailyOffice[$dateKey][$unknownCabinetNorm][$officeKey])) {
                    $cabinetDailyOffice[$dateKey][$unknownCabinetNorm][$officeKey] = in_array($officeKey, ['SHORT_TOTAL'], true) ? 0 : [];
                }
            }
            $totalKey = $isShortOfficePresence ? 'SHORT_TOTAL' : 'TOTAL';
            $legalKey = $isShortOfficePresence ? 'SHORT_BY_LEGAL_ENTITY' : 'BY_LEGAL_ENTITY';
            if (!isset($cabinetDailyOffice[$dateKey][$unknownCabinetNorm][$legalKey][$legalEntity])) {
                $cabinetDailyOffice[$dateKey][$unknownCabinetNorm][$legalKey][$legalEntity] = 0;
            }
            $cabinetDailyOffice[$dateKey][$unknownCabinetNorm][$totalKey]++;
            $cabinetDailyOffice[$dateKey][$unknownCabinetNorm][$legalKey][$legalEntity]++;
        }

        if ($cabinetFilterNorm !== '' && $unknownCabinetNorm !== $cabinetFilterNorm) { continue; }
        $employeeName = trim((string)$reverseUser['FIO']);
        if ($employeeName === '') { $employeeName = 'Пропуск ' . $passId; }
        if ($isTemporaryOrGuestPass($employeeName)) {
            $temporaryGuestVisits[] = [
                'NAME' => $employeeName,
                'DATE' => $dateKey,
            ];
            continue;
        }
        $unknownReason = 'Не определена причина исключения из основного списка.';
        if ($portalUserId <= 0 || !isset($portalUserInfoById[$portalUserId])) {
            $unknownReason = 'Не найдена учетная запись на портале для пропуска Reverse.';
        } elseif ((string)$portalUserInfoById[$portalUserId]['ACTIVE'] !== 'Y') {
            $unknownReason = 'Учетная запись на портале не активна.';
        } elseif (trim((string)$portalUserInfoById[$portalUserId]['CABINET']) === '') {
            $unknownReason = 'Не указан кабинет в поле UF_CABINET.';
        } elseif ($userCabinetNorm === '') {
            $unknownReason = 'Кабинет из поля UF_CABINET не удалось сопоставить со справочником кабинетов.';
        } elseif (empty($portalUserInfoById[$portalUserId]['DEPARTMENTS_RAW'])) {
            $unknownReason = 'Не указано подразделение в поле UF_DEPARTMENT.';
        } elseif (empty($portalUserInfoById[$portalUserId]['HEAD_DEPARTMENTS'])) {
            $unknownReason = 'Подразделение сотрудника не привязано к руководителю, выводимому в основном списке.';
        } elseif ($officeFilterRaw !== '' && $userCabinetNorm !== '' && !isset($cabinetDirectory[$userCabinetNorm])) {
            $unknownReason = 'Кабинет сотрудника не входит в выбранный фильтр офиса.';
        }
        $unknownEmployees[] = [
            'LEGAL_ENTITY' => $legalEntity,
            'EMPLOYEE' => $employeeName,
            'CABINET' => $unknownCabinetTitle,
            'CABINET_NORM' => $unknownCabinetNorm,
            'DATE' => $dateKey,
            'REASON' => $unknownReason,
            'CABINET_SOURCE' => $unknownCabinetSource !== '' ? $unknownCabinetSource : 'Другой источник',
        ];
    }
}

usort($temporaryGuestVisits, static function (array $left, array $right): int {
    $nameCompare = strnatcasecmp((string)$left['NAME'], (string)$right['NAME']);
    if ($nameCompare !== 0) { return $nameCompare; }

    return strcmp((string)$left['DATE'], (string)$right['DATE']);
});

usort($unknownEmployees, static function (array $left, array $right): int {
    $legalCompare = strnatcasecmp((string)$left['LEGAL_ENTITY'], (string)$right['LEGAL_ENTITY']);
    if ($legalCompare !== 0) { return $legalCompare; }

    $employeeCompare = strnatcasecmp((string)$left['EMPLOYEE'], (string)$right['EMPLOYEE']);
    if ($employeeCompare !== 0) { return $employeeCompare; }

    return strcmp((string)$left['DATE'], (string)$right['DATE']);
});

$allCabinets = [];
foreach ($cabinetDirectory as $cabKey => $cabData) { $allCabinets[$cabData['TITLE']] = true; }
foreach ($userCabinetMap as $cabName) {
    $cabNorm = $normalizeCabinet((string)$cabName);
    if ($officeFilterRaw !== '' && ($cabNorm === '' || !isset($cabinetDirectory[$cabNorm]))) { continue; }
    if ($cabNorm !== '' && isset($cabinetDirectory[$cabNorm])) { continue; }
    $allCabinets[(string)$cabName] = true;
}
$availableCabinets = array_keys($allCabinets);
sort($availableCabinets, SORT_NATURAL | SORT_FLAG_CASE);

$availableOffices = array_keys($availableOfficesMap);
sort($availableOffices, SORT_NATURAL | SORT_FLAG_CASE);

$availableCeo1 = [];
$availableDepartmentsByCeo1 = [];
foreach ($departments as $departmentId => $department) {
    $headUserId = (int)$department['UF_HEAD'];
    if ($headUserId <= 0) { continue; }
    $departmentSummary = isset($headOrgSummaryMap[$headUserId]) ? $headOrgSummaryMap[$headUserId] : ['CEO1' => '', 'DEPARTMENT' => ''];
    $ceo1 = trim((string)$departmentSummary['CEO1']);
    $departmentName = trim((string)$departmentSummary['DEPARTMENT']);
    if ($ceo1 !== '') {
        $availableCeo1[$ceo1] = true;
        if ($departmentName !== '') { $availableDepartmentsByCeo1[$ceo1][$departmentName] = true; }
    }
}
$availableDepartments = [];
if ($ceo1FilterRaw !== '' && isset($availableDepartmentsByCeo1[$ceo1FilterRaw])) {
    $availableDepartments = array_keys($availableDepartmentsByCeo1[$ceo1FilterRaw]);
}
sort($availableDepartments, SORT_NATURAL | SORT_FLAG_CASE);
if ($departmentFilterRaw !== '' && !in_array($departmentFilterRaw, $availableDepartments, true)) { $departmentFilterRaw = ''; }
$availableCeo1 = array_keys($availableCeo1);
sort($availableCeo1, SORT_NATURAL | SORT_FLAG_CASE);

$selectedHeadDepartmentIds = [];
foreach ($departments as $departmentId => $department) {
    if ((int)$department['UF_HEAD'] <= 0) { continue; }
    $headUserId = (int)$department['UF_HEAD'];
    $departmentSummary = isset($headOrgSummaryMap[$headUserId]) ? $headOrgSummaryMap[$headUserId] : ['CEO1' => '', 'DEPARTMENT' => ''];
    if ($ceo1FilterRaw !== '' && (string)$departmentSummary['CEO1'] !== $ceo1FilterRaw) { continue; }
    if ($departmentFilterRaw !== '' && (string)$departmentSummary['DEPARTMENT'] !== $departmentFilterRaw) { continue; }
    $selectedHeadDepartmentIds[$departmentId] = true;
}
$hasOrgUnitFilter = $ceo1FilterRaw !== '' || $departmentFilterRaw !== '';
$summaryCabinets = [];
foreach ($cabinetDirectory as $cabNorm => $cabData) {
    if ($hasOrgUnitFilter) { continue; }
    if ($cabinetFilterNorm !== '' && $cabNorm !== $cabinetFilterNorm) { continue; }
    $summaryCabinets[$cabNorm] = [
        'TITLE' => (string)$cabData['TITLE'],
        'WORKPLACES' => (int)$cabData['WORKPLACES'],
    ];
}
foreach ($userCabinetMap as $userId => $cabName) {
    if ($hasOrgUnitFilter && empty(array_intersect($userDepartmentsMap[(int)$userId] ?? [], array_keys($selectedHeadDepartmentIds)))) { continue; }
    $cabNorm = $normalizeCabinet((string)$cabName);
    if ($cabNorm === '' || ($cabinetFilterNorm !== '' && $cabNorm !== $cabinetFilterNorm) || ($officeFilterRaw !== '' && !isset($cabinetDirectory[$cabNorm]))) { continue; }
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

$officeWorkplacesTotal = 0;
foreach ($summaryCabinets as $cabData) {
    $officeWorkplacesTotal += (int)$cabData['WORKPLACES'];
}

$legalEntityAssignedWorkplaces = [];
foreach ($userCabinetMap as $userId => $cabName) {
    if ($hasOrgUnitFilter && empty(array_intersect($userDepartmentsMap[(int)$userId] ?? [], array_keys($selectedHeadDepartmentIds)))) { continue; }
    $cabNorm = $normalizeCabinet((string)$cabName);
    if ($cabNorm === '' || !isset($summaryCabinets[$cabNorm])) { continue; }

    $legalEntityTitle = isset($userLegalEntityMap[(int)$userId]) && $userLegalEntityMap[(int)$userId] !== '' ? $userLegalEntityMap[(int)$userId] : $undefinedLegalEntity;
    if (!isset($legalEntityAssignedWorkplaces[$legalEntityTitle])) {
        $legalEntityAssignedWorkplaces[$legalEntityTitle] = 0;
    }
    $legalEntityAssignedWorkplaces[$legalEntityTitle]++;
}
ksort($legalEntityAssignedWorkplaces, SORT_NATURAL | SORT_FLAG_CASE);

$otherVisitorsLegalSummary = [];
$otherVisitorsAssignedWorkplaces = [];
foreach ($unknownEmployees as $employee) {
    if ($hasOrgUnitFilter) { continue; }
    $legalEntityTitle = trim((string)($employee['LEGAL_ENTITY'] ?? ''));
    $cabNorm = (string)($employee['CABINET_NORM'] ?? '');
    $dateKey = (string)($employee['DATE'] ?? '');
    if ($legalEntityTitle === '' || $legalEntityTitle === $undefinedLegalEntity || $cabNorm === '' || $dateKey === '' || !isset($summaryCabinets[$cabNorm])) { continue; }

    if (!isset($otherVisitorsLegalSummary[$dateKey])) { $otherVisitorsLegalSummary[$dateKey] = []; }
    if (!isset($otherVisitorsLegalSummary[$dateKey][$cabNorm])) { $otherVisitorsLegalSummary[$dateKey][$cabNorm] = []; }
    if (!isset($otherVisitorsLegalSummary[$dateKey][$cabNorm][$legalEntityTitle])) {
        $otherVisitorsLegalSummary[$dateKey][$cabNorm][$legalEntityTitle] = 0;
    }
    $otherVisitorsLegalSummary[$dateKey][$cabNorm][$legalEntityTitle]++;

    if (!isset($otherVisitorsAssignedWorkplaces[$dateKey])) { $otherVisitorsAssignedWorkplaces[$dateKey] = []; }
    if (!isset($otherVisitorsAssignedWorkplaces[$dateKey][$legalEntityTitle])) { $otherVisitorsAssignedWorkplaces[$dateKey][$legalEntityTitle] = []; }
    $otherVisitorAssignedKey = mb_strtolower(trim((string)($employee['EMPLOYEE'] ?? ''))) . '|' . $cabNorm;
    if ($otherVisitorAssignedKey !== '|') {
        $otherVisitorsAssignedWorkplaces[$dateKey][$legalEntityTitle][$otherVisitorAssignedKey] = true;
    }
}

$legalEntitySummary = [];
foreach ($periodDays as $dateKey) {
    if (!isset($legalEntitySummary[$dateKey])) { $legalEntitySummary[$dateKey] = []; }
    foreach ($summaryCabinets as $cabNorm => $cabData) {
        $dayData = isset($cabinetDailyOffice[$dateKey][$cabNorm]) ? $cabinetDailyOffice[$dateKey][$cabNorm] : [];
        $legalCounts = [];
        if ($hasOrgUnitFilter) {
            $departmentCounts = isset($dayData['BY_DEPARTMENT']) && is_array($dayData['BY_DEPARTMENT']) ? $dayData['BY_DEPARTMENT'] : [];
            foreach (array_keys($selectedHeadDepartmentIds) as $selectedDepartmentId) {
                if (empty($departmentCounts[$selectedDepartmentId]) || !is_array($departmentCounts[$selectedDepartmentId])) { continue; }
                foreach ($departmentCounts[$selectedDepartmentId] as $legalEntity => $count) {
                    if (!isset($legalCounts[$legalEntity])) { $legalCounts[$legalEntity] = 0; }
                    $legalCounts[$legalEntity] += (int)$count;
                }
            }
        } else {
            $legalCounts = isset($dayData['BY_LEGAL_ENTITY']) && is_array($dayData['BY_LEGAL_ENTITY']) ? $dayData['BY_LEGAL_ENTITY'] : [];
        }
        foreach ($legalCounts as $legalEntity => $count) {
            $legalEntityTitle = (string)($legalEntity !== '' ? $legalEntity : $undefinedLegalEntity);
            if (!isset($legalEntitySummary[$dateKey][$legalEntityTitle])) {
                $legalEntitySummary[$dateKey][$legalEntityTitle] = 0;
            }
            $legalEntitySummary[$dateKey][$legalEntityTitle] += (int)$count;
        }
        $otherVisitorsLegalCounts = isset($otherVisitorsLegalSummary[$dateKey][$cabNorm]) && is_array($otherVisitorsLegalSummary[$dateKey][$cabNorm]) ? $otherVisitorsLegalSummary[$dateKey][$cabNorm] : [];
        foreach ($otherVisitorsLegalCounts as $legalEntityTitle => $count) {
            if (isset($legalCounts[$legalEntityTitle])) { continue; }
            if (!isset($legalEntitySummary[$dateKey][$legalEntityTitle])) {
                $legalEntitySummary[$dateKey][$legalEntityTitle] = 0;
            }
            $legalEntitySummary[$dateKey][$legalEntityTitle] += (int)$count;
        }
    }
    foreach ($legalEntityAssignedWorkplaces as $legalEntityTitle => $assignedCount) {
        if (!isset($legalEntitySummary[$dateKey][$legalEntityTitle])) {
            $legalEntitySummary[$dateKey][$legalEntityTitle] = 0;
        }
    }
    ksort($legalEntitySummary[$dateKey], SORT_NATURAL | SORT_FLAG_CASE);
}

$dashboardOfficeByDate = [];
$dashboardTotalWorkplaces = $officeWorkplacesTotal * max(1, count($workingPeriodDays));
$dashboardTotalOccupied = 0;
$dashboardPeakOfficeLoad = 0;
$dashboardPeakOfficeDate = '';
foreach ($periodDays as $dateKey) {
    $isDashboardWorkday = !empty($prodCalendarByDate[$dateKey]['IS_WORKDAY']);
    $dayOccupied = 0;
    $dayShortOccupied = 0;
    foreach ($summaryCabinets as $cabNorm => $cabData) {
        $workplaces = (int)$cabData['WORKPLACES'];
        $dayData = isset($cabinetDailyOffice[$dateKey][$cabNorm]) ? $cabinetDailyOffice[$dateKey][$cabNorm] : ['TOTAL' => 0, 'SHORT_TOTAL' => 0];
        $occupied = isset($dayData['TOTAL']) ? (int)$dayData['TOTAL'] : 0;
        $shortOccupied = isset($dayData['SHORT_TOTAL']) ? (int)$dayData['SHORT_TOTAL'] : 0;
        $dayOccupied += $occupied;
        $dayShortOccupied += $shortOccupied;
    }
    $dayUtilization = ($isDashboardWorkday && $officeWorkplacesTotal > 0) ? round(($dayOccupied / $officeWorkplacesTotal) * 100, 1) : 0;
    $dashboardOfficeByDate[$dateKey] = [
        'OCCUPIED' => $dayOccupied,
        'SHORT_OCCUPIED' => $dayShortOccupied,
        'WORKPLACES' => $officeWorkplacesTotal,
        'UTILIZATION' => $dayUtilization,
        'DAY_TYPE' => (string)($prodCalendarByDate[$dateKey]['TYPE'] ?? ''),
        'IS_WORKDAY' => $isDashboardWorkday,
        'IS_PREHOLIDAY' => (string)($prodCalendarByDate[$dateKey]['TYPE'] ?? '') === 'Предпраздничный',
    ];
    if ($isDashboardWorkday) { $dashboardTotalOccupied += $dayOccupied; }
    if ($isDashboardWorkday && $dayUtilization > $dashboardPeakOfficeLoad) {
        $dashboardPeakOfficeLoad = $dayUtilization;
        $dashboardPeakOfficeDate = $dateKey;
    }
}
$dashboardAverageOfficeLoad = $dashboardTotalWorkplaces > 0 ? round(($dashboardTotalOccupied / $dashboardTotalWorkplaces) * 100, 1) : 0;
$dashboardIsCabinetScope = $cabinetFilterNorm !== '';
$dashboardScopeGenitive = $dashboardIsCabinetScope ? 'кабинета' : 'офиса';
$dashboardScopePrepositional = $dashboardIsCabinetScope ? 'кабинету' : 'всему офису';
$dashboardScopeTitle = $dashboardIsCabinetScope ? (isset($summaryCabinets[$cabinetFilterNorm]) ? (string)$summaryCabinets[$cabinetFilterNorm]['TITLE'] : $cabinetFilterRaw) : 'весь офис';
$dashboardSelectionCardTitle = $dashboardIsCabinetScope ? 'Кабинет в выборке' : 'Кабинетов в выборке';
$dashboardSelectionCardValue = $dashboardIsCabinetScope ? 1 : count($summaryCabinets);
$dashboardCabinetTitles = array_map(static function (array $cabData): string { return (string)$cabData['TITLE']; }, $summaryCabinets);
sort($dashboardCabinetTitles, SORT_NATURAL | SORT_FLAG_CASE);
$dashboardSelectionCardNote = $dashboardIsCabinetScope
    ? ('Кабинет: ' . $dashboardScopeTitle)
    : ($ceo1FilterRaw !== ''
        ? (!empty($dashboardCabinetTitles) ? implode(', ', $dashboardCabinetTitles) : 'Кабинеты не найдены')
        : ('Рабочих мест: ' . (int)$officeWorkplacesTotal));
$legalEntitySummaryScopeTitle = $cabinetFilterRaw !== '' ? $cabinetFilterRaw : 'офисе';
?>


header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Расширенный отчет по рабочим местам Reverse</title>
    <style>
        body { font-family: Arial,sans-serif; font-size:13px; margin:16px; }
        table { border-collapse: collapse; width:auto; max-width: none; }
        th, td { border:1px solid #d8e0ea; padding:6px 8px; vertical-align: top; }
        th { background:#f5f9ff; white-space: normal; word-break: break-word; line-height: 1.2; }
        #employees-report-table thead th { position: sticky; top: 0; z-index: 2; }
        .col-narrow { width: 70px; max-width: 70px; }
        .filters { margin: 10px 0 16px; }
        .filters-row { margin-top: 8px; }
        .filters-row:first-child { margin-top: 0; }
        .tabs { display: flex; flex-wrap: wrap; gap: 6px; margin: 14px 0 12px; border-bottom: 1px solid #d8e0ea; }
        .tab-button { border: 1px solid #d8e0ea; border-bottom: 0; background: #f5f9ff; padding: 8px 12px; cursor: pointer; border-radius: 6px 6px 0 0; font: inherit; }
        .tab-button.is-active { background: #fff; font-weight: 700; position: relative; top: 1px; }
        .tab-pane { display: none; }
        .tab-pane.is-active { display: block; }
        .report-toolbar { margin: 0 0 10px; }
        .export-button { border: 1px solid #8bb6e8; background: #eaf4ff; color: #0f4f93; padding: 6px 10px; border-radius: 4px; cursor: pointer; font: inherit; }
        .management-link { display: inline-block; margin: 0 0 10px; border: 1px solid #8bb6e8; background: #eaf4ff; color: #0f4f93; padding: 7px 12px; border-radius: 4px; text-decoration: none; }
        .head-modal-trigger { padding: 0; border: 0; background: none; color: #1d5fbf; cursor: pointer; text-decoration: underline; font: inherit; text-align: left; }
        .modal-backdrop { display: none; position: fixed; inset: 0; z-index: 1000; background: rgba(0,0,0,.35); align-items: center; justify-content: center; padding: 24px; }
        .modal-backdrop.is-open { display: flex; }
        .modal-window { width: min(560px, 100%); max-height: 80vh; overflow: auto; background: #fff; border-radius: 6px; box-shadow: 0 12px 32px rgba(0,0,0,.28); padding: 18px 20px; }
        .modal-header { display: flex; gap: 12px; align-items: flex-start; justify-content: space-between; margin-bottom: 12px; }
        .modal-title { margin: 0; font-size: 18px; }
        .modal-close { border: 0; background: none; font-size: 24px; line-height: 1; cursor: pointer; }
        .modal-subtitle { color: #52616f; margin: 0 0 12px; }
        .modal-empty { color: #7a8794; }
        .modal-table { border-collapse: collapse; width: 100%; }
        .modal-table th, .modal-table td { border: 1px solid #d8e0ea; padding: 6px 8px; }
        .modal-status-in { color: #166534; font-weight: 600; }
        .modal-status-out { color: #991b1b; }
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin: 14px 0 18px; }
        .dashboard-card { border: 1px solid #d8e0ea; border-radius: 14px; padding: 16px; background: linear-gradient(135deg, #ffffff 0%, #f7fbff 100%); box-shadow: 0 8px 24px rgba(15, 79, 147, .08); }
        .dashboard-card-title { margin: 0 0 8px; color: #52616f; font-size: 12px; text-transform: uppercase; letter-spacing: .06em; }
        .dashboard-card-value { font-size: 30px; line-height: 1; font-weight: 800; color: #0f4f93; }
        .dashboard-card-note { margin-top: 8px; color: #6b7a88; }
        .dashboard-section { margin: 18px 0 24px; }
        .dashboard-section h3 { margin: 0 0 12px; font-size: 18px; }
        .office-load-chart { display: grid; gap: 10px; max-width: 980px; }
        .office-load-row { display: grid; grid-template-columns: 120px 1fr 76px; gap: 10px; align-items: center; }
        .office-load-bar { height: 28px; border-radius: 999px; background: #edf4fb; overflow: hidden; box-shadow: inset 0 0 0 1px #d8e0ea; }
        .office-load-row.is-non-workday .office-load-fill { background: #cbd5e1; }
        .office-load-date { font-weight: 700; }
        .office-load-row.is-non-workday .office-load-date { color: #dc2626; }
        .office-load-row.is-preholiday .office-load-date { color: #d97706; }
        .office-load-fill { height: 100%; min-width: 3px; border-radius: inherit; background: linear-gradient(90deg, #38bdf8 0%, #2563eb 55%, #7c3aed 100%); }
        .dashboard-muted { color: #7a8794; }
        .date-group-row { background: #eef6ff; font-weight: 700; cursor: pointer; }
        .date-group-toggle { border: 1px solid #8bb6e8; background: #fff; color: #0f4f93; border-radius: 999px; padding: 4px 10px; cursor: pointer; font: inherit; }
        .date-group-row.is-collapsed .date-group-toggle::after { content: " раскрыть"; }
        .date-group-row:not(.is-collapsed) .date-group-toggle::after { content: " свернуть"; }
        .date-detail-row.is-hidden { display: none; }
    </style>
</head>
<body>
<h1>Расширенный отчет по рабочим местам Reverse</h1>
<?php if ($isAhsAdmin): ?>
    <a class="management-link" href="/pub/apps/attendance_report/cabinet_edit.php">Управление РМ</a>
<?php endif; ?>
<form method="get" class="filters">
    <input type="hidden" name="active_tab" id="active-tab-input" value="<?=htmlspecialcharsbx($activeTab)?>">
    <div class="filters-row">
        <label>С даты: <input type="date" name="date_from" value="<?=htmlspecialcharsbx($dateFrom->format('Y-m-d'))?>"></label>
        <label style="margin-left:8px;">По дату: <input type="date" name="date_to" value="<?=htmlspecialcharsbx($dateTo->format('Y-m-d'))?>"></label>
        <label style="margin-left:8px;">Офис: <select name="office_filter"><option value="">Все</option><?php foreach ($availableOffices as $officeOpt): ?><option value="<?=htmlspecialcharsbx($officeOpt)?>" <?= $officeFilterRaw === $officeOpt ? 'selected' : '' ?>><?=htmlspecialcharsbx($officeOpt)?></option><?php endforeach; ?></select></label>
        <label style="margin-left:8px;">Кабинет: <select name="cabinet_filter"><option value="">Все</option><?php foreach ($availableCabinets as $cabOpt): ?><option value="<?=htmlspecialcharsbx($cabOpt)?>" <?= $cabinetFilterRaw === $cabOpt ? 'selected' : '' ?>><?=htmlspecialcharsbx($cabOpt)?></option><?php endforeach; ?></select></label>
    </div>
    <div class="filters-row">
        <label>CEO-1: <select name="ceo1_filter" id="ceo1-filter"><option value="">Все</option><?php foreach ($availableCeo1 as $ceo1Opt): ?><option value="<?=htmlspecialcharsbx($ceo1Opt)?>" <?= $ceo1FilterRaw === $ceo1Opt ? 'selected' : '' ?>><?=htmlspecialcharsbx($ceo1Opt)?></option><?php endforeach; ?></select></label>
        <label id="department-filter-wrap" style="margin-left:8px; display: <?= $ceo1FilterRaw !== '' ? 'inline' : 'none' ?>;">Подразделение: <select name="department_filter" id="department-filter"><option value="">Все</option><?php foreach ($availableDepartments as $departmentOpt): ?><option value="<?=htmlspecialcharsbx($departmentOpt)?>" <?= $departmentFilterRaw === $departmentOpt ? 'selected' : '' ?>><?=htmlspecialcharsbx($departmentOpt)?></option><?php endforeach; ?></select></label>
        <button type="submit" style="margin-left:8px;">Показать</button>
        <a href="<?=htmlspecialcharsbx((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))?>" style="margin-left:8px;">Сбросить</a>
    </div>
</form>

<div class="tabs" role="tablist" aria-label="Разделы отчета">
    <button type="button" class="tab-button<?= $activeTab === 'dashboard' ? ' is-active' : '' ?>" data-tab-target="dashboard" role="tab" aria-selected="<?= $activeTab === 'dashboard' ? 'true' : 'false' ?>">Дашборд загрузки</button>
    <button type="button" class="tab-button<?= $activeTab === 'employees' ? ' is-active' : '' ?>" data-tab-target="employees" role="tab" aria-selected="<?= $activeTab === 'employees' ? 'true' : 'false' ?>">Сотрудники</button>
    <button type="button" class="tab-button<?= $activeTab === 'unknown' ? ' is-active' : '' ?>" data-tab-target="unknown" role="tab" aria-selected="<?= $activeTab === 'unknown' ? 'true' : 'false' ?>">Прочие посетители</button>
    <button type="button" class="tab-button<?= $activeTab === 'temporary' ? ' is-active' : '' ?>" data-tab-target="temporary" role="tab" aria-selected="<?= $activeTab === 'temporary' ? 'true' : 'false' ?>">Посещения по временным и гостевым пропускам</button>
    <button type="button" class="tab-button<?= $activeTab === 'cabinet-summary' ? ' is-active' : '' ?>" data-tab-target="cabinet-summary" role="tab" aria-selected="<?= $activeTab === 'cabinet-summary' ? 'true' : 'false' ?>">Сводная таблица по кабинетам</button>
    <button type="button" class="tab-button<?= $activeTab === 'legal-summary' ? ' is-active' : '' ?>" data-tab-target="legal-summary" role="tab" aria-selected="<?= $activeTab === 'legal-summary' ? 'true' : 'false' ?>">Сводные данные по ЮЛ</button>
</div>

<section class="tab-pane<?= $activeTab === 'dashboard' ? ' is-active' : '' ?>" id="tab-dashboard" role="tabpanel">
<h2>Дашборд загрузки <?=htmlspecialcharsbx($dashboardScopeGenitive)?></h2>
<div class="dashboard-grid">
    <div class="dashboard-card">
        <p class="dashboard-card-title">Средняя загрузка <?=htmlspecialcharsbx($dashboardScopeGenitive)?></p>
        <div class="dashboard-card-value"><?= $dashboardAverageOfficeLoad ?>%</div>
        <div class="dashboard-card-note">За выбранный период: <?=htmlspecialcharsbx($dateFrom->format('d.m.Y'))?> — <?=htmlspecialcharsbx($dateTo->format('d.m.Y'))?></div>
    </div>
    <div class="dashboard-card">
        <p class="dashboard-card-title">Пик загрузки <?=htmlspecialcharsbx($dashboardScopeGenitive)?></p>
        <div class="dashboard-card-value"><?= $dashboardPeakOfficeLoad ?>%</div>
        <div class="dashboard-card-note"><?= $dashboardPeakOfficeDate !== '' ? htmlspecialcharsbx((new \DateTime($dashboardPeakOfficeDate))->format('d.m.Y')) : 'Нет данных' ?></div>
    </div>
    <div class="dashboard-card">
        <p class="dashboard-card-title"><?=htmlspecialcharsbx($dashboardSelectionCardTitle)?></p>
        <div class="dashboard-card-value"><?= $dashboardSelectionCardValue ?></div>
        <div class="dashboard-card-note"><?=htmlspecialcharsbx($dashboardSelectionCardNote)?></div>
    </div>
</div>

<div class="dashboard-section">
    <h3>Загрузка по <?=htmlspecialcharsbx($dashboardScopePrepositional)?> по дням</h3>
    <div class="office-load-chart">
        <?php foreach ($dashboardOfficeByDate as $dateKey => $dayData): ?>
            <div class="office-load-row<?= empty($dayData['IS_WORKDAY']) ? ' is-non-workday' : (!empty($dayData['IS_PREHOLIDAY']) ? ' is-preholiday' : '') ?>">
                <div class="office-load-date"><?=htmlspecialcharsbx($formatReportDate($dateKey, true))?></div>
                <div class="office-load-bar" title="<?= (int)$dayData['OCCUPIED'] ?> из <?= (int)$dayData['WORKPLACES'] ?> РМ">
                    <div class="office-load-fill" style="width: <?= min(100, (float)$dayData['UTILIZATION']) ?>%;"></div>
                </div>
                <strong><?= $dayData['UTILIZATION'] ?>%</strong>
            </div>
        <?php endforeach; ?>
    </div>
</div>

</section>

<section class="tab-pane<?= $activeTab === 'employees' ? ' is-active' : '' ?>" id="tab-employees" role="tabpanel">
<div class="report-toolbar"><button type="button" class="export-button" data-export-table="employees-report-table" data-export-name="employees">Экспорт в Excel</button></div>
<p class="dashboard-muted">Если выбран период, строки сгруппированы по датам. Нажмите на строку даты, чтобы раскрыть или свернуть детализацию за день.</p>
<table id="employees-report-table">
    <thead>
    <tr>
        <th>ЮЛ</th>
        <th>CEO-1</th>
        <th>Подразделение</th>
        <th>Руководитель</th>
        <th>Кабинет</th>
        <th class="col-narrow">Кол-во рабочих мест в кабинете</th>
        <th class="col-narrow">Кол-во закрепленных чел. за кабинетом</th>
        <th>Дата</th>
        <th class="col-narrow">Кол-во сотрудников в офисе (&lt;4ч)</th>
        <th class="col-narrow">Кол-во сотрудников в офисе (&gt;4ч)</th>
    </tr>
    </thead>
    <tbody>
    <?php
    $employeeRowsByDate = [];
    foreach ($periodDays as $dateIndex => $dateKey) {
        $dateGroupId = 'employees-date-' . $dateIndex;
        $employeeRowsByDate[$dateKey] = [
            'GROUP_ID' => $dateGroupId,
            'ROWS' => [],
            'TOTALS' => [
                'WORKPLACES_BY_CABINET' => [],
                'ASSIGNED_BY_CABINET' => [],
                'SHORT_OFFICE_BY_CABINET' => [],
                'OFFICE_BY_CABINET' => [],
            ],
        ];

        foreach ($departments as $departmentId => $department) {
            if ((int)$department['UF_HEAD'] <= 0) { continue; }
            $headUserId = (int)$department['UF_HEAD'];
            $headName = isset($headsMap[$headUserId]) ? $headsMap[$headUserId] : 'Не назначен';
            $departmentSummary = isset($headOrgSummaryMap[$headUserId]) ? $headOrgSummaryMap[$headUserId] : ['CEO1' => '', 'DEPARTMENT' => ''];
            if ($ceo1FilterRaw !== '' && (string)$departmentSummary['CEO1'] !== $ceo1FilterRaw) { continue; }
            if ($departmentFilterRaw !== '' && (string)$departmentSummary['DEPARTMENT'] !== $departmentFilterRaw) { continue; }

            $departmentCabinets = [];
            foreach ($userCabinetMap as $userId => $cabName) {
                if (!isset($userDepartmentsMap[$userId]) || !in_array($departmentId, $userDepartmentsMap[$userId], true)) { continue; }
                $norm = $normalizeCabinet((string)$cabName);
                if ($norm === '' || ($officeFilterRaw !== '' && !isset($cabinetDirectory[$norm]))) { continue; }
                $departmentCabinets[$norm] = true;
            }

            foreach (array_keys($departmentCabinets) as $cabNorm) {
                if ($cabinetFilterNorm !== '' && $cabNorm !== $cabinetFilterNorm) { continue; }

                $cabTitle = isset($cabinetDirectory[$cabNorm]) ? (string)$cabinetDirectory[$cabNorm]['TITLE'] : $cabNorm;
                $workplaces = isset($cabinetDirectory[$cabNorm]) ? (int)$cabinetDirectory[$cabNorm]['WORKPLACES'] : 0;
                $assignedCount = isset($cabinetAssignedTotal[$cabNorm]) ? (int)$cabinetAssignedTotal[$cabNorm] : 0;
                $dayData = isset($cabinetDailyOffice[$dateKey][$cabNorm]) ? $cabinetDailyOffice[$dateKey][$cabNorm] : ['TOTAL' => 0, 'SHORT_TOTAL' => 0, 'BY_DEPARTMENT' => [], 'SHORT_BY_DEPARTMENT' => []];
                $departmentLegalCounts = isset($dayData['BY_DEPARTMENT'][$departmentId]) && is_array($dayData['BY_DEPARTMENT'][$departmentId]) ? $dayData['BY_DEPARTMENT'][$departmentId] : [];
                $shortDepartmentLegalCounts = isset($dayData['SHORT_BY_DEPARTMENT'][$departmentId]) && is_array($dayData['SHORT_BY_DEPARTMENT'][$departmentId]) ? $dayData['SHORT_BY_DEPARTMENT'][$departmentId] : [];
                $departmentLegalEntityNames = isset($departmentCabinetLegalEntities[$departmentId][$cabNorm]) ? array_keys($departmentCabinetLegalEntities[$departmentId][$cabNorm]) : [];
                sort($departmentLegalEntityNames, SORT_NATURAL | SORT_FLAG_CASE);
                $departmentLegalEntitiesTitle = !empty($departmentLegalEntityNames) ? implode(', ', $departmentLegalEntityNames) : $undefinedLegalEntity;
                $officeCount = array_sum($departmentLegalCounts);
                $shortOfficeCount = array_sum($shortDepartmentLegalCounts);

                $departmentAssignedUsersRaw = isset($departmentCabinetAssignedUsers[$departmentId][$cabNorm]) && is_array($departmentCabinetAssignedUsers[$departmentId][$cabNorm]) ? $departmentCabinetAssignedUsers[$departmentId][$cabNorm] : [];
                $departmentAssignedUsers = [];
                foreach ($departmentAssignedUsersRaw as $assignedUserId => $assignedUserName) {
                    $assignedUserName = trim((string)$assignedUserName);
                    if ($assignedUserName === '') { continue; }
                    $assignedEmployeeKey = 'U' . (int)$assignedUserId;
                    $isAssignedUserInOffice = isset($officePresenceKeys[$dateKey][$assignedEmployeeKey]) || isset($shortOfficePresenceKeys[$dateKey][$assignedEmployeeKey]);
                    $departmentAssignedUsers[] = [
                        'NAME' => $assignedUserName,
                        'LEGAL_ENTITY' => isset($userLegalEntityMap[(int)$assignedUserId]) && $userLegalEntityMap[(int)$assignedUserId] !== '' ? $userLegalEntityMap[(int)$assignedUserId] : $undefinedLegalEntity,
                        'STATUS' => $isAssignedUserInOffice ? 'В офисе' : 'Не в офисе',
                    ];
                }
                usort($departmentAssignedUsers, static function (array $left, array $right): int { return strnatcasecmp((string)$left['NAME'], (string)$right['NAME']); });
                $departmentAssignedUsersJson = htmlspecialcharsbx(json_encode($departmentAssignedUsers, JSON_UNESCAPED_UNICODE));

                $cabinetAssignedEmployees = [];
                foreach ($departmentCabinetAssignedUsers as $assignedDepartmentId => $assignedCabinets) {
                    if (!isset($assignedCabinets[$cabNorm]) || !isset($departments[$assignedDepartmentId])) { continue; }
                    $assignedHeadUserId = (int)$departments[$assignedDepartmentId]['UF_HEAD'];
                    $assignedHeadName = isset($headsMap[$assignedHeadUserId]) ? $headsMap[$assignedHeadUserId] : 'Не назначен';
                    $assignedDepartmentSummary = isset($headOrgSummaryMap[$assignedHeadUserId]) ? $headOrgSummaryMap[$assignedHeadUserId] : ['CEO1' => '', 'DEPARTMENT' => ''];
                    foreach ($assignedCabinets[$cabNorm] as $assignedUserId => $assignedUserName) {
                        $assignedUserName = trim((string)$assignedUserName);
                        if ($assignedUserName === '') { continue; }
                        $cabinetAssignedEmployees[] = [
                            'LEGAL_ENTITY' => isset($userLegalEntityMap[(int)$assignedUserId]) && $userLegalEntityMap[(int)$assignedUserId] !== '' ? $userLegalEntityMap[(int)$assignedUserId] : $undefinedLegalEntity,
                            'CEO1' => (string)$assignedDepartmentSummary['CEO1'],
                            'DEPARTMENT' => (string)$assignedDepartmentSummary['DEPARTMENT'],
                            'HEAD' => $assignedHeadName,
                            'EMPLOYEE' => $assignedUserName,
                        ];
                    }
                }
                usort($cabinetAssignedEmployees, static function (array $left, array $right): int {
                    $departmentCompare = strnatcasecmp((string)$left['DEPARTMENT'], (string)$right['DEPARTMENT']);
                    if ($departmentCompare !== 0) { return $departmentCompare; }
                    return strnatcasecmp((string)$left['EMPLOYEE'], (string)$right['EMPLOYEE']);
                });
                $cabinetAssignedEmployeesJson = htmlspecialcharsbx(json_encode($cabinetAssignedEmployees, JSON_UNESCAPED_UNICODE));

                $employeeRowsByDate[$dateKey]['ROWS'][] = [
                    'LEGAL_ENTITY' => $departmentLegalEntitiesTitle,
                    'CEO1' => (string)$departmentSummary['CEO1'],
                    'DEPARTMENT' => (string)$departmentSummary['DEPARTMENT'],
                    'HEAD' => $headName,
                    'CABINET' => $cabTitle,
                    'WORKPLACES' => $workplaces,
                    'ASSIGNED' => $assignedCount,
                    'DATE' => (new \DateTime($dateKey))->format('d.m.Y'),
                    'SHORT_OFFICE' => (int)$shortOfficeCount,
                    'OFFICE' => (int)$officeCount,
                    'DEPARTMENT_EMPLOYEES_JSON' => $departmentAssignedUsersJson,
                    'CABINET_EMPLOYEES_JSON' => $cabinetAssignedEmployeesJson,
                ];
                $employeeRowsByDate[$dateKey]['TOTALS']['WORKPLACES_BY_CABINET'][$cabNorm] = $workplaces;
                $employeeRowsByDate[$dateKey]['TOTALS']['ASSIGNED_BY_CABINET'][$cabNorm] = $assignedCount;
                $employeeRowsByDate[$dateKey]['TOTALS']['SHORT_OFFICE_BY_CABINET'][$cabNorm] = isset($dayData['SHORT_TOTAL']) ? (int)$dayData['SHORT_TOTAL'] : 0;
                $employeeRowsByDate[$dateKey]['TOTALS']['OFFICE_BY_CABINET'][$cabNorm] = isset($dayData['TOTAL']) ? (int)$dayData['TOTAL'] : 0;
            }
        }
    }
    ?>
    <?php foreach ($employeeRowsByDate as $dateKey => $dateGroup): ?>
        <?php
        $dateTotals = $dateGroup['TOTALS'];
        $dateWorkplacesTotal = array_sum($dateTotals['WORKPLACES_BY_CABINET']);
        $dateAssignedTotal = array_sum($dateTotals['ASSIGNED_BY_CABINET']);
        $dateShortTotal = array_sum($dateTotals['SHORT_OFFICE_BY_CABINET']);
        $dateOfficeTotal = array_sum($dateTotals['OFFICE_BY_CABINET']);
        $isCollapsed = count($periodDays) > 1;
        ?>
        <tr class="date-group-row<?= $isCollapsed ? ' is-collapsed' : '' ?>" data-date-group="<?=htmlspecialcharsbx((string)$dateGroup['GROUP_ID'])?>">
            <td colspan="5"><button type="button" class="date-group-toggle" aria-expanded="<?= $isCollapsed ? 'false' : 'true' ?>"></button> <?=htmlspecialcharsbx((new \DateTime($dateKey))->format('d.m.Y'))?> — строк: <?= count($dateGroup['ROWS']) ?></td>
            <td><?= (int)$dateWorkplacesTotal ?></td>
            <td><?= (int)$dateAssignedTotal ?></td>
            <td>Итого за день</td>
            <td><?= (int)$dateShortTotal ?></td>
            <td><?= (int)$dateOfficeTotal ?></td>
        </tr>
        <?php foreach ($dateGroup['ROWS'] as $row): ?>
            <tr class="date-detail-row<?= $isCollapsed ? ' is-hidden' : '' ?>" data-date-group="<?=htmlspecialcharsbx((string)$dateGroup['GROUP_ID'])?>">
                <td><?=htmlspecialcharsbx((string)$row['LEGAL_ENTITY'])?></td>
                <td><?=htmlspecialcharsbx((string)$row['CEO1'])?></td>
                <td><?=htmlspecialcharsbx((string)$row['DEPARTMENT'])?></td>
                <td><button type="button" class="head-modal-trigger" data-head="<?=htmlspecialcharsbx((string)$row['HEAD'])?>" data-cabinet="<?=htmlspecialcharsbx((string)$row['CABINET'])?>" data-date="<?=htmlspecialcharsbx((string)$row['DATE'])?>" data-employees="<?=$row['DEPARTMENT_EMPLOYEES_JSON']?>"><?=htmlspecialcharsbx((string)$row['HEAD'])?></button></td>
                <td><?=htmlspecialcharsbx((string)$row['CABINET'])?></td>
                <td><?= (int)$row['WORKPLACES'] ?></td>
                <td><button type="button" class="head-modal-trigger assigned-modal-trigger" data-cabinet="<?=htmlspecialcharsbx((string)$row['CABINET'])?>" data-employees="<?=$row['CABINET_EMPLOYEES_JSON']?>"><?= (int)$row['ASSIGNED'] ?></button></td>
                <td><?=htmlspecialcharsbx((string)$row['DATE'])?></td>
                <td><?= (int)$row['SHORT_OFFICE'] ?></td>
                <td><?= (int)$row['OFFICE'] ?></td>
            </tr>
        <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
</table>
</section>

<section class="tab-pane<?= $activeTab === 'unknown' ? ' is-active' : '' ?>" id="tab-unknown" role="tabpanel">
<h2>Прочие посетители</h2>
<div class="report-toolbar"><button type="button" class="export-button" data-export-table="unknown-report-table" data-export-name="unknown_visitors">Экспорт в Excel</button></div>
<table id="unknown-report-table">
    <thead>
    <tr>
        <th>ЮЛ</th>
        <th>Сотрудник</th>
        <th>Кабинет</th>
        <th>Дата</th>
    </tr>
    </thead>
    <tbody>
    <?php if (empty($unknownEmployees)): ?>
        <tr>
            <td colspan="4">Нет прочих посетителей без определенной структуры или кабинета.</td>
        </tr>
    <?php else: ?>
        <?php $unknownRowsByDate = []; foreach ($unknownEmployees as $employee) { $unknownRowsByDate[(string)$employee['DATE']][] = $employee; } ksort($unknownRowsByDate); ?>
        <?php foreach ($unknownRowsByDate as $dateIndex => $employees): ?>
            <?php $groupId = 'unknown-date-' . preg_replace('/[^0-9]/', '', (string)$dateIndex); $isCollapsed = count($periodDays) > 1; ?>
            <tr class="date-group-row<?= $isCollapsed ? ' is-collapsed' : '' ?>" data-date-group="<?=htmlspecialcharsbx($groupId)?>">
                <td colspan="3"><button type="button" class="date-group-toggle" aria-expanded="<?= $isCollapsed ? 'false' : 'true' ?>"></button> <?=htmlspecialcharsbx($getDateGroupLabel((string)$dateIndex))?> — строк: <?= count($employees) ?></td>
                <td>Итого за день</td>
            </tr>
            <?php foreach ($employees as $employee): ?>
                <tr class="date-detail-row<?= $isCollapsed ? ' is-hidden' : '' ?>" data-date-group="<?=htmlspecialcharsbx($groupId)?>">
                    <td><?=htmlspecialcharsbx((string)($employee['LEGAL_ENTITY'] !== '' ? $employee['LEGAL_ENTITY'] : $undefinedLegalEntity))?></td>
                    <td title="<?=htmlspecialcharsbx((string)($employee['REASON'] ?? ''))?>"><?=htmlspecialcharsbx((string)$employee['EMPLOYEE'])?></td>
                    <td title="<?=htmlspecialcharsbx((string)($employee['CABINET_SOURCE'] ?? 'Другой источник'))?>"><?=htmlspecialcharsbx((string)$employee['CABINET'])?></td>
                    <td><?=htmlspecialcharsbx($formatReportDate((string)$employee['DATE']))?></td>
                </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>
</section>

<section class="tab-pane<?= $activeTab === 'temporary' ? ' is-active' : '' ?>" id="tab-temporary" role="tabpanel">
<h2>Посещения по временным и гостевым пропускам</h2>
<div class="report-toolbar"><button type="button" class="export-button" data-export-table="temporary-report-table" data-export-name="temporary_guest_visits">Экспорт в Excel</button></div>
<table id="temporary-report-table">
    <thead>
    <tr>
        <th>Название</th>
        <th>Дата</th>
    </tr>
    </thead>
    <tbody>
    <?php if (empty($temporaryGuestVisits)): ?>
        <tr>
            <td colspan="2">Нет посещений по временным и гостевым пропускам.</td>
        </tr>
    <?php else: ?>
        <?php $temporaryRowsByDate = []; foreach ($temporaryGuestVisits as $visit) { $temporaryRowsByDate[(string)$visit['DATE']][] = $visit; } ksort($temporaryRowsByDate); ?>
        <?php foreach ($temporaryRowsByDate as $dateIndex => $visits): ?>
            <?php $groupId = 'temporary-date-' . preg_replace('/[^0-9]/', '', (string)$dateIndex); $isCollapsed = count($periodDays) > 1; ?>
            <tr class="date-group-row<?= $isCollapsed ? ' is-collapsed' : '' ?>" data-date-group="<?=htmlspecialcharsbx($groupId)?>">
                <td><button type="button" class="date-group-toggle" aria-expanded="<?= $isCollapsed ? 'false' : 'true' ?>"></button> <?=htmlspecialcharsbx($getDateGroupLabel((string)$dateIndex))?> — строк: <?= count($visits) ?></td>
                <td>Итого за день</td>
            </tr>
            <?php foreach ($visits as $visit): ?>
                <tr class="date-detail-row<?= $isCollapsed ? ' is-hidden' : '' ?>" data-date-group="<?=htmlspecialcharsbx($groupId)?>">
                    <td><?=htmlspecialcharsbx((string)$visit['NAME'])?></td>
                    <td><?=htmlspecialcharsbx($formatReportDate((string)$visit['DATE']))?></td>
                </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>
</section>



<section class="tab-pane<?= $activeTab === 'cabinet-summary' ? ' is-active' : '' ?>" id="tab-cabinet-summary" role="tabpanel">
<h2>Сводная таблица по кабинетам</h2>
<div class="report-toolbar"><button type="button" class="export-button" data-export-table="cabinet-summary-report-table" data-export-name="cabinet_summary">Экспорт в Excel</button></div>
<table id="cabinet-summary-report-table">
    <thead>
    <tr>
        <th>Кабинет</th>
        <th>Дата</th>
        <th class="col-narrow">Кол-во рабочих мест в кабинете</th>
        <th class="col-narrow">Кол-во сотрудников в офисе (&lt;4ч)</th>
        <th class="col-narrow">Кол-во сотрудников в офисе (&gt;4ч)</th>
        <th>% загрузки</th>
        <th class="col-narrow">Кол-во свободных рм</th>
    </tr>
    </thead>
    <tbody>
    <?php
    $cabinetSummaryRowsByDate = [];
    foreach ($periodDays as $dateKey) { $cabinetSummaryRowsByDate[$dateKey] = ['ROWS' => [], 'TOTALS' => ['WORKPLACES' => 0, 'SHORT' => 0, 'OCCUPIED' => 0, 'FREE' => 0]]; }
    foreach ($summaryCabinets as $cabNorm => $cabData) {
        $cabTitle = (string)$cabData['TITLE'];
        $workplaces = (int)$cabData['WORKPLACES'];
        foreach ($periodDays as $dateKey) {
            $dayData = isset($cabinetDailyOffice[$dateKey][$cabNorm]) ? $cabinetDailyOffice[$dateKey][$cabNorm] : ['TOTAL' => 0, 'SHORT_TOTAL' => 0];
            $shortTotalOccupied = isset($dayData['SHORT_TOTAL']) ? (int)$dayData['SHORT_TOTAL'] : 0;
            $totalOccupied = (int)$dayData['TOTAL'];
            $utilization = $workplaces > 0 ? round(($totalOccupied / $workplaces) * 100, 1) : 0;
            $free = max(0, $workplaces - $totalOccupied);
            $cabinetSummaryRowsByDate[$dateKey]['ROWS'][] = [
                'CABINET' => $cabTitle,
                'WORKPLACES' => $workplaces,
                'SHORT' => $shortTotalOccupied,
                'OCCUPIED' => $totalOccupied,
                'UTILIZATION' => $utilization,
                'FREE' => $free,
            ];
            $cabinetSummaryRowsByDate[$dateKey]['TOTALS']['WORKPLACES'] += $workplaces;
            $cabinetSummaryRowsByDate[$dateKey]['TOTALS']['SHORT'] += $shortTotalOccupied;
            $cabinetSummaryRowsByDate[$dateKey]['TOTALS']['OCCUPIED'] += $totalOccupied;
            $cabinetSummaryRowsByDate[$dateKey]['TOTALS']['FREE'] += $free;
        }
    }
    ?>
    <?php foreach ($cabinetSummaryRowsByDate as $dateIndex => $dateGroup): ?>
        <?php $groupId = 'cabinet-summary-date-' . preg_replace('/[^0-9]/', '', (string)$dateIndex); $isCollapsed = count($periodDays) > 1; $totals = $dateGroup['TOTALS']; $totalUtilization = (int)$totals['WORKPLACES'] > 0 ? round(((int)$totals['OCCUPIED'] / (int)$totals['WORKPLACES']) * 100, 1) : 0; ?>
        <tr class="date-group-row<?= $isCollapsed ? ' is-collapsed' : '' ?>" data-date-group="<?=htmlspecialcharsbx($groupId)?>">
            <td><button type="button" class="date-group-toggle" aria-expanded="<?= $isCollapsed ? 'false' : 'true' ?>"></button> <?=htmlspecialcharsbx($getDateGroupLabel((string)$dateIndex))?> — строк: <?= count($dateGroup['ROWS']) ?></td>
            <td>Итого за день</td>
            <td><?= (int)$totals['WORKPLACES'] ?></td>
            <td><?= (int)$totals['SHORT'] ?></td>
            <td><?= (int)$totals['OCCUPIED'] ?></td>
            <td><?= $totalUtilization ?>%</td>
            <td><?= (int)$totals['FREE'] ?></td>
        </tr>
        <?php foreach ($dateGroup['ROWS'] as $row): ?>
            <tr class="date-detail-row<?= $isCollapsed ? ' is-hidden' : '' ?>" data-date-group="<?=htmlspecialcharsbx($groupId)?>">
                <td><?=htmlspecialcharsbx((string)$row['CABINET'])?></td>
                <td><?=htmlspecialcharsbx($formatReportDate((string)$dateIndex))?></td>
                <td><?= (int)$row['WORKPLACES'] ?></td>
                <td><?= (int)$row['SHORT'] ?></td>
                <td><?= (int)$row['OCCUPIED'] ?></td>
                <td><?= (float)$row['UTILIZATION'] ?>%</td>
                <td><?= (int)$row['FREE'] ?></td>
            </tr>
        <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
</table>
</section>

<section class="tab-pane<?= $activeTab === 'legal-summary' ? ' is-active' : '' ?>" id="tab-legal-summary" role="tabpanel">
<h2>Сводные данные по ЮЛ</h2>
<div class="report-toolbar"><button type="button" class="export-button" data-export-table="legal-summary-report-table" data-export-name="legal_entity_summary">Экспорт в Excel</button></div>
<table id="legal-summary-report-table">
    <thead>
    <tr>
        <th>Дата</th>
        <th>ЮЛ</th>
        <th class="col-narrow">Кол-во рабочих мест в <?=htmlspecialcharsbx($legalEntitySummaryScopeTitle)?></th>
        <th class="col-narrow">Кол-во привязанных РМ в <?=htmlspecialcharsbx($legalEntitySummaryScopeTitle)?> к ЮЛ</th>
        <th class="col-narrow">Кол-во сотрудников в <?=htmlspecialcharsbx($legalEntitySummaryScopeTitle)?> (&gt;4 ч)</th>
        <th class="col-narrow">Кол-во свободных рм</th>
        <th>% загрузки офиса</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($legalEntitySummary as $dateIndex => $legalEntityCounts): ?>
        <?php if (empty($legalEntityCounts)): ?>
            <?php $legalEntityCounts = [$undefinedLegalEntity => 0]; ?>
        <?php endif; ?>
        <?php
        $groupId = 'legal-summary-date-' . preg_replace('/[^0-9]/', '', (string)$dateIndex);
        $isCollapsed = count($periodDays) > 1;
        $dateTotals = [
            'WORKPLACES' => $officeWorkplacesTotal,
            'ASSIGNED' => 0,
            'OCCUPIED' => 0,
            'FREE' => 0,
        ];
        $legalRows = [];
        foreach ($legalEntityCounts as $legalEntity => $occupied) {
            $occupied = (int)$occupied;
            $assigned = isset($legalEntityAssignedWorkplaces[$legalEntity]) ? (int)$legalEntityAssignedWorkplaces[$legalEntity] : 0;
            $assigned += isset($otherVisitorsAssignedWorkplaces[$dateIndex][$legalEntity]) && is_array($otherVisitorsAssignedWorkplaces[$dateIndex][$legalEntity]) ? count($otherVisitorsAssignedWorkplaces[$dateIndex][$legalEntity]) : 0;
            $free = max(0, $assigned - $occupied);
            $dateTotals['ASSIGNED'] += $assigned;
            $dateTotals['OCCUPIED'] += $occupied;
            $dateTotals['FREE'] += $free;
            $legalRows[] = ['LEGAL_ENTITY' => (string)$legalEntity, 'ASSIGNED' => $assigned, 'OCCUPIED' => $occupied];
        }
        $dateTotalAssigned = (int)$dateTotals['ASSIGNED'];
        $dateTotalUtilization = $dateTotalAssigned > 0 ? round(((int)$dateTotals['OCCUPIED'] / $dateTotalAssigned) * 100, 1) : 0;
        ?>
        <tr class="date-group-row<?= $isCollapsed ? ' is-collapsed' : '' ?>" data-date-group="<?=htmlspecialcharsbx($groupId)?>">
            <td><button type="button" class="date-group-toggle" aria-expanded="<?= $isCollapsed ? 'false' : 'true' ?>"></button> <?=htmlspecialcharsbx($getDateGroupLabel((string)$dateIndex))?></td>
            <td>Итого за день</td>
            <td><?= (int)$dateTotals['WORKPLACES'] ?></td>
            <td><?= $dateTotalAssigned ?></td>
            <td><?= (int)$dateTotals['OCCUPIED'] ?></td>
            <td><?= (int)$dateTotals['FREE'] ?></td>
            <td><?= $dateTotalUtilization ?>%</td>
        </tr>
        <?php foreach ($legalRows as $row): ?>
            <tr class="date-detail-row<?= $isCollapsed ? ' is-hidden' : '' ?>" data-date-group="<?=htmlspecialcharsbx($groupId)?>">
                <td><?=htmlspecialcharsbx($formatReportDate((string)$dateIndex))?></td>
                <td><?=htmlspecialcharsbx((string)$row['LEGAL_ENTITY'])?></td>
                <td></td>
                <td><?= (int)$row['ASSIGNED'] ?></td>
                <td><?= (int)$row['OCCUPIED'] ?></td>
                <td></td>
                <td></td>
            </tr>
        <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
</table>
</section>



<div class="modal-backdrop" id="departmentCabinetModal" aria-hidden="true">
    <div class="modal-window" role="dialog" aria-modal="true" aria-labelledby="departmentCabinetModalTitle">
        <div class="modal-header">
            <h2 class="modal-title" id="departmentCabinetModalTitle">Сотрудники подразделения</h2>
            <button type="button" class="modal-close" id="departmentCabinetModalClose" aria-label="Закрыть">&times;</button>
        </div>
        <p class="modal-subtitle" id="departmentCabinetModalSubtitle"></p>
        <div id="departmentCabinetModalBody"></div>
    </div>
</div>
<script>
(function () {
    var activeTabInput = document.getElementById('active-tab-input');

    var departmentsByCeo1 = <?=\CUtil::PhpToJSObject(array_map('array_keys', $availableDepartmentsByCeo1), false, true)?>;
    var ceo1Filter = document.getElementById('ceo1-filter');
    var departmentWrap = document.getElementById('department-filter-wrap');
    var departmentFilter = document.getElementById('department-filter');
    function rebuildDepartmentFilter() {
        if (!ceo1Filter || !departmentWrap || !departmentFilter) { return; }
        var ceo1 = ceo1Filter.value || '';
        var current = departmentFilter.value || '';
        departmentWrap.style.display = ceo1 ? 'inline' : 'none';
        departmentFilter.innerHTML = '<option value="">Все</option>';
        (departmentsByCeo1[ceo1] || []).forEach(function (department) {
            var option = document.createElement('option');
            option.value = department;
            option.textContent = department;
            option.selected = department === current;
            departmentFilter.appendChild(option);
        });
        if (!ceo1) { departmentFilter.value = ''; }
    }
    if (ceo1Filter) { ceo1Filter.addEventListener('change', rebuildDepartmentFilter); }

    document.querySelectorAll('.tab-button').forEach(function (button) {
        button.addEventListener('click', function () {
            var target = button.getAttribute('data-tab-target');
            if (!target) { return; }
            if (activeTabInput) { activeTabInput.value = target; }

            document.querySelectorAll('.tab-button').forEach(function (tabButton) {
                var isActive = tabButton === button;
                tabButton.classList.toggle('is-active', isActive);
                tabButton.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });
            document.querySelectorAll('.tab-pane').forEach(function (pane) {
                pane.classList.toggle('is-active', pane.id === 'tab-' + target);
            });
        });
    });


    document.querySelectorAll('.date-group-row').forEach(function (row) {
        row.addEventListener('click', function (event) {
            if (event.target && event.target.closest && event.target.closest('.head-modal-trigger')) { return; }
            var groupId = row.getAttribute('data-date-group');
            if (!groupId) { return; }
            var shouldCollapse = !row.classList.contains('is-collapsed');
            row.classList.toggle('is-collapsed', shouldCollapse);
            var toggle = row.querySelector('.date-group-toggle');
            if (toggle) { toggle.setAttribute('aria-expanded', shouldCollapse ? 'false' : 'true'); }
            document.querySelectorAll('.date-detail-row[data-date-group="' + groupId + '"]').forEach(function (detailRow) {
                detailRow.classList.toggle('is-hidden', shouldCollapse);
            });
        });
    });

    document.querySelectorAll('.export-button').forEach(function (button) {
        button.addEventListener('click', function () {
            var table = document.getElementById(button.getAttribute('data-export-table') || '');
            if (!table) { return; }

            var html = '<html><head><meta charset="UTF-8"></head><body>' + table.outerHTML + '</body></html>';
            var blob = new Blob(['\ufeff', html], {type: 'application/vnd.ms-excel;charset=utf-8;'});
            var link = document.createElement('a');
            var fileName = (button.getAttribute('data-export-name') || 'report') + '_' + (new Date()).toISOString().slice(0, 10) + '.xls';
            link.href = URL.createObjectURL(blob);
            link.download = fileName;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            setTimeout(function () { URL.revokeObjectURL(link.href); }, 1000);
        });
    });

    var modal = document.getElementById('departmentCabinetModal');
    var closeButton = document.getElementById('departmentCabinetModalClose');
    var subtitle = document.getElementById('departmentCabinetModalSubtitle');
    var body = document.getElementById('departmentCabinetModalBody');
    if (!modal || !closeButton || !subtitle || !body) { return; }

    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
    }

    function renderEmployees(employees) {
        body.innerHTML = '';
        if (!employees.length) {
            var empty = document.createElement('p');
            empty.className = 'modal-empty';
            empty.textContent = 'Нет сотрудников этого подразделения, закрепленных за кабинетом.';
            body.appendChild(empty);
            return;
        }

        var table = document.createElement('table');
        table.className = 'modal-table';
        var thead = document.createElement('thead');
        var headerRow = document.createElement('tr');
        ['ФИО', 'ЮЛ', 'В офисе/не в офисе за отчетный день'].forEach(function (title) {
            var th = document.createElement('th');
            th.textContent = title;
            headerRow.appendChild(th);
        });
        thead.appendChild(headerRow);
        table.appendChild(thead);

        var tbody = document.createElement('tbody');
        employees.forEach(function (employee) {
            var row = document.createElement('tr');
            var nameCell = document.createElement('td');
            var legalEntityCell = document.createElement('td');
            var statusCell = document.createElement('td');
            var status = employee.STATUS || 'Не в офисе';
            nameCell.textContent = employee.NAME || '';
            legalEntityCell.textContent = employee.LEGAL_ENTITY || 'Не определено';
            statusCell.textContent = status;
            statusCell.className = status === 'В офисе' ? 'modal-status-in' : 'modal-status-out';
            row.appendChild(nameCell);
            row.appendChild(legalEntityCell);
            row.appendChild(statusCell);
            tbody.appendChild(row);
        });
        table.appendChild(tbody);
        body.appendChild(table);
    }

    function renderAssignedEmployees(employees) {
        body.innerHTML = '';
        if (!employees.length) {
            var empty = document.createElement('p');
            empty.className = 'modal-empty';
            empty.textContent = 'Нет сотрудников, закрепленных за этим кабинетом.';
            body.appendChild(empty);
            return;
        }

        var table = document.createElement('table');
        table.className = 'modal-table';
        var thead = document.createElement('thead');
        var headerRow = document.createElement('tr');
        ['ЮЛ', 'CEO-1', 'Подразделение', 'Руководитель', 'Сотрудник'].forEach(function (title) {
            var th = document.createElement('th');
            th.textContent = title;
            headerRow.appendChild(th);
        });
        thead.appendChild(headerRow);
        table.appendChild(thead);

        var tbody = document.createElement('tbody');
        employees.forEach(function (employee) {
            var row = document.createElement('tr');
            ['LEGAL_ENTITY', 'CEO1', 'DEPARTMENT', 'HEAD', 'EMPLOYEE'].forEach(function (field) {
                var cell = document.createElement('td');
                cell.textContent = employee[field] || '';
                row.appendChild(cell);
            });
            tbody.appendChild(row);
        });
        table.appendChild(tbody);
        body.appendChild(table);
    }

    document.querySelectorAll('.head-modal-trigger').forEach(function (button) {
        button.addEventListener('click', function () {
            var employees = [];
            try {
                employees = JSON.parse(button.getAttribute('data-employees') || '[]');
            } catch (error) {
                employees = [];
            }
            if (!Array.isArray(employees)) { employees = []; }

            var isAssignedModal = button.classList.contains('assigned-modal-trigger');
            if (isAssignedModal) {
                subtitle.textContent = 'Кабинет: ' + (button.getAttribute('data-cabinet') || '') + '.';
                renderAssignedEmployees(employees);
            } else {
                subtitle.textContent = 'Руководитель: ' + (button.getAttribute('data-head') || '') + '. Кабинет: ' + (button.getAttribute('data-cabinet') || '') + '. Дата: ' + (button.getAttribute('data-date') || '') + '.';
                renderEmployees(employees);
            }
            document.getElementById('departmentCabinetModalTitle').textContent = isAssignedModal ? 'Сотрудники, закрепленные за кабинетом' : 'Сотрудники подразделения';
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            closeButton.focus();
        });
    });

    closeButton.addEventListener('click', closeModal);
    modal.addEventListener('click', function (event) {
        if (event.target === modal) { closeModal(); }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { closeModal(); }
    });
})();
</script>
</body>
</html>
