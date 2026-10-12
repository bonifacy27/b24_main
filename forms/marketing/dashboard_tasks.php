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
