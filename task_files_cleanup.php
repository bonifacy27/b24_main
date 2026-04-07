<?php
/**
 * task_files_cleanup.php
 *
 * Оснастка для поиска и удаления файлов пользователя,
 * прикреплённых к задачам и комментариям задач.
 */

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('NOT_CHECK_PERMISSIONS', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\UserTable;

if (!Loader::includeModule('main')) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Ошибка: не удалось подключить модуль main.';
    exit;
}

$statusMap = [1 => 'Новая', 2 => 'Ожидает выполнения', 3 => 'Выполняется', 4 => 'Ждёт контроля', 5 => 'Завершена', 6 => 'Отложена', 7 => 'Отклонена'];

function h($value): string
{
    return htmlspecialcharsbx((string)$value);
}

function formatBytes(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 Б';
    }

    $units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
    $power = max(0, min((int)floor(log($bytes, 1024)), count($units) - 1));

    return round($bytes / (1024 ** $power), 2) . ' ' . $units[$power];
}

function getUserById(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $user = UserTable::getList([
        'filter' => ['=ID' => $userId],
        'select' => ['ID', 'LOGIN', 'NAME', 'LAST_NAME', 'ACTIVE'],
        'limit' => 1,
    ])->fetch();

    if (!$user) {
        return null;
    }

    $fullName = trim($user['LAST_NAME'] . ' ' . $user['NAME']);
    if ($fullName === '') {
        $fullName = $user['LOGIN'];
    }

    return [
        'ID' => (int)$user['ID'],
        'LABEL' => sprintf('[%d] %s (%s)%s', (int)$user['ID'], $fullName, (string)$user['LOGIN'], $user['ACTIVE'] === 'N' ? ' [неактивен]' : ''),
    ];
}

function getSortParams(array $request): array
{
    $allowed = [
        'file_size' => 'file_size',
        'usage_date' => 'usage_date',
        'task_created_date' => 'task_created_date',
    ];

    $sort = isset($request['sort']) ? (string)$request['sort'] : 'usage_date';
    if (!isset($allowed[$sort])) {
        $sort = 'usage_date';
    }

    $dir = isset($request['dir']) ? strtoupper((string)$request['dir']) : 'DESC';
    $dir = $dir === 'ASC' ? 'ASC' : 'DESC';

    return ['sort' => $sort, 'dir' => $dir];
}

function sortRows(array $rows, string $sort, string $dir): array
{
    $multiplier = $dir === 'ASC' ? 1 : -1;

    uasort($rows, static function (array $a, array $b) use ($sort, $multiplier): int {
        $fileA = $a['FILE'];
        $fileB = $b['FILE'];
        $usageA = $a['USAGES'][0] ?? null;
        $usageB = $b['USAGES'][0] ?? null;

        if ($sort === 'file_size') {
            return ((int)$fileA['FILE_SIZE'] <=> (int)$fileB['FILE_SIZE']) * $multiplier;
        }

        if ($sort === 'task_created_date') {
            $aVal = (string)($usageA['TASK_CREATED_DATE'] ?? '');
            $bVal = (string)($usageB['TASK_CREATED_DATE'] ?? '');
            return strcmp($aVal, $bVal) * $multiplier;
        }

        $aVal = (string)($usageA['USAGE_DATE'] ?? $fileA['TIMESTAMP_X']);
        $bVal = (string)($usageB['USAGE_DATE'] ?? $fileB['TIMESTAMP_X']);
        return strcmp($aVal, $bVal) * $multiplier;
    });

    return $rows;
}

function sortLink(string $column, string $title, int $selectedUserId, string $currentSort, string $currentDir): string
{
    $nextDir = ($currentSort === $column && $currentDir === 'ASC') ? 'DESC' : 'ASC';
    $arrow = $currentSort === $column ? ($currentDir === 'ASC' ? ' ▲' : ' ▼') : '';
    $url = '?user_id=' . $selectedUserId . '&sort=' . urlencode($column) . '&dir=' . urlencode($nextDir);
    return '<a href="' . h($url) . '">' . h($title . $arrow) . '</a>';
}

function buildUserFilter(string $fileAlias, array $candidates, int $fallbackUserId): string
{
    $conditions = [];

    if (hasColumn('b_file', 'CREATED_BY')) {
        $conditions[] = $fileAlias . '.CREATED_BY = ' . $fallbackUserId;
    }

    foreach ($candidates as $candidate) {
        [$table, $column, $alias] = $candidate;
        if (hasColumn($table, $column)) {
            $conditions[] = $alias . '.' . $column . ' = ' . $fallbackUserId;
        }
    }

    if (empty($conditions)) {
        return '1=0';
    }

    return '(' . implode(' OR ', $conditions) . ')';
}

function buildTaskUserFilter(int $userId): string
{
    $tasksFileTable = getTasksFileTable();
    if ($tasksFileTable === null) {
        return '1=0';
    }

    $main = buildUserFilter('f', [
        [$tasksFileTable, 'USER_ID', 'tf'],
        [$tasksFileTable, 'CREATED_BY', 'tf'],
    ], $userId);

    if ($main !== '1=0') {
        return $main;
    }

    if (hasColumn('b_tasks', 'CREATED_BY')) {
        return '(t.CREATED_BY = ' . $userId . ')';
    }

    return '1=0';
}

function buildCommentUserFilter(int $userId): string
{
    $main = buildUserFilter('f', [
        ['b_forum_message', 'AUTHOR_ID', 'fm'],
        ['b_forum_message', 'USER_ID', 'fm'],
    ], $userId);

    if ($main !== '1=0') {
        return $main;
    }

    if (hasColumn('b_tasks', 'CREATED_BY')) {
        return '(t.CREATED_BY = ' . $userId . ')';
    }

    return '1=0';
}

