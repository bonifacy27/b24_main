<?php
/**
 * CLI-скрипт: находит текущее задание БП по элементу списка и выполняет его с результатом Approve
 * от имени пользователя, у которого сейчас находится задание.
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

function cliOut(string $message): void { fwrite(STDOUT, $message . PHP_EOL); }
function cliErr(string $message): void { fwrite(STDERR, $message . PHP_EOL); }

function parseArgs(array $argv): array
{
    $result = ['iblock' => 0, 'element' => 0, 'url' => '', 'comment' => '', 'as_admin' => false, 'actor_user' => 0];
    foreach (array_slice($argv, 1) as $arg) {
        if (strpos($arg, '--iblock=') === 0) { $result['iblock'] = (int)substr($arg, 9); continue; }
        if (strpos($arg, '--element=') === 0) { $result['element'] = (int)substr($arg, 10); continue; }
        if (strpos($arg, '--url=') === 0) { $result['url'] = trim((string)substr($arg, 6)); continue; }
        if (strpos($arg, '--comment=') === 0) { $result['comment'] = trim((string)substr($arg, 10)); continue; }
        if ($arg === '--as-admin' || $arg === '--admin') { $result['as_admin'] = true; continue; }
        if (strpos($arg, '--actor-user=') === 0) { $result['actor_user'] = (int)substr($arg, 13); continue; }
    }
    return $result;
}

function parseIblockAndElementFromUrl(string $url): array
{
    $path = (string)parse_url(trim($url), PHP_URL_PATH);
    if ($path !== '' && preg_match('#/lists/(\d+)/element/\d+/(\d+)/?#', $path, $m)) {
        return [(int)$m[1], (int)$m[2]];
    }
    return [0, 0];
}

function extractNumericUserId($raw): int
{
    if (is_array($raw)) {
        foreach ($raw as $item) {
            $id = extractNumericUserId($item);
            if ($id > 0) { return $id; }
        }
        return 0;
    }
    $value = trim((string)$raw);
    return preg_match('/(\d+)/', $value, $m) ? (int)$m[1] : 0;
}

function findWaitingTaskByDocument(int $iblockId, int $elementId): ?array
{
    $select = ['ID', 'NAME', 'DOCUMENT_ID', 'WORKFLOW_ID', 'ACTIVITY_NAME', 'ACTIVITY', 'USER_ID', 'USERS', 'PARAMETERS', 'STATUS', 'CREATED_BY', 'MODIFIED_BY', 'OVERDUE_DATE'];
    $docCandidates = [
        ['lists', 'BizprocDocument', 'lists_' . $iblockId . '_' . $elementId],
        ['iblock', 'CIBlockDocument', 'iblock_' . $iblockId . '_' . $elementId],
        ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', (string)$elementId],
    ];

    foreach ($docCandidates as $docId) {
        $res = CBPTaskService::GetList(['ID' => 'DESC'], ['DOCUMENT_ID' => $docId, 'USER_STATUS' => CBPTaskUserStatus::Waiting], false, false, $select);
        while ($task = $res->GetNext()) {
            if ((int)($task['STATUS'] ?? 0) === (int)CBPTaskStatus::Running) { return $task; }
        }
    }
    return null;
}


function collectUserCandidates(array $task): array
{
    $candidates = [];
    foreach (['USER_ID', 'CREATED_BY', 'MODIFIED_BY'] as $key) {
        $id = extractNumericUserId($task[$key] ?? 0);
        if ($id > 0) { $candidates[] = $id; }
    }

    $users = $task['USERS'] ?? [];
    if (!is_array($users)) {
        $users = preg_split('/[,\s;|]+/u', (string)$users) ?: [];
    }
    foreach ($users as $u) {
        $id = extractNumericUserId($u);
        if ($id > 0) { $candidates[] = $id; }
    }

    $paramsRaw = $task['PARAMETERS'] ?? [];
    $params = is_array($paramsRaw) ? $paramsRaw : @unserialize((string)$paramsRaw, ['allowed_classes' => false]);
    if (!is_array($params)) { $params = []; }
    $walker = static function ($node, $key = '') use (&$walker, &$candidates) {
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                $walker($v, is_string($k) ? $k : (string)$k);
            }
            return;
        }

        if (!is_scalar($node)) { return; }
        $str = trim((string)$node);
        if (preg_match('/^user_(\d+)$/i', $str, $m)) {
            $id = (int)$m[1];
            if ($id > 0) { $candidates[] = $id; }
            return;
        }

        if ($key !== '' && preg_match('/user|respons|approv|delegat|actor|owner|author|creator/i', $key) && preg_match('/^(\d+)$/', $str, $m)) {
            $id = (int)$m[1];
            if ($id > 0 && $id < 1000000) { $candidates[] = $id; }
        }
    };
    $walker($params);

    return array_values(array_unique(array_filter(array_map('intval', $candidates))));
}

function taskIsRunning(int $taskId): bool
{
    $res = CBPTaskService::GetList(['ID' => 'DESC'], ['ID' => $taskId], false, false, ['ID', 'STATUS']);
    $task = is_object($res) ? $res->GetNext() : null;
    return $task && (int)($task['STATUS'] ?? 0) === (int)CBPTaskStatus::Running;
}

function flattenErrors(array $errors): string
{
    $messages = [];
    foreach ($errors as $error) {
        $message = is_array($error) ? (string)($error['message'] ?? $error['MESSAGE'] ?? '') : (string)$error;
        $message = trim($message);
        if ($message !== '') { $messages[] = $message; }
    }
    return implode('; ', array_unique($messages));
}

function getTaskControls(int $taskId): array
{
    try {
        if (method_exists('CBPDocument', 'GetTaskControls')) {
            $controls = (array)CBPDocument::GetTaskControls($taskId);
            if (!empty($controls)) { return $controls; }
        }
    } catch (\Throwable $e) {}

    try {
        if (method_exists('CBPTaskService', 'GetTaskControls')) {
            return (array)CBPTaskService::GetTaskControls($taskId);
        }
    } catch (\Throwable $e) {}

    return [];
}

function getApproveActionCodes(int $taskId): array
{
    $codes = ['approve'];
    $controls = getTaskControls($taskId);
    foreach ($controls as $code => $controlData) {
        $controlCode = is_string($code) ? trim($code) : '';
        $label = '';
        if (is_array($controlData)) {
            $label = trim((string)($controlData['TEXT'] ?? $controlData['LABEL'] ?? $controlData['NAME'] ?? ''));
            if ($controlCode === '') { $controlCode = trim((string)($controlData['NAME'] ?? $controlData['ID'] ?? '')); }
        } elseif (is_string($controlData)) {
            $label = trim($controlData);
        }

        $haystack = mb_strtolower($controlCode . ' ' . $label, 'UTF-8');
        if ($controlCode !== '' && preg_match('/\b(approve|agree|accept|ok|yes|y|соглас)/u', $haystack)) {
            $codes[] = $controlCode;
        }
    }
    return array_values(array_unique($codes));
}

function completeTaskApprove(array $task, int $primaryUserId, string $comment = '', bool $asAdmin = false, int $forcedActorUserId = 0): array
{
    $taskId = (int)($task['ID'] ?? 0);
    if ($taskId <= 0 || $primaryUserId <= 0) {
        return ['OK' => false, 'ERROR' => 'Некорректные taskId/userId'];
    }

    $errors = [];
    $approveCodes = getApproveActionCodes($taskId);
    $userCandidates = collectUserCandidates($task);
    array_unshift($userCandidates, $primaryUserId);
    $userCandidates = array_values(array_unique(array_filter(array_map('intval', $userCandidates))));
    if ($forcedActorUserId > 0) {
        array_unshift($userCandidates, $forcedActorUserId);
        $userCandidates = array_values(array_unique(array_filter(array_map('intval', $userCandidates))));
    }
    if ($asAdmin) {
        array_unshift($userCandidates, 1);
        $userCandidates = array_values(array_unique(array_filter(array_map('intval', $userCandidates))));
    }

    foreach ($userCandidates as $actorUserId) {
        foreach ($approveCodes as $approveCode) {
            $requestFields = [
                'USER_ID' => $actorUserId,
                'REAL_USER_ID' => $actorUserId,
                'COMMENT' => $comment,
                'task_comment' => $comment,
                'ACTION' => $approveCode,
                $approveCode => 'Y',
            ];
            if ($approveCode !== 'approve') {
                $requestFields['approve'] = 'Y';
            }

            try {
                $tmpErr = [];
                CBPDocument::PostTaskForm($taskId, $actorUserId, $requestFields, $tmpErr, '', $actorUserId);
                if (!empty($tmpErr)) { $errors = array_merge($errors, $tmpErr); }
                if (!taskIsRunning($taskId)) { return ['OK' => true, 'ERROR' => '']; }
            } catch (\Throwable $e) {
                $errors[] = ['message' => $e->getMessage()];
            }
        }
    }

    try {
        $workflowId = (string)($task['WORKFLOW_ID'] ?? '');
        $activity = (string)($task['ACTIVITY_NAME'] ?? $task['ACTIVITY'] ?? '');
        if ($workflowId !== '' && $activity !== '' && class_exists('CBPRuntime') && method_exists('CBPRuntime', 'SendExternalEvent')) {
            foreach ($userCandidates as $actorUserId) {
                CBPRuntime::SendExternalEvent($workflowId, $activity, [
                    'USER_ID' => $actorUserId,
                    'REAL_USER_ID' => $actorUserId,
                    'COMMENT' => $comment,
                    'APPROVE' => true,
                ]);
                if (!taskIsRunning($taskId)) { return ['OK' => true, 'ERROR' => '']; }
            }
        }
    } catch (\Throwable $e) {
        $errors[] = ['message' => $e->getMessage()];
    }

    try {
        if (method_exists('CBPTaskService', 'DoTask')) {
            foreach ($approveCodes as $approveCode) {
                foreach ($userCandidates as $actorUserId) {
                    CBPTaskService::DoTask($taskId, $actorUserId, [
                        'ACTION' => $approveCode,
                        $approveCode => 'Y',
                        'COMMENT' => $comment,
                        'task_comment' => $comment,
                    ]);
                    if (!taskIsRunning($taskId)) { return ['OK' => true, 'ERROR' => '']; }
                }
            }
        }
    } catch (\Throwable $e) {
        $errors[] = ['message' => $e->getMessage()];
    }

    $flat = flattenErrors($errors);
    return ['OK' => false, 'ERROR' => $flat !== '' ? $flat : 'Задание осталось в статусе Running'];
}


$args = parseArgs($argv);
$iblockId = (int)$args['iblock'];
$elementId = (int)$args['element'];
if (($iblockId <= 0 || $elementId <= 0) && $args['url'] !== '') {
    [$parsedIblock, $parsedElement] = parseIblockAndElementFromUrl($args['url']);
    if ($iblockId <= 0) { $iblockId = $parsedIblock; }
    if ($elementId <= 0) { $elementId = $parsedElement; }
}

if ($iblockId <= 0 || $elementId <= 0) {
    cliErr('Использование: php test_bp_approve.php --iblock=391 --element=3586572 [--comment="..."] [--as-admin] [--actor-user=3532]');
    cliErr('Или:         php test_bp_approve.php --url="https://.../lists/391/element/0/3586572/"');
    exit(1);
}

cliOut("Поиск задания БП для элемента {$elementId} (IBLOCK {$iblockId})...");
$task = findWaitingTaskByDocument($iblockId, $elementId);
if (!$task) { cliErr('Активное задание (Waiting/Running) не найдено.'); exit(2); }

$userId = extractNumericUserId($task['USER_ID'] ?? 0);
if ($userId <= 0) { $userId = extractNumericUserId($task['USERS'] ?? 0); }
if ($userId <= 0) { cliErr('Не удалось определить пользователя-исполнителя задания.'); exit(3); }

cliOut('Найдено задание:');
cliOut('  TASK_ID: ' . (int)$task['ID']);
cliOut('  NAME: ' . (string)($task['NAME'] ?? ''));
cliOut('  ACTIVITY: ' . (string)($task['ACTIVITY_NAME'] ?? $task['ACTIVITY'] ?? ''));
cliOut('  USER_ID: ' . $userId);
$candidatesForLog = collectUserCandidates($task);
if ((int)$args['actor_user'] > 0) { array_unshift($candidatesForLog, (int)$args['actor_user']); }
if (!empty($args['as_admin'])) { array_unshift($candidatesForLog, 1); }
$candidatesForLog = array_values(array_unique(array_filter(array_map('intval', $candidatesForLog))));
cliOut('  USER_CANDIDATES: ' . implode(', ', $candidatesForLog));

$result = completeTaskApprove($task, $userId, (string)$args['comment'], (bool)$args['as_admin'], (int)$args['actor_user']);
if (!$result['OK']) {
    cliErr('Ошибка завершения задания: ' . (string)$result['ERROR']);
    exit(4);
}

cliOut('OK: задание успешно выполнено с результатом Approve.');
exit(0);
