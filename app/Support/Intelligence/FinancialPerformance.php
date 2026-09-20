<?php

namespace App\Support\Intelligence;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\JournalLine;
use App\Models\Project;
use App\Models\StockAdjustment;
use App\Models\SupplierInvoice;
use App\Support\Bank;
use App\Support\Ledger;
use App\Support\Money;
use App\Support\Receivables;
use App\Support\Revenue;
use App\Support\SupplierInvoices;
use App\Support\Vat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * الأداءُ الماليّ للشركة في مدّة — بأساسٍ مكتوبٍ لكلّ رقم. المرحلة 11.
 *
 * ═══ الأساس ═══
 *
 * • **الإيرادُ** فاتورةُ عميلٍ صادرة (`Revenue`)، صافيًا من الضريبة وبعد
 *   إشعارات الدائن — أساسُ الفاتورة.
 * • **التحصيلُ** دفعاتُ العملاء الحيّة بتاريخ قبضها — أساسُ النقد.
 * • **التكلفةُ المباشرة** سندُ المورّد المعتمَد (صافيًا لمن يستردّ ضريبته)
 *   بتاريخ إصداره، وما استُهلك من الرفّ لمشروع — كما تقرؤها `ProjectFinancials`.
 * • **المصروفاتُ** المدفوعةُ وحدها بتاريخ صرفها («المدفوعُ وحده مصروف»).
 * • **الرواتبُ** من الدفتر: ما قُيّد على حساب الرواتب في المدّة — لا صفَّ لها
 *   في المصروفات.
 * • **النقدُ** أرصدةُ الصندوق والبنوك من الدفتر، والشيكاتُ تحت التحصيل
 *   تُقال منفصلةً: ورقةٌ لم تصل البنك ليست نقدًا.
 *
 * والمساهمةُ الإجماليّة = الإيراد − التكلفةُ المباشرة. والنتيجةُ التشغيليّة
 * = المساهمة − المصروفات − الرواتب. وكلاهما **على أساس المستندات** لا ميزانَ
 * المراجعة: سندُ المورّد يُقيَّد في الدفتر مخزونًا لا مصروفًا (إرثُ التجزئة)،
 * فقائمةُ دخلِ الدفتر وحدَها تُخفي مشترياتِ الفعاليّات. ويُقال ذلك في الشاشة.
 *
 * ═══ ولا تُخلط الأسس ═══
 *
 * كلُّ رقمٍ يخرج مع `basis` يقول ما هو. والمقارنةُ بالمدّة السابقة لا تُختلق:
 * مدّةٌ لا حركةَ قبلها تردّ «لا بيانات كافية».
 */
final class FinancialPerformance
{
    /** أقلُّ ما يُقبل من حركةٍ في المدّة السابقة لتُحسب نسبةُ تغيّر */
    private const MIN_BASE = 0.0005;

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(int $bid, Period $period, bool $compare = true): array
    {
        $registered = Vat::enabled($bid);
        $revenue = Revenue::summary($bid, $period->start, $period->end);
        $collections = self::collections($bid, $period);
        $direct = self::directCosts($bid, $period, $registered);
        $expenses = self::expenses($bid, $period);
        $payroll = self::payroll($bid, $period);

        $contribution = round($revenue['net'] - $direct['total'], 3);
        $operating = round($contribution - $expenses['total'] - $payroll, 3);

        $out = [
            'period' => $period->toArray(),
            'currency' => Money::of($bid),
            'basis' => [
                'revenue' => __('فواتيرُ عملاءَ صادرة بتاريخ إصدارها — صافيةٌ من الضريبة وبعد إشعارات الدائن'),
                'collections' => __('دفعاتُ عملاءَ حيّة بتاريخ قبضها — لا تشمل الشيكات المرتجعة'),
                'direct_costs' => __('سنداتُ مورّدين معتمَدة (صافيةً لمن يستردّ ضريبته) وما استُهلك من الرفّ لمشروع'),
                'expenses' => __('مصروفاتٌ مدفوعة بتاريخ صرفها'),
                'payroll' => __('ما قُيّد على حساب الرواتب في الدفتر'),
                'operating_result' => __('الإيراد − التكلفة المباشرة − المصروفات − الرواتب — على أساس المستندات لا ميزان المراجعة'),
            ],
            'revenue' => [
                'net' => $revenue['net'],
                'gross' => $revenue['total'],
                'tax' => $revenue['netTax'],
                'credited' => $revenue['credited'],
                'invoices' => $revenue['count'],
            ],
            'collections' => $collections,
            'direct_costs' => $direct,
            'expenses' => $expenses,
            'payroll' => $payroll,
            'gross_contribution' => $contribution,
            'gross_margin_percent' => $revenue['net'] > 0 ? round($contribution / $revenue['net'] * 100, 2) : null,
            'operating_result' => $operating,
            'operating_margin_percent' => $revenue['net'] > 0 ? round($operating / $revenue['net'] * 100, 2) : null,
            'generated_at' => now()->format('Y-m-d H:i'),
        ];

        if ($compare) {
            $prev = self::snapshot($bid, $period->previous(), false);
            $out['previous'] = [
                'period' => $prev['period'],
                'revenue' => $prev['revenue']['net'],
                'collections' => $prev['collections']['total'],
                'expenses' => $prev['expenses']['total'],
                'direct_costs' => $prev['direct_costs']['total'],
                'operating_result' => $prev['operating_result'],
                'change' => [
                    'revenue' => self::change($revenue['net'], $prev['revenue']['net']),
                    'collections' => self::change($collections['total'], $prev['collections']['total']),
                    'expenses' => self::change($expenses['total'], $prev['expenses']['total']),
                    'direct_costs' => self::change($direct['total'], $prev['direct_costs']['total']),
                ],
            ];
        }

        return $out;
    }

