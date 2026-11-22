<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\Insight;

use App\Services\Reports\Insight\CountryRules;
use Tests\Concerns\BuildsInsightPayloads;
use Tests\TestCase;

class CountryRulesTest extends TestCase
{
    use BuildsInsightPayloads;

    private CountryRules $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rules = new CountryRules();
    }

    /**
     * @param  list<array<string, mixed>>  $rows  country rows (ranked by engaged sessions)
     * @param  array<string, mixed>  $extra  keys of the countries section (entered, dropped, priorityMarkets, ...)
     * @param  list<array<string, mixed>>  $performance
     */
    private function payload2(array $rows, array $extra = [], array $performance = []): array
    {
        $payload = $this->payload();
        $payload['countries'] = $extra + ['rows' => $rows, 'entered' => [], 'dropped' => []];
        $payload['countries']['rows'] = $rows;
        $payload['country_performance'] = $performance;

        return $payload;
    }

    public function test_the_overview_ranks_countries_by_engaged_sessions_with_their_share_of_engaged_sessions(): void
    {
        $payload = $this->payload2([
            $this->country('Spain', 295, 200, 549, 23.5),
            $this->country('Indonesia', 287, 250, 552, 22.8),
            $this->country('United States', 218, 180, 1162, 17.3),
            $this->country('Brazil', 178, 150, 1175, 14.1),
        ]);

        $finding = $this->find($this->rules->findings($this->context($payload)), 'overview');

        $this->assertSame(
            'Ranked by engaged sessions, the most engaged visitors came from Spain (23.5%), Indonesia (22.8%) and United States (17.3%).',
            $this->plain($finding),
        );
        $this->assertTrue($finding->essential);
    }

    public function test_the_other_bucket_and_not_set_are_not_named(): void
    {
        $payload = $this->payload2([
            $this->country('(not set)', 900, 800, 900, 40.0),
            $this->country('Spain', 300, 200, 549, 20.0),
            ['country' => 'Other', 'isOther' => true] + $this->country('Other', 500, 400, 900, 30.0),
        ]);

        $text = $this->plain($this->find($this->rules->findings($this->context($payload)), 'overview'));

        $this->assertSame('Ranked by engaged sessions, the most engaged visitors came from Spain (20.0%).', $text);
    }

    public function test_a_new_country_with_real_engagement_and_booking_activity_looks_genuine(): void
    {
        $payload = $this->payload2(
            [$this->country('Spain', 295, 200, 549, 23.5), $this->country('India', 50, null, 100, 3.0)],
            ['entered' => ['India']],
            [$this->perf('India', 100, 50, 5.0)],
        );

        $finding = $this->find($this->rules->findings($this->context($payload)), 'entered');

        $this->assertSame('New in the top list: India (50.0% engaged, 5 booking-engine visits) looks genuine.', $this->plain($finding));
        $this->assertNull($finding->action);
    }

    public function test_a_new_country_with_very_low_engagement_and_no_booking_activity_looks_suspicious(): void
    {
        $payload = $this->payload2(
            [$this->country('Spain', 295, 200, 549, 23.5), $this->country('Lithuania', 10, null, 121, 1.0)],
            ['entered' => ['Lithuania']],
            [$this->perf('Lithuania', 121, 10, 0.0)],
        );

        $finding = $this->find($this->rules->findings($this->context($payload)), 'entered');

        $this->assertSame('New in the top list: Lithuania (8.3% engaged, 0 booking-engine visits) looks suspicious.', $this->plain($finding));
        $this->assertSame('Check the Lithuania traffic in GA4 before counting it as demand.', $finding->action);
        $this->assertSame(2, $finding->actionPriority);
    }

    public function test_mixed_signals_are_called_a_closer_look_not_real_or_suspicious(): void
    {
        $text = fn (float $engaged, float $visits) => $this->plain($this->find($this->rules->findings($this->context($this->payload2(
            [$this->country('Peru', $engaged, null, 100, 3.0)],
            ['entered' => ['Peru']],
            [$this->perf('Peru', 100, $engaged, $visits)],
        ))), 'entered'));

        $this->assertStringContainsString('needs a closer look', $text(30.0, 0.0));   // engaged enough, but no booking activity
        $this->assertStringContainsString('needs a closer look', $text(10.0, 3.0));   // booking activity, but very low engagement
    }

    public function test_without_country_rows_booking_activity_is_unknown_and_engagement_decides(): void
    {
        $text = fn (float $engaged) => $this->plain($this->find($this->rules->findings($this->context($this->payload2(
            [$this->country('Peru', $engaged, null, 100, 3.0)],
            ['entered' => ['Peru']],
        ))), 'entered'));

        $this->assertSame('New in the top list: Peru (50.0% engaged) looks genuine.', $text(50.0));
        $this->assertSame('New in the top list: Peru (5.0% engaged) looks suspicious.', $text(5.0));
    }

    public function test_the_suspicious_engagement_limit_is_configurable(): void
    {
        $payload = $this->payload2([$this->country('Peru', 30.0, null, 100, 3.0)], ['entered' => ['Peru']], [$this->perf('Peru', 100, 30.0, 0.0)]);

        config(['report.insight.suspicious_engagement_percent' => 40]);

        $this->assertStringContainsString('looks suspicious', $this->plain($this->find($this->rules->findings($this->context($payload)), 'entered')));
    }

    public function test_several_new_countries_are_judged_one_by_one(): void
    {
        $payload = $this->payload2(
            [$this->country('A', 60, null, 100, 3.0), $this->country('B', 5, null, 100, 1.0)],
            ['entered' => ['A', 'B']],
            [$this->perf('A', 100, 60, 4.0), $this->perf('B', 100, 5, 0.0)],
        );

        $finding = $this->find($this->rules->findings($this->context($payload)), 'entered');

        $this->assertSame('New in the top list: A (60.0% engaged, 4 booking-engine visits) looks genuine; B (5.0% engaged, 0 booking-engine visits) looks suspicious.', $this->plain($finding));
        $this->assertSame('Check the B traffic in GA4 before counting it as demand.', $finding->action);
    }

    public function test_countries_that_dropped_out_of_the_top_list_are_named(): void
    {
        $payload = $this->payload2([$this->country('Spain', 295, 200, 549, 23.5)], ['dropped' => ['Brazil', 'Argentina']]);

        $this->assertSame('Dropped out of the top list: Brazil and Argentina.', $this->plain($this->find($this->rules->findings($this->context($payload)), 'dropped')));
        $this->assertNull($this->find($this->rules->findings($this->context($this->payload2([$this->country('Spain', 295, 200, 549, 23.5)]))), 'dropped'));
    }

    public function test_priority_markets_report_their_combined_share_and_whether_it_grew(): void
    {
        $markets = fn (?float $before, float $now = 30.0) => $this->payload2(
            [$this->country('Spain', 295, 200, 549, 23.5)],
            ['priorityMarkets' => ['countries' => ['Australia', 'United States'], 'engagedShare' => ['current' => $now, 'previous' => $before], 'sessionsShare' => ['current' => 20.0, 'previous' => 18.0]]],
        );
        $text = fn (array $p) => $this->plain($this->find($this->rules->findings($this->context($p)), 'priorityMarkets'));

        $this->assertSame('Your priority markets (Australia and United States) made up 30.0% of engaged sessions, up from 20.0%.', $text($markets(20.0)));
        $this->assertSame('Your priority markets (Australia and United States) made up 30.0% of engaged sessions, down from 40.0%.', $text($markets(40.0)));
        $this->assertSame('Your priority markets (Australia and United States) made up 30.0% of engaged sessions, unchanged from 30.0%.', $text($markets(30.0)));
        $this->assertSame('Your priority markets (Australia and United States) made up 30.0% of engaged sessions (no comparison available).', $text($markets(null)));
    }

    public function test_priority_markets_are_skipped_when_none_are_configured(): void
    {
        $payload = $this->payload2([$this->country('Spain', 295, 200, 549, 23.5)], ['priorityMarkets' => null]);

        $this->assertNull($this->find($this->rules->findings($this->context($payload)), 'priorityMarkets'));
    }

    public function test_rows_flagged_for_bots_are_called_out_with_care(): void
    {
        $payload = $this->payload2(
            [$this->country('Spain', 295, 200, 549, 23.5)],
            [],
            [$this->perf('Brazil', 1153, 177, 0.0, bot: true), $this->perf('Argentina', 433, 74, 0.0, bot: true), $this->perf('Spain', 465, 251, 3.0)],
        );

        $this->assertSame(
            'Visits from Brazil and Argentina are very short with no booking activity, so treat those figures with care.',
            $this->plain($this->find($this->rules->findings($this->context($payload)), 'botSuspects')),
        );
    }

    // ---- best traffic quality ----------------------------------------------------------------------

    /** @param  list<array<string, mixed>>  $performance */
    private function best(array $performance, array $countries = []): ?string
    {
        $payload = $this->payload2([$this->country('Anywhere', 10, 10, 10, 1.0)], $countries, $performance);

        return $this->plain($this->rules->bestQuality($this->context($payload), []));
    }

    public function test_the_best_quality_sentence_is_written_when_the_winner_is_big_enough_and_converts(): void
    {
        $text = $this->best([$this->perf('Norway', 200, 100, 6.0), $this->perf('Chile', 900, 450, 9.0)]);

        $this->assertStringStartsWith('The best traffic quality came from Norway (3.0% of its 200 top-source sessions reached the booking engine), judged on bookings and purchases rather than sessions.', $text);
    }

    public function test_the_best_quality_sentence_is_left_out_with_too_few_sessions_or_a_weak_rate(): void
    {
        $this->assertNull($this->best([$this->perf('Norway', 49, 30, 10.0), $this->perf('Chile', 900, 450, 0.0)]));      // 49 < 50 sessions
        $this->assertNotNull($this->best([$this->perf('Norway', 50, 30, 10.0)]));                                          // 50 is enough
        $this->assertNull($this->best([$this->perf('Chile', 1000, 450, 10.0)]));                                           // exactly 1.0%: not above
        $this->assertNull($this->best([$this->perf('United States', 1058, 160, 2.0), $this->perf('Brazil', 1153, 170, 0.0)]));   // 0.2%
        $this->assertNull($this->best([$this->perf('Chile', 900, 450, 0.0)]));                                             // no booking or purchase activity
    }

    public function test_the_purchase_rate_can_qualify_a_country_when_there_is_no_booking_definition(): void
    {
        $this->assertNull($this->best([$this->perf('Chile', 100, 50, null, purchasers: 1.0)]));   // 1.0% is not above 1%
        $text = $this->best([$this->perf('Chile', 100, 50, null, purchasers: 2.0)]);

        $this->assertStringContainsString('2 purchasers (2.0% of those sessions)', $text);
    }

    public function test_bot_flagged_and_low_data_rows_cannot_win(): void
    {
        $this->assertNull($this->best([$this->perf('Bots', 500, 5, 0.0, bot: true), $this->perf('Tiny', 8, 8, 4.0, quality: 'Low Data')]));
    }

    public function test_the_largest_country_is_named_by_sessions_with_the_engaged_leader_when_it_differs(): void
    {
        $text = $this->best([
            $this->perf('Alpha', 1000, 300, 0.0),
            $this->perf('Bravo', 400, 100, 20.0),   // best quality: 5%
            $this->perf('Charlie', 600, 500, 0.0),
        ]);

        $this->assertStringContainsString('The best traffic quality came from Bravo (5.0% of its 400 top-source sessions reached the booking engine)', $text);
        $this->assertStringContainsString('The largest country by sessions was Alpha, and the engaged-sessions leader was Charlie.', $text);
        $this->assertStringNotContainsString('largest source', $text);
    }

    public function test_the_engaged_leader_is_not_repeated_when_it_is_also_the_largest(): void
    {
        $text = $this->best([$this->perf('Alpha', 1000, 800, 0.0), $this->perf('Bravo', 400, 100, 20.0)]);

        $this->assertStringContainsString('The largest country by sessions was Alpha.', $text);
        $this->assertStringNotContainsString('engaged-sessions leader', $text);
    }

    public function test_no_largest_country_is_named_when_the_quality_country_is_the_largest(): void
    {
        $text = $this->best([$this->perf('Alpha', 1000, 300, 50.0), $this->perf('Bravo', 400, 380, 4.0)]);

        $this->assertStringContainsString('came from Alpha', $text);
        $this->assertStringNotContainsString('largest', $text);
    }

    public function test_the_largest_and_the_engaged_leader_come_from_all_countries_not_just_the_rows_shown(): void
    {
        $text = $this->best(
            [$this->perf('Alpha', 300, 250, 0.0), $this->perf('Bravo', 400, 100, 20.0)],
            ['largestBySessions' => ['country' => 'Zulu', 'sessions' => 9000.0], 'engagedLeader' => ['country' => 'Yankee', 'engagedSessions' => 4000.0]],
        );

        $this->assertStringContainsString('The largest country by sessions was Zulu, and the engaged-sessions leader was Yankee.', $text);
        $this->assertStringNotContainsString('Alpha', $text);
    }

    public function test_engaged_sessions_fall_back_to_sessions_times_engagement_when_a_row_has_none(): void
    {
        $rows = [
            ['engagedSessions' => null] + $this->perf('Alpha', 1000, 100, 0.0),
            ['engagedSessions' => null] + $this->perf('Bravo', 400, 80, 20.0),
            ['engagedSessions' => null] + $this->perf('Charlie', 600, 540, 0.0),
        ];
        foreach ($rows as &$row) {
            unset($row['engagedSessions']);   // the payload may not have the key at all
        }
        unset($row);

        $this->assertStringContainsString('the engaged-sessions leader was Charlie', $this->best($rows));
    }

    public function test_no_paragraph_without_country_rows_and_error_sections_are_skipped(): void
    {
        $this->assertSame([], $this->rules->findings($this->context($this->payload())));
        $this->assertSame([], $this->rules->findings($this->context($this->payload() + ['countries' => ['error' => ['message' => 'x']]])));
        $this->assertSame([], $this->rules->findings($this->context($this->payload2([]))));
    }
}
