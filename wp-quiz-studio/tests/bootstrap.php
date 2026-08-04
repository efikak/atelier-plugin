<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_readable($autoload)) {
    require_once $autoload;
}

if (!function_exists('absint')) {
    function absint(mixed $value): int { return abs((int) $value); }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key(mixed $value): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)) ?: ''; }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(mixed $value): string { return trim(strip_tags((string) $value)); }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field(mixed $value): string { return trim(strip_tags((string) $value)); }
}
if (!function_exists('wp_kses_post')) {
    function wp_kses_post(mixed $value): string { return (string) $value; }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw(mixed $value): string { return filter_var((string) $value, FILTER_SANITIZE_URL) ?: ''; }
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): mixed { return parse_url($url, $component); }
}

if (!function_exists('remove_accents')) {
    function remove_accents(mixed $value): string {
        return strtr((string) $value, [
            'ά'=>'α','έ'=>'ε','ή'=>'η','ί'=>'ι','ό'=>'ο','ύ'=>'υ','ώ'=>'ω',
            'Ά'=>'Α','Έ'=>'Ε','Ή'=>'Η','Ί'=>'Ι','Ό'=>'Ο','Ύ'=>'Υ','Ώ'=>'Ω',
            'ϊ'=>'ι','ΐ'=>'ι','ϋ'=>'υ','ΰ'=>'υ','Ϊ'=>'Ι','Ϋ'=>'Υ',
        ]);
    }
}
