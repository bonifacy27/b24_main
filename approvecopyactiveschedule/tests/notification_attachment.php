<?php
// Standalone checks; no portal installation or outgoing mail required.
define('B_PROLOG_INCLUDED', true);
function GetMessage($code) { return $code; }
interface IBPEventActivity {}
interface IBPActivityExternalEventListener {}
class CBPCompositeActivity
{
    protected $arProperties = array();
    public $tracking = array();
    public function __construct($name) {}
    public function SetPropertiesTypes($types, $returns = array()) {}
    public static function ValidateProperties($properties, CBPWorkflowTemplateUser $user = null) { return array(); }
    public function __get($name) { return $this->arProperties[$name] ?? null; }
    public function __set($name, $value) { $this->arProperties[$name] = $value; }
    public function IsPropertyExists($name) { return array_key_exists($name, $this->arProperties); }
    public function WriteToTrackingService($message) { $this->tracking[] = $message; }
}
class CBPActivity
{
    public static function isExpression($value) { return is_string($value) && strpos($value, '{=') === 0; }
}
class CBPWorkflowTemplateUser
{
    const CurrentUser = 0;
    public function __construct($user) {}
}
class CBPHelper
{
    public static function UsersStringToArray($value, $type, &$errors) { return array($value); }
    public static function UsersArrayToString($value, $template, $type) { return implode(',', $value); }
}
class CBPWorkflowTemplateLoader
{
    public static function &FindActivityByName(&$template, $name) { return $template[0]; }
}
class CBPRuntime
{
    public static function GetRuntime() { return new self(); }
    public function GetService($name) { return $this; }
    public function GetDocumentFields($type) { return array(); }
    public function ExecuteResourceFile($file, $resource, $arguments) { return $arguments; }
}
class CFile
{
    public static $calls = array();
    public static function MakeFileArray($id)
    {
        self::$calls[] = $id;
        if ($id === 2) throw new RuntimeException('Storage unavailable');
        if ($id !== 1) return false;
        return array('tmp_name' => __FILE__, 'name' => 'Документ.pdf', 'type' => 'application/pdf');
    }
}
require_once dirname(__DIR__) . '/approvecopyactiveschedule.php';
class AttachmentActivity extends CBPapprovecopyactiveschedule
{
    public function attachments() { return $this->getNotifyAttachments(); }
    public function legacyState() { unset($this->arProperties['NotifyFile']); }
}
function checkAttachment($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}
$activity = new AttachmentActivity('test');
checkAttachment($activity->attachments() === array(), 'Default must have no attachments');
$activity->legacyState();
$activity = unserialize(serialize($activity));
checkAttachment(!$activity->IsPropertyExists('NotifyFile'), 'Legacy property unexpectedly added');
checkAttachment($activity->attachments() === array() && CFile::$calls === array(), 'Legacy workflow accessed file storage');
$activity->NotifyFile = '1';
checkAttachment($activity->attachments() === array(array('PATH' => __FILE__, 'NAME' => 'Документ.pdf', 'CONTENT_TYPE' => 'application/pdf')), 'File metadata lost');
$activity->NotifyFile = array(1, '1', 2, 3, '', null, '/etc/passwd', 'https://example.org/file', -1);
CFile::$calls = array();
checkAttachment(count($activity->attachments()) === 1, 'Missing or duplicate file attached');
checkAttachment(CFile::$calls === array(1, 2, 3), 'Invalid IDs reached storage');
checkAttachment(count($activity->tracking) === 5, 'Unavailable attachments were not logged');
$activity->NotifyFile = '';
checkAttachment($activity->attachments() === array(), 'Cleared field retained attachment');
$template = array(array('Properties' => array()));
$parameters = $variables = $errors = array();
$values = array('approve_users' => 'user_1', 'approve_type' => 'all', 'approve_name' => 'Test', 'notify_file' => '{=Document:FILE}');
checkAttachment(AttachmentActivity::GetPropertiesDialogValues(array(), 'test', $template, $parameters, $variables, $values, $errors), 'Saving file expression failed');
checkAttachment($template[0]['Properties']['NotifyFile'] === '{=Document:FILE}', 'File expression changed on save');
$dialog = AttachmentActivity::GetPropertiesDialog(array(), 'test', $template, $parameters, $variables);
checkAttachment($dialog['arCurrentValues']['notify_file'] === '{=Document:FILE}', 'File expression lost on reopen');
unset($values['notify_file']);
checkAttachment(AttachmentActivity::GetPropertiesDialogValues(array(), 'test', $template, $parameters, $variables, $values, $errors), 'Old dialog values rejected');
checkAttachment($template[0]['Properties']['NotifyFile'] === null, 'Old dialog should save empty file');
echo "Notification attachment checks passed\n";
