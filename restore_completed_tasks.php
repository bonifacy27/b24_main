<?php
/**
 * restore_completed_tasks.php
 *
 * Двухшаговое восстановление завершённых задач Битрикс24 без REST:
 * 1) На резервном портале mytricolordev.nsc.ru выгрузить задачи в JSON-файл.
 * 2) На рабочем портале ourtricolortv.nsc.ru импортировать задачи из этого файла.
 *
 * Все поля дат берутся из таблиц Битрикс как есть и при импорте записываются теми же
 * значениями. Скрипт импортирует задачи с исходными ID, поэтому перед запуском важно
 * убедиться, что эти ID действительно свободны на целевом портале.
 *
 * Примеры:
 *   php -f restore_completed_tasks.php -- --mode=export --file=/tmp/social_tasks_167.json
 *   php -f restore_completed_tasks.php -- --mode=import --file=/tmp/social_tasks_167.json --dry-run
 *   php -f restore_completed_tasks.php -- --mode=import --file=/tmp/social_tasks_167.json --run
 */

declare(strict_types=1);

const DEFAULT_GROUP_ID = 167;
const DEFAULT_CLOSED_BEFORE = '2024-01-01 00:00:00';
const COMPLETED_STATUS = 5;

final class RestoreConfig
{
    /** @var string */
    public $mode = '';
    /** @var string */
    public $file = '';
    /** @var int */
    public $groupId = DEFAULT_GROUP_ID;
    /** @var string */
    public $closedBefore = DEFAULT_CLOSED_BEFORE;
    /** @var bool */
    public $dryRun = true;
    /** @var int */
    public $limit = 0;
    /** @var int|null */
    public $onlyTaskId = null;
    /** @var bool */
    public $overwrite = false;
}

function usage()
{
    echo "Usage:\n";
    echo "  php -f restore_completed_tasks.php -- --mode=export --file=/path/tasks.json [--group-id=167] [--closed-before='2024-01-01 00:00:00'] [--limit=N] [--task-id=N]\n";
    echo "  php -f restore_completed_tasks.php -- --mode=import --file=/path/tasks.json [--dry-run|--run] [--overwrite]\n";
}

function startsWith(string $value, string $prefix): bool
{
    return substr($value, 0, strlen($prefix)) === $prefix;
}

function parseConfig(array $argv): RestoreConfig
{
    $config = new RestoreConfig();

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            usage();
            exit(0);
        }
        if ($arg === '--run') {
            $config->dryRun = false;
            continue;
        }
        if ($arg === '--dry-run') {
            $config->dryRun = true;
            continue;
        }
        if (startsWith($arg, '--mode=')) {
            $config->mode = trim(substr($arg, 7));
            continue;
        }
        if (startsWith($arg, '--file=')) {
            $config->file = trim(substr($arg, 7));
            continue;
        }
        if (startsWith($arg, '--group-id=')) {
            $config->groupId = max(1, (int)substr($arg, 11));
            continue;
        }
        if (startsWith($arg, '--closed-before=')) {
            $config->closedBefore = trim(substr($arg, 16), " \t\n\r\0\x0B'");
            continue;
        }
        if (startsWith($arg, '--limit=')) {
            $config->limit = max(0, (int)substr($arg, 8));
            continue;
        }
        if (startsWith($arg, '--task-id=')) {
            $config->onlyTaskId = max(1, (int)substr($arg, 10));
            continue;
        }
        if ($arg === '--overwrite') {
            $config->overwrite = true;
            continue;
        }
        throw new InvalidArgumentException('Unknown argument: ' . $arg);
    }

    if (!in_array($config->mode, ['export', 'import'], true)) {
        throw new InvalidArgumentException('Set --mode=export or --mode=import.');
    }
    if ($config->file === '') {
        throw new InvalidArgumentException('Set --file=/path/to/export.json.');
    }

    return $config;
}

function findBitrixDocumentRoot(): string
{
    $candidates = [];

    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $candidates[] = (string)$_SERVER['DOCUMENT_ROOT'];
    }

    $envDocumentRoot = getenv('DOCUMENT_ROOT');
    if ($envDocumentRoot !== false && trim((string)$envDocumentRoot) !== '') {
        $candidates[] = trim((string)$envDocumentRoot);
    }

    $current = realpath(__DIR__) ?: __DIR__;
    while ($current !== '' && $current !== dirname($current)) {
        $candidates[] = $current;
        $current = dirname($current);
    }

    $candidates[] = '/home/bitrix/www';

    foreach (array_unique($candidates) as $candidate) {
        $documentRoot = rtrim($candidate, '/');
        if ($documentRoot === '') {
            continue;
        }

        $prolog = $documentRoot . '/bitrix/modules/main/include/prolog_before.php';
        if (file_exists($prolog)) {
            return $documentRoot;
        }
    }

    throw new RuntimeException(
        'Bitrix prolog not found. Checked from script directory up to filesystem root and /home/bitrix/www. '
        . 'Run from the portal tree or set DOCUMENT_ROOT=/home/bitrix/www.'
    );
}

