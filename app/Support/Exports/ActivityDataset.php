<?php

namespace App\Support\Exports;

use App\Http\Controllers\ActivityController;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

/**
 * سجل النشاط — بمرشّحات شاشته (البحث · الإجراء · من · إلى).
 *
 * المالكون خارجه كما في شاشته، والقسمُ يُقرأ من نوع ما وقع عليه الفعل،
 * والمرجعُ نوعُه ورقمُه — ما يُبحث به عن الشيء نفسه بعد شهرين.
 */
final class ActivityDataset extends Dataset
{
    /** قسمُ الحدث من نوع ما وقع عليه — ولا يُخترع لنوعٍ غير معروف */
    private const SECTIONS = [
        'project' => 'المشاريع', 'event_operation' => 'العمليات', 'delivery_package' => 'التسليم',
        'customer_invoice' => 'فواتير العملاء', 'customer_payment' => 'التحصيل', 'quotation' => 'عروض الأسعار',
        'asset' => 'المعدّات', 'asset_reservation' => 'المعدّات', 'operation_incident' => 'العمليات',
        'budget' => 'الإدارة', 'expense' => 'المصروفات', 'supplier_invoice' => 'المشتريات',
        'purchase_order' => 'المشتريات', 'customer' => 'العملاء', 'product' => 'المنتجات',
    ];

    private const ACTIONS = [
        'created' => 'إنشاء', 'updated' => 'تعديل', 'deleted' => 'حذف', 'status' => 'تغيير حالة',
        'login' => 'دخول', 'logout' => 'خروج', 'settings' => 'إعدادات', 'report' => 'تقرير', 'checkout' => 'بيع',
    ];

    public static function slug(): string
    {
        return 'activity';
    }

    public function title(): string
    {
        return __('سجل النشاط');
    }

    public function columns(Request $request): array
    {
        return [
            self::col('time', 'التاريخ والوقت'), self::col('user', 'الموظف'), self::col('action', 'الإجراء'),
            self::col('section', 'القسم'), self::col('description', 'الوصف'), self::col('reference', 'المرجع'),
            self::col('ip', 'عنوان IP'),
        ];
    }

    public function data(Request $request, int $bid): array
    {
        return $this->fromQuery(ActivityController::listQuery($request, $bid), fn (ActivityLog $l) => [
            'time' => optional($l->created_at)->format('Y-m-d H:i'),
            'user' => $l->user_name,
            'action' => __(self::ACTIONS[$l->action] ?? $l->action),
            'section' => $l->subject_type ? __(self::SECTIONS[$l->subject_type] ?? $l->subject_type) : null,
            'description' => $l->description,
            'reference' => $l->subject_type && $l->subject_id ? $l->subject_type.' #'.$l->subject_id : null,
            'ip' => $l->ip,
        ]);
    }

    public function filterLines(Request $request): array
    {
        return array_values(array_filter([
            ($q = self::param($request, 'q')) ? __('البحث').': '.$q : null,
            ($a = self::param($request, 'action')) ? __('الإجراء').': '.__(self::ACTIONS[$a] ?? $a) : null,
        ]));
    }
}
