<?php
namespace Bitrix\Main\Entity { class DataManager {} }
namespace {
    define('B_PROLOG_INCLUDED', true);
    class CMailUtil { public static function ExtractMailAddress($value) { return $value; } }
    require_once dirname(__DIR__) . '/email_approval_mail_rule.php';
    $config = array('authserv_id' => 'mx.example.org', 'gateway_strips_untrusted_results' => true);
    $fields = array('FIELD_FROM' => 'employee@example.org', 'HEADER' => "Authentication-Results: mx.example.org;\r\n dmarc=pass header.from=example.org\r\n");
    if (!\Acs\EmailApproval\MailRuleAdapter::verifyGateway($fields, $config)) throw new \RuntimeException('valid gateway rejected');
    $badHeaders = array(
        'Authentication-Results: forged.example.org; dmarc=pass header.from=example.org',
        'Authentication-Results: mx.example.org; dmarc=fail header.from=example.org',
        'Authentication-Results: mx.example.org; spf=pass smtp.mailfrom=example.org',
        'Authentication-Results: mx.example.org; dmarc=pass header.from=other.org',
        $fields['HEADER'] . $fields['HEADER'],
    );
    foreach ($badHeaders as $header) {
        $bad = $fields; $bad['HEADER'] = $header;
        if (\Acs\EmailApproval\MailRuleAdapter::verifyGateway($bad, $config)) throw new \RuntimeException('untrusted results accepted');
    }
    if (\Acs\EmailApproval\MailRuleAdapter::verifyGateway($fields, array('authserv_id' => 'mx.example.org'))) throw new \RuntimeException('gateway trust assumed');
    if (\Acs\EmailApproval\MailRuleAdapter::handle($fields)['status'] !== 'unverified_sender') throw new \RuntimeException('default not blocked');
    $exchangeConfig = array('exchange_sender_trust_confirmed' => true, 'exchange_servers' => array('MV-EXCH-OFF.nsc.ru', 'RV-EXCH-OFF.nsc.ru'), 'sender_domains' => array('tricolor.ru'));
    $exchange = array('FIELD_FROM' => 'employee@tricolor.ru', 'HEADER' => "X-MS-Exchange-Organization-AuthAs: Internal\r\nX-MS-Exchange-Organization-AuthSource: MV-EXCH-OFF.nsc.ru\r\nX-MS-Exchange-Organization-AuthMechanism: 04\r\nReceived: from MV-EXCH-OFF.NSC.ru ([fe80::1]) by MV-EXCH-OFF.nsc.ru ([fe80::1]) with mapi id 15.01.2507.037; Mon, 12 Oct 2026\r\n");
    if (!\Acs\EmailApproval\MailRuleAdapter::verifyExchange($exchange, $exchangeConfig)) throw new \RuntimeException('Exchange sample rejected');
    foreach (array(
        str_replace('Internal', 'Anonymous', $exchange['HEADER']),
        str_replace('AuthMechanism: 04', 'AuthMechanism: 10', $exchange['HEADER']),
        $exchange['HEADER'] . "X-MS-Exchange-Organization-AuthAs: Internal\r\n",
        str_replace('with mapi id', 'with SMTP id', $exchange['HEADER']),
    ) as $header) {
        $bad = $exchange; $bad['HEADER'] = $header;
        if (\Acs\EmailApproval\MailRuleAdapter::verifyExchange($bad, $exchangeConfig)) throw new \RuntimeException('untrusted Exchange sample accepted');
    }
    $exchangeConfig['exchange_sender_trust_confirmed'] = false;
    if (\Acs\EmailApproval\MailRuleAdapter::verifyExchange($exchange, $exchangeConfig)) throw new \RuntimeException('Exchange trust assumed');
    echo "Mail rule adapter checks passed\n";
}
