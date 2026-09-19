<?php

namespace App\Support;

use App\Models\AutomationRun;
use App\Models\ChangeOrder;
use App\Models\Communication;
use App\Models\CustomerInvoice;
use App\Models\DeliveryPackage;
use App\Models\Expense;
use App\Models\PolicyException;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectTask;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Automation\Engine;
use App\Support\Automation\Settings as AutomationSettings;
use App\Support\Communication\Subjects;
use App\Support\Communication\Templates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * مركزُ العمليات — ما يفتحه المديرُ كلَّ صباحٍ ليعرف ما يفعل.
 *
 * ═══ فعلٌ لا رسم ═══
 *
 * ليست لوحةَ أرقام: كلُّ صفٍّ هنا شيءٌ ينتظر يدًا — قرارٌ لم يُتّخذ، موعدٌ
 * فات، حزمةٌ لم تُرسَل، رسالةٌ لم تصل، مالٌ لم يُحصَّل أو لم يُسدَّد. ويقول
 * الصفُّ ما يُفعل به («اعتمد»، «ذكّر العميل»، «أقفل المشروع») ويقود إلى
 * شاشته. وما لا يحتاج يدًا لا يُعرض.
 *
 * ═══ طبقةُ قراءةٍ لا حساب ═══
 *
 * لا يكتب شيئًا، ولا يُعيد حسابَ ما تحسبه الخدماتُ: الجاهزيّةُ من
 * `DeliveryReadiness`، والمالُ من `ProjectFinancials`، والانحرافُ من
 * `ProjectScope::verify`، وما يُرسَل عنه من `Communication\Subjects`. وكلُّ
 * استعلامٍ يبدأ بـ`business_id`.
 *
 * ═══ والمالُ لمن يملك رؤيته ═══
 *
 * المبالغُ تُشكَّل هنا لا تُخفى في الشاشة: من لا يملك «المالية» ولا
 * `project.budget.view` يصله `null` في كلّ مبلغ، ولا يصله قسمُ المال أصلًا.
 */
final class CommandCenter
{
    /** أيّامُ «القادم»: ما يبدأ خلالها يُعرض بجاهزيّته */
    public const UPCOMING_DAYS = 14;

    /** أفقُ المستحقّات القريبة — ما يُسدَّد أو يُحصَّل خلالها */
    public const HORIZON_DAYS = 7;

    /** بعدها يُعدّ ما ينتظر العميلَ راكدًا */
    public const STALE_DAYS = 7;

    /** أكثرُ ما يُعرض في قسمٍ واحد — الشاشةُ تقول «وأكثر» لا تطول */
    public const LIMIT = 25;

    /** @return array<string, mixed> */
    public static function for(int $businessId, ?User $viewer): array
    {
        $money = self::seesMoney($viewer);

        $decisions = self::decisions($businessId, $money);
        $late = self::late($businessId, $money);
        $delivery = self::delivery($businessId);
        $communications = self::communications($businessId);
        $upcoming = self::upcoming($businessId);
        $blockers = self::blockers($businessId, $money);
        $team = self::team($businessId);
        $automation = self::automation($businessId);

        return [
            'generated_at' => now()->format('Y-m-d H:i'),
            'sees_money' => $money,
            'summary' => [
                'decisions' => $decisions['total'],
                'late' => $late['total'],
                'delivery' => $delivery['total'],
                'communications' => $communications['total'],
                'upcoming' => $upcoming['soon'],
                'blockers' => $blockers['total'],
                'automation' => $automation['total'],
            ],
            'decisions' => $decisions,
            'late' => $late,
            'delivery' => $delivery,
            'communications' => $communications,
            'upcoming' => $upcoming,
            'blockers' => $blockers,
            'team' => $team,
            'automation' => $automation,
            'money' => $money ? self::money($businessId) : null,
        ];
    }

    /* ─────────────────────────── الأتمتة ─────────────────────────── */

