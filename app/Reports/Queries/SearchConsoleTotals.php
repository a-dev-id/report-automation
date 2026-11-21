<?php

declare(strict_types=1);

namespace App\Reports\Queries;

use App\Contracts\SearchConsoleClient;
use App\Enums\ReportType;
use App\Exceptions\PropertyNotConfiguredException;
use App\Models\Property;
use App\Reports\Ga4Rows;
use App\Support\Compare;
use App\Support\ReportPeriod;
use App\Support\SearchConsoleQuery;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Search Console totals, top queries and pages, and the daily series.
 *
 * Search Console data is 2 to 3 days behind, so the window comes from
 * ReportPeriod::searchConsoleWindow() and the comparison periods are cut by the same number of
 * days. This class only reports what it got: "truncated", "daysMissing" and "through" (the last day
 * with data). How the email words it is decided later.
 *
 * Output: [
 *   'hasData', 'truncated', 'daysMissing', 'through' (Y-m-d|null),
 *   'windows' => ['current' => [s, e], 'previous' => [s, e], ('yearAgo' => [s, e])],
 *   'totals' => ['impressions', 'clicks', 'ctr', 'position'] each a Compare::metric() array
 *               (position treats lower as better; ctr is a fraction; position is null with no impressions),
 *   'topQueries' => [['query', 'clicks', 'impressions', 'ctr', 'position'] each a metric (current, previous)],
 *   'topPages'   => same with 'page',
 *   'daily' => [['date', 'clicks', 'impressions'], ...] for every day of the window
 * ]
 * A top row whose previous value is missing may simply be outside the previous top 1000 rows.
 */
final class SearchConsoleTotals
{
    private const PREVIOUS_ROW_LIMIT = 1000;

    public function __construct(private readonly SearchConsoleClient $gsc)
    {
    }

    public function fetch(Property $property, ReportPeriod $period, DateTimeInterface $now): array
    {
        if (trim((string) $property->gsc_site_url) === '') {
            throw new PropertyNotConfiguredException(
                "Property '{$property->slug}' has no Search Console site (gsc_site_url is empty).",
            );
        }

        $window = $period->searchConsoleWindow($now, (int) config('report.search_console_delay_days'));
        $fmt = fn (DateTimeImmutable $d) => $d->format('Y-m-d');

        $windows = [
            'current' => [$fmt($window['start']), $fmt($window['end'])],
            'previous' => [$fmt($window['previousStart']), $fmt($window['previousEnd'])],
        ];
        if ($period->yearAgoStart !== null) {
            $windows['yearAgo'] = [
                $fmt($period->yearAgoStart),
                $fmt($period->yearAgoEnd->modify("-{$window['daysMissing']} days")),
            ];
        }

        $base = [
            'hasData' => $window['hasData'],
            'truncated' => $window['truncated'],
            'daysMissing' => $window['daysMissing'],
            'through' => $window['hasData'] ? $fmt($window['end']) : null,
            'windows' => $windows,
        ];

        if (! $window['hasData']) {
            return $base + ['totals' => null, 'topQueries' => [], 'topPages' => [], 'daily' => []];
        }

        return $base + [
            'totals' => $this->totals($property, $windows),
            'topQueries' => $this->top($property, $period, $windows, 'query', 'queries', 'search_queries'),
            'topPages' => $this->top($property, $period, $windows, 'page', 'pages', 'search_pages'),
            'daily' => $this->daily($property, $windows['current']),
        ];
    }

    private function totals(Property $property, array $windows): array
    {
        $values = ['impressions' => [], 'clicks' => [], 'ctr' => [], 'position' => []];

        foreach ($windows as $range => [$start, $end]) {
            $row = $this->gsc->query($property, new SearchConsoleQuery("totals.{$range}", $start, $end))[0] ?? null;

            $values['impressions'][$range] = $row['impressions'] ?? 0.0;
            $values['clicks'][$range] = $row['clicks'] ?? 0.0;
            $values['ctr'][$range] = $row['ctr'] ?? null;
            $values['position'][$range] = $row['position'] ?? null;
        }

        return [
            'impressions' => Compare::metric($values['impressions']),
            'clicks' => Compare::metric($values['clicks']),
            'ctr' => Compare::metric($values['ctr']),
            'position' => Compare::metric($values['position'], lowerIsBetter: true),
        ];
    }

    private function top(Property $property, ReportPeriod $period, array $windows, string $dimension, string $labelPrefix, string $configKey): array
    {
        $limit = Ga4Rows::topN($configKey, $period);

        $current = $this->gsc->query($property, new SearchConsoleQuery(
            "{$labelPrefix}.current", $windows['current'][0], $windows['current'][1], [$dimension], $limit,
        ));
        $previous = [];
        foreach ($this->gsc->query($property, new SearchConsoleQuery(
            "{$labelPrefix}.previous", $windows['previous'][0], $windows['previous'][1], [$dimension], self::PREVIOUS_ROW_LIMIT,
        )) as $row) {
            $previous[$row['keys'][0]] = $row;
        }

        return array_map(function (array $row) use ($previous, $dimension) {
            $before = $previous[$row['keys'][0]] ?? null;

            return [
                $dimension => $row['keys'][0],
                'clicks' => Compare::metric(['current' => $row['clicks'], 'previous' => $before['clicks'] ?? null]),
                'impressions' => Compare::metric(['current' => $row['impressions'], 'previous' => $before['impressions'] ?? null]),
                'ctr' => Compare::metric(['current' => $row['ctr'], 'previous' => $before['ctr'] ?? null]),
                'position' => Compare::metric(['current' => $row['position'], 'previous' => $before['position'] ?? null], lowerIsBetter: true),
            ];
        }, array_slice($current, 0, $limit));
    }

    /** One entry per day of the window; days without rows are zero. */
    private function daily(Property $property, array $window): array
    {
        $byDate = [];
        foreach ($this->gsc->query($property, new SearchConsoleQuery('daily.current', $window[0], $window[1], ['date'], 100)) as $row) {
            $byDate[$row['keys'][0]] = $row;
        }

        $days = [];
        for ($d = new DateTimeImmutable($window[0]); $d <= new DateTimeImmutable($window[1]); $d = $d->modify('+1 day')) {
            $date = $d->format('Y-m-d');
            $days[] = [
                'date' => $date,
                'clicks' => $byDate[$date]['clicks'] ?? 0.0,
                'impressions' => $byDate[$date]['impressions'] ?? 0.0,
            ];
        }

        return $days;
    }
}
