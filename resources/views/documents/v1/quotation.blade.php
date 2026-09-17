{{--
    عرضُ السعر — أوّلُ ما تراه الجهةُ من الشركة.

    ═══ وهو ليس فاتورةً لم تُصدَر بعد ═══

    الفاتورةُ **مطالبةٌ بمبلغ**: تُرسَل ويُبنى عليها جدولُ صرف. وهذا
    **وعدٌ بثمن**: يُقرأ ويُقارَن بعروضٍ أخرى ويُناقَش ثمّ يُقبل أو يُردّ.

    فما يميّزه أربعة، ولكلٍّ سببُه:

    • **الفعاليّة** — «مؤتمر عُمان للطاقة، ١٢–١٤ مارس، ٥٠٠ حاضر». من يقرأ
      العرضَ في جهةٍ كبيرة يفتح ثلاثةً في وقتٍ واحد، فأوّلُ ما يسأل: أيُّ
      فعاليّةٍ هذه؟ وورقةٌ تبدأ بالبنود تجعله يبحث.

    • **الصلاحيّة** — «صالحٌ حتى ٣٠ مارس» ليست زينة: هي ما يجعل القرارَ
      يُتّخذ. وعرضٌ بلا تاريخ انتهاءٍ يُؤجَّل إلى أجلٍ غير مسمّى، ثمّ
      يُطلب تنفيذُه بعد سنةٍ بأسعارٍ تغيّرت.

    • **نطاقُ العمل** — ما سيُنفَّذ وما لا يُنفَّذ. وهو أكثرُ ما يُختلف
      عليه بعد القبول، فيُكتب قبله. وبنودٌ بأرقامٍ بلا نطاقٍ يكتبه أحدٌ
      تجعل كلَّ طرفٍ يقرأ ما يريد.

    • **الشروطُ التجاريّة** — الإلغاءُ والتأجيلُ والمسؤوليّة.

    ═══ ولا «الرصيد المستحقّ» فيه ═══

    لا مسدَّدَ ولا باقيَ ولا تعليماتِ سداد: لا ذمّةَ بعد. والرقمُ الأهمّ
    **الإجماليُّ المعروض** — وهو ما يُقارَن به.
--}}
@extends('documents.v1.layout')

