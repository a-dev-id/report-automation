<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\ReportType;
use App\Exceptions\GoogleApiException;
use App\Reports\Queries\AiReferralTraffic;
use App\Reports\Queries\PaidPerformance;
use App\Reports\Queries\SearchConsoleTotals;
use App\Reports\Queries\TrafficByChannel;
use App\Reports\Queries\TrafficByCountry;
use Carbon\CarbonImmutable;
use Tests\Concerns\BuildsFakePayloads;
use Tests\Fakes\FakeData;
use Tests\Fakes\FakeGa4Client;
use Tests\Fakes\FakeSearchConsoleClient;
use Tests\TestCase;

/** The payload fields added to match the owner's reference report: shares, New and dropped lists, paid purchases, brand share. */
class PayloadParityTest extends TestCase
{
    use BuildsFakePayloads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeGoogle();
    }

    // ---- channels ----------------------------------------------------------------------------------

    public function test_channels_have_a_share_of_sessions_and_its_change_in_points(): void
    {
        $channels = (new TrafficByChannel($this->ga4))->fetch(FakeData::nandini(), $this->period(ReportType::Weekly));

        $organic = $channels['rows'][0];
        $this->assertSame('Organic Search', $organic['channel']);
        $this->assertSame(['current' => 40.0, 'previous' => 40.0], $organic['share']);                       // 4000/10000 and 3200/8000
        $this->assertSame(['points' => 0.0, 'direction' => 'flat'], $organic['shareChange']['previous']);

        $paid = $channels['rows'][2];
        $this->assertSame('Paid Search', $paid['channel']);
        $this->assertSame(['current' => 12.0, 'previous' => 12.5], $paid['share']);
        $this->assertSame(['points' => -0.5, 'direction' => 'down'], $paid['shareChange']['previous']);

        $other = end($channels['rows']);
        $this->assertTrue($other['isOther']);
        $this->assertSame(5.0, $other['share']['current']);                                                  // Display 300 + Unassigned 200
        $this->assertSame(3.75, $other['share']['previous']);
        $this->assertEqualsWithDelta(100.0, array_sum(array_column(array_column($channels['rows'], 'share'), 'current')), 0.01);
        $this->assertArrayNotHasKey('yearAgo', $organic['share']);
    }

    public function test_the_channels_folded_into_other_are_named_so_text_can_say_what_the_rest_came_from(): void
    {
        $channels = (new TrafficByChannel($this->ga4))->fetch(FakeData::nandini(), $this->period(ReportType::Weekly));

        $this->assertSame(
            [['channel' => 'Display', 'sessions' => 300.0, 'share' => 3.0], ['channel' => 'Unassigned', 'sessions' => 200.0, 'share' => 2.0]],
            $channels['otherChannels'],
        );
    }

    public function test_monthly_channel_shares_include_last_year(): void
    {
        $channels = (new TrafficByChannel($this->ga4))->fetch(FakeData::nandini(), $this->period(ReportType::Monthly));

        $this->assertSame(40.0, $channels['rows'][0]['share']['yearAgo']);     // 2000 of 5000
        $this->assertSame(['points' => 0.0, 'direction' => 'flat'], $channels['rows'][0]['shareChange']['yearAgo']);
    }

    // ---- countries ---------------------------------------------------------------------------------

    private function countries(ReportType $type = ReportType::Weekly, ?FakeGa4Client $ga4 = null, $property = null): array
    {
        return (new TrafficByCountry($ga4 ?? $this->ga4))->fetch($property ?? FakeData::nandini(), $this->period($type));
    }

    public function test_countries_that_entered_or_left_the_engaged_top_list_are_named(): void
    {
        $countries = $this->countries();

        $this->assertSame(['India'], $countries['entered']);     // in the top 8 now, not before
        $this->assertSame(['Brazil'], $countries['dropped']);    // in the top 8 before, not now
        $this->assertNotContains('Brazil', array_column($countries['rows'], 'country'));
        $this->assertContains('India', array_column($countries['rows'], 'country'));

        $monthly = $this->countries(ReportType::Monthly);
        $this->assertSame([], $monthly['entered']);               // the top 10 did not change
        $this->assertSame([], $monthly['dropped']);
    }

    public function test_not_set_is_never_called_entered_or_dropped(): void
    {
        $row = fn (string $c, float $s, float $e) => ['dimensions' => ['country' => $c], 'metrics' => ['sessions' => $s, 'engagedSessions' => $e]];
        $ga4 = new FakeGa4Client([FakeData::NANDINI_ID => ['countries' => [
            'current' => [$row('(not set)', 900, 800), $row('Spain', 300, 200)],
            'previous' => [$row('Spain', 250, 150)],
        ]]]);

        $countries = $this->countries(ga4: $ga4);

        $this->assertSame([], $countries['entered']);
        $this->assertSame([], $countries['dropped']);
    }

    public function test_without_a_previous_period_nothing_is_called_new_in_the_top_list(): void
    {
        $row = fn (string $c, float $s, float $e) => ['dimensions' => ['country' => $c], 'metrics' => ['sessions' => $s, 'engagedSessions' => $e]];
        $ga4 = new FakeGa4Client([FakeData::NANDINI_ID => ['countries' => ['current' => [$row('Spain', 300, 200), $row('India', 100, 50)], 'previous' => []]]]);

        $countries = $this->countries(ga4: $ga4);

        $this->assertSame([], $countries['entered']);   // everything would be "new": that says nothing
        $this->assertSame([], $countries['dropped']);
        $this->assertSame('new', $countries['rows'][0]['engagedSessions']['change']['direction']);   // the row itself still shows "New"
    }

    public function test_each_country_has_a_share_of_all_engaged_sessions(): void
    {
        $countries = $this->countries();

        $indonesia = $countries['rows'][0];
        $this->assertSame('Indonesia', $indonesia['country']);
        $this->assertSame(28.89, $indonesia['engagedShare']['current']);    // 1500 of 5192.5
        $this->assertSame(31.71, $indonesia['engagedShare']['previous']);   // 1300 of 4100
        $this->assertEqualsWithDelta(100.0, array_sum(array_column(array_column($countries['rows'], 'engagedShare'), 'current')), 0.1);
        $this->assertSame(30.0, $indonesia['share']['current']);            // the share of SESSIONS is untouched (3000 of 10000)
    }

    public function test_priority_markets_are_off_by_default_and_summed_over_all_countries_when_set(): void
    {
        $this->assertNull($this->countries()['priorityMarkets']);

        config(['report.insight.priority_countries' => ['australia', 'United States', 'Nowhere']]);   // case-insensitive, unknown names ignored
        $markets = $this->countries()['priorityMarkets'];

        $this->assertSame(['australia', 'United States', 'Nowhere'], $markets['countries']);
        $this->assertEqualsWithDelta(43.91, $markets['engagedShare']['current'], 0.01);     // (1440 + 840) of 5192.5
        $this->assertEqualsWithDelta(40.49, $markets['engagedShare']['previous'], 0.01);    // (960 + 700) of 4100
        $this->assertEqualsWithDelta(30.0, $markets['sessionsShare']['current'], 0.01);     // (1800 + 1200) of 10000
        $this->assertEqualsWithDelta(27.5, $markets['sessionsShare']['previous'], 0.01);    // (1200 + 1000) of 8000
    }

    public function test_priority_countries_can_be_overridden_per_property(): void
    {
        config(['report.insight.priority_countries' => ['Indonesia'], 'report.insight.properties.nandini.priority_countries' => ['Australia']]);

        $this->assertSame(['Australia'], $this->countries()['priorityMarkets']['countries']);

        // An explicit empty list for this hotel means "none": the override wins over the default.
        config(['report.insight.properties.nandini.priority_countries' => []]);
        $this->assertNull($this->countries()['priorityMarkets']);
    }

    // ---- AI traffic --------------------------------------------------------------------------------

    public function test_ai_assistants_have_a_share_and_countries_are_compared_with_the_previous_period(): void
    {
        $ai = (new AiReferralTraffic($this->ga4))->fetch(FakeData::nandini(), $this->period(ReportType::Weekly));

        $chatgpt = $ai['sources'][0];
        $this->assertSame('chatgpt.com', $chatgpt['source']);
        $this->assertSame(68.57, $chatgpt['share']['current']);      // 120 of 175
        $this->assertSame(75.47, $chatgpt['share']['previous']);     // 80 of 106
        $this->assertSame('new', $ai['sources'][2]['sessions']['change']['direction']);   // gemini: nothing before

        [$us, $indonesia, $australia] = $ai['topCountries'];
        $this->assertSame(['United States', 70.0, 60.0, 40.0, false], [$us['country'], $us['sessions'], $us['previousSessions'], $us['share'], $us['isNew']]);
        $this->assertSame(16.7, $us['change']['percent']);
        $this->assertSame(['Indonesia', true, 28.57], [$indonesia['country'], $indonesia['isNew'], $indonesia['share']]);
        $this->assertSame('new', $indonesia['change']['direction']);
        $this->assertSame(50.0, $australia['change']['percent']);
        $this->assertSame(['Germany'], $ai['droppedCountries']);      // was in the previous top 3, not now
    }

    public function test_the_ai_country_query_asks_for_current_and_previous_only(): void
    {
        (new AiReferralTraffic($this->ga4))->fetch(FakeData::nandini(), $this->period(ReportType::Monthly));

        $request = $this->ga4->reportsFor(FakeData::NANDINI_ID, 'ai.countries')[0];
        $this->assertSame(['current', 'previous'], array_keys($request->dateRanges));
    }

    // ---- paid --------------------------------------------------------------------------------------

    public function test_paid_has_purchases_and_revenue_from_the_paid_channels_and_the_biggest_campaigns(): void
    {
        $paid = (new PaidPerformance($this->ga4))->fetch(FakeData::nandini(), $this->period(ReportType::Weekly));

        $this->assertSame(2.0, $paid['metrics']['purchases']['current']);
        $this->assertSame(0.0, $paid['metrics']['purchases']['previous']);          // no GA4 row = none then
        $this->assertSame('new', $paid['metrics']['purchases']['change']['direction']);
        $this->assertSame(9000000.0, $paid['metrics']['revenue']['current']);

        $request = $this->ga4->reportsFor(FakeData::NANDINI_ID, 'paid.conversions')[0];
        $this->assertSame(['ecommercePurchases', 'purchaseRevenue'], $request->metrics);
        $this->assertCount(7, $request->dimensionFilter['or']);
        $this->assertSame(['field' => 'sessionDefaultChannelGroup', 'op' => 'exact', 'value' => 'Paid Search'], $request->dimensionFilter['or'][0]);

        $this->assertSame(['brand', 'spa'], array_column($paid['campaigns'], 'campaign'));   // biggest cost first
        $this->assertSame(3000000.0, $paid['campaigns'][0]['cost']);
    }

    public function test_when_ga4_cannot_give_purchases_they_are_left_out_not_shown_as_zero(): void
    {
        $this->ga4->failOn('paid.conversions', new GoogleApiException('conversion metrics unavailable'));

        $paid = (new PaidPerformance($this->ga4))->fetch(FakeData::nandini(), $this->period(ReportType::Weekly));

        $this->assertArrayNotHasKey('purchases', $paid['metrics']);
        $this->assertArrayNotHasKey('revenue', $paid['metrics']);
        $this->assertSame(50000.0, $paid['metrics']['impressions']['current']);   // the rest is intact
    }

    public function test_with_no_paid_channels_configured_no_conversion_query_is_made(): void
    {
        config(['report.paid_channels' => []]);

        $paid = (new PaidPerformance($this->ga4))->fetch(FakeData::nandini(), $this->period(ReportType::Weekly));

        $this->assertArrayNotHasKey('purchases', $paid['metrics']);
        $this->assertSame([], $this->ga4->reportsFor(FakeData::NANDINI_ID, 'paid.conversions'));
    }

    // ---- Search Console brand share ----------------------------------------------------------------

    private function searchConsole(?FakeSearchConsoleClient $gsc = null, $property = null): array
    {
        $period = $this->period(ReportType::Weekly);

        return (new SearchConsoleTotals($gsc ?? $this->gsc))->fetch($property ?? FakeData::nandini(), $period, CarbonImmutable::parse(FakeData::NOW));
    }

    public function test_brand_share_is_off_without_brand_terms_and_makes_no_extra_calls(): void
    {
        $sc = $this->searchConsole();

        $this->assertNull($sc['brand']);
        $this->assertSame([], array_filter($this->gsc->calls, fn ($c) => str_starts_with($c['query']->label, 'brand.')));
    }

    public function test_brand_share_is_the_share_of_clicks_from_queries_that_contain_a_brand_term(): void
    {
        config(['report.insight.brand_terms' => ['NANDINI']]);   // matching ignores case

        $brand = $this->searchConsole()['brand'];

        $this->assertSame(['NANDINI'], $brand['terms']);
        $this->assertSame(['current' => 230.0, 'previous' => 180.0], $brand['clicks']);   // "nandini ubud" + "nandini spa"
        $this->assertSame(['current' => 46.0, 'previous' => 45.0], $brand['share']);      // of 500 and 400 total clicks
        $this->assertFalse($brand['sampleTruncated']);

        $calls = array_values(array_filter($this->gsc->calls, fn ($c) => str_starts_with($c['query']->label, 'brand.')));
        $this->assertCount(2, $calls);
        $this->assertSame(['query'], $calls[0]['query']->dimensions);
        $this->assertSame(1000, $calls[0]['query']->rowLimit);
    }

    public function test_brand_terms_can_be_set_per_property(): void
    {
        config(['report.insight.brand_terms' => [], 'report.insight.properties.nandini.brand_terms' => ['ubud villa']]);

        $this->assertSame(['ubud villa'], $this->searchConsole()['brand']['terms']);
        $this->assertSame(24.0, $this->searchConsole()['brand']['share']['current']);   // 120 of 500

        // An override for another hotel does not apply here: with no default terms Nandini has no brand share.
        config(['report.insight.properties.nandini' => [], 'report.insight.properties.kamandalu.brand_terms' => ['kamandalu']]);
        $this->assertNull($this->searchConsole()['brand']);
    }

    public function test_a_possibly_truncated_sample_is_flagged(): void
    {
        config(['report.insight.brand_terms' => ['nandini']]);
        $many = array_map(fn ($i) => ['keys' => ["query {$i}"], 'clicks' => 1.0, 'impressions' => 10.0, 'ctr' => 0.1, 'position' => 5.0], range(1, 1000));
        $fixtures = FakeData::gsc();
        $fixtures['sc-domain:nandini.test']['brand.current'] = $many;
        $gsc = new FakeSearchConsoleClient($fixtures);

        $this->assertTrue($this->searchConsole($gsc)['brand']['sampleTruncated']);
    }

    public function test_no_brand_calls_when_there_is_no_search_console_data_yet(): void
    {
        config(['report.insight.brand_terms' => ['nandini']]);
        $period = $this->period(ReportType::Weekly);

        $sc = (new SearchConsoleTotals($this->gsc))->fetch(FakeData::nandini(), $period, CarbonImmutable::parse('2026-11-10 09:00:00', 'Asia/Makassar'));

        $this->assertFalse($sc['hasData']);
        $this->assertNull($sc['brand']);
        $this->assertSame([], $this->gsc->calls);
    }

    public function test_the_built_payload_carries_all_the_new_fields(): void
    {
        $payload = $this->fakePayload(FakeData::nandini(), ReportType::Weekly);

        $this->assertArrayHasKey('otherChannels', $payload['channels']);
        $this->assertArrayHasKey('share', $payload['channels']['rows'][0]);
        $this->assertSame(['India'], $payload['countries']['entered']);
        $this->assertArrayHasKey('priorityMarkets', $payload['countries']);
        $this->assertArrayHasKey('droppedCountries', $payload['ai_traffic']);
        $this->assertArrayHasKey('purchases', $payload['paid']['metrics']);
        $this->assertArrayHasKey('campaigns', $payload['paid']);
        $this->assertArrayHasKey('brand', $payload['search_console']);
        $this->assertSame([], array_filter($payload, fn ($v) => is_array($v) && isset($v['error'])));
    }
}
