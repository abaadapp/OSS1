<?php

namespace App\Support\Automation;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Business;
use App\Models\Setting;
use App\Support\Activity;
use App\Support\Communication\Communications;
use App\Support\Communication\Subjects;
use App\Support\Communication\Templates;
use App\Support\Contention;
use App\Support\Import\Provenance;
use App\Support\Messaging\Notifiers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * محرّكُ الأتمتة — يكشف، ويؤهّل، ويجدول، ويحرس، وينفّذ، ويقيّد، ويصعّد.
 *
 * ═══ دورةٌ واحدة ═══
 *
 * `tick()` تمرّ على الشركات التي لم توقف الأتمتة، ولكلّ قاعدةٍ مفعَّلة تسأل
 * مُطلِقَها عن أهدافه في **نافذةٍ** محسوبةٍ من خطواتها (لا الجدولَ كلَّه)،
 * ثمّ لكلّ هدفٍ وخطوةٍ حان وقتُها تكتب صفَّ تشغيلٍ بمفتاحٍ فريد — وهنا يُردّ
 * العاملُ الثاني — ثمّ تنفّذ. وما أُجّل إلى ساعات العمل يُنفَّذ في دورةٍ لاحقة
 * من `pending`.
 *
 * ═══ الحرّاس بترتيبهم ═══
 *
 * الإيقافُ العامّ → صلاحيّةُ الهدف الآن (التأهيلُ الطازج) → المرساةُ لم تتبدّل
 * → الجمهورُ موجود → الهدوءُ لم يُخرَق → ساعاتُ العمل (للعميل) → الوضعُ
 * (مراقبة تسجّل ولا تفعل) → القالبُ صالحٌ للقناة → الإرسالُ عبر المرحلة 6.
 * وكلُّ توقّفٍ يُكتب بسببه.
 *
 * ═══ ولا يمسّ المحرّكُ حالَ مستند ═══
 *
 * لا يقبل عرضًا ولا يقفل مشروعًا ولا يسدّد فاتورة: يذكّر ويبلّغ ويصعّد.
 */
final class Engine
{
    public const ACTOR = 'الأتمتة';

    /** خطوةٌ فات وقتُها بأكثر من هذا لا تُنفَّذ — قاعدةٌ فُعّلت متأخّرةً لا تُغرق العميل بتذكيراتٍ قديمة */
    public const GRACE_HOURS = 48;

    public const PAUSED = 'paused';

    public const NOT_ELIGIBLE = 'not_eligible';

    public const SETTLED = 'settled';

    public const RESOLVED = 'resolved';

    public const ANCHOR_CHANGED = 'anchor_changed';

    public const NO_RECIPIENT = 'no_recipient';

    public const COOLDOWN = 'cooldown';

    public const NO_TEMPLATE = 'no_template';

    public const NOT_CONFIGURED = 'not_configured';

    public const MODE_OFF = 'mode_off';

    public const TARGET_GONE = 'target_gone';

    /** مستورَدٌ تاريخيًّا — لم يمرّ بدورة النظام فلا يُذكَّر به (المرحلة 9) */
    public const HISTORICAL = 'historical';

    public const FUSE_BLOWN = 'fuse_blown';

    /*
     * حدُّ الانفجار — المرحلة 12.
     *
     * قاعدةٌ معطوبة تطابق ألفَ صفٍّ لا تُخرج ألفَ رسالةٍ في ربع ساعة: كلُّ
     * دورةٍ تُنفّذ من الرسائل الخارجيّة لكلّ شركةٍ هذا العددَ ثمّ تترك الباقي
     * **معلَّقًا** للدورة التالية — فالدفعةُ المشروعة تخرج كلُّها لكن على مهل،
     * والمعطوبةُ تُلحَق قبل أن تبلغ الجميع. ويُضبط من إعدادات الشركة.
     */
    public const DEFAULT_EXTERNAL_PER_TICK = 50;

    /* والفتيل: قاعدةٌ أخفقت هذا العددَ في ساعةٍ تُوقَف صفوفُها حتى يُصلح سببُها */
    public const FUSE_FAILURES = 10;

