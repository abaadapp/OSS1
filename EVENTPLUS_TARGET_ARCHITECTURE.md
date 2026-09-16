# معمارية Event Plus المستهدَفة

> **دراسةُ تصميمٍ — لا تنفيذ.** لم يُعدَّل في هذه المرحلة ملفٌّ واحد سوى هذا
> الملفّ: لا هجرة، ولا نموذج، ولا متحكّم، ولا اختبار، ولا commit.
>
> **التاريخ:** 2026-09-16 · على `remove-sections` عند `7ce9397`
> **يُقرأ بعد:** [EVENTPLUS_SYSTEM_STATE.md](EVENTPLUS_SYSTEM_STATE.md) — وصفُ ما هو
> **ويُقرأ معه:** [EVENTPLUS_SYSTEM_AUDIT.md](EVENTPLUS_SYSTEM_AUDIT.md) — سجلُّ ما كان

**المصدر:** الكودُ نفسُه. كلُّ رقمٍ وكلُّ ادّعاءٍ هنا مقروءٌ من ملفٍّ أو من
مخطَّط قاعدةٍ أو من تشغيل `artisan` — لا من التوثيق. وحيث أخالف التوثيقَ
السابق أقول ذلك صراحةً.

---

## 0. ما وجدتُه قبل أن أصمّم — ولا يجوز أن يمرّ في صمت

هذه أربعةُ أشياء اكتشفتُها في الفحص، وكلُّها تسبق أيَّ نقاشٍ عن المعمارية،
لأنّ ثلاثةً منها **أعطابٌ حيّة الآن** والرابع خطأٌ محاسبيّ سيقع في أوّل
مشروع.

### 0.1 ⚠️ طبقةُ التقارير واللوحة عمياءُ تمامًا عن إيراد الشركة

هذا أخطرُ ما وجدت، وهو مُثبَت لا مستنتَج:

| الملفّ | يقرأ `orders` | يقرأ `customer_invoices` |
|---|---|---|
| `ReportData.php` | **12 موضعًا** | **صفر** |
| `DashboardMetrics.php` | **2** | **صفر** |
| `Demo.php` | **32** | **صفر** |

**ولا مسارَ HTTP واحدٍ يُنشئ `Order`.** الكاتبون الوحيدون هما `DemoStore`
(بذرة) و`SeedSalesHistory` (أمرٌ يدويّ). فجدولُ `orders` في تثبيتِ Event Plus
**فارغٌ ويبقى فارغًا إلى الأبد**.

النتيجة على النظام كما هو الآن:

- **لوحةُ التحكم تقرأ صفرًا** مهما فوترت الشركةُ وحصّلت.
- **«ملخّص المبيعات»** و«العملاء الأكثر إنفاقًا» و«أداء الموظفين» و«الطلبات»
  — أربعةُ تقاريرَ تُرجع صفرًا.
- **وأخطرُها الإقرار الضريبيّ.** `ReportData::vat()` يحسب ضريبةَ المخرجات من
  `orders` وحدها (السطر 103) وضريبةَ المدخلات من سندات الموردين. ففاتورةُ
  عميلٍ تُصدَر اليوم بضريبةٍ قدرها 5٪ **لا تظهر في الإقرار**. النظامُ يقول
  «ضريبةٌ مستحقّة عليك: صفر» بينما دفترُ الأستاذ فيه رصيدٌ في `tax_payable`.
  رقمان يقولان الشيء نفسه ويفترقان — وهو الصنفُ الذي يحرسه هذا المستودعُ
  في كلّ موضعٍ آخر.

> **هذا يعيد ترتيب المراحل.** لم أكن سأضع «تحويل مصادر التقارير» في المرحلة
> الأولى — لكنّه ليس تحسينًا مؤجَّلًا، هو إصلاحُ إقرارٍ ضريبيّ خاطئ. انظر §30.

### 0.2 ⚠️ الدفعة المقدَّمة تُقيَّد **أصلًا مدينًا سالبًا** لا **التزامًا**

طلبتَ صراحةً: «Advance + Final» و«50/50» و«Milestones». وهذه هي الحالة
الغالبة في أعمال الفعاليات. وفحصتُ ما يفعله النظام اليوم:

`CustomerPayments::record()` تقبل دفعةً بلا فواتيرَ مفتوحة، وتترك المبلغ
«غيرَ مخصَّص» (وهو تصميمٌ حسن). لكنّ `post()` تكتب دائمًا:

```
مدين: الصندوق/البنك     دائن: ذمم العملاء (receivable)
```

فعربونُ مشروعٍ لم تُصدَر له فاتورةٌ بعد يجعل **ذمّة العميل سالبة** — أصلٌ
بقيمةٍ دون الصفر في الميزانية. والصواب أنّه **التزام**: «دفعاتٌ مقدَّمة من
العملاء». ويترتّب على الخطأ أمران:

1. الميزانيةُ تعرض أصلًا سالبًا بدل خصمٍ حقيقيّ.
2. **الضريبة.** الضريبةُ في عُمان تستحقّ عند قبض الدفعة المقدَّمة. ودفعةٌ بلا
   فاتورةٍ لا ضريبةَ فيها في هذا النظام.

**الحلّ — وهو إعادةُ استخدامٍ لا بناءٌ جديد:** لا تُقبض دفعةٌ مقدَّمةٌ «حرّة».
يُصدَر لها **مستندُ فاتورةٍ للدفعة المقدَّمة** (`CustomerInvoice` عاديّة
ببندٍ واحد: «دفعة مقدّمة — مشروع X · 50٪»)، فتمرّ بـ`CustomerInvoices::issue()`
كما هي: تُقيَّد الضريبة، وتُنشأ الذمّة، ثمّ يُخصَّص التحصيل عليها. صفرُ كودٍ
ماليٍّ جديد. انظر §16.

### 0.3 كتابةُ المرفقات مكرَّرةٌ خمسَ مرّات

قبل أن نضيف ملفّات المشروع، هذه حالُ حفظ الملفّات اليوم:

| الموضع | كيف |
|---|---|
| `customer_invoice_attachments` | **جدولٌ كامل** (path · name · mime · size · uploaded_by) |
| `purchase_orders.attachment` + `attachment_name` | عمودان على الصفّ |
| `supplier_invoices.attachment` + `attachment_name` | عمودان على الصفّ |
| `goods_receipt_notes.attachment` + `attachment_name` | عمودان على الصفّ |
| `expenses.attachment` + `attachment_name` | عمودان على الصفّ |

خمسةُ أشكالٍ لشيءٍ واحد، أربعةٌ منها تقبل **ملفًّا واحدًا فقط** ولا تعرف من
رفعه ولا متى. والمشروع يحتاج عشرين ملفًّا مصنَّفًا بنسخٍ ومواعيد. انظر §17.

### 0.4 مدخلٌ ميّتٌ يقود إلى مسارٍ غير موجود

`CustomAlert::url()` فيها `'profitability' => 'admin.profitability.index'` —
ولا وجود لهذا المسار في الـ298. ولا ينفجر اليوم لسببٍ واحد: التحقّق في
`CustomAlertController` يقصر `section` على `Permissions::sections()` وليس فيها
`profitability`. فهو لغمٌ نائم: أوّلُ من يضيف `profitability` إلى الأقسام
يُسقط شاشةَ التنبيهات. **ليس عطبًا اليوم، ويصير عطبًا في المرحلة القادمة
بالتحديد** — لأنّنا سنضيف أقسامًا.

---

## 1. الحالة الراهنة بعد التنظيف — ملخَّص

نظامٌ إداريٌّ داخليٌّ صحيحُ البنية، أساسُه المحاسبيّ متينٌ وواجهتُه التشغيليّة
ما زالت واجهةَ متجر.

| البند | العدد | الحال |
|---|---|---|
| المسارات | 298 | صفر معلّق |
| النماذج | 53 | — |
| الجداول | 64 | — |
| أصناف الدعم | 77 | فيها `Demo` بـ**2902 سطر** و**86 دالّة** |
| اختبارات PHP | 2053 | كلُّها تمرّ |
| اختبارات الواجهة | 114 | كلُّها تمرّ |
| TypeScript | — | صفر أخطاء |

**ما هو متينٌ فعلًا** (مقروءٌ من الكود لا مفترَض):

- **دفترُ أستاذٍ مزدوج القيد كامل.** `Ledger::post` بابٌ واحد يفرض التوازن،
  ويمنع الحسابَ في طرفَي القيد، ويرفض الترحيل إلى حسابٍ له فروع. و26 حسابًا
  نظاميًّا، و`ensureSystemAccounts` تستدرك حسابًا يُضاف لاحقًا إلى شجرةٍ
  قائمة — وهي آليّةٌ سنستفيد منها مرّتين في هذا التصميم.
- **دورةُ فاتورة العميل ناضجة.** مسودّة → إصدار (قفلُ صفٍّ يمنع الإصدار
  المزدوج) → قيدٌ → تحصيل بتخصيصات → إشعارٌ دائن → إلغاءٌ بعكسٍ لا محو.
  و«المتأخّرة» و«المسدَّدة» **تُشتقّ ولا تُخزَّن** — قاعدةٌ ممتازة سنتبعها.
- **نظامُ مستنداتٍ كامل.** ثمانيةُ أنواعِ أوراق، ولكلٍّ قالبُه وحقولُه
  المضبوطة من الإعدادات؛ ومعاينةٌ تُرسم **بالقالب الذي يُطبع** لا بنسخةٍ في
  الشاشة؛ و`Snapshot` تختم الورقةَ بحال الشركة يومَ صدورها؛ و`Version` تحفظ
  أيُّ قالبٍ رسمها؛ و`DocumentLink` يمنحها رمزًا عامًّا من 22 حرفًا.
- **صلاحياتٌ ثلاثيّةُ الطبقات**: دور → قسم → فعل، والفعلُ يُميَّز عن القسم
  بالنقطة في اسمه.
- **نمطُ اعتمادٍ مكتوبٌ مرّتين ويعمل**: `goods_receipt_notes` و
  `supplier_invoices` كلاهما فيه `submitted_by · approved_at/by ·
  rejected_at/by · rejection_reason` — وفي الثاني `override_by/at/reason`.
  هذا **قالبُ سير الموافقات** الذي سنستنسخه لعرض السعر، لا محرّكٌ جديد.
- **العميلُ جاهزٌ للشركات أصلًا**: `customer_type` · `legal_name` ·
  `commercial_registration` · `contact_person/email/phone` ·
  `billing_address` · `department` · `customer_reference` ·
  `allow_credit_sales` · `credit_limit` · `payment_terms_days` ·
  `monthly_billing`. **لا يحتاج حقلًا واحدًا** ليصير «Client».
- **شاشةُ إنشاء فاتورةٍ بـ2176 سطرًا** فيها: اختيارُ عميلٍ وإنشاؤه في مكانه ·
  كتالوجٌ يُبحث فيه بالاسم والرمز والباركود · بنودٌ حرّة · كميّة وسعرٌ وخصمٌ
  وضريبةٌ لكلّ بند · إجمالياتٌ حيّة · معاينةٌ من الخادم بالقالب الحقيقيّ ·
  مرفقاتٌ · حفظٌ كمسودّة أو إصدارٌ بحسب الصلاحية. **هذه تسعةُ أعشار مُنشئ
  عرض السعر، مبنيّةً ومختبَرة.**

**وما هو مكسورٌ مفهوميًّا**: كلُّ ما يقرأ `orders` (§0.1).

---

## 2. دورةُ العمل المستهدَفة

```
   العميل  (Customer — قائم، لا يحتاج حقلًا)
      │
      ▼
   عرض سعر  (Quotation — جديد)  ──revision──▶ عرضٌ مُنقَّح
      │                                            │
      │◀────────────── يُرسل برابطٍ عامّ ───────────┘
      ▼
   قبولُ العميل  (DocumentApproval — جديد)
      │   ‹‹ الشروطُ والسياساتُ مختومةٌ داخل العرض لحظةَ إرساله ››
      ▼
   المشروع / الفعالية  (Project — جديد)
      │
      ├── تخطيط ──── مهامّ · فريق · معالم
      ├── شراء  ──── PurchaseOrder → GRN → SupplierInvoice   (قائمٌ كلُّه)
      ├── مخزون ──── InventoryMovement · StockAdjustment      (قائمٌ كلُّه)
      ├── مصروف ──── Expense                                  (قائم)
      └── تنفيذٌ ميدانيّ
      │
      ▼
   حزمةُ التسليم  (Delivery — جديد، فوق DeliveryNote القائم)
      │
      ▼
   قبولُ التسليم  (DocumentApproval — الآليّةُ نفسُها)
      │
      ▼
   فاتورة عميل  (CustomerInvoice — قائمة، تُستعمل كما هي)  × 1..n
      │
      ▼
   تحصيل  (CustomerPayment + Allocation — قائم)
      │
      ▼
   المحاسبة  (Ledger — قائمة، بلا حرفٍ جديد)
      │
      ▼
   إقفالُ المشروع  (Project::close — حسابُ الربحيّة يُجمَّد)
```

