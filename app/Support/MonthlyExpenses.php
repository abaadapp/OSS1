<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\SupplierInvoice;
use App\Support\Automation\Settings as AutomationSettings;
use App\Support\Intelligence\Period;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * «مصروفات الشهر» — شهرٌ تقويميٌّ واحد، من الدفتر، بلا حسابٍ مزدوج.
 *
 * ═══ الإجماليُّ من الدفتر وحده ═══
 *
 * صافي حركة حسابات «المصروف» (الفئة 5) في قيود الشهر — مدينًا ناقص دائن.
 * كلُّ حدثٍ ماليٍّ قيدٌ واحد على حسابٍ واحد، فلا يُجمع شيءٌ مرّتين:
 *
 *  • سندُ المورّد المعتمَد يدخل «مشتريات مباشرة» (5150) — وما ذهب منه إلى
 *    المخزون (1400) أصلٌ لا مصروف، يُعرض معلومةً ولا يُجمع. وأمرُ الشراء لا
 *    قيدَ له: التزامٌ لا مصروف.
 *  • الراتبُ يُقيَّد يوم اعتماد المسيرة على «الرواتب» (5200)، وصرفُه يمسّ
 *    الذمّة والنقد لا المصروف — فلا يُعدّ مرّتين.
 *  • المصروفُ المسدَّد يُقيَّد على حساب نوعه؛ وغيرُ المسدَّد لا قيدَ له بعد.
 *  • والعكسُ يُقيَّد بتاريخه فيُطرح من شهره: سندٌ أُلغي في الشهر نفسِه صافيه صفر.
 *
 * فالرقمُ يطابق دفتر اليوميّة لمن جمع سطوره بنفسه (`admin.finance.journal`
 * بمدّة الشهر) — وعليه اختبار.
 *
 * ═══ والمدفوعُ والمستحقُّ من مستندات الشهر ═══
 *
 * المصروفاتُ المسجَّلة بتاريخ الشهر، وسنداتُ الموردين المعتمَدة الصادرة فيه،
 * ومسيرةُ رواتبه: ما سُدّد منها، وما بقي، وما فات موعدُه وما زال مستحقًّا. وهي
 * غيرُ الإجماليّ عمدًا: المستحقُّ يشمل مصروفًا لم يُسدَّد فلم يدخل الدفترَ بعد.
 *
 * ═══ والشهرُ مستقلٌّ عن المدّة ═══
 *
 * يُختار بنفسه (`?expense_month=YYYY-MM`) بتوقيت الشركة [أوّلُه، أوّلُ ما بعده)،
 * ولا يتبع تبويبات المدّة في الملخّص — فلا يُكتب «الشهر» فوق رقمٍ لمدّةٍ أخرى.
 */
final class MonthlyExpenses
{
    /** نوعُ حسابات المصروف في الشجرة (`Account::TYPES`) */
    private const EXPENSE = 'مصروف';

    /** كم شهرًا يُعرض في قائمة الاختيار */
    public const MONTHS_BACK = 24;

    /** مجموعاتُ العرض بالحساب النظاميّ — وما لا مفتاحَ له (حسابٌ أضافه التاجر) تشغيليّ */
    public const GROUPS = [
        'operating' => 'مصروفات تشغيلية',
        'suppliers' => 'فواتير موردين ومشتريات مباشرة',
        'payroll' => 'رواتب ومستحقات موظفين',
        'cogs' => 'تكلفة البضاعة المباعة والمستهلكة',
        'depreciation' => 'إهلاك الأصول',
        'other' => 'مصروفات أخرى وعجز المخزون',
    ];

    private const KEY_GROUP = [
        'direct_purchases' => 'suppliers',
        'salaries' => 'payroll',
        'cogs' => 'cogs',
        'depreciation' => 'depreciation',
        'other_expenses' => 'other',
    ];

    /** الشهرُ من مدخل `YYYY-MM` — أو الشهرُ الجاري بتوقيت الشركة */
    public static function month(int $businessId, ?string $month): Period
    {
        $tz = AutomationSettings::for($businessId)->timezone;
        $start = is_string($month) && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $m) === 1
            ? Carbon::create((int) $m[1], (int) $m[2], 1, 0, 0, 0, $tz)
            : Carbon::now($tz)->startOfMonth();

