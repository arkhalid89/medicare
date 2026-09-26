<?php
declare(strict_types=1);

namespace App\Core;

/**
 * The single place that decides how passwords are stored.
 *
 * Current requirement: plain text ('password_mode' => 'plain' in config).
 * Set it to 'bcrypt' later and nothing else changes: existing plain-text
 * passwords still verify and are hashed automatically at the next login.
 */
final class Password
{
    public static function hash(string $plain): string
    {
        return self::mode() === 'bcrypt' ? password_hash($plain, PASSWORD_BCRYPT) : $plain;
    }

    public static function verify(string $plain, string $stored): bool
    {
        if (self::isHash($stored)) {
            return password_verify($plain, $stored);
        }
        return $stored !== '' && hash_equals($stored, $plain);
    }

    public static function needsUpgrade(string $stored): bool
    {
        return self::mode() === 'bcrypt' && !self::isHash($stored);
    }

    public static function isHash(string $stored): bool
    {
        return (password_get_info($stored)['algo'] ?? null) !== null
            && (password_get_info($stored)['algoName'] ?? 'unknown') !== 'unknown';
    }

    public static function minLength(): int
    {
        return (int) config('security.min_password_length', 6);
    }

    private static function mode(): string
    {
        return (string) config('security.password_mode', 'plain');
    }
}
