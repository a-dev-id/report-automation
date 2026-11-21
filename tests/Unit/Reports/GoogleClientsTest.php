<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Exceptions\GoogleApiException;
use App\Exceptions\GooglePermissionDeniedException;
use App\Exceptions\PropertyNotConfiguredException;
use App\Models\Property;
use App\Services\Google\Ga4ApiClient;
use App\Services\Google\Retrier;
use App\Services\Google\SearchConsoleApiClient;
use App\Support\Ga4Report;
use App\Support\SearchConsoleQuery;
use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Google\Analytics\Data\V1beta\DimensionHeader;
use Google\Analytics\Data\V1beta\DimensionValue;
use Google\Analytics\Data\V1beta\MetricHeader;
use Google\Analytics\Data\V1beta\ResponseMetaData;
use Google\Analytics\Data\V1beta\MetricValue;
use Google\Analytics\Data\V1beta\Row;
use Google\Analytics\Data\V1beta\RunReportRequest;
use Google\Analytics\Data\V1beta\RunReportResponse;
use Google\ApiCore\ApiException;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\SearchConsole\ApiDataRow;
use Google\Service\SearchConsole\SearchAnalyticsQueryRequest;
use Google\Service\SearchConsole\SearchAnalyticsQueryResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The real client classes, with the network call replaced. No Google request is made. */
class GoogleClientsTest extends TestCase
{
    /** @var list<int> */
    private array $sleeps = [];

    private function retrier(int $attempts = 3): Retrier
    {
        $this->sleeps = [];

        return new Retrier($attempts, 10, function (int $ms): void {
            $this->sleeps[] = $ms;
        });
    }

    private function property(array $attributes = []): Property
    {
        return new Property($attributes + ['slug' => 'nandini', 'ga4_property_id' => '111111111', 'gsc_site_url' => 'sc-domain:nandini.test']);
    }

    private function ga4(Closure $handler, int $attempts = 3): Ga4ApiClient
    {
        return new class('', $this->retrier($attempts), $handler) extends Ga4ApiClient {
            public int $sent = 0;

            public function __construct(string $path, Retrier $retrier, private readonly Closure $handler)
            {
                parent::__construct($path, $retrier);
            }

            protected function send(RunReportRequest $request): RunReportResponse
            {
                $this->sent++;

                return ($this->handler)($request, $this->sent);
            }
        };
    }

    private function response(array $dimensionNames, array $metricNames, array $rows): RunReportResponse
    {
        return (new RunReportResponse())
            ->setDimensionHeaders(array_map(fn ($n) => (new DimensionHeader())->setName($n), $dimensionNames))
            ->setMetricHeaders(array_map(fn ($n) => (new MetricHeader())->setName($n), $metricNames))
            ->setRows(array_map(fn ($r) => (new Row())
                ->setDimensionValues(array_map(fn ($v) => (new DimensionValue())->setValue($v), $r[0]))
                ->setMetricValues(array_map(fn ($v) => (new MetricValue())->setValue((string) $v), $r[1])), $rows));
    }

    private function report(array $ranges = ['current' => ['2026-11-09', '2026-11-15']]): Ga4Report
    {
        return new Ga4Report('test', $ranges, ['country'], ['sessions']);
    }

    // GA4 ------------------------------------------------------------------------------------

    public function test_ga4_requires_a_property_id(): void
    {
        $client = $this->ga4(fn () => $this->fail('must not call Google'));

        foreach ([null, '', '   ', 'abc'] as $id) {
            try {
                $client->runReport($this->property(['ga4_property_id' => $id]), $this->report());
                $this->fail('Expected an exception for '.var_export($id, true));
            } catch (PropertyNotConfiguredException $e) {
                $this->assertStringContainsString('nandini', $e->getMessage());
                $this->assertStringContainsString('GA4 property ID', $e->getMessage());
            }
        }
    }

