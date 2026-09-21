<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\User;
use App\Support\Mailer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * فحص ما قبل الإطلاق — يقرأ الحالة الفعلية للنظام ولا يفترض شيئًا.
 *
 *   php artisan eventplus:preflight
 *
 * كل بند يقول ماذا فحص وبماذا خرج، حتى يكون الفشل قابلًا للتصرّف لا مجرّد ✗.
 * الخروج بـ1 عند وجود أي خطأ حرج، ليصلح للاستخدام في خط نشر آلي.
 */
class Preflight extends Command
{
    protected $signature = 'eventplus:preflight';

    protected $description = 'فحص جاهزية النظام للإطلاق: الإعدادات والأمان والأصول والبيانات';

    private array $fail = [];

    private array $warn = [];

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <options=bold>فحص ما قبل الإطلاق — افنت بلس</>');
        $this->newLine();

        $this->section('البيئة والإعدادات');
        $this->check(
            'APP_ENV = production',
            app()->environment('production'),
            'القيمة الحالية: '.app()->environment().' — اضبط APP_ENV=production في .env'
        );
        $this->check(
            'APP_DEBUG مُطفأ',
            ! config('app.debug'),
            'التصحيح مفتوح: أي خطأ يعرض مسارات الملفات وقيم .env للزائر. اضبط APP_DEBUG=false'
        );
        $this->check('APP_KEY مضبوط', ! empty(config('app.key')), 'شغّل: php artisan key:generate');
        $this->check(
            'APP_URL نطاق حقيقي بـhttps',
            str_starts_with((string) config('app.url'), 'https://') && ! str_contains((string) config('app.url'), 'localhost'),
            'القيمة الحالية: '.config('app.url').' — الروابط في الفواتير والبريد تُبنى منها'
        );
        $this->check(
            'كوكي الجلسة محصور بـhttps',
            (bool) config('session.secure'),
            'اضبط SESSION_SECURE_COOKIE=true وإلا انتقلت الجلسة على http مكشوفة',
            warnOnly: ! str_starts_with((string) config('app.url'), 'https://')
        );

        $this->section('الأبواب المفتوحة');
        $this->check(
            'الدخول التجريبي غير مسجَّل',
            ! Route::has('demo.login'),
            'مسار /demo-login يمنح جلسةً كاملة بلا كلمة مرور — يجب ألّا يوجد في الإنتاج'
        );
        /*
         * صار مانعًا لا تنبيهًا.
         *
         * كان `log` يعني أن التنبيهات والتقارير لا تصل — مزعج ولا يُوقف أحدًا.
         * ثم صارت استعادة كلمة المرور تمرّ من هنا: فمع `log` يقول النظام
         * للتاجر «أرسلنا الرابط» ولا يُرسل شيئًا، ولا باب له غير هذا.
         */
        // نفس الحكم الذي تقرؤه شاشة الدخول ومسار الاستعادة — مصدرٌ واحد،
        // فلا يتخلّف موضعٌ يوم يُضبط SMTP. ويشمل smtp بلا مضيف، وهي حالةٌ
        // كان الفحص القديم يمرّرها ثم تفشل عند المستخدم لا عند من ضبطها
        $this->check(
            'البريد يصل فعلًا (لا سجلّ ولا مُرسِل صامت)',
            Mailer::configured(),
            'لا مُرسِل بريد حقيقي — لا تصل التنبيهات، ولا رابط استعادة كلمة المرور: يقول النظام «أرسلنا» ولا يُرسل'
        );

        $this->section('الحسابات');
        $weak = User::where('role', 'admin')->get()
            ->filter(fn ($u) => Hash::check('password', $u->password) || Hash::check('test1234', $u->password))
            ->pluck('email');
        $this->check(
            'لا حساب إداري بكلمة مرور افتراضية',
            $weak->isEmpty(),
            'حسابات بكلمة مرور معروفة: '.$weak->implode('، ')
        );
        $this->check(
            'يوجد حساب مالكٍ واحد على الأقل',
            User::where('role', 'admin')->exists(),
            'لا يمكن الدخول إلى اللوحة — أنشئ حسابًا عبر البذرة أو tinker'
        );

