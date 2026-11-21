<?php

declare(strict_types=1);

namespace App\Reports\Queries;

use App\Contracts\Ga4Client;
use App\Models\Property;
use App\Reports\BookingEngineFilter;
use App\Reports\Ga4Rows;
use App\Support\Ga4Report;
use App\Support\ReportPeriod;

/**
 * Whole-site totals. Each value is a Compare::metric() array (current, previous, change and,
 * for monthly reports, yearAgo and yearAgoChange).
 *
 * Output: sessions, totalUsers, averageSessionDuration (seconds), purchases, purchaseRevenue (property currency),
 * bookingEngineVisits (sessions that reached the booking engine or contained its event) and bookingEvents
 * (eventCount of the booking event). bookingEvents is only reported for the "event" booking type: for hostname
 * and path it would count every event there (page_view included), so it is null. Both are null when the
 * property has no booking engine definition. They come from one extra GA4 query.
 */
final class SiteTotals
{
    public function __construct(private readonly Ga4Client $ga4)
    {
    }

    public function fetch(Property $property, ReportPeriod $period): array
    {
        $ranges = Ga4Rows::ranges($period);
        $names = array_keys($ranges);

        $totals = $this->ga4->runReport($property, new Ga4Report(
            'site.totals',
            $ranges,
            [],
            ['sessions', 'totalUsers', 'averageSessionDuration', 'ecommercePurchases', 'purchaseRevenue'],
        ));

        $booking = BookingEngineFilter::fromProperty($property);
        $bookingVisits = null;
        $bookingEvents = null;
        if ($booking->isConfigured()) {
            $result = $this->ga4->runReport($property, new Ga4Report(
                'site.booking',
                $ranges,
                [],
                $booking->bookingEventsMetric() === null ? [$booking->visitsMetric()] : [$booking->visitsMetric(), $booking->bookingEventsMetric()],
                $booking->filter(),
            ));
            $bookingVisits = Ga4Rows::totalMetric($result, $names, $booking->visitsMetric());
            if ($booking->bookingEventsMetric() !== null) {
                $bookingEvents = Ga4Rows::totalMetric($result, $names, $booking->bookingEventsMetric());
            }
        }

        return [
            'sessions' => Ga4Rows::totalMetric($totals, $names, 'sessions'),
            'totalUsers' => Ga4Rows::totalMetric($totals, $names, 'totalUsers'),
            'averageSessionDuration' => Ga4Rows::totalMetric($totals, $names, 'averageSessionDuration'),
            'bookingEngineVisits' => $bookingVisits,
            'bookingEvents' => $bookingEvents,
            'purchases' => Ga4Rows::totalMetric($totals, $names, 'ecommercePurchases'),
            'purchaseRevenue' => Ga4Rows::totalMetric($totals, $names, 'purchaseRevenue'),
        ];
    }
}
