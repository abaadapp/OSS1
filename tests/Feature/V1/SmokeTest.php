<?php

namespace Tests\Feature\V1;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * دخّانُ الأعمال — يُشغَّل بعد النشر على قاعدةٍ في الذاكرة لا على بيانات الإنتاج (المرحلة 12 §74).
 *
 *   php artisan test --group smoke
 *
 * لا يكتب في قاعدة الإنتاج شيئًا: RefreshDatabase على SQLite في الذاكرة.
 */
#[Group('smoke')]
class SmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_public_surface_answers_without_leaking(): void
    {
        $this->get('/health')->assertOk()->assertJson(['ok' => true]);
        $this->get('/login')->assertOk();
        $this->get('/admin/dashboard')->assertRedirect();
        $this->get('/i/'.bin2hex(random_bytes(11)))->assertNotFound();
        $this->get('/d/'.bin2hex(random_bytes(11)))->assertNotFound();
        $this->post('/webhooks/postmark', [])->assertStatus(404);
        $this->post('/webhooks/whatsapp', [])->assertStatus(404);
    }

    public function test_a_fresh_company_can_log_in_and_open_its_first_screens(): void
    {
        $b = Business::create(['name' => 'دخّان', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $b->id, 'name' => 'مدير', 'role' => 'admin']);
        $owner = User::create(['business_id' => $b->id, 'name' => 'المالك', 'email' => 'smoke@eventplus.om', 'password' => bcrypt('Smoke-Pass-1234'), 'role' => 'admin', 'status' => 'نشط']);

        $this->post('/login', ['email' => 'smoke@eventplus.om', 'password' => 'Smoke-Pass-1234'])->assertRedirect();
        $this->assertAuthenticatedAs($owner);
        foreach (['admin.dashboard', 'admin.command.index', 'admin.customers.index', 'admin.quotations.index', 'admin.projects.index', 'admin.customerInvoices.index', 'admin.finance.index', 'admin.management.index', 'admin.settings.index'] as $screen) {
            $this->get(route($screen))->assertOk();
        }
        $this->post('/logout')->assertRedirect();
        $this->assertGuest();
    }
}
