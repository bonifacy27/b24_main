<?php
// ===== ACS REMINDER: HL helpers =====
if (!function_exists('acs_reminder_get_hl'))
{
    function acs_reminder_get_hl()
    {
        if (!\Bitrix\Main\Loader::includeModule('highloadblock'))
            return [0, null];
        $hlName = 'AcsReminders';
        $hlTable = 'acs_reminders';
        $hl = \Bitrix\Highloadblock\HighloadBlockTable::getList([
            'filter' => ['=NAME' => $hlName]
        ])->fetch();
        if (!$hl)
        {
            $result = \Bitrix\Highloadblock\HighloadBlockTable::add([
                'NAME'       => $hlName,
                'TABLE_NAME' => $hlTable,
            ]);
            if ($result->isSuccess())
            {
                $hlId = (int)$result->getId();
                $uf = new \CUserTypeEntity();
                $fields = [
                    ['FIELD_NAME'=>'UF_WORKFLOW_ID','USER_TYPE_ID'=>'string','EDIT_FORM_LABEL'=>['ru'=>'Workflow ID']],
                    ['FIELD_NAME'=>'UF_TASK_ID','USER_TYPE_ID'=>'integer','EDIT_FORM_LABEL'=>['ru'=>'Task ID']],
                    ['FIELD_NAME'=>'UF_DOC_ID','USER_TYPE_ID'=>'string','EDIT_FORM_LABEL'=>['ru'=>'Document ID']],
                    ['FIELD_NAME'=>'UF_RECIPIENTS','USER_TYPE_ID'=>'string','EDIT_FORM_LABEL'=>['ru'=>'Recipients (comma)']],
                    ['FIELD_NAME'=>'UF_SUBJECT','USER_TYPE_ID'=>'string','EDIT_FORM_LABEL'=>['ru'=>'Subject']],
                    ['FIELD_NAME'=>'UF_BODY','USER_TYPE_ID'=>'string','EDIT_FORM_LABEL'=>['ru'=>'Body']],
                    ['FIELD_NAME'=>'UF_REMIND_AT','USER_TYPE_ID'=>'datetime','EDIT_FORM_LABEL'=>['ru'=>'Remind at']],
                    ['FIELD_NAME'=>'UF_STATUS','USER_TYPE_ID'=>'string','EDIT_FORM_LABEL'=>['ru'=>'Status P/S/D']],
                    ['FIELD_NAME'=>'UF_CREATED_AT','USER_TYPE_ID'=>'datetime','EDIT_FORM_LABEL'=>['ru'=>'Created at']],
                    ['FIELD_NAME'=>'UF_SENT_AT','USER_TYPE_ID'=>'datetime','EDIT_FORM_LABEL'=>['ru'=>'Sent at']],
                ];
                foreach ($fields as $f)
                {
                    $f['ENTITY_ID'] = 'HLBLOCK_'.$hlId;
                    $f['MANDATORY'] = 'N';
                    $uf->Add($f);
                }
            }
            $hl = \Bitrix\Highloadblock\HighloadBlockTable::getList([
                'filter' => ['=NAME' => $hlName]
            ])->fetch();
        }
        if (!$hl) return [0, null];
        $entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hl);
        $dataClass = $entity->getDataClass();
        return [(int)$hl['ID'], $dataClass];
    }
}
if (!function_exists('acs_reminder_add'))
{
    function acs_reminder_add($workflowId, $taskId, $docId, array $emails, $subject, $body, \Bitrix\Main\Type\DateTime $when)
    {
        list($hlId, $dataClass) = acs_reminder_get_hl();
        if (!$hlId || !$dataClass) return false;
        $recipients = implode(',', array_unique(array_filter(array_map('trim', $emails))));
        $res = $dataClass::add([
            'UF_WORKFLOW_ID' => (string)$workflowId,
            'UF_TASK_ID'     => (int)$taskId,
            'UF_DOC_ID'      => (string)$docId,
            'UF_RECIPIENTS'  => (string)$recipients,
            'UF_SUBJECT'     => (string)$subject,
            'UF_BODY'        => (string)$body,
            'UF_REMIND_AT'   => $when,
            'UF_STATUS'      => 'P',
            'UF_CREATED_AT'  => new \Bitrix\Main\Type\DateTime(),
        ]);
        if ($res->isSuccess())
        {
            return $res->getId();
        }
        else
        {
            \acs_reminder_log("HL ADD ERROR taskId={$taskId} ".implode('; ',$res->getErrorMessages()));
            return false;
        }
    }
}
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();
class CBPapprovecopyactiveschedule
	extends CBPCompositeActivity
	implements IBPEventActivity, IBPActivityExternalEventListener
{
	private $taskId = 0;
	private $taskUsers = array();
	private $subscriptionId = 0;
	private $isInEventActivityMode = false;
	private $taskStatus = false;
	private $arApproveResults = array();
	private $arApproveOriginalResults = array();
	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = array(
			"Title" => "",
			"Users" => null,
			"ApproveType" => "all",
			"Percent" => 100,
			"OverdueDate" => null,
			"Name" => null,
			"Description" => null,
			"Parameters" => null,
			"ApproveMinPercent" => 50,
			"ApproveWaitForAll" => "N",
			"TaskId" => 0,
			"NotifyText" => "",
			"NotifySubject" => "",
			"Reminder3Text" => "",
			"Reminder2Text" => "",
			"Reminder1Text" => "",
			"Reminder3Subject" => "",
			"Reminder2Subject" => "",
			"Reminder1Subject" => "",
			"Reminder3Hours" => 0,
			"Reminder2Hours" => 0,
			"Reminder1Hours" => 0,
			"Comments" => "",
			"VotedCount" => 0,
			"TotalCount" => 0,
			"VotedPercent" => 0,
			"ApprovedPercent" => 0,
			"NotApprovedPercent" => 0,
			"ApprovedCount" => 0,
			"NotApprovedCount" => 0,
			"StatusMessage" => "",
			"SetStatusMessage" => "Y",
			"LastApprover" => null,
			"LastApproverComment" => '',
			"Approvers" => "",
			"Rejecters" => "",
			"UserApprovers" => [],
			"UserRejecters" => [],
			"TimeoutDuration" => 0,
			"TimeoutDurationType" => "s",
			"IsTimeout" => 0,
			"TaskButton1Message" => "",
			"TaskButton2Message" => "",
			"TaskButton3Message" => "",
			"RefinePressed" => "N",
			"CommentLabelMessage" => "",
			"ShowComment" => "Y",
			'CommentRequired' => 'N',
			'AccessControl' => 'N',
			'DelegationType' => 0,
			'RefineAllowed' => 'Y', // <<< НОВОЕ СВОЙСТВО
		);
		$this->SetPropertiesTypes(array(
			'RefinePressed' => array('Type' => 'string'),
			'TaskId' => ['Type' => 'int'],
			'Comments' => array('Type' => 'string'),
			'VotedCount' => array('Type' => 'int'),
			'TotalCount' => array('Type' => 'int'),
			'VotedPercent' => array('Type' => 'int'),
			'ApprovedPercent' => array('Type' => 'int'),
			'NotApprovedPercent' => array('Type' => 'int'),
			'ApprovedCount' => array('Type' => 'int'),
			'NotApprovedCount' => array('Type' => 'int'),
			'LastApprover' => array('Type' => 'user'),
			'LastApproverComment' => array('Type' => 'string'),
			'Approvers' => array('Type' => 'string'),
			'Rejecters' => array('Type' => 'string'),
			'UserApprovers' => array('Type' => 'user', 'Multiple' => true),
			'UserRejecters' => array('Type' => 'user', 'Multiple' => true),
			'IsTimeout' => array('Type' => 'int'),
			'RefineAllowed' => array('Type' => 'string'), // <<< ТИП
		), array(
			'RefinePressed' => array('Name' => GetMessage('BPAA_DESCR_REFINE_PRESSED'), 'Type' => 'string'),
			'RefineAllowed' => array('Name' => GetMessage('BPAA_DESCR_REFINE_ALLOWED'), 'Type' => 'string'), // <<< ЛОКАЛИЗАЦИЯ
		));
	}
	public function Execute()
	{
		if ($this->isInEventActivityMode)
			return CBPActivityExecutionStatus::Closed;
		$this->Subscribe($this);
		$this->isInEventActivityMode = false;
		return CBPActivityExecutionStatus::Executing;
	}
	public function Subscribe(IBPActivityExternalEventListener $eventHandler)
	{
		if ($eventHandler == null)
			throw new Exception("eventHandler");
		$this->isInEventActivityMode = true;
		$rootActivity = $this->GetRootActivity();
		$documentId = $rootActivity->GetDocumentId();
		$runtime = CBPRuntime::GetRuntime();
		$documentService = $runtime->GetService("DocumentService");
		$arUsersTmp = $this->Users;
		if (!is_array($arUsersTmp))
			$arUsersTmp = array($arUsersTmp);
		if ($this->ApproveType == "any")
			$this->WriteToTrackingService(str_replace("#VAL#", "{=user:".implode("}, {=user:", $arUsersTmp)."}", GetMessage("BPAA_ACT_TRACK1")));
		elseif ($this->ApproveType == "all")
			$this->WriteToTrackingService(str_replace("#VAL#", "{=user:".implode("}, {=user:", $arUsersTmp)."}", GetMessage("BPAA_ACT_TRACK2")));
		elseif ($this->ApproveType == "vote")
			$this->WriteToTrackingService(str_replace("#VAL#", "{=user:".implode("}, {=user:", $arUsersTmp)."}", GetMessage("BPAA_ACT_TRACK3")));
		$arUsers = CBPHelper::ExtractUsers($arUsersTmp, $documentId, false);
		$arParameters = $this->Parameters;
		if (!is_array($arParameters))
			$arParameters = array($arParameters);
		$arParameters["DOCUMENT_ID"] = $documentId;
		$arParameters["DOCUMENT_URL"] = $documentService->GetDocumentAdminPage($documentId);
		$arParameters["TaskButton1Message"] = $this->IsPropertyExists("TaskButton1Message") ? $this->TaskButton1Message : GetMessage("BPAA_ACT_BUTTON1");
		if ($arParameters["TaskButton1Message"] == '')
			$arParameters["TaskButton1Message"] = GetMessage("BPAA_ACT_BUTTON1");
		$arParameters["TaskButton2Message"] = $this->IsPropertyExists("TaskButton2Message") ? $this->TaskButton2Message : GetMessage("BPAA_ACT_BUTTON2");
		if ($arParameters["TaskButton2Message"] == '')
			$arParameters["TaskButton2Message"] = GetMessage("BPAA_ACT_BUTTON2");
		$arParameters["TaskButton3Message"] = $this->IsPropertyExists("TaskButton3Message") ? $this->TaskButton3Message : GetMessage("BPAA_ACT_BUTTON3");
		if ($arParameters["TaskButton3Message"] == '')
			$arParameters["TaskButton3Message"] = GetMessage("BPAA_ACT_BUTTON3");
		$arParameters["CommentLabelMessage"] = $this->IsPropertyExists("CommentLabelMessage") ? $this->CommentLabelMessage : GetMessage("BPAA_ACT_COMMENT");
		if ($arParameters["CommentLabelMessage"] == '')
			$arParameters["CommentLabelMessage"] = GetMessage("BPAA_ACT_COMMENT");
		$arParameters["ShowComment"] = $this->IsPropertyExists("ShowComment") ? $this->ShowComment : "Y";
		if ($arParameters["ShowComment"] != "Y" && $arParameters["ShowComment"] != "N")
			$arParameters["ShowComment"] = "Y";
		$arParameters["CommentRequired"] = $this->IsPropertyExists("CommentRequired") ? $this->CommentRequired : "N";
		$arParameters["AccessControl"] = $this->IsPropertyExists("AccessControl") && $this->AccessControl == 'Y' ? 'Y' : 'N';
		$arParameters["RefineAllowed"] = $this->IsPropertyExists("RefineAllowed") ? $this->RefineAllowed : "Y"; // <<< ПЕРЕДАЁМ В ПАРАМЕТРЫ
		$overdueDate = $this->OverdueDate;
		$timeoutDuration = $this->CalculateTimeoutDuration();
		if ($timeoutDuration > 0)
		{
			$overdueDate = ConvertTimeStamp(time() + max($timeoutDuration, CBPSchedulerService::getDelayMinLimit()), "FULL");
		}
		/** @var CBPTaskService $taskService */
		$taskService = $this->workflow->GetService("TaskService");
		$this->taskId = $taskService->CreateTask(
			array(
				"USERS" => $arUsers,
				"WORKFLOW_ID" => $this->GetWorkflowInstanceId(),
				"ACTIVITY" => "approvecopyactiveschedule",
				"ACTIVITY_NAME" => $this->name,
				"OVERDUE_DATE" => $overdueDate,
				"NAME" => $this->Name,
				"DESCRIPTION" => $this->Description,
				"PARAMETERS" => $arParameters,
				'IS_INLINE' => $arParameters["ShowComment"] == "Y" ? 'N' : 'Y',
				'DELEGATION_TYPE' => (int)$this->DelegationType,
				'DOCUMENT_NAME' => $documentService->GetDocumentName($documentId)
			)
		);
		// === Сбор email-адресов исполнителей ДО создания напоминаний ===
		$executorEmails = [];
		foreach ($arUsers as $uid) {
			$uid = (int)$uid;
			if ($uid <= 0) continue;
			$rsUser = CUser::GetByID($uid);
			if ($arU = $rsUser->Fetch()) {
				$email = trim($arU['EMAIL']);
				if ($email !== '') {
					$executorEmails[] = $email;
				}
			}
		}
		// Schedule email reminders via agents
		if (CModule::IncludeModule('bizproc'))
		{
			$baseTime = time();
			$addAgent = function($minutes, $subj, $text) use ($baseTime, $executorEmails)
			{
				$minutes = (int)$minutes;
				if ($minutes <= 0 || empty($executorEmails)) return;
				$ts = $baseTime + $minutes * 3600; // minutes → seconds
				$when = \Bitrix\Main\Type\DateTime::createFromTimestamp($ts);
				$workflowId = CBPActivity::GetWorkflowInstanceId();
				$docId = is_array($this->GetDocumentId()) ? implode(':', $this->GetDocumentId()) : (string)$this->GetDocumentId();
				\acs_reminder_add($workflowId, (int)$this->taskId, $docId, $executorEmails, (string)$subj, (string)$text, $when);
			};
			$addAgent($this->Reminder1Hours, $this->Reminder1Subject, $this->Reminder1Text);
			$addAgent($this->Reminder2Hours, $this->Reminder2Subject, $this->Reminder2Text);
			$addAgent($this->Reminder3Hours, $this->Reminder3Subject, $this->Reminder3Text);
		}
		// === Email notifications: send to all task executors ===
		try
		{
			$notifyText = (string)$this->NotifyText;
			$taskLink = 'https://ourtricolortv.nsc.ru/company/personal/bizproc/' . intval($this->taskId) . '/';
			if (!empty($arUsers))
			{
				foreach ($arUsers as $uid)
				{
					$uid = (int)$uid;
					if ($uid <= 0) continue;
					$rsUser = CUser::GetByID($uid);
					if ($arU = $rsUser->Fetch())
					{
						$email = trim($arU['EMAIL']);
						if ($email <> '')
						{
							\Bitrix\Main\Mail\Mail::send(array(
								'TO' => $email,
								'SUBJECT' => ($this->NotifySubject !== '' ? $this->NotifySubject : ($this->Name ? $this->Name : 'Business Process Task')),
								'BODY' => ($notifyText ? $notifyText."
" : '') . 'Ссылка на задание: ' . $taskLink,
								'HEADER' => array('Precedence' => 'bulk')
							));
						}
					}
				}
			}
		}
		catch (\Exception $e) { /* keep silent */ }
		$this->TaskId = $this->taskId;
		$this->taskUsers = $arUsers;
		$this->TotalCount = count($arUsers);
		if (!$this->IsPropertyExists("SetStatusMessage") || $this->SetStatusMessage == "Y")
		{
			$totalCount = $this->TotalCount;
			$message = ($this->IsPropertyExists("StatusMessage") && $this->StatusMessage <> '') ? $this->StatusMessage : GetMessage("BPAA_ACT_INFO");
			$this->SetStatusTitle(str_replace(
				array("#PERC#", "#PERCENT#", "#REV#", "#VOTED#", "#TOT#", "#TOTAL#", "#APPROVERS#", "#REJECTERS#"),
				array(0, 0, 0, 0, $totalCount, $totalCount, GetMessage("BPAA_ACT_APPROVERS_NONE"), GetMessage("BPAA_ACT_APPROVERS_NONE")),
				$message
			));
		}
		if ($timeoutDuration > 0)
		{
			/** @var CBPSchedulerService $schedulerService */
			$schedulerService = $this->workflow->GetService("SchedulerService");
			$this->subscriptionId = $schedulerService->SubscribeOnTime($this->workflow->GetInstanceId(), $this->name, time() + $timeoutDuration);
		}
		$this->workflow->AddEventHandler($this->name, $eventHandler);
	}
	private function ReplaceTemplate($str, $ar)
	{
		$str = str_replace("%", "%2", $str);
		foreach ($ar as $key => $val)
		{
			$val = str_replace("%", "%2", $val);
			$val = str_replace("#", "%1", $val);
			$str = str_replace("#".$key."#", $val, $str);
		}
		$str = str_replace("%1", "#", $str);
		$str = str_replace("%2", "%", $str);
		return $str;
	}
	public function Unsubscribe(IBPActivityExternalEventListener $eventHandler)
	{
		if ($eventHandler == null)
			throw new Exception("eventHandler");
		$taskService = $this->workflow->GetService("TaskService");
		if ($this->taskStatus === false)
		{
			$taskService->DeleteTask($this->taskId);
		}
		else
		{
			$taskService->Update($this->taskId, array(
				'STATUS' => $this->taskStatus
			));
		}
		$timeoutDuration = $this->CalculateTimeoutDuration();
		if ($timeoutDuration > 0)
		{
			$schedulerService = $this->workflow->GetService("SchedulerService");
			$schedulerService->UnSubscribeOnTime($this->subscriptionId);
		}
		$this->workflow->RemoveEventHandler($this->name, $eventHandler);
		$this->taskId = 0;
		$this->taskUsers = array();
		$this->taskStatus = false;
		$this->subscriptionId = 0;
	}
	public function Cancel()
	{
		if (!$this->isInEventActivityMode && $this->taskId > 0)
			$this->Unsubscribe($this);
		for ($i = count($this->arActivities) - 1; $i >= 0; $i--)
		{
			$activity = $this->arActivities[$i];
			if ($activity->executionStatus == CBPActivityExecutionStatus::Executing)
			{
				$this->workflow->CancelActivity($activity);
				return CBPActivityExecutionStatus::Canceling;
			}
			if (($activity->executionStatus == CBPActivityExecutionStatus::Canceling)
				|| ($activity->executionStatus == CBPActivityExecutionStatus::Faulting))
				return CBPActivityExecutionStatus::Canceling;
			if ($activity->executionStatus == CBPActivityExecutionStatus::Closed)
				return CBPActivityExecutionStatus::Closed;
		}
		return CBPActivityExecutionStatus::Closed;
	}
	protected function ExecuteOnApprove()
	{
		if (count($this->arActivities) <= 0)
		{
			$this->workflow->CloseActivity($this);
			return;
		}
		$this->WriteToTrackingService(GetMessage("BPAA_ACT_APPROVE"));
		$activity = $this->arActivities[0];
		$activity->AddStatusChangeHandler(self::ClosedEvent, $this);
		$this->workflow->ExecuteActivity($activity);
	}
	protected function ExecuteOnNonApprove()
	{
		if (count($this->arActivities) <= 1)
		{
			$this->workflow->CloseActivity($this);
			return;
		}
		$this->WriteToTrackingService(GetMessage("BPAA_ACT_NONAPPROVE"));
		$activity = $this->arActivities[1];
		$activity->AddStatusChangeHandler(self::ClosedEvent, $this);
		$this->workflow->ExecuteActivity($activity);
	}
	public function OnExternalEvent($arEventParameters = array())
	{
		$this->RefinePressed = (isset($arEventParameters['REFINE']) && $arEventParameters['REFINE'] === 'Y') ? 'Y' : 'N';
		if ($this->executionStatus == CBPActivityExecutionStatus::Closed)
			return;
		$timeoutDuration = $this->CalculateTimeoutDuration();
		if ($timeoutDuration > 0)
		{
			if (array_key_exists("SchedulerService", $arEventParameters) && $arEventParameters["SchedulerService"] == "OnAgent")
			{
				$this->IsTimeout = 1;
				$this->taskStatus = CBPTaskStatus::Timeout;
				$this->RefinePressed = 'N';
				$this->Unsubscribe($this);
				$this->writeApproversResult();
				$this->ExecuteOnNonApprove();
				return;
			}
		}
		if (!array_key_exists("USER_ID", $arEventParameters) || intval($arEventParameters["USER_ID"]) <= 0)
			return;
		if (!array_key_exists("APPROVE", $arEventParameters))
			return;
		if (empty($arEventParameters["REAL_USER_ID"]))
			$arEventParameters["REAL_USER_ID"] = $arEventParameters["USER_ID"];
		$approve = ($arEventParameters["APPROVE"] ? true : false);
		$arUsers = $this->taskUsers;
		if (empty($arUsers)) //compatibility
			$arUsers = CBPHelper::ExtractUsers($this->Users, $this->GetDocumentId(), false);
		$arEventParameters["USER_ID"] = intval($arEventParameters["USER_ID"]);
		$arEventParameters["REAL_USER_ID"] = intval($arEventParameters["REAL_USER_ID"]);
		if (!in_array($arEventParameters["USER_ID"], $arUsers))
			return;
		if ($this->IsPropertyExists("LastApprover"))
			$this->LastApprover = "user_".$arEventParameters["REAL_USER_ID"];
		if ($this->IsPropertyExists("LastApproverComment"))
			$this->LastApproverComment = (string)$arEventParameters["COMMENT"];
		$taskService = $this->workflow->GetService("TaskService");
		$taskService->MarkCompleted($this->taskId, $arEventParameters["REAL_USER_ID"], $approve? CBPTaskUserStatus::Yes : CBPTaskUserStatus::No);
		$dbUser = CUser::GetById($arEventParameters["REAL_USER_ID"]);
		if($arUser = $dbUser->Fetch())
			$this->Comments = $arEventParameters["COMMENT"];
// Определяем тип действия
if (isset($arEventParameters['REFINE']) && $arEventParameters['REFINE'] === 'Y')
{
	// Отправка на доработку
	$trackingMessage = GetMessage("BPAA_ACT_REFINE_TRACK");
}
elseif ($approve)
{
	// Одобрение
	$trackingMessage = GetMessage("BPAA_ACT_APPROVE_TRACK");
}
else
{
	// Отклонение (без доработки)
	$trackingMessage = GetMessage("BPAA_ACT_NONAPPROVE_TRACK");
}
$this->WriteToTrackingService(
	str_replace(
		array("#PERSON#", "#COMMENT#"),
		array(
			"{=user:user_".$arEventParameters["REAL_USER_ID"]."}",
			($arEventParameters["COMMENT"] <> '' ? ": ".$arEventParameters["COMMENT"] : "")
		),
		$trackingMessage
	),
	$arEventParameters["REAL_USER_ID"]
);
		$result = "Continue";
		$this->arApproveOriginalResults[$arEventParameters["USER_ID"]] = $approve;
		$this->arApproveResults[$arEventParameters["REAL_USER_ID"]] = $approve;
		if($approve)
			$this->ApprovedCount = $this->ApprovedCount + 1;
		else
			$this->NotApprovedCount = $this->NotApprovedCount + 1;
		$this->VotedCount = count($this->arApproveResults);
		$this->VotedPercent = intval($this->VotedCount/$this->TotalCount*100);
		$this->ApprovedPercent = intval($this->ApprovedCount/$this->TotalCount*100);
		$this->NotApprovedPercent = intval($this->NotApprovedCount/$this->TotalCount*100);
		if ($this->ApproveType == "any")
		{
			$result = ($approve ? "Approve" : "NonApprove");
		}
		elseif ($this->ApproveType == "all")
		{
			if (!$approve)
			{
				$result = "NonApprove";
			}
			else
			{
				$allAproved = true;
				foreach ($arUsers as $userId)
				{
					if (!isset($this->arApproveOriginalResults[$userId]))
						$allAproved = false;
				}
				if ($allAproved)
					$result = "Approve";
			}
		}
		elseif ($this->ApproveType == "vote")
		{
			if($this->ApproveWaitForAll == "Y")
			{
				if($this->VotedPercent==100)
				{
					if ($this->ApprovedPercent > $this->ApproveMinPercent || $this->ApprovedPercent == 100 && $this->ApproveMinPercent == 100)
						$result = "Approve";
					else
						$result = "NonApprove";
				}
			}
			else
			{
				$noneApprovedPercent = ($this->VotedCount-$this->ApprovedCount)/$this->TotalCount*100;
				if ($this->ApprovedPercent > $this->ApproveMinPercent || $this->ApprovedPercent == 100 && $this->ApproveMinPercent == 100)
					$result = "Approve";
				elseif($noneApprovedPercent > 0 && $noneApprovedPercent >= 100 - $this->ApproveMinPercent)
					$result = "NonApprove";
			}
		}
		$approvers = "";
		$rejecters = "";
		if (!$this->IsPropertyExists("SetStatusMessage") || $this->SetStatusMessage == "Y")
		{
			$statusMessage = $this->StatusMessage;
			$messageTemplate = ($statusMessage && is_string($statusMessage)) ? $statusMessage : GetMessage("BPAA_ACT_INFO");
			$votedPercent = $this->VotedPercent;
			$votedCount = $this->VotedCount;
			$totalCount = $this->TotalCount;
			if (mb_strpos($messageTemplate, "#REJECTERS#") !== false)
				$rejecters = $this->GetApproversNames(false);
			if (mb_strpos($messageTemplate, "#APPROVERS#") !== false)
				$approvers = $this->GetApproversNames(true);
			$approversTmp = $approvers;
			$rejectersTmp = $rejecters;
			if ($approversTmp == "")
				$approversTmp = GetMessage("BPAA_ACT_APPROVERS_NONE");
			if ($rejectersTmp == "")
				$rejectersTmp = GetMessage("BPAA_ACT_APPROVERS_NONE");
			$this->SetStatusTitle(str_replace(
				array("#PERC#", "#PERCENT#", "#REV#", "#VOTED#", "#TOT#", "#TOTAL#", "#APPROVERS#", "#REJECTERS#"),
				array($votedPercent, $votedPercent, $votedCount, $votedCount, $totalCount, $totalCount, $approversTmp, $rejectersTmp),
				$messageTemplate
			));
		}
		if ($result != "Continue")
		{
			$this->taskStatus = $result == "Approve"? CBPTaskStatus::CompleteYes : CBPTaskStatus::CompleteNo;
			$this->Unsubscribe($this);
			$this->writeApproversResult();
			if ($result == "Approve")
				$this->ExecuteOnApprove();
			else
				$this->ExecuteOnNonApprove();
		}
	}
	private function GetApproversNames($b)
	{
		$result = "";
		$ar = array();
		foreach ($this->arApproveResults as $k => $v)
		{
			if ($b && $v || !$b && !$v)
				$ar[] = $k;
		}
		if (count($ar) > 0)
		{
			$dbUsers = CUser::GetList(
				"",
				"",
				array("ID" => implode('|', $ar)),
				array('FIELDS' => array('ID', 'LOGIN', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'TITLE'))
			);
			while ($arUser = $dbUsers->Fetch())
			{
				if ($result != "")
					$result .= ", ";
				$result .= CUser::FormatName(
						COption::GetOptionString("bizproc", "name_template", CSite::GetNameFormat(false), SITE_ID),
						$arUser)." (".$arUser["LOGIN"].")";
			}
		}
		return $result;
	}
	private function writeApproversResult()
	{
		$this->Rejecters = $this->GetApproversNames(false);
		$this->Approvers = $this->GetApproversNames(true);
		$approvers = $rejecters = [];
		foreach ($this->arApproveResults as $userId => $vote)
		{
			$user = 'user_'.$userId;
			if ($vote)
			{
				$approvers[] = $user;
			}
			else
			{
				$rejecters[] = $user;
			}
		}
		$this->UserApprovers = $approvers;
		$this->UserRejecters = $rejecters;
	}
	protected function OnEvent(CBPActivity $sender)
	{
		$sender->RemoveStatusChangeHandler(self::ClosedEvent, $this);
		$this->workflow->CloseActivity($this);
	}
	protected function ReInitialize()
	{
		parent::ReInitialize();
		$this->TaskId = 0;
		$this->arApproveResults = array();
		$this->arApproveOriginalResults = array();
		$this->ApprovedCount = 0;
		$this->NotApprovedCount = 0;
		$this->VotedCount = 0;
		$this->VotedPercent = 0;
		$this->ApprovedPercent = 0;
		$this->NotApprovedPercent = 0;
		$this->Comments = '';
		$this->IsTimeout = 0;
		$this->Approvers = '';
		$this->Rejecters = '';
		$this->UserApprovers = [];
		$this->UserRejecters = [];
		$this->LastApprover = null;
		$this->LastApproverComment = '';
	}
	public static function ShowTaskForm($arTask, $userId, $userName = "")
	{
		$form = '';
		if (!array_key_exists("ShowComment", $arTask["PARAMETERS"]) || ($arTask["PARAMETERS"]["ShowComment"] != "N"))
		{
			$required = '';
			if (isset($arTask['PARAMETERS']['CommentRequired']))
			{
				switch ($arTask['PARAMETERS']['CommentRequired'])
				{
					case 'Y':
						$required = '<span>*</span>';
						break;
					case 'YA':
						$required = '<span style="color: green;">*</span>';
						break;
					case 'YR':
						$required = '<span style="color: red">*</span>';
						break;
					default:
						break;
				}
			}
			$form .=
				'<tr><td valign="top" width="40%" align="right" class="bizproc-field-name">'
					.($arTask["PARAMETERS"]["CommentLabelMessage"] <> '' ? $arTask["PARAMETERS"]["CommentLabelMessage"] : GetMessage("BPAA_ACT_COMMENT"))
					.$required
				.':</td>'.
				'<td valign="top" width="60%" class="bizproc-field-value">'.
				'<textarea rows="3" cols="50" name="task_comment"></textarea>'.
				'</td></tr>';
		}
		$buttons =
			'<input type="submit" name="approve" value="'.($arTask["PARAMETERS"]["TaskButton1Message"] <> '' ? $arTask["PARAMETERS"]["TaskButton1Message"] : GetMessage("BPAA_ACT_BUTTON1")).'"/>'.
			'<input type="submit" name="nonapprove" value="'.($arTask["PARAMETERS"]["TaskButton2Message"] <> '' ? $arTask["PARAMETERS"]["TaskButton2Message"] : GetMessage("BPAA_ACT_BUTTON2")).'"/>';

		// <<< ПОКАЗ КНОПКИ ТОЛЬКО ЕСЛИ РАЗРЕШЕНО >>>
		if (!isset($arTask["PARAMETERS"]["RefineAllowed"]) || $arTask["PARAMETERS"]["RefineAllowed"] !== 'N')
		{
			$buttons .= '<input type="submit" name="refine" class="ui-btn ui-btn-warning" style="background-color:#FFA500;border-color:#FFA500;color:#fff" value="'.($arTask["PARAMETERS"]["TaskButton3Message"] <> '' ? $arTask["PARAMETERS"]["TaskButton3Message"] : GetMessage("BPAA_ACT_BUTTON3")).'"/>';
		}

		return array($form, $buttons);
	}
	public static function getTaskControls($arTask)
	{
		$controls = array(
			'BUTTONS' => array(
				array(
					'TYPE'  => 'submit',
					'TARGET_USER_STATUS' => CBPTaskUserStatus::Yes,
					'NAME'  => 'approve',
					'VALUE' => 'Y',
					'TEXT'  => ($arTask["PARAMETERS"]["TaskButton1Message"] <> '' ? $arTask["PARAMETERS"]["TaskButton1Message"] : GetMessage("BPAA_ACT_BUTTON1"))
				),
				array(
					'TYPE'  => 'submit',
					'TARGET_USER_STATUS' => CBPTaskUserStatus::No,
					'NAME'  => 'nonapprove',
					'VALUE' => 'Y',
					'TEXT'  => ($arTask["PARAMETERS"]["TaskButton2Message"] <> '' ? $arTask["PARAMETERS"]["TaskButton2Message"] : GetMessage("BPAA_ACT_BUTTON2"))
				),
			)
		);

		// <<< ПОКАЗ КНОПКИ ТОЛЬКО ЕСЛИ РАЗРЕШЕНО >>>
		if (!isset($arTask["PARAMETERS"]["RefineAllowed"]) || $arTask["PARAMETERS"]["RefineAllowed"] !== 'N')
		{
			$controls['BUTTONS'][] = array(
				'TYPE'  => 'submit',
				'TARGET_USER_STATUS' => CBPTaskUserStatus::No,
				'NAME'  => 'refine',
				'VALUE' => 'REFINE',
				'TEXT'  => ($arTask["PARAMETERS"]["TaskButton3Message"] <> '' ? $arTask["PARAMETERS"]["TaskButton3Message"] : GetMessage("BPAA_ACT_BUTTON3")),
				'CLASS' => 'ui-btn ui-btn-rework',
				'ADDITIONAL_CLASS' => 'ui-btn ui-btn-rework'
			);
		}

		return $controls;
	}
	public static function PostTaskForm($arTask, $userId, $arRequest, &$arErrors, $userName = "", $realUserId = null)
	{
		$arErrors = array();
		try
		{
			$userId = intval($userId);
			if ($userId <= 0)
				throw new CBPArgumentNullException("userId");
			$arEventParameters = array(
				"USER_ID" => $userId,
				"REAL_USER_ID" => $realUserId,
				"USER_NAME" => $userName,
				"COMMENT" => isset($arRequest["task_comment"]) ? trim($arRequest["task_comment"]) : '',
			);
			// Устанавливаем флаг доработки
			if ((isset($arRequest['REFINE']) && $arRequest['REFINE'] === 'Y') || (isset($arRequest['refine']) && $arRequest['refine'] !== ''))
			{
				$arEventParameters['REFINE'] = 'Y';
			}
			if (isset($arRequest['approve']) && $arRequest["approve"] <> ''
				|| isset($arRequest['INLINE_USER_STATUS']) && $arRequest['INLINE_USER_STATUS'] == CBPTaskUserStatus::Yes)
				$arEventParameters["APPROVE"] = true;
			elseif ((isset($arRequest['nonapprove']) && $arRequest['nonapprove'] <> ''
				|| isset($arRequest['refine']) && $arRequest['refine'] <> ''
				|| isset($arRequest['INLINE_USER_STATUS']) && $arRequest['INLINE_USER_STATUS'] == CBPTaskUserStatus::No))
				$arEventParameters["APPROVE"] = false;
			else
				throw new CBPNotSupportedException(GetMessage("BPAA_ACT_NO_ACTION"));
			if (
				isset($arTask['PARAMETERS']['ShowComment'])
				&& $arTask['PARAMETERS']['ShowComment'] === 'Y'
				&& isset($arTask['PARAMETERS']['CommentRequired'])
				&& empty($arEventParameters['COMMENT'])
				&&
				($arTask['PARAMETERS']['CommentRequired'] === 'Y'
					|| $arTask['PARAMETERS']['CommentRequired'] === 'YA' && $arEventParameters["APPROVE"]
					|| $arTask['PARAMETERS']['CommentRequired'] === 'YR' && !$arEventParameters["APPROVE"]
				)
			)
			{
				$label = $arTask["PARAMETERS"]["CommentLabelMessage"] <> '' ? $arTask["PARAMETERS"]["CommentLabelMessage"] : GetMessage("BPAA_ACT_COMMENT");
				throw new CBPArgumentNullException(
					'task_comment',
					GetMessage("BPAA_ACT_COMMENT_ERROR", array(
						'#COMMENT_LABEL#' => $label
					))
				);
			}
			CBPRuntime::SendExternalEvent($arTask["WORKFLOW_ID"], $arTask["ACTIVITY_NAME"], $arEventParameters);
			return true;
		}
		catch (Exception $e)
		{
			$arErrors[] = array(
				"code" => $e->getCode(),
				"message" => $e->getMessage(),
				"file" => $e->getFile()." [".$e->getLine()."]",
			);
		}
		return false;
	}
	public static function ValidateProperties($arTestProperties = array(), CBPWorkflowTemplateUser $user = null)
	{
		$arErrors = array();
		if (!array_key_exists("Users", $arTestProperties))
		{
			$bUsersFieldEmpty = true;
		}
		else
		{
			if (!is_array($arTestProperties["Users"]))
				$arTestProperties["Users"] = array($arTestProperties["Users"]);
			$bUsersFieldEmpty = true;
			foreach ($arTestProperties["Users"] as $userId)
			{
				if (!is_array($userId) && (trim($userId) <> '') || is_array($userId) && (count($userId) > 0))
				{
					$bUsersFieldEmpty = false;
					break;
				}
			}
		}
		if ($bUsersFieldEmpty)
			$arErrors[] = array("code" => "NotExist", "parameter" => "Users", "message" => GetMessage("BPAA_ACT_PROP_EMPTY1"));
		if (!array_key_exists("ApproveType", $arTestProperties))
		{
			$arErrors[] = array("code" => "NotExist", "parameter" => "ApproveType", "message" => GetMessage("BPAA_ACT_PROP_EMPTY2"));
		}
		else
		{
			if (!in_array($arTestProperties["ApproveType"], array("any", "all", "vote")))
				$arErrors[] = array("code" => "NotInRange", "parameter" => "ApproveType", "message" => GetMessage("BPAA_ACT_PROP_EMPTY3"));
		}
		if (!array_key_exists("Name", $arTestProperties) || $arTestProperties["Name"] == '')
		{
			$arErrors[] = array("code" => "NotExist", "parameter" => "Name", "message" => GetMessage("BPAA_ACT_PROP_EMPTY4"));
		}
		return array_merge($arErrors, parent::ValidateProperties($arTestProperties, $user));
	}
	private function CalculateTimeoutDuration()
	{
		$timeoutDuration = ($this->IsPropertyExists("TimeoutDuration") ? $this->TimeoutDuration : 0);
		$timeoutDurationType = ($this->IsPropertyExists("TimeoutDurationType") ? $this->TimeoutDurationType : "s");
		$timeoutDurationType = mb_strtolower($timeoutDurationType);
		if (!in_array($timeoutDurationType, array("s", "d", "h", "m")))
			$timeoutDurationType = "s";
		$timeoutDuration = intval($timeoutDuration);
		switch ($timeoutDurationType)
		{
			case 'd':
				$timeoutDuration *= 3600 * 24;
				break;
			case 'h':
				$timeoutDuration *= 3600;
				break;
			case 'm':
				$timeoutDuration *= 60;
				break;
			default:
				break;
		}
		return min($timeoutDuration, 3600 * 24 * 365 * 5);
	}
	public static function GetPropertiesDialog($documentType, $activityName, $arWorkflowTemplate, $arWorkflowParameters, $arWorkflowVariables, $arCurrentValues = null, $formName = "")
	{
		$runtime = CBPRuntime::GetRuntime();
		$arMap = array(
			"NotifySubject" => "notify_subject",
			"NotifyText" => "notify_text",
			"Reminder1Hours" => "rem1_hours",
			"Reminder2Hours" => "rem2_hours",
			"Reminder3Hours" => "rem3_hours",
			"Reminder1Subject" => "rem1_subject",
			"Reminder2Subject" => "rem2_subject",
			"Reminder3Subject" => "rem3_subject",
			"Reminder1Text" => "rem1_text",
			"Reminder2Text" => "rem2_text",
			"Reminder3Text" => "rem3_text",
			"Users" => "approve_users",
			"ApproveType" => "approve_type",
			"ApproveMinPercent" => "approve_percent",
			"OverdueDate" => "approve_overdue_date",
			"Name" => "approve_name",
			"Description" => "approve_description",
			"Parameters" => "approve_parameters",
			"ApproveWaitForAll" => "approve_wait",
			"StatusMessage" => "status_message",
			"SetStatusMessage" => "set_status_message",
			"TimeoutDuration" => "timeout_duration",
			"TimeoutDurationType" => "timeout_duration_type",
			"TaskButton1Message" => "task_button1_message",
			"TaskButton2Message" => "task_button2_message",
			"TaskButton3Message" => "task_button3_message",
			"CommentLabelMessage" => "comment_label_message",
			"ShowComment" => "show_comment",
			'CommentRequired' => 'comment_required',
			"AccessControl" => "access_control",
			"DelegationType" => "delegation_type",
			"RefineAllowed" => "refine_allowed", // <<< НОВОЕ СООТВЕТСТВИЕ
		);
		if (!is_array($arWorkflowParameters))
			$arWorkflowParameters = array();
		if (!is_array($arWorkflowVariables))
			$arWorkflowVariables = array();
		if (!is_array($arCurrentValues))
		{
			$arCurrentValues = Array();
			$arCurrentActivity = &CBPWorkflowTemplateLoader::FindActivityByName($arWorkflowTemplate, $activityName);
			if (is_array($arCurrentActivity["Properties"]))
			{
				foreach ($arMap as $k => $v)
				{
					if (array_key_exists($k, $arCurrentActivity["Properties"]))
					{
						if ($k == "Users")
						{
							$arCurrentValues[$arMap[$k]] = CBPHelper::UsersArrayToString($arCurrentActivity["Properties"][$k], $arWorkflowTemplate, $documentType);
						}
						elseif ($k == "TimeoutDuration")
						{
							$arCurrentValues["timeout_duration"] = $arCurrentActivity["Properties"]["TimeoutDuration"];
							if (!CBPActivity::isExpression($arCurrentValues["timeout_duration"])
								&& !array_key_exists("TimeoutDurationType", $arCurrentActivity["Properties"]))
							{
								$arCurrentValues["timeout_duration"] = intval($arCurrentValues["timeout_duration"]);
								$arCurrentValues["timeout_duration_type"] = "s";
								if ($arCurrentValues["timeout_duration"] % (3600 * 24) == 0)
								{
									$arCurrentValues["timeout_duration"] = $arCurrentValues["timeout_duration"] / (3600 * 24);
									$arCurrentValues["timeout_duration_type"] = "d";
								}
								elseif ($arCurrentValues["timeout_duration"] % 3600 == 0)
								{
									$arCurrentValues["timeout_duration"] = $arCurrentValues["timeout_duration"] / 3600;
									$arCurrentValues["timeout_duration_type"] = "h";
								}
								elseif ($arCurrentValues["timeout_duration"] % 60 == 0)
								{
									$arCurrentValues["timeout_duration"] = $arCurrentValues["timeout_duration"] / 60;
									$arCurrentValues["timeout_duration_type"] = "m";
								}
							}
						}
						else
						{
							$arCurrentValues[$arMap[$k]] = $arCurrentActivity["Properties"][$k];
						}
					}
					else
					{
						if (!is_array($arCurrentValues) || !array_key_exists($arMap[$k], $arCurrentValues))
							$arCurrentValues[$arMap[$k]] = "";
					}
				}
			}
			else
			{
				foreach ($arMap as $k => $v)
					$arCurrentValues[$arMap[$k]] = "";
			}
			if($arCurrentValues["approve_wait"] == '')
				$arCurrentValues["approve_wait"] = "N";
			if($arCurrentValues["approve_percent"] == '')
				$arCurrentValues["approve_percent"] = "50";
		}
		if ($arCurrentValues['status_message'] == '')
			$arCurrentValues['status_message'] = GetMessage("BPAA_ACT_INFO");
		if ($arCurrentValues['task_button1_message'] == '')
			$arCurrentValues['task_button1_message'] = GetMessage("BPAA_ACT_BUTTON1");
		if ($arCurrentValues['task_button2_message'] == '')
			$arCurrentValues['task_button2_message'] = GetMessage("BPAA_ACT_BUTTON2");
		if ($arCurrentValues['comment_label_message'] == '')
			$arCurrentValues['comment_label_message'] = GetMessage("BPAA_ACT_COMMENT");
		if ($arCurrentValues["timeout_duration_type"] == '')
			$arCurrentValues["timeout_duration_type"] = "s";
		$documentService = $runtime->GetService("DocumentService");
		$arDocumentFields = $documentService->GetDocumentFields($documentType);
		if (!isset($arCurrentValues["task_button3_message"]))
			$arCurrentValues["task_button3_message"] = "";
		return $runtime->ExecuteResourceFile(
			__FILE__,
			"properties_dialog.php",
			array(
				"arCurrentValues" => $arCurrentValues,
				"arDocumentFields" => $arDocumentFields,
				"formName" => $formName,
			)
		);
	}
	public static function GetPropertiesDialogValues($documentType, $activityName, &$arWorkflowTemplate, &$arWorkflowParameters, &$arWorkflowVariables, $arCurrentValues, &$arErrors)
	{
		$arErrors = array();
		$runtime = CBPRuntime::GetRuntime();
		$arMap = array(
			"notify_subject" => "NotifySubject",
			"notify_text" => "NotifyText",
			"rem1_hours" => "Reminder1Hours",
			"rem2_hours" => "Reminder2Hours",
			"rem3_hours" => "Reminder3Hours",
			"rem1_subject" => "Reminder1Subject",
			"rem2_subject" => "Reminder2Subject",
			"rem3_subject" => "Reminder3Subject",
			"rem1_text" => "Reminder1Text",
			"rem2_text" => "Reminder2Text",
			"rem3_text" => "Reminder3Text",
			"approve_users" => "Users",
			"approve_type" => "ApproveType",
			"approve_overdue_date" => "OverdueDate",
			"approve_percent" => "ApproveMinPercent",
			"approve_wait" => "ApproveWaitForAll",
			"approve_name" => "Name",
			"approve_description" => "Description",
			"approve_parameters" => "Parameters",
			"status_message" => "StatusMessage",
			"set_status_message" => "SetStatusMessage",
			"timeout_duration" => "TimeoutDuration",
			"timeout_duration_type" => "TimeoutDurationType",
			"task_button1_message" => "TaskButton1Message",
			"task_button2_message" => "TaskButton2Message",
			"task_button3_message" => "TaskButton3Message",
			"comment_label_message" => "CommentLabelMessage",
			"show_comment" => "ShowComment",
			'comment_required' => 'CommentRequired',
			"access_control" => "AccessControl",
			"delegation_type" => "DelegationType",
			"refine_allowed" => "RefineAllowed", // <<< ОБРАТНОЕ СООТВЕТСТВИЕ
		);
		$arProperties = array();
		foreach ($arMap as $key => $value)
		{
			if ($key == "approve_users")
				continue;
			if(!empty($arCurrentValues[$key . '_X']))
			{
				$arProperties[$value] = $arCurrentValues[$key . "_X"];
			}
			else
			{
				$arProperties[$value] = $arCurrentValues[$key] ?? null;
			}
		}
		$arProperties["Users"] = CBPHelper::UsersStringToArray($arCurrentValues["approve_users"], $documentType, $arErrors);
		if (count($arErrors) > 0)
			return false;
		$arErrors = self::ValidateProperties($arProperties, new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser));
		if (count($arErrors) > 0)
			return false;
		$arCurrentActivity = &CBPWorkflowTemplateLoader::FindActivityByName($arWorkflowTemplate, $activityName);
		$arCurrentActivity["Properties"] = $arProperties;
		return true;
	}
	public static function GetReturnValues()
	{
		return array(
			"RefinePressed" => array(
				"Name" => GetMessage("BPAA_DESCR_REFINE_PRESSED"),
				"Type" => "string",
			),
		);
	}
}
function approvecopyactiveschedule_remind_agent($taskId, $subjectB64, $bodyB64)
{
	if (!CModule::IncludeModule('bizproc')) return "";
	$subject = base64_decode($subjectB64);
	$body = base64_decode($bodyB64);
	$runtime = CBPRuntime::GetRuntime();
	/** @var CBPTaskService $taskService */
	$taskService = $runtime->GetService("TaskService");
	$task = $taskService->GetTaskById((int)$taskId);
	if (!$task)
		return ""; // task not found => stop
	// Check if any user still has Waiting status
	$stillWaiting = false;
	$users = $taskService->GetTaskUsers((int)$taskId);
	if (is_array($users))
	{
		foreach ($users as $u)
		{
			if ((int)$u['STATUS'] === CBPTaskUserStatus::Waiting)
			{ $stillWaiting = true; break; }
		}
	}
	if (!$stillWaiting) return ""; // no reminders if completed
	// Send email to all task users (who still waiting)
	foreach ($users as $u)
	{
		if ((int)$u['STATUS'] !== CBPTaskUserStatus::Waiting) continue;
		$rsUser = CUser::GetByID((int)$u['USER_ID']);
		if ($arU = $rsUser->Fetch())
		{
			$email = trim($arU['EMAIL']);
			if ($email <> '')
			{
				\Bitrix\Main\Mail\Mail::send(array(
					'TO' => $email,
					'SUBJECT' => $subject,
					'BODY' => $body
				));
			}
		}
	}
	return ""; // one-shot
}