function bootstrapBitrix()
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('This script is intended to be run from CLI.');
    }

    $_SERVER['DOCUMENT_ROOT'] = findBitrixDocumentRoot();

    define('NO_KEEP_STATISTIC', true);
    define('NO_AGENT_STATISTIC', true);
    define('NO_AGENT_CHECK', true);
    define('NOT_CHECK_PERMISSIONS', true);

    require_once rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') . '/bitrix/modules/main/include/prolog_before.php';

    if (!class_exists('Bitrix\\Main\\Application')) {
        throw new RuntimeException('Bitrix main module is not available.');
    }
}

function connection()
{
    return \Bitrix\Main\Application::getConnection();
}

function tableExists(string $tableName): bool
{
    static $cache = [];
    if (!array_key_exists($tableName, $cache)) {
        $cache[$tableName] = connection()->isTableExists($tableName);
    }
    return $cache[$tableName];
}

function getTableColumns(string $tableName): array
{
    static $cache = [];
    if (!isset($cache[$tableName])) {
        $columns = [];
        $rows = connection()->query('SHOW COLUMNS FROM ' . connection()->getSqlHelper()->quote($tableName));
        while ($row = $rows->fetch()) {
            $columns[] = (string)$row['Field'];
        }
        $cache[$tableName] = $columns;
    }
    return $cache[$tableName];
}

function getPrimaryKeyColumns(string $tableName): array
{
    static $cache = [];
    if (!isset($cache[$tableName])) {
        $columns = [];
        $rows = connection()->query('SHOW KEYS FROM ' . connection()->getSqlHelper()->quote($tableName) . " WHERE Key_name = 'PRIMARY'");
        while ($row = $rows->fetch()) {
            $columns[(int)$row['Seq_in_index']] = (string)$row['Column_name'];
        }
        ksort($columns);
        $cache[$tableName] = array_values($columns);
    }
    return $cache[$tableName];
}

function quoteValue($value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if ($value instanceof DateTimeInterface) {
        $value = $value->format('Y-m-d H:i:s');
    }
    return "'" . connection()->getSqlHelper()->forSql((string)$value) . "'";
}

function normalizeDbValue($value)
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d H:i:s');
    }
    if (is_object($value) && method_exists($value, 'format')) {
        return $value->format('Y-m-d H:i:s');
    }
    if (is_object($value) && method_exists($value, 'toString')) {
        return $value->toString();
    }
    return $value;
}

function normalizeDbRow(array $row): array
{
    foreach ($row as $column => $value) {
        $row[$column] = normalizeDbValue($value);
    }
    return $row;
}


function buildOrder(string $tableName, array $columns): string
{
    if (!tableExists($tableName)) {
        return '';
    }

    $availableColumns = array_flip(getTableColumns($tableName));
    $orderParts = [];
    foreach ($columns as $column => $direction) {
        if (!isset($availableColumns[$column])) {
            continue;
        }
        $direction = strtoupper((string)$direction) === 'DESC' ? 'DESC' : 'ASC';
        $orderParts[] = connection()->getSqlHelper()->quote((string)$column) . ' ' . $direction;
    }

    return implode(', ', $orderParts);
}

function selectRows(string $tableName, string $where, string $order = ''): array
{
    if (!tableExists($tableName)) {
        return [];
    }

    $sql = 'SELECT * FROM ' . connection()->getSqlHelper()->quote($tableName) . ' WHERE ' . $where;
    if ($order !== '') {
        $sql .= ' ORDER BY ' . $order;
    }

    $rows = [];
    $result = connection()->query($sql);
    while ($row = $result->fetch()) {
        $rows[] = normalizeDbRow($row);
    }
    return $rows;
}

function taskFilterSql(RestoreConfig $config): string
{
    if ($config->onlyTaskId !== null) {
        return 'ID = ' . (int)$config->onlyTaskId;
    }

    return 'GROUP_ID = ' . (int)$config->groupId
        . ' AND STATUS = ' . COMPLETED_STATUS
        . ' AND CLOSED_DATE < ' . quoteValue($config->closedBefore);
}

function getTaskIds(RestoreConfig $config): array
{
    $sql = 'SELECT ID FROM ' . connection()->getSqlHelper()->quote('b_tasks') . ' WHERE ' . taskFilterSql($config) . ' ORDER BY ID ASC';
    if ($config->limit > 0) {
        $sql .= ' LIMIT ' . (int)$config->limit;
    }

    $ids = [];
    $result = connection()->query($sql);
    while ($row = $result->fetch()) {
        $ids[] = (int)$row['ID'];
    }
    return $ids;
}

