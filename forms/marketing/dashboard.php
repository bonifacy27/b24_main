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
$fromDate = $parseDate($get('from', $today->modify('first day of this month')->format('Y-m-d')));
$endDate = $parseDate($get('to', $today->modify('last day of this month')->format('Y-m-d')));
if (!$fromDate || !$endDate || $endDate < $fromDate || $endDate->diff($fromDate)->days > 3660) {
    echo '<p>Укажите корректный диапазон дат (не более 10 лет).</p>';
    require($_SERVER['DOCUMENT_ROOT'].'/bitrix/footer.php');
    return;
}
$toDate = $endDate->modify('+1 day');
$previousFrom = $fromDate->modify('-' . $toDate->diff($fromDate)->days . ' days');
$staleDays = 7;
$readMultiple = static function ($key) {
    $values = $_GET[$key] ?? [];
    return is_array($values) ? array_values(array_unique(array_filter($values, 'is_string'))) : [];
};
$positiveIds = static function (array $values) {
    $ids = array_map(static function ($value) {
        return preg_match('/^[0-9]+$/D', $value) ? (int)$value : 0;
    }, $values);
    return array_values(array_unique(array_filter($ids)));
};
$selectedEmployees = $positiveIds(array_merge($readMultiple('employees'), [$get('employee')]));
$selectedDepartments = $positiveIds($readMultiple('departments'));
$departmentPickerAvailable = Loader::includeModule('intranet');
$statusNames = [
    1=>'Новая', 2=>'Ждёт выполнения', 3=>'В работе', 4=>'Ждёт контроля',
    5=>'Завершена', 6=>'Отложена', 7=>'Отклонена',
];
\CJSCore::Init(['ui.entity-selector']);
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
            ['MAKE_ACCESS_FILTER'=>true, 'SORTING_GROUP_ID'=>(int)($filter['GROUP_ID'] ?? 0)],
            ['ID','TITLE','GROUP_ID','PARENT_ID','STATUS','STAGE_ID','RESPONSIBLE_ID','CREATED_BY','CREATED_DATE','CHANGED_DATE','ACTIVITY_DATE','CLOSED_DATE','DEADLINE','DATE_START']);
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
    $tasks[$id] = ['id'=>$id,'in_group'=>!empty($row['IN_REPORT_GROUP']),'group'=>(int)($row['GROUP_ID'] ?? $groupId),'title'=>(string)$row['TITLE'],'parent'=>(int)$row['PARENT_ID'],'status'=>(int)$row['STATUS'],'stage'=>(int)($row['STAGE_ID'] ?? 0), 'responsible'=>(int)$row['RESPONSIBLE_ID'],'creator'=>(int)$row['CREATED_BY'],
        'created'=>$timestamp($row['CREATED_DATE']), 'changed'=>$timestamp($row['ACTIVITY_DATE'] ?? $row['CHANGED_DATE']), 'closed'=>$timestamp($row['CLOSED_DATE']), 'deadline'=>$timestamp($row['DEADLINE']), 'start'=>$timestamp($row['DATE_START']), 'accomplices'=>[], 'tags'=>[], 'logs'=>[], 'elapsed'=>[]];
}
$connection = \Bitrix\Main\Application::getConnection();
$available = ['history'=>false,'elapsed'=>false];
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
    if ($at) { $tasks[(int)$row['TASK_ID']]['logs'][] = ['at'=>$at,'id'=>(int)($row['ID'] ?? 0),'field'=>$row['FIELD'],'from'=>$row['FROM_VALUE'],'to'=>$row['TO_VALUE']]; }
});
$available['elapsed'] = $load('b_tasks_elapsed_time',['TASK_ID','USER_ID','CREATED_DATE','SECONDS'],static function ($row) use (&$tasks,$timestamp) {
    $at = $timestamp($row['CREATED_DATE']);
    if ($at) { $tasks[(int)$row['TASK_ID']]['elapsed'][] = ['at'=>$at,'user'=>(int)$row['USER_ID'],'seconds'=>max(0,(int)$row['SECONDS'])]; }
});
try {
    $fetchTags = static function (array $ids) {
        return CTaskTags::GetList([], ['TASK_ID'=>$ids]);
    };
    foreach (marketingLoadDashboardTags($fetchTags, array_keys($tasks)) as $id => $tags) {
        $tasks[$id]['tags'] = $tags;
    }
} catch (Throwable $e) {
    $warnings[] = 'Не удалось загрузить теги задач.';
    AddMessage2Log('Marketing dashboard: cannot read task tags: ' . $e->getMessage(), 'tasks');
}
$stageMap = $config['stage_map']; $stageNames=[]; $defaultStage=null;
try {
    if ($connection->isTableExists('b_tasks_stages')) {
        $rows=$connection->query("SELECT ID,TITLE,SYSTEM_TYPE FROM b_tasks_stages WHERE ENTITY_TYPE='G' AND ENTITY_ID=".$groupId." ORDER BY SORT, ID");
        while ($row=$rows->fetch()) {
            $id=(int)$row['ID']; $stageNames[$id]=$row['TITLE'];
            if ($row['SYSTEM_TYPE']==='NEW') { $defaultStage=$id; }
            if (isset($stageMap[$id])) { continue; }
            if ($row['SYSTEM_TYPE']==='NEW') { $stageMap[$id]='new'; }
            elseif (preg_match('/согласован|утвержден|проверке|review/iu',$row['TITLE'])) { $stageMap[$id]='review'; }
            elseif (preg_match('/в работе|выполнении|progress/iu',$row['TITLE'])) { $stageMap[$id]='work'; }
        }
    }
} catch (Throwable $e) { $warnings[]='Стадии канбана недоступны; используются статусы задач.'; }
$tagOptions=[]; $userIds=[];
foreach ($tasks as &$task) {
    usort($task['logs'],static function($a,$b){ return ($a['at'] <=> $b['at']) ?: ($a['id'] <=> $b['id']); });
    $task['tags']=array_values(array_unique($task['tags']));
    if ($task['in_group']) {
        foreach ($task['tags'] as $tag) { $tagOptions[$tag]=true; }
    }
    foreach (array_merge([$task['responsible'],$task['creator']],$task['accomplices'],array_column($task['elapsed'],'user')) as $id) { if ($id) { $userIds[$id]=true; } }
}
unset($task); ksort($tagOptions,SORT_NATURAL);
$userIds += array_fill_keys($selectedEmployees, true);
$users = [];
$userDepartments = [];
$departmentIds = array_fill_keys($selectedDepartments, true);
if ($userIds) {
    $by='last_name'; $order='asc';
    $result=CUser::GetList($by,$order,['ID'=>implode('|',array_keys($userIds))],[
        'FIELDS'=>['ID','NAME','LAST_NAME','LOGIN'],
        'SELECT'=>['UF_DEPARTMENT'],
    ]);
    while ($row=$result->Fetch()) {
        $id = (int)$row['ID'];
        $users[$id] = trim($row['LAST_NAME'].' '.$row['NAME']) ?: $row['LOGIN'];
        $userDepartments[$id] = array_values(array_unique(array_filter(array_map('intval', (array)($row['UF_DEPARTMENT'] ?? [])))));
        $departmentIds += array_fill_keys($userDepartments[$id], true);
    }
}
$departmentNames = [];
if ($departmentIds && Loader::includeModule('iblock')) {
    $structureId = (int)COption::GetOptionInt('intranet', 'iblock_structure', 0);
    if ($structureId) {
        $sections = CIBlockSection::GetList([], ['IBLOCK_ID'=>$structureId, 'ID'=>array_keys($departmentIds)], false, ['ID','NAME']);
        while ($row = $sections->Fetch()) {
            $departmentNames[(int)$row['ID']] = $row['NAME'];
        }
    }
}
$name=static function($id) use ($users) { return $users[$id] ?? ('Пользователь #'.$id); };
$departmentName = static function ($id) use ($departmentNames) {
    return $departmentNames[$id] ?? ('Подразделение #'.$id);
};
$selectedTags = array_values(array_filter($readMultiple('tags'), static function ($tag) use ($tagOptions) {
    return isset($tagOptions[$tag]);
}));
$selectedStatuses = array_values(array_filter(array_map('intval', $readMultiple('statuses')), static function ($status) use ($statusNames) {
    return isset($statusNames[$status]);
}));
$selectedStages = array_values(array_filter(array_map('intval', $readMultiple('stages')), static function ($stage) use ($stageNames) {
    return isset($stageNames[$stage]);
}));
$creatorIds = $selectedEmployees || $selectedDepartments ? array_fill_keys($selectedEmployees, true) : null;
if ($selectedDepartments) {
    try {
        if (!$departmentPickerAvailable) { throw new RuntimeException('Intranet module is unavailable'); }
        $members = CIntranetUtils::GetDepartmentEmployees($selectedDepartments, true);
        if (!is_object($members)) { throw new RuntimeException('Department employee lookup failed'); }
        $departmentEmployees = [];
        while ($member = $members->Fetch()) {
            $departmentEmployees[(int)$member['ID']] = true;
        }
        $creatorIds += $departmentEmployees;
    } catch (Throwable $e) {
        $warnings[] = 'Не удалось получить сотрудников выбранных подразделений. В отчёте учтены сотрудники, выбранные вручную.';
        AddMessage2Log('Marketing dashboard: cannot read department employees: ' . $e->getMessage(), 'tasks');
    }
}
$reportTasks = $tasks;
foreach ($reportTasks as &$task) {
    $task['included'] = $task['in_group']
        && (!$selectedTags || array_intersect($selectedTags, $task['tags']));
}
unset($task);
$current = marketingReport($reportTasks,$fromDate->getTimestamp(),$toDate->getTimestamp(),$now,$staleDays,$config,$stageMap,$available,$selectedStatuses,$selectedStages,$defaultStage,$creatorIds);
$previous = marketingReport($reportTasks,$previousFrom->getTimestamp(),$fromDate->getTimestamp(),$now,$staleDays,$config,$stageMap,$available,$selectedStatuses,$selectedStages,$defaultStage,$creatorIds);
uasort($current['projects'], static function ($a, $b) {
    return strcmp($a['title'], $b['title']);
});
$cutoff = min($toDate->getTimestamp(), $now + 1);
$fmt=static function($number) { return $number===null ? 'Нет данных' : number_format($number, is_float($number)?1:0, ',', ' '); };
$viewerId = (int)$USER->GetID();
$link=static function($id,$title) use ($h,$groupId,$tasks,$viewerId) {
    if (!$id) { return $h($title); }
    $taskGroup = (int)($tasks[$id]['group'] ?? $groupId);
    $base = $taskGroup > 0 ? '/workgroups/group/'.$taskGroup.'/' : '/company/personal/user/'.$viewerId.'/';
    return '<a href="'.$base.'tasks/task/view/'.(int)$id.'/" target="_blank" rel="noopener noreferrer">'.$h($title).'</a>';
};
$renderTimeline = static function ($timeline, array $labels, $fallback) use ($h, $zone) {
    if ($timeline === null) {
        echo '<span class="muted">Нет данных о переходах</span>';
        return;
    }
    $days = static function ($seconds) { return number_format($seconds / 86400, 2, ',', ' ') . ' дн.'; };
    $date = static function ($timestamp) use ($zone) {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($zone)->format('d.m.Y H:i');
    };
    ?>
    <details class="timeline">
        <summary><?= $timeline['completed'] ? 'Завершена за' : 'Всего' ?> <?= $days($timeline['seconds']) ?></summary>
        <ol>
        <?php foreach ($timeline['segments'] as $segment): ?>
            <li>
                <?= $h($segment['state'] === null ? 'Нет данных' : ($labels[$segment['state']] ?? $fallback . ' #' . $segment['state'])) ?>
                · <?= $days($segment['to'] - $segment['from']) ?>
                <div class="muted"><?= $h($date($segment['from']) . ' — ' . $date($segment['to'])) ?></div>
            </li>
        <?php endforeach ?>
        </ol>
    </details>
    <?php
};
?>
<style>
.md{font:14px/1.5 Arial,sans-serif;color:#172a45;max-width:1600px;margin:auto}.md *{box-sizing:border-box}.md h1{font-size:27px;margin:0}.md h2{font-size:19px;margin:0 0 16px}.md .muted{color:#65748b}.md .panel{background:#fff;border:1px solid #dfe6ee;border-radius:12px;padding:22px;margin:18px 0}.md .filters{display:flex;flex-wrap:wrap;gap:14px;align-items:end}.md label{display:flex;flex-direction:column;gap:5px}.md input,.md select,.md button{padding:9px;border:1px solid #bcc9d8;border-radius:6px;font:inherit;max-width:250px}.md button{background:#1565cf;color:white;cursor:pointer}.md .cards{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}.md .card{background:#f4f8ff;border:1px solid #dde8fa;border-radius:10px;padding:18px}.md .value{font-size:29px;font-weight:bold}.md summary{cursor:pointer;font-size:19px;font-weight:bold}.md details[open]>summary{margin-bottom:16px}.md summary:focus-visible{outline:2px solid #1565cf;outline-offset:5px}.md .scroll{overflow:auto}.md table{width:100%;border-collapse:collapse;white-space:nowrap}.md th,.md td{padding:11px 13px;border-bottom:1px solid #e7edf3;text-align:left}.md th{background:#f4f7fa}.md td.wrap{white-space:normal;min-width:220px}.md a{color:#1565cf}.md .funnel{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.md progress{width:100%;height:14px;accent-color:#1565cf}.md .notice{background:#fff6dc;padding:12px;border-radius:8px;margin:10px 0}@media(max-width:800px){.md .cards,.md .funnel{grid-template-columns:repeat(2,1fr)}.md .panel{padding:14px}}
.md .timeline summary{font-size:14px}.md .timeline ol{padding-left:20px;margin:0}.md .timeline li+li{margin-top:8px}.md .employee-picker button{max-width:250px}.md select[multiple]{min-width:165px}
</style>
<div class="md">
<h1>Маркетинг · задачи и подзадачи</h1><p class="muted">Группа #<?= $groupId ?> · <?= $h($today->format('d.m.Y H:i')) ?> МСК · Все задачи группы · выборка от лица администратора</p>
<form class="panel filters" method="get">
<label>С<input type="date" name="from" required value="<?= $h($fromDate->format('Y-m-d')) ?>"></label>
<label>По<input type="date" name="to" required value="<?= $h($endDate->format('Y-m-d')) ?>"></label>
<?php foreach ([
    ['employees', 'user', 'Инициаторы', 'Все сотрудники', $selectedEmployees, true],
    ['departments', 'department', 'Подразделения', 'Выбрать подразделения', $selectedDepartments, $departmentPickerAvailable],
] as [$field, $entity, $label, $placeholder, $selected, $pickerAvailable]): ?>
<div class="employee-picker" data-field="<?= $field ?>" data-entity="<?= $entity ?>" data-placeholder="<?= $h($placeholder) ?>">
    <div id="md-<?= $field ?>-label"><?= $h($label) ?></div>
    <span class="picker-values">
        <?php foreach ($selected as $id): ?><input type="hidden" name="<?= $field ?>[]" value="<?= $id ?>"><?php endforeach ?>
    </span>
    <button type="button" class="picker-select" id="md-<?= $field ?>-select" aria-labelledby="md-<?= $field ?>-label md-<?= $field ?>-select" <?= $pickerAvailable ? '' : 'disabled' ?>><?= $h($selected ? 'Выбрано: ' . count($selected) : $placeholder) ?></button>
    <button type="button" class="picker-clear" aria-label="<?= $h('Очистить выбор: ' . $label) ?>" <?= $selected ? '' : 'hidden' ?>>×</button>
</div>
<?php endforeach ?>
<label>Теги
    <select name="tags[]" multiple size="4">
    <?php foreach ($tagOptions as $tag => $unused): ?>
        <option value="<?= $h($tag) ?>" <?= in_array((string)$tag, $selectedTags, true) ? 'selected' : '' ?>><?= $h($tag) ?></option>
    <?php endforeach ?>
    </select>
</label>
<label>Статус
    <select name="statuses[]" multiple size="4">
    <?php foreach ($statusNames as $id => $label): ?>
        <option value="<?= $id ?>" <?= in_array($id, $selectedStatuses, true) ? 'selected' : '' ?>><?= $h($label) ?></option>
    <?php endforeach ?>
    </select>
</label>
<label>Стадия канбана
    <select name="stages[]" multiple size="4" <?= $stageNames ? '' : 'disabled' ?>>
    <?php foreach ($stageNames as $id => $label): ?>
        <option value="<?= $id ?>" <?= in_array($id, $selectedStages, true) ? 'selected' : '' ?>><?= $h($label) ?></option>
    <?php endforeach ?>
    <?php if (!$stageNames): ?><option>Стадии недоступны</option><?php endif ?>
    </select>
</label>
<button type="submit">Применить</button><a href="?">Сбросить</a>
</form>
<script>
BX.ready(function () {
    document.querySelectorAll('.md .employee-picker').forEach(function (picker) {
        const {field, entity, placeholder} = picker.dataset;
        const values = picker.querySelector('.picker-values');
        const button = picker.querySelector('.picker-select');
        const clear = picker.querySelector('.picker-clear');
        const selected = new Set(Array.from(values.querySelectorAll('input'), function (input) {
            return Number(input.value);
        }));

        function renderSelection() {
            values.replaceChildren();
            selected.forEach(function (id) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = field + '[]';
                input.value = id;
                values.append(input);
            });
            button.textContent = selected.size ? 'Выбрано: ' + selected.size : placeholder;
            clear.hidden = !selected.size;
        }

        let dialog;
        if (!button.disabled && BX.UI && BX.UI.EntitySelector) {
            dialog = new BX.UI.EntitySelector.Dialog({
                targetNode: button,
                context: 'MARKETING_DASHBOARD_' + field.toUpperCase(),
                multiple: true,
                enableSearch: true,
                entities: [entity === 'department'
                    ? {id: entity, options: {selectMode: 'departmentsOnly'}}
                    : {id: entity}],
                preselectedItems: Array.from(selected, function (id) { return [entity, id]; }),
                events: {
                    'Item:onSelect': function (event) {
                        selected.add(Number(event.getData().item.getId()));
                        renderSelection();
                    },
                    'Item:onDeselect': function (event) {
                        selected.delete(Number(event.getData().item.getId()));
                        renderSelection();
                    }
                }
            });
            button.addEventListener('click', function () { dialog.show(); });
        } else {
            button.disabled = true;
        }
        clear.addEventListener('click', function () {
            if (dialog) {
                dialog.getSelectedItems().forEach(function (item) { item.deselect(); });
            }
            selected.clear();
            renderSelection();
        });
    });
});
</script>