**المبدأ الحاكم في هذه الصورة:** المشروعُ **حاويةٌ تشغيليّةٌ وبُعدٌ تحليليّ**
— وليس مستندًا ماليًّا. لا يُقيَّد المشروعُ في الدفتر أبدًا. تُقيَّد
مستنداتُه: فاتورةُ العميل، وسندُ المورّد، والمصروف. والمشروعُ يجمعُها
ويقرؤها.

هذا التمييز هو الفرقُ بين تصميمٍ صحيحٍ وتصميمٍ يُنشئ نظامًا ماليًّا ثانيًا.

---

## 3. الوحدات المقترَحة

| # | الوحدة | حالُها | تعتمد على |
|---|---|---|---|
| M1 | **الكتالوج** (منتجات وخدمات وباقات) | **تعديلُ القائم** | `Product` · `Category` · `RecipeItem` |
| M2 | **عروض الأسعار** | **جديدة** | M1 · `Customer` · نظام المستندات |
| M3 | **الاعتماد والتوقيع الإلكتروني** | **جديدة** | `DocumentLink` · `paper.show` |
| M4 | **المشاريع/الفعاليات** | **جديدة** | M2 |
| M5 | **السياسات والاستثناءات** | **جديدة، صغيرة** | M2 · M4 |
| M6 | **مستندات المشروع** | **جديدة** (توحيد 5 أنماط) | M4 |
| M7 | **التسليم والقبول** | **جديدة، فوق قائم** | M3 · M4 · `DeliveryNote` |
| M8 | **ماليّةُ المشروع** | **قراءةٌ فوق القائم** | `CustomerInvoice` · `Expense` · `PurchaseOrder` · `SupplierInvoice` |
| M9 | **مركزُ الإجراءات** | **توسيعُ القائم** | `Demo::notifications` · `CustomAlert` · `DismissedNotification` |
| M10 | **الأتمتة** | **جديدة، صغيرة** | M9 · المجدول |
| M11 | **الرسائل الموحَّدة (واتساب)** | **جديدة، خلف واجهة** | `PhoneNumber` · `Mailer` |
| M12 | **المساعد الذكي** | **جديدة، طبقةُ اقتراح** | M2 |
| M13 | **بين الشركات** | **مؤجَّلة بشرط** | `Ledger::ensureSystemAccounts` |

**ولا وحدةَ جديدةً لـ**: المحاسبة · الفواتير · التحصيل · المشتريات ·
المخزون · الموظفين · الرواتب · التقارير · الصلاحيات · النسخ الاحتياطي ·
سجلّ النشاط. كلُّها تُستعمل كما هي.

---

## 4. دورةُ حياة عرض السعر

### 4.1 نقدُ الدورة التي اقترحتَها

اقترحتَ: `Draft → Internal Approval → Sent → Viewed → Client Accepted /
Rejected / Expired / Revised / Cancelled`. وثلاثٌ منها **ليست حالاتٍ**،
ووضعُها في عمود `status` يُدخِل النظامَ في أعطابٍ يعرفها هذا المستودعُ
ويحرسُ نفسَه منها في مواضعَ أخرى:

| المقترَح | لماذا لا يصلح حالةً | الدليل من الكود |
|---|---|---|
| **`Viewed`** | «شُوهد» **واقعةٌ لها ختمُ وقت**، لا موضعٌ في المسار. جعلُها حالةً يفقدك «أُرسل ولم يُفتح قطّ» مقابل «فُتح خمسَ مرّات»، ويجبرك على انتقالٍ `sent → viewed → accepted` بلا معنى. والأسوأ: عرضٌ قُبل بمكالمةٍ هاتفيّة بلا فتحِ الرابط يبقى عالقًا | الصواب: `sent_at` · `first_viewed_at` · `view_count` |
| **`Expired`** | مشتقّةٌ بحتة: `status = sent AND valid_until < today`. وتخزينُها يستلزم مهمّةً ليليّة، وأوّلُ ليلةٍ تسقط فيها تجعل الشاشةَ تكذب | `CustomerInvoice::paymentState()` اتّخذت هذا القرار حرفيًّا: «و«متأخرة» محسوبةٌ من تاريخ الاستحقاق لا حالةٌ في عمود: حالةٌ تُخزَّن تحتاج مهمّةً ليليّة، وأوّلُ ليلةٍ تسقط فيها تجعل الشاشة تقول «غير مستحقّة» عن دَينٍ تأخّر شهرًا» — **اتّبع قاعدةَ البيت** |
| **`Revised`** | ليست حالةَ العرض، بل **علاقةٌ** بين عرضين. العرضُ القديم يصير `superseded` والجديد يبدأ `draft` | نمطُ `journal_entries.reverses_id` نفسُه: الأصلُ يبقى ويُشار إليه |

### 4.2 الدورة المقترَحة — 7 حالاتٍ مخزَّنة

```
                    ┌──────────────────────────────┐
                    ▼                              │
  draft ──▶ pending_approval ──▶ sent ──▶ accepted │
    │  ▲            │              │               │
    │  │            │ (رُفض داخليًّا)│               │
    │  └────────────┘              ├──▶ rejected   │
    │                              │               │
    └──────────────────────────────┴──▶ cancelled  │
                                   │               │
                        (تنقيح) ───┴──▶ superseded ┘
```

| الحالة | المعنى | من ينقلها |
|---|---|---|
| `draft` | تُكتب — تُعدَّل بحرّية | `quotation.create` |
| `pending_approval` | تنتظر اعتمادًا داخليًّا | تلقائيّ عند شرطٍ (§18) |
| `sent` | **مجمَّدة**. خرجت من الشركة | `quotation.send` |
| `accepted` | قَبِل العميل | العميلُ عبر الرابط، أو موظّفٌ بـ`quotation.recordDecision` |
| `rejected` | رفض العميل | كسابقتها + سببٌ مطلوب |
| `cancelled` | أُلغي داخليًّا قبل القبول | `quotation.cancel` |
| `superseded` | حلَّ محلَّه تنقيحٌ أحدث | تلقائيّ عند إنشاء التنقيح |

**وحالاتٌ لا تُخزَّن، تُشتقّ** (دالّةُ `presentedState()` على غرار
`CustomerInvoice::paymentState()`):

```
expired          = sent  AND valid_until < today
awaiting_client  = sent  AND valid_until >= today
unopened         = sent  AND first_viewed_at IS NULL
needs_follow_up  = sent  AND sent_at < now() - followUpDays  ← مركزُ الإجراءات
```

### 4.3 التجميد والتنقيح — كيف

**عرضٌ أُرسل لا يتغيّر بصمت.** والآليّةُ موجودةٌ ومختبَرةٌ ولا تُخترع:

1. عند `send`: تُستدعى `Snapshot::stamp($quotation, $bid)` — تكتب هويّة
   الشركة وعملتَها ونسخةَ القالب في `document_snapshot`، **ولا تُعيد الختمَ
   إن كان مختومًا**. هذا سلوكُها المكتوب حرفيًّا.
2. ويُضاف إليه ما هو خاصٌّ بعرض السعر: `terms_snapshot` — نصُّ الشروط
   والسياسات كما كانت لحظتَها (§18).
3. ويُحسب `content_hash` = `sha256` على (البنود + الإجماليات + الشروط). وهو
   ما يجعل القبولَ **دليلًا**: سجلُّ القبول يحمل البصمة، فيُثبَت بعد سنةٍ
   أنّ ما قُبل هو هذا بعينه.
4. بعد `sent`، أيُّ محاولةِ تعديل **تُرفض** في الخادم لا في الشاشة. والسبيلُ
   الوحيد: `revise()` تنسخ الصفَّ ببنوده، `revision + 1`، و`root_id` نفسُه،
   والحالة `draft`، والأصلُ يصير `superseded`.

### 4.4 ضبطُ الخصم — وهو جوهرُ ما طلبت

طلبتَ: «لا يستطيع الموظف تغيير Base price / Discount... إلا حسب Permissions
واضحة» و«إذا أعطى خصمًا: السبب · من طلبه · من اعتمده · القيمة قبل وبعد ·
Audit trail».

**التصميم — وقاعدتُه أنّ الخادم لا يثق بالشاشة أبدًا:**

سطرُ العرض يحمل عمودين لا واحدًا:

| العمود | من أين | أيُعدَّله الموظّف؟ |
|---|---|---|
| `list_price` | **من `Product::sellingPrice()` في الخادم، لحظةَ إضافة البند** | ❌ أبدًا — لا يُقبل من الطلب إطلاقًا |
| `unit_price` | ما سيُفوتَر به فعلًا | ✅ بشروط |
| `discount_amount` | **محسوبٌ**: `(list_price − unit_price) × quantity` | ❌ لا يُرسَل، يُشتقّ |

والقاعدةُ عند الحفظ:

```
unit_price  = list_price                → يمرّ بلا شيء
unit_price  < list_price  (خصمٌ ≤ الحدّ) → يلزم quotation.discount + سببٌ نصّيّ
unit_price  < list_price  (خصمٌ > الحدّ) → يلزم ما سبق + انتقالٌ إلى pending_approval
unit_price  > list_price                → يلزم quotation.overridePrice + سبب
بندٌ حرٌّ بلا product_id                 → list_price = unit_price، ولا خصم
```

الحدُّ (`discount_threshold_percent`) سياسةٌ تُدار من الإعدادات، لا رقمٌ في
الكود.

**والأثر يُكتب في ثلاثة مواضعَ لا واحد**، وكلُّها قائمة:

1. عمودان على السطر: `discount_reason` و`discount_approved_by`.
2. صفٌّ في `activity_logs` عبر `Activity::log('updated', …, ['subject_type'
   => 'quotation', 'subject_id' => …])` — الجدولُ متعدّدُ الأشكال أصلًا.
3. لقطةُ `document_snapshot` عند الإرسال تحمل الأرقامَ كما خرجت.

> **ملاحظةٌ مهمّة:** `Product::sellingPrice()` موجودةٌ وتطبّق خصمَ الصنف،
> و`Product::discountRate()` تقصّ بين 0 و100. فـ«السعر الأساسيّ» له مصدرٌ
> واحدٌ مكتوبٌ أصلًا. لا تكتب حسابَ سعرٍ ثانيًا.

---

## 5. دورةُ حياة المشروع / الفعالية

```
draft ──▶ confirmed ──▶ in_progress ──▶ delivered ──▶ accepted ──▶ closed
  │            │             │              │
  └────────────┴─────────────┴──────────────┴──▶ cancelled
                                                  on_hold ⇄ in_progress
```

| الحالة | متى | ما يترتّب |
|---|---|---|
| `draft` | أُنشئ يدويًّا قبل القبول | لا شيء |
| `confirmed` | **تلقائيًّا عند قبول العرض** | البنودُ تُنسخ من العرض · التواريخ تُثبَّت |
| `in_progress` | بدأ التنفيذ | تُقبل المشترياتُ والمصروفاتُ عليه |
| `on_hold` | مؤجَّل | يُسجَّل السببُ والمدّة — سياسةُ تأجيل (§18) |
| `delivered` | أُرسلت حزمةُ التسليم | ينتظر قبولَ العميل |
| `accepted` | قَبِل العميل التسليم | يُتاح إصدارُ الفاتورة النهائيّة |
| `closed` | أُقفل | ❗ لا مستندَ ماليٌّ جديد عليه · تُجمَّد الربحيّة |
| `cancelled` | أُلغي | تُراجَع المستنداتُ القائمة يدويًّا |

**قاعدةٌ صارمةٌ عند الإقفال:** لا يُقفَل مشروعٌ عليه فاتورةٌ مسودّة، ولا
سندُ مورّدٍ معلَّق، ولا أمرُ شراءٍ لم يُستلم. وهي القاعدةُ نفسُها التي
تحرسُ إلغاءَ الفاتورة اليوم («لا تُلغى فاتورةٌ سُدِّد منها شيء»).

**وحقلُ `financials_frozen_at`:** عند الإقفال تُكتب لقطةُ الربحيّة في عمودٍ
JSON. لماذا؟ لأنّ الربحيّةَ مشتقّةٌ من مستنداتٍ قد تُلغى بعد سنة، فيتغيّر
ربحُ مشروعٍ أُقفل. اللقطةُ تقول «هذا ما كان عند الإقفال»، والحيُّ يبقى
محسوبًا بجانبه. هذا هو نمطُ `Snapshot` نفسُه مطبَّقًا على الأرقام.

---

## 6. دورةُ حياة التسليم

