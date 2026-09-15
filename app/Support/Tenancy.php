<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;

/**
 * حالة المستأجر: هل يُسمح لهذا الحساب بالعمل الآن؟
 *
 * كانت «موقوف» و«معطل» كلمتين تُكتبان في عمودين لا يقرؤهما أحد: موظفٌ
 * موقوفٌ في شركةٍ معطّلة كان يسجّل الدخول ويعمل.
 *
 * مصدرٌ واحد يقرأه حارس الطلب وشاشة الدخول معًا — لئلا يُمنع عند الباب من
 * يمرّ من نافذة.
 */
class Tenancy
{
    public const USER_SUSPENDED = 'user_suspended';

    public const USER_REMOVED = 'user_removed';

    public const BUSINESS_DISABLED = 'business_disabled';

    /** حالات الحساب التي تعني «موقوف» — الواجهة تكتبها بأكثر من لفظ */
    private const BLOCKED_USER = ['موقوف', 'معطل', 'معطّل'];

    private const BLOCKED_BUSINESS = ['معطل', 'معطّل'];

    /** سبب المنع إن وُجد، وإلا null */
    public static function blockReason(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        /*
         * المحذوف قبل الموقوف: الحذف أشدّ، ورسالتُه غير رسالته.
         *
         * الحذف هنا ناعم — يبقى الصفّ ويُرفع عنه العلم — ومزوّد المصادقة
         * يقرأ المستخدم بلا نطاقات، فالمحذوف يبقى مسجَّلًا في جلسته. فصاحب
         * النشاط يطرد موظفًا ويحذفه من «الموظفون»، والموظف واقفٌ في الشارع
         * ولوحتُه مفتوحة على هاتفه: يقرأ الزبائن، ويعدّل المنتجات، ويكتب —
         * أثبتناه: صفٌّ كُتب بعد الحذف. والنافذة ١٢٠ دقيقة تتجدّد مع كل نقرة،
         * أي ما دام يضغط.
         *
         * ودخولُه من جديد ممنوعٌ أصلًا: البحث بالبريد وبالرمز يمرّ بنطاق
         * الحذف الناعم فلا يجده. فالثغرة في الجلسة القائمة وحدها — وهنا
         * تُغلق، عند النقطة التي يقرؤها كلّ باب.
         */
        if (method_exists($user, 'trashed') && $user->trashed()) {
            return self::USER_REMOVED;
        }

        if (in_array((string) $user->status, self::BLOCKED_USER, true)) {
            return self::USER_SUSPENDED;
        }

        $business = $user->business;
        if (! $business) {
            return null;
        }

        if (in_array((string) $business->status, self::BLOCKED_BUSINESS, true)) {
            return self::BUSINESS_DISABLED;
        }

        return null;
    }

    /** إعدادٌ عامٌّ لا يخصّ شركةً بعينها (business_id = null) */
    public static function platform(string $key, $default = null)
    {
        $value = Setting::whereNull('business_id')->where('key', $key)->value('value');

        return $value === null || $value === '' ? $default : $value;
    }

    /**
     * وضع الصيانة: النظام موقوفٌ مؤقّتًا على الجميع.
     *
     * مقبضٌ يُرفع قبل ترقيةٍ فلا يعمل أحدٌ على قاعدةٍ تُهاجَر تحته. ويُضبط
     * من القاعدة مباشرةً (`settings` بـ`business_id = null`) — لا شاشةَ له.
     */
    public static function maintenance(): bool
    {
        return (string) self::platform('maintenance_mode', '0') === '1';
    }

    /** الرسالة كما تُقال للمستخدم — لا رمز الحالة */
    public static function message(string $reason): string
    {
        return match ($reason) {
            self::USER_SUSPENDED => __('حسابك موقوف. راجع صاحب النشاط.'),
            self::USER_REMOVED => __('لم يعد هذا الحساب موجودًا. راجع صاحب النشاط.'),
            self::BUSINESS_DISABLED => __('حساب الشركة معطَّل. راجع مدير النظام.'),
            default => __('لا يمكن الدخول الآن.'),
        };
    }
}
