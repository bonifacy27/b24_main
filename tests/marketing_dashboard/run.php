<?php
require __DIR__ . '/../../forms/marketing/dashboard_metrics.php';
require __DIR__ . '/../../forms/marketing/dashboard_tasks.php';
$config = require __DIR__ . '/../../forms/marketing/dashboard_config.php';
function ts($v) { return (new DateTimeImmutable($v,new DateTimeZone('Europe/Moscow')))->getTimestamp(); }
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function task($id,$parent=0) { return ['id'=>$id,'parent'=>$parent,'title'=>'Task '.$id,'responsible'=>10,'accomplices'=>[20],'created'=>ts('2026-09-01'),'changed'=>ts('2026-09-01'),'closed'=>null,'deadline'=>null,'start'=>null,'status'=>3,'stage'=>2,'logs'=>[],'elapsed'=>[]]; }
$map=[1=>'new',2=>'work',3=>'review'];
$t=task(1); $child=task(2,1); $child['status']=5; $child['closed']=ts('2026-10-05'); $child['deadline']=ts('2026-10-06');
$child['logs']=[['at'=>ts('2026-10-03'),'field'=>'STATUS','from'=>4,'to'=>3],['at'=>ts('2026-10-03'),'field'=>'STAGE_ID','from'=>3,'to'=>2],['at'=>ts('2026-10-05'),'field'=>'STATUS','from'=>3,'to'=>5]];
$child['elapsed']=[['at'=>ts('2026-10-04'),'user'=>20,'seconds'=>7200],['at'=>ts('2026-11-01'),'user'=>20,'seconds'=>3600]];
$tasks=[1=>$t,2=>$child]; $availability=['history'=>true,'elapsed'=>true];
$r=marketingReport($tasks,ts('2026-10-01'),ts('2026-11-01'),ts('2026-10-08 12:00'),7,$config,$map,$availability);
check($r['tasks']===2 && count($r['projects'])===1,'Root projects and task count');
check($r['returns']===1 && $r['taskReturns'][2]===1,'Deduplicate simultaneous returns');
check($r['percent']===100.0,'On-time ratio');
check($r['hours']==2.0 && $r['employees'][20]['hours']==2.0,'Actual worker and period boundary');
check($r['projects'][1]['employeeHours'][20]==2.0,'Employee hours per project');
check(count($r['stale'])===1 && isset($r['stale'][1]),'Stale tasks exclude done');
check($r['funnel']['done']===1 && $r['funnel']['work']===1,'Funnel');
check(marketingSnapshot($child,ts('2026-10-01'),$map)==='review','Reverse history');
$old=$child; $old['closed']=ts('2026-09-15'); $old['logs']=[];
$r=marketingReport([1=>$t,2=>$old],ts('2026-10-01'),ts('2026-11-01'),ts('2026-10-08'),7,$config,$map,$availability);
check($r['tasks']===1 && $r['percent']===null,'Completed before period excluded; missing denominator');
$tasks[1]['included']=false;
$r=marketingReport($tasks,ts('2026-10-01'),ts('2026-11-01'),ts('2026-10-08'),7,$config,$map,$availability);
check($r['tasks']===1 && $r['projects'][1]['title']==='Task 1','Filtered root metadata retained, not counted');
check(marketingProjectId(2,[2=>$child])===null,'Inaccessible root');
$cycle=task(1,2); check(marketingProjectId(1,[1=>$cycle,2=>$child])===null,'Cycles do not hang');
check(marketingWorkingDays(ts('2026-10-02'),ts('2026-10-05'),$config)===2,'Weekend excluded, inclusive boundaries');
$config['working_dates']=['2026-10-03']; $config['holidays']=['2026-10-05'];
check(marketingWorkingDays(ts('2026-10-02'),ts('2026-10-05'),$config)===2,'Holiday and working Saturday overrides');
// Group child -> external parent -> external root; deleted parent and a cycle.
$source = [
    10=>['ID'=>10,'PARENT_ID'=>11,'GROUP_ID'=>163],
    11=>['ID'=>11,'PARENT_ID'=>12,'GROUP_ID'=>999],
    12=>['ID'=>12,'PARENT_ID'=>0,'GROUP_ID'=>999],
    20=>['ID'=>20,'PARENT_ID'=>21,'GROUP_ID'=>163],
    30=>['ID'=>30,'PARENT_ID'=>31,'GROUP_ID'=>163],
    31=>['ID'=>31,'PARENT_ID'=>30,'GROUP_ID'=>999],
];
$calls=[];
$fetch=static function($filter) use ($source,&$calls) {
    $calls[]=$filter;
    if (count($calls)>5) { throw new RuntimeException('Ancestor loading must terminate'); }
    return array_values(array_filter($source,static function($row) use ($filter) {
        return isset($filter['GROUP_ID']) ? $row['GROUP_ID']===$filter['GROUP_ID'] : in_array($row['ID'],$filter['ID'],true);
    }));
};
$loaded=marketingLoadDashboardTasks($fetch,163);
$loaded=array_column($loaded,null,'ID');
check(count($loaded)===6 && isset($loaded[12]),'Load external root chain');
check($loaded[10]['IN_REPORT_GROUP'] && !$loaded[12]['IN_REPORT_GROUP'],'External ancestors are metadata only');
check(!isset($loaded[21]) && count($calls)===3,'Missing parent and cycle terminate');
$external=task(12); $external['included']=false; $external['elapsed']=[['at'=>ts('2026-10-04'),'user'=>20,'seconds'=>36000]];
$inside=task(10,12);
$r=marketingReport([10=>$inside,12=>$external],ts('2026-10-01'),ts('2026-11-01'),ts('2026-10-08'),7,$config,$map,$availability);
check($r['tasks']===1 && $r['projectCount']===1 && $r['hours']===0,'External metadata cannot inflate department counts or hours');
check($r['projects'][12]['title']==='Task 12','External root names project');
$timelineTask=task(50);
$timelineTask['created']=ts('2026-10-01');
$timelineTask['status']=5;
$timelineTask['stage']=3;
$timelineTask['closed']=ts('2026-10-26');
$timelineTask['logs']=[
    ['at'=>ts('2026-10-11'),'field'=>'STATUS','from'=>2,'to'=>3],
    ['at'=>ts('2026-10-11'),'field'=>'STAGE_ID','from'=>0,'to'=>2],
    ['at'=>ts('2026-10-16'),'field'=>'STATUS','from'=>3,'to'=>4],
    ['at'=>ts('2026-10-16'),'field'=>'STAGE_ID','from'=>2,'to'=>3],
    ['at'=>ts('2026-10-26'),'field'=>'STATUS','from'=>4,'to'=>5],
];
$cutoff=ts('2026-11-01');
$status=marketingTaskTimeline($timelineTask,'STATUS',$cutoff);
$stage=marketingTaskTimeline($timelineTask,'STAGE_ID',$cutoff,1);
check($status['completed'] && $status['seconds']===25*86400,'Completion stops the timeline');
check(array_column($status['segments'],'state')===[2,3,4],'Status timeline follows actual transitions');
check(array_column($stage['segments'],'state')===[1,2,3],'Stage timeline uses the group default and kanban transitions');
foreach ([$status,$stage] as $timeline) {
    check(array_map(static function ($segment) {
        return $segment['to'] - $segment['from'];
    },$timeline['segments'])===[10*86400,5*86400,10*86400],'Intervals sum to 25 days');
}
$historical=marketingTaskTimeline($timelineTask,'STATUS',ts('2026-10-16'));
check(!$historical['completed'] && array_column($historical['segments'],'state')===[2,3],'Cutoff excludes a transition at the boundary');
check(marketingTaskTimeline(task(60),'STAGE_ID',$cutoff)===null,'Do not infer stage durations without transitions');
$r=marketingReport([50=>$timelineTask],ts('2026-10-01'),ts('2026-10-16'),$cutoff,7,$config,$map,$availability,[3]);
check($r['projectCount']===1 && $r['subtasks']===0,'Status filter uses the status at the end of the range');
$r=marketingReport([50=>$timelineTask],ts('2026-10-01'),ts('2026-10-16'),$cutoff,7,$config,$map,$availability,[5]);
check($r['projectCount']===0,'Status filter excludes a later completion');
$fetchTags = static function (array $ids) {
    check($ids === [50, 51], 'Pass only requested task IDs to the Tasks API');
    return new class {
        private $rows = [
            ['TASK_ID'=>50,'NAME'=>'ценообразование/контент'],
            ['TASK_ID'=>51,'NAME'=>'Тег подзадачи'],
            ['TASK_ID'=>99,'NAME'=>'Посторонний тег'],
        ];
        public function Fetch() { return array_shift($this->rows); }
    };
};
check(marketingLoadDashboardTags($fetchTags, [50, 51]) === [
    50=>['ценообразование/контент'],
    51=>['Тег подзадачи'],
], 'Read raw linked tags and keep them attached to their own tasks');