    public function test_ga4_builds_the_request_from_the_property_and_report(): void
    {
        $captured = null;
        $client = $this->ga4(function (RunReportRequest $r) use (&$captured) {
            $captured = $r;

            return $this->response([], [], []);
        });

        $client->runReport($this->property(['ga4_property_id' => 'properties/987']), new Ga4Report(
            'test',
            ['current' => ['2026-11-09', '2026-11-15'], 'previous' => ['2026-11-02', '2026-11-08']],
            ['country'],
            ['sessions', 'totalUsers'],
            ['and' => [
                ['field' => 'hostName', 'op' => 'exact', 'value' => 'book.example.com'],
                ['not' => ['field' => 'country', 'op' => 'exact', 'value' => '(not set)']],
            ]],
            [['field' => 'sessions', 'metric' => true, 'desc' => true]],
            50,
        ));

        $this->assertSame('properties/987', $captured->getProperty());
        $this->assertSame(['current', 'previous'], array_map(fn ($d) => $d->getName(), iterator_to_array($captured->getDateRanges())));
        $this->assertSame('2026-11-02', iterator_to_array($captured->getDateRanges())[1]->getStartDate());
        $this->assertSame(50, $captured->getLimit());
        $this->assertSame('sessions', iterator_to_array($captured->getOrderBys())[0]->getMetric()->getMetricName());
        $this->assertTrue(iterator_to_array($captured->getOrderBys())[0]->getDesc());

        $and = iterator_to_array($captured->getDimensionFilter()->getAndGroup()->getExpressions());
        $this->assertSame('hostName', $and[0]->getFilter()->getFieldName());
        $this->assertSame('book.example.com', $and[0]->getFilter()->getStringFilter()->getValue());
        $this->assertFalse($and[0]->getFilter()->getStringFilter()->getCaseSensitive());
        $this->assertSame('country', $and[1]->getNotExpression()->getFilter()->getFieldName());
    }

    public function test_ga4_splits_rows_by_the_date_range_dimension_when_several_ranges_are_sent(): void
    {
        $client = $this->ga4(fn () => $this->response(['country', 'dateRange'], ['sessions'], [
            [['Indonesia', 'current'], [100]],
            [['Indonesia', 'previous'], [80]],
            [['Japan', 'current'], [5]],
        ]));

        $result = $client->runReport($this->property(), $this->report([
            'current' => ['2026-11-09', '2026-11-15'], 'previous' => ['2026-11-02', '2026-11-08'], 'yearAgo' => ['2025-11-10', '2025-11-16'],
        ]));

        $this->assertSame(['current', 'previous', 'yearAgo'], array_keys($result));
        $this->assertSame([['dimensions' => ['country' => 'Indonesia'], 'metrics' => ['sessions' => 100.0]], ['dimensions' => ['country' => 'Japan'], 'metrics' => ['sessions' => 5.0]]], $result['current']);
        $this->assertSame(80.0, $result['previous'][0]['metrics']['sessions']);
        $this->assertSame([], $result['yearAgo']);
    }

    public function test_ga4_with_one_range_has_no_date_range_dimension(): void
    {
        $client = $this->ga4(fn () => $this->response(['country'], ['sessions'], [[['Indonesia'], [100]]]));

        $result = $client->runReport($this->property(), $this->report());

        $this->assertSame(100.0, $result['current'][0]['metrics']['sessions']);
        $this->assertSame(['country' => 'Indonesia'], $result['current'][0]['dimensions']);
    }

    public function test_ga4_permission_denied_says_the_service_account_is_missing_and_is_not_retried(): void
    {
        $client = $this->ga4(fn () => throw new ApiException('denied', 7, 'PERMISSION_DENIED'));

        try {
            $client->runReport($this->property(), $this->report());
            $this->fail('Expected GooglePermissionDeniedException');
        } catch (GooglePermissionDeniedException $e) {
            $this->assertStringContainsString('service account has not been added to GA4 property 111111111', $e->getMessage());
        }

        $this->assertSame(1, $client->sent);
        $this->assertSame([], $this->sleeps);
    }

    #[DataProvider('retryableStatuses')]
    public function test_ga4_retries_quota_and_transient_errors_with_backoff(string $status): void
    {
        $client = $this->ga4(fn ($request, int $attempt) => $attempt < 3
            ? throw new ApiException('busy', 8, $status)
            : $this->response(['country'], ['sessions'], [[['Indonesia'], [1]]]));

        $result = $client->runReport($this->property(), $this->report());

        $this->assertSame(1.0, $result['current'][0]['metrics']['sessions']);
        $this->assertSame(3, $client->sent);
        $this->assertSame([10, 20], $this->sleeps);
    }