    /**
     * ما فعلته الأتمتةُ ولم يتمّ — أو ما لم تقدر عليه فيحتاج يدًا. المرحلة 8.
     *
     * تكاملٌ خفيف: الإيقافُ العامّ، وما فشل خلال أسبوع، وما تُخطّي لسببٍ
     * يُصلحه إنسان (لا مُرسَلَ إليه، لا قالب، قناةٌ غير موصولة). ولا يُعاد
     * بناءُ سجلّ الأتمتة هنا — الصفُّ يقود إليه.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, paused: bool, mode: string, configured: bool}
     */
    public static function automation(int $bid): array
    {
        $settings = AutomationSettings::for($bid);
        $rows = collect();
        $since = now()->subDays(7);

        $failed = AutomationRun::where('business_id', $bid)->where('status', AutomationRun::FAILED)
            ->where('created_at', '>=', $since)->with('rule')->orderByDesc('id')->limit(self::LIMIT)->get();
        foreach ($failed as $r) {
            $rows->push(self::row('automation_failed', 'danger',
                __('فشلت الأتمتة «:rule» — :target', ['rule' => (string) $r->rule?->name, 'target' => (string) $r->target_label]),
                $r->failure, __('راجع السبب وأعد بيد إن لزم'), route('admin.automation.history', ['status' => AutomationRun::FAILED]), $r->executed_at ?? $r->created_at));
        }

        $attention = AutomationRun::where('business_id', $bid)->where('status', AutomationRun::SKIPPED)
            ->whereIn('skipped_reason', [Engine::NO_RECIPIENT, Engine::NO_TEMPLATE, Engine::NOT_CONFIGURED])
            ->where('created_at', '>=', $since)->with('rule')->orderByDesc('id')->limit(self::LIMIT)->get();
        foreach ($attention as $r) {
            $rows->push(self::row('automation_attention', 'warning',
                __('لم تستطع الأتمتة «:rule» — :target', ['rule' => (string) $r->rule?->name, 'target' => (string) $r->target_label]),
                __(Engine::REASONS[$r->skipped_reason] ?? $r->skipped_reason),
                match ($r->skipped_reason) {
                    Engine::NO_RECIPIENT => __('أكمل بيانات الاتصال'),
                    Engine::NO_TEMPLATE => __('اضبط القالب في المراسلات'),
                    default => __('اضبط القناة على الخادم'),
                },
                route('admin.automation.history', ['status' => AutomationRun::SKIPPED]), $r->executed_at ?? $r->created_at));
        }

        /* والتكرارُ: القاعدةُ نفسُها تفشل ثلاثًا فأكثر في أسبوع — تُقال مرّةً */
        $repeated = AutomationRun::where('business_id', $bid)->where('status', AutomationRun::FAILED)->where('created_at', '>=', $since)
            ->selectRaw('automation_rule_id, COUNT(*) AS n')->groupBy('automation_rule_id')->having('n', '>=', 3)->with('rule')->get();
        foreach ($repeated as $r) {
            $rows->push(self::row('automation_repeated', 'danger',
                __('الأتمتة «:rule» تفشل تكرارًا (:n خلال أسبوع)', ['rule' => (string) $r->rule?->name, 'n' => (int) $r->n]),
                null, __('أوقف القاعدة حتى يُصلح السبب'), route('admin.automation.index'), now()));
        }

        return self::section($rows->sortByDesc('at')) + [
            'paused' => $settings->paused,
            'mode' => $settings->mode,
            'configured' => Engine::configured($bid),
            'pending' => (int) AutomationRun::where('business_id', $bid)->where('status', AutomationRun::PENDING)->count(),
        ];
    }

    /** هل يرى هذا المستخدمُ المبالغَ؟ — «المالية» أو ميزانيّةُ المشروع */
    public static function seesMoney(?User $viewer): bool
    {
        return $viewer !== null
            && ($viewer->allows('finance') || $viewer->may(Permissions::PROJECT_BUDGET_VIEW));
    }

    /* ─────────────────────────── يحتاج قرارًا ─────────────────────────── */

    /**
     * ما ينتظر توقيعَ أحد: خصومٌ واستثناءاتٌ وسنداتٌ — وعروضٌ فات وقتُها.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public static function decisions(int $bid, bool $money): array
    {
        $rows = collect();

        foreach (Quotation::where('business_id', $bid)->awaitingApproval()->with('customer')->orderBy('updated_at')->limit(self::LIMIT)->get() as $q) {
            $rows->push(self::row('quotation_approval', 'warning',
                __('عرض سعر :label — :customer', ['label' => Quotations::label($q), 'customer' => (string) ($q->customer?->name ?? $q->customer_name)]),
                (string) $q->title, __('اعتمد الخصم أو ارفضه'), route('admin.quotations.show', $q->id),
                $q->updated_at, $money ? (float) $q->total : null));
        }

        foreach (ChangeOrder::where('business_id', $bid)->where('status', ChangeOrder::PENDING_APPROVAL)->with('project')->orderBy('updated_at')->limit(self::LIMIT)->get() as $co) {
            $rows->push(self::row('change_order_approval', 'warning',
                __('أمر التغيير :label — :project', ['label' => ChangeOrders::label($co), 'project' => (string) $co->project?->name]),
                (string) $co->title, __('اعتمد أمر التغيير أو ارفضه'), route('admin.changeOrders.show', $co->id),
                $co->updated_at, $money ? (float) $co->total_delta : null));
        }

        foreach (PolicyException::where('business_id', $bid)->pending()->with('exceptionable', 'requester')->orderBy('created_at')->limit(self::LIMIT)->get() as $x) {
            $subject = $x->exceptionable;
            $rows->push(self::row('policy_exception', 'danger',
                __('استثناء من سياسة «:policy» — :subject', ['policy' => $x->policy_key, 'subject' => (string) (Subjects::label($subject) ?? '')]),
                trim((string) $x->reason).($x->requester ? ' — '.$x->requester->name : ''),
                __('اعتمد الاستثناء أو ارفضه'), Subjects::adminRoute($subject) ?? route('admin.settings.policies.index'),
                $x->created_at, $money && $x->surcharge_amount !== null ? (float) $x->surcharge_amount : null));
        }

        foreach (SupplierInvoice::where('business_id', $bid)->where('approval_status', SupplierInvoices::PENDING)->with('supplier')->orderBy('created_at')->limit(self::LIMIT)->get() as $si) {
            $rows->push(self::row('supplier_invoice_approval', 'warning',
                __('سند مورّد :number — :supplier', ['number' => (string) $si->supplier_ref, 'supplier' => (string) ($si->supplier?->name ?? '')]),
                $si->project_id ? __('على مشروع') : null, __('اعتمد السند أو ارفضه'), route('admin.purchases.invoices.show', $si->id),
                $si->created_at, $money ? (float) $si->total : null));
        }

        foreach (Quotation::where('business_id', $bid)->expired()->with('customer')->orderBy('valid_until')->limit(self::LIMIT)->get() as $q) {
            $rows->push(self::row('quotation_expired', 'warning',
                __('عرض سعر :label — :customer', ['label' => Quotations::label($q), 'customer' => (string) ($q->customer?->name ?? $q->customer_name)]),
                __('انتهت صلاحيّته في :date بلا قرار', ['date' => $q->valid_until?->format('Y-m-d')]),
                __('نقّح العرض أو أغلقه'), route('admin.quotations.show', $q->id),
                $q->valid_until, $money ? (float) $q->total : null));
        }

        return self::section($rows->sortBy('at'));
    }

    /* ─────────────────────────── متأخّر ─────────────────────────── */

