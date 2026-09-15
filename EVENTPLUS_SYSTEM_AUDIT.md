# تقرير فحص نظام Event Plus — الحالة الفعلية

> **نوع التقرير:** Discovery / Audit فقط — لم يُعدَّل ولم يُحذف شيء أثناء إعداده.
> **تاريخ الفحص:** 2026-09-15
> **المصدر:** الكود الفعلي في `/Users/macbook/event-plus-oss` (فرع `remove-sections`، آخر كوميت `92fac69`).
> **ملاحظة:** لم يُعتمد على أيّ توثيق سابق. كلّ رقمٍ وكلّ اسمٍ في هذا التقرير مأخوذ من قراءة الملفات أو من تشغيل أدوات المشروع.

---

## المنهجية — ماذا شُغِّل فعليًّا

| الفحص | الأداة | النتيجة |
|---|---|---|
| جرد المسارات | `php artisan route:list --json` | 298 مسارًا |
| جرد الجداول | `sqlite3 database.sqlite .tables` + `PRAGMA table_info` | 67 جدولًا + أعمدة كل جدول |
| المهام المجدولة | `php artisan schedule:list` + استدعاء كل أمر | 10 مهام، **3 منها معطوبة** |
| مفاتيح الترجمة | `php artisan translations:check` | 2507 مستعملة مقابل 5780 في `en.json` |
| صحّة الأنواع | `npx tsc --noEmit` | صفر أخطاء |
| الاختبارات | `phpunit --log-junit` ثم تحليل XML | 2069 اختبارًا · 0 إخفاق · 0 خطأ |
| الشاشات اليتيمة | مقارنة `Inertia::render()` بملفات `Pages/` | صفحتان يتيمتان |
| الدوالّ اليتيمة | مطابقة دوالّ المتحكّمات بجدول المسارات | 6 دوالّ بلا مسار |
| الأصناف اليتيمة | عدّ المراجع لكل ملف في `Models/` و`Support/` و`Components/` | نموذجان + 4 وحدات واجهة |
| القوالب اليتيمة | مطابقة `view('...')` بملفات `views/` | 8 قوالب |

> **عن رمز خروج الاختبارات:** `phpunit` يُرجع الرمز 2 في هذه البيئة حتى على اختبارٍ واحدٍ ناجح — أثرٌ من غلاف إخراجٍ محلّي لا من الاختبارات. الحقيقة من `--log-junit` مباشرةً: **2069/2069، صفر إخفاق، صفر خطأ**.

---

## 0. الملخّص التنفيذي

**النظام سليم وقابل للبناء عليه.** ما بقي من أبعاد ليس عيبًا في الهيكلة، بل بقايا سطحية: أسماء، ونصوص واجهة، وأعمدة لا يكتبها أحد، وشيفرة ميتة لم تُكنَس بعد الحذف.

ثلاث نتائج تستحقّ انتباهك قبل غيرها:

1. **عطبٌ حقيقيّ في الإنتاج:** المجدول يستدعي **ثلاثة أوامر غير موجودة** (`subscriptions:expire`، `subscriptions:notify`، `whatsapp:sync-templates`). كلّ ليلةٍ يفشل ثلاثة نداءات بصمت.
2. **لا طريق لإنشاء عملية بيع.** قسم «المبيعات» يعرض ويصحّح ويطبع — ولا يُنشئ. نقطة البيع كانت المسار الوحيد. **فواتير العملاء تُنشأ وتُصدر طبيعيًّا**، وهي على الأرجح البديل المناسب لشركة تقنية.
3. **هيكل المحاسبة والفوترة أقوى ممّا يبدو من اسم النظام.** دفتر أستاذ مزدوج القيد كامل، وشجرة حسابات، وفواتير عملاء تحمل `po_number` و`contract_number` و`cost_center` و`department` وبنودًا **نصّيّة حرّة** — أي فوترة خدمات لا فوترة أصناف. هذا جاهزٌ لشركة مشاريع كما هو تقريبًا.

**الأرقام:** 27 عنصرًا للإزالة · 19 للتحويل · 14 يحتاج قرارك · والباقي صالحٌ كما هو.

---

## 1. الحالة بالأرقام

| البند | العدد |
|---|---|
| المسارات | **298** (270 تحت `admin.` + 28 عامّة ومصادقة) |
| النماذج (Models) | 55 |
| المتحكّمات (Controllers) | 67 |
| أصناف الدعم (`app/Support`) | 78 |
| الهجرات | 104 |
| جداول قاعدة البيانات | 67 |
| صفحات الواجهة (`Pages/*.tsx`) | 97 |
| مكوّنات الواجهة | 55 |
| قوالب Blade | 66 |
| أوامر الطرفية | 16 |
| ملفات اختبار PHP | 218 (2069 اختبارًا) |
| ملفات اختبار الواجهة | 11 |
| أسطر PHP في `app/` | ‎47,626 |
| أسطر TS/TSX | ‎39,756 |

### توزيع المسارات على الأقسام

```
المنتجات 39 · المالية 34 · المشتريات 26 · التقارير 23 · العملاء 18
فواتير العملاء 17 · المخزون 12 · المبيعات 12 · الإعدادات 11 · الموردون 10
المصروفات 9 · الموظفون 8 · التصدير 8 · الرواتب 8 · الفروع 4
تحصيلات العملاء 4 · وما دونها
```

---

## 2. المعمارية الحالية

```
المتصفّح
   │
   ├─ Inertia 3 + React 19 + TypeScript + Tailwind 4   (resources/js)
   │     AdminLayout ─ Sidebar (lib/nav.ts) ─ Topbar ─ SectionTabs
   │
Laravel 13 / PHP 8.4
   │
   ├─ routes/web.php ─── مجموعة واحدة:
   │      middleware: auth → tenant → business → panel → ability
   │
   ├─ Controllers (67) ─── رقيقة: تتحقّق، تستدعي طبقة الدعم، تُعيد Inertia
   │
   ├─ app/Support (78) ─── حيث يعيش المنطق فعلًا
   │      Demo ............ واجهة قراءة البيانات (3129 سطرًا، 75 دالّة)
   │      Books / Ledger .. الجسر إلى دفتر الأستاذ، وبابُ القيد الوحيد
   │      CustomerInvoices  دورة حياة فاتورة العميل
   │      Permissions ..... الأقسام والأفعال والأدوار
   │      Tenancy ......... حالة المستأجر
   │
   └─ Models (55) ─── Eloquent، كلّها تحت business_id
```

### مبدآن حاكمان في الكود

**١) `business_id` في كل مكان.** كلّ جدولٍ للمستأجر يحمله، وكلّ استعلامٍ يُرشِّح به عبر `Demo::bid()` (103 استدعاء). الحارس عند الباب (`RequiresBusiness`) لا في كل شاشة. أبقيتَ هذا عمدًا — وهو صحيح: يصلح لاحقًا لفصل الشركات أو الفروع المستقلّة.

**٢) بابٌ واحد لكلّ كتابةٍ خطرة.** لا يدخل الدفترَ قيدٌ إلا من `Ledger::post`. ولا يُعدَّل بند فاتورةٍ صدرت إلا من `OrderCorrection`. ولا تتحرّك كميّةٌ إلا من `StockLedger`. هذا أهمّ ما في الهيكلة وأجدرُه بالحفاظ.

### العلاقات الأساسية

