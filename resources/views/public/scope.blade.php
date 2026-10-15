{{--
    نطاقُ العمل كما يفتحه العميل — يقرؤه كاملًا ويوقّعه من هاتفه.

    ═══ صفحةٌ للهاتف أوّلًا ═══

    يصل رابطُها على واتساب غالبًا، فتُقرأ بإبهامٍ واحد: عمودٌ واحد،
    وأقسامٌ بعناوين، وجدولُ بنودٍ يصير بطاقاتٍ على الشاشة الضيّقة. ولا
    Inertia ولا أصولُ البناء — ملفٌّ واحد يُفتح على شبكةٍ ضعيفة.

    ═══ وما يُوقَّع هو ما يُرى ═══

    البصمةُ تُطبع في أسفل الورقة وتُرسَل مع التوقيع (`hash`)، فلا يُوقَّع
    إلا ما قرأه الموقّع بعينه — انظر `ScopesOfWork::decide`. والأسعارُ
    والقيمةُ تظهر حين قرّر من كتب النطاق إظهارَها وحدَه، وهي داخلةٌ في
    البصمة حينها لا غيرها.

    وهو **قبولٌ إلكترونيٌّ موثَّق** لا توقيعٌ معتمَدٌ قانونًا — كقبول العرض.

    و`$preview`: الصفحةُ نفسُها يراها الفريقُ قبل الإرسال — بلا نموذج توقيع.
--}}
@php
    $rtl = \App\Support\Paper::rtl();
    $accepted = ($approval ?? null)?->decision === \App\Models\DocumentApproval::ACCEPTED;
    $show = (bool) $scope->show_value;
    $items = (array) ($snap['items'] ?? []);
    $requirements = (array) ($snap['requirements'] ?? []);
    $money = fn ($v) => \App\Support\Money::format((float) $v, $currency);
    $lines = fn (?string $text) => array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $text) ?: []), fn ($l) => $l !== ''));
    $dates = trim(($scope->starts_at?->format('Y-m-d') ?? '').($scope->ends_at && $scope->starts_at && $scope->ends_at->ne($scope->starts_at) ? ' → '.$scope->ends_at->format('Y-m-d') : ''));
    $stateLabel = match ($scope->status) {
        \App\Models\ScopeOfWork::SIGNED => __('موقَّع ومعتمد'),
        \App\Models\ScopeOfWork::DECLINED => __('طُلب تعديله'),
        \App\Models\ScopeOfWork::SUPERSEDED => __('نسخة قديمة'),
        \App\Models\ScopeOfWork::SENT => __('بانتظار توقيعكم'),
        default => __('مسودة'),
    };