    public const REASONS = [
        self::PAUSED => 'الأتمتة موقوفة',
        self::NOT_ELIGIBLE => 'لم يعد المستند في الحال التي تستدعي المتابعة',
        self::SETTLED => 'سُدّد قبل الموعد',
        self::RESOLVED => 'عولج قبل الموعد',
        self::ANCHOR_CHANGED => 'تغيّر الموعد الذي بُنيت عليه الخطوة',
        self::NO_RECIPIENT => 'لا مُرسَلَ إليه صالح',
        self::COOLDOWN => 'أُرسل مثله قريبًا — فترة الهدوء',
        self::NO_TEMPLATE => 'لا قالب صالح لهذه القناة',
        self::NOT_CONFIGURED => 'القناة غير موصولة على الخادم',
        self::HISTORICAL => 'مستند مستورَد من بيانات تاريخية — لا تُتابعه الأتمتة',
        self::MODE_OFF => 'الأتمتة مطفأة',
        self::TARGET_GONE => 'المستند لم يعد موجودًا',
        self::FUSE_BLOWN => 'القاعدة تفشل تكرارًا — أُوقفت صفوفُها حتى يُصلح السبب',
    ];

    /* ─────────────────────────── الدورة ─────────────────────────── */

    /**
     * دورةٌ كاملة — لكلّ الشركات أو لواحدة.
     *
     * @return array{businesses: int, evaluated: int, executed: int, observed: int, skipped: int, failed: int, pending: int}
     */
    public static function tick(?int $businessId = null): array
    {
        $totals = ['businesses' => 0, 'evaluated' => 0, 'executed' => 0, 'observed' => 0, 'skipped' => 0, 'failed' => 0, 'pending' => 0];

        $ids = $businessId !== null ? [$businessId] : Business::query()->orderBy('id')->pluck('id')->all();

        foreach ($ids as $bid) {
            $settings = Settings::for((int) $bid);
            if ($settings->mode === Settings::OFF) {
                continue;
            }

            $totals['businesses']++;
            self::resetBudget((int) $bid);
            foreach (self::evaluate((int) $bid, $settings) as $k => $v) {
                $totals[$k] += $v;
            }
            foreach (self::processPending((int) $bid, $settings) as $k => $v) {
                $totals[$k] += $v;
            }
        }

        return $totals;
    }

