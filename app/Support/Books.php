<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\ExpenseType;
use App\Models\JournalEntry;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * الجسر بين ما يقع في المحلّ وما يُكتب في دفتر الأستاذ.
 *
 * كان في النظام دفتران لا يلتقيان:
 *
 *   `transactions` — دفتر صندوقٍ بسيط: دخلٌ ومصروف. تكتب فيه نقطةُ البيع
 *   وشاشةُ المصروفات، وتقرأ منه التقارير والأرباح.
 *
 *   `journal_entries` — قيدٌ مزدوج بشجرة حسابات وميزان مراجعة. تكتب فيه
 *   سنداتُ الموردين والرواتب والأصول الثابتة وتسويات المخزون والقيود اليدوية.
 *
 * فالمبيعات لم تكن تصل إلى الثاني أبدًا. ونتيجتُه على متجرٍ حقيقيّ: شجرةٌ
 * فيها «إيراد المبيعات» برصيد صفر بينما باع صاحبُها، ومخزونٌ يزيد بالشراء
 * ولا ينقص بالبيع، ورواتبُ وإهلاكٌ بلا إيرادٍ يقابلها. وميزانُ المراجعة
 * يقول «متوازن» — وهو متوازنٌ فعلًا، لأنّ كلّ قيدٍ كُتب متوازن — فيطمئنّ
 * التاجر إلى دفترٍ ليس فيه عملُه.
 *
 * وأثبتُّه على الإنتاج قبل أن أكتب هذا: متجرٌ حقيقيّ فيه ١٠٦ ر.ع مبيعاتٍ في
 * دفتر الصندوق، و«إيراد المبيعات» في شجرته صفر. والمتجر التجريبيّ وحده
 * كانت قيودُه كاملة — لأنّ البذرة تكتب الاثنين — فكان العرض يُظهر ميزةً لا
 * وجود لها عند من يشتري.
 *
 * ولا يُعدّ المبلغ مرّتين: التقارير والأرباح تُقرأ من `transactions`
 * و`expenses` كما كانت، والقيد هنا للدفتر المحاسبيّ وحده.
 */
class Books
{
    /**
     * مصدرُ القيود المكتوبة من هنا — به تُعرَف.
     *
     * وقيدا البيعة (`recordSale`) وعكسُهما خرجا مع «المبيعات»: لا طلبَ يُنشأ
     * ليُقيَّد. وما قُيّد من الطلبات القديمة باقٍ في الدفتر بمصدره (`SALE` و
     * `SALE_COST`) — لا يُعكس ولا يُمحى بخروج الشاشة.
     */
    public const SALE = 'مبيعات';

    public const SALE_COST = 'تكلفة مبيعات';

    public const EXPENSE = 'مصروف';

    /* ------------------------------ المصروف ------------------------------ */

    /**
     * قيدُ المصروف — يوم خروج المال لا يوم تسجيل الورقة.
     *
     * فاتورةٌ سُجّلت اليوم وتُدفع بعد أسبوع ليست نقدًا خرج من الدرج، ولذلك
     * يُنادى هذا عند السداد لا عند التسجيل.
     */
    /**
     * مصروفٌ سُدِّد يدخل الدفترَ من بابٍ واحد: حركةٌ في المعاملات ثمّ قيدٌ.
     *
     * كان هذا في متحكّم المصروفات؛ خرج إلى هنا لمّا صار الاستيرادُ التاريخيّ
     * (المرحلة 9) يحتاجه — فبابٌ واحد لا نسختان تفترقان.
     */
    public static function postExpense(Expense $expense): void
    {
        if ($expense->transaction_id === null) {
            $transaction = Transaction::create([
                'business_id' => $expense->business_id,
                'reference' => Transaction::nextReference($expense->business_id),
                'description' => $expense->type.(($expense->description ?? '') !== '' ? ' — '.$expense->description : ''),
                'method' => $expense->method,
                'type' => 'مصروف',
                'amount' => $expense->amount,
                'employee_name' => $expense->employee_name,
                'occurred_at' => $expense->spent_at,
            ]);

            $expense->update(['transaction_id' => $transaction->id]);
        }

        self::recordExpense($expense->fresh());
    }

