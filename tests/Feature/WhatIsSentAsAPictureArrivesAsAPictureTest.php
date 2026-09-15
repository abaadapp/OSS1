<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckRole;
use App\Models\Business;
use App\Models\CrmAttachment;
use App\Models\CrmLead;
use App\Models\CrmMessage;
use App\Models\SupportAttachment;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Support\Support;
use App\Support\WhatsAppMedia;
use App\Support\WhatsAppMode;
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

    protected function setUp(): void
    {
        parent::setUp();

        config(['whatsapp.app_secret' => 'test-app-secret']);
        Http::preventStrayRequests();
        Storage::fake('local');

        $this->shop = Business::create([
            'name' => 'محل الورد', 'type' => 'محل ورود', 'status' => 'نشط',
        ]);

        $this->owner = $this->person($this->shop->id, 'admin', 'صاحب المحل', '96891112222');
        $this->staff = $this->person(null, 'super_admin', 'دعم افنت بلس');

        $this->line = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'phone_number_id' => 'EVENTPLUS-PN',
            'display_phone_number' => '+968 7114 1624',
            'access_token' => 'platform-token-value-0123456789',
            'status' => WhatsAppConnection::ACTIVE,
            'purpose' => WhatsAppMode::PURPOSE_NOTIFICATIONS,
            'supports_inbox' => true,
        ]);

        $this->sales = WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'phone_number_id' => 'SALES-PN',
            'display_phone_number' => '+968 7114 9999',
            'access_token' => 'sales-token-value-01234567890',
            'status' => WhatsAppConnection::ACTIVE,
            'purpose' => WhatsAppMode::PURPOSE_CRM_SALES,
        ]);
    }

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

    /** محادثةُ دعمٍ نافذتُها مفتوحة */
    private function thread(): SupportConversation
    {
        $c = Support::open($this->owner, 'عطب', 'أخرى', 'الشاشة لا تفتح', 'whatsapp', '96891112222');
        $c->forceFill(['whatsapp_window_at' => now(), 'contact_phone' => '96891112222'])->save();

        return $c->fresh();
    }
    private function lastReply(): SupportMessage
    {
        return SupportMessage::where('sender_scope', 'platform')->orderByDesc('id')->firstOrFail();
    }

    /** عميلٌ محتمَلٌ نافذتُه مفتوحة */
    private function lead(): CrmLead
    {
        $this->metaServesFile();
        $this->inbound([
            'id' => 'wamid.LEAD-1', 'from' => '96896669730', 'type' => 'text',
            'text' => ['body' => 'كم سعر النظام؟'],
        ], 'SALES-PN')->assertOk();

        return CrmLead::firstOrFail();
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

    /** صورةٌ واردةٌ تُنزَّل وتُحفظ مرفقًا على رسالتها */
    public function test_an_inbound_picture_is_downloaded_and_attached(): void
    {
        $this->metaServesFile('JPEG-BYTES');

        $this->inbound($this->picture())->assertOk();

        $attachment = SupportAttachment::firstOrFail();

        $this->assertSame('image/jpeg', $attachment->mime);
        $this->assertSame(strlen('JPEG-BYTES'), $attachment->size);
        $this->assertTrue(Storage::disk('local')->exists($attachment->path), 'المرفقُ لم يُكتب على القرص');

        /*
         * والصورةُ تصل بلا اسم — فيُولَّد لها واحد.
         *
         * مرفقٌ بلا اسمٍ يُعرض سطرًا فارغًا يُضغط ولا يُعرف ما هو، ويُحمَّل
         * ملفًّا بلا امتدادٍ لا يفتحه النظام.
         */
        $this->assertStringStartsWith('whatsapp-', $attachment->name);
        $this->assertStringEndsWith('.jpg', $attachment->name, 'نزل الملفُّ بلا امتدادٍ يُفتح به');
        $this->assertSame(
            SupportMessage::orderByDesc('id')->first()->id,
            $attachment->message_id,
            'المرفقُ عُلّق على رسالةٍ غير رسالته'
        );
    }

    /** وتعليقُ الصورة يصير نصَّ الرسالة — لا يُطرح */
    public function test_a_caption_becomes_the_message_body(): void
    {
        $this->metaServesFile();

        $this->inbound($this->picture(extra: ['caption' => 'هنا يقف عند الطباعة']))->assertOk();

        $this->assertSame(
            'هنا يقف عند الطباعة',
            SupportMessage::orderByDesc('id')->first()->body
        );
    }

    /**
     * وإخفاقُ التنزيل يُقال ولا يُحفظ مرفقٌ فارغ.
     *
     * «طمأنينةٌ كاذبة أسوأ من تحذيرٍ كاذب»: مرفقٌ بصفرِ بايتٍ يُضغط فلا
     * يُفتح، وسطرٌ فارغٌ يُقرأ رسالةً بلا معنى.
     */
    public function test_a_failed_download_says_so_and_stores_nothing(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'media not found']], 404)]);

        $this->inbound($this->picture())->assertOk();

        $this->assertSame(0, SupportAttachment::count(), 'حُفظ مرفقٌ لم يُنزَّل');
        $this->assertStringContainsString(
            'media not found',
            (string) SupportMessage::orderByDesc('id')->first()->body
        );
    }

    /** وملفٌّ يصل فارغًا ليس ملفًّا */
    public function test_an_empty_download_is_not_kept(): void
    {
        $this->metaServesFile('');

        $this->inbound($this->picture())->assertOk();

        $this->assertSame(0, SupportAttachment::count(), 'حُفظ ملفٌّ بصفرِ بايت');
    }

    /**
     * ونوعٌ لا تعرضه شاشتُنا لا يُنزَّل أصلًا.
     *
     * رسالةٌ صوتيّةٌ بلا مشغّلٍ في الخيط بايتاتٌ على القرص لا يفتحها أحد.
     */
    public function test_an_unsupported_type_is_named_not_downloaded(): void
    {
        Http::fake();

        $this->inbound([
            'id' => 'wamid.AUD-1', 'from' => '96891112222', 'type' => 'audio',
            'audio' => ['id' => 'MEDIA-IN-9', 'mime_type' => 'audio/ogg'],
        ])->assertOk();

        Http::assertNothingSent();
        $this->assertSame(0, SupportAttachment::count());
        $this->assertStringContainsString(
            'رسالة صوتية',
            (string) SupportMessage::orderByDesc('id')->first()->body
        );
    }

    /* ═══════════════════ المبيعات ═══════════════════ */

    /** صورةٌ من عميلٍ محتمَلٍ تُحفظ في دفتره هو */
    public function test_a_lead_picture_lands_in_the_sales_book(): void
    {
        $this->lead();
        $this->metaServesFile('LEAD-JPEG');

        $this->inbound($this->picture(wamid: 'wamid.IMG-2', from: '96896669730'), 'SALES-PN')->assertOk();

        $attachment = CrmAttachment::firstOrFail();
        $message = CrmMessage::orderByDesc('id')->first();

        $this->assertSame($message->id, $attachment->message_id);
        $this->assertSame('image', $message->media_type, 'نوعُ ما جاءت به الرسالةُ لم يُكتب');
        $this->assertSame(0, SupportAttachment::count(), 'مرفقُ مبيعاتٍ كُتب في دفتر الدعم');
    }

    /**
     * ونوعٌ لا تعرضه شاشتُنا لا يُنزَّل على خطّ المبيعات أيضًا.
     *
     * والحارسان منفصلان في الكود — كلٌّ في دفتره. ومن يسقط أحدَهما لا
     * يُسقِط الآخر، فيُقاس كلٌّ على حدة.
     */
    public function test_an_unsupported_lead_file_is_named_not_downloaded(): void
    {
        $this->lead();
        Http::fake();

        $this->inbound([
            'id' => 'wamid.AUD-2', 'from' => '96896669730', 'type' => 'audio',
            'audio' => ['id' => 'MEDIA-IN-8', 'mime_type' => 'audio/ogg'],
        ], 'SALES-PN')->assertOk();

        Http::assertNothingSent();
        $this->assertSame(0, CrmAttachment::count(), 'نُزّل ما لا تعرضه الشاشة في دفتر المبيعات');
        $this->assertStringContainsString(
            'رسالة صوتية',
            (string) CrmMessage::orderByDesc('id')->first()->body
        );
    }
    /* ═══════════════════ القائمةُ واحدة ═══════════════════ */

    /**
     * وما يُقبل رفعُه هو ما يحمله واتساب — قائمةً واحدة.
     *
     * «حقلان يقولان الشيء نفسه يفترقان يومًا»: قائمةٌ في الدعم وأخرى في
     * القناة تعنيان ملفًّا يُقبل رفعُه ويُقال للموظّف «أُرسل» ثمّ تردُّه ميتا.
     */
    public function test_the_upload_policy_has_a_single_source(): void
    {
        $this->assertSame(WhatsAppMedia::EXTENSIONS, Support::EXTENSIONS);
        $this->assertSame(WhatsAppMedia::MAX_KB, Support::MAX_KB);
        $this->assertSame(WhatsAppMedia::MAX_FILES, Support::MAX_FILES);

        /* وكلُّ امتدادٍ نقبله له نوعٌ عند ميتا — وإلّا قُبل ما لا يُرسَل */
        foreach (WhatsAppMedia::KINDS as $mime => $kind) {
            $this->assertContains(WhatsAppMedia::suffix($mime), WhatsAppMedia::EXTENSIONS);
            $this->assertContains($kind, ['image', 'document']);
        }
    }

    /**
     * وصورةٌ فوق حدّ ميتا تُردّ قبل النداء لا بعده.
     *
     * ميتا تردُّها بخطأٍ عامٍّ إنجليزيٍّ لا يقول للموظّف ما يفعل — والقياسُ
     * عندنا يقول له.
     */
    public function test_an_oversized_image_is_refused_before_the_call(): void
    {
        Http::fake();

        Storage::disk('local')->put('big.jpg', str_repeat('x', (WhatsAppMedia::IMAGE_MAX_KB + 1) * 1024));

        $result = WhatsAppMedia::upload($this->line, 'local', 'big.jpg', 'image/jpeg', 'big.jpg');

        $this->assertFalse($result['ok']);
        $this->assertSame('image_too_large', $result['code']);
        Http::assertNothingSent();
    }

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