        /* والمستقبلُ لا يُختار: لا مصروفَ لشهرٍ لم يبدأ */
        if ($start->greaterThan(Carbon::now($tz)->startOfMonth())) {
            $start = Carbon::now($tz)->startOfMonth();
        }

        return Period::between($businessId, $start, $start->copy()->addMonth(), 'custom');
    }

    /**
     * بطاقةُ الشهر كاملةً.
     *
     * @return array<string, mixed>
     */
    public static function for(int $businessId, ?string $month = null): array
    {
        Ledger::ensureSystemAccounts($businessId);
        $period = self::month($businessId, $month);
        $previous = Period::between($businessId, $period->start->copy()->subMonthNoOverflow(), $period->start->copy(), 'custom');

        $accounts = self::byAccount($businessId, $period);
        $total = round(array_sum(array_column($accounts, 'total')), 3);
        $previousTotal = round(array_sum(array_column(self::byAccount($businessId, $previous), 'total')), 3);

        $groups = [];
        foreach ($accounts as $row) {
            $key = self::KEY_GROUP[$row['system_key'] ?? ''] ?? 'operating';
            $groups[$key] ??= ['key' => $key, 'label' => __(self::GROUPS[$key]), 'total' => 0.0, 'accounts' => []];
            $groups[$key]['total'] = round($groups[$key]['total'] + $row['total'], 3);
            $groups[$key]['accounts'][] = $row;
        }
        /* بترتيب `GROUPS` — والفارغُ لا يُعرض */
        $groups = array_values(array_filter(array_map(fn ($k) => $groups[$k] ?? null, array_keys(self::GROUPS)),
            fn ($g) => $g !== null && abs($g['total']) > 0.0005));

        return [
            'month' => $period->start->format('Y-m'),
            'label' => self::label($period->start),
            'from' => $period->startDate(),
            'to' => $period->end->copy()->subDay()->toDateString(),
            'total' => $total,
            'groups' => $groups,
            'previous' => ['month' => $previous->start->format('Y-m'), 'label' => self::label($previous->start), 'total' => $previousTotal],
            'change' => [
                'amount' => round($total - $previousTotal, 3),
                'percent' => abs($previousTotal) > 0.0005 ? round(100 * ($total - $previousTotal) / abs($previousTotal), 1) : null,
            ],
            'entries' => self::entryCount($businessId, $period),
            'inventory_purchases' => self::inventoryPurchases($businessId, $period),
            'obligations' => self::obligations($businessId, $period),
            'months' => self::months($businessId),
        ];
    }

    /**
     * صافي كلّ حساب مصروفٍ في الشهر — من سطور القيود.
     *
     * @return list<array{account_id: int, code: string, name: string, system_key: ?string, total: float, entries: int}>
     */
    public static function byAccount(int $businessId, Period $period): array
    {
        $rows = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.business_id', $businessId)
            ->where('accounts.business_id', $businessId)
            ->where('accounts.type', self::EXPENSE)
            ->where('journal_entries.entry_date', '>=', $period->startDate())
            ->where('journal_entries.entry_date', '<', $period->endDate())
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.system_key', 'accounts.normal_side')
            ->orderBy('accounts.code')
            ->selectRaw('accounts.id AS id, accounts.code AS code, accounts.name AS name, accounts.system_key AS system_key, accounts.normal_side AS side,
                COALESCE(SUM(journal_lines.debit), 0) AS d, COALESCE(SUM(journal_lines.credit), 0) AS c, COUNT(DISTINCT journal_entries.id) AS n')
            ->get();

        return $rows->map(function ($r) {
            $net = (float) $r->d - (float) $r->c;

            return [
                'account_id' => (int) $r->id, 'code' => (string) $r->code, 'name' => (string) $r->name,
                'system_key' => $r->system_key, 'total' => round($r->side === 'credit' ? -$net : $net, 3), 'entries' => (int) $r->n,
            ];
        })->values()->all();
    }

    private static function entryCount(int $businessId, Period $period): int
    {
        return (int) DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.business_id', $businessId)->where('accounts.type', self::EXPENSE)
            ->where('journal_entries.entry_date', '>=', $period->startDate())
            ->where('journal_entries.entry_date', '<', $period->endDate())
            ->distinct()->count('journal_entries.id');
    }

    /** ما ذهب من سندات الموردين المعتمَدة إلى المخزون — أصلٌ لا مصروف حتى يُستهلك */
    private static function inventoryPurchases(int $businessId, Period $period): float
    {
        return round((float) SupplierInvoice::where('business_id', $businessId)->owed()
            ->where(fn ($q) => $q->whereNull('is_opening_balance')->orWhere('is_opening_balance', false))
            ->whereDate('issued_at', '>=', $period->startDate())->whereDate('issued_at', '<', $period->endDate())
            ->sum('inventory_cost'), 3);
    }

    /**
     * المدفوعُ والمستحقُّ والمتأخّر — من مستندات الشهر.
     *
     * @return array{paid: float, due: float, overdue: float, documents: array{expenses: int, supplier_invoices: int, payroll: int}}
     */
    public static function obligations(int $businessId, Period $period): array
    {
        $today = Carbon::now($period->timezone)->toDateString();
        $from = $period->startDate();
        $to = $period->endDate();

        /* المصروفاتُ بتاريخ الشهر — بلا قيود المخزون الداخليّة (عجزٌ لا التزام) */
        $expenses = Expense::where('business_id', $businessId)->where(fn ($q) => $q->whereNull('method')->orWhere('method', '!=', 'قيد داخلي'))
            ->whereDate('spent_at', '>=', $from)->whereDate('spent_at', '<', $to);
        $expensePaid = (float) (clone $expenses)->paid()->sum('amount');
        $expenseDue = (float) (clone $expenses)->unpaid()->sum('amount');
        $expenseOverdue = (float) (clone $expenses)->unpaid()->whereNotNull('due_date')->whereDate('due_date', '<', $today)->sum('amount');

        /* سنداتُ الموردين المعتمَدة الصادرة في الشهر — المعلَّقُ ليس دَينًا */
        $invoices = SupplierInvoice::where('business_id', $businessId)->owed()
            ->where(fn ($q) => $q->whereNull('is_opening_balance')->orWhere('is_opening_balance', false))
            ->whereDate('issued_at', '>=', $from)->whereDate('issued_at', '<', $to);
        $invoicePaid = (float) (clone $invoices)->sum('paid');
        $invoiceDue = max(0.0, (float) (clone $invoices)->sum('total') - $invoicePaid);
        $invoiceOverdue = (float) (clone $invoices)->whereNotNull('due_at')->whereDate('due_at', '<', $today)
            ->whereColumn('paid', '<', 'total')->get(['total', 'paid'])->sum(fn ($i) => max(0, (float) $i->total - (float) $i->paid));

        /* ومسيرةُ رواتب الشهر إن اعتُمدت — الصافي المصروف والباقي */
        $run = PayrollRun::where('business_id', $businessId)->whereIn('status', ['معتمدة', 'مصروفة'])
            ->whereDate('period', '>=', $from)->whereDate('period', '<', $to)->first();
        $payrollPaid = $run ? (float) PayrollLine::where('payroll_run_id', $run->id)->where('paid', true)->sum('net') : 0.0;
        $payrollDue = $run ? (float) PayrollLine::where('payroll_run_id', $run->id)->where('paid', false)->sum('net') : 0.0;

        return [
            'paid' => round($expensePaid + $invoicePaid + $payrollPaid, 3),
            'due' => round($expenseDue + $invoiceDue + $payrollDue, 3),
            'overdue' => round($expenseOverdue + $invoiceOverdue, 3),
            'documents' => [
                'expenses' => (int) (clone $expenses)->count(),
                'supplier_invoices' => (int) (clone $invoices)->count(),
                'payroll' => $run ? 1 : 0,
            ],
        ];
    }

    /** @return list<array{value: string, label: string}> أشهرُ القائمة — الجاري فما قبله */
    public static function months(int $businessId): array
    {
        $cursor = Carbon::now(AutomationSettings::for($businessId)->timezone)->startOfMonth();
        $out = [];
        for ($i = 0; $i < self::MONTHS_BACK; $i++) {
            $out[] = ['value' => $cursor->format('Y-m'), 'label' => self::label($cursor)];
            $cursor->subMonthNoOverflow();
        }

        return $out;
    }

    /** «أكتوبر 2026» — بلغة الواجهة */
    public static function label(Carbon $month): string
    {
        return __(self::MONTH_NAMES[(int) $month->format('n')]).' '.$month->format('Y');
    }

    private const MONTH_NAMES = [
        1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل', 5 => 'مايو', 6 => 'يونيو',
        7 => 'يوليو', 8 => 'أغسطس', 9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
    ];
}
