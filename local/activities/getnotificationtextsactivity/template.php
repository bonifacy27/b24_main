<?php
/** Pure template functions; no evaluation of PHP or Bitrix expressions in catalog text. */
final class TricolorNotificationTemplate
{
    public static function parameters(array $texts)
    {
        $keys = array();
        foreach ($texts as $text) {
            preg_match_all('/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/', $text, $matches);
            foreach ($matches[1] as $key) {
                $keys[$key] = true;
            }
            // Reject typos instead of silently sending an unresolved parameter.
            $rest = preg_replace('/\{\{\s*[a-zA-Z_][a-zA-Z0-9_]*\s*\}\}/', '', $text);
            if (strpos($rest, '{{') !== false || strpos($rest, '}}') !== false) {
                throw new InvalidArgumentException('Некорректный параметр шаблона. Используйте {{parameter_name}}.');
            }
        }
        return array_keys($keys);
    }

    public static function valueToString($value)
    {
        if ($value === null || $value === false) {
            return '';
        }
        if (is_array($value)) {
            return implode(', ', array_map(array(__CLASS__, 'valueToString'), $value));
        }
        if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
            return (string)$value;
        }
        throw new InvalidArgumentException('Значение параметра необходимо преобразовать в строку.');
    }

    public static function render(array $texts, array $values, array $htmlOutputs = array())
    {
        foreach (self::parameters($texts) as $key) {
            if (!array_key_exists($key, $values)) {
                throw new InvalidArgumentException('Не задано соответствие параметра {{'.$key.'}}.');
            }
        }
        $result = array();
        foreach ($texts as $output => $text) {
            $isHtml = in_array($output, $htmlOutputs, true);
            $result[$output] = preg_replace_callback(
                '/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/',
                function ($match) use ($values, $isHtml) {
                    $value = self::valueToString($values[$match[1]]);
                    return $isHtml ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $value;
                },
                $text
            );
        }
        return $result;
    }
}
