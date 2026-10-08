<?php
require __DIR__ . '/../../forms/marketing/dashboard_metrics.php';
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
echo "Marketing dashboard: all checks passed\n";