    /**
     * ما فات موعدُه: مهامٌّ وفواتيرُ وسنداتٌ ومصاريفُ وأوامرُ شراءٍ ومشاريعُ لم تُقفل.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public static function late(int $bid, bool $money): array
    {
        $today = now()->toDateString();
        $rows = collect();

        $tasks = ProjectTask::where('status', ProjectTask::OPEN)->whereDate('due_at', '<', $today)
            ->whereHas('project', fn ($p) => $p->where('business_id', $bid)->open())
            ->with('project', 'assignee')->orderBy('due_at')->limit(self::LIMIT)->get();
        foreach ($tasks as $task) {
            $rows->push(self::row('task', 'danger', (string) $task->title,
                __('المشروع :code — :name', ['code' => (string) $task->project?->code, 'name' => (string) $task->project?->name])
                .($task->assignee ? ' · '.$task->assignee->name : ' · '.__('بلا مسؤول')),
                __('أنجز المهمّة أو أعد جدولتها'), route('admin.projects.show', $task->project_id), $task->due_at));
        }

        foreach (CustomerInvoice::where('business_id', $bid)->overdue()->with('customer')->orderBy('due_at')->limit(self::LIMIT)->get() as $inv) {
            $rows->push(self::row('customer_invoice', 'danger',
                __('فاتورة :number — :customer', ['number' => (string) $inv->number, 'customer' => (string) ($inv->customer?->name ?? $inv->customer_name)]),
                null, __('طالب بالسداد — ذكّر العميل'), route('admin.customerInvoices.show', $inv->id),
                $inv->due_at, $money ? $inv->outstanding() : null));
        }

        $supplierDue = SupplierInvoice::where('business_id', $bid)->owed()->whereNotNull('due_at')->whereDate('due_at', '<', $today)
            ->whereRaw('(total - paid) > 0.0005')->with('supplier')->orderBy('due_at')->limit(self::LIMIT)->get();
        foreach ($supplierDue as $si) {
            $rows->push(self::row('supplier_invoice', 'danger',
                __('سند مورّد :number — :supplier', ['number' => (string) $si->supplier_ref, 'supplier' => (string) ($si->supplier?->name ?? '')]),
                null, __('سدّد أو اتّفق على موعد'), route('admin.purchases.invoices.show', $si->id),
                $si->due_at, $money ? $si->outstanding() : null));
        }

        foreach (Expense::where('business_id', $bid)->unpaid()->whereNotNull('due_date')->whereDate('due_date', '<', $today)->orderBy('due_date')->limit(self::LIMIT)->get() as $e) {
            $rows->push(self::row('expense', 'warning', (string) ($e->description ?: $e->type),
                (string) ($e->reference ?? ''), __('سدّد المصروف'), route('admin.expenses.index'),
                $e->due_date, $money ? (float) $e->amount : null));
        }

        $pos = PurchaseOrder::where('business_id', $bid)->whereIn('status', [PurchaseOrders::SENT, PurchaseOrders::PARTIAL])
            ->whereNotNull('expected_delivery_at')->whereDate('expected_delivery_at', '<', $today)
            ->with('supplier')->orderBy('expected_delivery_at')->limit(self::LIMIT)->get();
        foreach ($pos as $po) {
            $rows->push(self::row('purchase_order', 'warning',
                __('أمر شراء :number — :supplier', ['number' => (string) $po->number, 'supplier' => (string) ($po->supplier?->name ?? $po->supplier_name)]),
                __('لم يُستلم كاملًا'), __('تابع المورّد'), route('admin.purchases.show', $po->id),
                $po->expected_delivery_at, $money ? (float) $po->total : null));
        }

        foreach (Project::where('business_id', $bid)->open()->whereNotNull('ends_at')->whereDate('ends_at', '<', $today)->with('customer')->orderBy('ends_at')->limit(self::LIMIT)->get() as $p) {
            $readiness = DeliveryReadiness::forProject($p);
            $rows->push(self::row('project_overdue', $readiness->ready ? 'warning' : 'danger',
                __('مشروع :code — :name', ['code' => $p->code, 'name' => $p->name]),
                $readiness->ready ? __('التسليم مقبول — يُقفل الآن') : (string) ($readiness->blockers[0] ?? ''),
                $readiness->ready ? __('أقفل المشروع') : __('أتمّ التسليم ثمّ أقفل'), route('admin.projects.show', $p->id), $p->ends_at));
        }

        return self::section($rows->sortBy('at'));
    }

    /* ─────────────────────────── التسليم ─────────────────────────── */

