<?php

namespace App\Support\Exports;

use App\Models\BankAccount;
use App\Support\Demo;
use Illuminate\Http\Request;

/**
 * كشف الحساب البنكي — حركاتُ البنك برصيدها الجاري، كما تقرؤها شاشةُ الكشف.
 *
 * والمدّةُ تقصّ الصفوف ولا تقصّ الرصيد: الرصيدُ الجاري يُحسب من أوّل حركةٍ بعد
 * الرصيد الافتتاحيّ كما على الشاشة، ثمّ تُعرض صفوفُ المدّة وحدها برصيدها
 * الصحيح. فـ«رصيدُ أوّل المدّة» و«رصيدُ آخرها» رصيدان لا مجموعُ حركاتٍ — و«المدين»
 * و«الدائن» وحدهما مجموعا المدّة.
 */
final class BankStatementDataset extends Dataset
{
    public static function slug(): string
    {
        return 'bank-statement';
    }

    public function title(): string
    {
        return __('كشف الحساب البنكي');
    }

    public function columns(Request $request): array
    {
        return [
            self::col('date', 'التاريخ'), self::col('reference', 'السند'), self::col('description', 'البيان'),
            self::col('method', 'الوسيلة'), self::col('debit', 'مدين (خارج)', 'money'), self::col('credit', 'دائن (داخل)', 'money'),
            self::col('balance', 'الرصيد', 'money'),
        ];
    }

    private function account(Request $request, int $bid): ?int
    {
        $id = (int) $request->query('account');

        return $id > 0 ? BankAccount::where('business_id', $bid)->whereKey($id)->value('id') : null;
    }

    /**
     * صفوفُ المدّة من الكشف كلّه — برصيدها الجاري الصحيح، ورصيدَي أوّلها وآخرها.
     * تقرؤها شاشةُ الكشف والملفُّ معًا.
     *
     * @param  array{opening: float, rows: list<array<string, mixed>>, closing: float}  $statement
     * @return array{opening: float, rows: list<array<string, mixed>>, closing: float}
     */
    public static function window(array $statement, ?string $from, ?string $to): array
    {
        $opening = (float) $statement['opening'];
        $rows = [];
        foreach ($statement['rows'] as $row) {
            if ($from && $row['date'] < $from) {
                $opening = (float) $row['balance'];

                continue;
            }
            if ($to && $row['date'] > $to) {
                continue;
            }
            $rows[] = $row;
        }

        return ['opening' => round($opening, 3), 'rows' => $rows, 'closing' => round($rows === [] ? $opening : (float) end($rows)['balance'], 3)];
    }

    public function data(Request $request, int $bid): array
    {
        ['opening' => $opening, 'rows' => $rows, 'closing' => $closing] = self::window(
            Demo::bankStatement($this->account($request, $bid)), self::day($request, 'from'), self::day($request, 'to'),
        );

        return [
            'rows' => $rows,
            'totals' => ['debit' => round(array_sum(array_column($rows, 'debit')), 3), 'credit' => round(array_sum(array_column($rows, 'credit')), 3)],
            'cards' => [
                ['label' => __('رصيد أوّل المدّة'), 'value' => Demo::moneyBase($opening)],
                ['label' => __('رصيد آخر المدّة'), 'value' => Demo::moneyBase($closing)],
            ],
        ];
    }

    public function filterLines(Request $request): array
    {
        $bid = (int) (auth()->user()->business_id ?? Demo::bid());
        $id = $this->account($request, $bid);

        return $id ? [__('الحساب').': '.(BankAccount::find($id)?->displayName() ?? $id)] : [];
    }
}
