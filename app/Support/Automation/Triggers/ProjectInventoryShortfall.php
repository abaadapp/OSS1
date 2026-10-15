<?php

namespace App\Support\Automation\Triggers;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\InternalNotification;
use App\Models\InventoryShortfallState;
use App\Models\Project;
use App\Models\User;
use App\Support\Activity;
use App\Support\Automation\Audience;
use App\Support\Automation\Engine;
use App\Support\Automation\Notify;
use App\Support\Automation\Settings;
use App\Support\Automation\Trigger;
use App\Support\InventoryForecast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * نقصُ مخزونٍ في مشروعٍ قادم — يُقال مرّةً لكلّ جمهور، ثمّ لا يُعاد حتى يتغيّر.
 *
 * ═══ المرساةُ اليوم، والتكرارُ ببصمة النقص ═══
 *
 * التوقّعُ يُحسب في كلّ دورة (`InventoryForecast`)، والمشروعُ الذي فيه نقصٌ
 * لم يُقَل لجمهور الخطوة — بصمتُه (المنتجاتُ الناقصة ومقاديرُها) غيرُ ما حُفظ في
 * `inventory_shortfall_states` — يُرشَّح وحده. فالنقصُ نفسُه لا يُعاد كلَّ ربع ساعة،
 * وإن زاد أو تبدّل منتجٌ تغيّرت البصمةُ فقيل. وإن زال النقصُ مُحيت البصمة: نقصٌ
 * يعود لاحقًا يُقال من جديد. داخليٌّ كلُّه: لا يبلغ العميل.
 */
final class ProjectInventoryShortfall extends Trigger
{
    /** @var array<string, mixed>|null توقّعُ الدورة — يُحسب مرّةً لكلّ قاعدة */
    private ?array $forecast = null;

    private ?int $forecastFor = null;

    public function key(): string
    {
        return 'project_inventory_shortfall';
    }

    public function label(): string
    {
        return __('نقص مخزون في مشروع قادم');
    }

    public function subjectType(): ?string
    {
        return null;
    }

    public function audiences(): array
    {
        return [Audience::OWNER, Audience::INVENTORY, Audience::MANAGEMENT, Audience::CUSTOM];
    }

    public function candidates(int $businessId, string $from, string $to, AutomationRule $rule): iterable
    {
        $forecast = $this->forecast($businessId, true);
        $short = array_filter($forecast['projects'], fn (array $p) => $p['shortages'] > 0);

        /* ما زال نقصُه — تُمحى بصمتُه، فإن عاد قيل من جديد */
        InventoryShortfallState::where('business_id', $businessId)->whereNotNull('notified_signature')
            ->whereNotIn('project_id', array_keys($short) ?: [0])
            ->update(['notified_signature' => null, 'notified_audiences' => null, 'resolved_at' => now()]);

        $states = InventoryShortfallState::where('business_id', $businessId)->whereIn('project_id', array_keys($short) ?: [0])->get()->keyBy('project_id');
        $audiences = array_values(array_unique(array_column($rule->steps(), 'audience')));

        $ids = array_keys(array_filter($short, function (array $p) use ($states, $audiences) {
            $state = $states->get($p['id']);
            if ($state === null || $state->notified_signature !== InventoryForecast::signature($p)) {
                return true;
            }

            return array_diff($audiences, (array) $state->notified_audiences) !== [];
        }));

        return Project::where('business_id', $businessId)->whereIn('id', $ids ?: [0])->orderBy('id')->get();
    }

    public function anchor(Model $target): ?string
    {
        return Carbon::now(Settings::for((int) $target->business_id)->timezone)->format('Y-m-d');
    }

    public function qualify(Model $target, array $step, AutomationRule $rule): ?string
    {
        /** @var Project $target */
        if (! $target->isOpen()) {
            return Engine::NOT_ELIGIBLE;
        }

        $line = $this->forecast((int) $target->business_id)['projects'][$target->id] ?? null;
        $signature = $line === null ? null : InventoryForecast::signature($line);
        if ($signature === null) {
            return Engine::RESOLVED;
        }

        $state = InventoryShortfallState::where('business_id', $target->business_id)->where('project_id', $target->id)->first();
        $told = $state !== null && $state->notified_signature === $signature && in_array($step['audience'], (array) $state->notified_audiences, true);

        return $told ? Engine::RESOLVED : null;
    }