        $this->section('البيانات');
        /*
         * بالعلامة لا بالبريد.
         *
         * كان يُعرَف ببريد مالكه، وهو نصٌّ يُبدَّل من شاشة الإعدادات — فمتى
         * بُدِّل صارت الشركةُ التجريبيّة خفيّةً عن هذا الفحص وبقيت في الإنتاج.
         *
         * وتنبيهٌ لا مانع: الشركة التجريبيّة معزولةٌ بعلامتها — خارج
         * الإحصاءات — فوجودُها لا يُفسد شيئًا. لكنّها تبقى حسابًا يُدخَل
         * إليه بكلمة مرورٍ معروفة، وذاك يُقال لا يُسكَت عنه.
         */
        $demos = Business::demo()->pluck('name', 'id');
        $this->check(
            'لا شركة تجريبيّة في القاعدة',
            $demos->isEmpty(),
            'شركات تجريبيّة قائمة: '.$demos->map(fn ($n, $id) => "{$n} (#{$id})")->join('، ')
                .' — تُحذف بـ`App\\Support\\DemoStore::destroy`',
            warnOnly: true
        );

        $this->section('الأصول والذاكرة المؤقتة');
        $manifest = public_path('build/manifest.json');
        $this->check('أصول الواجهة مبنيّة', file_exists($manifest), 'شغّل: npm ci && npm run build');
        $this->check(
            'رابط التخزين العام موجود',
            file_exists(public_path('storage')),
            'شغّل: php artisan storage:link — بدونه لا تظهر الشعارات وصور المنتجات'
        );
        $this->check(
            'الإعدادات مُخزَّنة مؤقتًا',
            file_exists(base_path('bootstrap/cache/config.php')),
            'شغّل: php artisan config:cache route:cache view:cache',
            warnOnly: true
        );

        $this->section('المهام المجدولة');

        /*
         * كان هذا تحذيرًا ثابتًا يُطبع دائمًا: «المجدول لا يعمل من نفسه».
         *
         * وعلى خادم الإنتاج كان المجدول يعمل فعلًا من /etc/cron.d/eventplus —
         * فيقرأ الناظر تحذيرًا كاذبًا كل مرّة حتى يتعوّد تخطّيه، ويمرّ معه
         * الصادقُ يومًا. فيُبحث عن السطر حيث يُكتب عادةً بدل افتراض غيابه.
         */
        $hook = $this->schedulerHook();
        if ($hook !== null) {
            $this->check('المجدول موصولٌ بـcron', true, '');
        } else {
            $this->warn2(
                'لم أجد سطر المجدول في cron — تأكّد منه بنفسك:',
                '* * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1'
            );
        }

        /*
         * الجدولة لا تُثبت أن شيئًا جرى.
         *
         * `backup:run` مجدولٌ يوميًا منذ شهور، ولم يكن في النظام ما يقول إن
         * cron يعمل أصلًا. فيبقى السطر في routes/console.php شاهدَ نيّةٍ لا
         * شاهدَ نسخة — ولا يُكتشف الفرق إلا يوم تُطلب النسخة.
         */
        /*
         * الطابور بلا عامل هو صمتٌ آخر.
         *
         * إشعار «طلب جديد» صار يُوضع في الطابور كي لا ينتظره الكاشير — وهذا
         * يعني أن لا شيء يُرسَل إن لم يكن هناك عاملٌ يسحب. فيتحوّل بطءٌ ظاهر
         * إلى غيابٍ صامت، وهو أسوأ.
         */
        if (config('queue.default') === 'database') {
            $stuck = DB::table('jobs')
                ->where('created_at', '<', now()->subMinutes(15)->timestamp)->count();
            $this->check(
                'لا مهام عالقة في الطابور',
                $stuck === 0,
                $stuck.' مهمة عالقة منذ أكثر من ربع ساعة — شغّل عاملًا دائمًا: php artisan queue:work (عبر supervisor أو systemd)',
            );

            /*
             * وجود العامل يُفحص وحده، لا يُستنتج من خلوّ الطابور.
             *
             * كان الفحص السابق يمرّ ✓ على خادمٍ لا عامل فيه إطلاقًا — لا
             * systemd ولا supervisor — لأن الطابور فارغ. وطابورٌ فارغ ليس
             * دليلًا على أن أحدًا يسحب منه، بل على أن شيئًا لم يُصفَّ بعد:
             * أوّل مهمةٍ تُصفّ تبقى إلى الأبد، والإشعار الذي وُضع في الطابور
             * كي لا ينتظره الكاشير لا يصل أحدًا.
             *
             * وطمأنينةٌ كاذبة أسوأ من تحذيرٍ كاذب: الثاني يُزعج، والأول يُنيم.
             */
            $worker = $this->queueWorkerRunning();
            if ($worker === false) {
                $this->check(
                    'عاملٌ دائم يسحب من الطابور',
                    false,
                    'لا عامل يعمل على هذا الخادم — أي مهمة تُصفّ ستبقى بلا تنفيذ. '
                    .'شغّله دائمًا عبر systemd أو supervisor: php artisan queue:work --sleep=3 --tries=3',
                );
            } elseif ($worker === null) {
                $this->warn2(
                    'تعذّر التحقّق من عامل الطابور (لا تنفيذ أوامر) — تأكّد بنفسك:',
                    'pgrep -fa "artisan queue:work"',
                );
            } else {
                $this->check('عاملٌ دائم يسحب من الطابور', true, '');
            }

            $failed = DB::table('failed_jobs')->count();
            $this->check(
                'لا مهام فاشلة في الطابور',
                $failed === 0,
                $failed.' مهمة فاشلة — راجعها: php artisan queue:failed',
                warnOnly: true,
            );
        }

