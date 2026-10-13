<?php
declare(strict_types=1);

namespace App\Modules\Reports;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\DB;
use App\Core\Migrator;

/**
 * v2 hospital operations reports (Doc 2 §5.12): revenue by department /
 * module, token waiting times and stock valuation. Branch-scoped; Excel export.
 */
final class OpsReportController extends Controller
{
    /** Billed, collected and refunded by department and by source module. */
    public function revenue(): void
    {
        [$from, $to] = $this->range();
        $branch = $this->branchSql('i.branch_id');
        $p = array_merge([$from . ' 00:00:00', $to . ' 23:59:59'], $branch[1]);
        $byDept = DB::all("SELECT COALESCE(d.name, 'Unassigned') AS label, COUNT(DISTINCT i.id) AS invoices,
                                  SUM(ii.amount) AS billed, SUM(ii.refunded_amount) AS refunded
                             FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id LEFT JOIN departments d ON d.id = i.department_id
                            WHERE ii.is_void = 0 AND i.status <> 'void' AND i.created_at BETWEEN ? AND ?" . $branch[0] . '
                            GROUP BY label ORDER BY billed DESC', $p);
        $byModule = DB::all("SELECT ii.source_module AS label, COUNT(*) AS line_count, SUM(ii.amount) AS billed, SUM(ii.refunded_amount) AS refunded
                               FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id
                              WHERE ii.is_void = 0 AND i.status <> 'void' AND i.created_at BETWEEN ? AND ?" . $branch[0] . '
                              GROUP BY ii.source_module ORDER BY billed DESC', $p);
        $pb = $this->branchSql('pm.branch_id');
        $collected = DB::all('SELECT pm.mode AS label, COUNT(*) AS n, SUM(pm.amount) AS amount FROM payments pm
                               WHERE pm.is_void = 0 AND pm.collected_at BETWEEN ? AND ?' . $pb[0] . ' GROUP BY pm.mode ORDER BY amount DESC',
            array_merge([$from . ' 00:00:00', $to . ' 23:59:59'], $pb[1]));
        $rb = $this->branchSql('r.branch_id');
        $refunds = (float) DB::value("SELECT COALESCE(SUM(r.amount), 0) FROM refunds r WHERE r.status = 'processed' AND r.initiated_at BETWEEN ? AND ?" . $rb[0],
            array_merge([$from . ' 00:00:00', $to . ' 23:59:59'], $rb[1]));
        $discounts = (float) DB::value("SELECT COALESCE(SUM(i.discount_amount), 0) FROM invoices i WHERE i.status <> 'void' AND i.discount_status IN ('none','approved')
                                          AND i.created_at BETWEEN ? AND ?" . $branch[0], $p);
        if ($this->wantsExport()) {
            $rows = array_merge(array_map(static fn ($r) => ['group' => 'Department'] + $r, $byDept), array_map(static fn ($r) => ['group' => 'Module'] + $r, $byModule));
            $this->export('revenue', 'Revenue by department & module', ['group' => 'Group', 'label' => 'Name', 'billed' => ['Billed', static fn ($r) => (float) $r['billed']],
                'refunded' => ['Refunded', static fn ($r) => (float) $r['refunded']]], $rows, ['From' => $from, 'To' => $to]);
            return;
        }
        $this->view('reports/revenue', ['title' => 'Revenue by Department', 'from' => $from, 'to' => $to, 'byDept' => $byDept, 'byModule' => $byModule,
            'collected' => $collected, 'refunds' => $refunds, 'discounts' => $discounts]);
    }

    /** Tokens issued / served / no-show and average waiting & service minutes per queue and hour. */
    public function tokens(): void
    {
        [$from, $to] = $this->range();
        $branch = $this->branchSql('q.branch_id');
        $p = array_merge([$from, $to], $branch[1]);
        $byQueue = Migrator::tableExists('tokens') ? DB::all("SELECT q.name AS label, COUNT(*) AS issued,
                                    SUM(t.status = 'completed') AS served, SUM(t.status IN ('no_show','skipped','cancelled')) AS missed,
                                    SUM(t.priority <> 'normal') AS priority,
                                    AVG(CASE WHEN t.called_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, t.issued_at, t.called_at) END) AS avg_wait,
                                    MAX(CASE WHEN t.called_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, t.issued_at, t.called_at) END) AS max_wait,
                                    AVG(CASE WHEN t.completed_at IS NOT NULL AND t.serving_started_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, t.serving_started_at, t.completed_at) END) AS avg_service
                               FROM tokens t JOIN token_queues q ON q.id = t.queue_id
                              WHERE t.issue_date BETWEEN ? AND ?" . $branch[0] . ' GROUP BY q.id, q.name ORDER BY issued DESC', $p) : [];
        $byHour = Migrator::tableExists('tokens') ? DB::pairs('SELECT HOUR(t.issued_at) AS h, COUNT(*) FROM tokens t JOIN token_queues q ON q.id = t.queue_id
                                                                WHERE t.issue_date BETWEEN ? AND ?' . $branch[0] . ' GROUP BY h ORDER BY h', $p) : [];
        if ($this->wantsExport()) {
            $this->export('tokens', 'Token & waiting times', ['label' => 'Queue', 'issued' => 'Issued', 'served' => 'Served', 'missed' => 'No-show / skipped',
                'avg_wait' => ['Avg wait (min)', static fn ($r) => round((float) $r['avg_wait'], 1)], 'max_wait' => 'Max wait (min)',
                'avg_service' => ['Avg service (min)', static fn ($r) => round((float) $r['avg_service'], 1)]], $byQueue, ['From' => $from, 'To' => $to]);
            return;
        }
        $this->view('reports/tokens', ['title' => 'Token & Waiting Times', 'from' => $from, 'to' => $to, 'byQueue' => $byQueue, 'byHour' => $byHour]);
    }

    /** Stock on hand valued at average cost (and at sale price) per location and category. */
    public function stock(): void
    {
        $loc = (int) input('location_id', 0);
        $branch = $this->branchSql('l.branch_id');
        $where = 'b.qty_on_hand <> 0' . $branch[0] . ($loc ? ' AND l.id = ?' : '');
        $params = array_merge($branch[1], $loc ? [$loc] : []);
        $byLocation = DB::all("SELECT l.name AS label, l.location_type, COUNT(DISTINCT b.item_id) AS items, SUM(b.qty_on_hand * i.avg_cost) AS cost_value,
                                      SUM(b.qty_on_hand * COALESCE(i.sale_price, 0)) AS sale_value,
                                      SUM(CASE WHEN sb.expiry_date IS NOT NULL AND sb.expiry_date < CURDATE() THEN b.qty_on_hand * i.avg_cost ELSE 0 END) AS expired_value
                                 FROM stock_balances b JOIN stock_locations l ON l.id = b.location_id JOIN items i ON i.id = b.item_id
                                 LEFT JOIN stock_batches sb ON sb.id = b.batch_id
                                WHERE $where GROUP BY l.id, l.name, l.location_type ORDER BY cost_value DESC", $params);
        $byCategory = DB::all("SELECT COALESCE(c.name, 'Uncategorised') AS label, COUNT(DISTINCT b.item_id) AS items, SUM(b.qty_on_hand * i.avg_cost) AS cost_value,
                                      SUM(b.qty_on_hand * COALESCE(i.sale_price, 0)) AS sale_value
                                 FROM stock_balances b JOIN stock_locations l ON l.id = b.location_id JOIN items i ON i.id = b.item_id
                                 LEFT JOIN item_categories c ON c.id = i.category_id
                                WHERE $where GROUP BY label ORDER BY cost_value DESC", $params);
        if ($this->wantsExport()) {
            $this->export('stock_valuation', 'Stock valuation', ['label' => 'Location', 'items' => 'Items', 'cost_value' => ['Value at cost', static fn ($r) => round((float) $r['cost_value'], 2)],
                'sale_value' => ['Value at sale price', static fn ($r) => round((float) $r['sale_value'], 2)], 'expired_value' => ['Expired (cost)', static fn ($r) => round((float) $r['expired_value'], 2)]],
                $byLocation, ['Date' => today()]);
            return;
        }
        $this->view('reports/stock', ['title' => 'Stock Valuation', 'byLocation' => $byLocation, 'byCategory' => $byCategory, 'loc' => $loc,
            'locations' => DB::pairs('SELECT id, name FROM stock_locations WHERE deleted_at IS NULL ORDER BY name')]);
    }

    /** @return array{0:string,1:string} */
    private function range(): array
    {
        $from = (string) input('from', date('Y-m-01'));
        $to = (string) input('to', today());
        $ok = static fn (string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
        return [$ok($from) ? $from : date('Y-m-01'), $ok($to) ? $to : today()];
    }

    /** Restricts to the active branch unless the user has chain-wide reporting. */
    private function branchSql(string $col): array
    {
        if (Auth::scope('reports.view') === 'chain' || !Auth::branchId()) {
            return ['', []];
        }
        return [" AND ($col = ? OR $col IS NULL)", [Auth::branchId()]];
    }
}