function hasColumn(string $tableName, string $columnName): bool
{
    static $cache = [];
    $key = $tableName . '.' . $columnName;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $connection = Application::getConnection();
    $sqlHelper = $connection->getSqlHelper();
    $table = $sqlHelper->forSql($tableName);
    $column = $sqlHelper->forSql($columnName);

    if (!hasTable($tableName)) {
        $cache[$key] = false;
        return false;
    }

    $row = $connection->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'")->fetch();
    $cache[$key] = is_array($row);

    return $cache[$key];
}

function hasTable(string $tableName): bool
{
    static $cache = [];
    if (array_key_exists($tableName, $cache)) {
        return $cache[$tableName];
    }

    $connection = Application::getConnection();
    $sqlHelper = $connection->getSqlHelper();
    $table = $sqlHelper->forSql($tableName);

    $row = $connection->query("SHOW TABLES LIKE '{$table}'")->fetch();
    $cache[$tableName] = is_array($row);

    return $cache[$tableName];
}

function getTasksFileTable(): ?string
{
    if (hasTable('b_tasks_file')) {
        return 'b_tasks_file';
    }

    if (hasTable('b_tasks_files')) {
        return 'b_tasks_files';
    }

    return null;
}

function loadTaskFileUsages(int $userId): array
{
    $connection = Application::getConnection();
    $tasksFileTable = getTasksFileTable();
    if ($tasksFileTable === null) {
        return [];
    }

    $filter = buildTaskUserFilter($userId);
    $items = [];

    $sql = "
        SELECT
            f.ID AS FILE_ID,
            f.FILE_NAME,
            f.ORIGINAL_NAME,
            f.FILE_SIZE,
            f.SUBDIR,
            f.TIMESTAMP_X,
            t.ID AS TASK_ID,
            t.TITLE AS TASK_TITLE,
            t.CREATED_DATE,
            t.CLOSED_DATE,
            t.STATUS,
            COALESCE(t.CHANGED_DATE, t.CREATED_DATE) AS USAGE_DATE,
            'TASK' AS USAGE_TYPE
        FROM {$tasksFileTable} tf
        INNER JOIN b_file f ON f.ID = tf.FILE_ID
        INNER JOIN b_tasks t ON t.ID = tf.TASK_ID
        WHERE {$filter}
    ";

    $result = $connection->query($sql);
    while ($row = $result->fetch()) {
        $fileId = (int)$row['FILE_ID'];
        $items[$fileId][] = [
            'FILE_ID' => $fileId,
            'FILE_NAME' => (string)$row['FILE_NAME'],
            'ORIGINAL_NAME' => (string)$row['ORIGINAL_NAME'],
            'FILE_SIZE' => (int)$row['FILE_SIZE'],
            'SUBDIR' => (string)$row['SUBDIR'],
            'TIMESTAMP_X' => (string)$row['TIMESTAMP_X'],
            'USAGE_TYPE' => (string)$row['USAGE_TYPE'],
            'USAGE_DATE' => (string)$row['USAGE_DATE'],
            'TASK_ID' => (int)$row['TASK_ID'],
            'TASK_TITLE' => (string)$row['TASK_TITLE'],
            'TASK_CREATED_DATE' => (string)$row['CREATED_DATE'],
            'TASK_CLOSED_DATE' => (string)$row['CLOSED_DATE'],
            'TASK_STATUS' => (int)$row['STATUS'],
        ];
    }

    return $items;
}

function loadTaskDiskAttachedUsages(int $userId): array
{
    if (!hasTable('b_disk_attached_object') || !hasTable('b_disk_object') || !hasTable('b_disk_version')) {
        return [];
    }

    $connection = Application::getConnection();
    $items = [];

    $createdByColumn = hasColumn('b_disk_attached_object', 'CREATED_BY') ? 'dao.CREATED_BY' : null;
    $authorFilter = $createdByColumn ? $createdByColumn . ' = ' . $userId : '1=0';

    $storageFilter = '1=0';
    if (hasTable('b_disk_storage') && hasColumn('b_disk_storage', 'ENTITY_TYPE') && hasColumn('b_disk_storage', 'ENTITY_ID')) {
        $storageFilter = "do.STORAGE_ID IN (SELECT s.ID FROM b_disk_storage s WHERE s.ENTITY_TYPE = 'USER' AND s.ENTITY_ID = {$userId})";
    }

    $connectorTaskFilter = "dao.ENTITY_TYPE LIKE '%Task%'";
    $where = '(' . $authorFilter . ' OR ' . $storageFilter . ') AND ' . $connectorTaskFilter;

    $sql = "
        SELECT
            COALESCE(f.ID, 0) AS FILE_ID,
            f.FILE_NAME,
            f.ORIGINAL_NAME,
            COALESCE(dv.SIZE, f.FILE_SIZE, 0) AS FILE_SIZE,
            f.SUBDIR,
            COALESCE(dao.CREATE_TIME, dv.CREATE_TIME, f.TIMESTAMP_X) AS FILE_TIME,
            t.ID AS TASK_ID,
            t.TITLE AS TASK_TITLE,
            t.CREATED_DATE,
            t.CLOSED_DATE,
            t.STATUS,
            COALESCE(t.CHANGED_DATE, t.CREATED_DATE) AS USAGE_DATE,
            'TASK' AS USAGE_TYPE
        FROM b_disk_attached_object dao
        INNER JOIN b_disk_object do ON do.ID = dao.OBJECT_ID
        LEFT JOIN b_disk_version dv ON dv.ID = (
            SELECT MAX(v.ID)
            FROM b_disk_version v
            WHERE v.OBJECT_ID = COALESCE(do.REAL_OBJECT_ID, do.ID)
        )
        LEFT JOIN b_file f ON f.ID = COALESCE(dv.FILE_ID, do.FILE_ID)
        INNER JOIN b_tasks t ON t.ID = dao.ENTITY_ID
        WHERE {$where}
          AND f.ID IS NOT NULL
    ";

    $result = $connection->query($sql);
    while ($row = $result->fetch()) {
        $fileId = (int)$row['FILE_ID'];
        if ($fileId <= 0) {
            continue;
        }
        $items[$fileId][] = [
            'FILE_ID' => $fileId,
            'FILE_NAME' => (string)$row['FILE_NAME'],
            'ORIGINAL_NAME' => (string)$row['ORIGINAL_NAME'],
            'FILE_SIZE' => (int)$row['FILE_SIZE'],
            'SUBDIR' => (string)$row['SUBDIR'],
            'TIMESTAMP_X' => (string)$row['FILE_TIME'],
            'USAGE_TYPE' => (string)$row['USAGE_TYPE'],
            'USAGE_DATE' => (string)$row['USAGE_DATE'],
            'TASK_ID' => (int)$row['TASK_ID'],
            'TASK_TITLE' => (string)$row['TASK_TITLE'],
            'TASK_CREATED_DATE' => (string)$row['CREATED_DATE'],
            'TASK_CLOSED_DATE' => (string)$row['CLOSED_DATE'],
            'TASK_STATUS' => (int)$row['STATUS'],
        ];
    }

    return $items;
}

