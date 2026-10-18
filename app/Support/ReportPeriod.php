<?php

namespace App\Support;

use App\Support\Automation\Settings as AutomationSettings;
use Illuminate\Contracts\Database\Query\Builder as QueryContract;
use Illuminate\Support\Carbon;

/**
 * فترةُ التقرير والتصدير — جاهزةٌ (اليوم · الأسبوع · الشهر · السنة · الكل) أو مخصَّصةٌ بحدّين.
 *
 * ═══ الجاهزةُ كما كانت ═══
 *
 * بدايتُها `Demo::rangeStart` نفسُها، ونهايتُها مفتوحةٌ حتى الآن — فلا يتغيّر
 * رقمٌ واحدٌ في تقريرٍ فُتح على «الشهر» قبل هذه الفترة وبعدها.
 *
 * ═══ والمخصَّصةُ [من، إلى] بيومَيها كاملَين ═══
 *
 * `range=custom&from=2026-01-01&to=2026-01-31` — الاسمان نفسُهما في شاشات
 * الحركة والقيود والفواتير والهالك وتقارير الإدارة. اليومان يُقرآن
 * بتوقيت الشركة (`AutomationSettings::timezone` — ما تقرأ به تقاريرُ الإدارة
 * مدّتَها)، والنهايةُ تُفتح عند أوّل اليوم التالي: معاملةُ الحادية عشرة
 * والنصف ليلةَ ٣١ يناير داخلةٌ، ومعاملةُ منتصف ليل ١ فبراير خارجة.
 *
 * وعمودُ التاريخ (`issued_at`) يُقارَن بتاريخٍ لا بلحظة، وعمودُ اللحظة
 * (`occurred_at`) يُقارَن بلحظةٍ محوَّلةٍ إلى توقيت الخادم — فلا يسقط يومٌ
 * على حدّه حين يختلف التوقيتان. انظر `apply`.
 *
 * ═══ والرابطُ مُدخَلٌ لا يُوثق به ═══
 *
 * الشاشةُ تقرأ فترةً خاطئةً بلطف (`of`): تعود إلى الافتراضيّ وتقول لماذا.
 * والتنزيلُ يردّها (`strict`): ملفٌّ بفترةٍ غير التي طُلبت أسوأ من لا ملفّ.
 */
final class ReportPeriod
{
    public const CUSTOM = 'custom';

    /** أطولُ فترةٍ مخصَّصة — عشرُ سنوات، حارسٌ من رابطٍ كُتب فيه «0001-01-01» لا حدٌّ للعمل */
    public const MAX_DAYS = 3660;

    private function __construct(
        public readonly string $key,
        /** البدايةُ الداخلة — بتوقيت الخادم — أو null فالعمرُ كلّه */
        public readonly ?Carbon $start,
        /** النهايةُ المفتوحة — بتوقيت الخادم — أو null فحتى الآن */
        public readonly ?Carbon $end,
        /** اليومان كما كُتبا (Y-m-d) — للمخصَّصة وحدها */
        public readonly ?string $from = null,
        public readonly ?string $to = null,
        /** لماذا لم تُقرأ الفترةُ المطلوبة — للشاشة */
        public readonly ?string $error = null,
    ) {}

    /** الفترةُ من المرشّحات — والمخصَّصةُ الخاطئةُ تعود إلى الافتراضيّ ومعها سببُها */
    public static function of(array $filters, string $fallback = 'month', ?int $businessId = null): self
    {
        if (($filters['range'] ?? null) !== self::CUSTOM) {
            return self::preset(Demo::range(self::text($filters['range'] ?? null), $fallback));
        }

        $from = self::text($filters['from'] ?? null);
        $to = self::text($filters['to'] ?? null);
        $error = self::check($from, $to);

        if ($error !== null) {
            $preset = self::preset(Demo::range($fallback, 'month'));

            return new self($preset->key, $preset->start, $preset->end, null, null, $error);
        }

        return self::custom((string) $from, (string) $to, $businessId);
    }

    /**
     * الفترةُ للتنزيل — والمخصَّصةُ الخاطئةُ تُردّ بـ422 لا بملفٍّ لفترةٍ أخرى.
     *
     * ردٌّ صريحٌ لا تحويلٌ إلى صفحةٍ سابقة: رابطُ التنزيل يُفتح من زرٍّ أو من
     * رسالة، ومن فتحه ينتظر ملفًّا أو سببًا لا شاشةً أخرى.
     */
    public static function strict(array $filters, string $fallback = 'month', ?int $businessId = null): self
    {
        $period = self::of($filters, $fallback, $businessId);

        abort_if($period->error !== null, 422, (string) $period->error);

        return $period;
    }

    /**
     * سببُ رفض فترةٍ مخصَّصة — أو null فهي سليمة.
     *
     * والتاريخُ يُقرأ بصيغته الصارمة `Y-m-d` لا بتخمين `Carbon::parse`: «2026-02-30»
     * يُقرأ عند Carbon الثاني من مارس، وتصديرٌ يبدأ يومًا لم يطلبه أحد.
     */
    public static function check(?string $from, ?string $to): ?string
    {
        if ($from === null || $to === null) {
            return __('اختر تاريخ البداية وتاريخ النهاية.');
        }

        $start = self::date($from);
        $end = self::date($to);
        if ($start === null || $end === null) {
            return __('تاريخٌ غير صحيح — الصيغة سنة-شهر-يوم.');
        }
        if ($end->lt($start)) {
            return __('تاريخ النهاية قبل تاريخ البداية.');
        }
        if ($start->diffInDays($end) + 1 > self::MAX_DAYS) {
            return __('الفترة أطول من :n يومًا — ضيّقها.', ['n' => self::MAX_DAYS]);
        }

        return null;
    }

