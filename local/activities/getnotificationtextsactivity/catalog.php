<?php
require_once __DIR__.'/template.php';

final class TricolorNotificationCatalog
{
    public static function outputs()
    {
        return array(
            'TaskTitle' => 'Название задания',
            'TaskText' => 'Текст задания',
            'FormName' => 'Название задания для формы',
            'FormText' => 'Текст задания для формы',
            'MailSubject' => 'Тема письма',
            'MailText' => 'Текст письма',
            'SiteText' => 'Текст уведомления на сайте',
        );
    }

    public static function defaults()
    {
        return array('TaskTitle'=>'NAME', 'TaskText'=>'DETAIL_TEXT', 'FormName'=>'', 'FormText'=>'', 'MailSubject'=>'', 'MailText'=>'', 'SiteText'=>'PREVIEW_TEXT');
    }

    public static function normalizeFieldMap(array $map)
    {
        // Existing saved workflows predate the optional form outputs.
        return array_merge(array('FormName'=>'', 'FormText'=>''), $map);
    }

    private static function module()
    {
        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            throw new RuntimeException('Модуль инфоблоков недоступен.');
        }
    }

    public static function iblocks()
    {
        self::module();
        $items = array();
        $rs = CIBlock::GetList(array('NAME'=>'ASC'), array('ACTIVE'=>'Y', 'CHECK_PERMISSIONS'=>'Y', 'MIN_PERMISSION'=>'R'));
        while ($row = $rs->Fetch()) {
            $items[] = array('id'=>(int)$row['ID'], 'name'=>$row['NAME']);
        }
        return $items;
    }

    public static function assertReadable($iblockId)
    {
        self::module();
        $rs = CIBlock::GetList(array(), array('ID'=>(int)$iblockId, 'ACTIVE'=>'Y', 'CHECK_PERMISSIONS'=>'Y', 'MIN_PERMISSION'=>'R'));
        if (!$rs->Fetch()) {
            throw new RuntimeException('Инфоблок отсутствует или недоступен для чтения.');
        }
    }

    public static function fields($iblockId, $checkPermissions = true)
    {
        $schema = self::schema($iblockId, $checkPermissions);
        return $schema['fields'];
    }

    public static function schema($iblockId, $checkPermissions = true)
    {
        self::module();
        if ($checkPermissions) {
            self::assertReadable($iblockId);
        }
        $codes = array('TaskTitle'=>'TASK_TITLE', 'TaskText'=>'TASK_TEXT', 'FormName'=>'FORM_NAME', 'FormText'=>'FORM_TEXT', 'MailSubject'=>'MAIL_SUBJECT', 'MailText'=>'MAIL_TEXT', 'SiteText'=>'SITE_TEXT');
        $defaults = self::defaults();
        $matched = array();
        $fields = array('NAME'=>'Название элемента', 'PREVIEW_TEXT'=>'Описание для анонса', 'DETAIL_TEXT'=>'Подробное описание');
        $rs = CIBlockProperty::GetList(array('SORT'=>'ASC', 'ID'=>'ASC'), array('IBLOCK_ID'=>(int)$iblockId, 'ACTIVE'=>'Y', 'PROPERTY_TYPE'=>'S', 'MULTIPLE'=>'N'));
        while ($row = $rs->Fetch()) {
            // Avoid linked users, directories, etc. Only plain strings and HTML/text.
            if (!empty($row['USER_TYPE']) && $row['USER_TYPE'] !== 'HTML') {
                continue;
            }
            $source = 'PROPERTY_'.$row['ID'];
            $fields[$source] = $row['NAME'].(!empty($row['CODE']) ? ' ['.$row['CODE'].']' : '');
            $code = strtoupper((string)$row['CODE']);
            foreach ($codes as $output => $expectedCode) {
                if ($code === $expectedCode && !isset($matched[$output])) {
                    $defaults[$output] = $source;
                    $matched[$output] = true;
                }
            }
        }
        return array('fields'=>$fields, 'defaults'=>$defaults);
    }

    public static function validateFields($iblockId, array $map, $checkPermissions = true)
    {
        $available = self::fields($iblockId, $checkPermissions);
        foreach (self::outputs() as $output => $label) {
            if (!array_key_exists($output, $map) || !is_string($map[$output]) || ($map[$output] !== '' && !isset($available[$map[$output]]))) {
                throw new InvalidArgumentException('Выберите доступное текстовое поле: '.$label.'.');
            }
        }
        if (!array_filter($map)) {
            throw new InvalidArgumentException('Выберите хотя бы одно поле с текстом шаблона.');
        }
    }

    public static function elements($iblockId)
    {
        self::assertReadable($iblockId);
        $items = array();
        $rs = CIBlockElement::GetList(array('NAME'=>'ASC', 'ID'=>'ASC'), array('IBLOCK_ID'=>(int)$iblockId, 'ACTIVE'=>'Y', 'CHECK_PERMISSIONS'=>'Y', 'MIN_PERMISSION'=>'R'), false, false, array('ID','NAME'));
        while ($row = $rs->Fetch()) {
            $items[] = array('id'=>(int)$row['ID'], 'name'=>$row['NAME']);
        }
        return $items;
    }

    public static function texts($iblockId, $elementId, array $map, $checkPermissions = true)
    {
        $map = self::normalizeFieldMap($map);
        self::validateFields($iblockId, $map, $checkPermissions);
        $rs = CIBlockElement::GetList(array(), array(
            'IBLOCK_ID'=>(int)$iblockId, 'ID'=>(int)$elementId, 'ACTIVE'=>'Y',
            'CHECK_PERMISSIONS'=>$checkPermissions ? 'Y' : 'N', 'MIN_PERMISSION'=>'R',
        ), false, false, array('ID','IBLOCK_ID','NAME','PREVIEW_TEXT','DETAIL_TEXT'));
        $element = $rs->GetNextElement();
        if (!$element) {
            throw new RuntimeException('Шаблон отсутствует, неактивен или недоступен.');
        }
        $fields = $element->GetFields();
        $properties = $element->GetProperties();
        $byId = array();
        foreach ($properties as $property) {
            $byId['PROPERTY_'.$property['ID']] = $property['VALUE'];
        }
        $texts = array();
        foreach (self::outputs() as $output => $label) {
            $source = $map[$output];
            if ($source === '') {
                $texts[$output] = '';
                continue;
            }
            if (strpos($source, 'PROPERTY_') === 0) {
                $value = isset($byId[$source]) ? $byId[$source] : '';
                if (is_array($value) && array_key_exists('TEXT', $value)) {
                    $value = $value['TEXT'];
                }
            } else {
                // GetNextElement escapes fields; ~ contains the original template.
                $value = isset($fields['~'.$source]) ? $fields['~'.$source] : $fields[$source];
            }
            $texts[$output] = TricolorNotificationTemplate::valueToString($value);
        }
        return $texts;
    }
}
