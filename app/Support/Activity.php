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

    public static function log(string $action, string $description, array $opts = []): void
    {
        $u = auth()->user();

        /*
         * دخول مدير المنصة إلى بابه هو وخروجه منه لا يُقيَّدان.
         *
         * السجلّ يُفتح ليُقرأ فيه ما جرى للمتاجر، وكان يزاحمه بأفعاله هو:
         * اثنان وأربعون سطر دخولٍ وأربعةٌ وعشرون خروجًا يدفعان ما يُراقَب حقًّا
         * خارج الصفحة الأولى — وهو يدخل كلّ يوم مرّاتٍ لأنّ عملَه هنا.
         *
         * والشرط `self` لا اسمُ الفعل وحده: «دخل كتاجر» يُقيَّد بفعل `login`
         * أيضًا، وهو أهمّ سطرٍ في السجلّ كلّه — به يُعرف أنّ الدعم دخل متجرًا
         * ومتى. فحصرُ الإسكات في المصدر الذي يعلن أنه بابُ صاحبه يُبقيه.
         *
         * ومحاولاتُ الدخول الفاشلة تبقى كذلك: إشارةُ أمنٍ لا ضجيجَ إدارة،
         * وإخفاؤها يُعمي عن مَن يطرق الباب.
         */
        ActivityLog::create([
            'business_id' => $opts['business_id'] ?? $u?->business_id,
            'user_id' => $u?->id,
            'user_name' => $u?->name ?? 'زائر',
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
