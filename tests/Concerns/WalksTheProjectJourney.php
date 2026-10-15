<?php

namespace Tests\Concerns;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\DeliveryPackage;
use App\Models\EventOperation;
use App\Models\JobTitle;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\ScopeOfWork;
use App\Models\Setting;
use App\Models\User;
use App\Support\DeliveryPackages;
use App\Support\Ledger;
use App\Support\Operations\EventOperations;
use App\Support\Quotations;
use App\Support\ScopesOfWork;
use Illuminate\Http\UploadedFile;

/**
 * رحلةُ المشروع من أبوابها — لمن يحتاج مشروعًا بلغ مرحلةً بعينها.
 *
 * كلُّ خطوةٍ تمرّ بالباب الذي تمرّ به الشاشة: العرضُ يُقبل فيصير مشروعًا،
 * والنطاقُ يُكتب ويُرسَل ويوقّعه العميل، والتسليمُ يُقبل. والاستثناءُ الوحيد
 * إتمامُ الفعاليّة: سلسلةُ جاهزيّتها (فريقٌ وقوائمُ وتجاوزات) اختبارُها في
 * `tests/Feature/Operations`، وهنا تُكتب «مكتملة» تحضيرًا لا اختبارًا.
 */
trait WalksTheProjectJourney
{
    protected Business $business;

    protected User $owner;

    protected Customer $customer;

    /** شركةٌ بضريبة ٥٪ ودفترٍ مبذور ومالكٍ وعميل */
    protected function journeyCompany(string $name = 'افنت بلس'): void
    {
        $this->business = Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط', 'email' => 'hello@eventplus.om']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);
        Currency::create(['business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);
        Ledger::seedChart((int) $this->business->id);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner-'.$this->business->id.'@eventplus.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'وزارة الطاقة', 'legal_name' => 'وزارة الطاقة والمعادن',
            'phone' => '+96891234567', 'email' => 'events@energy.gov.om',
        ]);
    }

    /**
     * مشروعٌ من عرضٍ مقبول — بندٌ واحد: كميّةٌ × سعر، والقيمةُ بضريبتها.
     *
     * عشرةٌ في ٩٥٢٫٣٨١ = ٩٥٢٣٫٨١ صافيًا و٩٥٢٣٫٨١ × ١٫٠٥ ≈ ١٠٠٠٠ إجماليًّا.
     */
    protected function acceptedProject(float $unitPrice = 1000.0, float $quantity = 10, ?Customer $customer = null): Project
    {
        $this->actingAs($this->owner);

        $quotation = Quotations::create((int) $this->business->id, $customer ?? $this->customer, [
            'title' => 'مؤتمر الطاقة', 'issued_at' => now()->toDateString(), 'valid_until' => now()->addDays(30)->toDateString(),
            'event_starts_at' => now()->addDays(20)->toDateString(), 'event_ends_at' => now()->addDays(21)->toDateString(),
            'event_location' => 'مركز عُمان للمؤتمرات', 'attention_to' => 'سالم الهنائي', 'contact_phone' => '+96891234567',
            'contact_email' => 'salem@energy.gov.om', 'scope' => 'تشغيل نظام التسجيل والبطاقات طوال أيام المؤتمر.',
        ], [
            ['description' => 'تشغيل نظام تسجيل', 'quantity' => $quantity, 'unit_price' => $unitPrice, 'unit' => 'يوم'],
        ], $this->owner);
        Quotations::send($quotation, $this->owner);
        Quotations::recordDecision($quotation->fresh(), Quotation::ACCEPTED, ['decided_by_name' => 'سالم الهنائي'], $this->owner);

        return Project::where('quotation_id', $quotation->id)->firstOrFail();
    }

    /** نسخةٌ خرجت إلى العميل وتنتظر توقيعه */
    protected function sentScope(Project $project): ScopeOfWork
    {
        $this->actingAs($this->owner);

        $scope = ScopesOfWork::start($project->fresh(), $this->owner);
        $scope = ScopesOfWork::update($scope, [
            'description' => 'تشغيل نظام التسجيل في يومي المؤتمر.',
            'inclusions' => "أجهزة التسجيل\nطباعة البطاقات",
            'exclusions' => 'الإنترنت في القاعة',
            'company_responsibilities' => 'فريقٌ فنيّ طوال الفعالية',
            'client_responsibilities' => 'قائمة المدعوين قبل أسبوع',
        ], $this->owner);
        $scope = ScopesOfWork::markReady($scope, $this->owner);

        return ScopesOfWork::send($scope, $this->owner);
    }

    /** نطاقٌ وقّعه العميل — من الباب الذي يوقّع منه رابطُه */
    protected function signedScope(Project $project): ScopeOfWork
    {
        $scope = $this->sentScope($project);

        return ScopesOfWork::decide($scope, 'accepted', [
            'name' => 'سالم الهنائي', 'title' => 'مدير الفعاليات', 'consent' => ScopesOfWork::consent((string) $this->business->name),
        ], ['ip' => '10.0.0.7', 'user_agent' => 'PHPUnit'], $scope->content_hash);
    }

    /** فعاليّةٌ مكتملة — تحضيرٌ لا اختبار (انظر رأسَ هذا الملفّ) */
    protected function completedOperation(Project $project): EventOperation
    {
        $this->actingAs($this->owner);

        $operation = EventOperations::create($project->fresh(), [
            'name' => 'تشغيل المؤتمر', 'venue_name' => 'القاعة الكبرى',
            'event_starts_at' => now()->addDays(20)->setTime(9, 0)->format('Y-m-d H:i'),
            'event_ends_at' => now()->addDays(20)->setTime(17, 0)->format('Y-m-d H:i'),
        ], $this->owner);
        $operation->forceFill(['status' => EventOperation::COMPLETED, 'completed_at' => now()])->save();

        return $operation->fresh();
    }

    /** تسليمٌ قَبِله العميل — بنودُ المشروع كاملةً */
    protected function acceptedDelivery(Project $project): DeliveryPackage
    {
        $this->actingAs($this->owner);

        $package = DeliveryPackages::create((int) $this->business->id, null, ['project_id' => $project->id], $this->owner);
        foreach ($project->items()->get() as $item) {
            DeliveryPackages::addItem($package, ['kind' => 'item', 'project_item_id' => $item->id, 'quantity' => (float) $item->quantity], null, $this->owner);
        }
        DeliveryPackages::addItem($package, ['kind' => 'file', 'title' => 'تقرير'], UploadedFile::fake()->create('report.pdf', 5, 'application/pdf'), $this->owner);
        $package = DeliveryPackages::send(DeliveryPackages::ready($package->fresh(), $this->owner), $this->owner);

        return DeliveryPackages::recordDecision($package->fresh(), DeliveryPackage::ACCEPTED, ['decided_by_name' => 'سالم الهنائي'], $this->owner);
    }
}
