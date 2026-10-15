<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Customer;
use App\Models\EventOperation;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Quotation;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Support\Intelligence\Budgeting;
use App\Support\Operations\Assets;
use App\Support\Operations\Checklists;
use App\Support\Operations\Crew;
use App\Support\Operations\EventOperations;
use App\Support\Operations\Incidents;
use App\Support\Operations\Requirements;
use App\Support\Operations\Reservations;
use App\Support\Operations\Schedule;
use App\Support\WhatsApp\Inbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * شركةُ الفعاليّات التجريبيّة — ما فوق `DemoStore`.
 *
 * `DemoStore` تبني ما كان للمتجر: كتالوجًا ومبيعاتٍ ومشترياتٍ ورواتب. وهذه
 * تبني ما صار للشركة: عملاءُ جهات، وعروضُ أسعارٍ بحالاتها، ومشاريعُ منها،
 * وفعاليّاتٌ بمواعيدها وفريقها ومعدّاتها، وفواتيرُ وتحصيل، وحزمُ تسليم،
 * ومحادثاتُ واتساب.
 *
 * ═══ من أبواب النظام وحدَها ═══
 *
 * كلُّ ما يُكتب هنا يمرّ بالخدمة التي تكتبه الشاشةُ بها — `Quotations`
 * و`Projects` و`EventOperations` و`CustomerInvoices` و`CustomerPayments` —
 * فالدفترُ يُرحَّل بـ`Ledger::post` والمخزونُ بـ`StockLedger`، ولا يُرى في
 * العرض ما لا يمكن أن يقع في الحقيقة.
 *
 * ═══ ولا تكتب إلّا في شركةٍ تجريبيّة ═══
 *
 * يحرسها `DemoGuard` كما يحرس `DemoStore`، وتُمحى معها بـ`DemoStore::destroy`:
 * كلُّ صفٍّ هنا تحت `business_id` الشركة التجريبيّة.
 */
final class DemoEvents
{
    private const CLIENTS = [
        ['وزارة الطاقة والمعادن', 'جهة حكومية', 'سالم البوسعيدي'],
        ['وزارة التراث والسياحة', 'جهة حكومية', 'هدى الرواحي'],
        ['هيئة تنمية المؤسسات الصغيرة', 'جهة حكومية', 'ناصر الكندي'],
        ['جامعة السلطان قابوس', 'جهة حكومية', 'د. مها الهنائي'],
        ['بنك مسقط', 'شركة', 'خالد المعمري'],
        ['أوكيو', 'شركة', 'ريم الشعيلي'],
        ['عمانتل', 'شركة', 'يوسف البلوشي'],
        ['الطيران العماني', 'شركة', 'أسماء الغيثي'],
        ['مجموعة الزبير', 'شركة', 'حمد العامري'],
        ['غرفة تجارة وصناعة عمان', 'شركة', 'لطيفة السيابي'],
        ['شركة تنمية نفط عمان', 'شركة', 'راشد الحارثي'],
        ['مركز عمان للمؤتمرات والمعارض', 'شركة', 'طلال الزدجالي'],
    ];

