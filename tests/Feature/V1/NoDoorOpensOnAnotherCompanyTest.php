<?php

namespace Tests\Feature\V1;

use App\Models\Asset;
use App\Models\Branch;
use App\Models\Budget;
use App\Models\Business;
use App\Models\ChangeOrder;
use App\Models\Communication;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\DeliveryPackage;
use App\Models\EventOperation;
use App\Models\Expense;
use App\Models\ExpenseType;
use App\Models\FinancialForecast;
use App\Models\FinancialScenario;
use App\Models\ImportDataset;
use App\Models\JobTitle;
use App\Models\JournalEntry;
use App\Models\ManagementReview;
use App\Models\Order;
use App\Models\OperationChecklistTemplate;
use App\Models\PayrollRun;
use App\Models\Product;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\DeliveryPackages;
use App\Support\DemoStore;
use App\Support\Import\Datasets;
use App\Support\Intelligence\Budgeting;
use App\Support\Intelligence\Forecasting;
use App\Support\Intelligence\ManagementReviews;
use App\Support\Intelligence\ScenarioPlanning;
use App\Support\Ledger;
use App\Support\Operations\Assets;
use App\Support\Operations\Checklists;
use App\Support\Operations\Consumables;
use App\Support\Operations\Crew;
use App\Support\Operations\EventOperations;
use App\Support\Operations\Incidents;
use App\Support\Operations\Requirements;
use App\Support\Operations\Reservations;
use App\Support\Operations\Schedule;
use App\Support\Permissions;
use App\Support\Projects;
use App\Support\Quotations;
use App\Support\SupplierInvoices;
use App\Support\TenantTables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * لا بابَ في النظام يُفتح على شركةٍ أخرى — ولو كُتب المعرّفُ بيد (المرحلة 12).
 *
 * ═══ الفكرة ═══
 *
 * شركةٌ «أ» ممتلئة: صفٌّ من كلّ نوعٍ يحمل معرّفًا في مسار — عميلٌ وعرضٌ ومشروعٌ
 * وأمرُ تغييرٍ وفعاليّةٌ وقطعةٌ وحجزٌ وفريقٌ وحادثٌ وقائمةٌ وحزمةٌ وفاتورةٌ
 * ودفعةٌ وسندٌ ومصروفٌ ومراسلةٌ وموازنةٌ وتوقّعٌ وسيناريو ومراجعةٌ واستيراد.
 * ومالكُ شركةٍ «ب» يطرق **كلَّ مسارٍ ذي معاملٍ تحت `admin.`** بمعرّفات «أ».
 *
 * فلا يُقبل منه ٢٠٠ على قراءة، ولا ٥٠٠ على أيّ شيء، ولا يتغيّر صفٌّ واحد
 * من صفوف «أ». والمساراتُ التي لا تُدرَج هنا تُعدّ ثغرةً في الفحص نفسِه
 * (يُطبع اسمُها)، لا استثناءً صامتًا.
 *
 * وحارسُ دورٍ لا يُغني عن هذا: `EveryRoleIsProbedOnEveryRouteTest` يفحص
 * الأقسامَ على مساراتٍ بلا معاملات، وهذا يفحص المعرّفات.
 */
class NoDoorOpensOnAnotherCompanyTest extends TestCase
{
    use RefreshDatabase;

    private const MARK = 'ZQXA9';

    /** المساراتُ التي معاملُها ليس معرّفَ صفٍّ لشركة — لا تُفحص هنا */
    private const NOT_AN_ID = [
        'admin.currency.switch', 'admin.reports.export.csv', 'admin.reports.export.pdf', 'admin.reports.export.xlsx',
        'admin.settings.templates.edit', 'admin.settings.templates.update', 'admin.settings.templates.preview',
        'admin.imports.rows',
    ];

    private Business $a;

    private Business $b;

    private User $ownerA;

    private User $ownerB;

    /** معرّفاتُ «أ» باسم المعامل الذي تُحشر فيه */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->b = $this->company('شركة ب');
        $this->ownerB = $this->user($this->b, 'owner-b@x.om');

