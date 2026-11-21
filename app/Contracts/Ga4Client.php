<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Property;
use App\Support\Ga4Report;

interface Ga4Client
{
    /**
     * Runs one GA4 report for one property.
     *
     * The result is keyed by the date range names of the request ("current", "previous", "yearAgo").
     * GA4 adds a "dateRange" dimension when several ranges are sent; that is removed here. Every
     * range of the request is present in the result, with an empty list if GA4 had no rows.
     *
     * @return array<string, list<array{dimensions: array<string, string>, metrics: array<string, float>}>>
     *
     * @throws \App\Exceptions\PropertyNotConfiguredException when the property has no GA4 property ID
     * @throws \App\Exceptions\GooglePermissionDeniedException when the service account was not added to the GA4 property
     * @throws \App\Exceptions\GoogleApiException for any other failure, after retries
     */
    public function runReport(Property $property, Ga4Report $report): array;

    /**
     * The currency code GA4 reported (response metadata) on the most recent successful report for this
     * property, or null if none has run yet. Paid cost and revenue are in this currency.
     */
    public function currencyCode(Property $property): ?string;
}
