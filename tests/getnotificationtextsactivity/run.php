<?php
// Standalone contract tests. These substitutes do not replace a check in real Bitrix.
namespace Bitrix\Main {
    final class Loader { public static function includeModule($name) { return true; } }
}
namespace {
define('B_PROLOG_INCLUDED', true);
class CBPActivity {
    protected $arProperties = array();
    public $parsed = array();
    private $readOnlyData = array();
    public function __construct($name) {}
    public function SetPropertiesTypes($types) {}
    protected function getRawProperty($name) {
        return isset($this->arProperties[$name]) ? $this->arProperties[$name] : (isset($this->readOnlyData[$name]) ? $this->readOnlyData[$name] : null);
    }
    public function __get($name) { return $this->getRawProperty($name); }
    public function pullProperties() {
        $this->readOnlyData = $this->arProperties;
        $this->arProperties = array_fill_keys(array_keys($this->arProperties), null);
        return $this->readOnlyData;
    }
    protected function ParseValue($value, $type = null) {
        $this->parsed[] = $value;
        $values = array('{=Variable:Number}'=>'42', '{=Constant:Days}'=>'3');
        return isset($values[$value]) ? $values[$value] : $value;
    }
    public static function ValidateProperties($properties = array(), ?CBPWorkflowTemplateUser $user = null) { return array(); }
}
class CBPActivityExecutionStatus { const Closed = 'closed'; }
class CBPWorkflowTemplateUser { const CurrentUser = 1; public function __construct($id) {} }
class CBPWorkflowTemplateLoader {
    public static function &FindActivityByName(&$template, $name) { return $template[0]; }
}
class FakeResult {
    private $rows;
    public function __construct($rows) { $this->rows = $rows; }
    public function Fetch() { return array_shift($this->rows); }
    public function GetNextElement() { return $this->rows ? new FakeElement($this->rows[0]) : false; }
}
class FakeElement {
    private $fields;
    public function __construct($fields) { $this->fields = $fields; }
    public function GetFields() { return $this->fields; }
    public function GetProperties() { return array('MAIL'=>array('ID'=>12, 'VALUE'=>array('TEXT'=>'<p>{{number}}: {{days}}</p>', 'TYPE'=>'html'))); }
}
class CIBlock {
    public static $readable = true;
    public static function GetList($order, $filter) { return new FakeResult(self::$readable ? array(array('ID'=>407, 'NAME'=>'Каталог')) : array()); }
}
class CIBlockProperty {
    public static function GetList($order, $filter) { return new FakeResult(array(array('ID'=>12,'NAME'=>'Письмо','CODE'=>'MAIL','USER_TYPE'=>'HTML'))); }
}
class CIBlockElement {
    public static $title = 'Договор {{number}}';
    public static $lastFilter;
    public static function GetList($order, $filter, $group = false, $nav = false, $select = array()) {
        self::$lastFilter = $filter;
        return new FakeResult($filter['IBLOCK_ID'] === 407 && (!isset($filter['ID']) || $filter['ID'] === 5) ? array(array(
            'ID'=>5,'IBLOCK_ID'=>407,'NAME'=>htmlspecialchars(self::$title), '~NAME'=>self::$title,
            'PREVIEW_TEXT'=>'{{number}}', '~PREVIEW_TEXT'=>'{{number}}',
            'DETAIL_TEXT'=>'Ответьте за {{days}} дней', '~DETAIL_TEXT'=>'Ответьте за {{days}} дней',
        )) : array());
    }
}
require __DIR__.'/../../local/activities/getnotificationtextsactivity/getnotificationtextsactivity.php';
class TestActivity extends CBPGetNotificationTextsActivity {
    public function configure($properties) { $this->arProperties = array_merge($this->arProperties, $properties); }
}

function same($expected, $actual, $label) {
    if ($expected !== $actual) { throw new RuntimeException($label.': '.var_export($actual,true)); }
    echo 'PASS '.$label.PHP_EOL;
}
function fails($callback, $message, $label) {
    try { $callback(); } catch (Exception $e) {
        if (strpos($e->getMessage(), $message) !== false) { echo 'PASS '.$label.PHP_EOL; return; }
        throw $e;
    }
    throw new RuntimeException('Expected failure: '.$label);
}
same(array('number','days'), TricolorNotificationTemplate::parameters(array('{{number}} {{ days }} {{number}}')), 'unique parameters and whitespace');
same(array('x'=>'0, 1, Два'), TricolorNotificationTemplate::render(array('x'=>'{{a}}'), array('a'=>array(0,1,'Два'))), 'zero and multiple values');
same(array('x'=>''), TricolorNotificationTemplate::render(array('x'=>'{{a}}'), array('a'=>null)), 'defined empty runtime value');
same(array('MailText'=>'<p>&lt;a&gt;&amp;&quot;</p>', 'TaskTitle'=>'<a>&"'), TricolorNotificationTemplate::render(array('MailText'=>'<p>{{a}}</p>', 'TaskTitle'=>'{{a}}'), array('a'=>'<a>&"'), array('MailText')), 'HTML escaping only selected outputs');
same(array('x'=>'{{b}} {=Variable:Number}'), TricolorNotificationTemplate::render(array('x'=>'{{a}}'), array('a'=>'{{b}} {=Variable:Number}', 'b'=>'wrong')), 'one substitution pass');
fails(function () { TricolorNotificationTemplate::render(array('{{missing}}'), array()); }, 'missing', 'missing binding rejected');
fails(function () { TricolorNotificationTemplate::parameters(array('{{wrong-name}}')); }, 'Некорректный', 'malformed placeholder rejected');
$properties = array('Title'=>'Моя активити','IblockId'=>407,'TemplateId'=>5,'FieldMap'=>TricolorNotificationCatalog::defaults(),'Bindings'=>array('number'=>'{=Variable:Number}', 'days'=>'{=Constant:Days}'),'HtmlOutputs'=>array());
$properties['FieldMap']['MailText'] = 'PROPERTY_12';
$a = new TestActivity('a'); $a->configure($properties);
same('closed', $a->Execute(), 'activity completes');
same('Договор 42', $a->TaskTitle, 'variable substituted');
same('Ответьте за 3 дней', $a->TaskText, 'constant substituted');
same('<p>42: 3</p>', $a->MailText, 'HTML property read');
same('N', CIBlockElement::$lastFilter['CHECK_PERMISSIONS'], 'runtime service catalog read');
same(array('{=Variable:Number}','{=Constant:Days}'), $a->parsed, 'only bindings evaluated');
$relocated = new TestActivity('relocated'); $relocated->configure($properties);
$relocated->pullProperties();
same('closed', $relocated->Execute(), 'execution after Bitrix relocates properties');
same('Договор 42', $relocated->TaskTitle, 'relocated field map read');
same('<p>42: 3</p>', $relocated->MailText, 'relocated bindings read');
same(array('{=Variable:Number}','{=Constant:Days}'), $relocated->parsed, 'relocated bindings evaluated once');
$htmlProperties = $properties; $htmlProperties['Bindings']['number'] = '<42>'; $htmlProperties['HtmlOutputs'] = array('MailText');
$htmlActivity = new TestActivity('html'); $htmlActivity->configure($htmlProperties); $htmlActivity->pullProperties();
$htmlActivity->Execute(); same('<p>&lt;42&gt;: 3</p>', $htmlActivity->MailText, 'relocated HTML settings read');
$broken = new TestActivity('broken'); $broken->configure(array('FieldMap'=>null));
fails(function () use ($broken) { $broken->Execute(); }, 'Настройки шаблона', 'corrupt settings produce actionable error');
$formProperties = $properties;
$formProperties['FieldMap']['FormName'] = 'NAME';
$formProperties['FieldMap']['FormText'] = 'PROPERTY_12';
$formProperties['Bindings']['number'] = '<42>';
$formProperties['HtmlOutputs'] = array('FormText');
$formActivity = new TestActivity('form'); $formActivity->configure($formProperties); $formActivity->pullProperties();
$formActivity->Execute();
same('Договор <42>', $formActivity->FormName, 'form title substitutes values without HTML escaping');
same('<p>&lt;42&gt;: 3</p>', $formActivity->FormText, 'form text supports HTML escaping after property relocation');
same(array(), CBPGetNotificationTextsActivity::ValidateProperties($formProperties), 'designer accepts form HTML setting');
$legacyProperties = $properties;
unset($legacyProperties['FieldMap']['FormName'], $legacyProperties['FieldMap']['FormText']);
$legacyActivity = new TestActivity('legacy'); $legacyActivity->configure($legacyProperties); $legacyActivity->pullProperties();
$legacyActivity->Execute();
same('Договор 42', $legacyActivity->TaskTitle, 'legacy workflow still executes');
same('', $legacyActivity->FormName, 'legacy form title defaults to empty');
same('', $legacyActivity->FormText, 'legacy form text defaults to empty');
same(array(), CBPGetNotificationTextsActivity::ValidateProperties($legacyProperties), 'legacy settings remain valid');
$formTexts = TricolorNotificationCatalog::texts(407, 5, array_merge(TricolorNotificationCatalog::defaults(), array('TaskTitle'=>'', 'TaskText'=>'', 'SiteText'=>'', 'FormText'=>'DETAIL_TEXT')));
same(array('days'), TricolorNotificationTemplate::parameters($formTexts), 'parameters discovered in form-only template');
CIBlockElement::$title = 'Новая редакция {{number}}';
$a->Execute(); same('Новая редакция 42', $a->TaskTitle, 'latest catalog revision read');
CIBlockElement::$title = 'Новая редакция {{number}} {{new_parameter}}';
fails(function () use ($a) { $a->Execute(); }, 'new_parameter', 'new catalog parameter fails explicitly');
CIBlockElement::$title = '{=Variable:Number} {{number}}';
$a->Execute(); same('{=Variable:Number} 42', $a->TaskTitle, 'catalog Bitrix expression stays literal');
same(array(), CBPGetNotificationTextsActivity::ValidateProperties($properties), 'valid designer settings');
$invalid = $properties; unset($invalid['Bindings']['days']);
same('InvalidTemplate', CBPGetNotificationTextsActivity::ValidateProperties($invalid)[0]['code'], 'designer rejects missing binding');
fails(function () use ($properties) { TricolorNotificationCatalog::texts(408, 5, $properties['FieldMap'], false); }, 'Шаблон', 'element scoped to selected iblock');
$invalidMap = $properties['FieldMap']; $invalidMap['TaskText'] = 'PROPERTY_999';
fails(function () use ($invalidMap) { TricolorNotificationCatalog::texts(407, 5, $invalidMap); }, 'Текст задания', 'deleted field rejected');
$template = array(array('Properties'=>array('Title'=>'Сохранить заголовок'))); $parameters=array(); $variables=array(); $errors=array();
$post = $properties;
$post['nt_binding_number']='old'; $post['nt_binding_number_X']='{=Variable:Number}'; $post['nt_binding_days']='{=Constant:Days}';
same(true, CBPGetNotificationTextsActivity::GetPropertiesDialogValues(array(), 'a', $template, $parameters, $variables, $post, $errors), 'designer saves settings');
same('{=Variable:Number}', $template[0]['Properties']['Bindings']['number'], 'auxiliary expression field saved');
same('Сохранить заголовок', $template[0]['Properties']['Title'], 'activity title preserved');
$formPost = $post; $formPost['FieldMap'] = $formProperties['FieldMap']; $formPost['HtmlOutputs'] = array('FormText');
same(true, CBPGetNotificationTextsActivity::GetPropertiesDialogValues(array(), 'a', $template, $parameters, $variables, $formPost, $errors), 'designer saves form outputs');
same('NAME', $template[0]['Properties']['FieldMap']['FormName'], 'form title mapping saved');
same('PROPERTY_12', $template[0]['Properties']['FieldMap']['FormText'], 'form text mapping saved');
same(array('FormText'), $template[0]['Properties']['HtmlOutputs'], 'form HTML option saved');
CIBlock::$readable=false;
fails(function () { TricolorNotificationCatalog::elements(407); }, 'недоступен', 'designer access denied');
echo 'All contract tests passed.'.PHP_EOL;
}
