<?php

namespace App\Support;

use App\Models\User;

/**
 * صلاحيات الأقسام حسب الدور داخل لوحة النشاط.
 * '*' = كل الأقسام. dashboard و pos متاحة دائمًا لأي موظف يصل للوحة.
 */
class Permissions
{
    public const MAP = [
        'admin' => ['*'],
        'manager' => ['*'],
        /*
         * المحاسب يقرأ التقارير: هي عملُه لا زينةٌ فوقه.
         *
         * ═══ و«الموردون» و«المشتريات» أُضيفتا لأنّ غيابهما كان سهوًا ═══
         *
         * كان يُصدر فواتير العملاء ويحصّلها ويكتب الإشعار الدائن ويقرأ
         * الرواتب وشجرةَ الحسابات وميزانَ المراجعة — ولا يرى **الذمم
         * الدائنة**: فواتيرَ الموردين وسنداتِ استلامهم. فيقفل نصفَ الدفتر.
         *
         * والدليلُ أنّه سهوٌ لا تصميم: `ATTACHMENT_VIEW` كانت تشمله من
         * أوّل يوم — فهو مأذونٌ أن يفتح **مرفقَ** سندٍ لا يرى السندَ نفسَه.
         * القصدُ كان أن يقرأها، ونُسيت أفعالُ القراءة.
         *
         * ولا تُمنح معها كتابةٌ ولا اعتمادٌ ولا سداد: الاستلامُ عملُ من
         * يستلم البضاعة بيده، والاعتمادُ والصرفُ لمن يملك المال. ومن يُقيّد
         * لا يعتمد ما قيّده.
         */
        /*
         * و«المشاريع» للجميع — كلٌّ بحاجته (§23): المحاسبُ يقرأ ماليّتَها،
         * والبائعُ يكتب أوامرَ تغييرها، وأمينُ المخزن يرى ما يُطلب ومتى،
         * والمنسّقُ الميدانيّ يقرأ ما سينفّذه. والقسمُ يفتح الشاشة؛ وما يمسّ
         * النطاقَ أو المالَ أفعالٌ تُمنح بالاسم.
         */
        /*
         * و«الإدارة» مُنحت للمحاسب عمدًا في المرحلة 11: يقرأ الأداءَ الماليّ
         * وربحيّةَ المشاريع والعملاءَ والموازناتِ والتوقّعاتِ — قراءةً. والقسمُ
         * يفتح الشاشة؛ والإعدادُ والاعتمادُ والسيناريوهاتُ أفعالٌ تُمنح بالاسم.
         */
        'accountant' => ['dashboard', 'orders', 'customers', 'finance', 'expenses',
            'employees', 'reports', 'suppliers', 'purchases', 'projects', 'management'],
        /*
         * وأمينُ المخزن يفتح «المعدّات» — المرحلة 10: القطعُ ذاتُ الهويّة
         * (حواسيبُ وطابعاتٌ وماسحات) في عهدته كما البضاعةُ في عهدته. والقسمُ
         * يفتح السجلّ؛ والحجزُ والتسليمُ والردُّ أفعالٌ تُمنح بالاسم.
         */
        'inventory' => ['dashboard', 'products', 'inventory', 'suppliers', 'purchases', 'projects', 'assets'],
        /*
         * والبائعُ يفتح «عروض الأسعار»: هي بابُ عمله الأوّل في Event Plus —
         * يعرض قبل أن يُفوتر. ولا يفتحها المحاسب: مستندٌ تجاريٌّ قبل البيع
         * لا أثرَ له في الدفتر.
         */
        'sales' => ['dashboard', 'quotations', 'projects', 'orders', 'customers', 'products'],
        // والسائق يقرأ ما سيحمله: العنوان والموعد والمستلِم
        /*
         * ولا يُمنح «العمليات» بالدور — المرحلة 10: المنفّذُ الميدانيّ دورُه
         * أدنى الأدوار، والمحاسبُ يوظّفه؛ فما يُضاف إلى دوره يصير فوق ما يملك
         * المحاسب فلا يعود يوظّفه (انظر `StaffCannotOutrankThemselvesTest`).
         * وشاشةُ الموقع تُمنح له بالاسم: القسمُ `operations` مع `operations.status`
         * و`operations.incidents` و`assets.checkout` و`assets.return`.
         */
        'delivery' => ['dashboard', 'orders', 'projects'],
    ];

    /**
     * أفعالٌ تُمنح بأسمائها — لا أقسامٌ تُفتح.
     *
     * القسمُ يقول «أيّ شاشةٍ تفتح»، والفعلُ يقول «كم من الضرر تستطيع». وهما
     * سؤالان مختلفان: من فتحتَ له «المبيعات» ليقرأها لم تفتح له بالضرورة أن
     * يعيد كتابة فاتورةٍ صدرت.
     *
     * ويعيشان في العمود نفسه (`users.permissions`) ولا يختلطان: اسمُ الفعل
     * فيه نقطة واسمُ القسم لا نقطة فيه، فلا يُقرأ فعلٌ قسمًا ولا العكس —
     * انظر `entersPanel` و`isAction`.
     *
     * والدورةُ المالية تُفصَّل هنا فعلًا فعلًا: قراءةٌ، وكتابةٌ، واعتمادٌ،
     * ورفضٌ، وتجاوزٌ، وسداد. وجمعُها في مفتاحٍ واحدٍ كان يعني أنّ من يُمنح
     * كتابةَ السند يُمنح معها الإقرارَ بالذمّة وإخراجَ المال — وهي ثلاثةُ
     * قراراتٍ يفعلها في المتاجر ثلاثةُ أشخاص.
     *
     * وما لا يحرس شيئًا لا يُكتب: `view_payroll_payment_proofs` في المواصفة
     * لا مستندَ له بعد (دفعاتُ الرواتب المجمّعة لم تُبنَ)، ومقبضٌ لا يُدير
     * شيئًا أسوأ من غياب المقبض.
     */
    /** قراءةُ طابور إشعارات الاستلام — وفيه تكاليفُ الشراء */
    public const RECEIPT_VIEW = 'receipt.view';

    /** كتابةُ إشعار استلام — ورقةٌ تنتظر، ولا يتحرّك بها رفّ */
    public const RECEIPT_CREATE = 'receipt.create';

    /** اعتمادُ استلام البضاعة — وهو الفعل الذي يُدخلها الرفّ */
    public const RECEIPT_APPROVE = 'receipt.approve';

    /** رفضُ إشعار استلام — نصفُ المراجعة الآمن، يُمنح وحدَه */
    public const RECEIPT_REJECT = 'receipt.reject';

    /** قراءةُ سندات الموردين — وفيها ما على المتجر وبكم اشترى */
    public const INVOICE_VIEW = 'invoice.view';

    /** كتابةُ سند مورّد — إدخالُ الورقة لا الإقرارُ بالذمّة */
    public const INVOICE_CREATE = 'invoice.create';

    /** اعتمادُ سند المورّد — وهو الفعل الذي يُنشئ الذمّة */
    public const INVOICE_APPROVE = 'invoice.approve';

    /** رفضُ سند المورّد — نصفُ المراجعة الآمن، يُمنح وحدَه */
    public const INVOICE_REJECT = 'invoice.reject';

    /** تجاوزُ عدم مطابقة السند لأمره — بسببٍ يُكتب ويُنسب */
    public const INVOICE_OVERRIDE = 'invoice.override';

    /** سدادُ سند المورّد — مالٌ يخرج، وهو فعلٌ غيرُ الاعتماد */
    public const INVOICE_PAY = 'invoice.pay';

    /** قراءةُ مسيرة الرواتب — وفيها راتبُ كلّ موظّفٍ في المتجر */
    public const PAYROLL_VIEW = 'payroll.view';

    /** اعتمادُ المسيرة — وهو الفعل الذي يُنشئ الالتزام */
    public const PAYROLL_APPROVE = 'payroll.approve';

    /** صرفُ الرواتب — مالٌ يخرج، وهو فعلٌ غيرُ الاعتماد */
    public const PAYROLL_PAY = 'payroll.pay';

