/**
 * Создание заявки Jira Service Management на загрузку МЧД из элемента списка Bitrix24.
 *
 * Источник данных:
 * - инфоблок/список: 310
 * - элемент: текущий элемент бизнес-процесса; если ID не удалось определить, используется 3616774
 * - ФИО: PROPERTY_1857, свойство ID 1857
 * - файлы: PROPERTY_1874, свойство ID 1874 (множественное поле типа «Файл»)
 *
 * Скрипт рассчитан на выполнение в PHP-коде бизнес-процесса Bitrix24.
 */

$rootActivity = $this->GetRootActivity();

$responsible_login = (string)$rootActivity->GetVariable('var_Initiator_Login');
$jira_pass = (string)$rootActivity->GetVariable('var_JiraPass');
$fallbackElementId  = (string)$rootActivity->GetVariable('var_ID');
$organisation  = (string)$rootActivity->GetVariable('var_Org');

$iblockId = 310;
$templateIblockId = 385;
$templateElementId = 3616785; // ID элемента справочника с шаблоном "Загрузка МЧД".

$jiraBaseUrl = 'https://jira.tricolor.tv';
$jiraUsername = 'jiraService';

$logMessage = function ($message) {
    $this->WriteToTrackingService((string)$message);
};

$fail = function ($message) use ($logMessage) {
    $logMessage('Ошибка: ' . $message);
    throw new Exception($message);
};



if (!function_exists('resolveWorkflowElementId')) {
    function resolveWorkflowElementId($rootActivity, $fallbackElementId)
{
    if (!is_object($rootActivity) || !method_exists($rootActivity, 'GetDocumentId')) {
        return (int)$fallbackElementId;
    }

    $documentId = $rootActivity->GetDocumentId();

    if (is_array($documentId)) {
        for ($i = count($documentId) - 1; $i >= 0; $i--) {
            if (preg_match('/(\d+)$/', (string)$documentId[$i], $matches)) {
                return (int)$matches[1];
            }
        }
    }

    if (is_scalar($documentId) && preg_match('/(\d+)$/', (string)$documentId, $matches)) {
        return (int)$matches[1];
    }

    return (int)$fallbackElementId;
    }
}

if (!function_exists('interpolateTemplate')) {
    function interpolateTemplate($value, array $context)
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = interpolateTemplate($item, $context);
            }

            return $result;
        }

        if (!is_string($value)) {
            return $value;
        }

        return preg_replace_callback('/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/', static function ($matches) use ($context) {
            $key = $matches[1];

            return array_key_exists($key, $context) ? (string)$context[$key] : $matches[0];
        }, $value);
    }
}

if (!function_exists('normalizeJiraOrganizationName')) {
    function normalizeJiraOrganizationName($value)
    {
        if (!is_string($value)) {
            return $value;
        }

        $normalizedValue = trim(preg_replace('/\s+/u', ' ', $value));
        $quoteInsensitiveValue = strtr($normalizedValue, [
            '«' => '"',
            '»' => '"',
            '„' => '"',
            '“' => '"',
            '”' => '"',
        ]);

        $jiraOrganizationValues = [
            'НАО "Национальная спутниковая компания"' => 'НАО “Национальная спутниковая компания”',
            'АО "Триколор Медиахолдинг"' => 'АО "Триколор Медиахолдинг"',
            'ООО "Торговые технологии"' => 'ООО "Торговые технологии"',
            'Контактный центр' => 'Контактный центр',
            'Контрагент' => 'Контрагент',
            'ООО "Центр медицинских технологий"' => 'ООО "Центр медицинских технологий"',
            'ООО "Семейный магазин"' => 'ООО "Семейный магазин"',
            'ООО "ТМХ-ИНВЕСТ"' => 'ООО "ТМХ-ИНВЕСТ"',
            'АО "Медиа"' => 'АО "Медиа"',
            'АО "Технологии будущего"' => 'АО "Технологии будущего"',
            'АО "Ультра Ру"' => 'АО "Ультра Ру"',
            'ООО "Цифровое семейство"' => 'ООО "Цифровое семейство"',
            'АО "Первый ТВЧ"' => 'АО "Первый ТВЧ"',
            'ООО "Спутник Трейд"' => 'ООО "Спутник Трейд"',
            'Не установлено' => 'Не установлено',
        ];

        return $jiraOrganizationValues[$quoteInsensitiveValue] ?? $normalizedValue;
    }
}