    /**
     * العروض: العنوان · بنودها من الكتالوج (الرمز، الكمية، سعرٌ لما يُسعَّر حسب النطاق) · المكان · بعد كم يومٍ من اليوم
     *
     * والكميّةُ كما يحسبها الكتالوج: «جهاز / يوم» عددُ الأجهزة في عدد الأيّام.
     */
    private const EVENTS = [
        ['مؤتمر الطاقة المستدامة 2026', [['01.01', 2], ['03.01', 8], ['05.04', 12], ['04.02', 1200], ['05.01', 2]], 'مركز عمان للمؤتمرات والمعارض', 18],
        ['معرض عمان للسياحة', [['07.03', 1], ['07.03x', 2], ['01.04', 1, 1500], ['02.04', 1]], 'مركز عمان للمؤتمرات والمعارض', 32],
        ['ملتقى رواد الأعمال', [['07.02', 1], ['04.01', 600], ['02.06', 1]], 'فندق قصر البستان', 9],
        ['حفل تخرج الدفعة 38', [['01.01', 1], ['03.02', 4], ['05.04', 10], ['04.05', 1, 900]], 'جامعة السلطان قابوس', 45],
        ['يوم الابتكار المصرفي', [['07.01', 1], ['04.02', 400], ['02.05', 1]], 'فندق الشيراتون', -12],
        ['مؤتمر سلاسل الإمداد', [['01.01', 2], ['03.01', 8], ['05.04', 8], ['02.09', 800]], 'فندق كمبينسكي', -40],
        ['ورشة التحول الرقمي', [['02.01', 1], ['03.03', 2], ['02.06', 1]], 'مقر عمانتل', 5],
        ['إطلاق وجهة الطيران الجديدة', [['06.02', 1, 7800], ['03.04', 1]], 'مطار مسقط الدولي', 60],
        ['منتدى الاستثمار الصناعي', [['07.02', 1], ['07.02x', 1], ['04.01', 500]], 'غرفة تجارة وصناعة عمان', 25],
        ['اليوم المفتوح للسلامة', [['02.01', 1], ['05.04', 10], ['04.05', 1, 350]], 'مخيم فهود', 70],
    ];

    /**
     * طولُ كلّ فعاليّةٍ وتجهيزُها — بترتيب EVENTS: [أيّامُ الفعاليّة، أيّامُ التجهيز قبلها].
     *
     * المعرضُ ثلاثةُ أيّامٍ ويُجهَّز قبله يومين أو ثلاثة، والورشةُ يومٌ
     * تُجهَّز صباحَه — فيُرى في التقويم الفرقُ بين هذا وذاك.
     */
    private const SPANS = [[2, 2], [3, 3], [1, 1], [1, 0], [1, 1], [2, 2], [1, 0], [1, 2], [2, 1], [1, 1]];

    /** مهامُّ كلّ مشروع: العنوان، وكم يومًا قبل الفعاليّة، والأولويّة */
    private const TASKS = [
        ['اعتماد التصاميم مع العميل', 21, 'high'],
        ['تأكيد قائمة الحضور', 10, 'normal'],
        ['حجز المعدّات', 7, 'normal'],
        ['طباعة البطاقات والمطبوعات', 3, 'normal'],
        ['تجهيز الموقع', 1, 'high'],
    ];

    /** المعدّات: الاسم · الفئة · العدد — أجهزةُ الكتالوج (03 و04.06) قطعًا بهويّاتها */
    private const EQUIPMENT = [
        ['جهاز مسح QR محمول', 'handheld', 20], ['جهاز تسجيل الدخول الذاتي', 'pos', 4], ['جهاز لوحي لتسجيل الدخول', 'tablet', 10],
        ['كاميرا تحليل الزوار بالذكاء الاصطناعي', 'camera', 3], ['طابعة البطاقات', 'badge_printer', 6],
        ['حاسوب محمول للتشغيل', 'laptop', 6], ['راوتر شبكة', 'router', 4],
    ];

    /** @var array<string, Product> الكتالوجُ بالرمز */
    private array $catalog = [];

    public static function fill(Business $business, User $owner, array $staff, Carbon $registered, array $catalog = []): void
    {
        DemoGuard::assertDemo($business);

        /*
         * الخدماتُ تقرأ الفاعلَ من الجلسة في سجلّ النشاط — فيُجلَس المالكُ
         * ما دام البناء، ويُعاد ما كان بعده (والطرفيّةُ لا جلسةَ لها أصلًا).
         */
        $previous = Auth::user();
        Auth::setUser($owner);

        try {
            $self = new self($business, $owner, $staff);
            $self->catalog = $catalog;
            $self->build($registered);
        } finally {
            $previous ? Auth::setUser($previous) : Auth::forgetUser();
        }
    }

    private function __construct(private readonly Business $business, private readonly User $owner, private readonly array $staff)
    {
        mt_srand($business->id * 104729);
    }

