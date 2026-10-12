<?php
namespace Acs\EmailApproval;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();
require_once __DIR__ . '/email_approval_handler.php';

/** Adapter for the PHP action in /bitrix/admin/mail_filter_admin.php. */
class MailRuleAdapter
{
    /** Valid only for a gateway that removes forged results for this authserv-id. */
    public static function verifyGateway(array $fields, array $config)
    {
        $authservId = (string)($config['authserv_id'] ?? '');
        if (($config['gateway_strips_untrusted_results'] ?? false) !== true
            || !preg_match('/^[a-z0-9.-]+$/i', $authservId)) return false;
        $from = \CMailUtil::ExtractMailAddress((string)($fields['FIELD_FROM'] ?? ''));
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) return false;
        $domain = strtolower(substr(strrchr($from, '@'), 1));
        $headers = preg_replace('/\r?\n[ \t]+/', ' ', (string)($fields['HEADER'] ?? ''));
        preg_match_all('/^Authentication-Results\s*:\s*([^\r\n]*)/im', $headers, $matches);
        $trusted = array();
        foreach ($matches[1] as $value)
        {
            $parts = explode(';', $value);
            if (strcasecmp(trim(array_shift($parts)), $authservId) === 0) $trusted[] = $parts;
        }
        // Ambiguous/repeated trusted results require administrator investigation.
        if (count($trusted) !== 1) return false;
        $dmarc = array();
        foreach ($trusted[0] as $part)
            if (preg_match('/^\s*dmarc\s*=/i', $part)) $dmarc[] = $part;
        if (count($dmarc) !== 1) return false;
        if (!preg_match('/^\s*dmarc\s*=\s*pass\b/i', $dmarc[0])) return false;
        if (!preg_match('/\bheader\.from\s*=\s*"?([a-z0-9.-]+)"?(?:\s|$)/i', $dmarc[0], $match)) return false;
        return strcasecmp($domain, $match[1]) === 0;
    }

    /** Local Exchange policy must guarantee protected headers and sender binding. */
    public static function verifyExchange(array $fields, array $config)
    {
        if (($config['exchange_sender_trust_confirmed'] ?? false) !== true) return false;
        $headers = preg_replace('/\r?\n[ \t]+/', ' ', (string)($fields['HEADER'] ?? ''));
        $values = array();
        foreach (array('AuthAs', 'AuthSource', 'AuthMechanism') as $name)
        {
            preg_match_all('/^X-MS-Exchange-Organization-' . $name . '\s*:\s*([^\r\n]*)/im', $headers, $matches);
            if (count($matches[1]) !== 1) return false;
            $values[$name] = strtolower(trim($matches[1][0]));
        }
        if ($values['AuthAs'] !== 'internal' || $values['AuthMechanism'] !== '04') return false;
        $servers = array_map('strtolower', (array)($config['exchange_servers'] ?? array()));
        if (!in_array($values['AuthSource'], $servers, true)) return false;
        $from = \CMailUtil::ExtractMailAddress((string)($fields['FIELD_FROM'] ?? ''));
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) return false;
        $domain = strtolower(substr(strrchr($from, '@'), 1));
        if (!in_array($domain, array_map('strtolower', (array)($config['sender_domains'] ?? array())), true)) return false;
        // Recognize the MAPI submission format observed on this Exchange route.
        preg_match_all('/^Received\s*:\s*([^\r\n]*)/im', $headers, $received);
        foreach ($received[1] as $value)
            if (preg_match('/^from\s+([a-z0-9.-]+)\s+.*\sby\s+([a-z0-9.-]+)\s+.*\swith\s+mapi\s+id\s/i', $value, $match)
                && in_array(strtolower($match[1]), $servers, true)
                && in_array(strtolower($match[2]), $servers, true)) return true;
        return false;
    }

    public static function handle(array $fields, array $config = array())
    {
        $mode = $config['sender_mode'] ?? 'verified_gateway';
        $authorized = false;
        if ($mode === 'verified_gateway')
        {
            $authorized = isset($config['verify_sender']) && is_callable($config['verify_sender'])
                ? call_user_func($config['verify_sender'], $fields) === true
                : static::verifyGateway($fields, $config);
        }
        elseif ($mode === 'exchange_internal')
            $authorized = static::verifyExchange($fields, $config);
        elseif ($mode === 'token_and_from')
            $authorized = true; // Explicit portal policy; not proof of From authenticity.
        if (!$authorized) return array('status' => 'unverified_sender');

        $rawFrom = trim((string)($fields['FIELD_FROM'] ?? ''));
        // CMailUtil extracts an address but may silently choose one from a list.
        // This first version accepts a single mailbox only.
        if ($rawFrom === '' || preg_match('/[\r\n,;]/', $rawFrom) || substr_count($rawFrom, '@') !== 1)
            return array('status' => 'invalid_sender');
        $from = \CMailUtil::ExtractMailAddress($rawFrom);
        $subject = trim((string)($fields['SUBJECT'] ?? ''));
        $body = (string)($fields['BODY'] ?? '');
        if (!preg_match('//u', $subject) || !preg_match('//u', $body))
            return array('status' => 'unsupported_charset');
        // BODY is already parsed by Bitrix; do not decode the whole message again.
        // HTML-only replies need a separate, tested MIME/HTML adapter.
        if (preg_match('/<(?:html|body|div|p|br|table|blockquote)\b/i', $body))
            return array('status' => 'html_reply_unsupported');
        $headers = (string)($fields['HEADER'] ?? '');
        $headers = preg_replace('/\r?\n[ \t]+/', ' ', $headers);
        $autoReply = false;
        if (preg_match_all('/^Auto-Submitted\s*:\s*([^\r\n]*)/im', $headers, $matches))
            foreach ($matches[1] as $value)
                if (strtolower(trim(explode(';', $value)[0])) !== 'no') $autoReply = true;
        if (preg_match('/^Content-Type\s*:\s*multipart\/report\b/im', $headers)) $autoReply = true;
        try
        {
            return ReplyHandler::handle(array(
                'subject' => $subject,
                'from_email' => $from,
                'body_text' => $body,
                'auto_reply' => $autoReply,
            ), $authorized);
        }
        catch (\Throwable $e)
        {
            // Do not expose database errors, headers or raw tokens to the mail log.
            return array(
                'status' => 'handler_error',
                'error_class' => get_class($e),
                'error_file' => basename($e->getFile()),
                'error_line' => $e->getLine(),
            );
        }
    }
}
