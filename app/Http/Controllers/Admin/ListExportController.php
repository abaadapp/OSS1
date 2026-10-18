<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\Exports\Datasets;
use App\Support\TableExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * تصديرُ القوائم التشغيليّة — زرُّ «تصدير» في شاشات العمل اليوميّ.
 *
 * مسارٌ واحدٌ لكلّ شاشة بصيغته معاملًا (`{format}` = xlsx · csv · pdf)، واسمُه
 * تحت قسم الشاشة نفسها (`admin.finance.transactions.export`…): فحارسُ المسار
 * يقيسه بصلاحية القسم كما يقيس الشاشة، والقائمةُ تسأل فوق ذلك عن إذنها
 * (`Dataset::authorize` — الرواتبُ وسنداتُ المورّدين). ومن كتب الرابطَ بيده
 * بلا صلاحيّةٍ رُدّ — لا يكفي أن يُخفى الزرّ.
 */
class ListExportController extends Controller
{
    public function __invoke(Request $request, string $format): Response
    {
        abort_unless(in_array($format, ['xlsx', 'csv', 'pdf'], true), 404);

        $dataset = Datasets::make((string) $request->route('dataset'));
        $dataset->authorize($request);
        $dataset->validate($request);

        TableExport::prepare($format);
        $limit = TableExport::limit($format);
        $data = $dataset->data($request, (int) (auth()->user()->business_id ?? Demo::bid()), $limit);
        /* والمعدودُ ما يُعدّ به المجموع: قيدٌ لا سطورُه، ومشروعٌ لا مهامُّه */
        $shown = $data['shown'] ?? (is_countable($data['rows']) ? count($data['rows']) : min($data['total'], $limit));

        Activity::log('report', 'صدّر '.$dataset->title().' ('.['xlsx' => 'Excel', 'csv' => 'CSV', 'pdf' => 'PDF'][$format].')');

        return TableExport::download(
            $format,
            $dataset->filename($request),
            [
                'business' => Demo::business(auth()->user()->business_id ?? Demo::bid())['name'] ?? 'Event Plus',
                'title' => $dataset->title(),
                'period' => $dataset->periodLabel($request),
                'filters' => $dataset->filterLines($request),
                'branch' => Demo::scopeName(false),
            ],
            $dataset->columns($request),
            $data['rows'],
            $data['cards'] ?? [],
            $data['totals'] ?? null,
            $data['total'] > $shown ? ['shown' => $shown, 'total' => $data['total']] : null,
        );
    }
}