    private function build(Carbon $registered): void
    {
        $bid = (int) $this->business->id;
        $clients = $this->clients();
        $assets = $this->equipment();

        $template = Checklists::createTemplate($bid, ['name' => 'تجهيز موقع الفعاليّة', 'phase' => 'setup', 'items' => [
            ['title' => 'فحص الشبكة والإنترنت'], ['title' => 'تشغيل طابعات البطاقات'], ['title' => 'اختبار الشاشات والصوت'], ['title' => 'تجهيز منطقة الاستقبال'],
        ]], $this->owner);

        foreach (self::EVENTS as $i => [$title, $lines, $venue, $in]) {
            $client = $clients[$i % count($clients)];
            $issued = now()->subDays(max(3, 30 - $in))->toDateString();

            $eventDay = now()->addDays($in)->toDateString();
            $quotation = Quotations::create($bid, $client, [
                'title' => $title, 'attention_to' => $client->contact_person, 'issued_at' => $issued,
                'event_location' => $venue, 'event_starts_at' => $eventDay, 'event_ends_at' => $eventDay,
                'expected_attendees' => 150 + ($i * 137) % 900,
            ], array_map(fn ($l) => $this->line(...$l), $lines), $this->owner);

            /* العرضان الأخيران مسودّةٌ ومرسَلٌ ينتظر — والباقي مقبولٌ صار مشروعًا */
            if ($i === 9) {
                continue;
            }

            Quotations::send($quotation, $this->owner);

            if ($i === 8) {
                continue;
            }

            if ($i === 7) {
                Quotations::recordDecision($quotation->fresh(), Quotation::REJECTED, ['decided_by_name' => $client->contact_person, 'rejection_reason' => 'الميزانية لم تُعتمد هذا العام'], $this->owner);

                continue;
            }

            Quotations::recordDecision($quotation->fresh(), Quotation::ACCEPTED, ['decided_by_name' => $client->contact_person], $this->owner);
            $project = Project::where('business_id', $bid)->where('quotation_id', $quotation->id)->firstOrFail();

            $this->project($project, $client, $title, $venue, $in, $assets, $template, $i);
        }

        $this->history($clients);
        $this->whatsapp($clients);
        $this->budget();
    }

    /* ─────────────────────────── العملاء والخدمات ─────────────────────────── */

    /** @return list<Customer> */
    private function clients(): array
    {
        $bid = (int) $this->business->id;

        return collect(self::CLIENTS)->values()->map(fn ($c, $i) => Customer::create([
            'business_id' => $bid,
            'name' => $c[0],
            'customer_type' => $c[1],
            'legal_name' => $c[0],
            'contact_person' => $c[2],
            'phone' => '+968 9'.str_pad((string) (1100000 + $i * 73191), 7, '0', STR_PAD_LEFT),
            'contact_phone' => '+968 9'.str_pad((string) (2200000 + $i * 51713), 7, '0', STR_PAD_LEFT),
            'email' => 'events'.($i + 1).'@client.example.om',
            'contact_email' => 'contact'.($i + 1).'@client.example.om',
            'address' => 'مسقط — عُمان',
            'payment_terms_days' => $c[1] === 'جهة حكومية' ? 60 : 30,
            'allow_credit_sales' => true,
            'credit_limit' => $c[1] === 'جهة حكومية' ? 50000 : 20000,
        ]))->all();
    }

    /**
     * سطرُ عرضٍ من الكتالوج — بسعره المطبوع، أو بسعر النطاق لما لا سعرَ له.
     *
     * @return array<string, mixed>
     */
    private function line(string $code, float $quantity, ?float $price = null): array
    {
        $product = $this->catalog[$code] ?? null;

        return [
            'product_id' => $product?->id,
            'description' => $product?->name ?? $code,
            'unit' => DemoCatalog::unit($code),
            'quantity' => $quantity,
            'unit_price' => $price ?? (float) ($product?->price ?? 0),
            /* وسعرٌ غيرُ المطبوع يُقال سببُه — كما يُطالَب به البائع في الشاشة */
            'discount_reason' => $price !== null ? 'تسعير حسب نطاق العمل' : null,
        ];
    }

