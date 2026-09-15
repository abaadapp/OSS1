<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckRole;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ما أُرسل صورةً يصل صورة — وما لم يصل يُقال إنّه لم يصل.
 *
 * ═══ ما كان قبل هذا ═══
 *
 * تاجرٌ يُصوّر شاشةً عطبت ويرسلها على واتساب، فيقرأ الدعمُ سطرًا يقول
 * «أرسل صورةً — لا تُعرض هنا. اطلب منه رفعها من داخل افنت بلس». وعميلٌ محتمَل
 * يرسل صورةَ ما يريد شراءَه فلا يراها أحد. وموظّفٌ يريد أن يُرِي تاجرًا
 * أين يضغط فلا بابَ يُرسل منه.
 *
 * ═══ وثلاثةُ أسئلةٍ يحرسها هذا الملفّ ═══
 *
 * **أوّلًا**: أيعبر الملفُّ فعلًا؟ رفعًا إلى ميتا بمعرّفٍ ثمّ رسالةً تحمله،
 * وتنزيلًا بالمعرّف ثمّ حفظًا على قرصنا الخاصّ.
 *
 * **ثانيًا**: وماذا يُكتب حين يعبر بعضُه؟ خرج النصُّ وسقط المرفق: ليست
 * «أُرسلت» — فالتاجرُ لم يرَ الصورة؛ وليست «لم تُرسل» — فمن أعادها أرسل
 * النصَّ مرّتين. فحالٌ ثالثةٌ تُقال بحرفها.
 *
 * **ثالثًا**: ومن يفتح المرفق؟ بابٌ يسأل عن الجلسة وعن الخيط معًا — ورقمٌ
 * يُبدَّل في العنوان لا يفتح مرفقَ عميلٍ آخر.
 */