    /**
     * تقييمُ قواعد شركةٍ واحدة — كشفٌ بالنافذة ثمّ تنفيذ.
     *
     * @return array{evaluated: int, executed: int, observed: int, skipped: int, failed: int, pending: int}
     */
    public static function evaluate(int $businessId, ?Settings $settings = null): array
    {
        $settings ??= Settings::for($businessId);
        $stats = ['evaluated' => 0, 'executed' => 0, 'observed' => 0, 'skipped' => 0, 'failed' => 0, 'pending' => 0];

        if ($settings->mode === Settings::OFF) {
            return $stats;
        }

        Rules::ensure($businessId);
        $now = $settings->now();

        $rules = AutomationRule::where('business_id', $businessId)->where('enabled', true)->orderBy('id')->get();

        foreach ($rules as $rule) {
            if (! Triggers::exists($rule->trigger)) {
                continue;
            }
            $trigger = Triggers::for($rule->trigger);
            $steps = $rule->steps();
            if ($steps === []) {
                continue;
            }

            /*
             * نافذةُ المرساة: الخطوةُ تُنفَّذ حين تكون لحظتُها بين (الآن − مهلة)
             * والآن؛ ولحظتُها = المرساة + الأيّام. فالمرساةُ بين
             * (اليوم − مهلة − أكبر إزاحة) و(اليوم − أصغر إزاحة).
             */
            $offsets = array_column($steps, 'offset');
            $graceDays = (int) ceil(self::GRACE_HOURS / 24);
            $from = $now->copy()->subDays($graceDays + max($offsets))->format('Y-m-d');
            $to = $now->copy()->subDays(min($offsets))->format('Y-m-d');

            foreach ($trigger->candidates($businessId, $from, $to, $rule) as $target) {
                /*
                 * وما جاء من الخزنة لا يُتابَع — المرحلة 9.
                 *
                 * فاتورةٌ عمرُها سنتان استُوردت اليوم ليست «متأخّرةً 700 يوم»:
                 * لم تمرّ بدورة النظام، ولا يُفاجَأ عميلٌ برسالةٍ عن ورقةٍ قديمة.
                 * يُسأل المنشأُ هنا قبل أن يُفتح تنفيذ، وفي التأهيل ثانيةً.
                 */
                if (Provenance::isHistorical($target)) {
                    continue;
                }

                $anchor = $trigger->anchor($target);
                if ($anchor === null) {
                    continue;
                }

                foreach ($steps as $step) {
                    $scheduled = self::scheduledAt($settings, $trigger, $rule, $anchor, $step);

                    if ($scheduled->greaterThan($now) || $scheduled->lessThan($now->copy()->subHours(self::GRACE_HOURS))) {
                        continue;
                    }

                    $stats['evaluated']++;
                    $run = self::open($rule, $trigger, $target, $step, $anchor, $scheduled);
                    if ($run === null) {
                        continue;
                    }

                    $gate = self::gate($run, $rule, $settings);
                    if ($gate !== null) {
                        $stats[$gate]++;

                        continue;
                    }

                    $outcome = self::execute($run, $rule, $trigger, $target, $step, $settings);
                    $stats[$outcome] = ($stats[$outcome] ?? 0) + 1;
                    self::spend($run, $rule, $settings, $outcome);
                }
            }

            $rule->forceFill(['last_run_at' => now()])->saveQuietly();
        }

        return $stats;
    }

    /**
     * ما أُجّل إلى ساعات العمل — يُنفَّذ حين تحين.
     *
     * @return array{executed: int, observed: int, skipped: int, failed: int, pending: int}
     */
    public static function processPending(int $businessId, ?Settings $settings = null): array
    {
        $settings ??= Settings::for($businessId);
        $stats = ['executed' => 0, 'observed' => 0, 'skipped' => 0, 'failed' => 0, 'pending' => 0];

        $due = AutomationRun::where('business_id', $businessId)->where('status', AutomationRun::PENDING)
            ->where('scheduled_for', '<=', now())->with('rule')->orderBy('scheduled_for')->limit(200)->get();

        foreach ($due as $run) {
            $rule = $run->rule;
            if ($rule === null || ! Triggers::exists($run->trigger)) {
                self::finish($run, AutomationRun::CANCELLED, ['result' => __('القاعدة لم تعد موجودة')]);
                $stats['skipped']++;

                continue;
            }
            if (! $rule->enabled) {
                self::finish($run, AutomationRun::CANCELLED, ['result' => __('القاعدة أُوقفت قبل التنفيذ')]);
                $stats['skipped']++;

                continue;
            }
            $gate = self::gate($run, $rule, $settings);
            if ($gate !== null) {
                $stats[$gate]++;

                continue;
            }

            $trigger = Triggers::for($run->trigger);
            $target = $run->target_type ? $trigger->find($businessId, $run->target_type, (int) $run->target_id) : null;
            if ($target === null) {
                self::skip($run, self::TARGET_GONE);
                $stats['skipped']++;

                continue;
            }

            $step = ['offset' => (int) $run->step, 'audience' => (string) $run->audience];
            $outcome = self::execute($run, $rule, $trigger, $target, $step, $settings);
            $stats[$outcome] = ($stats[$outcome] ?? 0) + 1;
            self::spend($run, $rule, $settings, $outcome);
        }

        return $stats;
    }

    /* ─────────────────────────── حدُّ الانفجار ─────────────────────────── */

    /** ميزانيّةُ الدورة لكلّ شركة: سقفُ الخارجيّ وما صُرف منه، والقواعدُ المنقطعُ فتيلُها */
    private static array $budget = [];

