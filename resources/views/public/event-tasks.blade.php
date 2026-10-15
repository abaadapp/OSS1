{{--
    «مهامي في الفعاليّة» — ما يفتحه زرُّ «عرض المهام» في رسالة واتساب.

    صفحةٌ قائمةٌ بذاتها كأخواتها في `public/`: لا Inertia ولا أصولُ البناء،
    تُفتح في متصفّح واتساب على شبكةٍ ضعيفة وتعمل بلا JavaScript. تقرأ المهامَّ
    لحظتَها — ما أُنجز من أيّ بابٍ يظهر منجزًا — و«تمّت» نموذجٌ يُرسَل إلى بابٍ
    واحد هو نفسُه الذي تمرّ به شاشةُ المشروع.
--}}
@php
    $rtl = \App\Support\Paper::rtl();
    $start = $operation->event_starts_at?->copy()->setTimezone($timezone);
    $end = $operation->event_ends_at?->copy()->setTimezone($timezone);
    $priority = ['high' => __('عاجلة'), 'normal' => __('عادية'), 'low' => __('منخفضة')];
@endphp
<!DOCTYPE html>
<html lang="{{ $rtl ? 'ar' : 'en' }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('مهامي في الفعالية') }} — {{ $operation->name }}</title>
    <style>
        html, body { margin: 0; padding: 0; background: #f5f5f4; font-family: Tahoma, Arial, sans-serif; color: #1f2937; }
        .wrap { padding: 12px; max-width: 640px; margin: 0 auto; }
        @media (min-width: 640px) { .wrap { padding: 24px 12px 48px; } }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 18px; margin-bottom: 14px; }
        .co { font-size: 12px; color: #6b7280; }
        h1 { font-size: 20px; margin: 6px 0 2px; color: #111; }
        .project { font-size: 13px; color: #6b7280; margin-bottom: 12px; }
        dl { display: grid; grid-template-columns: auto 1fr; gap: 6px 12px; margin: 0; font-size: 13px; }
        dt { color: #6b7280; }
        dd { margin: 0; color: #111; }
        .ltr { direction: ltr; unicode-bidi: isolate; }
        .progress { display: flex; justify-content: space-between; align-items: center; font-size: 13px; color: #374151; margin-bottom: 10px; }
        .progress b { font-size: 16px; color: #111; }
        .bar { height: 6px; border-radius: 99px; background: #f3f4f6; overflow: hidden; }
        .bar span { display: block; height: 100%; background: #059669; }
        ul { list-style: none; margin: 0; padding: 0; }
        li.task { display: flex; gap: 12px; align-items: flex-start; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #f3f4f6; }
        li.task:last-child { border-bottom: 0; }
        .title { font-size: 15px; font-weight: bold; color: #111; line-height: 1.5; }
        .task.done .title { color: #9ca3af; text-decoration: line-through; }
        .meta { font-size: 12px; color: #6b7280; margin-top: 3px; }
        .desc { font-size: 12px; color: #6b7280; margin-top: 3px; line-height: 1.6; white-space: pre-wrap; }
        .chip { display: inline-block; border-radius: 99px; padding: 1px 8px; font-size: 11px; margin-inline-start: 6px; vertical-align: middle; }
        .chip.high { background: #fef2f2; color: #b91c1c; }
        .chip.done { background: #ecfdf5; color: #047857; }
        form { margin: 0; flex-shrink: 0; }
        button { border: 0; border-radius: 10px; padding: 10px 16px; font-size: 14px; font-weight: bold; cursor: pointer; font-family: inherit; min-width: 84px; }
        .finish { background: #111; color: #fff; }
        .undo { background: #fff; color: #374151; border: 1px solid #e5e7eb; font-weight: normal; }
        .flash { border-radius: 12px; padding: 10px 12px; font-size: 13px; margin-bottom: 12px; }
        .flash.ok { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .flash.no { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .empty { font-size: 13px; color: #9ca3af; text-align: center; padding: 16px 0; }
        .hint { text-align: center; font-size: 12px; color: #9ca3af; line-height: 1.7; margin: 6px 0 24px; }
    </style>
</head>
<body>
<div class="wrap">
    @if (session('event_tasks_ok'))
        <div class="flash ok">{{ session('event_tasks_ok') }}</div>
    @endif
    @if (session('event_tasks_error'))
        <div class="flash no">{{ session('event_tasks_error') }}</div>
    @endif

    <div class="card">
        <div class="co">{{ $company }} · {{ __('مهامي في الفعالية') }} · {{ $employee->name }}</div>
        <h1>{{ $operation->name }}</h1>
        @if ($project)
            <div class="project">{{ __('المشروع') }}: {{ $project->name }}</div>
        @endif
        <dl>
            <dt>{{ __('التاريخ') }}</dt>
            <dd class="ltr">{{ $start?->format('Y-m-d') ?? '—' }}</dd>
            <dt>{{ __('الوقت') }}</dt>
            <dd class="ltr">{{ $start?->format('H:i') ?? '—' }}{{ $end ? ' – '.$end->format('H:i') : '' }}</dd>
            <dt>{{ __('الموقع') }}</dt>
            <dd>{{ $operation->venue_name ?: '—' }}@if ($operation->venue_address) — {{ $operation->venue_address }}@endif</dd>
            <dt>{{ __('مسؤول العملية') }}</dt>
            <dd>{{ $lead?->name ?? '—' }}</dd>
        </dl>
    </div>

    <div class="card">
        <div class="progress">
            <span>{{ __('مهامي') }}</span>
            <span><b>{{ $open }}</b> {{ __('مفتوحة من :n', ['n' => $tasks->count()]) }}</span>
        </div>
        <div class="bar"><span style="width: {{ $tasks->count() > 0 ? round(100 * ($tasks->count() - $open) / $tasks->count()) : 0 }}%"></span></div>

        @if ($tasks->isEmpty())
            <p class="empty">{{ __('لا مهام لك في هذه الفعالية') }}</p>
        @else
            <ul>
                @foreach ($tasks as $task)
                    @php $done = $task->status === \App\Models\ProjectTask::DONE; @endphp
                    <li class="task {{ $done ? 'done' : '' }}">
                        <div>
                            <div class="title">
                                {{ $task->title }}
                                @if ($done)
                                    <span class="chip done">{{ __('تمت') }}</span>
                                @elseif ($task->priority === 'high')
                                    <span class="chip high">{{ $priority['high'] }}</span>
                                @endif
                            </div>
                            <div class="meta">
                                {{ __('الأولوية') }}: {{ $priority[$task->priority] ?? $task->priority }}
                                @if ($task->due_at) · {{ __('الموعد') }}: <span class="ltr">{{ $task->due_at->format('Y-m-d') }}</span>@endif
                                @if ($done && $task->completed_at) · {{ __('أُنجزت') }}: <span class="ltr">{{ $task->completed_at->copy()->setTimezone($timezone)->format('Y-m-d H:i') }}</span>@endif
                            </div>
                            @if ($task->description)
                                <div class="desc">{{ $task->description }}</div>
                            @endif
                        </div>
                        @if ($writable)
                            <form method="POST" action="{{ route($done ? 'event-tasks.reopen' : 'event-tasks.done', [$token, $task->id]) }}">
                                @csrf
                                <button type="submit" class="{{ $done ? 'undo' : 'finish' }}">{{ $done ? __('تراجع') : __('تمت') }}</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <p class="hint">{{ __('هذه الصفحة تقرأ مهامك الآن — ما تنجزه يُسجَّل فورًا في النظام، ولا يلزم أن تتغيّر رسالة واتساب.') }}</p>
</div>
</body>
</html>
