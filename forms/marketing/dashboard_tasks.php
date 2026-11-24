<?php
/** Fetch group tasks and ancestor metadata; ancestors never expand report scope. */
function marketingLoadDashboardTasks(callable $fetch, $groupId)
{
    $tasks = [];
    foreach ($fetch(['GROUP_ID'=>(int)$groupId, 'REAL_STATUS'=>[1,2,3,4,5,6,7], 'ONLY_ROOT_TASKS'=>'N']) as $row) {
        $row['IN_REPORT_GROUP'] = true;
        $tasks[(int)$row['ID']] = $row;
    }
    $attempted = [];
    while (true) {
        $missing = [];
        foreach ($tasks as $row) {
            $parent = (int)$row['PARENT_ID'];
            if ($parent > 0 && !isset($tasks[$parent]) && !isset($attempted[$parent])) { $missing[$parent] = $parent; }
        }
        if (!$missing) { break; }
        foreach (array_chunk(array_values($missing),500) as $ids) {
            foreach ($ids as $id) { $attempted[$id] = true; }
            foreach ($fetch(['ID'=>$ids, 'ONLY_ROOT_TASKS'=>'N']) as $row) {
                $id = (int)$row['ID'];
                if (!isset($missing[$id])) { continue; }
                $row['IN_REPORT_GROUP'] = false;
                $tasks[$id] = $row;
            }
        }
    }
    return array_values($tasks);
}

/** Read both tag formats because upgraded portals can retain the legacy columns. */
function marketingLoadDashboardTags($connection, array $taskIds)
{
    $tags = [];
    if (!$taskIds || !$connection->isTableExists('b_tasks_tag')) { return $tags; }

    $fields = $connection->getTableFields('b_tasks_tag');
    $queries = [];
    if (isset($fields['TASK_ID'], $fields['NAME'])) {
        $queries[] = 'SELECT TASK_ID, NAME FROM b_tasks_tag WHERE TASK_ID IN ';
    }
    if (isset($fields['ID'], $fields['NAME']) && $connection->isTableExists('b_tasks_task_tag')) {
        $linkFields = $connection->getTableFields('b_tasks_task_tag');
        if (isset($linkFields['TASK_ID'], $linkFields['TAG_ID'])) {
            $queries[] = 'SELECT L.TASK_ID, T.NAME FROM b_tasks_task_tag L INNER JOIN b_tasks_tag T ON T.ID=L.TAG_ID WHERE L.TASK_ID IN ';
        }
    }
    foreach (array_chunk($taskIds, 500) as $ids) {
        $in = '(' . implode(',', array_map('intval', $ids)) . ')';
        foreach ($queries as $query) {
            $rows = $connection->query($query . $in);
            while ($row = $rows->fetch()) {
                if ((string)$row['NAME'] !== '') { $tags[(int)$row['TASK_ID']][] = (string)$row['NAME']; }
            }
        }
    }
    return $tags;
}