    /*
     * ═══ فواتيرُ العملاء: أفعالُ المال تُمنح بأسمائها ═══
     *
     * القسمُ («المبيعات») يفتح الشاشةَ ويكتب المسودّة — والمسودّةُ لا تُنشئ
     * ذمّةً ولا تكتب قيدًا، فلا تحتاج اسمًا. وما بعدها يحتاج: الإصدارُ يولد
     * الذمّة ويكتب القيد، والإلغاءُ يعكسه، والإشعارُ الدائن يُنقصه، والتحصيلُ
     * مالٌ يدخل الصندوق.
     *
     * وكان الأربعةُ يُملَكون بفتح «المبيعات» — فمن يُؤتمن على قراءة الطلبات
     * يُلغي فاتورةً صادرةً على وزارة.
     */

    /** إصدارُ فاتورة العميل — وهو الفعل الذي يُنشئ الذمّة ويكتب القيد */
    public const CUSTOMER_INVOICE_ISSUE = 'customerInvoice.issue';

    /** إلغاءُ فاتورةٍ صادرة — عكسُ قيدٍ وُقّع، لا محوُ ورقة */
    public const CUSTOMER_INVOICE_CANCEL = 'customerInvoice.cancel';

    /** إشعارٌ دائن — يُنقص دَينًا قائمًا على العميل */
    public const CUSTOMER_CREDIT_NOTE = 'customerCreditNote.create';

    /** تسجيلُ تحصيل — مالٌ يدخل، وهو فعلٌ غيرُ الإصدار */
    public const CUSTOMER_PAYMENT_CREATE = 'customerPayment.create';

    /** فتحُ مرفقات المستندات المالية — وفيها أثمنُ ما في المتجر: أسعارُ شرائه */
    public const ATTACHMENT_VIEW = 'attachment.view';

    /*
     * ═══ عروضُ الأسعار: الكتابةُ فعلٌ، والسعرُ فعلٌ آخر ═══
     *
     * القسمُ («عروض الأسعار») يفتح الشاشةَ ويقرأ ما فيها. وما بعده يُفصَّل
     * لأنّ أخطرَ ما في عرض السعر ليس كتابتَه بل **ثمنَه**: من يكتب البنود
     * ليس بالضرورة من يقرّر أن تُباع بأقلّ من سعرها المعتمد، ولا من يأذن
     * لها أن تخرج إلى جهةٍ تُحاسِب عليها.
     *
     * والخصمُ فعلان لا فعلٌ واحد: منحُه ضمن حدٍّ، واعتمادُ ما تجاوزه. ومن
     * يمنح لا يعتمد ما منحه — وإلّا فالحدُّ زينة.
     */

    /** كتابةُ عرضِ سعرٍ وتعديلُ مسودّته — ولا سعرَ يُغيَّر به */
    public const QUOTATION_CREATE = 'quotation.create';

    /** إخراجُ العرض إلى العميل — وهو الفعل الذي يُجمّده ويقطع رقمَه */
    public const QUOTATION_SEND = 'quotation.send';

    /** منحُ خصمٍ عن سعر الكتالوج — بسببٍ يُكتب، وضمن حدٍّ يُضبط */
    public const QUOTATION_DISCOUNT = 'quotation.discount';

    /** البيعُ فوق سعر الكتالوج — بسببٍ يُكتب */
    public const QUOTATION_OVERRIDE_PRICE = 'quotation.overridePrice';

    /** اعتمادُ خصمٍ تجاوز حدَّ صاحبه — وبه وحده يخرج العرض */
    public const QUOTATION_APPROVE = 'quotation.approve';

    /** إلغاءُ عرضٍ قائم */
    public const QUOTATION_CANCEL = 'quotation.cancel';

    /** تسجيلُ قبول العميل أو رفضِه نيابةً عنه */
    public const QUOTATION_RECORD_DECISION = 'quotation.recordDecision';

    /**
     * اعتمادُ استثناءٍ من سياسة الشركة — «طلبٌ عاجل والمهلةُ سبعة».
     *
     * والطلبُ مفتوحٌ لمن يكتب: يكتب السببَ وينتظر. والاعتمادُ لمن يُجاوز
     * السياسةَ باسمه — وهو غيرُ اعتماد الخصم: من يأذن بخصمٍ لا يأذن
     * بالضرورة بتنفيذٍ في ثلاثة أيّام.
     */
    public const POLICY_OVERRIDE = 'policy.override';

    /**
     * الإرسالُ من بريد الشركة إلى العميل — رابطُ العرض وتذكيرُه.
     *
     * وهو `communication.send` في المرحلة 6: القناةُ صارت بريدًا وواتساب،
     * والمفتاحُ يبقى — من مُنحه أمسِ يُرسل اليوم من القناتين.
     */
    public const MESSAGING_SEND = 'messaging.send';

    /*
     * ═══ المراسلات — المرحلة 6 ═══
     *
     * قسمُ «المراسلات» يفتح سجلَّ الشركة كلَّه؛ وهذه الأفعالُ تُمنح بالاسم:
     * القراءةُ داخل المستند (تبويبُ المشروع وبطاقةُ العرض) لمن يعمل عليه،
     * والإعادةُ لمن يُسأل عن رسالةٍ ماليّة تصل مرّتين، والقوالبُ لمن يقرّر
     * ما تقوله الشركةُ باسمها.
     */

    /** رؤيةُ مراسلات مستندٍ من داخله */
    public const COMMUNICATION_VIEW = 'communication.view';

    /** إعادةُ إرسالِ رسالةٍ فشلت */
    public const COMMUNICATION_RETRY = 'communication.retry';

    /** ضبطُ قوالب الرسائل: أسماؤها عند المزوّد وحالُها */
    public const COMMUNICATION_TEMPLATES_MANAGE = 'communication.templates.manage';

    /*
     * ═══ الأتمتة — المرحلة 8 ═══
     *
     * قسمُ «الأتمتة» يفتح القواعدَ والسجلّ للقراءة؛ والتغييرُ فعلان بالاسم:
     * ضبطُ القواعد وتشغيلُها (ومنها ما يبلغ العميل)، وإيقافُ الأتمتة كلِّها
     * واستئنافُها — مفتاحُ الأمان.
     */

    /** ضبطُ قواعد الأتمتة ووضعِها: تفعيلٌ وإيقاف وتبديلُ مراقبة/تشغيل */
    public const AUTOMATION_MANAGE = 'automation.manage';

    /** إيقافُ الأتمتة كلِّها واستئنافُها */
    public const AUTOMATION_PAUSE = 'automation.pause';

    /*
     * ═══ استيرادُ البيانات التاريخيّة — المرحلة 9 ═══
     *
     * القسمُ `imports` يفتح الشاشة ويقرأ. والأفعال: إدارةُ المجموعات (تسجيلٌ
     * وربطٌ وتجربة)، وتنفيذُ الاستيراد (يدٌ بعد اعتماد)، والماليُّ منه (ما
     * يكتب في الدفتر — أشدُّها)، والتراجعُ. ولا فعلَ يُنفَّذ من الشاشة وحدها.
     */

    /** تسجيلُ مجموعةٍ وربطُها وتجربتُها وقراراتُ التكرار */
    public const IMPORT_MANAGE = 'import.manage';

    /** اعتمادُ الاستيراد وتنفيذُه بعد تجربةٍ ناجحة */
    public const IMPORT_EXECUTE = 'import.execute';

    /** ما يكتب في الدفتر: فواتيرُ وتحصيلاتٌ ومصروفاتٌ وسنداتٌ وأرصدةٌ افتتاحيّة */
    public const IMPORT_FINANCIAL = 'import.financial';

    /** التراجعُ عن استيرادٍ نُفّذ */
    public const IMPORT_ROLLBACK = 'import.rollback';

    /*
     * ═══ تشغيلُ الفعاليّة والمعدّات — المرحلة 10 ═══
     *
     * قسمُ «العمليات» يفتح الفعاليّاتِ وشاشةَ الموقع للقراءة، وقسمُ
     * «المعدّات» يفتح سجلَّ القطع. والأفعالُ بالاسم: إدارةُ الفعاليّة
     * (جدولٌ ومتطلّبات)، ونقلُ حالها، وتجاوزُ الجاهزيّة (أشدُّها — للمالك
     * وحده وبسببٍ يُكتب)، والحوادثُ، وإدارةُ القطع، وحجزُها، وتسليمُها،
     * وردُّها، والفريقُ، وقوائمُ التحقّق.
     */

    /** إنشاءُ الفعاليّة وتعديلُها وجدولُها ومتطلّباتُها */
    public const OPERATIONS_MANAGE = 'operations.manage';

