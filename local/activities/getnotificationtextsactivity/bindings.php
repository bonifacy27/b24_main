<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) { die(); }
foreach ($parameters as $key):
    $name = 'nt_binding_'.$key;
    $value = isset($bindings[$key]) ? $bindings[$key] : '';
?>
<div style="margin:8px 0">
    <div><strong><?=htmlspecialcharsbx('{{'.$key.'}}')?></strong></div>
    <?=CBPDocument::ShowParameterField('string', $name, $value, array('size'=>50))?>
</div>
<?php endforeach; ?>
<?php if (!$parameters): ?>
<div>В выбранных полях нет параметров {{parameter_name}}.</div>
<?php endif; ?>