القائمُ اليوم: جدولا `delivery_notes` و`delivery_note_items` موجودان
و`DeliveryNote` نموذجٌ قائم — **ولا شيء يكتب فيهما**. القالبُ `delivery`
يُرسم من `Order` مباشرةً، ويقول التعليقُ في `DocumentTemplates` ذلك صراحةً:
«`delivery` تُرسم من الطلب لا من جدول `delivery_notes`: ذاك جدولٌ لا يكتب
فيه شيءٌ في النظام كلّه».

**فهنا جدولٌ فارغٌ بالشكل الصحيح تقريبًا، ينتظر من يكتب فيه.**

```
حزمةُ التسليم (DeliveryPackage)
  ├── project_id
  ├── البنودُ المسلَّمة      ← من بنود المشروع، بكميّاتٍ مسلَّمة
  ├── المرفقات             ← من مستندات المشروع (تقارير · صور · ملفّات نهائيّة)
  ├── بنودٌ معلَّقة          ← ما لم يُسلَّم وسببُه
  ├── ملاحظات
  └── حالة: draft → sent → viewed → accepted / disputed
```

والقبولُ يمرّ بالآليّة نفسِها التي يمرّ بها قبولُ عرض السعر (§19): رابطٌ
عامّ · هويّةُ الموقِّع · ختمُ الوقت · بصمةُ المحتوى. **واحدةٌ لا اثنتان.**

`sent_at · first_viewed_at · accepted_at · accepted_by_name · comments` —
كلُّها على سجلّ الاعتماد لا على الحزمة، فلا يُكتب النمطُ مرّتين.

---

## 7. علاقةُ المشروع بالفاتورة والتحصيل

```
Project  1 ──── n  CustomerInvoice   (customer_invoices.project_id — عمودٌ جديد)
                         │
                         n ──── n  CustomerPayment  (عبر customer_payment_allocations — قائم)
```

**ولا يُمَسُّ شيءٌ من دورة الفاتورة.** هذا أهمُّ ما في القسم.

### أنماط الدفع التي طلبتَها — كلُّها بلا كودٍ ماليٍّ جديد

| النمط | كيف يُنفَّذ |
|---|---|
| **100٪ بعد التنفيذ** | فاتورةٌ واحدةٌ عند `accepted` |
| **مقدَّم + نهائيّ** | فاتورةُ «دفعة مقدّمة» عند `confirmed`، ثمّ فاتورةُ الباقي عند `accepted` |
| **50/50** | كسابقه بنسبةٍ 50 |
| **معالم (Milestones)** | فاتورةٌ لكلّ معلم — `milestone_id` على الفاتورة (اختياريّ) |
| **بحسب أمر شراء العميل** | `po_number` **موجودٌ أصلًا** في `customer_invoices` |
| **فواتيرُ متعدّدة** | `hasMany` طبيعيّة |

**وقاعدةُ الحماية من الفوترة الزائدة**: `Project::invoicedTotal()` مقابل
`approved_value`. تُعرض على الشاشة، وتُحذَّر عند التجاوز — ولا تُمنع: مشروعٌ
زِيد نطاقُه يُفوتَر أكثر ممّا اعتُمد، وهذا مشروع. التحذيرُ يُقال ولا يُسَدّ
الباب — على نمط `CreditSales::OVERRIDE` القائم.

**ونقطةُ الاعتراف بالإيراد:** عند **إصدار** الفاتورة. لا عند إنشاء المشروع
ولا عند التسليم. وهذا يعمل **بلا حرفٍ واحد** من التعديل: فاتورةُ مشروعٍ لا
تغطّي `orders`، فـ`coversOrders()` تردّ `false`، فتمرّ `CustomerInvoices::post()`
وتكتب القيد. الاستثناءُ الوحيد في تلك الدالّة خاصٌّ بالطلبات، ونحن لا نمرّ به.

> ⚠️ **قرارٌ محاسبيّ يحتاج إقرارَك:** إن كنتَ تريد الاعتراف بالإيراد **على
> نسبة الإنجاز** (Percentage of Completion) لمشاريعَ تمتدّ أشهرًا وتعبر نهايةَ
> السنة الماليّة، فذلك بناءٌ محاسبيٌّ آخر تمامًا: حساباتُ «أعمالٌ تحت
> التنفيذ» و«إيرادٌ مستحقٌّ غير مفوتَر» وقيودٌ شهريّة. **لا أنصح به الآن**،
> ولا أظنّه لازمًا لفعاليّاتٍ تُنفَّذ في أيّام. إن لزم يومًا، فبنيةُ
> `Ledger::ensureSystemAccounts` تسمح بإضافة حساباتِه دون المساس بشجرةٍ قائمة.

---

## 8. مقترحُ نموذج البيانات

### 8.1 الجداول الجديدة — 11

#### `quotations`
```
id · business_id · customer_id
number            varchar   ← تسلسلٌ على نمط CustomerInvoices::nextNumber
root_id           FK self   ← جذرُ سلسلة التنقيحات (NULL للأصل)
revision          int       ← 1, 2, 3…
status            varchar   ← 7 قيمٍ لاتينيّة (§4.2)
project_id        FK NULL   ← يُملأ عند التحويل
─ الفعالية ─
event_name · event_type · event_location
event_starts_at · event_ends_at  (datetime)
expected_attendees int NULL
─ التجارة ─
valid_until       date
currency          varchar
payment_terms_days int
subtotal · discount_total · tax_total · total   numeric(*,3)
po_number · contract_number · department · attention_to
─ النصوص ─
notes · internal_notes   text
─ التجميد ─
terms_snapshot    json    ← الشروطُ والسياساتُ كما كانت (§18)
document_snapshot json    ← Snapshot::capture — هويّةُ الشركة والعملة والقالب
content_hash      char(64)
─ الأثر ─
created_by · approved_by · approved_at
sent_by · sent_at · first_viewed_at · view_count
decided_at · decided_by_name · rejection_reason
created_at · updated_at
```

#### `quotation_items`
```
id · quotation_id · product_id NULL
description   varchar
quantity      numeric(*,3)
list_price    numeric(*,3)   ← من الخادم. لا يُقبل من الطلب
unit_price    numeric(*,3)
discount_reason varchar NULL · discount_approved_by FK NULL
tax_rate · tax_amount · line_total   numeric
unit          varchar NULL   ← «يوم» · «قطعة» · «شخص» · «م²»
sort_order    int
notes         text NULL      ← وصفُ النطاق لهذا البند
```
> **مرآةُ `customer_invoice_items` عمدًا** — وزيادتُها أربعةُ أعمدة. التحويلُ
> إلى فاتورةٍ يصير نسخًا مباشرًا لا تحويلَ شكل.

#### `projects`
```
id · business_id · customer_id
code           varchar   ← «EP-2026-014»
name           varchar
status         varchar
quotation_id   FK NULL   ← العرضُ المعتمَد
─ الفعالية ─
event_type · location · starts_at · ends_at
contact_person · contact_phone · contact_email
─ القيمة ─
quoted_value · approved_value   numeric(*,3)
─ الفريق ─
owner_id FK users
─ الإقفال ─
closed_at · closed_by · financials_frozen json NULL
notes · internal_notes
```

#### `project_items`
بنودُ العمل المباعة — منسوخةٌ من العرض المعتمَد، وتتبع التسليمَ لا المال:
```
id · project_id · product_id NULL · quotation_item_id NULL
description · quantity · unit · unit_price · line_total
delivered_quantity numeric   ← يزيد مع حزم التسليم
status  varchar              ← pending · in_progress · delivered
```

#### `project_members`
```
id · project_id · user_id · role_in_project varchar · assigned_at
```

#### `project_tasks`
```
id · project_id · title · description · assignee_id FK NULL
due_at · status · priority · completed_at · created_by
```

#### `documents` — الجدولُ الموحَّد (§17)
```
id · business_id · project_id FK NULL
documentable_type · documentable_id      ← polymorphic
category      varchar   ← قائمةٌ مغلقة
title         varchar
version       int · supersedes_id FK NULL
status        varchar   ← draft · sent · signed · approved · expired
sent_at · due_at · signed_at
path · name · mime · size
uploaded_by · notes
```

#### `document_approvals` — قبولُ العميل (§19)
```
id · business_id
approvable_type · approvable_id    ← Quotation | DeliveryPackage | Document
document_link_id FK                ← يعيد استخدام رمز 22 حرفًا القائم
state         varchar   ← pending · viewed · accepted · declined · expired
content_hash  char(64)             ← بصمةُ ما عُرض بالضبط
signer_name · signer_title · signer_email · signer_phone
signature_image varchar NULL
accepted_snapshot json              ← ما رآه العميلُ حرفيًّا
viewed_at · decided_at · decline_reason
ip · user_agent
expires_at
```

#### `delivery_packages`
```
id · business_id · project_id · number
status · sent_at · notes · outstanding_notes
created_by
```
> وبنودُها من `project_items` بكميّاتٍ مسلَّمة، عبر `delivery_package_items`.

#### `company_policies` (§18)
```
id · business_id
key        varchar   ← من قائمةٍ مغلقةٍ في PHP
scope_type varchar NULL · scope_id int NULL   ← عامّ | صنف | تصنيف | عميل
params     json
text_ar · text_en   text        ← ما يراه العميل
active · effective_from · version
created_by
```

#### `policy_exceptions`
```
id · business_id
exceptionable_type · exceptionable_id   ← Quotation | Project
policy_key varchar
detected   json      ← ما رصده النظام: «طُلب قبل 3 أيام والحدّ 7»
reason     text
surcharge_amount numeric NULL
requested_by · approved_by · approved_at · status
```

### 8.2 الأعمدة المضافة إلى جداولَ قائمة — 6 فقط

| الجدول | العمود | لماذا |
|---|---|---|
| `customer_invoices` | `project_id` FK NULL | ربطُ الإيراد بالمشروع |
| `expenses` | `project_id` FK NULL | تكلفةٌ مباشرة |
| `purchase_orders` | `project_id` FK NULL | شراءٌ لأجل المشروع |
| `supplier_invoices` | `project_id` FK NULL | التزامُ مورّدٍ على المشروع |
| `products` | `kind` varchar default `'item'` | صنف · خدمة · باقة (§15) |
| `stock_adjustments` | `project_id` FK NULL | معدّاتٌ استُهلكت في المشروع |

**ستّةُ أعمدةٍ نُسخُها واحدة، وكلُّها `NULL` افتراضًا.** لا هجرةَ بيانات، ولا
تغييرَ سلوكٍ لصفٍّ قائم. وهذا هو معنى «Extend» في طلبك.

### 8.3 ⚠️ ما لا أضيفه — وأبيّن لماذا

**لا `project_id` على `journal_lines`.** وهذا قرارٌ يخالف ما قد يبدو
بديهيًّا، فإليك سببُه:

1. **بابُ القيد واحد.** إضافةُ بُعدٍ إلى السطر تعني أنّ كلَّ مستدعٍ
   لـ`Ledger::post` — وهم عشرة — يجب أن يمرّره. ومن نسي يكتب سطرًا بلا بُعد،
   فيصير التقريرُ ناقصًا بلا أن يقول أحد.
2. **السطرُ قد يخدم مشروعين.** تحصيلٌ واحدٌ يُسدِّد فاتورتين لمشروعين: قيدٌ
   واحدٌ سطراه لا يقبلان بُعدين.
3. **الجسرُ موجودٌ أصلًا.** `journal_entries.sourceable_type` و`sourceable_id`
   يقودان من أيّ قيدٍ إلى مستنده. ومن المستند إلى المشروع خطوةٌ واحدة. فربطُ
   القيد بالمشروع **مشتقٌّ بلا عمودٍ جديد**:
   ```sql
   journal_entries → sourceable (CustomerInvoice|Expense|SupplierInvoice) → project_id
   ```
4. وقاعدةُ البيت: «يُشتقّ ولا يُخزَّن: عمودٌ وجدولٌ يقولان الشيء نفسه
   يفترقان يومًا» — `CustomerInvoice::paidTotal()`.

**متى يتغيّر هذا القرار؟** إن احتجتَ يومًا **ميزانَ مراجعةٍ لكلّ مشروع**
(تقريرٌ نظاميّ لا إداريّ). عندها يُضاف بُعدٌ على السطر بقرارٍ واعٍ ومع
استدراكٍ للماضي. ولا أظنّك تحتاجه.

---

## 9. خريطةُ العلاقات

