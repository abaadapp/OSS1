<?php

namespace App\Support\Exports;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Support\BranchAccess;
use App\Support\Demo;
use App\Support\Search;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * أوامر الشراء — بتاريخ الأمر (`ordered_at`) وبحاله ومورّده.
 *
 * أمرُ الشراء التزامٌ لا مصروفٌ محقَّق: قيمتُه ما طُلب، والمحقَّقُ في سند
 * المورّد بعد اعتماده. فالملفّ لا يخلطهما — عمودُ «الاستلام» يقول ما وصل.
 * ولمن قُيّد بفروعٍ أوامرُ فروعه وحدها (`BranchAccess::limit`) كما في الشاشة.
 */
final class PurchaseOrdersDataset extends Dataset
{
    public static function slug(): string
    {
        return 'purchase-orders';
    }

    public function title(): string
    {
        return __('أوامر الشراء');
    }

    /** الأوامرُ المرشَّحة — تقرؤها الشاشةُ (`PurchaseOrderController::index`) والملفُّ معًا */
    public static function query(Request $request, int $bid): Builder
    {
        return BranchAccess::limit(PurchaseOrder::where('business_id', $bid))
            ->when(Search::term($request) ?: null, fn ($q, $term) => self::search($q, $term))
            ->when(self::param($request, 'status'), fn ($q, $s) => $q->where('status', $s))
            ->when(self::param($request, 'supplier_id'), fn ($q, $s) => $q->where('supplier_id', (int) $s))
            ->when(self::day($request, 'from'), fn ($q, $d) => $q->whereDate('ordered_at', '>=', $d))
            ->when(self::day($request, 'to'), fn ($q, $d) => $q->whereDate('ordered_at', '<=', $d));
    }

    /**
     * البحثُ كما كان يبحث جدولُ الشاشة في المتصفّح: رقمُ الأمر، والمورّدُ كما
     * يُعرض (الاسمُ المكتوب على الأمر وإلّا اسمُ المورّد)، والفرع.
     *
     * كان البحثُ محلّيًّا في `DataTable` لا يعرفه الخادم: يكتب التاجرُ «الخليج»
     * فيرى ثلاثة أوامر، ويصدّر فيخرج الملفُّ بالأوامر كلّها.
     */
    private static function search(Builder $query, string $term): Builder
    {
        $like = Search::like();
        $text = '%'.$term.'%';

        return $query->where(fn ($w) => $w
            ->where('number', $like, $text)
            ->orWhere('supplier_name', $like, $text)
            ->orWhere(fn ($x) => $x
                ->where(fn ($none) => $none->whereNull('supplier_name')->orWhere('supplier_name', ''))
                ->whereHas('supplier', fn ($supplier) => $supplier->where('name', $like, $text)))
            ->orWhereHas('branch', fn ($branch) => $branch->where('name', $like, $text)));
    }

    public function columns(Request $request): array
    {
        return [
            self::col('ordered', 'التاريخ'), self::col('number', 'رقم الأمر'), self::col('supplier', 'المورّد'),
            self::col('project', 'المشروع'), self::col('branch', 'الفرع'), self::col('status', 'الحالة'),
            self::col('received', 'الاستلام'), self::col('total', 'القيمة', 'money'),
        ];
    }

    public function data(Request $request, int $bid): array
    {
        $query = self::query($request, $bid)->with(['supplier:id,name', 'project:id,code,name', 'branch:id,name'])
            ->orderByDesc('ordered_at')->orderByDesc('id');

        return $this->fromQuery($query, fn (PurchaseOrder $p) => [
            'ordered' => optional($p->ordered_at)->format('Y-m-d'),
            'number' => $p->number,
            'supplier' => $p->supplier_name ?? $p->supplier?->name,
            'project' => $p->project ? $p->project->code.' — '.$p->project->name : null,
            'branch' => $p->branch?->name,
            'status' => $p->status,
            'received' => optional($p->received_at)->format('Y-m-d'),
            'total' => round((float) $p->total, 3),
        ]) + ['totals' => ['total' => round((float) (clone $query)->reorder()->sum('total'), 3)]];
    }

    public function periodLabel(Request $request): string
    {
        return __('تاريخ الأمر').': '.parent::periodLabel($request);
    }

    public function filterLines(Request $request): array
    {
        $bid = (int) (auth()->user()->business_id ?? Demo::bid());

        return array_values(array_filter([
            ($q = Search::term($request)) !== '' ? __('البحث').': '.$q : null,
            ($s = self::param($request, 'status')) ? __('الحالة').': '.$s : null,
            ($sp = self::param($request, 'supplier_id')) ? __('المورّد').': '.(Supplier::where('business_id', $bid)->whereKey((int) $sp)->value('name') ?? $sp) : null,
        ]));
    }
}
