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
echo "Marketing dashboard: all checks passed\n";