    public static function resetBudget(int $businessId): void
    {
        unset(self::$budget[$businessId]);
    }

    private static function budget(int $businessId, Settings $settings): array
    {
        return self::$budget[$businessId] ??= ['cap' => $settings->externalPerTick, 'sent' => 0, 'blown' => self::blownFuses($businessId)];
    }

    private static function isExternalLive(AutomationRun $run, AutomationRule $rule, Settings $settings): bool
    {
        return Audience::isExternal((string) $run->audience) && $settings->effectiveMode($rule) === Settings::LIVE;
    }

    /**
     * البوّابةُ قبل التنفيذ — تُعيد اسمَ الإحصاء إن مُنع، أو `null` ليمضي.
     *
     * الفتيلُ المنقطع يُتخطّى صفُّه بسببٍ مكتوب؛ والخارجيُّ فوق سقف الدورة
     * يبقى **معلَّقًا** لا مُلغًى — يخرج في الدورة التالية.
     */
    private static function gate(AutomationRun $run, AutomationRule $rule, Settings $settings): ?string
    {
        $budget = self::budget((int) $rule->business_id, $settings);

        if (in_array((int) $rule->id, $budget['blown'], true)) {
            self::skip($run, self::FUSE_BLOWN);

            return 'skipped';
        }
        if (self::isExternalLive($run, $rule, $settings) && $budget['sent'] >= $budget['cap']) {
            return 'pending';
        }

        return null;
    }

    private static function spend(AutomationRun $run, AutomationRule $rule, Settings $settings, string $outcome): void
    {
        if ($outcome === 'executed' && self::isExternalLive($run, $rule, $settings)) {
            self::budget((int) $rule->business_id, $settings);
            self::$budget[(int) $rule->business_id]['sent']++;
        }
    }

    /**
     * القواعدُ التي انقطع فتيلُها — `FUSE_FAILURES` إخفاقًا في الساعة الأخيرة.
     *
     * @return list<int>
     */
    public static function blownFuses(int $businessId): array
    {
        return AutomationRun::where('business_id', $businessId)->where('status', AutomationRun::FAILED)
            ->where('executed_at', '>=', now()->subHour())
            ->selectRaw('automation_rule_id, COUNT(*) AS n')->groupBy('automation_rule_id')
            ->havingRaw('COUNT(*) >= ?', [self::FUSE_FAILURES])
            ->pluck('automation_rule_id')->map(fn ($id) => (int) $id)->all();
    }

    /* ─────────────────────────── الفتح ─────────────────────────── */

    /** لحظةُ الخطوة: فوريّةٌ من المرساة، أو في ساعة الإرسال بتوقيت الشركة */
    public static function scheduledAt(Settings $settings, Trigger $trigger, AutomationRule $rule, string $anchor, array $step): Carbon
    {
        if ($trigger->immediate() && strlen($anchor) > 10) {
            return Carbon::parse($anchor, config('app.timezone'))->setTimezone($settings->timezone)->addDays((int) $step['offset']);
        }

        return $settings->at($anchor, (int) $step['offset'], $rule->send_time);
    }

    /** مفتاحُ التكرار — قاعدةٌ + هدفٌ + مرساةٌ + خطوةٌ + جمهور */
    public static function idempotencyKey(AutomationRule $rule, ?Model $target, string $occurrence, array $step): string
    {
        return implode('|', [
            $rule->id, $target?->getMorphClass() ?? '-', $target?->getKey() ?? '-', substr($occurrence, 0, 10), (int) $step['offset'], $step['audience'],
        ]);
    }

