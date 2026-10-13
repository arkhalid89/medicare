<?php
declare(strict_types=1);

namespace App\Modules\License;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\License;

/**
 * Subscription: status and activation (reachable without login), plus for the
 * Super Admin the key generator (vendor installation only) and the logs.
 */
final class LicenseController extends Controller
{
    public function index(): void
    {
        $this->render(null, null, []);
    }

    public function activate(): void
    {
        $result = License::activate((string) input('phone', ''), (string) input('key', ''));
        if ($result['ok']) {
            flash('success', $result['message']);
            redirect(Auth::check() ? 'license' : 'login');
        }
        $this->render($result['message'], null, []);
    }

    public function generate(): void
    {
        $result = License::generate($_POST);
        if ($result['ok']) {
            flash('success', $result['message'] . ' Copy the key below and send it to the clinic.');
            $_SESSION['license_new_key'] = $result['key'];
            redirect('license');
        }
        $this->render(null, $result['message'], $result['errors'] ?? []);
    }

    public function exportLog(): void
    {
        $type = input('type') === 'activations' ? 'activations' : 'issued';
        if ($type === 'issued') {
            $this->export('subscription_keys_issued', 'Subscription Keys Issued', [
                'created_at'     => ['Issued On', static fn ($r) => fmt_datetime($r['created_at'])],
                'clinic_name'    => 'Clinic',
                'phone'          => 'Phone',
                'from_date'      => ['Valid From', static fn ($r) => fmt_date($r['from_date'])],
                'to_date'        => ['Valid To', static fn ($r) => fmt_date($r['to_date'])],
                'status'         => 'Status',
                'amount'         => ['Amount', static fn ($r) => (float) $r['amount']],
                'payment_ref'    => 'Payment Ref',
                'notes'          => 'Notes',
                'issued_by_name' => 'Issued By',
                'license_key'    => 'Key',
            ], License::issuedLog(100000));
            return;
        }
        $this->export('subscription_activations', 'Subscription Activations', [
            'activated_at'      => ['Activated On', static fn ($r) => fmt_datetime($r['activated_at'])],
            'phone'             => 'Phone',
            'from_date'         => ['Valid From', static fn ($r) => fmt_date($r['from_date'])],
            'to_date'           => ['Valid To', static fn ($r) => fmt_date($r['to_date'])],
            'status'            => 'Status',
            'activated_by_name' => 'Activated By',
        ], License::activationLog(100000));
    }

    private function render(?string $error, ?string $generateError, array $fieldErrors): void
    {
        $admin = Auth::check() && can('settings.manage');
        $newKey = $_SESSION['license_new_key'] ?? null;
        unset($_SESSION['license_new_key']);
        $this->view('license/index', [
            'title'         => 'Subscription',
            'status'        => License::status(),
            'error'         => $error,
            'admin'         => $admin,
            'canIssue'      => $admin && License::canIssue(),
            'issued'        => $admin ? License::issuedLog() : [],
            'activations'   => $admin ? License::activationLog() : [],
            'newKey'        => $newKey,
            'generateError' => $generateError,
            'fieldErrors'   => $fieldErrors,
        ], Auth::check() ? 'main' : 'auth');
    }
}
