<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\EventOperation;
use App\Models\JournalLine;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Support\DemoStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الشركةُ التجريبيّة تُبنى كاملةً — وتُمحى بلا أثر.
 *
 * كلُّ قسمٍ فيها ممتلئ من أبواب النظام نفسِها، والدفترُ متوازن. ومحوُها
 * يترك الشركةَ الحقيقيّة كما كانت صفًّا صفًّا.
 */
class TheDemoCompanyIsBuiltWholeAndLeavesNoTraceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_company_fills_every_section_and_its_removal_leaves_the_real_one_untouched(): void
    {
        app()->setLocale('ar');

        $real = Business::create(['name' => 'Event Plus', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $real->id, 'name' => 'الرئيسي']);
        User::create(['business_id' => $real->id, 'name' => 'المالك', 'email' => 'owner@hadath.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);
        Customer::create(['business_id' => $real->id, 'name' => 'عميلٌ حقيقيّ']);

        $count = fn () => collect(['customers', 'users', 'branches'])->mapWithKeys(fn ($t) => [$t => DB::table($t)->where('business_id', $real->id)->count()])->all();
        $before = $count();

        $this->artisan('demo:create', ['--size' => 'صغير'])->assertSuccessful();

        $demo = Business::demo()->sole();
        $bid = $demo->id;

        $this->assertGreaterThan(5, Quotation::where('business_id', $bid)->count());
        $this->assertGreaterThan(3, Project::where('business_id', $bid)->count());
        $this->assertGreaterThan(3, EventOperation::where('business_id', $bid)->count());
        $this->assertGreaterThan(3, CustomerInvoice::where('business_id', $bid)->count());
        $this->assertGreaterThan(2, WhatsAppConversation::where('business_id', $bid)->count());
        $this->assertSame(0, Quotation::where('business_id', $real->id)->count());

        /* والدفترُ متوازن: مدينُه دائنُه */
        $lines = JournalLine::whereIn('journal_entry_id', DB::table('journal_entries')->where('business_id', $bid)->pluck('id'));
        $this->assertEqualsWithDelta((float) (clone $lines)->sum('debit'), (float) (clone $lines)->sum('credit'), 0.01);

        $this->artisan('demo:destroy', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, Business::demo()->count());
        $this->assertSame($before, $count());
        $this->assertSame(0, WhatsAppConversation::where('business_id', $bid)->count());
    }
}