        /*
         * فوترةُ Google — تنبيهٌ يصل مع كلّ نشر، لا في شاشةٍ تُفتح شهريًّا.
         *
         * ومفتاحُ المنصّة واحدٌ لكلّ التجّار: يومَ تنتهي التجربة ولا يُرفع
         * الحساب تتوقّف الخرائط عن الجميع دفعةً واحدة — ولا يقول شيءٌ لماذا،
         * فيرى التاجر «رفضت Google المفتاح» ويظنّ العطبَ عندنا.
         *
         * ولا يُنبَّه على منصّةٍ بلا مفتاح: لا شيء يُحمى، وتحذيرٌ لا يقابل
         * خطرًا يُقرأ مرّتين ثمّ يُتخطّى — ويمرّ معه الصادقُ يومًا.
         */
        $stamp = $this->lastBackup();
        $this->check(
            'نسخة احتياطية خلال آخر ٤٨ ساعة',
            $stamp !== null && $stamp['fresh'] && empty($stamp['failed']),
            $stamp === null
                ? 'لم تُنشأ أي نسخة احتياطية قط — تأكّد من cron، وشغّل الآن: php artisan backup:run'
                : (! $stamp['fresh']
                    ? 'آخر نسخة: '.$stamp['at'].' — المجدول متوقّف على الأرجح'
                    : 'آخر تشغيل فشل في '.count($stamp['failed']).' متجرًا — شغّل: php artisan backup:run'),
        );

        /*
         * ─────────────────── جاهزيّةُ الإنتاج — المرحلة 12 §66 ───────────────────
         *
         * ما يُفحص هنا يُقرأ من الإعدادات الحيّة ولا يطبع سرًّا: يقول «مضبوط»
         * أو «ناقص» ولا يقول القيمة. ويصلح للتشغيل على خادم الإنتاج بعد النشر.
         */
        $this->section('البنية التحتيّة');
        $driver = (string) config('database.default');
        $this->check('قاعدةُ البيانات PostgreSQL', $driver === 'pgsql',
            'المحرّكُ الحاليّ '.$driver.' — الإنتاجُ على PostgreSQL؛ SQLite للتطوير وحده', warnOnly: ! app()->environment('production'));
        $this->check('الاتّصالُ بالقاعدة يعمل', $this->safe(fn () => DB::connection()->getPdo() !== null), 'تعذّر الاتّصال — راجع DB_* في .env');
        $this->check('لا هجراتٍ معلَّقة', $this->safe(fn () => $this->pendingMigrations() === 0), 'هجراتٌ لم تُشغَّل: php artisan migrate --force');
        foreach ([['queue', 'jobs', 'QUEUE_CONNECTION=database يحتاج جدول jobs'], ['session', 'sessions', 'SESSION_DRIVER=database يحتاج جدول sessions'], ['cache', 'cache', 'CACHE_STORE=database يحتاج جدول cache']] as [$kind, $table, $fix]) {
            $store = match ($kind) { 'queue' => config('queue.default'), 'session' => config('session.driver'), default => config('cache.default') };
            if ($store === 'database') {
                $this->check("جدولُ {$table} موجود (".$kind.' = database)', $this->safe(fn () => Schema::hasTable($table)), $fix.' — شغّل الهجرات');
            } else {
                $this->check(ucfirst($kind).' = '.$store, ! in_array($store, ['array', 'null'], true), $kind.' على '.$store.' لا يصلح للإنتاج');
            }
        }
        $this->check('مجلّدُ التخزين قابلٌ للكتابة', is_writable(storage_path('app')) && is_writable(storage_path('logs')) && is_writable(storage_path('framework')), 'storage/ غيرُ قابلٍ للكتابة — chown/chmod لمستخدم الخادم');
        $this->check('ملفُّ .env ليس مقروءًا للعامّة', ! file_exists(base_path('.env')) || (fileperms(base_path('.env')) & 0004) === 0, 'chmod 640 .env', warnOnly: true);
        $this->check('المنطقةُ الزمنيّة مسقط', config('app.timezone') === 'Asia/Muscat', 'APP_TIMEZONE=Asia/Muscat — المواعيدُ والفواتيرُ تُختم بها', warnOnly: true);
        $this->check('المسارات مُخزَّنة مؤقتًا', file_exists(base_path('bootstrap/cache/routes-v7.php')) || count(glob(base_path('bootstrap/cache/routes*.php')) ?: []) > 0, 'php artisan route:cache', warnOnly: true);