    /** نقلُ حال الفعاليّة: تجهيزٌ وجاهزيّةٌ وبثٌّ وفكٌّ وإتمام */
    public const OPERATIONS_STATUS = 'operations.status';

    /** تجاوزُ موانع الجاهزيّة — بسببٍ يُكتب وأثرٍ يبقى */
    public const OPERATIONS_OVERRIDE = 'operations.override';

    /** تسجيلُ حادثٍ ومعالجتُه */
    public const OPERATIONS_INCIDENTS = 'operations.incidents';

    /** تسجيلُ قطعةٍ وتعديلُها وتغييرُ حالها (صيانةٌ وتلفٌ وفقدٌ وسحب) */
    public const ASSETS_MANAGE = 'assets.manage';

    /** حجزُ قطعةٍ لفعاليّة وإلغاءُ الحجز */
    public const ASSETS_RESERVE = 'assets.reserve';

    /** تسليمُ قطعةٍ محجوزة — خرجت من المكتب */
    public const ASSETS_CHECKOUT = 'assets.checkout';

    /** ردُّ قطعةٍ — عادت وبأيّ حال */
    public const ASSETS_RETURN = 'assets.return';

    /** تعيينُ فريق الفعاليّة وإزالتُه */
    public const CREW_MANAGE = 'crew.manage';

    /** قوالبُ قوائم التحقّق، وإلحاقُها بالفعاليّة، وإعفاءُ بندٍ إلزاميّ */
    public const CHECKLIST_MANAGE = 'checklist.manage';

    /*
     * ═══ الذكاءُ الإداريّ — المرحلة 11 ═══
     *
     * قسمُ «الإدارة» يفتح الشاشات؛ والأرقامُ الماليّة لا تُرى بمجرّد فتح
     * القسم: كلُّ بابٍ منها فعلٌ بالاسم — المالُ، والمشاريعُ، والعملاءُ،
     * والعمليات. والموازنةُ والتوقّعُ يُقرآن بفعلٍ ويُداران بفعلٍ ويُعتمدان
     * بفعل: من يكتب لا يعتمد ما كتبه إلّا أن يُمنح الاثنين.
     */

    /** الأداءُ الماليّ: الإيرادُ والتحصيلُ والمصروفُ والنقدُ والذمم */
    public const ANALYTICS_FINANCIAL = 'analytics.financial';

    /** ربحيّةُ المشاريع */
    public const ANALYTICS_PROJECTS = 'analytics.projects';

    /** العملاءُ والخدماتُ والقمع التجاريّ */
    public const ANALYTICS_CUSTOMERS = 'analytics.customers';

    /** التشغيلُ والمعدّات */
    public const ANALYTICS_OPERATIONS = 'analytics.operations';

    public const BUDGETS_VIEW = 'budgets.view';

    public const BUDGETS_MANAGE = 'budgets.manage';

    public const BUDGETS_APPROVE = 'budgets.approve';

    public const FORECASTS_VIEW = 'forecasts.view';

    public const FORECASTS_MANAGE = 'forecasts.manage';

    public const FORECASTS_APPROVE = 'forecasts.approve';

    public const SCENARIOS_MANAGE = 'scenarios.manage';

    public const MANAGEMENT_REVIEWS_MANAGE = 'management_reviews.manage';

    /** ضبطُ عتبات التنبيهات الإداريّة */
    public const MANAGEMENT_THRESHOLDS = 'management.thresholds';

    /*
     * ═══ المشروع — المرحلة 4 ═══
     *
     * ولا `project.create`: المشروعُ يُنشأ من قبول العرض لا بيد، ومقبضٌ لا
     * يحرس بابًا لا يُكتب.
     */

    /** إدارةُ المشروع: بياناتُه وفريقُه ومهامُّه ومتطلّباتُه قبل التثبيت — والتثبيتُ نفسُه */
    public const PROJECT_MANAGE = 'project.manage';

    /** رؤيةُ تكلفته وربحيّته — المالُ لا النطاق */
    public const PROJECT_BUDGET_VIEW = 'project.budget.view';

    /** إقفالُ مشروع — يُجمّد أرقامَه */
    public const PROJECT_CLOSE = 'project.close';

    /** إعادةُ فتح مشروعٍ مُقفَل */
    public const PROJECT_REOPEN = 'project.reopen';

    /**
     * أمرُ التغيير: أربعةُ أفعال — والتسعيرُ بأفعال العرض نفسِها.
     *
     * خصمُ بندٍ فيه وبيعُه فوق الكتالوج واعتمادُ ما تجاوز الحدَّ تمرّ بـ
     * `quotation.discount` و`overridePrice` و`approve`: الكودُ الذي يفرضها
     * واحد (`Quotations::price`)، ومن يُؤذَن له بخصمٍ في العرض يُؤذَن له
     * في تعديله.
     */
    public const CHANGE_ORDER_CREATE = 'changeOrder.create';

    public const CHANGE_ORDER_SEND = 'changeOrder.send';

    public const CHANGE_ORDER_RECORD_DECISION = 'changeOrder.recordDecision';

    public const CHANGE_ORDER_CANCEL = 'changeOrder.cancel';

