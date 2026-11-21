<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Property;
use App\Support\SearchConsoleQuery;

interface SearchConsoleClient
{
    /**
     * Runs one searchanalytics.query call for one property's Search Console site.
     *
     * @return list<array{keys: list<string>, clicks: float, impressions: float, ctr: float, position: float}>
     *
     * @throws \App\Exceptions\PropertyNotConfiguredException when the property has no gsc_site_url
     * @throws \App\Exceptions\GooglePermissionDeniedException when the service account was not added to the site
     * @throws \App\Exceptions\GoogleApiException for any other failure, after retries
     */
    public function query(Property $property, SearchConsoleQuery $query): array;
}
