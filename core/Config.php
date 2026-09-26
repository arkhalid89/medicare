<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Read-only access to the files in /config using dot notation.
 *   config('app.base_url')            -> config/config.php
 *   config('permissions.roles')       -> config/permissions.php
 *   config('masters.medicines.title') -> config/masters.php
 */
final class Config
{
    private const SEPARATE_FILES = ['permissions', 'masters', 'menu'];

    private static array $items = [];
    private static string $dir = '';

    public static function load(string $dir): void
    {
        self::$dir = $dir;
        self::$items['config'] = require $dir . '/config.php';
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        if (in_array($parts[0], self::SEPARATE_FILES, true)) {
            $file = array_shift($parts);
            if (!isset(self::$items[$file])) {
                self::$items[$file] = require self::$dir . '/' . $file . '.php';
            }
            $value = self::$items[$file];
        } else {
            $value = self::$items['config'];
        }
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    /** Runtime override of a config.php value (used by the test runner). */
    public static function set(string $key, mixed $value): void
    {
        $ref = &self::$items['config'];
        foreach (explode('.', $key) as $part) {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = $ref[$part] ?? [];
            }
            $ref = &$ref[$part];
        }
        $ref = $value;
    }
}
