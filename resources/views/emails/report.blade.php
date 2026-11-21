{{--
    Traffic report email. Tables and inline CSS only (Gmail and Outlook). Max width 640px, one column.
    Every figure is pre-formatted by App\Services\Reports\ReportViewData, the same data feeds report-text.blade.php.

    Variables: $view (ReportViewData::make), $charts (name => PNG path|null), optional $inline (path => src, used
    by report:preview). In a real email, $message->embed() turns a file into a "cid:" image.
--}}
@php
    $embed = isset($message) ? fn ($path) => $message->embed($path) : ($inline ?? fn ($path) => $path);
    $src = fn (?string $path) => $path === null ? null : $embed($path);
    $brand = $view['brandColor'];
    $font = 'font-family:Arial, Helvetica, sans-serif;';
    $card = 'background:#ffffff; border:1px solid #E5E7EB; border-radius:6px;';
    $th = $font.' font-size:13px; font-weight:bold; color:#4B5563; text-align:left; padding:8px 6px; border-bottom:2px solid #E5E7EB;';
    $td = $font.' font-size:14px; color:#111827; padding:8px 6px; border-bottom:1px solid #F0F1F3; vertical-align:top;';
    $num = $td.' text-align:right; white-space:nowrap;';
    $thNum = $th.' text-align:right;';