```
Business ─┬─ User ─── Branch (many-to-many)
          ├─ Branch ─── BranchStock ─── Product
          ├─ Category ─── Product ─┬─ ProductVariant
          │                        ├─ RecipeItem ─── (منتج آخر)
          │                        ├─ ProductImage
          │                        └─ Addon (product_addons)
          ├─ Customer ─┬─ CustomerAddress
          │            ├─ Order ─┬─ OrderItem ─── OrderItemAddon
          │            │         ├─ OrderEdit
          │            │         └─ Transaction
          │            └─ CustomerInvoice ─┬─ CustomerInvoiceItem
          │                                ├─ CustomerInvoiceAttachment
          │                                ├─ CustomerCreditNote
          │                                └─ Order (customer_invoice_orders)
          ├─ Supplier ─┬─ PurchaseOrder ─── PurchaseOrderItem
          │            └─ SupplierInvoice
          ├─ GoodsReceiptNote ─── GoodsReceiptNoteItem
          ├─ Account ─── JournalEntry ─── JournalLine
          ├─ BankAccount ─── BankStatementLine
          ├─ PayrollRun ─── PayrollLine
          ├─ FixedAsset · Expense · ExpenseType · Transaction
          ├─ InventoryMovement · StockAdjustment
          └─ Setting · ActivityLog · CustomAlert · Currency · JobTitle
```

---

## 3. الأقسام الموجودة — ماذا يفعل كلٌّ منها

القائمة الجانبية (`resources/js/lib/nav.ts`) فيها **11 عنصرًا**. وفي `Permissions::SECTIONS` **12 مفتاح صلاحية** (يزيد `suppliers`، وهو تبويبٌ داخل «العملاء» لا عنصرٌ في القائمة).

### 3.1 لوحة التحكم — `admin.dashboard`
**ما يفعله المستخدم:** يقرأ. لا كتابة إلا هدف الشهر.

- مؤشّرات قابلة للاختيار من `AlertMetrics::catalog`: مبيعات اليوم · مصروفات الشهر · الطلبات المعلّقة · أصناف تحت الحدّ · عملاء خاملون · أوامر شراء مفتوحة · ربح اليوم
- رسم بياني مساحي: المبيعات على مدار السنة · رسم أعمدة: توزيع وسائل الدفع
- آخر 6 عمليات بيع · أعلى 5 منتجات مبيعًا · أعلى الموظفين
- تغذية حيّة بالاستطلاع (`admin.dashboard.stats`) فلا تتجمّد الصفحة المتروكة مفتوحة
- هدف شهري يُضبط من الشاشة (`admin.goals.update`)

**الحالة:** ✅ سليم · **التصنيف:** ADAPT (المؤشّرات مبنية على مبيعات المتجر — تحتاج تحويلًا إلى مؤشّرات مشاريع)

---

### 3.2 العملاء — `admin.customers.index` (18 مسارًا)
تبويبان: **العملاء** و**الموردون**.

**ما يفعله المستخدم:** إضافة عميل وتعديله وحذفه (حذفٌ ناعم) واستعادته · عناوين متعدّدة مع عنوان افتراضي · ملاحظة على العميل · كشف حساب PDF · استيراد وتصدير Excel/PDF مع **معاينة قبل التأكيد** وربط أعمدة يدوي.

**الحقول — وهنا المفاجأة السارّة:** جدول `customers` يحمل بالفعل حقول الأعمال بين الشركات:

```
customer_type (فرد | شركة | جهة حكومية) · legal_name · commercial_registration
contact_person · contact_email · contact_phone · department · customer_reference
billing_address · tax_number · allow_credit_sales · credit_limit
payment_terms_days · monthly_billing
```

هذا **جاهزٌ لعملاء شركة تقنية** بلا هجرةٍ واحدة. والافتراضي في `CustomerInvoiceController` هو `'شركة'` أصلًا.

**بقايا:** عمود `points` (نقاط ولاء) — يُعرض في جدول العملاء وفي صفحة العميل ويُصدَّر ويُستورد، **ولا شيء يزيده** منذ حذف نقطة البيع.

**الحالة:** ✅ سليم · **التصنيف:** KEEP (مع REMOVE لنقاط الولاء)

---

### 3.3 المنتجات — `admin.products.index` (39 مسارًا — أكبر قسم)
**ما يفعله المستخدم:** إنشاء وتعديل وحذف (ناعم) واستعادة ومحوٌ نهائي · تعطيل/تفعيل · نسخ صنف · تعديل سريع في الخليّة · إجراء جماعي · معرض صور مع صورة رئيسية · استيراد/تصدير Excel وPDF · إنشاء تصنيف أو إضافة من جانب الحقل.

**التركيب (`ProductCompositionController`):**
- **المقاسات** (`ProductVariant`) — سعر لكلّ مقاس
- **الوصفة** (`RecipeItem`) — ممّ يُصنع المنتج، وكم يُخصم من الرفّ حين يُباع
- **الإضافات** (`Addon`) — ما يُباع مع المنتج، وما تأكله من المخزون

**بقايا:** «الوصفة» و«الإضافات» مفهومان من محلّ الورود والمطعم (`Recipe`: «ممّ تُصنع الباقة»). وعمود `products.published` من المتجر الإلكتروني المحذوف. ونصوص الواجهة تقول «يظهر في نقطة البيع».

**الحالة:** ✅ سليم تقنيًّا · **التصنيف:** ADAPT (المنتج → خدمة/باقة خدمات؛ الوصفة والإضافات: REVIEW)

---

### 3.4 المبيعات — `admin.orders.index` (12 مسارًا)
**ما يفعله المستخدم:** يقرأ قائمة الطلبات ويُرشِّحها · يفتح الطلب · ينقل حالته · يعدّل بيانات التنفيذ · **يصحّح فاتورةً صدرت** (بصلاحية `order.edit` باسمها، مع سبب، ومع تحريك المخزون والمال معًا) · يطبع فاتورة A4 وإيصالًا حراريًّا وسند تسليم وفاتورة ضريبية · يصدّر Excel وPDF.

> ### ⚠️ لا مسار يُنشئ عملية بيع
> فُحِص بالبحث عن كلّ استدعاءات `Order::create`. الموجود:
> - `DemoStore.php` — بناء بيانات تجريبية
> - `SeedSalesHistory.php` — أمر طرفية
>
> ولا مسار HTTP واحد. `pos/checkout` كان الطريق الوحيد، وقد أُزيل بطلبك. النتيجة: القسم **للقراءة والتصحيح والطباعة فقط**.
>
> ومقابله: `admin.customerInvoices.store` يُنشئ فواتير العملاء بلا أيّ اعتمادٍ على الطلبات.

**بقايا محل الورود في جدول `orders`:**
```
fulfillment_type (استلام | توصيل) · occasion_type · card_message
sender_name · hide_sender · recipient_name · recipient_phone · scheduled_for
```
تُدار من `app/Support/FlowerOrder.php` (422 سطرًا) — «المستلِم والموعد والمناسبة وبطاقة الإهداء».

> **ملاحظة تستحقّ التفكير:** هذه الحقول تحديدًا — مستلِمٌ غير المشتري، وموعدٌ مجدول، ومناسبة، وعنوان تسليم — هي **بنية حدثٍ** لا بنية باقة ورد. شركة فعاليات تحتاج الشيء نفسه بأسماء أخرى: `scheduled_for` = موعد الحدث، `occasion_type` = نوع الفعالية، `recipient_*` = جهة الاتصال في الموقع. هذا مرشّح ADAPT من الدرجة الأولى، لا REMOVE.

**بقايا نقطة البيع في الجدول:** `is_held` · `shift_id` · `coupon_code` · `coupon_discount` · `points_earned` · `redeemed_points` · `review_request_sent_at` · `status_notice_at` · `status_notice_event`

**الحالة:** ⚠️ ناقص طريق الإنشاء · **التصنيف:** ADAPT + REVIEW

---

### 3.5 فواتير العملاء — `admin.customerInvoices.index` (17 مسارًا + 4 للتحصيل)
**أنضج قسمٍ في النظام، وأصلحه لشركة تقنية.**

