<?php
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');

use Bitrix\Disk\AttachedObject;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Internals\TaskTable;
use Bitrix\Tasks\Helper\Filter as TaskFilter;

$APPLICATION->SetTitle('Задачи креативного отдела');

if (!Loader::includeModule('tasks')) {
    echo '<div style="color:#b00020;font-weight:600;">Модуль tasks не установлен.</div>';
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

$diskAvailable = Loader::includeModule('disk');
$groupId = 242;
$groupUrl = '/workgroups/group/' . $groupId . '/';
$nowTs = time();

$eventType = 'MARKETING_KANBAN_KO_242_VISIT';
$statsViewerUserIds = [3532];
$skipLoggingUserIds = [3532];
$currentUserId = (int)$USER->GetID();
$canOpenTasks = false;
if ($currentUserId > 0 && Loader::includeModule('socialnetwork')) {
    $groupRole = CSocNetUserToGroup::GetUserRole($currentUserId, $groupId);
    $canOpenTasks = in_array($groupRole, [SONET_ROLES_OWNER, SONET_ROLES_MODERATOR], true);
}

if ($currentUserId > 0 && !in_array($currentUserId, $skipLoggingUserIds, true)) {
    CEventLog::Add([
        'SEVERITY' => 'SECURITY',
        'AUDIT_TYPE_ID' => $eventType,
        'MODULE_ID' => 'main',
        'ITEM_ID' => 'forms/marketing/view_tasks_Kanban_KO_242.php',
        'DESCRIPTION' => sprintf('USER_ID=%d; GROUP_ID=%d; URI=%s', $currentUserId, $groupId, (string)($_SERVER['REQUEST_URI'] ?? '')),
    ]);
}

$connection = \Bitrix\Main\Application::getConnection();
$stageRows = $connection->query(sprintf(
    "SELECT ID, TITLE, SORT, SYSTEM_TYPE FROM b_tasks_stages WHERE ENTITY_TYPE = 'G' AND ENTITY_ID = %d ORDER BY SORT ASC, ID ASC",
    $groupId
))->fetchAll();

$columns = [];
$defaultStageId = null;
foreach ($stageRows as $stage) {
    $stageId = (int)$stage['ID'];
    if ($stage['SYSTEM_TYPE'] === 'NEW') {
        $defaultStageId = $stageId;
    }
    $columns[$stageId] = [
        'title' => (string)$stage['TITLE'],
        'tasks' => [],
    ];
}

// Use the installed Bitrix "In progress" preset, not only the raw status 3.
$presets = TaskFilter::getPresets();
$inProgressStatuses = $presets['filter_tasks_in_progress']['fields']['STATUS'] ?? [];
if (empty($inProgressStatuses)) {
    echo '<div style="color:#b00020;font-weight:600;">Предустановленный фильтр «В работе» не найден.</div>';
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}
// Older tasks modules may not register the ProjectsTable ORM class.
// Read the same setting from its table only when this schema is available.
$newTaskOrder = 'actual';
if ($connection->isTableExists('b_tasks_projects')) {
    $projectFields = $connection->getTableFields('b_tasks_projects');
    if (isset($projectFields['ID'], $projectFields['ORDER_NEW_TASK'])) {
        $project = $connection->query(sprintf(
            'SELECT ORDER_NEW_TASK FROM b_tasks_projects WHERE ID = %d',
            $groupId
        ))->fetch();
        if (!empty($project['ORDER_NEW_TASK'])) {
            $newTaskOrder = (string)$project['ORDER_NEW_TASK'];
        }
    }
}
$taskOrder = $newTaskOrder === 'actual'
    ? ['ACTIVITY_DATE' => 'DESC', 'ID' => 'ASC']
    : ['SORTING_ORDER' => 'ASC', 'STATUS_COMPLETE' => 'ASC', 'DEADLINE' => 'ASC,NULLS', 'ID' => 'ASC'];

// Use the native kanban query, including its access check and group sorting.
// No pagination: load every matching task, not only the first page of each stage.
$nativeRows = [];
if ($currentUserId > 0) {
    [$nativeRows] = CTaskItem::fetchListArray(
        $currentUserId,
        $taskOrder,
        [
            'GROUP_ID' => $groupId,
            'REAL_STATUS' => $inProgressStatuses,
            'ONLY_ROOT_TASKS' => 'N',
        ],
        ['SORTING_GROUP_ID' => $groupId, 'MAKE_ACCESS_FILTER' => true],
        ['ID', 'STAGE_ID']
    );
}
$taskIds = [];
$taskStages = [];
foreach ($nativeRows as $taskRow) {
    $taskId = (int)$taskRow['ID'];
    $taskIds[] = $taskId;
    $taskStages[$taskId] = (int)$taskRow['STAGE_ID'] ?: $defaultStageId;
}

// Fetch typed dates and attachment fields through ORM, preserving the native order.
$rows = [];
if (!empty($taskIds)) {
    $rows = TaskTable::getList([
        'select' => ['ID', 'TITLE', 'CREATED_DATE', 'DEADLINE', 'GROUP_ID', 'STATUS', 'CREATED_BY', 'RESPONSIBLE_ID', 'UF_TASK_WEBDAV_FILES'],
        'filter' => ['=GROUP_ID' => $groupId, '@STATUS' => $inProgressStatuses, '@ID' => $taskIds],
    ])->fetchAll();
    $taskPositions = array_flip($taskIds);
    usort($rows, static function (array $left, array $right) use ($taskPositions): int {
        return $taskPositions[(int)$left['ID']] <=> $taskPositions[(int)$right['ID']];
    });
}

$unassignedColumnId = 'unassigned';
$userIds = [];
foreach ($rows as &$task) {
    $task['DEADLINE_TS'] = $task['DEADLINE'] instanceof DateTime ? $task['DEADLINE']->getTimestamp() : null;
    $task['CREATED_TS'] = $task['CREATED_DATE'] instanceof DateTime ? $task['CREATED_DATE']->getTimestamp() : null;
    $task['KANBAN_STAGE_ID'] = $taskStages[(int)$task['ID']] ?? $defaultStageId;
    $userIds[(int)$task['CREATED_BY']] = true;
    $userIds[(int)$task['RESPONSIBLE_ID']] = true;
}
unset($task);
unset($userIds[0]);

$users = [];
if (!empty($userIds)) {
    $rsUsers = CUser::GetList(($by = 'last_name'), ($order = 'asc'), ['ID' => implode('|', array_keys($userIds))], ['FIELDS' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN', 'PERSONAL_PHOTO']]);
    while ($user = $rsUsers->Fetch()) {
        $name = trim((string)$user['LAST_NAME'] . ' ' . (string)$user['NAME'] . ' ' . (string)$user['SECOND_NAME']);
        if ($name === '') {
            $name = (string)$user['LOGIN'];
        }
        $photo = '';
        if ((int)$user['PERSONAL_PHOTO'] > 0) {
            $resized = CFile::ResizeImageGet((int)$user['PERSONAL_PHOTO'], ['width' => 48, 'height' => 48], BX_RESIZE_IMAGE_EXACT, true);
            $photo = (string)($resized['src'] ?? '');
        }
        $users[(int)$user['ID']] = ['name' => $name, 'photo' => $photo];
    }
}

$getUser = static function (int $userId) use (&$users): array {
    return $users[$userId] ?? ['name' => 'Пользователь #' . $userId, 'photo' => ''];
};

$getTaskImagePreviews = static function (array $task) use ($diskAvailable): array {
    if (!$diskAvailable || empty($task['UF_TASK_WEBDAV_FILES'])) {
        return [];
    }

    $attachedIds = is_array($task['UF_TASK_WEBDAV_FILES']) ? $task['UF_TASK_WEBDAV_FILES'] : [$task['UF_TASK_WEBDAV_FILES']];
    $previews = [];
    foreach ($attachedIds as $attachedId) {
        $attachedId = (int)$attachedId;
        if ($attachedId <= 0) {
            continue;
        }
        $attachedObject = AttachedObject::loadById($attachedId);
        if (!$attachedObject) {
            continue;
        }
        $file = $attachedObject->getFile();
        if (!$file) {
            continue;
        }
        $fileArray = CFile::GetFileArray((int)$file->getFileId());
        if (!$fileArray || strpos((string)$fileArray['CONTENT_TYPE'], 'image/') !== 0) {
            continue;
        }
        $thumb = CFile::ResizeImageGet((int)$fileArray['ID'], ['width' => 320, 'height' => 180], BX_RESIZE_IMAGE_PROPORTIONAL, true);
        $previews[] = [
            'src' => (string)($thumb['src'] ?? $fileArray['SRC']),
            'href' => (string)$fileArray['SRC'],
            'name' => (string)$fileArray['ORIGINAL_NAME'],
        ];
        if (count($previews) >= 3) {
            break;
        }
    }
    return $previews;
};

foreach ($rows as $task) {
    $task['IMAGE_PREVIEWS'] = $getTaskImagePreviews($task);
    $stageId = $task['KANBAN_STAGE_ID'];
    if ($stageId !== null && isset($columns[$stageId])) {
        $columns[$stageId]['tasks'][] = $task;
        continue;
    }
    if (!isset($columns[$unassignedColumnId])) {
        $columns[$unassignedColumnId] = ['title' => 'Без стадии', 'tasks' => []];
    }
    $columns[$unassignedColumnId]['tasks'][] = $task;
}

$formatDeadline = static function (?int $deadlineTs) use ($nowTs): array {
    if (!$deadlineTs) {
        return ['text' => 'Без срока', 'class' => 'is-empty'];
    }
    $text = FormatDate('d F, H:i', $deadlineTs);
    if (date('Y-m-d', $deadlineTs) === date('Y-m-d', $nowTs)) {
        $text = 'Сегодня, ' . date('H:i', $deadlineTs);
    }
    return ['text' => $text, 'class' => $deadlineTs < $nowTs ? 'is-overdue' : ''];
};
?>
<style>
.ko-kanban{font-family:Arial,sans-serif;font-size:13px;color:#1f2937}
.ko-kanban-board{display:grid;grid-auto-flow:column;grid-auto-columns:minmax(210px,1fr);gap:10px;align-items:start;overflow-x:auto;padding-bottom:10px}
.ko-column{background:#eef3f6;border-radius:8px;min-height:70vh}
.ko-column-header{position:sticky;top:0;z-index:2;padding:10px;font-size:12px;font-weight:700;line-height:1.3;overflow-wrap:anywhere;border-radius:8px 8px 0 0;color:#111827}
.ko-column:nth-child(1) .ko-column-header{background:#9bd800}
.ko-column:nth-child(2) .ko-column-header{background:#30c0e4}
.ko-column:nth-child(3) .ko-column-header{background:#55c8d3}
.ko-column:nth-child(4) .ko-column-header{background:#aeb4bb}
.ko-column:nth-child(5) .ko-column-header{background:#4a90e2;color:#fff}
.ko-count{opacity:.75;font-weight:600}
.ko-cards{padding:8px;display:flex;flex-direction:column;gap:7px}
.ko-card{min-width:0;background:#fff;border-radius:10px;padding:10px;box-shadow:0 1px 2px rgba(15,23,42,.08);border-left:3px solid transparent}
.ko-card.is-overdue-card{border-left-color:#ef4444}
.ko-title{display:block;color:#1f2937;font-size:13px;font-weight:700;line-height:1.3;overflow-wrap:anywhere;text-decoration:none;margin-bottom:8px}
a.ko-title:hover{text-decoration:underline}
.ko-preview-grid{display:grid;grid-template-columns:1fr;gap:5px;margin:6px 0 8px}
.ko-preview{display:block;border-radius:6px;overflow:hidden;background:#f3f4f6}
.ko-preview img{display:block;width:100%;height:100px;object-fit:cover}
.ko-meta{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:8px}
.ko-deadline{display:inline-flex;border-radius:16px;padding:3px 8px;background:#38bdf8;color:#fff;font-weight:600;font-size:11px}
.ko-deadline.is-overdue{background:#f59e0b}
.ko-deadline.is-empty{background:#fff;color:#6b7280;border:1px solid #cbd5e1}
.ko-users{display:flex;gap:7px;align-items:center;margin-top:8px;color:#4b5563}
.ko-avatar{width:26px;height:26px;border-radius:50%;background:#d1d5db;display:inline-flex;align-items:center;justify-content:center;color:#374151;font-size:11px;flex:0 0 auto;overflow:hidden}
.ko-avatar img{width:100%;height:100%;object-fit:cover}
.ko-user-arrow{font-size:14px;color:#6b7280}
.ko-empty{padding:10px;color:#6b7280}
@media(max-width:1300px){.ko-kanban-board{grid-auto-columns:220px}}
</style>
<div class="ko-kanban">
    <?php if (empty($rows)): ?>
        <div>Задач по фильтру «В работе» в группе #<?= (int)$groupId ?> не найдено.</div>
    <?php else: ?>
        <div class="ko-kanban-board">
            <?php foreach ($columns as $column): ?>
                <section class="ko-column">
                    <div class="ko-column-header"><?= htmlspecialcharsbx($column['title']) ?> <span class="ko-count">(<?= count($column['tasks']) ?>)</span></div>
                    <div class="ko-cards">
                        <?php if (empty($column['tasks'])): ?>
                            <div class="ko-empty">Нет задач</div>
                        <?php endif; ?>
                        <?php foreach ($column['tasks'] as $task):
                            $deadline = $formatDeadline($task['DEADLINE_TS']);
                            $creator = $getUser((int)$task['CREATED_BY']);
                            $responsible = $getUser((int)$task['RESPONSIBLE_ID']);
                            $taskUrl = $groupUrl . 'tasks/task/view/' . (int)$task['ID'] . '/';
                        ?>
                            <article class="ko-card<?= $deadline['class'] === 'is-overdue' ? ' is-overdue-card' : '' ?>">
                                <?php if ($canOpenTasks): ?>
                                    <a class="ko-title" href="<?= htmlspecialcharsbx($taskUrl) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialcharsbx($task['TITLE']) ?></a>
                                <?php else: ?>
                                    <span class="ko-title"><?= htmlspecialcharsbx($task['TITLE']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($task['IMAGE_PREVIEWS'])): ?>
                                    <div class="ko-preview-grid">
                                        <?php foreach ($task['IMAGE_PREVIEWS'] as $preview): ?>
                                            <a class="ko-preview" href="<?= htmlspecialcharsbx($preview['href']) ?>" target="_blank" title="<?= htmlspecialcharsbx($preview['name']) ?>"><img src="<?= htmlspecialcharsbx($preview['src']) ?>" alt="<?= htmlspecialcharsbx($preview['name']) ?>"></a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="ko-meta"><span class="ko-deadline <?= htmlspecialcharsbx($deadline['class']) ?>"><?= htmlspecialcharsbx($deadline['text']) ?></span></div>
                                <div class="ko-users">
                                    <span class="ko-avatar" title="<?= htmlspecialcharsbx('Постановщик: ' . $creator['name']) ?>" role="img" aria-label="<?= htmlspecialcharsbx('Постановщик: ' . $creator['name']) ?>">
                                        <?php if ($creator['photo'] !== ''): ?>
                                            <img src="<?= htmlspecialcharsbx($creator['photo']) ?>" alt="<?= htmlspecialcharsbx('Постановщик: ' . $creator['name']) ?>">
                                        <?php else: ?>П<?php endif; ?>
                                    </span>
                                    <span class="ko-user-arrow" aria-hidden="true">&rarr;</span>
                                    <span class="ko-avatar" title="<?= htmlspecialcharsbx('Ответственный: ' . $responsible['name']) ?>" role="img" aria-label="<?= htmlspecialcharsbx('Ответственный: ' . $responsible['name']) ?>">
                                        <?php if ($responsible['photo'] !== ''): ?>
                                            <img src="<?= htmlspecialcharsbx($responsible['photo']) ?>" alt="<?= htmlspecialcharsbx('Ответственный: ' . $responsible['name']) ?>">
                                        <?php else: ?>О<?php endif; ?>
                                    </span>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