```
Business
 └── Customer ──┬── Quotation ──┬── QuotationItem ──▶ Product
                │       │ root_id↺ (سلسلةُ التنقيحات)
                │       └── DocumentApproval ──▶ DocumentLink
                │
                ├── Project ────┬── ProjectItem ────▶ Product
                │       │       ├── ProjectMember ──▶ User
                │       │       ├── ProjectTask ────▶ User
                │       │       ├── Document ────────▶ (polymorphic)
                │       │       ├── DeliveryPackage ─── DocumentApproval
                │       │       └── PolicyException
                │       │
                │       ├── CustomerInvoice   (project_id)  ◀── قائمٌ كما هو
                │       ├── Expense           (project_id)  ◀── قائمٌ كما هو
                │       ├── PurchaseOrder     (project_id)  ◀── قائمٌ كما هو
                │       └── SupplierInvoice   (project_id)  ◀── قائمٌ كما هو
                │
                └── CustomerInvoice ──┬── CustomerInvoiceItem
                                      ├── CustomerPaymentAllocation ──▶ CustomerPayment
                                      └── CustomerCreditNote

Business ── CompanyPolicy          (سياساتٌ تُدار، لا تُبرمَج)
Business ── Account ── JournalEntry ── JournalLine
                           │
                           └── sourceable ──▶ CustomerInvoice | Expense | SupplierInvoice | …
                                                    └── project_id  ← الجسرُ إلى المشروع
```

**والمشروعُ ليس في أيّ مسارِ قيد.** يظهر في المخطّط تحت المستندات لا فوقها.

---

## 10. نماذجُ تبقى كما هي — KEEP

| النموذج/النظام | لماذا لا يُمسّ |
|---|---|
| `Account` · `JournalEntry` · `JournalLine` · `Ledger` · `Books` | محاسبةٌ مزدوجةٌ كاملةٌ ببابٍ واحد. **ولا حرفَ واحد** |
| `CustomerInvoice` + بنودُها + مرفقاتُها | قلبُ الإيراد. يُضاف عمودُ `project_id` ولا شيء غيره |
| `CustomerPayment` · `CustomerPaymentAllocation` | التخصيصُ والقفلُ والشيكاتُ — صحيحٌ كلُّه |
| `CustomerCreditNote` | كما هو |
| `Customer` · `CustomerAddress` | **جاهزٌ للشركات بلا حقلٍ واحد** |
| `Supplier` · `PurchaseOrder` · `PurchaseOrderItem` | `project_id` فقط |
| `SupplierInvoice` · `GoodsReceiptNote` + بنودُه | نمطُ الاعتماد فيهما هو القالب |
| `Expense` · `ExpenseType` | `project_id` فقط |
| `BankAccount` · `BankStatementLine` · `FixedAsset` | كما هي |
| `PayrollRun` · `PayrollLine` · `JobTitle` | كما هي |
| `User` · `Branch` · `Business` · `Setting` · `Currency` | كما هي |
| `ActivityLog` | متعدّدُ الأشكال أصلًا — يستقبل الأنواع الجديدة بلا تعديل |
| `DocumentLink` · `Snapshot` · `Version` · `DocumentTemplates` · `DocumentRenderer` | نظامُ المستندات — يُضاف نوعٌ ولا تُغيَّر آلة |
| `InventoryMovement` · `StockAdjustment` · `BranchStock` · `StockLedger` | مفاهيمُ عامّة |
| `Permissions` · `Roles` · `Tenancy` · `TenantTables` | تُوسَّع لا تُغيَّر |
| `ImportBatch` · نظامُ النسخ والاستعادة والمحذوفات | كما هي |

---

## 11. نماذجُ تُعدَّل — ADAPT

| النموذج | التعديل | حجمُه |
|---|---|---|
| **`Product`** | `+ kind` (item · service · package) · إخفاءُ الكميّة للخدمات · `sku` يصير «رمزَ الخدمة» في التسمية | **عمودٌ واحد** + تسميات |
| **`RecipeItem`** | يصير «بنودَ الباقة»: الجدولُ يقول أصلًا «هذا المبيع يتكوّن من هذه المبيعات بكميّاتها». تسميةٌ ومفهوم | **صفر أعمدة** |
| **`Category`** | تصنيفاتُ خدمات بدل تصنيفات بضاعة — بيانات لا كود | صفر |
| **`BusinessTypes`** | قائمةُ الأنواع والتصنيفاتُ البذرة | بيانات |
| **`CustomAlert::METRICS`** | تُضاف مقاييسُ المشاريع والعروض · **ويُصلَح مدخلُ `profitability` الميّت** | قائمة |
| **`Reports::ALL`** | تُبدَّل مصادرُ الأربعة العمياء، وتُضاف تقاريرُ المشاريع | §30 م1 |
| **`Demo`** | **تُجمَّد**: لا دالّةَ جديدةً فيها. إعادةُ التسمية مؤجَّلة | سياسة |
| **`DeliveryNote`** | يُستعمل أخيرًا — من المشروع لا من الطلب | ربط |
| **`Addon` · `ProductAddons`** | «خدماتٌ اختياريّة تُضاف إلى باقة» | مفهوم |
| **دور `sales`** | تسميتُه «مسؤول عروض وعملاء» | تسمية |
| **دور `delivery`** | «منسّق ميدانيّ» | تسمية |
| **قسم `orders`** | يُخلَف بقسمَي `quotations` و`projects` | §14 |

---

## 12. النماذجُ الجديدة — 11

```
Quotation · QuotationItem
Project · ProjectItem · ProjectMember · ProjectTask
Document · DocumentApproval
DeliveryPackage (+ DeliveryPackageItem)
CompanyPolicy · PolicyException
```

**ولا نموذجَ ماليّ في القائمة.** لا `ProjectInvoice`، ولا `ProjectExpense`،
ولا `ProjectLedger`، ولا `Advance`. كلُّ المال يمرّ بما هو قائم.

---

## 13. ما لا يُمسّ في هذه الدورة كلِّها

هذه قائمةٌ صريحةٌ لأنّ أخطرَ ما يقع في تحويلٍ كهذا هو أن «يُحسَّن» شيءٌ يعمل:

```
Ledger::post · Ledger::reverse · Ledger::seedChart · Ledger::ensureSystemAccounts
Books::recordSale · Books::unpostSale · Books::recordExpense
CustomerInvoices::issue · cancel · creditNote · consolidate · post
CustomerPayments::record · allocate · post · cancel
Cheques · Receivables · CreditSales · Vat · EInvoice
SupplierInvoices (المطابقة والاعتماد والتجاوز) · GoodsReceipts
PayrollRun · PayrollLine
Snapshot · Version · Paper · DocumentRenderer
Permissions::allows · entersPanel · isAction
Tenancy · RequiresBusiness · TenantTables (تُضاف جداولُ ولا تُغيَّر قواعد)
BackupService · TrashPurge
المصادقةُ واستعادةُ الحساب
```

وقاعدةٌ عمليّة: **أيُّ pull request يلمس ملفًّا من هذه القائمة في مرحلةٍ
مخصَّصةٍ للمشاريع — يُردّ.** وإن لزم تغييرٌ فيها، يكون تغييرًا مستقلًّا
باختباره ومبرِّره.

---

## 14. القرار: `Order` → `Project`؟

### 14.1 المقارنة التقنيّة — بالأرقام

**بصمةُ `Order` اليوم** (معدودةٌ لا مقدَّرة):

| الموضع | العدد |
|---|---|
| ملفّات `app/` تشير إلى نموذج `Order` | **37** |
| ملفّات اختبار تشير إليه | **72** |
| قوالب Blade تذكر `order` | **27** |
| هجرات تمسّ `orders` | **31** |
| مسارات HTTP على الطلبات | 12 — **كلُّها قراءةٌ أو نقلُ حالةٍ أو طباعة** |
| مسارات HTTP تُنشئ طلبًا | **صفر** |
| صفوفُ `orders` في تثبيتٍ جديد | **صفر** |

### 14.2 الخياران

| | **أ) تحويل `Order` → `Project`** | **ب) `Project` جديدٌ و`Order` يُتقاعد** |
|---|---|---|
| التغييرُ في الكود القائم | 37 ملفًّا + 72 اختبارًا + 27 قالبًا | **صفر** — إضافةٌ محضة |
| المخاطرةُ على الأخضر (2053 اختبارًا) | عالية | **معدومة** |
| مسارُ الكتابة المكتسَب | **لا شيء** — لا مسارَ يُنشئ طلبًا | لا شيء (متساويان) |
| الحالاتُ المكتسَبة | آلةُ حالاتٍ لمتجرٍ: «قيد التجهيز» «خرج للتوصيل» — ليست دورةَ مشروع | تُكتب على المقاس |
| `payment_method` و`payment_status` على الرأس | **يجب تجاهلُهما** — ويبقيان مصدرَ حقيقةٍ ثانيًا يناقض `customer_payments` | لا وجودَ لهما |
| الترحيلُ المحاسبيّ | ⚠️ `Books::recordSale` مبنيٌّ على أنّ الطلبَ **بيعةٌ وقعت**. أيُّ إعادةِ إحياءٍ لمسار الكتابة تُقيّد إيرادًا لحظةَ إنشاء المشروع | لا علاقة — الإيرادُ من الفاتورة |
| منعُ الازدواج في الفواتير | `CustomerInvoices::post()` **تمتنع عن القيد** لفاتورةٍ تغطّي طلبًا. فمشروعٌ=طلبٌ يعني فواتيرَ بلا قيدٍ أبدًا | الطريقُ نظيف: القيدُ يقع |
| حجمُ الـdiff | ضخم، على نظامٍ ماليٍّ حيّ | صغير ومعزول |
| ما نكسبه | **الاسم** | كلُّ شيءٍ عدا الاسم |

### 14.3 ❗ العطبُ الحاسم في الخيار (أ)

ليس اعتراضًا على الاسم — هو تناقضٌ محاسبيّ:

```php
// CustomerInvoices::post()
if ($invoice->coversOrders()) {
    return;   // لا قيد: Books::recordSale رحّلت الطلبَ لحظةَ وقوعه
}
```

هذا السطرُ صحيحٌ تمامًا في نظام متجر: بيعةُ الصندوق تُقيَّد لحظتَها، فالورقةُ
بعدها لا تُقيَّد ثانية.

**فلو صار المشروعُ هو الطلبَ نفسَه**، فكلُّ فاتورةِ مشروعٍ «تغطّي طلبًا»
(= المشروع) فلا تُقيَّد أبدًا. تصير الفواتيرُ **مستنداتٍ بلا أثرٍ في
الدفتر**: الإيرادُ صفر، والذمّةُ صفر، والضريبةُ صفر — والميزانُ متوازنٌ
لأنّ ما لم يُكتب لا يُخلّ به. وهو العطبُ الذي وُجدت `Books` أصلًا لعلاجه،
موصوفًا في صدرها.

والبديل — أن يكون المشروعُ طلبًا **لا** يُرحَّل — يعني كتابةَ استثناءٍ في
`Books` و`CustomerInvoices` معًا: فرعٌ في بابِ القيد الوحيد يقول «إلّا إن
كان هذا الطلبُ مشروعًا». وهذا بالضبط ما يبدأ به التعفّن.

### 14.4 ✅ التوصية

> **أنشئ `Project` جديدًا. ولا تُعِد تسمية `Order` ولا تُحيِ مسار كتابته.**
>
> والسببُ ليس أنّ الجديدَ أنظف، بل أنّ **`Order` مستندُ بيعٍ و`Project` حاويةُ
> عمل** — كِيانان مختلفان في الطبيعة. وخلطُهما ليس إعادةَ استخدام، هو
> إدخالُ بيعةٍ في محلِّ مشروع، وثمنُه قيدٌ ماليٌّ خاطئ لا اسمٌ غيرُ موفَّق.
>
> **وإعادةُ الاستخدام تقع في مكانها الصحيح:** `Customer` و`Product` و
> `CustomerInvoice` و`CustomerPayment` و`PurchaseOrder` و`Expense` و`Ledger`
> و`DocumentTemplates` و`Snapshot` و`DocumentLink` و`Permissions` و
> `ActivityLog` — اثنا عشر نظامًا تُستعمل كما هي.

### 14.5 ومصيرُ `orders`

| الحال | ما يُفعل |
|---|---|
| **إن كان الإنتاجُ صفرًا** (وهو المرجَّح: لا مسار كتابة) | المرحلةُ 2: يُحذف قسمُ «المبيعات» من القائمة ومساراتُه الاثنا عشر · المرحلةُ 6: تُسقَط الجداولُ بهجرة |
| **إن كان فيه صفوفٌ من تاريخ أبعاد** | يبقى **أرشيفًا يُقرأ فقط**: يُخفى من القائمة، ويُترك مسارُ `show` و`pdf`، ولا يدخل في أيّ مقياسٍ جديد |

> ❓ **يحتاج إجابتَك:** `SELECT COUNT(*) FROM orders` على إنتاج Event Plus.
> عليه يتوقّف حذفُ 12 مسارًا و2 جدولٍ و4 نماذج.

---

## 15. استراتيجيّةُ `Product` → منتج/خدمة

