# قائمةُ الانطلاق — Event Plus OSS V1

> تُنفَّذ بندًا بندًا بيد مهندسٍ واحد وتُوقَّع في آخرها. لا يُتخطّى بندٌ إلّا بقرارٍ مكتوب.

## الكود
- [ ] `origin/main` على الالتزام المراد نشره، وCI أخضر (SQLite + PostgreSQL + الواجهة).
- [ ] محليًّا: `php artisan test` · `vendor/bin/phpunit -c phpunit.pgsql.xml` · `npx tsc --noEmit` · `npm run build` · `composer audit` · `npm audit` — كلُّها نظيفة.
- [ ] `docs/V1_AUDIT_FINDINGS.md`: لا P0/P1 مفتوح.

## النسخة الاحتياطيّة
- [ ] `pg_dump -Fc` للقاعدة الحاليّة + `storage/app` + `.env` (مشفَّر) خارج `/var/www`.
- [ ] استُعيدت النسخةُ على قاعدةٍ جانبيّة وقُورن عددُ الفواتير — النسخةُ **صالحة**.
- [ ] `ROLLBACK.txt` يحمل commit الرجوع ومسارَ النسخة.

## البيئة
- [ ] `.env` من `.env.production.example`: `APP_ENV=production` · `APP_DEBUG=false` · `APP_URL=https://…` · `APP_TIMEZONE=Asia/Muscat` · `SESSION_SECURE_COOKIE=true`.
- [ ] `APP_KEY` مولَّد ومحفوظ في النسخة.
- [ ] `LOG_LEVEL=warning`، `LOG_STACK=daily`.
- [ ] `.env` بـ`chmod 640` لمستخدم التطبيق.

## قاعدةُ البيانات
- [ ] `DB_CONNECTION=pgsql` وPostgreSQL 16 يستجيب.
- [ ] `php artisan migrate --pretend` قُرئ؛ ثمّ `migrate --force` نجح (130 هجرة).
- [ ] `php artisan eventplus:production-check` ← «لا هجراتٍ معلَّقة» ✓.

## التخزين
- [ ] `storage/` و`bootstrap/cache/` قابلان للكتابة لمستخدم التطبيق.
- [ ] `php artisan storage:link` ← `public/storage` موجود.
- [ ] `public/build/manifest.json` موجود بعد البناء.

## الطابور
- [ ] `QUEUE_CONNECTION=database` وجدولُ `jobs` موجود.
- [ ] عاملٌ دائم تحت systemd/supervisor (`queue:work database --sleep=3 --tries=3 --max-time=3600`) — `production-check` يراه.
- [ ] `queue:restart` بعد النشر.

## المجدول
- [ ] سطرُ cron (`schedule:run` كلَّ دقيقة) موجود؛ `schedule:list` يطبع الثمانية.
- [ ] `automation_mode` لكلّ شركة = `off` (الافتراضي) — `production-check` ✓ «لا شركةَ في الوضع الحيّ».

## المراسلات
- [ ] `MAIL_MAILER=postmark` و`POSTMARK_API_KEY` مضبوط — `production-check` ✓ «البريد يصل فعلًا».
- [ ] `POSTMARK_WEBHOOK_SECRET` مضبوط وعنوانُ الويبهوك في Postmark: `https://postmark:<SECRET>@<domain>/webhooks/postmark`.
- [ ] واتساب **فارغ** حتى يُنفَّذ `docs/COMMUNICATIONS_GO_LIVE.md`.
- [ ] رسالةُ اختبارٍ داخليّة (استعادةُ كلمة مرور لحسابٍ داخليّ) وصلت.

## Webhooks
- [ ] `curl -X POST https://<domain>/webhooks/postmark` ← 403/404 (مقفل بلا سرّ).
- [ ] `curl -X POST https://<domain>/webhooks/whatsapp` ← 404 (لا سرَّ مضبوط = بابٌ مقفل).

## الأتمتة
- [ ] كلُّ الشركات `off`؛ القواعدُ الخارجيّة موقوفة (`production-check` ✓).
- [ ] `docs/AUTOMATION_GO_LIVE.md` مقروء؛ التفعيلُ **بعد** الانطلاق بمراحل.

## الاستيراد
- [ ] لا بياناتٍ حقيقيّة قبل `docs/DATA_MIGRATION_RUNBOOK.md`.
- [ ] `import.financial` و`import.execute` و`import.rollback` للمالك وحده.

## الأمن
- [ ] `docs/SECURITY_CHECKLIST.md` مُراجَع بندًا بندًا.
- [ ] لا حسابَ مالكٍ بكلمة مرورٍ افتراضيّة؛ لا شركةَ تجريبيّة (`production-check`).
- [ ] `/demo-login` غيرُ مسجَّل (`production-check`).

## الدخّان
- [ ] `php artisan eventplus:production-check` ← «جاهز للإطلاق» (أو تنبيهاتٌ مقروءةٌ ومقبولة).
- [ ] `php artisan eventplus:smoke --url=https://<domain>` ← «الدخّان نظيف».
- [ ] `php artisan test --group smoke` على الخادم (SQLite في الذاكرة) ← أخضر.
- [ ] يدويًّا: دخولُ المالك، فتحُ لوحة التحكّم ومركز العمليّات والإدارة، إنشاءُ عرضٍ تجريبيّ ثمّ حذفُه/إلغاؤه.

## الرجوع
- [ ] الأمرُ جاهزٌ في `docs/PRODUCTION_RUNBOOK.md §5` ومَن ينفّذه معروف.
- [ ] نافذةُ القرار: 30 دقيقةً من النشر.

## الاعتماد النهائيّ
- [ ] المهندس: ______ التاريخ: ______
- [ ] صاحبُ النظام (Event Plus): ______ التاريخ: ______
- [ ] الشروطُ المفتوحة (من `docs/V1_DEFERRED.md`): مراقبةٌ خارجيّة ______ · تشخيصُ 500 القديم ______
