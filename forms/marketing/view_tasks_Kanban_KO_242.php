<?php
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');

use Bitrix\Disk\AttachedObject;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Internals\TaskTable;

$APPLICATION->SetTitle('Канбан: задачи группы 242 по стадиям');

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
    "SELECT ID, TITLE, SORT FROM b_tasks_stages WHERE ENTITY_TYPE = 'G' AND ENTITY_ID = %d ORDER BY SORT ASC, ID ASC",
    $groupId
))->fetchAll();

$columns = [];
$stageIds = [];
foreach ($stageRows as $stage) {
    $stageId = (int)$stage['ID'];
    $stageIds[] = $stageId;
    $columns[$stageId] = [
        'title' => (string)$stage['TITLE'],
        'tasks' => [],
    ];
}

$rows = TaskTable::getList([
    'select' => ['ID', 'TITLE', 'CREATED_DATE', 'DEADLINE', 'GROUP_ID', 'STATUS', 'CREATED_BY', 'RESPONSIBLE_ID', 'UF_TASK_WEBDAV_FILES'],
    'filter' => ['=GROUP_ID' => $groupId],
    'order' => ['ID' => 'ASC'],
])->fetchAll();

$rows = array_values(array_filter($rows, static function (array $task): bool {
    $title = (string)($task['TITLE'] ?? '');
    return mb_stripos($title, 'КОвнутр') === false && mb_stripos($title, 'КО внутр') === false;
}));

$taskIds = array_map(static function (array $task): int {
    return (int)$task['ID'];
}, $rows);

$taskStages = [];
if (!empty($taskIds) && !empty($stageIds)) {
    $taskRows = $connection->query(sprintf(
        'SELECT ID, STAGE_ID FROM b_tasks WHERE ID IN (%s) AND GROUP_ID = %d AND STAGE_ID IN (%s)',
        implode(',', array_map('intval', $taskIds)),
        $groupId,
        implode(',', array_map('intval', $stageIds))
    ))->fetchAll();
    foreach ($taskRows as $taskRow) {
        $taskStages[(int)$taskRow['ID']] = (int)$taskRow['STAGE_ID'];
    }

    if (count($taskStages) < count($taskIds)) {
        $taskStageRows = $connection->query(sprintf(
            'SELECT TASK_ID, STAGE_ID FROM b_tasks_task_stage WHERE TASK_ID IN (%s) AND STAGE_ID IN (%s)',
            implode(',', array_map('intval', $taskIds)),
            implode(',', array_map('intval', $stageIds))
        ))->fetchAll();
        foreach ($taskStageRows as $taskStage) {
            $taskId = (int)$taskStage['TASK_ID'];
            if (!isset($taskStages[$taskId])) {
                $taskStages[$taskId] = (int)$taskStage['STAGE_ID'];
            }
        }
    }
}