    /** @return array<string, list<\App\Models\Asset>> */
    private function equipment(): array
    {
        $bid = (int) $this->business->id;
        $out = [];

        foreach (self::EQUIPMENT as [$name, $category, $count]) {
            for ($n = 1; $n <= $count; $n++) {
                $out[$category][] = Assets::create($bid, ['name' => $name.' #'.$n, 'category' => $category], $this->owner);
            }
        }

        return $out;
    }

    /* ─────────────────────────── المشروع وما يتبعه ─────────────────────────── */

    private function project(Project $project, Customer $client, string $title, string $venue, int $in, array $assets, $template, int $i): void
    {
        $bid = (int) $this->business->id;
        $project->update(['name' => $title]);

        $coordinator = $this->staff[($i % max(1, count($this->staff) - 1)) + 1] ?? $this->owner;
        Projects::addMember($project, $coordinator->id, 'منسّق المشروع', $this->owner);
        /*
         * والمهامُّ بمواعيدها قبل الفعاليّة وبمسؤوليها: ما فات موعدُه بأيّامٍ
         * أُنجز، وواحدةٌ في كلّ مشروعٍ ثانٍ تبقى متأخّرة — فتُرى شاشةُ المهامّ
         * كما تكون في شركةٍ حقيقيّة لا صفًّا من العناوين بلا موعد.
         */
        $today = now()->startOfDay();
        foreach (self::TASKS as $k => [$task, $before, $priority]) {
            $due = $today->copy()->addDays($in - $before);
            $made = Projects::addTask($project, [
                'title' => $task,
                'due_at' => $due->toDateString(),
                'priority' => $priority,
                'assignee_id' => ($k % 2 === 0 ? $coordinator : ($this->staff[($i + $k) % max(1, count($this->staff))] ?? $this->owner))->id,
            ], $this->owner);
            if ($due->lt($today->copy()->subDays(1)) && ! ($i % 2 === 1 && $k === 1)) {
                Projects::updateTask($made, ['status' => ProjectTask::DONE], $this->owner);
            }
        }

        /* والمتطلّباتُ تُثبَّت كما يثبّتها المنسّق قبل التنفيذ — فلا تُعرض «لم تُثبّت» */
        foreach ([['title' => 'طابعات بطاقات وحبر', 'kind' => 'equipment'], ['title' => 'فريق تسجيل واستقبال', 'kind' => 'staff']] as $req) {
            try {
                Projects::addRequirement($project->fresh(), $req, $this->owner);
            } catch (\RuntimeException) {
                // نوعٌ لا يعرفه المشروع — يُترك ولا يسقط البناء
            }
        }
        Projects::baseline($project->fresh(), $this->owner);

        $past = $in < 0;
        if (! $past && $i % 3 !== 2) {
            Projects::transition($project->fresh(), Project::IN_PROGRESS, null, $this->owner);
        }

        $day = now()->addDays($in)->startOfDay();
        [$length, $prep] = self::SPANS[$i] ?? [1, 0];
        $last = $day->copy()->addDays($length - 1);
        $op = EventOperations::create($project->fresh(), [
            'name' => $title, 'venue_name' => $venue,
            'setup_starts_at' => $day->copy()->subDays($prep)->setTime(6, 0)->format('Y-m-d H:i'),
            'event_starts_at' => $day->copy()->setTime(9, 0)->format('Y-m-d H:i'),
            'event_ends_at' => $last->copy()->setTime(17, 0)->format('Y-m-d H:i'),
            'teardown_starts_at' => $last->copy()->setTime(17, 0)->format('Y-m-d H:i'),
            /* والمعرضُ الطويلُ يُفكّ صباحَ اليوم التالي، والقصيرُ ليلتَه */
            'teardown_ends_at' => ($length > 1 ? $last->copy()->addDay()->setTime(12, 0) : $last->copy()->setTime(20, 0))->format('Y-m-d H:i'),
            'operations_lead_id' => $this->owner->id,
        ], $this->owner);

        foreach ([['الافتتاح والكلمة الرئيسية', 9, 10], ['الجلسة الأولى', 10, 12], ['استراحة غداء', 12, 13], ['الجلسة الثانية', 13, 16]] as [$item, $from, $to]) {
            Schedule::add($op, ['title' => $item, 'starts_at' => $day->copy()->setTime($from, 0)->format('Y-m-d H:i'), 'ends_at' => $day->copy()->setTime($to, 0)->format('Y-m-d H:i')], $this->owner);
        }

        /* والمعدّاتُ تُحجز للفعاليّات القادمة وحدَها — ما مضى أُعيد إلى المخزن */
        if (! $past) {
            foreach (['handheld' => 3, 'badge_printer' => 1, 'tablet' => 2] as $category => $qty) {
                $req = Requirements::add($op, ['kind' => 'asset_category', 'asset_category' => $category, 'required_quantity' => $qty], $this->owner);
                foreach (array_slice($assets[$category] ?? [], ($i * $qty) % max(1, count($assets[$category] ?? [1])), $qty) as $asset) {
                    try {
                        Reservations::reserve($op, $asset, ['requirement_id' => $req->id], $this->owner);
                    } catch (\RuntimeException) {
                        // قطعةٌ محجوزةٌ لفعاليّةٍ تتقاطع مواعيدُها — يبقى المتطلَّب ناقصًا كما يقع في الحقيقة
                    }
                }
            }
        }

        foreach (array_slice($this->staff, 1, 3) as $k => $member) {
            Crew::assign($op, ['user_id' => $member->id, 'role' => ['technical_support', 'registration_lead', 'printer_operator'][$k] ?? 'technical_support'], $this->owner);
        }

        Checklists::attach($op, $template, $this->owner);

        if ($i === 4) {
            Incidents::report($op, ['title' => 'تعطّل طابعة بطاقات عند الافتتاح', 'category' => 'equipment', 'severity' => 'medium'], $this->owner);
        }

        /*
         * ونطاقُ العمل — مسارُ المشروع يبدأ منه: أكثرُها وقّعه العميل، وواحدٌ
         * من كلّ أربعةٍ ما زال عنده ينتظر التوقيع. والدفعةُ المقدّمة (٥٠٪) بعد
         * التوقيع وحدَه، من بابها (`ProjectBilling`) — فاتورةُ عميلٍ حقيقيّة.
         */
        $signed = $i % 4 !== 3;
        $this->scope($project, $client, $signed);

        if ($signed) {
            $invoice = ProjectBilling::createDeposit($project->fresh(), ['method' => ProjectBilling::PERCENT, 'percent' => 50, 'issue' => true], $this->owner);

            /* والتحصيلُ متفاوت: ما مضى سُدّد، ونصفُ القادم سدّد جزءًا، والباقي ينتظر */
            $due = (float) $invoice->fresh()->total;
            $paid = match (true) {
                $past => $due,
                $i % 2 === 0 => round($due * 0.4, 3),
                default => 0.0,
            };
            if ($paid > 0) {
                CustomerPayments::record($bid, $client, $paid, ['method' => 'تحويل بنكي', 'occurred_at' => now()->subDays(3)->toDateString()], [$invoice->id => $paid], $this->owner->id);
            }
            Project::whereKey($project->id)->update(['workflow_stage' => ProjectWorkflow::EXECUTION]);
        }

        if ($past) {
            $item = $project->items()->first();
            if ($item) {
                $package = DeliveryPackages::create($bid, $client, ['project_id' => $project->id], $this->owner);
                DeliveryPackages::addItem($package, ['kind' => 'item', 'project_item_id' => $item->id, 'quantity' => 1], null, $this->owner);
            }
        }
    }

