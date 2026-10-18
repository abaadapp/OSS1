<?php

namespace App\Support\Exports;

use App\Http\Controllers\Admin\Finance\JournalController;
use App\Models\JournalEntry;
use Illuminate\Http\Request;

/**
 * القيود اليومية — سطرًا سطرًا، بمرشّحات شاشتها (البحث · المصدر · من · إلى).
 *
 * الملفُّ للمحاسب: كلُّ سطرٍ حسابُه ومدينُه ودائنُه وبيانُه ورقمُ قيده — فيُجمع
 * المدينُ والدائنُ ويتساويان. والقيدُ المعكوسُ وعاكسُه يبقيان موسومَين كما على
 * الشاشة: العكسُ قيدٌ ثانٍ لا محوٌ للأوّل.
 */
final class JournalDataset extends Dataset
{
    public static function slug(): string
    {
        return 'journal';
    }

    public function title(): string
    {
        return __('القيود اليومية');
    }

    public function columns(Request $request): array
    {
        return [
            self::col('date', 'التاريخ'), self::col('number', 'رقم القيد'), self::col('description', 'البيان'),
            self::col('source', 'المصدر'), self::col('account', 'الحساب'), self::col('memo', 'بيان السطر'),
            self::col('debit', 'مدين', 'money'), self::col('credit', 'دائن', 'money'), self::col('state', 'الحالة'),
        ];
    }

    public function data(Request $request, int $bid, int $limit): array
    {
        $query = JournalController::listQuery($request, $bid);
        $total = (clone $query)->toBase()->getCountForPagination();
        $rows = [];
        $debit = 0.0;
        $credit = 0.0;
        $entries = 0;

        /* السقفُ على القيود لا على السطور: قيدٌ لا يُقطع نصفين بين ملفّين */
        for ($offset = 0, $want = min($total, $limit); $offset < $want; $offset += 200) {
            foreach ((clone $query)->offset($offset)->limit(min(200, $want - $offset))->get() as $entry) {
                $entries++;
                foreach ($entry->lines as $line) {
                    $rows[] = [
                        'date' => optional($entry->entry_date)->format('Y-m-d'),
                        'number' => $entry->number,
                        'description' => $entry->description,
                        'source' => $entry->source,
                        'account' => trim(($line->account?->code ?? '').' — '.($line->account?->name ?? ''), ' —'),
                        'memo' => $line->memo,
                        'debit' => round((float) $line->debit, 3),
                        'credit' => round((float) $line->credit, 3),
                        'state' => $this->state($entry),
                    ];
                    $debit += (float) $line->debit;
                    $credit += (float) $line->credit;
                }
            }
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'shown' => $entries,
            'totals' => ['debit' => round($debit, 3), 'credit' => round($credit, 3)],
            'cards' => [['label' => __('القيود'), 'value' => number_format($entries)]],
        ];
    }

    private function state(JournalEntry $entry): string
    {
        return match (true) {
            $entry->reversed_at !== null => __('معكوس'),
            $entry->reverses_id !== null => __('قيد عكس'),
            default => __('مرحَّل'),
        };
    }

    public function filterLines(Request $request): array
    {
        return array_values(array_filter([
            ($q = self::param($request, 'q')) ? __('البحث').': '.$q : null,
            ($source = self::param($request, 'source')) ? __('المصدر').': '.$source : null,
        ]));
    }
}