$r=marketingReport([50=>$timelineTask],ts('2026-10-01'),ts('2026-10-16'),$cutoff,7,$config,$map,$availability,[3],[1,2],1);
check($r['projectCount']===1,'Match any chosen kanban stage at the cutoff in combination with status');
$r=marketingReport([50=>$timelineTask],ts('2026-10-01'),ts('2026-10-16'),$cutoff,7,$config,$map,$availability,[],[3],1);
check($r['projectCount']===0,'A later kanban stage does not match');
$r=marketingReport([50=>$timelineTask],ts('2026-10-01'),ts('2026-10-11'),$cutoff,7,$config,$map,$availability,[],[1],1);
check($r['projectCount']===1,'An unset kanban stage matches the board default');
$initiatorRoot = task(70);
$initiatorRoot['creator'] = 10;
$initiatorChild = task(71, 70);
$initiatorChild['creator'] = 20;
$initiatorChild['responsible'] = 30;
$otherRoot = task(72);
$otherRoot['creator'] = 30;
$byInitiator = [70=>$initiatorRoot, 71=>$initiatorChild, 72=>$otherRoot];
$r=marketingReport($byInitiator,ts('2026-10-01'),$cutoff,$cutoff,7,$config,$map,$availability,[],[],null,20);
check(array_keys($r['taskReturns'])===[71], 'Filter by creator even if the same employee is an accomplice elsewhere');
check($r['projectCount']===0 && $r['active']===0 && $r['subtasks']===1, 'A parent created by another user is context only');
$r=marketingReport($byInitiator,ts('2026-10-01'),$cutoff,$cutoff,7,$config,$map,$availability,[],[],null,10);
check(array_keys($r['taskReturns'])===[70] && $r['projectCount']===1, 'An initiator can be the responsible user of another task');
echo "Marketing dashboard: all checks passed\n";
