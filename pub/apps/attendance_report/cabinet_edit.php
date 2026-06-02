<?php
/**
 * Редактор справочника кабинетов (HL-блок 74).
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
const ORG_IBLOCK_ID = 308;
const DEFAULT_ORG_ELEMENT_ID = 3197820;

header('Content-Type: text/html; charset=UTF-8');

if (!Loader::includeModule('main') || !Loader::includeModule('highloadblock') || !Loader::includeModule('iblock')) {
    echo '<!doctype html><meta charset="utf-8"><p>Ошибка: не удалось подключить обязательные модули Bitrix.</p>';
    exit;
}

global $USER, $APPLICATION;
if (!is_object($USER) || !$USER->IsAuthorized() || !$USER->IsAdmin()) {
    echo '<!doctype html><meta charset="utf-8"><p>Доступ запрещен. Для редактирования справочника кабинетов нужны права администратора.</p>';
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

function cabinetEditorNormalizeOrgIds($orgIds): array
{
    if (!is_array($orgIds)) {
        $orgIds = [$orgIds];
    }

    $normalized = [DEFAULT_ORG_ELEMENT_ID => DEFAULT_ORG_ELEMENT_ID];
    foreach ($orgIds as $orgId) {
        $orgId = (int)$orgId;
        if ($orgId > 0) {
            $normalized[$orgId] = $orgId;
        }
    }

    return array_values($normalized);
}

function cabinetEditorFindDuplicateCabinetId(string $cabinetId, string $cabinetClass, int $excludeRowId = 0): int
{
    $cabinetId = trim($cabinetId);
    if ($cabinetId === '') {
        return 0;
    }

    $row = $cabinetClass::getList([
        'select' => ['ID', 'UF_CABINET_ID'],
        'filter' => ['=UF_CABINET_ID' => $cabinetId],
        'limit' => 1,
    ])->fetch();

    if (!$row) {
        return 0;
    }

    $rowId = (int)$row['ID'];
    return $rowId === $excludeRowId ? 0 : $rowId;
}

function cabinetEditorGetNextCabinetId(string $cabinetClass): string
{
    $maxCabinetId = 0;
    $rows = $cabinetClass::getList(['select' => ['UF_CABINET_ID']]);
    while ($row = $rows->fetch()) {
        $cabinetId = trim((string)$row['UF_CABINET_ID']);
        if ($cabinetId !== '' && ctype_digit($cabinetId)) {
            $maxCabinetId = max($maxCabinetId, (int)$cabinetId);
        }
    }

    return (string)($maxCabinetId + 1);
}

function cabinetEditorLoadOrganizations(): array
{
    $organizations = [];
    $rsElements = \CIBlockElement::GetList(
        ['NAME' => 'ASC'],
        ['IBLOCK_ID' => ORG_IBLOCK_ID, 'ACTIVE' => 'Y'],
        false,
        false,
        ['ID', 'NAME']
    );

    while ($element = $rsElements->Fetch()) {
        $organizations[(int)$element['ID']] = [
            'ID' => (int)$element['ID'],
            'NAME' => (string)$element['NAME'],
        ];
    }

    if (!isset($organizations[DEFAULT_ORG_ELEMENT_ID])) {
        $rsDefault = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => ORG_IBLOCK_ID, 'ID' => DEFAULT_ORG_ELEMENT_ID],
            false,
            ['nTopCount' => 1],
            ['ID', 'NAME']
        );
        if ($defaultElement = $rsDefault->Fetch()) {
            $organizations[(int)$defaultElement['ID']] = [
                'ID' => (int)$defaultElement['ID'],
                'NAME' => (string)$defaultElement['NAME'],
            ];
        }
    }

    return $organizations;
}

function cabinetEditorRenderOrgCheckboxes(array $organizations, array $selectedOrgIds, string $fieldName, string $idPrefix, string $formId = ''): string
{
    $selectedMap = array_fill_keys(cabinetEditorNormalizeOrgIds($selectedOrgIds), true);
    $html = '<div class="org-list">';
    foreach ($organizations as $organization) {
        $orgId = (int)$organization['ID'];
        $isDefault = $orgId === DEFAULT_ORG_ELEMENT_ID;
        $checked = isset($selectedMap[$orgId]) ? ' checked' : '';
        $disabled = $isDefault ? ' disabled' : '';
        $hidden = $isDefault ? '<input type="hidden" name="' . cabinetEditorHtml($fieldName) . '[]" value="' . $orgId . '">' : '';
        $labelClass = $isDefault ? ' class="default-org"' : '';
        $formAttr = $formId !== '' ? ' form="' . cabinetEditorHtml($formId) . '"' : '';
        $hidden = $isDefault && $formId !== ''
            ? '<input type="hidden" form="' . cabinetEditorHtml($formId) . '" name="' . cabinetEditorHtml($fieldName) . '[]" value="' . $orgId . '">'
            : $hidden;
        $html .= '<label' . $labelClass . '>' . $hidden
            . '<input type="checkbox" id="' . cabinetEditorHtml($idPrefix . '_' . $orgId) . '" name="' . cabinetEditorHtml($fieldName) . '[]" value="' . $orgId . '"' . $formAttr . $checked . $disabled . '> '
            . cabinetEditorHtml($organization['NAME']) . ' <span class="muted">#' . $orgId . '</span>'
            . ($isDefault ? ' <span class="badge">обязательно</span>' : '')
            . '</label>';
    }
    $html .= '</div>';

    return $html;
}

$organizations = cabinetEditorLoadOrganizations();
$nextCabinetId = cabinetEditorGetNextCabinetId($cabinetClass);

if ($request->isPost()) {
    if (!check_bitrix_sessid()) {
        $errors[] = 'Сессия истекла. Обновите страницу и повторите действие.';
    } else {
        $action = (string)$request->getPost('action');

        if ($action === 'add' || $action === 'save') {
            $rowId = $action === 'save' ? (int)$request->getPost('row_id') : 0;
            $cabinetId = trim((string)$request->getPost('cabinet_id'));
            $cabinetName = trim((string)$request->getPost('name'));
            $workplaces = max(0, (int)$request->getPost('workplaces'));
            $orgIds = cabinetEditorNormalizeOrgIds($request->getPost('org_ids'));

            if ($cabinetId === '') {
                $errors[] = 'Укажите ID кабинета.';
            }
            if ($cabinetName === '') {
                $errors[] = 'Укажите название кабинета.';
            }
            if (cabinetEditorFindDuplicateCabinetId($cabinetId, $cabinetClass, $rowId) > 0) {
                $errors[] = 'ID кабинета «' . cabinetEditorHtml($cabinetId) . '» уже используется.';
            }

            if (empty($errors)) {
                $fields = [
                    'UF_CABINET_ID' => $cabinetId,
                    'UF_NAME' => $cabinetName,
                    'UF_WORKPLACES' => $workplaces,
                    'UF_ORG' => $orgIds,
                ];

                if ($action === 'add') {
                    $result = $cabinetClass::add($fields);
                    if ($result->isSuccess()) {
                        $messages[] = 'Кабинет добавлен.';
                    } else {
                        $errors = array_merge($errors, $result->getErrorMessages());
                    }
                } else {
                    if ($rowId <= 0) {
                        $errors[] = 'Не найден ID строки для сохранения.';
                    } else {
                        $result = $cabinetClass::update($rowId, $fields);
                        if ($result->isSuccess()) {
                            $messages[] = 'Кабинет сохранен.';
                        } else {
                            $errors = array_merge($errors, $result->getErrorMessages());
                        }
                    }
                }
            }
        } elseif ($action === 'bulk_assign') {
            $selectedIds = $request->getPost('selected_ids');
            if (!is_array($selectedIds)) {
                $selectedIds = [];
            }
            $selectedIds = array_values(array_filter(array_map('intval', $selectedIds)));
            $bulkOrgIds = cabinetEditorNormalizeOrgIds($request->getPost('bulk_org_ids'));
            $bulkMode = (string)$request->getPost('bulk_mode') === 'replace' ? 'replace' : 'append';

            if (empty($selectedIds)) {
                $errors[] = 'Отметьте хотя бы один кабинет для массовой привязки.';
            }

            if (empty($errors)) {
                $updatedCount = 0;
                foreach ($selectedIds as $selectedId) {
                    $row = $cabinetClass::getById($selectedId)->fetch();
                    if (!$row) {
                        continue;
                    }

                    $currentOrgIds = cabinetEditorNormalizeOrgIds($row['UF_ORG']);
                    $newOrgIds = $bulkMode === 'replace'
                        ? $bulkOrgIds
                        : cabinetEditorNormalizeOrgIds(array_merge($currentOrgIds, $bulkOrgIds));

                    $result = $cabinetClass::update($selectedId, ['UF_ORG' => $newOrgIds]);
                    if ($result->isSuccess()) {
                        $updatedCount++;
                    } else {
                        $errors = array_merge($errors, $result->getErrorMessages());
                    }
                }
                if ($updatedCount > 0) {
                    $messages[] = 'Массовая привязка выполнена. Обновлено кабинетов: ' . $updatedCount . '.';
                }
            }
        }
    }

    $nextCabinetId = cabinetEditorGetNextCabinetId($cabinetClass);
}

$search = trim((string)$request->getQuery('q'));
$cabinets = [];
$rows = $cabinetClass::getList([
    'select' => ['ID', 'UF_CABINET_ID', 'UF_NAME', 'UF_WORKPLACES', 'UF_ORG'],
    'order' => ['UF_NAME' => 'ASC', 'ID' => 'ASC'],
]);
while ($row = $rows->fetch()) {
    $row['UF_ORG'] = cabinetEditorNormalizeOrgIds($row['UF_ORG']);
    if ($search !== '') {
        $haystack = mb_strtolower((string)$row['UF_CABINET_ID'] . ' ' . (string)$row['UF_NAME']);
        if (mb_strpos($haystack, mb_strtolower($search)) === false) {
            continue;
        }
    }
    $cabinets[] = $row;
}

$APPLICATION->SetTitle('Редактор кабинетов');
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Редактор кабинетов</title>
    <style>
        body { margin: 0; padding: 24px; background: #f5f7fb; color: #1f2937; font: 14px/1.45 Arial, sans-serif; }
        h1 { margin: 0 0 18px; font-size: 26px; }
        h2 { margin: 0 0 14px; font-size: 19px; }
        a { color: #2563eb; }
        .panel { margin-bottom: 18px; padding: 18px; background: #fff; border: 1px solid #d9e2ef; border-radius: 12px; box-shadow: 0 2px 8px rgba(15, 23, 42, .05); }
        .grid { display: grid; grid-template-columns: minmax(150px, 220px) minmax(240px, 1fr) minmax(110px, 150px); gap: 12px; align-items: start; }
        .bulk-grid { display: grid; grid-template-columns: minmax(220px, 1fr) minmax(180px, 240px) auto; gap: 16px; align-items: end; }
        label.title { display: block; margin-bottom: 5px; color: #4b5563; font-weight: 700; }
        input[type="text"], input[type="number"], select { box-sizing: border-box; width: 100%; padding: 9px 10px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; }
        button, .button { display: inline-block; padding: 9px 14px; border: 0; border-radius: 8px; background: #2563eb; color: #fff; font-weight: 700; cursor: pointer; text-decoration: none; }
        button.secondary { background: #475569; }
        button.success { background: #16a34a; }
        table { width: 100%; border-collapse: collapse; background: #fff; }
        th, td { padding: 10px; border-bottom: 1px solid #e5e7eb; vertical-align: top; text-align: left; }
        th { position: sticky; top: 0; z-index: 1; background: #f8fafc; color: #475569; }
        tr:hover td { background: #f8fafc; }
        .row-form { display: contents; }
        .org-list { max-height: 190px; overflow: auto; padding: 8px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fbfdff; }
        .org-list label { display: block; margin: 0 0 6px; }
        .default-org { font-weight: 700; }
        .badge { display: inline-block; margin-left: 4px; padding: 2px 6px; border-radius: 999px; background: #dcfce7; color: #166534; font-size: 12px; }
        .muted { color: #64748b; font-size: 12px; }
        .messages, .errors { margin-bottom: 18px; padding: 12px 14px; border-radius: 10px; }
        .messages { background: #ecfdf5; border: 1px solid #bbf7d0; color: #166534; }
        .errors { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .toolbar { display: flex; gap: 10px; align-items: end; flex-wrap: wrap; }
        .toolbar .search { min-width: 280px; }
        .table-wrap { overflow: auto; border: 1px solid #d9e2ef; border-radius: 12px; }
        .actions { white-space: nowrap; }
        .hint { margin-top: 8px; color: #64748b; font-size: 12px; }
    </style>
    <script>
        function cabinetEditorToggleAll(source) {
            document.querySelectorAll('.cabinet-select').forEach(function (checkbox) {
                checkbox.checked = source.checked;
            });
        }
    </script>
</head>
<body>
    <h1>Редактор кабинетов</h1>

    <?php if (!empty($messages)): ?>
        <div class="messages"><?php foreach ($messages as $message): ?><div><?= cabinetEditorHtml((string)$message) ?></div><?php endforeach; ?></div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="errors"><?php foreach ($errors as $error): ?><div><?= cabinetEditorHtml((string)$error) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="panel">
        <h2>Добавить кабинет</h2>
        <form method="post">
            <?= bitrix_sessid_post() ?>
            <input type="hidden" name="action" value="add">
            <div class="grid">
                <div>
                    <label class="title" for="new_cabinet_id">ID кабинета</label>
                    <input id="new_cabinet_id" type="text" name="cabinet_id" value="<?= cabinetEditorHtml($nextCabinetId) ?>" required>
                    <div class="hint">Заполняется автоматически как максимум + 1, но поле можно изменить.</div>
                </div>
                <div>
                    <label class="title" for="new_name">Название кабинета</label>
                    <input id="new_name" type="text" name="name" placeholder="Например: Московский, 601" required>
                </div>
                <div>
                    <label class="title" for="new_workplaces">Количество мест</label>
                    <input id="new_workplaces" type="number" min="0" step="1" name="workplaces" value="0">
                </div>
            </div>
            <div style="margin-top:12px;">
                <label class="title">Юридические лица</label>
                <?= cabinetEditorRenderOrgCheckboxes($organizations, [DEFAULT_ORG_ELEMENT_ID], 'org_ids', 'new_org') ?>
                <div class="hint">Юр. лицо по умолчанию всегда выбрано и не может быть отвязано.</div>
            </div>
            <div style="margin-top:14px;"><button class="success" type="submit">Добавить кабинет</button></div>
        </form>
    </div>

    <div class="panel">
        <h2>Массовая привязка отмеченных кабинетов</h2>
        <form method="post" id="bulkForm">
            <?= bitrix_sessid_post() ?>
            <input type="hidden" name="action" value="bulk_assign">
            <div class="bulk-grid">
                <div>
                    <label class="title">Юридические лица для привязки</label>
                    <?= cabinetEditorRenderOrgCheckboxes($organizations, [DEFAULT_ORG_ELEMENT_ID], 'bulk_org_ids', 'bulk_org') ?>
                </div>
                <div>
                    <label class="title" for="bulk_mode">Режим</label>
                    <select id="bulk_mode" name="bulk_mode">
                        <option value="append">Добавить к текущим</option>
                        <option value="replace">Заменить текущие</option>
                    </select>
                    <div class="hint">При замене обязательное юр. лицо останется привязанным.</div>
                </div>
                <div><button class="secondary" type="submit">Применить к отмеченным</button></div>
            </div>
        </form>
    </div>

    <div class="panel">
        <div class="toolbar">
            <form method="get" class="toolbar">
                <div class="search">
                    <label class="title" for="q">Поиск по ID или названию</label>
                    <input id="q" type="text" name="q" value="<?= cabinetEditorHtml($search) ?>" placeholder="Введите часть названия или ID">
                </div>
                <button type="submit">Найти</button>
                <?php if ($search !== ''): ?><a class="button" href="<?= cabinetEditorHtml($request->getRequestedPage()) ?>">Сбросить</a><?php endif; ?>
            </form>
        </div>
        <p class="muted">Всего кабинетов в списке: <?= count($cabinets) ?>.</p>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th><input type="checkbox" onclick="cabinetEditorToggleAll(this)" title="Отметить все"></th>
                        <th>ID кабинета</th>
                        <th>Название</th>
                        <th>Мест</th>
                        <th>Юридические лица</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($cabinets)): ?>
                    <tr><td colspan="6">Кабинеты не найдены.</td></tr>
                <?php endif; ?>
                <?php foreach ($cabinets as $cabinet): ?>
                    <?php $rowId = (int)$cabinet['ID']; ?>
                    <tr>
                        <td>
                            <input form="bulkForm" class="cabinet-select" type="checkbox" name="selected_ids[]" value="<?= $rowId ?>">
                        </td>
                        <td>
                            <form method="post" id="rowForm<?= $rowId ?>">
                                <?= bitrix_sessid_post() ?>
                                <input type="hidden" name="action" value="save">
                                <input type="hidden" name="row_id" value="<?= $rowId ?>">
                                <input type="text" name="cabinet_id" value="<?= cabinetEditorHtml((string)$cabinet['UF_CABINET_ID']) ?>" required>
                            </form>
                        </td>
                        <td><input form="rowForm<?= $rowId ?>" type="text" name="name" value="<?= cabinetEditorHtml((string)$cabinet['UF_NAME']) ?>" required></td>
                        <td><input form="rowForm<?= $rowId ?>" type="number" min="0" step="1" name="workplaces" value="<?= (int)$cabinet['UF_WORKPLACES'] ?>"></td>
                        <td><?= cabinetEditorRenderOrgCheckboxes($organizations, $cabinet['UF_ORG'], 'org_ids', 'row_' . $rowId . '_org', 'rowForm' . $rowId) ?></td>
                        <td class="actions"><button form="rowForm<?= $rowId ?>" type="submit">Сохранить</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
