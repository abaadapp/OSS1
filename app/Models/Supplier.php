<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * المورّد — منتجاتٍ أو خدماتٍ أو كليهما.
 *
 * مقدّمُ الخدمة مورّدٌ لا عالمٌ ماليٌّ ثانٍ: أوامرُ شرائه وسنداتُه وذممُه
 * هي نفسُها، ويزيد عليه كتالوجُ ما يقدّمه (`ServiceCategory`) يُبحث به.
 */
class Supplier extends Model
{
    public const PRODUCTS = 'products';

    public const SERVICES = 'services';

    public const BOTH = 'both';

    /** @var list<string> */
    public const TYPES = [self::PRODUCTS, self::SERVICES, self::BOTH];

    /** @var list<string> */
    public const STATUSES = ['active', 'inactive'];

    protected $guarded = [];

    public function serviceCategories(): BelongsToMany
    {
        return $this->belongsToMany(ServiceCategory::class, 'service_category_supplier')->withPivot('business_id')->orderBy('name');
    }

    public function providesServices(): bool
    {
        return in_array($this->supplier_type, [self::SERVICES, self::BOTH], true);
    }

    public function purchaseOrders(): HasMany { return $this->hasMany(PurchaseOrder::class); }

    public function invoices(): HasMany { return $this->hasMany(SupplierInvoice::class); }

    /**
     * ما له علينا الآن — من سنداته **المعتمَدة** وحدها.
     *
     * والمرفوضُ والملغى والمعلَّق ليست دَينًا: التعريفُ واحدٌ في
     * `SupplierInvoice::scopeOwed`، ولا يُعاد كتابتُه هنا فيفترق عنه.
     */
    public function outstanding(): float
    {
        return round((float) $this->invoices()->owed()->sum('total')
            - (float) $this->invoices()->owed()->sum('paid'), 3);
    }
}