**دورة الحياة:** مسودّة → إصدار → (تحصيل جزئي/كامل) → إلغاء أو إشعار دائن

- المسودّة لا تُنشئ ذمّة ولا تكتب قيدًا ولا تستهلك رقم فاتورة
- **الإصدار** يولّد الذمّة ويكتب القيد في الدفتر — بصلاحية `customerInvoice.issue` باسمها
- **الإلغاء** يعكس القيد ولا يمحو الورقة
- **الإشعار الدائن** يُنقص دَينًا قائمًا — بصلاحيته المستقلّة
- **التحصيل** سندُ قبضٍ له صفحته وورقته، ويُوزَّع على عدّة فواتير
- مرفقات على القرص الخاص، تُقرأ ببابٍ يسأل عن الصلاحية
- معاينة قبل الحفظ (POST لا GET، فلا تمرّ الفاتورة في شريط العنوان)
- هويّة الورقة: شعار واسم ولغة وذيل — يُضبطان بصلاحية «الإعدادات» لا «المبيعات»
- **فوترة شهرية مجمّعة** (`consolidate`) — ورقةٌ واحدة على طلباتٍ آجلة لم تُفوتر
- رمز QR للفوترة الإلكترونية (TLV/Base64) عبر `EInvoice`
- رابط عامّ قصير للورقة (`/i/{token}`) يُمسح من الرمز المطبوع

**حقول الفاتورة — بنية أعمالٍ لا بنية متجر:**
```
po_number · contract_number · external_reference · department · cost_center
attention_to · payment_terms_days · customer_cr · customer_tax_number
issued_by_at · cancelled_by · cancellation_reason · document_snapshot
```

**وبنود الفاتورة `description` نصٌّ حرّ و`product_id` اختياري** — أي أنّ النظام **يفوتر خدماتٍ** لا أصنافًا فقط. هذا بالضبط ما تحتاجه شركة مشاريع.

**الحالة:** ✅ ممتاز · **التصنيف:** KEEP — وهذا هو القلب الذي يُبنى عليه Event Plus

---

### 3.6 المشتريات — `admin.purchases.index` (26 مسارًا)
ثلاث شاشات: **قائمة المشتريات** · **سندات الموردين** · **أوامر الشراء**.

**أمر الشراء:** إنشاء · معاينة قبل الحفظ · استلام (كلّي أو جزئي) · اعتماد الاستلام أو رفضه **على الإشعار لا على الأمر** (دفعة سليمة تدخل ودفعة ناقصة تُرفض) · رفع إيصال الدفع ومرفق الأمر · طباعة PDF · وحدات شراء · مورّد افتراضي · حقول: `expected_delivery_at` · `supplier_reference` · `shipping_cost` · `payment_terms_days` · `payment_method`

**سند المورّد:** إدخال → مطابقة مع الاستلام → اعتماد أو رفض → **تجاوز عدم المطابقة** (بصلاحيته) → سداد → إلغاء بعكس القيد. كلّ خطوةٍ فعلٌ مستقلّ بصلاحيةٍ باسمها.

**الحالة:** ✅ ممتاز — دورة شراء كاملة بفصل المهامّ · **التصنيف:** KEEP (مشتريات الشركة: معدّات، تراخيص، مقاولون من الباطن — المفهوم واحد)

---

### 3.7 المالية — `admin.finance.index` (34 مسارًا)
**أوسع قسمٍ وأعمقه.** عشرة تبويبات:

| التبويب | ما فيه |
|---|---|
| الملخّص المالي | أين المال الآن، وماذا جرى في المدّة |
| الحركة المالية | ما دخل وما خرج + تسجيل ما لا مستند له |
| الذمم المدينة | ما لك على العملاء + كشف حساب + ضبط الائتمان + فوترة الشهر |
| الشيكات | شيك تحت التحصيل → صُرف أو ارتدّ |
| المبالغ المستحقّة | ما عليك |
| مصاريف شهرية | (قسم المصروفات) |
| الحسابات البنكية | متعدّدة، بحسابٍ رئيسي، مع استيراد كشف ومطابقة آلية |
| القيود اليومية | قيد يدوي + **عكس قيد** |
| شجرة الحسابات | إضافة وتعديل وتعطيل + ميزان مراجعة |
| أصول ثابتة | شراء · إهلاك · استبعاد |

**الحالة:** ✅ ممتاز · **التصنيف:** KEEP بالكامل

---

### 3.8 الرواتب والموظفين — `admin.employees.index` (8 + 8 مسارًا)
**الموظفون:** إنشاء حساب بكلمة مرور تُصدَر · تعديل · تعطيل · إعادة تعيين كلمة المرور · صفحة الموظف مع سجلّ نشاطه وأدائه · هدف شهري · مسمّى وظيفي · ربط بفروع · راتب أساسي وبدلات · **صلاحيات مخصّصة لكلّ موظف** فوق دوره.

**الرواتب:** مسيرة شهرية (`PayrollRun`) → اعتماد → صرف. قيدان لا واحد: الاعتماد يُقيّد المستحقّ، والصرف يُخرج المال. أسطرها تُعدَّل وتُحذف قبل الاعتماد. `payroll_lines` فيها: أساسي · بدلات · **إضافي** · خصومات · صافي · وسيلة الدفع.

**الحالة:** ✅ سليم · **التصنيف:** KEEP (ويحتاج توسيعًا لاحقًا: إجازات، حضور، عقود — غير موجودة اليوم)

---

### 3.9 المخزون — `admin.inventory.index` (12 مسارًا)
أربعة تبويبات: **المنتجات** · **الجرد** · **سجلّ المخزون** · **إشعارات الاستلام**.

- رصيدٌ لكلّ فرع (`branch_stocks`) لا رصيدٌ واحد
- **الجرد** (`stocktake`): عدُّ الواقع ومقارنته بالدفتر، والفرق يُقيَّد خسارةً أو زيادة
- **التعديلات** (`stock_adjustments`): تُنقص المخزون **وتُقيّد الخسارة معًا** — لا تنقيص عددٍ بلا قيد
- **إشعارات الاستلام**: قراءةٌ فقط، تُنشئها لحظةُ الاستلام
- تنبيه انخفاض المخزون، واقتراحات إعادة الطلب
- **تحليلات الهالك** (`Waste` + `WasteInsights`): أيّ تعديلٍ يعني بضاعةً ضاعت، وكم كلّفت

**بقايا:** «الهالك» مفهومٌ للسلع القابلة للتلف. و`StockTransfer` (نموذج + جدول + هجرة) **ميّت تمامًا** — لا مسار ولا شاشة ولا مرجع.

**الحالة:** ✅ سليم · **التصنيف:** REVIEW (هل تحتاج شركة تقنية مخزونًا؟ ربّما للأجهزة والمعدّات — لكن لا للوصفات والهالك)

---

### 3.10 التقارير — `admin.reports.index` (23 مسارًا)
فهرسٌ يقود إلى **16 تقريرًا**، لكلٍّ صفحته ومبدّل فترته ومؤشّراته:

```
ملخّص المبيعات · الحركة المالية · ضريبة القيمة المضافة · المصروفات
وسائل الدفع · كشف الحساب البنكي · الطلبات · المنتجات · المخزون والكميات
عمليات الجرد · أوامر الشراء · المورّدون · أداء الموظفين · سجلّ النشاط
العملاء الأكثر إنفاقًا · تحليلات الهالك
```

- **صلاحية كلّ تقرير قسمُه لا «التقارير»** — فيه مبيعات الموظفين وإنفاق العملاء
- تنزيلٌ بثلاث صيغ من **بابٍ واحد** (`reports.{report}.export.{xlsx|csv|pdf}`) — لا 48 مسارًا
- التصدير يتبع الفترة المعروضة
- تغذية حيّة للصفحات المتروكة مفتوحة