@php
    /* والصيغةُ من `Support\Money` — انظر `customer-invoice` */
    $amount = fn ($v) => \App\Support\Money::amount((float) $v, $currency);
    $money = fn ($v) => \App\Support\Money::format((float) $v, $currency);

    $terms = $quotation->payment_terms_days === null ? null
        : ((int) $quotation->payment_terms_days === 0
            ? __('مستحق فورًا')
            : __('صافي :n يومًا', ['n' => (int) $quotation->payment_terms_days]));

    /*
        وحقولُ الجهة تُرشَّح قبل أن تُرسم: صفٌّ يُكتب بحقلين يُخرج خانةً
        فارغةً حين يُملأ أحدُهما — وورقةٌ فيها خاناتٌ خاوية تُقرأ نموذجًا
        لم يُكمَل. انظر `customer-invoice`.
    */
    $orgFields = array_values(array_filter([
        ['label' => 'مرجع العميل', 'value' => $quotation->customer_reference],
        ['label' => 'القسم / الإدارة', 'value' => $quotation->department],
        ['label' => 'موجه إلى / عناية', 'value' => $quotation->attention_to],
    ], fn ($f) => filled($f['value'])));

    /*
        وتفاصيلُ الفعاليّة تُرشَّح مثلَها — ولا تُكتب «—» مكان الفارغ:
        تقول للجهة إنّ للفعاليّة موقعًا لم يُذكر، ولا موقعَ لها أصلًا.
    */
    $eventDates = match (true) {
        $quotation->event_starts_at && $quotation->event_ends_at
            && ! $quotation->event_starts_at->isSameDay($quotation->event_ends_at)
            => $quotation->event_starts_at->format('Y-m-d').' → '.$quotation->event_ends_at->format('Y-m-d'),
        (bool) $quotation->event_starts_at => $quotation->event_starts_at->format('Y-m-d'),
        default => null,
    };

    $eventFields = array_values(array_filter([
        ['label' => 'موعد الفعالية', 'value' => $eventDates],
        ['label' => 'الموقع', 'value' => $quotation->event_location],
        ['label' => 'عدد الحضور المتوقع', 'value' => $quotation->expected_attendees
            ? number_format((int) $quotation->expected_attendees) : null],
    ], fn ($f) => filled($f['value'])));

    $totals = [['label' => 'المجموع الفرعي', 'value' => $amount($quotation->subtotal)]];

    if ((float) $quotation->discount_total > 0) {
        $totals[] = ['label' => 'الخصم', 'value' => '− '.$amount($quotation->discount_total)];
    }

    if ((float) $quotation->tax_total > 0) {
        $totals[] = ['label' => 'ضريبة القيمة المضافة', 'value' => $amount($quotation->tax_total)];
    }

    $totals[] = ['label' => 'الإجمالي', 'value' => $money($quotation->total), 'grand' => true];

    /*
        والخَتمُ يقرأ `status` — فعرضٌ أُلغي أو حلّ محلَّه تنقيحٌ يقول ذلك
        على وجهه. و«أُرسل» لا يُختم: هي الحالُ الطبيعيّةُ لورقةٍ في يد من
        يقرؤها، وختمُها على كلّ ورقةٍ ضجيجٌ لا خبر.

        و«منتهي الصلاحية» مشتقٌّ لا مخزَّن — ويُختم لأنّه أهمُّ ما يُقرأ
        على ورقةٍ يُبنى عليها قرار.
    */
    $stamp = match (true) {
        $quotation->status === \App\Models\Quotation::CANCELLED => __('ملغى'),
        $quotation->status === \App\Models\Quotation::SUPERSEDED => __('حلّ محلّه تنقيح أحدث'),
        $quotation->status === \App\Models\Quotation::ACCEPTED => __('مقبول'),
        $quotation->status === \App\Models\Quotation::REJECTED => __('مرفوض'),
        $quotation->isExpired() => __('منتهي الصلاحية'),
        $quotation->status === \App\Models\Quotation::SENT => '',
        default => __('مسودة'),
    };

    $label = \App\Support\Quotations::label($quotation);

    $doc = ['number' => $label, 'status' => $stamp];
@endphp

@section('type', 'عرض سعر')
@section('number', $label)

@section('parties')
    @include('documents.v1.partials.parties', ['parties' => [[
        'cap' => 'العميل',
        'lines' => array_values(array_filter([
            $quotation->customer_name ?: null,
            filled($quotation->customer_tax_number) ? __('الرقم الضريبي').': '.$quotation->customer_tax_number : null,
            filled($quotation->customer_cr) ? __('السجل التجاري').': '.$quotation->customer_cr : null,
        ])),
    ]]])
@endsection

@php
    $metaCells = array_merge(
        [['label' => 'رقم العرض', 'value' => $label]],
        [['label' => 'التاريخ', 'value' => optional($quotation->issued_at)->format('Y-m-d')]],
        $quotation->valid_until
            ? [['label' => 'صالح حتى', 'value' => $quotation->valid_until->format('Y-m-d')]] : [],
        $terms !== null ? [['label' => 'شروط الدفع', 'value' => $terms]] : [],
        $eventFields,
        $orgFields,
    );
@endphp

@section('figure')
    {{-- وما يُبحث عنه في عرض سعر: كم يكلّف --}}
    @include('documents.v1.partials.figure', [
        'label' => 'الإجمالي المعروض',
        'value' => $money($quotation->total),
        'status' => $doc['status'],
    ])
@endsection