function loadCommentFileUsages(int $userId): array
{
    $connection = Application::getConnection();
    $filter = buildCommentUserFilter($userId);
    $items = [];

    $sql = "
        SELECT
            f.ID AS FILE_ID,
            f.FILE_NAME,
            f.ORIGINAL_NAME,
            f.FILE_SIZE,
            f.SUBDIR,
            f.TIMESTAMP_X,
            t.ID AS TASK_ID,
            t.TITLE AS TASK_TITLE,
            t.CREATED_DATE,
            t.CLOSED_DATE,
            t.STATUS,
            fm.POST_DATE AS USAGE_DATE,
            'COMMENT' AS USAGE_TYPE
        FROM b_user_field uf
        INNER JOIN b_utm_forum_message utm ON utm.FIELD_ID = uf.ID
        INNER JOIN b_forum_message fm ON fm.ID = utm.VALUE_ID
        INNER JOIN b_forum_topic ft ON ft.ID = fm.TOPIC_ID
        INNER JOIN b_tasks t ON ft.XML_ID LIKE 'TASK_%' AND t.ID = CAST(SUBSTRING(ft.XML_ID, 6) AS UNSIGNED)
        INNER JOIN b_file f ON f.ID = utm.VALUE_INT
        WHERE uf.ENTITY_ID = 'FORUM_MESSAGE'
          AND uf.FIELD_NAME = 'UF_FORUM_MESSAGE_DOC'
          AND {$filter}
    ";

    $result = $connection->query($sql);
    while ($row = $result->fetch()) {
        $fileId = (int)$row['FILE_ID'];
        $items[$fileId][] = [
            'FILE_ID' => $fileId,
            'FILE_NAME' => (string)$row['FILE_NAME'],
            'ORIGINAL_NAME' => (string)$row['ORIGINAL_NAME'],
            'FILE_SIZE' => (int)$row['FILE_SIZE'],
            'SUBDIR' => (string)$row['SUBDIR'],
            'TIMESTAMP_X' => (string)$row['TIMESTAMP_X'],
            'USAGE_TYPE' => (string)$row['USAGE_TYPE'],
            'USAGE_DATE' => (string)$row['USAGE_DATE'],
            'TASK_ID' => (int)$row['TASK_ID'],
            'TASK_TITLE' => (string)$row['TASK_TITLE'],
            'TASK_CREATED_DATE' => (string)$row['CREATED_DATE'],
            'TASK_CLOSED_DATE' => (string)$row['CLOSED_DATE'],
            'TASK_STATUS' => (int)$row['STATUS'],
        ];
    }

    if (hasColumn('b_forum_message', 'FILE_ID')) {
        $legacySql = "
            SELECT
                f.ID AS FILE_ID,
                f.FILE_NAME,
                f.ORIGINAL_NAME,
                f.FILE_SIZE,
                f.SUBDIR,
                f.TIMESTAMP_X,
                t.ID AS TASK_ID,
                t.TITLE AS TASK_TITLE,
                t.CREATED_DATE,
                t.CLOSED_DATE,
                t.STATUS,
                fm.POST_DATE AS USAGE_DATE,
                'COMMENT' AS USAGE_TYPE
            FROM b_forum_message fm
            INNER JOIN b_forum_topic ft ON ft.ID = fm.TOPIC_ID
            INNER JOIN b_tasks t ON ft.XML_ID LIKE 'TASK_%' AND t.ID = CAST(SUBSTRING(ft.XML_ID, 6) AS UNSIGNED)
            INNER JOIN b_file f ON f.ID = fm.FILE_ID
            WHERE fm.FILE_ID > 0
              AND {$filter}
        ";

        $legacyResult = $connection->query($legacySql);
        while ($row = $legacyResult->fetch()) {
            $fileId = (int)$row['FILE_ID'];
            $items[$fileId][] = [
                'FILE_ID' => $fileId,
                'FILE_NAME' => (string)$row['FILE_NAME'],
                'ORIGINAL_NAME' => (string)$row['ORIGINAL_NAME'],
                'FILE_SIZE' => (int)$row['FILE_SIZE'],
                'SUBDIR' => (string)$row['SUBDIR'],
                'TIMESTAMP_X' => (string)$row['TIMESTAMP_X'],
                'USAGE_TYPE' => (string)$row['USAGE_TYPE'],
                'USAGE_DATE' => (string)$row['USAGE_DATE'],
                'TASK_ID' => (int)$row['TASK_ID'],
                'TASK_TITLE' => (string)$row['TASK_TITLE'],
                'TASK_CREATED_DATE' => (string)$row['CREATED_DATE'],
                'TASK_CLOSED_DATE' => (string)$row['CLOSED_DATE'],
                'TASK_STATUS' => (int)$row['STATUS'],
            ];
        }
    }

    return $items;
}