**الحالة:** ✅ ممتاز معماريًّا · **التصنيف:** ADAPT (المحتوى بيعيّ، والبنية صالحة لتقارير المشاريع)

---

### 3.11 الإعدادات — `admin.settings.index` (11 مسارًا)
**11 بطاقة** في 5 مجموعات:

| المجموعة | البطاقات |
|---|---|
| المتجر | بيانات النشاط (اسم · هاتف · عنوان · شعار · لغة) |
| المالية | الضرائب والعملة والدفع · شجرة الحسابات |
| الأوراق | قوالب الأوراق (5 قوالب بمحرّر ومعاينة) |
| الفريق والتنبيهات | صلاحيات الموظفين · الإشعارات · تنبيهات مخصّصة · التنبيهات المرسلة |
| النظام | الفروع · الموظفون · سجلّ النشاط · المحذوفات · النسخ الاحتياطي |

- **قوالب الأوراق:** فاتورة البيع · فاتورة العميل · سند التسليم · أمر الشراء · سند الاستلام — لكلٍّ محرّرٌ ومعاينةٌ بحجم الورقة الحقيقي
- **المحذوفات:** سلّة بمهلة، والمحو النهائي بصلاحية الحذف نفسها
- **النسخ الاحتياطي:** تنزيل واستعادة لمتجرٍ واحد، بقائمة جداول واحدة (`TenantTables`)
- **تنبيهات مخصّصة:** قواعد يكتبها المستخدم على أيّ مؤشّر

**الحالة:** ✅ سليم · **التصنيف:** KEEP

---

## 4. نظام المستخدمين والأدوار والصلاحيات

نظامٌ **من ثلاث طبقات**، وهو من أفضل ما في المشروع.

### الطبقة الأولى: الدور (`users.role`)

`app/Support/Roles.php` — ثمانية أدوار:

| المفتاح | التسمية | يدخل اللوحة؟ | الحالة |
|---|---|---|---|
| `super_admin` | مدير المنصة | — | ⚠️ **بقيّة SaaS** — لا لوحة له بعد الحذف |
| `admin` | مدير نشاط | ✅ | كلّ الأقسام (`*`) |
| `manager` | مدير فرع | ✅ | كلّ الأقسام (`*`) |
| `accountant` | محاسب | ✅ | 9 أقسام |
| `inventory` | مسؤول مخزون | ✅ | 5 أقسام |
| `sales` | موظف مبيعات | ✅ | 4 أقسام |
| `delivery` | مندوب توصيل | ✅ | لوحة + مبيعات |
| `cashier` | كاشير | ❌ | ⚠️ **بقيّة POS** — لوحة فقط، ولا يدخل اللوحة أصلًا |

> `cashier` في وضعٍ متناقض: يملك صلاحية `dashboard` لكنّه ليس في `PANEL_ROLES` — فلا يستطيع فتح أيّ شاشة. دورٌ حيٌّ في القائمة وميّتٌ في الاستعمال.

### الطبقة الثانية: القسم (12 مفتاحًا)

```php
dashboard · customers · products · orders · inventory · finance
expenses · settings · suppliers · purchases · employees · reports
```

القسم يجيب: **أيّ شاشةٍ تُفتح**.

### الطبقة الثالثة: الفعل (18 فعلًا)

الفعل يجيب: **كم من الضرر تستطيع**. ويعيشان في العمود نفسه (`users.permissions`) ولا يختلطان — اسم الفعل فيه نقطة واسم القسم لا نقطة فيه.

```
order.edit ................ تصحيح فاتورة مكتملة
credit.override ........... ضبط ائتمان العميل وتجاوز حدّه
receipt.view/create/approve/reject ..... إشعارات استلام البضاعة
invoice.view/create/approve/reject/override/pay ... سندات الموردين
payroll.view/approve/pay ............... الرواتب
customerInvoice.issue/cancel ........... فواتير العملاء
customerCreditNote.create · customerPayment.create
attachment.view ........... فتح مرفقات المستندات المالية
website.configure ......... ⚠️ ميّت — الموقع محذوف
```

**التصميم الجيّد هنا:** من يفتح شاشة المشتريات ليس بالضرورة من يعتمد الاستلام، ولا من يدفع. ومن يضيف موظفًا ليس بالضرورة من يقرأ راتب كلّ من في الشركة. هذا فصلُ مهامٍّ حقيقيّ ونادر في أنظمة بهذا الحجم.

### آليّات مساندة
- `Permissions::ALIASES` — مسارٌ اسمه لا يشتقّ قسمه يُنسب صراحةً (14 مدخلًا، **اثنان منها ميّتان**: `devices` و`store`)
- `EXPORT_ALIASES` — التصدير يتبع قسم ما يُصدَّر
- `SHELL` — مسارات الهيكل لا الأقسام (**5 مداخل ميّتة**: `admin.help.*`)
- `ACTION_ROLES` — من يرث الفعل بدوره، ومن سواه يُمنحه بالاسم
- صلاحيات مخصّصة لكلّ موظف (`users.permissions` JSON) تعلو دورَه

**التصنيف:** KEEP الهيكل بالكامل · REMOVE دورَي `super_admin` و`cashier` وفعل `website.configure`

---

## 5. النظام المالي والمحاسبي

**دفتر أستاذ مزدوج القيد كامل** — لا واجهة مالية تجميلية.

### شجرة الحسابات (`Ledger::seedChart`)
تُبذر تلقائيًّا لكلّ شركة، **26 حسابًا نظاميًّا**:

```
الأصول      1100 الصندوق · 1200 البنك · 1300 ذمم العملاء
            1350 شيكات تحت التحصيل · 1400 المخزون
            1500 الأصول الثابتة · 1590 مجمّع الإهلاك
الخصوم      2100 ذمم الموردين · 2200 رواتب مستحقّة · 2300 ضريبة مستحقّة
حقوق الملكية 3100 رأس المال · 3200 الأرباح المحتجزة · 3300 مسحوبات المالك
الإيرادات   4100 إيراد المبيعات · 4800 إيرادات أخرى · 4900 مردودات المبيعات
المصروفات   5100 تكلفة البضاعة المباعة · 5150 مشتريات مباشرة
            5200 الرواتب · 5300 الإيجار · 5400 الإهلاك · 5500 كهرباء وماء
            5600 التسويق والإعلان · 5700 الصيانة · 5800 النقل والتوصيل
            5900 مصروفات أخرى
```

### الطبقات

| الطبقة | الملف | الدور |
|---|---|---|
| `Ledger` | 478 سطرًا | **بابُ القيد الوحيد** — `post` · `reverse` · `balance` · `trialBalance` |
| `Books` | 652 سطرًا | الجسر: `recordSale` · `recordExpense` · `recordMovement` · `costOf` |
| `Receivables` | 263 سطرًا | الذمم المدينة |
| `Cheques` | 248 سطرًا | عمر الشيك |
| `Bank` | 126 سطرًا | ما مرّ بالحساب فعلًا |
| `Vat` / `EInvoice` | — | ضريبة القيمة المضافة ورمز QR |

### ما يُقيَّد تلقائيًّا
بيعٌ يقع · مصروفٌ يُسجَّل · فاتورة عميلٍ تُصدَر · إشعارٌ دائن · تحصيل · سند مورّد يُعتمد · سدادٌ يخرج · استلام بضاعة · نقص/زيادة مخزون · إهلاك أصل · مسيرة رواتب تُعتمد وتُصرف · شيك يُصرف أو يرتدّ

