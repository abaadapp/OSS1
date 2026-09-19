{{--
    حزمةُ التسليم كما يفتحها العميل — ويقرّر منها.

    صفحةٌ قائمةٌ بذاتها كأختها `public/quotation`: لا Inertia ولا أصولُ
    البناء، تُفتح على هاتفٍ بشبكةٍ ضعيفة. تعرض هويّةَ الشركة، وما سُلِّم
    بنودًا وملفّات، وملاحظاتِ التسليم، ثمّ لوحةَ القرار.

    وما يُكتب بالقرار **دليل**: البصمةُ تُقارَن بما خُتم عند الإرسال،
    واللقطةُ والموقّعُ والعنوانُ والمتصفّح تُكتب مرّةً في `document_approvals`
    — انظر `DeliveryPackages::decide`. ونصُّ الإقرار يُعرض ويُحفظ بحرفه.
--}}
@php
    $rtl = \App\Support\Paper::rtl();
    $accepted = ($approval ?? null)?->decision === \App\Models\DocumentApproval::ACCEPTED;
    $files = $package->items->filter(fn ($i) => $i->kind === \App\Models\DeliveryPackageItem::FILE);
    $lines = $package->items->filter(fn ($i) => $i->kind === \App\Models\DeliveryPackageItem::ITEM);
    $size = static function (?int $bytes): string {
        if ($bytes === null) return '';
        if ($bytes >= 1048576) return number_format($bytes / 1048576, 1).' MB';
        if ($bytes >= 1024) return number_format($bytes / 1024).' KB';
        return $bytes.' B';
    };
