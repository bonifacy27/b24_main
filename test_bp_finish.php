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
    $res = CIBlockElement::GetList([], ['IBLOCK_ID' => $iblockId, 'ID' => $elementId], false, false, ['*']);
    $element = $res->GetNextElement();
    if (!$element) {
        return null;
    }

    $fields = $element->GetFields();
    $props = $element->GetProperties();

    $preparedProps = [];
    foreach ($props as $code => $prop) {
        $preparedProps[$code] = [
            'NAME' => $prop['NAME'],
            'VALUE' => $prop['VALUE'],
        ];
    }

    return [
        'FIELDS' => $fields,
        'PROPERTIES' => $preparedProps,
    ];
}

function findCurrentUserTaskForDocument(int $userId, int $iblockId, int $elementId): ?array
{
    /** @var CBPTaskService $taskService */
    $taskService = CBPRuntime::GetRuntime()->GetService('TaskService');

    $dbTasks = $taskService->GetList(
        ['ID' => 'DESC'],
        [
            'USER_ID' => $userId,
            'USER_STATUS' => CBPTaskUserStatus::Waiting,
        ],
        false,
        false,
        ['ID', 'WORKFLOW_ID', 'ACTIVITY', 'ACTIVITY_NAME', 'NAME', 'DESCRIPTION', 'PARAMETERS', 'DOCUMENT_ID']
    );

    while ($task = $dbTasks->Fetch()) {
        $documentId = $task['DOCUMENT_ID'] ?? null;
        if (is_array($documentId)) {
            $serialized = implode('|', $documentId);
        } else {
            $serialized = (string)$documentId;
        }

        $isTarget = mb_strpos($serialized, 'iblock_' . $iblockId . '_' . $elementId) !== false
            || (
                mb_strpos($serialized, (string)$iblockId) !== false
                && mb_strpos($serialized, (string)$elementId) !== false
            );

        if ($isTarget) {
            return $task;
        }
    }

    return null;
}

function buildActionButtons(array $task): array
{
    $params = (array)($task['PARAMETERS'] ?? []);

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

function completeTask(array $task, int $userId, string $action, string $comment): array
{
    $errors = [];

    $request = ['task_comment' => $comment];
    if ($action === 'approve') {
        $request['approve'] = 'Y';
    } elseif ($action === 'nonapprove') {
        $request['nonapprove'] = 'Y';
    } elseif ($action === 'refine') {
        $request['refine'] = 'Y';
        $request['REFINE'] = 'Y';
    }

    $activity = (string)($task['ACTIVITY'] ?? '');
    $ok = false;

    if ($activity === 'ApproveActivity') {
        require_once __DIR__ . '/approveactivity/approveactivity.php';
        $ok = CBPApproveActivity::PostTaskForm($task, $userId, $request, $errors);
    } elseif ($activity === 'approvecopyactiveschedule') {
        require_once __DIR__ . '/approvecopyactiveschedule/approvecopyactiveschedule.php';
        $ok = CBPapprovecopyactiveschedule::PostTaskForm($task, $userId, $request, $errors);
    } else {
        $errors[] = [
            'message' => 'Неподдерживаемое активити: ' . $activity,
        ];
    }

    return ['OK' => $ok, 'ERRORS' => $errors];
}

$currentUserId = (int)$USER->GetID();
$elementData = getElementData(TEST_IBLOCK_ID, TEST_ELEMENT_ID);
$task = findCurrentUserTaskForDocument($currentUserId, TEST_IBLOCK_ID, TEST_ELEMENT_ID);
$message = null;
$messageType = 'ok';

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
    <h2>Поля документа (iblock <?= (int)TEST_IBLOCK_ID ?>, element <?= (int)TEST_ELEMENT_ID ?>)</h2>
    <?php if (!$elementData): ?>
        <p class="msg-error">Документ не найден.</p>
    <?php else: ?>
        <h3>Системные поля</h3>
        <table>
            <?php foreach ($elementData['FIELDS'] as $fieldName => $fieldValue): ?>
                <tr>
                    <th><?= h($fieldName) ?></th>
                    <td><?= h(renderFieldValue($fieldValue)) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <h3>Свойства</h3>
        <table>
            <?php foreach ($elementData['PROPERTIES'] as $code => $property): ?>
                <tr>
                    <th><?= h($code . ' (' . $property['NAME'] . ')') ?></th>
                    <td><?= h(renderFieldValue($property['VALUE'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</div>

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