    public const ACTIONS = [
        'order.edit' => 'تصحيح فاتورة مكتملة',
        /*
         * ائتمانُ العميل: وضعُ حدّه وتجاوزُه — فعلٌ واحدٌ لا فعلان.
         *
         * التجاوزُ في البيع يُكتب بسببه ويُسجَّل. ووضعُ الحدّ كان مفتوحًا بقسم
         * «العملاء»، فمن لا يملك التجاوز يمحو الحدَّ (والفراغُ «بلا حدّ»)
         * ويبيع بلا سقف. ومفتاحان لثقةٍ واحدة يفترقان يومًا — انظر
         * `ReceivablesController::credit`.
         */
        'credit.override' => 'ضبط ائتمان العميل وتجاوز حدّه',
        /*
         * ومن يكتب الاستلام ليس بالضرورة من يعتمده.
         *
         * قسمُ «المشتريات» يُمنح لمن يطلب البضاعة ويستلمها؛ وإدخالُها الرفَّ
         * وترجيحُ متوسّط تكلفتها قرارٌ آخر — كميّةٌ تُكتب أكبر ممّا وصل
         * تُفسد تسعير كلّ بيعةٍ بعدها ولا يكشفها إلّا الجرد.
         */
        self::RECEIPT_VIEW => 'عرض إشعارات الاستلام',
        self::RECEIPT_CREATE => 'تسجيل استلام بضاعة',
        self::RECEIPT_APPROVE => 'اعتماد استلام البضاعة',
        /*
         * والرفضُ يُفصل عن الاعتماد: هو نصفُ المراجعة الآمن.
         *
         * ورقةٌ تُرفض لا تُحرّك رفًّا ولا تكتب قيدًا — فمن يُوثَق به ليقول
         * «هذه ناقصة» لا يلزم أن يُوثَق به ليقول «هذه صحيحة فأدخِلها».
         */
        self::RECEIPT_REJECT => 'رفض إشعار استلام',
        /*
         * وسندُ المورّد أربعةُ أفعالٍ لا فعلٌ واحد.
         *
         * من يُدخل الأوراق ليس بالضرورة من يقرّ بأنّ المتجر مدينٌ بها، ولا
         * من يخرج المال بها — وسندٌ يُرحَّل لحظةَ كتابته كان يجعل الثلاثة
         * فعلًا واحدًا.
         */
        self::INVOICE_VIEW => 'عرض سندات الموردين',
        self::INVOICE_CREATE => 'إدخال سند مورّد',
        self::INVOICE_APPROVE => 'اعتماد سند المورّد',
        self::INVOICE_REJECT => 'رفض سند المورّد',
        /*
         * والتجاوزُ منفصلٌ عن الاعتماد: من يعتمد السنداتِ المطابِقة يوميًّا
         * ليس بالضرورة من يقرّر الدفعَ عن بضاعةٍ لم تصل.
         */
        self::INVOICE_OVERRIDE => 'تجاوز عدم مطابقة سند المورّد',
        // والسدادُ آخرُها: مالٌ يخرج مقابل ذمّةٍ نشأت، لا مقابل ورقةٍ وصلت
        self::INVOICE_PAY => 'سداد سندات الموردين',
        /*
         * والرواتب: قراءتُها وحدها فعلٌ يُمنح.
         *
         * مسيرةُ الرواتب كانت تحت قسم «الرواتب والموظفين»، فمن مُنح القسمَ
         * ليضيف موظّفًا أو يصحّح مسمّاه كان يقرأ راتبَ كلّ من في المتجر —
         * وهو أكثرُ ما يُفسد بين الموظّفين إن تسرّب.
         */
        self::PAYROLL_VIEW => 'عرض مسيرة الرواتب',
        self::PAYROLL_APPROVE => 'اعتماد مسيرة الرواتب',
        self::PAYROLL_PAY => 'صرف الرواتب',
        /*
         * والمرفقاتُ بابٌ واحدٌ لكلّ المستندات المالية.
         *
         * ورقةُ المورّد فيها أسعارُ شرائك — أثمنُ ما في متجرك عند منافسك.
         */
        self::CUSTOMER_INVOICE_ISSUE => 'إصدار فاتورة عميل',
        self::CUSTOMER_INVOICE_CANCEL => 'إلغاء فاتورة عميل صادرة',
        self::CUSTOMER_CREDIT_NOTE => 'إصدار إشعار دائن',
        self::CUSTOMER_PAYMENT_CREATE => 'تسجيل تحصيل من عميل',
        self::ATTACHMENT_VIEW => 'فتح مرفقات المستندات المالية',
        /*
         * وعرضُ السعر: سبعةُ أفعالٍ لأنّها سبعةُ قراراتٍ مختلفة.
         *
         * وأخطرُها ليس الكتابة: `overridePrice` تبيع فوق السعر المعلن،
         * و`approve` تُبطل الحدَّ الذي وضعه صاحبُ العمل، و`send` تُخرج
         * الورقةَ إلى جهةٍ تحتجّ بها.
         */
        self::QUOTATION_CREATE => 'كتابة عرض سعر',
        self::QUOTATION_SEND => 'إرسال عرض سعر للعميل',
        self::QUOTATION_DISCOUNT => 'منح خصم على عرض سعر أو أمر تغيير',
        self::QUOTATION_OVERRIDE_PRICE => 'البيع فوق سعر الكتالوج',
        self::QUOTATION_APPROVE => 'اعتماد خصم تجاوز الحدّ',
        self::QUOTATION_CANCEL => 'إلغاء عرض سعر',
        self::QUOTATION_RECORD_DECISION => 'تسجيل قبول العميل أو رفضه',
        self::POLICY_OVERRIDE => 'اعتماد استثناء من سياسة الشركة',
        self::MESSAGING_SEND => 'الإرسال من بريد الشركة',
        self::COMMUNICATION_VIEW => 'رؤية مراسلات العميل داخل المستند',
        self::COMMUNICATION_RETRY => 'إعادة إرسال رسالة فشلت',
        self::COMMUNICATION_TEMPLATES_MANAGE => 'ضبط قوالب الرسائل',
        self::AUTOMATION_MANAGE => 'ضبط قواعد الأتمتة وتشغيلها',
        self::AUTOMATION_PAUSE => 'إيقاف الأتمتة واستئنافها',
        self::IMPORT_MANAGE => 'تسجيل مجموعات البيانات وربطها وتجربتها',
        self::IMPORT_EXECUTE => 'اعتماد الاستيراد وتنفيذه',
        self::IMPORT_FINANCIAL => 'استيراد البيانات المالية إلى الدفتر',
        self::IMPORT_ROLLBACK => 'التراجع عن استيراد',
        self::OPERATIONS_MANAGE => 'إدارة الفعاليّة وجدولها ومتطلّباتها',
        self::OPERATIONS_STATUS => 'نقل حال الفعاليّة: تجهيز وبثّ وفكّ وإتمام',
        self::OPERATIONS_OVERRIDE => 'تجاوز موانع جاهزيّة الفعاليّة',
        self::OPERATIONS_INCIDENTS => 'تسجيل حوادث الفعاليّة ومعالجتها',
        self::ASSETS_MANAGE => 'تسجيل المعدّات وتغيير حالها',
        self::ASSETS_RESERVE => 'حجز المعدّات لفعاليّة',
        self::ASSETS_CHECKOUT => 'تسليم المعدّات للميدان',
        self::ASSETS_RETURN => 'ردّ المعدّات وتسجيل حالها',
        self::CREW_MANAGE => 'تعيين فريق الفعاليّة',
        self::CHECKLIST_MANAGE => 'قوالب قوائم التحقّق وإعفاء بندٍ إلزاميّ',
        self::ANALYTICS_FINANCIAL => 'قراءة الأداء المالي والنقد والذمم',
        self::ANALYTICS_PROJECTS => 'قراءة ربحيّة المشاريع',
        self::ANALYTICS_CUSTOMERS => 'قراءة تحليلات العملاء والخدمات والقمع',
        self::ANALYTICS_OPERATIONS => 'قراءة تحليلات التشغيل والمعدّات',
        self::BUDGETS_VIEW => 'قراءة الموازنات',
        self::BUDGETS_MANAGE => 'إعداد الموازنات وتنقيحها',
        self::BUDGETS_APPROVE => 'اعتماد الموازنات',
        self::FORECASTS_VIEW => 'قراءة التوقّعات النقدية',
        self::FORECASTS_MANAGE => 'إعداد التوقّعات النقدية',
        self::FORECASTS_APPROVE => 'اعتماد التوقّعات النقدية',
        self::SCENARIOS_MANAGE => 'إعداد السيناريوهات المالية',
        self::MANAGEMENT_REVIEWS_MANAGE => 'إعداد المراجعات الإدارية وقراراتها',
        self::MANAGEMENT_THRESHOLDS => 'ضبط عتبات التنبيهات الإدارية',
        self::PROJECT_MANAGE => 'إدارة المشروع وفريقه ومهامّه وتثبيت متطلّباته',
        self::PROJECT_BUDGET_VIEW => 'رؤية تكلفة المشروع وربحيّته',
        self::PROJECT_CLOSE => 'إقفال مشروع',
        self::PROJECT_REOPEN => 'إعادة فتح مشروع مُقفَل',
        self::CHANGE_ORDER_CREATE => 'كتابة أمر تغيير',
        self::CHANGE_ORDER_SEND => 'إرسال أمر تغيير للعميل',
        self::CHANGE_ORDER_RECORD_DECISION => 'تسجيل قبول العميل لأمر التغيير أو رفضه',
        self::CHANGE_ORDER_CANCEL => 'إلغاء أمر تغيير',
    ];

