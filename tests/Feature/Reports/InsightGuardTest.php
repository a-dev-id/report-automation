<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\ReportType;
use App\Services\Reports\InsightGenerator;
use App\Support\Compare;
use Tests\Concerns\BuildsFakePayloads;
use Tests\Fakes\FakeData;
use Tests\TestCase;

/** The large-change guard and the "best traffic quality" sentence rules of InsightGenerator. */
class InsightGuardTest extends TestCase
{
    use BuildsFakePayloads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeGoogle();
    }

    private function insight(array $payload): array
    {
        return (new InsightGenerator())->generate($payload);
    }

    private function weekly(): array
    {
        return $this->fakePayload(FakeData::nandini(), ReportType::Weekly);
    }

    private function monthly(): array
    {
        return $this->fakePayload(FakeData::nandini(), ReportType::Monthly);
    }

    // ---- 1a. large-change guard -------------------------------------------------------------------

    public function test_a_year_ago_collapse_of_key_events_is_a_neutral_caution_not_a_headline_fact(): void
    {
        $payload = $this->monthly();
        // The real October 2026 case: 732 key events against 7,100 a year ago (-89.7%).
        $payload['site']['keyEvents'] = Compare::metric(['current' => 732.0, 'previous' => 764.0, 'yearAgo' => 7100.0]);

        $insight = $this->insight($payload);

        // Against last month key events are only -4.2%, so that stays. The -89.7% year-ago change is held back.
        $this->assertSame(
            'This month, sessions rose 25.0% to 10,000 and key events fell 4.2% to 732, compared with last month. Against the same month last year, sessions are up 100.0%.',
            $insight['headline'],
        );
        $this->assertStringNotContainsString('key events are', $insight['headline']);   // no year-ago key-events claim
        $this->assertStringNotContainsString('89.7', $insight['headline']);
        $this->assertSame(
            'Key events changed by -89.7% versus October 2025. A change this large often comes from a change in how events are tracked, '
            .'so please check the tracking setup before drawing conclusions.',
            $insight['caution'],
        );
        $this->assertDoesNotMatchRegularExpression('/collaps|surg|plung|crash|soar|alarm|critical/i', $insight['headline'].' '.implode(' ', $insight['bullets']).' '.$insight['caution']);
    }

    public function test_a_large_previous_period_change_is_also_held_back(): void
    {
        $payload = $this->weekly();
        $payload['site']['keyEvents'] = Compare::metric(['current' => 100.0, 'previous' => 700.0]);   // -85.7%

        $insight = $this->insight($payload);

        $this->assertSame('This week, sessions rose 25.0% to 10,000, compared with the previous week.', $insight['headline']);
        $this->assertSame(
            'Key events changed by -85.7% versus the previous week. A change this large often comes from a change in how events are tracked, '
            .'so please check the tracking setup before drawing conclusions.',
            $insight['caution'],
        );
    }

    public function test_a_large_rise_is_guarded_too_and_shown_with_a_plus_sign(): void
    {
        $payload = $this->weekly();
        $payload['site']['keyEvents'] = Compare::metric(['current' => 1000.0, 'previous' => 100.0]);   // +900.0%

        $insight = $this->insight($payload);

        $this->assertStringNotContainsString('key events', $insight['headline']);
        $this->assertStringContainsString('Key events changed by +900.0% versus the previous week.', $insight['caution']);
        $this->assertDoesNotMatchRegularExpression('/surg|soar|skyrocket/i', $insight['headline'].$insight['caution']);
    }

    public function test_the_guard_does_not_trigger_at_the_threshold_but_does_just_beyond_it(): void
    {
        $keyEventsHeadline = function (float $current, float $previous): array {
            $payload = $this->weekly();
            $payload['site']['keyEvents'] = Compare::metric(['current' => $current, 'previous' => $previous]);

            return $this->insight($payload);
        };

        // Exactly -60.0% and +200.0%: not beyond the threshold, so stated normally and no caution.
        $drop = $keyEventsHeadline(40.0, 100.0);
        $this->assertStringContainsString('key events fell 60.0% to 40', $drop['headline']);
        $this->assertNull($drop['caution']);

        $rise = $keyEventsHeadline(300.0, 100.0);
        $this->assertStringContainsString('key events rose 200.0% to 300', $rise['headline']);
        $this->assertNull($rise['caution']);

        // -60.1% and +200.1%: guarded.
        $this->assertStringNotContainsString('key events', $keyEventsHeadline(39.9, 100.0)['headline']);
        $this->assertStringContainsString('-60.1%', $keyEventsHeadline(39.9, 100.0)['caution']);
        $this->assertStringNotContainsString('key events', $keyEventsHeadline(300.1, 100.0)['headline']);
        $this->assertStringContainsString('+200.1%', $keyEventsHeadline(300.1, 100.0)['caution']);
    }

    public function test_an_ordinary_change_is_stated_as_before(): void
    {
        $insight = $this->insight($this->weekly());

        $this->assertSame('This week, sessions rose 25.0% to 10,000 and key events rose 16.7% to 350, compared with the previous week.', $insight['headline']);
        $this->assertNull($insight['caution']);
    }

    public function test_a_zero_previous_value_is_not_a_large_change(): void
    {
        $payload = $this->weekly();
        $payload['site']['keyEvents'] = Compare::metric(['current' => 350.0, 'previous' => 0.0]);   // no percent at all

        $insight = $this->insight($payload);

        $this->assertStringContainsString('key events reached 350 (none before)', $insight['headline']);
        $this->assertNull($insight['caution']);

        $payload['site']['keyEvents'] = Compare::metric(['current' => 0.0, 'previous' => 0.0]);
        $this->assertStringContainsString('key events were 0 (no comparison available)', $this->insight($payload)['headline']);
    }

    public function test_the_thresholds_come_from_config(): void
    {
        $payload = $this->monthly();
        $payload['site']['keyEvents'] = Compare::metric(['current' => 732.0, 'previous' => 764.0, 'yearAgo' => 7100.0]);

        config(['report.insights.large_drop_percent' => 95]);
        $relaxed = $this->insight($payload);
        $this->assertStringContainsString('key events are down 89.7%', $relaxed['headline']);
        $this->assertNull($relaxed['caution']);

        config(['report.insights.large_drop_percent' => 60, 'report.insights.large_rise_percent' => 50]);
        $strict = $this->insight($this->monthly());   // key events +16.7% / +75.0% year ago: now 75% is a large rise
        $this->assertStringContainsString('key events changed by +75.0% versus October 2025', $strict['caution']);
        $this->assertStringContainsString('Sessions changed by +100.0% versus October 2025', $strict['caution']);
    }

    public function test_guarded_sessions_leave_only_the_value_in_the_headline(): void
    {
        $payload = $this->weekly();
        $payload['site']['sessions'] = Compare::metric(['current' => 10000.0, 'previous' => 800.0]);   // +1150%

        $insight = $this->insight($payload);

        $this->assertSame('This week, sessions were 10,000 and key events rose 16.7% to 350, compared with the previous week.', $insight['headline']);
        $this->assertStringContainsString('Sessions changed by +1,150.0% versus the previous week.', $insight['caution']);
    }

    public function test_guarded_bullets_give_the_value_and_point_to_the_note(): void
    {
        $payload = $this->weekly();
        $payload['site']['purchases'] = Compare::metric(['current' => 3.0, 'previous' => 12.0]);   // -75.0%

        $insight = $this->insight($payload);

        $purchases = array_values(array_filter($insight['bullets'], fn ($b) => str_starts_with($b, 'Purchases')))[0];
        $this->assertStringContainsString('compared with 12 in the previous week (see the note below)', $purchases);
        $this->assertStringNotContainsString('75.0%', $purchases);
        $this->assertStringContainsString('Purchases changed by -75.0% versus the previous week.', $insight['caution']);
    }

    public function test_several_guarded_metrics_share_one_neutral_sentence(): void
    {
        $payload = $this->monthly();
        $payload['site']['keyEvents'] = Compare::metric(['current' => 732.0, 'previous' => 764.0, 'yearAgo' => 7100.0]);
        $payload['site']['purchases'] = Compare::metric(['current' => 3.0, 'previous' => 12.0]);

        $caution = $this->insight($payload)['caution'];

        $this->assertSame(
            'Key events changed by -89.7% versus October 2025 and purchases changed by -75.0% versus last month. '
            .'Changes this large often come from a change in how events are tracked, so please check the tracking setup before drawing conclusions.',
            $caution,
        );
        $this->assertSame(1, substr_count($caution, 'check the tracking setup'));
    }

    public function test_the_tracking_caution_and_the_data_quality_note_are_both_kept(): void
    {
        $payload = $this->weekly();
        $payload['site']['keyEvents'] = Compare::metric(['current' => 100.0, 'previous' => 700.0]);
        $payload['quality_alerts'] = [['code' => 'country_sum_mismatch', 'message' => 'x', 'details' => []]];

        $caution = $this->insight($payload)['caution'];

        $this->assertStringContainsString('Key events changed by -85.7% versus the previous week.', $caution);
        $this->assertStringContainsString('Data note: some sessions are counted in more than one country', $caution);
    }

    public function test_the_guard_state_does_not_leak_between_reports(): void
    {
        $generator = new InsightGenerator();
        $guarded = $this->weekly();
        $guarded['site']['keyEvents'] = Compare::metric(['current' => 100.0, 'previous' => 700.0]);

        $this->assertNotNull($generator->generate($guarded)['caution']);
        $this->assertNull($generator->generate($this->weekly())['caution']);   // same instance, clean payload
    }

    // ---- 1b. "best traffic quality" sentence ------------------------------------------------------

    /** @param  list<array<string, mixed>>  $rows */
    private function withCountries(array $rows): array
    {
        $payload = $this->weekly();
        unset($payload['countries']['largestBySessions'], $payload['countries']['engagedLeader']);   // use the rows given here
        $payload['country_performance'] = array_map(fn (array $r) => $r + [
            'campaign' => '(not set)', 'sourceMedium' => 'google / organic', 'users' => 1.0, 'averageSessionDuration' => 60.0, 'engagementRate' => 0.5,
            'purchasers' => 0.0, 'purchaseRevenue' => 0.0, 'bookingEngineEvents' => 0.0, 'quality' => 'Potential', 'checkForBots' => false,
        ], $rows);

        return $payload;
    }

    private function countrySentence(array $payload): ?string
    {
        $found = array_values(array_filter($this->insight($payload)['bullets'], fn ($b) => str_starts_with($b, 'Best traffic quality')));

        return $found[0] ?? null;
    }

    public function test_the_best_quality_sentence_is_shown_when_the_winner_is_big_enough_and_converts(): void
    {
        $sentence = $this->countrySentence($this->withCountries([
            ['country' => 'Norway', 'sessions' => 200.0, 'engagedSessions' => 100.0, 'bookingEngineVisits' => 6.0],   // 3.0%
            ['country' => 'Chile', 'sessions' => 900.0, 'engagedSessions' => 450.0, 'bookingEngineVisits' => 9.0],    // 1.0%
        ]));

        $this->assertNotNull($sentence);
        $this->assertStringStartsWith('Best traffic quality: Norway (3.0% of its 200 top-source sessions reached the booking engine)', $sentence);
    }

    public function test_the_sentence_is_omitted_when_the_winner_has_too_few_sessions(): void
    {
        // 49 sessions is under the 50 minimum, even with a 20% booking rate.
        $this->assertNull($this->countrySentence($this->withCountries([
            ['country' => 'Norway', 'sessions' => 49.0, 'engagedSessions' => 30.0, 'bookingEngineVisits' => 10.0],
            ['country' => 'Chile', 'sessions' => 900.0, 'engagedSessions' => 450.0, 'bookingEngineVisits' => 0.0],
        ])));

        // 50 sessions is enough.
        $this->assertNotNull($this->countrySentence($this->withCountries([
            ['country' => 'Norway', 'sessions' => 50.0, 'engagedSessions' => 30.0, 'bookingEngineVisits' => 10.0],
        ])));
    }

    public function test_the_sentence_is_omitted_when_the_rate_is_at_or_below_one_percent(): void
    {
        // Exactly 1.0%: not above the minimum.
        $this->assertNull($this->countrySentence($this->withCountries([
            ['country' => 'Chile', 'sessions' => 1000.0, 'engagedSessions' => 450.0, 'bookingEngineVisits' => 10.0],
        ])));
        // The real weekly case: the best country books 0.2% of its sessions.
        $this->assertNull($this->countrySentence($this->withCountries([
            ['country' => 'United States', 'sessions' => 1058.0, 'engagedSessions' => 160.0, 'bookingEngineVisits' => 2.0],
            ['country' => 'Brazil', 'sessions' => 1153.0, 'engagedSessions' => 170.0, 'bookingEngineVisits' => 0.0],
        ])));
        // No booking visits and no purchases at all.
        $this->assertNull($this->countrySentence($this->withCountries([
            ['country' => 'Chile', 'sessions' => 900.0, 'engagedSessions' => 450.0, 'bookingEngineVisits' => 0.0],
        ])));
    }

    public function test_the_purchase_rate_can_qualify_a_country_without_a_booking_definition(): void
    {
        $rows = fn (float $purchasers) => $this->withCountries([
            ['country' => 'Chile', 'sessions' => 100.0, 'engagedSessions' => 50.0, 'bookingEngineVisits' => null, 'purchasers' => $purchasers],
        ]);

        $this->assertNull($this->countrySentence($rows(1.0)));        // 1.0% is not above 1%
        $sentence = $this->countrySentence($rows(2.0));               // 2.0% is
        $this->assertNotNull($sentence);
        $this->assertStringContainsString('2 purchasers (2.0% of those sessions)', $sentence);
    }

    public function test_when_the_sentence_is_omitted_nothing_replaces_it(): void
    {
        $payload = $this->withCountries([
            ['country' => 'Chile', 'sessions' => 900.0, 'engagedSessions' => 450.0, 'bookingEngineVisits' => 0.0],
        ]);

        $insight = $this->insight($payload);

        $text = implode("\n", $insight['bullets']);
        $this->assertStringNotContainsString('Best traffic quality', $text);
        $this->assertStringNotContainsString('largest', $text);          // the largest country is only named inside that sentence
        $this->assertStringNotContainsString('Chile', $text);
        $this->assertGreaterThanOrEqual(3, count($insight['bullets']));
    }

    // ---- 2. largest by sessions and the engaged-sessions leader ------------------------------------

    public function test_the_largest_country_is_called_largest_by_sessions_and_the_engaged_leader_is_added_when_it_differs(): void
    {
        $sentence = $this->countrySentence($this->withCountries([
            ['country' => 'Alpha', 'sessions' => 1000.0, 'engagedSessions' => 300.0, 'bookingEngineVisits' => 0.0],
            ['country' => 'Bravo', 'sessions' => 400.0, 'engagedSessions' => 100.0, 'bookingEngineVisits' => 20.0],   // best quality: 5%
            ['country' => 'Charlie', 'sessions' => 600.0, 'engagedSessions' => 500.0, 'bookingEngineVisits' => 0.0],
        ]));

        $this->assertStringStartsWith('Best traffic quality: Bravo (5.0% of its 400 top-source sessions reached the booking engine)', $sentence);
        $this->assertStringContainsString('The largest country by sessions was Alpha, and the engaged-sessions leader was Charlie.', $sentence);
    }

    public function test_the_engaged_leader_is_not_repeated_when_it_is_also_the_largest(): void
    {
        $sentence = $this->countrySentence($this->withCountries([
            ['country' => 'Alpha', 'sessions' => 1000.0, 'engagedSessions' => 800.0, 'bookingEngineVisits' => 0.0],
            ['country' => 'Bravo', 'sessions' => 400.0, 'engagedSessions' => 100.0, 'bookingEngineVisits' => 20.0],
        ]));

        $this->assertStringContainsString('The largest country by sessions was Alpha.', $sentence);
        $this->assertStringNotContainsString('engaged-sessions leader', $sentence);
        $this->assertStringNotContainsString('largest source', $sentence);   // the old wording is gone
    }

    public function test_no_largest_country_is_named_when_the_quality_country_is_the_largest(): void
    {
        $sentence = $this->countrySentence($this->withCountries([
            ['country' => 'Alpha', 'sessions' => 1000.0, 'engagedSessions' => 300.0, 'bookingEngineVisits' => 50.0],
            ['country' => 'Bravo', 'sessions' => 400.0, 'engagedSessions' => 380.0, 'bookingEngineVisits' => 4.0],
        ]));

        $this->assertStringStartsWith('Best traffic quality: Alpha', $sentence);
        $this->assertStringNotContainsString('largest', $sentence);
        $this->assertStringNotContainsString('leader', $sentence);
    }

    public function test_the_largest_and_engaged_leader_come_from_all_countries_not_just_the_rows_shown(): void
    {
        // Zulu is the real largest country but is not among the top rows (low engagement); the countries section knows.
        $payload = $this->withCountries([
            ['country' => 'Alpha', 'sessions' => 300.0, 'engagedSessions' => 250.0, 'bookingEngineVisits' => 0.0],
            ['country' => 'Bravo', 'sessions' => 400.0, 'engagedSessions' => 100.0, 'bookingEngineVisits' => 20.0],
        ]);
        $payload['countries']['largestBySessions'] = ['country' => 'Zulu', 'sessions' => 9000.0];
        $payload['countries']['engagedLeader'] = ['country' => 'Yankee', 'engagedSessions' => 4000.0];

        $sentence = $this->countrySentence($payload);

        $this->assertStringContainsString('The largest country by sessions was Zulu, and the engaged-sessions leader was Yankee.', $sentence);
        $this->assertStringNotContainsString('Alpha', $sentence);
    }

    public function test_the_engaged_leader_falls_back_to_sessions_times_engagement_when_engaged_sessions_are_missing(): void
    {
        $rows = $this->withCountries([
            ['country' => 'Alpha', 'sessions' => 1000.0, 'engagementRate' => 0.1, 'bookingEngineVisits' => 0.0],
            ['country' => 'Bravo', 'sessions' => 400.0, 'engagementRate' => 0.2, 'bookingEngineVisits' => 20.0],
            ['country' => 'Charlie', 'sessions' => 600.0, 'engagementRate' => 0.9, 'bookingEngineVisits' => 0.0],
        ]);

        $this->assertStringContainsString('the engaged-sessions leader was Charlie', $this->countrySentence($rows));   // 540 engaged
    }
}
