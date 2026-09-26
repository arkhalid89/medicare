<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Front-controller router. Each module declares its own routes in
 * modules/{module}/routes.php as:
 *
 *   'patients/edit' => ['GET|POST', [PatientController::class, 'edit'], 'patients.edit'],
 *
 * Third element: permission required; 'auth' = any logged-in user;
 * null = public (login page).
 */
final class Router
{
    private static ?array $routes = null;

    public static function routes(): array
    {
        if (self::$routes === null) {
            // Each file is loaded in its own scope so variables inside a
            // routes file can never overwrite the routes collected so far.
            $load = static fn (string $file): array => require $file;
            $routes = [];
            foreach (glob(BASE_PATH . '/modules/*/routes.php') ?: [] as $file) {
                $routes += $load($file);
            }
            self::$routes = $routes;
        }
        return self::$routes;
    }

    public static function current(): string
    {
        $route = $_GET['r'] ?? '';
        $route = is_string($route) ? trim($route, '/') : '';
        return preg_match('#^[a-z0-9_\-/]*$#', $route) ? $route : '__invalid__';
    }

    public static function dispatch(): void
    {
        $route = self::current();
        if ($route === '') {
            $route = Auth::check() ? 'dashboard' : 'login';
        }

        $routes = self::routes();
        if (!isset($routes[$route])) {
            abort(404);
        }
        [$methods, $handler, $permission] = $routes[$route] + [2 => 'auth'];

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'HEAD') {
            $method = 'GET';
        }
        if ($methods !== 'ANY' && !in_array($method, explode('|', $methods), true)) {
            abort(405);
        }

        if ($permission !== null) {
            if (!Auth::check()) {
                if (is_ajax()) {
                    json_response(['ok' => false, 'message' => 'Your session has expired. Please log in again.', 'login' => true], 401);
                }
                if ($method === 'GET') {
                    Session::set('intended', $_SERVER['REQUEST_URI'] ?? '');
                }
                redirect('login');
            }
            if ($permission !== 'auth' && !Auth::can($permission)) {
                ActivityLog::record('Access denied', 'auth', null, 'Blocked access to "' . $route . '"');
                abort(403);
            }
        }

        if ($method === 'POST') {
            Csrf::verify();
        }

        [$class, $action] = $handler;
        (new $class())->$action();
    }
}