function exportTask(int $taskId): array
{
    $taskRows = selectRows('b_tasks', 'ID = ' . $taskId);
    if (!$taskRows) {
        throw new RuntimeException('Task not found: ' . $taskId);
    }

    $task = $taskRows[0];
    $forumTopicId = isset($task['FORUM_TOPIC_ID']) ? (int)$task['FORUM_TOPIC_ID'] : 0;

    $data = [
        'taskId' => $taskId,
        'tables' => [
            'b_tasks' => [$task],
            'b_tasks_member' => selectRows('b_tasks_member', 'TASK_ID = ' . $taskId, buildOrder('b_tasks_member', ['TYPE' => 'ASC', 'USER_ID' => 'ASC'])),
            'b_tasks_checklist_items' => selectRows('b_tasks_checklist_items', 'TASK_ID = ' . $taskId, buildOrder('b_tasks_checklist_items', ['SORT_INDEX' => 'ASC', 'ID' => 'ASC'])),
            'b_tasks_elapsed_time' => selectRows('b_tasks_elapsed_time', 'TASK_ID = ' . $taskId, buildOrder('b_tasks_elapsed_time', ['ID' => 'ASC', 'CREATED_DATE' => 'ASC'])),
            'b_tasks_reminder' => selectRows('b_tasks_reminder', 'TASK_ID = ' . $taskId, buildOrder('b_tasks_reminder', ['ID' => 'ASC', 'REMIND_DATE' => 'ASC'])),
            'b_tasks_tag' => selectRows('b_tasks_tag', 'TASK_ID = ' . $taskId, 'NAME ASC'),
            'b_tasks_result' => selectRows('b_tasks_result', 'TASK_ID = ' . $taskId, buildOrder('b_tasks_result', ['ID' => 'ASC', 'CREATED_AT' => 'ASC'])),
            'b_tasks_viewed' => selectRows('b_tasks_viewed', 'TASK_ID = ' . $taskId),
            'b_uts_tasks_task' => selectRows('b_uts_tasks_task', 'VALUE_ID = ' . $taskId),
            'b_utm_tasks_task' => selectRows('b_utm_tasks_task', 'VALUE_ID = ' . $taskId),
        ],
    ];

    if ($forumTopicId > 0) {
        $messages = selectRows('b_forum_message', 'TOPIC_ID = ' . $forumTopicId, buildOrder('b_forum_message', ['ID' => 'ASC']));
        $messageIds = array_map(static function (array $row): int { return (int)$row['ID']; }, $messages);
        $data['tables']['b_forum_topic'] = selectRows('b_forum_topic', 'ID = ' . $forumTopicId);
        $data['tables']['b_forum_message'] = $messages;
        if ($messageIds) {
            $data['tables']['b_forum_message_file'] = selectRows('b_forum_message_file', 'MESSAGE_ID IN (' . implode(',', $messageIds) . ')');
        }
    }

    foreach ($data['tables'] as $tableName => $rows) {
        if (!tableExists($tableName)) {
            unset($data['tables'][$tableName]);
        }
    }

    return $data;
}

function exportTasks(RestoreConfig $config)
{
    if (!tableExists('b_tasks')) {
        throw new RuntimeException('Table b_tasks does not exist.');
    }

    $taskIds = getTaskIds($config);
    $export = [
        'meta' => [
            'format' => 'bitrix24-task-raw-export-v1',
            'createdAt' => date('c'),
            'groupId' => $config->groupId,
            'closedBefore' => $config->closedBefore,
            'completedStatus' => COMPLETED_STATUS,
            'preserveDates' => true,
            'preserveIds' => true,
        ],
        'tasks' => [],
    ];

    foreach ($taskIds as $taskId) {
        $export['tasks'][] = exportTask($taskId);
        echo 'Exported task #' . $taskId . "\n";
    }

    $json = json_encode($export, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('JSON encode failed: ' . json_last_error_msg());
    }
    if (file_put_contents($config->file, $json) === false) {
        throw new RuntimeException('Could not write export file: ' . $config->file);
    }

    echo 'Export file written: ' . $config->file . "\n";
    echo 'Tasks exported: ' . count($taskIds) . "\n";
}

function loadExportFile(string $file): array
{
    if (!is_file($file)) {
        throw new RuntimeException('Export file not found: ' . $file);
    }
    $json = file_get_contents($file);
    if ($json === false) {
        throw new RuntimeException('Could not read export file: ' . $file);
    }
    $data = json_decode($json, true);
    if (!is_array($data) || ($data['meta']['format'] ?? '') !== 'bitrix24-task-raw-export-v1') {
        throw new RuntimeException('Invalid export file format.');
    }
    return $data;
}