        $this->section('القنوات الخارجيّة');
        $mailer = (string) config('mail.default');
        $this->check('مُرسِلُ البريد معروف: '.$mailer, in_array($mailer, ['smtp', 'postmark', 'ses', 'sendmail'], true), 'MAIL_MAILER على مُرسِلٍ حقيقيّ: postmark أو smtp أو ses أو sendmail — لا log ولا resend');
        if ($mailer === 'postmark') {
            $this->check('سرُّ webhook لـPostmark مضبوط', filled(config('services.postmark.webhook_secret')), 'POSTMARK_WEBHOOK_SECRET — بدونه بابُ الأخبار مقفل (404) ولا يُعرف ما وصل وما ارتدّ', warnOnly: true);
        }
        $wa = filled(config('services.whatsapp.token'));
        $this->check('واتساب: '.($wa ? 'موصول' : 'غيرُ موصول (مقصود حتى قرار التفعيل)'), true, '');
        if ($wa) {
            $this->check('واتساب: سرُّ التطبيق ورمزُ التحقّق مضبوطان', filled(config('services.whatsapp.app_secret')) && filled(config('services.whatsapp.verify_token')), 'WHATSAPP_APP_SECRET وWHATSAPP_VERIFY_TOKEN — بدونهما لا تُقبل أخبارُ Meta');
        }

        $this->section('سلامةُ الأتمتة والمراسلات');
        $live = $this->safe(fn () => \App\Models\Setting::where('key', 'automation_mode')->where('value', 'live')->count()) ?: 0;
        $this->check('لا شركةَ في وضع الأتمتة الحيّ', $live === 0, $live.' شركة في الوضع الحيّ — الانطلاقُ يكون مطفأً أو مراقِبًا ثمّ يُفعَّل بقرار (docs/AUTOMATION_GO_LIVE.md)', warnOnly: true);
        $liveRules = $this->safe(fn () => \App\Models\AutomationRule::where('kind', 'external')->where('enabled', true)->where('mode', 'live')->count()) ?: 0;
        $this->check('لا قاعدةَ خارجيّة حيّة', $liveRules === 0, $liveRules.' قاعدة خارجيّة حيّة — تُراجَع قبل الانطلاق', warnOnly: true);

        /* ------------------------------- الخلاصة ------------------------------- */
        $this->newLine();
        if ($this->fail) {
            $this->line('  <fg=red;options=bold>✗ غير جاهز — '.count($this->fail).' مانع:</>');
            foreach ($this->fail as $f) {
                $this->line('    • '.$f);
            }
        } else {
            $this->line('  <fg=green;options=bold>✓ جاهز للإطلاق</>');
        }
        if ($this->warn) {
            $this->newLine();
            $this->line('  <fg=yellow>تنبيهات ('.count($this->warn).') — لا تمنع الإطلاق:</>');
            foreach ($this->warn as $w) {
                $this->line('    • '.$w);
            }
        }
        $this->newLine();

