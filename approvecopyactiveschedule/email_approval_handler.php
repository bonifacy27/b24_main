<?php
namespace Acs\EmailApproval;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();
require_once __DIR__ . '/email_approval_tokens.php';

/** Input is decoded UTF-8 plain text supplied by a trusted mail-rule adapter. */
class ReplyHandler
{
    public static function parse($subject, $body)
    {
        if (strlen($subject) > 8192 || strlen($body) > 262144)
            throw new \InvalidArgumentException('message_too_large');
        preg_match_all('/\[ACS:([a-f0-9]{64})\]/i', $subject, $matches);
        $tokens = array_unique(array_map('strtolower', $matches[1]));
        if (count($tokens) !== 1) throw new \InvalidArgumentException('ambiguous_token');
        $lines = preg_split('/\r\n|\r|\n/', $body);
        while ($lines && trim($lines[0]) === '') array_shift($lines);
        $command = trim((string)array_shift($lines));
        if (preg_match('/^Согласовано[.!]?$/iu', $command)) $approve = true;
        elseif (preg_match('/^Отклонено[.!]?$/iu', $command)) $approve = false;
        else throw new \InvalidArgumentException('unknown_decision');
        $comment = array();
        foreach ($lines as $line)
        {
            // Stop at a quote, signature or a recognizable reply delimiter.
            if (preg_match('/^\s*>|^--\s*$|^-{2,}\s*(Original Message|Исходное сообщение)|^On .+wrote:|^.*написал\(а\):/iu', $line)) break;
            if (preg_match('/ACS:[a-f0-9]{64}/i', $line)) break;
            $comment[] = $line;
        }
        $comment = trim(implode("\n", $comment));
        if (strlen($comment) > 16384) throw new \InvalidArgumentException('comment_too_large');
        return array('hash' => hash('sha256', reset($tokens)), 'approve' => $approve, 'comment' => $comment);
    }

    private static function waiting($service, $taskId, $userId)
    {
        $result = \CBPTaskService::GetList(array(), array(
            'ID' => $taskId, 'USER_ID' => $userId,
            'USER_STATUS' => \CBPTaskUserStatus::Waiting,
        ), false, false, array('ID'));
        return (bool)$result->Fetch();
    }

    /** Sender authorization is supplied by portal policy, never by the message itself. */
    public static function handle(array $mail, $senderAuthorized = false)
    {
        if ($senderAuthorized !== true) return array('status' => 'unverified_sender');
        if (!empty($mail['auto_reply'])) return array('status' => 'automatic_reply');
        $sender = trim(isset($mail['from_email']) ? $mail['from_email'] : '');
        if (!filter_var($sender, FILTER_VALIDATE_EMAIL)) return array('status' => 'invalid_sender');
        try { $decision = static::parse((string)($mail['subject'] ?? ''), (string)($mail['body_text'] ?? '')); }
        catch (\InvalidArgumentException $e) { return array('status' => $e->getMessage()); }
        if (!\Bitrix\Main\Loader::includeModule('bizproc')) return array('status' => 'bizproc_unavailable');
        $row = TokenTable::getList(array('filter' => array('=TOKEN_HASH' => $decision['hash']), 'limit' => 1))->fetch();
        if (!$row) return array('status' => 'token_not_found');
        if ($row['STATUS'] !== 'PENDING') return array('status' => 'token_unavailable');
        if ($row['EXPIRES_AT']->getTimestamp() <= time()) return array('status' => 'token_expired');
        if (strcasecmp($sender, $row['EMAIL']) !== 0) return array('status' => 'sender_mismatch');
        $userId = (int)$row['USER_ID'];
        $user = \CUser::GetByID($userId)->Fetch();
        if (!$user || $user['ACTIVE'] !== 'Y' || strcasecmp($sender, trim($user['EMAIL'])) !== 0)
            return array('status' => 'user_changed');
        $service = \CBPRuntime::GetRuntime()->GetService('TaskService');
        $taskId = (int)$row['TASK_ID'];
        $task = \CBPTaskService::GetList(array(), array('ID' => $taskId), false, false, array(
            'ID', 'STATUS', 'ACTIVITY', 'WORKFLOW_ID', 'ACTIVITY_NAME', 'PARAMETERS',
        ))->Fetch();
        if (!$task || (int)$task['STATUS'] !== \CBPTaskStatus::Running
            || $task['ACTIVITY'] !== 'approvecopyactiveschedule'
            || $task['WORKFLOW_ID'] !== $row['WORKFLOW_ID']
            || $task['ACTIVITY_NAME'] !== $row['ACTIVITY_NAME']
            || !static::waiting($service, $taskId, $userId)) return array('status' => 'task_unavailable');
        $parameters = $task['PARAMETERS'];
        $required = $parameters['CommentRequired'] ?? 'N';
        if (($parameters['ShowComment'] ?? 'Y') === 'Y' && $decision['comment'] === ''
            && ($required === 'Y' || ($required === 'YA' && $decision['approve']) || ($required === 'YR' && !$decision['approve'])))
            return array('status' => 'comment_required');

        // Commit the claim BEFORE sending the external event. An ambiguous failure
        // must never make the token reusable automatically.
        $connection = \Bitrix\Main\Application::getConnection();
        $connection->startTransaction();
        try
        {
            $locked = $connection->query('SELECT STATUS FROM acs_email_approval_tokens WHERE ID=' . (int)$row['ID'] . ' FOR UPDATE')->fetch();
            if (!$locked || $locked['STATUS'] !== 'PENDING')
            {
                $connection->rollbackTransaction();
                return array('status' => 'token_unavailable');
            }
            $result = TokenTable::update((int)$row['ID'], array('STATUS' => 'PROCESSING'));
            if (!$result->isSuccess()) throw new \RuntimeException('claim_failed');
            $connection->commitTransaction();
        }
        catch (\Throwable $e)
        {
            $connection->rollbackTransaction();
            return array('status' => 'claim_failed');
        }
        try
        {
            $request = array('task_comment' => $decision['comment'], 'EMAIL_TOKEN_ID' => (int)$row['ID']);
            $request[$decision['approve'] ? 'approve' : 'nonapprove'] = 'Y';
            $errors = array();
            // The standard dispatcher loads the activity and resolves the task user.
            $accepted = \CBPDocument::PostTaskForm($task, $userId, $request, $errors, '');
            $expected = $decision['approve'] ? \CBPTaskUserStatus::Yes : \CBPTaskUserStatus::No;
            $confirmed = (bool)\CBPTaskService::GetList(array(), array(
                'ID' => $taskId, 'USER_ID' => $userId, 'USER_STATUS' => $expected,
            ), false, false, array('ID'))->Fetch();
            $status = $accepted && $confirmed ? 'USED' : 'REVIEW';
            $result = TokenTable::update((int)$row['ID'], array('STATUS' => $status));
            if (!$result->isSuccess()) return array('status' => 'review_required');
            return array('status' => $status === 'USED' ? 'completed' : 'review_required', 'task_id' => $taskId);
        }
        catch (\Throwable $e)
        {
            // PROCESSING remains blocked for manual reconciliation.
            return array(
                'status' => 'review_required', 'task_id' => $taskId,
                'error_class' => get_class($e),
                'error_file' => basename($e->getFile()), 'error_line' => $e->getLine(),
            );
        }
    }
}
