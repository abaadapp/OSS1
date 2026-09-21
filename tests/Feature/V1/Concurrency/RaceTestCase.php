<?php

namespace Tests\Feature\V1\Concurrency;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\JobTitle;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * سباقاتٌ حقيقيّة على PostgreSQL — المرحلة 12.
 *
 * SQLite يقفل القاعدةَ كلَّها عند الكتابة فلا يُثبت شيئًا عن أقفال الصفوف
 * ولا عن القيود تحت التزامن. فهذه المجموعةُ تُشغَّل على محرّك الإنتاج وحده
 * (`phpunit.pgsql.xml`) وتُتخطّى على غيره.
 *
 * والسباقُ عمليّاتٌ تُشعَّب (`pcntl_fork`)، لكلٍّ اتّصالُها بالقاعدة، تنطلق
 * في لحظةٍ واحدة على بياناتٍ **مُرسَلة** (لا معاملةَ تلفّ الاختبار — وإلّا
 * ما رأتها العمليّاتُ الأخرى)؛ ثمّ تُقصّ الجداول بعد كلّ اختبار.
 */
abstract class RaceTestCase extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('السباقاتُ تُثبَت على PostgreSQL وحده — vendor/bin/phpunit -c phpunit.pgsql.xml');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl غير متاح');
        }
    }

    /**
     * يُشغّل العمّالَ معًا ويُعيد نتائجَهم بترتيبهم.
     *
     * كلُّ عاملٍ دالّةٌ تُعيد قيمةً قابلةً للتحويل إلى JSON أو ترمي؛ فتُقرأ
     * نتيجتُه `['ok' => bool, 'value' => mixed, 'error' => ?string]`.
     *
     * @param  list<callable>  $workers
     * @return list<array{ok: bool, value: mixed, error: ?string}>
     */
    protected function race(array $workers, float $leadSeconds = 0.4): array
    {
        $dir = sys_get_temp_dir().'/eventplus-race-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $startAt = microtime(true) + $leadSeconds;

        /* الاتّصالُ الموروثُ لا يُشارَك بين عمليّتين — يُقطع قبل التشعّب ويُعاد في كلٍّ */
        DB::disconnect();

        $pids = [];
        foreach ($workers as $i => $worker) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('تعذّر التشعّب');
            }
            if ($pid === 0) {
                $out = ['ok' => false, 'value' => null, 'error' => null];
                try {
                    DB::purge();
                    DB::reconnect();
                    $wait = $startAt - microtime(true);
                    if ($wait > 0) {
                        usleep((int) ($wait * 1_000_000));
                    }
                    $out = ['ok' => true, 'value' => $worker(), 'error' => null];
                } catch (\Throwable $e) {
                    $out = ['ok' => false, 'value' => null, 'error' => get_class($e).': '.$e->getMessage()];
                }
                file_put_contents("$dir/$i.json", json_encode($out, JSON_UNESCAPED_UNICODE));
                /* لا تُنفَّذ دوالُّ الإنهاء الموروثة من PHPUnit في الابن */
                posix_kill(posix_getpid(), SIGKILL);
            }
            $pids[$i] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        DB::reconnect();

        $results = [];
        foreach (array_keys($workers) as $i) {
            $raw = @file_get_contents("$dir/$i.json");
            $results[$i] = $raw === false ? ['ok' => false, 'value' => null, 'error' => 'لا نتيجة من العامل'] : json_decode($raw, true);
            @unlink("$dir/$i.json");
        }
        @rmdir($dir);

        return $results;
    }

    /** شركةٌ مُرسَلةٌ إلى القاعدة بشجرتها وعملتها — تراها العمليّاتُ المتشعّبة */
    protected function company(string $name = 'افنت بلس'): Business
    {
        $b = Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط', 'email' => 'race-'.bin2hex(random_bytes(3)).'@eventplus.om']);
        Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $b->id, 'name' => 'مدير', 'role' => 'admin']);
        Currency::create(['business_id' => $b->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Setting::create(['business_id' => $b->id, 'key' => 'vat_rate', 'value' => '5']);
        Ledger::seedChart((int) $b->id);

        return $b;
    }

    protected function user(Business $b, string $role = 'admin', string $name = 'المالك'): User
    {
        return User::create([
            'business_id' => $b->id, 'name' => $name, 'email' => strtolower($role).'-'.bin2hex(random_bytes(3)).'@eventplus.om',
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط', 'phone' => '9'.random_int(1000000, 9999999),
        ]);
    }

    protected function customer(Business $b, string $name = 'وزارة الطاقة'): Customer
    {
        return Customer::create(['business_id' => $b->id, 'name' => $name, 'phone' => '99887766', 'email' => 'buyer@ministry.om']);
    }

    /** كم عاملًا نجح */
    protected function successes(array $results): int
    {
        return count(array_filter($results, fn ($r) => $r['ok']));
    }
}
