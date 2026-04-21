<?php
/**
 * Тестовая форма завершения заданий БП для документа списка.
 */

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;

if (!Loader::includeModule('bizproc') || !Loader::includeModule('iblock')) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Ошибка: не удалось подключить модули bizproc/iblock.';
    exit;
}

global $USER;

if (!is_object($USER) || !$USER->IsAuthorized()) {
    header('Content-Type: text/html; charset=UTF-8');
    echo 'Пользователь не авторизован.';
    exit;
}

const TEST_DOC_URL = 'https://ourtricolortv.nsc.ru/workgroups/group/87/lists/391/element/0/3578984/?list_section_id=';
const TEST_IBLOCK_ID = 391;
const TEST_ELEMENT_ID = 3578984;

function h($value): string
{
    return htmlspecialcharsbx((string)$value);
}

function renderFieldValue($value): string
{
    if (is_array($value)) {
        $parts = [];
        foreach ($value as $item) {
            $parts[] = is_scalar($item) ? (string)$item : print_r($item, true);
        }
        return implode(', ', $parts);
    }

    if ($value === null || $value === '') {
        return '—';
    }

    return (string)$value;
}

function getElementData(int $iblockId, int $elementId): ?array
{
    $res = CIBlockElement::GetList([], ['IBLOCK_ID' => $iblockId, 'ID' => $elementId], false, false, ['ID']);
    $item = $res->Fetch();
    if (!$item) {
        return null;
    }

    return [
        'ID' => (int)$item['ID'],
    ];
}

function extractRequestIdFromDocumentId($documentId, int $iblockId): int
{
    if (is_array($documentId)) {
        $documentId = implode('|', $documentId);
    }

    $documentId = trim((string)$documentId);
    if ($documentId === '') {
        return 0;
    }

    if ($iblockId > 0) {
        $patternByIblock = '/(?:^|_)' . preg_quote((string)$iblockId, '/') . '_([0-9]+)$/';
        if (preg_match($patternByIblock, $documentId, $matches)) {
            return (int)$matches[1];
        }
    }

    if (preg_match('/([0-9]+)\D*$/', $documentId, $matches)) {
        return (int)$matches[1];
    }

    return 0;
}

function bizprocTaskIsForUser(array $task, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    $rawUserId = (string)($task['USER_ID'] ?? '');
    $scalarUserId = (int)preg_replace('/\D+/u', '', $rawUserId);
    if ($scalarUserId > 0) {
        return $scalarUserId === $userId;
    }

    $users = $task['USERS'] ?? null;
    if (is_string($users) && $users !== '') {
        $parts = preg_split('/[,\s;|]+/u', $users) ?: [];
        foreach ($parts as $part) {
            $normalized = (int)preg_replace('/\D+/u', '', (string)$part);
            if ($normalized === $userId) {
                return true;
            }
        }
    }

    if (is_array($users)) {
        foreach ($users as $value) {
            $normalized = (int)preg_replace('/\D+/u', '', (string)$value);
            if ($normalized === $userId) {
                return true;
            }
        }
    }

    return false;
}

