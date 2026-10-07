<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) { die(); }
require_once __DIR__.'/catalog.php';
$return = array();
foreach (TricolorNotificationCatalog::outputs() as $key => $label) {
    $return[$key] = array('NAME'=>$label, 'TYPE'=>'string');
}
$arActivityDescription = array(
    'NAME'=>'Получение текстов уведомлений',
    'DESCRIPTION'=>'Получает шаблон из инфоблока и подставляет параметры текущего бизнес-процесса.',
    'TYPE'=>'activity',
    'CLASS'=>'GetNotificationTextsActivity',
    'JSCLASS'=>'BizProcActivity',
    'CATEGORY'=>array('ID'=>'other'),
    'RETURN'=>$return,
);
