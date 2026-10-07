<?php
// This endpoint reads catalog data only; it cannot run a workflow or modify templates.
require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
global $USER;
$bufferLevel = ob_get_level();
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$USER || !$USER->IsAuthorized() || !check_bitrix_sessid()) {
        http_response_code(403);
        throw new RuntimeException('Нет доступа или истекла сессия.');
    }
    if (!\Bitrix\Main\Loader::includeModule('bizproc')) {
        throw new RuntimeException('Модуль бизнес-процессов недоступен.');
    }
    require_once __DIR__.'/catalog.php';
    $iblockId = isset($_POST['iblock']) ? (int)$_POST['iblock'] : 0;
    TricolorNotificationCatalog::assertReadable($iblockId);
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    if ($action === 'catalog') {
        $data = TricolorNotificationCatalog::schema($iblockId);
        $data['templates'] = TricolorNotificationCatalog::elements($iblockId);
    } elseif ($action === 'parameters') {
        $map = isset($_POST['map']) && is_array($_POST['map']) ? $_POST['map'] : array();
        $texts = TricolorNotificationCatalog::texts($iblockId, (int)$_POST['template'], $map);
        $parameters = TricolorNotificationTemplate::parameters($texts);
        // BX.ajax.prepareData requires hasOwnProperty() on nested objects. The UI cache
        // deliberately has no prototype; send it as JSON, also allowing reserved key names.
        if (isset($_POST['bindings_json'])) {
            if (!is_string($_POST['bindings_json'])) {
                throw new InvalidArgumentException('Некорректный формат соответствий параметров.');
            }
            $bindings = \Bitrix\Main\Web\Json::decode($_POST['bindings_json']);
            if (!is_array($bindings)) {
                throw new InvalidArgumentException('Некорректный формат соответствий параметров.');
            }
        } else {
            // Keep compatibility with an already opened dialog from the previous version.
            $bindings = isset($_POST['bindings']) && is_array($_POST['bindings']) ? $_POST['bindings'] : array();
        }
        foreach ($bindings as $key => $value) {
            if (!is_string($value)) {
                unset($bindings[$key]);
            }
        }
        ob_start();
        require __DIR__.'/bindings.php';
        $html = ob_get_clean();
        $data = array('html'=>$html, 'parameters'=>$parameters);
    } else {
        throw new InvalidArgumentException('Неизвестное действие.');
    }
    echo \Bitrix\Main\Web\Json::encode(array('ok'=>true, 'data'=>$data));
} catch (Exception $e) {
    while (ob_get_level() > $bufferLevel) {
        ob_end_clean();
    }
    echo \Bitrix\Main\Web\Json::encode(array('ok'=>false, 'error'=>$e->getMessage()));
}
require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_after.php';
