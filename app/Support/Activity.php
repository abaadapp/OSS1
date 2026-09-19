<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\User;

/**
 * تسجيل النشاط (Audit Log) — استدعِ Activity::log(...) بعد أي عملية.
 */
class Activity
{
    private const ICONS = [
        'created' => 'plus-circle', 'updated' => 'pencil', 'deleted' => 'trash-2',
        'login' => 'log-in', 'logout' => 'log-out', 'checkout' => 'shopping-cart',
        'status' => 'refresh-cw', 'settings' => 'settings', 'hold' => 'pause-circle',
        // «وردية» بقيت وإن رُفعت الميزة: سجلّاتٌ قديمة تحمل النوع، وبلا
        // اسمٍ لها تُعرض في شاشة النشاط بمفتاحها اللاتينيّ
        'shift' => 'clock', 'report' => 'file-text', 'backup' => 'database-backup', 'restore' => 'database',
        'return' => 'undo-2',
    ];

    private const COLORS = [
        'created' => 'success', 'updated' => 'info', 'deleted' => 'danger',
        'login' => 'primary', 'logout' => 'gray', 'checkout' => 'success',
        'status' => 'warning', 'settings' => 'primary', 'hold' => 'warning',
        'shift' => 'info', 'report' => 'info', 'backup' => 'primary', 'restore' => 'warning',
        'return' => 'danger',
    ];

    /** اسمُ فاعلٍ بلا جلسة — «الأتمتة» حين يكتب المحرّكُ لا موظّف */
    private static ?string $actor = null;

    /**
     * تنفيذٌ باسم فاعلٍ غير مستخدم — فلا يُكتب «زائر» عمّا فعله المحرّك.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public static function as(string $name, callable $fn): mixed
    {
        $previous = self::$actor;
        self::$actor = $name;

        try {
            return $fn();
        } finally {
            self::$actor = $previous;
        }
    }

    public static function log(string $action, string $description, array $opts = []): void
    {
        $u = auth()->user();

        /*
         * ولا استثناء: كلُّ فعلٍ يُقيَّد على صاحبه.
         *
         * كان بابٌ للإسكات — دخولُ مدير المنصّة وخروجُه لا يُكتبان لأنّه يدخل
         * كلّ يوم مرّاتٍ فيزاحم ما يُراقَب حقًّا. وذهبت لوحةُ المنصّة وذهب
         * الاستثناء معها: في نظامٍ داخليٍّ لا أحدَ فوق الدفتر.
         */
        ActivityLog::create([
            'business_id' => $opts['business_id'] ?? $u?->business_id,
            'user_id' => $u?->id,
            'user_name' => $u?->name ?? self::$actor ?? 'زائر',
            'action' => $action,
            'subject_type' => $opts['subject_type'] ?? null,
            'subject_id' => $opts['subject_id'] ?? null,
            'description' => $description,
            'icon' => $opts['icon'] ?? (self::ICONS[$action] ?? 'activity'),
            'color' => $opts['color'] ?? (self::COLORS[$action] ?? 'primary'),
            'ip' => request()->ip(),
        ]);
    }
}
