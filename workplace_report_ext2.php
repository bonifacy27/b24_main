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

$defaultOfficeFilter = 'Московский пр., 139/1';
$cabinetFilterRaw = isset($_GET['cabinet_filter']) ? trim((string)$_GET['cabinet_filter']) : '';
$ceo1FilterRaw = isset($_GET['ceo1_filter']) ? trim((string)$_GET['ceo1_filter']) : '';
$officeFilterRaw = isset($_GET['office_filter']) ? trim((string)$_GET['office_filter']) : $defaultOfficeFilter;

$normalizeLegalEntity = static function ($value): string {
    $legalEntity = trim((string)$value);
    return $legalEntity !== '' ? $legalEntity : 'НСК';
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
    'ТТ' => 'ТТ',
];

$resolveLegalEntityByUser = static function (array $user, array $companyLegalEntityMap, array $companyEnumValueMap) use ($resolveListDisplayValue, $normalizeLegalEntity): string {
    $email = mb_strtolower(trim((string)($user['EMAIL'] ?? '')));
    if ($email !== '') {
        if (substr($email, -12) === '@tricolor.ru') { return 'НСК'; }
        if (substr($email, -18) === '@tricolormedia.ru') { return 'ТМХ'; }
        if (substr($email, -16) === '@monobrand-tt.ru') { return 'ТТ'; }
        if (substr($email, -8) === '@n-l-e.ru') { return 'НЛЕ'; }
        if (substr($email, -11) === '@telemag.ru') { return 'СМ'; }
    }

    $company = $resolveListDisplayValue($user['UF_COMPANY'] ?? '', $companyEnumValueMap);
    return isset($companyLegalEntityMap[$company]) ? $companyLegalEntityMap[$company] : $normalizeLegalEntity('');
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
$cabinetAssignedTotal = [];
$companyEnumValueMap = $getEnumValueMap('USER', 'UF_COMPANY');

$rsUsers = \CUser::GetList($by='id', $order='asc', ['ACTIVE' => 'Y'], ['SELECT' => ['UF_DEPARTMENT', 'UF_CABINET', 'UF_COMPANY'], 'FIELDS' => ['ID', 'EMAIL', 'UF_DEPARTMENT', 'UF_CABINET']]);
while ($user = $rsUsers->Fetch()) {
    $userId = (int)$user['ID'];
    $userLegalEntityMap[$userId] = $resolveLegalEntityByUser($user, $companyLegalEntityMap, $companyEnumValueMap);
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
$cabinetDirectoryById = [];
$availableOfficesMap = [];
$officeEnumValueMap = $getEnumValueMap('HLBLOCK_74', 'UF_OFFICE');
$cabinetHl = \Bitrix\Highloadblock\HighloadBlockTable::getById(74)->fetch();
if ($cabinetHl) {
    $cabinetEntity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($cabinetHl);
    $cabinetClass = $cabinetEntity->getDataClass();
    $rsCabinets = $cabinetClass::getList(['select' => ['ID', 'UF_NAME', 'UF_WORKPLACES', 'UF_OFFICE']]);
    while ($row = $rsCabinets->fetch()) {
        $title = trim((string)$row['UF_NAME']);
        $normalized = $normalizeDirectoryCabinet($title);
        if ($normalized === '') { continue; }
        $office = $resolveListDisplayValue(isset($row['UF_OFFICE']) ? $row['UF_OFFICE'] : '', $officeEnumValueMap);
        if ($office !== '') { $availableOfficesMap[$office] = true; }
        if ($officeFilterRaw !== '' && $office !== $officeFilterRaw) { continue; }
        $cabinetData = ['TITLE' => $title, 'WORKPLACES' => (int)$row['UF_WORKPLACES'], 'OFFICE' => $office];
        $cabinetDirectory[$normalized] = $cabinetData;
        $cabinetDirectoryById[(int)$row['ID']] = $cabinetData + ['NORM' => $normalized];
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
        ];
    }

    $cabinetNorm = $normalizeCabinet($cabinetRaw);
    if ($cabinetNorm === '') { $cabinetNorm = $normalizeDirectoryCabinet($cabinetRaw); }

    return [
        'TITLE' => $cabinetNorm !== '' ? $cabinetRaw : '',
        'NORM' => $cabinetNorm,
    ];
};

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
        'select' => ['UF_EXT_ID', 'UF_LAST_NAME', 'UF_FIRST_NAME', 'UF_SECOND_NAME', 'UF_CABINET'],
        'filter' => ['=UF_SOURCE' => 'Reverse'],
    ]);
    while ($row = $reverseUsersRows->fetch()) {
        $passId = trim((string)$row['UF_EXT_ID']);
        if ($passId === '') { continue; }
        $reverseCabinet = $resolveReverseCabinet(isset($row['UF_CABINET']) ? $row['UF_CABINET'] : '');
        $reverseUsersByPass[$passId] = [
            'FIO' => trim((string)$row['UF_LAST_NAME'] . ' ' . (string)$row['UF_FIRST_NAME'] . ' ' . (string)$row['UF_SECOND_NAME']),
            'LEGAL_ENTITY' => 'НСК',
            'CABINET' => (string)$reverseCabinet['TITLE'],
            'CABINET_NORM' => (string)$reverseCabinet['NORM'],
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

        $reverseUser = isset($reverseUsersByPass[$passId]) ? $reverseUsersByPass[$passId] : ['FIO' => '', 'LEGAL_ENTITY' => 'НСК', 'CABINET' => '', 'CABINET_NORM' => ''];
        $legalEntity = $portalUserId > 0 && isset($userLegalEntityMap[$portalUserId]) ? $userLegalEntityMap[$portalUserId] : $normalizeLegalEntity($reverseUser['LEGAL_ENTITY']);
        $employeeKey = $portalUserId > 0 ? 'U' . $portalUserId : 'P' . $passId;
        if ($isShortOfficePresence) {
            if (isset($shortOfficePresenceKeys[$dateKey][$employeeKey])) { continue; }
            $shortOfficePresenceKeys[$dateKey][$employeeKey] = true;
        } else {
            if (isset($officePresenceKeys[$dateKey][$employeeKey])) { continue; }
            $officePresenceKeys[$dateKey][$employeeKey] = true;
        }

        $userCabinetNorm = $portalUserId > 0 && isset($userCabinetMap[$portalUserId]) ? $normalizeCabinet((string)$userCabinetMap[$portalUserId]) : '';
        $userDepartmentIds = $portalUserId > 0 && isset($userDepartmentsMap[$portalUserId]) ? $userDepartmentsMap[$portalUserId] : [];

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
        }

        if ($cabinetFilterNorm !== '' && $reverseCabinetNorm !== $cabinetFilterNorm) { continue; }
        $employeeName = trim((string)$reverseUser['FIO']);
        if ($employeeName === '') { $employeeName = 'Пропуск ' . $passId; }
        if ($isTemporaryOrGuestPass($employeeName)) {
            $temporaryGuestVisits[] = [
                'NAME' => $employeeName,
                'DATE' => $dateKey,
            ];
            continue;
        }
        $unknownEmployees[] = [
            'LEGAL_ENTITY' => $legalEntity,
            'EMPLOYEE' => $employeeName,
            'CABINET' => $reverseCabinetTitle,
            'DATE' => $dateKey,
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
    $allCabinets[(string)$cabName] = true;
}
$availableCabinets = array_keys($allCabinets);
sort($availableCabinets, SORT_NATURAL | SORT_FLAG_CASE);

$availableOffices = array_keys($availableOfficesMap);
sort($availableOffices, SORT_NATURAL | SORT_FLAG_CASE);

$availableCeo1 = [];
foreach ($departments as $departmentId => $department) {
    $headUserId = (int)$department['UF_HEAD'];
    if ($headUserId <= 0) { continue; }
    $departmentSummary = isset($headOrgSummaryMap[$headUserId]) ? $headOrgSummaryMap[$headUserId] : ['CEO1' => '', 'DEPARTMENT' => ''];
    $ceo1 = trim((string)$departmentSummary['CEO1']);
    if ($ceo1 !== '') { $availableCeo1[$ceo1] = true; }
}
$availableCeo1 = array_keys($availableCeo1);
sort($availableCeo1, SORT_NATURAL | SORT_FLAG_CASE);

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
    <label style="margin-left:8px;">Офис: <select name="office_filter"><option value="">Все</option><?php foreach ($availableOffices as $officeOpt): ?><option value="<?=htmlspecialcharsbx($officeOpt)?>" <?= $officeFilterRaw === $officeOpt ? 'selected' : '' ?>><?=htmlspecialcharsbx($officeOpt)?></option><?php endforeach; ?></select></label>
    <label style="margin-left:8px;">Кабинет: <select name="cabinet_filter"><option value="">Все</option><?php foreach ($availableCabinets as $cabOpt): ?><option value="<?=htmlspecialcharsbx($cabOpt)?>" <?= $cabinetFilterRaw === $cabOpt ? 'selected' : '' ?>><?=htmlspecialcharsbx($cabOpt)?></option><?php endforeach; ?></select></label>
    <label style="margin-left:8px;">CEO-1: <select name="ceo1_filter"><option value="">Все</option><?php foreach ($availableCeo1 as $ceo1Opt): ?><option value="<?=htmlspecialcharsbx($ceo1Opt)?>" <?= $ceo1FilterRaw === $ceo1Opt ? 'selected' : '' ?>><?=htmlspecialcharsbx($ceo1Opt)?></option><?php endforeach; ?></select></label>
    <button type="submit" style="margin-left:8px;">Показать</button>
    <a href="<?=htmlspecialcharsbx((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))?>" style="margin-left:8px;">Сбросить</a>
</form>

<table>
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
    foreach ($departments as $departmentId => $department) {
        if ((int)$department['UF_HEAD'] <= 0) { continue; }
        $headUserId = (int)$department['UF_HEAD'];
        $headName = isset($headsMap[$headUserId]) ? $headsMap[$headUserId] : 'Не назначен';
        $departmentSummary = isset($headOrgSummaryMap[$headUserId]) ? $headOrgSummaryMap[$headUserId] : ['CEO1' => '', 'DEPARTMENT' => ''];
        if ($ceo1FilterRaw !== '' && (string)$departmentSummary['CEO1'] !== $ceo1FilterRaw) { continue; }

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

            foreach ($periodDays as $dateKey) {
                $dayData = isset($cabinetDailyOffice[$dateKey][$cabNorm]) ? $cabinetDailyOffice[$dateKey][$cabNorm] : ['TOTAL' => 0, 'SHORT_TOTAL' => 0, 'BY_DEPARTMENT' => [], 'SHORT_BY_DEPARTMENT' => []];
                $departmentLegalCounts = isset($dayData['BY_DEPARTMENT'][$departmentId]) && is_array($dayData['BY_DEPARTMENT'][$departmentId]) ? $dayData['BY_DEPARTMENT'][$departmentId] : [];
                $shortDepartmentLegalCounts = isset($dayData['SHORT_BY_DEPARTMENT'][$departmentId]) && is_array($dayData['SHORT_BY_DEPARTMENT'][$departmentId]) ? $dayData['SHORT_BY_DEPARTMENT'][$departmentId] : [];
                $legalEntities = array_fill_keys(array_merge(array_keys($departmentLegalCounts), array_keys($shortDepartmentLegalCounts)), true);
                if (empty($legalEntities)) { $legalEntities = ['НСК' => true]; }
                $departmentLegalCounts = array_replace(array_fill_keys(array_keys($legalEntities), 0), $departmentLegalCounts);
                $shortDepartmentLegalCounts = array_replace(array_fill_keys(array_keys($legalEntities), 0), $shortDepartmentLegalCounts);
                ksort($departmentLegalCounts, SORT_NATURAL | SORT_FLAG_CASE);
                ?>
                <?php foreach ($departmentLegalCounts as $legalEntity => $officeCount): ?>
                <tr>
                    <td><?=htmlspecialcharsbx((string)$legalEntity)?></td>
                    <td><?=htmlspecialcharsbx((string)$departmentSummary['CEO1'])?></td>
                    <td><?=htmlspecialcharsbx((string)$departmentSummary['DEPARTMENT'])?></td>
                    <td><?=htmlspecialcharsbx($headName)?></td>
                    <td><?=htmlspecialcharsbx($cabTitle)?></td>
                    <td><?= $workplaces ?></td>
                    <td><?= $assignedCount ?></td>
                    <td><?=htmlspecialcharsbx((new \DateTime($dateKey))->format('d.m.Y'))?></td>
                    <td><?= isset($shortDepartmentLegalCounts[$legalEntity]) ? (int)$shortDepartmentLegalCounts[$legalEntity] : 0 ?></td>
                    <td><?= (int)$officeCount ?></td>
                </tr>
                <?php endforeach; ?>
                <?php
            }
        }
    }
    ?>
    </tbody>
