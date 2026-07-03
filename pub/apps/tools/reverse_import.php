<?php
/**
 * Импорт событий СКУД Reverse из CSV в HL-блок событий (ID=3).
 *
 * URL: /pub/apps/tools/reverse_import.php
 * Формат CSV: Дата/Время;Место;Код раздела;Событие;Пользователь/карта;Подразделение;Должность
 */

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime as BitrixDateTime;

const REVERSE_EVENTS_HL_BLOCK_ID = 3;
const REVERSE_TURNSTILES_HL_BLOCK_ID = 6;
const REVERSE_USERS_HL_BLOCK_ID = 100;
const REVERSE_SOURCE = 'Reverse';
const REVERSE_TYPE_ENUM_ID = 1721;
const REVERSE_EVENT_IN_ENUM_ID = 45;
const REVERSE_EVENT_OUT_ENUM_ID = 46;

header('Content-Type: text/html; charset=UTF-8');

global $USER;
if (!is_object($USER) || !$USER->IsAdmin()) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><p>Доступ запрещен.</p>';
    exit;
}

if (!Loader::includeModule('main') || !Loader::includeModule('highloadblock')) {
    echo '<!doctype html><meta charset="utf-8"><p>Ошибка: не удалось подключить обязательные модули Bitrix.</p>';
    exit;
}

function reverseImportHtml(string $value): string
{
    return htmlspecialcharsbx($value);
}

function reverseImportToUtf8(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
    if ($value !== '' && function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8')) {
        $converted = @mb_convert_encoding($value, 'UTF-8', 'Windows-1251');
        if (is_string($converted)) {
            $value = $converted;
        }
    }

    return $value;
}