    /**
     * فتحُ صفّ تشغيلٍ — أو لا شيء إن سبق أن فُتح.
     *
     * القيدُ الفريد على المفتاح هو الحارس: عاملان يصلان هنا معًا يكتب
     * أحدُهما ويُردّ الآخر بـ`null` قبل أن يبلغ التنفيذ.
     */
    private static function open(AutomationRule $rule, Trigger $trigger, Model $target, array $step, string $anchor, Carbon $scheduled): ?AutomationRun
    {
        $key = self::idempotencyKey($rule, $target, $anchor, $step);

        return Contention::attempt(fn () => AutomationRun::create([
            'business_id' => (int) $rule->business_id,
            'automation_rule_id' => $rule->id,
            'trigger' => $trigger->key(),
            'target_type' => $target->getMorphClass(),
            'target_id' => $target->getKey(),
            'target_label' => mb_substr($trigger->targetLabel($target), 0, 255),
            'step' => (int) $step['offset'],
            'audience' => $step['audience'],
            'occurrence' => substr($anchor, 0, 40),
            'idempotency_key' => $key,
            'status' => AutomationRun::PENDING,
            'evaluated_at' => now(),
            'scheduled_for' => $scheduled->copy()->setTimezone(config('app.timezone')),
            'metadata' => ['step' => $step, 'description' => $trigger->describe($target, $step)],
        ]));
    }

    /* ─────────────────────────── التنفيذ ─────────────────────────── */

    /**
     * الحرّاسُ ثمّ الفعل — ويُعاد اسمُ النتيجة للإحصاء.
     *
     * @return 'executed'|'observed'|'skipped'|'failed'|'pending'
     */
    public static function execute(AutomationRun $run, AutomationRule $rule, Trigger $trigger, Model $target, array $step, Settings $settings): string
    {
        /* الحجزُ الذرّيّ: صفٌّ معلَّق واحد لا يُنفَّذ من عاملين */
        $claimed = AutomationRun::whereKey($run->id)->where('status', AutomationRun::PENDING)->update(['status' => AutomationRun::EXECUTING]);
        if ($claimed !== 1) {
            return 'skipped';
        }
        /* ويُطابَق الأصلُ: التأجيلُ يعيده `pending` — وقيمةٌ تساوي أصلَها لا تُكتب */
        $run->status = AutomationRun::EXECUTING;
        $run->syncOriginalAttribute('status');

        try {
            return Activity::as(self::ACTOR, fn () => self::guarded($run, $rule, $trigger, $target, $step, $settings));
        } catch (Throwable $e) {
            report($e);
            self::finish($run, AutomationRun::FAILED, ['failure' => mb_substr($e->getMessage(), 0, 2000), 'executed_at' => now()]);

            return 'failed';
        }
    }