    /**
     * ما ينتظر أن يُسلَّم أو يُقبل أو يُقفل.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public static function delivery(int $bid): array
    {
        $rows = collect();

        $packages = DeliveryPackage::where('business_id', $bid)
            ->whereIn('status', [DeliveryPackage::READY, DeliveryPackage::SENT, DeliveryPackage::DECLINED])
            ->with('customer', 'project')->orderBy('updated_at')->limit(self::LIMIT * 2)->get();

        foreach ($packages as $d) {
            $label = __('حزمة التسليم :label — :customer', ['label' => DeliveryPackages::label($d), 'customer' => (string) ($d->customer?->name ?? '')]);
            $detail = $d->project ? __('المشروع :code — :name', ['code' => $d->project->code, 'name' => $d->project->name]) : (string) $d->title;

            $rows->push(match ($d->status) {
                DeliveryPackage::READY => self::row('package_ready', 'info', $label, $detail, __('أرسل الحزمة للعميل'),
                    route('admin.deliveries.show', $d->id), $d->updated_at),
                DeliveryPackage::DECLINED => self::row('package_declined', 'danger', $label,
                    trim($detail.' — '.(string) ($d->decline_reason ?? '')), __('نقّح الحزمة بعد الرفض'),
                    route('admin.deliveries.show', $d->id), $d->declined_at ?? $d->updated_at),
                default => self::row('package_waiting', ($d->sent_at?->diffInDays(now()) ?? 0) >= self::STALE_DAYS ? 'warning' : 'info', $label,
                    $detail.($d->view_count > 0 ? '' : ' — '.__('لم يُفتح بعد')), __('ذكّر العميل'),
                    route('admin.deliveries.show', $d->id), $d->sent_at ?? $d->updated_at),
            });
        }

        /* ومشاريعُ انتهى عملُها ولا حزمةَ مقبولة — أو مقبولةٌ فتُقفل */
        $ended = Project::where('business_id', $bid)->open()
            ->where(fn ($q) => $q->whereDate('ends_at', '<=', now()->toDateString())->orWhere('status', Project::IN_PROGRESS))
            ->with('customer')->orderBy('ends_at')->limit(self::LIMIT * 2)->get();

        foreach ($ended as $p) {
            $readiness = DeliveryReadiness::forProject($p);
            $label = __('مشروع :code — :name', ['code' => $p->code, 'name' => $p->name]);

            if ($readiness->ready) {
                $rows->push(self::row('project_closable', 'info', $label, __('التسليم مقبول'), __('أقفل المشروع'),
                    route('admin.projects.show', $p->id), $p->ends_at ?? $p->updated_at));
            } elseif ($p->ends_at !== null && $p->ends_at->isPast()
                && ! DeliveryPackage::where('project_id', $p->id)->whereIn('status', [DeliveryPackage::READY, DeliveryPackage::SENT, DeliveryPackage::DECLINED])->exists()) {
                $rows->push(self::row('project_needs_package', 'warning', $label, (string) ($readiness->blockers[0] ?? ''),
                    __('أنشئ حزمة التسليم'), route('admin.deliveries.create', ['project_id' => $p->id]), $p->ends_at));
            }
        }