    public static function retryableStatuses(): array
    {
        return [['RESOURCE_EXHAUSTED'], ['UNAVAILABLE'], ['DEADLINE_EXCEEDED']];
    }

    public function test_ga4_gives_up_after_the_last_attempt(): void
    {
        $client = $this->ga4(fn () => throw new ApiException('quota', 8, 'RESOURCE_EXHAUSTED'), attempts: 3);

        try {
            $client->runReport($this->property(), $this->report());
            $this->fail('Expected GoogleApiException');
        } catch (GoogleApiException $e) {
            $this->assertNotInstanceOf(GooglePermissionDeniedException::class, $e);
            $this->assertStringContainsString('RESOURCE_EXHAUSTED', $e->getMessage());
        }

        $this->assertSame(3, $client->sent);
    }

    public function test_ga4_does_not_retry_a_bad_request(): void
    {
        $client = $this->ga4(fn () => throw new ApiException('bad metric', 3, 'INVALID_ARGUMENT'));

        $this->expectException(GoogleApiException::class);
        try {
            $client->runReport($this->property(), $this->report());
        } finally {
            $this->assertSame(1, $client->sent);
        }
    }

    public function test_ga4_remembers_the_currency_from_the_response_metadata_per_property(): void
    {
        $client = $this->ga4(function ($request) {
            $response = $this->response(['country'], ['sessions'], [[['Indonesia'], [1]]]);
            $currency = str_contains($request->getProperty(), '111') ? 'IDR' : 'USD';
            $response->setMetadata((new ResponseMetaData())->setCurrencyCode($currency));

            return $response;
        });
        $nandini = $this->property();
        $other = $this->property(['slug' => 'other', 'ga4_property_id' => '222222222']);

        $this->assertNull($client->currencyCode($nandini), 'nothing has run yet');

        $client->runReport($nandini, $this->report());
        $client->runReport($other, $this->report());

        $this->assertSame('IDR', $client->currencyCode($nandini));
        $this->assertSame('USD', $client->currencyCode($other));
    }

    public function test_ga4_currency_is_null_when_the_response_has_no_metadata(): void
    {
        $client = $this->ga4(fn () => $this->response(['country'], ['sessions'], []));
        $client->runReport($this->property(), $this->report());

        $this->assertNull($client->currencyCode($this->property()));
    }

    public function test_ga4_wraps_and_retries_network_errors_but_not_certificate_errors(): void
    {
        $timeout = fn () => new ConnectException('cURL error 28: Operation timed out', new Request('POST', 'https://analyticsdata.googleapis.com'));

        $client = $this->ga4(fn ($request, int $attempt) => $attempt < 2 ? throw $timeout() : $this->response(['country'], ['sessions'], [[['Indonesia'], [1]]]));
        $this->assertSame(1.0, $client->runReport($this->property(), $this->report())['current'][0]['metrics']['sessions']);
        $this->assertSame(2, $client->sent);

        $client = $this->ga4(fn () => throw new ConnectException('cURL error 60: SSL certificate problem: unable to get local issuer certificate', new Request('POST', 'https://x')));
        try {
            $client->runReport($this->property(), $this->report());
            $this->fail('Expected GoogleApiException');
        } catch (GoogleApiException $e) {
            $this->assertStringContainsString('SSL certificate problem', $e->getMessage());
            $this->assertStringContainsString('nandini', $e->getMessage());
        }
        $this->assertSame(1, $client->sent);
    }

    // Search Console -------------------------------------------------------------------------

    private function gsc(Closure $handler, int $attempts = 3): SearchConsoleApiClient
    {
        return new class('', $this->retrier($attempts), $handler) extends SearchConsoleApiClient {
            public int $sent = 0;

            /** @var list<string> */
            public array $sites = [];

            public function __construct(string $path, Retrier $retrier, private readonly Closure $handler)
            {
                parent::__construct($path, $retrier);
            }

            protected function send(string $siteUrl, SearchAnalyticsQueryRequest $request): SearchAnalyticsQueryResponse
            {
                $this->sent++;
                $this->sites[] = $siteUrl;

                return ($this->handler)($request, $this->sent);
            }
        };
    }

