<?php
/**
 * Рабочее место администратора АХС: управление рабочими местами (HL-блок 74).
 *
 * URL: /pub/apps/attendance_report/cabinet_edit.php
 */

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;

const CABINET_HL_BLOCK_ID = 74;
const EXCLUDED_CABINET_ROW_IDS = [249, 210];
const REPORT_URL = '/pub/apps/attendance_report/workplace_report_ext2.php';

$cabinetEditorRoles = [
    'AHS_ADMIN' => [4945, 3532, 5060],
];

header('Content-Type: text/html; charset=UTF-8');

if (!Loader::includeModule('main') || !Loader::includeModule('highloadblock')) {
    echo '<!doctype html><meta charset="utf-8"><p>Ошибка: не удалось подключить обязательные модули Bitrix.</p>';
    exit;
}

global $USER, $APPLICATION;
$currentUserId = is_object($USER) ? (int)$USER->GetID() : 0;
$isAhsAdmin = in_array($currentUserId, $cabinetEditorRoles['AHS_ADMIN'], true);
if (!$isAhsAdmin) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><p>Доступ запрещен.</p>';
    exit;
}

$hlBlock = HighloadBlockTable::getById(CABINET_HL_BLOCK_ID)->fetch();
if (!$hlBlock) {
    echo '<!doctype html><meta charset="utf-8"><p>Ошибка: HL-блок кабинетов с ID ' . CABINET_HL_BLOCK_ID . ' не найден.</p>';
    exit;
}

$entity = HighloadBlockTable::compileEntity($hlBlock);
$cabinetClass = $entity->getDataClass();
$request = Application::getInstance()->getContext()->getRequest();
$messages = [];
$errors = [];

function cabinetEditorHtml(string $value): string
{
    return htmlspecialcharsbx($value);
}

function cabinetEditorGetOfficeEnums(): array
{
    $offices = [];
    $field = \CUserTypeEntity::GetList([], ['ENTITY_ID' => 'HLBLOCK_' . CABINET_HL_BLOCK_ID, 'FIELD_NAME' => 'UF_OFFICE'])->Fetch();
    if (!$field || empty($field['ID'])) {
        return $offices;
    }

    $enumRows = \CUserFieldEnum::GetList(['SORT' => 'ASC', 'VALUE' => 'ASC'], ['USER_FIELD_ID' => (int)$field['ID']]);
    while ($enum = $enumRows->Fetch()) {
        $id = (int)$enum['ID'];
        $offices[$id] = trim((string)$enum['VALUE']);
    }

    return $offices;
}

function cabinetEditorNormalizeOfficeValue($value): int
{
    if (is_array($value)) {
        $value = isset($value['VALUE']) ? $value['VALUE'] : reset($value);
    }

    return (int)$value;
}

function cabinetEditorOfficeName($value, array $offices): string
{
    $officeId = cabinetEditorNormalizeOfficeValue($value);
    return isset($offices[$officeId]) ? $offices[$officeId] : '';
}

function cabinetEditorGetNextCabinetId(string $cabinetClass): int
{
    $maxCabinetId = 0;
    $rows = $cabinetClass::getList(['select' => ['UF_CABINET_ID']]);
    while ($row = $rows->fetch()) {
        $cabinetId = trim((string)$row['UF_CABINET_ID']);
        if ($cabinetId !== '' && ctype_digit($cabinetId)) {
            $maxCabinetId = max($maxCabinetId, (int)$cabinetId);
        }
    }

    return $maxCabinetId + 1;
}

function cabinetEditorBuildCabinetName(string $officeName, string $cabinetNumber): string
{
    return trim($officeName) . ', каб. ' . trim($cabinetNumber);
}

function cabinetEditorNormalizeFlexWorkplaces(string $value, int $workplaces, array &$errors): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $parts = preg_split('/\s*,\s*/', $value, -1, PREG_SPLIT_NO_EMPTY);
    $numbers = [];
    foreach ($parts as $part) {
        if (!ctype_digit($part) || (int)$part <= 0) {
            $errors[] = 'Номера гибридных рабочих мест должны быть положительными целыми числами через запятую.';
            return '';
        }
        $number = (int)$part;
        if ($workplaces > 0 && $number > $workplaces) {
            $errors[] = 'Номер гибридного рабочего места ' . $number . ' больше общего количества рабочих мест (' . $workplaces . ').';
            return '';
        }
        $numbers[$number] = $number;
    }

    ksort($numbers, SORT_NUMERIC);
    if (count($numbers) > $workplaces) {
        $errors[] = 'Гибридных мест не может быть больше, чем общее количество рабочих мест.';
        return '';
    }

    return implode(',', $numbers);
}