function loadCommentDiskAttachedUsages(int $userId): array
{
    if (
        !hasTable('b_disk_attached_object')
        || !hasTable('b_disk_object')
        || !hasTable('b_disk_version')
        || !hasTable('b_forum_message')
        || !hasTable('b_forum_topic')
    ) {
        return [];
    }

    $connection = Application::getConnection();
    $items = [];

    $createdByColumn = hasColumn('b_disk_attached_object', 'CREATED_BY') ? 'dao.CREATED_BY' : null;
    $authorFilter = $createdByColumn ? $createdByColumn . ' = ' . $userId : '1=0';

    $storageFilter = '1=0';
    if (hasTable('b_disk_storage') && hasColumn('b_disk_storage', 'ENTITY_TYPE') && hasColumn('b_disk_storage', 'ENTITY_ID')) {
        $storageFilter = "do.STORAGE_ID IN (SELECT s.ID FROM b_disk_storage s WHERE s.ENTITY_TYPE = 'USER' AND s.ENTITY_ID = {$userId})";
    }

    $connectorCommentFilter = "dao.ENTITY_TYPE LIKE '%Comment%'";
    $where = '(' . $authorFilter . ' OR ' . $storageFilter . ') AND ' . $connectorCommentFilter;

    $sql = "
        SELECT
            COALESCE(f.ID, 0) AS FILE_ID,
            f.FILE_NAME,
            f.ORIGINAL_NAME,
            COALESCE(dv.SIZE, f.FILE_SIZE, 0) AS FILE_SIZE,
            f.SUBDIR,
            COALESCE(dao.CREATE_TIME, fm.POST_DATE, dv.CREATE_TIME, f.TIMESTAMP_X) AS FILE_TIME,
            t.ID AS TASK_ID,
            t.TITLE AS TASK_TITLE,
            t.CREATED_DATE,
            t.CLOSED_DATE,
            t.STATUS,
            fm.POST_DATE AS USAGE_DATE,
            'COMMENT' AS USAGE_TYPE
        FROM b_disk_attached_object dao
        INNER JOIN b_disk_object do ON do.ID = dao.OBJECT_ID
        LEFT JOIN b_disk_version dv ON dv.ID = (
            SELECT MAX(v.ID)
            FROM b_disk_version v
            WHERE v.OBJECT_ID = COALESCE(do.REAL_OBJECT_ID, do.ID)
        )
        LEFT JOIN b_file f ON f.ID = COALESCE(dv.FILE_ID, do.FILE_ID)
        INNER JOIN b_forum_message fm ON fm.ID = dao.ENTITY_ID
        INNER JOIN b_forum_topic ft ON ft.ID = fm.TOPIC_ID
        INNER JOIN b_tasks t ON ft.XML_ID LIKE 'TASK_%' AND t.ID = CAST(SUBSTRING(ft.XML_ID, 6) AS UNSIGNED)
        WHERE {$where}
          AND f.ID IS NOT NULL
    ";

    $result = $connection->query($sql);
    while ($row = $result->fetch()) {
        $fileId = (int)$row['FILE_ID'];
        if ($fileId <= 0) {
            continue;
        }
        $items[$fileId][] = [
            'FILE_ID' => $fileId,
            'FILE_NAME' => (string)$row['FILE_NAME'],
            'ORIGINAL_NAME' => (string)$row['ORIGINAL_NAME'],
            'FILE_SIZE' => (int)$row['FILE_SIZE'],
            'SUBDIR' => (string)$row['SUBDIR'],
            'TIMESTAMP_X' => (string)$row['FILE_TIME'],
            'USAGE_TYPE' => (string)$row['USAGE_TYPE'],
            'USAGE_DATE' => (string)$row['USAGE_DATE'],
            'TASK_ID' => (int)$row['TASK_ID'],
            'TASK_TITLE' => (string)$row['TASK_TITLE'],
            'TASK_CREATED_DATE' => (string)$row['CREATED_DATE'],
            'TASK_CLOSED_DATE' => (string)$row['CLOSED_DATE'],
            'TASK_STATUS' => (int)$row['STATUS'],
        ];
    }

    return $items;
}

function loadStandaloneUserFiles(int $userId): array
{
    if (!hasColumn('b_file', 'CREATED_BY')) {
        return [];
    }

    $connection = Application::getConnection();
    $items = [];

    $sql = "
        SELECT
            f.ID AS FILE_ID,
            f.FILE_NAME,
            f.ORIGINAL_NAME,
            f.FILE_SIZE,
            f.SUBDIR,
            f.TIMESTAMP_X
        FROM b_file f
        WHERE f.CREATED_BY = {$userId}
        ORDER BY f.TIMESTAMP_X DESC, f.ID DESC
    ";

    $result = $connection->query($sql);
    while ($row = $result->fetch()) {
        $fileId = (int)$row['FILE_ID'];
        $items[$fileId] = [
            'FILE_ID' => $fileId,
            'FILE_NAME' => (string)$row['FILE_NAME'],
            'ORIGINAL_NAME' => (string)$row['ORIGINAL_NAME'],
            'FILE_SIZE' => (int)$row['FILE_SIZE'],
            'SUBDIR' => (string)$row['SUBDIR'],
            'TIMESTAMP_X' => (string)$row['TIMESTAMP_X'],
        ];
    }

    return $items;
}

