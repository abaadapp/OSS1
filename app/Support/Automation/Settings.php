<?php

namespace App\Support\Automation;

use App\Models\AutomationRule;
use App\Models\Setting;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * إعداداتُ الأتمتة للشركة — الوضعُ، والإيقافُ، وساعاتُ العمل.
 *
 * ═══ ثلاثةُ أوضاع ومفتاحُ إيقاف ═══
 *
 * `off` لا يُقيَّم شيء · `observe` يُقيَّم ويُسجَّل ما كان سيحدث ولا يُفعل ·
 * `live` يُفعل. و«إيقاف الأتمتة» فوقها كلِّها: المحرّكُ يقيّم ويسجّل «تخطّى:
 * موقوف» ولا ينفّذ شيئًا — لا خارجيًّا ولا داخليًّا — حتى يُستأنف بيد.
 *
 * ═══ والوقتُ وقتُ الشركة ═══
 *
 * كلُّ «قبل ثلاثة أيّام» و«في التاسعة» يُحسب بمنطقة الشركة لا بمنطقة
 * الخادم. والرسائلُ إلى العملاء لا تخرج خارج ساعات العمل: تُؤجَّل إلى أوّل
 * ساعةٍ مفتوحة. والتنبيهاتُ الداخليّة لا تنتظر.
 *
 * وتُحفظ صفوفًا في `settings` كسائر إعدادات الشركة — لا جدولَ جديدًا لستّ قيم.
 */
final class Settings
{
    public const OFF = 'off';

    public const OBSERVE = 'observe';

    public const LIVE = 'live';

    public const MODES = [self::OFF, self::OBSERVE, self::LIVE];

    /** أيّامُ الأسبوع بترقيم Carbon: الأحد 0 … السبت 6 — وعُمان تعمل الأحد إلى الخميس */
    private const DEFAULT_DAYS = [0, 1, 2, 3, 4];

    private const KEYS = [
        'mode' => 'automation_mode',
        'paused' => 'automation_paused',
        'timezone' => 'automation_timezone',
        'days' => 'automation_work_days',
        'start' => 'automation_work_start',
        'end' => 'automation_work_end',
        'external_cap' => 'automation_external_per_tick',
    ];

    /** @param  list<int>  $days */
    private function __construct(
        public readonly int $businessId,
        public readonly string $mode,
        public readonly bool $paused,
        public readonly string $timezone,
        public readonly array $days,
        public readonly string $start,
        public readonly string $end,
        /** سقفُ الرسائل الخارجيّة في الدورة الواحدة — حدُّ الانفجار (المرحلة 12) */
        public readonly int $externalPerTick = Engine::DEFAULT_EXTERNAL_PER_TICK,
    ) {}

    public static function for(int $businessId): self
    {
        $rows = Setting::where('business_id', $businessId)->whereIn('key', array_values(self::KEYS))->pluck('value', 'key');
        $get = fn (string $key, ?string $default = null) => filled($rows[self::KEYS[$key]] ?? null) ? (string) $rows[self::KEYS[$key]] : $default;

        $mode = $get('mode', self::OFF);
        $tz = $get('timezone', (string) config('app.timezone', 'Asia/Muscat'));
        $days = array_values(array_filter(array_map('intval', explode(',', $get('days', implode(',', self::DEFAULT_DAYS)))), fn ($d) => $d >= 0 && $d <= 6));

        return new self(
            $businessId,
            in_array($mode, self::MODES, true) ? $mode : self::OFF,
            $get('paused', '0') === '1',
            self::validTimezone($tz) ? $tz : (string) config('app.timezone', 'Asia/Muscat'),
            $days === [] ? self::DEFAULT_DAYS : array_unique($days),
            self::validTime($get('start', '09:00')) ? $get('start', '09:00') : '09:00',
            self::validTime($get('end', '17:00')) ? $get('end', '17:00') : '17:00',
            max(1, min(1000, (int) $get('external_cap', (string) Engine::DEFAULT_EXTERNAL_PER_TICK))),
        );
    }

    /** الآنَ بتوقيت الشركة */
    public function now(): Carbon
    {
        return now()->setTimezone($this->timezone);
    }

    /** لحظةُ خطوةٍ: تاريخُ المرساة + الأيّام، في ساعة الإرسال بتوقيت الشركة */
    public function at(Carbon|string $anchorDate, int $offsetDays, ?string $time = null): Carbon
    {
        $date = $anchorDate instanceof Carbon ? $anchorDate->format('Y-m-d') : substr((string) $anchorDate, 0, 10);
        $time = $time !== null && self::validTime($time) ? $time : $this->start;

        return Carbon::parse($date.' '.$time, $this->timezone)->addDays($offsetDays);
    }

    /** هل هذه اللحظةُ داخل ساعات العمل؟ */
    public function isOpen(Carbon $at): bool
    {
        $t = $at->copy()->setTimezone($this->timezone);

        if (! in_array((int) $t->dayOfWeek, $this->days, true)) {
            return false;
        }

        $clock = $t->format('H:i');

        return $clock >= $this->start && $clock < $this->end;
    }

