<?php

namespace App\Support\Automation\Triggers;

use App\Models\AutomationRule;
use App\Models\Quotation;
use App\Support\Automation\Audience;
use App\Support\Automation\Engine;
use App\Support\Automation\Trigger;
use App\Support\Quotations;
use Illuminate\Database\Eloquent\Model;

/** عرضٌ خرج وينتظر قرارَ العميل — المرساةُ يومُ إرساله */
final class QuotationAwaiting extends Trigger
{
    public function key(): string
    {
        return 'quotation_awaiting';
    }

    public function label(): string
    {
        return __('عرض سعر ينتظر قرار العميل');
    }

    public function subjectType(): ?string
    {
        return 'quotation';
    }

    public function audiences(): array
    {
        return [Audience::CUSTOMER, Audience::CREATOR, Audience::MANAGEMENT];
    }

    public function candidates(int $businessId, string $from, string $to, AutomationRule $rule): iterable
    {
        return Quotation::where('business_id', $businessId)->where('status', Quotation::SENT)
            ->whereNotNull('sent_at')->whereDate('sent_at', '>=', $from)->whereDate('sent_at', '<=', $to)
            ->with('customer')->orderBy('id')->lazy(200);
    }

    public function anchor(Model $target): ?string
    {
        return $target->sent_at?->format('Y-m-d');
    }

    public function qualify(Model $target, array $step, AutomationRule $rule): ?string
    {
        /** @var Quotation $target */
        return $target->isDecidable() ? null : Engine::NOT_ELIGIBLE;
    }

    public function describe(Model $target, array $step): string
    {
        return __('عرض السعر :label بانتظار قرار العميل منذ :n يومًا', [
            'label' => Quotations::label($target), 'n' => max(0, (int) $step['offset']),
        ]);
    }

    public function url(Model $target): string
    {
        return route('admin.quotations.show', $target->id);
    }
}
