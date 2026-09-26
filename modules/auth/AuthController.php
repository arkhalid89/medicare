<?php
declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Backup;
use App\Core\Controller;
use App\Core\DB;
use App\Core\Password;
use App\Core\Session;
use App\Core\Validator;

final class AuthController extends Controller
{
    /** Landing page + login (the application's index). */
    public function login(): void
    {
        if (Auth::check()) {
            redirect('dashboard');
        }
        $error = null;
        if (is_post()) {
            $username = (string) input('username', '');
            $password = (string) ($_POST['password'] ?? '');
            if ($username === '' || $password === '') {
                $error = 'Please enter your username and password.';
            } else {
                $result = Auth::attempt($username, $password);
                if ($result['ok']) {
                    $auto = Backup::runScheduled();
                    flash('success', $result['message'] . ($auto ? ' An automatic backup was created.' : ''));
                    $intended = (string) Session::get('intended', '');
                    Session::forget('intended');
                    if ($intended !== '' && str_starts_with($intended, (string) (parse_url(base_url(), PHP_URL_PATH) ?: '/'))) {
                        redirect_to($intended);
                    }
                    redirect('dashboard');
                }
                $error = $result['message'];
            }
        }

        $sampleUsers = [];
        if (config('app.debug')) {
            $sampleUsers = DB::all(
                "SELECT username, password, role FROM users WHERE deleted_at IS NULL AND is_active = 1
                  AND username IN ('superadmin','deptadmin','doctor','doctor2','reporting') ORDER BY FIELD(role,'super_admin','dept_admin','doctor','reporting'), username"
            );
        }
        $this->view('auth/login', ['title' => 'Sign in', 'error' => $error, 'sampleUsers' => $sampleUsers], 'auth');
    }

    public function logout(): void
    {
        Auth::logout();
        Session::start();
        flash('success', 'You have been logged out.');
        redirect('login');
    }

    public function profile(): void
    {
        $user = Auth::user();
        $errors = [];
        if (is_post()) {
            $v = new Validator($_POST);
            $v->required('name', 'Name')->max('name', 'Name', 150)
                ->phone('mobile', 'Mobile')->email('email', 'Email')->max('email', 'Email', 150);
            if ($user['role'] === 'doctor') {
                $v->max('pmdc_registration', 'PMDC registration', 50)->max('qualification', 'Qualification', 255)
                    ->max('specialization', 'Specialization', 150)->numeric('default_fee', 'Default fee', 0, 10000000)
                    ->max('signature_text', 'Signature text', 255);
            }
            $errors = $v->errors();
            if (!$errors) {
                DB::transaction(static function () use ($user): void {
                    DB::update('users', [
                        'name'       => trim((string) input('name')),
                        'mobile'     => normalize_phone((string) input('mobile', '')),
                        'email'      => input('email') ?: null,
                        'updated_at' => now(),
                        'updated_by' => $user['id'],
                    ], 'id = ?', [$user['id']]);
                    if ($user['role'] === 'doctor') {
                        DB::update('doctor_profiles', [
                            'pmdc_registration' => input('pmdc_registration') ?: null,
                            'qualification'     => input('qualification') ?: null,
                            'specialization'    => input('specialization') ?: null,
                            'default_fee'       => (float) input('default_fee', 0),
                            'signature_text'    => input('signature_text') ?: null,
                            'updated_at'        => now(),
                        ], 'user_id = ?', [$user['id']]);
                    }
                    ActivityLog::record('Profile updated', 'users', (string) $user['id'], 'Updated own profile');
                });
                flash('success', 'Profile updated successfully.');
                redirect('profile');
            }
        }
        $departments = DB::column(
            'SELECT d.name FROM departments d JOIN user_departments ud ON ud.department_id = d.id WHERE ud.user_id = ? AND d.deleted_at IS NULL ORDER BY d.name',
            [$user['id']]
        );
        $this->view('auth/profile', ['title' => 'My Profile', 'user' => $user, 'errors' => array_values($errors), 'departments' => $departments]);
    }

    public function password(): void
    {
        $user = Auth::user();
        $errors = [];
        if (is_post()) {
            $stored = (string) DB::value('SELECT password FROM users WHERE id = ?', [$user['id']]);
            $v = new Validator($_POST);
            $v->required('current_password', 'Current password')
                ->required('new_password', 'New password')->min('new_password', 'New password', Password::minLength())->max('new_password', 'New password', 100)
                ->check('confirm_password', ($_POST['new_password'] ?? '') === ($_POST['confirm_password'] ?? ''), 'New password and confirmation do not match.');
            if (!$v->fails() && !Password::verify((string) $_POST['current_password'], $stored)) {
                $v->add('current_password', 'Current password is incorrect.');
            }
            $errors = $v->errors();
            if (!$errors) {
                DB::update('users', ['password' => Password::hash((string) $_POST['new_password']), 'updated_at' => now(), 'updated_by' => $user['id']], 'id = ?', [$user['id']]);
                ActivityLog::record('Password changed', 'users', (string) $user['id'], 'Changed own password');
                flash('success', 'Password changed successfully.');
                redirect('profile');
            }
        }
        $this->view('auth/password', ['title' => 'Change Password', 'errors' => array_values($errors)]);
    }
}