    private static function guarded(AutomationRun $run, AutomationRule $rule, Trigger $trigger, Model $target, array $step, Settings $settings): string
    {
        $mode = $settings->effectiveMode($rule);
        $external = Audience::isExternal($step['audience']);

        if ($mode === Settings::OFF) {
            return self::skip($run, self::MODE_OFF);
        }
        if ($settings->paused) {
            return self::skip($run, self::PAUSED);
        }

        /* التأهيلُ الطازج: الهدفُ يُقرأ الآن لا لحظةَ الكشف */
        $fresh = $trigger->find((int) $rule->business_id, $target->getMorphClass(), (int) $target->getKey());
        if ($fresh === null) {
            return self::skip($run, self::TARGET_GONE);
        }
        $target = $fresh;

        $anchor = $trigger->anchor($target);
        if ($anchor === null || substr($anchor, 0, 10) !== substr((string) $run->occurrence, 0, 10)) {
            return self::skip($run, self::ANCHOR_CHANGED);
        }

        /* والمنشأُ يُسأل لحظةَ الفعل أيضًا — تنفيذٌ فُتح قبل الاستيراد لا يُرسَل بعده */
        if (Provenance::isHistorical($target)) {
            return self::skip($run, self::HISTORICAL);
        }

        if (($why = $trigger->qualify($target, $step, $rule)) !== null) {
            return self::skip($run, $why);
        }

        /* الجمهور */
        $recipient = null;
        $users = collect();
        if ($external) {
            $subject = $trigger->subject($target);
            if ($subject === null) {
                return self::skip($run, self::NO_RECIPIENT);
            }
            $channel = $rule->channel === 'whatsapp' ? 'whatsapp' : 'email';
            $book = Subjects::recipients($subject);
            $recipient = ($book[$channel === 'whatsapp' ? 'phone' : 'email'][0]['value'] ?? null);
            if ($recipient === null) {
                return self::skip($run, self::NO_RECIPIENT);
            }
        } else {
            $users = Audience::users((int) $rule->business_id, $step['audience'], $target, $rule);
            if ($users->isEmpty()) {
                return self::skip($run, self::NO_RECIPIENT);
            }
        }

        /* الهدوء: مثلُه إلى الجمهور نفسِه عن الهدف نفسِه قريبًا */
        if (self::inCooldown($run, $rule)) {
            return self::skip($run, self::COOLDOWN);
        }

        /* ساعاتُ العمل — للعميل وحدَه، وما لم يحن يعود معلَّقًا إلى أوّل ساعةٍ مفتوحة */
        if ($external && $rule->business_hours && ! $settings->isOpen(now())) {
            $run->update(['status' => AutomationRun::PENDING, 'scheduled_for' => $settings->nextOpen(now())->setTimezone(config('app.timezone')),
                'result' => __('أُجّل إلى ساعات العمل')]);

            return 'pending';
        }

        /* القالبُ والقناة — قبل الوضع: «كان سيفشل» يُسجَّل أيضًا */
        $templateKey = null;
        if ($external) {
            $channel = $rule->channel === 'whatsapp' ? 'whatsapp' : 'email';
            $templateKey = (string) $rule->template_key;
            $subject = $trigger->subject($target);
            try {
                Templates::assertFits($templateKey, $subject, $channel);
                $template = Templates::resolve((int) $rule->business_id, $templateKey, $channel, $rule->language ?: 'ar');
                if (! $template->isSendable()) {
                    return self::skip($run, self::NO_TEMPLATE);
                }
            } catch (RuntimeException) {
                return self::skip($run, self::NO_TEMPLATE);
            }
            if (! Notifiers::for($channel)->configured()) {
                return self::skip($run, self::NOT_CONFIGURED);
            }
        }

        $description = $trigger->describe($target, $step);

        /* المراقبة: يُكتب ما كان سيحدث — ولا يحدث */
        if ($mode === Settings::OBSERVE) {
            self::finish($run, AutomationRun::OBSERVED, [
                'executed_at' => now(),
                'result' => $external
                    ? __('كان سيُرسل «:template» إلى :to', ['template' => Templates::exists($templateKey) ? __(Templates::CATALOG[$templateKey]['name']) : $templateKey, 'to' => $recipient])
                    : __('كان سينبّه :n — :who', ['n' => $users->count(), 'who' => $users->pluck('name')->implode('، ')]),
                'metadata' => ($run->metadata ?? []) + ['would' => [
                    'to' => $external ? $recipient : $users->pluck('name')->all(), 'template' => $templateKey, 'reason' => $description,
                ]],
            ]);

            return 'observed';
        }

        /* التنفيذ الفعليّ */
        if ($external) {
            return self::sendExternal($run, $rule, $trigger, $target, $step, $recipient, $templateKey, $description);
        }

        $custom = $trigger->handle($run, $rule, $target, $step, $users, $settings);
        if ($custom !== null) {
            self::finish($run, AutomationRun::EXECUTED, ['executed_at' => now(), 'result' => $custom['result'],
                'notification_count' => (int) ($custom['notification_count'] ?? 0), 'communication_id' => $custom['communication_id'] ?? null]);

            return 'executed';
        }

        $done = Notify::users($users, $rule, $run, $description, null, $trigger->url($target), $trigger->subject($target));
        self::finish($run, AutomationRun::EXECUTED, ['executed_at' => now(), 'notification_count' => $done['count'],
            'result' => __('نُبّه :n — :who', ['n' => $done['count'], 'who' => $users->pluck('name')->implode('، ')])]);

        return 'executed';
    }