    public static function recordExpense(Expense $expense): void
    {
        if (self::hasEntry($expense)) {
            return;
        }

        $amount = round((float) $expense->amount, 3);
        if ($amount <= 0) {
            return;
        }

        Ledger::post(
            $expense->business_id,
            __('مصروف: ').$expense->type,
            [
                ['account' => self::expenseAccount($expense->type, $expense->business_id), 'debit' => $amount, 'memo' => $expense->description],
                ['account' => self::payingAccount($expense->method), 'credit' => $amount],
            ],
            Carbon::parse($expense->spent_at ?? now()),
            self::EXPENSE,
            null,
            null,
            $expense,
        );
    }

    /** وحذفُ المصروف كإلغاء البيعة: عكسٌ لا محو — القاعدة واحدة في الدفتر */
    public static function unpostExpense(Expense $expense, ?int $userId = null, ?string $reason = null): void
    {
        self::liveEntriesFor($expense)->each(
            fn (JournalEntry $e) => Ledger::reverse($e, null, $userId, $reason)
        );
    }

    /**
     * دليلُ الاسم إلى الحساب — لمن لم يختر حسابًا لنوعه.
     *
     * قائمةٌ في الكود لا تكفي وحدها: النوع يكتبه التاجر بيده، ومن كتب
     * «كهرباء» بدل «كهرباء وماء» يسقط منها. فهذه للأنواع الافتراضية التي
     * يبدأ بها كلّ متجر، والاختيار المحفوظ على النوع يسبقها.
     *
     * والرواتب ليست منها عمدًا: مسيرةُ الرواتب تُرحّل إلى «الرواتب والأجور»
     * بنفسها، فربطُ نوعٍ مكتوبٍ باليد بالحساب نفسه يجعل راتبًا واحدًا
     * يُقيَّد مرّتين. ومن كتبه هنا يريد مصروفًا نقديًّا لا مسيرة.
     */
    public const TYPE_ACCOUNTS = [
        'إيجار' => 'rent',
        'كهرباء وماء' => 'utilities',
        'تسويق' => 'marketing',
        'صيانة' => 'maintenance',
        'نقل وتوصيل' => 'transport',
        'مواد خام' => 'direct_purchases',
    ];

    /**
     * الحسابات التي يجوز للتاجر أن يربط نوع مصروفٍ بها — ولا شيء سواها.
     *
     * قائمةٌ مغلقة لا شجرةٌ مفتوحة: من يربط مصروفًا بحساب «الصندوق» أو
     * «إيراد المبيعات» يقلب قيدَه رأسًا على عقب، والدفتر يتوازن ويكذب.
     * والرواتب ليست منها — مسيرةُ الرواتب تُرحّل بنفسها.
     *
     * والاسم يُقرأ من الشجرة الافتراضية لا يُكتب هنا مرّتين.
     */
    public const EXPENSE_ACCOUNTS = [
        'rent', 'utilities', 'marketing', 'maintenance',
        'transport', 'direct_purchases', 'other_expenses',
    ];

    /**
     * خياراتُ الحساب كما تُعرض في الشاشة — مفتاحٌ واسمٌ عربيّ.
     *
     * @return list<array{key: string, label: string}>
     */
    public static function expenseAccountOptions(): array
    {
        $names = [];

        foreach (Ledger::DEFAULT_CHART as [, , , , , $children]) {
            foreach ($children as [, $name, , , $key]) {
                $names[$key] = $name;
            }
        }

        return array_map(
            fn (string $key) => ['key' => $key, 'label' => __($names[$key] ?? $key)],
            self::EXPENSE_ACCOUNTS,
        );
    }

