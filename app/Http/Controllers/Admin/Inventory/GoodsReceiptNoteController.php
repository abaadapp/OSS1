<?php

namespace App\Http\Controllers\Admin\Inventory;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceiptNote;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Support\BranchAccess;
use App\Support\Demo;
use App\Support\Document\PaperSize;
use App\Support\DocumentPaper;
use App\Support\DocumentRenderer;
use App\Support\GoodsReceipts;
use App\Support\Permissions;
use Inertia\Inertia;
use Inertia\Response;

/**
 * إشعار استلام بضاعة — ورقةُ ما دخل المخزن ومن استلمه.
 *
 * توأمُ إشعار التسليم بالاتجاه المعاكس، وقراءةٌ فقط: هذه الأوراق لا تُكتب
 * بيدٍ ولا تُحذف — تُنشئها لحظةُ استلام أمر الشراء
 * (`PurchaseOrderController::receive`) شاهدةً على واقعةٍ جرت. ونموذجٌ يُنشئ
 * إشعارًا بلا استلامٍ يجعل الورقة تقول ما لم يقله المخزون.
 *
 * ولا تمسّ المخزون من هنا: `GoodsReceipts::approve` هي التي تُدخل الكمية،
 * من زرّ الاعتماد في صفحة السند. وقائمةُ السندات خرجت من الواجهة: ما ينتظر
 * الاعتماد يُعرض في «المخزون» وفي الجرس، وكلُّ سندٍ يُفتح من أمر شرائه.
 */
class GoodsReceiptNoteController extends Controller
{
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    /**
     * سندُ استلامٍ واحد — بنودُه وأمرُه وورقتُه كما تُطبع.
     *
     * ═══ والبابُ الذي لم يكن ═══
     *
     * كان السندُ يُقرأ في نافذةٍ فوق القائمة، ورقمُه في شاشة أمر الشراء يقود
     * إلى **ملفّ PDF** في لسانٍ آخر. فلا عنوانَ له يُرسَل، ولا رجوعَ منه إلى
     * أمره، ولا موضعَ يقول من اعتمده ومتى أو لمَ رُفض. ومن يراجع خلافًا مع
     * مورّدٍ يحتاج الثلاثة معًا.
     *
     * ═══ والقيدُ في الاستعلام لا في الشاشة ═══
     *
     * `business_id` في `where` قبل المفتاح: رقمٌ مُخمَّن في العنوان كان
     * سيفتح سندَ متجرٍ آخر — وفيه تكلفةُ شرائه صنفًا صنفًا.
     */
    public function show(int|string $id): Response
    {
        if (! auth()->user()?->may(Permissions::RECEIPT_VIEW)) {
            abort(403);
        }

        $bid = $this->bid();

        $note = BranchAccess::limit(GoodsReceiptNote::where('business_id', $bid))
            ->with(['supplier', 'branch', 'purchaseOrder', 'items' => fn ($q) => $q->orderBy('id')])
            ->findOrFail($id);

        $mayAttachment = (bool) auth()->user()?->may(Permissions::ATTACHMENT_VIEW);

        /*
         * وأسماءُ من وقّعوا تُقرأ دفعةً واحدة — لا استعلامًا لكلّ اسم.
         */
        $actors = User::whereIn('id', array_filter([
            $note->submitted_by, $note->approved_by, $note->rejected_by,
        ]))->pluck('name', 'id');

        return Inertia::render('Admin/Inventory/ReceiptShow', [
            'note' => [
                'id' => $note->id,
                'number' => $note->number,
                'status' => $note->status,
                'supplier' => $note->supplier?->name,
                'branch' => $note->branch?->name,
                'received_at' => optional($note->received_at)->format('Y-m-d'),
                'receiver' => $note->receiver,
                'notes' => $note->notes,
                'approved_at' => optional($note->approved_at)->format('Y-m-d'),
                'rejected_at' => optional($note->rejected_at)->format('Y-m-d'),
                'rejection_reason' => $note->rejection_reason,
                'submitted_by' => $actors[$note->submitted_by] ?? null,
                'approved_by' => $actors[$note->approved_by] ?? null,
                'rejected_by' => $actors[$note->rejected_by] ?? null,
                'value' => round($note->items->sum(fn ($i) => (float) $i->quantity * (float) $i->cost), 3),
                /* ورقةُ خدماتٍ وحدها «تأكيدُ تنفيذ» — لا تدخل بها كميّةٌ المخزون (`GoodsReceipts::mode`) */
                'mode' => GoodsReceipts::mode($note->purchase_order_id
                    ? PurchaseOrderItem::where('purchase_order_id', $note->purchase_order_id)
                        ->whereIn('id', $note->items->pluck('purchase_order_item_id')->filter()->all())->pluck('line_kind')
                    : []),
                /*
                 * ووجودُ المرفق سؤالٌ غيرُ «هل تقرؤه؟».
                 *
                 * من لا يملك فتحَ المرفقات لا يُبنى له رابط — وتقول له الشاشة
                 * إنّ ثمّة ورقةً لا تُفتح، لا إنّه لا ورقة.
                 */
                'has_attachment' => $note->attachment !== null,
                'attachment' => $note->attachment && $mayAttachment
                    ? route('admin.inventory.receipts.attachment', $note->id) : null,
                'attachment_name' => $note->attachment ? ($note->attachment_name ?: __('ورقة المورّد')) : null,
                'items' => $note->items->map(function ($i) {
                    $line = $i->purchase_order_item_id
                        ? PurchaseOrderItem::find($i->purchase_order_item_id)
                        : null;

                    return [
                        'name' => $i->name,
                        'quantity' => (float) $i->quantity,
                        'cost' => (float) $i->cost,
                        'total' => round((float) $i->quantity * (float) $i->cost, 3),
                        'ordered' => $line ? (float) $line->quantity : null,
                        'received_before' => $line ? (float) $line->received_quantity : null,
                        'remaining' => $line ? (float) $line->remaining : null,
                    ];
                })->all(),
            ],
            /* وأمرُه: طريقٌ يعود إليه — لا رقمٌ يُقرأ ولا يُفتح */
            'order' => $note->purchaseOrder ? [
                'id' => $note->purchaseOrder->id,
                'number' => $note->purchaseOrder->number,
            ] : null,
            'can' => [
                'approve' => (bool) auth()->user()?->may(Permissions::RECEIPT_APPROVE),
                'reject' => (bool) auth()->user()?->may(Permissions::RECEIPT_REJECT),
            ],
            /* والورقةُ إلى جانب تفاصيلها — من بانيها الذي يُطبع منه */
            'paper' => [
                'html' => DocumentRenderer::generic($bid, 'grn', DocumentPaper::forGrn($note)),
                'size' => PaperSize::A4,
            ],
        ]);
    }
}
