<?php

namespace App\Support\Intelligence;

use App\Support\Automation\Settings as AutomationSettings;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * مدّةٌ إداريّة — بتوقيت الشركة، وحدُّها الأعلى مفتوح.
 *
 * ═══ [البداية، النهاية) ═══
 *
 * النهايةُ لا تدخل: «هذا الشهر» يبدأ أوّلَ يومٍ فيه وينتهي أوّلَ يومٍ في
 * الشهر التالي. فما وقع على الحدّ يُحسب في مدّةٍ واحدة لا اثنتين — وهي
 * قاعدةُ `Revenue::query` نفسُها.
 *
 * ═══ والمقارنةُ لا تُختلق ═══
 *
 * `previous()` المدّةُ المكافئة قبلها، و`lastYear()` نفسُها قبل سنة. ومن
 * قارن مدّةً لا بياناتَ قبلها قيل له «لا بيانات كافية» لا «+100٪».
 */
final class Period
{
    /** @var list<string> */
    public const KEYS = ['this_month', 'last_month', 'this_quarter', 'last_quarter', 'ytd', 'last_12_months', 'custom'];

    public const LABELS = [
        'this_month' => 'هذا الشهر', 'last_month' => 'الشهر الماضي', 'this_quarter' => 'هذا الربع',
        'last_quarter' => 'الربع الماضي', 'ytd' => 'منذ بداية السنة', 'last_12_months' => 'آخر 12 شهرًا', 'custom' => 'مدّة مخصَّصة',
    ];

    /** أقصى مدّةٍ مخصَّصة — التحليلُ محدودٌ لا يقرأ التاريخَ كلَّه */
    public const MAX_DAYS = 800;

    private function __construct(
        public readonly string $key,
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly string $timezone,
    ) {}

    /** المدّةُ من مفتاحها — أو من حدّين للمخصَّصة */
    public static function resolve(int $businessId, ?string $key, ?string $from = null, ?string $to = null): self
    {
        $tz = AutomationSettings::for($businessId)->timezone;
        $now = Carbon::now($tz);
        $key = in_array($key, self::KEYS, true) ? $key : 'this_month';

        [$start, $end] = match ($key) {
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->startOfMonth()->addMonth()],
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->startOfMonth()],
            'this_quarter' => [$now->copy()->startOfQuarter(), $now->copy()->startOfQuarter()->addQuarter()],
            'last_quarter' => [$now->copy()->subQuarter()->startOfQuarter(), $now->copy()->startOfQuarter()],
            'ytd' => [$now->copy()->startOfYear(), $now->copy()->startOfDay()->addDay()],
            'last_12_months' => [$now->copy()->startOfMonth()->subMonths(11), $now->copy()->startOfMonth()->addMonth()],
            default => self::custom($from, $to, $tz),
        };

        return new self($key, $start, $end, $tz);
    }

    /** مدّةٌ بحدّين صريحين — النهايةُ تُقرأ شاملةً ثمّ تُفتح */
    public static function between(int $businessId, Carbon $start, Carbon $endExclusive, string $key = 'custom'): self
    {
        $tz = AutomationSettings::for($businessId)->timezone;

        return new self($key, $start->copy()->setTimezone($tz), $endExclusive->copy()->setTimezone($tz), $tz);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private static function custom(?string $from, ?string $to, string $tz): array
    {
        try {
            $start = filled($from) ? Carbon::parse((string) $from, $tz)->startOfDay() : Carbon::now($tz)->startOfMonth();
            $end = filled($to) ? Carbon::parse((string) $to, $tz)->startOfDay()->addDay() : Carbon::now($tz)->startOfDay()->addDay();
        } catch (\Throwable) {
            throw new RuntimeException(__('تاريخٌ غير مقروء.'));
        }

        if ($end->lte($start)) {
            throw new RuntimeException(__('نهاية المدّة قبل بدايتها.'));
        }
        if ($start->diffInDays($end) > self::MAX_DAYS) {
            throw new RuntimeException(__('المدّة أطول من :n يومًا — ضيّقها.', ['n' => self::MAX_DAYS]));
        }

        return [$start, $end];
    }

    /** المدّةُ المكافئةُ قبلها مباشرةً — بالطول نفسه */
    public function previous(): self
    {
        $start = match ($this->key) {
            'this_month', 'last_month' => $this->start->copy()->subMonthNoOverflow(),
            'this_quarter', 'last_quarter' => $this->start->copy()->subQuarter(),
            'ytd' => $this->start->copy()->subYear(),
            'last_12_months' => $this->start->copy()->subMonths(12),
            default => $this->start->copy()->subDays($this->days()),
        };
        $end = match ($this->key) {
            'ytd' => $this->end->copy()->subYear(),
            default => $this->start->copy(),
        };

        return new self($this->key, $start, $end, $this->timezone);
    }

    /** المدّةُ نفسُها قبل سنة */
    public function lastYear(): self
    {
        return new self($this->key, $this->start->copy()->subYear(), $this->end->copy()->subYear(), $this->timezone);
    }

    /** البدايةُ بتوقيت الخادم — لأعمدة اللحظات (`occurred_at`) */
    public function startAt(): Carbon
    {
        return $this->start->copy()->setTimezone((string) config('app.timezone'));
    }

    /** النهايةُ (مفتوحة) بتوقيت الخادم */
    public function endAt(): Carbon
    {
        return $this->end->copy()->setTimezone((string) config('app.timezone'));
    }

    /** البدايةُ تاريخًا بتوقيت الشركة — لأعمدة التاريخ (`issued_at`) */
    public function startDate(): string
    {
        return $this->start->toDateString();
    }

    /** النهايةُ (مفتوحة) تاريخًا */
    public function endDate(): string
    {
        return $this->end->toDateString();
    }

    public function days(): int
    {
        return max(1, (int) $this->start->diffInDays($this->end));
    }

    /** أشهرُ المدّة — أوّلُ كلّ شهرٍ فيها */
    /** @return list<Carbon> */
    public function months(): array
    {
        $out = [];
        $cursor = $this->start->copy()->startOfMonth();
        while ($cursor->lt($this->end)) {
            $out[] = $cursor->copy();
            $cursor->addMonth();
        }

        return $out;
    }

    public function label(): string
    {
        return $this->key === 'custom'
            ? $this->start->format('Y-m-d').' → '.$this->end->copy()->subDay()->format('Y-m-d')
            : __(self::LABELS[$this->key]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label(),
            'from' => $this->start->format('Y-m-d'),
            'to' => $this->end->copy()->subDay()->format('Y-m-d'),
            'timezone' => $this->timezone,
            'days' => $this->days(),
        ];
    }
}