    /**
     * من يفعلها بدوره وحده — ومن سواه يُمنحها بالاسم.
     *
     * وليست `'*'`: صاحبُ النشاط ومديرُ الفرع يملكان كلَّ قسم، ولا يعني ذلك
     * أنّ كلّ فعلٍ يقع تحت `*` صامتًا يوم يُضاف. فما لا يُذكر هنا لا يُمنح.
     */
    public const ACTION_ROLES = [
        'order.edit' => ['admin', 'manager'],
        // والموظّف لا يتجاوز حدًّا وضعه صاحبُ العمل: يطلبه ممّن وضعه
        'credit.override' => ['admin', 'manager'],
        /*
         * والقراءةُ والكتابةُ تُورَثان بالدور كما كانتا قبل التفصيل.
         *
         * فمن يفتح «المخزون» و«المشتريات» اليوم بدوره يبقى يفتحهما غدًا:
         * التفصيلُ يضيّق ما يُخرج المالَ ويُنشئ الذمّة، ولا يقطع عن أحدٍ
         * شاشةً يعمل عليها.
         */
        /*
         * والقراءةُ تشمل المحاسب: الذمّةُ الدائنة نصفُ الدفتر، ومن يُقفل
         * الحسابات لا يُقفلها على نصفٍ لا يراه. والكتابةُ لا تشمله —
         * الاستلامُ عملُ من يستلم البضاعة بيده.
         */
        self::RECEIPT_VIEW => ['admin', 'manager', 'accountant', 'inventory'],
        self::RECEIPT_CREATE => ['admin', 'manager', 'inventory'],
        self::INVOICE_VIEW => ['admin', 'manager', 'accountant', 'inventory'],
        self::INVOICE_CREATE => ['admin', 'manager', 'inventory'],
        self::ATTACHMENT_VIEW => ['admin', 'manager', 'accountant', 'inventory'],
        // والاعتمادُ والرفضُ للمالك ومدير الفرع — ومن سواهما يُمنحهما بالاسم
        self::RECEIPT_APPROVE => ['admin', 'manager'],
        self::RECEIPT_REJECT => ['admin', 'manager'],
        self::INVOICE_APPROVE => ['admin', 'manager'],
        self::INVOICE_REJECT => ['admin', 'manager'],
        // والتجاوزُ للمالك وحده: دفعٌ عن بضاعةٍ لم تصل قرارُ من يملك المال
        self::INVOICE_OVERRIDE => ['admin'],
        /*
         * والسدادُ يضيق عمّا كان: كان أمينُ المخزن يسدّد للمورّد لأنّه يملك
         * «المشتريات». وإخراجُ المال ليس من عمله — يطلبه ممّن يملكه.
         */
        self::INVOICE_PAY => ['admin', 'manager'],
        /*
         * والرواتب: المحاسبُ يقرؤها لأنّها عملُه، ولا يعتمد ولا يصرف.
         * والاعتمادُ إقرارٌ بالتزام، والصرفُ إخراجُ مال — كلاهما لصاحبهما.
         */
        /*
         * وفواتيرُ العملاء: البائعُ يكتب المسودّة بقسمه، ولا يُصدر ولا يُلغي.
         *
         * والمحاسبُ يُصدر ويحصّل ويُصدر الإشعارَ الدائن — فذلك عملُه. والإلغاءُ
         * وحدَه للمالك ومدير الفرع: عكسُ قيدٍ وُقّع على وزارةٍ ليس تصحيحَ
         * خطأٍ مطبعيّ.
         */
        self::CUSTOMER_INVOICE_ISSUE => ['admin', 'manager', 'accountant'],
        self::CUSTOMER_PAYMENT_CREATE => ['admin', 'manager', 'accountant'],
        self::CUSTOMER_CREDIT_NOTE => ['admin', 'manager', 'accountant'],
        self::CUSTOMER_INVOICE_CANCEL => ['admin', 'manager'],
        self::PAYROLL_VIEW => ['admin', 'manager', 'accountant'],
        self::PAYROLL_APPROVE => ['admin', 'manager'],
        self::PAYROLL_PAY => ['admin', 'manager'],
        /*
         * وعروضُ الأسعار: البائعُ يكتب ويُرسل ويسجّل قرار العميل — فذلك عملُه.
         *
         * ولا يمسّ السعرَ: الخصمُ للمالك ومدير الفرع، والبيعُ فوق السعر
         * **للمالك وحده** — قرارٌ يمسّ سمعةَ التسعير كلَّه لا صفقةً واحدة.
         * والاعتمادُ لمن وُضع الحدُّ لأجله، لا لمن يُمنَح الخصمَ نفسَه.
         *
         * والمحاسبُ لا يكتب عروضًا: هي مستندٌ تجاريٌّ قبل البيع لا بعده،
         * ولا أثرَ له في الدفتر.
         */
        self::QUOTATION_CREATE => ['admin', 'manager', 'sales'],
        self::QUOTATION_SEND => ['admin', 'manager', 'sales'],
        self::QUOTATION_RECORD_DECISION => ['admin', 'manager', 'sales'],
        self::QUOTATION_DISCOUNT => ['admin', 'manager'],
        self::QUOTATION_APPROVE => ['admin', 'manager'],
        self::QUOTATION_CANCEL => ['admin', 'manager'],
        self::QUOTATION_OVERRIDE_PRICE => ['admin'],
        /* والاستثناءُ من السياسة يعتمده من يملك أن يُجاوزها باسمه */
        self::POLICY_OVERRIDE => ['admin', 'manager'],
        /* والبائعُ يرسل من بريد الشركة: هو من يكتب العرضَ ويتابعه */
        self::MESSAGING_SEND => ['admin', 'manager', 'sales'],
        /*
         * والمراسلات: يقرؤها من يعمل على المستند (البائعُ والمحاسب)، ويعيد
         * ما فشل ويضبط القوالبَ من يملك القسمَ كلَّه — المرحلة 6.
         */
        self::COMMUNICATION_VIEW => ['admin', 'manager', 'sales', 'accountant'],
        self::COMMUNICATION_RETRY => ['admin', 'manager'],
        self::COMMUNICATION_TEMPLATES_MANAGE => ['admin', 'manager'],
        /* والأتمتةُ للمالك والمدير: ما يبلغ العميلَ وحده لا يُترك لغيرهما */
        self::AUTOMATION_MANAGE => ['admin', 'manager'],
        self::AUTOMATION_PAUSE => ['admin', 'manager'],
        /* والاستيرادُ: الإدارةُ والتنفيذُ للمالك والمدير، والماليُّ والتراجعُ للمالك وحده — يكتبان في الدفتر ويمحوان */
        self::IMPORT_MANAGE => ['admin', 'manager'],
        self::IMPORT_EXECUTE => ['admin', 'manager'],
        self::IMPORT_FINANCIAL => ['admin'],
        self::IMPORT_ROLLBACK => ['admin'],
        /*
         * والتشغيلُ — المرحلة 10: الإدارةُ والفريقُ والقوائمُ ونقلُ الحال
         * والحوادثُ للمالك والمدير؛ والقطعُ لأمين المخزن أيضًا: يسجّل ويحجز
         * ويسلّم ويستردّ. والتجاوزُ للمالك وحده. ومن يقف في الموقع — المنفّذُ
         * الميدانيّ — يُمنح نقلَ الحال والحوادثَ والتسليمَ والردَّ بالاسم (انظر
         * `MAP`): دورُه لا يحمل فوق ما يملك من يوظّفه.
         */
        self::OPERATIONS_MANAGE => ['admin', 'manager'],
        self::OPERATIONS_STATUS => ['admin', 'manager'],
        self::OPERATIONS_OVERRIDE => ['admin'],
        self::OPERATIONS_INCIDENTS => ['admin', 'manager'],
        self::ASSETS_MANAGE => ['admin', 'manager', 'inventory'],
        self::ASSETS_RESERVE => ['admin', 'manager', 'inventory'],
        self::ASSETS_CHECKOUT => ['admin', 'manager', 'inventory'],
        self::ASSETS_RETURN => ['admin', 'manager', 'inventory'],
        self::CREW_MANAGE => ['admin', 'manager'],
        self::CHECKLIST_MANAGE => ['admin', 'manager'],
        /*
         * والذكاءُ الإداريّ — المرحلة 11: القراءةُ للمالك والمدير، وللمحاسب
         * ما هو عملُه (المالُ والمشاريعُ والعملاءُ والموازنةُ والتوقّعُ قراءةً).
         * والاعتمادُ للمالك وحده — من يكتب الموازنةَ لا يعتمدها إلّا أن يُمنح.
         */
        self::ANALYTICS_FINANCIAL => ['admin', 'manager', 'accountant'],
        self::ANALYTICS_PROJECTS => ['admin', 'manager', 'accountant'],
        self::ANALYTICS_CUSTOMERS => ['admin', 'manager', 'accountant'],
        self::ANALYTICS_OPERATIONS => ['admin', 'manager'],
        self::BUDGETS_VIEW => ['admin', 'manager', 'accountant'],
        self::BUDGETS_MANAGE => ['admin', 'manager'],
        self::BUDGETS_APPROVE => ['admin'],
        self::FORECASTS_VIEW => ['admin', 'manager', 'accountant'],
        self::FORECASTS_MANAGE => ['admin', 'manager'],
        self::FORECASTS_APPROVE => ['admin'],
        self::SCENARIOS_MANAGE => ['admin', 'manager'],
        self::MANAGEMENT_REVIEWS_MANAGE => ['admin', 'manager'],
        self::MANAGEMENT_THRESHOLDS => ['admin'],
        /*
         * والمشروع: إدارتُه وإقفالُه للمالك ومدير الفرع، وإعادةُ الفتح
         * للمالك وحده — تُعيد أرقامًا جُمّدت. والمحاسبُ يرى المال لأنّه عملُه.
         * وأمرُ التغيير كالعرض: البائعُ يكتب ويُرسل ويسجّل، ولا يُلغي.
         */
        self::PROJECT_MANAGE => ['admin', 'manager'],
        self::PROJECT_BUDGET_VIEW => ['admin', 'manager', 'accountant'],
        self::PROJECT_CLOSE => ['admin', 'manager'],
        self::PROJECT_REOPEN => ['admin'],
        self::CHANGE_ORDER_CREATE => ['admin', 'manager', 'sales'],
        self::CHANGE_ORDER_SEND => ['admin', 'manager', 'sales'],
        self::CHANGE_ORDER_RECORD_DECISION => ['admin', 'manager', 'sales'],
        self::CHANGE_ORDER_CANCEL => ['admin', 'manager'],
    ];