if (!function_exists('normalizeJiraOrganizationField')) {
    function normalizeJiraOrganizationField(array $data, callable $logMessage)
    {
        $fieldId = 'customfield_13727';
        if (!isset($data['requestFieldValues'][$fieldId])) {
            return $data;
        }

        $fieldValue = $data['requestFieldValues'][$fieldId];
        if (is_array($fieldValue) && array_key_exists('value', $fieldValue)) {
            $normalizedValue = normalizeJiraOrganizationName($fieldValue['value']);
            if ($normalizedValue !== $fieldValue['value']) {
                $logMessage('Поле Организация нормализовано для Jira: ' . $fieldValue['value'] . ' -> ' . $normalizedValue);
                $data['requestFieldValues'][$fieldId]['value'] = $normalizedValue;
            }
        } elseif (is_string($fieldValue)) {
            $normalizedValue = normalizeJiraOrganizationName($fieldValue);
            if ($normalizedValue !== $fieldValue) {
                $logMessage('Поле Организация нормализовано для Jira: ' . $fieldValue . ' -> ' . $normalizedValue);
                $data['requestFieldValues'][$fieldId] = $normalizedValue;
            }
        }

        return $data;
    }
}

if (!function_exists('getIblockPropertyValues')) {
    function getIblockPropertyValues($iblockId, $elementId, $property)
{
    $values = [];
    $filter = is_numeric($property) ? ['ID' => (int)$property] : ['CODE' => (string)$property];
    $propertyResult = CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc'], $filter);

    while ($propertyRow = $propertyResult->Fetch()) {
        $value = $propertyRow['VALUE'];
        if (is_array($value)) {
            if (isset($value['TEXT'])) {
                $value = $value['TEXT'];
            } elseif (isset($value['VALUE'])) {
                $value = $value['VALUE'];
            }
        }

        if ($value !== null && $value !== '') {
            $values[] = $value;
        }
    }

    return $values;
    }
}

if (!function_exists('jiraRequest')) {
    function jiraRequest($url, $username, $password, array $curlOptions, callable $logMessage)
{
    $ch = curl_init();
    curl_setopt_array($ch, $curlOptions + [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => [],
    ]);

    $result = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($result === false) {
        $logMessage('Ошибка запроса к Jira: ' . $curlError);
        throw new Exception('Ошибка запроса к Jira: ' . $curlError);
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $logMessage('Jira вернула HTTP ' . $httpCode . ': ' . $result);
        throw new Exception('Jira вернула HTTP ' . $httpCode . ': ' . $result);
    }

    return $result;
    }
}

if (!function_exists('uploadJiraTemporaryAttachment')) {
    function uploadJiraTemporaryAttachment($jiraBaseUrl, $serviceDeskId, $username, $password, array $file, callable $logMessage)
{
    $filePath = $file['tmp_name'];
    if (!is_readable($filePath)) {
        $logMessage('Файл недоступен для чтения: ' . $filePath);
        throw new Exception('Файл недоступен для чтения: ' . $filePath);
    }

    $url = rtrim($jiraBaseUrl, '/') . '/rest/servicedeskapi/servicedesk/' . rawurlencode($serviceDeskId) . '/attachTemporaryFile';
    $logMessage('Загрузка файла во временное вложение Jira: ' . (string)$file['name']);
    $response = jiraRequest($url, $username, $password, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'file' => new CURLFile($filePath, (string)$file['type'], (string)$file['name']),
        ],
        CURLOPT_HTTPHEADER => [
            'X-Atlassian-Token: no-check',
            'X-ExperimentalApi: opt-in',
            'Authorization: Basic ' . base64_encode($username . ':' . $password),
        ],
    ], $logMessage);

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $logMessage('Ошибка парсинга ответа Jira при загрузке файла: ' . json_last_error_msg());
        throw new Exception('Ошибка парсинга ответа Jira при загрузке файла: ' . json_last_error_msg());
    }

    $attachments = $data['temporaryAttachments'] ?? [];
    if (empty($attachments[0]['temporaryAttachmentId'])) {
        $logMessage('Jira не вернула temporaryAttachmentId: ' . print_r($data, true));
        throw new Exception('Jira не вернула temporaryAttachmentId: ' . print_r($data, true));
    }

    $logMessage('Файл загружен во временное вложение Jira: ' . $attachments[0]['temporaryAttachmentId']);

    return $attachments[0]['temporaryAttachmentId'];
    }
}