@endphp
<!DOCTYPE html>
<html lang="{{ $view['language'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <title>{{ $view['subject'] }}</title>
</head>
<body style="margin:0; padding:0; background:#F3F4F6; {{ $font }} color:#111827;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F3F4F6;">
<tr><td align="center" style="padding:16px 8px;">
<table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:640px;">

    {{-- Header --}}
    <tr><td style="background:{{ $brand }}; padding:20px 24px; border-radius:6px 6px 0 0;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
            @if ($view['logoPath'])
                <td width="64" style="padding-right:16px; vertical-align:middle;">
                    <img src="{{ $src($view['logoPath']) }}" alt="{{ $view['propertyName'] }}" width="56" style="display:block; width:56px; height:auto; border:0;">
                </td>
            @endif
            <td style="vertical-align:middle;">
                <div style="{{ $font }} font-size:22px; font-weight:bold; color:#ffffff;">{{ $view['propertyName'] }}</div>
                <div style="{{ $font }} font-size:15px; color:#ffffff; opacity:0.95; padding-top:4px;">{{ $view['title'] }}: {{ $view['periodLabel'] }}</div>
            </td>
        </tr></table>
    </td></tr>

    <tr><td style="background:#F3F4F6; height:12px; line-height:12px; font-size:0;">&nbsp;</td></tr>

    {{-- 1. Traffic Quality Insight --}}
    @if (! empty($view['insight']['headline']))
    <tr><td style="{{ $card }} padding:20px 24px; border-left:5px solid {{ $brand }};">
        <div style="{{ $font }} font-size:13px; font-weight:bold; letter-spacing:0.5px; text-transform:uppercase; color:{{ $brand }};">Traffic Quality Insight</div>
        <div style="{{ $font }} font-size:17px; line-height:24px; font-weight:bold; color:#111827; padding:8px 0 4px;">{{ $view['insight']['headline'] }}</div>
        @if (! empty($view['insight']['bullets']))
            <ul style="margin:8px 0 0 0; padding-left:20px; {{ $font }} font-size:14px; line-height:21px; color:#1F2937;">
                @foreach ($view['insight']['bullets'] as $bullet)
                    <li style="margin:0 0 6px 0;">{{ $bullet }}</li>
                @endforeach
            </ul>
        @endif
        @if (! empty($view['insight']['caution']))
            <div style="{{ $font }} font-size:13px; line-height:19px; color:#6B7280; background:#F9FAFB; border-radius:4px; padding:10px 12px; margin-top:10px;">{{ $view['insight']['caution'] }}</div>
        @endif
    </td></tr>
    <tr><td style="height:12px; line-height:12px; font-size:0;">&nbsp;</td></tr>
    @endif

    {{-- 2. KPI tiles --}}
    @if (! empty($view['kpis']))
    <tr><td style="{{ $card }} padding:12px 10px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
            @foreach (array_chunk($view['kpis'], 2) as $pair)
                <tr>
                    @foreach ($pair as $tile)
                        <td width="50%" valign="top" style="padding:6px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F9FAFB; border-radius:6px;">
                                <tr><td style="padding:12px 14px;">
                                    <div style="{{ $font }} font-size:13px; color:#6B7280;">{{ $tile['label'] }}</div>
                                    <div style="{{ $font }} font-size:24px; font-weight:bold; color:#111827; padding:2px 0;">{{ $tile['value'] }}</div>
                                    <div style="{{ $font }} font-size:14px;">
                                        @include('emails.partials.change', ['change' => $tile['change']])
                                        <span style="color:#6B7280;"> vs {{ $view['previousLabel'] }}</span>
                                    </div>
                                    @if ($tile['yearAgo'])
                                        <div style="{{ $font }} font-size:14px;">
                                            @include('emails.partials.change', ['change' => $tile['yearAgo']])
                                            <span style="color:#6B7280;"> vs {{ $view['yearAgoLabel'] }}</span>
                                        </div>
                                    @endif
                                </td></tr>
                            </table>
                        </td>
                    @endforeach
                    @if (count($pair) === 1)<td width="50%" style="padding:6px;">&nbsp;</td>@endif
                </tr>
            @endforeach
        </table>
    </td></tr>
    <tr><td style="height:12px; line-height:12px; font-size:0;">&nbsp;</td></tr>
    @endif

    {{-- 3. Trend --}}
    @if ($view['trend'])
    <tr><td style="{{ $card }} padding:20px 24px;">
        @include('emails.partials.heading', ['title' => $view['monthly'] ? 'Sessions, last 12 months' : 'Sessions, last 8 weeks'])
        @if ($view['trend']['unavailable'])
            @include('emails.partials.unavailable')
        @elseif ($view['trend']['hasChart'])
            <img src="{{ $src($charts['trend']) }}" alt="Sessions trend" width="592" style="display:block; width:100%; max-width:592px; height:auto; border:0;">
        @else
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr><th style="{{ $th }}">{{ $view['monthly'] ? 'Month' : 'Week of' }}</th><th style="{{ $thNum }}">Sessions</th><th style="{{ $thNum }}">Users</th></tr>
                @foreach ($view['trend']['rows'] as $row)
                    <tr><td style="{{ $td }}">{{ $row['label'] }}</td><td style="{{ $num }}">{{ $row['sessions'] }}</td><td style="{{ $num }}">{{ $row['users'] }}</td></tr>
                @endforeach
            </table>
        @endif
    </td></tr>
    <tr><td style="height:12px; line-height:12px; font-size:0;">&nbsp;</td></tr>
    @endif

    {{-- 4. Channels --}}
    @if ($view['channels'])
    <tr><td style="{{ $card }} padding:20px 24px;">
        @include('emails.partials.heading', ['title' => 'Traffic by channel'])
        @if ($view['channels']['unavailable'])
            @include('emails.partials.unavailable')
        @else
            @if ($view['channels']['hasChart'])
                <img src="{{ $src($charts['channels']) }}" alt="Sessions by channel" width="592" style="display:block; width:100%; max-width:592px; height:auto; border:0; margin-bottom:12px;">
            @endif
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr><th style="{{ $th }}">Channel</th><th style="{{ $thNum }}">Sessions</th><th style="{{ $thNum }}">Change</th><th style="{{ $thNum }}">Engagement</th></tr>
                @foreach ($view['channels']['rows'] as $row)
                    <tr>
                        <td style="{{ $td }}">{{ $row['name'] }}</td>
                        <td style="{{ $num }}">{{ $row['sessions'] }}</td>
                        <td style="{{ $num }}">@include('emails.partials.change', ['change' => $row['change']])</td>
                        <td style="{{ $num }}">{{ $row['engagement'] }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </td></tr>
    <tr><td style="height:12px; line-height:12px; font-size:0;">&nbsp;</td></tr>
    @endif

    {{-- 5. Countries and Country Performance --}}
    @if ($view['countries'] || $view['countryPerformance'])
    <tr><td style="{{ $card }} padding:20px 24px;">
        @if ($view['countries'])
            @include('emails.partials.heading', ['title' => 'Top countries'])
            @if ($view['countries']['unavailable'])
                @include('emails.partials.unavailable')
            @elseif ($view['countries']['hasChart'])
                <img src="{{ $src($charts['countries']) }}" alt="Sessions by country" width="592" style="display:block; width:100%; max-width:592px; height:auto; border:0;">
            @else
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr><th style="{{ $th }}">Country</th><th style="{{ $thNum }}">Sessions</th><th style="{{ $thNum }}">Share</th><th style="{{ $thNum }}">Change</th></tr>
                    @foreach ($view['countries']['rows'] as $row)
                        <tr>
                            <td style="{{ $td }}">{{ $row['name'] }}</td>
                            <td style="{{ $num }}">{{ $row['sessions'] }}</td>
                            <td style="{{ $num }}">{{ $row['share'] }}</td>
                            <td style="{{ $num }}">@include('emails.partials.change', ['change' => $row['change']])</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        @endif

        @if ($view['countryPerformance'])
            <div style="height:16px; line-height:16px; font-size:0;">&nbsp;</div>
            @include('emails.partials.heading', ['title' => 'Country performance'])
            @if ($view['countryPerformance']['unavailable'])
                @include('emails.partials.unavailable')
            @else
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <th style="{{ $th }}">Country</th><th style="{{ $thNum }}">Sessions</th><th style="{{ $thNum }}">Engagement</th>
                        <th style="{{ $thNum }}">Booking visits</th><th style="{{ $th }}">Quality</th>
                    </tr>
                    @foreach ($view['countryPerformance']['rows'] as $row)
                        <tr>
                            <td style="{{ $td }}">{{ $row['country'] }}
                                @if ($row['detail'] !== '')<div style="{{ $font }} font-size:13px; color:#6B7280;">{{ $row['detail'] }}</div>@endif
                            </td>
                            <td style="{{ $num }}">{{ $row['sessions'] }}</td>
                            <td style="{{ $num }}">{{ $row['engagement'] }}</td>
                            <td style="{{ $num }}">{{ $row['bookingVisits'] }}</td>
                            <td style="{{ $td }}">{{ $row['quality'] }}
                                @if ($row['flag'])<div style="{{ $font }} font-size:13px; color:#B45309; font-weight:bold;">{{ $row['flag'] }}</div>@endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            @endif
        @endif
    </td></tr>
    <tr><td style="height:12px; line-height:12px; font-size:0;">&nbsp;</td></tr>
    @endif

    {{-- 6. Paid --}}
    @if ($view['paid'])
    <tr><td style="{{ $card }} padding:20px 24px;">
        @include('emails.partials.heading', ['title' => 'Paid advertising'])
        @if ($view['paid']['unavailable'])
            @include('emails.partials.unavailable')
        @else
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr><th style="{{ $th }}">Metric</th><th style="{{ $thNum }}">This {{ $view['monthly'] ? 'month' : 'week' }}</th><th style="{{ $thNum }}">Previous</th><th style="{{ $thNum }}">Change</th></tr>
                @foreach ($view['paid']['rows'] as $row)
                    <tr>
                        <td style="{{ $td }}">{{ $row['label'] }}</td>
                        <td style="{{ $num }}">{{ $row['value'] }}</td>
                        <td style="{{ $num }}">{{ $row['previous'] }}</td>
                        <td style="{{ $num }}">@include('emails.partials.change', ['change' => $row['change']])</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </td></tr>
    <tr><td style="height:12px; line-height:12px; font-size:0;">&nbsp;</td></tr>
    @endif

    {{-- 7. AI traffic --}}
    @if ($view['ai'])
    <tr><td style="{{ $card }} padding:20px 24px;">
        @include('emails.partials.heading', ['title' => 'Traffic from AI assistants'])
        @if ($view['ai']['unavailable'])
            @include('emails.partials.unavailable')
        @else
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr><th style="{{ $th }}">Source</th><th style="{{ $thNum }}">Sessions</th><th style="{{ $thNum }}">Change</th></tr>
                @foreach ($view['ai']['rows'] as $row)
                    <tr>
                        <td style="{{ $td }}">{{ $row['source'] }}</td>
                        <td style="{{ $num }}">{{ $row['sessions'] }}</td>
                        <td style="{{ $num }}">@include('emails.partials.change', ['change' => $row['change']])</td>
                    </tr>
                @endforeach
            </table>
            @if ($view['ai']['countries'] !== '')
                <div style="{{ $font }} font-size:14px; color:#374151; padding-top:10px;"><strong>Top countries:</strong> {{ $view['ai']['countries'] }}</div>
            @endif
            @if ($view['ai']['pages'] !== '')
                <div style="{{ $font }} font-size:14px; color:#374151; padding-top:4px;"><strong>Top landing pages:</strong> {{ $view['ai']['pages'] }}</div>
            @endif
        @endif
    </td></tr>
    <tr><td style="height:12px; line-height:12px; font-size:0;">&nbsp;</td></tr>
    @endif

    {{-- 8. Search Console --}}
    @if ($view['searchConsole'])
    <tr><td style="{{ $card }} padding:20px 24px;">
        @include('emails.partials.heading', ['title' => 'Search Console'])
        @if ($view['searchConsole']['unavailable'])
            @include('emails.partials.unavailable')
        @else
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                @foreach (array_chunk($view['searchConsole']['tiles'], 2) as $pair)
                    <tr>
                        @foreach ($pair as $tile)
                            <td width="50%" valign="top" style="padding:6px;">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F9FAFB; border-radius:6px;">
                                    <tr><td style="padding:12px 14px;">
                                        <div style="{{ $font }} font-size:13px; color:#6B7280;">{{ $tile['label'] }}</div>
                                        <div style="{{ $font }} font-size:22px; font-weight:bold; color:#111827; padding:2px 0;">{{ $tile['value'] }}</div>
                                        <div style="{{ $font }} font-size:14px;">@include('emails.partials.change', ['change' => $tile['change']])<span style="color:#6B7280;"> vs {{ $view['previousLabel'] }}</span></div>
                                    </td></tr>
                                </table>
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </table>

            @foreach ([['Top queries', 'queries'], ['Top pages', 'pages']] as [$label, $key])
                @if (! empty($view['searchConsole'][$key]))
                    <div style="height:12px; line-height:12px; font-size:0;">&nbsp;</div>
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                        <tr><th style="{{ $th }}">{{ $label }}</th><th style="{{ $thNum }}">Clicks</th><th style="{{ $thNum }}">Impressions</th><th style="{{ $thNum }}">Position</th></tr>
                        @foreach ($view['searchConsole'][$key] as $row)
                            <tr>
                                <td style="{{ $td }} word-break:break-word;">{{ $row['name'] }}</td>
                                <td style="{{ $num }}">{{ $row['clicks'] }}</td>
                                <td style="{{ $num }}">{{ $row['impressions'] }}</td>
                                <td style="{{ $num }}">{{ $row['position'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                @endif
            @endforeach
        @endif
    </td></tr>
    <tr><td style="height:12px; line-height:12px; font-size:0;">&nbsp;</td></tr>
    @endif

    {{-- 9. Data notes --}}
    @if (! empty($view['notes']))
    <tr><td style="padding:4px 8px 12px 8px;">
        <div style="{{ $font }} font-size:12px; font-weight:bold; color:#6B7280; padding-bottom:4px;">Data notes</div>
        @foreach ($view['notes'] as $note)
            <div style="{{ $font }} font-size:12px; line-height:17px; color:#6B7280; padding-bottom:4px;">{{ $note }}</div>
        @endforeach
    </td></tr>
    @endif

    {{-- Footer --}}
    <tr><td style="border-top:1px solid #E5E7EB; padding:14px 8px 8px 8px; {{ $font }} font-size:12px; line-height:17px; color:#9CA3AF; text-align:center;">
        {{ $view['sentBy'] }}@if ($view['generatedAt']) · Generated {{ $view['generatedAt'] }}@endif
    </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