        $this->a = $this->company('شركة أ '.self::MARK);
        $this->ownerA = $this->user($this->a, 'owner-a@x.om');
        $this->fillA();
    }

    private function company(string $name): Business
    {
        $b = Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي '.$name]);
        JobTitle::create(['business_id' => $b->id, 'name' => 'مدير', 'role' => 'admin']);
        Currency::create(['business_id' => $b->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Setting::create(['business_id' => $b->id, 'key' => 'vat_rate', 'value' => '5']);
        Ledger::seedChart((int) $b->id);

        return $b;
    }

    private function user(Business $b, string $email, string $role = 'admin'): User
    {
        return User::create(['business_id' => $b->id, 'name' => 'مالك '.$b->name, 'email' => $email, 'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط', 'phone' => '9'.random_int(1000000, 9999999)]);
    }

    /** صفٌّ من كلّ نوعٍ في «أ» — يحمل العلامةَ حيث يُقرأ اسمُه */
    private function fillA(): void
    {
        $bid = (int) $this->a->id;
        $owner = $this->ownerA;
        $this->actingAs($owner);
        $m = self::MARK;

        $customer = Customer::create(['business_id' => $bid, 'name' => "عميل $m", 'phone' => '99887766', 'email' => 'buyer@a.om']);
        $address = CustomerAddress::create(['customer_id' => $customer->id, 'label' => "عنوان $m", 'city' => "مسقط $m", 'is_default' => true]);
        $supplier = Supplier::create(['business_id' => $bid, 'name' => "مورّد $m"]);
        $product = Product::create(['business_id' => $bid, 'name' => "صنف $m", 'price' => 10, 'cost' => 4, 'quantity' => 100, 'alert_qty' => 1, 'active' => true, 'sku' => "SKU-$m"]);
        $expenseType = ExpenseType::create(['business_id' => $bid, 'name' => "نوع $m"]);
        $expense = Expense::create(['business_id' => $bid, 'reference' => "EXP-$m", 'type' => 'نقل', 'description' => "مصروف $m", 'amount' => 50, 'method' => 'نقدي', 'status' => 'مدفوع', 'spent_at' => now()->toDateString()]);
        $employee = $this->user($this->a, 'staff-a@x.om', 'sales');
        $jobTitle = JobTitle::where('business_id', $bid)->first();

        $q = Quotations::create($bid, $customer, ['title' => "عرض $m", 'issued_at' => now()->toDateString()], [['description' => "تنظيم $m", 'quantity' => 2, 'unit_price' => 500]], $owner);
        Quotations::send($q, $owner);
        Quotations::recordDecision($q->fresh(), 'accepted', ['decided_by_name' => 'سالم'], $owner);
        $project = Project::where('quotation_id', $q->id)->firstOrFail();
        $project->update(['name' => "مشروع $m"]);
        $draftQ = Quotations::create($bid, $customer, ['title' => "مسودّة $m", 'issued_at' => now()->toDateString()], [['description' => 'x', 'quantity' => 1, 'unit_price' => 100]], $owner);

        $member = Projects::addMember($project, $employee->id, 'منسّق', $owner);
        $task = Projects::addTask($project, ['title' => "مهمّة $m"], $owner);
        $requirement = Projects::addRequirement($project, ['title' => "متطلَّب $m", 'kind' => 'equipment'], $owner);
        $co = \App\Support\ChangeOrders::create($project, ['title' => "أمر تغيير $m", 'requested_by_party' => 'customer'], [['project_item_id' => $project->items()->first()->id, 'quantity' => 1]], [], $owner);

        $op = EventOperations::create($project, ['name' => "فعاليّة $m", 'venue_name' => "قاعة $m", 'setup_starts_at' => '2026-10-01 06:00', 'event_starts_at' => '2026-10-01 09:00',
            'event_ends_at' => '2026-10-01 17:00', 'teardown_starts_at' => '2026-10-01 17:00', 'teardown_ends_at' => '2026-10-01 19:00', 'operations_lead_id' => $owner->id], $owner);
        $schedule = Schedule::add($op, ['title' => "بند $m", 'starts_at' => '2026-10-01 09:00', 'ends_at' => '2026-10-01 10:00'], $owner);
        $asset = Assets::create($bid, ['name' => "حاسوب $m", 'category' => 'laptop'], $owner);
        $opReq = Requirements::add($op, ['kind' => 'asset_category', 'asset_category' => 'laptop', 'required_quantity' => 1], $owner);
        $reservation = Reservations::reserve($op, $asset, ['requirement_id' => $opReq->id], $owner);
        $crew = Crew::assign($op, ['user_id' => $employee->id, 'role' => 'technical_support'], $owner);
        $incident = Incidents::report($op, ['title' => "حادث $m", 'category' => 'equipment', 'severity' => 'medium'], $owner);
        $template = Checklists::createTemplate($bid, ['name' => "قائمة $m", 'phase' => 'setup', 'items' => [['title' => "بند $m"]]], $owner);
        Checklists::attach($op, $template, $owner);
        $checklist = $op->checklists()->firstOrFail();
        $checklistItem = $checklist->items()->firstOrFail();
        $consumable = Consumables::issue($op, $product, ['quantity' => 2, 'key' => 'k'], $owner);

        $package = DeliveryPackages::create($bid, null, ['project_id' => $project->id], $owner);
        $packageItem = DeliveryPackages::addItem($package, ['kind' => 'item', 'project_item_id' => $project->items()->first()->id, 'quantity' => 1], null, $owner);

        $invoice = CustomerInvoices::create($bid, $customer, ['project_id' => $project->id, 'issued_at' => now()->toDateString()], [['description' => "تنظيم $m", 'quantity' => 1, 'unit_price' => 1000]], $owner->id);
        $draftInvoice = CustomerInvoices::create($bid, $customer, ['issued_at' => now()->toDateString()], [['description' => "مسودّة $m", 'quantity' => 1, 'unit_price' => 10]], $owner->id);
        $invoice = CustomerInvoices::issue($invoice->fresh(), $owner->id);
        $payment = CustomerPayments::record($bid, $customer, 100, ['method' => 'نقدي', 'occurred_at' => now()->toDateString()], [$invoice->id => 100], $owner->id);
        $creditNote = CustomerInvoices::creditNote($invoice->fresh(), 105, 5, "إشعار $m", $owner->id);

        $po = PurchaseOrder::create(['business_id' => $bid, 'number' => "PO-$m", 'supplier_id' => $supplier->id, 'supplier_name' => "مورّد $m", 'status' => \App\Support\PurchaseOrders::SENT, 'items_subtotal' => 100, 'tax' => 0, 'total' => 100, 'ordered_at' => now()]);
        $si = SupplierInvoices::create($bid, ['supplier_id' => $supplier->id, 'supplier_ref' => "R-$m", 'issued_at' => now()->toDateString(), 'subtotal' => 100, 'tax' => 0], $owner);
        $journal = JournalEntry::where('business_id', $bid)->firstOrFail();
        $order = Order::create(['business_id' => $bid, 'number' => "ORD-$m", 'customer_id' => $customer->id, 'customer_name' => "عميل $m", 'status' => 'جديد', 'subtotal' => 10, 'total' => 10, 'ordered_at' => now()]);
        $bank = \App\Models\BankAccount::create(['business_id' => $bid, 'bank_name' => "بنك $m", 'label' => "حساب $m", 'active' => true, 'is_primary' => true]);
        $account = \App\Models\Account::where('business_id', $bid)->where('code', '5900')->firstOrFail();
        $fixedAsset = \App\Models\FixedAsset::create(['business_id' => $bid, 'name' => "أصل $m", 'cost' => 1000, 'purchased_at' => now()->toDateString(), 'life_months' => 12, 'status' => 'نشط']);

        $communication = Communication::create(['business_id' => $bid, 'customer_id' => $customer->id, 'project_id' => $project->id, 'subject_type' => Project::class, 'subject_id' => $project->id,
            'channel' => 'email', 'recipient' => 'buyer@a.om', 'template_key' => 'project.update', 'title' => "رسالة $m", 'provider' => 'postmark', 'provider_message_id' => "pm-$m",
            'status' => Communication::BOUNCED, 'sent_at' => now(), 'failed_at' => now(), 'failure_reason' => 'x', 'initiated_by_name' => 'المالك']);
        \App\Support\Automation\Rules::ensure($bid);
        $rule = \App\Models\AutomationRule::where('business_id', $bid)->firstOrFail();
        $run = \App\Models\AutomationRun::create(['business_id' => $bid, 'automation_rule_id' => $rule->id, 'trigger' => 'invoice_due', 'step' => 0, 'audience' => 'management', 'occurrence' => 'x', 'status' => 'pending', 'idempotency_key' => "k-$m", 'scheduled_for' => now()]);
        $notification = \App\Models\InternalNotification::create(['business_id' => $bid, 'user_id' => $owner->id, 'title' => "تنبيه $m", 'body' => 'x', 'kind' => 'automation']);
        $alert = \App\Models\CustomAlert::create(['business_id' => $bid, 'type' => 'metric', 'section' => 'dashboard', 'metric' => 'daily_sales', 'operator' => '<', 'threshold' => 1, 'message' => "تنبيه $m", 'active' => true]);

        $budget = Budgeting::create($bid, ['fiscal_year' => 2026, 'name' => "موازنة $m", 'lines' => [['account_id' => \App\Models\Account::where('business_id', $bid)->where('code', '4100')->value('id'), 'month' => 1, 'planned_amount' => 10]]], $owner);
        $forecast = Forecasting::create($bid, ['name' => "توقّع $m", 'horizon_days' => 30], $owner);
        $scenario = ScenarioPlanning::create($bid, ['name' => "سيناريو $m"], $owner);
        $review = ManagementReviews::create($bid, ['title' => "مراجعة $m", 'period_start' => '2026-08-01', 'period_end' => '2026-08-31'], $owner);
        $decision = ManagementReviews::decide($review, ['decision' => "قرار $m"], $owner);

        $dataset = Datasets::registerPath($bid, base_path('tests/Fixtures/import/customers.csv'), ['category' => 'customers', 'name' => "استيراد $m"], $owner);
        $payroll = PayrollRun::create(['business_id' => $bid, 'number' => 'PR-00001', 'period' => '2026-08-01', 'status' => 'مسودة', 'gross' => 0, 'deductions' => 0, 'net' => 0]);
        $policy = \App\Models\CompanyPolicy::create(['business_id' => $bid, 'key' => 'lead_time', 'params' => ['days' => 3], 'text_ar' => "سياسة $m", 'active' => true, 'version' => 1]);

        auth()->logout();

        $this->ids = [
            'customers' => $customer->id, 'addressId' => $address->id, 'suppliers' => $supplier->id, 'products' => $product->id,
            'expenseTypes' => $expenseType->id, 'expenses' => $expense->id, 'employees' => $employee->id, 'jobTitles' => $jobTitle->id,
            'quotations' => $q->id, 'quotations.draft' => $draftQ->id, 'projects' => $project->id, 'mid' => $member->id, 'tid' => $task->id, 'projects.rid' => $requirement->id,
            'changeOrders' => $co->id, 'operations' => $op->id, 'sid' => $schedule->id, 'assets' => $asset->id, 'operations.rid' => $opReq->id,
            'assets.rid' => $reservation->id, 'reservations' => $reservation->id, 'cid' => $crew->id, 'iid' => $incident->id, 'operations.tid' => $template->id,
            'clid' => $checklist->id, 'checklist.iid' => $checklistItem->id, 'consumables.cid' => $consumable->id,
            'deliveries' => $package->id, 'item' => $packageItem->id, 'customerInvoices' => $invoice->id, 'customerInvoices.draft' => $draftInvoice->id,
            'customerPayments' => $payment->id, 'note' => $creditNote->id, 'purchases' => $po->id, 'purchases.invoices' => $si->id,
            'finance.journal' => $journal->id, 'orders.number' => $order->number, 'finance.banks' => $bank->id, 'finance.chart' => $account->id, 'finance.assets' => $fixedAsset->id,
            'communications' => $communication->id, 'automation.rules' => $rule->id, 'automation.runs' => $run->id, 'automation.history' => $run->id,
            'notifications' => $notification->id, 'alerts' => $alert->id, 'management.budgets' => $budget->id, 'management.forecasts' => $forecast->id,
            'management.scenarios' => $scenario->id, 'management.reviews' => $review->id, 'did' => $decision->id, 'imports' => $dataset->id,
            'payroll' => $payroll->id, 'branches' => (int) Branch::where('business_id', $bid)->value('id'), 'settings.policies' => $policy->id,
            'customer' => $customer->id, 'finance.cheques' => $payment->id, 'inventory.receipts' => 0,
        ];
    }

    /** المعرّفُ من «أ» لمعاملٍ في مسارٍ باسمه */
    private function resolve(string $routeName, string $param): int|string|null
    {
        $seg = explode('.', $routeName);
        $second = $seg[1] ?? '';
        $third = $seg[2] ?? '';
        $key = "$second.$third";

        return match (true) {
            $param === 'number' => $this->ids['orders.number'],
            $param === 'id' && $second === 'finance' && $third === 'statement' => $this->ids['finance.banks'],
            $param === 'customer' => $this->ids['customer'],
            $param === 'branch' => $this->ids['branches'],
            $param === 'addressId' => $this->ids['addressId'],
            $param === 'did' => $this->ids['did'],
            $param === 'mid' => $this->ids['mid'],
            $param === 'sid' => $this->ids['sid'],
            $param === 'clid' => $this->ids['clid'],
            $param === 'note' => $this->ids['note'],
            $param === 'item' => $this->ids['item'],
            $param === 'attachment', $param === 'imageId', $param === 'addon', $param === 'variant', $param === 'run' => 1,
            $param === 'tid' && $second === 'operations' => $this->ids['operations.tid'],
            $param === 'tid' => $this->ids['tid'],
            $param === 'rid' && $second === 'projects' => $this->ids['projects.rid'],
            $param === 'rid' && $third === 'reservations' => $this->ids['reservations'],
            $param === 'rid' => $this->ids['operations.rid'],
            $param === 'cid' && $third === 'consumables' => $this->ids['consumables.cid'],
            $param === 'cid' => $this->ids['cid'],
            $param === 'iid' && $third === 'checklistItems' => $this->ids['checklist.iid'],
            $param === 'iid' => $this->ids['iid'],
            $param === 'id' && isset($this->ids[$key]) => $this->ids[$key],
            $param === 'id' && $second === 'quotations' && in_array($third, ['update', 'send', 'approve'], true) => $this->ids['quotations.draft'],
            $param === 'id' && $second === 'customerInvoices' && $third === 'issue' => $this->ids['customerInvoices.draft'],
            $param === 'id' && isset($this->ids[$second]) => $this->ids[$second],
            default => null,
        };
    }

    /**
     * هل في الجسد شيءٌ من صفوف «أ»؟
     *
     * أسماءُ «أ» كلُّها «كلمةٌ ثمّ العلامة» («عميل ZQXA9»)؛ أمّا صدى كلمةِ
     * البحث نفسِها (`"q":"ZQXA9"`) فليس تسريبًا — فتُطلب العلامةُ بعد حرف.
     */
    private function carriesNeighbourData(string $body): bool
    {
        return preg_match('/[^\s"\'>:=,\[\]{}] '.self::MARK.'/u', $body) === 1 || str_contains($body, 'buyer@a.om');
    }

    /** لقطةُ صفوف «أ»: عددٌ وآخرُ تعديل لكلّ جدولٍ للمستأجر */
    private function snapshot(): array
    {
        $out = [];
        foreach (TenantTables::ORDER as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'business_id')) {
                continue;
            }
            $q = DB::table($table)->where('business_id', $this->a->id);
            $out[$table] = [
                'count' => (clone $q)->count(),
                'updated' => Schema::hasColumn($table, 'updated_at') ? (string) (clone $q)->max('updated_at') : '',
            ];
        }
        /* والجداولُ التي تمرّ عبر أمّها — وأمُّها قد تمرّ بدورها عبر أمّها (`order_item_addons` ← `order_items` ← `orders`) */
        foreach (TenantTables::THROUGH as $table => [$parent, $fk]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $q = DB::table($table)->whereIn($fk, $this->rowsOf($parent));
            $out[$table] = ['count' => (clone $q)->count(), 'updated' => Schema::hasColumn($table, 'updated_at') ? (string) (clone $q)->max('updated_at') : ''];
        }

        return $out;
    }

    /** معرّفاتُ صفوف «أ» في جدولٍ — مباشرةً بـ`business_id` أو صعودًا عبر الأمّ */
    private function rowsOf(string $table): \Illuminate\Support\Collection
    {
        if (Schema::hasColumn($table, 'business_id')) {
            return DB::table($table)->where('business_id', $this->a->id)->pluck('id');
        }
        [$parent, $fk] = TenantTables::THROUGH[$table];

        return DB::table($table)->whereIn($fk, $this->rowsOf($parent))->pluck('id');
    }

    public function test_every_parameterised_admin_route_refuses_a_neighbours_identifiers(): void
    {
        $before = $this->snapshot();
        $leaks = [];
        $crashes = [];
        $unmapped = [];
        $probed = 0;

        foreach (app('router')->getRoutes() as $route) {
            $name = $route->getName();
            if (! $name || ! str_starts_with($name, 'admin.') || ! str_contains($route->uri(), '{') || in_array($name, self::NOT_AN_ID, true)) {
                continue;
            }
            $verb = collect($route->methods())->first(fn ($v) => in_array($v, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true));
            if ($verb === null) {
                continue;
            }

            preg_match_all('/\{(\w+)\??\}/', $route->uri(), $m);
            $uri = $route->uri();
            $ok = true;
            foreach ($m[1] as $param) {
                $value = $this->resolve($name, $param);
                if ($value === null) {
                    $ok = false;
                    break;
                }
                $uri = preg_replace('/\{'.$param.'\??\}/', (string) $value, $uri);
            }
            if (! $ok) {
                $unmapped[] = $name;

                continue;
            }

            $probed++;
            $response = $this->actingAs($this->ownerB)->call($verb, '/'.ltrim($uri, '/'));
            $status = $response->getStatusCode();
            $body = (string) $response->getContent();

            if ($status >= 500) {
                $crashes[] = "$verb $name → $status";
            } elseif ($status === 200 && $verb === 'GET') {
                $leaks[] = "$verb $name → 200";
            } elseif ($this->carriesNeighbourData($body)) {
                $leaks[] = "$verb $name → $status يحمل بياناتِ الجار";
            }
        }

        $this->assertGreaterThan(200, $probed, 'قلّ ما فُحص — الفحصُ نفسُه معطوب');
        $this->assertSame([], $unmapped, "مساراتٌ لم يُعرف معرّفُها — أضفها إلى الخريطة:\n".implode("\n", $unmapped));
        $this->assertSame([], $crashes, "مساراتٌ سقطت بـ٥٠٠ على معرّفٍ غريب:\n".implode("\n", $crashes));
        $this->assertSame([], $leaks, "أبوابٌ فُتحت على الجار:\n".implode("\n", $leaks));

        $after = $this->snapshot();
        $changed = [];
        foreach ($before as $table => $row) {
            if ($row !== ($after[$table] ?? null)) {
                $changed[] = $table.': '.json_encode($row).' → '.json_encode($after[$table] ?? null);
            }
        }
        $this->assertSame([], $changed, "صفوفُ الجار تغيّرت:\n".implode("\n", $changed));

        /* وتبديلُ الفرع إلى فرع الجار لا يعلق في الجلسة */
        $this->assertNotSame($this->ids['branches'], (int) session('current_branch'));
    }

    /** ومن مُنح كلَّ الأفعال بالاسم في «ب» لا يزيد على ذلك شيئًا */
    public function test_even_a_fully_empowered_user_of_b_cannot_read_a_through_lookups_and_search(): void
    {
        $this->ownerB->update(['permissions' => array_values(array_unique(array_merge(Permissions::SECTIONS, array_keys(Permissions::ACTION_ROLES))))]);
        $b = $this->ownerB->fresh();

        foreach (['admin.search', 'admin.customers.index', 'admin.projects.index', 'admin.quotations.index', 'admin.operations.index', 'admin.assets.index', 'admin.communications.index', 'admin.management.projects', 'admin.management.customers'] as $name) {
            if (! app('router')->has($name)) {
                continue;
            }
            $response = $this->actingAs($b)->get(route($name, ['q' => self::MARK, 'search' => self::MARK, 'period' => 'all']));
            $this->assertFalse($this->carriesNeighbourData((string) $response->getContent()), "$name يُظهر بياناتِ الجار");
        }
    }
}