    /* ─────────────────────────── ما مضى ─────────────────────────── */

    /**
     * فعاليّاتُ السنة الماضية — عرضٌ قُبل، فمشروعٌ، ففاتورةٌ في شهرها سُدّدت.
     *
     * بلا هذا تقع الإيراداتُ كلُّها في الشهر الجاري، فيرسم «الملخص» و«الإدارة»
     * خطًّا مسطّحًا بقمّةٍ واحدة لا يشبه شركةً تعمل منذ سنة.
     */
    private function history(array $clients): void
    {
        $bid = (int) $this->business->id;
        $titles = ['ملتقى الموارد البشرية', 'مؤتمر الأمن السيبراني', 'معرض التعليم العالي', 'حفل اليوم الوطني', 'منتدى الشباب',
            'مؤتمر الصحة الرقمية', 'معرض العقار', 'ملتقى المرأة القيادية', 'قمة الذكاء الاصطناعي', 'مهرجان الطفل', 'منتدى الطاقة المتجددة'];

        $workshops = ['ورشة', 'لقاء', 'جلسة حوارية', 'دورة تدريبية'];

        foreach ($titles as $k => $title) {
            $month = now()->startOfMonth()->subMonths($k + 1);

            /* فعاليّتان في الشهر: كبرى وصغرى — والأرقامُ تتفاوت كما تتفاوت الأشهر */
            foreach ([[$title, true], [$workshops[$k % 4].' '.$title, false]] as $n => [$name, $major]) {
                $client = $clients[($k * 5 + $n * 7) % count($clients)];
                $day = $month->copy()->addDays($major ? 8 + mt_rand(0, 6) : 18 + mt_rand(0, 6));
                $attendees = $major ? mt_rand(300, 1800) : mt_rand(40, 220);

                /* الكبرى بباقة التشغيل المتكاملة وأيّامها وبطاقاتها، والصغرى بالأساسيّة واستبيانها */
                $days = mt_rand(1, 3);
                $lines = $major
                    ? array_values(array_filter([
                        $this->line('07.03', 1),
                        $days > 1 ? $this->line('07.03x', $days - 1) : null,
                        $this->line(mt_rand(0, 1) ? '04.02' : '04.01', $attendees),
                        mt_rand(0, 2) === 0 ? $this->line('03.04', $days) : null,
                    ]))
                    : [$this->line('07.01', 1), $this->line('02.06', 1)];

                $this->pastEvent($client, $name, $day, $attendees, $lines, settled: $k >= 1 || $n === 1);
            }
        }
    }

