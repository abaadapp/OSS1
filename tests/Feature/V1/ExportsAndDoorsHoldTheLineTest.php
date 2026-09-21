<?php

namespace Tests\Feature\V1;

use App\Models\Budget;
use App\Models\Customer;
use App\Models\FinancialForecast;
use App\Support\Intelligence\Budgeting;
use App\Support\Intelligence\Forecasting;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Management\ManagementTestCase;

/**
 * التصديرُ لا يُنفّذ معادلة، والمحاسبُ يقرأ ولا يعتمد، وصحّةُ النظام لا تُفشي شيئًا — المرحلة 12 (§33 · §50 · §71).
 */
class ExportsAndDoorsHoldTheLineTest extends ManagementTestCase
{
    use RefreshDatabase;

    private const POISON = '=HYPERLINK("http://evil.example/x","اضغط")';

    public function test_a_customer_named_like_a_formula_is_exported_as_text_in_csv_and_xlsx(): void
    {
        Customer::create(['business_id' => $this->business->id, 'name' => self::POISON, 'phone' => '91111111']);
        Customer::create(['business_id' => $this->business->id, 'name' => '+966 شركة', 'phone' => '92222222']);
        Customer::create(['business_id' => $this->business->id, 'name' => '-5 درجات', 'phone' => '93333333']);
        $this->invoice(100, '2026-09-10', null, Customer::where('name', self::POISON)->first());

        $csv = $this->actingAs($this->owner)->get(route('admin.export.customers'));
        $csv->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringContainsString('"\'=HYPERLINK(""http', $body, 'المعادلةُ تُسبق بفاصلةٍ عليا فتُقرأ نصًّا');
        $this->assertStringNotContainsString(',=HYPERLINK', $body, 'ولا تظهر عاريةً في أوّل خليّة');
        $this->assertStringNotContainsString(',"=HYPERLINK', $body);
        $this->assertStringContainsString("'+966", $body);
        $this->assertStringContainsString("'-5", $body);

        $report = $this->actingAs($this->owner)->get(route('admin.reports.export.csv', ['report' => 'customer_revenue', 'period' => 'this_month']));
        $report->assertOk();
        $this->assertStringContainsString('"\'=HYPERLINK(""http', $report->streamedContent());

        $xlsx = $this->actingAs($this->owner)->get(route('admin.reports.export.xlsx', ['report' => 'customer_revenue', 'period' => 'this_month']));
        $xlsx->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'ep').'.xlsx';
        file_put_contents($path, $xlsx->streamedContent());
        $sheet = IOFactory::load($path);
        $found = false;
        foreach ($sheet->getAllSheets() as $ws) {
            foreach ($ws->getRowIterator() as $row) {
                foreach ($row->getCellIterator() as $cell) {
                    if ((string) $cell->getValue() === self::POISON) {
                        $found = true;
                        $this->assertSame('s', $cell->getDataType(), 'الخليّةُ نصٌّ لا معادلة');
                    }
                    $this->assertNotSame('f', $cell->getDataType(), 'لا خليّةَ معادلةٍ في تصديرٍ');
                }
            }
        }
        @unlink($path);
        $this->assertTrue($found, 'العميلُ في الملفّ');
    }

    public function test_the_accountant_reads_management_and_can_change_nothing_through_it(): void
    {
        $bid = (int) $this->business->id;
        $budget = Budgeting::create($bid, ['fiscal_year' => 2026, 'lines' => [['account_id' => Ledger::account($bid, 'sales')->id, 'month' => 1, 'planned_amount' => 10]]], $this->manager);
        $forecast = Forecasting::create($bid, ['horizon_days' => 30], $this->manager);
        $acc = $this->accountant;

        foreach (['admin.management.index', 'admin.management.financial', 'admin.management.projects', 'admin.management.customers', 'admin.management.cash', 'admin.management.budgets', 'admin.management.reviews', 'admin.management.reports'] as $screen) {
            $this->actingAs($acc)->get(route($screen))->assertOk();
        }

        $writes = [
            ['post', route('admin.management.budgets.store'), ['fiscal_year' => 2027]],
            ['put', route('admin.management.budgets.update', $budget->id), ['name' => 'x']],
            ['post', route('admin.management.budgets.approve', $budget->id), []],
            ['post', route('admin.management.budgets.revise', $budget->id), ['reason' => 'x']],
            ['post', route('admin.management.forecasts.store'), ['horizon_days' => 30]],
            ['post', route('admin.management.forecasts.approve', $forecast->id), []],
            ['delete', route('admin.management.forecasts.destroy', $forecast->id), []],
            ['post', route('admin.management.scenarios.store'), ['name' => 'x']],
            ['post', route('admin.management.reviews.store'), ['period_start' => '2026-08-01', 'period_end' => '2026-08-31']],
            ['put', route('admin.management.thresholds'), ['thresholds' => ['project_margin_percent' => 1]]],
            ['post', route('admin.automation.settings'), ['mode' => 'live']],
        ];
        foreach ($writes as [$verb, $url, $data]) {
            $status = $this->actingAs($acc)->{$verb}($url, $data)->getStatusCode();
            $this->assertSame(403, $status, "$verb $url → $status");
        }
        /* ولا يوظّف من يفوقه: مالكٌ جديد بيد المحاسب يُردّ */
        $this->actingAs($acc)->post(route('admin.employees.store'), ['name' => 'x', 'email' => 'x@x.om', 'password' => 'password1234', 'role' => 'admin', 'job_title_id' => \App\Models\JobTitle::where('business_id', $bid)->value('id')]);
        $this->assertSame(0, \App\Models\User::where('business_id', $bid)->where('email', 'x@x.om')->count(), 'المحاسبُ وظّف مالكًا');
        $this->assertSame(Budget::DRAFT, $budget->fresh()->status);
        $this->assertSame(FinancialForecast::DRAFT, $forecast->fresh()->status);
        $this->assertNotNull(FinancialForecast::find($forecast->id));
    }

    public function test_the_public_health_endpoint_answers_yes_or_no_and_nothing_else(): void
    {
        $response = $this->get('/health');
        $response->assertOk();
        $json = $response->json();
        $this->assertSame(['ok', 'db', 'storage', 'cache'], array_keys($json));
        $this->assertTrue($json['ok']);
        foreach (['version', 'env', 'database', 'host', 'key', 'path'] as $secret) {
            $this->assertArrayNotHasKey($secret, $json);
        }
    }
}
