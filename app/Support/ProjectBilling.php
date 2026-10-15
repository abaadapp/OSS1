<?php

namespace App\Support;

use App\Models\CustomerInvoice;
use App\Models\Project;
use App\Models\ProjectItem;
use App\Models\User;
use App\Support\Import\Provenance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * فوترةُ المشروع على مراحله — الدفعةُ المقدّمة والفاتورةُ النهائيّة.
 *
 * ═══ لا مالَ يُكتب هنا ═══
 *
 * الفاتورةُ تُنشأ من `CustomerInvoices::create` وتُصدَر من
 * `CustomerInvoices::issue` — والقيدُ والذممُ والتحصيلُ ولقطةُ الإقفال كلُّها
 * تقرأ الفاتورةَ كما تقرأ غيرها. وما يزيده هذا الملفّ ثلاثة: كم تكون
 * (المبلغُ يُحسب هنا لا في المتصفّح)، ومتى تجوز (بعد أيّ مرحلة)، وما دورُها
 * في المشروع (`billing_role`).
 *
 * ═══ والحقيقةُ الماليّة من مصدرها ═══
 *
 * القيمةُ الحاليّة وصافي المفوتَر (بعد إشعارات الدائن) من `ProjectFinancials`
 * — فالفاتورةُ النهائيّة = القيمةُ الحاليّة (بأوامر التغيير المقبولة) −
 * صافي ما فُوتر. لا رقمَ محفوظًا بيدٍ يُطرح منه شيء.
 *
 * ═══ وبالإجماليّ كما تُقاس القيمة ═══
 *
 * قيمةُ المشروع إجماليّةٌ بضريبتها (كالعرض المقبول)، وصافي المفوتَر
 * إجماليٌّ كذلك. فمبلغُ المرحلة إجماليٌّ يدفعه العميل، والبندُ يُكتب صافيًا
 * بحيث تردّ ضريبتُه الإجماليَّ نفسَه — ولا يتجاوزه بفلس.
 */
final class ProjectBilling
{
    public const DEPOSIT = 'project_deposit';

    public const FINAL = 'project_final';

    /** @var list<string> */
    public const ROLES = [self::DEPOSIT, self::FINAL];

    /** رسمُ إلغاء المشروع — ذمّةٌ تنشأ بإلغائه وفق سياسته، لا ثمنُ عملٍ من نطاقه */
    public const CANCELLATION_FEE = 'project_cancellation_fee';

    public const PERCENT = 'percent';

    public const FIXED = 'fixed';

    private const EPSILON = 0.0005;

    /* ─────────────────────────── الموقف ─────────────────────────── */

    /**
     * أين يقف المشروع من فوترته — من `ProjectFinancials` لا من عمودٍ محفوظ.
     *
     * - `unbilled`: ما لم يُفوتَر بعد من القيمة الحاليّة (صافيًا من إشعارات الدائن).
     * - `billable`: ما يجوز أن يُفوتَر الآن — ناقصًا ما في مسودّاتٍ لم تصدر.
     *
     * @param  array<string, mixed>|null  $summary  ملخّصُ `ProjectFinancials` إن حُسب قبلُ
     * @return array<string, mixed>
     */
    public static function position(Project $project, ?array $summary = null): array
    {
        $summary ??= ProjectFinancials::summary($project);
        $value = round((float) $summary['current_value'], 3);
        $net = round((float) $summary['net_invoiced'], 3);
        $drafts = round((float) CustomerInvoice::where('business_id', $project->business_id)
            ->where('project_id', $project->id)->where('status', CustomerInvoice::DRAFT)->sum('total'), 3);
        $unbilled = round(max(0.0, $value - $net), 3);

        return [
            'value' => $value,
            'invoiced' => round((float) $summary['invoiced'], 3),
            'credited' => round((float) $summary['credited'], 3),
            'net_invoiced' => $net,
            'drafts' => $drafts,
            'unbilled' => $unbilled,
            'billable' => round(max(0.0, $value - $net - $drafts), 3),
            'collected' => round((float) $summary['collected'], 3),
            'outstanding' => round((float) $summary['customer_outstanding'], 3),
            'fully_billed' => $unbilled <= self::EPSILON,
            'over_billed' => (bool) $summary['invoiced_above_approved'],
            'rate' => self::rate($project),
        ];
    }

