<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Property;
use App\Support\ReportFormat;
use App\Support\ReportPeriod;
use App\Support\Rich;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Turns a payload into the formatted data both email versions (HTML and plain text) render, so the two
 * always show the same numbers. Everything here is already a display string.
 *
 * A section whose payload value is an {error} object becomes ['unavailable' => true]. A section with no data
 * is null and is left out of the email. A metric the property does not have is left out, never shown as zero.
 *
 * Changes are arrays from ReportFormat::change() (text, arrow, color, kind): green for positive, red for negative,
 * gray for zero, blue-gray for "New", gray for the neutral metrics in config report.styling.neutral_metrics.
 */
final class ReportViewData
{
    public const DEFAULT_COLOR = '#1F6F8B';

    public static function subject(Property $property, ReportPeriod $period): string
    {
        return "{$property->name} ".ucfirst($period->type->value).' Traffic Report: '.$period->label();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $insight  from App\Contracts\InsightGenerator (headline, paragraphs, priority, caution)
     * @param  array<string, string|null>  $charts  chart name => PNG path or null
     * @return array<string, mixed>
     */
    public static function make(Property $property, ReportPeriod $period, array $payload, array $insight, array $charts, ?string $logoPath = null): array
    {
        $monthly = $period->type->value === 'monthly';
        $currency = (string) ($property->currency ?: ($payload['meta']['currency'] ?? ''));
        $previousLabel = $monthly ? 'last month' : 'last week';
        $yearAgoLabel = $period->yearAgoStart !== null ? $period->yearAgoStart->format('M Y') : null;
        $brand = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $property->brand_color) ? (string) $property->brand_color : self::DEFAULT_COLOR;

        $trend = self::trend($payload, $charts);
        $channels = self::channels($payload, $charts);
        $countries = self::countries($payload, $charts);
        $searchConsole = self::searchConsole($payload);
        $insightView = self::insight($insight);

        return [
            'propertyName' => $property->name,
            'language' => $property->language ?: 'en',
            'brandColor' => $brand,
            'logoPath' => $logoPath,
            'logoHeight' => self::imageHeight($logoPath, 56, 56),
            'headerTextColor' => self::readableTextOn($brand),
            'preheader' => self::preheader($insightView['headline']['text'] ?? ''),
            'title' => ucfirst($period->type->value).' Traffic Report',
            'periodLabel' => $period->label(),
            'subject' => self::subject($property, $period),
            'monthly' => $monthly,
            'previousLabel' => $previousLabel,
            'yearAgoLabel' => $yearAgoLabel,
            'insight' => $insightView,
            'kpis' => self::kpis($payload, $currency, $monthly),
            'trend' => $trend,
            'channels' => $channels,
            'countries' => $countries,
            'countryPerformance' => self::countryPerformance($payload),
            'pages' => self::pageList($payload, 'top_pages', 'Most visited pages'),
            'offers' => self::pageList($payload, 'top_offers', 'Most visited offers'),
            'paid' => self::paid($payload),
            'ai' => self::ai($payload),
            'searchConsole' => $searchConsole,
            'notes' => self::notes($payload, $charts, $trend, $channels, $countries, $searchConsole),
            'generatedAt' => self::generatedAt($payload, $property),
            'sentBy' => "Sent by the {$property->name} traffic report",
        ];
    }

    // ----------------------------------------------------------------------------------------------