    /**
     * حسابُ المصروف — اختيارُ التاجر أوّلًا، ثمّ اسمُ النوع، ثمّ «أخرى».
     *
     * كان يقرأ الاسم وحده ويطابقه بسطرٍ واحد، فيسقط كلّ ما عدا الإيجار في
     * «مصروفات أخرى»: دفترٌ يعرف أنّ المال خرج ولا يعرف من أيّ باب.
     *
     * والمعرّف يصل ليُسأل جدولُ الأنواع عن اختيار صاحبه — ونوعٌ لا يُعرف
     * صاحبُه يُقرأ باسمه وحده، فلا يسقط الترحيل لأجل معرّفٍ غائب.
     */
    public static function expenseAccount(?string $type, ?int $businessId = null): string
    {
        $name = trim((string) $type);

        if ($businessId !== null && $name !== '') {
            $chosen = ExpenseType::where('business_id', $businessId)
                ->where('name', $name)->value('account_key');

            if (in_array($chosen, self::EXPENSE_ACCOUNTS, true)) {
                return $chosen;
            }
        }

        return self::TYPE_ACCOUNTS[$name] ?? 'other_expenses';
    }

    /** من أين خرج المال — والافتراض الصندوق */
    public static function payingAccount(?string $method): string
    {
        return in_array(trim((string) $method), ['تحويل', 'بطاقة', 'شيك', 'بنك'], true) ? 'bank' : 'cash';
    }

    /* ============================ الحركة المالية ============================ */

    /** الصندوق والبنك — الوجهتان الوحيدتان اللتان يُسأل عنهما التاجر */
    public const CASH = 'cash';

    public const BANK = 'bank';

    /**
     * وصفة كل حركة يدوية.
     *
     * `debit` و`credit` إمّا مفتاحٌ نظاميّ في الشجرة، وإمّا `'@side'` أي
     * «الجهة التي اختارها التاجر» — الصندوق أو البنك — وإمّا `'@expense'` أي
     * «حساب نوع المصروف» (انظر `expenseAccount`).
     *
     * `direction` اتّجاه المال كما يُكتب في `transactions.type`: «دخل» لِما
     * يدخل، و«مصروف» لِما يخرج، و«تحويل» لِما لا يدخل ولا يخرج بل ينتقل.
     *
     * `asks` سؤال الشاشة عن الجهة — و`null` يعني أنّ النوع نفسه يحدّدها.
     *
     * والتاجر لا يُسأل عن مدينٍ ودائن. يُسأل: **ماذا حدث؟** — والوصفة تترجم
     * جوابه إلى قيدٍ صحيح، فلا يعرف عن الحسابات شيئًا ويصحّ دفترُه.
     */
    public const MOVEMENTS = [
        'expense' => [
            'label' => 'مصروف',
            'hint' => 'مالٌ خرج مقابل شيءٍ للشركة — إيجار، كهرباء، صيانة',
            'direction' => 'مصروف',
            'asks' => 'من أين خرج المال؟',
            'debit' => '@expense',
            'credit' => '@side',
        ],
        'other_income' => [
            'label' => 'دخل آخر (غير الفواتير)',
            'hint' => 'مالٌ دخل من غير فواتير العملاء — تعويض، إيجار محلٍّ تملكه، فرق عملة',
            'direction' => 'دخل',
            'asks' => 'أين دخل المال؟',
            'debit' => '@side',
            'credit' => 'other_income',
        ],
        'owner_deposit' => [
            'label' => 'إيداع نقدي من المالك',
            'hint' => 'مالٌ وضعه المالك في الشركة — ليس بيعًا ولا دخلًا',
            'direction' => 'دخل',
            'asks' => 'أين وُضع المال؟',
            'debit' => '@side',
            'credit' => 'capital',
        ],
        'owner_withdrawal' => [
            'label' => 'سحب مال للمالك',
            'hint' => 'مالٌ أخذه المالك لنفسه — لا يُنقص ربح الشركة',
            'direction' => 'مصروف',
            'asks' => 'من أين أُخذ المال؟',
            'debit' => 'drawings',
            'credit' => '@side',
        ],
        'cash_to_bank' => [
            'label' => 'تحويل من الصندوق إلى البنك',
            'hint' => 'المال ينتقل ولا يدخل ولا يخرج — لا يمسّ الربح',
            'direction' => 'تحويل',
            'asks' => null,
            'debit' => 'bank',
            'credit' => 'cash',
        ],
        'bank_to_cash' => [
            'label' => 'تحويل من البنك إلى الصندوق',
            'hint' => 'المال ينتقل ولا يدخل ولا يخرج — لا يمسّ الربح',
            'direction' => 'تحويل',
            'asks' => null,
            'debit' => 'cash',
            'credit' => 'bank',
        ],
    ];

