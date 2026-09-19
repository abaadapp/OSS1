<?php

namespace App\Support\Automation\Triggers;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Communication;
use App\Support\Automation\Audience;
use App\Support\Automation\Engine;
use App\Support\Automation\Settings;
use App\Support\Automation\Trigger;
use App\Support\Communication\Communications;
use App\Support\Communication\Subjects;
use App\Support\Communication\Templates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * رسالةٌ إلى عميلٍ فشلت أو ارتدّت — يُنبَّه من أرسلها، وتُعاد **مرّةً** إن أُذن.
 *
 * ولا إعادةَ بلا نهاية: `retry_once` في القاعدة يأذن بمحاولةٍ واحدة عبر
 * `Communications::retry` — بقواعدها هي (لا يُعاد ما نجح، ولا ما نجحت
 * إعادتُه). والافتراضُ: يدٌ بشريّة.
 */
final class CommunicationFailed extends Trigger
{
    public function key(): string
    {
        return 'communication_failed';
    }

    public function label(): string
    {
        return __('رسالة لم تصل');
    }

    public function subjectType(): ?string
    {
        return null;
    }

    public function audiences(): array
    {
        return [Audience::INITIATOR, Audience::MANAGEMENT];
    }

    public function immediate(): bool
    {
        return true;
    }

    public function candidates(int $businessId, string $from, string $to, AutomationRule $rule): iterable
    {
        return Communication::where('business_id', $businessId)->whereIn('status', Communication::TERMINAL)
            ->whereNotNull('failed_at')->whereDate('failed_at', '>=', $from)->whereDate('failed_at', '<=', $to)
            ->whereNull('retry_of_id')
            ->whereDoesntHave('retries', fn ($q) => $q->whereNotIn('status', Communication::TERMINAL))
            ->with('subject')->orderBy('id')->lazy(200);
    }

    public function anchor(Model $target): ?string
    {
        return $target->failed_at?->format('Y-m-d H:i:s');
    }

    public function qualify(Model $target, array $step, AutomationRule $rule): ?string
    {
        /** @var Communication $target */
        return $target->isRetryable() ? null : Engine::RESOLVED;
    }

    public function describe(Model $target, array $step): string
    {
        /** @var Communication $target */
        $name = Templates::exists($target->template_key) ? __(Templates::CATALOG[$target->template_key]['name']) : $target->template_key;

        return __('لم تصل «:name» إلى :to عبر :channel — :why', [
            'name' => $name, 'to' => $target->recipient, 'channel' => Communications::channelLabel($target->channel),
            'why' => (string) ($target->failure_reason ?: $target->failure_code ?: __('بلا سبب من المزوّد')),
        ]);
    }

    public function url(Model $target): string
    {
        return route('admin.communications.show', $target->id);
    }

    public function subject(Model $target): ?Model
    {
        return $target->subject;
    }

    public function targetLabel(Model $target): string
    {
        return (string) (Subjects::label($target->subject) ?? $target->title);
    }

    /** إعادةٌ واحدة إن أُذن — وإلّا التنبيهُ المعتاد */
    public function handle(AutomationRun $run, AutomationRule $rule, Model $target, array $step, Collection $users, Settings $settings): ?array
    {
        if (! $rule->retry_once || $settings->effectiveMode($rule) !== Settings::LIVE) {
            return null;
        }

        /** @var Communication $target */
        try {
            $retry = Communications::retry($target, null);
        } catch (RuntimeException $e) {
            return ['result' => __('لم تُعَد: :why', ['why' => $e->getMessage()]), 'communication_id' => null];
        }

        return ['result' => __('أُعيد الإرسال مرّةً واحدة إلى :to', ['to' => $retry->recipient]), 'communication_id' => $retry->id];
    }
}
