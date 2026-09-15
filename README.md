# Event Plus OSS — افنت بلس

منصّة SaaS لنقاط البيع متعدّدة المستأجرين: لوحة للتاجر، لوحة لمدير المنصّة، وعارض مواقع للمتاجر.

## المتطلّبات

PHP 8.4 · Composer 2 · Node 22+ · SQLite (محليًّا)

## التشغيل المحلّي

```bash
composer setup                 # تثبيت + مفتاح + هجرات + بناء
php artisan migrate --seed     # بيانات أوّليّة
composer dev                   # الخادم والطابور والسجلّات وVite معًا
```

ثمّ افتح <http://127.0.0.1:8000>. الصفحة الرئيسيّة هي صفحة الدخول.

الحسابات الأوّليّة تُضبط في `.env` (`SEED_SUPER_EMAIL` و`SEED_ADMIN_EMAIL` وكلماتهما) — غيّرها قبل أيّ نشر.

## البنية

| المسار | ما فيه |
|---|---|
| `app/` | الكود الخلفيّ — نماذج ومتحكّمات ودعم |
| `resources/js/` | واجهة Inertia + React |
| `storefront/` | عارض مواقع التجّار (Next.js، حزم منفصلة) |
| `database/migrations/` | الهجرات، مسمّاة بجُمَلٍ تصف السلوك |
| `tests/` | اختبارات PHPUnit وvitest |
| `design-system/` | رموز التصميم |

## النطاق

عناوين المتاجر تُبنى على `STOREFRONT_DOMAIN` في `.env` (`متجري.example.com`). القيمة الافتراضيّة في `config/storefront.php` مؤقّتة — اضبطها على نطاقك.

## النشر

انظر [DEPLOYMENT.md](DEPLOYMENT.md) و[SEEDING.md](SEEDING.md).
