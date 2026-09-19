<?php

namespace App\Support\Communication;

use App\Models\ActivityLog;
use App\Models\ChangeOrder;
use App\Models\Communication;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\DeliveryPackage;
use App\Models\Project;
use App\Models\Quotation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * قصّةُ المشروع بترتيبها — ما فعله الفريق وما قيل للعميل وما ردّ به المزوّد.
 *
 * ═══ من سجلّاتٍ قائمة، لا من تخمين ═══
 *
 * سطورُ الأثر (`activity_logs`) عن المشروع وعرضِه وأوامرِ تغييره وحزمِ
 * تسليمه وفواتيرِه، ورسائلُ المراسلات عنها كلِّها — تُجمع وتُرتَّب بالزمن.
 * ولا سطرَ يُخترع: ما لم يُكتب يومَ وقع لا يظهر هنا.
 *
 * ═══ والرسالةُ أسطرٌ لا سطر ═══
 *
 * «أُرسل البريد» ثمّ «وصل» ثمّ «قُرئ» — كلٌّ في وقته الذي أبلغه المزوّد،
 * فتُقرأ القصّةُ كما جرت: ١٠:٣٤ أُرسل، ١٠:٣٥ وصل.
 */
final class Timeline
{
    /** @return list<array<string, mixed>> */
    public static function forProject(Project $project): array
    {
        $bid = (int) $project->business_id;

        $quotationIds = array_values(array_filter([$project->quotation_id]));
        $changeOrderIds = ChangeOrder::where('project_id', $project->id)->pluck('id')->all();
        $packageIds = DeliveryPackage::where('project_id', $project->id)->pluck('id')->all();
        $invoiceIds = CustomerInvoice::where('project_id', $project->id)->pluck('id')->all();

        $activities = ActivityLog::where('business_id', $bid)
            ->where(function ($q) use ($project, $quotationIds, $changeOrderIds, $packageIds, $invoiceIds) {
                $q->where(fn ($w) => $w->where('subject_type', 'project')->where('subject_id', $project->id));
                foreach ([
                    'quotation' => $quotationIds, 'change_order' => $changeOrderIds,
                    'delivery_package' => $packageIds, 'customer_invoice' => $invoiceIds,
                ] as $type => $ids) {
                    if ($ids !== []) {
                        $q->orWhere(fn ($w) => $w->where('subject_type', $type)->whereIn('subject_id', $ids));
                    }
                }
            })
            ->orderBy('created_at')->orderBy('id')
            ->get();

        $communications = Communication::where('business_id', $bid)
            ->where(function ($q) use ($project, $quotationIds) {
                $q->where('project_id', $project->id);
                if ($quotationIds !== []) {
                    $q->orWhere(fn ($w) => $w->where('subject_type', Quotation::class)->whereIn('subject_id', $quotationIds));
                }
            })
            ->orderBy('created_at')->orderBy('id')
            ->get();

        $rows = collect();

        foreach ($activities as $a) {
            $rows->push([
                'at' => $a->created_at?->toIso8601String(),
                'kind' => 'activity',
                'icon' => $a->icon,
                'color' => $a->color,
                'title' => $a->description,
                'by' => $a->user_name,
                'subject_type' => $a->subject_type,
                'subject_id' => $a->subject_id,
            ]);
        }

        foreach ($communications as $c) {
            foreach (self::milestones($c) as $row) {
                $rows->push($row);
            }
        }

        return $rows
            ->sortBy(fn ($r) => ($r['at'] ?? '').'|'.($r['seq'] ?? 0))
            ->map(fn ($r) => collect($r)->except('seq')->all())
            ->values()
            ->all();
    }

    /**
     * أسطرُ رسالةٍ واحدة: أُرسلت، ثمّ ما أبلغه المزوّد بعدها.
     *
     * @return list<array<string, mixed>>
     */
    public static function milestones(Communication $c): array
    {
        $channel = Communications::channelLabel($c->channel);
        $base = [
            'kind' => 'communication',
            'communication_id' => $c->id,
            'channel' => $c->channel,
            'recipient' => $c->recipient,
            'template_key' => $c->template_key,
            'internal' => (bool) ($c->metadata['internal'] ?? false),
            'by' => $c->initiated_by_name,
        ];

        $rows = [];

        if ($c->status === Communication::FAILED && $c->sent_at === null) {
            $rows[] = $base + [
                'at' => ($c->failed_at ?? $c->created_at)?->toIso8601String(), 'seq' => 0, 'status' => Communication::FAILED,
                'icon' => 'alert-triangle', 'color' => 'danger',
                'title' => __('فشل إرسال :channel: :title', ['channel' => $channel, 'title' => $c->title]),
                'detail' => $c->failure_reason,
            ];

            return $rows;
        }

        $rows[] = $base + [
            'at' => ($c->sent_at ?? $c->created_at)?->toIso8601String(), 'seq' => 0, 'status' => Communication::SENT,
            'icon' => $c->channel === Communication::WHATSAPP ? 'message-circle' : 'mail', 'color' => 'info',
            'title' => __('أُرسل :channel: :title', ['channel' => $channel, 'title' => $c->title]),
            'detail' => __('إلى :to', ['to' => $c->recipient_name ? $c->recipient_name.' — '.$c->recipient : $c->recipient]),
        ];

        if ($c->delivered_at !== null) {
            $rows[] = $base + [
                'at' => $c->delivered_at->toIso8601String(), 'seq' => 1, 'status' => Communication::DELIVERED,
                'icon' => 'check', 'color' => 'success',
                'title' => __('وصل :channel', ['channel' => $channel]), 'detail' => $c->title,
            ];
        }

        if ($c->read_at !== null) {
            $rows[] = $base + [
                'at' => $c->read_at->toIso8601String(), 'seq' => 2, 'status' => Communication::READ,
                'icon' => 'eye', 'color' => 'success',
                'title' => __('قُرئ :channel', ['channel' => $channel]), 'detail' => $c->title,
            ];
        }

        if ($c->isTerminal() && $c->failed_at !== null) {
            $rows[] = $base + [
                'at' => $c->failed_at->toIso8601String(), 'seq' => 3, 'status' => $c->status,
                'icon' => 'alert-triangle', 'color' => 'danger',
                'title' => $c->status === Communication::BOUNCED
                    ? __('ارتدّ :channel', ['channel' => $channel])
                    : __('فشل :channel', ['channel' => $channel]),
                'detail' => $c->failure_reason ?: $c->failure_code,
            ];
        }

        return $rows;
    }

    /**
     * رسائلُ مستندٍ بعينه — بترتيبٍ عكسيّ، للبطاقة في شاشته.
     *
     * @return Collection<int, Communication>
     */
    public static function forSubject(Model $subject, int $limit = 50): Collection
    {
        return Communication::where('business_id', (int) $subject->business_id)
            /* والعميلُ يرى كلَّ ما أُرسل إليه عن أيّ مستند — لا ما موضوعُه هو */
            ->when($subject instanceof Customer,
                fn ($q) => $q->where('customer_id', $subject->getKey()),
                fn ($q) => $q->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey()))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
