<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Offline subscription licence (manual payments).
 *
 * A key carries the licensee's phone number and the subscription period
 * (from / to) in readable form, plus an Ed25519 digital signature made with
 * the vendor's PRIVATE key (tools/license.php, kept by the vendor, never
 * shipped). The application only holds the PUBLIC key, so it can verify and
 * read a key offline but nobody can forge or extend one.
 *
 *   Key format: MCP1-XXXXX-XXXXX-… (Crockford base32 of payload + signature)
 *   Payload   : version, phone digits, from-day, to-day (days since 2020-01-01)
 *
 * States: trial | active | expiring | expired | not_started | missing | invalid | clock
 * A new installation runs a TRIAL_DAYS trial. Moving the computer clock
 * backwards (to stretch a subscription) is detected and blocks login.
 */
final class License
{
    public const PREFIX = 'MCP1';
    public const TRIAL_DAYS = 14;
    public const WARN_DAYS = 15;
    private const EPOCH = '2020-01-01';
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** Vendor public key (base64). The matching private key stays with the vendor. */
    private const PUBLIC_KEY = 'TU5yK3kzV+cp5YDsopviFDwLGVidJStCqUSR2kg7OtQ=';

    private static ?string $publicKeyOverride = null;
    private static ?array $cache = null;

    // ------------------------------------------------------------ issue / read

