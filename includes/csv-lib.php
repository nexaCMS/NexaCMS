<?php
/**
 * DiCimes CSV Library Bootstrap (PHP 8+ compatible)
 */
if (!function_exists('each')) {
    function each(array &$array) {
        $key = key($array);
        if ($key === null) {
            return false;
        }
        $val = current($array);
        next($array);
        return [
            1       => $val,
            'value' => $val,
            0       => $key,
            'key'   => $key,
        ];
    }
}

require_once __DIR__ . '/MyCSV.class.php';
