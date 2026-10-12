<?php
namespace Acs\EmailApproval;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

/** Raw tokens must never be persisted or written to the tracking log. */
class TokenTable extends \Bitrix\Main\Entity\DataManager
{
    public static function getTableName()
    {
        return 'acs_email_approval_tokens';
    }

    public static function getMap()
    {
        return array(
            new \Bitrix\Main\Entity\IntegerField('ID', array('primary' => true, 'autocomplete' => true)),
            new \Bitrix\Main\Entity\StringField('TOKEN_HASH', array('required' => true)),
            new \Bitrix\Main\Entity\IntegerField('TASK_ID', array('required' => true)),
            new \Bitrix\Main\Entity\StringField('WORKFLOW_ID', array('required' => true)),
            new \Bitrix\Main\Entity\StringField('ACTIVITY_NAME', array('required' => true)),
            new \Bitrix\Main\Entity\IntegerField('USER_ID', array('required' => true)),
            new \Bitrix\Main\Entity\StringField('EMAIL', array('required' => true)),
            new \Bitrix\Main\Entity\DatetimeField('CREATED_AT', array('required' => true)),
            new \Bitrix\Main\Entity\DatetimeField('EXPIRES_AT', array('required' => true)),
            new \Bitrix\Main\Entity\StringField('STATUS', array('required' => true)),
        );
    }

    public static function issue($taskId, $workflowId, $activityName, $userId, $email, $expiresAt)
    {
        if ((int)$taskId <= 0 || (int)$userId <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL))
            throw new \InvalidArgumentException('Invalid token recipient or task');
        $token = bin2hex(random_bytes(32));
        $result = static::add(array(
            'TOKEN_HASH' => hash('sha256', $token),
            'TASK_ID' => (int)$taskId,
            'WORKFLOW_ID' => (string)$workflowId,
            'ACTIVITY_NAME' => (string)$activityName,
            'USER_ID' => (int)$userId,
            'EMAIL' => trim($email),
            'CREATED_AT' => new \Bitrix\Main\Type\DateTime(),
            'EXPIRES_AT' => \Bitrix\Main\Type\DateTime::createFromTimestamp($expiresAt),
            'STATUS' => 'PENDING',
        ));
        if (!$result->isSuccess()) throw new \RuntimeException('Cannot store email approval token');
        return array('id' => (int)$result->getId(), 'token' => $token);
    }

    public static function revoke($id)
    {
        $result = static::update((int)$id, array('STATUS' => 'REVOKED'));
        if (!$result->isSuccess()) throw new \RuntimeException('Cannot revoke email approval token');
    }
}
