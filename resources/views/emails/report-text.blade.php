{{--
    Plain-text version of the report. Same data as report.blade.php ($view comes from ReportViewData).
    {!! !!} is used on purpose: this is not HTML, so nothing is escaped.
--}}
@php
    $arrow = fn (array $c) => trim(($c['arrow'] !== '' ? $c['arrow'].' ' : '').$c['text']);
    $line = str_repeat('-', 56);
@endphp
{!! strtoupper($view['propertyName']) !!}
{!! $view['title'] !!}: {!! $view['periodLabel'] !!}
{!! $line !!}
@if (! empty($view['insight']['headline']))

TRAFFIC QUALITY INSIGHT
{!! $view['insight']['headline'] !!}
@foreach ($view['insight']['bullets'] as $bullet)
 * {!! $bullet !!}
@endforeach
@if (! empty($view['insight']['caution']))

{!! $view['insight']['caution'] !!}
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
 * {!! $row['name'] !!}: {!! $row['sessions'] !!} sessions ({!! $arrow($row['change']) !!}), engagement {!! $row['engagement'] !!}
@endforeach
@endif
@endif
@if ($view['countries'])

{!! strtoupper($view['countries']['title'] ?? 'Top countries by engaged sessions') !!}
@if ($view['countries']['unavailable'])
This section is temporarily unavailable.
@else
@foreach ($view['countries']['rows'] as $row)
 * {!! $row['name'] !!}: {!! $row['engaged'] !!} engaged sessions, {!! $row['sessions'] !!} sessions ({!! $row['share'] !!} of traffic, {!! $arrow($row['change']) !!})
@endforeach
@endif
@endif
@if ($view['countryPerformance'])

COUNTRY PERFORMANCE
@if ($view['countryPerformance']['unavailable'])
This section is temporarily unavailable.
@else
@foreach ($view['countryPerformance']['rows'] as $row)
 * {!! $row['country'] !!}@if ($row['detail'] !== '') ({!! $row['detail'] !!})@endif: {!! $row['sessions'] !!} sessions, {!! $row['engaged'] !!} engaged, engagement {!! $row['engagement'] !!}, booking visits {!! $row['bookingVisits'] !!}, {!! $row['quality'] !!}@if ($row['flag']) [{!! $row['flag'] !!}]@endif

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
 * {!! $row['source'] !!}: {!! $row['sessions'] !!} sessions ({!! $arrow($row['change']) !!})
@endforeach
@if ($view['ai']['countries'] !== '')
Top countries: {!! $view['ai']['countries'] !!}
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
 * {!! $tile['label'] !!}: {!! $tile['value'] !!} ({!! $arrow($tile['change']) !!})
@endforeach
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