class WhatIsSentAsAPictureArrivesAsAPictureTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private User $staff;

    private WhatsAppConnection $line;

    private WhatsAppConnection $sales;
    /* ═══════════════════ أدوات ═══════════════════ */

    private function person(?int $businessId, string $role, string $name, ?string $phone = null): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'business_id' => $businessId, 'name' => $name, 'email' => "media{$n}@eventplus.om",
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط', 'phone' => $phone,
        ]);
    }
    /** رسالةُ صورةٍ واردة */
    private function picture(string $wamid = 'wamid.IMG-1', string $from = '96891112222', array $extra = []): array
    {
        return ['id' => $wamid, 'from' => $from, 'type' => 'image', 'timestamp' => (string) now()->timestamp,
            'image' => ['id' => 'MEDIA-IN-1', 'mime_type' => 'image/jpeg'] + $extra];
    }

    /** ميتا تردّ: رفعٌ ناجحٌ وإرسالٌ ناجح */
    private function metaAccepts(): void
    {
        Http::fake([
            '*/media' => Http::response(['id' => 'MEDIA-OUT-1'], 200),
            '*/messages' => Http::sequence()
                ->push(['messages' => [['id' => 'wamid.OUT-1']]], 200)
                ->push(['messages' => [['id' => 'wamid.OUT-2']]], 200)
                ->push(['messages' => [['id' => 'wamid.OUT-3']]], 200),
        ]);
    }

    /** ميتا تردّ الملفَّ حين يُسأل عنه */
    private function metaServesFile(string $bytes = 'JPEG-BYTES'): void
    {
        Http::fake([
            '*/MEDIA-IN-1' => Http::response(['url' => 'https://lookaside.test/f1', 'mime_type' => 'image/jpeg'], 200),
            'https://lookaside.test/*' => Http::response($bytes, 200),
        ]);
    }
    /* ═══════════════════ الصادر — الدعم ═══════════════════ */

    /**
     * صورةٌ تُرفع إلى ميتا أوّلًا ثمّ تُرسل بمعرّفها.
     *
     * والرفعُ شرطٌ لا زينة: ميتا لا تقبل بايتات الملفّ في جسم الرسالة، ولا
     * يُرسَل رابطٌ إلى قرصنا — فمرفقُ الدعم خلف بابٍ يسأل عن الجلسة، ورابطٌ
     * يُعطى لميتا يعني قرصًا يُفتح من الخارج.
     */
    public function test_a_picture_is_uploaded_then_sent_by_its_id(): void
    {
        $this->metaAccepts();
        $c = $this->thread();

        $this->reply($c, [UploadedFile::fake()->create('shot.jpg', 8, 'image/jpeg')]);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/EVENTPLUS-PN/media'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/EVENTPLUS-PN/messages')
            && ($r->data()['type'] ?? '') === 'image'
            && ($r->data()['image']['id'] ?? '') === 'MEDIA-OUT-1');

        $this->assertSame('sent', $this->lastReply()->delivery);
    }

    /**
     * والنصُّ يخرج قبل الملفّ.
     *
     * من يصله ملفٌّ بلا كلمةٍ لا يعرف لماذا وصله، ويظنُّه خطأً فيتجاهله.
     */
    public function test_the_text_goes_out_before_the_file(): void
    {
        $this->metaAccepts();
        $c = $this->thread();

        $this->reply($c, [UploadedFile::fake()->create('shot.jpg', 8, 'image/jpeg')], 'هذي الشاشة');

        $types = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn ($r) => str_ends_with($r->url(), '/messages'))
            ->map(fn ($r) => (string) ($r->data()['type'] ?? ''))
            ->values()->all();

        $this->assertSame(['text', 'image'], $types, 'الملفُّ سبق النصَّ أو أحدُهما لم يخرج');
    }

    /**
     * وخروجُ النصّ دون المرفق ليس نجاحًا ولا فشلًا.
     *
     * «تقريرُ حالٍ كاذب أسوأ من غياب التقرير»: `sent` تجعل الموظّفَ يُغلق
     * الشاشةَ وهو يظنّ الصورةَ عند التاجر، و`failed` تجعله يُعيد الردَّ
     * كلَّه فيصل النصُّ مرّتين.
     */
    public function test_a_file_that_fails_after_the_text_is_marked_partial(): void
    {
        Http::fake([
            '*/media' => Http::response(['error' => ['code' => 100, 'message' => 'bad media']], 400),
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.OUT-1']]], 200),
        ]);

        $c = $this->thread();
        $this->reply($c, [UploadedFile::fake()->create('shot.jpg', 8, 'image/jpeg')]);

        $message = $this->lastReply();

        $this->assertSame('partial', $message->delivery, 'سقوطُ المرفق بعد خروج النصّ لم يُقيَّد `partial`');
        $this->assertStringContainsString('bad media', (string) $message->delivery_error);
        $this->assertSame('wamid.OUT-1', $message->external_message_id, 'ضاع معرّفُ ما خرج فعلًا');
    }

    /** وسقوطُ النصّ نفسِه لا يرفع ملفًّا ولا يُقيَّد `partial` */
    public function test_a_failed_text_never_uploads_the_file(): void
    {
        Http::fake([
            '*/media' => Http::response(['id' => 'MEDIA-OUT-1'], 200),
            '*/messages' => Http::response(['error' => ['code' => 131047, 'message' => 'Re-engagement']], 400),
        ]);

        $c = $this->thread();
        $this->reply($c, [UploadedFile::fake()->create('shot.jpg', 8, 'image/jpeg')]);

        $this->assertSame('failed', $this->lastReply()->delivery);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/media'));
    }

    /** ونافذةٌ مغلقةٌ لا تُخرج شيئًا — ولا ترفع ملفًّا يُحجز عند ميتا */
    public function test_a_closed_window_uploads_nothing(): void
    {
        Http::fake();

        $c = $this->thread();
        $c->forceFill(['whatsapp_window_at' => now()->subDays(3)])->save();

        $this->reply($c, [UploadedFile::fake()->create('shot.jpg', 8, 'image/jpeg')]);

        $this->assertSame('blocked', $this->lastReply()->delivery);
        Http::assertNothingSent();
    }
    /* ═══════════════════ الوارد — الدعم ═══════════════════ */
    /* ═══════════════════ المبيعات ═══════════════════ */
    /* ═══════════════════ القائمةُ واحدة ═══════════════════ */
    /**
     * ولا نوعَ توستٍ في المصدر خارج ما ترسمه الواجهة.
     *
     * ═══ ولمَ هذا هنا ═══
     *
     * وُجد وأنا أكتب حالَ `partial`: تسعةَ عشرَ موضعًا تكتب `'type' =>
     * 'error'`، والواجهةُ لا تعرف إلّا `success|warning|danger|info` —
     * فكلُّ إخفاقٍ في النظام كان يُعرض رماديًّا هادئًا كأنّه خبرٌ عاديّ.
     *
     * «طمأنينةٌ كاذبة أسوأ من تحذيرٍ كاذب».
     */
    public function test_no_toast_type_escapes_what_the_screen_can_draw(): void
    {
        $known = ['success', 'warning', 'danger', 'info'];
        $bad = [];

        foreach ($this->sourceFiles(app_path()) as $file) {
            preg_match_all("/'type' => '([a-z_]+)'/", (string) file_get_contents($file), $m, PREG_SET_ORDER);

            foreach ($m as $hit) {
                /* والسطرُ لا يُعدّ توستًا إلّا إن كان في جملةِ توست */
                if (! str_contains((string) file_get_contents($file), "'toast'")) {
                    continue;
                }

                if (in_array($hit[1], $known, true)) {
                    continue;
                }

                /* وحقولُ النماذج تكتب `'type' => 'text'` أيضًا — تُستثنى بما هي */
                if (in_array($hit[1], ['text', 'number', 'select', 'toggle', 'textarea', 'date',
                    'link', 'list', 'image', 'body', 'layout', 'social', 'products', 'order',
                    'invoice', 'template', 'type'], true)) {
                    continue;
                }

                $bad[] = basename($file).' → '.$hit[1];
            }
        }

        $this->assertSame([], $bad, 'نوعُ توستٍ لا ترسمه الواجهة — يُعرض رماديًّا هادئًا');
    }

    /** @return list<string> */
    private function sourceFiles(string $dir): array
    {
        $out = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }
}