### الأمان المحاسبي
- **لا محو، بل عكس:** مستندٌ صدر يبقى مقروءًا، والإلغاء يكتب قيدًا عكسيًّا
- **أمر استدراك ليليّ** (`finance:post-missing-sales`) — إن أخفق الترحيل لحظتَه، يُستدرك دون تكرار
- **ميزان مراجعة** يُفحص توازنه
- `journal_entries.reverses_id` و`reversed_at` — أثرٌ لا يُمحى

**الحالة:** ✅ ممتاز · **التصنيف:** KEEP بالكامل — هذا أثمن ما في النظام وأقلّه حاجةً للتعديل

---

## 6. حالة بقيّة الأنظمة — نظرة سريعة

| النظام | الحالة | ملاحظة |
|---|---|---|
| **العملاء** | ✅ سليم، وB2B جاهز | نقاط الولاء ميتة |
| **الموردون** | ✅ سليم | استيراد/تصدير + ذمم دائنة |
| **المخزون** | ✅ سليم | `StockTransfer` ميّت؛ الهالك والوصفات مفاهيم سلع |
| **المشتريات** | ✅ ممتاز | دورة كاملة بفصل مهامّ |
| **المصروفات** | ✅ سليم | أنواع قابلة للتعريف، مربوطة بحسابات الدفتر، مرفقات محميّة |
| **فواتير العملاء** | ✅ ممتاز | **القلب الصالح لشركة تقنية** |
| **التقارير** | ✅ ممتاز بنيةً | المحتوى بيعيّ |
| **الموظفون والرواتب** | ✅ سليم | ينقصه: إجازات، حضور، عقود |
| **المبيعات** | ⚠️ بلا طريق إنشاء | يقرأ ويصحّح ويطبع فقط |
| **لوحة التحكم** | ✅ سليم | مؤشّرات بيعيّة |

---

## 7. بقايا أبعاد — Retail · POS · SaaS · E-commerce

### 7.1 بقايا SaaS (نظام الباقات والمنصّة)

| ما هو | أين | الحالة |
|---|---|---|
| دور `super_admin` | `Roles.php` · `User::isSuperAdmin()` · `CheckRole` · `Tenancy` · `Demo` (7 مواضع) | حيّ بلا لوحة |
| `businesses.starts_at` / `ends_at` | جدول `businesses` | يُقرأ فعليًّا |
| `Tenancy::platform()` · `graceDays` · `locksAt` · `locked` · `graceLeft` · `expired` · `daysLeft` | `Tenancy.php` | آلة اشتراكٍ كاملة تعمل |
| `SUBSCRIPTION_EXPIRED` | `Tenancy.php` | ثابتٌ حيّ |
| `SubscriptionBanner.tsx` | مركّب في `AdminLayout` | يعرض «اشتراكك ينتهي بعد N يومًا» |
| `Pages/Auth/SubscriptionExpired.tsx` | — | **ميّت** — لا مسار ولا `render` |
| `SubscriptionNoticeMail` + قالبه | `app/Mail` + `views/emails` | **ميّت** — صفر مراجع |
| `Rule/PlatformEmailDomain` | `ProfileController` · `Preflight` | حيّ — يحرس نطاق بريد مدير المنصّة |
| `planFeatures` / `usePlanFeature` / `lib/plan.ts` | `Sidebar` · `ExportMenu` · `pages.ts` · `types` | **سباكة ميتة** — الخادم لا يرسلها، فكلّ شيء مفتوح |
| `NavItem.feature` | `lib/nav.ts` | حقلٌ لا يُملأ |
| `Demo::businesses()` + `businessCounts/Branches/Employees/Products/Orders/Overview/SalesSeries` | `Demo.php` (8 دوالّ) | لا تُستدعى إلا من دوالّ ميتة |
| `ExportController::businesses` · `ReportExportController::businessesXlsx` · `PdfController::businessesReport` | 3 متحكّمات | **بلا مسار** |
| `views/pdf/{businesses-report,platform-report,platform-invoice,invoices-report}.blade.php` | 4 قوالب | **ميتة** |
| `ActivityController::superIndex` → `Platform/Activity` | — | **يرسم صفحةً غير موجودة** |
| `Schedule: subscriptions:expire` · `subscriptions:notify` | `routes/console.php` | 🔴 **أمران غير موجودين** |
| `SEED_SUPER_EMAIL` + بذرة `super_admin` | `.env.example` · `DatabaseSeeder` | ينشئ حسابًا بلا لوحة |
| `is_demo` · `businesses.branches_count` | جدول `businesses` | بقايا لوحة المنصّة |

### 7.2 بقايا نقطة البيع (POS)

| ما هو | أين | الحالة |
|---|---|---|
| جدولا `shifts` و`shift_movements` | القاعدة + `TenantTables` + 5 هجرات | **لا مسار ولا شاشة ولا نموذج مستعمل** |
| `Models/Shift.php` | — | **ميّت** — صفر مراجع خارجية |
| `orders.shift_id` · `is_held` | جدول `orders` | لا يكتبهما شيء |
| `Demo::heldOrders()` · `posCategories()` · `receipts()` | `Demo.php` | ميّتة (`heldOrders` يستدعيها اختبارٌ فقط) |
| `OrderEditController::addon` | — | **بلا مسار** |
| `Support/CreditSales.php` | `ReceivablesController` | حيّ — لكن وصفه «البيع الآجل من نقطة البيع» |
| `Support/ReceiptTemplate.php` + `orders.receipt` (إيصال حراري 80مم) | `PdfController` | حيّ — ورق صندوق |
| `context.deviceName` · `peripherals` · `posCashier` | `types/index.d.ts` | **أنواع لا يرسلها الخادم** |
| `Roles: cashier` | `Roles.php` | دورٌ لا يفتح شيئًا |
| نصوص «نقطة البيع» في الواجهة | `ProductForm` · `Composition` · `Gallery` · `EmployeeForm` | **يراها المستخدم** |
| `2026_07_18_000010_create_eventpluspos_tables.php` | اسم الهجرة | «pos» في الاسم |

### 7.3 بقايا المتجر الإلكتروني والموقع

| ما هو | أين | الحالة |
|---|---|---|
| `businesses.site_slug` · `products.published` | القاعدة | لا يكتبهما شيء |
| `views/site/show.blade.php` · `site/maintenance.blade.php` · `store/show.blade.php` | 3 قوالب | **ميتة تمامًا** |
| `Settings/partials/DomainChooser.tsx` | — | **ميّت** — صفر مستوردين |
| `Permissions::WEBSITE_CONFIGURE` + مدخلاه في `ACTIONS` و`ACTION_ROLES` | `Permissions.php` | فعلٌ ميّت |
| `ALIASES['store']` · `ALIASES['devices']` | `Permissions.php` | مداخل ميتة |
| `context.website` | `types/index.d.ts` | نوعٌ لا يُرسل |
| نصّ «الموقع الإلكتروني» في **شاشة الدخول** | `Components/auth/AuthShowcase.tsx` | 🔴 **أوّل ما يراه أيّ زائر** |
| `Settings/Index.tsx:659` «الشعار هنا لا في الموقع الإلكتروني» | — | نصّ مرئي |

### 7.4 بقايا التسويق والولاء والتكاملات