    /** أوّلُ لحظةٍ مفتوحة عند هذه اللحظة أو بعدها — وإلّا هي نفسُها بعد أسبوعين بلا يومِ عمل */
    public function nextOpen(Carbon $at): Carbon
    {
        $t = $at->copy()->setTimezone($this->timezone);

        for ($i = 0; $i < 15; $i++) {
            $day = $t->copy()->addDays($i);
            if (! in_array((int) $day->dayOfWeek, $this->days, true)) {
                continue;
            }
            $open = Carbon::parse($day->format('Y-m-d').' '.$this->start, $this->timezone);
            $close = Carbon::parse($day->format('Y-m-d').' '.$this->end, $this->timezone);

            if ($i === 0 && $t->greaterThanOrEqualTo($open) && $t->lessThan($close)) {
                return $t;
            }
            if ($t->lessThan($open)) {
                return $open;
            }
        }

        return $t;
    }

    /** الوضعُ الفعليّ لقاعدة: أضيقُ وضعَي الشركة والقاعدة */
    public function effectiveMode(AutomationRule $rule): string
    {
        if ($this->mode === self::OFF) {
            return self::OFF;
        }

        return $this->mode === self::LIVE && $rule->mode === AutomationRule::LIVE ? self::LIVE : self::OBSERVE;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'paused' => $this->paused,
            'timezone' => $this->timezone,
            'days' => $this->days,
            'start' => $this->start,
            'end' => $this->end,
            'now' => $this->now()->format('Y-m-d H:i'),
            'open_now' => $this->isOpen(now()),
            'external_per_tick' => $this->externalPerTick,
        ];
    }

    /* ─────────────────────────── الكتابة ─────────────────────────── */

    /**
     * حفظُ الإعدادات — وكلُّ تغييرٍ في الأثر.
     *
     * @param  array{mode?: string, timezone?: string, days?: list<int>, start?: string, end?: string}  $data
     */
    public static function save(int $businessId, array $data, ?User $actor = null): self
    {
        $before = self::for($businessId);

        if (isset($data['mode'])) {
            if (! in_array($data['mode'], self::MODES, true)) {
                throw new RuntimeException(__('وضعٌ غير معروف.'));
            }
            self::put($businessId, 'mode', $data['mode']);
            if ($data['mode'] !== $before->mode) {
                Activity::log('settings', 'غيّر وضع الأتمتة من «'.$before->mode.'» إلى «'.$data['mode'].'»', [
                    'business_id' => $businessId, 'subject_type' => 'automation', 'icon' => 'zap',
                ]);
            }
        }

        if (isset($data['timezone'])) {
            if (! self::validTimezone((string) $data['timezone'])) {
                throw new RuntimeException(__('منطقةٌ زمنيّة غير معروفة.'));
            }
            self::put($businessId, 'timezone', (string) $data['timezone']);
        }

        if (isset($data['days'])) {
            $days = array_values(array_unique(array_filter(array_map('intval', (array) $data['days']), fn ($d) => $d >= 0 && $d <= 6)));
            if ($days === []) {
                throw new RuntimeException(__('اختر يومَ عملٍ واحدًا على الأقلّ.'));
            }
            sort($days);
            self::put($businessId, 'days', implode(',', $days));
        }

        if (isset($data['external_cap'])) {
            $cap = (int) $data['external_cap'];
            if ($cap < 1 || $cap > 1000) {
                throw new RuntimeException(__('سقفُ الرسائل في الدورة بين 1 و1000.'));
            }
            self::put($businessId, 'external_cap', (string) $cap);
        }

        foreach (['start', 'end'] as $k) {
            if (isset($data[$k])) {
                if (! self::validTime((string) $data[$k])) {
                    throw new RuntimeException(__('الوقتُ بصيغة HH:MM.'));
                }
                self::put($businessId, $k, (string) $data[$k]);
            }
        }

        $after = self::for($businessId);
        if ($after->start >= $after->end) {
            self::put($businessId, 'start', $before->start);
            self::put($businessId, 'end', $before->end);
            throw new RuntimeException(__('بدايةُ الدوام قبل نهايته.'));
        }

        if ([$after->timezone, $after->days, $after->start, $after->end] !== [$before->timezone, $before->days, $before->start, $before->end]) {
            Activity::log('settings', 'عدّل ساعات عمل الأتمتة: '.$after->timezone.' · '.implode(',', $after->days).' · '.$after->start.'–'.$after->end, [
                'business_id' => $businessId, 'subject_type' => 'automation', 'icon' => 'clock',
            ]);
        }

        return $after;
    }

    /** الإيقافُ والاستئناف — مفتاحُ الأمان، وكلُّ ضغطةٍ في الأثر */
    public static function pause(int $businessId, bool $paused, ?User $actor = null): self
    {
        $before = self::for($businessId);
        self::put($businessId, 'paused', $paused ? '1' : '0');

        if ($before->paused !== $paused) {
            Activity::log('settings', $paused ? 'أوقف الأتمتة كلَّها' : 'استأنف الأتمتة', [
                'business_id' => $businessId, 'subject_type' => 'automation',
                'icon' => $paused ? 'pause-circle' : 'play-circle', 'color' => $paused ? 'danger' : 'success',
            ]);
        }

        return self::for($businessId);
    }

    private static function put(int $businessId, string $key, string $value): void
    {
        Setting::updateOrCreate(['business_id' => $businessId, 'key' => self::KEYS[$key]], ['value' => $value]);
    }

    private static function validTime(?string $t): bool
    {
        return $t !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) === 1;
    }

    private static function validTimezone(string $tz): bool
    {
        return in_array($tz, timezone_identifiers_list(), true);
    }
}
