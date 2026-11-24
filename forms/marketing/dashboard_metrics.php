<?php
/** Pure analytics; timestamps are normalized by the Bitrix adapter. */
function marketingWorkingDays($start, $end, array $calendar)
{
    if (!$start || !$end || $end < $start) { return null; }
    $day = (new DateTimeImmutable('@' . $start))->setTimezone(new DateTimeZone($calendar['timezone']))->setTime(0, 0);
    $last = (new DateTimeImmutable('@' . $end))->setTimezone($day->getTimezone())->setTime(0, 0);
    $count = 0;
    while ($day <= $last) {
        $date = $day->format('Y-m-d');
        if (in_array($date, $calendar['working_dates'], true) ||
            (!in_array((int)$day->format('N'), $calendar['weekends'], true) &&
             !in_array($date, $calendar['holidays'], true) &&
             !in_array($day->format('j.n'), $calendar['annual_holidays'], true))) { ++$count; }
        $day = $day->modify('+1 day');
    }
    return $count;
}
function marketingProjectId($id, array $tasks)
{
    $seen = [];
    while (isset($tasks[$id]) && (int)$tasks[$id]['parent'] > 0) {
        if (isset($seen[$id])) { return null; }
        $seen[$id] = true;
        $id = (int)$tasks[$id]['parent'];
    }
    return isset($tasks[$id]) ? $id : null;
}
function marketingStage($status, $stage, array $stageMap)
{
    if ((int)$status === 5) { return 'done'; }
    if ((int)$status === 4) { return 'review'; }
    if (isset($stageMap[$stage])) { return $stageMap[$stage]; }
    return (int)$status === 3 ? 'work' : 'new';
}
function marketingFieldAt(array $task, $field, $cutoff)
{
    $value = $task[$field === 'STATUS' ? 'status' : 'stage'];
    foreach (array_reverse($task['logs']) as $log) {
        if ($log['at'] < $cutoff) { break; }
        if ($log['field'] === $field) { $value = (int)$log['from']; }
    }
    return $value;
}

function marketingStatusAt(array $task, $cutoff)
{
    $status = marketingFieldAt($task, 'STATUS', $cutoff);
    // Older records may have a completion date but no corresponding status event.
    if ($status === 5 && $task['closed'] && $task['closed'] >= $cutoff) {
        foreach (array_reverse($task['logs']) as $log) {
            if ($log['at'] < $cutoff) { break; }
            if ($log['field'] === 'STATUS') { return $status; }
        }
        return 0;
    }
    return $status;
}

function marketingSnapshot(array $task, $cutoff, array $stageMap)
{
    $status = marketingStatusAt($task, $cutoff);
    return marketingStage($status ?: 3, marketingFieldAt($task, 'STAGE_ID', $cutoff), $stageMap);
}

function marketingTaskTimeline(array $task, $field, $cutoff, $defaultStage = null)
{
    if (!$task['created'] || $task['created'] >= $cutoff) { return null; }

    $completion = null;
    if (marketingStatusAt($task, $cutoff) === 5) {
        foreach (array_reverse($task['logs']) as $log) {
            if ($log['at'] < $cutoff && $log['field'] === 'STATUS' && (int)$log['to'] === 5) {
                $completion = $log['at'];
                break;
            }
        }
        if ($task['closed'] && $task['closed'] < $cutoff) {
            $completion = max($completion ?? 0, $task['closed']);
        }
    }

    $events = array_values(array_filter($task['logs'], static function ($log) use ($field) {
        return $log['field'] === $field;
    }));
    if ($field === 'STAGE_ID' && !$events) { return null; }

    $end = max($task['created'], $completion ?? $cutoff);
    $cursor = $task['created'];
    $state = isset($events[0]) ? (int)$events[0]['from'] : null;
    $segments = [];
    foreach ($events as $log) {
        if ($log['at'] > $end || $log['at'] >= $cutoff) { break; }
        $at = max($cursor, $log['at']);
        if ($at > $cursor) {
            $segments[] = ['state'=>$state === (int)$log['from'] ? $state : null, 'from'=>$cursor, 'to'=>$at];
        }
        $cursor = $at;
        $state = (int)$log['to'];
    }
    if ($cursor < $end) {
        // The next event can confirm state in an interval ending before the event.
        $expected = marketingFieldAt($task, $field, $end + (int)($completion !== null));
        $segments[] = ['state'=>$state === $expected ? $state : null, 'from'=>$cursor, 'to'=>$end];
    }
    if ($field === 'STAGE_ID' && $defaultStage !== null) {
        foreach ($segments as &$segment) {
            if ($segment['state'] === 0) { $segment['state'] = $defaultStage; }
        }
        unset($segment);
    }
    return ['segments'=>$segments, 'seconds'=>$end - $task['created'], 'completed'=>$completion !== null];
}