| ما هو | أين | الحالة |
|---|---|---|
| `ReportPageController::marketing` + `Reports/Marketing.tsx` | — | **بلا مسار** — «الكوبونات والتسويق» |
| `customers.points` + عمود «نقاط الولاء» في الجدول وصفحة العميل والاستيراد والتصدير | 8 مواضع | يُعرض ولا يُكتب |
| `loyalty_earn_rate` | `SettingController` · `NormalizeNumbers` | مفتاحٌ بلا شاشة |
| `orders.coupon_code` · `coupon_discount` · `points_earned` · `redeemed_points` | جدول `orders` | لا يكتبها شيء |
| `config/whatsapp.php` | يُقرأ منه `default_country_code` فقط في `PhoneNumber` | ملفٌّ كامل لمفتاحٍ واحد |
| `config/ai.php` | — | **صفر قراءات** |
| `META_WHATSAPP_*` (5 مفاتيح) | `.env.example` | ميتة |
| استثناء CSRF لـ `webhooks/whatsapp` | `bootstrap/app.php` | مسارٌ غير موجود |
| `Schedule: whatsapp:sync-templates` | `routes/console.php` | 🔴 **أمر غير موجود** |
| `orders.review_request_sent_at` · `status_notice_at` · `status_notice_event` | جدول `orders` | لا يكتبها شيء |
| `Support/PhoneNumber.php` | حيّ ومستعمل | وصفه ما زال «كيف يُكتب الرقم لواتساب» |
| نصّ «ذكّره بواتسابك» | `CustomerInvoices/Show.tsx:144` | نصّ مرئي |
| `SHELL: admin.help.*` (5 مسارات) | `Permissions.php` | قسم الدعم محذوف |

### 7.5 بقايا التجزئة ومحلّ الورود

| ما هو | أين | ملاحظة |
|---|---|---|
| `BusinessTypes::TYPES` = `['محل ورود','مطعم','كافيه','بقالة','صيدلية','متجر ملابس']` + تصنيفات بذرة لكلٍّ | `BusinessTypes.php` (145 سطرًا) | يُستعمل في التسجيل وبذر التصنيفات |
| `Support/FlowerOrder.php` (422 سطرًا) + 10 مناسبات | `Demo` · `OrderController` · `OrderDetailController` | مستلِم · موعد · مناسبة · بطاقة إهداء |
| `Support/Recipe.php` — «ممّ تُصنع الباقة» | `Product` · `OrderCorrection` | وصفة التصنيع |
| `Support/AddonStock.php` · `ProductAddons.php` | — | إضافات المطاعم |
| `Support/Waste.php` + `WasteInsights.php` (511 سطرًا) | تحليلات الهالك | مفهوم سلعٍ قابلة للتلف |
| `Support/MerchantAccount.php` | `User` · `Signup` · `EmployeeController` | «حساب دخول التاجر» |
| `Support/ShopIdentity.php` · `Paper.php` | هويّة المتجر على الورق | تسمية فقط |
| `Support/StockLosses.php` · `StockLedger.php` | حركة المخزون | صحيحة المفهوم |
| مفردات الواجهة والتعليقات | `متجر` ×1703 · `تاجر` ×852 · `المتجر` ×779 | معظمها تعليقات عربية |
| `lang/en.json` | 5780 مفتاحًا مقابل **2507 مستعملة** | ~3273 مفتاحًا يتيمًا، 501 منها بمفردات POS/باقات/منصّة |

---

## 8. الكود الميّت — القائمة الكاملة المؤكَّدة

كلّ سطرٍ هنا فُحِص بعدّ المراجع، لا بالتخمين.

### 8.1 أعطال حقيقية (لا مجرّد كود ميت)

| # | العطب | الأثر |
|---|---|---|
| 🔴 1 | `routes/console.php` يجدول **3 أوامر غير موجودة**: `subscriptions:expire` (00:10) · `subscriptions:notify` (07:30) · `whatsapp:sync-templates` (كلّ ساعة) | فشلٌ يوميّ صامت في الإنتاج. مؤكَّد بتشغيل كلٍّ منها: `ERROR There are no commands defined in the "subscriptions" namespace` |
| 🔴 2 | `ActivityController::superIndex()` يرسم `Platform/Activity` — وهي صفحة **غير موجودة** | 500 لو رُبط بمسار يومًا. لا مسار له الآن |
| 🟡 3 | `DEPLOYMENT.md` يقول «4 مهام مجدولة» والواقع 10 (3 معطوبة) | توثيق يضلّل من ينشر |
| 🟡 4 | `README.md` و`CLAUDE.md` يصفان «منصّة SaaS لنقاط البيع» و`storefront/` (Next.js) و`config/storefront.php` — **كلّها محذوفة** | أوّل ما يقرأه أيّ منضمّ للمشروع خطأ بالكامل |
| 🟡 5 | `SEEDING.md` يتحدّث عن فرع `ابعاد-2.0` و«الباقات 3» و`DemoSeeder` — لا شيء منها موجود | — |
| 🟡 6 | `AuthShowcase.tsx` يعرض في **شاشة الدخول** 4 شرائح تُعلن: «الموقع الإلكتروني» · «نقطة بيع تعمل على أي جهاز» · «متجرك على الإنترنت بلا مطوّر» | يراها كلّ زائر، ولا شيء منها موجود |

### 8.2 ملفات ميتة تمامًا (صفر مراجع)

**نماذج (2)**
```
app/Models/Shift.php
app/Models/StockTransfer.php
```

**بريد (1)**
```
app/Mail/SubscriptionNoticeMail.php
```

**صفحات واجهة (3)**
```
resources/js/Pages/Auth/SubscriptionExpired.tsx        لا مسار ولا render
resources/js/Pages/Admin/Reports/Marketing.tsx         لا مسار
resources/js/Pages/Admin/Settings/partials/DomainChooser.tsx   صفر مستوردين
```

**مكوّنات ووحدات واجهة (3)**
```
resources/js/Components/Gate.tsx          خدم 4 شاشات محذوفة
resources/js/Components/EmojiPicker.tsx
resources/js/lib/tenancy.ts               كان للوحة المنصّة
```

**قوالب Blade (8)**
```
resources/views/site/show.blade.php
resources/views/site/maintenance.blade.php
resources/views/store/show.blade.php
resources/views/pdf/platform-report.blade.php
resources/views/pdf/platform-invoice.blade.php
resources/views/pdf/invoices-report.blade.php
resources/views/pdf/businesses-report.blade.php       (يستدعيه متحكّمٌ ميّت فقط)
resources/views/emails/subscription-notice.blade.php  (يستدعيه Mailable ميّت فقط)
```

**ملفات إعداد (1 كامل + 1 شبه)**
```
config/ai.php           صفر قراءات
config/whatsapp.php     مفتاح واحد يُقرأ: default_country_code
```

### 8.3 دوالّ ميتة (بلا مسار)

```
ActivityController::superIndex
Admin\ReportPageController::marketing
Admin\ReportExportController::businessesXlsx
Admin\OrderEditController::addon
ExportController::businesses
PdfController::businessesReport
```

### 8.4 دوالّ `Demo` بلا مستدعٍ (8)

```
businessCounts · businessBranches · businessEmployees · businessProducts
businessOrders · businessOverview · businessSalesSeries · posCategories · receipts
```
و`businesses()` لا يستدعيها إلا الدوالّ الميتة الثلاث أعلاه.
و`heldOrders()` لا يستدعيها إلا `HeldOrdersTest`.
و`employeeLeaderboard()` بلا مستدعٍ.

### 8.5 جداول وأعمدة لا يكتبها شيء

**جداول (3)**
```
shifts · shift_movements · stock_transfers
```

**أعمدة في `orders` (9)**
```
shift_id · is_held · coupon_code · coupon_discount · points_earned
redeemed_points · review_request_sent_at · status_notice_at · status_notice_event
```

**أعمدة أخرى**
```
customers.points          يُقرأ ويُصدَّر ويُستورد، ولا شيء يزيده
products.published        من المتجر الإلكتروني
businesses.site_slug      من الموقع
businesses.is_demo · branches_count   من لوحة المنصّة
```

