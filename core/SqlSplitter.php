<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Splits a SQL script into individual statements, respecting quoted strings,
 * backslash escapes and comments. Streams line by line so large backup files
 * do not have to fit in memory.
 */
final class SqlSplitter
{
    /** @return \Generator<string> */
    public static function fromFile(string $path): \Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open SQL file: ' . basename($path));
        }
        try {
            yield from self::split(self::lines($handle));
        } finally {
            fclose($handle);
        }
    }

    /** @return string[] */
    public static function fromString(string $sql): array
    {
        $lines = preg_split('/(?<=\n)/', $sql) ?: [];
        return iterator_to_array(self::split($lines), false);
    }

    /** @param resource $handle */
    private static function lines($handle): \Generator
    {
        while (($line = fgets($handle)) !== false) {
            yield $line;
        }
    }

    private static function split(iterable $lines): \Generator
    {
        $buffer = '';
        $quote = null;          // current quote char, or null
        foreach ($lines as $line) {
            if ($quote === null && $buffer === '') {
                $trim = ltrim($line);
                if ($trim === '' || str_starts_with($trim, '--') || str_starts_with($trim, '#')) {
                    continue;
                }
            }
            $len = strlen($line);
            for ($i = 0; $i < $len; $i++) {
                $ch = $line[$i];
                if ($quote !== null) {
                    $buffer .= $ch;
                    if ($ch === '\\' && $quote !== '`' && $i + 1 < $len) {
                        $buffer .= $line[++$i];
                    } elseif ($ch === $quote) {
                        if ($i + 1 < $len && $line[$i + 1] === $quote) {
                            $buffer .= $line[++$i];
                        } else {
                            $quote = null;
                        }
                    }
                    continue;
                }
                if ($ch === '\'' || $ch === '"' || $ch === '`') {
                    $quote = $ch;
                    $buffer .= $ch;
                    continue;
                }
                if ($ch === '-' && $i + 1 < $len && $line[$i + 1] === '-' && ($i + 2 >= $len || ctype_space($line[$i + 2]))) {
                    break; // rest of line is a comment
                }
                if ($ch === ';') {
                    $statement = trim($buffer);
                    if ($statement !== '') {
                        yield $statement;
                    }
                    $buffer = '';
                    continue;
                }
                $buffer .= $ch;
            }
        }
        $statement = trim($buffer);
        if ($statement !== '') {
            yield $statement;
        }
    }
}
