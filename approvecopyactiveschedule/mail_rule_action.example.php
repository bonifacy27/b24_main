<?php
// Paste below into the PHP action WITHOUT the opening PHP tag.
$acsRoot = rtrim((string)\Bitrix\Main\Application::getDocumentRoot(), '/');
$acsLogFile = $acsRoot . '/upload/logs/acs_email_approval.log';
$acsMessageId = (int)($arMessageFields['ID'] ?? 0);
$acsLog = static function ($status, $taskId = 0, $errorClass = '') use ($acsLogFile, $acsMessageId) {
    $dir = dirname($acsLogFile);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $line = date('c') . ' message_id=' . $acsMessageId
        . ' status=' . $status . ' task_id=' . (int)$taskId;
    if ($errorClass !== '') $line .= ' error_class=' . $errorClass;
    if (@file_put_contents($acsLogFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
        error_log('ACS: cannot write ' . $acsLogFile . '; message_id=' . $acsMessageId);
    }
};

// Written before loading any custom PHP files or checking the message.
$acsLog('rule_started');
try {
    // Adjust this directory to the actual location of the installed activity.
    $acsActivityDir = $acsRoot . '/local/activities/approvecopyactiveschedule';
    if (!is_file($acsActivityDir . '/email_approval_mail_rule.php')) {
        $acsLog('handler_file_missing');
        return;
    }
    require_once $acsActivityDir . '/email_approval_mail_rule.php';
    $acsLog('handler_loaded');

    $acsMailConfig = array(
        'sender_mode' => 'exchange_internal',
        'exchange_servers' => array('MV-EXCH-OFF.nsc.ru', 'RV-EXCH-OFF.nsc.ru'),
        'sender_domains' => array('tricolor.ru'),
        'exchange_sender_trust_confirmed' => true,
    );
    if (!isset($arMessageFields) || !is_array($arMessageFields)) {
        $acsLog('message_fields_missing');
        return;
    }
    $acsResult = \Acs\EmailApproval\MailRuleAdapter::handle($arMessageFields, $acsMailConfig);
    $acsLog($acsResult['status'], $acsResult['task_id'] ?? 0);
} catch (\Throwable $e) {
    // Exception messages can contain secrets; log only the class.
    $acsLog('rule_exception', 0, get_class($e));
}