    /**
     * حركاتٌ يكتبها النظام عن مستنداتها — لا تُسجَّل من شاشة المالية.
     *
     * البيع تكتبه نقطة البيع. وتسجيلُه يدويًّا يعني الحدث مرّتين في الدفتر.
     */
    public const AUTOMATIC = [
        // حركاتُ الطلبات القديمة باقيةٌ في الدفتر بنوعها `sale` — تُقرأ باسمٍ يقول إنّها من النظام القديم
        Transaction::SALE => 'طلب قديم',
    ];

    /**
     * الوسيلة تتبع الجهة.
     *
     * كشف المطابقة البنكيّ يُبنى من الوسيلة لا من الحساب، فحركةٌ بنكية
     * بوسيلة «نقدي» تغيب عنه — ويظهر سطر البنك بلا ما يقابله فيُقرأ فرقًا.
     */
    public static function methodFor(string $side): string
    {
        return $side === self::BANK ? 'تحويل بنكي' : 'نقدي';
    }

    /** الوسيلة → الجهة: ما ليس نقدًا مرّ بالبنك */
    public static function sideForMethod(?string $method): string
    {
        return ($method ?? 'نقدي') === 'نقدي' ? self::CASH : self::BANK;
    }

    /** أنواع الحركة التي تُسجَّل يدويًّا — والبيع ليس منها */
    public static function manualKinds(): array
    {
        return array_keys(self::MOVEMENTS);
    }

    /**
     * ما تعرضه الشاشة: النوع وسؤاله وشرحه — بلا حسابٍ ولا طرف.
     *
     * الوصفة المحاسبية تبقى هنا ولا تُرسل: الواجهة التي تعرف الحسابات تُغري
     * بأن تختار منها، وأوّلُ اختيارٍ يكسر القاعدة التي بُني عليها هذا الباب.
     *
     * @return list<array{value: string, label: string, hint: string, asks: string|null, direction: string}>
     */
    public static function movementOptions(): array
    {
        $out = [];

        foreach (self::MOVEMENTS as $key => $m) {
            $out[] = [
                'value' => $key,
                'label' => __($m['label']),
                'hint' => __($m['hint']),
                'asks' => $m['asks'] ? __($m['asks']) : null,
                'direction' => $m['direction'],
            ];
        }

        return $out;
    }

    /** اسم النوع كما يُقرأ — والقديم الذي لا نوع له يبقى «حركة» */
    public static function label(?string $kind): string
    {
        if (isset(self::MOVEMENTS[$kind])) {
            return __(self::MOVEMENTS[$kind]['label']);
        }

        return isset(self::AUTOMATIC[$kind]) ? __(self::AUTOMATIC[$kind]) : __('حركة');
    }

