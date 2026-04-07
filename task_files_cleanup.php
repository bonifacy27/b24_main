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

function buildRows(int $userId): array
{
    $taskUsages = loadTaskFileUsages($userId);
    $commentUsages = loadCommentFileUsages($userId);
    $standaloneFiles = loadStandaloneUserFiles($userId);

    $byFile = [];

    foreach ($standaloneFiles as $fileId => $file) {
        $byFile[$fileId] = ['FILE' => $file, 'USAGES' => []];
    }

    foreach ([$taskUsages, $commentUsages] as $group) {
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

    foreach (array_unique(array_map('intval', $fileIds)) as $fileId) {
        if ($fileId <= 0) {
            continue;
        }

        if (\CFile::Delete($fileId)) {
            $deleted[] = $fileId;
        } else {
            $errors[] = $fileId;
        }
    }

    return ['deleted' => $deleted, 'errors' => $errors];
}

$selectedUserId = isset($_REQUEST['user_id']) ? (int)$_REQUEST['user_id'] : 0;
$action = isset($_POST['action']) ? (string)$_POST['action'] : '';
$message = '';

if ($action === 'delete' && check_bitrix_sessid()) {
    $toDelete = isset($_POST['delete_files']) && is_array($_POST['delete_files']) ? $_POST['delete_files'] : [];
    $result = deleteFiles($toDelete);
    $message = sprintf('Удалено файлов: %d. Ошибок удаления: %d.', count($result['deleted']), count($result['errors']));
}

$user = getUserById($selectedUserId);
$sortParams = getSortParams($_GET);
$rows = $selectedUserId > 0 ? buildRows($selectedUserId) : [];
$rows = sortRows($rows, $sortParams['sort'], $sortParams['dir']);
$hasCreatedBy = hasColumn('b_file', 'CREATED_BY');
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
    <button type="button" id="user_picker_btn">Выбрать пользователя</button>
    <span id="user_picker_label" class="muted"><?= $user ? h($user['LABEL']) : 'Не выбран' ?></span>
    <button type="submit">Сканировать</button>
</form>

<?php if (!$hasCreatedBy): ?>
    <div class="warn">В таблице <code>b_file</code> нет поля <code>CREATED_BY</code>. Показаны файлы, найденные по привязкам к задачам/комментариям выбранного пользователя.</div>
<?php endif; ?>

<?php if ($message !== ''): ?>
    <div class="msg"><?= h($message) ?></div>
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
BX.ready(function () {
    const userInput = document.getElementById('user_id');
    const userLabel = document.getElementById('user_picker_label');
    const btn = document.getElementById('user_picker_btn');
    if (!btn || !BX.UI || !BX.UI.EntitySelector) {
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
                userLabel.textContent = item.getTitle() + ' [' + item.getId() + ']';
            },
            'Item:onDeselect': function () {
                userInput.value = '';
                userLabel.textContent = 'Не выбран';
            }
        }
    });

    btn.addEventListener('click', function () {
        dialog.show();
    });
});
</script>
</body>
</html>
