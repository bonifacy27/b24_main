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

$statusMap = [
    1 => 'Новая',
    2 => 'Ожидает выполнения',
    3 => 'Выполняется',
    4 => 'Ждёт контроля',
    5 => 'Завершена',
    6 => 'Отложена',
    7 => 'Отклонена',
];

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
    $power = (int)floor(log($bytes, 1024));
    $power = max(0, min($power, count($units) - 1));

    return round($bytes / (1024 ** $power), 2) . ' ' . $units[$power];
}

function loadUsers(): array
{
    $users = [];
    $result = UserTable::getList([
        'order' => ['LAST_NAME' => 'ASC', 'NAME' => 'ASC', 'LOGIN' => 'ASC'],
        'select' => ['ID', 'LOGIN', 'NAME', 'LAST_NAME', 'EMAIL', 'ACTIVE'],
    ]);

    while ($user = $result->fetch()) {
        $fullName = trim($user['LAST_NAME'] . ' ' . $user['NAME']);
        if ($fullName === '') {
            $fullName = $user['LOGIN'];
        }

        $users[] = [
            'ID' => (int)$user['ID'],
            'LABEL' => sprintf(
                '[%d] %s (%s)%s',
                (int)$user['ID'],
                $fullName,
                (string)$user['LOGIN'],
                $user['ACTIVE'] === 'N' ? ' [неактивен]' : ''
            ),
        ];
    }

    return $users;
}

function loadFilesByUser(int $userId): array
{
    $connection = Application::getConnection();
    $userId = (int)$userId;
    $files = [];

    $sql = "
        SELECT
            f.ID,
            f.FILE_NAME,
            f.ORIGINAL_NAME,
            f.FILE_SIZE,
            f.SUBDIR,
            f.TIMESTAMP_X,
            f.MODULE_ID
        FROM b_file f
        WHERE f.CREATED_BY = {$userId}
        ORDER BY f.TIMESTAMP_X DESC, f.ID DESC
    ";

    $result = $connection->query($sql);
    while ($row = $result->fetch()) {
        $fileId = (int)$row['ID'];
        $files[$fileId] = [
            'ID' => $fileId,
            'FILE_NAME' => (string)$row['FILE_NAME'],
            'ORIGINAL_NAME' => (string)$row['ORIGINAL_NAME'],
            'FILE_SIZE' => (int)$row['FILE_SIZE'],
            'SUBDIR' => (string)$row['SUBDIR'],
            'TIMESTAMP_X' => (string)$row['TIMESTAMP_X'],
            'MODULE_ID' => (string)$row['MODULE_ID'],
            'USAGES' => [],
        ];
    }

    return $files;
}

