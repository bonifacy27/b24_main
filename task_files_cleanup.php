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

function loadUsers(): array
{
    $users = [];
    $result = UserTable::getList([
        'order' => ['LAST_NAME' => 'ASC', 'NAME' => 'ASC', 'LOGIN' => 'ASC'],
        'select' => ['ID', 'LOGIN', 'NAME', 'LAST_NAME', 'ACTIVE'],
    ]);

    while ($user = $result->fetch()) {
        $fullName = trim($user['LAST_NAME'] . ' ' . $user['NAME']);
        if ($fullName === '') {
            $fullName = $user['LOGIN'];
        }

        $users[] = [
            'ID' => (int)$user['ID'],
            'LABEL' => sprintf('[%d] %s (%s)%s', (int)$user['ID'], $fullName, (string)$user['LOGIN'], $user['ACTIVE'] === 'N' ? ' [неактивен]' : ''),
        ];
    }

    return $users;
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

    $row = $connection->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'")->fetch();
    $cache[$key] = is_array($row);

    return $cache[$key];
}

function buildTaskUserFilter(int $userId): string
{
    $conditions = [];

    if (hasColumn('b_file', 'CREATED_BY')) {
        $conditions[] = 'f.CREATED_BY = ' . $userId;
    }
    if (hasColumn('b_tasks_files', 'USER_ID')) {
        $conditions[] = 'tf.USER_ID = ' . $userId;
    }
    if (hasColumn('b_tasks_files', 'CREATED_BY')) {
        $conditions[] = 'tf.CREATED_BY = ' . $userId;
    }
    if (hasColumn('b_tasks', 'CREATED_BY')) {
        $conditions[] = 't.CREATED_BY = ' . $userId;
    }

    if (empty($conditions)) {
        return '1=0';
    }

    return '(' . implode(' OR ', $conditions) . ')';
}

function buildCommentUserFilter(int $userId): string
{
    $conditions = [];

    if (hasColumn('b_file', 'CREATED_BY')) {
        $conditions[] = 'f.CREATED_BY = ' . $userId;
    }
    if (hasColumn('b_forum_message', 'AUTHOR_ID')) {
        $conditions[] = 'fm.AUTHOR_ID = ' . $userId;
    }
    if (hasColumn('b_tasks', 'CREATED_BY')) {
        $conditions[] = 't.CREATED_BY = ' . $userId;
    }

    if (empty($conditions)) {
        return '1=0';
    }

    return '(' . implode(' OR ', $conditions) . ')';
}

function loadTaskFileUsages(int $userId): array
{
    $connection = Application::getConnection();
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
        FROM b_tasks_files tf
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

$users = loadUsers();
$rows = $selectedUserId > 0 ? buildRows($selectedUserId) : [];
$hasCreatedBy = hasColumn('b_file', 'CREATED_BY');
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
    <select name="user_id" id="user_id" required>
        <option value="">-- выберите пользователя --</option>
        <?php foreach ($users as $user): ?>
            <option value="<?= (int)$user['ID'] ?>" <?= $selectedUserId === (int)$user['ID'] ? 'selected' : '' ?>><?= h($user['LABEL']) ?></option>
        <?php endforeach; ?>
    </select>
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
                <th>Удалить</th><th>Файл</th><th>Размер</th><th>Дата использования</th><th>Задача</th><th>Дата создания задачи</th><th>Дата завершения задачи</th><th>Статус задачи</th>
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
</body>
</html>