    /**
     * نسبةُ الضريبة على فاتورة المرحلة — نسبةُ بنود النطاق إن اتّحدت.
     *
     * بنودُ المشروع تحمل نسبتَها من العرض، فبها تُفوتَر الدفعةُ ليردّ
     * مجموعُها قيمةَ المشروع نفسَها. ونطاقٌ بنسبٍ مختلطة (بندٌ معفًى) يُفوتَر
     * بنسبة الشركة — والفرقُ فرقُ توزيعٍ بين صافٍ وضريبة لا في الإجماليّ.
     */
    public static function rate(Project $project): float
    {
        $rates = ProjectItem::where('project_id', $project->id)->where('quantity', '>', 0)
            ->pluck('tax_rate')->map(fn ($r) => number_format((float) $r, 2, '.', ''))->unique()->values();

        return $rates->count() === 1 ? (float) $rates->first() : LineTotals::rate((int) $project->business_id);
    }

    /**
     * سعرُ البند الصافي الذي تردّ ضريبتُه الإجماليَّ المطلوب — ولا تتجاوزه.
     *
     * والتقريبُ بثلاث منازل قد يجعل `صافٍ + ضريبة` يزيد فلسًا على الإجماليّ؛
     * فيُنقص الصافي حتى لا يزيد، ثمّ يُزاد ما دام لا يتجاوز.
     */
    public static function netFor(float $gross, float $rate): float
    {
        $gross = round($gross, 3);

        if ($rate <= 0) {
            return $gross;
        }

        $total = fn (float $net) => round($net + round($net * $rate / 100, 3), 3);
        $net = round($gross / (1 + $rate / 100), 3);

        for ($i = 0; $i < 5 && $total($net) > $gross + self::EPSILON; $i++) {
            $net = round($net - 0.001, 3);
        }
        for ($i = 0; $i < 5 && $total(round($net + 0.001, 3)) <= $gross + self::EPSILON; $i++) {
            $net = round($net + 0.001, 3);
        }

        return $net;
    }

    /* ─────────────────────────── الدفعة المقدّمة ─────────────────────────── */

    /**
     * حسابُ الدفعة قبل إنشائها — نسبةٌ من القيمة الحاليّة أو مبلغٌ ثابت.
     *
     * والحسابُ هنا وحدَه: الشاشةُ تعرض ما يردّه، والإنشاءُ يعيد السؤالَ نفسَه
     * ولا يقرأ رقمًا أرسله المتصفّح.
     *
     * @param  array<string, mixed>|null  $position
     * @return array<string, mixed>
     */
    public static function quoteDeposit(Project $project, string $method, mixed $input, ?array $position = null): array
    {
        $position ??= self::position($project);
        $value = (float) $position['value'];

        if ($method === self::PERCENT) {
            $percent = round((float) $input, 2);
            if ($percent <= 0 || $percent > 100) {
                throw new RuntimeException(__('نسبة الدفعة بين 0 و100.'));
            }
            $gross = round($value * $percent / 100, 3);
        } elseif ($method === self::FIXED) {
            $percent = null;
            $gross = round((float) $input, 3);
        } else {
            throw new RuntimeException(__('طريقة حسابٍ غير معروفة — نسبةٌ أو مبلغ.'));
        }

        if ($gross <= self::EPSILON) {
            throw new RuntimeException(__('مبلغ الدفعة يجب أن يكون أكبر من صفر.'));
        }

        if ($gross > (float) $position['billable'] + self::EPSILON) {
            throw new RuntimeException(__('مبلغ الدفعة :amount أكبر ممّا يجوز فوترتُه على المشروع الآن (:left).', [
                'amount' => number_format($gross, 3), 'left' => number_format((float) $position['billable'], 3),
            ]));
        }

        $rate = (float) $position['rate'];
        $net = self::netFor($gross, $rate);
        $tax = round($net * $rate / 100, 3);
        $total = round($net + $tax, 3);

        return [
            'method' => $method,
            'percent' => $percent,
            'share' => $value > 0 ? round($total / $value * 100, 2) : null,
            'amount' => $gross,
            'net' => $net,
            'tax' => $tax,
            'total' => $total,
            'rate' => $rate,
            'value' => $value,
            'billable_before' => (float) $position['billable'],
            'remaining_after' => round(max(0.0, (float) $position['unbilled'] - (float) $position['drafts'] - $total), 3),
        ];
    }