    /** فعاليّةٌ مضت — من العرض إلى الإقفال، من أبواب النظام كلّها */
    private function pastEvent(Customer $client, string $title, Carbon $day, int $attendees, array $lines, bool $settled): void
    {
        $bid = (int) $this->business->id;

        $quotation = Quotations::create($bid, $client, [
            'title' => $title, 'attention_to' => $client->contact_person,
            'issued_at' => $day->copy()->subDays(25)->toDateString(),
            'event_location' => 'مركز عمان للمؤتمرات والمعارض',
            'event_starts_at' => $day->toDateString(), 'event_ends_at' => $day->toDateString(),
            'expected_attendees' => $attendees,
        ], $lines, $this->owner);

        Quotations::send($quotation, $this->owner);
        Quotations::recordDecision($quotation->fresh(), Quotation::ACCEPTED, ['decided_by_name' => $client->contact_person], $this->owner);
        $project = Project::where('business_id', $bid)->where('quotation_id', $quotation->id)->firstOrFail();
        $project->update(['name' => $title]);

        /* المسارُ من أوّله: نطاقٌ يوقّعه العميل، فدفعةٌ مقدّمة ٥٠٪ تُصدَر وتُحصَّل */
        $this->scope($project, $client, true);
        $deposit = ProjectBilling::createDeposit($project->fresh(), ['method' => ProjectBilling::PERCENT, 'percent' => 50, 'issue' => true], $this->owner);
        $deposit->forceFill(['issued_at' => $day->copy()->subDays(10)->toDateString()])->save();
        $due = (float) $deposit->fresh()->total;
        CustomerPayments::record($bid, $client, $due, ['method' => 'تحويل بنكي', 'occurred_at' => $day->copy()->subDays(5)->min(now())->toDateString()], [$deposit->id => $due], $this->owner->id);

        /*
         * ثمّ التنفيذُ فالتسليمُ فالفاتورةُ النهائيّة فالإقفال — شروطُ `Projects::close`
         * نفسُها. وما يمنعه يُبقي المشروعَ قائمًا كما يقع.
         */
        try {
            $this->completeOperation($project, $title, $day);

            $package = DeliveryPackages::create($bid, $client, ['project_id' => $project->id], $this->owner);
            foreach ($project->items()->get() as $item) {
                DeliveryPackages::addItem($package, ['kind' => 'item', 'project_item_id' => $item->id, 'quantity' => (float) $item->quantity], null, $this->owner);
            }
            DeliveryPackages::ready($package->fresh(), $this->owner);
            DeliveryPackages::send($package->fresh(), $this->owner);
            DeliveryPackages::recordDecision($package->fresh(), 'accepted', ['decided_by_name' => $client->contact_person], $this->owner);

            $final = ProjectBilling::createFinal($project->fresh(), ['issue' => true], $this->owner);
            $final->forceFill(['issued_at' => $day->copy()->addDays(2)->toDateString()])->save();

            /* والأحدثُ لم يُسدَّد كلُّه بعد — ذممٌ قائمة كما في كلّ شركة */
            $rest = (float) $final->fresh()->total;
            $paid = $settled ? $rest : round($rest * 0.5, 3);
            CustomerPayments::record($bid, $client, $paid, ['method' => 'تحويل بنكي', 'occurred_at' => $day->copy()->addDays(20)->min(now())->toDateString()], [$final->id => $paid], $this->owner->id);

            if ($settled) {
                Projects::close($project->fresh(), $this->owner);
            } else {
                /* بلغ آخرَ المسار وينتظر الإقفال — والمؤشّرُ حيث يقف الفريق */
                Project::whereKey($project->id)->update(['workflow_stage' => ProjectWorkflow::CLOSING]);
            }
        } catch (\RuntimeException) {
            // يبقى المشروعُ قائمًا بما لم يكتمل
        }
    }

