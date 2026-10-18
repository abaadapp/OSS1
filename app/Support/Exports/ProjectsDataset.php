<?php

namespace App\Support\Exports;

use App\Http\Controllers\Admin\ProjectController;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Support\Demo;
use App\Support\Permissions;
use App\Support\ProjectWorkflow;
use App\Support\ReportPeriod;
use Illuminate\Http\Request;

/**
 * المشاريع والمعارض — بتاريخ البداية، وبمرشّحات القائمة (البحث · العميل · الحال · المسؤول).
 *
 * والمرحلةُ هي ما اختاره الفريقُ يدويًّا (`ProjectWorkflow` — `workflow_stage`)
 * تُقرأ كما هي ولا تُحرَّك. والقيمُ لمن يقرأ المالَ التجاريّ وحده
 * (`PROJECT_BUDGET_VIEW` أو `CHANGE_ORDER_CREATE`) — ولغيره لا يُكتب عمودُها أصلًا.
 */
final class ProjectsDataset extends Dataset
{
    private const STATUS = [
        Project::DRAFT => 'مسودة', Project::CONFIRMED => 'مؤكَّد', Project::IN_PROGRESS => 'قيد التنفيذ',
        Project::ON_HOLD => 'مؤجَّل', Project::CLOSED => 'مُقفَل', Project::CANCELLED => 'ملغى',
    ];

    public static function slug(): string
    {
        return 'projects';
    }

    public function title(): string
    {
        return __('المشاريع والمعارض');
    }

    private function commercial(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->may(Permissions::PROJECT_BUDGET_VIEW) || $user?->may(Permissions::CHANGE_ORDER_CREATE));
    }

    public function columns(Request $request): array
    {
        return array_values(array_filter([
            self::col('code', 'كود المشروع'), self::col('name', 'اسم المشروع'), self::col('customer', 'العميل'),
            self::col('starts_at', 'البداية'), self::col('ends_at', 'النهاية'), self::col('location', 'الموقع'),
            self::col('status_label', 'الحالة'), self::col('stage', 'المرحلة الحالية'),
            $this->commercial() ? self::col('approved_value', 'القيمة المعتمدة', 'money') : null,
            $this->commercial() ? self::col('current_value', 'القيمة الحالية', 'money') : null,
            self::col('owner', 'المسؤول'), self::col('open_tasks', 'مهامّ مفتوحة', 'number'), self::col('late_tasks', 'مهامّ متأخّرة', 'number'),
        ]));
    }

    public function data(Request $request, int $bid, int $limit): array
    {
        $commercial = $this->commercial();
        $query = ProjectController::listQuery($request, $bid);
        $total = (clone $query)->toBase()->getCountForPagination();
        $rows = [];
        $approved = 0.0;
        $current = 0.0;

        for ($offset = 0, $want = min($total, $limit); $offset < $want; $offset += 200) {
            $chunk = (clone $query)->offset($offset)->limit(min(200, $want - $offset))->get();
            /* المرحلةُ من `ProjectWorkflow` دفعةً للدفعة — كما تقرؤها بطاقاتُ القائمة */
            $flows = ProjectWorkflow::forMany($bid, $chunk);
            foreach ($chunk as $project) {
                $row = ProjectController::listRow($project, $commercial) + [
                    'location' => $project->location,
                    'status_label' => __(self::STATUS[$project->status] ?? $project->status),
                    'stage' => __(ProjectWorkflow::LABELS[$flows[(int) $project->id]['focus'] ?? ''] ?? '—'),
                ];
                $approved += (float) ($row['approved_value'] ?? 0);
                $current += (float) ($row['current_value'] ?? 0);
                $rows[] = $row;
            }
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'totals' => $commercial ? ['approved_value' => round($approved, 3), 'current_value' => round($current, 3)] : null,
        ];
    }

    public function periodLabel(Request $request): string
    {
        return __('تاريخ البداية').': '.ReportPeriod::openLabel(self::param($request, 'from'), self::param($request, 'to'));
    }

    public function filterLines(Request $request): array
    {
        $bid = (int) (auth()->user()->business_id ?? Demo::bid());

        return array_values(array_filter([
            ($q = self::param($request, 'q')) ? __('البحث').': '.$q : null,
            ($c = self::param($request, 'customer_id')) ? __('العميل').': '.(Customer::where('business_id', $bid)->whereKey((int) $c)->value('name') ?? $c) : null,
            ($s = self::param($request, 'status')) ? __('الحالة').': '.__(self::STATUS[$s] ?? $s) : null,
            ($o = self::param($request, 'owner_id')) ? __('المسؤول').': '.(User::where('business_id', $bid)->whereKey((int) $o)->value('name') ?? $o) : null,
        ]));
    }
}
