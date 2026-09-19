<?php

namespace App\Support\Automation\Triggers;

use App\Models\AutomationRule;
use App\Models\Quotation;
use App\Support\Automation\Audience;
use App\Support\Automation\Engine;
use App\Support\Automation\Trigger;
use App\Support\Quotations;
use Illuminate\Database\Eloquent\Model;

/** صلاحيّةُ عرضٍ تقترب أو انقضت بلا قرار — المرساةُ `valid_until` */
final class QuotationExpiry extends Trigger
{
    public function key(): string
    {
        return 'quotation_expiry';
    }

    public function label(): string
    {
        return __('صلاحية عرض السعر');
    }

    public function subjectType(): ?string
    {
        return 'quotation';
    }

    public function audiences(): array
    {
        return [Audience::CREATOR, Audience::MANAGEMENT, Audience::CUSTOMER];
    }

    public function candidates(int $businessId, string $from, string $to, AutomationRule $rule): iterable
    {
        return Quotation::where('business_id', $businessId)->where('status', Quotation::SENT)
            ->whereNotNull('valid_until')->whereDate('valid_until', '>=', $from)->whereDate('valid_until', '<=', $to)
            ->with('customer')->orderBy('id')->lazy(200);
    }

    public function anchor(Model $target): ?string
    {
        return $target->valid_until?->format('Y-m-d');
    }

    public function qualify(Model $target, array $step, AutomationRule $rule): ?string
    {
        /** @var Quotation $target */
        if ($target->status !== Quotation::SENT) {
            return Engine::NOT_ELIGIBLE;
        }

        /* والعميلُ لا يُذكَّر بعرضٍ انقضى: تذكيرُه قبل الانقضاء فقط */
        if ($step['audience'] === Audience::CUSTOMER && ! $target->isDecidable()) {
            return Engine::NOT_ELIGIBLE;
        }

        return null;
    }

    public function describe(Model $target, array $step): string
    {
        $label = Quotations::label($target);

        return (int) $step['offset'] < 0
            ? __('صلاحية عرض السعر :label تنتهي بعد :n يومًا بلا قرار', ['label' => $label, 'n' => -(int) $step['offset']])
            : __('انتهت صلاحية عرض السعر :label بلا قرار — نقّحه أو أغلقه', ['label' => $label]);
    }

    public function url(Model $target): string
    {
        return route('admin.quotations.show', $target->id);
    }
}