function loadUserDiskFiles(int $userId): array
{
    if (!hasTable('b_disk_object')) {
        return [];
    }

    $connection = Application::getConnection();
    $items = [];

    // 1) Основной источник: все файлы из пользовательского storage Диска (как в UI volume/files/{USER_ID}).
    if (
        hasTable('b_disk_storage')
        && hasColumn('b_disk_storage', 'ENTITY_TYPE')
        && hasColumn('b_disk_storage', 'ENTITY_ID')
        && hasColumn('b_disk_storage', 'ID')
        && hasColumn('b_disk_object', 'STORAGE_ID')
        && hasTable('b_disk_version')
    ) {
        $storageSql = "
            SELECT s.ID
            FROM b_disk_storage s
            WHERE s.ENTITY_TYPE = 'USER'
              AND s.ENTITY_ID = {$userId}
        ";
        $storageIds = [];
        $storageResult = $connection->query($storageSql);
        while ($storageRow = $storageResult->fetch()) {
            $storageIds[] = (int)$storageRow['ID'];
        }

        if (!empty($storageIds)) {
            $scopeConditions = ['do.STORAGE_ID IN (' . implode(', ', array_unique($storageIds)) . ')'];
            if (hasColumn('b_disk_object', 'TYPE')) {
                $scopeConditions[] = 'do.TYPE = 2';
            }
            if (hasColumn('b_disk_object', 'DELETED_TYPE')) {
                $scopeConditions[] = 'do.DELETED_TYPE = 0';
            }

            $scopeWhere = implode(' AND ', $scopeConditions);
            $scopeSql = "
                SELECT
                    f.ID AS FILE_ID,
                    f.FILE_NAME,
                    f.ORIGINAL_NAME,
                    f.SUBDIR,
                    COALESCE(dv.SIZE, f.FILE_SIZE, 0) AS FILE_SIZE,
                    COALESCE(do.NAME, f.ORIGINAL_NAME, f.FILE_NAME) AS DISK_NAME,
                    COALESCE(do.UPDATE_TIME, do.CREATE_TIME, dv.CREATE_TIME, f.TIMESTAMP_X) AS FILE_TIME
                FROM b_disk_object do
                LEFT JOIN b_disk_version dv ON dv.ID = (
                    SELECT MAX(v.ID)
                    FROM b_disk_version v
                    WHERE v.OBJECT_ID = COALESCE(do.REAL_OBJECT_ID, do.ID)
                )
                LEFT JOIN b_file f ON f.ID = COALESCE(dv.FILE_ID, do.FILE_ID)
                WHERE {$scopeWhere}
                  AND f.ID IS NOT NULL
                ORDER BY FILE_TIME DESC, f.ID DESC
            ";

            $scopeResult = $connection->query($scopeSql);
            while ($row = $scopeResult->fetch()) {
                $fileId = (int)$row['FILE_ID'];
                $items[$fileId] = [
                    'FILE_ID' => $fileId,
                    'FILE_NAME' => (string)$row['FILE_NAME'],
                    'ORIGINAL_NAME' => (string)$row['ORIGINAL_NAME'],
                    'FILE_SIZE' => (int)$row['FILE_SIZE'],
                    'SUBDIR' => (string)$row['SUBDIR'],
                    'TIMESTAMP_X' => (string)$row['FILE_TIME'],
                    'DISK_NAME' => (string)$row['DISK_NAME'],
                ];
            }
        }
    }

    if (hasTable('b_disk_uploaded_file') && hasColumn('b_disk_uploaded_file', 'USER_ID')) {
        $sqlUploaded = "
            SELECT
                f.ID AS FILE_ID,
                f.FILE_NAME,
                f.ORIGINAL_NAME,
                f.SUBDIR,
                COALESCE(dv.SIZE, f.FILE_SIZE, 0) AS FILE_SIZE,
                COALESCE(do.NAME, f.ORIGINAL_NAME, f.FILE_NAME) AS DISK_NAME,
                COALESCE(dv.CREATE_TIME, do.UPDATE_TIME, do.CREATE_TIME, f.TIMESTAMP_X) AS FILE_TIME
                FROM b_disk_uploaded_file duf
            INNER JOIN b_disk_object do ON do.ID = duf.OBJECT_ID
            LEFT JOIN b_disk_version dv ON dv.ID = (
                SELECT MAX(v.ID)
                FROM b_disk_version v
                WHERE v.OBJECT_ID = COALESCE(do.REAL_OBJECT_ID, do.ID)
            )
            LEFT JOIN b_file f ON f.ID = COALESCE(dv.FILE_ID, do.FILE_ID)
            WHERE duf.USER_ID = {$userId}
              AND f.ID IS NOT NULL
        ";

        $uploadedResult = $connection->query($sqlUploaded);
        while ($row = $uploadedResult->fetch()) {
            $fileId = (int)$row['FILE_ID'];
            if (isset($items[$fileId])) {
                continue;
            }
            $items[$fileId] = [
                'FILE_ID' => $fileId,
                'FILE_NAME' => (string)$row['FILE_NAME'],
                'ORIGINAL_NAME' => (string)$row['ORIGINAL_NAME'],
                'FILE_SIZE' => (int)$row['FILE_SIZE'],
                'SUBDIR' => (string)$row['SUBDIR'],
                'TIMESTAMP_X' => (string)$row['FILE_TIME'],
                'DISK_NAME' => (string)$row['DISK_NAME'],
            ];
        }
    }

    $conditions = [];
    if (hasColumn('b_disk_object', 'TYPE')) {
        $conditions[] = 'do.TYPE = 2';
    }
    if (hasColumn('b_disk_object', 'DELETED_TYPE')) {
        $conditions[] = 'do.DELETED_TYPE = 0';
    }
    if (hasColumn('b_disk_object', 'CREATED_BY')) {
        $conditions[] = 'do.CREATED_BY = ' . $userId;
    } elseif (hasColumn('b_disk_object', 'CREATE_USER_ID')) {
        $conditions[] = 'do.CREATE_USER_ID = ' . $userId;
    }

    if (!empty($conditions) && hasTable('b_disk_version')) {
        $whereSql = implode(' AND ', $conditions);
        $sqlFallback = "
            SELECT
                f.ID AS FILE_ID,
                f.FILE_NAME,
                f.ORIGINAL_NAME,
                f.SUBDIR,
                COALESCE(dv.SIZE, f.FILE_SIZE, 0) AS FILE_SIZE,
                COALESCE(do.NAME, f.ORIGINAL_NAME, f.FILE_NAME) AS DISK_NAME,
                COALESCE(do.UPDATE_TIME, do.CREATE_TIME, dv.CREATE_TIME, f.TIMESTAMP_X) AS FILE_TIME
            FROM b_disk_object do
            LEFT JOIN b_disk_version dv ON dv.ID = (
                SELECT MAX(v.ID)
                FROM b_disk_version v
                WHERE v.OBJECT_ID = COALESCE(do.REAL_OBJECT_ID, do.ID)
            )
            LEFT JOIN b_file f ON f.ID = COALESCE(dv.FILE_ID, do.FILE_ID)
            WHERE {$whereSql}
              AND f.ID IS NOT NULL
        ";

        $fallbackResult = $connection->query($sqlFallback);
        while ($row = $fallbackResult->fetch()) {
            $fileId = (int)$row['FILE_ID'];
            if (isset($items[$fileId])) {
                continue;
            }
            $items[$fileId] = [
                'FILE_ID' => $fileId,
                'FILE_NAME' => (string)$row['FILE_NAME'],
                'ORIGINAL_NAME' => (string)$row['ORIGINAL_NAME'],
                'FILE_SIZE' => (int)$row['FILE_SIZE'],
                'SUBDIR' => (string)$row['SUBDIR'],
                'TIMESTAMP_X' => (string)$row['FILE_TIME'],
                'DISK_NAME' => (string)$row['DISK_NAME'],
            ];
        }
    }

    return $items;
}

