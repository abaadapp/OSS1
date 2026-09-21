# كتابُ تشغيل الإنتاج — Event Plus OSS V1

> للمهندس الذي ينشر ويراقب. لا سرَّ هنا؛ الأسرارُ في `.env` على الخادم وحده.
> البنيةُ المعروفة: خادمٌ واحد (Ubuntu، nginx + php8.4-fpm)، PostgreSQL 16، مستودعٌ في
> `/var/www/eventplus`، مستخدمُ التطبيق `www-data`، سكربتُ النشر `scripts/deploy.sh`.

## 0. ما يجب أن يكون قائمًا قبل أوّل نشر

| المكوّن | المطلوب | يفحصه |
|---|---|---|
| PHP 8.4 + امتدادات `pdo_pgsql mbstring intl bcmath gd zip` | | `php -m` |
| PostgreSQL 16، قاعدةٌ ومستخدمٌ بصلاحيّات DDL | | `psql` |
| Node 22 + npm | البناءُ على الخادم (أو رفعُ `public/build`) | |
| `.env` من `.env.production.example` | `APP_ENV=production APP_DEBUG=false APP_URL=https://… DB_CONNECTION=pgsql MAIL_MAILER=postmark QUEUE_CONNECTION=database CACHE_STORE=database SESSION_DRIVER=database SESSION_SECURE_COOKIE=true APP_TIMEZONE=Asia/Muscat` | `eventplus:production-check` |
| `php artisan key:generate` مرّةً واحدة | | |
| `php artisan storage:link` | | `production-check` |
| cron المجدول | `* * * * * cd /var/www/eventplus && php artisan schedule:run >> /dev/null 2>&1` | `production-check` |
| عاملُ الطابور (systemd) | `php artisan queue:work database --sleep=3 --tries=3 --max-time=3600` | `production-check` |
| نسخةٌ احتياطيّة (§6) | | `production-check` (آخر 48 ساعة) |

## 1. قبل النشر

1. على جهازك: `php artisan test` (SQLite) و`vendor/bin/phpunit -c phpunit.pgsql.xml` (PostgreSQL) خضراء، `npx tsc --noEmit`، `npm run build`، `composer audit`، `npm audit`.
2. الدفعُ إلى `origin/main` بقرارٍ صريح — CI تشغّل المجموعتين.
3. اقرأ `docs/GO_LIVE_CHECKLIST.md` وعلّم البنود.
4. أعلن نافذةَ النشر (النشرُ لا يوقف الموقع إلّا لحظاتٍ عند تبديل الأصول).

## 2. النسخة الاحتياطيّة قبل النشر (يفعلها السكربت)

```bash
sudo -u postgres pg_dump -Fc eventplus > /root/pre-deploy/eventplus-$(date +%Y%m%d-%H%M%S).dump
tar czf /root/pre-deploy/storage-$(date +%Y%m%d-%H%M%S).tgz -C /var/www/eventplus storage/app
cp /var/www/eventplus/.env /root/pre-deploy/env-$(date +%Y%m%d-%H%M%S)   # chmod 600
```

النسخةُ لا تُعدّ صالحةً حتى تُستعاد مرّةً على قاعدةٍ جانبيّة (§6).

## 3. النشر

```bash
sudo bash /var/www/eventplus/scripts/deploy.sh
```

ما يفعله بالترتيب — ولماذا هذا الترتيب:

1. **نسخةُ القاعدة** (الرجوعُ الوحيد المضمون).
2. `git pull --ff-only`.
3. `composer install --no-dev --optimize-autoloader`.
4. **بناءُ الواجهة جانبًا** في `public/build.new` ثمّ تبديلٌ ذرّيّ — لا لحظةَ بلا manifest (كانت سببَ 500 قديمًا).
5. **الهجرات** `php artisan migrate --force` — بعد البناء عمدًا: فشلُ البناء يترك القاعدةَ كما هي.
6. `config:cache` · `route:cache` · `view:cache` · `systemctl reload php8.4-fpm`.
7. `eventplus:preflight` (= `production-check`) ثمّ `curl /health`.
8. **أعد تشغيل العامل** ليقرأ الكودَ الجديد: `php artisan queue:restart` (السكربتُ لا يفعلها — أضفها يدويًّا حتى تُدمج).

