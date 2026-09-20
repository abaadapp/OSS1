<?php

namespace App\Support\Intelligence;

use App\Models\Setting;
use App\Models\User;
use App\Support\Activity;
use RuntimeException;

/**
 * عتباتُ التنبيهات الإداريّة — تضبطها الشركةُ وتُحفظ في `settings`.
 *
 * لا رقمَ «خطر» مكتوبٌ في الكود: كلُّ تنبيهٍ يقيس رقمًا على عتبةٍ تُقرأ من
 * هنا، ويقولها في نصّه. والافتراضيُّ نقطةُ بدايةٍ لا حكم.
 */
final class Thresholds
{
    public const PREFIX = 'mgmt_threshold_';

    /** @var array<string, array{default: float, min: float, max: float, label: string, unit: string}> */
    public const DEFAULTS = [
        'overdue_receivable_amount' => ['default' => 1000.0, 'min' => 0, 'max' => 1e9, 'label' => 'متأخّراتٌ إجماليّة تستدعي تنبيهًا', 'unit' => 'money'],
        'overdue_invoice_days' => ['default' => 30.0, 'min' => 1, 'max' => 365, 'label' => 'فاتورةٌ تأخّرت أكثر من (أيّام)', 'unit' => 'days'],
        'project_margin_percent' => ['default' => 15.0, 'min' => -100, 'max' => 100, 'label' => 'هامشُ مشروعٍ دون (٪)', 'unit' => 'percent'],
        'budget_variance_percent' => ['default' => 10.0, 'min' => 0, 'max' => 500, 'label' => 'انحرافُ مصروفٍ عن الموازنة فوق (٪)', 'unit' => 'percent'],
        'cash_floor_amount' => ['default' => 5000.0, 'min' => 0, 'max' => 1e9, 'label' => 'حدٌّ أدنى للنقد المتوقَّع', 'unit' => 'money'],
        'customer_concentration_percent' => ['default' => 40.0, 'min' => 1, 'max' => 100, 'label' => 'حصّةُ أكبر عميلٍ فوق (٪)', 'unit' => 'percent'],
        'expense_anomaly_percent' => ['default' => 40.0, 'min' => 5, 'max' => 500, 'label' => 'مصروفُ الشهر فوق متوسّطه بـ(٪)', 'unit' => 'percent'],
        'asset_shortage_events' => ['default' => 3.0, 'min' => 1, 'max' => 100, 'label' => 'نقصُ صنفِ معدّاتٍ في (فعاليّات)', 'unit' => 'count'],
        'critical_incidents_per_event' => ['default' => 1.0, 'min' => 0, 'max' => 50, 'label' => 'حوادثُ حرجة لكلّ فعاليّة فوق', 'unit' => 'count'],
    ];

    /** @return array<string, float> */
    public static function for(int $businessId): array
    {
        $saved = Setting::where('business_id', $businessId)->where('key', 'like', self::PREFIX.'%')->pluck('value', 'key');
        $out = [];
        foreach (self::DEFAULTS as $key => $meta) {
            $raw = $saved[self::PREFIX.$key] ?? null;
            $out[$key] = is_numeric($raw) ? (float) $raw : $meta['default'];
        }

        return $out;
    }

    /** @return list<array{key: string, label: string, unit: string, value: float, default: float}> */
    public static function describe(int $businessId): array
    {
        $values = self::for($businessId);

        return collect(self::DEFAULTS)->map(fn ($meta, $key) => [
            'key' => $key, 'label' => __($meta['label']), 'unit' => $meta['unit'], 'value' => $values[$key], 'default' => $meta['default'],
        ])->values()->all();
    }

    /** @param  array<string, mixed>  $data */
    public static function save(int $businessId, array $data, ?User $actor = null): array
    {
        $before = self::for($businessId);
        $changed = [];

        foreach ($data as $key => $value) {
            if (! isset(self::DEFAULTS[$key])) {
                continue;
            }
            if ($value === null || $value === '') {
                Setting::where('business_id', $businessId)->where('key', self::PREFIX.$key)->delete();
                $changed[] = $key;

                continue;
            }
            if (! is_numeric($value)) {
                throw new RuntimeException(__('العتبة رقمٌ.'));
            }
            $v = (float) $value;
            if ($v < self::DEFAULTS[$key]['min'] || $v > self::DEFAULTS[$key]['max']) {
                throw new RuntimeException(__('العتبة «:label» خارج مداها.', ['label' => __(self::DEFAULTS[$key]['label'])]));
            }
            Setting::updateOrCreate(['business_id' => $businessId, 'key' => self::PREFIX.$key], ['value' => (string) $v]);
            if (abs($v - $before[$key]) > 0.0005) {
                $changed[] = $key;
            }
        }

        if ($changed !== []) {
            Activity::log('settings', 'غيّر عتبات التنبيهات الإدارية ('.implode('، ', array_unique($changed)).')', [
                'business_id' => $businessId, 'subject_type' => 'management_thresholds', 'icon' => 'sliders',
            ]);
        }

        return self::for($businessId);
    }
}
