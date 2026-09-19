<?php

namespace App\Support;

use App\Models\ChangeOrder;
use App\Models\ChangeOrderItem;
use App\Models\Project;
use App\Models\ProjectItem;
use App\Models\ProjectRequirement;
use App\Models\QuotationItem;

/**
 * نطاقُ المشروع — الأصلُ، وما تغيّر، وما هو الآن. قراءةٌ لا كتابة.
 *
 * ═══ ثلاثةُ أسئلةٍ يجيبها ═══
 *
 * • **الأصل** — ما وافق عليه العميل يومَ القبول: العرضُ ببرهانه، والمتطلّباتُ
 *   المثبَّتة، والقيمةُ الأصليّة.
 * • **التغييرات** — أوامرُ التغيير المقبولة بترتيبها: من طلب، وما تغيّر،
 *   ومن اعتمد، ومتى قَبِل العميل.
 * • **الحاليّ** — ما يجب على الفريق تنفيذَه الآن: البنودُ بكميّاتها الحاليّة،
 *   والمتطلّباتُ القائمة، والموعدُ، والقيمةُ الحاليّة **المشتقّة**.
 *
 * ═══ والحارس ═══
 *
 * `verify()`: كميّةُ كلّ بندٍ = كميّةُ العرض + مجموعُ فروق الأوامر المقبولة.
 * فإن افترقا فقد كُتب في البند من غير بابه.
 */
final class ProjectScope
{
    /** القيمةُ المعتمَدةُ الحاليّة — الأصلُ + فروقُ الأوامر المقبولة، ولا عمودَ لها */
    public static function currentValue(Project $project): float
    {
        $deltas = (float) ChangeOrder::where('project_id', $project->id)->accepted()->sum('total_delta');

        return round((float) $project->approved_value + $deltas, 3);
    }

    /**
     * @return array<string, mixed>
     */
    public static function original(Project $project): array
    {
        $quotation = $project->quotation?->load('items', 'approval');

        return [
            'quotation' => $quotation === null ? null : [
                'id' => $quotation->id,
                'label' => Quotations::label($quotation),
                'total' => (float) $quotation->total,
                'content_hash' => $quotation->content_hash,
                'decided_at' => optional($quotation->decided_at)->format('Y-m-d H:i'),
                'decided_by_name' => $quotation->decided_by_name,
                'event_starts_at' => optional($quotation->event_starts_at)->format('Y-m-d'),
                'event_ends_at' => optional($quotation->event_ends_at)->format('Y-m-d'),
                /* والبرهانُ يُعاد فحصُه عند كلّ قراءة — دليلٌ لا شهادة */
                'evidence' => $quotation->approval === null ? null : [
                    'signer_name' => $quotation->approval->signer_name,
                    'decided_at' => optional($quotation->approval->decided_at)->format('Y-m-d H:i'),
                    'verified' => $quotation->approval->verify(),
                ],
            ],
            'items' => $quotation === null ? [] : $quotation->items->map(fn (QuotationItem $i) => [
                'id' => $i->id,
                'description' => $i->description,
                'unit' => $i->unit,
                'quantity' => (float) $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'line_total' => (float) $i->line_total,
            ])->all(),
            'requirements' => $project->requirements()->baseline()->get()
                ->map(fn (ProjectRequirement $r) => self::requirementRow($r))->all(),
            'approved_value' => round((float) $project->approved_value, 3),
            'baselined_at' => optional($project->requirements_baselined_at)->format('Y-m-d H:i'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function changes(Project $project): array
    {
        return ChangeOrder::where('project_id', $project->id)->accepted()
            ->with('items', 'addedRequirements', 'removedRequirements', 'approval')
            ->orderBy('sequence')->get()
            ->map(fn (ChangeOrder $co) => [
                'id' => $co->id,
                'number' => $co->number,
                'sequence' => $co->sequence,
                'title' => $co->title,
                'requested_by_party' => $co->requested_by_party,
                'requested_by_name' => $co->requested_by_name,
                'total_delta' => (float) $co->total_delta,
                'new_starts_at' => optional($co->new_starts_at)->format('Y-m-d'),
                'new_ends_at' => optional($co->new_ends_at)->format('Y-m-d'),
                'approved_by' => $co->approved_by,
                'decided_at' => optional($co->decided_at)->format('Y-m-d H:i'),
                'decided_by_name' => $co->decided_by_name,
                'verified' => $co->approval?->verify(),
                'items' => $co->items->map(fn (ChangeOrderItem $i) => [
                    'project_item_id' => $i->project_item_id,
                    'description' => $i->description,
                    'quantity' => (float) $i->quantity,
                    'unit_price' => (float) $i->unit_price,
                    'line_total' => (float) $i->line_total,
                ])->all(),
                'added' => $co->addedRequirements->map(fn ($r) => self::requirementRow($r))->all(),
                'removed' => $co->removedRequirements->map(fn ($r) => self::requirementRow($r))->all(),
            ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function current(Project $project): array
    {
        return [
            'items' => $project->items()->get()->map(fn (ProjectItem $i) => [
                'id' => $i->id,
                'description' => $i->description,
                'unit' => $i->unit,
                'quantity' => (float) $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'tax_rate' => (float) $i->tax_rate,
                'line_total' => (float) $i->line_total,
                'delivered_quantity' => (float) $i->delivered_quantity,
                'status' => $i->status,
                'origin' => $i->quotation_item_id !== null ? 'quotation' : 'change_order',
                'change_order_id' => $i->change_order_id,
            ])->all(),
            'requirements' => $project->requirements()->active()->get()
                ->map(fn (ProjectRequirement $r) => self::requirementRow($r))->all(),
            'starts_at' => optional($project->starts_at)->format('Y-m-d'),
            'ends_at' => optional($project->ends_at)->format('Y-m-d'),
            'current_value' => self::currentValue($project),
        ];
    }

    /**
     * الحارس: كلُّ بندٍ كميّتُه = أصلُه + فروقُ الأوامر المقبولة.
     *
     * @return list<string> ما افترق — وفارغةٌ إن استقام كلُّ شيء
     */
    public static function verify(Project $project): array
    {
        $deltas = ChangeOrderItem::whereNotNull('project_item_id')
            ->whereHas('changeOrder', fn ($q) => $q->where('project_id', $project->id)->accepted())
            ->groupBy('project_item_id')
            ->selectRaw('project_item_id, SUM(quantity) AS delta')
            ->pluck('delta', 'project_item_id');

        $broken = [];

        foreach ($project->items()->with('quotationItem', 'changeOrderItem')->get() as $item) {
            /* أصلُه: كميّةُ بند العرض، أو كميّةُ سطر الأمر الذي ولّده */
            $origin = $item->quotationItem !== null
                ? (float) $item->quotationItem->quantity
                : (float) ($item->changeOrderItem?->quantity ?? 0);
            $expected = round($origin + (float) ($deltas[$item->id] ?? 0), 3);

            if (abs($expected - (float) $item->quantity) > 0.0005) {
                $broken[] = $item->description.': '.$item->quantity.' ≠ '.$expected;
            }
        }

        return $broken;
    }

    /** @return array<string, mixed> */
    private static function requirementRow(ProjectRequirement $r): array
    {
        return [
            'id' => $r->id,
            'title' => $r->title,
            'details' => $r->details,
            'project_item_id' => $r->project_item_id,
            'change_order_id' => $r->change_order_id,
            'removed_by_change_order_id' => $r->removed_by_change_order_id,
        ];
    }
}