    public function describe(Model $target, array $step): string
    {
        /** @var Project $target */
        $line = $this->forecast((int) $target->business_id)['projects'][$target->id] ?? ['lines' => [], 'shortages' => 0, 'date' => null];
        $items = collect($line['lines'])->filter(fn ($l) => $l['shortage'] > 0)->take(4)
            ->map(fn ($l) => __(':name (نقص :n)', ['name' => $l['name'], 'n' => rtrim(rtrim(number_format($l['shortage'], 3, '.', ''), '0'), '.')]))->implode('، ');

        return __('نقص مخزون في المشروع :code — :name (:date): :items', [
            'code' => $target->code, 'name' => $target->name, 'date' => $line['date'] ?? '—', 'items' => $items,
        ]);
    }

    public function url(Model $target): string
    {
        return route('admin.projects.show', ['id' => $target->getKey(), 'tab' => 'inventory']);
    }

    public function handle(AutomationRun $run, AutomationRule $rule, Model $target, array $step, Collection $users, Settings $settings): ?array
    {
        /* بلغة القاعدة لا بلغة الخادم — الأتمتةُ تدور من سطر الأوامر بلغته الافتراضيّة */
        $previous = app()->getLocale();
        app()->setLocale(in_array($rule->language, ['ar', 'en'], true) ? $rule->language : 'ar');

        try {
            return $this->notify($run, $rule, $target, $step, $users);
        } finally {
            app()->setLocale($previous);
        }
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array{result: string, notification_count: int}
     */
    private function notify(AutomationRun $run, AutomationRule $rule, Model $target, array $step, Collection $users): array
    {
        /** @var Project $target */
        $line = $this->forecast((int) $target->business_id)['projects'][$target->id] ?? null;
        $signature = $line === null ? null : InventoryForecast::signature($line);
        $description = $this->describe($target, $step);
        $state = InventoryShortfallState::firstOrNew(['project_id' => $target->id], ['business_id' => $target->business_id]);
        $same = $state->exists && $state->notified_signature === $signature && $state->notified_at !== null;

        /*
         * ومن بلغه هذا النقصُ نفسُه من خطوةٍ سبقت لا يُنبَّه ثانيةً: صاحبُ المشروع
         * الذي يفتح المخزون أيضًا تصله رسالةٌ واحدة لا اثنتان.
         */
        if ($same) {
            $told = InternalNotification::where('business_id', $target->business_id)->where('kind', $this->key())
                ->where('subject_type', $target->getMorphClass())->where('subject_id', $target->id)
                ->where('created_at', '>=', $state->notified_at)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            $users = $users->reject(fn ($u) => in_array((int) $u->id, $told, true))->values();
        }

        $done = $users->isEmpty() ? ['count' => 0] : Notify::users($users, $rule, $run, $description, null, $this->url($target), $target);

        $state->fill([
            'business_id' => $target->business_id,
            'notified_signature' => $signature,
            'notified_audiences' => array_values(array_unique([...($same ? (array) $state->notified_audiences : []), $step['audience']])),
            'items' => collect($line['lines'] ?? [])->filter(fn ($l) => $l['shortage'] > 0)
                ->map(fn ($l) => ['product_id' => $l['product_id'], 'name' => $l['name'], 'shortage' => $l['shortage']])->values()->all(),
            /* أوّلُ تنبيهٍ بهذه البصمة — ومنه يُقرأ من بلغه */
            'notified_at' => $same ? $state->notified_at : now(), 'resolved_at' => null,
        ])->save();

        Activity::log('updated', 'نبّهت الأتمتة إلى نقص مخزون في المشروع '.$target->code.' — '.$description, [
            'business_id' => (int) $target->business_id, 'subject_id' => $target->id, 'subject_type' => 'project', 'icon' => 'alert-triangle', 'color' => 'warning',
        ]);

        return ['result' => __('نُبّه :n — :who', ['n' => $done['count'], 'who' => $users->pluck('name')->implode('، ')]), 'notification_count' => $done['count']];
    }

    /** @return array<string, mixed> */
    private function forecast(int $businessId, bool $fresh = false): array
    {
        if ($fresh || $this->forecast === null || $this->forecastFor !== $businessId) {
            $this->forecast = InventoryForecast::for($businessId);
            $this->forecastFor = $businessId;
        }

        return $this->forecast;
    }
}