function buildRows(int $userId): array
{
    $taskUsages = loadTaskFileUsages($userId);
    $taskDiskUsages = loadTaskDiskAttachedUsages($userId);
    $commentUsages = loadCommentFileUsages($userId);
    $commentDiskUsages = loadCommentDiskAttachedUsages($userId);
    $diskFiles = loadUserDiskFiles($userId);
    $standaloneFiles = loadStandaloneUserFiles($userId);

    $byFile = [];

    foreach ($diskFiles as $fileId => $file) {
        $byFile[$fileId] = ['FILE' => $file, 'USAGES' => []];
    }

    foreach ($standaloneFiles as $fileId => $file) {
        if (!isset($byFile[$fileId])) {
            $byFile[$fileId] = ['FILE' => $file, 'USAGES' => []];
        }
    }

    foreach ([$taskUsages, $taskDiskUsages, $commentUsages, $commentDiskUsages] as $group) {
        foreach ($group as $fileId => $usages) {
            foreach ($usages as $usage) {
                if (!isset($byFile[$fileId])) {
                    $byFile[$fileId] = ['FILE' => [
                        'FILE_ID' => $fileId,
                        'FILE_NAME' => $usage['FILE_NAME'],
                        'ORIGINAL_NAME' => $usage['ORIGINAL_NAME'],
                        'FILE_SIZE' => $usage['FILE_SIZE'],
                        'SUBDIR' => $usage['SUBDIR'],
                        'TIMESTAMP_X' => $usage['TIMESTAMP_X'],
                    ], 'USAGES' => []];
                }

                $byFile[$fileId]['USAGES'][] = $usage;
            }
        }
    }

    foreach ($byFile as &$fileBlock) {
        usort($fileBlock['USAGES'], static function (array $a, array $b): int {
            return strcmp((string)$b['USAGE_DATE'], (string)$a['USAGE_DATE']);
        });
    }
    unset($fileBlock);

    uasort($byFile, static function (array $a, array $b): int {
        return strcmp((string)$b['FILE']['TIMESTAMP_X'], (string)$a['FILE']['TIMESTAMP_X']);
    });

    return $byFile;
}

function deleteFiles(array $fileIds): array
{
    $deleted = [];
    $errors = [];
    $diagnostics = [];

    foreach (array_unique(array_map('intval', $fileIds)) as $fileId) {
        if ($fileId <= 0) {
            continue;
        }

        if (\CFile::Delete($fileId)) {
            $deleted[] = $fileId;
        } else {
            $errors[] = $fileId;
            $diagnostics[$fileId] = buildDeleteDiagnostic($fileId);
        }
    }

    return ['deleted' => $deleted, 'errors' => $errors, 'diagnostics' => $diagnostics];
}