    /**
     * ما كان القسمُ يبيحه قبل أن تُفصَّل أفعالُ الدورة المالية.
     *
     * مصدرٌ واحد تقرأ منه هجرةُ الترقية (فتمنح من خُصِّصت صلاحياتُه يدويًّا
     * ما كان يفعله أمسِ) ويقرأ منه الاختبارُ حين يبني موظّفًا بقائمةٍ يدوية.
     * وقائمتان تُكتبان باليد تفترقان يومًا، فيمرّ الاختبارُ ويُردّ الموظّف.
     *
     * والاعتمادُ والرفضُ والتجاوزُ ليست فيها: لم تكن تُملَك بقسمٍ يومًا.
     */
    public const LEGACY_SECTION_ACTIONS = [
        'inventory' => [self::RECEIPT_VIEW, self::ATTACHMENT_VIEW],
        'purchases' => [
            self::RECEIPT_CREATE,
            self::INVOICE_VIEW,
            self::INVOICE_CREATE,
            self::INVOICE_PAY,
            self::ATTACHMENT_VIEW,
        ],
        /*
         * و«المبيعات» كانت تبيح ثلاثةً: من فتحها أصدر وألغى وأصدر الإشعارَ
         * الدائن.
         *
         * والتحصيلُ ليس منها: بابُه `customerPayments` وهو محسوبٌ على
         * «المالية» في `ALIASES` — فمن مُنح «المبيعات» وحدها لم يكن يبلغه
         * أصلًا. ووضعُه هنا كان يمنح البائعَ فعلًا لم يملكه يومًا.
         */
        'orders' => [
            self::CUSTOMER_INVOICE_ISSUE,
            self::CUSTOMER_INVOICE_CANCEL,
            self::CUSTOMER_CREDIT_NOTE,
        ],

        // والتحصيلُ لمن كانت له «المالية» — وهي بابُه قبل التفصيل وبعده
        'finance' => [self::CUSTOMER_PAYMENT_CREATE],
        'employees' => [self::PAYROLL_VIEW, self::PAYROLL_APPROVE, self::PAYROLL_PAY],
        'expenses' => [self::ATTACHMENT_VIEW],
    ];

    /**
     * قائمةٌ يدوية مضافًا إليها ما كانت أقسامُها تبيحه — كما تفعل الهجرة.
     *
     * @param  list<string>  $list
     * @return list<string>
     */
    public static function withLegacyActions(array $list): array
    {
        foreach (self::LEGACY_SECTION_ACTIONS as $section => $actions) {
            if (in_array($section, $list, true)) {
                $list = array_merge($list, $actions);
            }
        }

        return array_values(array_unique($list));
    }

    /**
     * ما يفتحه هذا الموظّف ولا يفتحه الفاعل — وفارغةٌ تعني «يُمسّ حسابُه».
     *
     * ═══ ولماذا هنا لا في المتحكّم ═══
     *
     * سؤالان يُسألان عن الشيء نفسه: الحارسُ يسأله ليردّ، والشاشةُ تسأله
     * لترسم. ولو كُتب في موضعين لافترقا يومًا — فتُرسم أزرارُ «إعادة تعيين
     * كلمة المرور» و«تعطيل الحساب» لمن يردّه الخادمُ عنها، وهو أسوأ من
     * غيابها: الموظّف يظنّ العطبَ في النظام ويعيد المحاولة.
     *
     * والقاعدة: من لا يملك أن **يَمنح** الصلاحية لا يملك أن **يأخذها**
     * بكلمة مرور. انظر `EmployeeController::refuseTouchingSomeoneAboveMe`.
     *
     * @return list<string> مفاتيحُ ما يفوقه به — أقسامًا وأفعالًا
     */
    public static function beyond(?User $actor, User $employee): array
    {
        if (! $actor) {
            return ['*'];
        }

        /*
         * صاحبُ النشاط يمسّ الجميع، ونفسُه ليست فوقه.
         *
         * و**بشرطِ أن يكون دورُه هو سلطتَه**: صفٌّ دورُه `admin` وصلاحياتُه
         * مخصّصةٌ ليس صاحبَ النشاط — هو حسابٌ ضُيّق عمدًا، و`allows`/`may`
         * تقرآن قائمتَه لا دورَه. فإعفاؤه هنا بالدور كان يعطيه على **الحسابات**
         * ما نُزع عنه في **الشاشات**: يمسّ كلَّ موظّفٍ في المتجر — ويعيد تعيين
         * كلمة مرور المالك — وهو لا يفتح نصف أقسامه.
         *
         * وحارسُ المنح يمنع إنشاء مثله (انظر `EmployeeController::refuseGrantingMoreThanIHave`)،
         * وهذا حارسٌ ثانٍ: صفٌّ كهذا قد يكون مكتوبًا من قبلُ، أو من هجرة، أو
         * بيدِ المالك نفسِه وهو يضيّق شريكًا. ويبقى المالكُ الحقيقيّ — دورُه
         * `admin` بلا تخصيص — على ما كان: يمسّ الجميع بلا قياس.
         */
        if (($actor->role === 'admin' && ! $actor->hasManualPermissions()) || $actor->id === $employee->id) {
            return [];
        }

        return [
            ...array_filter(
                self::SECTIONS,
                fn ($s) => $employee->allows($s) && ! $actor->allows($s),
            ),
            ...array_filter(
                self::actions(),
                fn ($a) => $employee->may($a) && ! $actor->may($a),
            ),
        ];
    }

    /**
     * ما يملك هذا الفاعلُ أن **يمنحه** — أقسامًا وأفعالًا.
     *
     * ويقرؤها الحارسُ والشاشةُ معًا: الحارسُ ليردّ، والنموذجُ ليُعطّل ما لا
     * يُمنح. ولو حُسبت في موضعين لافترقا يومًا — فيُعرض مربّعٌ يُؤشَّر ثمّ
     * تُصفع به صفحةُ ٤٠٣ عند الحفظ، ويضيع النموذجُ كلُّه.
     *
     * @return list<string>
     */
    public static function grantable(?User $actor): array
    {
        if (! $actor) {
            return [];
        }

        return [
            ...array_values(array_filter(self::SECTIONS, fn ($s) => $actor->allows($s))),
            ...array_values(array_filter(self::actions(), fn ($a) => $actor->may($a))),
        ];
    }

    /**
     * ما تحمله وظيفةٌ بدورها — أقسامًا وأفعالًا.
     *
     * وهي سلطةٌ كامنةٌ تبقى في الصفّ ولو ضُيّقت بقائمةٍ مخصّصة: `beyond`
     * تقرأ الدور، و`PANEL_ROLES` تقرؤه، وتقرؤه `MAP` كاملةً يومَ تُنزع
     * القائمة. فمن أسند دورًا أسند ما يحمله.
     *
     * @return list<string>
     */
    public static function roleGrants(string $role): array
    {
        return [
            ...array_values(array_filter(self::SECTIONS, fn ($s) => self::allows($role, $s))),
            ...array_values(array_filter(self::actions(), fn ($a) => self::allowsAction($role, $a))),
        ];
    }

    /** أيُسند هذا الفاعلُ وظيفةً بهذا الدور؟ */
    public static function mayAssignRole(?User $actor, string $role): bool
    {
        return array_diff(self::roleGrants($role), self::grantable($actor)) === [];
    }

    /** هل يمسّ هذا الفاعلُ حسابَ هذا الموظّف؟ */
    public static function mayTouch(?User $actor, User $employee): bool
    {
        return self::beyond($actor, $employee) === [];
    }

    /** هل هذا المفتاح فعلٌ لا قسم؟ — النقطة تفصلهما */
    public static function isAction(string $key): bool
    {
        return str_contains($key, '.');
    }

    /** @return list<string> */
    public static function actions(): array
    {
        return array_keys(self::ACTIONS);
    }

    /** أسماء الأفعال كما تُعرض للتاجر */
    public static function actionLabels(): array
    {
        return collect(self::ACTIONS)->map(fn ($label) => __($label))->all();
    }

    /** هل يفعلها هذا الدور بلا منحٍ باسمه؟ */
    public static function allowsAction(?string $role, string $action): bool
    {
        return in_array($role, self::ACTION_ROLES[$action] ?? [], true);
    }

