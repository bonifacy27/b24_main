<?php
/**
 * set_list42_xml_id.php
 *
 * Безопасно заполняет XML_ID элемента списка 42.
 *
 * Примеры:
 *   php -f set_list42_xml_id.php -- --id=3619782 --xml-id=3619782
 *   php -f set_list42_xml_id.php -- --id=3619782 --xml-id=3619782 --run
 *   /set_list42_xml_id.php?id=3619782&xml_id=3619782&run=Y
 */

declare(strict_types=1);

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('NOT_CHECK_PERMISSIONS', true);

if (PHP_SAPI === 'cli' && empty($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = __DIR__;
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use Bitrix\Main\Loader;

const SET_XML_ID_IBLOCK_ID = 42;
const DEFAULT_XML_ID_ELEMENT_ID = 3619782;

function xmlIdOption(string $name, ?string $default = null): ?string
{
    if (PHP_SAPI === 'cli') {
        foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
            if ($arg === '--' . $name) {
                return 'Y';
            }
            if (strpos($arg, '--' . $name . '=') === 0) {
                return substr($arg, strlen($name) + 3);
            }
        }
        return $default;
    }

    $httpName = str_replace('-', '_', $name);
    return isset($_REQUEST[$httpName]) ? (string)$_REQUEST[$httpName] : $default;
}

function xmlIdOut(string $message = ''): void
{
    echo $message . PHP_EOL;
}

function xmlIdIsRun(): bool
{
    return in_array(strtoupper((string)xmlIdOption('run', 'N')), ['Y', 'YES', '1', 'TRUE'], true);
}

header('Content-Type: text/plain; charset=UTF-8');

if (!Loader::includeModule('iblock')) {
    xmlIdOut('Ошибка: не удалось подключить модуль iblock.');
    exit(1);
}

$elementId = max(1, (int)xmlIdOption('id', (string)DEFAULT_XML_ID_ELEMENT_ID));
$xmlId = trim((string)(xmlIdOption('xml-id', null) ?? xmlIdOption('xml_id', (string)$elementId)));
$run = xmlIdIsRun();

if ($xmlId === '') {
    xmlIdOut('Ошибка: XML_ID не должен быть пустым.');
    exit(1);
}

$element = \CIBlockElement::GetList(
    [],
    ['IBLOCK_ID' => SET_XML_ID_IBLOCK_ID, 'ID' => $elementId],
    false,
    false,
    ['ID', 'IBLOCK_ID', 'NAME', 'XML_ID']
)->Fetch();

if (!$element) {
    xmlIdOut('Ошибка: элемент ' . $elementId . ' не найден в списке ' . SET_XML_ID_IBLOCK_ID . '.');
    exit(1);
}

xmlIdOut('Элемент: ' . $element['ID'] . ' — ' . $element['NAME']);
xmlIdOut('Текущий XML_ID: ' . (string)$element['XML_ID']);
xmlIdOut('Новый XML_ID: ' . $xmlId);

if ((string)$element['XML_ID'] === $xmlId) {
    xmlIdOut('Изменения не требуются: XML_ID уже совпадает.');
    exit(0);
}

if (!$run) {
    xmlIdOut('DRY-RUN: XML_ID не изменен. Для записи добавьте --run или run=Y.');
    exit(0);
}

$iblockElement = new \CIBlockElement();
if (!$iblockElement->Update($elementId, ['XML_ID' => $xmlId])) {
    xmlIdOut('Ошибка обновления XML_ID: ' . (string)$iblockElement->LAST_ERROR);
    exit(1);
}

xmlIdOut('OK: XML_ID обновлен.');