    /**
     * حركةٌ يدوية من شاشة المالية: صفٌّ في الحركة وقيدٌ في الدفتر معًا.
     *
     * وكان الباب القديم يكتب الصفّ ولا يكتب القيد — أي حركةً ماليةً لا
     * يقابلها شيءٌ في دفتر الأستاذ، وهو بالضبط ما تمنعه المحاسبة المزدوجة.
     *
     * `$data`: kind, amount, side (cash|bank)، واختياريًّا description،
     * occurred_at، branch_id، client_uuid، expense_type.
     *
     * @throws \RuntimeException إن اختلّ الترحيل — والمعاملة كلّها تسقط معه
     */
    public static function recordMovement(int $businessId, array $data, ?int $userId = null, ?string $employee = null): Transaction
    {
        $kind = $data['kind'] ?? '';
        $recipe = self::MOVEMENTS[$kind] ?? throw new \RuntimeException(__('نوع حركةٍ غير معروف'));

        $amount = round((float) ($data['amount'] ?? 0), 3);

        // حركةٌ بصفر لا تعني شيئًا: صفٌّ في الدفتر لا يغيّر رصيدًا ولا يُصحَّح
        if ($amount < 0.001) {
            throw new \RuntimeException(__('المبلغ يجب أن يكون أكبر من صفر'));
        }

        $side = $recipe['asks'] === null ? null : ($data['side'] ?? self::CASH);
        $occurredAt = ! empty($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now();

        // الوسيلة تتبع الجهة، والتحويل يُنسب إلى البنك: هو طرفُه المُطابَق
        $method = self::methodFor($side ?? self::BANK);

        $description = trim((string) ($data['description'] ?? '')) ?: self::label($kind);

        /*
         * والشجرة تُستدرك قبل الترحيل.
         *
         * متجرٌ بُنيت شجرتُه قبل أن يدخلها «مسحوبات المالك» لا حساب له، فأوّلُ
         * سحبٍ يُسجّله صاحبُه يُردّ بـ«حسابٌ غير موجود في الشجرة: drawings» —
         * رسالةٌ لا يفهمها من سجّل، وليست خطأه.
         */
        Ledger::ensureSystemAccounts($businessId);

        return DB::transaction(function () use (
            $businessId, $kind, $recipe, $amount, $side, $occurredAt, $method,
            $description, $data, $userId, $employee
        ) {
            /*
             * التكرار يُمنع بالمعرّف الذي يولّده المتصفّح.
             *
             * ضغطتان على «حفظ»، أو إعادةُ إرسالٍ بعد انقطاع، تكتبان الحركة
             * مرّتين — والمال لا يخرج مرّتين. والفحص داخل المعاملة لا قبلها:
             * طلبان متزامنان يمرّان من فحصٍ خارجها كلاهما.
             */
            if (! empty($data['client_uuid'])) {
                $existing = Transaction::where('business_id', $businessId)
                    ->where('client_uuid', $data['client_uuid'])->lockForUpdate()->first();

                if ($existing) {
                    return $existing;
                }
            }

            $transaction = Transaction::create([
                'business_id' => $businessId,
                'branch_id' => $data['branch_id'] ?? null,
                'reference' => Transaction::nextReference($businessId),
                'client_uuid' => $data['client_uuid'] ?? null,
                'description' => $description,
                'method' => $method,
                'type' => $recipe['direction'],
                'kind' => $kind,
                'amount' => $amount,
                'employee_name' => $employee,
                'occurred_at' => $occurredAt,
            ]);

            /*
             * المصروف اليدويّ يظهر في شاشة المصروفات أيضًا.
             *
             * وإلا صار للمصروف بابان: ما يُسجَّل هنا لا يُرى هناك، والتاجر
             * يقرأ «مصروفات الشهر» ناقصةً بلا أن يقول شيءٌ لماذا.
             */
            if ($kind === 'expense') {
                self::expenseFor($transaction, $data, $employee);
            }

            self::postMovement($transaction, $recipe, $side, $userId, $data['expense_type'] ?? null);

            return $transaction->fresh();
        });
    }

    /**
     * ترحيل حركةٍ إلى دفتر الأستاذ وربطُها بقيدها.
     *
     * ولا تُرحَّل مرّتين: الحركة التي تحمل قيدًا مرحَّلًا تُترك كما هي.
     */
    private static function postMovement(
        Transaction $transaction,
        array $recipe,
        ?string $side,
        ?int $userId,
        ?string $expenseType = null,
    ): void {
        if ($transaction->journal_entry_id && JournalEntry::whereKey($transaction->journal_entry_id)->exists()) {
            return;
        }

        /*
         * و«@expense» يُحلّ بـ`expenseAccount` نفسها التي تقرأ منها شاشةُ
         * المصروفات: المصروفُ الواحد لا يقع في حسابين لأنّه دخل من بابين.
         */
        $resolve = fn (string $key) => match ($key) {
            '@side' => $side ?? self::CASH,
            '@expense' => self::expenseAccount($expenseType, $transaction->business_id),
            default => $key,
        };

        $entry = Ledger::post(
            $transaction->business_id,
            $transaction->description,
            [
                ['account' => $resolve($recipe['debit']), 'debit' => (float) $transaction->amount],
                ['account' => $resolve($recipe['credit']), 'credit' => (float) $transaction->amount],
            ],
            Carbon::parse($transaction->occurred_at),
            mb_substr(self::label($transaction->kind), 0, 30),
            $transaction->branch_id,
            $userId,
            $transaction,
        );

        $transaction->update(['journal_entry_id' => $entry->id]);
    }

    /** صفّ المصروف المقابل لحركةٍ من شاشة المالية */
    private static function expenseFor(Transaction $transaction, array $data, ?string $employee): void
    {
        Expense::create([
            'business_id' => $transaction->business_id,
            'reference' => self::nextExpenseReference($transaction->business_id),
            'type' => trim((string) ($data['expense_type'] ?? '')) ?: __('مصروف عام'),
            'description' => $transaction->description,
            'amount' => $transaction->amount,
            'method' => $transaction->method,
            'employee_name' => $employee,
            'spent_at' => Carbon::parse($transaction->occurred_at)->toDateString(),
            'transaction_id' => $transaction->id,
        ]);
    }

    /**
     * مرجعُ المصروف التالي — بالصيغة نفسها التي تكتبها شاشة المصروفات.
     *
     * وصفٌّ بلا مرجعٍ يظهر هناك بخانةٍ فارغة، فيُقرأ ناقصًا ولا يُبحث عنه.
     */
    private static function nextExpenseReference(int $businessId): string
    {
        $last = Expense::where('business_id', $businessId)->whereNotNull('reference')
            ->orderByDesc('id')->value('reference');

        return 'EXP-'.(($last && preg_match('/(\d+)$/', $last, $m)) ? ((int) $m[1] + 1) : 1001);
    }

    /* ------------------------------ أدوات ------------------------------ */

    /**
     * قيودُ المستند الحيّة — ما لم يُعكس منها، ولا العكسُ نفسه.
     *
     * والعكسُ يُستثنى صراحةً: هو معلَّقٌ بالمستند نفسه (يحمل `sourceable`
     * الأصل ليُقرأ تاريخُه من مكانٍ واحد)، فلو عُدّ حيًّا لعكسه نداءٌ ثانٍ
     * فعاد الرصيد إلى ما قبل الإلغاء.
     */
    private static function liveEntriesFor($model)
    {
        return JournalEntry::where('sourceable_type', $model::class)
            ->where('sourceable_id', $model->id)
            ->whereNull('reversed_at')
            ->whereNull('reverses_id')
            ->get();
    }

    /**
     * هل للمستند قيدٌ حيّ؟
     *
     * ولا يُسأل عن أيّ قيد: بيعةٌ أُلغيت لها قيدان — أصلٌ معكوس وعكسُه —
     * فلو كان وجودُهما مانعًا لما استطاع أمرُ الاستدراك ولا التصحيح أن
     * يُرحّل من جديد، وبقيت البيعة المصحَّحة خارج الدفتر إلى الأبد.
     */
    private static function hasEntry($model): bool
    {
        return self::liveEntriesFor($model)->isNotEmpty();
    }
}
