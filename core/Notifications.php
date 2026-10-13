<?php
declare(strict_types=1);

namespace App\Core;

/**
 * "Waiting for you" counters in the top bar: approvals, quality gates,
 * queues and alerts, each shown only to users holding the permission that
 * acts on it. Every query is cheap (indexed status columns) and a missing
 * table (module not installed yet) is simply skipped.
 */
final class Notifications
{
    /** @return array<int, array{label:string, icon:string, url:string, count:int}> */
    public static function forUser(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $uid = (int) Auth::id();
        $today = today();
        $defs = [
            ['billing.refund.approve', 'Refunds to approve', 'check', 'billing/approvals',
                "SELECT COUNT(*) FROM refunds WHERE status = 'requested' AND source_module = 'billing'", []],
            ['billing.discount.approve', 'Discounts to approve', 'check', 'billing/approvals',
                "SELECT COUNT(*) FROM invoices WHERE discount_status = 'pending'", []],
            ['billing.refund.process', 'Refunds to pay out', 'money', 'billing',
                "SELECT COUNT(*) FROM refunds WHERE status = 'approved'", []],
            ['appointments.leave.approve', 'Leave requests', 'calendar', 'appointments/leaves',
                "SELECT COUNT(*) FROM doctor_leaves WHERE status = 'pending'", []],
            ['emergency.review', 'Break-glass access to review', 'alert', 'emergency',
                'SELECT COUNT(*) FROM emergency_access_grants WHERE reviewed_at IS NULL', []],
            ['clinical.cosign', 'Resident entries to co-sign', 'check', 'clinical/cosign',
                'SELECT COUNT(*) FROM visits WHERE cosign_required = 1 AND cosigned_by IS NULL AND deleted_at IS NULL', []],
            ['lab.sample.review', 'Samples awaiting acceptance', 'flask', 'lab/pathologist',
                "SELECT COUNT(*) FROM lab_samples s WHERE s.status = 'collected'", []],
            ['lab.result.verify', 'Results to verify', 'flask', 'lab/pathologist?tab=verify',
                "SELECT COUNT(*) FROM lab_order_items WHERE status = 'result_entered'", []],
            ['lab.sample.collect', 'Samples to collect', 'droplet', 'lab/collection',
                "SELECT COUNT(DISTINCT lo.patient_id) FROM lab_order_items li JOIN lab_orders lo ON lo.id = li.lab_order_id WHERE li.status IN ('ordered','sample_pending','resample_pending')", []],
            ['visits.create', 'Critical lab values', 'alert', 'lab/critical',
                'SELECT COUNT(*) FROM lab_critical_notifications WHERE notified_user_id = ? AND acknowledged_at IS NULL', [$uid]],
            ['pharmacy.dispense', 'Prescriptions to dispense', 'pill', 'pharmacy',
                "SELECT COUNT(*) FROM dispenses WHERE status IN ('pending','partial')", []],
            ['pharmacy.dispense.finalize', 'Controlled drugs to co-sign', 'shield', 'pharmacy?tab=cosign',
                "SELECT COUNT(*) FROM dispenses WHERE status = 'awaiting_cosign'", []],
            ['inventory.requisition.approve', 'Indents to approve', 'send', 'inventory/indents?status=submitted',
                "SELECT COUNT(*) FROM stock_requisitions WHERE status = 'submitted'", []],
            ['inventory.issue', 'Approved indents to issue', 'box', 'inventory/indents?status=approved',
                "SELECT COUNT(*) FROM stock_requisitions WHERE status IN ('approved','partially_issued')", []],
            ['inventory.po.approve', 'Purchase orders to approve', 'truck', 'inventory/po?status=draft',
                "SELECT COUNT(*) FROM purchase_orders WHERE status = 'submitted'", []],
            ['inventory.adjust.approve', 'Stock adjustments to approve', 'sliders', 'inventory/adjustments',
                "SELECT COUNT(*) FROM stock_adjustments WHERE status = 'proposed' AND proposed_by <> ?", [$uid]],
            ['inventory.view', 'Items below reorder level', 'alert', 'inventory/stock?low=1',
                'SELECT COUNT(*) FROM item_location_rules r WHERE r.min_qty > 0 AND COALESCE((SELECT SUM(b.qty_on_hand) FROM stock_balances b WHERE b.item_id = r.item_id AND b.location_id = r.location_id), 0) < r.min_qty', []],
            ['radiology.review', 'Studies awaiting review', 'image', 'radiology/radiologist',
                "SELECT COUNT(*) FROM radiology_orders WHERE status = 'under_review'", []],
            ['radiology.report', 'Studies to report', 'file-text', 'radiology/radiologist?tab=report',
                "SELECT COUNT(*) FROM radiology_orders WHERE status = 'accepted'", []],
            ['radiology.acquire', 'Imaging to perform', 'image', 'radiology/technician',
                "SELECT COUNT(*) FROM radiology_orders WHERE status IN ('ordered','scheduled','reprocess_requested')", []],
            ['radiology.schedule', 'Imaging to screen / book', 'calendar', 'radiology/reception',
                "SELECT COUNT(*) FROM radiology_orders WHERE status = 'ordered'", []],
            ['visits.create', 'Critical imaging findings', 'alert', 'radiology/orders?status=reported',
                'SELECT COUNT(*) FROM radiology_reports WHERE critical_notified_user_id = ? AND critical_acknowledged_at IS NULL', [$uid]],
            ['clinical.referral.create', 'Open referrals to you', 'send', 'clinical/referrals',
                "SELECT COUNT(*) FROM referrals WHERE status = 'open' AND to_doctor_id = ?", [$uid]],
            ['obgyn.pregnancy.manage', 'Delivery records to confirm', 'heart', 'obgyn/anc?filter=delivered',
                'SELECT COUNT(*) FROM pregnancy_deliveries WHERE is_draft = 1 AND recorded_by <> ?', [$uid]],
            ['dental.labcase.update', 'Dental lab cases', 'box', 'dental/lab-cases',
                "SELECT COUNT(*) FROM dental_lab_cases WHERE status IN ('created','sent','in_progress') AND (technician_user_id = ? OR technician_user_id IS NULL)", [$uid]],
            ['frontdesk.checkin', "Today's appointments to check in", 'calendar', 'appointments',
                "SELECT COUNT(*) FROM appointments WHERE status = 'booked' AND scheduled_at BETWEEN ? AND ?", [$today . ' 00:00:00', $today . ' 23:59:59']],
        ];
        $out = [];
        foreach ($defs as [$perm, $label, $icon, $route, $sql, $params]) {
            if (!Auth::can($perm)) {
                continue;
            }
            try {
                $n = (int) DB::value($sql, $params);
            } catch (\PDOException $e) {
                continue;
            }
            if ($n > 0) {
                [$path, $query] = array_pad(explode('?', $route, 2), 2, '');
                parse_str($query, $q);
                $out[] = ['label' => $label, 'icon' => $icon, 'url' => url($path, $q), 'count' => $n];
            }
        }
        return $cache = $out;
    }
}
