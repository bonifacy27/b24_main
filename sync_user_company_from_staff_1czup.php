<?php
/**
 * Синхронизация пользовательского поля UF_COMPANY (Компания) активных пользователей
 * по источнику сотрудника из GateDB.dbo.Staff_1CZUP.
 *
 * Логика:
 * - берём всех активных пользователей портала;
 * - по UF_1C_GUID ищем Staff_1CZUP.Staff_ID;
 * - по Staff_1CZUP.Source назначаем значение списка UF_COMPANY:
 *   - Srvr=srv-off-1c01;Ref=1c_Pay83_NSC; => НСК [26]
 *   - Srvr=srv-off-1c01;Ref=1c_Pay83_TM;  => УК ТМ [1723]
 * - если Source не найден/не распознан, определяем UF_COMPANY по e-mail:
 *   - содержит tricolor.ru    => НСК [26]
 *   - содержит tricolormedia  => УК ТМ [1723]
 *   - содержит monobrand      => ТТ [27]
 *   - иначе                  => Иное [29]
 *
 * Запуск из CLI:
 *   php -f sync_user_company_from_staff_1czup.php -- --dry-run
 *   php -f sync_user_company_from_staff_1czup.php -- --run
 *
 * Запуск из браузера:
 *   /sync_user_company_from_staff_1czup.php?dry_run=Y
 *   /sync_user_company_from_staff_1czup.php?run=Y
 */

$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__);
$DOCUMENT_ROOT = $_SERVER['DOCUMENT_ROOT'];

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('NOT_CHECK_PERMISSIONS', true);

require_once $DOCUMENT_ROOT . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Application;

const STAFF_SOURCE_NSC = 'Srvr=srv-off-1c01;Ref=1c_Pay83_NSC;';
const STAFF_SOURCE_TM = 'Srvr=srv-off-1c01;Ref=1c_Pay83_TM;';
const USER_COMPANY_NSC_ID = 26;
const USER_COMPANY_TM_ID = 1723;
const USER_COMPANY_TT_ID = 27;
const USER_COMPANY_OTHER_ID = 29;

$sourceToCompany = [
    STAFF_SOURCE_NSC => USER_COMPANY_NSC_ID,
    STAFF_SOURCE_TM => USER_COMPANY_TM_ID,
];

function scuIsCli(): bool
{
    return PHP_SAPI === 'cli';
}

function scuHasCliOption(string $option): bool
{
    global $argv;

    return in_array($option, is_array($argv) ? $argv : [], true);
}

function scuIsDryRun(): bool
{
    if (scuIsCli()) {
        return !scuHasCliOption('--run');
    }

    return !isset($_GET['run']) || (string)$_GET['run'] !== 'Y';
}

function scuPrintLine(string $message): void
{
    if (scuIsCli()) {
        echo $message . PHP_EOL;
        return;
    }

    echo htmlspecialcharsbx($message) . '<br>' . PHP_EOL;
}

function scuNormalizeScalar($value): string
{
    if ($value === null) {
        return '';
    }

    return is_scalar($value) ? trim((string)$value) : '';
}

function scuGetCompanyByEmail(string $email): int
{
    $email = strtolower(trim($email));

    if (strpos($email, 'tricolor.ru') !== false) {
        return USER_COMPANY_NSC_ID;
    }

    if (strpos($email, 'tricolormedia') !== false) {
        return USER_COMPANY_TM_ID;
    }

    if (strpos($email, 'monobrand') !== false) {
        return USER_COMPANY_TT_ID;
    }

    return USER_COMPANY_OTHER_ID;
}

function scuGetStaffSourceByGuid(\Bitrix\Main\DB\Connection $connection, string $guid): string
{
    $guid = trim($guid);
    if ($guid === '') {
        return '';
    }

    $helper = $connection->getSqlHelper();
    $escapedGuid = $helper->forSql($guid);

    $sql = "SELECT TOP (1) Source " .
        "FROM GateDB.dbo.Staff_1CZUP " .
        "WHERE Staff_ID = '" . $escapedGuid . "' " .
        "ORDER BY Staff_ID";

    $record = $connection->query($sql)->fetch();
    if (!$record || !array_key_exists('Source', $record)) {
        return '';
    }

    return scuNormalizeScalar($record['Source']);
}

