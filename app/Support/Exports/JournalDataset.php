<?php

namespace App\Support\Exports;

use App\Http\Controllers\Admin\Finance\JournalController;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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

    public function data(Request $request, int $bid): array
    {
        $query = JournalController::listQuery($request, $bid);
        /* سطورُ القيود المطابقة — يُعدّ بها الملفّ ويُجمع مدينُه ودائنُه قبل أن يُقرأ */
        $lines = fn () => JournalLine::whereIn('journal_entry_id', (clone $query)->reorder()->select('journal_entries.id'));
        $sums = $lines()->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')->first();

        return [
            // قيدٌ بسطوره كلِّها في الدفعة نفسِها: لا يُقطع نصفين
            'rows' => Rows::chunked($query, fn (Collection $entries) => $entries->flatMap(fn (JournalEntry $entry) => $entry->lines->map(fn ($line) => [
                'date' => optional($entry->entry_date)->format('Y-m-d'),
                'number' => $entry->number,
                'description' => $entry->description,
                'source' => $entry->source,
                'account' => trim(($line->account?->code ?? '').' — '.($line->account?->name ?? ''), ' —'),
                'memo' => $line->memo,
                'debit' => round((float) $line->debit, 3),
                'credit' => round((float) $line->credit, 3),
                'state' => $this->state($entry),
            ]))->all(), fn () => $lines()->count()),
            'totals' => ['debit' => round((float) $sums->d, 3), 'credit' => round((float) $sums->c, 3)],
            'cards' => [['label' => __('القيود'), 'value' => number_format((clone $query)->toBase()->getCountForPagination())]],
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
