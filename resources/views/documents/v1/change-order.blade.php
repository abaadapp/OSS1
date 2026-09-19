{{--
    أمرُ التغيير — ما تغيّر على نطاقٍ اعتمده العميل.

    وهو ليس عرضًا ثانيًا: لا يعيد كتابةَ ما اتُّفق عليه، بل يقول **الفرق**:
    بندٌ يزيد أو ينقص، ومتطلَّبٌ يُضاف أو يُزال، وموعدٌ جديد. والرقمُ الأهمّ
    **الفرقُ في القيمة** — موجبًا أو سالبًا — وما تصير إليه القيمةُ المعتمَدة
    بعده. والأساسُ الذي يُعدَّل مذكورٌ بعينه: رقمُ العرض المقبول وأوامرُ
    التغيير المقبولة قبله.
--}}
@extends('documents.v1.layout')

@php
    $amount = fn ($v) => \App\Support\Money::amount((float) $v, $currency);
    $money = fn ($v) => \App\Support\Money::format((float) $v, $currency);
    $signed = fn ($v) => ((float) $v < 0 ? '− ' : '+ ').$amount(abs((float) $v));
    $qty = fn ($v) => rtrim(rtrim(number_format(abs((float) $v), 3, '.', ''), '0'), '.');

    $label = \App\Support\ChangeOrders::label($order);
    $quotation = $project->quotation;

    $priorOrders = \App\Models\ChangeOrder::where('project_id', $project->id)->accepted()
        ->where('sequence', '<', (int) $order->sequence)->orderBy('sequence')->pluck('number')->all();

    /* والقيمةُ بعد الأمر: الحاليّةُ قبله + فرقُه — ولو قُبل فهي الحاليّةُ نفسُها */
    $before = \App\Support\ProjectScope::currentValue($project)
        - ($order->status === \App\Models\ChangeOrder::ACCEPTED ? (float) $order->total_delta : 0);
    $after = $before + (float) $order->total_delta;

    $stamp = match (true) {
        $order->status === \App\Models\ChangeOrder::CANCELLED => __('ملغى'),
        $order->status === \App\Models\ChangeOrder::ACCEPTED => __('مقبول'),
        $order->status === \App\Models\ChangeOrder::DECLINED => __('مرفوض'),
        $order->isExpired() => __('منتهي الصلاحية'),
        $order->status === \App\Models\ChangeOrder::SENT => '',
        default => __('مسودة'),
    };

    $party = $order->requested_by_party === \App\Models\ChangeOrder::BY_COMPANY ? __('الشركة') : __('العميل');

    $metaCells = array_values(array_filter([
        ['label' => 'رقم الأمر', 'value' => $label],
        ['label' => 'المشروع', 'value' => $project->code.' — '.$project->name],
        $quotation ? ['label' => 'العرض المقبول', 'value' => \App\Support\Quotations::label($quotation)] : null,
        $priorOrders !== [] ? ['label' => 'أوامر سابقة', 'value' => implode(' · ', $priorOrders)] : null,
        ['label' => 'التاريخ', 'value' => optional($order->sent_at ?? $order->created_at)->format('Y-m-d')],
        $order->valid_until ? ['label' => 'صالح حتى', 'value' => $order->valid_until->format('Y-m-d')] : null,
        ['label' => 'طلبه', 'value' => trim($party.(filled($order->requested_by_name) ? ' — '.$order->requested_by_name : ''))],
    ]));

    $totals = [
        ['label' => 'القيمة المعتمَدة قبل الأمر', 'value' => $amount($before)],
        ['label' => 'الفرق قبل الضريبة', 'value' => $signed((float) $order->subtotal_delta - (float) $order->discount_delta)],
    ];

    if (abs((float) $order->tax_delta) > 0.0005) {
        $totals[] = ['label' => 'فرق الضريبة', 'value' => $signed($order->tax_delta)];
    }

    $totals[] = ['label' => 'فرق القيمة', 'value' => $signed($order->total_delta)];
    $totals[] = ['label' => 'القيمة المعتمَدة بعد الأمر', 'value' => $money($after), 'grand' => true];

    $doc = ['number' => $label, 'status' => $stamp];
@endphp

@section('type', 'أمر تغيير')
@section('number', $label)

@section('parties')
    @include('documents.v1.partials.parties', ['parties' => [[
        'cap' => 'العميل',
        'lines' => array_values(array_filter([
            $quotation?->customer_name ?: $project->customer?->name,
            filled($quotation?->customer_tax_number) ? __('الرقم الضريبي').': '.$quotation->customer_tax_number : null,
        ])),
    ]]])
@endsection