    private function gscResponse(array $rows): SearchAnalyticsQueryResponse
    {
        $response = new SearchAnalyticsQueryResponse();
        $response->setRows(array_map(function (array $r) {
            $row = new ApiDataRow();
            $row->setKeys($r['keys']);
            $row->setClicks($r['clicks']);
            $row->setImpressions($r['impressions']);
            $row->setCtr($r['ctr']);
            $row->setPosition($r['position']);

            return $row;
        }, $rows));

        return $response;
    }

    public function test_gsc_requires_a_site_url(): void
    {
        $client = $this->gsc(fn () => $this->fail('must not call Google'));

        $this->expectException(PropertyNotConfiguredException::class);
        $client->query($this->property(['gsc_site_url' => null]), new SearchConsoleQuery('t', '2026-11-09', '2026-11-15'));
    }

    public function test_gsc_sends_the_properties_own_site_and_maps_rows(): void
    {
        $captured = null;
        $client = $this->gsc(function (SearchAnalyticsQueryRequest $r) use (&$captured) {
            $captured = $r;

            return $this->gscResponse([['keys' => ['nandini ubud'], 'clicks' => 12, 'impressions' => 340, 'ctr' => 0.035, 'position' => 2.5]]);
        });

        $rows = $client->query($this->property(), new SearchConsoleQuery('queries', '2026-11-09', '2026-11-15', ['query'], 5));

        $this->assertSame(['sc-domain:nandini.test'], $client->sites);
        $this->assertSame('2026-11-09', $captured->getStartDate());
        $this->assertSame(['query'], $captured->getDimensions());
        $this->assertSame(5, $captured->getRowLimit());
        $this->assertSame('web', $captured->getSearchType());
        $this->assertSame([['keys' => ['nandini ubud'], 'clicks' => 12.0, 'impressions' => 340.0, 'ctr' => 0.035, 'position' => 2.5]], $rows);
    }

    public function test_gsc_returns_no_rows_when_there_is_no_data(): void
    {
        $client = $this->gsc(fn () => new SearchAnalyticsQueryResponse());

        $this->assertSame([], $client->query($this->property(), new SearchConsoleQuery('t', '2026-11-09', '2026-11-15')));
    }

    public function test_gsc_403_means_the_service_account_was_not_added_and_is_not_retried(): void
    {
        $client = $this->gsc(fn () => throw new GoogleServiceException('forbidden', 403, null, [['reason' => 'forbidden']]));

        try {
            $client->query($this->property(), new SearchConsoleQuery('t', '2026-11-09', '2026-11-15'));
            $this->fail('Expected GooglePermissionDeniedException');
        } catch (GooglePermissionDeniedException $e) {
            $this->assertStringContainsString('service account has not been added to Search Console site sc-domain:nandini.test', $e->getMessage());
        }

        $this->assertSame(1, $client->sent);
    }

    public function test_gsc_retries_rate_limits_and_server_errors(): void
    {
        foreach ([[429, 'rateLimitExceeded'], [503, 'backendError'], [403, 'rateLimitExceeded']] as [$code, $reason]) {
            $client = $this->gsc(fn ($request, int $attempt) => $attempt < 2
                ? throw new GoogleServiceException('busy', $code, null, [['reason' => $reason]])
                : $this->gscResponse([]));

            $client->query($this->property(), new SearchConsoleQuery('t', '2026-11-09', '2026-11-15'));

            $this->assertSame(2, $client->sent, "HTTP {$code} {$reason} should be retried once");
        }
    }

    public function test_gsc_wraps_network_errors(): void
    {
        $client = $this->gsc(fn () => throw new ConnectException('cURL error 60: SSL certificate problem', new Request('POST', 'https://x')));

        $this->expectException(GoogleApiException::class);
        $this->expectExceptionMessage('SSL certificate problem');
        $client->query($this->property(), new SearchConsoleQuery('t', '2026-11-09', '2026-11-15'));
    }

    public function test_gsc_does_not_retry_a_bad_request(): void
    {
        $client = $this->gsc(fn () => throw new GoogleServiceException('bad dates', 400, null, [['reason' => 'badRequest']]));

        try {
            $client->query($this->property(), new SearchConsoleQuery('t', '2026-11-09', '2026-11-15'));
            $this->fail('Expected GoogleApiException');
        } catch (GoogleApiException $e) {
            $this->assertNotInstanceOf(GooglePermissionDeniedException::class, $e);
        }

        $this->assertSame(1, $client->sent);
    }
}
