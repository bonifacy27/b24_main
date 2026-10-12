<?php
// Paste the code below (without the opening PHP tag) into the rule's PHP action.
// Adjust the activity directory if it is installed elsewhere.
$acsActivityDir = $_SERVER['DOCUMENT_ROOT'] . '/local/activities/custom/approvecopyactiveschedule';
require_once $acsActivityDir . '/email_approval_mail_rule.php';

// Trust for this Exchange route was confirmed by the portal administrator.
$acsMailConfig = array(
    'sender_mode' => 'exchange_internal',
    'exchange_servers' => array('MV-EXCH-OFF.nsc.ru', 'RV-EXCH-OFF.nsc.ru'),
    'sender_domains' => array('tricolor.ru'),
    // Protected headers and sender-address enforcement confirmed by administrator.
    'exchange_sender_trust_confirmed' => true,
);

$acsResult = \Acs\EmailApproval\MailRuleAdapter::handle($arMessageFields, $acsMailConfig);
// Log only message ID, result code and task ID. Never log the token or subject.
AddMessage2Log(
    'ACS: message_id=' . (int)($arMessageFields['ID'] ?? 0)
    . '; status=' . $acsResult['status']
    . '; task_id=' . (int)($acsResult['task_id'] ?? 0),
    'acs_email_approval'
);