@section('figure')
    @include('documents.v1.partials.figure', [
        'label' => 'فرق القيمة',
        'value' => $signed($order->total_delta),
        'status' => $doc['status'],
    ])
@endsection

@section('body')
    @php
        $policyPanels = array_map(fn ($p) => ['cap' => $p['cap'], 'text' => $p['text']], $policies ?? []);

        $approvalPanel = [];

        if (($approval ?? null) !== null && $approval->decision === \App\Models\DocumentApproval::ACCEPTED) {
            $approvalPanel = ['cap' => 'القبول', 'rows' => array_values(array_filter([
                ['label' => __('قَبِله'), 'value' => trim($approval->signer_name.(filled($approval->signer_title) ? ' — '.$approval->signer_title : ''))],
                filled($approval->signer_email) ? ['label' => __('البريد'), 'value' => $approval->signer_email, 'ltr' => true] : null,
                ['label' => __('في'), 'value' => optional($approval->decided_at)->format('Y-m-d H:i'), 'ltr' => true],
                ['label' => __('بصمة المستند'), 'value' => substr((string) $approval->content_hash, 0, 16).'…', 'ltr' => true],
            ]))];
        }

        $dateRows = [];

        if ($order->new_starts_at !== null || $order->new_ends_at !== null) {
            $dateRows = ['cap' => 'الموعد', 'rows' => array_values(array_filter([
                ['label' => __('الموعد المعتمَد'), 'value' => optional($project->starts_at)->format('Y-m-d').($project->ends_at && ! $project->ends_at->isSameDay($project->starts_at) ? ' → '.$project->ends_at->format('Y-m-d') : ''), 'ltr' => true],
                ['label' => __('الموعد الجديد'), 'value' => optional($order->new_starts_at)->format('Y-m-d').($order->new_ends_at ? ' → '.$order->new_ends_at->format('Y-m-d') : ''), 'ltr' => true],
            ]))];
        }

        $reqLines = [];

        foreach ($order->addedRequirements as $r) {
            $reqLines[] = '+ '.$r->title.(filled($r->details) ? ' — '.$r->details : '');
        }

        foreach ($order->removedRequirements as $r) {
            $reqLines[] = '− '.$r->title;
        }
    @endphp

    @php($h = function (string $k) {
        [$a, $b] = \App\Support\Paper::pair($k);

        return '<span class="lbl">'.e($a).'</span>'.($b !== '' ? '<br><span class="lbl2">'.e($b).'</span>' : '');
    })

    <div class="eventline">{{ $order->title }}</div>

    @if ($order->items->isNotEmpty())
        <table class="items">
            <thead>
                <tr>
                    <th>{!! $h('البيان') !!}</th>
                    <th style="width:12%" class="num">{!! $h('التغيير') !!}</th>
                    <th style="width:10%" class="unit">{!! $h('الوحدة') !!}</th>
                    <th style="width:16%" class="amt">{!! $h('سعر الوحدة') !!}</th>
                    <th style="width:14%" class="amt">{!! $h('الضريبة') !!}</th>
                    <th style="width:19%" class="amt">{!! $h('المبلغ') !!}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->items as $item)
                    <tr>
                        <td class="bidi item" dir="{{ \App\Support\Paper::dirOf($item->description) }}">
                            {{ $item->description }}
                            <div class="itemnote">{{ $item->project_item_id === null ? __('بند جديد') : ($item->isReduction() ? __('نقص على بند قائم') : __('زيادة على بند قائم')) }}@if (filled($item->notes)) — {{ $item->notes }}@endif</div>
                        </td>
                        <td class="num"><span dir="ltr">{{ $item->isReduction() ? '−' : '+' }}{{ $qty($item->quantity) }}</span></td>
                        <td class="unit">{{ $item->unit }}</td>
                        <td class="amt"><span dir="ltr">{{ $amount($item->unit_price) }}</span></td>
                        <td class="amt"><span dir="ltr">{{ $signed($item->tax_amount) }}</span></td>
                        <td class="amt"><span dir="ltr">{{ $signed($item->line_total) }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @include('documents.v1.partials.totals', ['totals' => $totals])

    @include('documents.v1.partials.close', [
        'panels' => array_merge([
            filled($order->reason) ? ['cap' => 'سبب التغيير', 'text' => $order->reason] : [],
            $reqLines !== [] ? ['cap' => 'المتطلّبات', 'text' => implode("\n", $reqLines)] : [],
            $dateRows,
        ], $policyPanels, [$approvalPanel]),
        'paperUrl' => $paperUrl ?? '',
    ])
@endsection

@if (trim((string) ($footerNote ?? '')) !== '')
    @section('foot')
        {{ $footerNote }}
    @endsection
@endif