<?php foreach($warnings as $warning): ?><div class="notice"><?= $h($warning) ?></div><?php endforeach ?>
<?php if (!$available['history']): ?><div class="notice">История изменений недоступна: возвраты и исторические статусы не рассчитываются достоверно. Сравнение числа задач и часов доступно; исторические воронки и состояние задач недоступны.</div><?php endif ?>
<?php if (!$available['elapsed']): ?><div class="notice">Учёт времени недоступен. Трудозатраты не заменяются длительностью задачи.</div><?php endif ?>
<?php if (!$tasks): ?><div class="notice">В группе нет задач.</div><?php endif ?>
<?php if ($selectedEmployees || $selectedDepartments): ?>
<div class="panel" aria-label="Применённый отбор по инициаторам">
    <?php if ($selectedEmployees): ?>
    <div><strong>Выбранные инициаторы</strong> · <?= $h(implode(', ', array_map($name, $selectedEmployees))) ?></div>
    <?php endif ?>
    <?php if ($selectedDepartments): ?>
    <div><strong>Выбранные подразделения</strong> · <?= $h(implode(', ', array_map($departmentName, $selectedDepartments))) ?></div>
    <?php endif ?>
</div>
<?php endif ?>
<div class="cards"><?php foreach(['Задачи'=>$current['projectCount'],'Подзадачи'=>$current['subtasks'],'Активные задачи'=>$available['history']?$current['active']:null,'Завершённые задачи'=>$available['history']?$current['done']:null,'Выполнено в срок, %'=>$current['percent'],'Возвраты'=>$available['history']?$current['returns']:null,'Без движения > '.$staleDays.' дней'=>count($current['stale']),'Трудозатраты, ч'=>$available['elapsed']?$current['hours']:null] as $label=>$value): ?><div class="card"><div class="muted"><?= $h($label) ?></div><div class="value"><?= $fmt($value) ?></div></div><?php endforeach ?></div>
<section class="panel"><h2>Распределение задач и подзадач на конец периода</h2><div class="funnel"><?php foreach(['new'=>'Новая / отложена','work'=>'В работе','review'=>'Согласование','done'=>'Завершена'] as $key=>$label): ?><div><?= $label ?><div class="value"><?= $available['history']?$current['funnel'][$key]:'—' ?></div><progress max="<?= max(1,$current['tasks']) ?>" value="<?= $available['history']?$current['funnel'][$key]:0 ?>"></progress></div><?php endforeach ?></div></section>
<details class="panel md-list"><summary>Сотрудники</summary><div class="scroll"><table><thead><tr><th>Сотрудник</th><th>Задачи и подзадачи (ответственный)</th><th>Завершено за период</th><th>Возвраты</th><th>Без движения</th><th>Записано часов</th></tr></thead><tbody><?php foreach($current['employees'] as $id=>$employeeRow): ?><tr><td><?= $h($name($id)) ?></td><?php foreach(['tasks','done','returns','stale','hours'] as $key): ?><td><?= $fmt(($key==='returns' && !$available['history']) || ($key==='hours' && !$available['elapsed']) ? null : $employeeRow[$key]) ?></td><?php endforeach ?></tr><?php endforeach ?></tbody></table></div></details>
<details class="panel md-list"><summary>Задачи</summary><div class="scroll"><table><thead><tr><th scope="col">Инициатор / подразделение</th><th>Задача / теги</th><th>Ответственный / соисполнители</th><th>Состояние</th><th>Рабочие дни в работе</th><th>Подзадач</th><th>Возвраты</th><th>Часы за период / сотрудники</th><th>Воронка по статусам</th><th>Воронка по стадиям канбана</th></tr></thead><tbody><?php foreach($current['projects'] as $project): ?>
<?php
$root = $tasks[$project['id']] ?? null;
$creator = $root['creator'] ?? 0;
$departments = $userDepartments[$creator] ?? [];
?>
<tr>
<td class="wrap">
    <?= $creator ? $h($name($creator)) : '—' ?>
    <?php if ($departments): ?>
        <div class="muted"><?= $h(implode(', ', array_map($departmentName, $departments))) ?></div>
    <?php endif ?>
