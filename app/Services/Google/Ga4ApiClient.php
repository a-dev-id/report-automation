<?php

declare(strict_types=1);

namespace App\Services\Google;

use App\Contracts\Ga4Client;
use App\Exceptions\GoogleApiException;
use App\Exceptions\GooglePermissionDeniedException;
use App\Exceptions\PropertyNotConfiguredException;
use App\Models\Property;
use App\Support\Ga4Report;
use Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient;
use Google\Analytics\Data\V1beta\DateRange;
use Google\Analytics\Data\V1beta\Dimension;
use Google\Analytics\Data\V1beta\Filter;
use Google\Analytics\Data\V1beta\Filter\StringFilter;
use Google\Analytics\Data\V1beta\Filter\StringFilter\MatchType;
use Google\Analytics\Data\V1beta\FilterExpression;
use Google\Analytics\Data\V1beta\FilterExpressionList;
use Google\Analytics\Data\V1beta\Metric;
use Google\Analytics\Data\V1beta\OrderBy;
use Google\Analytics\Data\V1beta\OrderBy\DimensionOrderBy;
use Google\Analytics\Data\V1beta\OrderBy\MetricOrderBy;
use Google\Analytics\Data\V1beta\RunReportRequest;
use Google\Analytics\Data\V1beta\RunReportResponse;
use Google\ApiCore\ApiException;
use InvalidArgumentException;
use Throwable;

/**
 * Thin GA4 Data API wrapper (runReport over REST, so the grpc extension is not needed).
 *
 * - The GA4 property ID always comes from the Property passed in, never from config.
 * - RESOURCE_EXHAUSTED, UNAVAILABLE and DEADLINE_EXCEEDED are retried with backoff.
 * - PERMISSION_DENIED becomes a GooglePermissionDeniedException that says what to fix.
 * - When several date ranges are sent, GA4 adds a "dateRange" dimension. It is removed here and the
 *   rows are returned grouped by the range names we sent.
 */
class Ga4ApiClient implements Ga4Client
{
    private const RETRYABLE = ['RESOURCE_EXHAUSTED', 'UNAVAILABLE', 'DEADLINE_EXCEEDED'];

    private ?BetaAnalyticsDataClient $sdk = null;

    /** @var array<string, string> GA4 property name => currency code from the latest response */
    private array $currencies = [];

    public function __construct(
        private readonly string $credentialsPath,
        private readonly Retrier $retrier,
    ) {
    }

    public function runReport(Property $property, Ga4Report $report): array
    {
        $propertyName = $this->propertyName($property);
        $request = $this->buildRequest($propertyName, $report);

        try {
            $response = $this->retrier->run(
                fn () => $this->send($request),
                fn (Throwable $e) => ($e instanceof ApiException && in_array($e->getStatus(), self::RETRYABLE, true))
                    || TransientNetworkError::matches($e),
            );
        } catch (ApiException $e) {
            if ($e->getStatus() === 'PERMISSION_DENIED') {
                throw new GooglePermissionDeniedException(
                    "The service account has not been added to GA4 property {$property->ga4_property_id} ({$property->slug}). "
                    .'Add it as a Viewer under GA4 Admin > Property access management.',
                    0,
                    $e,
                );
            }

            throw new GoogleApiException(
                "GA4 request '{$report->label}' failed for {$property->slug}: {$e->getStatus()} {$e->getBasicMessage()}",
                0,
                $e,
            );
        } catch (GoogleApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Network and other client errors (Guzzle, SSL...) must not escape as raw exceptions.
            throw new GoogleApiException(
                "GA4 request '{$report->label}' failed for {$property->slug}: ".class_basename($e).': '.$e->getMessage(),
                0,
                $e,
            );
        }

        $currency = $response->getMetadata()?->getCurrencyCode();
        if ($currency) {
            $this->currencies[$propertyName] = $currency;
        }

        return $this->normalise($response, $report);
    }

    public function currencyCode(Property $property): ?string
    {
        return $this->currencies[$this->propertyName($property)] ?? null;
    }

    /** @throws ApiException */
    protected function send(RunReportRequest $request): RunReportResponse
    {
        return $this->sdk()->runReport($request);
    }

    private function sdk(): BetaAnalyticsDataClient
    {
        if ($this->credentialsPath === '' || ! is_file($this->credentialsPath)) {
            throw new GoogleApiException('Google credentials file not found. Check GOOGLE_APPLICATION_CREDENTIALS.');
        }

        return $this->sdk ??= new BetaAnalyticsDataClient([
            'credentials' => $this->credentialsPath,
            'transport' => 'rest',
        ]);
    }

