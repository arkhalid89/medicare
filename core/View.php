<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Plain-PHP templates.
 *   'patients/index'  => modules/patients/views/index.php
 *   'layouts/main'    => views/layouts/main.php   (shared UI)
 *   'partials/x'      => views/partials/x.php
 *   'errors/http'     => views/errors/http.php
 */
final class View
{
    private const SHARED = ['layouts', 'partials', 'errors'];

    public static function file(string $view): string
    {
        [$first, $rest] = array_pad(explode('/', $view, 2), 2, '');
        $file = in_array($first, self::SHARED, true)
            ? BASE_PATH . '/views/' . $view . '.php'
            : BASE_PATH . '/modules/' . $first . '/views/' . $rest . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('View not found: ' . $view);
        }
        return $file;
    }

    public static function render(string $view, array $data = [], ?string $layout = 'main'): string
    {
        $content = self::capture(self::file($view), $data);
        if ($layout === null) {
            return $content;
        }
        return self::capture(self::file('layouts/' . $layout), array_merge($data, ['content' => $content]));
    }

    public static function partial(string $name, array $data = []): string
    {
        return self::capture(self::file('partials/' . $name), $data);
    }

    private static function capture(string $__file, array $__data): string
    {
        extract($__data, EXTR_SKIP);
        ob_start();
        try {
            include $__file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
