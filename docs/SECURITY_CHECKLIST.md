# قائمةُ الأمن — Event Plus OSS V1

> تُقرأ قبل كلّ إطلاقٍ وبعد كلّ تغييرٍ في الصلاحيّات أو الأبواب العامّة. كلُّ بندٍ
> يشير إلى حارسه في الكود أو الاختبارات.

## الأسرار
- [ ] لا `.env` في git؛ `.env` على الخادم `chmod 640` لمستخدم التطبيق (يفحصه `eventplus:production-check`).
- [ ] لا مفاتيحَ في الشجرة ولا في التاريخ — فُحص في المرحلة 12 (`git log -p` على أنماط المفاتيح: صفر).
- [ ] `APP_KEY` مولَّدٌ على الخادم ومحفوظٌ في النسخة الاحتياطيّة المشفَّرة؛ فقدُه = فقدُ ما شُفّر به.
- [ ] الأسرارُ لا تُطبع: `production-check` يقول «مضبوط/ناقص» فقط؛ `TheProviderTellsUsWhatBecameOfTheMessageTest::test_a_provider_payload_is_stored_without_anything_that_looks_like_a_secret`.

## المصادقة والجلسة
- [ ] `APP_DEBUG=false`، `APP_URL` بـhttps، `SESSION_SECURE_COOKIE=true`، `SESSION_SAME_SITE=lax`.
- [ ] الدخولُ التجريبي (`/demo-login`) لا يُسجَّل خارج `APP_ENV=local` — مقفلٌ في `config/app.php` بلا مفتاحٍ يفتحه.
- [ ] الخنقُ على الدخول والاستعادة والقبول العامّ (`RateLimiter` في `PublicDocumentController`/`PublicDeliveryController`).
- [ ] لا حسابَ مالكٍ بكلمة مرورٍ افتراضيّة (`production-check`).

## الصلاحيّات
- [ ] `Permissions::MAP` مثبَّتٌ بنصّه (`EveryRoleIsProbedOnEveryRouteTest`) — توسيعُه قرارٌ يُكتب.
- [ ] الفعلُ يُمنح بالاسم والقسمُ يفتح الشاشة؛ الأدوارُ لا تُنشأ (`PANEL_ROLES`).
- [ ] من يوظّف دورًا يملك ما يملكه (`StaffCannotOutrankThemselvesTest`).
- [ ] المحاسبُ في «الإدارة» يقرأ ولا يعتمد ولا يضبط (`ExportsAndDoorsHoldTheLineTest`).

## العزل بين الشركات
- [ ] `business_id` في كلّ جدولٍ للمستأجر ومصنَّفٌ في `TenantTables` (`ARestoreReturnsTheShopTest`).
- [ ] كلُّ مسارٍ ذي معاملٍ يردّ معرّفَ شركةٍ أخرى بـ404 ولا يغيّر صفًّا (`NoDoorOpensOnAnotherCompanyTest` — 274 مسارًا).
- [ ] البحثُ والقوائم لا تُظهر الجار (`test_even_a_fully_empowered_user_of_b_cannot_read_a_through_lookups_and_search`).

## IDOR والإدخال
- [ ] لا `$request->all()` يُكتب؛ لا `business_id`/حالة/مبلغ من الطلب؛ المبالغُ تُحسب في الخادم (`LineTotals`, `CustomerInvoices::compute`, `Quotations::price`).
- [ ] المرفقاتُ والتنزيلاتُ والتصديرُ خلف الصلاحيّة والشركة (المسارات فُحصت أعلاه).
- [ ] المعرّفاتُ النسبيّة (`Rule::exists(...)->where('business_id')`) في كلّ تحقّق.

## الرموزُ العامّة
- [ ] `Str::random(22)` (~131 بت)، تُنشأ عند الإرسال، لا لمسودّةٍ ولا لملغًى (`PublicDocument::allows`).
- [ ] القرارُ من الرابط يُعاد فحصُه ببصمة المحتوى (`Fingerprint`) ويُقبل مرّةً (`CriticalWritesHappenOnceUnderContentionTest`).

## CSRF و XSS
- [ ] CSRF على كلّ كتابةٍ إلّا `webhooks/*` (`bootstrap/app.php`)؛ الويبهوك بسرٍّ/توقيع.
- [ ] React يهرّب النصوص؛ لا `dangerouslySetInnerHTML` على نصّ المستخدم (فُحص: لا استعمالَ خارج القوالب الثابتة).
- [ ] القوالبُ الورقيّة (`PDF`/`Blade`) تمرّ بـ`{{ }}` — النصُّ الحرّ يُهرَّب.

## Webhooks
- [ ] Postmark: سرٌّ في عنوان الـwebhook، مقارنةٌ ثابتةُ الزمن، 404 بلا سرٍّ مضبوط.
- [ ] Meta: `X-Hub-Signature-256` HMAC على الجسد الخامّ؛ رمزُ تحقّقٍ للاشتراك.
- [ ] الخبرُ يُكتب مرّةً (`communication_events.event_id` فريد) ولا تتراجع الحالة.
- [ ] الخنق `throttle:240,1`.

## الملفّات
- [ ] رفعُ المرفقات بامتداداتٍ مسمّاة (`extensions:jpg,jpeg,png,pdf,webp,heic`) وحجمٍ أقصى، على القرص الخاصّ `local` لا `public`.
- [ ] الاستيرادُ يفحص الملفَّ (`Import\FileGuard`) ويرفض ما ليس CSV/XLSX.
- [ ] التنزيلُ يمرّ بمتحكّمٍ مصرَّحٍ لا برابطٍ مباشر.

## التصدير
- [ ] لا خليّةَ معادلةٍ في CSV/XLSX (`SafeCellBinder`, `Sheet::guard` — `ExportsAndDoorsHoldTheLineTest`).
- [ ] التصديرُ خلف صلاحيّة `reports` والشركة.

## السجلّات
- [ ] لا كلمةَ مرور ولا رمزًا ولا حمولةً كاملة في السجلّ؛ الاستثناءاتُ بـ`report()` فقط.
- [ ] `LOG_LEVEL=warning` في الإنتاج؛ `LOG_STACK=daily` بمدّة احتفاظٍ محدودة.

## الاعتماديّات
- [ ] `composer audit` صفر؛ `npm audit` صفر (المرحلة 12).
- [ ] تُعاد شهريًّا؛ الترقياتُ الكبرى في V1.1.

## الحدُّ الأعلى للضرر
- [ ] الأتمتةُ مطفأةٌ افتراضًا، سقفٌ للخارجيّ في الدورة، فتيلٌ للقاعدة الفاشلة، مفتاحُ إيقافٍ عامّ.
- [ ] لا إرسالَ خارجيًّا بلا مزوّدٍ مضبوط — `Notifier::configured()`.
