# النشر

الفرق عن الموقع التعريفي (`eventplusapp/Website`): ذاك ملفّاتٌ ثابتة تُسحب بـ`git
pull`. وهذا **تطبيقُ خادم** — يحتاج بناءً وعمليّةً تعمل.

## أوّل مرّة

العارضُ يسكن `storefront/` داخل مستودع افنت بلس، ولا مستودعَ له وحده. فالسيرفر
يملك نسخةً منه أصلًا في `/var/www/eventplus` — وهذه النسخةُ ثانيةٌ مستقلّة عنها:
نشرُ افنت بلس لا يعيد بناء العارض ولا يوقفه، ونشرُ العارض لا يمسّ التطبيق.

```bash
# على السيرفر
cd /var/www && git clone git@eventplus.github.com:eventplusapp/eventplus.Saas.git eventplus-storefront
cd eventplus-storefront/storefront && npm ci && npm run build

cat > .env.local <<'ENV'
EVENTPLUS_API_URL=https://app.eventplusapp.om
EVENTPLUS_API_TIMEOUT_MS=6000
SITE_REVALIDATE_SECONDS=60
ENV
```

## الخدمة

`/etc/systemd/system/eventplus-storefront.service`:

```ini
[Unit]
Description=EventPlus Storefront
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/eventplus-storefront/storefront
Environment=NODE_ENV=production
Environment=PORT=3001
EnvironmentFile=/var/www/eventplus-storefront/storefront/.env.local
ExecStart=/usr/bin/node .next/standalone/server.js
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
systemctl enable --now eventplus-storefront
```

> `output: 'standalone'` يضع في `.next/standalone` خادمًا مكتفيًا بنفسه.
> انسخ معه `.next/static` و`public` إن وُجدا:
> `cp -r .next/static .next/standalone/.next/ && cp -r public .next/standalone/ 2>/dev/null || true`

## nginx

خادمٌ واحد يخدم كلّ نطاقات التجّار. و`default_server` مقصود: النطاق الذي
يُوجَّه إلينا ولا نعرفه يصل إلى العارض فيردّ «لا يوجد موقع على هذا العنوان»
بالعربية — لا صفحةَ nginx افتراضية.

```nginx
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;

    # نطاقات المنصّة نفسها لا تمرّ من هنا
    if ($host ~* ^(eventplusapp\.om|www\.eventplusapp\.om|app\.eventplusapp\.om)$) { return 444; }

    location / {
        proxy_pass http://127.0.0.1:3001;
        proxy_http_version 1.1;
        proxy_set_header Host              $host;
        proxy_set_header X-Forwarded-Host  $host;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_read_timeout 30s;
    }
}
```

**`X-Forwarded-Host` و`X-Forwarded-Proto` ليستا زينة**: بلا الأولى يقرأ
العارض عنوان الخادم الداخليّ فلا يعرف أيّ متجرٍ يعرض، وبلا الثانية تخرج
الروابط القانونيّة بـ`http` فيرى غوغل نسختين من كلّ صفحة.

## النطاقات الفرعية

التاجر الذي لا يملك نطاقًا يحجز `اسمه.eventplusapp.om` من شاشة الدومين في افنت بلس،
و`GET /site/{host}` يعرف كيف يحلّها. فيبقى شيئان خارج الشيفرة:

```bash
# ١) DNS: سجلّ شامل يشير إلى الخادم
*.eventplusapp.om.   A   165.227.145.219

# ٢) شهادة شاملة — تحتاج تحقّق DNS لا HTTP
certbot certonly --dns-<مزوّدك> -d '*.eventplusapp.om' -d eventplusapp.om
```

وحتى يُضبط هذان، الحجزُ في افنت بلس يبقى اسمًا محجوزًا لا عنوانًا يُفتح.

## شهادة نطاق التاجر

نطاقُ كلّ تاجر يملكه يحتاج شهادته:

```bash
certbot --nginx -d wrood.om -d www.wrood.om
```

ولمّا يكن ذلك آليًّا بعد، فربطُ نطاقٍ جديد خطوةٌ يدويّة يتابعها المشغّل —
وهي نفسها التي تصفها شاشة الدومين في افنت بلس.

## التحديث

```bash
cd /var/www/eventplus-storefront &&
git pull --ff-only &&
cd storefront &&
npm ci &&
npm run build &&
cp -r .next/static .next/standalone/.next/ &&
systemctl restart eventplus-storefront
```

## الفحص بعد النشر

```bash
curl -sI -H 'Host: wrood.om'   http://127.0.0.1:3001/          # 200
curl -sI -H 'Host: nothing.om' http://127.0.0.1:3001/          # 200 + «لا يوجد موقع»
curl -s  -H 'Host: wrood.om'   http://127.0.0.1:3001/robots.txt
curl -s  -H 'Host: wrood.om'   http://127.0.0.1:3001/sitemap.xml
```

وإن ردّ «الموقع غير متاح مؤقّتًا»: `EVENTPLUS_API_URL` خطأ، أو افنت بلس لا تُجيب —
`journalctl -u eventplus-storefront -n 50`.
