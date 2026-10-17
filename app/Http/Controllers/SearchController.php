<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Expense;
use App\Models\GoodsReceiptNote;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\Transaction;
use App\Models\User;
use App\Support\BranchAccess;
use App\Support\Demo;
use App\Support\Permissions;
use App\Support\Quotations;
use App\Support\Search;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * البحث العام الموحّد للشريط العلوي (JSON، مجمّع حسب النوع).
 */
class SearchController extends Controller
{
    public function admin(Request $request)
    {
        $q = Search::term($request);
        if (mb_strlen($q) < 2) {
            return response()->json(['groups' => []]);
        }
        $user = auth()->user();
        $bid = $user->business_id ?? Demo::bid();
        $like = "%{$q}%";
        $op = Search::like();

        /*
         * البحث لا يتجاوز صلاحيات صاحبه.
         *
         * المسار نفسه مفتوح لكل من دخل اللوحة — هو أداةٌ في الشريط العلوي لا
         * قسم. لكن نتائجه تقود إلى ثلاثة أقسام، فمن لا يملك «العملاء» كان
         * يقرأ أسماءهم وأرقامهم من مربّع البحث ثم يصطدم بـ403 عند الضغط:
         * البيانات وصلته قبل الباب المغلق.
         */
        $products = $user->allows('products') ? Product::where('business_id', $bid)
            ->where(fn ($w) => $w->where('name', $op, $like)->orWhere('sku', $op, $like))
            ->limit(5)->get()->map(fn ($p) => [
                'label' => $p->name, 'meta' => $p->sku ?: '—',
                'url' => route('admin.products.show', $p->id),
            ]) : collect();

        /*
         * والطلباتُ القديمة لا تُبحث: خرجت «المبيعات» من النظام ولا صفحةَ لها
         * تُفتح، فنتيجةٌ تقود إليها رابطٌ ميّت.
         *
         * وما يحلّ محلّها ما تعمل عليه الشركة: المشاريعُ بكودها واسمها، وعروضُ
         * الأسعار برقمها وعنوانها، وفواتيرُ العملاء برقمها واسمِ عميلها — كلٌّ
         * لمن يفتح قسمَه. والفاتورةُ تُفتح من «المالية»، ولمن قُيّد بفروعٍ
         * فواتيرُ فروعه وحدها كما في شاشتها (`BranchAccess::invoices`).
         */
        $projects = $user->allows('projects') ? Project::where('business_id', $bid)
            ->where(fn ($w) => $w->where('code', $op, $like)->orWhere('name', $op, $like))
            ->with('customer:id,name')->orderByDesc('id')->limit(5)->get()->map(fn ($p) => [
                'label' => $p->code.' · '.$p->name, 'meta' => $p->customer?->name ?? '—',
                'url' => route('admin.projects.show', $p->id),
            ]) : collect();

        $quotations = $user->allows('quotations') ? Quotation::where('business_id', $bid)
            ->whereNotNull('number')
            ->where(fn ($w) => $w->where('number', $op, $like)->orWhere('title', $op, $like))
            ->with('customer:id,name')->orderByDesc('id')->limit(5)->get()->map(fn ($qt) => [
                'label' => Quotations::label($qt), 'meta' => $qt->title ?: ($qt->customer?->name ?? '—'),
                'url' => route('admin.quotations.show', $qt->id),
            ]) : collect();

        $invoices = $user->allows('finance') ? BranchAccess::invoices(CustomerInvoice::where('business_id', $bid))
            ->whereNotNull('number')
            ->where(fn ($w) => $w->where('number', $op, $like)
                ->orWhereHas('customer', fn ($c) => $c->where('name', $op, $like)))
            ->with('customer:id,name')->orderByDesc('id')->limit(5)->get()->map(fn ($i) => [
                'label' => $i->number, 'meta' => $i->customer?->name ?? '—',
                'url' => route('admin.customerInvoices.show', $i->id),
            ]) : collect();

        $customers = $user->allows('customers') ? Customer::where('business_id', $bid)
            ->where(fn ($w) => $w->where('name', $op, $like)->orWhere('phone', $op, $like)
                /* وباسم شخص الاتصال أو هاتفه — الجهةُ تُعرف بمن يكلّمنا منها (المرحلة 14) */
                ->orWhereHas('contacts', fn ($c) => $c->where('name', $op, $like)->orWhere('phone', $op, $like)))
            ->limit(5)->get()->map(fn ($c) => [
                'label' => $c->name, 'meta' => $c->phone ?: '—',
                'url' => route('admin.customers.show', $c->id),
            ]) : collect();

        /*
         * والمورّدون كذلك: البحث كان يعرف من نبيع له ولا يعرف ممّن نشتري.
         *
         * ولكلّ مورّدٍ صفحتُه منذ المرحلة 14 — بياناتُه وخدماتُه وأوامرُه
         * وسنداتُه — فالوجهةُ صفحتُه.
         */
        $suppliers = $user->allows('suppliers') ? Supplier::where('business_id', $bid)
            ->where(fn ($w) => $w->where('name', $op, $like)
                ->orWhere('phone', $op, $like)
                ->orWhere('contact_person', $op, $like)
                // وبما يقدّمه — «تصوير» يجد استوديو التصوير
                ->orWhere('service', $op, $like))
            ->limit(5)->get()->map(fn ($s) => [
                'label' => $s->name, 'meta' => $s->service ?: ($s->phone ?: ($s->contact_person ?: '—')),
                'url' => route('admin.suppliers.show', $s->id),
            ]) : collect();

        return response()->json(['groups' => array_values(array_filter([
            $this->group(__('المنتجات'), 'package', $products),
            $this->group(__('المشاريع'), 'briefcase', $projects),
            $this->group(__('العملاء'), 'users', $customers),
            $this->group(__('عروض الأسعار'), 'file-text', $quotations),
            $this->group(__('فواتير العملاء'), 'receipt', $invoices),
            $this->group(__('الموردين'), 'truck', $suppliers),
            $this->group(__('السندات والمعاملات'), 'receipt', $this->documents($user, $bid, $q, $op)),
        ]))]);
    }