    /* ─────────────────────────── الأجزاء ─────────────────────────── */

    /** @return array{total: float, count: int, basis: string} */
    public static function collections(int $bid, Period $period): array
    {
        $row = Revenue::collections($bid, $period->startAt(), $period->endAt())->toBase()
            ->selectRaw('COALESCE(SUM(customer_payments.amount), 0) AS s, COUNT(*) AS c')->first();

        return ['total' => round((float) ($row->s ?? 0), 3), 'count' => (int) ($row->c ?? 0), 'basis' => 'cash'];
    }

    /**
     * التكلفةُ المباشرة: سنداتُ المورّدين المعتمَدة وما استُهلك من الرفّ.
     *
     * @return array{supplier_invoices: float, stock_consumed: float, total: float, unallocated_supplier_invoices: float, basis: string}
     */
    public static function directCosts(int $bid, Period $period, ?bool $registered = null): array
    {
        $registered ??= Vat::enabled($bid);
        $basis = $registered ? '(total - tax)' : 'total';

        $q = SupplierInvoice::where('business_id', $bid)->owed()->where('is_opening_balance', false)
            ->whereDate('issued_at', '>=', $period->startDate())->whereDate('issued_at', '<', $period->endDate());

        $suppliers = round((float) (clone $q)->selectRaw('COALESCE(SUM('.$basis.'), 0) AS v')->value('v'), 3);
        $unallocated = round((float) (clone $q)->whereNull('project_id')->selectRaw('COALESCE(SUM('.$basis.'), 0) AS v')->value('v'), 3);

        $stock = round(max(0.0, -(float) StockAdjustment::where('business_id', $bid)->whereNotNull('project_id')
            ->where('adjusted_at', '>=', $period->startAt())->where('adjusted_at', '<', $period->endAt())
            ->selectRaw('COALESCE(SUM(quantity_delta * cost_at_time), 0) AS v')->value('v')), 3);

        return [
            'supplier_invoices' => $suppliers,
            'stock_consumed' => $stock,
            'total' => round($suppliers + $stock, 3),
            'unallocated_supplier_invoices' => $unallocated,
            'basis' => 'accrual_documents',
        ];
    }

    /**
     * المصروفاتُ المدفوعة — مجموعًا وبالنوع.
     *
     * @return array{total: float, count: int, unallocated: float, by_type: list<array{type: string, total: float, count: int}>, basis: string}
     */
    public static function expenses(int $bid, Period $period): array
    {
        $q = Expense::where('business_id', $bid)->paid()
            ->whereDate('spent_at', '>=', $period->startDate())->whereDate('spent_at', '<', $period->endDate());

        $total = round((float) (clone $q)->sum('amount'), 3);
        $count = (int) (clone $q)->count();
        $unallocated = round((float) (clone $q)->whereNull('project_id')->sum('amount'), 3);
        $byType = (clone $q)->toBase()->selectRaw('type, COALESCE(SUM(amount), 0) AS s, COUNT(*) AS c')
            ->groupBy('type')->orderByDesc('s')->limit(20)->get()
            ->map(fn ($r) => ['type' => (string) $r->type, 'total' => round((float) $r->s, 3), 'count' => (int) $r->c])->values()->all();

        return ['total' => $total, 'count' => $count, 'unallocated' => $unallocated, 'by_type' => $byType, 'basis' => 'cash'];
    }