$isDryRun = scuIsDryRun();
$connection = Application::getConnection('gatedb');
$userUpdater = new \CUser();

$processed = 0;
$updated = 0;
$wouldUpdate = 0;
$alreadyActual = 0;
$withoutGuid = 0;
$withoutStaffRow = 0;
$unknownSource = 0;
$resolvedBySource = 0;
$resolvedByEmail = 0;
$errors = 0;

if (!scuIsCli()) {
    echo '<pre style="white-space:pre-wrap">';
}

scuPrintLine('Start UF_COMPANY sync from GateDB.dbo.Staff_1CZUP. Mode=' . ($isDryRun ? 'dry-run' : 'run'));

$by = 'id';
$order = 'asc';
$userRows = \CUser::GetList(
    $by,
    $order,
    ['ACTIVE' => 'Y'],
    [
        'FIELDS' => ['ID', 'LOGIN', 'EMAIL', 'NAME', 'LAST_NAME', 'SECOND_NAME'],
        'SELECT' => ['UF_1C_GUID', 'UF_COMPANY'],
    ]
);

while ($user = $userRows->Fetch()) {
    $processed++;

    $userId = (int)$user['ID'];
    $guid = scuNormalizeScalar($user['UF_1C_GUID'] ?? '');
    $email = scuNormalizeScalar($user['EMAIL'] ?? '');
    $currentCompany = (int)scuNormalizeScalar($user['UF_COMPANY'] ?? 0);
    $source = '';
    $targetCompany = 0;
    $resolveReason = '';

    if ($guid === '') {
        $withoutGuid++;
    } else {
        $source = scuGetStaffSourceByGuid($connection, $guid);
        if ($source === '') {
            $withoutStaffRow++;
        } elseif (isset($sourceToCompany[$source])) {
            $targetCompany = (int)$sourceToCompany[$source];
            $resolvedBySource++;
            $resolveReason = 'source';
        } else {
            $unknownSource++;
        }
    }

    if ($targetCompany <= 0) {
        $targetCompany = scuGetCompanyByEmail($email);
        $resolvedByEmail++;
        $resolveReason = 'email';
    }
    if ($currentCompany === $targetCompany) {
        $alreadyActual++;
        continue;
    }

    if ($isDryRun) {
        $wouldUpdate++;
        scuPrintLine(sprintf(
            'DRY-RUN user=%d login=%s email=%s guid=%s UF_COMPANY: %d -> %d reason=%s source=%s',
            $userId,
            scuNormalizeScalar($user['LOGIN'] ?? ''),
            $email,
            $guid,
            $currentCompany,
            $targetCompany,
            $resolveReason,
            $source
        ));
        continue;
    }

    if ($userUpdater->Update($userId, ['UF_COMPANY' => $targetCompany])) {
        $updated++;
        scuPrintLine(sprintf(
            'UPDATED user=%d login=%s email=%s guid=%s UF_COMPANY: %d -> %d reason=%s source=%s',
            $userId,
            scuNormalizeScalar($user['LOGIN'] ?? ''),
            $email,
            $guid,
            $currentCompany,
            $targetCompany,
            $resolveReason,
            $source
        ));
    } else {
        $errors++;
        scuPrintLine(sprintf(
            'ERROR user=%d login=%s email=%s guid=%s target=%d reason=%s: %s',
            $userId,
            scuNormalizeScalar($user['LOGIN'] ?? ''),
            $email,
            $guid,
            $targetCompany,
            $resolveReason,
            trim((string)$userUpdater->LAST_ERROR)
        ));
    }
}

scuPrintLine('Summary:');
scuPrintLine('processed=' . $processed);
scuPrintLine('updated=' . $updated);
scuPrintLine('would_update=' . $wouldUpdate);
scuPrintLine('already_actual=' . $alreadyActual);
scuPrintLine('without_guid=' . $withoutGuid);
scuPrintLine('without_staff_row_or_source=' . $withoutStaffRow);
scuPrintLine('unknown_source=' . $unknownSource);
scuPrintLine('resolved_by_source=' . $resolvedBySource);
scuPrintLine('resolved_by_email=' . $resolvedByEmail);
scuPrintLine('errors=' . $errors);
scuPrintLine('Finish UF_COMPANY sync.');

if (!scuIsCli()) {
    echo '</pre>';
}

if ($errors > 0) {
    exit(1);
}