### التوصية: عمودٌ واحد، لا نموذجٌ ثانٍ

`products` يحمل اليوم: `name · description · sku · barcode · price · cost ·
quantity · alert_qty · tax · discount · image · active · category_id`.
و**كلُّها صحيحةٌ لخدمة** عدا `quantity` و`alert_qty` و`barcode`.

```
products.kind  ← 'item' | 'service' | 'package'   (default 'item')
```

| `kind` | المعنى | الكميّة | الوصفة |
|---|---|---|---|
| `item` | عتادٌ يُشترى ويُستهلك (بطاقات · لانيارد · أحبار) | تُتابَع | — |
| `service` | عملٌ يُباع بالوقت أو بالمهمّة (تشغيل تسجيل · فريق ميدانيّ) | تُخفى | — |
| `package` | باقةٌ تحوي بنودًا | تُخفى | **`RecipeItem`** |

**و`RecipeItem` هي «بنودُ الباقة» بلا عمودٍ جديد.** الجدولُ يقول: «هذا المبيع
يتكوّن من هذه المبيعات بكميّاتها». وهو تعريفُ الباقة حرفًا بحرف. فباقة
«مؤتمر ٥٠٠ شخص» = تسجيل + بطاقات×500 + سكانرات×4 + فريق×2. والوصفةُ تعرف
ذلك أصلًا.

**الأثرُ على الشاشة**: مبدّلٌ في نموذج المنتج يُظهر أو يُخفي حقولَ المخزون.
لا شاشةَ جديدة.

### ⚠️ ما لا يصلح له `Product` — وأقولُه صراحةً

**التأجير.** معدّةٌ تخرج إلى الفعالية **وتعود**. و`Product` +
`InventoryMovement` يعرفان الخروجَ ولا يعرفان العودةَ المجدولة، ولا «محجوزٌ
من 20 إلى 22 أكتوبر»، ولا التعارضَ بين مشروعين على السكانر نفسه.

هذا **نموذجُ حجزٍ (Reservation)**، لا صنفُ مخزون. ولا أنصح ببنائه الآن.

> ❓ **يحتاج إجابتَك:** هل تؤجّرون معدّاتٍ تعود؟ إن نعم، فهي وحدةٌ إضافيّةٌ
> (M14) في مرحلةٍ لاحقة. وإن لا، فـ`Product` يكفي تمامًا.

---

## 16. معماريّةُ ماليّة المشروع

### المبدأ: المشروعُ يقرأ ولا يكتب

```
   ┌──────────────── Project (يقرأ) ────────────────┐
   │                                                │
   ▼                  ▼               ▼             ▼
CustomerInvoice   Expense     PurchaseOrder   SupplierInvoice
 (project_id)   (project_id)  (project_id)     (project_id)
   │                  │               │             │
   └──────────────────┴───────────────┴─────────────┘
                      │
              Ledger (بابٌ واحد — بلا تغيير)
```

### الأرقامُ التي طلبتَها — ومصدرُ كلٍّ منها

| الرقم | كيف يُحسب — **بلا جدولٍ ماليٍّ جديد** |
|---|---|
| **القيمة المعروضة** | `quotations.total` للعرض المرتبط |
| **القيمة المعتمَدة** | `projects.approved_value` (لقطةٌ عند القبول) |
| **المفوتَر** | `SUM(customer_invoices.total) WHERE project_id AND status = صادرة` |
| **المحصَّل** | `SUM(allocations.amount)` عبر فواتير المشروع — **بقاعدة `CustomerInvoice::paidSql()` نفسِها** (تستبعد الملغى والشيكَ المرتجع) |
| **الباقي على العميل** | `المفوتَر − المحصَّل − إشعاراتُ الدائن` = `outstandingSql()` مجموعةً |
| **المشتريات** | `SUM(purchase_orders.total) WHERE project_id` |
| **التزاماتُ الموردين** | `SUM(supplier_invoices.total − paid)` بـ`scopeOwed` القائم |
| **المصروفاتُ المباشرة** | `SUM(expenses.amount) WHERE project_id` |
| **تكلفةُ العتاد المستهلَك** | `SUM(stock_adjustments.quantity_delta × cost_at_time) WHERE project_id` |
| **مجملُ الربح** | `(المفوتَر − الضريبة) − (سنداتُ الموردين + المصروفات + العتاد)` |
| **نسبةُ الربح** | مجملُ الربح ÷ (المفوتَر − الضريبة) |

**ولا يُخزَّن منها شيءٌ** إلّا لقطةَ الإقفال (`financials_frozen`). وتعريفُ
«الربح» يُكتب في **موضعٍ واحد** — `ProjectFinancials::summary()` — لأنّ
المستودع دفع ثمنَ تعريفَين مختلفين للربح من قبل، وموصوفٌ في
`Demo::reportSummary`.

### ⚠️ الدفعة المقدَّمة — تصحيحُ §0.2

| ما يقع اليوم | ما ينبغي |
|---|---|
| تحصيلٌ بلا فاتورة → `دائن receivable` → **ذمّةٌ سالبة** | فاتورةُ دفعةٍ مقدَّمة (بند واحد) → إصدار → قيدٌ صحيح بضريبته → تحصيلٌ يُخصَّص عليها |

**فلا كودَ ماليٌّ جديد** — اتّفاقُ عملٍ وشاشةٌ تُيسّره: زرُّ «فاتورة دفعة
مقدّمة %» في المشروع يفتح شاشةَ إنشاء الفاتورة القائمة مملوءةً.

> وإن أردتَ يومًا قبضًا حقيقيًّا قبل أيّ فاتورة، فالسبيلُ الصحيح حسابٌ
> نظاميٌّ جديد `customer_advances` (خصم · 2400) يُضاف عبر
> `Ledger::ensureSystemAccounts` — وهي مبنيّةٌ لهذا بالضبط: تستدرك حسابًا
> يُضاف إلى شجرةٍ قائمةٍ دون أن تمسّ ما عدّله صاحبُها.

### تقاريرُ المشروع

تُضاف إلى `Reports::ALL` بالبنية القائمة (فئة · قسم · مسار · أيقونة):
`ربحيّةُ المشاريع` · `المشاريعُ الجارية` · `عروضُ الأسعار ومعدّلُ القبول`.
والتصديرُ يعمل تلقائيًّا من البابِ الواحد القائم.

---

## 17. استراتيجيّةُ المستندات

### المشكلة (§0.3): خمسةُ أشكالٍ لحفظ ملفّ

### الحلّ: جدولُ `documents` متعدّدُ الأشكال — **بالإضافة لا بالترحيل**

- **الكودُ الجديد** (المشاريع والعروض) يكتب في `documents`.
- **الكودُ القائم** يبقى كما هو. لا تُنقل الصفوفُ ولا تُحذف الأعمدة في هذه
  الدورة. (وإن أُريد التوحيدُ يومًا، فهو دورةٌ مستقلّةٌ بهجرتها واختبارها.)

### التصنيفاتُ — قائمةٌ مغلقةٌ في PHP

```
contract · client_po · technical_proposal · financial_proposal
scope · requirement · permit · government_approval
design · site_plan · report · photo
delivery_note · signed_acceptance · invoice_copy · other
```

ولا محرِّرَ عقودٍ ولا مُنشئَ عروضٍ فنّيّة — كما قلتَ، وأوافق. النظامُ
**يحفظ ويصنّف ويعرف الحالة والنسخة والموعد ومن رفع**. لا أكثر.

### النسخُ

`version` + `supersedes_id`. النسخةُ الجديدة تشير إلى سابقتها، والقديمةُ
تبقى مقروءةً موسومةً. **النمطُ نفسُه في `journal_entries.reverses_id`
و`quotations.root_id`** — ثلاثةُ مواضعَ باصطلاحٍ واحد.

### الصلاحية

`document.view` يتبع صلاحيةَ المشروع. والملفّاتُ الماليّة تبقى خلف
`ATTACHMENT_VIEW` القائم. ومساراتُ التنزيل تمرّ بحارسِ المستأجر — لا رابطَ
تخزينٍ مباشر، تمامًا كما يفعل `FinancialAttachmentController` اليوم.

---

## 18. استراتيجيّةُ السياسات والاستثناءات

### الموقف: لا Hard-code، ولا محرّكُ قواعد

طلبتَ الوسط، وهو موجودٌ في هذا المستودع بالفعل، ونصُّه في `CustomAlert`:

> «قائمة مغلقة عمدًا: كل مقياس هنا يقابله استعلامٌ مكتوب في الكود، فلا يصل
> شيءٌ من إدخال المستخدم إلى قاعدة البيانات كشرط.»

**اتّبع هذا حرفًا.** السياسةُ مفتاحٌ من قائمةٍ مغلقة، يقابله صنفُ فحصٍ
مكتوبٌ في PHP. والمدير يضبط **المعاملات والنصّ**، لا المنطق.

```php
// App\Support\Policies\PolicyRegistry
const KEYS = [
    'lead_time_days'          => LeadTimeCheck::class,
    'cancellation_window'     => CancellationWindowCheck::class,
    'postponement_window'     => PostponementWindowCheck::class,
    'scope_change_approval'   => ScopeChangeCheck::class,
    'discount_threshold'      => DiscountThresholdCheck::class,
    'payment_terms_default'   => PaymentTermsCheck::class,
];
```

كلُّ صنفٍ يجيب سؤالًا واحدًا:

```php
interface PolicyCheck {
    public function evaluate(CompanyPolicy $policy, object $subject): ?PolicyFinding;
}
```

و`PolicyFinding` كائنُ قيمةٍ: `{ key, severity, detected, suggestedSurcharge,
requiresApproval }`. **لا يكتب شيئًا** — يُعيد وصفًا.

### نطاقُ السياسة

`scope_type` = `null` (عامّة) | `product` | `category` | `customer`. فمهلةُ
الأيّام السبعة تكون عامّةً، أو على «طباعة البطاقات» وحدها، أو استثناءً
لعميلٍ بعقدٍ إطاريّ.

### المسار حين تقع مخالفة

```
موظّفٌ يكتب عرضًا لفعاليّةٍ بعد 3 أيّام، والسياسة 7
         │
         ▼
PolicyCheck::runAll($quotation)  ←  تُستدعى عند الحفظ وعند الإرسال
         │
         ▼
الشاشةُ تقول: «طلبٌ عاجل — المهلة 7 أيّام والمتبقّي 3»
         │
         ├── يُطلب سببٌ نصّيّ  (مطلوب)
         ├── تُقترح رسومُ استعجالٍ من معاملات السياسة  (قابلةٌ للتعديل)
         └── ينتقل العرضُ إلى pending_approval
         │
         ▼
policy_exceptions: {detected, reason, surcharge, requested_by, approved_by, status}
         │
         ▼
Activity::log('updated', …, subject_type: 'quotation')
```

### ⚠️ الجزءُ الأهمّ — والذي قد يُغفَل

**نصُّ السياسة أخطرُ من فحصِها.**

فحصٌ آليٌّ مفقودٌ يعني موظّفًا ينتبه أو لا ينتبه. أمّا **نصُّ الشروط**
فهو ما يُثبَت به الحقُّ إن اختلفتم مع عميل. ولو عاش النصُّ في ملفّ PDF خارج
النظام، فإنّ «القبولَ الإلكترونيّ» لا يثبت شيئًا: لا يُعرف **أيَّ نصٍّ**
قَبِل العميل.

لذلك: `text_ar` و`text_en` و`version` على السياسة، و**`terms_snapshot` مختومٌ
داخل العرض لحظةَ إرساله**. وهذا هو الجزءُ الذي **يجب** أن يُبنى في المرحلة
الأولى. أمّا الفحصُ الآليّ فيمكن أن يبدأ بقاعدةٍ واحدةٍ (`lead_time_days`)
ويتوسّع.

---

## 19. استراتيجيّةُ التوقيع الإلكترونيّ

### التوصية: (أ) داخليٌّ أوّلًا، و(ب) مزوّدٌ عند الحاجة — والحدُّ يُصمَّم الآن

### لماذا (أ) كافيةٌ لأكثر أعمالكم

لأنّ البنيةَ موجودةٌ ومختبَرة:

| القطعة | القائم |
|---|---|
| رابطٌ عامٌّ غيرُ مخمَّن | `PublicDocument::token()` — **22 حرفًا من 66 احتمالًا** |
| مسارٌ بلا تسجيلِ دخول | `GET /i/{token}` → `paper.show` |
| حارسُ «الورقةُ فقدت أصلها» | `abort_if($document === null …, 404)` — مكتوبٌ ومعلَّل |
| سياسةُ «أيُّ الأوراق تُمنح وجهًا عامًّا» | `PublicDocument::PUBLIC` — **من المصدر لا بشرطٍ في القالب** |
| صفحةٌ قائمةٌ بذاتها بلا Inertia | `public.paper` · `public.verify` |
| ختمُ حالِ الشركة | `Snapshot` |

