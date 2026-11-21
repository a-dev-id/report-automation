<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Ga4Client;
use App\Contracts\ReportBuilder;
use App\Models\Property;
use App\Reports\QualityAlerts;
use App\Reports\Queries\AiReferralTraffic;
use App\Reports\Queries\CountryPerformance;
use App\Reports\Queries\PaidPerformance;
use App\Reports\Queries\SearchConsoleTotals;
use App\Reports\Queries\SiteTotals;
use App\Reports\Queries\TrafficByChannel;
use App\Reports\Queries\TrafficByCountry;
use App\Reports\Queries\Trend;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Collects the GA4 and Search Console numbers for ONE property and ONE period.
 * The full payload shape is documented in docs/payload.md.
 *
 * "site" and "search_console" are required: if either fails, the exception is thrown and the whole
 * build fails (the job records it and /cron/retry-failed tries again). Every other section that
 * fails is replaced by ['error' => ['type' => ..., 'message' => ...]] and the rest is kept.
 * quality_alerts are informational and can never fail the build.
 */
class Ga4GscReportBuilder implements ReportBuilder
{
    public function __construct(
        private readonly Ga4Client $ga4,
        private readonly SiteTotals $site,
        private readonly TrafficByChannel $channels,
        private readonly TrafficByCountry $countries,
        private readonly CountryPerformance $countryPerformance,
        private readonly AiReferralTraffic $aiTraffic,
        private readonly PaidPerformance $paid,
        private readonly Trend $trend,
        private readonly SearchConsoleTotals $searchConsole,
    ) {
    }

    public function build(Property $property, ReportPeriod $period): array
    {
        return $this->buildAt($property, $period, CarbonImmutable::now());
    }

    /** Same as build(), with "now" given. It decides how much Search Console data is ready. */
    public function buildAt(Property $property, ReportPeriod $period, DateTimeInterface $now): array
    {
        // Required sections first, so a permission or configuration problem fails fast.
        $site = $this->site->fetch($property, $period);
        $searchConsole = $this->searchConsole->fetch($property, $period, $now);

        $payload = [
            'meta' => null,   // filled in last: it reports the currency GA4 returned
            'site' => $site,
            'channels' => $this->optional('channels', $property, fn () => $this->channels->fetch($property, $period)),
            'countries' => $this->optional('countries', $property, fn () => $this->countries->fetch($property, $period, $site['sessions'])),
            'country_performance' => $this->optional('country_performance', $property, fn () => $this->countryPerformance->fetch($property, $period)),
            'ai_traffic' => $this->optional('ai_traffic', $property, fn () => $this->aiTraffic->fetch($property, $period)),
            'paid' => $this->optional('paid', $property, fn () => $this->paid->fetch($property, $period)),
            'trend' => $this->optional('trend', $property, fn () => $this->trend->fetch($property, $period)),
            'search_console' => $searchConsole,
        ];

        $ga4Currency = $this->ga4->currencyCode($property);
        $payload['meta'] = $this->meta($property, $period, $now, $ga4Currency);
        $payload['quality_alerts'] = $this->alerts($property, $payload, $ga4Currency);

        return $payload;
    }

    /** Alerts are informational: if building them fails, the payload is returned without them. */
    private function alerts(Property $property, array $payload, ?string $ga4Currency): array
    {
        try {
            return QualityAlerts::evaluate($property, $payload, $ga4Currency);
        } catch (Throwable $e) {
            Log::warning('Quality alerts failed.', ['property' => $property->slug, 'exception' => $e]);

            return [];
        }
    }

    private function meta(Property $property, ReportPeriod $period, DateTimeInterface $now, ?string $ga4Currency): array
    {
        $ranges = $period->dateRanges();

        return [
            'slug' => $property->slug,
            'name' => $property->name,
            'currency' => $property->currency,
            'ga4Currency' => $ga4Currency,
            'timezone' => $property->timezone,
            'type' => $period->type->value,
            'periodLabel' => $period->label(),
            'dateRanges' => $ranges,
            'generatedAt' => CarbonImmutable::instance($now)->setTimezone($property->timezone)->toIso8601String(),
        ];
    }

    /** @param  Closure(): mixed  $section */
    private function optional(string $name, Property $property, Closure $section): mixed
    {
        try {
            return $section();
        } catch (Throwable $e) {
            Log::warning('Report section failed.', ['section' => $name, 'property' => $property->slug, 'exception' => $e]);

            return ['error' => ['type' => class_basename($e), 'message' => $e->getMessage()]];
        }
    }
}
