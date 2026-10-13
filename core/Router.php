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
            $route = Auth::check() ? Auth::homeRoute() : 'login';
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
            // Subscription expired while logged in: end the session.
            if (!License::allowsLogin()) {
                $message = License::status()['message'];
                Auth::logout();
                Session::start();
                if (is_ajax()) {
                    json_response(['ok' => false, 'message' => $message, 'login' => true], 401);
                }
                Session::flash('error', $message);
                redirect('license');
            }
            // Roles flagged "2FA" must set up an authenticator first (when enforced).
            if (Auth::mustSetupTwoFactor() && !in_array($route, ['profile/2fa', 'logout', 'profile/password'], true)) {
                if (is_ajax()) {
                    json_response(['ok' => false, 'message' => 'Please set up two-factor authentication first.'], 403);
                }
                Session::flash('warning', 'Your role requires two-factor authentication. Please set it up to continue.');
                redirect('profile/2fa');
            }
            if ($permission !== 'auth' && !self::allowed($permission)) {
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

    /** 'a.b' = one permission; 'a.b|c.d' = any of them. */
    public static function allowed(string $permission): bool
    {
        return str_contains($permission, '|') ? Auth::canAny(explode('|', $permission)) : Auth::can($permission);
    }
}