    /** كل الأقسام التي تظهر في لوحة النشاط — مصدر واحد تقرأ منه الواجهة */
    public const SECTIONS = [
        'dashboard', 'customers', 'products', 'quotations', 'projects', 'orders',
        'inventory', 'finance', 'expenses', 'settings',
        'suppliers', 'purchases', 'employees', 'reports',
        // سجلُّ المراسلات وقوالبُها — المرحلة 6. للمالك والمدير بـ`*`، ويُمنح بالاسم لغيرهما
        'communications',
        // مركزُ العمليات — المرحلة 7: ما يحتاج يدًا اليوم. للمالك والمدير، ويُمنح بالاسم
        'command',
        // الأتمتة — المرحلة 8: القواعدُ والسجلّ. للمالك والمدير، ويُمنح بالاسم
        'automation',
        // استيرادُ البيانات التاريخيّة — المرحلة 9. للمالك والمدير بـ`*`، ويُمنح بالاسم
        'imports',
        // تشغيلُ الفعاليّات — المرحلة 10: الفعاليّاتُ وشاشةُ الموقع. وللمنفّذ الميدانيّ
        'operations',
        // والمعدّاتُ ذاتُ الهويّة — المرحلة 10. ولأمين المخزن
        'assets',
        // الذكاءُ الإداريّ — المرحلة 11: لوحةُ الأداء والموازنةُ والتوقّع. للمالك والمدير، ويُمنح بالاسم
        'management',
    ];

    /**
     * الأدوار التي تدخل اللوحة.
     *
     * مصدرٌ واحد: يقرأه middleware المسار، ويقرأه الخادم ليخبر الواجهة هل
     * تُظهر زر «العودة إلى اللوحة» أم لا. الفصل بينهما كان سيُظهر زرًّا
     * يقود إلى 403.
     */
    public const PANEL_ROLES = ['admin', 'manager', 'accountant', 'inventory', 'sales', 'delivery'];

    /**
     * لا قسم مفتوحًا بلا منح.
     *
     * كانت «لوحة التحكم» و«الفروع» تُفتحان لكل من دخل مهما كانت صلاحياته —
     * فصاحب العمل يرفع علامة عنها ولا يتغيّر شيء، وهو أسوأ ما يكون: منعٌ
     * ظاهرٌ في الشاشة لا وجود له في الواقع. صارتا تُمنحان صراحةً كغيرهما،
     * فما لم يُعلَّم لا يُفتح.
     */
    public const ALWAYS_OPEN = [];

    /**
     * أقسام تُمنح ولا تفتح باب اللوحة.
     *
     * فارغةٌ اليوم: كلُّ قسمٍ قائمٍ بابُه في اللوحة. وتبقى لأنّ `entersPanel`
     * تقرؤها، فقسمٌ يُضاف خارجها غدًا يُكتب هنا لا في شرطٍ متفرّق.
     */
    public const OUTSIDE_PANEL = [];

    /**
     * هل يدخل هذا المستخدم لوحة النشاط؟
     *
     * الدور وحده لا يكفي منذ صارت الصلاحيات تُخصَّص يدويًّا: موظّفٌ مُنح
     * صلاحية المخزون كان يُمنع عند الباب فلا تصل صلاحيته إلى الفحص أصلًا —
     * ميزةٌ تُحفَظ في القاعدة ولا تعمل. فمن مُنح قسمًا من أقسام اللوحة يدخل
     * ليصل إليه، ويبقى ما لم يُمنح محجوبًا عنه بحارس القسم.
     */
    public static function entersPanel(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if (in_array($user->role, self::PANEL_ROLES, true)) {
            return true;
        }

        /*
         * والفعلُ لا يفتح بابًا.
         *
         * `permissions` تحمل الأقسام والأفعال معًا، ومن مُنح «تصحيح فاتورة»
         * وحدَه لم يُمنح شاشةً واحدة — فلو عُدَّ منحُه دخولًا لوقف عند أوّل
         * قسمٍ بـ٤٠٣، أو رأى لوحةً فارغة لا شيء فيها له.
         */
        return collect($user->permissions ?? [])
            ->filter(fn ($s) => in_array($s, self::SECTIONS, true))
            ->reject(fn ($s) => in_array($s, self::OUTSIDE_PANEL, true))
            ->isNotEmpty();
    }

    /**
     * أوّل صفحةٍ في اللوحة يفتحها هذا المستخدم فعلًا — أو null.
     *
     * `entersPanel` تجيب «هل يدخل؟» ولا تقول «إلى أين». وزرّ «لوحة النشاط»
     * في نقطة البيع كان يعتمد عليها ثم يقود إلى `admin.dashboard` دائمًا،
     * فموظّفٌ مُنح المخزون وحده يرى الزرّ — لأنه يدخل اللوحة — ويصطدم بـ403
     * على قسمٍ لم يُمنحه. بابٌ يُعرض ولا يُفتح، وهو أسوأ من بابٍ لا يُعرض:
     * الموظّف يظنّ العطب في النظام ويعيد المحاولة، والتاجر يظنّ أن صلاحياته
     * لم تُحفظ.
     *
     * فيُسأل عن الوجهة لا عن الإذن، ومن لا وجهة له لا يرى الزرّ.
     */
    public static function panelEntry(?User $user): ?string
    {
        if ($user === null || ! self::entersPanel($user)) {
            return null;
        }

        return collect(self::SECTIONS)
            ->reject(fn ($s) => in_array($s, self::OUTSIDE_PANEL, true))
            ->filter(fn ($s) => $user->allows($s))
            ->map(fn ($s) => self::routeFor($s))
            ->first(fn ($r) => $r !== null);
    }

    /** القسم → مساره. مصدرٌ واحد يقرؤه التوجيه بعد الدخول وروابط التنبيهات */
    public const ROUTES = [
        'dashboard' => 'admin.dashboard', 'customers' => 'admin.customers.index',
        'products' => 'admin.products.index', 'orders' => 'admin.orders.index',
        'inventory' => 'admin.inventory.index',
        'finance' => 'admin.finance.index', 'expenses' => 'admin.expenses.index',
        'settings' => 'admin.settings.index', 'suppliers' => 'admin.suppliers.index',
        'purchases' => 'admin.purchases.index', 'employees' => 'admin.employees.index',
        // «التقارير» كانت في SECTIONS ولا مسار لها هنا: من أوّلُ ما مُنح له
        // التقاريرُ يسقط على `ROUTES['reports']` غير الموجود، فيرتفع خطأ
        // مفتاحٍ ناقص داخل HandleInertiaRequests — أي ٥٠٠ على كل صفحةٍ يفتحها
        'reports' => 'admin.reports.index',
        'quotations' => 'admin.quotations.index',
        'projects' => 'admin.projects.index',
        'communications' => 'admin.communications.index',
        'command' => 'admin.command.index',
        'automation' => 'admin.automation.index',
        'imports' => 'admin.imports.index',
        'operations' => 'admin.operations.index',
        'assets' => 'admin.assets.index',
        'management' => 'admin.management.index',
    ];

    /**
     * مسار القسم إن كان له باب — وإلا null.
     *
     * القراءة المباشرة من ROUTES كانت تنفجر على قسمٍ نُسي فيها بدل أن تتخطّاه:
     * والفشل هنا يقع في مشاركة Inertia، فيصير خمسمئةً على كلّ صفحة لا بابًا
     * مفقودًا في قائمة.
     */
    private static function routeFor(string $section): ?string
    {
        return isset(self::ROUTES[$section]) ? route(self::ROUTES[$section]) : null;
    }

    /**
     * أوّل صفحة يراها المستخدم بعد الدخول.
     *
     * كانت تُختار بالدور وحده، فتُرسل كلّ من ليس مديرًا إلى شاشةٍ لا يملكها.
     * ومنذ صارت الصلاحيات تُخصَّص، صار ذلك يعني دخولًا ناجحًا ينتهي إلى 403.
     * فيُختار القسم من صلاحياته: لوحتُه إن ملكها، ثمّ أوّل ما مُنح.
     *
     * وبابان يجب أن يتّفقا هنا: صلاحية القسم، ودخول اللوحة — فلا يُقترح
     * قسمٌ من اللوحة على من لا يدخلها.
     */
    public static function homeFor(User $user): string
    {
        $panel = self::entersPanel($user);

        if ($panel && $user->allows('dashboard')) {
            return route(self::ROUTES['dashboard']);
        }

        // بلا صلاحية واحدة: تُرفض الآن عند الحفظ، لكن حسابًا قديمًا قد يسبقها
        return ($panel ? self::panelEntry($user) : null) ?? route('login');
    }