    /**
     * The insight in both renderings: HTML (bold, colored inline spans) and plain text (no color, signs kept).
     *
     * @param  array<string, mixed>  $insight
     * @return array<string, mixed>
     */
    private static function insight(array $insight): array
    {
        $both = fn (string $markup) => ['html' => Rich::toHtml($markup), 'text' => Rich::toPlain($markup)];

        $priority = isset($insight['priority']) && $insight['priority'] !== ''
            ? $both(preg_replace('/^Priority:/', '⟦b|Priority:⟧', (string) $insight['priority']) ?? (string) $insight['priority'])
            : null;

        return [
            'headline' => $both((string) ($insight['headline'] ?? '')),
            'paragraphs' => array_map(fn (array $p) => ['title' => (string) $p['title']] + $both((string) $p['text']), $insight['paragraphs'] ?? []),
            'priority' => $priority,
            'caution' => isset($insight['caution']) && $insight['caution'] !== '' ? $both((string) $insight['caution']) : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function kpis(array $payload, string $currency, bool $monthly): array
    {
        $site = self::ok($payload, 'site');
        if ($site === null) {
            return [];
        }

        $defs = [
            ['sessions', 'Sessions', fn ($v) => ReportFormat::number($v), false, false],
            ['totalUsers', 'Users', fn ($v) => ReportFormat::number($v), false, false],
            ['engagementRate', 'Engagement rate', fn ($v) => ReportFormat::rate($v), true, false],
            ['averageSessionDuration', 'Avg. session duration', fn ($v) => ReportFormat::duration($v), false, false],
            ['keyEvents', 'Key events', fn ($v) => ReportFormat::number($v), false, false],
            ['purchases', 'Purchases', fn ($v) => ReportFormat::number($v), false, true],
            ['purchaseRevenue', 'Revenue', fn ($v) => ReportFormat::money($v, $currency), false, true],
        ];

        $tiles = [];
        foreach ($defs as [$key, $label, $format, $isRate, $onlyWithData]) {
            $m = $site[$key] ?? null;
            if (! is_array($m) || ($m['current'] ?? null) === null) {
                continue;
            }
            if ($onlyWithData && (float) $m['current'] <= 0 && (float) ($m['previous'] ?? 0) <= 0) {
                continue;
            }

            $change = fn (?array $c) => $isRate ? ReportFormat::rateChange($c, $key) : ReportFormat::change($c, $key);
            $tiles[] = [
                'label' => $label,
                'value' => $format($m['current']),
                'change' => $change($m['change'] ?? null),
                'yearAgo' => $monthly && isset($m['yearAgoChange']) ? $change($m['yearAgoChange']) : null,
            ];
        }

        return $tiles;
    }

    /** @return array<string, mixed>|null */
    private static function trend(array $payload, array $charts): ?array
    {
        if (self::failed($payload, 'trend')) {
            return ['unavailable' => true];
        }
        $trend = self::ok($payload, 'trend');
        $points = $trend['points'] ?? [];
        if (count($points) < 2) {
            return null;
        }

        $weekly = ($trend['granularity'] ?? 'week') === 'week';

        return [
            'unavailable' => false,
            'hasChart' => ($charts['trend'] ?? null) !== null,
            'imgHeight' => self::imageHeight($charts['trend'] ?? null, 592, 296),
            'rows' => array_map(fn (array $p) => [
                'label' => (new DateTimeImmutable($p['start']))->format($weekly ? 'j M' : 'M Y'),
                'sessions' => ReportFormat::number($p['sessions']),
                'users' => ReportFormat::number($p['totalUsers']),
            ], $points),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function channels(array $payload, array $charts): ?array
    {
        if (self::failed($payload, 'channels')) {
            return ['unavailable' => true];
        }
        $channels = self::ok($payload, 'channels');
        if (($channels['rows'] ?? []) === []) {
            return null;
        }

        $others = array_values(array_filter((array) ($channels['otherChannels'] ?? []), fn (array $o) => ($o['share'] ?? 0) > 0));
        $remaining = null;
        if ($others !== []) {
            $names = array_slice(array_column($others, 'channel'), 0, 3);
            $last = array_pop($names);
            $list = count($others) > 3
                ? implode(', ', array_merge($names, [$last])).' and smaller channels'
                : ($names === [] ? $last : implode(', ', $names).' and '.$last);
            $remaining = 'The remaining '.ReportFormat::percent(array_sum(array_column($others, 'share'))).' (shown as Other) came from '.$list.'.';
        }

        return [
            'unavailable' => false,
            'hasChart' => ($charts['channels'] ?? null) !== null,
            'imgHeight' => self::imageHeight($charts['channels'] ?? null, 592, 300),
            'remaining' => $remaining,
            'rows' => array_map(fn (array $r) => [
                'name' => (string) $r['channel'],
                'share' => isset($r['share']['current']) ? ReportFormat::percent($r['share']['current']) : '–',
                'sessions' => ReportFormat::number($r['sessions']['current']),
                'change' => ReportFormat::change($r['sessions']['change'] ?? null, 'sessions'),
                'engagement' => isset($r['engagementRate']) ? ReportFormat::rate($r['engagementRate']['current']) : '–',
            ], $channels['rows']),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function countries(array $payload, array $charts): ?array
    {
        if (self::failed($payload, 'countries')) {
            return ['unavailable' => true];
        }
        $countries = self::ok($payload, 'countries');
        if (($countries['rows'] ?? []) === []) {
            return null;
        }

        $dropped = array_values((array) ($countries['dropped'] ?? []));

        return [
            'unavailable' => false,
            'hasChart' => ($charts['countries'] ?? null) !== null,
            'imgHeight' => self::imageHeight($charts['countries'] ?? null, 592, 400),
            // The chart carries its own title ("Top countries by engaged sessions"), so the section heading is not repeated above it.
            'title' => ($charts['countries'] ?? null) !== null ? 'Top countries' : 'Top countries by engaged sessions',
            'dropped' => $dropped === [] ? null : 'Dropped from the current top countries: '.implode(', ', $dropped).'.',
            'rows' => array_map(fn (array $r) => [
                'name' => (string) $r['country'],
                'engaged' => ReportFormat::number($r['engagedSessions']['current'] ?? null),
                'sessions' => ReportFormat::number($r['sessions']['current']),
                'share' => isset($r['engagedShare']['current']) ? ReportFormat::percent($r['engagedShare']['current']) : '–',
                'change' => ReportFormat::change($r['engagedSessions']['change'] ?? null, 'engagedSessions'),
            ], $countries['rows']),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function countryPerformance(array $payload): ?array
    {
        if (self::failed($payload, 'country_performance')) {
            return ['unavailable' => true];
        }
        $rows = $payload['country_performance'] ?? null;
        if (! is_array($rows) || $rows === []) {
            return null;
        }

        $siteSessions = (float) ($payload['site']['sessions']['current'] ?? 0);

        return [
            'unavailable' => false,
            'rows' => array_map(function (array $r) use ($siteSessions) {
                $campaign = (string) ($r['campaign'] ?? '');
                $detail = trim((string) ($r['sourceMedium'] ?? '').($campaign !== '' && ! str_starts_with($campaign, '(') ? ' · '.$campaign : ''));

                return [
                    'country' => (string) $r['country'],
                    'detail' => $detail,
                    'sessions' => ReportFormat::number($r['sessions']),
                    'share' => $siteSessions > 0 ? ReportFormat::percent((float) $r['sessions'] / $siteSessions * 100) : '–',
                    'engaged' => ReportFormat::number($r['engagedSessions'] ?? null),
                    'engagement' => isset($r['engagementRate']) ? ReportFormat::rate($r['engagementRate']) : '–',
                    'bookingVisits' => ($r['bookingEngineVisits'] ?? null) === null ? '–' : ReportFormat::number($r['bookingEngineVisits']),
                    'quality' => (string) ($r['quality'] ?? ''),
                    'flag' => $r['flag'] ?? (($r['checkForBots'] ?? false) ? 'check for bots' : null),
                ];
            }, $rows),
        ];
    }

    /**
     * Most visited pages or offers: name, views (bold, with change), users. Left out when there are none.
     *
     * @return array<string, mixed>|null
     */
    private static function pageList(array $payload, string $key, string $title): ?array
    {
        if (self::failed($payload, $key)) {
            return ['title' => $title, 'unavailable' => true];
        }
        $list = self::ok($payload, $key);
        if (($list['rows'] ?? []) === []) {
            return null;
        }

        return [
            'title' => $title,
            'unavailable' => false,
            'total' => ReportFormat::number($list['total']['current'] ?? null),
            'rows' => array_map(fn (array $r) => [
                'name' => (string) $r['name'],
                'path' => (string) $r['path'],
                'views' => ReportFormat::number($r['views']['current']),
                'users' => ReportFormat::number($r['users']),
                'change' => ReportFormat::change($r['views']['change'] ?? null, 'views'),
            ], $list['rows']),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function paid(array $payload): ?array
    {
        if (self::failed($payload, 'paid')) {
            return ['unavailable' => true];
        }
        $paid = self::ok($payload, 'paid');
        if ($paid === null || ($paid['available'] ?? false) !== true || ! is_array($paid['metrics'] ?? null)) {
            return null;
        }

        $m = $paid['metrics'];
        if ((float) ($m['impressions']['current'] ?? 0) <= 0 && (float) ($m['clicks']['current'] ?? 0) <= 0 && (float) ($m['cost']['current'] ?? 0) <= 0) {
            return null;
        }

        $currency = (string) ($paid['currency'] ?? '');
        // A metric GA4 did not provide is left out of the table (never shown as zero).
        $row = fn (string $label, string $key, callable $format, bool $isRate = false) => isset($m[$key]) ? [
            'label' => $label,
            'value' => $format($m[$key]['current'] ?? null),
            'previous' => $format($m[$key]['previous'] ?? null),
            'change' => $isRate ? ReportFormat::rateChange($m[$key]['change'] ?? null, $key) : ReportFormat::change($m[$key]['change'] ?? null, $key),
        ] : null;

        return [
            'unavailable' => false,
            'currency' => $currency,
            'rows' => array_values(array_filter([
                $row('Impressions', 'impressions', fn ($v) => ReportFormat::number($v)),
                $row('Clicks', 'clicks', fn ($v) => ReportFormat::number($v)),
                $row('CTR', 'ctr', fn ($v) => ReportFormat::rate($v, 2), true),
                $row('Cost', 'cost', fn ($v) => ReportFormat::money($v, $currency)),
                $row('Cost per click', 'costPerClick', fn ($v) => ReportFormat::money($v, $currency)),
                $row('Purchases', 'purchases', fn ($v) => ReportFormat::number($v)),
                $row('Revenue', 'revenue', fn ($v) => ReportFormat::money($v, $currency)),
            ])),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function ai(array $payload): ?array
    {
        if (self::failed($payload, 'ai_traffic')) {
            return ['unavailable' => true];
        }
        $ai = self::ok($payload, 'ai_traffic');
        if ($ai === null || (float) ($ai['total']['current'] ?? 0) <= 0) {
            return null;
        }

        $landing = implode(', ', array_map(
            fn (array $i) => $i['landingPage'].' ('.ReportFormat::number($i['sessions']).')',
            (array) ($ai['topLandingPages'] ?? []),
        ));
        $dropped = array_values((array) ($ai['droppedCountries'] ?? []));

        return [
            'unavailable' => false,
            'total' => ReportFormat::number($ai['total']['current']),
            'totalChange' => ReportFormat::change($ai['total']['change'] ?? null, 'sessions'),
            'rows' => array_map(fn (array $r) => [
                'source' => (string) $r['source'],
                'sessions' => ReportFormat::number($r['sessions']['current']),
                'share' => isset($r['share']['current']) ? ReportFormat::percent($r['share']['current']) : '–',
                'change' => ReportFormat::change($r['sessions']['change'] ?? null, 'sessions'),
            ], $ai['sources'] ?? []),
            'countryRows' => array_map(fn (array $r) => [
                'name' => (string) $r['country'],
                'sessions' => ReportFormat::number($r['sessions']),
                'share' => isset($r['share']) ? ReportFormat::percent($r['share']) : '–',
                'change' => ReportFormat::change($r['change'] ?? null, 'sessions'),
            ], (array) ($ai['topCountries'] ?? [])),
            'dropped' => $dropped === [] ? null : 'Dropped from the AI top countries: '.implode(', ', $dropped).'.',
            'pages' => $landing,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function searchConsole(array $payload): ?array
    {
        if (self::failed($payload, 'search_console')) {
            return ['unavailable' => true];
        }
        $sc = self::ok($payload, 'search_console');
        if ($sc === null || ! ($sc['hasData'] ?? false) || ! is_array($sc['totals'] ?? null)) {
            return null;
        }

        $t = $sc['totals'];
        $tile = fn (string $label, string $value, array $change, ?string $hint = null) => ['label' => $label, 'value' => $value, 'change' => $change, 'hint' => $hint];

        $list = fn (array $rows, string $dimension, bool $isPage) => array_map(fn (array $r) => [
            'name' => $isPage ? (preg_replace('#^https?://[^/]+#', '', (string) $r[$dimension]) ?: '/') : (string) $r[$dimension],
            'clicks' => ReportFormat::number($r['clicks']['current']),
            'impressions' => ReportFormat::number($r['impressions']['current']),
            'position' => ReportFormat::number($r['position']['current'], 1),
            'change' => ReportFormat::change($r['clicks']['change'] ?? null),
        ], array_slice($rows, 0, 5));

        $brand = $sc['brand'] ?? null;
        $brandLine = null;
        if (is_array($brand) && ($brand['share']['current'] ?? null) !== null) {
            $brandLine = 'Brand searches (terms: '.implode(', ', array_slice((array) $brand['terms'], 0, 3)).') brought '
                .ReportFormat::percent($brand['share']['current']).' of clicks'
                .(($brand['share']['previous'] ?? null) !== null ? ' (previous '.ReportFormat::percent($brand['share']['previous']).')' : '').'.';
        }

        return [
            'unavailable' => false,
            'tiles' => [
                $tile('Clicks', ReportFormat::number($t['clicks']['current']), ReportFormat::change($t['clicks']['change'] ?? null)),
                $tile('Impressions', ReportFormat::number($t['impressions']['current']), ReportFormat::change($t['impressions']['change'] ?? null)),
                $tile('CTR', ReportFormat::rate($t['ctr']['current'], 2), ReportFormat::rateChange($t['ctr']['change'] ?? null)),
                // A lower position number is better, so a drop in the number is green and a rise is red (already inverted in the payload).
                $tile('Avg. position', ReportFormat::number($t['position']['current'], 1), ReportFormat::change($t['position']['change'] ?? null), 'lower is better'),
            ],
            'queries' => $list($sc['topQueries'] ?? [], 'query', false),
            'pages' => $list($sc['topPages'] ?? [], 'page', true),
            'brandLine' => $brandLine,
            'truncated' => (bool) ($sc['truncated'] ?? false),
            'through' => ($sc['through'] ?? null) ? (new DateTimeImmutable($sc['through']))->format('j M Y') : null,
        ];
    }

    /** @return list<string> */
    private static function notes(array $payload, array $charts, ?array $trend, ?array $channels, ?array $countries, ?array $searchConsole): array
    {
        $notes = [];

        if ($searchConsole !== null && ! ($searchConsole['unavailable'] ?? false)) {
            if ($searchConsole['truncated'] && $searchConsole['through'] !== null) {
                $notes[] = "Search Console data through {$searchConsole['through']}.";
            }
            $notes[] = 'Search Console may differ slightly from the web interface because of time zone.';
        }

        foreach ($payload['quality_alerts'] ?? [] as $alert) {
            if (! empty($alert['message'])) {
                $notes[] = (string) $alert['message'];
            }
        }

        if (config('report.charts.enabled')) {
            $missing = [];
            foreach (['trend' => $trend, 'channels' => $channels, 'countries' => $countries] as $name => $section) {
                if ($section !== null && ! ($section['unavailable'] ?? false) && ! ($section['hasChart'] ?? false)) {
                    $missing[] = $name;
                }
            }
            if ($missing !== []) {
                $notes[] = 'The '.implode(', ', $missing).' chart'.(count($missing) > 1 ? 's' : '').' could not be generated, so the figures are shown as a table.';
            }
        }

        return $notes;
    }

    private static function generatedAt(array $payload, Property $property): ?string
    {
        $iso = $payload['meta']['generatedAt'] ?? null;
        if (! is_string($iso)) {
            return null;
        }

        $tz = new DateTimeZone($property->timezone ?: 'UTC');

        return (new DateTimeImmutable($iso))->setTimezone($tz)->format('j M Y, H:i').' ('.$tz->getName().')';
    }

    // ----------------------------------------------------------------------------------------------

    /** Hidden inbox preview text: the insight headline, at most 90 characters (cut at a word, ending in an ellipsis). */
    public static function preheader(string $headline): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $headline));
        if (mb_strlen($text) <= 90) {
            return $text;
        }

        $cut = mb_substr($text, 0, 89);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > 50) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, ' ,;:.').'…';
    }

    /** White or near-black, whichever is readable on this hex background. */
    public static function readableTextOn(string $hex): string
    {
        $r = hexdec(substr($hex, 1, 2));
        $g = hexdec(substr($hex, 3, 2));
        $b = hexdec(substr($hex, 5, 2));

        return (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255 > 0.6 ? '#111827' : '#FFFFFF';
    }

    /** Height in px an image gets when shown $width px wide, from the file's own size. Outlook needs it as an attribute. */
    private static function imageHeight(?string $path, int $width, int $fallback): int
    {
        $size = $path !== null && is_file($path) ? @getimagesize($path) : false;

        return $size !== false && $size[0] > 0 ? (int) round($width * $size[1] / $size[0]) : $fallback;
    }

    private static function failed(array $payload, string $key): bool
    {
        return is_array($payload[$key] ?? null) && isset($payload[$key]['error']);
    }

    /** @return array<string, mixed>|null the section, or null when missing, empty or an {error} object */
    private static function ok(array $payload, string $key): ?array
    {
        $section = $payload[$key] ?? null;

        return is_array($section) && ! isset($section['error']) ? $section : null;
    }
}
