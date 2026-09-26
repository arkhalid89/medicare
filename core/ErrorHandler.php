<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Converts PHP warnings/notices into exceptions (so no bug is silently
 * ignored), logs every failure to storage/logs, and shows the user a friendly
 * message instead of a raw exception (BRD §68).
 */
final class ErrorHandler
{
    public static function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '0');

        set_error_handler(static function (int $no, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $no)) {
                return false;
            }
            if ($no === E_DEPRECATED || $no === E_USER_DEPRECATED) {
                Logger::warning("Deprecated: $message in $file:$line");
                return true;
            }
            throw new \ErrorException($message, 0, $no, $file, $line);
        });

        set_exception_handler([self::class, 'handle']);

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::handle(new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']));
            }
        });
    }

    public static function handle(\Throwable $e): void
    {
        if ($e instanceof HttpException) {
            self::renderHttp($e);
            return;
        }

        $reference = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        Logger::error('[' . $reference . '] ' . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL);
            exit(1);
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
        }

        $debug = (bool) config('app.debug', false);
        $message = 'Something went wrong while processing your request. The error has been logged (ref ' . $reference . ').';
        if ($e instanceof \PDOException && str_contains($e->getMessage(), 'SQLSTATE[HY000] [')) {
            $message = 'Unable to connect to the database. Please check the database settings in config/config.php and make sure MySQL is running.';
        }

        if (is_ajax()) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'ok'      => false,
                'message' => $message,
                'debug'   => $debug ? $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() : null,
            ]);
            exit;
        }

        $file = BASE_PATH . '/views/errors/500.php';
        $exception = $debug ? $e : null;
        require $file;
        exit;
    }

    private static function renderHttp(HttpException $e): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $code = $e->getStatus();
        if (!headers_sent()) {
            // Apache turns non-standard codes such as 419 into 500, so an
            // expired form token is sent as 403 with its own message.
            http_response_code($code === 419 ? 403 : $code);
        }
        $messages = [
            403 => 'You are not authorized to access this page.',
            404 => 'The page you are looking for was not found.',
            405 => 'This action is not allowed.',
            419 => 'Your session form token has expired. Please go back, refresh the page and try again.',
        ];
        $message = $e->getMessage() !== '' ? $e->getMessage() : ($messages[$code] ?? 'Request could not be completed.');

        if (is_ajax()) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['ok' => false, 'message' => $message]);
            exit;
        }

        $layout = Auth::check() ? 'main' : 'auth';
        echo View::render('errors/http', [
            'title'   => $code === 403 ? 'Not Authorized' : ($code === 404 ? 'Page Not Found' : 'Request Error'),
            'code'    => $code,
            'message' => $message,
        ], $layout);
        exit;
    }
}