    /** أسماء الأقسام كما تُعرض للتاجر — الواجهة لا تخمّنها من المفتاح */
    public static function sectionLabels(): array
    {
        $labels = [
            'dashboard' => 'الملخص', 'customers' => 'العملاء', 'products' => 'المنتجات والخدمات',
            'orders' => 'المبيعات', 'inventory' => 'المخزون',
            'finance' => 'المالية', 'expenses' => 'مصاريف شهرية', 'settings' => 'الإعدادات',
            'suppliers' => 'الموردين', 'purchases' => 'المشتريات',
            'employees' => 'الرواتب والموظفين',
            // كانت ساقطةً فتُعرض «reports» بحروفٍ لاتينية في قائمة صلاحيات عربية
            'reports' => 'التقارير',
            'quotations' => 'عروض الأسعار',
            'projects' => 'المشاريع والمعارض',
            'communications' => 'المراسلات',
            'command' => 'مركز العمليات',
            'automation' => 'الأتمتة',
            'imports' => 'استيراد البيانات',
            'operations' => 'العمليات',
            'assets' => 'المعدّات',
            'management' => 'الإدارة',
        ];

        return collect(self::SECTIONS)
            ->mapWithKeys(fn ($s) => [$s => __($labels[$s] ?? $s)])
            ->all();
    }

    public static function sections(): array
    {
        return self::SECTIONS;
    }

    /**
     * ما يفتحه الدور — والمجهول لا يفتح شيئًا.
     *
     * كان السطر ينتهي بـ`?? ['*']`، فأيّ دورٍ لا تعرفه الخريطة يمرّ بكلّ
     * قسم: خطأٌ مطبعيّ في الحقل، أو صفٌّ قديم بدورٍ أُلغي، أو طلبٌ يُرسَل
     * إلى المسار مباشرةً بدورٍ مخترَع — كلّها كانت تنتهي إلى صلاحياتٍ كاملة.
     * والفشل في بابٍ يجب أن يُغلقه لا أن يفتحه على مصراعيه.
     *
     * وصاحبُ النشاط ومديرُ الفرع يبقيان على `*` صراحةً في الخريطة، فلا
     * يمسّ هذا التشديدُ أحدًا يعمل اليوم.
     */
    public static function abilities(?string $role): array
    {
        return self::MAP[$role] ?? [];
    }

    public static function allows(?string $role, string $section): bool
    {
        $abilities = self::abilities($role);
        if (in_array('*', $abilities, true)) {
            return true;
        }
        if (in_array($section, self::ALWAYS_OPEN, true)) {
            return true;
        }

        return in_array($section, $abilities, true);
    }

    /**
     * مسارات هيكل اللوحة لا أقسامها: الجرس، والبحث، ومبدّلا اللغة والعملة.
     *
     * هذه أدوات الشريط العلوي التي يراها كلّ من دخل اللوحة أيًّا كان قسمه —
     * ليست بابًا إلى بيانات قسم، فلا يُشتقّ لها مفتاح صلاحية. والبحث يصفّي
     * نتائجه بنفسه حسب ما يملكه صاحبه (انظر SearchController).
     */
    public const SHELL = [
        'admin.search', 'admin.currency.switch', 'admin.language.update',
        'admin.notifications.feed', 'admin.notifications.dismiss', 'admin.notifications.clear',
        // وقراءةُ تنبيهٍ داخليّ من الجرس — لكلّ من دخل اللوحة، والتنبيهُ لصاحبه وحده — المرحلة 8
        'admin.notifications.internal.read',
    ];

    /**
     * مسارٌ اسمه لا يشتقّ قسمه — يُنسب صراحةً إلى القسم الذي يملكه.
     *
     * الاشتقاق من الاسم يعمل ما دام الاسم يطابق مفتاح الصلاحية. وحين يفترقان
     * يُنتج مفتاحًا لا وجود له في SECTIONS، فلا يملكه أحد: يُمنع منه كلّ من
     * خُصّصت صلاحياته يدويًّا مهما مُنح، ويُمنع منه كلُّ دورٍ إلا المالك
     * والمدير (لهما '*'). والنتيجة صفحةٌ في القائمة لا تُفتح، أو علامةٌ
     * يرفعها صاحب النشاط ولا تغيّر شيئًا.
     *
     * أوضحها «الفروع»: مساره admin.branches يشتقّ 'branches' والمفتاح
     * 'branch' — فمنحُها كان بلا أثر.
     */
    public const ALIASES = [
        // تبديل الفرع صار في قائمة الحساب بالهيدر، وصفحة الفروع في الإعدادات
        'branches' => 'settings',
        'branch' => 'settings',
        'addons' => 'products',
        'jobTitles' => 'employees',
        // مسيرة الرواتب وصرفها من قسم «الرواتب والموظفين» — لا مفتاح ثالث لها
        'payroll' => 'employees',
        'bank' => 'finance',
        'expenseTypes' => 'expenses',
        'goals' => 'dashboard',
        'alerts' => 'settings',
        'backup' => 'settings',
        // فواتيرُ العملاء مستندُ بيع: من يرى الطلبات يراها
        'customerInvoices' => 'orders',
        // والتحصيلُ مالٌ يدخل الصندوق: بابُه المالية
        'customerPayments' => 'finance',
        // وحزمُ التسليم تتبع عروضَ الأسعار: من يعرض على العميل يسلّمه — المرحلة 5
        'deliveries' => 'quotations',
        // تهيئةُ المتجر أوّلَ مرّة تكتب بيانات النشاط — فتتبع بابَها
        'setup' => 'settings',
        'activity' => 'settings',
        // وأمرُ التغيير تعديلٌ على مشروع: بابُه بابُ المشاريع، ولا قسمَ له
        'changeOrders' => 'projects',
        // والمهامُّ مهامُّ المشاريع: من يرى المشاريعَ يرى مهامَّها
        'tasks' => 'projects',
        // وصندوقُ وارد واتساب محادثاتٌ مع العملاء: بابُه بابُ المراسلات
        'whatsapp' => 'communications',
    ];

    /**
     * التصدير يتبع قسم ما يُصدَّر: admin.export.orders → orders.
     *
     * وما ليس هنا يسقط إلى «الإعدادات» — أضيقُ ما يُمنح. و`reports` كانت
     * ساقطةً فيه: زرّ «CSV» في ملخّص المبيعات يُرسم لكل من يفتح الصفحة،
     * ويُطالَب صاحبه عند الضغط بصلاحية الإعدادات. فالمحاسب — وهو أكثر من
     * يُصدّر — يفتح تقريرًا مأذونًا له ويُردّ بـ٤٠٣ عن ملفّه.
     */
    public const EXPORT_ALIASES = [
        'products' => 'products', 'orders' => 'orders', 'customers' => 'customers',
        'transactions' => 'finance', 'expenses' => 'expenses', 'inventory' => 'inventory',
        'reports' => 'reports',
        // و«الموردون» كانت ساقطةً مثلها: زرّ CSV في شاشة المورّدين يُرسم لمن
        // يملك القسم، ويُطالَب عند الضغط بصلاحية الإعدادات — وأمين المخزن
        // يملك المورّدين ولا يملك الإعدادات، فلا يُنزِّل ملفّ شاشته أبدًا.
        // وحارسٌ في ReportsTellTheirScopeTest يمنع سقوط الثامن.
        'suppliers' => 'suppliers',
    ];

    /** هل هذا المسار من هيكل اللوحة لا من أقسامها؟ */
    public static function isShell(?string $route): bool
    {
        return $route !== null && in_array($route, self::SHELL, true);
    }

    /** استخراج القسم من اسم المسار: admin.products.index → products */
    public static function sectionFromRoute(?string $route): string
    {
        if (! $route) {
            return 'dashboard';
        }
        $parts = explode('.', $route);
        $section = $parts[1] ?? 'dashboard';

        if ($section === 'export') {
            // ما لا يُعرف ما يُصدَّر يتبع الإعدادات: أضيقُ ما يُمنح
            return self::EXPORT_ALIASES[$parts[2] ?? ''] ?? 'settings';
        }

        return self::ALIASES[$section] ?? $section;
    }
}