@endphp
<!DOCTYPE html>
<html lang="{{ $rtl ? 'ar' : 'en' }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('نطاق العمل') }} {{ $label }} — {{ $brand['name'] }}</title>
    <style>
        :root { --ink: #0f172a; --muted: #6b7280; --faint: #9ca3af; --rule: #e8e8e8; --wash: #fafafa; --brand: {{ $primary }}; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 12px 12px 40px; background: #f5f5f4; color: var(--ink);
            font: 15px/1.7 'IBM Plex Sans Arabic', -apple-system, 'Segoe UI', system-ui, Tahoma, Arial, sans-serif; }
        .sheet { max-width: 760px; margin: 0 auto; background: #fff; border: 1px solid var(--rule); border-radius: 16px; overflow: hidden; }
        .band { height: 6px; background: var(--brand); }
        .pad { padding: 18px; }
        @media (min-width: 640px) { body { padding: 24px 16px 56px; } .pad { padding: 24px 28px; } }
        .head { display: flex; align-items: center; gap: 12px; border-bottom: 1px solid var(--rule); }
        .logo { max-height: 48px; max-width: 120px; }
        .company { font-size: 17px; font-weight: 700; margin: 0; }
        .muted { color: var(--muted); } .faint { color: var(--faint); } .sm { font-size: 13px; } .xs { font-size: 12px; }
        .ltr { direction: ltr; unicode-bidi: isolate; }
        .title { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 8px; }
        h1 { font-size: 22px; margin: 0; }
        .pill { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; background: #eff6ff; color: #1d4ed8; }
        .pill.ok { background: #ecfdf5; color: #047857; } .pill.warn { background: #fffbeb; color: #b45309; } .pill.void { background: #f5f5f4; color: #6b7280; }
        section { border-top: 1px solid var(--rule); }
        h2 { font-size: 15px; margin: 0 0 10px; }
        dl.facts { display: grid; grid-template-columns: 1fr; gap: 8px 20px; margin: 0; }
        @media (min-width: 640px) { dl.facts { grid-template-columns: 1fr 1fr; } }
        dl.facts div { display: flex; justify-content: space-between; gap: 12px; border-bottom: 1px dashed #f0f0f0; padding-bottom: 6px; }
        dl.facts dt { color: var(--muted); font-size: 13px; } dl.facts dd { margin: 0; font-weight: 600; text-align: end; }
        .items { width: 100%; border-collapse: collapse; font-size: 14px; }
        .items th { text-align: start; font-size: 12px; color: var(--muted); font-weight: 600; border-bottom: 1px solid var(--rule); padding: 6px 4px; }
        .items td { border-bottom: 1px solid #f4f4f5; padding: 8px 4px; vertical-align: top; }
        .num { text-align: end; white-space: nowrap; direction: ltr; unicode-bidi: isolate; }
        @media (max-width: 520px) {
            .items thead { display: none; }
            .items tr { display: block; border-bottom: 1px solid #f4f4f5; padding: 8px 0; }
            .items td { display: flex; justify-content: space-between; border: 0; padding: 2px 0; }
            .items td::before { content: attr(data-label); color: var(--muted); font-size: 12px; }
            .items td:first-child { font-weight: 600; } .items td:first-child::before { content: none; }
        }
        ul.list { margin: 0; padding-inline-start: 20px; } ul.list li { margin: 4px 0; }
        .prose { white-space: pre-line; margin: 0; }
        .value { display: flex; justify-content: space-between; align-items: baseline; background: var(--wash); border-radius: 10px; padding: 12px 14px; }
        .value .v { font-size: 20px; font-weight: 700; direction: ltr; }
        .hash { font-family: ui-monospace, monospace; font-size: 11px; word-break: break-all; }
        .banner { max-width: 760px; margin: 0 auto 12px; border-radius: 14px; padding: 14px 16px; font-size: 14px; line-height: 1.8; }
        .banner.ok { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .banner.warn { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
        .banner.info { background: #eff6ff; color: #1e3a8a; border: 1px solid #bfdbfe; }
        .decide { max-width: 760px; margin: 14px auto 0; background: #fff; border: 1px solid var(--rule); border-radius: 16px; padding: 18px; }
        .decide h2 { font-size: 17px; }
        .grid { display: grid; grid-template-columns: 1fr; gap: 10px; }
        @media (min-width: 640px) { .grid { grid-template-columns: 1fr 1fr; } }
        label { display: block; font-size: 12px; color: #374151; margin-bottom: 4px; }
        input[type=text], input[type=email], input[type=tel], textarea { width: 100%; border: 1px solid #d1d5db; border-radius: 10px; padding: 11px 12px; font-size: 16px; font-family: inherit; background: #fff; }
        textarea { min-height: 84px; resize: vertical; }
        .consent { display: flex; gap: 10px; align-items: flex-start; margin: 14px 0; font-size: 13.5px; line-height: 1.8; color: #374151; }
        .consent input { margin-top: 6px; width: 18px; height: 18px; flex: 0 0 auto; }
        .actions { display: flex; flex-direction: column; gap: 10px; margin-top: 8px; }
        @media (min-width: 640px) { .actions { flex-direction: row; } }
        button { border: 0; border-radius: 12px; padding: 14px 22px; font-size: 16px; font-weight: 700; cursor: pointer; font-family: inherit; }
        .accept { background: #111; color: #fff; } .revise { background: #fff; color: #b45309; border: 1px solid #fde68a; }
        .error { background: #fef2f2; color: #b91c1c; border-radius: 10px; padding: 10px 12px; font-size: 13px; margin-bottom: 12px; }
        .reason { display: none; margin-top: 10px; }
        footer { max-width: 760px; margin: 14px auto 0; text-align: center; }
    </style>
</head>
<body>
    @if ($preview)
        <div class="banner info"><strong>{{ __('معاينة') }}</strong> — {{ __('هذا ما سيراه العميل عند فتح الرابط. لم يُرسل بعد.') }}</div>
    @endif

    @if (session('decided') === 'accepted' || $accepted)
        <div class="banner ok">
            <strong>{{ __('تم اعتماد نطاق العمل — شكرًا لكم.') }}</strong><br>
            @if ($approval)
                {{ __('سُجّل التوقيع باسم :name في :at.', ['name' => $approval->signer_name, 'at' => $approval->decided_at?->format('Y-m-d H:i')]) }}<br>
            @endif
            {{ __('ستتواصل معكم :company لترتيب الخطوة التالية.', ['company' => $company]) }}
        </div>
    @elseif (($approval ?? null) !== null)
        <div class="banner warn">
            <strong>{{ __('سُجّل طلبكم تعديل نطاق العمل.') }}</strong><br>
            {{ __('سترسل لكم :company نسخةً معدّلة للمراجعة.', ['company' => $company]) }}
        </div>
    @elseif ($superseded)
        <div class="banner warn">
            <strong>{{ __('هذه نسخةٌ قديمة من نطاق العمل.') }}</strong><br>
            {{ __('أُرسلت إليكم نسخةٌ أحدث — اطلبوا رابطها من :company. لا تُوقَّع هذه النسخة.', ['company' => $company]) }}
        </div>
    @endif

    <main class="sheet">
        <div class="band"></div>

        <div class="pad head">
            @if ($brand['logo'])
                <img class="logo" src="{{ $brand['logo'] }}" alt="">
            @endif
            <div>
                <p class="company">{{ $brand['name'] }}</p>
                @if ($brand['sub'] !== '')
                    <div class="muted xs">{{ $brand['sub'] }}</div>
                @endif
            </div>
        </div>

        <div class="pad">
            <div class="title">
                <h1>{{ __('نطاق العمل') }}</h1>
                <span class="pill {{ $accepted ? 'ok' : (in_array($scope->status, ['declined', 'superseded'], true) ? 'warn' : '') }}">{{ $stateLabel }}</span>
            </div>
            <div class="muted sm">
                <span class="ltr">{{ $label }}</span>
                · {{ __('النسخة :n', ['n' => $scope->revision]) }}
                @if ($scope->sent_at)
                    · {{ __('أُرسل في') }} <span class="ltr">{{ $scope->sent_at->format('Y-m-d') }}</span>
                @endif
            </div>
        </div>

        <section class="pad">
            <h2>{{ __('بيانات العميل') }}</h2>
            <dl class="facts">
                <div><dt>{{ __('العميل') }}</dt><dd>{{ $scope->customer_name ?: '—' }}</dd></div>
                @if ($scope->contact_person)<div><dt>{{ __('جهة الاتصال') }}</dt><dd>{{ $scope->contact_person }}</dd></div>@endif
                @if ($scope->contact_phone)<div><dt>{{ __('الهاتف') }}</dt><dd class="ltr">{{ $scope->contact_phone }}</dd></div>@endif
                @if ($scope->contact_email)<div><dt>{{ __('البريد الإلكتروني') }}</dt><dd class="ltr">{{ $scope->contact_email }}</dd></div>@endif
            </dl>
        </section>

        <section class="pad">
            <h2>{{ __('المشروع والفعالية') }}</h2>
            <dl class="facts">
                <div><dt>{{ __('المشروع') }}</dt><dd>{{ $scope->project_name }}</dd></div>
                @if ($scope->event_type)<div><dt>{{ __('نوع الفعالية') }}</dt><dd>{{ $scope->event_type }}</dd></div>@endif
                <div><dt>{{ __('التاريخ') }}</dt><dd class="ltr">{{ $dates !== '' ? $dates : '—' }}</dd></div>
                @if ($scope->schedule_notes)<div><dt>{{ __('الوقت') }}</dt><dd>{{ $scope->schedule_notes }}</dd></div>@endif
                <div><dt>{{ __('الموقع') }}</dt><dd>{{ $scope->location ?: '—' }}</dd></div>
                @if (! empty($snap['quotation']['label']))<div><dt>{{ __('مرجع عرض السعر') }}</dt><dd class="ltr">{{ $snap['quotation']['label'] }}</dd></div>@endif
            </dl>
        </section>

        <section class="pad">
            <h2>{{ __('الخدمات والبنود المتفق عليها') }}</h2>
            @if ($items === [])
                <p class="muted sm">{{ __('لا بنود.') }}</p>
            @else
                <table class="items">
                    <thead>
                        <tr>
                            <th>{{ __('البيان') }}</th>
                            <th class="num">{{ __('الكمية') }}</th>
                            @if ($show)
                                <th class="num">{{ __('سعر الوحدة') }}</th>
                                <th class="num">{{ __('المبلغ') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($items as $item)
                            <tr>
                                <td>{{ $item['description'] }}</td>
                                <td class="num" data-label="{{ __('الكمية') }}">{{ rtrim(rtrim((string) $item['quantity'], '0'), '.') }} {{ $item['unit'] }}</td>
                                @if ($show)
                                    <td class="num" data-label="{{ __('سعر الوحدة') }}">{{ $money($item['unit_price'] ?? 0) }}</td>
                                    <td class="num" data-label="{{ __('المبلغ') }}">{{ $money($item['line_total'] ?? 0) }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            @if (! empty($snap['change_orders']))
                <p class="faint xs" style="margin-top:8px">{{ __('يشمل أوامر التغيير المعتمدة: :list', ['list' => implode('، ', $snap['change_orders'])]) }}</p>
            @endif
        </section>

        @if ($requirements !== [])
            <section class="pad">
                <h2>{{ __('المتطلبات') }}</h2>
                <ul class="list">
                    @foreach ($requirements as $r)
                        <li><strong>{{ $r['title'] }}</strong>@if (($r['details'] ?? '') !== '') — <span class="muted">{{ $r['details'] }}</span>@endif</li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if (filled($scope->description))
            <section class="pad">
                <h2>{{ __('وصف نطاق التنفيذ') }}</h2>
                <p class="prose">{{ $scope->description }}</p>
            </section>
        @endif

        @foreach ([
            ['inclusions', __('ما يشمله العمل')],
            ['exclusions', __('ما لا يشمله العمل')],
            ['company_responsibilities', __('مسؤوليات :company', ['company' => $company ?: 'Event Plus'])],
            ['client_responsibilities', __('مسؤوليات العميل')],
        ] as [$field, $heading])
            @if ($lines($scope->{$field}) !== [])
                <section class="pad">
                    <h2>{{ $heading }}</h2>
                    <ul class="list">
                        @foreach ($lines($scope->{$field}) as $line)
                            <li>{{ ltrim($line, '-•* ') }}</li>
                        @endforeach
                    </ul>
                </section>
            @endif
        @endforeach

        @if (filled($scope->terms))
            <section class="pad">
                <h2>{{ __('ملاحظات وشروط خاصة') }}</h2>
                <p class="prose">{{ $scope->terms }}</p>
            </section>
        @endif

        @if ($show)
            <section class="pad">
                <div class="value">
                    <span class="muted sm">{{ __('القيمة المرجعية للنطاق') }}</span>
                    <span class="v">{{ $money($snap['value'] ?? 0) }}</span>
                </div>
            </section>
        @endif

        @if ($scope->content_hash)
            <section class="pad">
                <div class="faint xs">{{ __('بصمة المستند') }}</div>
                <div class="hash ltr">{{ $scope->content_hash }}</div>
                @if ($approval)
                    <div class="faint xs" style="margin-top:6px">
                        {{ $accepted ? __('وقّعه :name', ['name' => $approval->signer_name]) : __('طلب تعديله :name', ['name' => $approval->signer_name]) }}
                        @if ($approval->signer_title) — {{ $approval->signer_title }} @endif
                        · <span class="ltr">{{ $approval->decided_at?->format('Y-m-d H:i') }}</span>
                    </div>
                @endif
            </section>
        @endif
    </main>

    @if (! $preview && $decidable && ($approval ?? null) === null)
        <form class="decide" method="post" action="{{ $decideUrl }}" id="decide">
            @csrf
            <h2>{{ __('الموافقة والتوقيع') }}</h2>
            <p class="muted sm" style="margin-top:0">{{ __('اقرأ نطاق العمل أعلاه كاملًا، ثم اكتب اسمك ووافق. يُسجَّل التوقيع بوقته وبصمة المستند كما قرأته.') }}</p>

            @if ($errors->any())
                <div class="error">{{ $errors->first() }}</div>
            @endif

            <div class="grid">
                <div>
                    <label for="name">{{ __('اسم الموقّع') }} *</label>
                    <input type="text" id="name" name="name" required minlength="2" maxlength="120" autocomplete="name" value="{{ old('name', $scope->contact_person) }}">
                </div>
                <div>
                    <label for="title">{{ __('الصفة الوظيفية') }}</label>
                    <input type="text" id="title" name="title" maxlength="120" autocomplete="organization-title" value="{{ old('title') }}">
                </div>
                <div>
                    <label for="email">{{ __('البريد الإلكتروني') }}</label>
                    <input type="email" id="email" name="email" maxlength="120" dir="ltr" autocomplete="email" value="{{ old('email', $scope->contact_email) }}">
                </div>
                <div>
                    <label for="phone">{{ __('الهاتف') }}</label>
                    <input type="tel" id="phone" name="phone" maxlength="40" dir="ltr" autocomplete="tel" value="{{ old('phone', $scope->contact_phone) }}">
                </div>
            </div>

            <div class="reason" id="reason">
                <label for="decline_reason">{{ __('ما يحتاج تعديلًا') }} *</label>
                <textarea id="decline_reason" name="decline_reason" maxlength="500">{{ old('decline_reason') }}</textarea>
            </div>

            <label class="consent">
                <input type="checkbox" name="consent" value="1" id="consent" {{ old('consent') ? 'checked' : '' }}>
                <span>{{ $consent }}</span>
            </label>

            <input type="hidden" name="decision" id="decision" value="accepted">
            <input type="hidden" name="hash" value="{{ $scope->content_hash }}">

            <div class="actions">
                <button type="submit" class="accept" id="acceptBtn">{{ __('موافقة وتوقيع') }}</button>
                <button type="button" class="revise" id="reviseBtn">{{ __('يحتاج تعديل') }}</button>
            </div>
            <p class="faint xs" style="margin-bottom:0">{{ __('قبولٌ إلكتروني موثَّق بالبصمة والوقت والعنوان — وليس توقيعًا معتمَدًا قانونًا.') }}</p>
        </form>

        {{--
            سطورٌ بلا مكتبة: «يحتاج تعديل» يُظهر حقلَ الملاحظة ويبدّل القرار؛
            وزرُّ التوقيع يُعطَّل بعد أوّل ضغطة فلا يُرسَل التوقيعُ مرّتين —
            والخادمُ يردّ الثانيةَ على كلّ حال.
        --}}
        <script>
            (function () {
                var form = document.getElementById('decide');
                var revise = document.getElementById('reviseBtn');
                var accept = document.getElementById('acceptBtn');
                var reason = document.getElementById('reason');
                var field = document.getElementById('decline_reason');
                var consent = document.getElementById('consent');
                var decision = document.getElementById('decision');

                revise.addEventListener('click', function () {
                    if (reason.style.display !== 'block') { reason.style.display = 'block'; field.focus(); return; }
                    if (field.value.trim() === '') { field.focus(); return; }
                    decision.value = 'declined';
                    revise.disabled = true; accept.disabled = true;
                    form.submit();
                });

                form.addEventListener('submit', function (e) {
                    if (decision.value === 'accepted' && !consent.checked) {
                        e.preventDefault();
                        consent.focus();
                        alert(@json(__('لا يُعتمد نطاق العمل بلا الإقرار.')));
                        return;
                    }
                    accept.disabled = true; revise.disabled = true;
                });

                @if (old('decision') === 'declined')
                    reason.style.display = 'block';
                @endif
            })();
        </script>
    @endif

    <footer class="faint xs">{{ $brand['name'] }} — {{ __('نطاق العمل') }}</footer>
</body>
</html>
