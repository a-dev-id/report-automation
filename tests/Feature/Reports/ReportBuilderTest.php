<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Contracts\Ga4Client;
use App\Contracts\ReportBuilder;
use App\Contracts\SearchConsoleClient;
use App\Enums\ReportType;
use App\Exceptions\GoogleApiException;
use App\Exceptions\GooglePermissionDeniedException;
use App\Exceptions\PropertyNotConfiguredException;
use App\Models\Property;
use App\Services\Ga4GscReportBuilder;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Fakes\FakeData;
use Tests\Fakes\FakeGa4Client;
use Tests\Fakes\FakeSearchConsoleClient;
use Tests\TestCase;

class ReportBuilderTest extends TestCase
{
    use RefreshDatabase;

    private FakeGa4Client $ga4;

    private FakeSearchConsoleClient $gsc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ga4 = new FakeGa4Client(FakeData::ga4(), [FakeData::NANDINI_ID => 'IDR', FakeData::KAMANDALU_ID => 'USD']);
        $this->gsc = new FakeSearchConsoleClient(FakeData::gsc());
        $this->app->instance(Ga4Client::class, $this->ga4);
        $this->app->instance(SearchConsoleClient::class, $this->gsc);
    }

    private function build(Property $property, ReportType $type, string $now = FakeData::NOW): array
    {
        $now = CarbonImmutable::parse($now, 'Asia/Makassar');
        $period = ReportPeriod::forType($type, $now, new DateTimeZone($property->timezone));

        return $this->app->make(Ga4GscReportBuilder::class)->buildAt($property, $period, $now);
    }

    public function test_the_real_builder_is_bound_to_the_contract(): void
    {
        $this->assertInstanceOf(Ga4GscReportBuilder::class, $this->app->make(ReportBuilder::class));
    }

    public function test_weekly_payload_end_to_end(): void
    {
        $payload = $this->build(FakeData::nandini(), ReportType::Weekly);

        $this->assertSame(
            ['meta', 'site', 'channels', 'countries', 'country_performance', 'ai_traffic', 'paid', 'trend', 'search_console', 'quality_alerts'],
            array_keys($payload),
        );

        $meta = $payload['meta'];
        $this->assertSame('nandini', $meta['slug']);
        $this->assertSame('Nandini', $meta['name']);
        $this->assertSame('IDR', $meta['currency']);
        $this->assertSame('IDR', $meta['ga4Currency']);
        $this->assertSame('IDR', $payload['paid']['currency']);
        $this->assertSame([], $payload['quality_alerts']);   // the clean fixtures raise nothing
        $this->assertSame(10000.0, $payload['countries']['sessionsSum']);
        $this->assertSame(10000.0, $payload['countries']['sessionsTotal']);
        $this->assertFalse($payload['countries']['warning']);
        $this->assertSame('Asia/Makassar', $meta['timezone']);
        $this->assertSame('weekly', $meta['type']);
        $this->assertSame('9 to 15 Nov 2026', $meta['periodLabel']);
        $this->assertSame(['2026-11-09', '2026-11-15'], $meta['dateRanges']['current']);
        $this->assertSame(['2026-11-02', '2026-11-08'], $meta['dateRanges']['previous']);
        $this->assertNull($meta['dateRanges']['yearAgo']);
        $this->assertSame('2026-11-21T10:00:00+08:00', $meta['generatedAt']);

        $this->assertSame(10000.0, $payload['site']['sessions']['current']);
        $this->assertCount(7, $payload['channels']['rows']);
        $this->assertCount(9, $payload['countries']['rows']);
        $this->assertCount(10, $payload['country_performance']);
        $this->assertSame(175.0, $payload['ai_traffic']['total']['current']);
        $this->assertSame('ga4', $payload['paid']['source']);
        $this->assertCount(8, $payload['trend']['points']);
        $this->assertSame(500.0, $payload['search_console']['totals']['clicks']['current']);

        // The payload is stored as JSON in reports.payload.
        $this->assertNotFalse(json_encode($payload));
        $this->assertEquals($payload, json_decode(json_encode($payload), true));
    }

    public function test_monthly_payload_end_to_end(): void
    {
        $payload = $this->build(FakeData::nandini(), ReportType::Monthly);

        $this->assertSame('monthly', $payload['meta']['type']);
        $this->assertSame('October 2026', $payload['meta']['periodLabel']);
        $this->assertSame(['2025-10-01', '2025-10-31'], $payload['meta']['dateRanges']['yearAgo']);
        $this->assertSame(5000.0, $payload['site']['sessions']['yearAgo']);
        $this->assertCount(11, $payload['countries']['rows']);
        $this->assertCount(12, $payload['trend']['points']);
        $this->assertCount(10, $payload['search_console']['topQueries']);
        $this->assertSame(['2025-10-01', '2025-10-31'], $payload['search_console']['windows']['yearAgo']);
    }

    public function test_two_properties_produce_different_payloads_and_never_mix_data(): void
    {
        $nandini = $this->build(FakeData::nandini(), ReportType::Weekly);
        $kamandalu = $this->build(FakeData::kamandalu(), ReportType::Weekly);

        $this->assertSame('IDR', $nandini['meta']['currency']);
        $this->assertSame('USD', $kamandalu['meta']['currency']);
        $this->assertSame(10000.0, $nandini['site']['sessions']['current']);
        $this->assertSame(20000.0, $kamandalu['site']['sessions']['current']);
        $this->assertSame(900.0, $nandini['site']['bookingEngineVisits']['current']);
        $this->assertSame(1800.0, $kamandalu['site']['bookingEngineVisits']['current']);
        $this->assertNotNull($nandini['paid']);
        $this->assertNull($kamandalu['paid']);
        $this->assertSame(500.0, $nandini['search_console']['totals']['clicks']['current']);
        $this->assertSame(1000.0, $kamandalu['search_console']['totals']['clicks']['current']);
        $this->assertSame('https://nandini.test/', $nandini['search_console']['topPages'][0]['page']);

        // Every GA4 call used the ID of the hotel it was for, and every Search Console call its own site.
        $ids = array_unique(array_column($this->ga4->calls, 'propertyId'));
        sort($ids);
        $this->assertSame([FakeData::NANDINI_ID, FakeData::KAMANDALU_ID], $ids);
        $this->assertSame(['sc-domain:nandini.test', 'https://kamandalu.test/'], array_values(array_unique(array_column($this->gsc->calls, 'siteUrl'))));

        // Different booking rules produced different GA4 filters.
        $this->assertSame('hostName', $this->ga4->reportsFor(FakeData::NANDINI_ID, 'country_performance.booking')[0]->dimensionFilter['field']);
        $this->assertSame('eventName', $this->ga4->reportsFor(FakeData::KAMANDALU_ID, 'country_performance.booking')[0]->dimensionFilter['field']);

        // Building the second hotel did not change the first one's payload.
        $this->assertSame($nandini, $this->build(FakeData::nandini(), ReportType::Weekly));
    }

    /** @param  callable(array): array  $change */
    private function buildWithFixtures(callable $change, array $currencies = [FakeData::NANDINI_ID => 'IDR']): array
    {
        $fixtures = FakeData::ga4();
        $fixtures[FakeData::NANDINI_ID] = $change($fixtures[FakeData::NANDINI_ID]);
        $this->app->instance(Ga4Client::class, new FakeGa4Client($fixtures, $currencies));

        return $this->build(FakeData::nandini(), ReportType::Weekly);
    }

    public function test_country_sum_mismatch_is_an_alert_and_the_country_rows_are_unchanged(): void
    {
        $clean = $this->build(FakeData::nandini(), ReportType::Weekly);

        $payload = $this->buildWithFixtures(function (array $f) {
            $f['site.totals']['current'][0]['metrics']['sessions'] = 5000.0;

            return $f;
        });

        $this->assertTrue($payload['countries']['warning']);
        $this->assertSame(10000.0, $payload['countries']['sessionsSum']);
        $this->assertSame(5000.0, $payload['countries']['sessionsTotal']);
        $this->assertSame($clean['countries']['rows'], $payload['countries']['rows']);

        $alert = $payload['quality_alerts'][0];
        $this->assertSame('country_sum_mismatch', $alert['code']);
        $this->assertSame(100.0, $alert['details']['excessPercent']);
        $this->assertSame(10.0, $alert['details']['tolerancePercent']);
        $this->assertNotEmpty($alert['message']);
    }

    public function test_direct_low_engagement_needs_both_a_big_share_and_short_sessions(): void
    {
        $direct = fn (float $sessions, float $duration) => function (array $f) use ($sessions, $duration) {
            $f['channels']['current'][1]['metrics']['sessions'] = $sessions;   // Direct is the second row
            $f['channels']['current'][1]['metrics']['averageSessionDuration'] = $duration;

            return $f;
        };
        $codes = fn (array $payload) => array_column($payload['quality_alerts'], 'code');

        // Direct 9500 of 17000 channel sessions = 55.9% and 12 s: alert.
        $alert = $this->buildWithFixtures($direct(9500.0, 12.0));
        $this->assertContains('direct_low_engagement', $codes($alert));
        $details = array_column($alert['quality_alerts'], null, 'code')['direct_low_engagement']['details'];
        $this->assertSame(55.9, $details['directSharePercent']);
        $this->assertSame(12.0, $details['directAverageSessionDuration']);

        $this->assertNotContains('direct_low_engagement', $codes($this->buildWithFixtures($direct(9500.0, 45.0))), 'long sessions');
        $this->assertNotContains('direct_low_engagement', $codes($this->buildWithFixtures($direct(2500.0, 12.0))), 'small share');
    }

    public function test_currency_mismatch_is_an_alert_and_a_matching_currency_is_not(): void
    {
        $payload = $this->buildWithFixtures(fn (array $f) => $f, [FakeData::NANDINI_ID => 'USD']);

        $this->assertSame('USD', $payload['meta']['ga4Currency']);
        $this->assertSame('IDR', $payload['meta']['currency']);
        $this->assertSame('USD', $payload['paid']['currency']);
        $this->assertSame('currency_mismatch', $payload['quality_alerts'][0]['code']);
        $this->assertSame(['ga4Currency' => 'USD', 'propertyCurrency' => 'IDR'], $payload['quality_alerts'][0]['details']);

        $same = $this->buildWithFixtures(fn (array $f) => $f, [FakeData::NANDINI_ID => 'idr']);
        $this->assertSame([], $same['quality_alerts']);

        $unknown = $this->buildWithFixtures(fn (array $f) => $f, []);
        $this->assertNull($unknown['meta']['ga4Currency']);
        $this->assertSame([], $unknown['quality_alerts']);
    }

    public function test_alerts_never_fail_the_build_even_when_their_inputs_are_missing(): void
    {
        $this->ga4->failOn('countries', new GoogleApiException('countries down'));
        $this->ga4->failOn('channels', new GoogleApiException('channels down'));

        $payload = $this->build(FakeData::nandini(), ReportType::Weekly);

        $this->assertSame('countries down', $payload['countries']['error']['message']);
        $this->assertSame([], $payload['quality_alerts']);
        $this->assertSame(10000.0, $payload['site']['sessions']['current']);
    }

    public function test_a_failing_optional_section_is_recorded_and_the_rest_is_kept(): void
    {
        $this->ga4->failOn('paid', new GoogleApiException('advertiser metrics unavailable'));
        $this->ga4->failOn('ai.sources', new GoogleApiException('boom'));

        $payload = $this->build(FakeData::nandini(), ReportType::Weekly);

        $this->assertSame(['error' => ['type' => 'GoogleApiException', 'message' => 'advertiser metrics unavailable']], $payload['paid']);
        $this->assertSame('boom', $payload['ai_traffic']['error']['message']);
        $this->assertSame(10000.0, $payload['site']['sessions']['current']);
        $this->assertCount(7, $payload['channels']['rows']);
        $this->assertSame(500.0, $payload['search_console']['totals']['clicks']['current']);
    }

    public function test_a_failing_site_totals_section_fails_the_whole_build(): void
    {
        $this->ga4->failOn('site.totals', new GoogleApiException('quota'));

        $this->expectException(GoogleApiException::class);
        $this->expectExceptionMessage('quota');
        $this->build(FakeData::nandini(), ReportType::Weekly);
    }

    public function test_a_failing_search_console_section_fails_the_whole_build(): void
    {
        $this->gsc->failWith(new GoogleApiException('search console down'));

        $this->expectException(GoogleApiException::class);
        $this->build(FakeData::nandini(), ReportType::Weekly);
    }

    public function test_permission_denied_fails_the_build_with_a_clear_message(): void
    {
        $this->ga4->failOn('site.totals', new GooglePermissionDeniedException('The service account has not been added to GA4 property 111111111 (nandini).'));

        $this->expectException(GooglePermissionDeniedException::class);
        $this->expectExceptionMessage('service account has not been added');
        $this->build(FakeData::nandini(), ReportType::Weekly);
    }

    public function test_a_missing_ga4_property_id_fails_the_build(): void
    {
        $property = FakeData::nandini();
        $property->ga4_property_id = null;

        $this->expectException(PropertyNotConfiguredException::class);
        $this->expectExceptionMessage('GA4 property ID');
        $this->build($property, ReportType::Weekly);
    }

    public function test_a_missing_search_console_site_fails_the_build(): void
    {
        $property = FakeData::nandini();
        $property->gsc_site_url = null;

        $this->expectException(PropertyNotConfiguredException::class);
        $this->build($property, ReportType::Weekly);
    }

    public function test_the_payload_command_prints_json_without_saving_anything(): void
    {
        $property = Property::create([
            'name' => 'Nandini', 'slug' => 'nandini', 'ga4_property_id' => FakeData::NANDINI_ID,
            'gsc_site_url' => 'sc-domain:nandini.test', 'booking_engine' => ['type' => 'hostname', 'value' => 'book.nandini.test'],
            'ads_source' => 'ga4', 'currency' => 'IDR', 'timezone' => 'Asia/Makassar',
        ]);

        $exit = Artisan::call('report:payload', ['property' => 'nandini', 'type' => 'monthly', '--now' => '2026-11-21 10:00']);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true);
        $this->assertSame('October 2026', $payload['meta']['periodLabel']);
        $this->assertSame(10000.0, $payload['site']['sessions']['current']);
        $this->assertDatabaseCount('reports', 0);
        $this->assertNotNull($property->id);
    }

    public function test_the_payload_command_reports_problems_and_fails(): void
    {
        $this->artisan('report:payload', ['property' => 'nope', 'type' => 'weekly'])->expectsOutputToContain("No property with slug 'nope'")->assertFailed();
        $this->artisan('report:payload', ['property' => 'nope', 'type' => 'daily'])->expectsOutputToContain('weekly or monthly')->assertFailed();

        Property::create(['name' => 'Bare', 'slug' => 'bare']);
        $this->artisan('report:payload', ['property' => 'bare', 'type' => 'weekly'])->expectsOutputToContain('GA4 property ID')->assertFailed();
    }
}
