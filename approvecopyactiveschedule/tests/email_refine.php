<?php
namespace Bitrix\Main\Entity {
    class DataManager {
        public static $row;
        public static function getList($params) { return new \TestRows(self::$row); }
        public static function getByPrimary($id) { return new \TestRows(self::$row); }
        public static function update($id, $fields) { self::$row = array_merge(self::$row, $fields); return new \TestResult(); }
    }
}
namespace Bitrix\Main {
    class Loader { public static function includeModule($name) { return true; } }
    class Application { public static function getConnection() { return new \TestConnection(); } }
}
namespace {
    require __DIR__ . '/notification_attachment.php';
    require dirname(__DIR__) . '/email_approval_handler.php';
    class TestRows {
        private $row;
        public function __construct($row) { $this->row = $row; }
        public function Fetch() { $row = $this->row; $this->row = false; return $row; }
    }
    class TestResult { public function isSuccess() { return true; } }
    class TestConnection {
        public function startTransaction() {}
        public function commitTransaction() {}
        public function rollbackTransaction() {}
        public function query($query) { return new TestRows(array('STATUS' => \Acs\EmailApproval\TokenTable::$row['STATUS'])); }
    }
    class CBPTaskStatus { const Running = 0; const CompleteYes = 1; const CompleteNo = 2; }
    class CBPTaskUserStatus { const Waiting = 0; const Yes = 1; const No = 2; }
    class CBPActivityExecutionStatus { const Closed = 3; }
    class CBPNotSupportedException extends Exception {}
    class CBPArgumentNullException extends Exception {}
    class CUser {
        public static function GetByID($id) { return new TestRows(array('ACTIVE' => 'Y', 'EMAIL' => 'user@example.org')); }
        public static function GetList($sort, $order, $filter, $params) { return new TestRows(false); }
    }
    class CBPTaskService {
        public static $task;
        public static $userStatus = CBPTaskUserStatus::Waiting;
        public static function GetList($sort, $filter, $group, $nav, $select) {
            if (isset($filter['USER_STATUS'])) return new TestRows($filter['USER_STATUS'] === self::$userStatus ? array('ID' => 10) : false);
            return new TestRows(self::$task);
        }
        public function MarkCompleted($taskId, $userId, $status) { self::$userStatus = $status; }
    }
    class CBPDocument {
        public static function PostTaskForm($task, $userId, $request, &$errors, $name) {
            return CBPapprovecopyactiveschedule::PostTaskForm($task, $userId, $request, $errors, $name);
        }
    }
    class TestWorkflow { public function GetService($name) { return new CBPTaskService(); } }
    class RefineActivity extends AttachmentActivity {
        public $workflow;
        public $executionStatus = 0;
        public $finished = false;
        public function __construct() {
            parent::__construct('test');
            $this->workflow = new TestWorkflow();
            $this->EmailApprovalEnabled = 'Y';
            $this->RefineAllowed = 'Y';
            $this->SetStatusMessage = 'N';
            $this->TimeoutDuration = 0;
            $this->TotalCount = 2;
            foreach (array('taskId' => 10, 'taskUsers' => array(1, 2)) as $name => $value) {
                $property = new ReflectionProperty(CBPapprovecopyactiveschedule::class, $name);
                $property->setAccessible(true);
                $property->setValue($this, $value);
            }
        }
        public function GetWorkflowInstanceId() { return 'wf'; }
        public function __get($name) { return $name === 'name' ? 'test' : parent::__get($name); }
        public function Unsubscribe(IBPActivityExternalEventListener $eventHandler) {}
        protected function ExecuteOnNonApprove() { $this->finished = true; $this->executionStatus = CBPActivityExecutionStatus::Closed; }
    }
    function setupRefine($allowed, $type = 'all') {
        \Acs\EmailApproval\TokenTable::$row = array('ID' => 1, 'STATUS' => 'PENDING', 'TASK_ID' => 10, 'USER_ID' => 1,
            'EMAIL' => 'user@example.org', 'WORKFLOW_ID' => 'wf', 'ACTIVITY_NAME' => 'test', 'EXPIRES_AT' => new DateTime('+1 day'));
        CBPTaskService::$task = array('ID' => 10, 'STATUS' => CBPTaskStatus::Running, 'ACTIVITY' => 'approvecopyactiveschedule',
            'WORKFLOW_ID' => 'wf', 'ACTIVITY_NAME' => 'test', 'PARAMETERS' => array('RefineAllowed' => $allowed));
        CBPTaskService::$userStatus = CBPTaskUserStatus::Waiting;
        $activity = new RefineActivity();
        $activity->RefineAllowed = $allowed;
        $activity->ApproveType = $type;
        $activity->ApproveWaitForAll = 'Y';
        CBPRuntime::$eventHandler = array($activity, 'OnExternalEvent');
        return $activity;
    }
    $mail = array('from_email' => 'user@example.org', 'subject' => '', 'body_text' => "Доработать\nИсправить сумму\nЕщё строка\n> ACS:" . str_repeat('a', 64));
    $activity = setupRefine('N');
    $result = \Acs\EmailApproval\ReplyHandler::handle($mail, true);
    checkAttachment($result['status'] === 'refine_not_allowed' && \Acs\EmailApproval\TokenTable::$row['STATUS'] === 'PENDING', 'Disabled refine consumed token');
    checkAttachment($activity->RefinePressed === 'N' && !$activity->finished, 'Disabled refine changed workflow');
    foreach (array('all', 'any', 'vote') as $type) {
        $activity = setupRefine('Y', $type);
        $result = \Acs\EmailApproval\ReplyHandler::handle($mail, true);
        checkAttachment($result['status'] === 'completed', 'Refine not confirmed: ' . $result['status']);
        checkAttachment($activity->finished && $activity->RefinePressed === 'Y', 'Refine did not finish task in ' . $type);
        checkAttachment($activity->Comments === 'Исправить сумму' && $activity->LastApproverComment === 'Исправить сумму', 'Refine comment lost');
        checkAttachment($activity->ConfirmationMethod === 'email', 'Refine source lost');
        $activity->OnExternalEvent(array('USER_ID' => 1, 'APPROVE' => true));
        checkAttachment($activity->RefinePressed === 'Y', 'Late event erased refine result');
    }
    // The task snapshot can allow refine while the live instance disallows it.
    $activity = setupRefine('Y');
    $activity->RefineAllowed = 'N';
    $result = \Acs\EmailApproval\ReplyHandler::handle($mail, true);
    checkAttachment(!$activity->finished && $activity->RefinePressed === 'N' && $result['status'] === 'review_required', 'Live option gate ignored');
    $activity = setupRefine('Y');
    CBPTaskService::$task['PARAMETERS']['ShowComment'] = 'Y';
    CBPTaskService::$task['PARAMETERS']['CommentRequired'] = 'YR';
    $mail['body_text'] = "Доработка\n\n> ACS:" . str_repeat('a', 64);
    $result = \Acs\EmailApproval\ReplyHandler::handle($mail, true);
    checkAttachment($result['status'] === 'comment_required' && \Acs\EmailApproval\TokenTable::$row['STATUS'] === 'PENDING', 'Required refine comment ignored');
    echo "Email refine checks passed\n";
}