    private function propertyName(Property $property): string
    {
        $id = trim((string) $property->ga4_property_id);
        $id = preg_replace('#^properties/#', '', $id);

        if ($id === '' || ! ctype_digit($id)) {
            throw new PropertyNotConfiguredException(
                "Property '{$property->slug}' has no valid GA4 property ID (ga4_property_id is empty or not numeric).",
            );
        }

        return 'properties/'.$id;
    }

    public function buildRequest(string $propertyName, Ga4Report $report): RunReportRequest
    {
        $request = (new RunReportRequest())
            ->setProperty($propertyName)
            ->setDateRanges(array_map(
                fn (string $name, array $range) => (new DateRange())->setName($name)->setStartDate($range[0])->setEndDate($range[1]),
                array_keys($report->dateRanges),
                array_values($report->dateRanges),
            ))
            ->setDimensions(array_map(fn (string $d) => (new Dimension())->setName($d), $report->dimensions))
            ->setMetrics(array_map(fn (string $m) => (new Metric())->setName($m), $report->metrics))
            ->setLimit($report->limit)
            ->setKeepEmptyRows(false);

        if ($report->dimensionFilter !== null) {
            $request->setDimensionFilter($this->expression($report->dimensionFilter));
        }

        if ($report->orderBys !== []) {
            $request->setOrderBys(array_map(function (array $o) {
                $order = (new OrderBy())->setDesc($o['desc']);

                return $o['metric']
                    ? $order->setMetric((new MetricOrderBy())->setMetricName($o['field']))
                    : $order->setDimension((new DimensionOrderBy())->setDimensionName($o['field']));
            }, $report->orderBys));
        }

        return $request;
    }

    /** @param  array<string, mixed>  $node */
    private function expression(array $node): FilterExpression
    {
        $expression = new FilterExpression();

        if (isset($node['and'])) {
            return $expression->setAndGroup($this->group($node['and']));
        }
        if (isset($node['or'])) {
            return $expression->setOrGroup($this->group($node['or']));
        }
        if (isset($node['not'])) {
            return $expression->setNotExpression($this->expression($node['not']));
        }

        $matchType = match ($node['op'] ?? null) {
            'exact' => MatchType::EXACT,
            'contains' => MatchType::CONTAINS,
            'begins_with' => MatchType::BEGINS_WITH,
            'ends_with' => MatchType::ENDS_WITH,
            'regex' => MatchType::FULL_REGEXP,
            'partial_regex' => MatchType::PARTIAL_REGEXP,
            default => throw new InvalidArgumentException('Unknown GA4 filter operator: '.json_encode($node['op'] ?? null)),
        };

        return $expression->setFilter(
            (new Filter())
                ->setFieldName($node['field'])
                ->setStringFilter(
                    (new StringFilter())->setMatchType($matchType)->setValue((string) $node['value'])->setCaseSensitive(false),
                ),
        );
    }

    /** @param  list<array<string, mixed>>  $nodes */
    private function group(array $nodes): FilterExpressionList
    {
        return (new FilterExpressionList())->setExpressions(array_map(fn (array $n) => $this->expression($n), $nodes));
    }

    /**
     * @return array<string, list<array{dimensions: array<string, string>, metrics: array<string, float>}>>
     */
    public function normalise(RunReportResponse $response, Ga4Report $report): array
    {
        $dimensionNames = [];
        foreach ($response->getDimensionHeaders() as $header) {
            $dimensionNames[] = $header->getName();
        }
        $metricNames = [];
        foreach ($response->getMetricHeaders() as $header) {
            $metricNames[] = $header->getName();
        }

        $rangeNames = array_keys($report->dateRanges);
        $result = array_fill_keys($rangeNames, []);

        foreach ($response->getRows() as $row) {
            $dimensions = [];
            foreach ($row->getDimensionValues() as $i => $value) {
                $dimensions[$dimensionNames[$i]] = $value->getValue();
            }

            // Several ranges: GA4 adds "dateRange". One range: there is no such dimension.
            $range = $dimensions['dateRange'] ?? $rangeNames[0];
            unset($dimensions['dateRange']);

            $metrics = [];
            foreach ($row->getMetricValues() as $i => $value) {
                $metrics[$metricNames[$i]] = (float) $value->getValue();
            }

            if (array_key_exists($range, $result)) {
                $result[$range][] = ['dimensions' => $dimensions, 'metrics' => $metrics];
            }
        }

        return $result;
    }
}
