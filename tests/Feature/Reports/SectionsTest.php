<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\ReportType;
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
use DateTimeZone;
use Tests\Fakes\FakeData;
use Tests\Fakes\FakeGa4Client;
use Tests\Fakes\FakeSearchConsoleClient;
use Tests\TestCase;

/** Each query class against fixtures through the fake clients. Pinned now: 2026-11-21. */
class SectionsTest extends TestCase
{
    private FakeGa4Client $ga4;

    private FakeSearchConsoleClient $gsc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ga4 = new FakeGa4Client(FakeData::ga4(), [FakeData::NANDINI_ID => 'IDR', FakeData::KAMANDALU_ID => 'USD']);
        $this->gsc = new FakeSearchConsoleClient(FakeData::gsc());
    }

    private function period(ReportType $type, string $now = FakeData::NOW): ReportPeriod
    {
        return ReportPeriod::forType($type, CarbonImmutable::parse($now, 'Asia/Makassar'), new DateTimeZone('Asia/Makassar'));
    }

    public function test_site_totals_weekly(): void
    {
        $site = (new SiteTotals($this->ga4))->fetch(FakeData::nandini(), $this->period(ReportType::Weekly));

        $this->assertSame(10000.0, $site['sessions']['current']);
        $this->assertSame(8000.0, $site['sessions']['previous']);
        $this->assertSame(25.0, $site['sessions']['change']['percent']);
        $this->assertArrayNotHasKey('yearAgo', $site['sessions']);
        $this->assertSame(95.5, $site['averageSessionDuration']['current']);
        $this->assertSame('down', $site['averageSessionDuration']['change']['direction']);
        $this->assertSame(900.0, $site['bookingEngineVisits']['current']);
        // Hostname booking: eventCount would include page_view, so there is no booking events figure.
        $this->assertNull($site['bookingEvents']);
        $this->assertSame(['sessions'], $this->ga4->reportsFor(FakeData::NANDINI_ID, 'site.booking')[0]->metrics);
        $this->assertSame(12.0, $site['purchases']['current']);
        $this->assertSame(48000000.0, $site['purchaseRevenue']['current']);

        // Weekly requests never ask GA4 for a year-ago range.
        foreach ($this->ga4->calls as $call) {
            $this->assertSame(['current', 'previous'], array_keys($call['report']->dateRanges));
        }
    }

    public function test_site_totals_monthly_adds_year_ago(): void
    {
        $site = (new SiteTotals($this->ga4))->fetch(FakeData::nandini(), $this->period(ReportType::Monthly));

        $this->assertSame(5000.0, $site['sessions']['yearAgo']);
        $this->assertSame(100.0, $site['sessions']['yearAgoChange']['percent']);
        $this->assertSame(200.0, $site['bookingEngineVisits']['yearAgoChange']['percent']);
    }

    public function test_site_totals_uses_the_booking_filter_of_the_property(): void
    {
        (new SiteTotals($this->ga4))->fetch(FakeData::nandini(), $this->period(ReportType::Weekly));
        (new SiteTotals($this->ga4))->fetch(FakeData::kamandalu(), $this->period(ReportType::Weekly));

        $nandini = $this->ga4->reportsFor(FakeData::NANDINI_ID, 'site.booking')[0];
        $kamandalu = $this->ga4->reportsFor(FakeData::KAMANDALU_ID, 'site.booking')[0];

        $this->assertSame(['field' => 'hostName', 'op' => 'exact', 'value' => 'book.nandini.test'], $nandini->dimensionFilter);
        $this->assertSame(['sessions'], $nandini->metrics);
        $this->assertSame(['field' => 'eventName', 'op' => 'exact', 'value' => 'click_book_now'], $kamandalu->dimensionFilter);
        // The event type also counts sessions (sessions that contain the event), plus the raw event count.
        $this->assertSame(['sessions', 'eventCount'], $kamandalu->metrics);
        $kamandaluSite = (new SiteTotals($this->ga4))->fetch(FakeData::kamandalu(), $this->period(ReportType::Weekly));
        $this->assertSame(1800.0, $kamandaluSite['bookingEngineVisits']['current']);
        $this->assertSame(5400.0, $kamandaluSite['bookingEvents']['current']);
        $this->assertSame(80.0, $kamandaluSite['bookingEvents']['change']['percent']);
    }

    public function test_without_a_booking_definition_booking_visits_are_null_and_no_booking_query_is_made(): void
    {
        $property = FakeData::nandini();
        $property->booking_engine = null;

        $site = (new SiteTotals($this->ga4))->fetch($property, $this->period(ReportType::Weekly));

        $this->assertNull($site['bookingEngineVisits']);
        $this->assertNull($site['bookingEvents']);
        $this->assertSame([], $this->ga4->reportsFor(FakeData::NANDINI_ID, 'site.booking'));
    }

    public function test_channels_are_the_top_six_plus_other(): void
    {
        $channels = (new TrafficByChannel($this->ga4))->fetch(FakeData::nandini(), $this->period(ReportType::Weekly));

        $this->assertCount(7, $channels['rows']);
        $this->assertSame(['Organic Search', 'Direct', 'Paid Search', 'Organic Social', 'Referral', 'Email', 'Other'], array_column($channels['rows'], 'channel'));
        $this->assertTrue($channels['rows'][6]['isOther']);
        $this->assertSame(500.0, $channels['rows'][6]['sessions']['current']);   // Display 300 + Unassigned 200
        $this->assertSame(300.0, $channels['rows'][6]['sessions']['previous']);
        $this->assertSame(10000.0, $channels['total']['sessions']['current']);
        $this->assertSame(60.0, $channels['rows'][0]['averageSessionDuration']['current']);
        $this->assertSame(60.0, $channels['rows'][6]['averageSessionDuration']['current']);   // weighted average of the rest
        $this->assertSame(25.0, $channels['rows'][0]['sessions']['change']['percent']);
        $this->assertSame(2800.0, $channels['rows'][0]['totalUsers']['current']);
    }

    public function test_countries_are_ranked_by_engaged_sessions_with_share_in_points(): void
    {
        $countries = (new TrafficByCountry($this->ga4))->fetch(FakeData::nandini(), $this->period(ReportType::Weekly));

        // Ranked by engaged sessions (sessions x engagement), not by sessions: Germany has 700 sessions but 10% engagement.
        $this->assertSame(
            ['Indonesia', 'Australia', 'United States', 'United Kingdom', 'Singapore', 'France', 'Netherlands', 'India', 'Other'],
            array_column($countries['rows'], 'country'),
        );
        $engaged = array_column(array_map(fn ($r) => ['e' => $r['engagedSessions']['current']], $countries['rows']), 'e');
        $this->assertSame([1500.0, 1440.0, 840.0, 270.0, 240.0, 200.0, 175.0, 150.0], array_slice($engaged, 0, 8));

        $other = $countries['rows'][8];
        $this->assertTrue($other['isOther']);
        $this->assertSame(1550.0, $other['sessions']['current']);               // Germany, Canada, Japan, Brazil
        $this->assertSame(377.5, $other['engagedSessions']['current']);

        // Sessions are still there, and the share is still a share of sessions.
        $indonesia = $countries['rows'][0];
        $this->assertSame(3000.0, $indonesia['sessions']['current']);
        $this->assertSame(1500.0, $indonesia['engagedSessions']['current']);
        $this->assertSame(1300.0, $indonesia['engagedSessions']['previous']);
        $this->assertSame(15.4, $indonesia['engagedSessions']['change']['percent']);
        $this->assertSame(30.0, $indonesia['share']['current']);
        $this->assertSame(32.5, $indonesia['share']['previous']);
        $this->assertSame(['points' => -2.5, 'direction' => 'down'], $indonesia['shareChange']['previous']);
        $this->assertArrayNotHasKey('yearAgo', $indonesia['share']);
        $this->assertArrayNotHasKey('yearAgo', $indonesia['engagedSessions']);

        $this->assertEqualsWithDelta(100.0, array_sum(array_map(fn ($r) => $r['share']['current'], $countries['rows'])), 0.1);

        // The largest and the engaged leader are over all countries: Indonesia leads both in the fixture.
        $this->assertSame(['country' => 'Indonesia', 'sessions' => 3000.0], $countries['largestBySessions']);
        $this->assertSame(['country' => 'Indonesia', 'engagedSessions' => 1500.0], $countries['engagedLeader']);

        $request = $this->ga4->reportsFor(FakeData::NANDINI_ID, 'countries')[0];
        $this->assertSame(['sessions', 'engagedSessions'], $request->metrics);
        $this->assertSame([['field' => 'engagedSessions', 'metric' => true, 'desc' => true]], $request->orderBys);
    }
    public function test_the_largest_country_is_found_even_when_it_is_not_among_the_top_rows_and_not_set_is_ignored(): void
    {
        $row = fn (string $country, float $sessions, float $engaged) => ['dimensions' => ['country' => $country], 'metrics' => ['sessions' => $sessions, 'engagedSessions' => $engaged]];
        $ga4 = new FakeGa4Client([FakeData::NANDINI_ID => ['countries' => ['current' => array_merge(
            [$row('(not set)', 90000.0, 99000.0)],
            array_map(fn ($i) => $row("Engaged{$i}", 100.0, 90.0 - $i), range(1, 9)),   // nine small, very engaged countries
            [$row('Bigland', 5000.0, 10.0)],                                              // huge, barely engaged: ranks last
        )]]]);

        $countries = (new TrafficByCountry($ga4))->fetch(FakeData::nandini(), $this->period(ReportType::Weekly));

        $this->assertNotContains('Bigland', array_column($countries['rows'], 'country'));   // not shown: it is in "Other"
        $this->assertSame(['country' => 'Bigland', 'sessions' => 5000.0], $countries['largestBySessions']);
        $this->assertSame('Engaged1', $countries['engagedLeader']['country']);
    }

    public function test_countries_report_the_sum_the_site_total_and_a_warning(): void
    {
        $countries = new TrafficByCountry($this->ga4);
        $period = $this->period(ReportType::Weekly);
        $property = FakeData::nandini();

        // Rows add up to 10000. Site total 10000: fine. 10% over (9091) is the limit, 9000 is over it.
        $ok = $countries->fetch($property, $period, ['current' => 10000.0]);
        $this->assertSame(10000.0, $ok['sessionsSum']);
        $this->assertSame(10000.0, $ok['sessionsTotal']);
        $this->assertFalse($ok['warning']);
        $this->assertFalse($countries->fetch($property, $period, ['current' => 9091.0])['warning']);
        $this->assertTrue($countries->fetch($property, $period, ['current' => 9000.0])['warning']);

        // Without a site total there is nothing to compare with.
        $none = $countries->fetch($property, $period);
        $this->assertNull($none['sessionsTotal']);
        $this->assertFalse($none['warning']);
        $this->assertCount(9, $none['rows']);   // the rows themselves are unchanged
    }

    public function test_countries_monthly_top_ten_with_year_ago(): void
    {
        $countries = (new TrafficByCountry($this->ga4))->fetch(FakeData::nandini(), $this->period(ReportType::Monthly));

        $this->assertCount(11, $countries['rows']);
        $this->assertSame(['India', 'Japan', 'Brazil', 'Other'], array_column(array_slice($countries['rows'], 7), 'country'));
        $this->assertSame(1050.0, $countries['rows'][10]['sessions']['current']);  // Germany + Canada
        $this->assertSame(40.0, $countries['rows'][0]['share']['yearAgo']);
        $this->assertSame(['points' => -10.0, 'direction' => 'down'], $countries['rows'][0]['shareChange']['yearAgo']);
        $this->assertSame(1000.0, $countries['rows'][0]['engagedSessions']['yearAgo']);
        // Japan had no sessions a year ago: share is 0, not missing.
        $this->assertSame('Japan', $countries['rows'][8]['country']);
        $this->assertSame(0.0, $countries['rows'][8]['share']['yearAgo']);
    }

    public function test_country_performance_computes_engaged_sessions_when_ga4_does_not_return_them(): void
    {
        $row = fn (string $country, array $metrics) => ['dimensions' => ['country' => $country, 'sessionCampaignName' => '(not set)', 'sessionSourceMedium' => 'google / organic'], 'metrics' => $metrics];
        $ga4 = new FakeGa4Client([FakeData::NANDINI_ID => ['country_performance' => ['current' => [
            $row('Indonesia', ['sessions' => 200.0, 'engagementRate' => 0.25, 'averageSessionDuration' => 30.0, 'totalUsers' => 150.0]),   // no engagedSessions
            $row('Australia', ['sessions' => 100.0, 'engagementRate' => 0.8, 'engagedSessions' => 77.0, 'averageSessionDuration' => 60.0, 'totalUsers' => 90.0]),
        ]]]]);

        $rows = (new CountryPerformance($ga4))->fetch(FakeData::nandini(), $this->period(ReportType::Weekly));
        $by = array_column($rows, null, 'country');

        $this->assertSame(50.0, $by['Indonesia']['engagedSessions']);   // 200 x 0.25, computed
        $this->assertSame(77.0, $by['Australia']['engagedSessions']);   // GA4's own figure wins over sessions x rate (80)
    }

    public function test_country_performance_is_ranked_by_engaged_sessions(): void
    {
        $fixtures = FakeData::ga4();
        foreach ($fixtures[FakeData::NANDINI_ID]['country_performance']['current'] as &$row) {
            if ($row['dimensions']['country'] === 'Singapore') {
                $row['metrics']['engagedSessions'] = 5000.0;   // Singapore: few sessions, very engaged
            }
        }
        unset($row);
        $ga4 = new FakeGa4Client($fixtures);

        $rows = (new CountryPerformance($ga4))->fetch(FakeData::nandini(), $this->period(ReportType::Weekly));

        $this->assertSame('Singapore', $rows[0]['country']);
        $this->assertSame(5000.0, $rows[0]['engagedSessions']);
        $this->assertSame(400.0, $rows[0]['sessions']);                    // the Sessions column keeps the sessions
        $by = array_column($rows, null, 'country');
        $this->assertSame(1500.0, $by['Indonesia']['engagedSessions']);   // 3000 sessions x 0.5 engagement
        $this->assertCount(10, $rows);
        $engaged = array_column($rows, 'engagedSessions');
        $sorted = $engaged;
        rsort($sorted);
        $this->assertSame($sorted, $engaged);                              // descending

        $request = $ga4->reportsFor(FakeData::NANDINI_ID, 'country_performance')[0];
        $this->assertContains('engagedSessions', $request->metrics);
        $this->assertSame([['field' => 'engagedSessions', 'metric' => true, 'desc' => true]], $request->orderBys);
        $this->assertSame('High Value', $by['Australia']['quality']);      // the label and the bot flag are untouched
        $this->assertTrue($by['Germany']['checkForBots']);
    }
    public function test_bot_flag_needs_no_booking_visits_no_purchasers_enough_sessions_and_a_short_session(): void
    {
        $bots = fn (float $sessions, float $duration, ?float $visits, float $buyers) => CountryPerformance::looksLikeBots($sessions, $duration, $visits, $buyers);

        $this->assertTrue($bots(10, 4.9, 0.0, 0.0));
        $this->assertFalse($bots(10, 5.0, 0.0, 0.0), 'threshold is strictly under 5 s');
        $this->assertFalse($bots(9, 1.0, 0.0, 0.0), 'fewer than 10 sessions');
        $this->assertFalse($bots(10, 1.0, 1.0, 0.0), 'has booking visits');
        $this->assertFalse($bots(10, 1.0, 0.0, 1.0), 'has purchasers');
        $this->assertFalse($bots(10, 1.0, null, 0.0), 'unknown booking definition is never guessed');
    }

    public function test_country_performance_labels_and_bot_flag(): void
    {
        $rows = (new CountryPerformance($this->ga4))->fetch(FakeData::nandini(), $this->period(ReportType::Weekly));
        $by = array_column($rows, null, 'country');

        $this->assertCount(10, $rows);
        $this->assertSame('High Value', $by['Indonesia']['quality']);        // purchasers
        $this->assertSame('High Value', $by['Australia']['quality']);        // 90 booking events
        $this->assertSame(90.0, $by['Australia']['bookingEngineEvents']);
        $this->assertSame(80.0, $by['Australia']['bookingEngineVisits']);      // sessions that contain the booking event
        $this->assertSame(0.0, $by['Indonesia']['bookingEngineVisits']);
        $this->assertSame('Potential', $by['United States']['quality']);     // 3 booking events
        $this->assertSame('Potential', $by['Singapore']['quality']);         // 50 s average session
        $this->assertSame('Low Intent', $by['United Kingdom']['quality']);
        $this->assertSame('Low Data', $by['Japan']['quality']);

        $this->assertTrue($by['Germany']['checkForBots']);
        $this->assertSame('Low Intent', $by['Germany']['quality']);
        $this->assertSame(0.0, $by['Germany']['bookingEngineEvents']);
        $this->assertSame('check for bots', $by['Germany']['flag']);
        $this->assertNull($by['Indonesia']['flag']);
        $this->assertTrue($by['Canada']['checkForBots']);                     // 3.5 s is under the 5 s threshold
        $this->assertFalse($by['United Kingdom']['checkForBots']);           // exactly 5 s is not under it
        $this->assertFalse($by['Japan']['checkForBots']);                    // too few sessions to judge
        $this->assertFalse($by['Indonesia']['checkForBots']);
        $this->assertSame('spam.example / referral', $by['Germany']['sourceMedium']);
    }

    public function test_country_performance_compares_sessions_with_the_previous_period(): void
    {
        $rows = (new CountryPerformance($this->ga4))->fetch(FakeData::nandini(), $this->period(ReportType::Weekly));
        $by = array_column($rows, null, 'country');

        $this->assertSame(2400.0, $by['Indonesia']['previousSessions']);
        $this->assertSame(25.0, $by['Indonesia']['sessionsChange']['percent']);
        $this->assertSame('up', $by['Indonesia']['sessionsChange']['direction']);
        $this->assertSame(-20.0, $by['United States']['sessionsChange']['percent']);
        $this->assertSame('down', $by['United States']['sessionsChange']['direction']);
        $this->assertSame('flat', $by['Germany']['sessionsChange']['direction']);
        $this->assertSame(0.0, $by['Australia']['previousSessions']);
        $this->assertSame('new', $by['Australia']['sessionsChange']['direction']);
        $this->assertNull($by['Australia']['sessionsChange']['percent']);

        // Only sessions are compared: the other columns keep their plain values.
        $this->assertSame(2000.0, $by['Indonesia']['users']);
        $this->assertSame(80.0, $by['Indonesia']['averageSessionDuration']);

        $previous = $this->ga4->reportsFor(FakeData::NANDINI_ID, 'country_performance.previous')[0];
        $this->assertSame(['previous'], array_keys($previous->dateRanges));
        $this->assertSame(['2026-11-02', '2026-11-08'], $previous->dateRanges['previous']);
        $this->assertSame(['sessions'], $previous->metrics);
    }

    public function test_country_performance_monthly_compares_with_the_previous_month_only(): void
    {
        $rows = (new CountryPerformance($this->ga4))->fetch(FakeData::nandini(), $this->period(ReportType::Monthly));

        $this->assertSame(['2026-09-01', '2026-09-30'], $this->ga4->reportsFor(FakeData::NANDINI_ID, 'country_performance.previous')[0]->dateRanges['previous']);
        $this->assertArrayNotHasKey('yearAgo', $rows[0]);
        $this->assertArrayHasKey('sessionsChange', $rows[0]);
    }

    public function test_country_performance_without_booking_definition_never_guesses_events(): void
    {
        $property = FakeData::nandini();
        $property->booking_engine = null;

        $rows = (new CountryPerformance($this->ga4))->fetch($property, $this->period(ReportType::Weekly));
        $by = array_column($rows, null, 'country');

        $this->assertNull($by['Germany']['bookingEngineEvents']);
        $this->assertNull($by['Germany']['bookingEngineVisits']);
        $this->assertFalse($by['Germany']['checkForBots']);
        $this->assertNull($by['Germany']['flag']);
        $this->assertSame('High Value', $by['Indonesia']['quality']);
        $this->assertSame('High Value', $by['Australia']['quality']);   // purchasers still count
        $this->assertSame('Potential', $by['United States']['quality']);   // 60 s average session; booking events unknown, not guessed
        $this->assertSame([], $this->ga4->reportsFor(FakeData::NANDINI_ID, 'country_performance.booking'));
    }

    public function test_ai_referral_traffic(): void
    {
        $ai = (new AiReferralTraffic($this->ga4))->fetch(FakeData::nandini(), $this->period(ReportType::Weekly));

        $this->assertSame(175.0, $ai['total']['current']);
        $this->assertSame(106.0, $ai['total']['previous']);
        $this->assertSame(['chatgpt.com', 'perplexity.ai', 'gemini.google.com', 'claude.ai'], array_column($ai['sources'], 'source'));

        $monthly = (new AiReferralTraffic($this->ga4))->fetch(FakeData::nandini(), $this->period(ReportType::Monthly));
        $this->assertNotContains('copilot.microsoft.com', array_column($monthly['sources'], 'source'));   // only year-ago sessions: dropped
        $this->assertSame(30.0, $monthly['sources'][0]['sessions']['yearAgo']);
        $this->assertSame(175.0, $monthly['total']['current']);
        $this->assertSame(50.0, $ai['sources'][0]['sessions']['change']['percent']);
        $this->assertSame('new', $ai['sources'][2]['sessions']['change']['direction']);
        $this->assertSame('down', $ai['sources'][3]['sessions']['change']['direction']);
        $this->assertSame(['United States', 'Indonesia', 'Australia'], array_column($ai['topCountries'], 'country'));
        $this->assertSame(['/', '/rooms', '/spa'], array_column($ai['topLandingPages'], 'landingPage'));

        $filter = $this->ga4->reportsFor(FakeData::NANDINI_ID, 'ai.sources')[0]->dimensionFilter;
        $this->assertSame('sessionSource', $filter['field']);
        $this->assertSame('partial_regex', $filter['op']);
        $this->assertSame('chatgpt\.com|perplexity|gemini|claude\.ai|copilot', $filter['value']);
    }

    public function test_paid_performance_by_ads_source(): void
    {
        $paid = new PaidPerformance($this->ga4);
        $period = $this->period(ReportType::Monthly);

        $ga4 = $paid->fetch(FakeData::nandini(), $period);
        $this->assertSame('ga4', $ga4['source']);
        $this->assertTrue($ga4['available']);
        $this->assertSame(50000.0, $ga4['metrics']['impressions']['current']);   // 30000 + 20000: campaigns are summed
        $this->assertSame(['sessionCampaignName'], $this->ga4->reportsFor(FakeData::NANDINI_ID, 'paid')[0]->dimensions);
        $this->assertSame('IDR', $ga4['currency']);
        $this->assertSame(0.02, $ga4['metrics']['ctr']['current']);
        $this->assertSame(5000.0, $ga4['metrics']['costPerClick']['current']);
        $this->assertSame(6000.0, $ga4['metrics']['costPerClick']['previous']);
        $this->assertSame('down', $ga4['metrics']['costPerClick']['change']['direction']);
        $this->assertTrue($ga4['metrics']['costPerClick']['change']['improved']);
        $this->assertSame(5000.0, $ga4['metrics']['costPerClick']['yearAgo']);

        $none = FakeData::nandini();
        $none->ads_source = 'none';
        $this->assertNull($paid->fetch($none, $period));

        $api = FakeData::nandini();
        $api->ads_source = 'google_ads_api';
        $result = $paid->fetch($api, $period);
        $this->assertFalse($result['available']);
        $this->assertNull($result['metrics']);
        $this->assertStringContainsString('not implemented', $result['note']);
    }

    public function test_paid_ratios_are_null_when_the_divisor_is_zero(): void
    {
        $ga4 = new FakeGa4Client([FakeData::NANDINI_ID => ['paid' => [
            'current' => [['dimensions' => [], 'metrics' => ['advertiserAdImpressions' => 0.0, 'advertiserAdClicks' => 0.0, 'advertiserAdCost' => 0.0]]],
            'previous' => [],
        ]]]);

        $paid = (new PaidPerformance($ga4))->fetch(FakeData::nandini(), $this->period(ReportType::Weekly));

        $this->assertNull($paid['metrics']['ctr']['current']);
        $this->assertNull($paid['metrics']['costPerClick']['current']);
        $this->assertSame('none', $paid['metrics']['costPerClick']['change']['direction']);
    }

    public function test_weekly_trend_has_eight_weeks_ending_with_the_report_week(): void
    {
        $trend = (new Trend($this->ga4))->fetch(FakeData::nandini(), $this->period(ReportType::Weekly));

        $this->assertSame('week', $trend['granularity']);
        $this->assertCount(8, $trend['points']);
        $this->assertSame('2026-09-21', $trend['points'][0]['start']);
        $this->assertSame('2026-11-09', $trend['points'][7]['start']);
        $this->assertSame('2026-11-15', $trend['points'][7]['end']);
        $this->assertSame('202639', $trend['points'][0]['key']);
        $this->assertSame('202646', $trend['points'][7]['key']);
        $this->assertSame(100.0, $trend['points'][0]['sessions']);
        $this->assertSame(0.0, $trend['points'][3]['sessions']);      // the fake skips every 4th bucket
        $this->assertSame(['2026-09-21', '2026-11-15'], $this->ga4->reportsFor(FakeData::NANDINI_ID, 'trend.weekly')[0]->dateRanges['current']);
    }

    public function test_monthly_trend_has_twelve_months_ending_with_the_report_month(): void
    {
        $trend = (new Trend($this->ga4))->fetch(FakeData::nandini(), $this->period(ReportType::Monthly));

        $this->assertSame('month', $trend['granularity']);
        $this->assertCount(12, $trend['points']);
        $this->assertSame('202511', $trend['points'][0]['key']);
        $this->assertSame('2025-11-01', $trend['points'][0]['start']);
        $this->assertSame('202610', $trend['points'][11]['key']);
        $this->assertSame('2026-10-31', $trend['points'][11]['end']);
    }

    public function test_search_console_weekly_with_complete_data(): void
    {
        $sc = (new SearchConsoleTotals($this->gsc))->fetch(FakeData::nandini(), $this->period(ReportType::Weekly), CarbonImmutable::parse(FakeData::NOW));

        $this->assertTrue($sc['hasData']);
        $this->assertFalse($sc['truncated']);
        $this->assertSame(0, $sc['daysMissing']);
        $this->assertSame('2026-11-15', $sc['through']);
        $this->assertSame(['current' => ['2026-11-09', '2026-11-15'], 'previous' => ['2026-11-02', '2026-11-08']], $sc['windows']);

        $this->assertSame(500.0, $sc['totals']['clicks']['current']);
        $this->assertSame(25.0, $sc['totals']['clicks']['change']['percent']);
        $this->assertSame('down', $sc['totals']['position']['change']['direction']);
        $this->assertTrue($sc['totals']['position']['change']['improved']);          // lower is better

        $this->assertCount(5, $sc['topQueries']);
        $this->assertSame('nandini ubud', $sc['topQueries'][0]['query']);
        $this->assertSame(160.0, $sc['topQueries'][0]['clicks']['previous']);
        $this->assertSame('new', $sc['topQueries'][2]['clicks']['change']['direction']);  // not in the previous rows
        $this->assertCount(5, $sc['topPages']);
        $this->assertSame('https://nandini.test/', $sc['topPages'][0]['page']);

        $this->assertCount(7, $sc['daily']);
        $this->assertSame(['date' => '2026-11-11', 'clicks' => 11.0, 'impressions' => 220.0], $sc['daily'][2]);
        $this->assertSame(['date' => '2026-11-10', 'clicks' => 0.0, 'impressions' => 0.0], $sc['daily'][1]);   // no row: zero
        $this->assertSame(['date' => '2026-11-15', 'clicks' => 0.0, 'impressions' => 0.0], $sc['daily'][6]);
    }

    public function test_search_console_is_truncated_when_the_last_days_are_not_ready(): void
    {
        $sc = (new SearchConsoleTotals($this->gsc))->fetch(
            FakeData::nandini(),
            $this->period(ReportType::Weekly, '2026-11-17 09:00:00'),
            CarbonImmutable::parse('2026-11-17 09:00:00', 'Asia/Makassar'),
        );

        $this->assertTrue($sc['truncated']);
        $this->assertSame(1, $sc['daysMissing']);
        $this->assertSame('2026-11-14', $sc['through']);
        $this->assertSame(['2026-11-09', '2026-11-14'], $sc['windows']['current']);
        $this->assertSame(['2026-11-02', '2026-11-07'], $sc['windows']['previous']);   // same length
        $this->assertCount(6, $sc['daily']);
    }

    public function test_search_console_monthly_has_ten_rows_and_a_year_ago_window(): void
    {
        $sc = (new SearchConsoleTotals($this->gsc))->fetch(FakeData::nandini(), $this->period(ReportType::Monthly), CarbonImmutable::parse(FakeData::NOW));

        $this->assertSame(['2026-10-01', '2026-10-31'], $sc['windows']['current']);
        $this->assertSame(['2025-10-01', '2025-10-31'], $sc['windows']['yearAgo']);
        $this->assertSame(200.0, $sc['totals']['clicks']['yearAgo']);
        $this->assertSame(150.0, $sc['totals']['clicks']['yearAgoChange']['percent']);
        $this->assertCount(10, $sc['topQueries']);
        $this->assertCount(6, $sc['topPages']);     // the fixture only has six pages
        $this->assertCount(31, $sc['daily']);
    }

    public function test_search_console_with_no_data_ready_returns_has_data_false(): void
    {
        $sc = (new SearchConsoleTotals($this->gsc))->fetch(
            FakeData::nandini(),
            $this->period(ReportType::Weekly, '2026-11-17 09:00:00'),
            CarbonImmutable::parse('2026-11-10 09:00:00', 'Asia/Makassar'),   // clock behind: nothing is ready
        );

        $this->assertFalse($sc['hasData']);
        $this->assertNull($sc['through']);
        $this->assertNull($sc['totals']);
        $this->assertSame([], $this->gsc->calls);
    }
}