@endphp
<!DOCTYPE html>
<html lang="{{ $rtl ? 'ar' : 'en' }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('حزمة تسليم') }} {{ $label }}</title>
    <style>
        html, body { margin: 0; padding: 0; background: #f5f5f4; font-family: Tahoma, Arial, sans-serif; color: #1f2937; }
        .wrap { padding: 12px; }
        @media (min-width: 640px) { .wrap { padding: 24px 12px 48px; } }
        .card { max-width: 794px; margin: 0 auto 16px; background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 20px; }
        .head { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; flex-wrap: wrap; }
        .head .co { font-weight: bold; font-size: 17px; }
        .head .co small { display: block; font-weight: normal; font-size: 12px; color: #6b7280; margin-top: 2px; }
        .head .no { text-align: end; font-size: 12px; color: #6b7280; }
        .head .no b { display: block; font-size: 15px; color: #111; direction: ltr; }
        h1 { font-size: 20px; margin: 14px 0 4px; }
        .muted { color: #6b7280; font-size: 13px; line-height: 1.7; }
        h2 { font-size: 14px; margin: 18px 0 8px; color: #374151; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 8px 6px; border-bottom: 1px solid #f3f4f6; text-align: start; vertical-align: top; }
        th { color: #6b7280; font-weight: normal; font-size: 12px; }
        td.n { text-align: end; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .file { display: flex; gap: 10px; align-items: center; padding: 10px 0; border-bottom: 1px solid #f3f4f6; }
        .file:last-child { border-bottom: 0; }
        .file a { color: #1d4ed8; text-decoration: none; font-weight: bold; }
        .file .meta { font-size: 11px; color: #9ca3af; direction: ltr; }
        .file .desc { font-size: 12px; color: #6b7280; margin-top: 2px; }
        .notes { white-space: pre-wrap; font-size: 13px; line-height: 1.8; background: #fafaf9; border-radius: 10px; padding: 12px; }
        .decide h2 { margin: 0 0 6px; font-size: 17px; color: #111; }
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
        .done { max-width: 794px; margin: 0 auto 16px; border-radius: 16px; padding: 20px; font-size: 14px; line-height: 1.8; }
        .done.ok { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .done.no { background: #f9fafb; color: #374151; border: 1px solid #e5e7eb; }
        .done code { font-family: monospace; font-size: 11px; word-break: break-all; }
        .hint { max-width: 794px; margin: 0 auto 32px; text-align: center; font-size: 12px; color: #9ca3af; line-height: 1.7; }
        .reason { display: none; margin-top: 10px; }
        .stamp { font-size: 11px; color: #9ca3af; margin-top: 14px; direction: ltr; text-align: end; }
    </style>
</head>
<body>
    @if (($approval ?? null) !== null)
        <div class="wrap">
            <div class="done {{ $accepted ? 'ok' : 'no' }}">
                @if ($accepted)
                    <strong>{{ __('تمّ قبول التسليم — شكرًا لكم.') }}</strong><br>
                    {{ __('سُجّل القبول باسم :name في :at.', ['name' => $approval->signer_name, 'at' => $approval->decided_at?->format('Y-m-d H:i')]) }}<br>
                    <span dir="ltr"><code>{{ __('بصمة المستند') }}: {{ $approval->content_hash }}</code></span>
                @else
                    <strong>{{ __('سُجّل ردّكم لهذا التسليم.') }}</strong><br>
                    {{ __('ستراجع :company ملاحظاتكم وتعود إليكم بحزمةٍ معدَّلة.', ['company' => $company]) }}
                @endif
            </div>
        </div>
    @elseif ($package->status === \App\Models\DeliveryPackage::DECLINED || $package->status === \App\Models\DeliveryPackage::ACCEPTED)
        <div class="wrap">
            <div class="done {{ $package->status === \App\Models\DeliveryPackage::ACCEPTED ? 'ok' : 'no' }}">
                <strong>{{ $package->status === \App\Models\DeliveryPackage::ACCEPTED ? __('تمّ قبول التسليم — شكرًا لكم.') : __('سُجّل ردّكم لهذا التسليم.') }}</strong>
            </div>
        </div>
    @endif

    <div class="wrap">
        <div class="card">
            <div class="head">
                <div class="co">
                    {{ $brand['name'] }}
                    @if ($brand['sub'] !== '')<small>{{ $brand['sub'] }}</small>@endif
                    @foreach ($brand['contact'] as $line)<small>{{ $line }}</small>@endforeach
                </div>
                <div class="no">
                    {{ __('حزمة تسليم') }}
                    <b>{{ $label }}</b>
                    @if ($package->sent_at)<span dir="ltr">{{ $package->sent_at->format('Y-m-d') }}</span>@endif
                </div>
            </div>

            <h1>{{ $package->title }}</h1>
            @if ($package->customer)
                <div class="muted">{{ __('إلى') }}: {{ $package->customer->legal_name ?: $package->customer->name }}</div>
            @endif
            @if (filled($package->description))
                <p class="muted">{{ $package->description }}</p>
            @endif

            @if ($lines->isNotEmpty())
                <h2>{{ __('ما سُلِّم') }}</h2>
                <table>
                    <thead><tr><th>{{ __('البند') }}</th><th class="n">{{ __('الكمية') }}</th></tr></thead>
                    <tbody>
                        @foreach ($lines as $i)
                            <tr>
                                <td>{{ $i->title }}@if (filled($i->description))<div class="muted">{{ $i->description }}</div>@endif</td>
                                <td class="n">{{ $i->quantity === null ? '—' : rtrim(rtrim(number_format($i->quantity, 3, '.', ''), '0'), '.') }} {{ $i->unit }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if ($files->isNotEmpty())
                <h2>{{ __('الملفّات والتقارير') }}</h2>
                @foreach ($files as $i)
                    <div class="file">
                        <div>
                            <a href="{{ $fileUrl($i->id) }}" target="_blank" rel="noopener">{{ $i->title }}</a>
                            <span class="meta">{{ $i->name }} · {{ $size($i->size) }}</span>
                            @if (filled($i->description))<div class="desc">{{ $i->description }}</div>@endif
                        </div>
                    </div>
                @endforeach
            @endif

            @if (filled($package->client_notes))
                <h2>{{ __('ملاحظات التسليم') }}</h2>
                <div class="notes">{{ $package->client_notes }}</div>
            @endif

            @if ($package->content_hash)
                <div class="stamp">{{ __('بصمة المستند') }}: {{ $package->content_hash }}</div>
            @endif
        </div>
    </div>

    @if ($decidable && ($approval ?? null) === null)
        <div class="wrap">
            <form class="card decide" method="post" action="{{ $decideUrl }}" id="decide">
                @csrf
                <h2>{{ __('اعتماد التسليم') }}</h2>
                <p class="lead">{{ __('راجع ما سُلِّم والملفّات أعلاه، ثم اكتب اسمك واختر. يُسجَّل القرار بوقته وبصمة المستند كما قرأته.') }}</p>

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
                        <input type="email" id="email" name="email" maxlength="120" dir="ltr" value="{{ old('email', $package->contact_email) }}">
                    </div>
                    <div>
                        <label for="phone">{{ __('الهاتف') }}</label>
                        <input type="tel" id="phone" name="phone" maxlength="40" dir="ltr" value="{{ old('phone') }}">
                    </div>
                </div>

                <div class="reason" id="reason">
                    <label for="decline_reason">{{ __('ما الذي ينقص أو يحتاج تصحيحًا؟') }} *</label>
                    <textarea id="decline_reason" name="decline_reason" maxlength="500">{{ old('decline_reason') }}</textarea>
                </div>

                <label class="consent">
                    <input type="checkbox" name="consent" value="1" id="consent" {{ old('consent') ? 'checked' : '' }}>
                    <span>{{ $consent }}</span>
                </label>

                <input type="hidden" name="decision" id="decision" value="accepted">

                <div class="actions">
                    <button type="submit" class="accept" onclick="document.getElementById('decision').value='accepted'">
                        {{ __('أقبل التسليم') }}
                    </button>
                    <button type="button" class="decline" id="declineBtn">
                        {{ __('أطلب تصحيحًا') }}
                    </button>
                </div>
            </form>
            <p class="hint">{{ __('قبولٌ إلكتروني موثَّق بالبصمة والوقت والعنوان — وليس توقيعًا معتمَدًا قانونًا.') }}</p>
        </div>

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
                        alert(@json(__('لا يُقبل التسليم بلا الإقرار.')));
                    }
                });

                @if (old('decision') === 'declined')
                    reason.style.display = 'block';
                @endif
            })();
        </script>
    @endif
</body>
</html>
