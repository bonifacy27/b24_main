<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) { die(); }
$uid = 'nt_'.bin2hex(random_bytes(6));
$bindings = $currentValues['Bindings'];
// Preserve posted expressions when the designer redisplays a validation error.
foreach ($parameters as $key) {
    $name = 'nt_binding_'.$key;
    if (isset($currentValues[$name.'_X']) && $currentValues[$name.'_X'] !== '') {
        $bindings[$key] = $currentValues[$name.'_X'];
    } elseif (isset($currentValues[$name])) {
        $bindings[$key] = $currentValues[$name];
    }
}
?>
<tr><td align="right" width="40%">Инфоблок:</td><td>
<select name="IblockId" id="<?=$uid?>_iblock">
<option value="0">Выберите инфоблок</option>
<?php foreach ($iblocks as $item): ?>
<option value="<?=$item['id']?>" <?=$item['id']==(int)$currentValues['IblockId'] ? 'selected' : ''?>><?=htmlspecialcharsbx($item['name'].' ['.$item['id'].']')?></option>
<?php endforeach; ?>
</select></td></tr>
<tr><td align="right">Шаблон:</td><td>
<select name="TemplateId" id="<?=$uid?>_template">
<option value="0">Выберите шаблон</option>
<?php foreach ($templates as $item): ?>
<option value="<?=$item['id']?>" <?=$item['id']==(int)$currentValues['TemplateId'] ? 'selected' : ''?>><?=htmlspecialcharsbx($item['name'].' ['.$item['id'].']')?></option>
<?php endforeach; ?>
</select></td></tr>
<?php foreach (TricolorNotificationCatalog::outputs() as $key => $label): ?>
<tr><td align="right"><?=htmlspecialcharsbx($label)?> — поле:</td><td>
<select name="FieldMap[<?=$key?>]" id="<?=$uid?>_<?=$key?>">
<option value="">Не использовать</option>
<?php foreach ($fields as $source => $sourceLabel): ?>
<option value="<?=htmlspecialcharsbx($source)?>" <?=$source===$currentValues['FieldMap'][$key] ? 'selected' : ''?>><?=htmlspecialcharsbx($sourceLabel)?></option>
<?php endforeach; ?>
</select>
<?php if (in_array($key, array('TaskText','FORM_TEXT','MailText','SiteText'), true)): ?>
<label><input type="checkbox" name="HtmlOutputs[]" value="<?=$key?>" <?=in_array($key, $currentValues['HtmlOutputs'], true) ? 'checked' : ''?>> Экранировать значения для HTML</label>
<?php endif; ?>
</td></tr>
<?php endforeach; ?>
<tr><td align="right" valign="top">Соответствия параметров:</td><td>
<button type="button" id="<?=$uid?>_refresh">Обновить параметры шаблона</button>
<div id="<?=$uid?>_status" role="status" style="margin:8px 0;color:#b00"><?=htmlspecialcharsbx($error)?></div>
<div id="<?=$uid?>_bindings"><?php require __DIR__.'/bindings.php'; ?></div>
<div style="margin-top:8px">Выбирайте переменные, константы и поля через кнопку вставки значения. Даты и пользователей передавайте в нужном текстовом представлении.</div>
</td></tr>
<script>
(function () {
    var id = '<?=$uid?>';
    var root = document.getElementById(id + '_bindings');
    var block = document.getElementById(id + '_iblock');
    var template = document.getElementById(id + '_template');
    var status = document.getElementById(id + '_status');
    var refresh = document.getElementById(id + '_refresh');
    var outputs = <?=\Bitrix\Main\Web\Json::encode(array_keys(TricolorNotificationCatalog::outputs()))?>;
    var defaults = <?=\Bitrix\Main\Web\Json::encode(TricolorNotificationCatalog::defaults())?>;
    var cache = Object.create(null);
    var revision = 0;
    var loading = false;
    function collect() {
        Array.prototype.forEach.call(root.querySelectorAll('input, textarea, select'), function (input) {
            if (input.name.indexOf('nt_binding_') === 0 && !/_X$/.test(input.name)) {
                cache[input.name.slice(11)] = input.value;
            }
        });
        Array.prototype.forEach.call(root.querySelectorAll('input, textarea'), function (input) {
            if (input.name.indexOf('nt_binding_') === 0 && /_X$/.test(input.name) && input.value !== '') {
                cache[input.name.slice(11, -2)] = input.value;
            }
        });
    }
    function map() {
        var value = {};
        outputs.forEach(function (key) { value[key] = document.getElementById(id + '_' + key).value; });
        return value;
    }
    function options(select, items, selected, placeholder) {
        select.innerHTML = '';
        select.add(new Option(placeholder, ''));
        items.forEach(function (item) { select.add(new Option(item.name, String(item.id))); });
        select.value = selected;
        if (select.selectedIndex < 0) { select.selectedIndex = 0; }
    }
    function request(action, extra, callback) {
        var ticket = ++revision;
        loading = true;
        refresh.disabled = true;
        status.textContent = 'Загрузка…';
        var data = {action: action, iblock: block.value, sessid: BX.bitrix_sessid()};
        Object.keys(extra).forEach(function (key) { data[key] = extra[key]; });
        BX.ajax({url: '/local/activities/getnotificationtextsactivity/ajax.php', method: 'POST', dataType: 'json', data: data,
            onsuccess: function (response) {
                if (ticket !== revision) { return; }
                loading = false;
                refresh.disabled = false;
                status.textContent = response.ok ? '' : response.error;
                if (response.ok) { callback(response.data); }
            },
            onfailure: function () {
                if (ticket !== revision) { return; }
                loading = false; refresh.disabled = false;
                status.textContent = 'Не удалось загрузить шаблон. Проверьте соединение и сессию.';
            }
        });
    }
    function parameters() {
        collect();
        if (!Number(template.value) || !Number(block.value)) {
            ++revision; loading = false; refresh.disabled = false; root.innerHTML = ''; status.textContent = 'Выберите инфоблок и шаблон.'; return;
        }
        request('parameters', {template: template.value, map: map(), bindings: cache}, function (data) {
            var html = BX.processHTML(data.html);
            root.innerHTML = html.HTML;
            BX.ajax.processScripts(html.SCRIPT);
        });
    }
    block.onchange = function () {
        collect(); root.innerHTML = ''; cache = Object.create(null);
        options(template, [], '', 'Выберите шаблон');
        outputs.forEach(function (key) { options(document.getElementById(id + '_' + key), [], '', 'Не использовать'); });
        if (!Number(block.value)) { ++revision; loading = false; refresh.disabled = false; status.textContent = 'Выберите инфоблок.'; return; }
        request('catalog', {}, function (data) {
            options(template, data.templates.map(function (item) { return {id: item.id, name: item.name + ' [' + item.id + ']'}; }), '', 'Выберите шаблон');
            var fields = Object.keys(data.fields).map(function (key) { return {id: key, name: data.fields[key]}; });
            outputs.forEach(function (key) { options(document.getElementById(id + '_' + key), fields, defaults[key], 'Не использовать'); });
        });
    };
    template.onchange = parameters;
    outputs.forEach(function (key) { document.getElementById(id + '_' + key).onchange = parameters; });
    refresh.onclick = parameters;
    // Saving during an unfinished schema request would lose dynamically generated bindings.
    if (root.closest('form')) {
        root.closest('form').addEventListener('submit', function (event) {
            if (loading) { event.preventDefault(); status.textContent = 'Дождитесь загрузки параметров.'; }
        });
    }
}());
</script>
