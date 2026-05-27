<?php
/**
 * Ежедневная синхронизация пользователей Reverse/Reverse2 в HL-блок TrsUsersReverse (ID=100).
 *
 * Запуск (cron):
 * 0 2 * * * /usr/bin/php -f /path/to/trs_skud_users_reverse_cron_sync.php
 */

$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__);
$DOCUMENT_ROOT = $_SERVER['DOCUMENT_ROOT'];

require_once $DOCUMENT_ROOT . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Bitrix\Highloadblock\HighloadBlockTable;
use TRS\Reverse;
use TRS\Reverse2;

if (!Loader::includeModule('highloadblock')) {
    throw new \RuntimeException('Не удалось подключить модуль highloadblock');
}

if (!Loader::includeModule('tricolor.trs')) {
    throw new \RuntimeException('Не удалось подключить модуль tricolor.trs');
}

const TRS_USERS_REVERSE_HL_ID = 100;

/**
 * Получение списка пользователей из СКУД.
 */
function trsGetSkudUsersForSync($skudObj, string $source): array
{
    $users = [];

    try {
        $skudObj->openSocket();
        $response = $skudObj->getUserList();
        $skudObj->closeSocket();

        if (is_array($response) && isset($response['Data']) && is_array($response['Data'])) {
            foreach ($response['Data'] as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $row['_SOURCE'] = $source;
                $users[] = $row;
            }
        }
    } catch (\Throwable $e) {
        try {
            $skudObj->closeSocket();
        } catch (\Throwable $e2) {
            // noop
        }

        throw $e;
    }

    return $users;
}

function trsValue(array $row, string $key): string
{
    if (!array_key_exists($key, $row)) {
        return '';
    }

    $value = $row[$key];
    if ($value === null) {
        return '';
    }

    return is_scalar($value) ? (string)$value : '';
}

$hlBlock = HighloadBlockTable::getById(TRS_USERS_REVERSE_HL_ID)->fetch();
if (!$hlBlock) {
    throw new \RuntimeException('HL-блок с ID=100 не найден');
}

$entity = HighloadBlockTable::compileEntity($hlBlock);
$dataClass = $entity->getDataClass();

$allUsers = array_merge(
    trsGetSkudUsersForSync(new Reverse(), 'Reverse'),
    trsGetSkudUsersForSync(new Reverse2(), 'Reverse2')
);

$existing = [];
$rs = $dataClass::getList([
    'select' => ['ID', 'UF_SOURCE', 'UF_EXT_ID'],
]);
while ($item = $rs->fetch()) {
    $key = (string)$item['UF_SOURCE'] . '|' . (string)$item['UF_EXT_ID'];
    if ($key === '|') {
        continue;
    }
    $existing[$key] = (int)$item['ID'];
}

$added = 0;
$updated = 0;
$skipped = 0;

foreach ($allUsers as $user) {
    $source = trsValue($user, '_SOURCE');
    $extId = trsValue($user, 'Id');

    if ($source === '' || $extId === '') {
        $skipped++;
        continue;
    }

    $fields = [
        'UF_SOURCE' => $source,
        'UF_EXT_ID' => $extId,
        'UF_LAST_NAME' => trsValue($user, 'LastName'),
        'UF_FIRST_NAME' => trsValue($user, 'FirstName'),
        'UF_SECOND_NAME' => trsValue($user, 'SecondName'),
        'UF_ARCHIVE' => trsValue($user, 'Archive'),
        'UF_DEP_ID' => trsValue($user, 'DepId'),
        'UF_GUID' => trsValue($user, 'GUID'),
        'UF_JOB_ID' => trsValue($user, 'JobId'),
        'UF_TAB_NUM' => trsValue($user, 'TabNum'),
        'UF_TYPE' => trsValue($user, 'Type'),
    ];

    $rowKey = $source . '|' . $extId;

    if (isset($existing[$rowKey])) {
        $result = $dataClass::update($existing[$rowKey], $fields);
        if (!$result->isSuccess()) {
            throw new \RuntimeException('Ошибка обновления записи ' . $rowKey . ': ' . implode('; ', $result->getErrorMessages()));
        }
        $updated++;
    } else {
        $result = $dataClass::add($fields);
        if (!$result->isSuccess()) {
            throw new \RuntimeException('Ошибка добавления записи ' . $rowKey . ': ' . implode('; ', $result->getErrorMessages()));
        }
        $added++;
    }
}

echo sprintf(
    "Sync completed. Total=%d, added=%d, updated=%d, skipped=%d\n",
    count($allUsers),
    $added,
    $updated,
    $skipped
);