function cabinetEditorFindDuplicateName(string $name, string $cabinetClass, int $excludeRowId = 0): int
{
    $row = $cabinetClass::getList([
        'select' => ['ID'],
        'filter' => ['=UF_NAME' => $name],
        'limit' => 1,
    ])->fetch();

    if (!$row) {
        return 0;
    }

    $rowId = (int)$row['ID'];
    return $rowId === $excludeRowId ? 0 : $rowId;
}

function cabinetEditorLoadCabinets(string $cabinetClass, array $offices, string $search, int $officeFilter, string $sortDirection): array
{
    $cabinets = [];
    $rows = $cabinetClass::getList([
        'select' => ['ID', 'UF_CABINET_ID', 'UF_NAME', 'UF_WORKPLACES', 'UF_FLEX_WORKPLACES', 'UF_OFFICE'],
        'order' => ['UF_NAME' => $sortDirection === 'DESC' ? 'DESC' : 'ASC', 'ID' => 'ASC'],
    ]);

    $searchLower = mb_strtolower($search);
    while ($row = $rows->fetch()) {
        if (in_array((int)$row['ID'], EXCLUDED_CABINET_ROW_IDS, true)) {
            continue;
        }

        $officeId = cabinetEditorNormalizeOfficeValue($row['UF_OFFICE'] ?? 0);
        $row['OFFICE_ID'] = $officeId;
        $row['OFFICE_NAME'] = cabinetEditorOfficeName($officeId, $offices);

        if ($officeFilter > 0 && $officeId !== $officeFilter) {
            continue;
        }

        if ($searchLower !== '') {
            $haystack = mb_strtolower((string)$row['UF_NAME'] . ' ' . (string)$row['UF_CABINET_ID'] . ' ' . $row['OFFICE_NAME']);
            if (mb_strpos($haystack, $searchLower) === false) {
                continue;
            }
        }

        $cabinets[] = $row;
    }

    return $cabinets;
}

function cabinetEditorExportExcel(array $cabinets): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="workplaces_' . date('Y-m-d_H-i-s') . '.xls"');
    echo "\xEF\xBB\xBF";
    echo '<table border="1">';
    echo '<tr><th>ID кабинета</th><th>Название</th><th>Офис</th><th>Рабочих мест</th><th>Гибридные места</th></tr>';
    foreach ($cabinets as $cabinet) {
        echo '<tr>';
        echo '<td>' . cabinetEditorHtml((string)$cabinet['UF_CABINET_ID']) . '</td>';
        echo '<td>' . cabinetEditorHtml((string)$cabinet['UF_NAME']) . '</td>';
        echo '<td>' . cabinetEditorHtml((string)$cabinet['OFFICE_NAME']) . '</td>';
        echo '<td>' . (int)$cabinet['UF_WORKPLACES'] . '</td>';
        echo '<td>' . cabinetEditorHtml((string)($cabinet['UF_FLEX_WORKPLACES'] ?? '')) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    exit;
}

$offices = cabinetEditorGetOfficeEnums();
$search = trim((string)$request->getQuery('q'));
$officeFilter = (int)$request->getQuery('office');
$sortDirection = mb_strtolower((string)$request->getQuery('sort')) === 'desc' ? 'DESC' : 'ASC';

if ($request->isPost()) {
    if (!check_bitrix_sessid()) {
        $errors[] = 'Сессия истекла. Обновите страницу и повторите действие.';
    } else {
        $action = (string)$request->getPost('action');
        $workplaces = (int)$request->getPost('workplaces');
        $flexErrors = [];
        $flexWorkplaces = cabinetEditorNormalizeFlexWorkplaces((string)$request->getPost('flex_workplaces'), $workplaces, $flexErrors);
        $errors = array_merge($errors, $flexErrors);

        if ($workplaces <= 0) {
            $errors[] = 'Укажите количество рабочих мест больше нуля.';
        }

        if ($action === 'add') {
            $officeId = (int)$request->getPost('office_id');
            $cabinetNumber = trim((string)$request->getPost('cabinet_number'));
            $officeName = isset($offices[$officeId]) ? $offices[$officeId] : '';
            $cabinetName = $officeName !== '' && $cabinetNumber !== '' ? cabinetEditorBuildCabinetName($officeName, $cabinetNumber) : '';

            if ($officeId <= 0 || $officeName === '') {
                $errors[] = 'Выберите офис.';
            }
            if ($cabinetNumber === '') {
                $errors[] = 'Укажите номер кабинета.';
            }
            if ($cabinetName !== '' && cabinetEditorFindDuplicateName($cabinetName, $cabinetClass) > 0) {
                $errors[] = 'Кабинет «' . cabinetEditorHtml($cabinetName) . '» уже есть в справочнике.';
            }

            if (empty($errors)) {
                $result = $cabinetClass::add([
                    'UF_CABINET_ID' => cabinetEditorGetNextCabinetId($cabinetClass),
                    'UF_NAME' => $cabinetName,
                    'UF_WORKPLACES' => $workplaces,
                    'UF_FLEX_WORKPLACES' => $flexWorkplaces,
                    'UF_OFFICE' => $officeId,
                ]);
                if ($result->isSuccess()) {
                    $messages[] = 'Кабинет добавлен: ' . $cabinetName . '.';
                } else {
                    $errors = array_merge($errors, $result->getErrorMessages());
                }
            }
        } elseif ($action === 'save') {
            $rowId = (int)$request->getPost('row_id');
            if ($rowId <= 0) {
                $errors[] = 'Не найден ID строки для сохранения.';
            }

            if (empty($errors)) {
                $result = $cabinetClass::update($rowId, [
                    'UF_WORKPLACES' => $workplaces,
                    'UF_FLEX_WORKPLACES' => $flexWorkplaces,
                ]);
                if ($result->isSuccess()) {
                    $messages[] = 'Рабочие места кабинета сохранены.';
                } else {
                    $errors = array_merge($errors, $result->getErrorMessages());
                }
            }
        }
    }
}

