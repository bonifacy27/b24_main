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
$sort = isset($_GET['sort']) ? (string)$_GET['sort'] : 'id';
$sortOptions = ['id', 'deadline', 'created', 'title'];
if (!in_array($sort, $sortOptions, true)) {
    $sort = 'id';
}

$rows = TaskTable::getList([
    'select' => ['ID', 'TITLE', 'CREATED_DATE', 'DEADLINE', 'PARENT_ID', 'GROUP_ID', 'STATUS'],
    'filter' => ['=GROUP_ID' => $groupId, '@STATUS' => $activeStatuses],
])->fetchAll();

$tasksById = [];
$rootTasks = [];
$minTs = strtotime('-6 months', $nowTs);

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

$compareTasks = function (int $aId, int $bId) use (&$tasksById, $sort): int {
    $a = $tasksById[$aId];
    $b = $tasksById[$bId];
    if ($sort === 'title') {
        return strnatcasecmp($a['TITLE'], $b['TITLE']) ?: ($a['ID'] <=> $b['ID']);
    }
    if ($sort === 'created') {
        return ($a['CREATED_TS'] <=> $b['CREATED_TS']) ?: ($a['ID'] <=> $b['ID']);
    }
    if ($sort === 'deadline') {
        $ad = $a['DEADLINE_TS'] ?: PHP_INT_MAX;
        $bd = $b['DEADLINE_TS'] ?: PHP_INT_MAX;
        return ($ad <=> $bd) ?: ($a['ID'] <=> $b['ID']);
    }
    return ((int)$a['ID'] <=> (int)$b['ID']);
};

$sortTree = function (array &$nodeIds) use (&$sortTree, &$tasksById, $compareTasks): void {
    usort($nodeIds, $compareTasks);
    foreach ($nodeIds as $id) {
        if (!empty($tasksById[$id]['CHILDREN'])) {
            $sortTree($tasksById[$id]['CHILDREN']);
        }
    }
};
$sortTree($rootTasks);

$timelineStart = strtotime(date('Y-m-01 00:00:00', $minTs));
$timelineEnd = strtotime(date('Y-m-t 23:59:59', strtotime('+1 month', $nowTs)));
$daySpan = max(1, (int)ceil(($timelineEnd - $timelineStart) / 86400));
$defaultViewStartTs = strtotime('-14 days', $nowTs);
$defaultViewEndTs = strtotime('+1 month', $nowTs);
$defaultViewDays = max(1, (int)ceil(($defaultViewEndTs - $defaultViewStartTs) / 86400));

$months = [];
for ($monthCursor = $timelineStart; $monthCursor <= $timelineEnd; $monthCursor = strtotime('+1 month', $monthCursor)) {
    $monthStart = $monthCursor;
    $monthEnd = strtotime(date('Y-m-t 23:59:59', $monthCursor));
    $startOffset = max(0, (int)floor(($monthStart - $timelineStart) / 86400));
    $monthDays = (int)ceil((min($monthEnd, $timelineEnd) - max($monthStart, $timelineStart)) / 86400) + 1;
    $months[] = ['label' => FormatDate('f Y', $monthStart), 'left' => ($startOffset / $daySpan) * 100, 'width' => ($monthDays / $daySpan) * 100];
}