    /**
     * السندات تُطلب برموزها — لا بأسمائها.
     *
     * التاجر يمسك ورقةً في يده: سندَ استلامٍ من المورّد، أو قيدًا يسأل عنه
     * محاسبُه، أو مصروفًا يريد إيصاله. وكان الشريط يعرف المنتجات والعملاء
     * ولا يعرف الأوراق — فمن معه «GRN-000042» لا باب له إلا أن يفتح
     * المخزون، ثمّ تبويب الإشعارات، ثمّ يبحث فيها. ثلاث نقراتٍ ليجد ما
     * رمزُه في يده.
     *
     * والوجهة تُرشَّح بالرمز نفسه لا تُفتح على أوّل القائمة: رابطٌ يُنزلك في
     * صفحةٍ فيها ستّون سندًا ويتركك تبحث ليس رابطًا. ولذلك لا يُدرَج هنا إلا
     * ما لصفحته مُرشِّحٌ يقرأ `q` فعلًا — سندُ التسليم وسندُ نقل المخزون
     * خارجَ القائمة لأنّ لا شاشة تعرضهما بعد، ورمزٌ يُوجَد ولا يُفتح أسوأ
     * من رمزٍ لا يُوجَد.
     *
     * ولا يُسأل عن الأوراق إلا حين يحتمل الطلبُ أن يكون رمزًا — أي حين يحمل
     * رقمًا. الرموز كلّها بادئةٌ ورقم، فالبحث عن «أحمد» لا يستحقّ سبعة
     * استعلامات على سبعة جداول لا يمكن أن يحمل أيٌّ منها «أحمد» في خانة
     * رمزه. وهذا يجري مع كل ضغطة حرفٍ في الشريط.
     */
    private function documents(User $user, int $bid, string $q, string $op): Collection
    {
        if (! preg_match('/\d/', $q)) {
            return collect();
        }

        $like = "%{$q}%";
        $rows = collect();

        /*
         * سبعةٌ في سقفٍ واحد لا سقفٌ لكلّ نوع.
         *
         * سبعة أنواعٍ بخمسة صفوفٍ لكلٍّ تعني خمسةً وثلاثين صفًّا في قائمةٍ
         * منسدلة — ومن كتب رمزًا كاملًا يريد صفًّا واحدًا لا خمسةً وثلاثين.
         */
        $rows = $rows->concat($user->allows('purchases')
            ? BranchAccess::limit(PurchaseOrder::where('business_id', $bid))->where('number', $op, $like)
                ->orderByDesc('id')->limit(3)->get()->map(fn ($p) => [
                    'label' => $p->number,
                    'meta' => __('أمر شراء'),
                    'url' => route('admin.purchases.orders', ['q' => $p->number]),
                ])
            : []);

        $rows = $rows->concat($user->allows('purchases')
            ? SupplierInvoice::where('business_id', $bid)->where('supplier_ref', $op, $like)
                ->orderByDesc('id')->limit(3)->get()->map(fn ($i) => [
                    'label' => $i->supplier_ref,
                    'meta' => __('سند مورّد'),
                    'url' => route('admin.purchases.invoices', ['q' => $i->supplier_ref]),
                ])
            : []);

        // والسندُ يُفتح في صفحته — وهي تُحرَس بفعل القراءة لا بالقسم وحده
        $rows = $rows->concat($user->allows('inventory') && $user->may(Permissions::RECEIPT_VIEW)
            ? BranchAccess::limit(GoodsReceiptNote::where('business_id', $bid))->where('number', $op, $like)
                ->orderByDesc('id')->limit(3)->get()->map(fn ($g) => [
                    'label' => $g->number,
                    'meta' => __('إشعار استلام'),
                    'url' => route('admin.inventory.receipts.show', $g->id),
                ])
            : []);

        /*
         * والتسويةُ لا تُبحث: «سجل المخزون» الذي كانت تُفتح فيه خرج من الواجهة،
         * ورقمٌ يُعرض ولا يقود إلى شيء أسوأ من غيابه. وصفوفُها باقية.
         */

        $rows = $rows->concat($user->allows('finance')
            ? JournalEntry::where('business_id', $bid)->where('number', $op, $like)
                ->orderByDesc('id')->limit(3)->get()->map(fn ($j) => [
                    'label' => $j->number,
                    'meta' => __('قيد يومية'),
                    'url' => route('admin.finance.journal', ['q' => $j->number]),
                ])
            : []);

        /*
         * والمصروف يُفتح على كلّ الشهور لا على الشهر الجاري.
         *
         * شاشة المصروفات تبدأ من شهرها، فرابطٌ برمزٍ من مايو يقع على أبريل
         * ولا يعرض شيئًا. و`month=` فارغةً تعني «كلّ الشهور» — انظر
         * `ListFilters::expenseSpan`.
         */
        $rows = $rows->concat($user->allows('expenses')
            ? Expense::where('business_id', $bid)->where('reference', $op, $like)
                ->orderByDesc('id')->limit(3)->get()->map(fn ($e) => [
                    'label' => $e->reference,
                    'meta' => __('مصروف'),
                    'url' => route('admin.expenses.index', ['month' => '', 'q' => $e->reference]),
                ])
            : []);

        /*
         * ومعاملةُ البيع لا تُعرض مرّتين.
         *
         * نقطة البيع تكتب لكل فاتورةٍ معاملةَ دخلٍ مرجعُها رقمُ الفاتورة
         * نفسه، فالبحث عن «INV-000012» كان سيردّ صفَّين برمزٍ واحد: الفاتورة
         * ومعاملتَها. والفاتورة أنفعُ الوجهتين — فيها الأصناف والعميل وزرّ
         * الطباعة — فتُترك المعاملة لصاحب الحركة اليدوية وحده.
         */
        $rows = $rows->concat($user->allows('finance')
            ? Transaction::where('business_id', $bid)->whereNull('order_id')
                ->where('reference', $op, $like)
                ->orderByDesc('id')->limit(3)->get()->map(fn ($t) => [
                    'label' => $t->reference,
                    'meta' => $t->type ?: __('معاملة'),
                    'url' => route('admin.reports.finance', ['range' => 'all', 'q' => $t->reference]),
                ])
            : []);

        return $rows->take(7)->values();
    }

    private function group(string $title, string $icon, $items): ?array
    {
        return $items->isEmpty() ? null : ['title' => $title, 'icon' => $icon, 'items' => $items->all()];
    }
}