$elementId = resolveWorkflowElementId($rootActivity, $fallbackElementId);
$url_mchd = 'https://ourtricolortv.nsc.ru/workgroups/group/187/lists/310/element/0/' . $elementId . '/?list_section_id=';
$logMessage('Старт создания заявки Jira на загрузку МЧД из элемента ' . $elementId);

$employeeFioValues = getIblockPropertyValues($iblockId, $elementId, 1857);
$employee_fio = trim((string)reset($employeeFioValues));
if ($employee_fio === '') {
    $fail('Не заполнено поле ФИО (свойство ID 1857 / PROPERTY_1857) в элементе ' . $elementId);
}

$fileIds = getIblockPropertyValues($iblockId, $elementId, 1874);
if (empty($fileIds)) {
    $fail('Не заполнено поле Файлы (свойство ID 1874 / PROPERTY_1874) в элементе ' . $elementId);
}

$res = CIBlockElement::GetProperty($templateIblockId, $templateElementId, [], ['CODE' => 'JIRA_FIELDS']);
$templateJson = '';
if ($prop = $res->Fetch()) {
    $templateJson = (string)$prop['VALUE'];
}

if ($templateJson === '') {
    $fail('Не найдено поле JIRA_FIELDS в справочнике для заявки "Загрузка МЧД"');
}

$templateData = json_decode($templateJson, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    $fail('Ошибка парсинга JSON из JIRA_FIELDS: ' . json_last_error_msg());
}

$description = 'Загрузить МЧД для ' . $employee_fio . ' в систему';
$context = get_defined_vars();
$data = interpolateTemplate($templateData, $context);
$data = normalizeJiraOrganizationField($data, $logMessage);

if (isset($data['requestFieldValues']['priority']['value'])
    && !isset($data['requestFieldValues']['priority']['name'])
    && !isset($data['requestFieldValues']['priority']['id'])) {
    $data['requestFieldValues']['priority'] = [
        'name' => $data['requestFieldValues']['priority']['value'],
    ];
    $logMessage('Поле priority преобразовано из value в name для Jira Service Management');
}

$serviceDeskId = (string)($data['serviceDeskId'] ?? '');
if ($serviceDeskId === '') {
    $fail('В шаблоне Jira не заполнен serviceDeskId');
}

$temporaryAttachmentIds = [];
foreach ($fileIds as $fileId) {
    $file = CFile::MakeFileArray((int)$fileId);
    if (empty($file) || empty($file['tmp_name'])) {
        $fail('Не удалось получить файл Bitrix с ID ' . $fileId);
    }

    $temporaryAttachmentIds[] = uploadJiraTemporaryAttachment($jiraBaseUrl, $serviceDeskId, $jiraUsername, $jira_pass, $file, $logMessage);
}

$data['requestFieldValues']['attachment'] = $temporaryAttachmentIds;

$txt = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($txt === false) {
    $fail('Ошибка формирования JSON: ' . json_last_error_msg());
}

$txt = preg_replace('/[[:cntrl:]]/', '', $txt);
$logMessage('Текст запроса: ' . $txt);

$result = jiraRequest(rtrim($jiraBaseUrl, '/') . '/rest/servicedeskapi/request', $jiraUsername, $jira_pass, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $txt,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode($jiraUsername . ':' . $jira_pass),
    ],
], $logMessage);

$logMessage('result: ' . $result);
$response = json_decode($result, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    $fail('Ошибка парсинга ответа Jira при создании заявки: ' . json_last_error_msg());
}

if (isset($response['issueKey'])) {
    $issueKey = $response['issueKey'];
    $this->SetVariable('var_Jira_issue', $issueKey . ' Загрузка МЧД');
    $logMessage('Jira key: ' . $issueKey);
} else {
    $fail('Ошибка создания заявки "Загрузка МЧД" в Jira: ' . print_r($response, true));
}
