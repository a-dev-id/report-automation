{{--
    Traffic report email. Built for Outlook (Word engine) and Gmail:
      - tables for all layout; spacing is td padding or spacer rows, never div padding or table margins
      - bgcolor attributes next to every CSS background, width/height attributes on every image, align attributes next to text-align
      - no float, flex, grid, no <style> block, no links; fonts Arial/Helvetica
    Every figure is pre-formatted by App\Services\Reports\ReportViewData, the same data feeds report-text.blade.php.

    Variables: $view (ReportViewData::make), $charts (name => PNG path|null), optional $inline (path => src, used
    by report:preview). In a real email, $message->embedData() attaches the image inline and returns a "cid:" source.
--}}
@php
    $mimes = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif'];
    $embed = isset($message)
        ? fn ($path) => $message->embedData(file_get_contents($path), basename($path), $mimes[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'image/png')
        : ($inline ?? fn ($path) => $path);
    $src = fn (?string $path) => $path === null ? null : $embed($path);

    $brand = $view['brandColor'];
    $headText = $view['headerTextColor'];
    $font = 'font-family:Arial, Helvetica, sans-serif;';
    $card = 'border:1px solid #E5E7EB; border-radius:6px; padding:20px 24px;';
    $th = $font.' font-size:13px; font-weight:bold; color:#4B5563; text-align:left; padding:8px 6px; border-bottom:2px solid #E5E7EB;';
    $thNum = $th.' text-align:right;';
    $td = $font.' font-size:14px; color:#111827; padding:8px 6px; border-bottom:1px solid #F0F1F3; vertical-align:top;';
    $num = $td.' text-align:right; white-space:nowrap;';
    $numB = $num.' font-weight:bold;';   // a value shown with a change is bold
    $gap = fn (int $h) => '<tr><td height="'.$h.'" style="height:'.$h.'px; line-height:'.$h.'px; font-size:'.$h.'px;">&nbsp;</td></tr>';
    // A paragraph as a one-cell table, so the spacing is td padding (Outlook ignores padding on div and p).
    $para = fn (string $text, string $style = '', string $pad = '0') => '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="'
        .$font.' '.$style.' padding:'.$pad.';">'.e($text).'</td></tr></table>';
@endphp
<!DOCTYPE html>
<html lang="{{ $view['language'] }}" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no">
    <meta name="color-scheme" content="light">
    <!--[if mso]><xml><o:OfficeDocumentSettings><o:AllowPNG/><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml><![endif]-->
    <title>{{ $view['subject'] }}</title>
</head>
<body bgcolor="#F3F4F6" style="margin:0; padding:0; background:#F3F4F6; {{ $font }} color:#111827;">
{{-- Hidden preheader: the inbox preview text (the insight headline, at most 90 characters). --}}
<div style="display:none; font-size:1px; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden; mso-hide:all; color:#F3F4F6;">{{ $view['preheader'] }}{!! str_repeat('&nbsp;&zwnj;', 40) !!}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#F3F4F6" style="background:#F3F4F6;">
<tr><td align="center" style="padding:16px 8px;">
<table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" align="center" style="width:100%; max-width:640px;">

    {{-- Header --}}
    <tr><td bgcolor="{{ $brand }}" style="background:{{ $brand }}; padding:20px 24px; border-radius:6px 6px 0 0;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
            @if ($view['logoPath'])
                <td width="72" valign="middle" style="width:72px; padding-right:16px;">
                    <img src="{{ $src($view['logoPath']) }}" alt="{{ $view['propertyName'] }}" width="56" height="{{ $view['logoHeight'] }}" style="display:block; width:56px; height:auto; border:0; outline:none; text-decoration:none;">
                </td>
            @endif
            <td valign="middle" style="{{ $font }}">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr><td style="{{ $font }} font-size:22px; line-height:28px; font-weight:bold; color:{{ $headText }};">{{ $view['propertyName'] }}</td></tr>
                    <tr><td style="{{ $font }} font-size:15px; line-height:21px; color:{{ $headText }}; padding-top:4px;">{{ $view['title'] }}: {{ $view['periodLabel'] }}</td></tr>
                </table>
            </td>
        </tr></table>
    </td></tr>

    {!! $gap(12) !!}

    {{-- 1. Traffic Quality Insight: verdict headline, titled paragraphs (what changed, what it means, what to check), priority, caution --}}
    @if (! empty($view['insight']['headline']['text']))
    <tr><td bgcolor="#FFFFFF" style="background:#FFFFFF; border:1px solid #E5E7EB; border-left:5px solid {{ $brand }}; border-radius:6px; padding:20px 24px;">
        {!! $para('Traffic Quality Insight', 'font-size:13px; line-height:18px; font-weight:bold; letter-spacing:0.5px; text-transform:uppercase; color:'.$brand.';') !!}
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="{{ $font }} font-size:16px; line-height:24px; font-weight:bold; color:#111827; padding:8px 0 4px 0;">{!! $view['insight']['headline']['html'] !!}</td></tr></table>
        @foreach ($view['insight']['paragraphs'] as $paragraph)
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr><td style="{{ $font }} font-size:13px; line-height:18px; font-weight:bold; color:{{ $brand }}; padding:12px 0 2px 0;">{{ $paragraph['title'] }}</td></tr>
                <tr><td style="{{ $font }} font-size:14px; line-height:21px; color:#1F2937; padding:0;">{!! $paragraph['html'] !!}</td></tr>
            </table>
        @endforeach
        @if (! empty($view['insight']['priority']))
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr><td style="padding:14px 0 0 0;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                        <tr><td bgcolor="#F0F7FA" style="{{ $font }} background:#F0F7FA; border-left:3px solid {{ $brand }}; font-size:14px; line-height:21px; color:#111827; padding:10px 12px;">{!! $view['insight']['priority']['html'] !!}</td></tr>
                    </table>
                </td></tr>
            </table>
        @endif
        @if (! empty($view['insight']['caution']))
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr><td style="padding:10px 0 0 0;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                        <tr><td bgcolor="#F9FAFB" style="{{ $font }} background:#F9FAFB; font-size:13px; line-height:19px; color:#4B5563; padding:10px 12px; border-radius:4px;">{!! $view['insight']['caution']['html'] !!}</td></tr>
                    </table>
                </td></tr>
            </table>
        @endif
    </td></tr>
    {!! $gap(12) !!}
    @endif
    {{-- 2. KPI tiles --}}
    @if (! empty($view['kpis']))
    <tr><td bgcolor="#FFFFFF" style="background:#FFFFFF; border:1px solid #E5E7EB; border-radius:6px; padding:12px 10px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
            @foreach (array_chunk($view['kpis'], 2) as $pair)
                <tr>
                    @foreach ($pair as $tile)
                        <td width="50%" valign="top" style="padding:6px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr><td bgcolor="#F9FAFB" style="background:#F9FAFB; border-radius:6px; padding:12px 14px;">
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                        <tr><td style="{{ $font }} font-size:13px; line-height:18px; color:#6B7280;">{{ $tile['label'] }}</td></tr>
                                        <tr><td style="{{ $font }} font-size:24px; line-height:30px; font-weight:bold; color:#111827; padding:2px 0;">{{ $tile['value'] }}</td></tr>
                                        <tr><td style="{{ $font }} font-size:14px; line-height:20px;">
                                            @include('emails.partials.change', ['change' => $tile['change']])<span style="color:#6B7280;"> vs {{ $view['previousLabel'] }}</span>
                                        </td></tr>
                                        @if ($tile['yearAgo'])
                                            <tr><td style="{{ $font }} font-size:14px; line-height:20px;">
                                                @include('emails.partials.change', ['change' => $tile['yearAgo']])<span style="color:#6B7280;"> vs {{ $view['yearAgoLabel'] }}</span>
                                            </td></tr>
                                        @endif
                                    </table>
                                </td></tr>
                            </table>
                        </td>
                    @endforeach
                    @if (count($pair) === 1)<td width="50%" style="padding:6px;">&nbsp;</td>@endif
                </tr>
            @endforeach
        </table>
    </td></tr>
    {!! $gap(12) !!}
    @endif

    {{-- 3. Trend --}}
    @if ($view['trend'])
    <tr><td bgcolor="#FFFFFF" style="background:#FFFFFF; {{ $card }}">
        @include('emails.partials.heading', ['title' => $view['monthly'] ? 'Sessions, last 12 months' : 'Sessions, last 8 weeks'])
        @if ($view['trend']['unavailable'])
            @include('emails.partials.unavailable')
        @elseif ($view['trend']['hasChart'])
            <img src="{{ $src($charts['trend']) }}" alt="Sessions trend" width="592" height="{{ $view['trend']['imgHeight'] }}" style="display:block; width:100%; max-width:592px; height:auto; border:0; outline:none; text-decoration:none;">
        @else
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr><th align="left" style="{{ $th }}">{{ $view['monthly'] ? 'Month' : 'Week of' }}</th><th align="right" style="{{ $thNum }}">Sessions</th><th align="right" style="{{ $thNum }}">Users</th></tr>
                @foreach ($view['trend']['rows'] as $row)
                    <tr><td style="{{ $td }}">{{ $row['label'] }}</td><td align="right" style="{{ $num }}">{{ $row['sessions'] }}</td><td align="right" style="{{ $num }}">{{ $row['users'] }}</td></tr>
                @endforeach
            </table>
        @endif
    </td></tr>
    {!! $gap(12) !!}
    @endif

    {{-- 4. Channels --}}
    @if ($view['channels'])
    <tr><td bgcolor="#FFFFFF" style="background:#FFFFFF; {{ $card }}">
        @include('emails.partials.heading', ['title' => 'Traffic by channel'])
        @if ($view['channels']['unavailable'])
            @include('emails.partials.unavailable')
        @else
            @if ($view['channels']['hasChart'])
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr><td><img src="{{ $src($charts['channels']) }}" alt="Sessions by channel" width="592" height="{{ $view['channels']['imgHeight'] }}" style="display:block; width:100%; max-width:592px; height:auto; border:0; outline:none; text-decoration:none;"></td></tr>
                    {!! $gap(12) !!}
                </table>
            @endif
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr><th align="left" style="{{ $th }}">Channel</th><th align="right" style="{{ $thNum }}">Share</th><th align="right" style="{{ $thNum }}">Sessions</th><th align="right" style="{{ $thNum }}">Change</th><th align="right" style="{{ $thNum }}">Engagement</th></tr>
                @foreach ($view['channels']['rows'] as $row)
                    <tr>
                        <td style="{{ $td }}">{{ $row['name'] }}</td>
                        <td align="right" style="{{ $num }}">{{ $row['share'] }}</td>
                        <td align="right" style="{{ $numB }}">{{ $row['sessions'] }}</td>
                        <td align="right" style="{{ $num }}">@include('emails.partials.change', ['change' => $row['change']])</td>
                        <td align="right" style="{{ $num }}">{{ $row['engagement'] }}</td>
                    </tr>
                @endforeach
            </table>
            @if ($view['channels']['remaining'])
                {!! $para($view['channels']['remaining'], 'font-size:13px; line-height:19px; color:#4B5563;', '8px 0 0 0') !!}
            @endif
        @endif
    </td></tr>
    {!! $gap(12) !!}
    @endif

    {{-- 5. Countries (ranked by engaged sessions) and Country Performance --}}
    @if ($view['countries'] || $view['countryPerformance'])
    <tr><td bgcolor="#FFFFFF" style="background:#FFFFFF; {{ $card }}">
        @if ($view['countries'])
            @include('emails.partials.heading', ['title' => $view['countries']['title'] ?? 'Top countries by engaged sessions'])
            @if ($view['countries']['unavailable'])
                @include('emails.partials.unavailable')
            @else
                @if ($view['countries']['hasChart'])
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                        <tr><td><img src="{{ $src($charts['countries']) }}" alt="Top countries by engaged sessions" width="592" height="{{ $view['countries']['imgHeight'] }}" style="display:block; width:100%; max-width:592px; height:auto; border:0; outline:none; text-decoration:none;"></td></tr>
                        {!! $gap(12) !!}
                    </table>
                @endif
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr><th align="left" style="{{ $th }}">Country</th><th align="right" style="{{ $thNum }}">Engaged</th><th align="right" style="{{ $thNum }}">Share</th><th align="right" style="{{ $thNum }}">Change</th><th align="right" style="{{ $thNum }}">Sessions</th></tr>
                    @foreach ($view['countries']['rows'] as $row)
                        <tr>
                            <td style="{{ $td }}">{{ $row['name'] }}</td>
                            <td align="right" style="{{ $numB }}">{{ $row['engaged'] }}</td>
                            <td align="right" style="{{ $num }}">{{ $row['share'] }}</td>
                            <td align="right" style="{{ $num }}">@include('emails.partials.change', ['change' => $row['change']])</td>
                            <td align="right" style="{{ $num }}">{{ $row['sessions'] }}</td>
                        </tr>
                    @endforeach
                </table>
                @if ($view['countries']['dropped'])
                    {!! $para($view['countries']['dropped'], 'font-size:13px; line-height:19px; color:#4B5563;', '8px 0 0 0') !!}
                @endif
            @endif
        @endif

        @if ($view['countryPerformance'])
            @if ($view['countries'])
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">{!! $gap(16) !!}</table>
            @endif
            @include('emails.partials.heading', ['title' => 'Country performance'])
            @if ($view['countryPerformance']['unavailable'])
                @include('emails.partials.unavailable')
            @else
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <th align="left" style="{{ $th }}">Country</th><th align="right" style="{{ $thNum }}">Sessions</th><th align="right" style="{{ $thNum }}">Share</th><th align="right" style="{{ $thNum }}">Engaged</th>
                        <th align="right" style="{{ $thNum }}">Engagement</th><th align="right" style="{{ $thNum }}">Booking visits</th><th align="left" style="{{ $th }}">Quality</th>
                    </tr>
                    @foreach ($view['countryPerformance']['rows'] as $row)
                        <tr>
                            <td style="{{ $td }}">{{ $row['country'] }}
                                @if ($row['detail'] !== '')<br><span style="{{ $font }} font-size:13px; color:#6B7280;">{{ $row['detail'] }}</span>@endif
                            </td>
                            <td align="right" style="{{ $num }}">{{ $row['sessions'] }}</td>
                            <td align="right" style="{{ $num }}">{{ $row['share'] }}</td>
                            <td align="right" style="{{ $num }}">{{ $row['engaged'] }}</td>
                            <td align="right" style="{{ $num }}">{{ $row['engagement'] }}</td>
                            <td align="right" style="{{ $num }}">{{ $row['bookingVisits'] }}</td>
                            <td style="{{ $td }}">{{ $row['quality'] }}
                                @if ($row['flag'])<br><span style="{{ $font }} font-size:13px; color:#B45309; font-weight:bold;">{{ $row['flag'] }}</span>@endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            @endif
        @endif
    </td></tr>
    {!! $gap(12) !!}
    @endif

    {{-- 6. Paid --}}
    @if ($view['paid'])
    <tr><td bgcolor="#FFFFFF" style="background:#FFFFFF; {{ $card }}">
        @include('emails.partials.heading', ['title' => 'Paid advertising'])
        @if ($view['paid']['unavailable'])
            @include('emails.partials.unavailable')
        @else
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr><th align="left" style="{{ $th }}">Metric</th><th align="right" style="{{ $thNum }}">This {{ $view['monthly'] ? 'month' : 'week' }}</th><th align="right" style="{{ $thNum }}">Previous</th><th align="right" style="{{ $thNum }}">Change</th></tr>
                @foreach ($view['paid']['rows'] as $row)
                    <tr>
                        <td style="{{ $td }}">{{ $row['label'] }}</td>
                        <td align="right" style="{{ $numB }}">{{ $row['value'] }}</td>
                        <td align="right" style="{{ $num }}">{{ $row['previous'] }}</td>
                        <td align="right" style="{{ $num }}">@include('emails.partials.change', ['change' => $row['change']])</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </td></tr>
    {!! $gap(12) !!}
    @endif

    {{-- 7. AI traffic --}}
    @if ($view['ai'])
    <tr><td bgcolor="#FFFFFF" style="background:#FFFFFF; {{ $card }}">
        @include('emails.partials.heading', ['title' => 'Traffic from AI assistants'])
        @if ($view['ai']['unavailable'])
            @include('emails.partials.unavailable')
        @else
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr><th align="left" style="{{ $th }}">Assistant</th><th align="right" style="{{ $thNum }}">Share</th><th align="right" style="{{ $thNum }}">Sessions</th><th align="right" style="{{ $thNum }}">Change</th></tr>
                @foreach ($view['ai']['rows'] as $row)
                    <tr>
                        <td style="{{ $td }}">{{ $row['source'] }}</td>
                        <td align="right" style="{{ $num }}">{{ $row['share'] }}</td>
                        <td align="right" style="{{ $numB }}">{{ $row['sessions'] }}</td>
                        <td align="right" style="{{ $num }}">@include('emails.partials.change', ['change' => $row['change']])</td>
                    </tr>
                @endforeach
            </table>
            @if (! empty($view['ai']['countryRows']))
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">{!! $gap(12) !!}</table>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr><th align="left" style="{{ $th }}">Top countries (AI traffic)</th><th align="right" style="{{ $thNum }}">Share</th><th align="right" style="{{ $thNum }}">Sessions</th><th align="right" style="{{ $thNum }}">Change</th></tr>
                    @foreach ($view['ai']['countryRows'] as $row)
                        <tr>
                            <td style="{{ $td }}">{{ $row['name'] }}</td>
                            <td align="right" style="{{ $num }}">{{ $row['share'] }}</td>
                            <td align="right" style="{{ $numB }}">{{ $row['sessions'] }}</td>
                            <td align="right" style="{{ $num }}">@include('emails.partials.change', ['change' => $row['change']])</td>
                        </tr>
                    @endforeach
                </table>
            @endif
            @if ($view['ai']['dropped'])
                {!! $para($view['ai']['dropped'], 'font-size:13px; line-height:19px; color:#4B5563;', '8px 0 0 0') !!}
            @endif
            @if ($view['ai']['pages'] !== '')
                {!! $para('Top landing pages: '.$view['ai']['pages'], 'font-size:14px; line-height:20px; color:#374151;', '8px 0 0 0') !!}
            @endif
        @endif
    </td></tr>
    {!! $gap(12) !!}
    @endif

    {{-- 8. Search Console --}}
    @if ($view['searchConsole'])
    <tr><td bgcolor="#FFFFFF" style="background:#FFFFFF; {{ $card }}">
        @include('emails.partials.heading', ['title' => 'Search Console'])
        @if ($view['searchConsole']['unavailable'])
            @include('emails.partials.unavailable')
        @else
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                @foreach (array_chunk($view['searchConsole']['tiles'], 2) as $pair)
                    <tr>
                        @foreach ($pair as $tile)
                            <td width="50%" valign="top" style="padding:6px;">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                    <tr><td bgcolor="#F9FAFB" style="background:#F9FAFB; border-radius:6px; padding:12px 14px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr><td style="{{ $font }} font-size:13px; line-height:18px; color:#6B7280;">{{ $tile['label'] }}@if ($tile['hint']) ({{ $tile['hint'] }})@endif</td></tr>
                                            <tr><td style="{{ $font }} font-size:22px; line-height:28px; font-weight:bold; color:#111827; padding:2px 0;">{{ $tile['value'] }}</td></tr>
                                            <tr><td style="{{ $font }} font-size:14px; line-height:20px;">@include('emails.partials.change', ['change' => $tile['change']])<span style="color:#6B7280;"> vs {{ $view['previousLabel'] }}</span></td></tr>
                                        </table>
                                    </td></tr>
                                </table>
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </table>

            @if ($view['searchConsole']['brandLine'])
                {!! $para($view['searchConsole']['brandLine'], 'font-size:14px; line-height:20px; color:#374151;', '8px 0 0 0') !!}
            @endif

            @foreach ([['Top queries', 'queries'], ['Top pages', 'pages']] as [$label, $key])
                @if (! empty($view['searchConsole'][$key]))
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">{!! $gap(12) !!}</table>
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                        <tr><th align="left" style="{{ $th }}">{{ $label }}</th><th align="right" style="{{ $thNum }}">Clicks</th><th align="right" style="{{ $thNum }}">Impressions</th><th align="right" style="{{ $thNum }}">Position</th></tr>
                        @foreach ($view['searchConsole'][$key] as $row)
                            <tr>
                                <td style="{{ $td }} word-break:break-word;">{{ $row['name'] }}</td>
                                <td align="right" style="{{ $numB }}">{{ $row['clicks'] }}</td>
                                <td align="right" style="{{ $num }}">{{ $row['impressions'] }}</td>
                                <td align="right" style="{{ $num }}">{{ $row['position'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                @endif
            @endforeach
        @endif
    </td></tr>
    {!! $gap(12) !!}
    @endif

    {{-- 9. Data notes --}}
    @if (! empty($view['notes']))
    <tr><td style="padding:4px 8px 12px 8px;">
        {!! $para('Data notes', 'font-size:12px; line-height:17px; font-weight:bold; color:#6B7280;', '0 0 4px 0') !!}
        @foreach ($view['notes'] as $note)
            {!! $para($note, 'font-size:12px; line-height:17px; color:#6B7280;', '0 0 4px 0') !!}
        @endforeach
    </td></tr>
    @endif

    {{-- Footer --}}
    <tr><td align="center" style="{{ $font }} border-top:1px solid #E5E7EB; padding:14px 8px 8px 8px; font-size:12px; line-height:17px; color:#9CA3AF; text-align:center;">
        {{ $view['sentBy'] }}@if ($view['generatedAt']) · Generated {{ $view['generatedAt'] }}@endif
    </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
