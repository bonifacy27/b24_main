<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) { die(); }
require_once __DIR__.'/catalog.php';

class CBPGetNotificationTextsActivity extends CBPActivity
{
    public function __construct($name)
    {
        parent::__construct($name);
        $this->arProperties = array(
            'Title'=>'', 'IblockId'=>0, 'TemplateId'=>0,
            'FieldMap'=>TricolorNotificationCatalog::defaults(), 'Bindings'=>array(), 'HtmlOutputs'=>array(),
            'TaskTitle'=>'', 'TaskText'=>'', 'MailSubject'=>'', 'MailText'=>'', 'SiteText'=>'',
        );
        $types = array();
        foreach (TricolorNotificationCatalog::outputs() as $key => $label) {
            $types[$key] = array('Type'=>'string');
        }
        $this->SetPropertiesTypes($types);
    }

    public function Execute()
    {
        // Read latest catalog contents as a workflow service; never impersonate the AJAX caller.
        $texts = TricolorNotificationCatalog::texts(
            (int)$this->IblockId, (int)$this->TemplateId, $this->arProperties['FieldMap'], false
        );
        $values = array();
        foreach (TricolorNotificationTemplate::parameters($texts) as $key) {
            $bindings = $this->arProperties['Bindings'];
            if (!array_key_exists($key, $bindings)) {
                throw new RuntimeException('Шаблон #'.(int)$this->TemplateId.': не задано соответствие {{'.$key.'}}.');
            }
            // Only bindings are expressions. Catalog text and substituted values are never evaluated.
            $values[$key] = $this->ParseValue($bindings[$key], 'string');
        }
        $results = TricolorNotificationTemplate::render($texts, $values, $this->arProperties['HtmlOutputs']);
        foreach ($results as $key => $value) {
            $this->arProperties[$key] = $value;
        }
        return CBPActivityExecutionStatus::Closed;
    }

    public static function ValidateProperties($properties = array(), ?CBPWorkflowTemplateUser $user = null)
    {
        $errors = parent::ValidateProperties($properties, $user);
        try {
            if (empty($properties['IblockId']) || empty($properties['TemplateId'])) {
                throw new InvalidArgumentException('Выберите инфоблок и шаблон.');
            }
            $texts = TricolorNotificationCatalog::texts((int)$properties['IblockId'], (int)$properties['TemplateId'], $properties['FieldMap']);
            foreach (TricolorNotificationTemplate::parameters($texts) as $key) {
                if (!isset($properties['Bindings'][$key]) || $properties['Bindings'][$key] === '') {
                    throw new InvalidArgumentException('Заполните соответствие {{'.$key.'}}.');
                }
            }
            foreach ($properties['HtmlOutputs'] as $output) {
                if (!in_array($output, array('TaskText','MailText','SiteText'), true)) {
                    throw new InvalidArgumentException('Недопустимый выход для HTML-экранирования.');
                }
            }
        } catch (Exception $e) {
            $errors[] = array('code'=>'InvalidTemplate', 'message'=>$e->getMessage());
        }
        return $errors;
    }

    public static function GetPropertiesDialog($documentType, $activityName, $workflowTemplate, $workflowParameters, $workflowVariables, $currentValues = null, $formName = '')
    {
        if (!is_array($currentValues)) {
            $activity = &CBPWorkflowTemplateLoader::FindActivityByName($workflowTemplate, $activityName);
            $currentValues = isset($activity['Properties']) ? $activity['Properties'] : array();
        }
        $currentValues = array_merge(array('IblockId'=>0, 'TemplateId'=>0, 'FieldMap'=>TricolorNotificationCatalog::defaults(), 'Bindings'=>array(), 'HtmlOutputs'=>array()), $currentValues);
        $error = '';
        $iblocks = array();
        $fields = array();
        $templates = array();
        $parameters = array();
        try {
            $iblocks = TricolorNotificationCatalog::iblocks();
            if ((int)$currentValues['IblockId'] > 0) {
                $fields = TricolorNotificationCatalog::fields((int)$currentValues['IblockId']);
                $templates = TricolorNotificationCatalog::elements((int)$currentValues['IblockId']);
                if ((int)$currentValues['TemplateId'] > 0) {
                    $parameters = TricolorNotificationTemplate::parameters(TricolorNotificationCatalog::texts((int)$currentValues['IblockId'], (int)$currentValues['TemplateId'], $currentValues['FieldMap']));
                }
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
        return CBPRuntime::GetRuntime()->ExecuteResourceFile(__FILE__, 'properties_dialog.php', array(
            'currentValues'=>$currentValues, 'iblocks'=>$iblocks, 'fields'=>$fields,
            'templates'=>$templates, 'parameters'=>$parameters, 'error'=>$error,
        ));
    }

    public static function GetPropertiesDialogValues($documentType, $activityName, &$workflowTemplate, &$workflowParameters, &$workflowVariables, $currentValues, &$errors)
    {
        $properties = array(
            'IblockId'=>(int)$currentValues['IblockId'], 'TemplateId'=>(int)$currentValues['TemplateId'],
            'FieldMap'=>array(), 'Bindings'=>array(), 'HtmlOutputs'=>array(),
        );
        foreach (TricolorNotificationCatalog::outputs() as $key => $label) {
            $properties['FieldMap'][$key] = isset($currentValues['FieldMap'][$key]) && is_string($currentValues['FieldMap'][$key]) ? $currentValues['FieldMap'][$key] : '';
        }
        try {
            $texts = TricolorNotificationCatalog::texts($properties['IblockId'], $properties['TemplateId'], $properties['FieldMap']);
            foreach (TricolorNotificationTemplate::parameters($texts) as $key) {
                $field = 'nt_binding_'.$key;
                // Some designer versions send expressions through the auxiliary _X field.
                $value = isset($currentValues[$field.'_X']) && $currentValues[$field.'_X'] !== '' ? $currentValues[$field.'_X'] : (isset($currentValues[$field]) ? $currentValues[$field] : '');
                if (!is_string($value)) {
                    throw new InvalidArgumentException('Соответствие {{'.$key.'}} должно быть строкой.');
                }
                $properties['Bindings'][$key] = $value;
            }
            if (isset($currentValues['HtmlOutputs']) && is_array($currentValues['HtmlOutputs'])) {
                $properties['HtmlOutputs'] = array_values(array_intersect(array('TaskText','MailText','SiteText'), $currentValues['HtmlOutputs']));
            }
            $errors = self::ValidateProperties($properties, new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser));
        } catch (Exception $e) {
            $errors = array(array('code'=>'InvalidTemplate', 'message'=>$e->getMessage()));
        }
        if ($errors) {
            return false;
        }
        $activity = &CBPWorkflowTemplateLoader::FindActivityByName($workflowTemplate, $activityName);
        $properties['Title'] = isset($activity['Properties']['Title']) ? $activity['Properties']['Title'] : 'Получение текстов уведомлений';
        $activity['Properties'] = $properties;
        return true;
    }
}
