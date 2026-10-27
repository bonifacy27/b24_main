<?php
/**
 * /forms/issues/create_issue.php
 * Создание обращения в списке 409 и запуск шаблона БП 1379.
 * Простой HTML, поиск CRM в административном контексте, возврат в список.
 */
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\UI\Extension;

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

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
const ISSUE_LIST_PATH = '/forms/issues/list.php';

global $APPLICATION, $USER;


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
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php';
    ShowError($message);
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
    exit;
}

function issueJson(array $data, $status = 200)
{
    global $APPLICATION;
    $APPLICATION->RestartBuffer();
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function issueSanitizeHtml($html)
{
    $sanitizer = new CBXSanitizer();
    $sanitizer->SetLevel(CBXSanitizer::SECURE_LEVEL_HIGH);
    $sanitizer->AddTags([
        'p' => [], 'br' => [], 'b' => [], 'strong' => [],
        'i' => [], 'em' => [], 'u' => [], 'ul' => [], 'ol' => [],
        'li' => [], 'a' => ['href', 'title'],
    ]);
    return $sanitizer->SanitizeHtml($html);
}

function issueHasText($html)
{
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return preg_match('/[^\s\x{00A0}\x{200B}\x{FEFF}]/u', $text) === 1;
}

/* Выбираем реального активного администратора, не меняя авторизацию посетителя. */
function issueCrmPermissions()
{
    static $permissions = null;
    if ($permissions !== null) {
        return $permissions;
    }
    $by = 'id';
    $order = 'asc';
    $users = CUser::GetList($by, $order, ['ACTIVE' => 'Y', 'GROUPS_ID' => [1]], ['FIELDS' => ['ID']]);
    while ($user = $users->Fetch()) {
        $id = (int)$user['ID'];
        if (in_array(1, array_map('intval', CUser::GetUserGroup($id)), true)) {
            $permissions = CCrmPerms::GetUserPermissions($id);
            return $permissions;
        }
    }
    throw new RuntimeException('Не найден активный администратор для выбора контрагента.');
}

function issueCrmItem($entity, $id, $permissions)
{
    $company = $entity === 'company';
    $allowed = $company
        ? CCrmCompany::CheckReadPermission($id, $permissions)
        : CCrmContact::CheckReadPermission($id, $permissions);
    if (!$allowed) {
        return null;
    }
    // Доступ проверен для администратора; текущая сессия пользователя не меняется.
    $record = $company ? CCrmCompany::GetByID($id, false) : CCrmContact::GetByID($id, false);
    if (!$record) {
        return null;
    }
    $title = $company ? (string)$record['TITLE'] : trim(
        ($record['LAST_NAME'] ?? '') . ' ' . ($record['NAME'] ?? '') . ' ' . ($record['SECOND_NAME'] ?? '')
    );
    return ['id' => (int)$id, 'entityId' => $entity, 'title' => $title !== '' ? $title : '#' . $id];
}

$request = Context::getCurrent()->getRequest();
$isCrmSearch = $request->isPost() && issuePostString($request, 'action') === 'crm_search';
if (!$USER->IsAuthorized()) {
    if ($isCrmSearch) {
        issueJson(['error' => 'Необходимо авторизоваться.'], 401);
    }
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php';
    $APPLICATION->AuthForm('Для создания заявки необходимо авторизоваться.');
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
    exit;
}

foreach (['iblock', 'lists', 'bizproc', 'crm', 'fileman'] as $module) {
    if (!Loader::includeModule($module)) {
        if ($isCrmSearch) {
            issueJson(['error' => 'Не удалось подключить модуль: ' . $module], 500);
        }
        issueStop('Не удалось подключить модуль: ' . $module);
    }
}

/* AJAX поиска обслуживается этим же файлом до вывода шаблона страницы. */
if ($isCrmSearch) {
    if (!check_bitrix_sessid()) {
        issueJson(['error' => 'Сессия истекла. Обновите страницу.'], 403);
    }
    $query = issuePostString($request, 'query');
    $entity = issuePostString($request, 'entity');
    if (!in_array($entity, ['contact', 'company'], true)) {
        issueJson(['error' => 'Некорректный тип контрагента.'], 400);
    }
    if (mb_strlen($query) > 100) {
        issueJson(['error' => 'Запрос должен быть не длиннее 100 символов.'], 400);
    }
    try {
        $permissions = issueCrmPermissions();
        // Явно разрешённый административный поиск только по компаниям и контактам.
        // Не используем провайдеры селектора, выполняющиеся с правами посетителя.
        $filter = ['CHECK_PERMISSIONS' => 'N'];
        $words = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($words as $word) {
            $word = str_replace(['%', '_'], ['\\%', '\\_'], $word);
            $filter[] = $entity === 'company'
                ? ['%TITLE' => $word]
                : ['LOGIC' => 'OR', '%NAME' => $word, '%LAST_NAME' => $word, '%SECOND_NAME' => $word];
        }
        $rows = $entity === 'company'
            ? CCrmCompany::GetListEx(['TITLE' => 'ASC', 'ID' => 'ASC'], $filter, false, ['nTopCount' => 20], ['ID'])
            : CCrmContact::GetListEx(['LAST_NAME' => 'ASC', 'NAME' => 'ASC', 'ID' => 'ASC'], $filter, false, ['nTopCount' => 20], ['ID']);
        $items = [];
        while ($row = $rows->Fetch()) {
            $item = issueCrmItem($entity, (int)$row['ID'], $permissions);
            if ($item) {
                $items[] = $item;
            }
        }
        issueJson(['items' => $items]);
    } catch (\Throwable $exception) {
        error_log('Issue CRM search failed: ' . $exception->getMessage());
        issueJson(['error' => 'Не удалось загрузить контрагентов. Проверьте настройку администратора CRM.'], 500);
    }
}

// Создание через эту форму разрешено любому авторизованному пользователю.
// Шаблон запускается напрямую, как в работающей форме подбора персонала.
// Ошибки запуска возвращает StartWorkflow; GetList не используется как проверка.

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
// USER_TYPE может отличаться между версиями и настройками Битрикс.
// Основные типы L/S/F/S проверены выше; формат записи выбирается по метаданным.
$textIsHtml = in_array(
    strtoupper((string)($properties[ISSUE_TEXT_PROPERTY]['USER_TYPE'] ?? '')),
    ['HTML', 'S:HTML'],
    true
);
$filesAreMultiple = $properties[ISSUE_FILES_PROPERTY]['MULTIPLE'] === 'Y';

$topics = [];
$result = CIBlockPropertyEnum::GetList(
    ['SORT' => 'ASC', 'VALUE' => 'ASC'],
    ['PROPERTY_ID' => ISSUE_TOPIC_PROPERTY]
);
while ($enum = $result->Fetch()) {
    $topics[(int)$enum['ID']] = $enum['VALUE'];
}

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
        if (!empty($entry['workflow'])) {
            LocalRedirect(ISSUE_LIST_PATH);
            exit;
        }
        $outcome = $entry;
    }
}

