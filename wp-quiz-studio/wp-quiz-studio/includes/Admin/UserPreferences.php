<?php

declare(strict_types=1);

namespace WPQuizStudio\Admin;

/** Stores the visual Studio preferences separately for every WordPress account. */
final class UserPreferences
{
    public const META_KEY = '_wpqs_studio_preferences';

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'mode' => 'dark',
            'preset' => 'atelier',
            'accent' => '#d9bd85',
            'accent_light' => '#f2dfb8',
            'accent_text' => '#111111',
            'lilac' => '#b9a7ff',
            'page' => '#08080a',
            'surface' => '#15151b',
            'surface_raised' => '#1b1b22',
            'text' => '#f6f4ef',
            'muted' => '#b8b5be',
            'border' => '#34343d',
            'radius' => 18,
            'density' => 'comfortable',
        ];
    }

    /** @return array<string,mixed> */
    public static function get(int $userId = 0): array
    {
        $userId = $userId ?: get_current_user_id();
        if ($userId < 1) {
            return self::defaults();
        }

        $stored = get_user_meta($userId, self::META_KEY, true);
        return self::sanitize(is_array($stored) ? $stored : []);
    }

    /** @param mixed $input @return array<string,mixed> */
    public static function sanitize(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $defaults = self::defaults();
        $output = $defaults;

        // Quiz Atelier uses one fixed dark interface.
        $output['mode'] = 'dark';

        $presets = ['atelier', 'midnight', 'graphite', 'contrast', 'custom'];
        $preset = sanitize_key((string) ($input['preset'] ?? $defaults['preset']));
        $output['preset'] = in_array($preset, $presets, true) ? $preset : $defaults['preset'];
        if ($output['preset'] === 'light') {
            $output['preset'] = 'atelier';
        }

        foreach (['accent', 'accent_light', 'accent_text', 'lilac', 'page', 'surface', 'surface_raised', 'text', 'muted', 'border'] as $key) {
            $color = sanitize_hex_color((string) ($input[$key] ?? $defaults[$key]));
            $output[$key] = $color ?: $defaults[$key];
        }

        $output['radius'] = min(32, max(6, absint($input['radius'] ?? $defaults['radius'])));
        $density = sanitize_key((string) ($input['density'] ?? $defaults['density']));
        $output['density'] = in_array($density, ['compact', 'comfortable', 'spacious'], true) ? $density : 'comfortable';

        return $output;
    }

    /** @param mixed $input @return array<string,mixed> */
    public static function save(mixed $input, int $userId = 0): array
    {
        $userId = $userId ?: get_current_user_id();
        $preferences = self::sanitize($input);
        if ($userId > 0) {
            update_user_meta($userId, self::META_KEY, $preferences);
        }
        return $preferences;
    }
}
