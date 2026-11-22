<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\Insight;

use App\Services\Reports\Insight\WebsiteTrafficRules;
use Tests\Concerns\BuildsInsightPayloads;
use Tests\TestCase;

class WebsiteTrafficRulesTest extends TestCase
{
    use BuildsInsightPayloads;

    private WebsiteTrafficRules $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rules = new WebsiteTrafficRules();
    }

    /** @param  array<string, mixed>  $site */
    private function site(array $site, string $type = 'weekly', ?array $channels = null): array
    {
        $payload = $this->payload($type, $site);
        if ($channels !== null) {
            $payload['channels'] = ['rows' => $channels, 'otherChannels' => []];
        }

        return $this->rules->findings($this->context($payload));
    }

    public function test_the_overview_says_what_changed_with_the_numbers(): void
    {
        // The headline already carries sessions, engagement and booking visits, so this paragraph adds users, duration and key events.
        $overview = $this->plain($this->find($this->site(['totalUsers' => $this->m(5947, 6471), 'averageSessionDuration' => $this->m(94, 160)]), 'overview'));

        $this->assertSame(
            'Total users fell -8.1% to 5,947, average session duration fell -41.3% to 1m 34s and key events held at 30.',
            $overview,
        );
        $this->assertStringNotContainsString('Sessions', $overview);

        // With none of those metrics there is no overview, and the other rules still work.
        $bare = $this->site(['totalUsers' => null, 'averageSessionDuration' => null, 'keyEvents' => null]);
        $this->assertNull($this->find($bare, 'overview'));
    }

    public function test_sessions_up_with_engagement_down_means_growth_from_lower_quality_traffic_and_names_the_channel(): void
    {
        $channels = [
            $this->channel('Direct', 700, 400, 56.0, 40.0),
            $this->channel('Organic Search', 300, 350, 24.0, 35.0),
        ];

        $findings = $this->site(['sessions' => $this->m(1250, 1000), 'engagementRate' => $this->m(0.50, 0.60)], channels: $channels);
        $finding = $this->find($findings, 'growthLowQuality');

        $this->assertNotNull($finding);
        $this->assertSame(
            'The growth came mainly from lower-quality traffic: visitors stayed for less time or engaged less, and the channel that grew most was Direct (300 more sessions).',
            $this->plain($finding),
        );
        $this->assertSame('Check whether the Direct visits are genuine, since that channel grew most.', $finding->action);
        $this->assertSame(2, $finding->actionPriority);
    }

    public function test_a_shorter_visit_alone_also_counts_as_weaker_quality(): void
    {
        $findings = $this->site(['sessions' => $this->m(1250, 1000), 'engagementRate' => $this->m(0.60, 0.60), 'averageSessionDuration' => $this->m(60, 90)]);

        $this->assertNotNull($this->find($findings, 'growthLowQuality'));
        // Without a channel to name the sentence stays true and the action is general.
        $this->assertStringNotContainsString('channel that grew most was', $this->plain($this->find($findings, 'growthLowQuality')));
        $this->assertSame('Check which channel grew most and whether those visits are genuine.', $this->find($findings, 'growthLowQuality')->action);
    }

    public function test_growth_with_steady_quality_is_not_called_low_quality(): void
    {
        $this->assertNull($this->find($this->site(['sessions' => $this->m(1250, 1000)]), 'growthLowQuality'));
        // An engagement dip smaller than the configured 2 points does not count.
        $this->assertNull($this->find($this->site(['sessions' => $this->m(1250, 1000), 'engagementRate' => $this->m(0.595, 0.60)]), 'growthLowQuality'));
        // Sessions that did not grow cannot be "growth".
        $this->assertNull($this->find($this->site(['sessions' => $this->m(1000, 1000), 'engagementRate' => $this->m(0.40, 0.60)]), 'growthLowQuality'));
    }

    public function test_fewer_sessions_with_better_engagement_means_fewer_but_more_interested_visitors(): void
    {
        $findings = $this->site(['sessions' => $this->m(800, 1000), 'engagementRate' => $this->m(0.66, 0.60)]);

        $this->assertSame('There were fewer visitors, but the ones who came were more interested.', $this->plain($this->find($findings, 'fewerButBetter')));
        $this->assertNull($this->find($this->site(['sessions' => $this->m(800, 1000), 'engagementRate' => $this->m(0.60, 0.60)]), 'fewerButBetter'));
        $this->assertNull($this->find($this->site(['sessions' => $this->m(1200, 1000), 'engagementRate' => $this->m(0.66, 0.60)]), 'fewerButBetter'));
    }

    public function test_more_visitors_but_flat_or_fewer_booking_visits_says_to_check_the_booking_step(): void
    {
        foreach ([$this->m(40, 40), $this->m(30, 40)] as $visits) {
            $finding = $this->find($this->site(['sessions' => $this->m(1250, 1000), 'bookingEngineVisits' => $visits]), 'bookingNotFollowing');

            $this->assertSame(
                'More visitors are not reaching the booking step: check the booking button and the pages people land on.',
                $this->plain($finding),
            );
            $this->assertSame('Check the booking button and the pages most visitors land on.', $finding->action);
            $this->assertSame(1, $finding->actionPriority);
        }

        $this->assertNull($this->find($this->site(['sessions' => $this->m(1250, 1000), 'bookingEngineVisits' => $this->m(60, 40)]), 'bookingNotFollowing'));
        $this->assertNull($this->find($this->site(['sessions' => $this->m(900, 1000), 'bookingEngineVisits' => $this->m(30, 40)]), 'bookingNotFollowing'));
    }

    public function test_without_a_booking_definition_nothing_is_said_about_the_booking_step(): void
    {
        $findings = $this->site(['sessions' => $this->m(1250, 1000), 'bookingEngineVisits' => null]);

        $this->assertSame([], array_values(array_intersect($this->rules($findings), ['bookingVisits', 'bookingNotFollowing', 'bookingRate'])));
        $this->assertStringNotContainsString('booking', $this->plainAll($findings));
    }

    public function test_the_booking_engine_rate_going_up_or_down_says_what_it_means_for_interest(): void
    {
        $up = $this->plain($this->find($this->site(['sessions' => $this->m(1000, 1000), 'bookingEngineVisits' => $this->m(60, 40)]), 'bookingRate'));
        $this->assertSame('The booking-engine rate rose from 4.0% to 6.0% of sessions, which means more visitors showed interest in booking.', $up);

        $down = $this->plain($this->find($this->site(['sessions' => $this->m(1000, 1000), 'bookingEngineVisits' => $this->m(30, 40)]), 'bookingRate'));
        $this->assertSame('The booking-engine rate fell from 4.0% to 3.0% of sessions, which means fewer visitors showed interest in booking.', $down);

        $this->assertNull($this->find($this->site(['sessions' => $this->m(1000, 1000), 'bookingEngineVisits' => $this->m(40, 40)]), 'bookingRate'));
        $this->assertNull($this->find($this->site(['sessions' => $this->m(0, 0), 'bookingEngineVisits' => $this->m(0, 0)]), 'bookingRate'));   // no division by zero
    }

    public function test_a_tiny_booking_rate_move_is_stated_as_numbers_without_interpretation(): void
    {
        // 10.0% -> 9.6% of sessions: 0.4 points and 4% relative, under both limits.
        $finding = $this->find($this->site(['sessions' => $this->m(1000, 1000), 'bookingEngineVisits' => $this->m(96, 100)]), 'bookingRate');

        $text = $this->plain($finding);
        $this->assertStringContainsString('10.0%', $text);
        $this->assertStringContainsString('9.6%', $text);
        $this->assertStringNotContainsString('interest', $text);
        $this->assertStringNotContainsString('fewer visitors', $text);
        $this->assertStringNotContainsString('more visitors', $text);
    }

    public function test_a_booking_rate_move_on_small_volume_is_never_interpreted(): void
    {
        // A big relative move (1.0% -> 2.0%) but only 10 and 20 booking visits... 20 is the limit, so use 10 and 15.
        $finding = $this->find($this->site(['sessions' => $this->m(1000, 1000), 'bookingEngineVisits' => $this->m(15, 10)]), 'bookingRate');

        $this->assertTrue($finding === null || ! str_contains($this->plain($finding), 'interest'));
    }

    public function test_a_booking_rate_move_with_enough_volume_is_interpreted_when_it_is_big_enough(): void
    {
        $up = $this->plain($this->find($this->site(['sessions' => $this->m(1000, 1000), 'bookingEngineVisits' => $this->m(60, 40)]), 'bookingRate'));

        $this->assertStringContainsString('more visitors showed interest in booking', $up);
        $this->assertStringNotContainsString('▲', $up);
        $this->assertStringNotContainsString('▼', $up);
    }

    public function test_the_booking_rate_limits_are_configurable(): void
    {
        $site = fn () => $this->site(['sessions' => $this->m(1000, 1000), 'bookingEngineVisits' => $this->m(96, 100)]);

        $this->assertStringNotContainsString('interest', $this->plain($this->find($site(), 'bookingRate')));

        config(['report.insight.booking_rate.min_points' => 0.1, 'report.insight.booking_rate.min_relative_percent' => 1]);

        $this->assertStringContainsString('fewer visitors showed interest in booking', $this->plain($this->find($site(), 'bookingRate')));
    }
    public function test_small_purchase_numbers_are_called_small_and_not_a_trend(): void
    {
        $finding = $this->find($this->site(['purchases' => $this->m(3, 2)]), 'purchases');

        $this->assertSame(
            'Purchases are few (3 this week, 2 before), so percentage changes can mislead.',
            $this->plain($finding),
        );
        $this->assertStringNotContainsString('rose', $this->plain($finding));
        $this->assertStringNotContainsString('%', $this->plain($finding));

        $monthly = $this->plain($this->find($this->site(['purchases' => $this->m(1, 4)], 'monthly'), 'purchases'));
        $this->assertStringContainsString('(1 this month, 4 before)', $monthly);
    }

    public function test_the_small_number_limit_is_configurable_and_uses_both_periods(): void
    {
        // 12 now and 10 before: not small.
        $this->assertSame(
            'Purchases rose +20.0% to 12, worth IDR 10,000,000.',
            $this->plain($this->find($this->site(['purchases' => $this->m(12, 10)]), 'purchases')),
        );
        // Either period reaching the minimum makes it a normal figure: 6 now but 12 before is a real drop.
        $this->assertStringContainsString('fell -50.0% to 6', $this->plain($this->find($this->site(['purchases' => $this->m(6, 12)]), 'purchases')));
        $this->assertStringContainsString('Purchases are few (4 this week, 3 before)', $this->plain($this->find($this->site(['purchases' => $this->m(4, 3)]), 'purchases')));

        config(['report.insight.small_numbers.min_purchases' => 20]);
        $this->assertStringContainsString('Purchases are few', $this->plain($this->find($this->site(['purchases' => $this->m(12, 10)]), 'purchases')));
    }

    public function test_no_purchases_in_either_period_says_nothing(): void
    {
        $this->assertNull($this->find($this->site(['purchases' => $this->m(0, 0)]), 'purchases'));
        $this->assertNull($this->find($this->site(['purchases' => null]), 'purchases'));
    }

    public function test_monthly_reports_compare_with_the_same_month_last_year(): void
    {
        $site = [
            'sessions' => $this->m(1250, 1000, 1000.0), 'keyEvents' => $this->m(30, 30, 20.0), 'bookingEngineVisits' => $this->m(60, 40, 30.0),
        ];

        $finding = $this->find($this->site($site, 'monthly'), 'yearAgo');

        $this->assertSame(
            'Compared with October 2025: sessions +25.0%, key events +50.0% and booking-engine visits +100.0%.',
            $this->plain($finding),
        );
        $this->assertNull($this->find($this->site($site, 'weekly'), 'yearAgo'));
    }

    public function test_a_year_ago_change_beyond_the_guard_is_left_out_and_noted(): void
    {
        $payload = $this->payload('monthly', ['keyEvents' => $this->m(732, 764, 7102.0), 'sessions' => $this->m(1250, 1000, 1000.0)]);
        $context = $this->context($payload);

        $text = $this->plain($this->rules->yearAgo($context, $payload['site']));

        $this->assertSame('Compared with October 2025: sessions +25.0%.', $text);
        $this->assertStringContainsString('Key events changed by -89.7% versus October 2025.', (string) $context->largeChangeSentence());
    }

    public function test_a_zero_previous_value_says_new_this_period_without_dividing(): void
    {
        $text = $this->plain($this->find($this->site(['totalUsers' => $this->m(100, 0), 'keyEvents' => $this->m(0, 0)]), 'overview'));

        $this->assertStringContainsString('Total users reached 100 (new this period)', $text);
        $this->assertStringContainsString('key events was 0 (no comparison available)', $text);
    }

    public function test_no_paragraph_without_a_site_section(): void
    {
        $this->assertSame([], $this->rules->findings($this->context(['meta' => ['type' => 'weekly']])));
        $this->assertSame([], $this->rules->findings($this->context(['meta' => ['type' => 'weekly'], 'site' => ['error' => ['message' => 'x']]])));
    }
}