</table>

<h2>Прочие посетители</h2>
<table>
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
        <?php foreach ($unknownEmployees as $employee): ?>
            <tr>
                <td><?=htmlspecialcharsbx((string)$employee['LEGAL_ENTITY'])?></td>
                <td><?=htmlspecialcharsbx((string)$employee['EMPLOYEE'])?></td>
                <td><?=htmlspecialcharsbx((string)$employee['CABINET'])?></td>
                <td><?=htmlspecialcharsbx((new \DateTime((string)$employee['DATE']))->format('d.m.Y'))?></td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>


<h2>Посещения по временным и гостевым пропускам</h2>
<table>
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
        <?php foreach ($temporaryGuestVisits as $visit): ?>
            <tr>
                <td><?=htmlspecialcharsbx((string)$visit['NAME'])?></td>
                <td><?=htmlspecialcharsbx((new \DateTime((string)$visit['DATE']))->format('d.m.Y'))?></td>
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
?>

<h2>Сводная таблица по кабинетам</h2>
<table>
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
    <?php foreach ($summaryCabinets as $cabNorm => $cabData): ?>
        <?php
        $cabTitle = (string)$cabData['TITLE'];
        $workplaces = (int)$cabData['WORKPLACES'];
        $rowspan = max(1, count($periodDays));
        ?>
        <?php foreach ($periodDays as $dateIndex => $dateKey): ?>
            <?php
            $dayData = isset($cabinetDailyOffice[$dateKey][$cabNorm]) ? $cabinetDailyOffice[$dateKey][$cabNorm] : ['TOTAL' => 0, 'SHORT_TOTAL' => 0];
            $shortTotalOccupied = isset($dayData['SHORT_TOTAL']) ? (int)$dayData['SHORT_TOTAL'] : 0;
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
                <td><?= $shortTotalOccupied ?></td>
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
