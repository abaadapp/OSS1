<?php

namespace App\Support\Automation\Triggers;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\DocumentRecord;
use App\Models\User;
use App\Support\Automation\Audience;
use App\Support\Automation\Engine;
use App\Support\Automation\Notify;
use App\Support\Automation\Settings;
use App\Support\Automation\Trigger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * مستندٌ يقترب انتهاؤه — يُسمع قبل ٩٠ و٦٠ و٣٠ و٧ أيّام ويومَ الانتهاء.
 *
 * المرساةُ تاريخُ الانتهاء، والخطواتُ من القاعدة (تُعدَّل من شاشة الأتمتة). ومفتاحُ
 * التشغيل (القاعدة · المستند · تاريخُ الانتهاء · الخطوة) يمنع التكرار؛ ومن جدّد
 * المستندَ بتاريخٍ جديد تبدأ خطواتُه من جديد. والمؤرشفُ لا يُسمع عنه شيء. داخليٌّ
 * كلُّه: للمسؤول عن المستند والإدارة — ولا يبلغ عميلًا.
 */
final class DocumentExpiry extends Trigger
{
    public function key(): string
    {
        return 'document_expiry';
    }

    public function label(): string
    {
        return __('انتهاء صلاحية مستند');
    }

    public function subjectType(): ?string
    {
        return null;
    }

    public function audiences(): array
    {
        return [Audience::ASSIGNEE, Audience::MANAGEMENT, Audience::CREATOR, Audience::CUSTOM];
    }

    public function candidates(int $businessId, string $from, string $to, AutomationRule $rule): iterable
    {
        return DocumentRecord::where('business_id', $businessId)->whereNull('archived_at')->whereNotNull('expires_at')
            ->whereDate('expires_at', '>=', $from)->whereDate('expires_at', '<=', $to)
            ->orderBy('id')->lazy(200);
    }

    public function anchor(Model $target): ?string
    {
        /** @var DocumentRecord $target */
        return $target->archived_at === null ? $target->expires_at?->format('Y-m-d') : null;
    }

    public function qualify(Model $target, array $step, AutomationRule $rule): ?string
    {
        /** @var DocumentRecord $target */
        return $target->archived_at !== null ? Engine::RESOLVED : null;
    }

    public function describe(Model $target, array $step): string
    {
        /** @var DocumentRecord $target */
        $days = -(int) $step['offset'];
        $date = $target->expires_at?->format('Y-m-d') ?? '—';

        return match (true) {
            $days === 0 => __('ينتهي المستند «:title» اليوم (:date)', ['title' => $target->title, 'date' => $date]),
            $days < 0 => __('انتهى المستند «:title» منذ :n أيام (:date)', ['title' => $target->title, 'n' => -$days, 'date' => $date]),
            default => __('ينتهي المستند «:title» بعد :n يومًا (:date)', ['title' => $target->title, 'n' => $days, 'date' => $date]),
        };
    }

    public function url(Model $target): string
    {
        return route('admin.documents.show', $target->getKey());
    }

    /**
     * التنبيهُ نفسُه — بلغة القاعدة لا بلغة الخادم.
     *
     * الأتمتةُ تدور من سطر الأوامر بلغته الافتراضيّة، فيُكتب العنوانُ هنا من جديد
     * بلغة القاعدة ثمّ يمضي إلى `Notify::users` كما يمضي غيرُه.
     *
     * @param  Collection<int, User>  $users
     */
    public function handle(AutomationRun $run, AutomationRule $rule, Model $target, array $step, Collection $users, Settings $settings): ?array
    {
        $previous = app()->getLocale();
        app()->setLocale(in_array($rule->language, ['ar', 'en'], true) ? $rule->language : 'ar');

        try {
            $done = Notify::users($users, $rule, $run, $this->describe($target, $step), null, $this->url($target), $this->subject($target));

            return ['notification_count' => $done['count'],
                'result' => __('نُبّه :n — :who', ['n' => $done['count'], 'who' => $users->pluck('name')->implode('، ')])];
        } finally {
            app()->setLocale($previous);
        }
    }
}