    /**
     * فاتورةُ الدفعة المقدّمة — فاتورةُ عميلٍ حقيقيّة على المشروع.
     *
     * ═══ بعد توقيع النطاق ═══
     *
     * الدفعةُ تُطلب على عملٍ اتُّفق على حدوده — فلا تُنشأ قبل أن يوقّع العميلُ
     * نطاقَه (`ScopesOfWork::isSigned`).
     *
     * ═══ ولا دفعتان بالخطأ ═══
     *
     * تحت قفل صفّ المشروع: دفعةٌ قائمةٌ (مسودّةٌ أو صادرة) تردّ الثانيةَ ما
     * لم تُطلب «دفعةٌ إضافيّة» صراحةً — مشروعٌ على دفعتين قرارٌ لا ضغطةٌ
     * مكرَّرة. ومفتاحُ الطلب (`request_key`) يجعل الطلبَ المعاد يجد فاتورتَه
     * القائمة ولا يكتب أخرى.
     *
     * @param  array<string, mixed>  $input  `method` · `percent` · `amount` · `issue` · `additional` · `request_key` · `due_at`
     */
    public static function createDeposit(Project $project, array $input, User $actor): CustomerInvoice
    {
        $method = (string) ($input['method'] ?? '');
        $raw = $method === self::PERCENT ? ($input['percent'] ?? null) : ($input['amount'] ?? null);
        $issue = filter_var($input['issue'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $additional = filter_var($input['additional'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $key = self::key($input['request_key'] ?? null);

        self::assertMayIssue($issue, $actor);

        return DB::transaction(function () use ($project, $method, $raw, $issue, $additional, $key, $input, $actor) {
            $locked = Project::where('business_id', $project->business_id)->lockForUpdate()->findOrFail($project->id);

            if ($key !== null && ($existing = self::byKey($locked, $key)) !== null) {
                return $existing;
            }

            self::assertOpen($locked);

            if (! ScopesOfWork::isSigned($locked)) {
                throw new RuntimeException(__('لم يوقّع العميل نطاق العمل بعد — الدفعة المقدّمة بعد اعتماده.'));
            }

            $customer = $locked->customer ?? throw new RuntimeException(__('لا عميل للمشروع — لا تُصدَر فاتورة.'));

            $live = self::invoices($locked, self::DEPOSIT)->where('status', '<>', CustomerInvoice::CANCELLED);

            if ($live->isNotEmpty() && ! $additional) {
                throw new RuntimeException(__('على هذا المشروع دفعةٌ مقدّمة (:number) — إن كانت هذه دفعةً إضافيّة فاخترها صراحةً.', [
                    'number' => $live->first()->number ?: __('مسودة #:id', ['id' => $live->first()->id]),
                ]));
            }

            $quote = self::quoteDeposit($locked, $method, $raw);
            $label = $live->isNotEmpty() ? __('دفعة مقدّمة إضافيّة') : __('دفعة مقدّمة');
            $description = $method === self::PERCENT
                ? __(':label — :name (:percent٪ من قيمة المشروع :code)', [
                    'label' => $label, 'name' => $locked->name, 'code' => $locked->code,
                    'percent' => rtrim(rtrim(number_format((float) $quote['percent'], 2, '.', ''), '0'), '.'),
                ])
                : __(':label — :name (المشروع :code)', ['label' => $label, 'name' => $locked->name, 'code' => $locked->code]);

            $invoice = CustomerInvoices::create((int) $locked->business_id, $customer, [
                'project_id' => $locked->id,
                'issued_at' => now()->toDateString(),
                'due_at' => Projects::date($input['due_at'] ?? null),
                'attention_to' => $locked->contact_person ?: null,
                'notes' => __('دفعة مقدّمة على المشروع :code — قيمته الحالية :value.', [
                    'code' => $locked->code, 'value' => number_format((float) $quote['value'], 3),
                ]),
            ], [[
                'description' => $description,
                'quantity' => 1,
                'unit_price' => $quote['net'],
                'tax_rate' => $quote['rate'],
            ]], $actor->id);

            $invoice->forceFill([
                'billing_role' => self::DEPOSIT,
                'billing_basis' => $quote + [
                    'request_key' => $key,
                    'additional' => $live->isNotEmpty(),
                    'scope' => ScopesOfWork::signed($locked)?->number,
                ],
            ])->save();

            if ($issue) {
                $invoice = CustomerInvoices::issue($invoice->fresh(), $actor->id);
            }

            Activity::log('created', ($issue ? 'أصدر ' : 'أنشأ مسودّة ').'فاتورة الدفعة المقدّمة '.($invoice->number ?: '#'.$invoice->id)
                .' للمشروع '.$locked->code.' بمبلغ '.number_format((float) $quote['total'], 3)
                .($method === self::PERCENT ? ' ('.$quote['percent'].'٪)' : ' (مبلغ ثابت)'), [
                    'subject_id' => $locked->id, 'subject_type' => 'project', 'icon' => 'receipt', 'color' => 'success',
                ]);

            return $invoice->fresh();
        });
    }

    /**
     * «بلا دفعة مقدّمة» — قرارٌ باسم صاحبه وسببه، لا فراغٌ يُفسَّر.
     */
    public static function waiveDeposit(Project $project, string $reason, User $actor): Project
    {
        $reason = Projects::text($reason, 500);

        if ($reason === null) {
            throw new RuntimeException(__('اكتب سبب الاستغناء عن الدفعة المقدّمة.'));
        }

        return DB::transaction(function () use ($project, $reason, $actor) {
            $locked = Project::where('business_id', $project->business_id)->lockForUpdate()->findOrFail($project->id);

            self::assertOpen($locked);

            if ($locked->deposit_waived_at !== null) {
                return $locked;
            }

            if (self::invoices($locked, self::DEPOSIT)->where('status', CustomerInvoice::ISSUED)->isNotEmpty()) {
                throw new RuntimeException(__('على هذا المشروع دفعةٌ مقدّمة صادرة — لا يُستغنى عنها.'));
            }

            $locked->update([
                'deposit_waived_at' => now(),
                'deposit_waived_by' => $actor->id,
                'deposit_waiver_reason' => $reason,
            ]);

            Activity::log('status', 'قرّر أن المشروع '.$locked->code.' بلا دفعة مقدّمة — '.$reason, [
                'subject_id' => $locked->id, 'subject_type' => 'project', 'icon' => 'shield-check', 'color' => 'warning',
            ]);

            return $locked->fresh();
        });
    }

    /* ─────────────────────────── الفاتورة النهائيّة ─────────────────────────── */

    /**
     * ما تكونه الفاتورةُ النهائيّة الآن — من الحقيقة الماليّة لا من رقمٍ محفوظ.
     *
     * القيمةُ الحاليّة − صافي ما فُوتر (بعد إشعارات الدائن) = المبلغ.
     *
     * ═══ والمبلغُ يُبلغ بعينه ═══
     *
     * ضريبةُ البند تُقرَّب إلى ثلاث منازل، فبعضُ الإجماليّات لا يردّها صافٍ
     * واحد: يقف `netFor` دونها بفلس (نحو واحدٍ من كلّ عشرين مبلغًا بضريبة ٥٪).
     * وفلسٌ باقٍ يترك المشروعَ «لم تُفوتَر قيمتُه كلُّها» فلا يُقفل، ولا علاجَ
     * له إلّا فاتورةٌ بفلس. فيُكتب الفرقُ بندًا ثانيًا في الفاتورة نفسِها
     * (`rounding`) بنسبتها — وضريبةُ فلسٍ تُقرَّب صفرًا — فيساوي إجماليُّها
     * الباقي بعينه، ولا يتجاوزه.
     *
     * @param  array<string, mixed>|null  $position
     * @return array<string, mixed>
     */
    public static function quoteFinal(Project $project, ?array $position = null): array
    {
        $position ??= self::position($project);
        $amount = (float) $position['unbilled'];
        $rate = (float) $position['rate'];
        $net = self::netFor($amount, $rate);
        $tax = round($net * $rate / 100, 3);

        $rounding = round($amount - round($net + $tax, 3), 3);
        if ($rounding <= self::EPSILON || round($rounding * $rate / 100, 3) > 0) {
            $rounding = 0.0;
        }

        return [
            'value' => (float) $position['value'],
            'invoiced' => (float) $position['invoiced'],
            'credited' => (float) $position['credited'],
            'net_invoiced' => (float) $position['net_invoiced'],
            'amount' => $amount,
            'net' => $net,
            'tax' => $tax,
            'rounding' => $rounding,
            'total' => round($net + $tax + $rounding, 3),
            'rate' => $rate,
            'drafts' => (float) $position['drafts'],
        ];
    }

    /**
     * الفاتورةُ النهائيّة — ما بقي من القيمة الحاليّة، بعد قبول التسليم.
     *
     * ولا تُفوتِر فوق القيمة: المبلغُ هو الباقي بعينه، ومشروعٌ فُوترت قيمتُه
     * كلُّها لا فاتورةَ نهائيّةً له. ومسودّةٌ قائمةٌ على المشروع تردّها:
     * مبلغٌ معلَّقٌ لا يُعرف أيُصدَر أم يُحذف يجعل «الباقي» تخمينًا.
     *
     * @param  array<string, mixed>  $input  `issue` · `request_key` · `due_at`
     */
    public static function createFinal(Project $project, array $input, User $actor): CustomerInvoice
    {
        $issue = filter_var($input['issue'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $key = self::key($input['request_key'] ?? null);

        self::assertMayIssue($issue, $actor);

        return DB::transaction(function () use ($project, $issue, $key, $input, $actor) {
            $locked = Project::where('business_id', $project->business_id)->lockForUpdate()->findOrFail($project->id);

            if ($key !== null && ($existing = self::byKey($locked, $key)) !== null) {
                return $existing;
            }

            self::assertOpen($locked);

            if (! ScopesOfWork::isSigned($locked)) {
                throw new RuntimeException(__('لم يوقّع العميل نطاق العمل بعد — لا فاتورة نهائية قبل اعتماده.'));
            }

            $delivery = DeliveryReadiness::forProject($locked);
            if (! $delivery->ready) {
                throw new RuntimeException(__('الفاتورة النهائية بعد قبول التسليم — :why', ['why' => implode(' · ', $delivery->blockers)]));
            }

            if ($locked->invoices()->where('status', CustomerInvoice::DRAFT)->exists()) {
                throw new RuntimeException(__('على هذا المشروع فاتورةٌ مسودّة — أصدرها أو احذفها قبل الفاتورة النهائية.'));
            }

            $customer = $locked->customer ?? throw new RuntimeException(__('لا عميل للمشروع — لا تُصدَر فاتورة.'));
            $quote = self::quoteFinal($locked);

            if ($quote['amount'] <= self::EPSILON) {
                throw new RuntimeException(__('فُوترت قيمة المشروع كلّها — لا شيء باقٍ لفاتورةٍ نهائية.'));
            }

            $previous = self::invoices($locked, self::FINAL)->where('status', CustomerInvoice::ISSUED);
            $label = $previous->isNotEmpty() ? __('فاتورة نهائية تكميلية') : __('الفاتورة النهائية');

            $lines = [[
                'description' => __(':label — :name (المشروع :code)', ['label' => $label, 'name' => $locked->name, 'code' => $locked->code]),
                'quantity' => 1,
                'unit_price' => $quote['net'],
                'tax_rate' => $quote['rate'],
            ]];

            /* وفلسُ التقريب بندٌ في الورقة نفسِها لا فاتورةٌ بفلس — انظر `quoteFinal` */
            if ($quote['rounding'] > self::EPSILON) {
                $lines[] = [
                    'description' => __('فرق تقريب الضريبة'),
                    'quantity' => 1,
                    'unit_price' => $quote['rounding'],
                    'tax_rate' => $quote['rate'],
                ];
            }

            $invoice = CustomerInvoices::create((int) $locked->business_id, $customer, [
                'project_id' => $locked->id,
                'issued_at' => now()->toDateString(),
                'due_at' => Projects::date($input['due_at'] ?? null),
                'attention_to' => $locked->contact_person ?: null,
                'notes' => __('القيمة الحالية :value − صافي ما فُوتر :billed = :amount', [
                    'value' => number_format($quote['value'], 3),
                    'billed' => number_format($quote['net_invoiced'], 3),
                    'amount' => number_format($quote['amount'], 3),
                ]),
            ], $lines, $actor->id);

            $invoice->forceFill([
                'billing_role' => self::FINAL,
                'billing_basis' => $quote + [
                    'request_key' => $key,
                    'supplementary' => $previous->isNotEmpty(),
                    'deposits' => self::invoices($locked, self::DEPOSIT)->where('status', CustomerInvoice::ISSUED)
                        ->pluck('number')->filter()->values()->all(),
                ],
            ])->save();

            if ($issue) {
                $invoice = CustomerInvoices::issue($invoice->fresh(), $actor->id);
            }

            Activity::log('created', ($issue ? 'أصدر ' : 'أنشأ مسودّة ').'الفاتورة النهائية '.($invoice->number ?: '#'.$invoice->id)
                .' للمشروع '.$locked->code.' بمبلغ '.number_format($quote['total'], 3)
                .' (القيمة '.number_format($quote['value'], 3).' − المفوتَر '.number_format($quote['net_invoiced'], 3).')', [
                    'subject_id' => $locked->id, 'subject_type' => 'project', 'icon' => 'receipt', 'color' => 'success',
                ]);

            return $invoice->fresh();
        });
    }

    /* ─────────────────────────── سقفُ القيمة ─────────────────────────── */

    /**
     * أتُقاس هذه الفاتورةُ بقيمة مشروعها؟
     *
     * كلُّ فاتورةٍ على مشروعٍ تُقاس — من مساره أو من شاشة الفواتير العامّة —
     * إلّا ما ليس فوترةً لنطاقه، وهما بابان مقصودان:
     * • رسمُ الإلغاء: ذمّةٌ يُنشئها إلغاءُ المشروع وفق سياسته (`Projects::cancel`).
     * • المستورَدُ من التاريخ والرصيدُ الافتتاحيّ: وقع قبل هذا الدفتر، فيُكتب كما وقع.
     */
    public static function capped(CustomerInvoice $invoice): bool
    {
        return $invoice->project_id !== null
            && $invoice->billing_role !== self::CANCELLATION_FEE
            && ! $invoice->is_opening_balance
            && $invoice->origin !== Provenance::HISTORICAL;
    }

    /**
     * ما بقي يجوز أن يُفوتَر على المشروع — القيمةُ الحاليّة ناقصًا صافي الصادر.
     *
     * و`$withDrafts` يُنقص المسودّاتِ القائمةَ أيضًا (سوى `$except`): عند كتابة
     * ورقةٍ جديدة، المسودّةُ المعلَّقةُ مالٌ موعودٌ من القيمة نفسِها. وعند
     * الإصدار يُقاس بالصادر وحدَه — المسودّةُ لم تصر ذمّةً بعد، وقد تُحذف.
     */
    public static function headroom(Project $project, ?int $except = null, bool $withDrafts = false): float
    {
        $summary = ProjectFinancials::summary($project);
        $left = (float) $summary['current_value'] - (float) $summary['net_invoiced'];

        if ($withDrafts) {
            $left -= (float) CustomerInvoice::where('business_id', $project->business_id)
                ->where('project_id', $project->id)->where('status', CustomerInvoice::DRAFT)
                ->when($except !== null, fn ($q) => $q->whereKeyNot($except))->sum('total');
        }

        return round($left, 3);
    }

    /**
     * لا تُفوتَر على المشروع فوق قيمته الحاليّة — من أيّ باب.
     *
     * والقيمةُ تزيد بأمر تغييرٍ يقبله العميل (`ProjectFinancials`: المعتمَدُ +
     * أوامرُ التغيير المقبولة). ذاك هو البابُ المقصود لفوترة عملٍ زاد، لا ورقةٌ
     * تُكتب فوق المعتمَد ثمّ يُحذَّر منها. وإشعارُ الدائن يُعيد ما نقصه.
     */
    public static function assertWithinValue(Project $project, float $total, ?int $except = null, bool $withDrafts = false): void
    {
        $left = self::headroom($project, $except, $withDrafts);

        if ($total > $left + self::EPSILON) {
            throw new RuntimeException(__('هذه الفاتورة (:total) تتجاوز ما بقي من قيمة المشروع :code (:left) — قيمة المشروع تزيد بأمر تغيير يقبله العميل، لا بفاتورة فوقها.', [
                'total' => number_format($total, 3),
                'code' => $project->code,
                'left' => number_format(max(0.0, $left), 3),
            ]));
        }
    }

    /* ─────────────────────────── القراءة ─────────────────────────── */

    /**
     * فواتيرُ المشروع بدورٍ واحد — بترتيبها.
     *
     * @return Collection<int, CustomerInvoice>
     */
    public static function invoices(Project $project, string $role): Collection
    {
        return CustomerInvoice::where('business_id', $project->business_id)
            ->where('project_id', $project->id)->where('billing_role', $role)->orderBy('id')->get();
    }

    /**
     * حالُ مرحلة الدفعة: تمّت بدفعةٍ صادرة، أو بقرار «بلا دفعة»، أو بفوترة القيمة كلِّها.
     *
     * والتحصيلُ يُقال منفصلًا — المرحلةُ تتمّ بالإصدار لا بالسداد.
     *
     * @param  array<string, mixed>|null  $position
     * @return array<string, mixed>
     */
    public static function depositState(Project $project, ?array $position = null, ?Collection $deposits = null): array
    {
        $deposits ??= self::invoices($project, self::DEPOSIT);
        $live = $deposits->where('status', '<>', CustomerInvoice::CANCELLED);
        $issued = $live->where('status', CustomerInvoice::ISSUED);
        $fullyBilled = $position !== null ? (bool) $position['fully_billed'] : false;

        return [
            'done' => $issued->isNotEmpty() || $project->deposit_waived_at !== null || ($fullyBilled && (float) ($position['value'] ?? 0) > 0),
            'issued' => $issued->count(),
            'drafts' => $live->where('status', CustomerInvoice::DRAFT)->count(),
            'waived' => $project->deposit_waived_at !== null,
            'paid' => $issued->isNotEmpty() && $issued->every(fn (CustomerInvoice $i) => $i->outstanding() <= self::EPSILON),
        ];
    }

    /**
     * فاتورةٌ للشاشة — رقمُها وحالُها وتحصيلُها، والمالُ لمن يملكه.
     *
     * @return array<string, mixed>
     */
    public static function row(CustomerInvoice $i, bool $commercial): array
    {
        return [
            'id' => $i->id,
            'number' => $i->number,
            'status' => $i->status,
            'role' => $i->billing_role,
            'payment_state' => $i->paymentState(),
            'issued_at' => optional($i->issued_at)->format('Y-m-d'),
            'due_at' => optional($i->due_at)->format('Y-m-d'),
            'total' => $commercial ? (float) $i->total : null,
            'outstanding' => $commercial ? $i->outstanding() : null,
            'percent' => $commercial ? ($i->billing_basis['percent'] ?? null) : null,
            'method' => $i->billing_basis['method'] ?? null,
        ];
    }

    /* ─────────────────────────── أدوات ─────────────────────────── */

    private static function assertOpen(Project $project): void
    {
        if (! $project->isOpen()) {
            throw new RuntimeException(__('هذا المشروع مُقفَل أو ملغى — لا فاتورة جديدة عليه من مساره.'));
        }
    }

    private static function assertMayIssue(bool $issue, User $actor): void
    {
        if ($issue && ! $actor->may(Permissions::CUSTOMER_INVOICE_ISSUE)) {
            throw new RuntimeException(__('إصدار الفاتورة يحتاج صلاحية «إصدار فاتورة عميل» — احفظها مسودّةً ليصدرها من يملكها.'));
        }
    }

    /** مفتاحُ الطلب — حروفٌ وأرقامٌ وشَرطات، أو لا مفتاح */
    private static function key(mixed $raw): ?string
    {
        $key = trim((string) ($raw ?? ''));

        return preg_match('/^[A-Za-z0-9\-]{8,64}$/', $key) === 1 ? $key : null;
    }

    private static function byKey(Project $project, string $key): ?CustomerInvoice
    {
        return CustomerInvoice::where('business_id', $project->business_id)
            ->where('project_id', $project->id)->whereIn('billing_role', self::ROLES)
            ->where('billing_basis->request_key', $key)->first();
    }
}
