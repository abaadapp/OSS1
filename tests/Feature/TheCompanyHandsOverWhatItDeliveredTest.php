<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\DeliveryPackage;
use App\Models\DeliveryPackageItem;
use App\Models\JobTitle;
use App\Models\User;
use App\Support\DeliveryPackages;
use App\Support\DeliveryReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * الشركةُ تسلّم ما أنجزته — حزمةً تُجمَع وتُفحص وتُرسَل، ولا تُقفز دورتُها.
 *
 * ما يُفحص: الإنشاءُ والبنودُ والملفّات · الدورةُ الصارمة ورفضُ القفز ·
 * الإرسالُ مرّةً (رقمٌ واحد وبصمةٌ واحدة) · الإلغاءُ · الإعادةُ بعد رفض ·
 * الأذونُ والعزلُ · وجاهزيّةُ الإتمام.
 */
class TheCompanyHandsOverWhatItDeliveredTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private User $sales;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('local');

        $this->business = Business::create(['name' => 'افنت بلس', 'type' => 'عام', 'status' => 'نشط', 'email' => 'hello@hadath.om']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);
        Currency::create(['business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true]);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@hadath.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        /* بائعٌ مُنح القسمَ بالاسم ولا فعلَ معه: يفتح ويكتب، ولا يُرسل ولا يقرّر */
        $this->sales = User::create([
            'business_id' => $this->business->id, 'name' => 'بائع', 'email' => 'sales@hadath.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط',
            'permissions' => ['dashboard', 'quotations'],
        ]);
        $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'وزارة', 'legal_name' => 'وزارة الطاقة']);
    }

    private function draft(array $over = []): DeliveryPackage
    {
        return DeliveryPackages::create((int) $this->business->id, $this->customer,
            $over + ['title' => 'تسليم مؤتمر الطاقة', 'client_notes' => 'كل التقارير مرفقة'], $this->owner);
    }

    private function withItems(DeliveryPackage $p): DeliveryPackage
    {
        DeliveryPackages::addItem($p, ['kind' => 'item', 'title' => 'تشغيل نظام التسجيل', 'quantity' => 2, 'unit' => 'يوم'], null, $this->owner);
        DeliveryPackages::addItem($p, ['kind' => 'file', 'title' => 'تقرير الحضور'],
            UploadedFile::fake()->create('attendance.pdf', 120, 'application/pdf'), $this->owner);

        return $p->fresh('items');
    }

    private function sent(): DeliveryPackage
    {
        $p = $this->withItems($this->draft());
        DeliveryPackages::ready($p, $this->owner);

        return DeliveryPackages::send($p->fresh(), $this->owner);
    }

    /* ─────────────────────────── الإنشاء والبنود ─────────────────────────── */

    public function test_a_package_is_created_as_a_draft_with_its_items_and_files(): void
    {
        $this->actingAs($this->owner);
        $p = $this->withItems($this->draft());

        $this->assertSame(DeliveryPackage::DRAFT, $p->status);
        $this->assertNull($p->number);
        $this->assertCount(2, $p->items);

        $file = $p->items->firstWhere('kind', DeliveryPackageItem::FILE);
        $this->assertNotNull($file->path);
        Storage::disk('local')->assertExists($file->path);
        $this->assertSame(hash_file('sha256', Storage::disk('local')->path($file->path)), $file->checksum);
        $this->assertSame('attendance.pdf', $file->name);

        $line = $p->items->firstWhere('kind', DeliveryPackageItem::ITEM);
        $this->assertSame(2.0, $line->quantity);
        $this->assertNull($line->path);

        $this->assertTrue(ActivityLog::where('subject_type', 'delivery_package')->where('action', 'created')->exists());
    }

    public function test_a_file_item_without_a_file_is_refused(): void
    {
        $this->actingAs($this->owner);
        $p = $this->draft();

        $this->expectException(RuntimeException::class);
        DeliveryPackages::addItem($p, ['kind' => 'file', 'title' => 'تقرير'], null, $this->owner);
    }

    public function test_removing_an_item_removes_its_file_unless_another_package_still_points_to_it(): void
    {
        $this->actingAs($this->owner);
        $p = $this->withItems($this->draft());
        $file = $p->items->firstWhere('kind', DeliveryPackageItem::FILE);
        $path = $file->path;

        DeliveryPackages::removeItem($file, $this->owner);

        Storage::disk('local')->assertMissing($path);
        $this->assertCount(1, $p->fresh()->items);
    }

    /* ─────────────────────────── الدورة ─────────────────────────── */

    public function test_the_workflow_is_strict_and_jumps_are_rejected(): void
    {
        $this->actingAs($this->owner);
        $p = $this->withItems($this->draft());

        // مسودّة ← مرسَلة: لا
        try {
            DeliveryPackages::send($p, $this->owner);
            $this->fail('أُرسلت مسودّة لم تُفحص');
        } catch (RuntimeException) {
        }
        $this->assertSame(DeliveryPackage::DRAFT, $p->fresh()->status);

        // مسودّة ← مقبولة: لا
        try {
            DeliveryPackages::recordDecision($p, DeliveryPackage::ACCEPTED, [], $this->owner);
            $this->fail('قُبلت حزمة لم تُرسَل');
        } catch (RuntimeException) {
        }
        $this->assertSame(DeliveryPackage::DRAFT, $p->fresh()->status);

        // مسودّة ← جاهزة ← مسودّة ← جاهزة ← مرسَلة: نعم
        $p = DeliveryPackages::ready($p, $this->owner);
        $this->assertSame(DeliveryPackage::READY, $p->status);
        $this->assertNotNull($p->prepared_at);

        $p = DeliveryPackages::reopen($p, $this->owner);
        $this->assertSame(DeliveryPackage::DRAFT, $p->status);
        $this->assertNull($p->prepared_at);

        $p = DeliveryPackages::ready($p, $this->owner);
        $p = DeliveryPackages::send($p, $this->owner);
        $this->assertSame(DeliveryPackage::SENT, $p->status);
        $this->assertSame('DP-000001', $p->number);
        $this->assertNotNull($p->content_hash);
        $this->assertNotNull($p->sent_at);

        // مرسَلة ← مسودّة: لا — ما خرج لا يُعاد
        $this->expectException(RuntimeException::class);
        DeliveryPackages::reopen($p, $this->owner);
    }

    public function test_an_empty_package_cannot_become_ready(): void
    {
        $this->actingAs($this->owner);
        $p = $this->draft();

        $this->assertNotEmpty(DeliveryPackages::blockers($p));

        $this->expectException(RuntimeException::class);
        DeliveryPackages::ready($p, $this->owner);
    }

    public function test_a_package_whose_file_vanished_from_disk_is_not_sent(): void
    {
        $this->actingAs($this->owner);
        $p = $this->withItems($this->draft());
        $p = DeliveryPackages::ready($p, $this->owner);

        Storage::disk('local')->delete($p->items->firstWhere('kind', 'file')->path);

        try {
            DeliveryPackages::send($p, $this->owner);
            $this->fail('أُرسلت حزمة ملفُّها مفقود');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('بلا ملفٍّ على القرص', $e->getMessage());
        }
        $this->assertSame(DeliveryPackage::READY, $p->fresh()->status);
    }

    public function test_sending_twice_cuts_one_number_and_one_fingerprint(): void
    {
        $this->actingAs($this->owner);
        $p = $this->sent();

        $again = DeliveryPackages::send($p, $this->owner);

        $this->assertSame($p->number, $again->number);
        $this->assertSame($p->content_hash, $again->content_hash);
        $this->assertSame($p->sent_at->toDateTimeString(), $again->sent_at->toDateTimeString());
        $this->assertSame(1, DeliveryPackage::count());
        $this->assertSame(1, ActivityLog::where('subject_type', 'delivery_package')
            ->where('description', 'like', 'أرسل حزمة التسليم%')->count());
    }

    public function test_a_sent_package_is_frozen_and_its_items_cannot_change(): void
    {
        $this->actingAs($this->owner);
        $p = $this->sent();

        $this->assertTrue($p->isFrozen());

        try {
            DeliveryPackages::update($p, ['title' => 'عنوان آخر'], $this->owner);
            $this->fail('عُدّلت حزمة مرسَلة');
        } catch (RuntimeException) {
        }
        try {
            DeliveryPackages::addItem($p, ['kind' => 'item', 'title' => 'بند'], null, $this->owner);
            $this->fail('أُضيف بند إلى حزمة مرسَلة');
        } catch (RuntimeException) {
        }
        try {
            DeliveryPackages::removeItem($p->items->first(), $this->owner);
            $this->fail('حُذف بند من حزمة مرسَلة');
        } catch (RuntimeException) {
        }

        $this->assertSame('تسليم مؤتمر الطاقة', $p->fresh()->title);
        $this->assertCount(2, $p->fresh()->items);
    }

    /* ─────────────────────────── القرار نيابةً ─────────────────────────── */

    public function test_staff_record_the_client_decision_once(): void
    {
        $this->actingAs($this->owner);
        $p = $this->sent();

        $p = DeliveryPackages::recordDecision($p, DeliveryPackage::ACCEPTED, ['decided_by_name' => 'سالم'], $this->owner);

        $this->assertSame(DeliveryPackage::ACCEPTED, $p->status);
        $this->assertNotNull($p->accepted_at);
        $this->assertSame('سالم', $p->decided_by_name);
        $this->assertSame($this->owner->id, $p->decided_by);
        $this->assertNull($p->approval, 'قرارُ موظّفٍ خبرٌ لا دليل');

        $this->expectException(RuntimeException::class);
        DeliveryPackages::recordDecision($p, DeliveryPackage::DECLINED, ['decline_reason' => 'x'], $this->owner);
    }

    public function test_a_decline_needs_a_reason(): void
    {
        $this->actingAs($this->owner);
        $p = $this->sent();

        $this->expectException(RuntimeException::class);
        DeliveryPackages::recordDecision($p, DeliveryPackage::DECLINED, [], $this->owner);
    }

    /* ─────────────────────────── الإلغاء والإعادة ─────────────────────────── */

    public function test_cancellation_is_internal_and_never_touches_a_decided_package(): void
    {
        $this->actingAs($this->owner);
        $p = $this->sent();

        $p = DeliveryPackages::cancel($p, 'أُرسلت خطأً', $this->owner);
        $this->assertSame(DeliveryPackage::CANCELLED, $p->status);
        $this->assertSame('أُرسلت خطأً', $p->cancellation_reason);

        // ومرّةً ثانية تردّ نفسَها
        $this->assertSame(DeliveryPackage::CANCELLED, DeliveryPackages::cancel($p, 'ثانية', $this->owner)->status);

        $accepted = DeliveryPackages::recordDecision($this->sent(), DeliveryPackage::ACCEPTED, [], $this->owner);

        $this->expectException(RuntimeException::class);
        DeliveryPackages::cancel($accepted, 'سبب', $this->owner);
    }

    public function test_after_a_decline_a_new_draft_is_prepared_and_the_declined_one_stays_as_evidence(): void
    {
        $this->actingAs($this->owner);
        $declined = DeliveryPackages::recordDecision($this->sent(), DeliveryPackage::DECLINED, ['decline_reason' => 'التقرير ناقص'], $this->owner);

        $copy = DeliveryPackages::revise($declined, $this->owner);

        $this->assertSame(DeliveryPackage::DRAFT, $copy->status);
        $this->assertSame($declined->id, $copy->supersedes_id);
        $this->assertNull($copy->number);
        $this->assertCount(2, $copy->items);

        $old = $declined->fresh('items');
        $this->assertSame(DeliveryPackage::DECLINED, $old->status);
        $this->assertSame('التقرير ناقص', $old->decline_reason);
        $this->assertSame('DP-000001', $old->number);

        /* والملفُّ مشترك: حذفُه من الجديدة لا يمحو دليلَ القديمة */
        $oldFile = $old->items->firstWhere('kind', 'file');
        $newFile = $copy->items->firstWhere('kind', 'file');
        $this->assertSame($oldFile->path, $newFile->path);
        $this->assertSame($oldFile->checksum, $newFile->checksum);

        DeliveryPackages::removeItem($newFile, $this->owner);
        Storage::disk('local')->assertExists($oldFile->path);

        /* والجديدةُ تُرسَل برقمٍ جديد */
        DeliveryPackages::addItem($copy, ['kind' => 'file', 'title' => 'التقرير المصحَّح'],
            UploadedFile::fake()->create('fixed.pdf', 10, 'application/pdf'), $this->owner);
        $sent = DeliveryPackages::send(DeliveryPackages::ready($copy->fresh(), $this->owner), $this->owner);
        $this->assertSame('DP-000002', $sent->number);

        // لا تُعاد إلا مرفوضة
        $this->expectException(RuntimeException::class);
        DeliveryPackages::revise($sent, $this->owner);
    }

    /* ─────────────────────────── الجاهزيّة ─────────────────────────── */

    public function test_delivery_readiness_reads_the_packages_of_one_scope(): void
    {
        $this->actingAs($this->owner);

        $none = DeliveryReadiness::of(collect());
        $this->assertFalse($none->ready);

        $declined = DeliveryPackages::recordDecision($this->sent(), DeliveryPackage::DECLINED, ['decline_reason' => 'ناقص'], $this->owner);
        $v = DeliveryReadiness::of(DeliveryPackage::all());
        $this->assertFalse($v->ready);
        $this->assertSame(1, $v->unresolvedDeclineCount);

        $copy = DeliveryPackages::revise($declined, $this->owner);
        $v = DeliveryReadiness::of(DeliveryPackage::all());
        $this->assertSame(0, $v->unresolvedDeclineCount, 'رفضٌ حلّت محلَّه حزمةٌ لاحقة عُولج');
        $this->assertFalse($v->ready, 'والجديدةُ ما زالت مسودّة');

        $sent = DeliveryPackages::send(DeliveryPackages::ready($copy, $this->owner), $this->owner);
        $this->assertSame(1, DeliveryReadiness::of(DeliveryPackage::all())->awaitingCount);

        DeliveryPackages::recordDecision($sent, DeliveryPackage::ACCEPTED, [], $this->owner);
        $v = DeliveryReadiness::of(DeliveryPackage::all());
        $this->assertTrue($v->ready, implode(' | ', $v->blockers));
        $this->assertSame(1, $v->acceptedCount);
        $this->assertNotNull($v->lastAcceptedAt);
    }

    /* ─────────────────────────── الأذون والعزل ─────────────────────────── */

    public function test_sensitive_actions_are_guarded_by_named_permissions_server_side(): void
    {
        $this->actingAs($this->owner);
        $p = $this->withItems($this->draft());
        DeliveryPackages::ready($p, $this->owner);

        /* والبائعُ يفتح القسم ويكتب — ولا يُرسل ولا يسجّل قرارًا ولا يُلغي */
        $this->actingAs($this->sales);
        $this->get(route('admin.deliveries.index'))->assertOk();
        $this->get(route('admin.deliveries.show', $p->id))->assertOk();
        $this->post(route('admin.deliveries.send', $p->id))->assertForbidden();
        $this->post(route('admin.deliveries.decision', $p->id), ['decision' => 'accepted'])->assertForbidden();
        $this->post(route('admin.deliveries.cancel', $p->id), ['reason' => 'x'])->assertForbidden();
        $this->assertSame(DeliveryPackage::READY, $p->fresh()->status);

        /* والمحاسبُ لا يفتح البابَ أصلًا */
        $accountant = User::create([
            'business_id' => $this->business->id, 'name' => 'محاسب', 'email' => 'acc@hadath.om',
            'password' => bcrypt('password'), 'role' => 'accountant', 'status' => 'نشط',
        ]);
        $this->actingAs($accountant);
        // 403 هو المطلوب؛ و302 إعادةُ توجيهٍ مقبولةٌ (حارسٌ آخر سبقه) — انظر `EveryRoleIsProbedOnEveryRouteTest`
        $this->assertContains($this->get(route('admin.deliveries.index'))->getStatusCode(), [403, 302]);
        $this->assertContains($this->post(route('admin.deliveries.send', $p->id))->getStatusCode(), [403, 302]);
        $this->assertSame(DeliveryPackage::READY, $p->fresh()->status);

        /* والمالكُ يُرسل */
        $this->actingAs($this->owner);
        $this->post(route('admin.deliveries.send', $p->id))->assertRedirect();
        $this->assertSame(DeliveryPackage::SENT, $p->fresh()->status);
    }

    public function test_a_package_of_another_company_is_not_found(): void
    {
        $this->actingAs($this->owner);
        $p = $this->withItems($this->draft());

        $other = Business::create(['name' => 'شركة أخرى', 'type' => 'عام', 'status' => 'نشط']);
        $stranger = User::create([
            'business_id' => $other->id, 'name' => 'غريب', 'email' => 'x@other.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($stranger);
        $this->get(route('admin.deliveries.show', $p->id))->assertNotFound();
        $this->post(route('admin.deliveries.ready', $p->id))->assertNotFound();
        $this->get(route('admin.deliveries.file', [$p->id, $p->items->firstWhere('kind', 'file')->id]))->assertNotFound();
        $this->delete(route('admin.deliveries.items.destroy', [$p->id, $p->items->first()->id]))->assertNotFound();

        $this->assertCount(2, $p->fresh()->items);
    }

    public function test_the_screens_open_and_the_file_streams_for_the_owner(): void
    {
        $this->actingAs($this->owner);
        $p = $this->withItems($this->draft());
        $file = $p->items->firstWhere('kind', 'file');

        $this->get(route('admin.deliveries.index'))->assertOk();
        $this->get(route('admin.deliveries.create'))->assertOk();
        $this->get(route('admin.deliveries.show', $p->id))->assertOk();
        $this->get(route('admin.deliveries.file', [$p->id, $file->id]))->assertOk()
            ->assertHeader('content-disposition', 'inline');

        /* والإضافةُ من الشاشة بملفٍّ خارج القائمة تُردّ */
        $this->post(route('admin.deliveries.items.store', $p->id), [
            'kind' => 'file', 'title' => 'سكربت', 'file' => UploadedFile::fake()->create('evil.exe', 10),
        ])->assertSessionHasErrors('file');
        $this->assertCount(2, $p->fresh()->items);

        $this->post(route('admin.deliveries.store'), ['title' => 'حزمة ثانية', 'customer_id' => $this->customer->id])
            ->assertRedirect(route('admin.deliveries.show', 2));
    }
}