### 8.6 مداخل ميتة في `Permissions.php`

```php
WEBSITE_CONFIGURE        الثابت + مدخله في ACTIONS + مدخله في ACTION_ROLES
SHELL: admin.help.index · admin.help.store · admin.help.show
       admin.help.reply · admin.help.attachment          (5 مسارات)
ALIASES: 'devices' => 'settings'    'store' => 'settings'
```

### 8.7 سباكة ميتة في الواجهة

```
types/index.d.ts:  auth.planFeatures · context.website · context.deviceName
                   context.peripherals · posCashier · auth.impersonating
lib/plan.ts        usePlanFeature — تُرجع true دائمًا
lib/nav.ts         NavItem.feature · NavItem.badge ('support' | 'crm')
                   + مجموعتان فارغتان { items: [] }
Sidebar.tsx:37     ترشيح بـ planFeatures لا يرشّح شيئًا
pages.ts:214       المثل
```

### 8.8 أحداث بلا مستمعين

```
app/Events/OrderStatusChanged.php   يُطلَق من OrderObserver ولا يسمعه أحد
                                    (المستمع SendWhatsAppOnOrderStatus محذوف)
app/Listeners/                      المجلّد فارغ
app/Jobs/                           المجلّد فارغ
app/Mail/NewOrderMail.php           + views/emails/new-order.blade.php
                                    لا يُرسَل إلا في اختبار — كان يرسله checkout
```

### 8.9 مفاتيح ترجمة يتيمة

```
en.json                5780 مفتاحًا
مستعمل في الكود        2507
يتيم                   ~3273  (501 منها بمفردات POS/باقات/منصّة/واتساب/ورديّات)
```

---

## 9. التصنيف النهائي

### ✅ KEEP — مناسب كما هو

| العنصر | لماذا |
|---|---|
| **دفتر الأستاذ** (`Ledger` · `Books` · شجرة الحسابات · القيود · ميزان المراجعة) | محاسبة مزدوجة كاملة، بابُ قيدٍ واحد، عكسٌ لا محو. لا يحتاج حرفًا |
| **فواتير العملاء** بدورتها كاملةً | `po_number` · `contract_number` · `cost_center` · بنود نصّية حرّة. **هذا قلب Event Plus** |
| **التحصيلات وسندات القبض والإشعارات الدائنة** | كما هي |
| **الذمم المدينة والدائنة والائتمان وشروط السداد** | كما هي |
| **الشيكات والحسابات البنكية والمطابقة** | كما هي |
| **الأصول الثابتة والإهلاك** | كما هي — شركة تقنية لها أجهزة وسيارات |
| **المشتريات كاملةً** (أوامر · استلام · سندات موردين · اعتماد · سداد) | فصلُ مهامٍّ ممتاز |
| **المصروفات وأنواعها وربطها بالحسابات** | كما هي |
| **نظام الصلاحيات ثلاثيّ الطبقات** (دور · قسم · فعل) | أفضل ما في النظام |
| **الموظفون والرواتب** | أساسٌ سليم يُبنى عليه |
| **بنية التقارير** (فهرس · فترة · تنزيل من بابٍ واحد · صلاحية القسم) | ممتازة |
| **الإعدادات** (قوالب · نسخ احتياطي · محذوفات · تنبيهات · سجلّ نشاط) | كما هي |
| **العملاء** بحقول B2B | جاهزة لعملاء الشركة |
| **الطبقات الأساسية** (`Demo` · `Tenancy` · `Permissions` · `TenantTables`) | البنية التحتية |
| **الأوراق والطباعة** (`DocumentTemplates` · `DocumentRenderer` · `DocumentPaper` · `EInvoice` · `Vat`) | نظام مستندات ناضج |
| **المصادقة والاستعادة** (رمز OTP · بريد استعادة · إعادة تعيين) | كما هي |
| **`business_id` والعزل** | قرارك، وهو صحيح |

### 🔄 ADAPT — مناسب لكن يحتاج تحويل المفهوم

| من | إلى | كيف |
|---|---|---|
| **المنتج** (`Product`) | **خدمة / باقة خدمات** | الحقول كما هي: `price` · `cost` · `sku` → رمز الخدمة. `quantity` يصير اختياريًّا |
| **الطلب** (`Order`) | **مشروع / فعالية** | `fulfillment_type` → نوع التنفيذ · `scheduled_for` → موعد الحدث · `occasion_type` → نوع الفعالية · `recipient_*` → جهة الاتصال بالموقع |
| **`FlowerOrder`** (422 سطرًا) | **`EventBooking`** | البنية صحيحة تمامًا: مستلِمٌ غير المشتري، موعدٌ مجدول، مناسبة، عنوان تسليم. **أعد التسمية ولا تحذف** |
| **`BusinessTypes`** (محل ورود، مطعم…) | أنواع مشاريع Event Plus | استبدل القائمة والتصنيفات البذرة |
| **`Demo`** | `DataAccess` / `Query` | الاسم مضلّل — 75 دالّة و103 استدعاء لـ`bid()` وحدها. إعادة تسميةٍ بحتة |
| **`MerchantAccount`** | `CompanyAccount` | تسمية فقط |
| **`ShopIdentity`** · **`Paper`** | `CompanyIdentity` | تسمية فقط |
| **`CreditSales`** | `CreditTerms` | وصفه «البيع الآجل من نقطة البيع» والمفهوم صالح |
| **`PhoneNumber`** | كما هو | احذف وصف «لواتساب» من الشرح فقط |
| **لوحة التحكم** | مؤشّرات مشاريع | استبدل `daily_sales` بـ«مشاريع جارية»، `low_stock_products` بـ«مهامّ متأخّرة»… البنية (`AlertMetrics::catalog`) تقبل ذلك |
| **التقارير الـ16** | تقارير شركة | الإطار ممتاز، المحتوى يُستبدل |
| **المخزون** | مخزون معدّات/أصول تشغيل | الجرد والحركات صحيحة؛ الوصفات والهالك لا |
| **`InventoryMovement`** · **`StockLedger`** | كما هي | مفاهيم عامّة سليمة |
| **دور `sales`** | «مسؤول حسابات عملاء» | تسمية |
| **دور `delivery`** | «منسّق ميداني» أو احذفه | REVIEW |
| **`orders` → «المبيعات»** | «المشاريع» أو «الفعاليات» | تسمية القسم |
| **`en.json`** | تنظيف | احذف ~3273 مفتاحًا يتيمًا ثمّ أعد الترجمة بمفردات الشركة |
| **الوثائق الثلاث** | إعادة كتابة كاملة | `README` · `CLAUDE.md` · `SEEDING.md` تصف نظامًا لم يعد موجودًا |
| **`AuthShowcase`** | شرائح Event Plus | 4 شرائح تُعلن ميزات محذوفة في شاشة الدخول |

### ❌ REMOVE — لا حاجة له في Event Plus

**أعطال أوّلًا**
```
1. Schedule: subscriptions:expire · subscriptions:notify · whatsapp:sync-templates
2. ActivityController::superIndex  (يرسم صفحة غير موجودة)
```

**ملفات (18)**
```
app/Models/Shift.php
app/Models/StockTransfer.php
app/Mail/SubscriptionNoticeMail.php
config/ai.php
resources/js/Pages/Auth/SubscriptionExpired.tsx
resources/js/Pages/Admin/Reports/Marketing.tsx
resources/js/Pages/Admin/Settings/partials/DomainChooser.tsx
resources/js/Components/Gate.tsx
resources/js/Components/EmojiPicker.tsx
resources/js/lib/tenancy.ts
resources/js/lib/plan.ts
resources/views/site/show.blade.php
resources/views/site/maintenance.blade.php
resources/views/store/show.blade.php
resources/views/pdf/platform-report.blade.php
resources/views/pdf/platform-invoice.blade.php
resources/views/pdf/invoices-report.blade.php
resources/views/pdf/businesses-report.blade.php
resources/views/emails/subscription-notice.blade.php
```