        return $this->fail ? self::FAILURE : self::SUCCESS;
    }

    /** بصمة آخر نسخة احتياطية — يكتبها backup:run */
    private function lastBackup(): ?array
    {
        $disk = Storage::disk('local');

        if (! $disk->exists(BackupRun::STAMP)) {
            return null;
        }

        $data = json_decode((string) $disk->get(BackupRun::STAMP), true);
        $at = $data['finished_at'] ?? null;

        if (! is_array($data) || ! $at) {
            return null;
        }

        return [
            'at' => Carbon::parse($at)->diffForHumans(),
            // ٤٨ لا ٢٤: تشغيلٌ واحد يتأخّر أو يفوت لا يستحق منعَ إطلاق
            'fresh' => Carbon::parse($at)->gt(now()->subHours(48)),
            'failed' => $data['failed'] ?? [],
        ];
    }

    /**
     * أين كُتب سطر المجدول — أو null إن لم يُوجد.
     *
     * ثلاثة مواضع لا موضعٌ واحد: `crontab -l` يقرأ جدول المستخدم الحالي
     * وحده، والمجدول يُكتب عادةً في /etc/cron.d باسم المشروع ليعمل بمستخدم
     * الخادم (www-data). فحصٌ يقرأ الأول وحده يُبلّغ عن غيابٍ لا وجود له.
     */
    private function schedulerHook(): ?string
    {
        // الملفات تُقرأ بلا تنفيذ أي أمر — تعمل حتى لو مُنع shell_exec
        foreach (array_merge(['/etc/crontab'], glob('/etc/cron.d/*') ?: []) as $file) {
            if (is_readable($file) && str_contains((string) @file_get_contents($file), 'schedule:run')) {
                return $file;
            }
        }

        foreach (['crontab -l 2>/dev/null', 'systemctl list-timers --all --no-pager 2>/dev/null'] as $cmd) {
            $out = $this->shell($cmd);
            if ($out !== null && str_contains($out, 'schedule')) {
                return $cmd;
            }
        }

        return null;
    }

    /**
     * هل يعمل عاملُ طابورٍ الآن؟ null = تعذّر الفحص، فلا يُدَّعى نفيٌ ولا إثبات.
     *
     * والعدّ بـwc لا قراءة مخرج pgrep مباشرةً: shell_exec يعيد null عند
     * غياب المخرج كما يعيدها عند الفشل، فـ«لا عامل» — وهي الحالة التي كُتب
     * هذا الفحص لأجلها — كانت ستُقرأ «تعذّر الفحص» وتمرّ. وwc يطبع رقمًا
     * دائمًا، فيفترق الصمتان.
     *
     * و[a]rtisan لا artisan: النمط يبحث في أسطر أوامر العمليات، وسطرُ الأمر
     * الذي يبحث يحمل النمط نفسه — فيلتقط pgrep نفسه ويعدّ واحدًا ويُقرأ
     * «يوجد عامل» على خادمٍ لا عامل فيه. القوس يكسر المطابقة الذاتية دون أن
     * يغيّر ما يُطابَق: «[a]rtisan» في سطر البحث ليس «artisan».
     *
     * وقعتُ فيها فعلًا في أول نسخةٍ من هذا الفحص، وهي نفس الطمأنينة الكاذبة
     * التي كُتب لإزالتها.
     */
    private function queueWorkerRunning(): ?bool
    {
        $out = $this->shell('pgrep -f "[a]rtisan queue:(work|listen)" 2>/dev/null | wc -l');

        return $out === null || trim($out) === '' ? null : ((int) trim($out)) > 0;
    }

    /** تنفيذ أمر قراءةٍ — null إن كان shell_exec ممنوعًا في هذه البيئة */
    private function shell(string $cmd): ?string
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        if (! function_exists('shell_exec') || in_array('shell_exec', $disabled, true)) {
            return null;
        }

        return @shell_exec($cmd);
    }

    /** فحصٌ يرمي استثناءً فحصٌ فاشل — لا سقوطٌ للأمر كلِّه */
    private function safe(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return false;
        }
    }

    private function pendingMigrations(): int
    {
        $migrator = app('migrator');
        $ran = $migrator->getRepository()->getRan();
        $files = $migrator->getMigrationFiles($migrator->paths() ?: [database_path('migrations')]);

        return count(array_diff(array_keys($files), $ran));
    }

    private function section(string $title): void
    {
        $this->line("  <options=bold>{$title}</>");
    }

    private function check(string $label, bool $ok, string $fix, bool $warnOnly = false): void
    {
        if ($ok) {
            $this->line("    <fg=green>✓</> {$label}");

            return;
        }

        if ($warnOnly) {
            $this->line("    <fg=yellow>!</> {$label}");
            $this->warn[] = $fix;

            return;
        }

        $this->line("    <fg=red>✗</> {$label}");
        $this->fail[] = $fix;
    }

    private function warn2(string $label, string $detail): void
    {
        $this->line("    <fg=yellow>!</> {$label}");
        $this->line("      <fg=gray>{$detail}</>");
    }
}