@section('body')
    {{--
        وكتلةُ `@php … @endphp` هنا **قبل** `@php(…)` المفردة أدناه لا بعدها:
        Blade يلتقط `@php` الأولى إلى أوّل `@endphp` تليها، فكتلةٌ بعد
        المفردة تبتلعها ومعها الجدولَ كلَّه.
    --}}
    @php
        /*
            وسياساتُ الشركة بعد الشروط التجاريّة: تلك لهذا العرض وحدَه،
            وهذه لكلّ عرض. وتُقرأ من الختم لما خرج — `CompanyPolicies::texts`
            — فورقةٌ تُطبع بعد سنةٍ تحمل نصَّ يومها لا نصَّ اليوم.

            ودليلُ القبول يُطبع آخرًا: من قَبِل، وبأيّ صفة، ومتى، وبأيّ
            بصمة. فالورقةُ المطبوعة بعد القبول تحمل قبولَها معها.
        */
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
    @endphp

    @php($h = function (string $k) {
        [$a, $b] = \App\Support\Paper::pair($k);

        return '<span class="lbl">'.e($a).'</span>'.($b !== '' ? '<br><span class="lbl2">'.e($b).'</span>' : '');
    })

    {{-- وعنوانُ الفعاليّة فوق البنود: هو ما يجمعها ويقول لِمَ هي معًا --}}
    @if (filled($quotation->title))
        <div class="eventline">{{ $quotation->title }}</div>
    @endif

    <table class="items">
        <thead>
            <tr>
                <th>{!! $h('البيان') !!}</th>
                <th style="width:10%" class="num">{!! $h('الكمية') !!}</th>
                <th style="width:10%" class="unit">{!! $h('الوحدة') !!}</th>
                <th style="width:16%" class="amt">{!! $h('سعر الوحدة') !!}</th>
                <th style="width:14%" class="amt">{!! $h('الضريبة') !!}</th>
                <th style="width:19%" class="amt">{!! $h('المبلغ') !!}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($quotation->items as $item)
                <tr>
                    {{-- والبيانُ يحمل اتّجاهَه: بندٌ إنجليزيٌّ في ورقةٍ عربيّة لا تنقلب نقطتُه --}}
                    <td class="bidi item" dir="{{ \App\Support\Paper::dirOf($item->description) }}">
                        {{ $item->description }}
                        {{--
                            ونطاقُ البند تحته لا في عمودٍ سادس: هو جملةٌ لا
                            كلمة، وعمودٌ يسعها يضيّق أعمدةَ المال.
                        --}}
                        @if (filled($item->notes))
                            <div class="itemnote">{{ $item->notes }}</div>
                        @endif
                    </td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $item->quantity, 3, '.', ''), '0'), '.') }}</td>
                    <td class="unit">{{ $item->unit }}</td>
                    {{-- والمبالغُ معزولةٌ عن اتّجاه السطر: انظر `partials/items` --}}
                    <td class="amt"><span dir="ltr">{{ $amount($item->unit_price) }}</span></td>
                    <td class="amt"><span dir="ltr">{{ $amount($item->tax_amount) }}</span></td>
                    <td class="amt"><span dir="ltr">{{ $amount($item->line_total) }}</span></td>
                </tr>
            @empty
                <tr><td class="empty" colspan="6">{{ __('لا بنود على هذا العرض') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    @include('documents.v1.partials.totals', ['totals' => $totals])

    {{--
        والنطاقُ والشروطُ بعد المال لا قبله: من يفتح ورقةً يسأل «كم؟»
        أوّلًا، ثمّ يقرأ ما يشمله الرقم. وترتيبُهما مقصود — النطاقُ يقول
        **ما ستأخذ**، والشروطُ تقول **بأيّ التزام**.
    --}}

    @include('documents.v1.partials.close', [
        'panels' => array_merge([
            filled($quotation->scope) ? ['cap' => 'نطاق العمل', 'text' => $quotation->scope] : [],
            filled($quotation->terms) ? ['cap' => 'الشروط التجارية', 'text' => $quotation->terms] : [],
        ], $policyPanels, [
            ($quotation->notes && ($showNotes ?? true)) ? ['cap' => 'ملاحظات', 'text' => $quotation->notes] : [],
            $approvalPanel,
        ]),
        'paperUrl' => $paperUrl ?? '',
    ])
@endsection

@if (trim((string) ($footerNote ?? '')) !== '')
    @section('foot')
        {{ $footerNote }}
    @endsection
@endif
