<?php
return [
    'group_id' => 163,
    // 0: first active member of Bitrix administrator group (ID 1).
    'administrator_user_id' => 0,
    'timezone' => 'Europe/Moscow',
    // Explicit stage IDs override automatic matching of stage titles.
    'stage_map' => [], // e.g. 123 => 'review'; values: new, work, review, done.
    'weekends' => [6, 7],
    'annual_holidays' => ['1.1','2.1','3.1','4.1','5.1','6.1','7.1','8.1','23.2','8.3','1.5','9.5','12.6','4.11'],
    // Add official transferred days for each reporting year.
    'holidays' => [], // YYYY-MM-DD
    'working_dates' => [], // YYYY-MM-DD; overrides weekends and holidays.
];