function buildDeleteDiagnostic(int $fileId): array
{
    $connection = Application::getConnection();
    $diag = [
        'FILE_ID' => $fileId,
        'FILE_ROW_EXISTS' => false,
        'FILE_PATH' => '',
        'FILE_EXISTS_ON_DISK' => null,
        'DISK_VERSION_LINKS' => null,
        'DISK_OBJECT_LINKS' => null,
        'TASK_LINKS' => null,
        'FORUM_LINKS' => null,
    ];

    $fileRow = $connection->query("
        SELECT ID, SUBDIR, FILE_NAME
        FROM b_file
        WHERE ID = {$fileId}
        LIMIT 1
    ")->fetch();

    if ($fileRow) {
        $diag['FILE_ROW_EXISTS'] = true;
        $relativePath = '/upload/' . trim((string)$fileRow['SUBDIR'] . '/' . (string)$fileRow['FILE_NAME'], '/');
        $diag['FILE_PATH'] = $relativePath;
        $diag['FILE_EXISTS_ON_DISK'] = is_file($_SERVER['DOCUMENT_ROOT'] . $relativePath);
    }

    if (hasTable('b_disk_version') && hasColumn('b_disk_version', 'FILE_ID')) {
        $row = $connection->query("SELECT COUNT(1) CNT FROM b_disk_version WHERE FILE_ID = {$fileId}")->fetch();
        $diag['DISK_VERSION_LINKS'] = (int)($row['CNT'] ?? 0);
    }

    if (hasTable('b_disk_object') && hasColumn('b_disk_object', 'FILE_ID')) {
        $row = $connection->query("SELECT COUNT(1) CNT FROM b_disk_object WHERE FILE_ID = {$fileId}")->fetch();
        $diag['DISK_OBJECT_LINKS'] = (int)($row['CNT'] ?? 0);
    }

    $tasksFileTable = getTasksFileTable();
    if ($tasksFileTable !== null && hasColumn($tasksFileTable, 'FILE_ID')) {
        $row = $connection->query("SELECT COUNT(1) CNT FROM {$tasksFileTable} WHERE FILE_ID = {$fileId}")->fetch();
        $diag['TASK_LINKS'] = (int)($row['CNT'] ?? 0);
    }

    if (hasTable('b_forum_message') && hasColumn('b_forum_message', 'FILE_ID')) {
        $row = $connection->query("SELECT COUNT(1) CNT FROM b_forum_message WHERE FILE_ID = {$fileId}")->fetch();
        $diag['FORUM_LINKS'] = (int)($row['CNT'] ?? 0);
    }

    return $diag;
}

$selectedUserId = isset($_REQUEST['user_id']) ? (int)$_REQUEST['user_id'] : 0;
$action = isset($_POST['action']) ? (string)$_POST['action'] : '';
$message = '';
$deleteDiagnostics = [];

if ($action === 'delete' && check_bitrix_sessid()) {
    $toDelete = isset($_POST['delete_files']) && is_array($_POST['delete_files']) ? $_POST['delete_files'] : [];
    $result = deleteFiles($toDelete);
    $message = sprintf('Удалено файлов: %d. Ошибок удаления: %d.', count($result['deleted']), count($result['errors']));
    $deleteDiagnostics = $result['diagnostics'] ?? [];
}

$user = getUserById($selectedUserId);
$sortParams = getSortParams($_GET);
$rows = $selectedUserId > 0 ? buildRows($selectedUserId) : [];
$rows = sortRows($rows, $sortParams['sort'], $sortParams['dir']);
\CJSCore::Init(['ui.entity-selector']);
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Очистка файлов задач</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin-top: 16px; }
        th, td { border: 1px solid #d0d7de; padding: 8px; vertical-align: top; text-align: left; }
        th { background: #f6f8fa; }
        .muted { color: #666; }
        .msg { padding: 10px; background: #e6ffed; border: 1px solid #b6e7c9; margin: 12px 0; }
        .warn { padding: 10px; background: #fff8e1; border: 1px solid #f0d68a; margin: 12px 0; }
        .controls { margin-top: 10px; }
        .nowrap { white-space: nowrap; }
    </style>
</head>
<body>
<h1>Файлы задач и комментариев</h1>

<form method="get">
    <label for="user_id"><strong>Пользователь:</strong></label>
    <input type="hidden" name="user_id" id="user_id" value="<?= (int)$selectedUserId ?>">
    <input type="number" id="user_id_manual" value="<?= (int)$selectedUserId ?>" min="1" placeholder="ID пользователя" style="width: 140px;">
    <button type="button" id="user_picker_btn">Выбрать пользователя</button>
    <span id="user_picker_label" class="muted"><?= $user ? h($user['LABEL']) : 'Не выбран' ?></span>
    <button type="submit">Сканировать</button>
</form>

<?php if ($message !== ''): ?>
    <div class="msg"><?= h($message) ?></div>
<?php endif; ?>
<?php if (!empty($deleteDiagnostics)): ?>
    <div class="warn">
        <strong>Диагностика ошибок удаления:</strong>
        <ul>
            <?php foreach ($deleteDiagnostics as $fileId => $diag): ?>
                <li>
                    <strong>Файл ID <?= (int)$fileId ?></strong>:
                    b_file: <?= $diag['FILE_ROW_EXISTS'] ? 'есть запись' : 'запись не найдена' ?>;
                    путь: <?= h((string)$diag['FILE_PATH']) ?: '—' ?>;
                    на диске: <?= $diag['FILE_EXISTS_ON_DISK'] === null ? 'не проверено' : ($diag['FILE_EXISTS_ON_DISK'] ? 'да' : 'нет') ?>;
                    ссылок disk_version: <?= $diag['DISK_VERSION_LINKS'] === null ? 'н/д' : (int)$diag['DISK_VERSION_LINKS'] ?>;
                    ссылок disk_object: <?= $diag['DISK_OBJECT_LINKS'] === null ? 'н/д' : (int)$diag['DISK_OBJECT_LINKS'] ?>;
                    ссылок tasks_file: <?= $diag['TASK_LINKS'] === null ? 'н/д' : (int)$diag['TASK_LINKS'] ?>;
                    ссылок forum_message: <?= $diag['FORUM_LINKS'] === null ? 'н/д' : (int)$diag['FORUM_LINKS'] ?>.
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($selectedUserId > 0): ?>
    <form method="post">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="user_id" value="<?= (int)$selectedUserId ?>">

        <div class="controls">
            <button type="submit" onclick="return confirm('Удалить выбранные файлы? Действие необратимо.');">Удалить выбранные файлы</button>
        </div>

        <table>
            <thead>
            <tr>
                <th>Удалить</th>
                <th>Файл</th>
                <th><?= sortLink('file_size', 'Размер', $selectedUserId, $sortParams['sort'], $sortParams['dir']) ?></th>
                <th><?= sortLink('usage_date', 'Дата использования', $selectedUserId, $sortParams['sort'], $sortParams['dir']) ?></th>
                <th>Задача</th>
                <th><?= sortLink('task_created_date', 'Дата создания задачи', $selectedUserId, $sortParams['sort'], $sortParams['dir']) ?></th>
                <th>Дата завершения задачи</th>
                <th>Статус задачи</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="8" class="muted">Ничего не найдено для выбранного пользователя.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $fileBlock): ?>
                    <?php
                    $file = $fileBlock['FILE'];
                    $displayName = $file['ORIGINAL_NAME'] !== '' ? $file['ORIGINAL_NAME'] : $file['FILE_NAME'];
                    $path = '/upload/' . trim($file['SUBDIR'] . '/' . $file['FILE_NAME'], '/');
                    $usages = $fileBlock['USAGES'];
                    if (empty($usages)) {
                        $usages = [[
                            'USAGE_TYPE' => 'NONE',
                            'USAGE_DATE' => $file['TIMESTAMP_X'],
                            'TASK_ID' => 0,
                            'TASK_TITLE' => 'Не используется в задачах',
                            'TASK_CREATED_DATE' => '',
                            'TASK_CLOSED_DATE' => '',
                            'TASK_STATUS' => 0,
                        ]];
                    }
                    ?>
                    <?php foreach ($usages as $usage): ?>
                        <tr>
                            <td class="nowrap"><label><input type="checkbox" name="delete_files[]" value="<?= (int)$file['FILE_ID'] ?>"> ID <?= (int)$file['FILE_ID'] ?></label></td>
                            <td>
                                <div><strong><?= h($displayName) ?></strong></div>
                                <div class="muted"><?= h($path) ?></div>
                                <div class="muted">Тип связи: <?= h($usage['USAGE_TYPE']) ?></div>
                            </td>
                            <td class="nowrap"><?= h(formatBytes((int)$file['FILE_SIZE'])) ?></td>
                            <td class="nowrap"><?= h($usage['USAGE_DATE']) ?></td>
                            <td><?php if ((int)$usage['TASK_ID'] > 0): ?>#<?= (int)$usage['TASK_ID'] ?> — <?= h($usage['TASK_TITLE']) ?><?php else: ?><span class="muted"><?= h($usage['TASK_TITLE']) ?></span><?php endif; ?></td>
                            <td class="nowrap"><?= h($usage['TASK_CREATED_DATE']) ?></td>
                            <td class="nowrap"><?= h($usage['TASK_CLOSED_DATE']) ?></td>
                            <td class="nowrap"><?= h($statusMap[(int)$usage['TASK_STATUS']] ?? ($usage['TASK_STATUS'] ? 'Статус ' . (int)$usage['TASK_STATUS'] : '—')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </form>
<?php endif; ?>
<script>
(function () {
    const userInput = document.getElementById('user_id');
    const manualInput = document.getElementById('user_id_manual');
    const userLabel = document.getElementById('user_picker_label');
    const btn = document.getElementById('user_picker_btn');

    if (manualInput && userInput) {
        manualInput.addEventListener('change', function () {
            userInput.value = manualInput.value || '';
            if (!manualInput.value) {
                userLabel.textContent = 'Не выбран';
            }
        });
    }

    if (typeof window.BX === 'undefined' || !btn || !BX.UI || !BX.UI.EntitySelector) {
        if (btn) {
            btn.style.display = 'none';
        }
        return;
    }

    const dialog = new BX.UI.EntitySelector.Dialog({
        targetNode: btn,
        context: 'TASK_FILES_CLEANUP',
        multiple: false,
        entities: [{id: 'user'}],
        preselectedItems: userInput.value ? [['user', userInput.value]] : [],
        events: {
            'Item:onSelect': function (event) {
                const item = event.getData().item;
                userInput.value = item.getId();
                if (manualInput) {
                    manualInput.value = item.getId();
                }
                userLabel.textContent = item.getTitle() + ' [' + item.getId() + ']';
            },
            'Item:onDeselect': function () {
                userInput.value = '';
                if (manualInput) {
                    manualInput.value = '';
                }
                userLabel.textContent = 'Не выбран';
            }
        }
    });

    btn.addEventListener('click', function () {
        dialog.show();
    });
})();
</script>
</body>
</html>
