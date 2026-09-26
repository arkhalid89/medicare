<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Pattern-based code generator for MRN, visit numbers and employee codes.
 *
 * Tokens
 *   {YYYY} {YY} {MM} {DD}   date parts
 *   {000001} {0001} {####}  running sequence, zero-padded to the token width
 *   {DEPT} {ROLE} ...       variables supplied by the caller
 *
 * The sequence restarts per period: yearly when the pattern contains a year,
 * monthly with {MM}, daily with {DD}, never when there is no date token.
 * Counters are row-locked inside a transaction and every generated code is
 * checked against the target table, so a code is never issued twice.
 */
final class CodeGenerator
{
    private const SEQUENCE = '/\{([0#]+1?)\}/';
    private const KNOWN = ['YYYY', 'YY', 'MM', 'DD'];

    public static function render(string $pattern, int $sequence, array $vars = [], ?int $time = null): string
    {
        $time = $time ?? time();
        $map = [
            '{YYYY}' => date('Y', $time),
            '{YY}'   => date('y', $time),
            '{MM}'   => date('m', $time),
            '{DD}'   => date('d', $time),
        ];
        foreach ($vars as $key => $value) {
            $map['{' . strtoupper((string) $key) . '}'] = preg_replace('/[^A-Za-z0-9]/', '', (string) $value);
        }
        $out = strtr($pattern, $map);
        return (string) preg_replace_callback(
            self::SEQUENCE,
            static fn (array $m): string => str_pad((string) $sequence, strlen($m[1]), '0', STR_PAD_LEFT),
            $out
        );
    }

    public static function hasSequence(string $pattern): bool
    {
        return preg_match(self::SEQUENCE, $pattern) === 1;
    }

    public static function periodKey(string $pattern, ?int $time = null): string
    {
        $time = $time ?? time();
        if (str_contains($pattern, '{DD}')) {
            return date('Ymd', $time);
        }
        if (str_contains($pattern, '{MM}')) {
            return date('Ym', $time);
        }
        if (str_contains($pattern, '{YYYY}') || str_contains($pattern, '{YY}')) {
            return date('Y', $time);
        }
        return 'all';
    }

    /** @return string|null error message, or null when valid */
    public static function validate(string $pattern, array $allowedVars = []): ?string
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return 'Pattern is required.';
        }
        if (mb_strlen($pattern) > 60) {
            return 'Pattern must not exceed 60 characters.';
        }
        if (!self::hasSequence($pattern)) {
            return 'Pattern must contain a sequence token such as {000001} or {0001}.';
        }
        if (preg_match_all('/\{([^}]*)\}/', $pattern, $m)) {
            foreach ($m[1] as $token) {
                if (preg_match('/^[0#]+1?$/', $token)) {
                    continue;
                }
                if (!in_array($token, self::KNOWN, true) && !in_array($token, array_map('strtoupper', $allowedVars), true)) {
                    return 'Unknown token {' . $token . '}. Allowed: {YYYY} {YY} {MM} {DD} {000001}'
                        . ($allowedVars ? ' {' . implode('} {', array_map('strtoupper', $allowedVars)) . '}' : '') . '.';
                }
            }
        }
        if (preg_match('/[^A-Za-z0-9\-\/_.{}#]/', $pattern)) {
            return 'Pattern may contain only letters, digits, - / _ . and tokens.';
        }
        if (mb_strlen(self::render($pattern, 999999, array_fill_keys($allowedVars, 'XXXX'))) > 50) {
            return 'Generated code would be longer than 50 characters.';
        }
        return null;
    }

    /**
     * Issues the next code.
     * @param callable $exists fn(string $code): bool — true if already used
     */
    public static function next(string $scope, string $pattern, callable $exists, array $vars = []): string
    {
        if (!self::hasSequence($pattern)) {
            throw new \InvalidArgumentException('Code pattern "' . $pattern . '" has no sequence token.');
        }
        return DB::transaction(static function () use ($scope, $pattern, $exists, $vars): string {
            $key = $scope . ':' . self::periodKey($pattern);
            DB::run('INSERT IGNORE INTO counters (counter_key, last_value) VALUES (?, 0)', [$key]);
            $n = (int) DB::value('SELECT last_value FROM counters WHERE counter_key = ? FOR UPDATE', [$key]);
            $code = '';
            for ($guard = 0; $guard < 1000000; $guard++) {
                $n++;
                $code = self::render($pattern, $n, $vars);
                if (!$exists($code)) {
                    break;
                }
            }
            DB::run('UPDATE counters SET last_value = ? WHERE counter_key = ?', [$n, $key]);
            return $code;
        });
    }

    /** What the next code will look like, without consuming it. */
    public static function preview(string $scope, string $pattern, array $vars = []): string
    {
        if (!self::hasSequence($pattern)) {
            return $pattern;
        }
        $key = $scope . ':' . self::periodKey($pattern);
        $n = (int) (DB::value('SELECT last_value FROM counters WHERE counter_key = ?', [$key]) ?? 0);
        return self::render($pattern, $n + 1, $vars);
    }
}