بعده مباشرةً:

```bash
php artisan eventplus:production-check
php artisan eventplus:smoke --url=https://app.eventplusapp.om
php artisan test --group smoke          # على SQLite في الذاكرة — لا يلمس قاعدة الإنتاج
php artisan queue:restart
```

## 4. الهجرات — قواعدُ السلامة

- كلُّ هجرةٍ في V1 إضافيّة (أعمدةٌ فارغة، فهارس، جداول جديدة) — لا إعادةَ كتابةٍ لجداولَ كبيرة.
- إضافةُ عمودٍ غير فارغٍ تكون بقيمةٍ افتراضيّة أو على خطوتين (فارغ ← تعبئة ← قيد).
- الفهارسُ على جداولَ تتجاوز مئات الآلاف تُضاف بـ`CREATE INDEX CONCURRENTLY` خارج المعاملة (`$this->withinTransaction = false` أو SQL خامّ).
- لا `migrate:rollback` على الإنتاج إلّا لهجرةٍ عُرفت رجعتُها؛ الرجوعُ العامّ من النسخة (§5).
- `php artisan migrate --pretend` قبل النشر يطبع SQL المتوقَّع.

## 5. الرجوع

**متى:** `production-check` يبلّغ مانعًا جديدًا، أو `/health` ≠ 200، أو الدخّانُ يفشل، أو خطأ 500 على شاشةٍ جوهريّة خلال 30 دقيقةً من النشر.

**كود فقط (لا هجرةَ جديدة في هذه الدفعة):**
```bash
cd /var/www/eventplus
sudo -u www-data git reset --hard <FROM_COMMIT>     # من /root/pre-deploy/ROLLBACK.txt
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data npm ci && sudo -u www-data npm run build
sudo -u www-data php artisan config:cache route:cache view:cache
systemctl reload php8.4-fpm && sudo -u www-data php artisan queue:restart
```

**كودٌ وقاعدة (كانت هجرة):**
```bash
sudo -u www-data php artisan down
sudo -u postgres pg_restore -c -d eventplus /root/pre-deploy/eventplus-<STAMP>.dump
# ثمّ الرجوعُ بالكود كما فوق
sudo -u www-data php artisan up
```
ما دخل القاعدةَ بين النشر والرجوع يضيع — لذلك النافذةُ قصيرة والقرارُ سريع.

## 6. النسخُ الاحتياطي والاستعادة

- **آليًّا:** `backup:run` 02:00 يوميًّا (JSON لكلّ شركة في `storage/app/private/backups/`، يُحتفظ 14 يومًا) — نسخةُ **تطبيق** تُستعاد من الإعدادات.
- **قاعدةُ البيانات:** `pg_dump -Fc` ليليًّا عبر cron الخادم (خارج التطبيق) إلى مجلّدٍ خارج `/var/www`، ونسخةٌ خارج الخادم (S3/Spaces) أسبوعيًّا على الأقلّ.
- **الملفّات:** `storage/app` (مرفقاتُ سنداتِ الموردين، ملفّاتُ الاستيراد، حزمُ التسليم، الشعارات) مع القاعدة نفسها.
- **`.env`:** نسخةٌ مشفَّرة خارج الخادم؛ `APP_KEY` جزءٌ من الاستعادة.
- **التحقّق (شهريًّا):** `createdb eventplus_restore && pg_restore -d eventplus_restore <dump>` ثمّ `psql -c "select count(*) from customer_invoices"` ومقارنةُ الرقم مع الإنتاج. نسخةٌ لم تُستعد مرّةً ليست نسخة.

## 7. الطابور