function extractTaskParameters($raw): array
{
    if (is_array($raw)) {
        return $raw;
    }

    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $unserialized = @unserialize($raw, ['allowed_classes' => false]);
    if (is_array($unserialized)) {
        return $unserialized;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function findCurrentUserTaskForDocument(int $userId, int $iblockId, int $elementId): ?array
{
    /** @var CBPTaskService $taskService */
    $taskService = CBPRuntime::GetRuntime()->GetService('TaskService');

    $dbTasks = $taskService->GetList(
        ['ID' => 'DESC'],
        [
            'USER_STATUS' => CBPTaskUserStatus::Waiting,
        ],
        false,
        false,
        ['ID', 'WORKFLOW_ID', 'ACTIVITY', 'ACTIVITY_NAME', 'NAME', 'DESCRIPTION', 'PARAMETERS', 'DOCUMENT_ID', 'USER_ID', 'USERS']
    );

    while ($task = $dbTasks->GetNext()) {
        if (!bizprocTaskIsForUser($task, $userId)) {
            continue;
        }

        $taskElementId = extractRequestIdFromDocumentId($task['DOCUMENT_ID'] ?? '', $iblockId);
        if ($taskElementId === $elementId) {
            return $task;
        }
    }

    return null;
}

function buildActionButtons(array $task): array
{
    $params = extractTaskParameters($task['PARAMETERS'] ?? []);

    $approveText = trim((string)($params['TaskButton1Message'] ?? ''));
    $rejectText = trim((string)($params['TaskButton2Message'] ?? ''));
    $refineText = trim((string)($params['TaskButton3Message'] ?? ''));

    return [
        'approve' => $approveText !== '' ? $approveText : 'Утвердить',
        'nonapprove' => $rejectText !== '' ? $rejectText : 'Отклонить',
        'refine' => $refineText !== '' ? $refineText : 'На доработку',
        'refineAllowed' => !isset($params['RefineAllowed']) || $params['RefineAllowed'] !== 'N',
        'commentLabel' => trim((string)($params['CommentLabelMessage'] ?? '')) ?: 'Комментарий',
    ];
}

function taskIsRunning(int $taskId): bool
{
    if ($taskId <= 0 || !class_exists('CBPTaskService')) {
        return false;
    }

    $res = CBPTaskService::GetList(['ID' => 'DESC'], ['ID' => $taskId], false, false, ['ID', 'STATUS']);
    if (!is_object($res)) {
        return false;
    }

    $task = $res->GetNext();
    if (!$task) {
        return false;
    }

    return (int)($task['STATUS'] ?? 0) === (int)CBPTaskStatus::Running;
}

function flattenBizprocErrors(array $errors): string
{
    $messages = [];
    foreach ($errors as $error) {
        if (is_string($error)) {
            $message = trim($error);
            if ($message !== '') {
                $messages[] = $message;
            }
            continue;
        }

        if (is_array($error)) {
            $message = trim((string)($error['message'] ?? $error['MESSAGE'] ?? ''));
            if ($message !== '') {
                $messages[] = $message;
            }
        }
    }

    $messages = array_values(array_unique($messages));
    return implode(' ', $messages);
}

function completeTask(array $task, int $userId, string $action, string $comment): array
{
    $taskId = (int)($task['ID'] ?? 0);
    if ($taskId <= 0 || $userId <= 0) {
        return [
            'OK' => false,
            'ERRORS' => [['message' => 'Некорректные входные данные для завершения задания.']],
        ];
    }

    $code = strtolower(trim($action));
    if ($code === '') {
        $code = 'approve';
    }

    $errors = [];
    $steps = [];

    $requests = [];
    $base = [
        'USER_ID' => $userId,
        'REAL_USER_ID' => $userId,
        'task_comment' => $comment,
        'COMMENT' => $comment,
    ];

    if ($code === 'approve') {
        $requests[] = $base + ['approve' => 'Y', 'ACTION' => 'approve'];
    } elseif ($code === 'nonapprove') {
        $requests[] = $base + ['nonapprove' => 'Y', 'ACTION' => 'nonapprove'];
    } elseif ($code === 'refine') {
        $requests[] = $base + ['refine' => 'Y', 'REFINE' => 'Y', 'nonapprove' => 'Y', 'ACTION' => 'refine'];
        $requests[] = $base + ['refine' => 'Y', 'REFINE' => 'Y', 'nonapprove' => 'Y', 'ACTION' => 'nonapprove'];
    }

    foreach ($requests as $request) {
        $postErrors = [];
        try {
            CBPDocument::PostTaskForm($taskId, $userId, $request, $postErrors, '', $userId);
        } catch (Throwable $e) {
            $postErrors[] = ['message' => $e->getMessage()];
        }

        if (!empty($postErrors)) {
            $errors = array_merge($errors, $postErrors);
        }

        $running = taskIsRunning($taskId);
        $steps[] = [
            'stage' => 'CBPDocument::PostTaskForm',
            'request' => $request,
            'running' => $running ? 'Y' : 'N',
            'errors' => $postErrors,
        ];
        if (!$running) {
            return ['OK' => true, 'ERRORS' => [], 'STEPS' => $steps];
        }
    }

    $workflowId = (string)($task['WORKFLOW_ID'] ?? '');
    $activityName = (string)($task['ACTIVITY_NAME'] ?? '');
    if ($workflowId !== '' && $activityName !== '') {
        $payload = [
            'USER_ID' => $userId,
            'REAL_USER_ID' => $userId,
            'COMMENT' => $comment,
        ];
        if ($code === 'approve') {
            $payload['APPROVE'] = true;
        } else {
            $payload['APPROVE'] = false;
            if ($code === 'refine') {
                $payload['REFINE'] = 'Y';
            }
        }

        $eventErrors = [];
        try {
            CBPRuntime::SendExternalEvent($workflowId, $activityName, $payload);
        } catch (Throwable $e) {
            $eventErrors[] = ['message' => $e->getMessage()];
        }
        if (!empty($eventErrors)) {
            $errors = array_merge($errors, $eventErrors);
        }
        $running = taskIsRunning($taskId);
        $steps[] = [
            'stage' => 'CBPRuntime::SendExternalEvent',
            'request' => $payload,
            'running' => $running ? 'Y' : 'N',
            'errors' => $eventErrors,
        ];
        if (!$running) {
            return ['OK' => true, 'ERRORS' => [], 'STEPS' => $steps];
        }
    }

    if (class_exists('CBPTaskService') && method_exists('CBPTaskService', 'DoTask')) {
        try {
            CBPTaskService::DoTask($taskId, $userId, [
                'ACTION' => $code,
                $code => 'Y',
                'COMMENT' => $comment,
                'task_comment' => $comment,
            ]);
        } catch (Throwable $e) {
            $errors[] = ['message' => $e->getMessage()];
        }

        $running = taskIsRunning($taskId);
        $steps[] = [
            'stage' => 'CBPTaskService::DoTask',
            'request' => ['ACTION' => $code, 'COMMENT' => $comment],
            'running' => $running ? 'Y' : 'N',
            'errors' => [],
        ];
        if (!$running) {
            return ['OK' => true, 'ERRORS' => [], 'STEPS' => $steps];
        }
    }

    $flatError = flattenBizprocErrors($errors);
    if ($flatError === '') {
        $flatError = 'Задание осталось активным после попыток завершения.';
    }

    return ['OK' => false, 'ERRORS' => [['message' => $flatError]], 'STEPS' => $steps];
}

$currentUserId = (int)$USER->GetID();
$elementData = getElementData(TEST_IBLOCK_ID, TEST_ELEMENT_ID);
$task = findCurrentUserTaskForDocument($currentUserId, TEST_IBLOCK_ID, TEST_ELEMENT_ID);
$message = null;
$messageType = 'ok';
$diagnosticSteps = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $postedTaskId = (int)($_POST['task_id'] ?? 0);
    $action = (string)($_POST['bp_action'] ?? '');
    $comment = trim((string)($_POST['task_comment'] ?? ''));

    if (!$task || (int)$task['ID'] !== $postedTaskId) {
        $message = 'Задание не найдено или уже завершено.';
        $messageType = 'error';
    } elseif (!in_array($action, ['approve', 'nonapprove', 'refine'], true)) {
        $message = 'Некорректное действие.';
        $messageType = 'error';
    } else {
        $result = completeTask($task, $currentUserId, $action, $comment);
        if ($result['OK']) {
            $message = 'Задание успешно завершено.';
            $task = findCurrentUserTaskForDocument($currentUserId, TEST_IBLOCK_ID, TEST_ELEMENT_ID);
            $messageType = 'ok';
        } else {
            $messageType = 'error';
            $errors = [];
            foreach ($result['ERRORS'] as $error) {
                $errors[] = (string)($error['message'] ?? 'Неизвестная ошибка');
            }
            $message = 'Ошибка завершения задания: ' . implode('; ', $errors);
        }
        $diagnosticSteps = (array)($result['STEPS'] ?? []);
    }
}

$buttons = $task ? buildActionButtons($task) : [];
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Test BP Finish</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
        th, td { border: 1px solid #d9d9d9; padding: 8px; text-align: left; vertical-align: top; }
        th { background: #f5f5f5; width: 280px; }
        .block { border: 1px solid #e0e0e0; padding: 16px; margin-bottom: 20px; }
        .msg-ok { color: #1d7a1d; }
        .msg-error { color: #c62828; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
        .btn { padding: 8px 14px; border: 1px solid #ccc; background: #f5f5f5; cursor: pointer; }
        .btn-approve { background: #d8f0d8; }
        .btn-reject { background: #ffdede; }
        .btn-refine { background: #fff2cc; }
        textarea { width: 100%; min-height: 80px; }
    </style>
</head>
<body>
<h1>Тест завершения задания БП</h1>
<p>Документ: <a href="<?= h(TEST_DOC_URL) ?>" target="_blank"><?= h(TEST_DOC_URL) ?></a></p>

<?php if ($message !== null): ?>
    <p class="<?= $messageType === 'ok' ? 'msg-ok' : 'msg-error' ?>"><?= h($message) ?></p>
<?php endif; ?>

<div class="block">
    <h2>Документ (минимальные данные)</h2>
    <?php if (!$elementData): ?>
        <p class="msg-error">Документ не найден.</p>
    <?php else: ?>
        <table>
            <tr><th>IBLOCK_ID</th><td><?= (int)TEST_IBLOCK_ID ?></td></tr>
            <tr><th>ELEMENT_ID (из константы)</th><td><?= (int)TEST_ELEMENT_ID ?></td></tr>
            <tr><th>ID (из БД)</th><td><?= (int)$elementData['ID'] ?></td></tr>
        </table>
    <?php endif; ?>
</div>

<?php if (!empty($diagnosticSteps)): ?>
    <div class="block">
        <h2>Диагностика завершения задания</h2>
        <table>
            <tr>
                <th>Этап</th>
                <th>Задание все еще Running</th>
                <th>Данные запроса</th>
                <th>Ошибки</th>
            </tr>
            <?php foreach ($diagnosticSteps as $step): ?>
                <tr>
                    <td><?= h((string)($step['stage'] ?? '')) ?></td>
                    <td><?= h((string)($step['running'] ?? '')) ?></td>
                    <td><pre><?= h(print_r($step['request'] ?? [], true)) ?></pre></td>
                    <td><pre><?= h(print_r($step['errors'] ?? [], true)) ?></pre></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
<?php endif; ?>

<div class="block">
    <h2>Задание бизнес-процесса текущего пользователя</h2>
    <?php if (!$task): ?>
        <p>Для текущего пользователя активных заданий по этому документу не найдено.</p>
    <?php else: ?>
        <p><strong>ID задания:</strong> <?= (int)$task['ID'] ?></p>
        <p><strong>Активити:</strong> <?= h((string)$task['ACTIVITY']) ?></p>
        <p><strong>Название:</strong> <?= h((string)$task['NAME']) ?></p>
        <form method="post">
            <?= bitrix_sessid_post() ?>
            <input type="hidden" name="task_id" value="<?= (int)$task['ID'] ?>">
            <label for="task_comment"><strong><?= h($buttons['commentLabel']) ?>:</strong></label><br>
            <textarea id="task_comment" name="task_comment"></textarea>

            <div class="actions">
                <button class="btn btn-approve" type="submit" name="bp_action" value="approve"><?= h($buttons['approve']) ?></button>
                <button class="btn btn-reject" type="submit" name="bp_action" value="nonapprove"><?= h($buttons['nonapprove']) ?></button>
                <?php if ($task['ACTIVITY'] === 'approvecopyactiveschedule' && $buttons['refineAllowed']): ?>
                    <button class="btn btn-refine" type="submit" name="bp_action" value="refine"><?= h($buttons['refine']) ?></button>
                <?php endif; ?>
            </div>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
