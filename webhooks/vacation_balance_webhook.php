<?php
/**
 * vacation_balance_webhook.php
 * v1.1 - чистый JSON (без BX.message), update/add в HL ENTITY_ID=84
 */

define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
define("BX_PUBLIC_MODE", true);
define("PUBLIC_AJAX_MODE", true);
define("NO_AGENT_CHECK", true);
define("DisableEventsCheck", true);

@ini_set('display_errors', 0);
@error_reporting(E_ALL & ~E_NOTICE);

ob_start();

function respond($ok, $message, array $data = [], $httpCode = 200)
{
    while (ob_get_level() > 0) { ob_end_clean(); }
    ob_start();

    http_response_code($httpCode);
    header('Content-Type: application/json; charset=UTF-8');

    echo json_encode([
        'ok' => (bool)$ok,
        'message' => (string)$message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

function getRequestData(): array
{
    $data = [];

    $raw = file_get_contents('php://input');
    if ($raw) {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $data = array_merge($data, $json);
        }
    }

    if (!empty($_POST)) $data = array_merge($data, $_POST);
    if (!empty($_GET))  $data = array_merge($data, $_GET);

    return $data;
}

// === НАСТРОЙКИ ===
const HL_ENTITY_ID = 84;
const WEBHOOK_TOKEN = '123'; // <-- поставь нормальный секрет
// ===================

$data = getRequestData();

$token   = isset($data['token']) ? (string)$data['token'] : '';
$guid    = isset($data['guid']) ? trim((string)$data['guid']) : '';
$year    = isset($data['year']) ? (int)$data['year'] : 0;
$balance = isset($data['balance']) ? (int)$data['balance'] : 0;

if ($token === '' || $token !== WEBHOOK_TOKEN) {
    respond(false, 'Unauthorized: invalid token', [], 401);
}
if ($guid === '') {
    respond(false, 'Validation error: guid is required', [], 400);
}
if ($year < 2000 || $year > 2100) {
    respond(false, 'Validation error: year is invalid', ['year' => $year], 400);
}

// Подключаем Битрикс
$prolog = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/bitrix/modules/main/include/prolog_before.php';
if (!file_exists($prolog)) {
    respond(false, 'Bitrix prolog not found', ['path' => $prolog], 500);
}
require_once $prolog;

// Сносим всё, что успел напечатать Битрикс
while (ob_get_level() > 0) { ob_end_clean(); }
ob_start();

if (!\Bitrix\Main\Loader::includeModule('highloadblock')) {
    respond(false, 'Module highloadblock not installed', [], 500);
}
if (!\Bitrix\Main\Loader::includeModule('main')) {
    respond(false, 'Module main not installed', [], 500);
}

try {
    // 1) Пользователь по UF_1C_GUID
    $userId = 0;
    $rsUser = \CUser::GetList(
        ($by = "ID"),
        ($order = "ASC"),
        ['=UF_1C_GUID' => $guid],
        ['FIELDS' => ['ID']]
    );

    if ($u = $rsUser->Fetch()) {
        $userId = (int)$u['ID'];
    }

    if ($userId <= 0) {
        respond(false, 'User not found by UF_1C_GUID', ['guid' => $guid], 404);
    }

    // 2) HL DataClass
    $hlblock = \Bitrix\Highloadblock\HighloadBlockTable::getById(HL_ENTITY_ID)->fetch();
    if (!$hlblock) {
        respond(false, 'HL-block not found', ['ENTITY_ID' => HL_ENTITY_ID], 500);
    }

    $entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlblock);
    $dataClass = $entity->getDataClass();

    // 3) Ищем строку
    $existing = $dataClass::getList([
        'select' => ['ID', 'UF_YEAR_END_BALANCE'],
        'filter' => [
            '=UF_EMPLOYEE' => $userId,
            '=UF_YEAR' => $year,
        ],
        'limit' => 1,
    ])->fetch();

    if ($existing && (int)$existing['ID'] > 0) {
        $updateRes = $dataClass::update((int)$existing['ID'], [
            'UF_YEAR_END_BALANCE' => $balance,
        ]);

        if (!$updateRes->isSuccess()) {
            respond(false, 'HL update failed', [
                'errors' => $updateRes->getErrorMessages(),
                'row' => $existing,
            ], 500);
        }

        respond(true, 'Updated', [
            'userId' => $userId,
            'guid' => $guid,
            'year' => $year,
            'balance' => $balance,
            'hlRowId' => (int)$existing['ID'],
            'previousBalance' => (int)$existing['UF_YEAR_END_BALANCE'],
        ]);
    }

    // 4) Если нет строки — создаём
    $addRes = $dataClass::add([
        'UF_EMPLOYEE' => $userId,
        'UF_YEAR' => $year,
        'UF_YEAR_END_BALANCE' => $balance,
    ]);

    if (!$addRes->isSuccess()) {
        respond(false, 'HL add failed', [
            'errors' => $addRes->getErrorMessages(),
            'userId' => $userId,
            'year' => $year,
            'balance' => $balance,
        ], 500);
    }

    respond(true, 'Created', [
        'userId' => $userId,
        'guid' => $guid,
        'year' => $year,
        'balance' => $balance,
        'hlRowId' => (int)$addRes->getId(),
    ]);

} catch (\Throwable $e) {
    respond(false, 'Exception: ' . $e->getMessage(), [
        'type' => get_class($e),
        'code' => $e->getCode(),
    ], 500);
}
