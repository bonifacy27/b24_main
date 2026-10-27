<?php
/**
 * /forms/issues/create_issue.php
 * Создание обращения в списке 409 и запуск шаблона БП 1379.
 * Текст HTML/text сохраняется как обычный текст, без исполнения HTML.
 */
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\UI\Extension;

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php';

const ISSUE_IBLOCK_ID = 409;
const ISSUE_TEMPLATE_ID = 1379;
const ISSUE_DOCUMENT_ENTITY = 'BizprocDocument';
const ISSUE_TOPIC_PROPERTY = 3202;
const ISSUE_TEXT_PROPERTY = 3203;
const ISSUE_FILES_PROPERTY = 3204;
const ISSUE_CRM_PROPERTY = 3205;
const ISSUE_MAX_FILES = 10;
const ISSUE_MAX_FILE_SIZE = 20 * 1024 * 1024;
const ISSUE_ALLOWED_EXTENSIONS = 'jpg,jpeg,png,gif,webp,pdf,txt,csv,doc,docx,xls,xlsx,ppt,pptx,zip';
const ISSUE_FORM_PATH = '/forms/issues/create_issue.php';

global $APPLICATION, $USER;
$APPLICATION->SetTitle('Новое обращение');

function issueEscape($value)
{
    return htmlspecialcharsbx((string)$value);
}

function issuePostString($request, $name)
{
    $value = $request->getPost($name);
    return is_string($value) ? trim($value) : '';
}

function issueStop($message)
{
    ShowError($message);
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
    exit;
}

if (!$USER->IsAuthorized()) {
    $APPLICATION->AuthForm('Для создания заявки необходимо авторизоваться.');
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
    exit;
}

foreach (['iblock', 'lists', 'bizproc', 'crm'] as $module) {
    if (!Loader::includeModule($module)) {
        issueStop('Не удалось подключить модуль: ' . $module);
    }
}

$documentType = ['lists', ISSUE_DOCUMENT_ENTITY, 'iblock_' . ISSUE_IBLOCK_ID];
// Создание через эту форму разрешено любому авторизованному пользователю.

// Не подставляем другой ENTITY: он должен совпадать с шаблоном БП.
$template = CBPWorkflowTemplateLoader::GetList(
    [],
    ['ID' => ISSUE_TEMPLATE_ID],
    false,
    false,
    ['ID', 'MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE']
)->Fetch();
if (
    !$template
    || $template['MODULE_ID'] !== $documentType[0]
    || $template['ENTITY'] !== $documentType[1]
    || $template['DOCUMENT_TYPE'] !== $documentType[2]
) {
    issueStop('Шаблон БП 1379 не найден либо не соответствует типу документа lists / BizprocDocument / iblock_409.');
}

$properties = [];
$result = CIBlockProperty::GetList([], ['IBLOCK_ID' => ISSUE_IBLOCK_ID]);
while ($property = $result->Fetch()) {
    $properties[(int)$property['ID']] = $property;
}
foreach ([
    ISSUE_TOPIC_PROPERTY => 'L',
    ISSUE_TEXT_PROPERTY => 'S',
    ISSUE_FILES_PROPERTY => 'F',
    ISSUE_CRM_PROPERTY => 'S',
] as $propertyId => $expectedType) {
    if (!isset($properties[$propertyId]) || $properties[$propertyId]['PROPERTY_TYPE'] !== $expectedType) {
        issueStop('Проверьте настройки свойства #' . $propertyId . '.');
    }
}
if (
    $properties[ISSUE_TEXT_PROPERTY]['USER_TYPE'] !== 'HTML'
    || $properties[ISSUE_FILES_PROPERTY]['MULTIPLE'] !== 'Y'
    || !in_array($properties[ISSUE_CRM_PROPERTY]['USER_TYPE'], ['CRM', 'S:CRM'], true)
) {
    issueStop('Проверьте типы свойств: 3203 — HTML/текст; 3204 — множественный файл; 3205 — привязка к CRM.');
}