function marketingReport(array $tasks, $from, $to, $now, $staleDays, array $calendar, array $stageMap, array $availability, array $statuses = [], array $stages = [], $defaultStage = null, ?array $creatorIds = null, array $userDepartments = [])
{
    $cutoff = min($to, $now + 1);
    $returnTimes = [];
    $report = ['tasks'=>0, 'subtasks'=>0, 'taskReturns'=>[], 'projects'=>[], 'employees'=>[], 'initiators'=>[], 'departments'=>[], 'funnel'=>array_fill_keys(['new','work','review','done'],0), 'closed'=>0, 'withDeadline'=>0, 'onTime'=>0, 'returns'=>0, 'stale'=>[], 'hours'=>0, 'active'=>0, 'done'=>0, 'projectCount'=>0];
    foreach ($tasks as $id => $task) {
        if (isset($task['included']) && !$task['included']) { continue; }
        if ($creatorIds !== null && !isset($creatorIds[$task['creator'] ?? 0])) { continue; }
        if (!$task['created'] || $task['created'] >= $cutoff) { continue; }
        if ($statuses && !in_array(marketingStatusAt($task, $cutoff), $statuses, true)) { continue; }
        if ($stages) {
            $stage = marketingFieldAt($task, 'STAGE_ID', $cutoff) ?: $defaultStage;
            if (!in_array($stage, $stages, true)) { continue; }
        }
        $state = marketingSnapshot($task, $cutoff, $stageMap);
        if ($state === 'done' && $task['closed'] && $task['closed'] < $from) { continue; }
        $projectId = marketingProjectId($id, $tasks);
        if ($projectId === null) { $projectId = 'unavailable-' . $id; }
        if (!isset($report['projects'][$projectId])) {
            $root = $tasks[$projectId] ?? null;
            $report['projects'][$projectId] = ['id'=>$root ? $projectId : null, 'title'=>$root ? $root['title'] : 'Корневая задача не найдена (задача #' . $id . ')', 'tasks'=>0,'done'=>0,'returns'=>0,'hours'=>0,'members'=>[], 'employeeHours'=>[], 'responsible'=>$root ? $root['responsible'] : null, 'days'=>null, 'state'=>$root ? marketingSnapshot($root,$cutoff,$stageMap) : null];
            if ($root) {
                foreach (array_merge([$root['responsible']],$root['accomplices']) as $member) { $report['projects'][$projectId]['members'][$member] = true; }
                $end = $root['closed'] && $root['closed'] < $cutoff ? $root['closed'] : $cutoff - 1;
                $report['projects'][$projectId]['days'] = marketingWorkingDays($root['start'] ?: $root['created'], $end, $calendar);
            }
        }
        $project =& $report['projects'][$projectId];
        ++$report['tasks']; ++$report['funnel'][$state];
        if ($task['parent'] > 0) { ++$report['subtasks']; ++$project['tasks']; }
        if ($state === 'done') { ++$project['done']; }
        $user = $task['responsible'];
        if (!isset($report['employees'][$user])) { $report['employees'][$user] = ['tasks'=>0,'done'=>0,'returns'=>0,'stale'=>0,'hours'=>0]; }
        ++$report['employees'][$user]['tasks'];
        foreach (array_unique(array_merge([$user], $task['accomplices'])) as $member) { $project['members'][$member] = true; }
        $closedInPeriod = $task['closed'] && $task['closed'] >= $from && $task['closed'] < $cutoff && $state === 'done';
        if ($closedInPeriod) {
            ++$report['closed']; ++$report['employees'][$user]['done'];
            if ($task['deadline']) { ++$report['withDeadline']; if ($task['closed'] <= $task['deadline']) { ++$report['onTime']; } }
        }
        $report['taskReturns'][$id] = 0;
        $lastActivity = $task['created'];
        foreach ($task['logs'] as $log) {
            if ($log['at'] < $cutoff) { $lastActivity = max($lastActivity, $log['at']); }
            if ($log['at'] < $from || $log['at'] >= $cutoff) { continue; }
            $returned = $log['field'] === 'STATUS' && in_array((int)$log['from'],[4,5],true) && in_array((int)$log['to'],[1,2,3],true);
            if ($log['field'] === 'STAGE_ID') { $returned = ($stageMap[(int)$log['from']] ?? '') === 'review' && ($stageMap[(int)$log['to']] ?? '') === 'work'; }
            // A simultaneous STATUS + STAGE_ID change counts as one return.
            if ($returned && !isset($returnTimes[$id][$log['at']])) {
                $returnTimes[$id][$log['at']] = true; ++$report['returns']; ++$report['taskReturns'][$id]; ++$project['returns']; ++$report['employees'][$user]['returns'];
            }
        }
        if ($task['changed'] && $task['changed'] < $cutoff) { $lastActivity = max($lastActivity,$task['changed']); }
        $isStale = $state !== 'done' && (int)$task['status'] !== 7 && $cutoff - 1 - $lastActivity > $staleDays * 86400;
        if ($isStale) {
            $report['stale'][$id] = ['title'=>$task['title'],'days'=>(int)floor(($cutoff-1-$lastActivity)/86400),'returns'=>$returnTimes[$id] ?? []];
            ++$report['employees'][$user]['stale'];
        }
        $taskHours = 0;
        foreach ($task['elapsed'] as $entry) {
            if ($entry['at'] < $from || $entry['at'] >= $cutoff) { continue; }
            $hours = $entry['seconds'] / 3600; $report['hours'] += $hours; $project['hours'] += $hours; $taskHours += $hours;
            $worker = $entry['user'];
            $project['employeeHours'][$worker] = ($project['employeeHours'][$worker] ?? 0) + $hours;
            if (!isset($report['employees'][$worker])) { $report['employees'][$worker] = ['tasks'=>0,'done'=>0,'returns'=>0,'stale'=>0,'hours'=>0]; }
            $report['employees'][$worker]['hours'] += $hours;
        }
        $creator = $task['creator'] ?? 0;
        $departmentIds = array_unique($userDepartments[$creator] ?? []) ?: [0];
        $taskMetrics = [
            'tasks'=>1,
            'done'=>(int)$closedInPeriod,
            'returns'=>$report['taskReturns'][$id],
            'stale'=>(int)$isStale,
            'hours'=>$taskHours,
        ];
        foreach (['initiators'=>[$creator], 'departments'=>$departmentIds] as $dimension=>$ids) {
            foreach ($ids as $groupId) {
                foreach ($taskMetrics as $key=>$value) {
                    $report[$dimension][$groupId][$key] = ($report[$dimension][$groupId][$key] ?? 0) + $value;
                }
            }
        }
        unset($project);
    }
    foreach ($report['projects'] as $project) {
        if ($creatorIds !== null && !isset($creatorIds[$tasks[$project['id']]['creator'] ?? 0])) { continue; }
        if ($project['id'] !== null) { ++$report['projectCount']; }
        if ($project['state'] === 'done') { ++$report['done']; }
        elseif ($project['state'] !== null) { ++$report['active']; }
    }
    $report['percent'] = $report['withDeadline'] ? round(100 * $report['onTime'] / $report['withDeadline'],1) : null;
    return $report;
}
