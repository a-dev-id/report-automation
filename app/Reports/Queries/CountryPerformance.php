<?php

declare(strict_types=1);

namespace App\Reports\Queries;

use App\Contracts\Ga4Client;
use App\Models\Property;
use App\Reports\BookingEngineFilter;
use App\Reports\Ga4Rows;
use App\Support\Compare;
use App\Support\Ga4Report;
use App\Support\ReportPeriod;

/**
 * Country x campaign x source/medium rows for the current period, top 10 by ENGAGED sessions. Only sessions are
 * compared with the previous period (one extra, sessions-only query matched by country, campaign and source).
 *
 * bookingEngineEvents comes from a second query under the property's booking engine filter and is
 * null for every row when the property has none. The quality label and the bot flag use the
 * thresholds in config report.traffic_quality (see the comments there).
 *
 * Output: list of [
 *   'country', 'campaign', 'sourceMedium', 'users', 'sessions', 'previousSessions', 'sessionsChange' (Compare::change),
 *   'engagedSessions' (GA4 engagedSessions, or sessions x engagementRate if GA4 did not return it),
 *   'averageSessionDuration', 'engagementRate' (fraction),
 *   'bookingEngineVisits' (float|null: sessions that contain the booking event / reached the booking engine),
 *   'bookingEngineEvents' (float|null: eventCount under the same filter), 'purchasers', 'purchaseRevenue',
 *   'quality' (High Value|Potential|Low Intent|Low Data),
 *   'checkForBots' (bool) and 'flag' ('check for bots' or null)
 * ]
 */
final class CountryPerformance
{
    private const DIMENSIONS = ['country', 'sessionCampaignName', 'sessionSourceMedium'];

    public function __construct(private readonly Ga4Client $ga4)
    {
    }

    public function fetch(Property $property, ReportPeriod $period): array
    {
        $current = ['current' => Ga4Rows::ranges($period)['current']];
        $limit = Ga4Rows::topN('country_performance', $period);

        $main = $this->ga4->runReport($property, new Ga4Report(
            'country_performance',
            $current,
            self::DIMENSIONS,
            ['totalUsers', 'sessions', 'engagedSessions', 'averageSessionDuration', 'engagementRate', 'totalPurchasers', 'purchaseRevenue'],
            null,
            [['field' => 'engagedSessions', 'metric' => true, 'desc' => true]],
            $limit,
        ));

        $booking = BookingEngineFilter::fromProperty($property);
        $bookingRows = null;
        if ($booking->isConfigured() && $main['current'] !== []) {
            $bookingRows = Ga4Rows::group($this->ga4->runReport($property, new Ga4Report(
                'country_performance.booking',
                $current,
                self::DIMENSIONS,
                [$booking->visitsMetric(), $booking->eventsMetric()],
                $booking->filter(),
            )), self::DIMENSIONS);
        } elseif ($booking->isConfigured()) {
            $bookingRows = [];
        }

        $previous = Ga4Rows::group($this->ga4->runReport($property, new Ga4Report(
            'country_performance.previous',
            ['previous' => Ga4Rows::ranges($period)['previous']],
            self::DIMENSIONS,
            ['sessions'],
        )), self::DIMENSIONS);

        $rows = [];
        foreach (array_slice($main['current'], 0, $limit) as $row) {
            $key = implode("\x1f", array_map(fn ($d) => (string) $row['dimensions'][$d], self::DIMENSIONS));
            $m = $row['metrics'];
            $previousSessions = (float) ($previous[$key]['ranges']['previous']['sessions'] ?? 0.0);
            $bookingVisits = $bookingRows === null ? null : (float) ($bookingRows[$key]['ranges']['current'][$booking->visitsMetric()] ?? 0.0);
            $bookingEvents = $bookingRows === null ? null : (float) ($bookingRows[$key]['ranges']['current'][$booking->eventsMetric()] ?? 0.0);
            $bots = self::looksLikeBots($m['sessions'] ?? 0.0, $m['averageSessionDuration'] ?? 0.0, $bookingVisits, $m['totalPurchasers'] ?? 0.0);

            $rows[] = [
                'country' => $row['dimensions']['country'],
                'campaign' => $row['dimensions']['sessionCampaignName'],
                'sourceMedium' => $row['dimensions']['sessionSourceMedium'],
                'users' => $m['totalUsers'] ?? 0.0,
                'sessions' => $m['sessions'] ?? 0.0,
                'engagedSessions' => $m['engagedSessions'] ?? round(($m['sessions'] ?? 0.0) * ($m['engagementRate'] ?? 0.0), 1),
                'previousSessions' => $previousSessions,
                'sessionsChange' => Compare::change($m['sessions'] ?? 0.0, $previousSessions),
                'averageSessionDuration' => $m['averageSessionDuration'] ?? 0.0,
                'engagementRate' => $m['engagementRate'] ?? 0.0,
                'bookingEngineVisits' => $bookingVisits,
                'bookingEngineEvents' => $bookingEvents,
                'purchasers' => $m['totalPurchasers'] ?? 0.0,
                'purchaseRevenue' => $m['purchaseRevenue'] ?? 0.0,
                'quality' => self::quality($m['sessions'] ?? 0.0, $m['averageSessionDuration'] ?? 0.0, $bookingEvents, $m['totalPurchasers'] ?? 0.0),
                'checkForBots' => $bots,
                'flag' => $bots ? 'check for bots' : null,
            ];
        }

        return $rows;
    }

    public static function quality(float $sessions, float $avgDuration, ?float $bookingEvents, float $purchasers): string
    {
        $c = config('report.traffic_quality');

        if ($sessions < $c['min_sessions']) {
            return 'Low Data';
        }

        if ($purchasers >= $c['high_value']['min_purchasers']
            || ($bookingEvents !== null && $bookingEvents >= $c['high_value']['min_booking_events'])) {
            return 'High Value';
        }

        if (($bookingEvents !== null && $bookingEvents >= $c['potential']['min_booking_events'])
            || $avgDuration >= $c['potential']['min_avg_session_seconds']) {
            return 'Potential';
        }

        return 'Low Intent';
    }

    /**
     * Very short sessions, no booking visits and no purchasers, with enough sessions to judge.
     * Never true when the booking definition is missing ($bookingVisits null).
     */
    public static function looksLikeBots(float $sessions, float $avgDuration, ?float $bookingVisits, float $purchasers): bool
    {
        $c = config('report.traffic_quality');

        return $bookingVisits !== null
            && $sessions >= $c['min_sessions']
            && $avgDuration < $c['bot_check']['max_avg_session_seconds']
            && $bookingVisits === 0.0
            && $purchasers === 0.0;
    }
}
