<?php
/**
 * CLI-скрипт: находит текущее задание БП по элементу списка и выполняет его с результатом Approve
 * от имени пользователя, у которого сейчас находится задание.
 *
 * Пример:
 *   php test_bp_approve.php --iblock=391 --element=3586572
 *   php test_bp_approve.php --url="https://ourtricolortv.nsc.ru/workgroups/group/87/lists/391/element/0/3586572/?list_section_id="
 */

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('NOT_CHECK_PERMISSIONS', true);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Ошибка: скрипт можно запускать только из CLI.\n");
    exit(1);
}

$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?: '/home/bitrix/www';

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;

if (!Loader::includeModule('bizproc') || !Loader::includeModule('iblock')) {
    fwrite(STDERR, "Ошибка: не удалось подключить модули bizproc/iblock.\n");
    exit(1);
}

function cliOut(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function cliErr(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
}

function parseArgs(array $argv): array
{
    $result = [
        'iblock' => 0,
        'element' => 0,
        'url' => '',
        'comment' => '',
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if (strpos($arg, '--iblock=') === 0) {
            $result['iblock'] = (int)substr($arg, 9);
            continue;
        }

        if (strpos($arg, '--element=') === 0) {
            $result['element'] = (int)substr($arg, 10);
            continue;
        }

        if (strpos($arg, '--url=') === 0) {
            $result['url'] = trim((string)substr($arg, 6));
            continue;
        }

        if (strpos($arg, '--comment=') === 0) {
            $result['comment'] = trim((string)substr($arg, 10));
            continue;
        }
    }

    return $result;
}

function parseIblockAndElementFromUrl(string $url): array
{
    $url = trim($url);
    if ($url === '') {
        return [0, 0];
    }

    $path = (string)parse_url($url, PHP_URL_PATH);
    if ($path === '') {
        return [0, 0];
    }

    if (preg_match('#/lists/(\d+)/element/\d+/(\d+)/?#', $path, $m)) {
        return [(int)$m[1], (int)$m[2]];
    }

    return [0, 0];
}

function extractNumericUserId($raw): int
{
    if (is_array($raw)) {
        foreach ($raw as $item) {
            $id = extractNumericUserId($item);
            if ($id > 0) {
                return $id;
            }
        }
        return 0;
    }

    $value = trim((string)$raw);
    if ($value === '') {
        return 0;
    }

    if (preg_match('/(\d+)/', $value, $m)) {
        return (int)$m[1];
    }

    return 0;
}

function findWaitingTaskByDocument(int $iblockId, int $elementId): ?array
{
    $select = ['ID', 'NAME', 'DOCUMENT_ID', 'WORKFLOW_ID', 'ACTIVITY_NAME', 'ACTIVITY', 'USER_ID', 'USERS', 'PARAMETERS', 'STATUS'];

    $docCandidates = [
        ['lists', 'BizprocDocument', 'lists_' . $iblockId . '_' . $elementId],
        ['iblock', 'CIBlockDocument', 'iblock_' . $iblockId . '_' . $elementId],
        ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', (string)$elementId],
    ];

    foreach ($docCandidates as $docId) {
        $res = CBPTaskService::GetList(
            ['ID' => 'DESC'],
            [
                'DOCUMENT_ID' => $docId,
                'USER_STATUS' => CBPTaskUserStatus::Waiting,
            ],
            false,
            false,
            $select
        );

        while ($task = $res->GetNext()) {
            if ((int)($task['STATUS'] ?? 0) === (int)CBPTaskStatus::Running) {
                return $task;
            }
        }
    }

    return null;
}

function completeTaskApprove(array $task, int $userId, string $comment = ''): array
{
    $taskId = (int)($task['ID'] ?? 0);
    if ($taskId <= 0 || $userId <= 0) {
        return ['OK' => false, 'ERROR' => 'Некорректные taskId/userId'];
    }

    $errors = [];
    $requestFields = [
        'USER_ID' => $userId,
        'REAL_USER_ID' => $userId,
        'approve' => 'Y',
        'ACTION' => 'approve',
        'COMMENT' => $comment,
        'task_comment' => $comment,
    ];

    try {
        CBPDocument::PostTaskForm($taskId, $userId, $requestFields, $errors, '', $userId);
    } catch (\Throwable $e) {
        $errors[] = ['message' => $e->getMessage()];
    }

    $res = CBPTaskService::GetList(['ID' => 'DESC'], ['ID' => $taskId], false, false, ['ID', 'STATUS']);
    $row = is_object($res) ? $res->GetNext() : null;
    $isClosed = !$row || (int)($row['STATUS'] ?? 0) !== (int)CBPTaskStatus::Running;

    if ($isClosed) {
        return ['OK' => true, 'ERROR' => ''];
    }

    $messages = [];
    foreach ($errors as $error) {
        $msg = trim((string)($error['message'] ?? $error['MESSAGE'] ?? $error));
        if ($msg !== '') {
            $messages[] = $msg;
        }
    }

    return ['OK' => false, 'ERROR' => $messages ? implode('; ', array_unique($messages)) : 'Задание осталось в статусе Running'];
}

$args = parseArgs($argv);
$iblockId = (int)$args['iblock'];
$elementId = (int)$args['element'];

if (($iblockId <= 0 || $elementId <= 0) && $args['url'] !== '') {
    [$parsedIblock, $parsedElement] = parseIblockAndElementFromUrl($args['url']);
    if ($iblockId <= 0) {
        $iblockId = $parsedIblock;
    }
    if ($elementId <= 0) {
        $elementId = $parsedElement;
    }
}

if ($iblockId <= 0 || $elementId <= 0) {
    cliErr('Использование: php test_bp_approve.php --iblock=391 --element=3586572 [--comment="..."]');
    cliErr('Или:         php test_bp_approve.php --url="https://.../lists/391/element/0/3586572/"');
    exit(1);
}

cliOut("Поиск задания БП для элемента {$elementId} (IBLOCK {$iblockId})...");
$task = findWaitingTaskByDocument($iblockId, $elementId);

if (!$task) {
    cliErr('Активное задание (Waiting/Running) не найдено.');
    exit(2);
}

$userId = extractNumericUserId($task['USER_ID'] ?? 0);
if ($userId <= 0) {
    $userId = extractNumericUserId($task['USERS'] ?? 0);
}

if ($userId <= 0) {
    cliErr('Не удалось определить пользователя-исполнителя задания.');
    exit(3);
}

cliOut('Найдено задание:');
cliOut('  TASK_ID: ' . (int)$task['ID']);
cliOut('  NAME: ' . (string)($task['NAME'] ?? ''));
cliOut('  ACTIVITY: ' . (string)($task['ACTIVITY_NAME'] ?? $task['ACTIVITY'] ?? ''));
cliOut('  USER_ID: ' . $userId);

$result = completeTaskApprove($task, $userId, (string)$args['comment']);
if (!$result['OK']) {
    cliErr('Ошибка завершения задания: ' . (string)$result['ERROR']);
    exit(4);
}

cliOut('OK: задание успешно выполнено с результатом Approve.');
exit(0);