**دوالّ (6 متحكّمات + 10 في `Demo`)**
```
ActivityController::superIndex · ReportPageController::marketing
ReportExportController::businessesXlsx · OrderEditController::addon
ExportController::businesses · PdfController::businessesReport

Demo:: businesses · businessCounts · businessBranches · businessEmployees
       businessProducts · businessOrders · businessOverview
       businessSalesSeries · posCategories · receipts · heldOrders
       employeeLeaderboard
```

**صلاحيات وأدوار**
```
Permissions::WEBSITE_CONFIGURE + مدخلاه
Permissions::SHELL → admin.help.*  (5 مسارات)
Permissions::ALIASES → 'devices' · 'store'
Roles::LABELS → 'super_admin' · 'cashier'   (بعد قرارك — انظر REVIEW)
```

**سباكة الواجهة**
```
types: planFeatures · website · deviceName · peripherals · posCashier · impersonating
nav.ts: NavItem.feature · NavItem.badge · المجموعتان الفارغتان
Sidebar.tsx / pages.ts: ترشيح planFeatures
```

**أحداث وبريد**
```
app/Events/OrderStatusChanged.php + app/Observers/OrderObserver (سطر الإطلاق)
app/Mail/NewOrderMail.php + views/emails/new-order.blade.php
app/Listeners/  و  app/Jobs/  (فارغان)
```

**إعدادات ومتغيّرات بيئة**
```
bootstrap/app.php → استثناء CSRF لـ webhooks/whatsapp
.env.example → META_WHATSAPP_* (5 مفاتيح) · SEED_SUPER_EMAIL · SEED_SUPER_PASSWORD
DatabaseSeeder → بذرة حساب super_admin
```

**نقاط الولاء**
```
customers.points + عمود الجدول + حقل صفحة العميل + الاستيراد + التصدير
SettingController → loyalty_earn_rate
NormalizeNumbers → loyalty_earn_rate
```

**~3273 مفتاح ترجمة يتيم في `en.json`**

### 🔍 REVIEW — يحتاج قرارك قبل أيّ تعديل

| # | البند | السؤال | ملاحظتي |
|---|---|---|---|
| 1 | **طريق إنشاء البيع** | هل تكتفي بفواتير العملاء، أم تريد شاشة «مشروع جديد» تُنشئ `Order`؟ | فواتير العملاء أقرب لعمل شركة تقنية. لكنّ `Order` يحمل بنود التنفيذ والمواعيد — قد تريده لإدارة المشاريع |
| 2 | **جدولا `shifts` و`shift_movements`** | حذفٌ نهائي بهجرة، أم إبقاء؟ | ميتان تمامًا. الحذف آمن، لكنّه هجرةٌ لا رجعة فيها |
| 3 | **`stock_transfers`** | المثل | التحويل بين الفروع قد يعود إن صار لكم فروع |
| 4 | **آلة الاشتراك** (`ends_at` · `Tenancy::locked` · `SubscriptionBanner`) | هل Event Plus نظامٌ داخليّ للشركة فقط؟ | إن نعم: احذفها كلّها. إن كان بيعًا لعملاء: أبقِها |
| 5 | **دور `super_admin`** | يحرسه `PlatformEmailDomain` وله بذرة، ولا لوحة له | إن لم تعد هناك منصّة، احذفه |
| 6 | **دور `cashier`** | حيٌّ في القائمة ولا يفتح شاشة واحدة | متناقض الآن — احذفه أو أعطه لوحة |
| 7 | **دور `delivery`** | «مندوب توصيل» — هل يصلح لشركة فعاليات؟ | ربّما كـ«منسّق ميداني» |
| 8 | **الوصفة والإضافات** (`Recipe` · `Addon` · `AddonStock` · `ProductAddons`) | هل تحتاج تركيبًا للخدمات؟ | «باقة خدمة تحتوي بنودًا» = الوصفة نفسها بمفهومٍ آخر. **لا تحذف قبل التفكير** |
| 9 | **الهالك** (`Waste` + `WasteInsights` = 511 سطرًا) | لا معنى له بلا سلعٍ قابلة للتلف | أرشحه للحذف، إلا إن كان لكم مخزون مواد |
| 10 | **المخزون كاملًا** | هل تحتاجه شركة تقنية؟ | نعم للأجهزة والمعدّات والتراخيص — لكن ليس بشكله الحالي |
| 11 | **الإيصال الحراري** (`ReceiptTemplate` + `orders.receipt`) | ورق صندوق 80مم | لا معنى له بلا كاشير — إلا إن أردتم إيصالات ميدانية |
| 12 | **`FlowerOrder`** | حذف أم تحويل؟ | **أرشّح التحويل بقوّة** — بنيتها هي بنية حدث |
| 13 | **`is_demo` و`DemoStore`** | بيانات عرض | `DemoStore` يبني بيانات الاختبار — لا تحذفه. `is_demo` عمودٌ قد يبقى نافعًا للعروض |
| 14 | **`config/whatsapp.php`** | يُقرأ منه مفتاح واحد | انقل `default_country_code` إلى `config/app.php` واحذف الملف |

---

## 10. ما أوصي به — بالترتيب

**قبل أيّ شيء (أعطال):**
1. أصلح المهامّ المجدولة الثلاث — احذف الأسطر من `routes/console.php`
2. احذف `ActivityController::superIndex`
3. أعد كتابة `README.md` و`CLAUDE.md` و`SEEDING.md` و`DEPLOYMENT.md`
4. استبدل شرائح `AuthShowcase` — هي أوّل ما يُرى

**ثمّ الكنس (بلا مخاطرة، كلّه مؤكَّد بعدّ المراجع):**
5. احذف الملفّات الـ18 والدوالّ الـ16 والمداخل الميتة في `Permissions`
6. انزع سباكة `planFeatures` من الواجهة
7. نظّف `en.json`

**ثمّ القرارات (بعد إجاباتك على جدول REVIEW):**
8. الأدوار · الاشتراك · الجداول الميتة · الوصفة · الهالك · المخزون

**ثمّ التحويل (المرحلة الحقيقية):**
9. المنتج → خدمة · الطلب → مشروع · `FlowerOrder` → `EventBooking`
10. مؤشّرات اللوحة والتقارير
11. `Demo` → اسم يصفها

**ما لا تلمسه:** دفتر الأستاذ · فواتير العملاء · المشتريات · الصلاحيات · نظام الأوراق.

---

## 11. حالة الصحّة اليوم

| الفحص | النتيجة |
|---|---|
| اختبارات PHP | **2069 / 2069** — 0 إخفاق · 0 خطأ · 7823 تأكيدًا |
| اختبارات الواجهة | 11 ملفًا |
| `npx tsc --noEmit` | صفر أخطاء |
| مسارات معلّقة | صفر |
| شاشات بلا مسار | 2 (موثّقتان أعلاه) |
| دوالّ بلا مسار | 6 (موثّقة أعلاه) |
| أثر كلمة «أبعاد» | **صفر** |
| مهامّ مجدولة معطوبة | **3** |

**الخلاصة:** الأساس متين، والمحاسبة والفوترة أقوى من المتوقّع، وما بقي من أبعاد سطحيّ وقابل للكنس في جلسة عمل واحدة. القرار الحقيقي الوحيد المعلّق هو **ما الذي يحلّ محلّ نقطة البيع كطريقٍ لبدء العمل** — وأرجّح أنّه «فاتورة العميل»، وهي موجودةٌ وناضجة.
