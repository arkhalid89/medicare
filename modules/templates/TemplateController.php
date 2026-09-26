<?php
declare(strict_types=1);

namespace App\Modules\Templates;

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\DB;
use App\Core\Paginator;
use App\Modules\Visits\VisitController;

/** Favourite templates — always scoped to the logged-in doctor. */
final class TemplateController extends Controller
{
    public function index(): void
    {
        $doctorId = (int) Auth::id();
        $q = trim((string) input('q', ''));
        $fav = (string) input('fav', '');
        $where = 't.doctor_id = ? AND t.deleted_at IS NULL';
        $params = [$doctorId];
        if ($q !== '') {
            $where .= ' AND (t.name LIKE ? OR t.description LIKE ?)';
            array_push($params, '%' . like_escape($q) . '%', '%' . like_escape($q) . '%');
        }
        if ($fav === '1') {
            $where .= ' AND t.is_favourite = 1';
        }
        $select = "SELECT t.*,
            (SELECT GROUP_CONCAT(i.name ORDER BY i.sort_order SEPARATOR ', ') FROM template_clinical_items i WHERE i.template_id = t.id AND i.item_type = 'diagnosis') AS diagnoses,
            (SELECT GROUP_CONCAT(m.medicine_name ORDER BY m.sort_order SEPARATOR ', ') FROM template_medicines m WHERE m.template_id = t.id) AS medicines,
            (SELECT COUNT(*) FROM template_medicines m WHERE m.template_id = t.id) AS medicine_count
            FROM templates t WHERE " . $where;
        $order = ' ORDER BY t.is_favourite DESC, t.usage_count DESC, t.name';

        if ($this->wantsExport()) {
            $this->export('templates', 'Favourite Templates', [
                'name'         => 'Template',
                'description'  => 'Description',
                'is_favourite' => ['Favourite', static fn ($r) => (int) $r['is_favourite'] ? 'Yes' : 'No'],
                'diagnoses'    => 'Diagnoses',
                'medicines'    => 'Medicines',
                'usage_count'  => 'Times Used',
                'updated_at'   => ['Last Updated', static fn ($r) => fmt_date($r['updated_at'] ?: $r['created_at'])],
            ], DB::run($select . $order, $params), ['Search' => $q]);
            return;
        }
        if (input('saved')) {
            flash('success', 'Template saved successfully.');
            redirect('templates');
        }
        $pager = new Paginator((int) DB::value('SELECT COUNT(*) FROM templates t WHERE ' . $where, $params));
        $this->view('templates/index', [
            'title' => 'Favourite Templates',
            'rows'  => DB::all($select . $order . $pager->limitSql(), $params),
            'pager' => $pager,
        ]);
    }

    public function editor(): void
    {
        $id = (int) input('id', 0);
        $template = null;
        if ($id > 0) {
            $template = TemplateService::loadForScreen($id, (int) Auth::id());
            if (!$template) {
                abort(404, 'Template not found.');
            }
        }
        $prefill = $template ? array_intersect_key($template, array_flip(['complaints', 'symptoms', 'diagnoses', 'investigations', 'medicines', 'overall_instructions', 'vitals'])) : [];
        $config = (new VisitController())->screenConfig('template', $prefill);
        $config['templateId'] = $template['template_id'] ?? null;
        $config['template'] = $template ? array_intersect_key($template, array_flip(['name', 'description', 'is_favourite', 'follow_up_days'])) : null;

        $this->view('templates/editor', [
            'title'    => $template ? 'Edit Template' : 'New Template',
            'scripts'  => ['js/consult.js'],
            'config'   => $config,
            'template' => $template,
        ]);
    }

    /** JSON create / update (from the editor or "Save as Template" on a visit). */
    public function save(): void
    {
        $body = json_body();
        $id = !empty($body['id']) ? (int) $body['id'] : null;
        $result = TemplateService::save($body, (int) Auth::id(), $id);
        $this->json($result, $result['ok'] ? 200 : 422);
    }

    /** JSON template content for the consultation screen. */
    public function load(): void
    {
        $template = TemplateService::loadForScreen((int) input('id', 0), (int) Auth::id());
        if (!$template) {
            $this->json(['ok' => false, 'message' => 'Template not found.'], 404);
        }
        $this->json(['ok' => true, 'template' => $template]);
    }

    public function favourite(): void
    {
        $id = $this->requireId();
        $t = TemplateService::find($id, (int) Auth::id());
        if (!$t) {
            abort(404, 'Template not found.');
        }
        $new = (int) $t['is_favourite'] ? 0 : 1;
        DB::update('templates', ['is_favourite' => $new, 'updated_at' => now(), 'updated_by' => Auth::id()], 'id = ?', [$id]);
        ActivityLog::record($new ? 'Template favourited' : 'Template unfavourited', 'templates', (string) $id, $t['name']);
        flash('success', '“' . $t['name'] . '” ' . ($new ? 'added to' : 'removed from') . ' favourites.');
        back('templates');
    }

    public function delete(): void
    {
        $id = $this->requireId();
        $t = TemplateService::find($id, (int) Auth::id());
        if (!$t) {
            abort(404, 'Template not found.');
        }
        DB::update('templates', ['deleted_at' => now(), 'deleted_by' => Auth::id()], 'id = ?', [$id]);
        ActivityLog::record('Template deleted', 'templates', (string) $id, 'Deleted template "' . $t['name'] . '"');
        flash('success', 'Template “' . $t['name'] . '” deleted.');
        redirect('templates');
    }
}
