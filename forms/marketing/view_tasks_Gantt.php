<?php
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Internals\TaskTable;

$APPLICATION->SetTitle('Диаграмма Ганта: задачи рабочей группы');

if (!Loader::includeModule('tasks')) {
    echo '<div style="color:#b00020;font-weight:600;">Модуль tasks не установлен.</div>';
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    return;
}

$groupId = 163;
$activeStatuses = [2, 3];
$nowTs = time();

$rows = TaskTable::getList([
    'select' => ['ID', 'TITLE', 'CREATED_DATE', 'DEADLINE', 'PARENT_ID', 'GROUP_ID', 'STATUS'],
    'filter' => ['=GROUP_ID' => $groupId, '@STATUS' => $activeStatuses],
    'order' => ['CREATED_DATE' => 'ASC', 'ID' => 'ASC'],
])->fetchAll();

$tasksById = [];
$rootTasks = [];
$minTs = $nowTs;

foreach ($rows as $task) {
    $createdTs = $task['CREATED_DATE'] instanceof DateTime ? $task['CREATED_DATE']->getTimestamp() : $nowTs;
    $deadlineTs = $task['DEADLINE'] instanceof DateTime ? $task['DEADLINE']->getTimestamp() : null;

    $task['CREATED_TS'] = $createdTs;
    $task['DEADLINE_TS'] = $deadlineTs;
    $task['CHILDREN'] = [];

    $minTs = min($minTs, $createdTs);
    $tasksById[(int)$task['ID']] = $task;
}

foreach ($tasksById as $id => $task) {
    $parentId = (int)$task['PARENT_ID'];
    if ($parentId > 0 && isset($tasksById[$parentId])) {
        $tasksById[$parentId]['CHILDREN'][] = $id;
    } else {
        $rootTasks[] = $id;
    }
}

$timelineStart = strtotime(date('Y-m-01 00:00:00', $minTs));
$timelineEnd = strtotime(date('Y-m-t 23:59:59', $nowTs));
$daySpan = max(1, (int)ceil(($timelineEnd - $timelineStart) / 86400));

$months = [];
$monthCursor = $timelineStart;
while ($monthCursor <= $timelineEnd) {
    $monthStart = $monthCursor;
    $monthEnd = strtotime(date('Y-m-t 23:59:59', $monthCursor));
    $startOffset = max(0, (int)floor(($monthStart - $timelineStart) / 86400));
    $monthDays = (int)ceil((min($monthEnd, $timelineEnd) - max($monthStart, $timelineStart)) / 86400) + 1;

    $months[] = [
        'label' => FormatDate('f Y', $monthStart),
        'left' => ($startOffset / $daySpan) * 100,
        'width' => ($monthDays / $daySpan) * 100,
    ];

    $monthCursor = strtotime('+1 month', $monthStart);
}

$flatRows = [];
$appendRows = function (int $taskId, int $level = 0, ?int $parentId = null) use (&$appendRows, &$flatRows, $tasksById): void {
    $task = $tasksById[$taskId];
    $flatRows[] = ['task' => $task, 'level' => $level, 'parentId' => $parentId, 'hasChildren' => !empty($task['CHILDREN'])];
    foreach ($task['CHILDREN'] as $childId) {
        $appendRows($childId, $level + 1, $taskId);
    }
};

