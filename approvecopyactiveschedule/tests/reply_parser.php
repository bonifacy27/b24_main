<?php
// Run with: php approvecopyactiveschedule/tests/reply_parser.php
namespace Bitrix\Main\Entity { class DataManager {} }
namespace {
    define('B_PROLOG_INCLUDED', true);
    require_once dirname(__DIR__) . '/email_approval_handler.php';
    $token = str_repeat('a', 64);
    $subject = 'Re: Задание [ACS:' . $token . ']';
    $r = \Acs\EmailApproval\ReplyHandler::parse($subject, "\r\nСогласовано\r\nКомментарий\r\n> Отклонено");
    if (!$r['approve'] || $r['comment'] !== 'Комментарий' || $r['hash'] !== hash('sha256', $token)) throw new \RuntimeException('approve parsing failed');
    $r = \Acs\EmailApproval\ReplyHandler::parse($subject, "Отклонено.\nПричина\n-- \nПодпись");
    if ($r['approve'] || $r['comment'] !== 'Причина') throw new \RuntimeException('decline parsing failed');
    foreach (array('Не согласовано', '> Согласовано', 'Текст Согласовано', 'Согласовано или Отклонено', '') as $body) {
        try { \Acs\EmailApproval\ReplyHandler::parse($subject, $body); }
        catch (\InvalidArgumentException $e) { continue; }
        throw new \RuntimeException('unsafe decision accepted');
    }
    foreach (array('Без токена', $subject . ' [ACS:' . str_repeat('b', 64) . ']') as $badSubject) {
        try { \Acs\EmailApproval\ReplyHandler::parse($badSubject, 'Согласовано'); }
        catch (\InvalidArgumentException $e) { continue; }
        throw new \RuntimeException('ambiguous token accepted');
    }
    foreach (array('Согласовано', 'Согласовать', 'согласовано', 'согласовать', 'Отклонено', 'Отклонить', 'отклонено', 'отклонить') as $command) {
        $body = $command . "\nКомментарий\n\nСогласование по почте:\nИнструкция\nКод согласования: ACS:" . $token;
        $r = \Acs\EmailApproval\ReplyHandler::parse('Re: Задание', $body);
        $approve = preg_match('/^соглас/iu', $command) === 1;
        if ($r['approve'] !== $approve || $r['hash'] !== hash('sha256', $token) || $r['comment'] !== 'Комментарий') throw new \RuntimeException('body token or synonym failed');
    }
    $r = \Acs\EmailApproval\ReplyHandler::parse('Re: Задание', "Согласовать\n> Код согласования: ACS:" . $token);
    if (!$r['approve'] || $r['comment'] !== '') throw new \RuntimeException('quoted token failed');
    try {
        \Acs\EmailApproval\ReplyHandler::parse($subject, "Согласовано\nACS:" . str_repeat('b', 64));
        throw new \RuntimeException('conflicting tokens accepted');
    } catch (\InvalidArgumentException $e) {}
    $longComment = str_repeat('Тестовый комментарий на этой строчке ', 6);
    foreach (array("\n", "\r\n", "\r") as $newline) {
        $r = \Acs\EmailApproval\ReplyHandler::parse($subject, 'Согласовано' . $newline . $longComment . $newline . '--Тело письма' . $newline . 'Другой текст');
        if ($r['comment'] !== trim($longComment)) throw new \RuntimeException('long single line truncated');
    }
    foreach (array('Отклонено', "Согласовано\n\nНе брать эту строку", "Согласовано\n--Тело письма", "Согласовано\n> Цитата") as $body) {
        $r = \Acs\EmailApproval\ReplyHandler::parse($subject, $body);
        if ($r['comment'] !== '') throw new \RuntimeException('unexpected comment');
    }
    $r = \Acs\EmailApproval\ReplyHandler::parse($subject, "Отклонено\nПервая строка\nВторая строка");
    if ($r['comment'] !== 'Первая строка') throw new \RuntimeException('multiple comment lines accepted');
    foreach (array('Доработать', 'доработать', 'Доработка', 'доработка', 'ДОРАБОТАТЬ', 'Доработка.') as $command) {
        foreach (array("\n", "\r\n", "\r") as $newline) {
            $r = \Acs\EmailApproval\ReplyHandler::parse($subject, $command . $newline . 'Исправить сумму' . $newline . 'Не брать эту строку');
            if (!$r['refine'] || $r['approve'] || $r['comment'] !== 'Исправить сумму') throw new \RuntimeException('refine parsing failed');
        }
    }
    foreach (array("Доработать\n\nНе брать эту строку", "Доработка\n> Цитата", "Доработать\nКод согласования: ACS:" . $token) as $body) {
        $r = \Acs\EmailApproval\ReplyHandler::parse($subject, $body);
        if ($r['comment'] !== '') throw new \RuntimeException('refine comment boundary failed');
    }
    foreach (array('Не доработать', '> Доработать', 'Прошу доработать', 'Доработать или Согласовано') as $body) {
        try { \Acs\EmailApproval\ReplyHandler::parse($subject, $body); }
        catch (\InvalidArgumentException $e) { continue; }
        throw new \RuntimeException('unsafe refine accepted');
    }
    if (\Acs\EmailApproval\ReplyHandler::handle(array())['status'] !== 'unverified_sender') throw new \RuntimeException('sender gate failed');
    echo "Reply parser checks passed\n";
}