**فالمطلوبُ إضافتُه قليل:** `document_approvals` بحقول الموقِّع وختمِ الوقت
والبصمة، وصفحةُ قبولٍ فيها زرّان واسمٌ وبريدٌ ومربّعُ إقرار.

### ما يجعلها **دليلًا** لا زرًّا

1. **`content_hash`** — `sha256` على البنود + الإجماليات + `terms_snapshot`.
2. **`accepted_snapshot`** — ما عُرض على العميل حرفيًّا (JSON).
3. **الأثر** — `viewed_at · decided_at · ip · user_agent · signer_*`.
4. **انتهاءُ الصلاحية** — `expires_at` = `valid_until` (رابطٌ لعرضٍ انتهت
   صلاحيتُه يقول ذلك ولا يَقبل).

### ⚠️ ما يجب أن يُقال بصراحة

هذا **قبولٌ إلكترونيٌّ موثَّق (click-wrap)**، وليس **توقيعًا إلكترونيًّا
معتمَدًا** بالمعنى النظاميّ (قانون المعاملات الإلكترونية العُمانيّ / eIDAS).
وهو كافٍ لعرض سعرٍ ولقبول تسليمٍ بين شركةٍ وعميلها. **وغيرُ كافٍ** لعقدٍ
حكوميٍّ يشترط شهادةً معتمَدة.

### حدُّ التكامل — يُصمَّم الآن، يُنفَّذ لاحقًا

```php
interface ApprovalProvider {
    public function request(Approvable $doc, Signer $signer): ApprovalRequest;
    public function status(string $externalId): ApprovalStatus;
    public function evidence(string $externalId): ?string;  // مسارُ ملفّ الإثبات
}

// المرحلةُ 3:  InternalApproval implements ApprovalProvider
// لاحقًا:       DocuSignProvider · ZohoSignProvider · …
```

`document_approvals` يحمل `provider` و`external_id`. فالتبديلُ يوم يلزم لا
يمسّ نموذجَ البيانات.

> ❓ **يحتاج إجابتَك:** هل لديكم عميلٌ **بعينه** يشترط توقيعًا معتمَدًا؟ إن
> لا، فالداخليُّ يكفي ولا يُبنى المزوّد.

---

## 20. معماريّةُ الأتمتة

### لا BPMN. ولا محرّكَ حالات. ولا صفٌّ من العمّال.

**الملاحظةُ الواقعيّة:** `QUEUE_CONNECTION=database` لكن `app/Jobs` حُذف في
التنظيف ولا مستمعَ ولا حدثَ في النظام. **ولا عاملَ صفٍّ يعمل.** وكلُّ ما هو
دوريٌّ اليوم يمرّ بالمجدول. فلا تُدخل بنيةً تحتيّةً جديدة قبل الحاجة.

### التصميم — ثلاثُ قطعٍ صغيرة

**١) `Signal`** — صنفٌ يجيب سؤالًا واحدًا: «ما الذي يحتاج انتباهًا الآن؟»

```php
interface Signal {
    public function key(): string;
    public function severity(): string;       // info · warn · urgent
    public function findings(int $businessId): iterable;  // {title, url, subject, since}
    public function section(): string;        // للصلاحيات
}
```

**٢) `SignalRegistry`** — قائمةٌ مغلقة، على نمط `CustomAlert::METRICS`:

```
quotation.awaiting_follow_up    عرضٌ أُرسل ولم يُجَب عليه منذ N يومًا
quotation.expiring_soon         صلاحيّتُه تنتهي خلال 3 أيّام
quotation.unopened              أُرسل ولم يُفتح
project.starting_soon           فعاليّةٌ خلال 7 أيّام
project.missing_procurement     بندٌ لا أمرَ شراءٍ له وموعدُ الفعاليّة يقترب
project.awaiting_delivery       انتهى تنفيذُه ولا حزمةَ تسليم
delivery.awaiting_acceptance    أُرسلت ولم تُقبل منذ N يومًا
invoice.overdue                 ← موجودةٌ أصلًا في CustomerInvoice::scopeOverdue
purchase.not_received           ← موجودةٌ أصلًا
policy.exception_pending        استثناءٌ ينتظر اعتمادًا
task.overdue                    مهمّةٌ فات موعدُها
```

**٣) مسلكان لا واحد:**

| المسلك | متى | كيف |
|---|---|---|
| **القراءة** | كلَّما فُتحت لوحةُ التحكم | `SignalRegistry::run($user)` — استعلاماتٌ صغيرةٌ مفهرسة. **لا شيء يُخزَّن** |
| **الدفع** | مرّةً في اليوم | أمرٌ جديد `ops:scan` في المجدول يجمع الإشارات ويرسل ملخّصًا لصاحب كلِّ إشارة |

### قاعدتان تمنعان التضخّم

1. **اشتقّ ولا تخزّن.** «متأخّر» و«ينتهي قريبًا» و«لم يُجَب» كلُّها محسوبةٌ
   من أعمدةٍ موجودة. لا جدولَ `notifications`.
2. **التجاهلُ وحدَه يُخزَّن.** و`dismissed_notifications` قائمٌ ويعمل.

### ما يُخزَّن فعلًا: `follow_ups`

المتابعةُ التي **ينشئها إنسان** («اتّصل بأحمد الثلاثاء») ليست مشتقّةً من شيء،
فتُخزَّن: `{ subject_type, subject_id, due_at, assignee_id, note, done_at }`.
جدولٌ واحدٌ صغير.

---

## 21. حدُّ التكامل مع واتساب

### ما يعرفه النظامُ الأساسيّ — وما لا يعرفه

| **Core يعرف** | **طبقةُ واتساب وحدها تعرف** |
|---|---|
| «أرسل هذا المستندَ إلى هذا الشخص» | WABA ID · phone_number_id · access token |
| قناةٌ مجرَّدة: `whatsapp` \| `email` \| `sms` | أسماءُ القوالب واعتمادُها والمتغيّرات |
| حالُ الرسالة: `queued · sent · delivered · read · failed` | نافذةُ الـ24 ساعة وقيودُها |
| مرجعُ الموضوع: `subject_type` + `subject_id` | Webhook والتوقيعُ والتحقّق |
| رقمٌ مطبَّعٌ عبر **`PhoneNumber::normalize`** ← بابٌ قائم | صيغةُ الوسائط ورفعُها |

### الجداول

```
messages
  business_id · channel · direction · to · subject_type · subject_id
  template_key · payload json · body text
  status · provider · provider_message_id
  queued_at · sent_at · delivered_at · read_at · failed_reason
  created_by

conversations          ← للردود الواردة
  business_id · customer_id · channel · external_thread_id
  last_message_at · assigned_to · status
conversation_messages
  conversation_id · direction · body · media_path · occurred_at · external_id
```

### الواجهة

```php
interface Notifier {
    public function channel(): string;
    public function configured(): bool;       // ← نمطُ Mailer::configured()
    public function send(MessageIntent $intent): MessageResult;
}
```

**و`Mailer::configured()` هي النموذج.** صدرُها يشرح المشكلة بدقّة: «`log`
و`array` مُرسلان صالحان في نظر لارافيل… فالشاشة تقول «أرسلنا الرابط»
والرسالةُ في ملفّ سجلّ». والواجهةُ الجديدة ترث هذا الدرس: **القناةُ تقول عن
نفسها هل تُوصِل**، والشاشةُ تُخفي بابًا لا يفتح.

### ⚠️ تحذيرٌ واقعيّ

النظامُ كان فيه `config/whatsapp.php` وحُذف لأنّه كان ميّتًا. **لا تبنِ
الجداولَ قبل أن يكون لديكم رقمُ WhatsApp Business معتمَدٌ وقوالبُ مُجازة.**

**ابدأ بـ`Notifier` وبتنفيذٍ واحدٍ للبريد.** يُوصِل اليوم شيئًا حقيقيًّا
(رابطُ عرض السعر، التذكير)، ويُثبت الحدَّ قبل أن تدفع ثمنَ واتساب.

---

## 22. حدُّ التكامل مع الذكاء الاصطناعيّ

### القاعدةُ الواحدة

> **الذكاءُ الاصطناعيّ يقترح. والإنسانُ يكتب. والخادمُ يتحقّق.**
> ولا بابَ كتابةٍ ثانٍ — أبدًا.

```
نصٌّ حرّ من المستخدم
   │
   ▼
AiExtractor  →  ai_extractions  { source_text, model, extracted json, confidence }
   │
   ▼            ‹‹ اقتراحٌ مخزَّنٌ في منطقةٍ محايدة — لا صفَّ مشروعٍ ولا عرض ››
   ▼
شاشةُ «عرضٌ جديد» تُفتح **مملوءةً** من الاقتراح
   │
   ▼
الموظّفُ يراجع ويعدّل ويحفظ
   │
   ▼
QuotationController::store   ←  التحقّقُ نفسُه · الصلاحيّاتُ نفسُها · السجلُّ نفسُه
```

### ما يُسمح وما يُمنع

| ✅ يُسمح | ❌ يُمنع |
|---|---|
| استخراجُ عميلٍ ومناسبةٍ وتاريخٍ وخدماتٍ وكمّيّاتٍ من نصّ | اعتمادُ سعر |
| اقتراحُ بنودٍ من الكتالوج **بأسمائها** | إعطاءُ خصم |
| اقتراحُ مهامٍّ ومعالمَ للمشروع | إرسالُ عرضٍ للعميل |
| كشفُ ما ينقص من معلومات | تسجيلُ تحصيل |
| تلخيصُ مشروعٍ أو عميل | أيُّ قيدٍ في الدفتر |
| صياغةُ نصٍّ لرسالةٍ **قبل** إرسالها | تجاوزُ أيّ صلاحية |

### كيف يُفرَض المنعُ تقنيًّا — لا بالوثيقة

1. **السعرُ من الخادم دائمًا.** `list_price` يُقرأ من `Product::sellingPrice()`
   وقتَ الحفظ. اقتراحُ الذكاء لسعرٍ يُعامَل كإدخالِ مستخدم، فيقع تحت قاعدة
   الخصم (§4.4) ويستدعي صلاحيةً وسببًا.
2. **لا مسارَ كتابةٍ خاصّ.** لا `POST /ai/quotations`. الاقتراحُ يملأ نموذجًا،
   والنموذجُ يمرّ بالمتحكّم القائم.
3. **صلاحيّةٌ لاستعمال الذكاء نفسِه**: `ai.use`.
4. **السجلّ**: كلُّ استخراجٍ يُقيَّد بمن طلبه، وكلُّ عرضٍ وُلد من اقتراحٍ
   يحمل `ai_extraction_id` — فيُعرف بعد سنةٍ ما صنعه إنسانٌ وما اقترحته آلة.
5. **تسريبُ البيانات**: نصُّ الطلب يُرسل إلى مزوّدٍ خارجيّ. يجب أن يكون
   ذلك اختيارًا صريحًا من الإعدادات، وأن يُقال في الشاشة. (والمستودعُ اتّخذ
   هذا الموقف من قبل: `Lexicon` رفضت آلةَ الترجمة لأنّها «تُرسل أسماء بضاعة
   التاجر إلى طرفٍ ثالث».)

---

## 23. خريطةُ الصلاحيات المقترَحة

### الأقسامُ الجديدة: 12 → 14

```
dashboard · customers · products · quotations · projects · orders*
inventory · finance · expenses · settings · suppliers · purchases
employees · reports
```
`*` يُحذف `orders` مع قسم المبيعات — انظر §14.5.

### الأفعالُ الجديدة: 20 → 34

| الفعل | التسمية | يرثه بالدور |
|---|---|---|
| `quotation.create` | إنشاء عرض سعر | admin · manager · sales |
| `quotation.send` | إرسال عرضٍ للعميل | admin · manager · sales |
| `quotation.discount` | منحُ خصمٍ ضمن الحدّ | admin · manager |
| `quotation.overridePrice` | البيعُ فوق سعر الكتالوج | admin |
| `quotation.approve` | اعتمادُ عرضٍ تجاوز الحدّ | admin · manager |
| `quotation.cancel` | إلغاءُ عرض | admin · manager |
| `quotation.recordDecision` | تسجيلُ قبولِ العميل أو رفضه نيابةً | admin · manager · sales |
| `project.create` | إنشاءُ مشروع | admin · manager |
| `project.manage` | إدارةُ فريقِه ومهامِّه | admin · manager |
| `project.budget.view` | رؤيةُ تكلفته وربحيّته | admin · manager · accountant |
| `project.close` | إقفالُ مشروع | admin · manager |
| `project.reopen` | إعادةُ فتحِ مشروعٍ مُقفَل | admin |
| `policy.override` | تجاوزُ سياسةٍ باستثناء | admin · manager |
| `delivery.send` | إرسالُ حزمة تسليم | admin · manager |
| `delivery.accept` | تسجيلُ قبولِ العميل نيابةً | admin · manager |
| `document.upload` | رفعُ مستندٍ إلى مشروع | admin · manager · sales · inventory |
| `document.approve` | وسمُ مستندٍ معتمَدًا | admin · manager |
| `messaging.send` | الإرسالُ من قناة الشركة | admin · manager · sales |
| `ai.use` | استعمالُ المساعد | admin · manager · sales |