$flatRows = [];
$appendRows = function (int $taskId, int $level = 0, ?int $parentId = null) use (&$appendRows, &$flatRows, $tasksById): void {
    $task = $tasksById[$taskId];
    $flatRows[] = ['task' => $task, 'level' => $level, 'parentId' => $parentId, 'hasChildren' => !empty($task['CHILDREN'])];
    foreach ($task['CHILDREN'] as $childId) $appendRows($childId, $level + 1, $taskId);
};
foreach ($rootTasks as $taskId) $appendRows($taskId, 0, null);
?>
<style>
.gantt-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:8px 0 12px}.gantt-controls{display:flex;gap:8px;align-items:center}.gantt-zoom{width:32px;height:32px;border:1px solid #ccd4e0;background:#fff;border-radius:4px;cursor:pointer;font-size:18px}.gantt-wrap{font-family:Arial,sans-serif;font-size:14px}.gantt-layout{display:grid;grid-template-columns:42% 58%;gap:16px}.gantt-left-col{min-width:0}.gantt-right-col{min-width:0}.gantt-left-header{font-weight:700;margin-bottom:8px;padding-top:6px}.gantt-right-scroll{overflow-x:auto;overflow-y:hidden}.gantt-right-inner{min-width:1000px}.gantt-scale{position:relative;height:32px;border:1px solid #d8dde6;background:#f7f9fc;overflow:hidden;margin-bottom:8px}.gantt-month{position:absolute;top:0;bottom:0;border-right:1px solid #d8dde6;font-size:12px;color:#5d6472;display:flex;align-items:center;padding-left:4px;box-sizing:border-box}.task-row{margin-bottom:8px;min-height:40px;display:flex;align-items:center}.gantt-title{padding:8px 10px;border:1px solid #e2e8f0;background:#fff;border-radius:4px;line-height:1.4;width:100%;box-sizing:border-box}.gantt-line{position:relative;border:1px solid #e2e8f0;background:#fff;border-radius:4px;min-height:40px;width:100%}.gantt-bar{position:absolute;top:10px;height:20px;background:#4f8df7;border-radius:10px;opacity:.85}.gantt-milestone{position:absolute;top:6px;width:0;height:0;border-left:8px solid transparent;border-right:8px solid transparent;border-top:14px solid #e74c3c;transform:translateX(-8px)}.toggle{display:inline-block;width:18px;cursor:pointer;color:#5d6472;font-weight:700}.toggle.empty{cursor:default;color:transparent}
</style>
<div class="gantt-wrap">
    <form class="gantt-toolbar" method="get">
        <div class="gantt-controls"><label for="sort">Сортировка:</label><select name="sort" id="sort" onchange="this.form.submit()"><option value="id" <?= $sort === 'id' ? 'selected' : '' ?>>По ID</option><option value="deadline" <?= $sort === 'deadline' ? 'selected' : '' ?>>По крайнему сроку</option><option value="created" <?= $sort === 'created' ? 'selected' : '' ?>>По дате создания</option><option value="title" <?= $sort === 'title' ? 'selected' : '' ?>>По алфавиту</option></select></div>
        <div class="gantt-controls"><button type="button" class="gantt-zoom" id="zoomOut" title="Уменьшить">−</button><button type="button" class="gantt-zoom" id="zoomIn" title="Увеличить">+</button></div>
    </form>

    <?php if (empty($flatRows)): ?>
        <div>Задач со статусами «Ждет выполнения» или «Выполняется» в группе #<?= (int)$groupId ?> не найдено.</div>
    <?php else: ?>
    <div class="gantt-layout">
        <div class="gantt-left-col">
            <div class="gantt-left-header">Задача</div>
            <?php foreach ($flatRows as $row): $task = $row['task']; $level = $row['level']; ?>
                <div class="task-row title-row" data-id="<?= (int)$task['ID'] ?>" data-parent-id="<?= (int)$row['parentId'] ?>">
                    <div class="gantt-title" style="padding-left: <?= 10 + (16 * $level) ?>px;"><?php if ($row['hasChildren']): ?><span class="toggle" data-expanded="1">▾</span><?php else: ?><span class="toggle empty">•</span><?php endif; ?><?= htmlspecialcharsbx($task['TITLE']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="gantt-right-col">
            <div class="gantt-right-scroll" id="ganttScrollHeader"><div class="gantt-right-inner" id="ganttInnerHeader"><div class="gantt-scale"><?php foreach ($months as $month): ?><div class="gantt-month" style="left:<?= $month['left'] ?>%; width:<?= $month['width'] ?>%;"><?= htmlspecialcharsbx($month['label']) ?></div><?php endforeach; ?></div></div></div>
            <div class="gantt-right-scroll" id="ganttScrollBody"><div class="gantt-right-inner" id="ganttInnerBody">
                <?php foreach ($flatRows as $row): $task = $row['task'];
                    $leftDays=max(0,(int)floor(($task['CREATED_TS']-$timelineStart)/86400)); $rightDays=max($leftDays+1,(int)ceil(($nowTs-$timelineStart)/86400));
                    $barLeft=($leftDays/$daySpan)*100; $barWidth=(max(1,$rightDays-$leftDays)/$daySpan)*100; $milestoneLeft=null;
                    if ($task['DEADLINE_TS']) {$deadlineOffset=(int)floor(($task['DEADLINE_TS']-$timelineStart)/86400);$milestoneLeft=max(0,min(100,($deadlineOffset/$daySpan)*100));} ?>
                    <div class="task-row line-row" data-id="<?= (int)$task['ID'] ?>" data-parent-id="<?= (int)$row['parentId'] ?>"><div class="gantt-line"><div class="gantt-bar" style="left:<?= $barLeft ?>%;width:<?= $barWidth ?>%;"></div><?php if ($milestoneLeft !== null): ?><div class="gantt-milestone" style="left:<?= $milestoneLeft ?>%;" title="Дедлайн: <?= htmlspecialcharsbx(FormatDate('d.m.Y', $task['DEADLINE_TS'])) ?>"></div><?php endif; ?></div></div>
                <?php endforeach; ?>
            </div></div>
        </div>
    </div>
    <?php endif; ?>
</div>
<script>
(function(){
 const header=document.getElementById('ganttScrollHeader'), body=document.getElementById('ganttScrollBody');
 if(body&&header){body.addEventListener('scroll',()=>header.scrollLeft=body.scrollLeft);header.addEventListener('scroll',()=>body.scrollLeft=header.scrollLeft);} 
 const titleRows=[...document.querySelectorAll('.title-row')], lineRows=[...document.querySelectorAll('.line-row')];
 const lineById=new Map(lineRows.map(r=>[r.dataset.id,r]));
 const byParent=new Map();
 titleRows.forEach(r=>{const p=r.dataset.parentId;if(p&&p!=='0'){if(!byParent.has(p))byParent.set(p,[]);byParent.get(p).push(r.dataset.id);}});
 function setVisibility(parentId, visible){(byParent.get(String(parentId))||[]).forEach(id=>{const tr=document.querySelector('.title-row[data-id="'+id+'"]');const lr=lineById.get(id);if(tr)tr.style.display=visible?'flex':'none';if(lr)lr.style.display=visible?'flex':'none';const t=tr?tr.querySelector('.toggle'):null;setVisibility(id, visible && t && t.dataset.expanded==='1');});}
 document.querySelectorAll('.toggle:not(.empty)').forEach(t=>t.addEventListener('click',()=>{const row=t.closest('.title-row'),exp=t.dataset.expanded==='1';t.dataset.expanded=exp?'0':'1';t.textContent=exp?'▸':'▾';setVisibility(row.dataset.id,!exp);}));
 const innerEls=[document.getElementById('ganttInnerHeader'),document.getElementById('ganttInnerBody')].filter(Boolean);
 let scale=1, baseWidth=1200; const daySpan=<?=$daySpan?>, defaultDays=<?=$defaultViewDays?>, defaultStart=<?=max(0,(int)floor(($defaultViewStartTs-$timelineStart)/86400))?>;
 if(defaultDays>0){baseWidth=Math.max(1200,Math.round((daySpan/defaultDays)*window.innerWidth*0.58));}
 function applyZoom(){const w=Math.round(baseWidth*scale);innerEls.forEach(e=>e.style.width=w+'px');}
 applyZoom(); if(body){const initialScroll=(defaultStart/daySpan)*(baseWidth*scale);body.scrollLeft=Math.max(0,initialScroll);header.scrollLeft=body.scrollLeft;}
 document.getElementById('zoomIn')?.addEventListener('click',()=>{scale=Math.min(5,scale*1.2);applyZoom();}); document.getElementById('zoomOut')?.addEventListener('click',()=>{scale=Math.max(0.4,scale/1.2);applyZoom();});
})();
</script>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