function reverseImportNormalize(string $value): string
{
    $value = reverseImportToUtf8($value);
    $value = preg_replace('/\x{FEFF}/u', '', $value);
    $value = str_replace(["\xc2\xa0", 'ё', 'Ё'], [' ', 'е', 'Е'], $value);
    $value = trim(preg_replace('/\s+/u', ' ', $value));

    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function reverseImportGetDataClass(int $hlBlockId): string
{
    $hlBlock = HighloadBlockTable::getById($hlBlockId)->fetch();
    if (!$hlBlock) {
        throw new RuntimeException('HL-блок с ID=' . $hlBlockId . ' не найден.');
    }

    return HighloadBlockTable::compileEntity($hlBlock)->getDataClass();
}

function reverseImportBuildUsersMap(string $usersClass): array
{
    $users = [];
    $rs = $usersClass::getList([
        'select' => ['ID', 'UF_SOURCE', 'UF_EXT_ID', 'UF_LAST_NAME', 'UF_FIRST_NAME', 'UF_SECOND_NAME'],
        'filter' => ['=UF_SOURCE' => REVERSE_SOURCE],
    ]);

    while ($row = $rs->fetch()) {
        $fio = trim((string)$row['UF_LAST_NAME'] . ' ' . (string)$row['UF_FIRST_NAME'] . ' ' . (string)$row['UF_SECOND_NAME']);
        $key = reverseImportNormalize($fio);
        if ($key !== '' && !isset($users[$key])) {
            $users[$key] = (string)$row['UF_EXT_ID'];
        }
    }

    return $users;
}

function reverseImportBuildTurnstilesMap(string $turnstilesClass): array
{
    $turnstiles = [];
    $rs = $turnstilesClass::getList([
        'select' => ['ID', 'UF_NAME'],
    ]);

    while ($row = $rs->fetch()) {
        $name = trim((string)$row['UF_NAME']);
        $key = reverseImportNormalize($name);
        if ($key !== '' && !isset($turnstiles[$key])) {
            $turnstiles[$key] = (int)$row['ID'];
        }
    }

    return $turnstiles;
}

function reverseImportDetectEvent(string $turnstileName): int
{
    $normalized = reverseImportNormalize($turnstileName);
    if (preg_match('/(^|\s|[-№])вход($|\s|[-№])/u', $normalized) || strpos($normalized, ' вход') !== false) {
        return REVERSE_EVENT_IN_ENUM_ID;
    }
    if (preg_match('/(^|\s|[-№])выход($|\s|[-№])/u', $normalized) || strpos($normalized, ' выход') !== false) {
        return REVERSE_EVENT_OUT_ENUM_ID;
    }

    return 0;
}

function reverseImportParseDateTime(string $value): ?BitrixDateTime
{
    $value = trim($value);
    $date = DateTime::createFromFormat('d.m.Y H:i:s', $value);
    if (!$date || $date->format('d.m.Y H:i:s') !== $value) {
        return null;
    }

    return BitrixDateTime::createFromPhp($date);
}

function reverseImportHasEvent(string $eventsClass, BitrixDateTime $dateTime, string $reverseId): bool
{
    $row = $eventsClass::getList([
        'select' => ['ID'],
        'filter' => [
            '=UF_DATETIME' => $dateTime,
            '=UF_IDREVERS' => $reverseId,
        ],
        'limit' => 1,
    ])->fetch();

    return (bool)$row;
}

function reverseImportReadCsvRows(string $filePath): array
{
    $handle = fopen($filePath, 'rb');
    if (!$handle) {
        throw new RuntimeException('Не удалось открыть CSV-файл.');
    }

    $rows = [];
    while (($row = fgetcsv($handle, 0, ';')) !== false) {
        if (count($row) === 1 && trim((string)$row[0]) === '') {
            continue;
        }
        $rows[] = array_map(static fn($value): string => trim(reverseImportToUtf8((string)$value)), $row);
    }
    fclose($handle);

    return $rows;
}

$messages = [];
$errors = [];
$stats = [
    'total' => 0,
    'added' => 0,
    'duplicates' => 0,
    'skipped' => 0,
];
$details = [];
$isDryRun = isset($_POST['dry_run']) && $_POST['dry_run'] === 'Y';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!check_bitrix_sessid()) {
            throw new RuntimeException('Сессия истекла, обновите страницу и повторите импорт.');
        }

        if (empty($_FILES['csv_file']['tmp_name']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            throw new RuntimeException('Загрузите CSV-файл.');
        }

        $eventsClass = reverseImportGetDataClass(REVERSE_EVENTS_HL_BLOCK_ID);
        $usersClass = reverseImportGetDataClass(REVERSE_USERS_HL_BLOCK_ID);
        $turnstilesClass = reverseImportGetDataClass(REVERSE_TURNSTILES_HL_BLOCK_ID);
        $usersMap = reverseImportBuildUsersMap($usersClass);
        $turnstilesMap = reverseImportBuildTurnstilesMap($turnstilesClass);
        $rows = reverseImportReadCsvRows($_FILES['csv_file']['tmp_name']);

        foreach ($rows as $lineNumber => $row) {
            $csvLineNumber = $lineNumber + 1;
            if ($csvLineNumber === 1 && reverseImportNormalize($row[0] ?? '') === reverseImportNormalize('Дата/Время')) {
                continue;
            }

            $stats['total']++;
            $dateTimeRaw = $row[0] ?? '';
            $turnstileName = $row[1] ?? '';
            $fio = $row[4] ?? '';
            $dateTime = reverseImportParseDateTime($dateTimeRaw);
            $reverseId = $usersMap[reverseImportNormalize($fio)] ?? '';
            $turnstileId = $turnstilesMap[reverseImportNormalize($turnstileName)] ?? 0;
            $eventId = reverseImportDetectEvent($turnstileName);

            if (!$dateTime || $turnstileName === '' || $fio === '' || $reverseId === '' || $turnstileId <= 0 || $eventId <= 0) {
                $stats['skipped']++;
                $details[] = 'Строка ' . $csvLineNumber . ': пропуск — дата, ФИО, турникет или тип события не сопоставлены.';
                continue;
            }

            if (reverseImportHasEvent($eventsClass, $dateTime, $reverseId)) {
                $stats['duplicates']++;
                $details[] = 'Строка ' . $csvLineNumber . ': дубль для ID Reverse ' . $reverseId . ' на ' . $dateTimeRaw . '.';
                continue;
            }

            $fields = [
                'UF_DATETIME' => $dateTime,
                'UF_IDREVERS' => $reverseId,
                'UF_EVENT' => $eventId,
                'UF_TYPE' => REVERSE_TYPE_ENUM_ID,
                'UF_REVERSE_AP' => $turnstileId,
            ];

            if (!$isDryRun) {
                $result = $eventsClass::add($fields);
                if (!$result->isSuccess()) {
                    throw new RuntimeException('Строка ' . $csvLineNumber . ': ошибка добавления — ' . implode('; ', $result->getErrorMessages()));
                }
            }

            $stats['added']++;
        }

        $messages[] = ($isDryRun ? 'Проверка завершена.' : 'Импорт завершен.');
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Импорт событий Reverse</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; color: #222; }
        .box { max-width: 960px; padding: 20px; border: 1px solid #d6d6d6; border-radius: 8px; background: #fff; }
        .msg { padding: 10px 12px; margin: 12px 0; border-radius: 4px; background: #e8f7e8; }
        .err { padding: 10px 12px; margin: 12px 0; border-radius: 4px; background: #fdeaea; color: #8a1f11; }
        .stats { margin: 16px 0; border-collapse: collapse; }
        .stats th, .stats td { border: 1px solid #ddd; padding: 8px 12px; text-align: left; }
        .details { max-height: 360px; overflow: auto; background: #f7f7f7; padding: 12px; white-space: pre-wrap; }
    </style>
</head>
<body>
<div class="box">
    <h1>Импорт событий СКУД Reverse из CSV</h1>
    <p>Файл должен содержать колонки: <strong>Дата/Время;Место;Код раздела;Событие;Пользователь/карта;Подразделение;Должность</strong>.</p>

    <?php foreach ($messages as $message): ?><div class="msg"><?=reverseImportHtml($message)?></div><?php endforeach; ?>
    <?php foreach ($errors as $error): ?><div class="err"><?=reverseImportHtml($error)?></div><?php endforeach; ?>

    <form method="post" enctype="multipart/form-data">
        <?=bitrix_sessid_post()?>
        <p><input type="file" name="csv_file" accept=".csv,text/csv,text/plain" required></p>
        <p><label><input type="checkbox" name="dry_run" value="Y" checked> Только проверить, не добавлять записи</label></p>
        <p><button type="submit">Запустить импорт</button></p>
    </form>

    <?php if ($stats['total'] > 0): ?>
        <table class="stats">
            <tr><th>Всего строк</th><td><?=$stats['total']?></td></tr>
            <tr><th><?=$isDryRun ? 'Готово к добавлению' : 'Добавлено'?></th><td><?=$stats['added']?></td></tr>
            <tr><th>Дубли</th><td><?=$stats['duplicates']?></td></tr>
            <tr><th>Пропущено</th><td><?=$stats['skipped']?></td></tr>
        </table>
    <?php endif; ?>

    <?php if ($details): ?>
        <h2>Детали</h2>
        <div class="details"><?=reverseImportHtml(implode("\n", $details))?></div>
    <?php endif; ?>
</div>
</body>
</html>