### توزيعُ الأقسام على الأدوار

| الدور | يُضاف إليه |
|---|---|
| `admin` · `manager` | `*` — بلا تغيير |
| `accountant` | `+ projects` (للقراءة الماليّة) — ولا `quotations` |
| `sales` | `+ quotations` `+ projects` |
| `inventory` | `+ projects` (ليرى ما يُطلب ومتى) |
| `delivery` → «منسّق ميدانيّ» | `+ projects` بدل `orders` |

### ⚠️ ما يكسره هذا — عمدًا

| الاختبار | لماذا يسقط | الصواب |
|---|---|---|
| `EveryRoleIsProbedOnEveryRouteTest` | يثبّت `Permissions::MAP` بنصّه | يُحدَّث عمدًا مع كلّ تغيير — **هذه وظيفتُه** |
| `EveryScreenOpensTest` | كلُّ شاشةٍ جديدةٍ يجب أن تُفتح | تُضاف بيانات التهيئة |
| `NoDeadLinksTest` | أيُّ رابطٍ لمسارٍ غير موجود | يُمسك فورًا |
| `TranslationCoverageTest` | كلُّ نصٍّ عربيٍّ يلزمه مقابلٌ في `en.json` | يُضاف مع النصّ |
| `ARestoreReturnsTheShopTest` | **كلُّ جدولٍ جديدٍ يجب أن يُصنَّف في `TenantTables`** | 11 جدولًا تُضاف إلى `ORDER` بترتيبها الصحيح |

**وهذه ليست عقباتٍ — هي الحرّاسُ يعملون.** لكن يجب أن تُحسب في تقدير الوقت.

---

## 24. استراتيجيّةُ أثرِ التدقيق

### ثلاثُ طبقاتٍ، وكلُّها قائمةٌ اليوم

| الطبقة | الآليّة | حالُها |
|---|---|---|
| **١. من فعل ماذا ومتى** | `activity_logs` — متعدّدُ الأشكال، مع IP واسمِ الفاعل | **قائمٌ ويكفي** |
| **٢. ما كان المستندُ عليه** | `document_snapshot` + `Version` + `content_hash` | **قائمٌ ويُوسَّع** |
| **٣. المالُ لا يُمحى** | `journal_entries.reverses_id` + `reversed_at` | **قائمٌ ولا يُمسّ** |

### الأفعالُ الحسّاسةُ الجديدة — وكلُّها تُقيَّد

```
عرضٌ أُرسل                  ← من · إلى أيّ بريد/رقم · متى · بأيّ بصمة
خصمٌ مُنح                   ← البند · قبل · بعد · السبب · من طلب · من اعتمد
سعرٌ فوق الكتالوج           ← كسابقه
عرضٌ نُقِّح                  ← من أيّ نسخةٍ إلى أيّ نسخة · ما تغيّر
قرارُ عميل                  ← من الرابط: الاسم · البريد · IP · الوقت · البصمة
سياسةٌ تُجوُوزت              ← المفتاح · ما رُصد · السبب · من اعتمد · الرسوم
مشروعٌ أُقفل / أُعيد فتحُه    ← من · متى · لقطةُ الأرقام
حزمةُ تسليمٍ أُرسلت وقُبلت    ← كقرار العميل
مستندٌ رُفع / اعتُمد          ← من · النسخة
رسالةٌ أُرسلت                ← القناة · المستقبِل · الموضوع · الحال
اقتراحُ ذكاءٍ استُهلك         ← من · أيُّ عرضٍ وُلد منه
```

### ⚠️ ثغرةٌ في القائم يجب سدُّها مع المشاريع

`ActivityLog` فيه `subject_type` **نصٌّ حرّ** يُكتب باليد: `'customer_invoice'`
· `'customer_payment'` · `'quotation'`… فخطأٌ مطبعيٌّ واحدٌ يجعل السطرَ غيرَ
قابلٍ للربط، ولا شيء يكشفه.

**العلاج الصغير:** ثابتٌ لكلّ نوعٍ في صنفٍ واحد (`ActivitySubjects`)، واختبارٌ
يفحص أنّ كلَّ قيمةٍ في العمود من القائمة. عملُ نصفِ يوم، ويحمي طبقةَ التدقيق
كلَّها.

---

## 25. معماريّةُ مركز الإجراءات

### التوصية: **استعلامٌ لا جدول**

القائمُ اليوم: `NotificationController::feed()` يستدعي `Demo::notifications()`
ويردّ JSON، والمتصفّح يستطلعه، و`DismissedNotification` يُخفي ما يُتجاهَل.
**البنيةُ موجودةٌ — المطلوبُ توسيعُها لا استبدالُها.**

```php
final class ActionCenter
{
    public static function for(User $user): array
    {
        return collect(SignalRegistry::all())
            ->filter(fn (Signal $s) => $user->allows($s->section()))   // الصلاحيات
            ->flatMap(fn (Signal $s) => $s->findings($user->business_id))
            ->reject(fn ($f) => Dismissed::has($user, $f['key']))      // التجاهل
            ->sortBy(fn ($f) => self::WEIGHT[$f['severity']])
            ->values()->all();
    }
}
```

### الشكلُ على الشاشة

```
┌─ ما يحتاج انتباهك ────────────────────────────────┐
│ 🔴  فعاليّةٌ بعد 4 أيّام — بندٌ بلا أمرِ شراء       │  → المشروع
│ 🔴  فاتورتان متأخّرتان — 4,200 ر.ع                │  → الذمم
│ 🟠  3 عروضٍ أُرسلت ولم يُجَب عليها منذ 3 أيّام      │  → العروض
│ 🟠  استثناءُ سياسةٍ ينتظر اعتمادك                  │  → الاعتمادات
│ 🔵  حزمةُ تسليمٍ تنتظر قبولَ العميل منذ يومين      │  → المشروع
└───────────────────────────────────────────────────┘
```

**وكلُّ سطرٍ يقود إلى مكان الجواب** — على قاعدة `CustomAlert::url()`:
«التنبيه على رقمٍ يقود إلى الرقم، ومن نقر لا يريد قائمةً يختار منها».

### متى يُبنى جدول؟

حين يصير مجموعُ الاستعلامات ملحوظًا في القياس. وبأحجام Event Plus (مشاريعُ
بالعشرات لا بالملايين) **لن يصير**. ولا يُبنى تخميناً.

---

## 26. معماريّةُ ما بين الشركات — مستقبلًا

### المبدأ الذي لا يُنتهَك

> **لا استعلامَ يعبر `business_id`. أبدًا.**

عزلُ المستأجر يُحرَس بـ`RequiresBusiness` عند الباب وبـ`TenantIsolationTest`.
وأيُّ «حلٍّ» يقرأ من شركتين في استعلامٍ واحدٍ يهدم الحارسَ ويفتح تسريبًا لا
يُكتشف.

### التصميم: مستنداتٌ متقابلة، لا استعلاماتٌ عابرة

**١) حسابان نظاميّان في كلِّ شركة** — عبر `Ledger::ensureSystemAccounts`،
وهي **مبنيّةٌ لهذا بالضبط**: تُضيف حسابًا نظاميًّا إلى شجرةٍ قائمةٍ دون أن
تمسّ ما عدّله صاحبُها، وتُزيح الرمزَ إن كان مشغولًا.

```
1450  ذمم شركاتٍ شقيقة (مستحقٌّ لنا)    due_from_related   أصل
2450  ذمم شركاتٍ شقيقة (مستحقٌّ عليها)  due_to_related     خصم
```

**٢) جدولُ ربطٍ فوق المستأجر** — وهو الجدولُ الوحيدُ في النظام الذي لا
يخصّ مستأجرًا بعينه:

```
intercompany_transactions
  id · group_id
  from_business_id · to_business_id
  kind          ← expense_paid_on_behalf · loan · service_charge · settlement
  amount · currency · occurred_at · description
  from_entry_id FK journal_entries    ← قيدُ الشركة الأولى
  to_entry_id   FK journal_entries    ← قيدُ الشركة الثانية
  status        ← posted · settled · reversed
  created_by
```

**٣) قيدان مستقلّان**، كلٌّ في شركته، عبر `Ledger::post` بلا تعديلٍ فيها:

```
الشركة A تدفع مصروفًا عن B بمئة:
   A:  مدين  1450 ذمم شقيقة 100   /  دائن  البنك 100
   B:  مدين  5xxx المصروف   100   /  دائن  2450 ذمم شقيقة 100
```

والتسويةُ قيدان عكسيّان يُقفلان الرصيدين.

**٤) واجهةٌ منفصلةٌ بصلاحيّةٍ منفصلة**: `group.view` · `group.post`. سطحٌ
صريحٌ محدود، **لا تخفيفٌ لـ`RequiresBusiness`**.

### ⚠️ ما لا يفعله هذا التصميم

**لا يُنتج قوائمَ ماليّةً موحَّدة.** التوحيدُ يقتضي حذفَ الأرصدة المتبادلة
والأرباحَ غير المحقَّقة بين الشركتين — وهو عملٌ محاسبيٌّ حقيقيٌّ خارج نطاق
هذا النظام.

> ❓ **يحتاج إجابتَك:** أهناك **كيانان قانونيّان قائمان** فعلًا؟ إن لا، **فلا
> تُبنَ هذه الوحدة.** بناءُ ما بين الشركات لاحتمالٍ هو أوضحُ صورةٍ من
> over-engineering في هذه الخطّة كلِّها، وأنا أرشّح تأجيلَها بلا تاريخ.

---

## 27. استراتيجيّةُ الانتقال من الحال الراهنة

### خمسُ قواعد

**١) إضافةٌ لا تحويل.** الجداولُ الأحدَ عشرَ جديدة، والأعمدةُ الستّة كلُّها
`NULL`. لا هجرةَ بيانات، ولا `UPDATE` على صفٍّ قائم.

**٢) لا تُعدَّل هجرةٌ تاريخيّة.** قاعدةٌ مثبَّتةٌ من المرحلة السابقة.

**٣) كلُّ جدولٍ جديدٍ يُصنَّف في `TenantTables` في الـPR نفسِه** — وإلّا سقط
`ARestoreReturnsTheShopTest`. وترتيبُ `ORDER` يجب أن يحترم المفاتيح الأجنبيّة:
```
… customers · products …
  → quotations → quotation_items
  → projects → project_items → project_members → project_tasks
  → documents → document_approvals
  → delivery_packages → delivery_package_items
  → company_policies → policy_exceptions
```

**٤) القديمُ يُطفأ بعد أن يعمل الجديد.** قسمُ «المبيعات» لا يُحذف في المرحلة
التي يُبنى فيها المشروع — بل في التي تليها، بعد أن تكون التقاريرُ قد بُدِّلت
مصادرُها. **لا فجوةَ يقرأ فيها النظامُ صفرًا.**

**٥) الهجراتُ المُتلِفة آخرًا.** إسقاطُ `orders` — إن تقرّر — في مرحلةٍ
مستقلّةٍ بعد عدِّ الصفوف وتأكيد صفر مرجع.

### ترتيبُ الإطفاء

```
م1: تُبدَّل مصادرُ التقارير واللوحة إلى الفواتير    ← يُصلَح العطب 0.1
م2: يُبنى العرضُ والمشروع بجانب «المبيعات»
م4: يُخفى قسمُ «المبيعات» من القائمة، وتبقى مساراتُه
م6: تُحذف المسارات · تُسقَط الجداول (إن كان الإنتاج صفرًا)
```

---

## 28. المخاطر