    /**
     * حدّا شاشةٍ تشغيليّة (`from` و`to`) — كلٌّ منهما اختياريّ، والمكتوبُ منهما صحيح.
     *
     * شاشاتُ الحركة والقيود والفواتير تقبل حدًّا واحدًا («من أوّل الشهر حتى
     * الآن»)، فلا يُشترط الاثنان كما في المخصَّصة. لكن ما كُتب يُقرأ بصيغته
     * الصارمة، و«إلى» لا تسبق «من».
     */
    public static function checkOpen(?string $from, ?string $to): ?string
    {
        foreach ([$from, $to] as $value) {
            if ($value !== null && $value !== '' && self::date($value) === null) {
                return __('تاريخٌ غير صحيح — الصيغة سنة-شهر-يوم.');
            }
        }
        if ($from && $to && $to < $from) {
            return __('تاريخ النهاية قبل تاريخ البداية.');
        }

        return null;
    }

    /** وصفُ حدّين اختياريّين للترويسة */
    public static function openLabel(?string $from, ?string $to, ?string $none = null): string
    {
        return match (true) {
            (bool) $from && (bool) $to => __('من :from إلى :to', ['from' => $from, 'to' => $to]),
            (bool) $from => __('من :from حتى الآن', ['from' => $from]),
            (bool) $to => __('حتى :to', ['to' => $to]),
            default => $none ?? __('كل الفترات'),
        };
    }

    private static function preset(string $key): self
    {
        return new self($key, Demo::rangeStart($key), null);
    }

    private static function custom(string $from, string $to, ?int $businessId): self
    {
        $tz = AutomationSettings::for($businessId ?? Demo::bid())->timezone;
        $app = (string) config('app.timezone');

        $start = Carbon::createFromFormat('!Y-m-d', $from, $tz)->setTimezone($app);
        $end = Carbon::createFromFormat('!Y-m-d', $to, $tz)->addDay()->setTimezone($app);

        return new self(self::CUSTOM, $start, $end, $from, $to);
    }

    private static function date(string $value): ?Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        return $date instanceof Carbon && $date->format('Y-m-d') === $value && $date->year >= 1900 && $date->year <= 2200 ? $date : null;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function isCustom(): bool
    {
        return $this->key === self::CUSTOM;
    }

    /**
     * يقصر الاستعلامَ على الفترة.
     *
     * `date`: عمودُ تاريخٍ (`issued_at`، `spent_at`) يُقارَن بيومَي الفترة بتوقيت
     * الشركة. `moment`: عمودُ لحظةٍ (`occurred_at`، `created_at`) يُقارَن بحدَّيها
     * بتوقيت الخادم الذي كُتب به. والجاهزةُ بلا نهاية: حتى الآن، كما كانت.
     *
     * @template T of QueryContract
     *
     * @param  T  $query
     * @return T
     */
    public function apply(QueryContract $query, string $column, string $kind = 'moment'): QueryContract
    {
        if ($kind === 'date') {
            if ($this->start !== null) {
                $query->whereDate($column, '>=', $this->startDate());
            }
            if ($this->end !== null) {
                $query->whereDate($column, '<', $this->endDate());
            }

            return $query;
        }

        if ($this->start !== null) {
            $query->where($column, '>=', $this->start);
        }
        if ($this->end !== null) {
            $query->where($column, '<', $this->end);
        }

        return $query;
    }

    /** أوّلُ يومٍ في الفترة — تاريخًا بتوقيت الشركة — أو null */
    public function startDate(): ?string
    {
        return $this->from ?? $this->start?->toDateString();
    }

    /** اليومُ الذي تنتهي عنده (لا يدخل) — أو null فحتى الآن */
    public function endDate(): ?string
    {
        return $this->to !== null ? Carbon::createFromFormat('!Y-m-d', $this->to)->addDay()->toDateString() : null;
    }

    /**
     * البدايةُ يومًا — منتصفَ ليلِ يومِها بتوقيت الخادم — لأبوابٍ تقرأ التاريخَ من
     * `Carbon` (`Revenue::query` وأخواتها تقارن بـ`toDateString()`).
     */
    public function startDay(): ?Carbon
    {
        $date = $this->startDate();

        return $date === null ? null : Carbon::createFromFormat('!Y-m-d', $date);
    }

    /** النهايةُ المفتوحة يومًا — أو null فحتى الآن */
    public function endDay(): ?Carbon
    {
        $date = $this->endDate();

        return $date === null ? null : Carbon::createFromFormat('!Y-m-d', $date);
    }

    /** آخرُ يومٍ داخلٍ — للعرض */
    public function lastDate(): string
    {
        return $this->to ?? now()->toDateString();
    }

    /**
     * وصفُ الفترة بالكلمات وبتاريخيها — يُطبع في الشاشة وفي ترويسة كلّ ملفّ.
     */
    public function label(): string
    {
        return $this->isCustom()
            ? __('فترة مخصّصة').' ('.$this->from.' — '.$this->to.')'
            : Demo::rangeLabel($this->key);
    }

    /** ما يُكتب في الرابط ليفتح على الفترة نفسها */
    public function query(): array
    {
        return $this->isCustom()
            ? ['range' => self::CUSTOM, 'from' => $this->from, 'to' => $this->to]
            : ['range' => $this->key];
    }

    /** وسمٌ قصير لاسم الملفّ */
    public function slug(): string
    {
        return $this->isCustom() ? $this->from.'_'.$this->to : $this->key;
    }

    /** ما تحمله الشاشة: المفتاحُ واليومان والوصفُ وسببُ الرفض */
    public function toArray(): array
    {
        return [
            'range' => $this->key,
            'from' => $this->from,
            'to' => $this->to,
            'label' => $this->label(),
            'error' => $this->error,
        ];
    }
}
