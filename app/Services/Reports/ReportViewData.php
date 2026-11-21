<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Property;
use App\Support\ReportFormat;
use App\Support\ReportPeriod;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Turns a payload into the formatted data both email versions (HTML and plain text) render, so the two
 * always show the same numbers. Everything here is already a display string.
 *
 * A section whose payload value is an {error} object becomes ['unavailable' => true]. A section with no data
 * is null and is left out of the email.
 *
 * @phpstan-type Change array{text: string, arrow: string, color: string, direction: string}
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
     * @param  array{headline: string, bullets: list<string>, caution: string|null}  $insight
     * @param  array<string, string|null>  $charts  chart name => PNG path or null
     * @return array<string, mixed>
     */
    public static function make(Property $property, ReportPeriod $period, array $payload, array $insight, array $charts, ?string $logoPath = null): array
    {
        $monthly = $period->type->value === 'monthly';
        $currency = (string) ($property->currency ?: ($payload['meta']['currency'] ?? ''));
        $previousLabel = $monthly ? 'last month' : 'last week';
        $yearAgoLabel = $period->yearAgoStart !== null ? $period->yearAgoStart->format('M Y') : null;

        $trend = self::trend($payload, $charts);
        $channels = self::channels($payload, $charts);
        $countries = self::countries($payload, $charts);
        $searchConsole = self::searchConsole($payload);

        return [
            'propertyName' => $property->name,
            'language' => $property->language ?: 'en',
            'brandColor' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $property->brand_color) ? (string) $property->brand_color : self::DEFAULT_COLOR,
            'logoPath' => $logoPath,
            'title' => ucfirst($period->type->value).' Traffic Report',
            'periodLabel' => $period->label(),
            'subject' => self::subject($property, $period),
            'monthly' => $monthly,
            'previousLabel' => $previousLabel,
            'yearAgoLabel' => $yearAgoLabel,
            'insight' => $insight,
            'kpis' => self::kpis($payload, $currency, $monthly),
            'trend' => $trend,
            'channels' => $channels,
            'countries' => $countries,
            'countryPerformance' => self::countryPerformance($payload),
            'paid' => self::paid($payload),
            'ai' => self::ai($payload),
            'searchConsole' => $searchConsole,
            'notes' => self::notes($payload, $charts, $trend, $channels, $countries, $searchConsole),
            'generatedAt' => self::generatedAt($payload, $property),
            'sentBy' => "Sent by the {$property->name} traffic report",
        ];
    }

    // ----------------------------------------------------------------------------------------------

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

            $change = fn (?array $c) => $isRate ? ReportFormat::rateChange($c) : ReportFormat::change($c);
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

        return [
            'unavailable' => false,
            'hasChart' => ($charts['channels'] ?? null) !== null,
            'rows' => array_map(fn (array $r) => [
                'name' => (string) $r['channel'],
                'sessions' => ReportFormat::number($r['sessions']['current']),
                'change' => ReportFormat::change($r['sessions']['change'] ?? null),
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

        return [
            'unavailable' => false,
            'hasChart' => ($charts['countries'] ?? null) !== null,
            'rows' => array_map(fn (array $r) => [
                'name' => (string) $r['country'],
                'sessions' => ReportFormat::number($r['sessions']['current']),
                'share' => ReportFormat::percent($r['share']['current'] ?? null),
                'change' => ReportFormat::change($r['sessions']['change'] ?? null),
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

        return [
            'unavailable' => false,
            'rows' => array_map(function (array $r) {
                $campaign = (string) ($r['campaign'] ?? '');
                $detail = trim((string) ($r['sourceMedium'] ?? '').($campaign !== '' && ! str_starts_with($campaign, '(') ? ' · '.$campaign : ''));

                return [
                    'country' => (string) $r['country'],
                    'detail' => $detail,
                    'sessions' => ReportFormat::number($r['sessions']),
                    'engagement' => isset($r['engagementRate']) ? ReportFormat::rate($r['engagementRate']) : '–',
                    'bookingVisits' => ($r['bookingEngineVisits'] ?? null) === null ? '–' : ReportFormat::number($r['bookingEngineVisits']),
                    'quality' => (string) ($r['quality'] ?? ''),
                    'flag' => $r['flag'] ?? (($r['checkForBots'] ?? false) ? 'check for bots' : null),
                ];
            }, $rows),
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
        $row = fn (string $label, string $key, callable $format, bool $isRate = false) => isset($m[$key]) ? [
            'label' => $label,
            'value' => $format($m[$key]['current'] ?? null),
            'previous' => $format($m[$key]['previous'] ?? null),
            'change' => $isRate ? ReportFormat::rateChange($m[$key]['change'] ?? null) : ReportFormat::change($m[$key]['change'] ?? null),
        ] : null;

        return [
            'unavailable' => false,
            'currency' => $currency,
            'rows' => array_values(array_filter([
                $row('Impressions', 'impressions', fn ($v) => ReportFormat::number($v)),
                $row('Clicks', 'clicks', fn ($v) => ReportFormat::number($v)),
                self::neutral($row('Cost', 'cost', fn ($v) => ReportFormat::money($v, $currency))),
                $row('CTR', 'ctr', fn ($v) => ReportFormat::rate($v, 2), true),
                $row('Cost per click', 'costPerClick', fn ($v) => ReportFormat::money($v, $currency)),
            ])),
        ];
    }

    /** A change that is neither good nor bad (more ad spend, for example) is shown in gray. */
    private static function neutral(?array $row): ?array
    {
        if ($row !== null) {
            $row['change']['color'] = ReportFormat::GRAY;
        }

        return $row;
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

        $list = fn (array $items, string $key) => implode(', ', array_map(
            fn (array $i) => $i[$key].' ('.ReportFormat::number($i['sessions']).')',
            $items,
        ));

        return [
            'unavailable' => false,
            'total' => ReportFormat::number($ai['total']['current']),
            'totalChange' => ReportFormat::change($ai['total']['change'] ?? null),
            'rows' => array_map(fn (array $r) => [
                'source' => (string) $r['source'],
                'sessions' => ReportFormat::number($r['sessions']['current']),
                'change' => ReportFormat::change($r['sessions']['change'] ?? null),
            ], $ai['sources'] ?? []),
            'countries' => $list($ai['topCountries'] ?? [], 'country'),
            'pages' => $list($ai['topLandingPages'] ?? [], 'landingPage'),
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
        $tile = fn (string $label, string $key, string $value, array $change) => ['label' => $label, 'value' => $value, 'change' => $change];

        $list = fn (array $rows, string $dimension, bool $isPage) => array_map(fn (array $r) => [
            'name' => $isPage ? (preg_replace('#^https?://[^/]+#', '', (string) $r[$dimension]) ?: '/') : (string) $r[$dimension],
            'clicks' => ReportFormat::number($r['clicks']['current']),
            'impressions' => ReportFormat::number($r['impressions']['current']),
            'position' => ReportFormat::number($r['position']['current'], 1),
            'change' => ReportFormat::change($r['clicks']['change'] ?? null),
        ], array_slice($rows, 0, 5));

        return [
            'unavailable' => false,
            'tiles' => [
                $tile('Clicks', 'clicks', ReportFormat::number($t['clicks']['current']), ReportFormat::change($t['clicks']['change'] ?? null)),
                $tile('Impressions', 'impressions', ReportFormat::number($t['impressions']['current']), ReportFormat::change($t['impressions']['change'] ?? null)),
                $tile('CTR', 'ctr', ReportFormat::rate($t['ctr']['current'], 2), ReportFormat::rateChange($t['ctr']['change'] ?? null)),
                $tile('Avg. position', 'position', ReportFormat::number($t['position']['current'], 1), ReportFormat::change($t['position']['change'] ?? null)),
            ],
            'queries' => $list($sc['topQueries'] ?? [], 'query', false),
            'pages' => $list($sc['topPages'] ?? [], 'page', true),
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
