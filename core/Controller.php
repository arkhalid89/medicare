<?php
declare(strict_types=1);

namespace App\Core;

/** Base controller: rendering, JSON, Excel export and common guards. */
abstract class Controller
{
    protected function view(string $view, array $data = [], ?string $layout = 'main'): void
    {
        echo View::render($view, $data, $layout);
    }

    protected function json(array $data, int $status = 200): void
    {
        json_response($data, $status);
    }

    protected function wantsExport(): bool
    {
        return ($_GET['export'] ?? '') === 'xlsx';
    }

    /**
     * Streams an Excel file of the current listing and logs the export.
     * @param array $columns key => label, or key => [label, callable formatter]
     */
    protected function export(string $name, string $title, array $columns, iterable $rows, array $filters = []): void
    {
        ActivityLog::record('Excel export', 'export', null, $title);
        Exporter::download($name, $title, $columns, $rows, $filters);
    }

    /** Positive integer from the query/body, or 404. */
    protected function requireId(string $key = 'id'): int
    {
        $id = (int) ($_POST[$key] ?? $_GET[$key] ?? 0);
        if ($id <= 0) {
            abort(404);
        }
        return $id;
    }
}