foreach ($rootTasks as $taskId) {
    $appendRows($taskId, 0, null);
}
?>
<style>
.gantt-wrap{font-family:Arial,sans-serif;font-size:14px}.gantt-header,.gantt-row{display:grid;grid-template-columns:42% 58%;gap:16px}.gantt-header{font-weight:700;margin-bottom:8px}.gantt-scale{position:relative;height:32px;border:1px solid #d8dde6;background:#f7f9fc;overflow:hidden}.gantt-month{position:absolute;top:0;bottom:0;border-right:1px solid #d8dde6;font-size:12px;color:#5d6472;display:flex;align-items:center;padding-left:4px;box-sizing:border-box}.gantt-row{margin-bottom:8px}.gantt-title{padding:8px 10px;border:1px solid #e2e8f0;background:#fff;border-radius:4px;line-height:1.4}.gantt-line{position:relative;border:1px solid #e2e8f0;background:#fff;border-radius:4px;min-height:40px}.gantt-bar{position:absolute;top:10px;height:20px;background:#4f8df7;border-radius:10px;opacity:.85}.gantt-milestone{position:absolute;top:6px;width:0;height:0;border-left:8px solid transparent;border-right:8px solid transparent;border-top:14px solid #e74c3c;transform:translateX(-8px)}.toggle{display:inline-block;width:18px;cursor:pointer;color:#5d6472;font-weight:700}.toggle.empty{cursor:default;color:transparent}
</style>
<div class="gantt-wrap">
    <div class="gantt-header">
        <div>Задача</div>
        <div class="gantt-scale">
            <?php foreach ($months as $month): ?>
                <div class="gantt-month" style="left:<?= $month['left'] ?>%; width:<?= $month['width'] ?>%;"><?= htmlspecialcharsbx($month['label']) ?></div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (empty($flatRows)): ?>
        <div>Задач со статусами «Ждет выполнения» или «Выполняется» в группе #<?= (int)$groupId ?> не найдено.</div>
    <?php else: ?>
        <?php foreach ($flatRows as $row): ?>
            <?php
            $task = $row['task'];
            $level = $row['level'];
            $leftDays = max(0, (int)floor(($task['CREATED_TS'] - $timelineStart) / 86400));
            $rightDays = max($leftDays + 1, (int)ceil(($nowTs - $timelineStart) / 86400));
            $barLeft = ($leftDays / $daySpan) * 100;
            $barWidth = (max(1, $rightDays - $leftDays) / $daySpan) * 100;
            $milestoneLeft = null;
            if ($task['DEADLINE_TS']) {
                $deadlineOffset = (int)floor(($task['DEADLINE_TS'] - $timelineStart) / 86400);
                $milestoneLeft = max(0, min(100, ($deadlineOffset / $daySpan) * 100));
            }
            ?>
            <div class="gantt-row" data-id="<?= (int)$task['ID'] ?>" data-parent-id="<?= (int)$row['parentId'] ?>">
                <div class="gantt-title" style="padding-left: <?= 10 + (16 * $level) ?>px;">
                    <?php if ($row['hasChildren']): ?>
                        <span class="toggle" data-expanded="1">▾</span>
                    <?php else: ?>
                        <span class="toggle empty">•</span>
                    <?php endif; ?>
                    <?= htmlspecialcharsbx($task['TITLE']) ?>
                </div>
                <div class="gantt-line">
                    <div class="gantt-bar" style="left:<?= $barLeft ?>%;width:<?= $barWidth ?>%;"></div>
                    <?php if ($milestoneLeft !== null): ?>
                        <div class="gantt-milestone" style="left:<?= $milestoneLeft ?>%;" title="Дедлайн: <?= htmlspecialcharsbx(FormatDate('d.m.Y', $task['DEADLINE_TS'])) ?>"></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<script>
(function(){
  const rows = Array.from(document.querySelectorAll('.gantt-row'));
  const byParent = new Map();
  rows.forEach(r=>{const p=r.dataset.parentId;if(p&&p!=='0'){if(!byParent.has(p))byParent.set(p,[]);byParent.get(p).push(r);}});
  function setVisibility(parentId, visible){
    (byParent.get(String(parentId))||[]).forEach(child=>{child.style.display=visible?'grid':'none';
      const t=child.querySelector('.toggle');
      if(!visible || (t && t.dataset.expanded==='0')) setVisibility(child.dataset.id,false); else setVisibility(child.dataset.id,true);
    });
  }
  document.querySelectorAll('.toggle:not(.empty)').forEach(t=>t.addEventListener('click',()=>{
    const row=t.closest('.gantt-row');
    const exp=t.dataset.expanded==='1';
    t.dataset.expanded=exp?'0':'1';t.textContent=exp?'▸':'▾';
    setVisibility(row.dataset.id,!exp);
  }));
})();
</script>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
