<?php
declare(strict_types=1);

namespace App\Core;

/**
 * "Export to Excel" for every listing. The export always uses the same
 * filters the user is looking at, without pagination.
 */
final class Exporter
{
    public const MAX_ROWS = 200000;

    /**
     * @param array $columns key => 'Label'  or  key => ['Label', fn(array $row): mixed]
     * @param array $filters label => value, printed above the table
     */
    public static function build(string $title, array $columns, iterable $rows, array $filters = []): string
    {
        $xlsx = new XlsxWriter();
        $xlsx->addRow([setting('org_name', 'MediCare') . ' — ' . $title], XlsxWriter::STYLE_TITLE);

        $meta = 'Generated ' . date('d-m-Y h:i A');
        $user = Auth::user();
        if ($user) {
            $meta .= ' by ' . $user['name'];
        }
        $active = array_filter($filters, static fn ($v) => $v !== null && $v !== '');
        if ($active) {
            $parts = [];
            foreach ($active as $label => $value) {
                $parts[] = $label . ': ' . $value;
            }
            $meta .= '   |   Filters — ' . implode(', ', $parts);
        }
        $xlsx->addRow([$meta], XlsxWriter::STYLE_META);
        $xlsx->addRow([], XlsxWriter::STYLE_META);

        $headers = [];
        foreach ($columns as $spec) {
            $headers[] = is_array($spec) ? $spec[0] : $spec;
        }
        $xlsx->addRow(array_merge(['#'], $headers), XlsxWriter::STYLE_HEADER);

        $i = 0;
        foreach ($rows as $row) {
            if (++$i > self::MAX_ROWS) {
                break;
            }
            $cells = [$i];
            foreach ($columns as $key => $spec) {
                $cells[] = is_array($spec) && isset($spec[1]) && is_callable($spec[1]) ? $spec[1]($row) : ($row[$key] ?? '');
            }
            $xlsx->addRow($cells);
        }
        if ($i === 0) {
            $xlsx->addRow(['', 'No records found for the selected filters.']);
        }
        return $xlsx->toString($title);
    }

    public static function download(string $name, string $title, array $columns, iterable $rows, array $filters = []): void
    {
        $content = self::build($title, $columns, $rows, $filters);
        $file = preg_replace('/[^A-Za-z0-9_\-]/', '_', $name) . '_' . date('Ymd_His') . '.xlsx';
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: no-store');
        echo $content;
        exit;
    }
}