$defaultStageId = $stageIds[0] ?? null;
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
        $name = trim((string)$user['LAST_NAME'] . ' ' . (string)$user['NAME']);
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
.ko-kanban{font-family:Arial,sans-serif;font-size:14px;color:#1f2937}.ko-kanban-board{display:grid;grid-auto-flow:column;grid-auto-columns:minmax(240px,1fr);gap:12px;align-items:start;overflow-x:auto;padding-bottom:12px}.ko-column{background:#eef3f6;border-radius:8px;min-height:70vh}.ko-column-header{position:sticky;top:0;z-index:2;padding:12px 14px;font-weight:700;border-radius:8px 8px 0 0;color:#111827}.ko-column:nth-child(1) .ko-column-header{background:#9bd800}.ko-column:nth-child(2) .ko-column-header{background:#30c0e4}.ko-column:nth-child(3) .ko-column-header{background:#55c8d3}.ko-column:nth-child(4) .ko-column-header{background:#aeb4bb}.ko-column:nth-child(5) .ko-column-header{background:#4a90e2;color:#fff}.ko-count{opacity:.75;font-weight:600}.ko-cards{padding:10px;display:flex;flex-direction:column;gap:8px}.ko-card{background:#fff;border-radius:12px;padding:14px;box-shadow:0 1px 2px rgba(15,23,42,.08);border-left:4px solid transparent}.ko-card.is-overdue-card{border-left-color:#ef4444}.ko-title{display:block;color:#1f2937;font-weight:700;line-height:1.35;text-decoration:none;margin-bottom:10px}.ko-title:hover{text-decoration:underline}.ko-preview-grid{display:grid;grid-template-columns:1fr;gap:6px;margin:8px 0 10px}.ko-preview{display:block;border-radius:6px;overflow:hidden;background:#f3f4f6}.ko-preview img{display:block;width:100%;height:120px;object-fit:cover}.ko-preview-more{font-size:12px;color:#6b7280}.ko-meta{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:10px}.ko-deadline{display:inline-flex;border-radius:16px;padding:4px 10px;background:#38bdf8;color:#fff;font-weight:600;font-size:12px}.ko-deadline.is-overdue{background:#f59e0b}.ko-deadline.is-empty{background:#fff;color:#6b7280;border:1px solid #cbd5e1}.ko-users{display:grid;grid-template-columns:1fr;gap:6px;margin-top:10px;color:#4b5563;font-size:12px}.ko-user{display:flex;align-items:center;gap:6px;min-width:0}.ko-avatar{width:22px;height:22px;border-radius:50%;background:#d1d5db;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:11px;flex:0 0 auto;overflow:hidden}.ko-avatar img{width:100%;height:100%;object-fit:cover}.ko-user-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.ko-empty{padding:14px;color:#6b7280}.ko-toolbar{margin:8px 0 14px;color:#6b7280}.ko-toolbar a{color:#2563eb}.ko-legend{font-size:12px}
@media(max-width:1300px){.ko-kanban-board{grid-auto-columns:280px}}
</style>
<div class="ko-kanban">
    <div class="ko-toolbar">
        <a href="<?= htmlspecialcharsbx($groupUrl) ?>" target="_blank">Группа #<?= (int)$groupId ?></a>: все задачи, распределенные по стадиям канбан-доски задач группы.
        <span class="ko-legend">Исключены задачи с фразами «КОвнутр» и «КО внутр».</span>
    </div>
    <?php if (empty($rows)): ?>
        <div>Задач в группе #<?= (int)$groupId ?> не найдено.</div>
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
                            $taskUrl = '/company/personal/user/' . (int)$task['RESPONSIBLE_ID'] . '/tasks/task/view/' . (int)$task['ID'] . '/';
                        ?>
                            <article class="ko-card<?= $deadline['class'] === 'is-overdue' ? ' is-overdue-card' : '' ?>">
                                <a class="ko-title" href="<?= htmlspecialcharsbx($taskUrl) ?>" target="_blank"><?= htmlspecialcharsbx($task['TITLE']) ?></a>
                                <?php if (!empty($task['IMAGE_PREVIEWS'])): ?>
                                    <div class="ko-preview-grid">
                                        <?php foreach ($task['IMAGE_PREVIEWS'] as $preview): ?>
                                            <a class="ko-preview" href="<?= htmlspecialcharsbx($preview['href']) ?>" target="_blank" title="<?= htmlspecialcharsbx($preview['name']) ?>"><img src="<?= htmlspecialcharsbx($preview['src']) ?>" alt="<?= htmlspecialcharsbx($preview['name']) ?>"></a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="ko-meta"><span class="ko-deadline <?= htmlspecialcharsbx($deadline['class']) ?>"><?= htmlspecialcharsbx($deadline['text']) ?></span></div>
                                <div class="ko-users">
                                    <div class="ko-user"><span class="ko-avatar"><?php if ($creator['photo'] !== ''): ?><img src="<?= htmlspecialcharsbx($creator['photo']) ?>" alt=""><?php else: ?>П<?php endif; ?></span><span class="ko-user-name">Постановщик: <?= htmlspecialcharsbx($creator['name']) ?></span></div>
                                    <div class="ko-user"><span class="ko-avatar"><?php if ($responsible['photo'] !== ''): ?><img src="<?= htmlspecialcharsbx($responsible['photo']) ?>" alt=""><?php else: ?>О<?php endif; ?></span><span class="ko-user-name">Ответственный: <?= htmlspecialcharsbx($responsible['name']) ?></span></div>
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