$topics = [];
$result = CIBlockPropertyEnum::GetList(
    ['SORT' => 'ASC', 'VALUE' => 'ASC'],
    ['PROPERTY_ID' => ISSUE_TOPIC_PROPERTY]
);
while ($enum = $result->Fetch()) {
    $topics[(int)$enum['ID']] = $enum['VALUE'];
}

$request = Context::getCurrent()->getRequest();
$userId = (int)$USER->GetID();
$sessionKey = 'ISSUE_CREATE_' . $userId;
if (!isset($_SESSION[$sessionKey])) {
    $_SESSION[$sessionKey] = [];
}
foreach ($_SESSION[$sessionKey] as $key => $entry) {
    if (($entry['time'] ?? 0) < time() - 86400) {
        unset($_SESSION[$sessionKey][$key]);
    }
}

$errors = [];
$topic = '';
$text = '';
$crmValue = '';
$crmItem = null;
$outcome = null;
$resultToken = $request->getQuery('result');
if (is_string($resultToken)) {
    $entry = $_SESSION[$sessionKey][$resultToken] ?? null;
    if (is_array($entry) && !empty($entry['id'])) {
        $outcome = $entry;
    }
}

if ($request->isPost()) {
    $topic = issuePostString($request, 'topic');
    $text = issuePostString($request, 'issue_text');
    $crmValue = issuePostString($request, 'counterparty');
    $token = issuePostString($request, 'form_token');
    $entry = $_SESSION[$sessionKey][$token] ?? null;

    if (!check_bitrix_sessid()) {
        $errors[] = 'Сессия истекла. Обновите страницу и отправьте форму снова.';
    } elseif (!is_array($entry)) {
        $errors[] = 'Форма устарела. Обновите страницу.';
    } elseif (!empty($entry['id'])) {
        LocalRedirect(ISSUE_FORM_PATH . '?result=' . rawurlencode($token));
        exit;
    } elseif (!empty($entry['processing'])) {
        $errors[] = 'Эта форма уже отправлена. Проверьте список заявок перед повторным созданием.';
    }
    if (!isset($topics[$topic])) {
        $errors[] = 'Выберите тему обращения.';
    }
    if ($text === '') {
        $errors[] = 'Введите текст обращения.';
    }

    // Проверяем существование контрагента и права CRM на сервере.
    if ($crmValue !== '') {
        if (!preg_match('/^(C|CO)_([1-9][0-9]*)$/D', $crmValue, $match)) {
            $errors[] = 'Некорректный контрагент.';
        } else {
            $crmId = (int)$match[2];
            $isCompany = $match[1] === 'CO';
            $allowed = $isCompany
                ? CCrmCompany::CheckReadPermission($crmId)
                : CCrmContact::CheckReadPermission($crmId);
            $record = false;
            if ($allowed) {
                $record = $isCompany
                    ? CCrmCompany::GetByID($crmId)
                    : CCrmContact::GetByID($crmId);
            }
            if (!$record) {
                $errors[] = 'Контрагент не найден или недоступен.';
            } else {
                $title = $isCompany ? $record['TITLE'] : trim(
                    ($record['LAST_NAME'] ?? '') . ' '
                    . ($record['NAME'] ?? '') . ' '
                    . ($record['SECOND_NAME'] ?? '')
                );
                $crmItem = [
                    'id' => $crmId,
                    'entityId' => $isCompany ? 'company' : 'contact',
                    'title' => $title !== '' ? $title : 'Контакт #' . $crmId,
                ];
            }
        }
    }

    $fileValues = [];
    $uploads = $_FILES['attachments'] ?? null;
    if ($uploads !== null) {
        if (!is_array($uploads) || !isset($uploads['error']) || !is_array($uploads['error'])) {
            $errors[] = 'Некорректный формат вложений.';
        } else {
            $fileCount = 0;
            foreach ($uploads['error'] as $index => $uploadError) {
                if (!is_scalar($uploadError)) {
                    $errors[] = 'Некорректный формат вложения.';
                    continue;
                }
                $uploadError = (int)$uploadError;
                if ($uploadError === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                ++$fileCount;
                if ($uploadError !== UPLOAD_ERR_OK) {
                    $errors[] = 'Не удалось загрузить вложение. Код PHP: ' . $uploadError . '.';
                    continue;
                }
                $file = [];
                foreach (['name', 'type', 'tmp_name', 'size'] as $field) {
                    $value = isset($uploads[$field]) && is_array($uploads[$field])
                        ? ($uploads[$field][$index] ?? null) : null;
                    if (!is_scalar($value)) {
                        $errors[] = 'Некорректные данные вложения.';
                        continue 2;
                    }
                    $file[$field] = $value;
                }
                $file['size'] = (int)$file['size'];
                $file['error'] = UPLOAD_ERR_OK;
                if (!is_uploaded_file($file['tmp_name'])) {
                    $errors[] = 'Вложение не является загруженным файлом.';
                    continue;
                }
                $fileError = CFile::CheckFile($file, ISSUE_MAX_FILE_SIZE, false, ISSUE_ALLOWED_EXTENSIONS);
                if ($fileError !== '') {
                    $errors[] = $file['name'] . ': ' . $fileError;
                    continue;
                }
                $fileValues['n' . count($fileValues)] = ['VALUE' => $file, 'DESCRIPTION' => ''];
            }
            if ($fileCount > ISSUE_MAX_FILES) {
                $errors[] = 'Можно прикрепить не более ' . ISSUE_MAX_FILES . ' файлов.';
            }
        }
    }

    if (!$errors) {
        // Не закрываем сессию до завершения записи и запуска БП.
        // Токен защищает повторный POST в пределах текущей сессии.
        $_SESSION[$sessionKey][$token]['processing'] = true;
        $element = new CIBlockElement();
        $propertyValues = [
            ISSUE_TOPIC_PROPERTY => (int)$topic,
            ISSUE_TEXT_PROPERTY => ['VALUE' => ['TEXT' => $text, 'TYPE' => 'text']],
        ];
        if ($fileValues) {
            $propertyValues[ISSUE_FILES_PROPERTY] = $fileValues;
        }
        if ($crmValue !== '') {
            $propertyValues[ISSUE_CRM_PROPERTY] = $properties[ISSUE_CRM_PROPERTY]['MULTIPLE'] === 'Y'
                ? [$crmValue] : $crmValue;
        }

        $createdId = 0;
        try {
            $createdId = (int)$element->Add([
                'IBLOCK_ID' => ISSUE_IBLOCK_ID,
                'ACTIVE' => 'Y',
                'NAME' => 'Обращение: ' . $topics[$topic],
                'CREATED_BY' => $userId,
                'MODIFIED_BY' => $userId,
                'PROPERTY_VALUES' => $propertyValues,
            ]);
            if (!$createdId) {
                $_SESSION[$sessionKey][$token]['processing'] = false;
                $errors[] = 'Ошибка сохранения: ' . $element->LAST_ERROR;
            }
        } catch (\Throwable $exception) {
            // При неопределённом результате не разрешаем повторить тот же POST.
            $errors[] = 'Не удалось завершить сохранение. Проверьте список заявок перед повторной отправкой.';
            error_log('Issue creation failed: ' . $exception->getMessage());
        }

        if ($createdId) {
            $_SESSION[$sessionKey][$token]['id'] = $createdId;
            $_SESSION[$sessionKey][$token]['workflow'] = '';
            $_SESSION[$sessionKey][$token]['workflow_error'] =
                'Результат запуска БП не подтверждён. Проверьте журнал бизнес-процессов заявки.';
            $workflowErrors = [];
            try {
                $workflowId = CBPDocument::StartWorkflow(
                    ISSUE_TEMPLATE_ID,
                    ['lists', ISSUE_DOCUMENT_ENTITY, $createdId],
                    [],
                    $workflowErrors
                );
                if ($workflowId) {
                    $_SESSION[$sessionKey][$token]['workflow'] = (string)$workflowId;
                    $_SESSION[$sessionKey][$token]['workflow_error'] = '';
                } else {
                    $messages = [];
                    foreach ($workflowErrors as $workflowError) {
                        $messages[] = is_array($workflowError)
                            ? (string)($workflowError['message'] ?? $workflowError['code'] ?? 'Неизвестная ошибка БП')
                            : (string)$workflowError;
                    }
                    $_SESSION[$sessionKey][$token]['workflow_error'] = $messages
                        ? implode('; ', $messages) : 'БП не вернул идентификатор запуска.';
                }
            } catch (\Throwable $exception) {
                error_log('Issue #' . $createdId . ', workflow start failed: ' . $exception->getMessage());
            }
            LocalRedirect(ISSUE_FORM_PATH . '?result=' . rawurlencode($token));
            exit;
        }
    }
}

$formToken = bin2hex(random_bytes(24));
$_SESSION[$sessionKey][$formToken] = ['time' => time(), 'processing' => false];
$employeeName = trim((string)$USER->GetFullName());
if ($employeeName === '') {
    $employeeName = (string)$USER->GetLogin();
}

Extension::load(['ui.forms', 'ui.buttons', 'ui.alerts', 'ui.entity-selector', 'crm.entity-selector']);
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
?>
<style>
.issue-form {max-width:900px;box-sizing:border-box;padding:24px;background:var(--ui-color-background-primary,#fff);border-radius:var(--ui-border-radius-md,8px)}
.issue-form .issue-field {margin-bottom:20px}
.issue-form .issue-note {color:var(--ui-color-text-secondary,#828b95);font-size:13px}
.issue-form .issue-crm-controls {display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.issue-form .issue-crm-controls [hidden] {display:none}
.issue-form .issue-text {min-height:180px;height:auto}
@media (max-width:600px) {.issue-form {padding:16px}.issue-form .issue-submit {width:100%;height:auto;min-height:39px;white-space:normal}}
</style>
<div class="issue-form">
    <?php if ($outcome): ?>
        <?php $issueUrl = '/bizproc/processes/' . ISSUE_IBLOCK_ID . '/element/0/' . (int)$outcome['id'] . '/'; ?>
        <div class="ui-alert <?= !empty($outcome['workflow']) ? 'ui-alert-success' : 'ui-alert-warning' ?>" role="status"><div class="ui-alert-message">
            Заявка <a href="<?= issueEscape($issueUrl) ?>">#<?= (int)$outcome['id'] ?></a> сохранена.
            <?php if (!empty($outcome['workflow'])): ?>
                Бизнес-процесс запущен.
            <?php else: ?>
                <p>Запуск бизнес-процесса не подтверждён: <?= issueEscape($outcome['workflow_error']) ?></p>
                <p>Не создавайте заявку повторно. Проверьте БП сохранённой заявки.</p>
            <?php endif; ?>
        </div></div>
        <a class="ui-btn ui-btn-light-border" href="<?= issueEscape(ISSUE_FORM_PATH) ?>">Создать новое обращение</a>
    <?php else: ?>
        <?php if ($errors): ?>
            <div class="ui-alert ui-alert-danger" role="alert"><div class="ui-alert-message">
                <ul><?php foreach ($errors as $error): ?><li><?= issueEscape($error) ?></li><?php endforeach; ?></ul>
                <p>При повторной отправке выберите вложения заново.</p>
            </div></div>
        <?php endif; ?>
        <form method="post" action="<?= issueEscape(ISSUE_FORM_PATH) ?>" enctype="multipart/form-data" id="issue-create-form">
            <?= bitrix_sessid_post() ?>
            <input type="hidden" name="form_token" value="<?= issueEscape($formToken) ?>">
            <div class="issue-field">
                <label class="ui-ctl-label-text">Автор обращения</label>
                <div><?= issueEscape($employeeName) ?></div>
            </div>
            <div class="issue-field">
                <label class="ui-ctl-label-text" for="issue-topic">Тема обращения *</label>
                <div class="ui-ctl ui-ctl-after-icon ui-ctl-dropdown ui-ctl-w100">
                <div class="ui-ctl-after ui-ctl-icon-angle"></div>
                <select class="ui-ctl-element" name="topic" id="issue-topic" required>
                    <option value="">— выберите —</option>
                    <?php foreach ($topics as $id => $name): ?>
                        <option value="<?= (int)$id ?>" <?= (string)$topic === (string)$id ? 'selected' : '' ?>><?= issueEscape($name) ?></option>
                    <?php endforeach; ?>
                </select>
                </div>
            </div>
            <div class="issue-field">
                <label class="ui-ctl-label-text" for="issue-text">Текст обращения *</label>
                <div class="ui-ctl ui-ctl-textarea ui-ctl-w100 issue-text">
                <textarea class="ui-ctl-element" name="issue_text" id="issue-text" rows="8" required><?= issueEscape($text) ?></textarea>
                </div>
            </div>
            <div class="issue-field">
                <label class="ui-ctl-label-text" for="issue-files">Вложения</label>
                <input type="file" name="attachments[]" id="issue-files" multiple>
                <p class="issue-note">До 10 файлов, каждый не более 20 МБ. Документы, изображения и ZIP. Также действуют ограничения загрузки сервера.</p>
            </div>
            <div class="issue-field">
                <label class="ui-ctl-label-text">Контрагент</label>
                <input type="hidden" name="counterparty" id="issue-crm-value" value="<?= issueEscape($crmItem ? $crmValue : '') ?>">
                <div class="issue-crm-controls">
                <span id="issue-crm-title"><?= issueEscape($crmItem ? $crmItem['title'] : 'Не выбран') ?></span>
                <button class="ui-btn ui-btn-light-border ui-btn-sm" type="button" id="issue-crm-select">Выбрать контакт или компанию</button>
                <button class="ui-btn ui-btn-link ui-btn-sm" type="button" id="issue-crm-clear" <?= $crmItem ? '' : 'hidden' ?>>Убрать</button>
                </div>
                <div id="issue-crm-error" class="issue-note" role="status"></div>
            </div>
            <button class="ui-btn ui-btn-success issue-submit" type="submit" id="issue-submit">Создать заявку и запустить БП</button>
        </form>
        <script>
        BX.ready(function () {
            var input = document.getElementById('issue-crm-value');
            var title = document.getElementById('issue-crm-title');
            var select = document.getElementById('issue-crm-select');
            var clear = document.getElementById('issue-crm-clear');
            var error = document.getElementById('issue-crm-error');
            var initialItem = <?= json_encode($crmItem, $jsonFlags) ?>;
            if (BX.UI && BX.UI.EntitySelector && BX.UI.EntitySelector.Dialog) {
                var dialog = new BX.UI.EntitySelector.Dialog({
                    targetNode: select,
                    context: 'ISSUE_CREATE_COUNTERPARTY',
                    multiple: false,
                    dropdownMode: true,
                    enableSearch: true,
                    entities: [{id: 'contact'}, {id: 'company'}],
                    items: initialItem ? [initialItem] : [],
                    preselectedItems: initialItem ? [[initialItem.entityId, initialItem.id]] : [],
                    events: {
                        'Item:onSelect': function (event) {
                            var item = event.getData().item;
                            var entity = item.getEntityId();
                            if (entity !== 'contact' && entity !== 'company') { return; }
                            input.value = (entity === 'company' ? 'CO_' : 'C_') + item.getId();
                            title.textContent = item.getTitle();
                            clear.hidden = false;
                        },
                        'Item:onDeselect': function () {
                            if (dialog.getSelectedItems().length === 0) {
                                input.value = '';
                                title.textContent = 'Не выбран';
                                clear.hidden = true;
                            }
                        }
                    }
                });
                select.addEventListener('click', function () { dialog.show(); });
                clear.addEventListener('click', function () {
                    dialog.getSelectedItems().slice().forEach(function (item) { item.deselect(); });
                    input.value = '';
                    title.textContent = 'Не выбран';
                    clear.hidden = true;
                });
            } else {
                select.disabled = true;
                error.textContent = 'Выбор контрагента временно недоступен.';
            }
            document.getElementById('issue-create-form').addEventListener('submit', function () {
                var button = document.getElementById('issue-submit');
                button.disabled = true;
                button.textContent = 'Создание заявки…';
            });
        });
        </script>
    <?php endif; ?>
</div>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'; ?>