    /**
     * نطاقُ العمل من بابه — يُكتب ويُجهَّز ويُرسَل، ويوقّعه العميل أو ينتظر.
     */
    private function scope(Project $project, Customer $client, bool $sign): void
    {
        $scope = ScopesOfWork::start($project->fresh(), $this->owner);
        $scope = ScopesOfWork::update($scope, [
            'inclusions' => "تجهيز الأجهزة والشبكة قبل الفعالية بيوم\nفريق تشغيل ودعم فنّي طوال الفعالية\nتقرير الحضور بعد الختام",
            'exclusions' => 'الإنترنت والكهرباء في الموقع',
            'company_responsibilities' => "توريد الأجهزة وتشغيلها\nطباعة البطاقات",
            'client_responsibilities' => "قائمة المدعوين قبل أسبوع\nتأمين نقاط الكهرباء",
        ], $this->owner);
        $scope = ScopesOfWork::send(ScopesOfWork::markReady($scope, $this->owner), $this->owner);

        if ($sign) {
            ScopesOfWork::decide($scope, 'accepted', [
                'name' => (string) $client->contact_person, 'title' => 'مدير الفعاليات',
                'consent' => ScopesOfWork::consent((string) $this->business->name),
            ], ['ip' => '10.0.0.1', 'user_agent' => 'demo'], $scope->content_hash);
        }
    }

    /**
     * فعاليّةٌ مضت تُتمّ من بابها — والجاهزيّةُ تُتجاوز باسم المالك وسببِه،
     * كما يفعل من يُدخل فعاليّةً جرت قبل أن يُسجَّل فريقُها ومعدّاتُها.
     */
    private function completeOperation(Project $project, string $title, Carbon $day): void
    {
        $op = EventOperations::create($project->fresh(), [
            'name' => $title, 'venue_name' => 'مركز عمان للمؤتمرات والمعارض',
            'event_starts_at' => $day->copy()->setTime(9, 0)->format('Y-m-d H:i'),
            'event_ends_at' => $day->copy()->setTime(17, 0)->format('Y-m-d H:i'),
        ], $this->owner);

        $why = 'فعاليّةٌ مضت — أُدخلت بعد وقوعها';
        foreach ([EventOperation::PREPARING, EventOperation::READY, EventOperation::SETUP, EventOperation::LIVE, EventOperation::TEARDOWN, EventOperation::COMPLETED] as $to) {
            EventOperations::transition($op->fresh(), $to, $this->owner, null, $why);
        }
    }

    /* ─────────────────────────── واتساب ─────────────────────────── */