    /** الرواتبُ من الدفتر — صافي ما قُيّد على حساب الرواتب في المدّة */
    public static function payroll(int $bid, Period $period): float
    {
        return self::ledgerNet($bid, 'salaries', $period);
    }

    /** صافي حركة حسابٍ نظاميّ في مدّة — مدينًا ناقص دائنًا للمصروف، وعكسُه للإيراد */
    public static function ledgerNet(int $bid, string $systemKey, Period $period): float
    {
        $account = Ledger::account($bid, $systemKey);
        if ($account === null) {
            return 0.0;
        }

        return self::accountNet($account, $period);
    }

    public static function accountNet(Account $account, Period $period): float
    {
        $row = JournalLine::query()->toBase()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $account->id)
            ->where('journal_entries.entry_date', '>=', $period->startDate())
            ->where('journal_entries.entry_date', '<', $period->endDate())
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) AS d, COALESCE(SUM(journal_lines.credit), 0) AS c')->first();

        $diff = (float) ($row->d ?? 0) - (float) ($row->c ?? 0);

        return round($account->normal_side === 'credit' ? -$diff : $diff, 3);
    }

    /* ─────────────────────────── النقد ─────────────────────────── */

    /**
     * الموقفُ النقديّ الآن — من الدفتر.
     *
     * @return array<string, mixed>
     */
    public static function cashPosition(int $bid, ?Carbon $through = null): array
    {
        $cash = Ledger::balance($bid, 'cash', $through);
        $bank = $through === null ? Bank::total($bid) : Ledger::balance($bid, 'bank', $through);
        $cheques = Ledger::balance($bid, 'cheques_receivable', $through);

        return [
            'cash' => $cash,
            'bank' => $bank,
            'liquid' => round($cash + $bank, 3),
            'cheques_under_collection' => $cheques,
            'bank_accounts' => $through === null ? BankAccount::where('business_id', $bid)->get()
                ->map(fn (BankAccount $a) => ['id' => $a->id, 'name' => $a->name, 'balance' => round((float) $a->balance(), 3)])->values()->all() : [],
            'basis' => __('أرصدةُ الصندوق والبنوك من دفتر الأستاذ — والشيكات تحت التحصيل ليست نقدًا'),
            'as_of' => ($through ?? now())->format('Y-m-d H:i'),
        ];
    }

    /** الذممُ الآن — من `Receivables` نفسِها التي تقرؤها شاشةُ المالية */
    public static function receivables(int $bid): array
    {
        $totals = Receivables::totals($bid);

        return [
            'outstanding' => round($totals['total'] - $totals['uninvoiced'], 3),
            'overdue' => $totals['overdue'],
            'due_soon' => $totals['due_soon'],
            'invoices' => $totals['invoices'],
            'customers' => $totals['customers'],
            'unallocated_payments' => $totals['credit'],
            'basis' => __('فواتيرُ صادرة قائمة ناقص ما خُصّص لها من دفعاتٍ حيّة وما رُدّ منها'),
        ];
    }

    /**
     * المستحقُّ علينا — ما اعتُمد ولم يُسدَّد.
     *
     * @return array{supplier_invoices: float, expenses_unpaid: float, total: float}
     */
    public static function payables(int $bid): array
    {
        $si = round((float) SupplierInvoice::where('business_id', $bid)->owed()
            ->selectRaw('COALESCE(SUM(total - paid), 0) AS v')->value('v'), 3);
        $expenses = round((float) Expense::where('business_id', $bid)->unpaid()->sum('amount'), 3);

        return ['supplier_invoices' => $si, 'expenses_unpaid' => $expenses, 'total' => round($si + $expenses, 3)];
    }

    /* ─────────────────────────── الاتّجاه ─────────────────────────── */

    /**
     * سلسلةٌ شهريّة — إيرادٌ وتحصيلٌ ومصروفاتٌ وتكلفةٌ وعددُ مشاريع.
     *
     * تجميعٌ في القاعدة شهرًا بشهر، لا تحميلٌ للصفوف.
     *
     * @return list<array<string, mixed>>
     */
    public static function monthly(int $bid, Period $period): array
    {
        $registered = Vat::enabled($bid);
        $months = $period->months();
        $keys = array_map(fn (Carbon $m) => $m->format('Y-m'), $months);

        /* الإيرادُ صافيًا شهرًا بشهر: الوعاءُ ناقص ما رُدّ منه بإشعارات الدائن في شهر الإشعار */
        $revenue = self::monthlySeries(Revenue::query($bid, $period->start, $period->end)->toBase(), 'issued_at', '(subtotal - discount_total)', 'date');
        $credits = self::monthlySeries(Revenue::credits($bid, $period->start, $period->end)->toBase(), 'issued_at', '(amount - tax_amount)', 'date');
        $collections = self::monthlySeries(
            Revenue::collections($bid, $period->startAt(), $period->endAt())->toBase(), 'customer_payments.occurred_at', 'customer_payments.amount', 'ts');
        $expenses = self::monthlySeries(
            Expense::where('business_id', $bid)->paid()->whereDate('spent_at', '>=', $period->startDate())->whereDate('spent_at', '<', $period->endDate())->toBase(),
            'spent_at', 'amount', 'date');
        $suppliers = self::monthlySeries(
            SupplierInvoice::where('business_id', $bid)->owed()->where('is_opening_balance', false)
                ->whereDate('issued_at', '>=', $period->startDate())->whereDate('issued_at', '<', $period->endDate())->toBase(),
            'issued_at', $registered ? '(total - tax)' : 'total', 'date');
        $projects = self::monthlySeries(
            Project::where('business_id', $bid)->whereNotIn('status', [Project::DRAFT, Project::CANCELLED])
                ->whereDate('created_at', '>=', $period->startDate())->whereDate('created_at', '<', $period->endDate())->toBase(),
            'created_at', 'approved_value', 'ts');

        $out = [];
        foreach ($keys as $k) {
            $r = $revenue[$k] ?? ['sum' => 0.0, 'count' => 0];
            $p = $projects[$k] ?? ['sum' => 0.0, 'count' => 0];
            $out[] = [
                'month' => $k,
                'revenue' => round((float) $r['sum'] - (float) ($credits[$k]['sum'] ?? 0), 3),
                'invoices' => (int) $r['count'],
                'collections' => round((float) ($collections[$k]['sum'] ?? 0), 3),
                'expenses' => round((float) ($expenses[$k]['sum'] ?? 0), 3),
                'direct_costs' => round((float) ($suppliers[$k]['sum'] ?? 0), 3),
                'projects' => (int) $p['count'],
                'average_project_value' => $p['count'] > 0 ? round((float) $p['sum'] / $p['count'], 3) : null,
            ];
        }

        return $out;
    }

    /**
     * تجميعٌ شهريّ لعمودٍ في القاعدة — بصيغة المحرّك.
     *
     * @return array<string, array{sum: float, count: int}>
     */
    public static function monthlySeries($query, string $column, string $amount, string $kind): array
    {
        $driver = DB::connection()->getDriverName();
        $expr = match ($driver) {
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            'sqlite' => "strftime('%Y-%m', {$column})",
            default => "DATE_FORMAT({$column}, '%Y-%m')",
        };

        return $query->selectRaw("{$expr} AS ym, COALESCE(SUM({$amount}), 0) AS s, COUNT(*) AS c")
            ->groupBy('ym')->get()
            ->mapWithKeys(fn ($r) => [(string) $r->ym => ['sum' => round((float) $r->s, 3), 'count' => (int) $r->c]])->all();
    }

    /** نسبةُ التغيّر — أو «لا بيانات كافية» حين لا أساسَ يُقاس عليه */
    public static function change(float $current, float $previous): array
    {
        if (abs($previous) < self::MIN_BASE) {
            return ['percent' => null, 'status' => 'insufficient_data'];
        }

        return ['percent' => round(($current - $previous) / abs($previous) * 100, 1), 'status' => 'ok', 'delta' => round($current - $previous, 3)];
    }

    /**
     * سندُ مورّدٍ أو مصروفٌ بلا مشروع في المدّة — عددًا وقيمة؛ للاكتمال.
     *
     * @return array{count: int, value: float}
     */
    public static function unallocatedCosts(int $bid, Period $period, ?bool $registered = null): array
    {
        $registered ??= Vat::enabled($bid);
        $basis = $registered ? '(total - tax)' : 'total';

        $si = SupplierInvoice::where('business_id', $bid)->where('approval_status', SupplierInvoices::APPROVED)
            ->where('is_opening_balance', false)->whereNull('project_id')
            ->whereDate('issued_at', '>=', $period->startDate())->whereDate('issued_at', '<', $period->endDate());
        $ex = Expense::where('business_id', $bid)->paid()->whereNull('project_id')
            ->whereDate('spent_at', '>=', $period->startDate())->whereDate('spent_at', '<', $period->endDate());

        return [
            'count' => (int) (clone $si)->count() + (int) (clone $ex)->count(),
            'value' => round((float) (clone $si)->selectRaw('COALESCE(SUM('.$basis.'), 0) AS v')->value('v') + (float) (clone $ex)->sum('amount'), 3),
        ];
    }
}