if ($request->isPost()) {
    $topic = issuePostString($request, 'topic');
    $text = issueSanitizeHtml(issuePostString($request, 'issue_text'));
    $crmValue = issuePostString($request, 'counterparty');
    $token = issuePostString($request, 'form_token');
    $entry = $_SESSION[$sessionKey][$token] ?? null;

    if (!check_bitrix_sessid()) {
        $errors[] = 'Сессия истекла. Обновите страницу и отправьте форму снова.';
    } elseif (!is_array($entry)) {
        $errors[] = 'Форма устарела. Обновите страницу.';
    } elseif (!empty($entry['id'])) {
        LocalRedirect(!empty($entry['workflow']) ? ISSUE_LIST_PATH : ISSUE_FORM_PATH . '?result=' . rawurlencode($token));
        exit;
    } elseif (!empty($entry['processing'])) {
        $errors[] = 'Эта форма уже отправлена. Проверьте список заявок перед повторным созданием.';
    }
    if (!isset($topics[$topic])) {
        $errors[] = 'Выберите тему обращения.';
    }
    if (!issueHasText($text)) {
        $errors[] = 'Введите текст обращения.';
    }

    // Повторная проверка выбранной сущности в том же административном контексте.
    if ($crmValue !== '') {
        if (!preg_match('/^(C|CO)_([1-9][0-9]*)$/D', $crmValue, $match)) {
            $errors[] = 'Некорректный контрагент.';
        } else {
            try {
                $crmItem = issueCrmItem($match[1] === 'CO' ? 'company' : 'contact', (int)$match[2], issueCrmPermissions());
                if (!$crmItem) {
                    $errors[] = 'Контрагент не найден.';
                }
            } catch (\Throwable $exception) {
                $errors[] = 'Не удалось проверить контрагента. Проверьте настройку администратора CRM.';
                error_log('Issue CRM validation failed: ' . $exception->getMessage());
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
            if (!$filesAreMultiple && $fileCount > 1) {
                $errors[] = 'Свойство «Вложение» допускает один файл. Для нескольких включите множественность свойства 3204.';
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
        $textPropertyValue = $textIsHtml
            ? ['VALUE' => ['TEXT' => $text, 'TYPE' => 'html']]
            : $text;
        $propertyValues = [
            ISSUE_TOPIC_PROPERTY => (int)$topic,
            ISSUE_TEXT_PROPERTY => $properties[ISSUE_TEXT_PROPERTY]['MULTIPLE'] === 'Y'
                ? [$textPropertyValue] : $textPropertyValue,
        ];
        if ($fileValues) {
            $propertyValues[ISSUE_FILES_PROPERTY] = $filesAreMultiple
                ? $fileValues : reset($fileValues);
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
                    ['TargetUser' => 'user_' . $userId],
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
            LocalRedirect(!empty($_SESSION[$sessionKey][$token]['workflow'])
                ? ISSUE_LIST_PATH : ISSUE_FORM_PATH . '?result=' . rawurlencode($token));
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

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php';
$APPLICATION->SetTitle('Новое обращение');
Extension::load(['ui.forms', 'ui.buttons', 'ui.alerts']);
?>
<style>
.issue-form {max-width:900px;box-sizing:border-box;padding:24px;background:var(--ui-color-background-primary,#fff);border-radius:var(--ui-border-radius-md,8px)}
.issue-form .issue-field {margin-bottom:20px}
.issue-form .issue-note {color:var(--ui-color-text-secondary,#828b95);font-size:13px}
.issue-form .issue-crm-controls {display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.issue-form .issue-crm-controls [hidden] {display:none}
.issue-form .issue-crm-panel {margin-top:12px;padding:16px;border:1px solid #dfe3e6;border-radius:6px}
.issue-form .issue-crm-search {display:flex;gap:8px;flex-wrap:wrap}
.issue-form .issue-crm-search .ui-ctl {flex:1;min-width:180px}
.issue-form .issue-crm-results {display:grid;gap:4px;margin-top:12px;max-height:280px;overflow:auto}
.issue-form .issue-crm-result {text-align:left;border:0;padding:10px;background:transparent;color:inherit;cursor:pointer;font:inherit}
.issue-form .issue-crm-result:hover,.issue-form .issue-crm-result:focus {background:#eef6ff}
.issue-form [hidden] {display:none!important}
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
                <label class="ui-ctl-label-text" for="issue-text-editor">Текст обращения *</label>
                <?php
                $editor = new CLightHTMLEditor();
                $editor->Show([
                    'id' => 'issue-text-editor',
                    'jsObjName' => 'issueTextEditor',
                    'inputName' => 'issue_text',
                    'content' => $text,
                    'width' => '100%',
                    'height' => '220',
                    'bUseFileDialogs' => false,
                    'bFloatingToolbar' => false,
                    'bArisingToolbar' => false,
                    'bAutoResize' => true,
                    'BBCode' => false,
                    'controls' => [
                        'Bold', 'Italic', 'Underline', 'RemoveFormat',
                        'InsertOrderedList', 'InsertUnorderedList', 'CreateLink', 'DeleteLink',
                    ],
                ]);
                ?>
                <div id="issue-text-error" class="issue-note" role="alert"></div>
            </div>
            <div class="issue-field">
                <label class="ui-ctl-label-text" for="issue-files">Вложения</label>
                <input type="file" name="attachments[]" id="issue-files" <?= $filesAreMultiple ? 'multiple' : '' ?>>
                <p class="issue-note"><?= $filesAreMultiple ? 'До 10 файлов' : 'Один файл' ?>, каждый не более 20 МБ. Документы, изображения и ZIP. Также действуют ограничения загрузки сервера.</p>
            </div>
            <div class="issue-field">
                <label class="ui-ctl-label-text">Контрагент</label>
                <input type="hidden" name="counterparty" id="issue-crm-value" value="<?= issueEscape($crmItem ? $crmValue : '') ?>">
                <div class="issue-crm-controls">
                <span id="issue-crm-title"><?= issueEscape($crmItem ? $crmItem['title'] : 'Не выбран') ?></span>
                <button class="ui-btn ui-btn-light-border ui-btn-sm" type="button" id="issue-crm-select">Выбрать контакт или компанию</button>
                <button class="ui-btn ui-btn-link ui-btn-sm" type="button" id="issue-crm-clear" <?= $crmItem ? '' : 'hidden' ?>>Убрать</button>
                </div>
                <div id="issue-crm-panel" class="issue-crm-panel" hidden>
                    <div class="issue-crm-search">
                        <div class="ui-ctl ui-ctl-after-icon ui-ctl-dropdown">
                            <div class="ui-ctl-after ui-ctl-icon-angle"></div>
                            <select class="ui-ctl-element" id="issue-crm-entity" aria-label="Тип контрагента">
                                <option value="contact">Контакты</option>
                                <option value="company">Компании</option>
                            </select>
                        </div>
                        <div class="ui-ctl ui-ctl-textbox">
                            <input class="ui-ctl-element" id="issue-crm-query" type="search" maxlength="100" placeholder="Введите название или ФИО" aria-label="Поиск контрагента">
                        </div>
                    </div>
                    <div id="issue-crm-results" class="issue-crm-results" aria-label="Результаты поиска"></div>
                </div>
                <div id="issue-crm-error" class="issue-note" role="status"></div>
            </div>
            <a class="ui-btn ui-btn-light-border" href="<?= issueEscape(ISSUE_LIST_PATH) ?>">К списку заявок</a>
            <button class="ui-btn ui-btn-success issue-submit" type="submit" id="issue-submit">Создать заявку и запустить БП</button>
        </form>
        <script>
        BX.ready(function () {
            var input = document.getElementById('issue-crm-value');
            var title = document.getElementById('issue-crm-title');
            var select = document.getElementById('issue-crm-select');
            var clear = document.getElementById('issue-crm-clear');
            var error = document.getElementById('issue-crm-error');
            var panel = document.getElementById('issue-crm-panel');
            var query = document.getElementById('issue-crm-query');
            var entity = document.getElementById('issue-crm-entity');
            var results = document.getElementById('issue-crm-results');
            var form = document.getElementById('issue-create-form');
            var timer;
            var requestNumber = 0;

            function searchCrm() {
                var number = ++requestNumber;
                results.replaceChildren();
                error.textContent = 'Поиск…';
                var body = new URLSearchParams({
                    action: 'crm_search',
                    sessid: BX.bitrix_sessid(),
                    query: query.value.trim(),
                    entity: entity.value
                });
                fetch(form.action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: body,
                    headers: {'Accept': 'application/json'}
                }).then(function (response) {
                    return response.json().then(function (data) {
                        if (!response.ok || data.error) {
                            throw new Error(data.error || 'Не удалось выполнить поиск.');
                        }
                        return data;
                    });
                }).then(function (data) {
                    if (number !== requestNumber) { return; }
                    error.textContent = data.items.length
                        ? 'Показано до 20 результатов. Уточните запрос при необходимости.'
                        : 'Ничего не найдено.';
                    data.items.forEach(function (item) {
                        if (item.entityId !== 'company' && item.entityId !== 'contact') { return; }
                        var button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'issue-crm-result';
                        button.textContent = item.title + ' (#' + item.id + ')';
                        button.addEventListener('click', function () {
                            input.value = (item.entityId === 'company' ? 'CO_' : 'C_') + item.id;
                            title.textContent = item.title;
                            clear.hidden = false;
                            panel.hidden = true;
                            error.textContent = '';
                            ++requestNumber;
                        });
                        results.appendChild(button);
                    });
                }).catch(function (exception) {
                    if (number === requestNumber) {
                        error.textContent = exception.message || 'Ошибка поиска контрагента.';
                    }
                });
            }
            select.addEventListener('click', function () {
                panel.hidden = !panel.hidden;
                if (!panel.hidden) {
                    query.focus();
                    searchCrm();
                } else {
                    ++requestNumber;
                    error.textContent = '';
                }
            });
            query.addEventListener('input', function () {
                clearTimeout(timer);
                ++requestNumber;
                timer = setTimeout(searchCrm, 300);
            });
            query.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    clearTimeout(timer);
                    searchCrm();
                }
            });
            entity.addEventListener('change', function () {
                clearTimeout(timer);
                searchCrm();
            });
            clear.addEventListener('click', function () {
                input.value = '';
                title.textContent = 'Не выбран';
                clear.hidden = true;
            });
            form.addEventListener('submit', function (event) {
                var editor = window.issueTextEditor;
                var textError = document.getElementById('issue-text-error');
                if (!editor || typeof editor.GetContent !== 'function') {
                    event.preventDefault();
                    textError.textContent = 'Редактор ещё не готов. Повторите отправку.';
                    return;
                }
                var html = editor.GetContent();
                var plain = document.createElement('div');
                plain.innerHTML = html;
                if (!plain.textContent.replace(/[\s\u00a0\u200b\ufeff]/g, '')) {
                    event.preventDefault();
                    textError.textContent = 'Введите текст обращения.';
                    return;
                }
                var field = form.querySelector('[name="issue_text"]');
                if (!field) {
                    field = document.createElement('input');
                    field.type = 'hidden';
                    field.name = 'issue_text';
                    form.appendChild(field);
                }
                field.value = html;
                textError.textContent = '';
                var button = document.getElementById('issue-submit');
                button.disabled = true;
                button.textContent = 'Создание заявки…';
            });
        });
        </script>
    <?php endif; ?>
</div>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'; ?>