function rowExists(string $tableName, array $primaryKey, array $row): bool
{
    if (!$primaryKey) {
        return false;
    }

    $where = [];
    foreach ($primaryKey as $column) {
        if (!array_key_exists($column, $row)) {
            return false;
        }
        $where[] = connection()->getSqlHelper()->quote($column) . ' = ' . quoteValue($row[$column]);
    }

    $sql = 'SELECT 1 FROM ' . connection()->getSqlHelper()->quote($tableName) . ' WHERE ' . implode(' AND ', $where) . ' LIMIT 1';
    return (bool)connection()->query($sql)->fetch();
}

function insertRawRow(string $tableName, array $row)
{
    $tableColumns = array_flip(getTableColumns($tableName));
    $filtered = [];
    foreach ($row as $column => $value) {
        if (isset($tableColumns[$column])) {
            $filtered[$column] = normalizeDbValue($value);
        }
    }
    if (!$filtered) {
        return;
    }

    $columns = [];
    $values = [];
    foreach ($filtered as $column => $value) {
        $columns[] = connection()->getSqlHelper()->quote($column);
        $values[] = quoteValue($value);
    }

    connection()->queryExecute(
        'INSERT INTO ' . connection()->getSqlHelper()->quote($tableName)
        . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')'
    );
}

function updateRawRow(string $tableName, array $primaryKey, array $row)
{
    $tableColumns = array_flip(getTableColumns($tableName));
    $sets = [];
    $where = [];

    foreach ($row as $column => $value) {
        if (!isset($tableColumns[$column])) {
            continue;
        }
        $quotedColumn = connection()->getSqlHelper()->quote($column);
        if (in_array($column, $primaryKey, true)) {
            $where[] = $quotedColumn . ' = ' . quoteValue($value);
            continue;
        }
        $sets[] = $quotedColumn . ' = ' . quoteValue(normalizeDbValue($value));
    }

    if (!$sets || count($where) !== count($primaryKey)) {
        return;
    }

    connection()->queryExecute(
        'UPDATE ' . connection()->getSqlHelper()->quote($tableName)
        . ' SET ' . implode(', ', $sets)
        . ' WHERE ' . implode(' AND ', $where)
    );
}

function importTableRows(string $tableName, array $rows, bool $dryRun, bool $overwrite = false): array
{
    if (!tableExists($tableName)) {
        return ['inserted' => 0, 'skipped' => count($rows), 'message' => 'table is absent'];
    }

    $primaryKey = getPrimaryKeyColumns($tableName);
    $inserted = 0;
    $updated = 0;
    $skipped = 0;

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (rowExists($tableName, $primaryKey, $row)) {
            if ($overwrite) {
                if (!$dryRun) {
                    updateRawRow($tableName, $primaryKey, $row);
                }
                $updated++;
            } else {
                $skipped++;
            }
            continue;
        }
        if (!$dryRun) {
            insertRawRow($tableName, $row);
        }
        $inserted++;
    }

    return ['inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped, 'message' => ''];
}

function importTasks(RestoreConfig $config)
{
    $export = loadExportFile($config->file);
    $tasks = isset($export['tasks']) && is_array($export['tasks']) ? $export['tasks'] : [];
    echo 'Tasks in export: ' . count($tasks) . "\n";
    echo $config->dryRun ? "Dry-run: database will not be changed.\n" : "Run mode: database will be changed.\n";
    echo $config->overwrite ? "Overwrite mode: existing rows will be updated from export.\n" : "Skip mode: existing rows will not be changed.\n";

    $connection = connection();
    if (!$config->dryRun) {
        $connection->startTransaction();
    }

    try {
        foreach ($tasks as $task) {
            $taskId = (int)($task['taskId'] ?? 0);
            echo 'Import task #' . $taskId . "\n";
            $tables = isset($task['tables']) && is_array($task['tables']) ? $task['tables'] : [];

            foreach ($tables as $tableName => $rows) {
                if (!is_array($rows)) {
                    continue;
                }
                $result = importTableRows((string)$tableName, $rows, $config->dryRun, $config->overwrite);
                $note = $result['message'] !== '' ? ' (' . $result['message'] . ')' : '';
                $updated = isset($result['updated']) ? (int)$result['updated'] : 0;
                echo '  ' . $tableName . ': insert ' . $result['inserted'] . ', update ' . $updated . ', skip ' . $result['skipped'] . $note . "\n";
            }
        }

        if (!$config->dryRun) {
            $connection->commitTransaction();
        }
    } catch (Throwable $e) {
        if (!$config->dryRun) {
            $connection->rollbackTransaction();
        }
        throw $e;
    }
}

try {
    $config = parseConfig($argv);
    bootstrapBitrix();

    if ($config->mode === 'export') {
        exportTasks($config);
    } else {
        importTasks($config);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