        return self::section($rows->sortBy('at'));
    }

    /* ─────────────────────────── المراسلات ─────────────────────────── */

    /**
     * ما لم يصل — ولم تنجح إعادتُه بعد.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, sent_today: int}
     */
    public static function communications(int $bid): array
    {
        $rows = collect();

        $failed = Communication::where('business_id', $bid)->whereIn('status', Communication::TERMINAL)
            ->where('created_at', '>=', now()->subDays(30))
            ->whereDoesntHave('retries', fn ($q) => $q->whereNotIn('status', Communication::TERMINAL))
            ->with('subject')->orderByDesc('failed_at')->limit(self::LIMIT)->get();

        foreach ($failed as $c) {
            $rows->push(self::row('communication_failed', 'danger',
                (Templates::exists($c->template_key) ? __(Templates::CATALOG[$c->template_key]['name']) : $c->template_key)
                    .' — '.(string) (Subjects::label($c->subject) ?? $c->title),
                ($c->channel === Communication::WHATSAPP ? __('واتساب') : __('البريد')).' · '.$c->recipient
                    .($c->failure_reason ? ' — '.mb_substr($c->failure_reason, 0, 120) : ''),
                __('أعد الإرسال أو صحّح العنوان'), route('admin.communications.show', $c->id), $c->failed_at ?? $c->created_at));
        }

        return self::section($rows) + [
            'sent_today' => (int) Communication::where('business_id', $bid)->whereNotIn('status', Communication::TERMINAL)
                ->whereDate('created_at', now()->toDateString())->count(),
        ];
    }

    /* ─────────────────────────── القادم ─────────────────────────── */

    /**
     * ما يبدأ خلال أسبوعين وما يجري الآن — بجاهزيّته لا بتاريخه وحده.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, soon: int}
     */
    public static function upcoming(int $bid): array
    {
        $today = now()->startOfDay();
        $until = $today->copy()->addDays(self::UPCOMING_DAYS);

        $projects = Project::where('business_id', $bid)->open()
            ->where(fn ($q) => $q->whereBetween('starts_at', [$today->toDateString(), $until->toDateString()])
                ->orWhere(fn ($w) => $w->whereDate('starts_at', '<=', $today->toDateString())
                    ->whereDate('ends_at', '>=', $today->toDateString())))
            ->with('customer', 'owner')->orderBy('starts_at')->limit(self::LIMIT * 2)->get();

        $ids = $projects->pluck('id')->all();
        $openTasks = ProjectTask::whereIn('project_id', $ids)->where('status', ProjectTask::OPEN)
            ->selectRaw('project_id, COUNT(*) AS n')->groupBy('project_id')->pluck('n', 'project_id');
        $lateTasks = ProjectTask::whereIn('project_id', $ids)->where('status', ProjectTask::OPEN)
            ->whereDate('due_at', '<', $today->toDateString())
            ->selectRaw('project_id, COUNT(*) AS n')->groupBy('project_id')->pluck('n', 'project_id');
        $packages = DeliveryPackage::whereIn('project_id', $ids)->whereIn('status', [DeliveryPackage::SENT, DeliveryPackage::ACCEPTED, DeliveryPackage::DECLINED])
            ->get(['project_id', 'status'])->groupBy('project_id');

        $rows = [];
        $soon = 0;

        foreach ($projects as $p) {
            $daysToStart = $p->starts_at === null ? null : (int) $today->diffInDays($p->starts_at->startOfDay(), false);
            $running = $daysToStart !== null && $daysToStart <= 0;
            $issues = [];

            if (! $p->isBaselined()) {
                $issues[] = __('المتطلّبات لم تُثبَّت');
            }
            if ($p->status === Project::ON_HOLD) {
                $issues[] = __('المشروع موقوف');
            }
            if (($late = (int) ($lateTasks[$p->id] ?? 0)) > 0) {
                $issues[] = __(':n مهامّ متأخّرة', ['n' => $late]);
            }
            if ($running && (int) ($openTasks[$p->id] ?? 0) === 0 && ! ($packages[$p->id] ?? collect())->contains('status', DeliveryPackage::ACCEPTED)) {
                $issues[] = __('لا مهامّ مفتوحة ولا تسليم مقبول');
            }

            if ($daysToStart !== null && $daysToStart >= 0 && $daysToStart <= self::HORIZON_DAYS) {
                $soon++;
            }

            $rows[] = [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'customer' => (string) ($p->customer?->name ?? ''),
                'status' => $p->status,
                'owner' => $p->owner?->name,
                'starts_at' => $p->starts_at?->format('Y-m-d'),
                'ends_at' => $p->ends_at?->format('Y-m-d'),
                'days_to_start' => $daysToStart,
                'running' => $running,
                'location' => $p->location,
                'open_tasks' => (int) ($openTasks[$p->id] ?? 0),
                'late_tasks' => (int) ($lateTasks[$p->id] ?? 0),
                'baselined' => $p->isBaselined(),
                'delivery' => ($packages[$p->id] ?? collect())->contains('status', DeliveryPackage::ACCEPTED) ? 'accepted'
                    : (($packages[$p->id] ?? collect())->contains('status', DeliveryPackage::SENT) ? 'sent' : 'none'),
                'issues' => $issues,
                'severity' => $issues === [] ? 'ok' : (($daysToStart ?? 99) <= self::HORIZON_DAYS ? 'danger' : 'warning'),
                'url' => route('admin.projects.show', $p->id),
            ];
        }

        return ['rows' => $rows, 'total' => count($rows), 'soon' => $soon];
    }

    /* ─────────────────────────── عوائقُ المشاريع ─────────────────────────── */

    /**
     * كلُّ مشروعٍ مفتوحٍ عليه ما يعطّله — تشغيليًّا أو ماليًّا.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public static function blockers(int $bid, bool $money): array
    {
        $today = now()->toDateString();
        $projects = Project::where('business_id', $bid)->open()->with('customer')->orderBy('starts_at')->limit(40)->get();
        $ids = $projects->pluck('id')->all();

        $lateTasks = ProjectTask::whereIn('project_id', $ids)->where('status', ProjectTask::OPEN)
            ->whereDate('due_at', '<', $today)->selectRaw('project_id, COUNT(*) AS n')->groupBy('project_id')->pluck('n', 'project_id');
        $changeOrders = ChangeOrder::whereIn('project_id', $ids)
            ->whereIn('status', [ChangeOrder::PENDING_APPROVAL, ChangeOrder::SENT])->get(['project_id', 'status', 'sent_at'])->groupBy('project_id');
        $declined = DeliveryPackage::whereIn('project_id', $ids)->where('status', DeliveryPackage::DECLINED)->pluck('project_id')->flip();
        $overdueInvoices = CustomerInvoice::whereIn('project_id', $ids)->overdue()->pluck('project_id')->flip();
        $pendingSupplier = SupplierInvoice::whereIn('project_id', $ids)->where('approval_status', SupplierInvoices::PENDING)
            ->selectRaw('project_id, COUNT(*) AS n')->groupBy('project_id')->pluck('n', 'project_id');

        $rows = [];

        foreach ($projects as $p) {
            $operational = [];
            $financial = [];

            if (! $p->isBaselined()) {
                $operational[] = __('المتطلّبات لم تُثبَّت');
            }
            if ($p->status === Project::ON_HOLD) {
                $operational[] = __('المشروع موقوف');
            }
            if (($n = (int) ($lateTasks[$p->id] ?? 0)) > 0) {
                $operational[] = __(':n مهامّ متأخّرة', ['n' => $n]);
            }
            foreach ($changeOrders[$p->id] ?? [] as $co) {
                if ($co->status === ChangeOrder::PENDING_APPROVAL) {
                    $operational[] = __('أمر تغيير ينتظر اعتمادًا');
                } elseif ($co->sent_at !== null && $co->sent_at->diffInDays(now()) >= self::STALE_DAYS) {
                    $operational[] = __('أمر تغيير بانتظار العميل منذ :n يومًا', ['n' => (int) $co->sent_at->diffInDays(now())]);
                }
            }
            if (isset($declined[$p->id])) {
                $operational[] = __('رفض العميل حزمة التسليم');
            }
            if (($broken = ProjectScope::verify($p)) !== []) {
                $operational[] = __('انحراف في النطاق: :what', ['what' => $broken[0]]);
            }

            if ($money) {
                $f = ProjectFinancials::summary($p);
                if ($f['invoiced_above_approved']) {
                    $financial[] = __('فُوتر فوق القيمة المعتمَدة');
                }
                if ($f['forecast_margin'] < 0) {
                    $financial[] = __('الهامش المتوقّع سالب (:pct%)', ['pct' => $f['forecast_margin_percent']]);
                }
                if (isset($overdueInvoices[$p->id])) {
                    $financial[] = __('ذمم متأخّرة على العميل');
                }
                if (($n = (int) ($pendingSupplier[$p->id] ?? 0)) > 0) {
                    $financial[] = __(':n سندات موردين بانتظار اعتماد', ['n' => $n]);
                }
            } else {
                if (isset($overdueInvoices[$p->id])) {
                    $financial[] = __('ذمم متأخّرة على العميل');
                }
                if (($n = (int) ($pendingSupplier[$p->id] ?? 0)) > 0) {
                    $financial[] = __(':n سندات موردين بانتظار اعتماد', ['n' => $n]);
                }
            }

            if ($operational === [] && $financial === []) {
                continue;
            }

            $rows[] = [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'customer' => (string) ($p->customer?->name ?? ''),
                'status' => $p->status,
                'starts_at' => $p->starts_at?->format('Y-m-d'),
                'operational' => $operational,
                'financial' => $financial,
                'severity' => (count($operational) + count($financial)) >= 3 || isset($declined[$p->id]) || $financial !== [] ? 'danger' : 'warning',
                'url' => route('admin.projects.show', $p->id),
            ];
        }

        usort($rows, fn ($a, $b) => [$b['severity'] === 'danger', count($b['operational']) + count($b['financial'])]
            <=> [$a['severity'] === 'danger', count($a['operational']) + count($a['financial'])]);

        return ['rows' => array_slice($rows, 0, self::LIMIT), 'total' => count($rows)];
    }

    /* ─────────────────────────── الفريق ─────────────────────────── */

    /**
     * من يعمل على ماذا — بالمهامّ والمشاريع لا بالأسماء.
     *
     * @return array{rows: list<array<string, mixed>>, unassigned: int}
     */
    public static function team(int $bid): array
    {
        $today = now()->toDateString();
        $openProjects = Project::where('business_id', $bid)->open()->pluck('id')->all();

        $users = User::where('business_id', $bid)->where('status', 'نشط')
            ->whereIn('role', Permissions::PANEL_ROLES)->orderBy('name')->get(['id', 'name', 'role']);

        $open = ProjectTask::whereIn('project_id', $openProjects)->where('status', ProjectTask::OPEN)->whereNotNull('assignee_id')
            ->selectRaw('assignee_id, COUNT(*) AS n')->groupBy('assignee_id')->pluck('n', 'assignee_id');
        $late = ProjectTask::whereIn('project_id', $openProjects)->where('status', ProjectTask::OPEN)->whereNotNull('assignee_id')
            ->whereDate('due_at', '<', $today)->selectRaw('assignee_id, COUNT(*) AS n')->groupBy('assignee_id')->pluck('n', 'assignee_id');
        $owned = Project::whereIn('id', $openProjects)->whereNotNull('owner_id')
            ->selectRaw('owner_id, COUNT(*) AS n')->groupBy('owner_id')->pluck('n', 'owner_id');
        $member = ProjectMember::whereIn('project_id', $openProjects)
            ->selectRaw('user_id, COUNT(*) AS n')->groupBy('user_id')->pluck('n', 'user_id');

        /* وأقربُ مهمّةٍ لكلّ واحد — استعلامٌ واحد ثمّ أوّلُ صفٍّ لكلّ مسؤول */
        $next = ProjectTask::whereIn('project_id', $openProjects)->where('status', ProjectTask::OPEN)->whereNotNull('assignee_id')
            ->with('project:id,code,name')->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')->orderBy('due_at')->orderBy('id')
            ->get()->groupBy('assignee_id')->map(fn (Collection $tasks) => $tasks->first());

        $rows = [];

        foreach ($users as $u) {
            $task = $next[$u->id] ?? null;
            $row = [
                'id' => $u->id,
                'name' => $u->name,
                'role' => $u->role,
                'open_tasks' => (int) ($open[$u->id] ?? 0),
                'late_tasks' => (int) ($late[$u->id] ?? 0),
                'projects_owned' => (int) ($owned[$u->id] ?? 0),
                'projects_member' => (int) ($member[$u->id] ?? 0),
                'next_task' => $task === null ? null : [
                    'title' => $task->title,
                    'due_at' => $task->due_at?->format('Y-m-d'),
                    'project' => $task->project?->code,
                    'url' => route('admin.projects.show', $task->project_id),
                ],
            ];

            if ($row['open_tasks'] === 0 && $row['projects_owned'] === 0 && $row['projects_member'] === 0) {
                continue;
            }

            $rows[] = $row;
        }

        usort($rows, fn ($a, $b) => [$b['late_tasks'], $b['open_tasks'], $b['projects_owned']] <=> [$a['late_tasks'], $a['open_tasks'], $a['projects_owned']]);

        return [
            'rows' => $rows,
            'unassigned' => (int) ProjectTask::whereIn('project_id', $openProjects)->where('status', ProjectTask::OPEN)->whereNull('assignee_id')->count(),
        ];
    }

    /* ─────────────────────────── المال ─────────────────────────── */

    /**
     * من يدين لنا وما علينا — بالمستحقّ والمتأخّر والقريب، لا بالإجمالي وحده.
     *
     * @return array<string, mixed>
     */
    public static function money(int $bid): array
    {
        $today = now()->toDateString();
        $soon = now()->addDays(self::HORIZON_DAYS)->toDateString();
        $out = CustomerInvoice::outstandingSql();

        $live = fn () => CustomerInvoice::where('business_id', $bid)->live()->whereRaw($out.' > 0.0005');
        $receivable = round((float) $live()->selectRaw('COALESCE(SUM('.$out.'), 0) AS v')->value('v'), 3);
        $overdueQ = CustomerInvoice::where('business_id', $bid)->overdue();
        $overdue = round((float) (clone $overdueQ)->selectRaw('COALESCE(SUM('.$out.'), 0) AS v')->value('v'), 3);
        $overdueCount = (int) (clone $overdueQ)->count();
        $dueSoon = round((float) $live()->whereNotNull('due_at')->whereDate('due_at', '>=', $today)->whereDate('due_at', '<=', $soon)
            ->selectRaw('COALESCE(SUM('.$out.'), 0) AS v')->value('v'), 3);

        $topDebtors = CustomerInvoice::where('business_id', $bid)->overdue()->with('customer')
            ->selectRaw('customer_id, SUM('.$out.') AS owed, MIN(due_at) AS oldest')->groupBy('customer_id')
            ->orderByDesc('owed')->limit(5)->get()
            ->map(fn ($r) => [
                'customer_id' => $r->customer_id,
                'customer' => (string) ($r->customer?->name ?? ''),
                'amount' => round((float) $r->owed, 3),
                'oldest_due' => $r->oldest ? Carbon::parse($r->oldest)->format('Y-m-d') : null,
                'url' => $r->customer_id ? route('admin.finance.customerStatement', $r->customer_id) : route('admin.finance.receivables'),
            ])->values()->all();

        $owedQ = fn () => SupplierInvoice::where('business_id', $bid)->owed()->whereRaw('(total - paid) > 0.0005');
        $payable = round((float) $owedQ()->selectRaw('COALESCE(SUM(total - paid), 0) AS v')->value('v'), 3);
        $payableOverdue = round((float) $owedQ()->whereNotNull('due_at')->whereDate('due_at', '<', $today)
            ->selectRaw('COALESCE(SUM(total - paid), 0) AS v')->value('v'), 3);
        $payableOverdueCount = (int) $owedQ()->whereNotNull('due_at')->whereDate('due_at', '<', $today)->count();
        $payableSoon = round((float) $owedQ()->whereNotNull('due_at')->whereDate('due_at', '>=', $today)->whereDate('due_at', '<=', $soon)
            ->selectRaw('COALESCE(SUM(total - paid), 0) AS v')->value('v'), 3);

        $topCreditors = $owedQ()->with('supplier')
            ->selectRaw('supplier_id, SUM(total - paid) AS owed, MIN(due_at) AS oldest')->groupBy('supplier_id')
            ->orderByDesc('owed')->limit(5)->get()
            ->map(fn ($r) => [
                'supplier_id' => $r->supplier_id,
                'supplier' => (string) ($r->supplier?->name ?? ''),
                'amount' => round((float) $r->owed, 3),
                'oldest_due' => $r->oldest ? Carbon::parse($r->oldest)->format('Y-m-d') : null,
                'url' => route('admin.purchases.invoices'),
            ])->values()->all();

        $expensesUnpaid = round((float) Expense::where('business_id', $bid)->unpaid()->sum('amount'), 3);
        $openPos = PurchaseOrder::where('business_id', $bid)->whereIn('status', [PurchaseOrders::SENT, PurchaseOrders::PARTIAL]);
        $openPoTotal = round((float) (clone $openPos)->sum('total'), 3);
        $openPoCount = (int) (clone $openPos)->count();

        return [
            'receivables' => [
                'total' => $receivable,
                'overdue' => $overdue,
                'overdue_count' => $overdueCount,
                'due_soon' => $dueSoon,
                'top' => $topDebtors,
                'url' => route('admin.finance.receivables'),
            ],
            'payables' => [
                'supplier_total' => $payable,
                'supplier_overdue' => $payableOverdue,
                'supplier_overdue_count' => $payableOverdueCount,
                'supplier_due_soon' => $payableSoon,
                'expenses_unpaid' => $expensesUnpaid,
                'open_purchase_orders' => $openPoTotal,
                'open_purchase_orders_count' => $openPoCount,
                'top' => $topCreditors,
                'url' => route('admin.purchases.invoices'),
            ],
            'net' => round($receivable - $payable - $expensesUnpaid, 3),
        ];
    }

    /* ─────────────────────────── مساعدات ─────────────────────────── */

    /**
     * صفٌّ موحَّد: نوعٌ وشدّةٌ وعنوانٌ وتفصيلٌ وفعلٌ ورابطٌ وتاريخٌ ومبلغ.
     *
     * @return array<string, mixed>
     */
    private static function row(string $kind, string $severity, string $title, ?string $detail, string $action, string $url, mixed $at, ?float $amount = null): array
    {
        $date = $at instanceof Carbon ? $at : (filled($at) ? Carbon::parse((string) $at) : null);

        return [
            'kind' => $kind,
            'severity' => $severity,
            'title' => $title,
            'detail' => filled($detail) ? $detail : null,
            'action' => $action,
            'url' => $url,
            'at' => $date?->format('Y-m-d'),
            /* موجبٌ لما فات موعدُه، سالبٌ لما لم يحن — والشاشةُ تقول «منذ» أو «بعد» */
            'days' => $date === null ? null : (int) $date->copy()->startOfDay()->diffInDays(now()->startOfDay(), false),
            'amount' => $amount === null ? null : round($amount, 3),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    private static function section(Collection $rows): array
    {
        return ['rows' => $rows->values()->take(self::LIMIT)->all(), 'total' => $rows->count()];
    }
}
