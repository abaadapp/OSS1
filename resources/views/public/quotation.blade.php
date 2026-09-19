{{--
    عرضُ السعر كما يفتحه العميل — ويقرّر منه.

    ═══ ولمَ الورقةُ كاملةً لا ملخّصًا ═══

    فاتورةُ العميل تُعرض ملخّصًا (`public/verify`) لأنّ السؤالَ عندها
    واحد: أهذه صحيحة؟ — والورقةُ كاملةً في يد من يسأل أصلًا.

    وعرضُ السعر عكسُها: **هو الورقةُ نفسُها**، لا نسخةٌ من ورقةٍ في يد
    أحد. يُرسَل رابطُه فيُفتح على هاتفٍ ويُقرَّر منه. فمن يفتحه يحتاج
    البنودَ والأسعارَ والنطاقَ والشروطَ والصلاحيّة — وملخّصٌ يقول
    «الإجمالي ١٢٠٠» لا يُقرَّر به شيء.

    ═══ وصفحةٌ قائمةٌ بذاتها ═══

    لا Inertia ولا قائمةٌ جانبية ولا أصولُ البناء: تُفتح على هاتفٍ بشبكةٍ
    ضعيفة، والورقةُ كلُّها في ملفٍّ واحد — أنماطُها مضمَّنةٌ فيها من
    `documents/v1/partials/tokens` كما تُضمَّن في الـPDF.

    و`noindex`: رابطُها لا يُخمَّن، لكنّ محرّك بحثٍ يزحف إليه من مشاركةٍ
    عابرة يجعل أسعارَ الشركة مفهرسةً للجميع.

    ═══ والقرارُ تحت الورقة ═══

    زرّان واسمٌ وإقرار. وما يُكتب بهما **دليل**: البصمةُ تُقارَن بما خُتم
    عند الإرسال، واللقطةُ والموقّعُ والعنوانُ والمتصفّح تُكتب مرّةً في
    `document_approvals` — انظر `Quotations::decide`. ونصُّ الإقرار يُعرض
    هنا ويُحفظ بحرفه: دليلٌ يشهد على ما قُرئ لا على مفتاح.

    وهو **قبولٌ إلكترونيٌّ موثَّق** لا توقيعٌ معتمَدٌ قانونًا — يكفي بين
    شركةٍ وعميلها، ولا يكفي لعقدٍ حكوميٍّ يشترط شهادة (§19).
--}}
@php
    $rtl = \App\Support\Paper::rtl();
    $accepted = ($approval ?? null)?->decision === \App\Models\DocumentApproval::ACCEPTED;