| # | الخطر | الأثر | التخفيف |
|---|---|---|---|
| **1** | **ازدواجُ الاعتراف بالإيراد** — أن يُحيا مسارُ كتابة `Order` فتُقيَّد البيعةُ مرّتين | 🔴 دفترٌ خاطئ · إقرارٌ خاطئ | **لا يُحيا مسارُ إنشاء الطلب أبدًا.** واختبارٌ يثبّت أنّ كلَّ إيرادٍ مصدرُه `CustomerInvoice` |
| **2** | **الدفعةُ المقدَّمةُ كذمّةٍ سالبة** (§0.2) | 🔴 ميزانيّةٌ خاطئة · ضريبةٌ غيرُ مقرَّرة | فاتورةُ دفعةٍ مقدَّمة — اتّفاقٌ لا كود |
| **3** | **التقاريرُ تبقى عمياء** (§0.1) | 🔴 إقرارٌ ضريبيٌّ خاطئ **اليوم** | المرحلةُ 1 لا المرحلةُ 5 |
| **4** | **`Demo` تبتلع الوحدات الجديدة** — 2902 سطر و86 دالّة | 🟠 دَينٌ يتضاعف | **قاعدةٌ صريحة: لا دالّةَ جديدةً في `Demo`.** كلُّ وحدةٍ تملك صنفَ دعمِها |
| **5** | **توسيعُ السطح العامّ** — روابطُ قبولٍ بلا مصادقة | 🔴 تسريبُ أسعارٍ وشروط | نمطُ `PublicDocument::PUBLIC`: **المنعُ من المصدر** · تحديدُ المعدّل · انتهاءُ صلاحية · لا وجهَ عامَّ لمستندِ مورّد |
| **6** | **تعريفان للربح** | 🟠 رقمان متناقضان | `ProjectFinancials` موضعٌ واحد · اختبارٌ يقابل التعريفين |
| **7** | **SQLite ضدّ PostgreSQL** | 🟠 يمرّ محلّيًّا ويسقط إنتاجًا | `MAX()` · `ORDER BY` مع التجميع · `SAVEPOINT` — ثلاثةُ فخاخٍ موثَّقةٌ في الكود. كلُّ SQL خامٍ يُفحص على المحرّكين |
| **8** | **تضخّمُ الصلاحيات** — 14 قسمًا و34 فعلًا | 🟠 لا أحدَ يفهم من يرى ماذا | شاشةُ «ماذا يرى هذا الموظّف؟» · `EveryRoleIsProbedOnEveryRouteTest` |
| **9** | **الاستمراريّة** — `document_snapshot` يجعل الأوراق غيرَ قابلةٍ للإصلاح الجماعيّ | 🟡 عيبُ قالبٍ لا يُصلَح أثرًا رجعيًّا | مقصودٌ ومقبول · `Version::KNOWN` تبقي القديم |
| **10** | **حجمُ الخطّة** — 13 وحدةً | 🔴 **الخطرُ الأكبر**: البدءُ بكلّ شيء | مرحلةٌ واحدةٌ في الطريق · كلُّ مرحلةٍ تُسلَّم عاملةً · **لا فرعَ يبقى مفتوحًا شهرًا** |
| **11** | **تسريبُ البيانات إلى مزوّد الذكاء** | 🟠 نصُّ طلبِ العميل يخرج | اختيارٌ صريحٌ في الإعدادات · يُقال في الشاشة · لا يُشغَّل افتراضيًّا |
| **12** | **بين الشركات يكسر العزل** | 🔴 شركةٌ تقرأ بيانات أخرى | لا استعلامَ عابر · مستنداتٌ متقابلة · صلاحيّةٌ منفصلة |

---

## 29. أثرُه على الدَّين التقنيّ

### ما **يُنقصه** هذا التصميم

| الدَّين | كيف يُسدَّد |
|---|---|
| طبقةٌ تحليليّةٌ تقرأ جدولًا ميّتًا | تُبدَّل مصادرُها في م1 |
| `orders` وتوابعُها: 4 نماذجَ و12 مسارًا و2 جدولٍ لا يكتبها شيء | تُطفأ ثمّ تُسقَط |
| خمسةُ أشكالٍ لحفظ ملفّ | شكلٌ سادسٌ موحَّدٌ للجديد، وأساسٌ لتوحيدٍ لاحق |
| `subject_type` نصٌّ حرٌّ في سجلّ النشاط | ثابتٌ واختبار |
| مدخلُ `profitability` الميّت | يُصلَح مع مقاييس المشاريع |

### ما **يزيده** — بصدق

| الدَّين الجديد | الحجم | لماذا مقبول |
|---|---|---|
| 11 جدولًا و11 نموذجًا | كبير | يقابل عملًا حقيقيًّا لا وجودَ له اليوم |
| 6 أعمدةٍ على جداولَ ماليّةٍ ناضجة | صغير | كلُّها `NULL` ولا تغيّر سلوكًا |
| قسمان جديدان في الصلاحيات | متوسّط | الحرّاسُ يمسكون الأثر |
| منطقُ ربحيّةٍ يُشتقّ في كلّ قراءة | متوسّط | مقصود · يُراجَع بالقياس لا بالتخمين |
| `Demo` تبقى 2902 سطرًا بلا حلّ | **قائم** | مجمَّدةٌ لا مُصلَحة — إعادةُ التسمية تبقى مؤجَّلة |

### ⚠️ ديونٌ قائمةٌ لا يعالجها هذا التصميم — وأقولُها كي لا تُنسى

1. **`Demo`** — 2902 سطر و86 دالّة و103 استدعاءات لـ`bid()`. التجميدُ يمنع
   التضخّم ولا يُصلح القائم.
2. **`orders` بـ34 عمودًا** بقيت فيها أعمدةُ محلّ ورود (`card_message` ·
   `sender_name` · `hide_sender` · `occasion_type`). تذهب مع الجدول.
3. **`ReportData` بـ864 سطرًا و15 دالّةً** كلٌّ منها تبني استعلامَها — تُبدَّل
   مصادرُها ولا تُعاد هيكلتُها.
4. **لا عاملَ صفٍّ يعمل.** كلُّ ما هو دوريٌّ في المجدول. مقبولٌ اليوم، ويصير
   حدًّا حين يدخل إرسالُ الرسائل.

---

## 30. المراحلُ الموصى بها

> **مبدأُ الترتيب:** كلُّ مرحلةٍ تُسلَّم **عاملةً ومختبَرةً ومدموجة**. ولا
> تُبدأ الثانيةُ قبل أن تصير الأولى خضراءَ في `main`.

### المرحلة 1 — عينُ النظام تُفتح ⚠️ *قبل أيّ ميزة*

**لماذا أوّلًا:** الإقرارُ الضريبيّ خاطئٌ **الآن** (§0.1). وكلُّ ما نبنيه فوق
طبقةٍ تحليليّةٍ عمياءَ يُبنى على رملٍ.

- `ReportData::vat()` تقرأ ضريبةَ المخرجات من `customer_invoices` الصادرة
- `ReportData::orders()` → تقريرُ «فواتير العملاء»
- `DashboardMetrics` و`Demo::reportSummary` و`profitStats` → الفواتير
- `AlertMetrics` → مقاييسُ الفواتير والتحصيل
- تقريرا «العملاء الأكثر إنفاقًا» و«أداء الموظفين» → الفواتير
- **إصلاحُ مدخل `profitability` الميّت**

**المحصّلة:** لوحةٌ وتقاريرُ وإقرارٌ تقول الحقيقة. **صفرُ جداولَ جديدة.**

---

### المرحلة 2 — عرضُ السعر 🎯 *أكبرُ مكسبٍ لكلّ ساعة*

**لماذا ثانيًا:** هو ألمُك المعلَن، وأكثرُ ما يُعيد استعمالَ القائم (شاشةُ
الفاتورة بـ2176 سطرًا)، **ويقف وحده**: عرضٌ ← فاتورةٌ مباشرةً بلا مشروع.

- `quotations` + `quotation_items`
- استخراجُ `CustomerInvoices::compute()` إلى `LineTotals` مشتركة — **لا نسخةَ
  ثانيةٍ لحساب الإجماليات**
- شاشةُ إنشاءٍ مبنيّةٌ على `CustomerInvoices/Create.tsx`
- نوعُ ورقةٍ `quotation` في `DocumentTemplates::TYPES` + قالب PDF + معاينةٌ من
  الخادم
- التنقيحات: `root_id` · `revision` · `superseded`
- **ضبطُ الخصم كاملًا** (§4.4): `list_price` من الخادم · الصلاحيّات ·
  السبب · السجلّ
- الحالاتُ السبع + المشتقّة
- الصلاحيّاتُ السبع + قسمُ `quotations`
- `Quotation → CustomerInvoice` (نسخُ بنودٍ مباشر)

**المحصّلة:** الشركةُ تُصدر عروضَها من النظام. وقيمةٌ حقيقيّةٌ تُسلَّم مبكرًا.

---

### المرحلة 3 — الإرسالُ والقبولُ والشروط

- `document_approvals` فوق `DocumentLink` القائم
- صفحةُ قبولٍ عامّة · `content_hash` · الأثر
- `company_policies` — **النصُّ والختمُ أوّلًا** (§18)، والفحصُ الآليّ
  بقاعدةٍ واحدة: `lead_time_days`
- `policy_exceptions` + `pending_approval`
- `Notifier` + تنفيذُ البريد (رابطُ العرض · التذكير)

**المحصّلة:** عرضٌ يُرسَل ويُقبَل إلكترونيًّا بشروطٍ مختومة.

---

### المرحلة 4 — المشروع / الفعالية

- `projects` · `project_items` · `project_members` · `project_tasks`
- `Quotation(accepted) → Project` بلا إعادة إدخال
- الأعمدةُ الستّة (`project_id` × 5 + `products.kind`)
- شاشةُ المشروع بتبويباتها على نمط `SectionTabs` القائم
- `ProjectFinancials` — الأرقامُ الأحدَ عشرَ (§16)
- **إخفاءُ قسم «المبيعات» من القائمة**
- تقريرا «ربحيّةُ المشاريع» و«المشاريعُ الجارية»

**المحصّلة:** المشروعُ مركزُ العمل، وربحيّتُه تُقرأ.

---

### المرحلة 5 — المستنداتُ والتسليمُ والقبول

- `documents` الموحَّد بتصنيفاته ونسخِه
- `delivery_packages` + بنودُها
- قبولُ التسليم بآليّة المرحلة 3 نفسِها
- إحياءُ `DeliveryNote` من المشروع
- فوترةُ المعالم والدفعات المقدَّمة (§16)

---

### المرحلة 6 — مركزُ الإجراءات والأتمتة

- `SignalRegistry` + 11 إشارة
- `ActionCenter::for()` فوق `DismissedNotification` القائم
- لوحةُ تحكّمٍ تُعاد بناؤها حول «ما يحتاج انتباهي»
- أمرُ `ops:scan` اليوميّ
- `follow_ups`
- **إسقاطُ `orders`** — إن كان الإنتاج صفرًا

---

### المرحلة 7 — الرسائلُ الموحَّدة

بشرط: رقمُ WhatsApp Business معتمَد وقوالبُ مُجازة.
`messages` · `conversations` · `WhatsAppNotifier` · الـwebhook.

---

### المرحلة 8 — المساعدُ الذكيّ

`ai_extractions` · الاستخراجُ من نصّ · تعبئةُ نموذج العرض · `ai.use`.
**ولا بابَ كتابةٍ ثانٍ.**

---

### مؤجَّلٌ بشرط

| الوحدة | الشرط |
|---|---|
| **بين الشركات** | كيانان قانونيّان قائمان فعلًا |
| **حجزُ المعدّات** | إن كان هناك تأجيرٌ يعود |
| **نسبةُ الإنجاز** | مشاريعُ تعبر السنةَ الماليّة |
| **`Demo` → اسمٌ يصفها** | نافذةٌ لا ميزةَ فيها |
| **توحيدُ المرفقات الخمسة** | دورةٌ مستقلّة |

---

## الخلاصة — القرارات العشرة

| # | القرار | التوصية |
|---|---|---|
| 1 | `Order` → مشروع؟ | ❌ **لا.** `Project` جديد · `Order` يُتقاعد (§14) |
| 2 | عرضُ السعر | نموذجٌ جديدٌ **مرآةً** لفاتورة العميل · إجمالياتٌ مشتركة (§4) |
| 3 | الحالات | 7 مخزَّنةٍ بمفاتيحَ لاتينيّة · `expired`/`viewed` **تُشتقّان** (§4.2) |
| 4 | الكتالوج | `products.kind` — **لا نموذجَ `Service`** (§15) |
| 5 | ربحيّةُ المشروع | `project_id` على المستندات · **لا بُعدَ على `journal_lines`** (§8.3) |
| 6 | الاعتراف بالإيراد | عند **إصدار الفاتورة** — بلا حرفٍ واحدٍ من التعديل (§7) |
| 7 | الدفعةُ المقدَّمة | **فاتورةُ دفعةٍ مقدَّمة** لا تحصيلٌ حرّ (§0.2 · §16) |
| 8 | السياسات | قائمةٌ مغلقة + أصنافُ فحص · **النصُّ والختمُ أوّلًا** (§18) |
| 9 | التوقيع | داخليٌّ فوق `DocumentLink` · حدُّ المزوّد يُصمَّم ولا يُنفَّذ (§19) |
| 10 | الترتيب | **إصلاحُ عمى التقارير قبل كلّ شيء** (§0.1 · §30) |