function loadTaskFileUsages(int $userId): array
{
    $connection = Application::getConnection();
    $userId = (int)$userId;
    $items = [];

    $sql = "
        SELECT
            f.ID AS FILE_ID,
            t.ID AS TASK_ID,
            t.TITLE AS TASK_TITLE,
            t.CREATED_DATE,
            t.CLOSED_DATE,
            t.STATUS,
            COALESCE(t.CHANGED_DATE, t.CREATED_DATE) AS USAGE_DATE,
            'TASK' AS USAGE_TYPE
        FROM b_file f
        INNER JOIN b_tasks_files tf ON tf.FILE_ID = f.ID
        INNER JOIN b_tasks t ON t.ID = tf.TASK_ID
        WHERE f.CREATED_BY = {$userId}
    ";

    $result = $connection->query($sql);
    while ($row = $result->fetch()) {
        $items[(int)$row['FILE_ID']][] = [
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
    $userId = (int)$userId;
    $items = [];

    $sql = "
        SELECT
            f.ID AS FILE_ID,
            t.ID AS TASK_ID,
            t.TITLE AS TASK_TITLE,
            t.CREATED_DATE,
            t.CLOSED_DATE,
            t.STATUS,
            fm.POST_DATE AS USAGE_DATE,
            'COMMENT' AS USAGE_TYPE
        FROM b_file f
        INNER JOIN b_user_field uf
            ON uf.ENTITY_ID = 'FORUM_MESSAGE'
            AND uf.FIELD_NAME = 'UF_FORUM_MESSAGE_DOC'
        INNER JOIN b_utm_forum_message utm
            ON utm.FIELD_ID = uf.ID
            AND utm.VALUE_INT = f.ID
        INNER JOIN b_forum_message fm ON fm.ID = utm.VALUE_ID
        INNER JOIN b_forum_topic ft ON ft.ID = fm.TOPIC_ID
        INNER JOIN b_tasks t
            ON ft.XML_ID LIKE 'TASK_%'
            AND t.ID = CAST(SUBSTRING(ft.XML_ID, 6) AS UNSIGNED)
        WHERE f.CREATED_BY = {$userId}
    ";

    $result = $connection->query($sql);
    while ($row = $result->fetch()) {
        $items[(int)$row['FILE_ID']][] = [
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

function mergeUsages(array $files, array $taskUsages, array $commentUsages): array
{
    foreach ($files as $fileId => $file) {
        $usages = [];
        if (isset($taskUsages[$fileId])) {
            $usages = array_merge($usages, $taskUsages[$fileId]);
        }
        if (isset($commentUsages[$fileId])) {
            $usages = array_merge($usages, $commentUsages[$fileId]);
        }

        usort($usages, static function (array $a, array $b): int {
            return strcmp((string)$b['USAGE_DATE'], (string)$a['USAGE_DATE']);
        });

        $files[$fileId]['USAGES'] = $usages;
    }

    return $files;
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

    if (!empty($result['deleted']) || !empty($result['errors'])) {
        $message = sprintf(
            'Удалено файлов: %d. Ошибок удаления: %d.',
            count($result['deleted']),
            count($result['errors'])
        );
    } else {
        $message = 'Ничего не удалено.';
    }
}

$users = loadUsers();
$files = [];
if ($selectedUserId > 0) {
    $files = loadFilesByUser($selectedUserId);
    if (!empty($files)) {
        $taskUsages = loadTaskFileUsages($selectedUserId);
        $commentUsages = loadCommentFileUsages($selectedUserId);
        $files = mergeUsages($files, $taskUsages, $commentUsages);
    }
}
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
            <option value="<?= (int)$user['ID'] ?>" <?= $selectedUserId === (int)$user['ID'] ? 'selected' : '' ?>>
                <?= h($user['LABEL']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit">Сканировать</button>
</form>

<?php if ($message !== ''): ?>
    <div class="msg"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($selectedUserId > 0): ?>
    <form method="post">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="user_id" value="<?= (int)$selectedUserId ?>">

        <div class="controls">
            <button type="submit" onclick="return confirm('Удалить выбранные файлы? Действие необратимо.');">
                Удалить выбранные файлы
            </button>
        </div>

        <table>
            <thead>
            <tr>
                <th>Удалить</th>
                <th>Файл</th>
                <th>Размер</th>
                <th>Дата использования</th>
                <th>Задача</th>
                <th>Дата создания задачи</th>
                <th>Дата завершения задачи</th>
                <th>Статус задачи</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($files)): ?>
                <tr>
                    <td colspan="8" class="muted">Файлы у выбранного пользователя не найдены.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($files as $file): ?>
                    <?php
                    $displayName = $file['ORIGINAL_NAME'] !== '' ? $file['ORIGINAL_NAME'] : $file['FILE_NAME'];
                    $path = '/upload/' . trim($file['SUBDIR'] . '/' . $file['FILE_NAME'], '/');
                    $usages = $file['USAGES'];
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
                            <td class="nowrap">
                                <label>
                                    <input type="checkbox" name="delete_files[]" value="<?= (int)$file['ID'] ?>">
                                    ID <?= (int)$file['ID'] ?>
                                </label>
                            </td>
                            <td>
                                <div><strong><?= h($displayName) ?></strong></div>
                                <div class="muted"><?= h($path) ?></div>
                                <div class="muted">Тип связи: <?= h($usage['USAGE_TYPE']) ?></div>
                            </td>
                            <td class="nowrap"><?= h(formatBytes((int)$file['FILE_SIZE'])) ?></td>
                            <td class="nowrap"><?= h($usage['USAGE_DATE']) ?></td>
                            <td>
                                <?php if ((int)$usage['TASK_ID'] > 0): ?>
                                    #<?= (int)$usage['TASK_ID'] ?> — <?= h($usage['TASK_TITLE']) ?>
                                <?php else: ?>
                                    <span class="muted"><?= h($usage['TASK_TITLE']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap"><?= h($usage['TASK_CREATED_DATE']) ?></td>
                            <td class="nowrap"><?= h($usage['TASK_CLOSED_DATE']) ?></td>
                            <td class="nowrap">
                                <?= h($statusMap[(int)$usage['TASK_STATUS']] ?? ($usage['TASK_STATUS'] ? 'Статус ' . (int)$usage['TASK_STATUS'] : '—')) ?>
                            </td>
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
