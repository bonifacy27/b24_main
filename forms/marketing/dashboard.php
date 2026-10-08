<?php
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
use Bitrix\Main\Loader;
$APPLICATION->SetTitle('Дашборд маркетинга');
if (!$USER->IsAuthorized() || !Loader::includeModule('tasks')) {
    echo '<p>Для просмотра необходимы авторизация и модуль задач.</p>';
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); return;
}
require_once __DIR__ . '/dashboard_metrics.php';
require_once __DIR__ . '/dashboard_tasks.php';
$config = require __DIR__ . '/dashboard_config.php';
$groupId = (int)$config['group_id'];
$zone = new DateTimeZone($config['timezone']);
$today = new DateTimeImmutable('now', $zone);
$now = $today->getTimestamp();
$get = static function ($key, $default = '') { return isset($_GET[$key]) && is_string($_GET[$key]) ? $_GET[$key] : $default; };
$parseDate = static function ($value) use ($zone) {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
    return $date && $date->format('Y-m-d') === $value ? $date : null;
};
$period = $get('period','month');
if ($period === 'quarter') {
    $fromDate = $today->setDate((int)$today->format('Y'), (int)(floor(((int)$today->format('n')-1)/3)*3+1),1)->setTime(0,0);
    $toDate = $fromDate->modify('+3 months'); $previousFrom = $fromDate->modify('-3 months');
} elseif ($period === 'custom') {
    $fromDate = $parseDate($get('from'));
    $endDate = $parseDate($get('to'));
    if (!$fromDate || !$endDate || $endDate < $fromDate || $endDate->diff($fromDate)->days > 3660) {
        echo '<p>Укажите корректный период (не более 10 лет).</p>'; require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php'); return;
    }
    $toDate = $endDate->modify('+1 day');
    $previousFrom = $fromDate->modify('-' . $toDate->diff($fromDate)->days . ' days');
} else {
    $period = 'month'; $fromDate = $today->modify('first day of this month')->setTime(0,0);
    $toDate = $fromDate->modify('+1 month'); $previousFrom = $fromDate->modify('-1 month');
}
$staleDays = max(1,min(3650,(int)$get('stale','7')));
$timestamp = static function ($value) use ($zone) {
    if (!$value) { return null; }
    if ($value instanceof \Bitrix\Main\Type\DateTime) { return $value->getTimestamp(); }
    try { return (new DateTimeImmutable((string)$value,$zone))->getTimestamp(); } catch (Exception $e) { return null; }
};
$h = static function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
$warnings = [];
try {
    // Use a real active administrator as the API actor; never change the viewer's session.
    $adminFilter = ['ACTIVE'=>'Y', 'GROUPS_ID'=>[1]];
    if (!empty($config['administrator_user_id'])) { $adminFilter['ID'] = (int)$config['administrator_user_id']; }
    $adminBy = 'id'; $adminOrder = 'asc';
    $admins = CUser::GetList($adminBy, $adminOrder, $adminFilter, ['FIELDS'=>['ID']]);
    $administratorId = 0;
    while ($admin = $admins->Fetch()) {
        $candidateId = (int)$admin['ID'];
        if (in_array(1, array_map('intval', CUser::GetUserGroup($candidateId)), true)) { $administratorId = $candidateId; break; }
    }
    if (!$administratorId) {
        echo '<p>Не найден активный администратор для получения задач. Проверьте administrator_user_id в настройках дашборда.</p>';
        require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php'); return;
    }
    $fetchTasks = static function (array $filter) use ($administratorId) {
        [$rows] = CTaskItem::fetchListArray($administratorId, ['ID'=>'ASC'], $filter,
            ['MAKE_ACCESS_FILTER'=>true],
            ['ID','TITLE','GROUP_ID','PARENT_ID','STATUS','STAGE_ID','RESPONSIBLE_ID','CREATED_DATE','CHANGED_DATE','ACTIVITY_DATE','CLOSED_DATE','DEADLINE','DATE_START']);
        return $rows;
    };
    $nativeRows = marketingLoadDashboardTasks($fetchTasks, $groupId);
} catch (Throwable $e) {
    AddMessage2Log('Marketing dashboard: native task selection failed', 'tasks');
    echo '<p>Не удалось получить задачи. Обратитесь к администратору.</p>'; require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php'); return;
}
$tasks = [];
foreach ($nativeRows as $row) {
    $id = (int)$row['ID'];
    $tasks[$id] = ['id'=>$id,'in_group'=>!empty($row['IN_REPORT_GROUP']),'group'=>(int)($row['GROUP_ID'] ?? $groupId),'title'=>(string)$row['TITLE'],'parent'=>(int)$row['PARENT_ID'],'status'=>(int)$row['STATUS'],'stage'=>(int)($row['STAGE_ID'] ?? 0), 'responsible'=>(int)$row['RESPONSIBLE_ID'],
        'created'=>$timestamp($row['CREATED_DATE']), 'changed'=>$timestamp($row['ACTIVITY_DATE'] ?? $row['CHANGED_DATE']), 'closed'=>$timestamp($row['CLOSED_DATE']), 'deadline'=>$timestamp($row['DEADLINE']), 'start'=>$timestamp($row['DATE_START']), 'accomplices'=>[], 'tags'=>[], 'logs'=>[], 'elapsed'=>[]];
}
$connection = \Bitrix\Main\Application::getConnection();
$available = ['history'=>false,'elapsed'=>false,'tags'=>false];
// Additional data belongs only to tasks selected by the administrator API actor.
$load = static function ($table, array $required, $callback) use ($connection, &$tasks, &$warnings) {
    if (!$tasks) { return false; }
    try {
        if (!$connection->isTableExists($table)) { return false; }
        $fields = $connection->getTableFields($table);
        foreach ($required as $field) { if (!isset($fields[$field])) { return false; } }
        foreach (array_chunk(array_keys($tasks),500) as $ids) {
            $rows = $connection->query('SELECT * FROM ' . $table . ' WHERE TASK_ID IN (' . implode(',',array_map('intval',$ids)) . ')');
            while ($row = $rows->fetch()) { $callback($row,$fields); }
        }
        return true;
    } catch (Throwable $e) {
        $warnings[] = 'Не удалось прочитать ' . $table . '; соответствующие показатели недоступны.';
        AddMessage2Log('Marketing dashboard: cannot read ' . $table, 'tasks'); return false;
    }
};
$load('b_tasks_member',['TASK_ID','USER_ID','TYPE'],static function ($row) use (&$tasks) {
    if ($row['TYPE'] === 'A') { $tasks[(int)$row['TASK_ID']]['accomplices'][] = (int)$row['USER_ID']; }
});
$available['history'] = $load('b_tasks_log',['TASK_ID','CREATED_DATE','FIELD','FROM_VALUE','TO_VALUE'],static function ($row) use (&$tasks,$timestamp) {
    $at = $timestamp($row['CREATED_DATE']);
    if ($at) { $tasks[(int)$row['TASK_ID']]['logs'][] = ['at'=>$at,'field'=>$row['FIELD'],'from'=>$row['FROM_VALUE'],'to'=>$row['TO_VALUE']]; }
});
$available['elapsed'] = $load('b_tasks_elapsed_time',['TASK_ID','USER_ID','CREATED_DATE','SECONDS'],static function ($row) use (&$tasks,$timestamp) {
    $at = $timestamp($row['CREATED_DATE']);
    if ($at) { $tasks[(int)$row['TASK_ID']]['elapsed'][] = ['at'=>$at,'user'=>(int)$row['USER_ID'],'seconds'=>max(0,(int)$row['SECONDS'])]; }
});
$available['tags'] = $load('b_tasks_tag',['TASK_ID','NAME'],static function ($row) use (&$tasks) { $tasks[(int)$row['TASK_ID']]['tags'][] = (string)$row['NAME']; });
if (!$available['tags']) {
    try {
        if ($connection->isTableExists('b_tasks_task_tag') && $connection->isTableExists('b_tasks_tag')) {
            $linkFields = $connection->getTableFields('b_tasks_task_tag'); $tagFields = $connection->getTableFields('b_tasks_tag');
            if (isset($linkFields['TASK_ID'],$linkFields['TAG_ID'],$tagFields['ID'],$tagFields['NAME'])) {
                foreach (array_chunk(array_keys($tasks),500) as $ids) {
                    $rows = $connection->query('SELECT L.TASK_ID, T.NAME FROM b_tasks_task_tag L INNER JOIN b_tasks_tag T ON T.ID=L.TAG_ID WHERE L.TASK_ID IN (' . implode(',',array_map('intval',$ids)) . ')');
                    while ($row=$rows->fetch()) { $tasks[(int)$row['TASK_ID']]['tags'][]=(string)$row['NAME']; }
                }
                $available['tags']=true;
            }
        }
    } catch (Throwable $e) { $warnings[]='Теги недоступны.'; }
}
$stageMap = $config['stage_map']; $stageNames=[];
try {
    if ($connection->isTableExists('b_tasks_stages')) {
        $rows=$connection->query("SELECT ID,TITLE,SYSTEM_TYPE FROM b_tasks_stages WHERE ENTITY_TYPE='G' AND ENTITY_ID=".$groupId);
        while ($row=$rows->fetch()) {
            $id=(int)$row['ID']; $stageNames[$id]=$row['TITLE'];
            if (isset($stageMap[$id])) { continue; }
            if ($row['SYSTEM_TYPE']==='NEW') { $stageMap[$id]='new'; }
            elseif (preg_match('/согласован|утвержден|проверке|review/iu',$row['TITLE'])) { $stageMap[$id]='review'; }
            elseif (preg_match('/в работе|выполнении|progress/iu',$row['TITLE'])) { $stageMap[$id]='work'; }
        }
    }
} catch (Throwable $e) { $warnings[]='Стадии канбана недоступны; используются статусы задач.'; }
$tagOptions=[]; $userIds=[];
foreach ($tasks as &$task) {
    usort($task['logs'],static function($a,$b){ return $a['at'] <=> $b['at']; });
    $task['tags']=array_values(array_unique($task['tags']));
    foreach ($task['tags'] as $tag) { $tagOptions[$tag]=true; }
    foreach (array_merge([$task['responsible']],$task['accomplices'],array_column($task['elapsed'],'user')) as $id) { if ($id) { $userIds[$id]=true; } }
}
unset($task); ksort($tagOptions,SORT_NATURAL);
$users=[];
if ($userIds) {
    $by='last_name'; $order='asc';
    $result=CUser::GetList($by,$order,['ID'=>implode('|',array_keys($userIds))],['FIELDS'=>['ID','NAME','LAST_NAME','LOGIN']]);
    while ($row=$result->Fetch()) { $users[(int)$row['ID']]=trim($row['LAST_NAME'].' '.$row['NAME']) ?: $row['LOGIN']; }
}
$name=static function($id) use ($users) { return $users[$id] ?? ('Пользователь #'.$id); };
$selectedTags=[];
foreach (['direction','product','priority','rk','tag'] as $key) { $value=$get($key); if ($value!=='' && isset($tagOptions[$value])) { $selectedTags[$key]=$value; } }
$employee=(int)$get('employee','0');
$filtered=array_filter($tasks,static function ($task) { return $task['in_group']; });
foreach ($filtered as $id=>$task) {
    $rootId=marketingProjectId($id,$tasks); $root=$tasks[$rootId] ?? $task;
    $tags=array_unique(array_merge($task['tags'],$root['tags']));
    if (array_diff(array_values($selectedTags),$tags) || ($employee && $task['responsible']!==$employee && !in_array($employee,$task['accomplices'],true))) { unset($filtered[$id]); }
}
// Preserve root metadata, without counting filtered-out tasks in statistics.
$reportTasks=$tasks;
foreach ($reportTasks as $id=>&$task) { $task['included']=isset($filtered[$id]); } unset($task);
$current=marketingReport($reportTasks,$fromDate->getTimestamp(),$toDate->getTimestamp(),$now,$staleDays,$config,$stageMap,$available);
$previous=marketingReport($reportTasks,$previousFrom->getTimestamp(),$fromDate->getTimestamp(),$now,$staleDays,$config,$stageMap,$available);
$sort=$get('sort','title');
uasort($current['projects'],static function($a,$b) use ($sort,$tasks) {
    if ($sort==='days' || $sort==='hours' || $sort==='returns') { return ($b[$sort] ?? -1) <=> ($a[$sort] ?? -1); }
    if ($sort==='tags') { return strcmp(implode(', ',$tasks[$a['id']]['tags'] ?? []),implode(', ',$tasks[$b['id']]['tags'] ?? [])); }
    return strcmp($a['title'],$b['title']);
});
$fmt=static function($number) { return $number===null ? 'Нет данных' : number_format($number, is_float($number)?1:0, ',', ' '); };
$viewerId = (int)$USER->GetID();
$link=static function($id,$title) use ($h,$groupId,$tasks,$viewerId) {
    if (!$id) { return $h($title); }
    $taskGroup = (int)($tasks[$id]['group'] ?? $groupId);
    $base = $taskGroup > 0 ? '/workgroups/group/'.$taskGroup.'/' : '/company/personal/user/'.$viewerId.'/';
    return '<a href="'.$base.'tasks/task/view/'.(int)$id.'/" target="_blank" rel="noopener noreferrer">'.$h($title).'</a>';
};
?>
<style>
.md{font:14px/1.5 Arial,sans-serif;color:#172a45;max-width:1600px;margin:auto}.md *{box-sizing:border-box}.md h1{font-size:27px;margin:0}.md h2{font-size:19px;margin:0 0 16px}.md .muted{color:#65748b}.md .panel{background:#fff;border:1px solid #dfe6ee;border-radius:12px;padding:22px;margin:18px 0}.md .filters{display:flex;flex-wrap:wrap;gap:14px;align-items:end}.md label{display:flex;flex-direction:column;gap:5px}.md input,.md select,.md button{padding:9px;border:1px solid #bcc9d8;border-radius:6px;font:inherit;max-width:250px}.md button{background:#1565cf;color:white;cursor:pointer}.md .cards{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}.md .card{background:#f4f8ff;border:1px solid #dde8fa;border-radius:10px;padding:18px}.md .value{font-size:29px;font-weight:bold}.md summary{cursor:pointer;font-size:19px;font-weight:bold}.md details[open]>summary{margin-bottom:16px}.md summary:focus-visible{outline:2px solid #1565cf;outline-offset:5px}.md .scroll{overflow:auto}.md table{width:100%;border-collapse:collapse;white-space:nowrap}.md th,.md td{padding:11px 13px;border-bottom:1px solid #e7edf3;text-align:left}.md th{background:#f4f7fa}.md td.wrap{white-space:normal;min-width:220px}.md a{color:#1565cf}.md .funnel{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.md progress{width:100%;height:14px;accent-color:#1565cf}.md .notice{background:#fff6dc;padding:12px;border-radius:8px;margin:10px 0}@media(max-width:800px){.md .cards,.md .funnel{grid-template-columns:repeat(2,1fr)}.md .panel{padding:14px}}
</style>
<div class="md">
<h1>Маркетинг · задачи и проекты</h1><p class="muted">Группа #<?= $groupId ?> · <?= $h($today->format('d.m.Y H:i')) ?> МСК · Все задачи группы · выборка от лица администратора</p>
<form class="panel filters" method="get">
<label>Период<select name="period"><?php foreach(['month'=>'Текущий месяц','quarter'=>'Текущий квартал','custom'=>'Произвольный'] as $v=>$label): ?><option value="<?= $v ?>" <?= $period===$v?'selected':'' ?>><?= $label ?></option><?php endforeach ?></select></label>
<label>С<input type="date" name="from" value="<?= $h($fromDate->format('Y-m-d')) ?>"></label><label>По<input type="date" name="to" value="<?= $h($toDate->modify('-1 day')->format('Y-m-d')) ?>"></label>
<label>Без движения более, дней<input type="number" name="stale" min="1" max="3650" value="<?= $staleDays ?>"></label>
<label>Сотрудник<select name="employee"><option value="0">Все</option><?php foreach($users as $id=>$label): ?><option value="<?= $id ?>" <?= $employee===$id?'selected':'' ?>><?= $h($label) ?></option><?php endforeach ?></select></label>
<?php foreach(['direction'=>'Направление','product'=>'Продукт','priority'=>'Приоритетность','rk'=>'Уровень РК','tag'=>'Любой тег'] as $key=>$label): ?><label><?= $label ?><select name="<?= $key ?>"><option value="">Все теги</option><?php foreach($tagOptions as $tag=>$unused): ?><option value="<?= $h($tag) ?>" <?= ($selectedTags[$key] ?? '')===$tag?'selected':'' ?>><?= $h($tag) ?></option><?php endforeach ?></select></label><?php endforeach ?>
<label>Сортировка проектов<select name="sort"><?php foreach(['title'=>'Название','tags'=>'Теги','days'=>'Рабочие дни','hours'=>'Трудозатраты','returns'=>'Возвраты'] as $v=>$label): ?><option value="<?= $v ?>" <?= $sort===$v?'selected':'' ?>><?= $label ?></option><?php endforeach ?></select></label><button type="submit">Применить</button><a href="?">Сбросить</a>
</form>
<?php foreach($warnings as $warning): ?><div class="notice"><?= $h($warning) ?></div><?php endforeach ?>
<?php if (!$available['history']): ?><div class="notice">История изменений недоступна: возвраты и исторические статусы не рассчитываются достоверно. Сравнение числа задач и часов доступно; историческая воронка и состояние проектов скрыты.</div><?php endif ?>
<?php if (!$available['elapsed']): ?><div class="notice">Учёт времени недоступен. Трудозатраты не заменяются длительностью проекта.</div><?php endif ?>
<?php if (!$tasks): ?><div class="notice">В группе нет задач.</div><?php endif ?>
<div class="cards"><?php foreach(['Задачи'=>$current['tasks'],'Проекты'=>$current['projectCount'],'Активные проекты'=>$available['history']?$current['active']:null,'Завершённые проекты'=>$available['history']?$current['done']:null,'Выполнено в срок, %'=>$current['percent'],'Возвраты'=>$available['history']?$current['returns']:null,'Без движения > '.$staleDays.' дней'=>count($current['stale']),'Трудозатраты, ч'=>$available['elapsed']?$current['hours']:null] as $label=>$value): ?><div class="card"><div class="muted"><?= $h($label) ?></div><div class="value"><?= $fmt($value) ?></div></div><?php endforeach ?></div>
<section class="panel"><h2>Воронка на конец периода</h2><div class="funnel"><?php foreach(['new'=>'Новая / отложена','work'=>'В работе','review'=>'Согласование','done'=>'Завершена'] as $key=>$label): ?><div><?= $label ?><div class="value"><?= $available['history']?$current['funnel'][$key]:'—' ?></div><progress max="<?= max(1,$current['tasks']) ?>" value="<?= $available['history']?$current['funnel'][$key]:0 ?>"></progress></div><?php endforeach ?></div></section>
<details class="panel md-list"><summary>Сотрудники</summary><div class="scroll"><table><thead><tr><th>Сотрудник</th><th>Задачи (ответственный)</th><th>Завершено за период</th><th>Возвраты</th><th>Без движения</th><th>Записано часов</th></tr></thead><tbody><?php foreach($current['employees'] as $id=>$employeeRow): ?><tr><td><?= $h($name($id)) ?></td><?php foreach(['tasks','done','returns','stale','hours'] as $key): ?><td><?= $fmt(($key==='returns' && !$available['history']) || ($key==='hours' && !$available['elapsed']) ? null : $employeeRow[$key]) ?></td><?php endforeach ?></tr><?php endforeach ?></tbody></table></div></details>
<details class="panel md-list"><summary>Проекты — корневые задачи</summary><div class="scroll"><table><thead><tr><th>Проект / теги</th><th>Ответственный / соисполнители</th><th>Состояние</th><th>Рабочие дни в работе</th><th>Задач</th><th>Возвраты</th><th>Часы за период / сотрудники</th></tr></thead><tbody><?php foreach($current['projects'] as $project): ?><tr><td class="wrap"><?= $link($project['id'],$project['title']) ?><div class="muted"><?= $h(implode(', ',$tasks[$project['id']]['tags'] ?? [])) ?></div></td><td class="wrap"><?= $project['responsible']?$h($name($project['responsible'])):'Недоступен' ?><div class="muted"><?php $members=array_diff(array_keys($project['members']),[$project['responsible']]); echo $h(implode(', ',array_map($name,$members))); ?></div></td><td><?= !$available['history']?'Нет данных':($project['state']===null?'Недоступно':($project['state']==='done'?'Завершён':'Активен')) ?></td><td><?= $fmt($project['days']) ?></td><td><?= $project['tasks'] ?></td><td><?= $fmt($available['history']?$project['returns']:null) ?></td><td><?= $fmt($available['elapsed']?$project['hours']:null) ?><?php if ($available['elapsed']): foreach ($project['employeeHours'] as $worker=>$hours): ?><div class="muted"><?= $h($name($worker)) ?>: <?= $fmt($hours) ?> ч</div><?php endforeach; endif ?></td></tr><?php endforeach ?></tbody></table></div></details>
<details class="panel md-list"><summary>Задачи без движения</summary><div class="scroll"><table><thead><tr><th>Задача</th><th>Ответственный</th><th>Дней без движения</th></tr></thead><tbody><?php foreach($current['stale'] as $id=>$item): ?><tr><td class="wrap"><?= $link($id,$item['title']) ?></td><td><?= $h($name($tasks[$id]['responsible'])) ?></td><td><?= $item['days'] ?></td></tr><?php endforeach ?><?php if (!$current['stale']): ?><tr><td colspan="3">Нет задач по выбранным условиям</td></tr><?php endif ?></tbody></table></div></details>
<details class="panel md-list"><summary>Возвраты по задачам</summary><div class="scroll"><table><thead><tr><th>Задача</th><th>Ответственный</th><th>Возвраты за период</th></tr></thead><tbody><?php foreach($current['taskReturns'] as $id=>$count): ?><tr><td class="wrap"><?= $link($id,$tasks[$id]['title']) ?></td><td><?= $h($name($tasks[$id]['responsible'])) ?></td><td><?= $available['history']?$count:'—' ?></td></tr><?php endforeach ?></tbody></table></div></details>
<details class="panel md-list"><summary>Сравнение периодов</summary><p class="muted">Текущий: <?= $h($fromDate->format('d.m.Y').' — '.$toDate->modify('-1 day')->format('d.m.Y')) ?>; предыдущий: <?= $h($previousFrom->format('d.m.Y').' — '.$fromDate->modify('-1 day')->format('d.m.Y')) ?>. Текущий период рассчитан по момент обновления.</p><div class="scroll"><table><thead><tr><th>Показатель</th><th>Текущий</th><th>Предыдущий</th><th>Изменение</th></tr></thead><tbody><?php foreach(['tasks'=>'Задачи','active'=>'Активные проекты','done'=>'Завершённые проекты','closed'=>'Завершённые задачи за период','percent'=>'В срок, %','returns'=>'Возвраты','hours'=>'Трудозатраты, ч'] as $key=>$label): $a=$current[$key]; $b=$previous[$key]; if ((!$available['history'] && in_array($key,['active','done','returns'],true)) || (!$available['elapsed'] && $key==='hours')) { $a=$b=null; } ?><tr><td><?= $label ?></td><td><?= $fmt($a) ?></td><td><?= $fmt($b) ?></td><td><?= $a===null||$b===null?'—':(($a-$b>0?'+':'').$fmt($a-$b).($key==='percent'?' п.п.':'')) ?></td></tr><?php endforeach ?><tr><td>Проекты</td><td><?= $current['projectCount'] ?></td><td><?= $previous['projectCount'] ?></td><td><?= $current['projectCount']-$previous['projectCount'] ?></td></tr></tbody></table></div></details>
<details class="panel"><summary>Как считаются показатели</summary><p>Проект — корневая задача. В период входят задачи, созданные до его конца, кроме завершённых до его начала. Ответственные и соисполнители берутся из текущих назначений. При фильтре по сотруднику включаются также задачи, где он соисполнитель; количество в таблице относится к ответственному. Фильтры тегов работают совместно (И) и учитывают теги корневого проекта.</p><p>В срок — завершённые в период задачи с указанным текущим дедлайном; без срока исключены из знаменателя (<?= $current['withDeadline'] ?>). Возврат — переход из согласования/завершения в новую/в работу либо из стадии согласования в работу. Атрибуция возвратов — текущему ответственному. Отложенные и отклонённые задачи входят в «Новая / отложена»; отклонённые не включаются в зависшие.</p><p>Рабочие дни — от фактического начала корневой задачи (если отсутствует, от создания) до завершения или конца периода, включительно. Используется пятидневка и праздники РФ; переносы выходных настраиваются в dashboard_config.php. Это длительность, включая паузы. Часы — фактически записанное время по дате записи, а не длительность проекта. «Без движения» использует последнюю активность/изменение, включая комментарии, и календарные дни.</p><p>Исторические статусы восстановлены из сохранившегося журнала. Удалённые записи, изменения дедлайна, состава команды и тегов не восстанавливаются. Показатели отражают все задачи группы. Родительские задачи из других групп используются только как сведения о проекте. Удалённая или не найденная корневая задача обозначена отдельно и исключена из количества проектов.</p></details>
</div>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
