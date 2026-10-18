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
 *
 * والملفُّ يحمل كلَّ ما طابق بلا سقف في CSV وإكسل. وPDF وحده له حدٌّ
 * (`TableExport::PDF_LIMIT`): يُسأل عنه قبل التنزيل (`?probe=1`)، وما جاوزه
 * يُردّ بشرحٍ إلّا أن يُطلب جزؤه صراحةً (`partial=1`).
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
        $data = $dataset->data($request, (int) (auth()->user()->business_id ?? Demo::bid()));
        $rows = $data['rows'];

        /* PDF يُسأل عن طوله قبل أن يُرسم: زرُّ «تصدير» يُنبّه إن جاوز حدَّه */
        if ($format === 'pdf' && $request->boolean('probe')) {
            return TableExport::probe(count($rows));
        }
        $cut = $format === 'pdf' ? TableExport::pdfCut(count($rows), $request->boolean('partial')) : null;

        Activity::log('report', 'صدّر '.$dataset->title().' ('.['xlsx' => 'Excel', 'csv' => 'CSV', 'pdf' => 'PDF'][$format].')');

        return TableExport::download(
            $format,
            $dataset->filename($request),
            [
                'business' => Demo::business(auth()->user()->business_id ?? Demo::bid())['name'] ?? 'Event Plus',
                'title' => $dataset->title(),
                'period' => $dataset->periodLabel($request),
                'periodLine' => $dataset->periodLine($request),
                'filters' => $dataset->filterLines($request),
                'branch' => Demo::scopeName(false),
            ],
            $dataset->columns($request),
            $rows,
            $data['cards'] ?? [],
            $data['totals'] ?? null,
            $cut,
        );
    }
}
