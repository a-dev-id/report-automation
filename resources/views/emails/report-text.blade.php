{{--
    Plain-text version of the report. Same data as report.blade.php ($view comes from ReportViewData): no colors, every change keeps
    its arrow and explicit + or - sign. {!! !!} is used on purpose: this is not HTML, so nothing is escaped.
--}}
@php
    $arrow = fn (array $c) => trim(($c['arrow'] !== '' ? $c['arrow'].' ' : '').$c['text']);
    $line = str_repeat('-', 56);
@endphp
{!! strtoupper($view['propertyName']) !!}
{!! $view['title'] !!}: {!! $view['periodLabel'] !!}
{!! $line !!}
@if (! empty($view['insight']['headline']['text']))

TRAFFIC QUALITY INSIGHT
{!! $view['insight']['headline']['text'] !!}
@foreach ($view['insight']['paragraphs'] as $paragraph)

{!! strtoupper($paragraph['title']) !!}
{!! $paragraph['text'] !!}
@endforeach
@if (! empty($view['insight']['priority']))

{!! $view['insight']['priority']['text'] !!}
@endif
@if (! empty($view['insight']['caution']))

{!! $view['insight']['caution']['text'] !!}
@endif
@endif
@if (! empty($view['kpis']))

KEY NUMBERS (vs {!! $view['previousLabel'] !!}@if ($view['yearAgoLabel']), vs {!! $view['yearAgoLabel'] !!}@endif)
@foreach ($view['kpis'] as $tile)
 * {!! $tile['label'] !!}: {!! $tile['value'] !!} ({!! $arrow($tile['change']) !!}@if ($tile['yearAgo']) / {!! $arrow($tile['yearAgo']) !!}@endif)
@endforeach
@endif
@if ($view['trend'])

{!! $view['monthly'] ? 'SESSIONS, LAST 12 MONTHS' : 'SESSIONS, LAST 8 WEEKS' !!}
@if ($view['trend']['unavailable'])
This section is temporarily unavailable.
@else
@foreach ($view['trend']['rows'] as $row)
 * {!! $row['label'] !!}: {!! $row['sessions'] !!} sessions, {!! $row['users'] !!} users
@endforeach
@endif
@endif
@if ($view['channels'])

TRAFFIC BY CHANNEL
@if ($view['channels']['unavailable'])
This section is temporarily unavailable.
@else
@foreach ($view['channels']['rows'] as $row)
 * {!! $row['name'] !!}: {!! $row['share'] !!} of sessions, {!! $row['sessions'] !!} sessions ({!! $arrow($row['change']) !!}), engagement {!! $row['engagement'] !!}
@endforeach
@if ($view['channels']['remaining'])
{!! $view['channels']['remaining'] !!}
@endif
@endif
@endif
@if ($view['countries'])

{!! strtoupper($view['countries']['title'] ?? 'Top countries by engaged sessions') !!}
@if ($view['countries']['unavailable'])
This section is temporarily unavailable.
@else
@foreach ($view['countries']['rows'] as $row)
 * {!! $row['name'] !!}: {!! $row['engaged'] !!} engaged sessions ({!! $row['share'] !!} of engaged, {!! $arrow($row['change']) !!}), {!! $row['sessions'] !!} sessions
@endforeach
@if ($view['countries']['dropped'])
{!! $view['countries']['dropped'] !!}
@endif
@endif
@endif
@if ($view['countryPerformance'])

COUNTRY PERFORMANCE
@if ($view['countryPerformance']['unavailable'])
This section is temporarily unavailable.
@else
@foreach ($view['countryPerformance']['rows'] as $row)
 * {!! $row['country'] !!}@if ($row['detail'] !== '') ({!! $row['detail'] !!})@endif: {!! $row['sessions'] !!} sessions ({!! $row['share'] !!} of all), {!! $row['engaged'] !!} engaged, engagement {!! $row['engagement'] !!}, booking visits {!! $row['bookingVisits'] !!}, {!! $row['quality'] !!}@if ($row['flag']) [{!! $row['flag'] !!}]@endif

@endforeach
@endif
@endif
@if ($view['paid'])

PAID ADVERTISING
@if ($view['paid']['unavailable'])
This section is temporarily unavailable.
@else
@foreach ($view['paid']['rows'] as $row)
 * {!! $row['label'] !!}: {!! $row['value'] !!} (previous {!! $row['previous'] !!}, {!! $arrow($row['change']) !!})
@endforeach
@endif
@endif
@if ($view['ai'])

TRAFFIC FROM AI ASSISTANTS
@if ($view['ai']['unavailable'])
This section is temporarily unavailable.
@else
@foreach ($view['ai']['rows'] as $row)
 * {!! $row['source'] !!}: {!! $row['share'] !!} of AI sessions, {!! $row['sessions'] !!} sessions ({!! $arrow($row['change']) !!})
@endforeach
@if (! empty($view['ai']['countryRows']))
Top countries (AI traffic):
@foreach ($view['ai']['countryRows'] as $row)
 * {!! $row['name'] !!}: {!! $row['share'] !!}, {!! $row['sessions'] !!} sessions ({!! $arrow($row['change']) !!})
@endforeach
@endif
@if ($view['ai']['dropped'])
{!! $view['ai']['dropped'] !!}
@endif
@if ($view['ai']['pages'] !== '')
Top landing pages: {!! $view['ai']['pages'] !!}
@endif
@endif
@endif
@if ($view['searchConsole'])

SEARCH CONSOLE
@if ($view['searchConsole']['unavailable'])
This section is temporarily unavailable.
@else
@foreach ($view['searchConsole']['tiles'] as $tile)
 * {!! $tile['label'] !!}@if ($tile['hint']) ({!! $tile['hint'] !!})@endif: {!! $tile['value'] !!} ({!! $arrow($tile['change']) !!})
@endforeach
@if ($view['searchConsole']['brandLine'])
{!! $view['searchConsole']['brandLine'] !!}
@endif
@if (! empty($view['searchConsole']['queries']))

Top queries (clicks / impressions / position)
@foreach ($view['searchConsole']['queries'] as $row)
 * {!! $row['name'] !!}: {!! $row['clicks'] !!} / {!! $row['impressions'] !!} / {!! $row['position'] !!}
@endforeach
@endif
@if (! empty($view['searchConsole']['pages']))

Top pages (clicks / impressions / position)
@foreach ($view['searchConsole']['pages'] as $row)
 * {!! $row['name'] !!}: {!! $row['clicks'] !!} / {!! $row['impressions'] !!} / {!! $row['position'] !!}
@endforeach
@endif
@endif
@endif
@if (! empty($view['notes']))

DATA NOTES
@foreach ($view['notes'] as $note)
 - {!! $note !!}
@endforeach
@endif

{!! $line !!}
{!! $view['sentBy'] !!}@if ($view['generatedAt']) | Generated {!! $view['generatedAt'] !!}@endif