$cabinets = cabinetEditorLoadCabinets($cabinetClass, $offices, $search, $officeFilter, $sortDirection);
if ((string)$request->getQuery('export') === 'excel') {
    cabinetEditorExportExcel($cabinets);
}

$APPLICATION->SetTitle('Управление РМ');
$sortToggle = $sortDirection === 'ASC' ? 'desc' : 'asc';
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Управление РМ</title>
    <style>
        body { margin: 0; padding: 20px; background: #f5f7fb; color: #1f2937; font: 13px/1.4 Arial, sans-serif; }
        h1 { margin: 0 0 14px; font-size: 24px; }
        h2 { margin: 0 0 12px; font-size: 18px; }
        a { color: #2563eb; }
        .panel { margin-bottom: 14px; padding: 14px; background: #fff; border: 1px solid #d9e2ef; border-radius: 10px; box-shadow: 0 2px 8px rgba(15, 23, 42, .05); }
        .top-links { display: flex; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; }
        .grid { display: grid; grid-template-columns: minmax(210px, 1.2fr) minmax(130px, .7fr) minmax(120px, .5fr) minmax(170px, .8fr) auto; gap: 10px; align-items: end; }
        .toolbar { display: flex; gap: 10px; align-items: end; flex-wrap: wrap; }
        label.title { display: block; margin-bottom: 4px; color: #4b5563; font-weight: 700; }
        input[type="text"], input[type="number"], select { box-sizing: border-box; width: 100%; padding: 7px 8px; border: 1px solid #cbd5e1; border-radius: 7px; background: #fff; }
        button, .button { display: inline-block; padding: 8px 12px; border: 0; border-radius: 7px; background: #2563eb; color: #fff; font-weight: 700; cursor: pointer; text-decoration: none; white-space: nowrap; }
        .button.secondary, button.secondary { background: #475569; }
        button.success { background: #16a34a; }
        table { width: 100%; border-collapse: collapse; background: #fff; }
        th, td { padding: 7px 8px; border-bottom: 1px solid #e5e7eb; vertical-align: middle; text-align: left; }
        th { position: sticky; top: 0; z-index: 1; background: #f8fafc; color: #475569; font-size: 12px; }
        tr:hover td { background: #f8fafc; }
        .table-wrap { overflow: auto; border: 1px solid #d9e2ef; border-radius: 10px; max-height: 68vh; }
        .messages, .errors { margin-bottom: 14px; padding: 10px 12px; border-radius: 9px; }
        .messages { background: #ecfdf5; border: 1px solid #bbf7d0; color: #166534; }
        .errors { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .muted { color: #64748b; font-size: 12px; }
        .id-col { width: 80px; }
        .name-col { min-width: 290px; }
        .office-col { min-width: 190px; }
        .num-col { width: 110px; }
        .flex-col { width: 170px; }
        .actions { width: 110px; white-space: nowrap; }
    </style>
</head>
<body>
    <h1>Управление РМ</h1>
    <div class="top-links">
        <a class="button secondary" href="<?= cabinetEditorHtml(REPORT_URL) ?>">Отчеты по рабочим местам</a>
    </div>

    <?php if (!empty($messages)): ?>
        <div class="messages"><?php foreach ($messages as $message): ?><div><?= cabinetEditorHtml((string)$message) ?></div><?php endforeach; ?></div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="errors"><?php foreach ($errors as $error): ?><div><?= cabinetEditorHtml((string)$error) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="panel">
        <h2>Добавить кабинет</h2>
        <form method="post" class="grid">
            <?= bitrix_sessid_post() ?>
            <input type="hidden" name="action" value="add">
            <div>
                <label class="title" for="new_office">Офис *</label>
                <select id="new_office" name="office_id" required>
                    <option value="">Выберите офис</option>
                    <?php foreach ($offices as $officeId => $officeName): ?>
                        <option value="<?= (int)$officeId ?>"><?= cabinetEditorHtml($officeName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="title" for="new_number">Номер кабинета *</label>
                <input id="new_number" type="text" name="cabinet_number" placeholder="609б" required>
            </div>
            <div>
                <label class="title" for="new_workplaces">Мест *</label>
                <input id="new_workplaces" type="number" min="1" step="1" name="workplaces" value="1" required>
            </div>
            <div>
                <label class="title" for="new_flex">Гибридные места</label>
                <input id="new_flex" type="text" name="flex_workplaces" placeholder="1,5,12">
            </div>
            <div><button class="success" type="submit">Добавить</button></div>
        </form>
        <div class="muted">Название будет создано автоматически по шаблону: «Офис, каб. номер». ID кабинета будет присвоен как максимум UF_CABINET_ID + 1.</div>
    </div>

    <div class="panel">
        <form method="get" class="toolbar">
            <div style="min-width:280px;">
                <label class="title" for="q">Поиск по названию</label>
                <input id="q" type="text" name="q" value="<?= cabinetEditorHtml($search) ?>" placeholder="Введите часть названия">
            </div>
            <div style="min-width:230px;">
                <label class="title" for="office">Фильтр по офису</label>
                <select id="office" name="office">
                    <option value="0">Все офисы</option>
                    <?php foreach ($offices as $officeId => $officeName): ?>
                        <option value="<?= (int)$officeId ?>"<?= (int)$officeId === $officeFilter ? ' selected' : '' ?>><?= cabinetEditorHtml($officeName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <input type="hidden" name="sort" value="<?= cabinetEditorHtml(mb_strtolower($sortDirection)) ?>">
            <button type="submit">Применить</button>
            <a class="button secondary" href="<?= cabinetEditorHtml($request->getRequestedPage()) ?>">Сбросить</a>
            <a class="button" href="?q=<?= urlencode($search) ?>&amp;office=<?= (int)$officeFilter ?>&amp;sort=<?= cabinetEditorHtml(mb_strtolower($sortDirection)) ?>&amp;export=excel">Выгрузить в Excel</a>
        </form>
        <p class="muted">Найдено кабинетов: <?= count($cabinets) ?>. Сотрудник АХС может менять только количество рабочих мест и номера гибридных мест.</p>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th class="id-col">ID</th>
                        <th class="name-col"><a href="?q=<?= urlencode($search) ?>&amp;office=<?= (int)$officeFilter ?>&amp;sort=<?= cabinetEditorHtml($sortToggle) ?>">Название <?= $sortDirection === 'ASC' ? '↑' : '↓' ?></a></th>
                        <th class="office-col">Офис</th>
                        <th class="num-col">Мест</th>
                        <th class="flex-col">Гибридные места</th>
                        <th class="actions">Действия</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($cabinets)): ?>
                    <tr><td colspan="6">Кабинеты не найдены.</td></tr>
                <?php endif; ?>
                <?php foreach ($cabinets as $cabinet): ?>
                    <?php $rowId = (int)$cabinet['ID']; ?>
                    <tr>
                        <td><?= cabinetEditorHtml((string)$cabinet['UF_CABINET_ID']) ?></td>
                        <td><?= cabinetEditorHtml((string)$cabinet['UF_NAME']) ?></td>
                        <td><?= cabinetEditorHtml((string)$cabinet['OFFICE_NAME']) ?></td>
                        <td>
                            <form method="post" id="rowForm<?= $rowId ?>">
                                <?= bitrix_sessid_post() ?>
                                <input type="hidden" name="action" value="save">
                                <input type="hidden" name="row_id" value="<?= $rowId ?>">
                                <input type="number" min="1" step="1" name="workplaces" value="<?= (int)$cabinet['UF_WORKPLACES'] ?>" required>
                            </form>
                        </td>
                        <td><input form="rowForm<?= $rowId ?>" type="text" name="flex_workplaces" value="<?= cabinetEditorHtml((string)($cabinet['UF_FLEX_WORKPLACES'] ?? '')) ?>" placeholder="1,5,12"></td>
                        <td class="actions"><button form="rowForm<?= $rowId ?>" type="submit">Сохранить</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