@endphp
<!DOCTYPE html>
<html lang="{{ $rtl ? 'ar' : 'en' }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $docTitle ?? __('عرض سعر') }} {{ $label ?? '' }}</title>
    <style>
        html, body { margin: 0; padding: 0; background: #f5f5f4; font-family: Tahoma, Arial, sans-serif; color: #1f2937; }
        /*
            وحشوٌ حول الورقة على الشاشة وحدها.

            `documents/v1` يرسم صندوقَ A4 بمقاسه، ووضعُه على أرضيّةٍ بلا
            هامشٍ يجعله يلتصق بحوافّ الهاتف — فتُقصّ الكلماتُ الأولى من
            كلّ سطر.
        */
        .wrap { padding: 12px; }
        @media (min-width: 640px) { .wrap { padding: 24px 12px 48px; } }

        /* لوحةُ القرار — بعرض الورقة، لا أعرض */
        .decide { max-width: 794px; margin: 0 auto 32px; background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 20px; }
        .decide h2 { margin: 0 0 6px; font-size: 17px; }
        .decide p.lead { margin: 0 0 16px; font-size: 13px; color: #6b7280; line-height: 1.7; }
        .grid { display: grid; grid-template-columns: 1fr; gap: 10px; }
        @media (min-width: 640px) { .grid { grid-template-columns: 1fr 1fr; } }
        label { display: block; font-size: 12px; color: #374151; margin-bottom: 4px; }
        input[type=text], input[type=email], input[type=tel], textarea { width: 100%; box-sizing: border-box; border: 1px solid #d1d5db; border-radius: 10px; padding: 10px 12px; font-size: 14px; font-family: inherit; background: #fff; }
        textarea { min-height: 72px; resize: vertical; }
        .consent { display: flex; gap: 10px; align-items: flex-start; margin: 14px 0; font-size: 13px; line-height: 1.7; color: #374151; }
        .consent input { margin-top: 5px; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 8px; }
        button { border: 0; border-radius: 10px; padding: 12px 22px; font-size: 15px; font-weight: bold; cursor: pointer; font-family: inherit; }
        .accept { background: #111; color: #fff; }
        .decline { background: #fff; color: #b91c1c; border: 1px solid #fecaca; }
        .error { background: #fef2f2; color: #b91c1c; border-radius: 10px; padding: 10px 12px; font-size: 13px; margin-bottom: 12px; }
        .done { max-width: 794px; margin: 0 auto; border-radius: 16px; padding: 20px; font-size: 14px; line-height: 1.8; }
        .done.ok { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .done.no { background: #f9fafb; color: #374151; border: 1px solid #e5e7eb; }
        .done code { font-family: monospace; font-size: 11px; word-break: break-all; }
        .hint { max-width: 794px; margin: 0 auto 32px; text-align: center; font-size: 12px; color: #9ca3af; line-height: 1.7; }
        .reason { display: none; margin-top: 10px; }
    </style>
</head>
<body>
    @if (($approval ?? null) !== null)
        {{--
            وما قُرّر يُقال أوّلًا لا تحت الورقة: من عاد إلى الرابط بعد
            أسبوعٍ يعرف من أوّل سطرٍ أنّه قَبِل، ولا يبحث عن زرٍّ لم يعد.
        --}}
        <div class="wrap">
            <div class="done {{ $accepted ? 'ok' : 'no' }}">
                @if ($accepted)
                    <strong>{{ __('تمّ قبول العرض — شكرًا لكم.') }}</strong><br>
                    {{ __('سُجّل القبول باسم :name في :at.', ['name' => $approval->signer_name, 'at' => $approval->decided_at?->format('Y-m-d H:i')]) }}<br>
                    <span dir="ltr"><code>{{ __('بصمة المستند') }}: {{ $approval->content_hash }}</code></span><br>
                    {{ __('ستتواصل معكم :company لترتيب الخطوات التالية.', ['company' => $company]) }}
                @else
                    <strong>{{ __('سُجّل اعتذاركم عن هذا العرض.') }}</strong><br>
                    {{ __('شكرًا لوقتكم — ويسرّ :company أن تعرض عليكم بديلًا يناسبكم.', ['company' => $company]) }}
                @endif
            </div>
        </div>
    @endif

    <div class="wrap">{!! $paper !!}</div>

    @if ($decidable && ($approval ?? null) === null)
        <div class="wrap">
            <form class="decide" method="post" action="{{ $decideUrl }}" id="decide">
                @csrf
                <h2>{{ __('قراركم على هذا العرض') }}</h2>
                <p class="lead">{{ __('اقرأ البنود ونطاق العمل والشروط أعلاه، ثم اكتب اسمك واختر. يُسجَّل القرار بوقته وبصمة المستند كما قرأته.') }}</p>

                @if ($errors->any())
                    <div class="error">{{ $errors->first() }}</div>
                @endif

                <div class="grid">
                    <div>
                        <label for="name">{{ __('الاسم') }} *</label>
                        <input type="text" id="name" name="name" required minlength="2" maxlength="120" value="{{ old('name') }}">
                    </div>
                    <div>
                        <label for="title">{{ __('الصفة / المنصب') }}</label>
                        <input type="text" id="title" name="title" maxlength="120" value="{{ old('title') }}">
                    </div>
                    <div>
                        <label for="email">{{ __('البريد الإلكتروني') }}</label>
                        <input type="email" id="email" name="email" maxlength="120" dir="ltr" value="{{ old('email', $quotation->contact_email) }}">
                    </div>
                    <div>
                        <label for="phone">{{ __('الهاتف') }}</label>
                        <input type="tel" id="phone" name="phone" maxlength="40" dir="ltr" value="{{ old('phone') }}">
                    </div>
                </div>

                <div class="reason" id="reason">
                    <label for="decline_reason">{{ __('سبب الاعتذار') }} *</label>
                    <textarea id="decline_reason" name="decline_reason" maxlength="500">{{ old('decline_reason') }}</textarea>
                </div>

                <label class="consent">
                    <input type="checkbox" name="consent" value="1" id="consent" {{ old('consent') ? 'checked' : '' }}>
                    <span>{{ $consent }}</span>
                </label>

                <input type="hidden" name="decision" id="decision" value="accepted">

                <div class="actions">
                    <button type="submit" class="accept" onclick="document.getElementById('decision').value='accepted'">
                        {{ __('أقبل العرض') }}
                    </button>
                    <button type="button" class="decline" id="declineBtn">
                        {{ __('أعتذر عن العرض') }}
                    </button>
                </div>
            </form>
            <p class="hint">{{ __('قبولٌ إلكتروني موثَّق بالبصمة والوقت والعنوان — وليس توقيعًا معتمَدًا قانونًا.') }}</p>
        </div>

        {{--
            وسطورٌ من الجافاسكربت لا مكتبة: زرُّ الاعتذار يُظهر حقلَ السبب
            ويبدّل القرارَ ثمّ يُرسل — وبلا جافاسكربت يعمل القبولُ وحدَه،
            وهو الأكثرُ وقوعًا. والإقرارُ يُفحص في الخادم أيضًا.
        --}}
        <script>
            (function () {
                var form = document.getElementById('decide');
                var btn = document.getElementById('declineBtn');
                var reason = document.getElementById('reason');
                var field = document.getElementById('decline_reason');
                var consent = document.getElementById('consent');
                var decision = document.getElementById('decision');

                btn.addEventListener('click', function () {
                    if (reason.style.display !== 'block') {
                        reason.style.display = 'block';
                        field.focus();
                        return;
                    }
                    if (field.value.trim() === '') { field.focus(); return; }
                    decision.value = 'declined';
                    form.submit();
                });

                form.addEventListener('submit', function (e) {
                    if (decision.value === 'accepted' && !consent.checked) {
                        e.preventDefault();
                        consent.focus();
                        alert(@json(__('لا يُقبل العرض بلا الإقرار.')));
                    }
                });

                @if (old('decision') === 'declined')
                    reason.style.display = 'block';
                @endif
            })();
        </script>
    @elseif (($approval ?? null) === null && $quotation->isExpired())
        <p class="hint">{{ __('انتهت صلاحية هذا العرض — تواصل مع :company لتجديده.', ['company' => $company]) }}</p>
    @endif
</body>
</html>