- اتّصالٌ واحد `database`، طابورٌ واحد `default` (لا أسماءَ طوابير في الكود — فُحص: `ShouldQueue` على `PasswordResetMail` وحدها).
- عاملٌ واحد يكفي: `queue:work database --sleep=3 --tries=3 --max-time=3600 --timeout=120` تحت systemd/supervisor بإعادة تشغيلٍ تلقائيّ.
- بعد كلّ نشر: `queue:restart`.
- الفاشل: `queue:failed` ثمّ `queue:retry <id>` بعد فهم السبب؛ `failed_jobs` تُراجَع أسبوعيًّا (`production-check` يحذّر).
- إيقافٌ آمن عند حادثة: `systemctl stop eventplus-queue` (المهامّ تبقى في الجدول ولا تضيع).

## 8. المجدول

`schedule:list` يطبع الثمانية:

| الأمر | الموعد | أثرٌ خارجيّ؟ |
|---|---|---|
| `automation:run` | كلّ 15 دقيقة، `withoutOverlapping(30)` | نعم — **فقط** إذا كانت الشركةُ في `live` وقاعدةٌ خارجيّة حيّة؛ وإلّا داخليٌّ أو لا شيء |
| `finance:post-missing-sales` | 01:30 | لا |
| `backup:run` | 02:00 | لا |
| `trash:purge` | 02:30 | لا (محوٌ نهائيّ لما انقضت مهلتُه) |
| `alerts:low-stock` · `alerts:smart` | 08:00 · 08:30 | بريدٌ **إلى بريد الشركة نفسِها** |
| `report:daily-summary` · `reports:email` | 23:55 · أوّل الشهر 07:00 | بريدٌ إلى الشركة |

تفعيلُ cron مع الأتمتة `off` لا يُرسل شيئًا لعميل. المنطقةُ الزمنيّة `Asia/Muscat`. خادمٌ واحد فلا حاجةَ لـ`onOneServer`؛ إن تعدّدت الخوادم أضِفها مع مخزن ذاكرةٍ مشترك.

## 9. المراقبة

- `/health` (عامّ، بلا أسرار) من مراقبٍ خارجيّ كلَّ دقيقة.
- `storage/logs/laravel-YYYY-MM-DD.log` بمستوى `warning`؛ راقب `ERROR` و`CRITICAL`.
- `php artisan eventplus:production-check` بعد كلّ نشر وأسبوعيًّا.
- شاشةُ «الأتمتة ‹ السجلّ» للمهامّ الفاشلة والمتخطّاة؛ مركزُ العمليّات يعرض «الأتمتة تحتاج يدًا».
- حتى تُختار مراقبةٌ خارجيّة: هذا شرطٌ مذكورٌ في `GO_LIVE_CHECKLIST.md`.

## 10. الحوادث — الأوامر الفوريّة

| الحادثة | الفعل الفوريّ |
|---|---|
| نشرٌ فاسد | §5 |
| هجرةٌ سيّئة | `php artisan down` ← استعادةُ النسخة ← رجوعُ الكود |
| استيرادٌ فاسد | من شاشة الاستيراد: «إرجاع» للدفعة (Phase 9 `Rollback`) ثمّ `المطابقة`؛ راجع `docs/DATA_MIGRATION_RUNBOOK.md` |
| طابورٌ يكرّر أو يفيض | `systemctl stop eventplus-queue` ← `queue:failed` ← أصلح ← `queue:restart` |
| الأتمتة تُرسل ما لا يجب | شاشةُ الأتمتة: **مفتاحُ الإيقاف** (أو `Settings::pause`) — يوقف كلَّ شيءٍ فورًا ولا يمحو شيئًا؛ أو `automation_mode=off` في الإعدادات |
| رسائلُ خاطئة | أوقف القناة: `MAIL_MAILER=log` أو أفرغ `WHATSAPP_ACCESS_TOKEN` ثمّ `config:cache` — `Notifier::configured()` يرفض الإرسال |
| تسرّبُ سرّ | دوّر: `APP_KEY` (يُبطل الجلسات والمشفَّر)، كلمةَ مرور القاعدة، `POSTMARK_*`، `WHATSAPP_*`؛ ثمّ `config:cache` و`queue:restart`؛ سجّل الحادثة |