    /** Creates a key (vendor side: needs the base64 secret key). */
    public static function issue(string $secretKeyB64, string $phone, string $from, string $to): string
    {
        self::requireSodium();
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) < 10 || strlen($digits) > 15) {
            throw new \InvalidArgumentException('Phone number must have 10 to 15 digits.');
        }
        $f = self::day($from);
        $t = self::day($to);
        if ($t < $f) {
            throw new \InvalidArgumentException('"To" date must be on or after "from" date.');
        }
        $payload = chr(1) . chr(strlen($digits)) . pack('J', (int) $digits) . pack('n', $f) . pack('n', $t);
        $signature = sodium_crypto_sign_detached($payload, base64_decode($secretKeyB64, true) ?: '');
        $code = self::base32($payload . $signature);
        return self::PREFIX . '-' . implode('-', str_split($code, 5));
    }

    /**
     * Verifies a key and returns what it contains, or null if forged / damaged.
     * @return array{phone:string, from:string, to:string}|null
     */
    public static function read(string $key): ?array
    {
        if (!self::sodiumAvailable()) {
            return null;
        }
        $clean = strtoupper(preg_replace('/[\s\-]/', '', $key) ?? '');
        if (!str_starts_with($clean, self::PREFIX)) {
            return null;
        }
        $bin = self::unbase32(substr($clean, strlen(self::PREFIX)));
        if ($bin === null || strlen($bin) !== 14 + SODIUM_CRYPTO_SIGN_BYTES) {
            return null;
        }
        $payload = substr($bin, 0, 14);
        $signature = substr($bin, 14);
        $pk = base64_decode(self::$publicKeyOverride ?? self::PUBLIC_KEY, true) ?: '';
        try {
            if (strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || !sodium_crypto_sign_verify_detached($signature, $payload, $pk)) {
                return null;
            }
        } catch (\SodiumException $e) {
            return null;
        }
        if (ord($payload[0]) !== 1) {
            return null;
        }
        $len = ord($payload[1]);
        $number = unpack('J', substr($payload, 2, 8))[1];
        $phone = str_pad((string) $number, $len, '0', STR_PAD_LEFT);
        return [
            'phone' => $phone,
            'from'  => self::date(unpack('n', substr($payload, 10, 2))[1]),
            'to'    => self::date(unpack('n', substr($payload, 12, 2))[1]),
        ];
    }

    // --------------------------------------------------------------- status

    /** Current licence state (cached per request). */
    public static function status(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $today = today();

        // Clock moved backwards?
        $lastSeen = (string) setting('license_last_seen', '');
        if ($lastSeen !== '' && $today < date('Y-m-d', strtotime($lastSeen . ' -1 day'))) {
            return self::$cache = self::state('clock', false, 'The computer date (' . fmt_date($today) . ') is earlier than the last recorded use (' . fmt_date($lastSeen) . '). Correct the Windows date and time.');
        }
        if ($lastSeen === '' || $today > $lastSeen) {
            try {
                Settings::set(['license_last_seen' => $today]);
            } catch (\Throwable $e) {
                // Settings table missing during install: ignore.
            }
        }

        $key = trim((string) setting('license_key', ''));
        if ($key === '') {
            $start = (string) setting('license_trial_start', '');
            if ($start === '') {
                $start = $today;
                try {
                    Settings::set(['license_trial_start' => $start]);
                } catch (\Throwable $e) {
                }
            }
            $end = date('Y-m-d', strtotime($start . ' +' . (self::TRIAL_DAYS - 1) . ' days'));
            $left = self::daysBetween($today, $end);
            if ($left < 0) {
                return self::$cache = self::state('expired', false, 'The ' . self::TRIAL_DAYS . '-day trial ended on ' . fmt_date($end) . '. Enter a subscription key to continue.', null, $start, $end);
            }
            return self::$cache = self::state('trial', true, 'Trial: ' . ($left + 1) . ' day(s) left. Enter a subscription key to keep using the software.', null, $start, $end, $left + 1);
        }

        $info = self::read($key);
        if ($info === null) {
            return self::$cache = self::state('invalid', false, self::sodiumAvailable() ? 'The saved subscription key is not valid.' : 'The PHP "sodium" extension is required to check the subscription key. Enable extension=sodium in php.ini.');
        }
        if (normalize_phone((string) setting('license_phone', '')) !== $info['phone'] && preg_replace('/\D/', '', (string) setting('license_phone', '')) !== $info['phone']) {
            return self::$cache = self::state('invalid', false, 'The subscription key belongs to a different phone number.');
        }
        if ($today < $info['from']) {
            return self::$cache = self::state('not_started', false, 'The subscription starts on ' . fmt_date($info['from']) . '.', $info['phone'], $info['from'], $info['to']);
        }
        $left = self::daysBetween($today, $info['to']);
        if ($left < 0) {
            return self::$cache = self::state('expired', false, 'The subscription expired on ' . fmt_date($info['to']) . '. Enter a renewal key to continue.', $info['phone'], $info['from'], $info['to'], 0);
        }
        $days = $left + 1;
        return self::$cache = self::state($days <= self::WARN_DAYS ? 'expiring' : 'active', true,
            $days <= self::WARN_DAYS ? 'The subscription ends on ' . fmt_date($info['to']) . ' (' . $days . ' day(s) left). Please renew.' : 'Subscription active until ' . fmt_date($info['to']) . '.',
            $info['phone'], $info['from'], $info['to'], $days);
    }

    public static function allowsLogin(): bool
    {
        return (bool) self::status()['ok'];
    }

    /**
     * Activates a key for a phone number (from the licence screen).
     * @return array{ok:bool, message:string}
     */
    public static function activate(string $phone, string $key): array
    {
        $info = self::read($key);
        if ($info === null) {
            return ['ok' => false, 'message' => self::sodiumAvailable()
                ? 'This key is not valid. Check that it was copied completely.'
                : 'The PHP "sodium" extension is required. Enable extension=sodium in php.ini and restart Apache.'];
        }
        $digits = preg_replace('/\D/', '', $phone);
        if ($digits !== $info['phone']) {
            return ['ok' => false, 'message' => 'This key was issued for a different phone number.'];
        }
        if (today() > $info['to']) {
            return ['ok' => false, 'message' => 'This key already expired on ' . fmt_date($info['to']) . '.'];
        }
        Settings::set(['license_key' => trim($key), 'license_phone' => $digits]);
        self::flush();
        try {
            DB::insert('license_activations', ['phone' => $digits, 'from_date' => $info['from'], 'to_date' => $info['to'],
                'license_key' => trim($key), 'activated_by' => Auth::id(), 'activated_at' => now()]);
        } catch (\PDOException $e) {
            Logger::warning('Licence activation log failed: ' . $e->getMessage());
        }
        ActivityLog::record('Subscription activated', 'license', null, 'Key for ' . $digits . ' valid ' . fmt_date($info['from']) . ' to ' . fmt_date($info['to']));
        return ['ok' => true, 'message' => 'Subscription activated: valid from ' . fmt_date($info['from']) . ' to ' . fmt_date($info['to']) . '.'];
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    // ------------------------------------------------- vendor: issue & logs

    private static ?string $vendorKeyFile = null;

    public static function vendorKeyFile(): string
    {
        return self::$vendorKeyFile ?? BASE_PATH . '/tools/license-private.key';
    }

    /** Test hook: use another private-key file. */
    public static function useVendorKeyFile(?string $path): void
    {
        self::$vendorKeyFile = $path;
    }

    /**
     * Key generation is possible only on the VENDOR's installation, where the
     * private key file exists and matches the built-in public key. Clinic
     * installations never contain it, so a clinic cannot make its own keys.
     */
    public static function canIssue(): bool
    {
        $file = self::vendorKeyFile();
        if (!self::sodiumAvailable() || !is_file($file) || !is_readable($file)) {
            return false;
        }
        try {
            $probe = self::issue(trim((string) file_get_contents($file)), '03000000000', today(), today());
            return self::read($probe) !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Issues a key from the Super Admin screen and records it in the log.
     * @return array{ok:bool, message:string, key?:string, errors?:array}
     */
    public static function generate(array $in): array
    {
        if (!self::canIssue()) {
            return ['ok' => false, 'message' => 'Keys can only be generated on the vendor installation (private key not present).'];
        }
        $v = new Validator($in);
        $v->required('phone', 'Phone')->phone('phone', 'Phone')
            ->required('from', 'Valid from')->date('from', 'Valid from')
            ->required('to', 'Valid to')->date('to', 'Valid to')
            ->max('clinic_name', 'Clinic name', 150)->numeric('amount', 'Amount', 0, 100000000)
            ->max('payment_ref', 'Payment reference', 100)->max('notes', 'Notes', 500);
        if (!$v->fails() && $in['to'] < $in['from']) {
            $v->add('to', '"Valid to" must be on or after "Valid from".');
        }
        if ($v->fails()) {
            return ['ok' => false, 'message' => $v->first(), 'errors' => $v->errors()];
        }
        $phone = (string) normalize_phone((string) $in['phone']);
        $key = self::issue(trim((string) file_get_contents(self::vendorKeyFile())), $phone, (string) $in['from'], (string) $in['to']);
        DB::insert('license_issued', [
            'phone'       => $phone,
            'clinic_name' => trim((string) ($in['clinic_name'] ?? '')) ?: null,
            'from_date'   => $in['from'],
            'to_date'     => $in['to'],
            'amount'      => round((float) ($in['amount'] ?? 0), 2),
            'payment_ref' => trim((string) ($in['payment_ref'] ?? '')) ?: null,
            'notes'       => trim((string) ($in['notes'] ?? '')) ?: null,
            'license_key' => $key,
            'issued_by'   => Auth::id(),
            'created_at'  => now(),
        ]);
        ActivityLog::record('Subscription key issued', 'license', null, 'Key for ' . $phone . ($in['clinic_name'] ? ' (' . $in['clinic_name'] . ')' : '')
            . ' valid ' . fmt_date($in['from']) . ' to ' . fmt_date($in['to']));
        return ['ok' => true, 'message' => 'Key generated for ' . $phone . '.', 'key' => $key];
    }

    /** Status of a subscription period today. */
    public static function periodStatus(string $from, string $to): string
    {
        $today = today();
        return $today < $from ? 'Scheduled' : ($today > $to ? 'Expired' : (self::daysBetween($today, $to) + 1 <= self::WARN_DAYS ? 'Expiring' : 'Active'));
    }

    /** Keys issued from this (vendor) installation, newest first, with status. */
    public static function issuedLog(int $limit = 500): array
    {
        $rows = DB::all('SELECT l.*, u.name AS issued_by_name FROM license_issued l LEFT JOIN users u ON u.id = l.issued_by ORDER BY l.id DESC LIMIT ' . $limit);
        foreach ($rows as &$r) {
            $r['status'] = self::periodStatus($r['from_date'], $r['to_date']);
        }
        return $rows;
    }

    /** Keys activated on this installation, newest first, with status. */
    public static function activationLog(int $limit = 200): array
    {
        $current = trim((string) setting('license_key', ''));
        $rows = DB::all('SELECT a.*, u.name AS activated_by_name FROM license_activations a LEFT JOIN users u ON u.id = a.activated_by ORDER BY a.id DESC LIMIT ' . $limit);
        $seenCurrent = false;
        foreach ($rows as &$r) {
            $period = self::periodStatus($r['from_date'], $r['to_date']);
            if (!$seenCurrent && trim($r['license_key']) === $current) {
                $r['status'] = 'In use · ' . $period;
                $seenCurrent = true;
            } else {
                $r['status'] = $period === 'Expired' ? 'Expired' : 'Replaced';
            }
        }
        return $rows;
    }

    /** Test hook: verify against a different public key. */
    public static function usePublicKey(?string $base64): void
    {
        self::$publicKeyOverride = $base64;
        self::$cache = null;
    }

    public static function sodiumAvailable(): bool
    {
        return function_exists('sodium_crypto_sign_verify_detached');
    }

    // --------------------------------------------------------------- helpers

    private static function state(string $state, bool $ok, string $message, ?string $phone = null, ?string $from = null, ?string $to = null, ?int $daysLeft = null): array
    {
        return ['state' => $state, 'ok' => $ok, 'message' => $message, 'phone' => $phone, 'from' => $from, 'to' => $to, 'days_left' => $daysLeft];
    }

    private static function requireSodium(): void
    {
        if (!self::sodiumAvailable()) {
            throw new \RuntimeException('The PHP sodium extension is required.');
        }
    }

    private static function day(string $date): int
    {
        $ts = strtotime($date);
        if (!$ts) {
            throw new \InvalidArgumentException('Invalid date: ' . $date);
        }
        $d = (int) floor((strtotime(date('Y-m-d', $ts)) - strtotime(self::EPOCH)) / 86400);
        if ($d < 0 || $d > 65535) {
            throw new \InvalidArgumentException('Date out of range: ' . $date);
        }
        return $d;
    }

    private static function date(int $day): string
    {
        return date('Y-m-d', strtotime(self::EPOCH . ' +' . $day . ' days'));
    }

    private static function daysBetween(string $from, string $to): int
    {
        return (int) round((strtotime($to) - strtotime($from)) / 86400);
    }

    private static function base32(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    private static function unbase32(string $text): ?string
    {
        // Crockford base32 tolerates the usual copy/typing confusions.
        $text = strtr($text, ['I' => '1', 'L' => '1', 'O' => '0']);
        $bits = '';
        foreach (str_split($text) as $c) {
            $v = strpos(self::ALPHABET, $c);
            if ($v === false) {
                return null;
            }
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        $bin = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $bin .= chr(bindec($byte));
            }
        }
        return $bin;
    }
}
