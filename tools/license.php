<?php
/**
 * VENDOR TOOL — issues subscription keys. Never ship this folder to clinics.
 *
 *   php tools/license.php issue --phone=03001234567 --from=2026-10-01 --to=2027-09-30
 *   php tools/license.php read  --key=MCP1-XXXXX-...
 *   php tools/license.php keygen          (new vendor keypair — invalidates all issued keys!)
 *
 * The private key lives in tools/license-private.key. Keep a safe copy:
 * without it no new keys can be issued; if it leaks, anyone can make keys.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Command line only.');
}
require dirname(__DIR__) . '/core/bootstrap.php';

use App\Core\License;

$command = $argv[1] ?? '';
// getopt() stops at the first positional argument (the command), so parse --name=value here.
$opts = [];
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}
$keyFile = __DIR__ . '/license-private.key';

switch ($command) {
    case 'issue':
        foreach (['phone', 'from', 'to'] as $o) {
            if (empty($opts[$o])) {
                fwrite(STDERR, "Missing --$o\n");
                exit(1);
            }
        }
        if (!is_file($keyFile)) {
            fwrite(STDERR, "Private key not found: $keyFile\n");
            exit(1);
        }
        $key = License::issue(trim((string) file_get_contents($keyFile)), $opts['phone'], $opts['from'], $opts['to']);
        $info = License::read($key);
        if ($info === null) {
            fwrite(STDERR, "The private key does not match the public key inside core/License.php.\n");
            exit(1);
        }
        echo "Phone : {$info['phone']}\nValid : {$info['from']} to {$info['to']}\n\n$key\n";
        break;

    case 'read':
        $info = License::read((string) ($opts['key'] ?? ''));
        echo $info ? "VALID — phone {$info['phone']}, {$info['from']} to {$info['to']}\n" : "INVALID key\n";
        exit($info ? 0 : 1);

    case 'keygen':
        if (is_file($keyFile) && !isset($opts['force'])) {
            fwrite(STDERR, "A private key already exists. Use --force to replace it (all issued keys stop working).\n");
            exit(1);
        }
        $kp = sodium_crypto_sign_keypair();
        file_put_contents($keyFile, base64_encode(sodium_crypto_sign_secretkey($kp)) . PHP_EOL);
        echo "Private key written to $keyFile\nPut this PUBLIC key into core/License.php (PUBLIC_KEY):\n" . base64_encode(sodium_crypto_sign_publickey($kp)) . "\n";
        break;

    default:
        echo "Usage:\n  php tools/license.php issue --phone=03001234567 --from=YYYY-MM-DD --to=YYYY-MM-DD\n  php tools/license.php read --key=MCP1-...\n  php tools/license.php keygen [--force]\n";
}