</td>
<td class="wrap"><?= $link($project['id'],$project['title']) ?><?php if ($creatorIds !== null && $project['id'] !== null && !isset($creatorIds[$tasks[$project['id']]['creator']])): ?><div class="muted">Родительская задача подзадач из выборки</div><?php endif ?><div class="muted"><?= $h(implode(', ',$tasks[$project['id']]['tags'] ?? [])) ?></div></td><td class="wrap"><?= $project['responsible']?$h($name($project['responsible'])):'Недоступен' ?><div class="muted"><?php $members=array_diff(array_keys($project['members']),[$project['responsible']]); echo $h(implode(', ',array_map($name,$members))); ?></div></td><td><?= !$available['history']?'Нет данных':($project['state']===null?'Недоступно':($project['state']==='done'?'Завершён':'Активен')) ?></td><td><?= $fmt($project['days']) ?></td><td><?= $project['tasks'] ?></td><td><?= $fmt($available['history']?$project['returns']:null) ?></td><td><?= $fmt($available['elapsed']?$project['hours']:null) ?><?php if ($available['elapsed']): foreach ($project['employeeHours'] as $worker=>$hours): ?><div class="muted"><?= $h($name($worker)) ?>: <?= $fmt($hours) ?> ч</div><?php endforeach; endif ?></td>
<td><?php $renderTimeline($available['history'] && $root ? marketingTaskTimeline($root, 'STATUS', $cutoff) : null, $statusNames, 'Статус'); ?></td>
<td><?php $renderTimeline($available['history'] && $stageNames && !empty($root['in_group']) ? marketingTaskTimeline($root, 'STAGE_ID', $cutoff, $defaultStage) : null, $stageNames, 'Стадия'); ?></td>
</tr><?php endforeach ?></tbody></table></div></details>
<details class="panel md-list"><summary>Задачи и подзадачи без движения</summary><div class="scroll"><table><thead><tr><th>Задача</th><th>Ответственный</th><th>Дней без движения</th></tr></thead><tbody><?php foreach($current['stale'] as $id=>$item): ?><tr><td class="wrap"><?= $link($id,$item['title']) ?></td><td><?= $h($name($tasks[$id]['responsible'])) ?></td><td><?= $item['days'] ?></td></tr><?php endforeach ?><?php if (!$current['stale']): ?><tr><td colspan="3">Нет задач по выбранным условиям</td></tr><?php endif ?></tbody></table></div></details>
<details class="panel md-list"><summary>Возвраты по задачам и подзадачам</summary><div class="scroll"><table><thead><tr><th>Задача</th><th>Ответственный</th><th>Возвраты за период</th></tr></thead><tbody><?php foreach($current['taskReturns'] as $id=>$count): ?><tr><td class="wrap"><?= $link($id,$tasks[$id]['title']) ?></td><td><?= $h($name($tasks[$id]['responsible'])) ?></td><td><?= $available['history']?$count:'—' ?></td></tr><?php endforeach ?></tbody></table></div></details>
<details class="panel md-list"><summary>Сравнение периодов</summary><p class="muted">Текущий: <?= $h($fromDate->format('d.m.Y').' — '.$toDate->modify('-1 day')->format('d.m.Y')) ?>; предыдущий: <?= $h($previousFrom->format('d.m.Y').' — '.$fromDate->modify('-1 day')->format('d.m.Y')) ?>. Текущий период рассчитан по момент обновления.</p><div class="scroll"><table><thead><tr><th>Показатель</th><th>Текущий</th><th>Предыдущий</th><th>Изменение</th></tr></thead><tbody><?php foreach(['subtasks'=>'Подзадачи','active'=>'Активные задачи','done'=>'Завершённые задачи','closed'=>'Завершено задач и подзадач за период','percent'=>'В срок, %','returns'=>'Возвраты','hours'=>'Трудозатраты, ч'] as $key=>$label): $a=$current[$key]; $b=$previous[$key]; if ((!$available['history'] && in_array($key,['active','done','returns'],true)) || (!$available['elapsed'] && $key==='hours')) { $a=$b=null; } ?><tr><td><?= $label ?></td><td><?= $fmt($a) ?></td><td><?= $fmt($b) ?></td><td><?= $a===null||$b===null?'—':(($a-$b>0?'+':'').$fmt($a-$b).($key==='percent'?' п.п.':'')) ?></td></tr><?php endforeach ?><tr><td>Задачи</td><td><?= $current['projectCount'] ?></td><td><?= $previous['projectCount'] ?></td><td><?= $current['projectCount']-$previous['projectCount'] ?></td></tr></tbody></table></div></details>
<details class="panel">
    <summary>Как считаются показатели</summary>
    <p>Задачи верхнего уровня объединяют свои подзадачи. По постановщику учитываются все выбранные сотрудники и сотрудники выбранных подразделений, включая вложенные отделы. Фильтр «Теги» ищет любое из выбранных значений среди собственных тегов задачи или подзадачи. Фильтры «Статус» и «Стадия канбана» используют состояние на конец каждого сравниваемого диапазона. Все заполненные фильтры применяются одновременно.</p>
    <p>Воронки показывают календарное время от создания задачи до завершения или конца выбранного диапазона. Для текущего диапазона расчёт ограничен моментом обновления. При повторном открытии показываются все зафиксированные интервалы до итогового завершения. Названия стадий берутся из канбана группы, переходы — из сохранившегося журнала.</p>
    <p>Рабочие дни считаются по пятидневке и праздничным дням РФ от фактического начала задачи (или создания) до завершения или конца диапазона. Трудозатраты — время, записанное сотрудниками в выбранном диапазоне. В долю завершённых в срок входят завершённые задачи и подзадачи с указанным дедлайном. Порог для показателя «Без движения» — <?= $staleDays ?> календарных дней с последней активности.</p>
</details>
</div>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