    /** الإرسالُ إلى العميل — عبر المرحلة 6 وحدَها، وبسياقٍ يقول لماذا */
    private static function sendExternal(AutomationRun $run, AutomationRule $rule, Trigger $trigger, Model $target, array $step, string $recipient, string $templateKey, string $description): string
    {
        $subject = $trigger->subject($target);
        $channel = $rule->channel === 'whatsapp' ? 'whatsapp' : 'email';

        try {
            $communication = Communications::send($subject, $channel, $templateKey, $recipient, null, [
                'language' => $rule->language ?: 'ar',
                'actor' => self::ACTOR,
                'metadata' => ['automation_rule_id' => $rule->id, 'automation_run_id' => $run->id, 'trigger' => $trigger->key(), 'reason' => $description],
            ]);
        } catch (RuntimeException $e) {
            /* الرسالةُ إن بلغت المزوّد قُيّدت فاشلةً هناك؛ وهنا يُقيَّد أنّ الأتمتة لم تُتمّ */
            self::finish($run, AutomationRun::FAILED, ['executed_at' => now(), 'failure' => mb_substr($e->getMessage(), 0, 2000),
                'communication_id' => Communications::last()?->id]);

            return 'failed';
        }

        self::finish($run, AutomationRun::EXECUTED, ['executed_at' => now(), 'communication_id' => $communication->id,
            'result' => __('أُرسل «:template» إلى :to', ['template' => __(Templates::CATALOG[$templateKey]['name']), 'to' => $recipient])]);

        Activity::log('updated', 'أرسلت الأتمتة «'.$rule->name.'» إلى '.$recipient.' — '.$description, [
            'business_id' => (int) $rule->business_id, 'subject_id' => $run->id, 'subject_type' => 'automation_run', 'icon' => 'zap',
        ]);

        return 'executed';
    }

    /* ─────────────────────────── الحرّاس ─────────────────────────── */

    /** هل أُرسل مثلُه إلى الجمهور نفسِه عن الهدف نفسِه خلال فترة الهدوء؟ */
    public static function inCooldown(AutomationRun $run, AutomationRule $rule): bool
    {
        return AutomationRun::where('automation_rule_id', $rule->id)
            ->where('target_type', $run->target_type)->where('target_id', $run->target_id)
            ->where('audience', $run->audience)
            ->whereKeyNot($run->id)
            ->whereIn('status', [AutomationRun::EXECUTED, AutomationRun::OBSERVED])
            ->where('executed_at', '>=', now()->subHours(max(1, (int) $rule->cooldown_hours)))
            ->exists();
    }

    private static function skip(AutomationRun $run, string $reason): string
    {
        self::finish($run, AutomationRun::SKIPPED, ['skipped_reason' => $reason, 'executed_at' => now(),
            'result' => __(self::REASONS[$reason] ?? $reason)]);

        return 'skipped';
    }

    private static function finish(AutomationRun $run, string $status, array $attributes = []): void
    {
        $run->update(['status' => $status] + $attributes);
    }

    /* ─────────────────────────── اليد ─────────────────────────── */

    /** إلغاءُ صفٍّ معلَّق بيد — قبل أن يحين */
    public static function cancel(AutomationRun $run, ?string $by = null): AutomationRun
    {
        $claimed = AutomationRun::whereKey($run->id)->where('status', AutomationRun::PENDING)->update([
            'status' => AutomationRun::CANCELLED, 'executed_at' => now(), 'result' => __('أُلغي يدويًّا'),
        ]);

        if ($claimed !== 1) {
            throw new RuntimeException(__('لا يُلغى إلا ما لم يُنفَّذ بعد.'));
        }

        Activity::log('status', 'ألغى تشغيلَ أتمتةٍ معلَّقًا — '.($run->target_label ?? '#'.$run->id), [
            'business_id' => (int) $run->business_id, 'subject_id' => $run->id, 'subject_type' => 'automation_run', 'icon' => 'x-circle',
        ]);

        return $run->fresh();
    }

    /** هل لهذه الشركة أتمتةٌ مضبوطة أصلًا؟ — لمركز العمليات */
    public static function configured(int $businessId): bool
    {
        return Setting::where('business_id', $businessId)->where('key', 'automation_mode')->exists();
    }
}
