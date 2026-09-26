<?php
/**
 * Syntax-checks every PHP file of the project:  php tests/lint.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$failed = 0;
$count = 0;
foreach ($it as $file) {
    if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR)) {
        continue;
    }
    $count++;
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        $failed++;
        echo implode("\n", $out), "\n";
    }
}
echo "Checked $count files, $failed with errors.\n";
exit($failed ? 1 : 0);