    private function whatsapp(array $clients): void
    {
        $bid = (int) $this->business->id;

        $threads = [
            [$clients[0], [
                ['outbound', 'عرض سعر — مؤتمر الطاقة المستدامة 2026', 60 * 50, 'read', 'template'],
                ['inbound', 'السلام عليكم، استلمنا العرض شكرًا', 60 * 49, 'read', 'text'],
                ['inbound', 'هل السعر يشمل شاشات LED للقاعة الثانية؟', 55, 'read', 'text'],
                ['outbound', 'وعليكم السلام، نعم يشمل شاشتين LED مع التشغيل والفنيين.', 40, 'read', 'text'],
                ['inbound', 'ممتاز، متى نقدر نعتمد؟', 4, 'delivered', 'text'],
            ], 1],
            [$clients[4], [
                ['inbound', 'Hi, can you send the final invoice for the banking day?', 60 * 30, 'read', 'text'],
                ['outbound', 'فاتورة — يوم الابتكار المصرفي', 60 * 29, 'delivered', 'template'],
                ['inbound', 'Received, thank you 🙏', 60 * 28, 'read', 'text'],
            ], 0],
            [null, [
                ['inbound', 'مرحبا، أبغى أستفسر عن تنظيم حفل تخرج', 9, 'delivered', 'text'],
                ['inbound', 'كم التكلفة التقريبية لـ 300 شخص؟', 8, 'delivered', 'text'],
            ], 2],
            [$clients[3], [
                ['outbound', 'عرض سعر — حفل تخرج الدفعة 38', 60 * 20, 'read', 'template'],
                ['inbound', 'نحتاج نضيف تصوير بالدرون، ممكن؟', 60 * 3, 'read', 'text'],
                ['outbound', 'أكيد، نرسل لكم أمر تغيير اليوم إن شاء الله.', 60 * 2, 'sent', 'text'],
            ], 0],
        ];

        foreach ($threads as $n => [$client, $messages, $unread]) {
            $waId = $client ? (PhoneNumber::normalize($client->phone) ?? '9689100000'.$n) : '96895551234';
            $conversation = WhatsAppConversation::create([
                'business_id' => $bid, 'customer_id' => $client?->id, 'wa_id' => $waId,
                'contact_name' => $client?->contact_person,
            ]);

            foreach ($messages as $k => [$direction, $body, $minutesAgo, $status, $type]) {
                $at = now()->subMinutes($minutesAgo);
                $row = WhatsAppMessage::create([
                    'business_id' => $bid, 'conversation_id' => $conversation->id, 'direction' => $direction,
                    'type' => $type, 'body' => $body, 'status' => $status, 'occurred_at' => $at,
                    'provider_message_id' => 'demo.'.$bid.'.'.$n.'.'.$k,
                    'sent_by' => $direction === 'outbound' ? $this->owner->id : null,
                    'sent_by_name' => $direction === 'outbound' ? $this->owner->name : null,
                    'delivered_at' => in_array($status, ['delivered', 'read'], true) ? $at : null,
                    'read_at' => $status === 'read' ? $at : null,
                ]);

                $conversation->forceFill([
                    'last_message_at' => $at, 'last_preview' => mb_substr(Inbox::preview($row), 0, 190),
                    'last_direction' => $direction, 'last_status' => $status,
                    'last_inbound_at' => $direction === 'inbound' ? $at : $conversation->last_inbound_at,
                ]);
            }

            $conversation->forceFill(['unread_count' => $unread])->save();
        }
    }

    /* ─────────────────────────── الموازنة ─────────────────────────── */

    private function budget(): void
    {
        $bid = (int) $this->business->id;
        $revenue = \App\Models\Account::where('business_id', $bid)->where('code', '4100')->value('id');

        if (! $revenue) {
            return;
        }

        Budgeting::create($bid, [
            'fiscal_year' => (int) now()->year,
            'name' => 'موازنة '.now()->year,
            'lines' => collect(range(1, 12))->map(fn ($m) => ['account_id' => $revenue, 'month' => $m, 'planned_amount' => 18000 + $m * 900])->all(),
        ], $this->owner);
    }
}
