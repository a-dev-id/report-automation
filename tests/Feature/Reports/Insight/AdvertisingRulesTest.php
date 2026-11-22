<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\Insight;

use App\Services\Reports\Insight\AdvertisingRules;
use Tests\Concerns\BuildsInsightPayloads;
use Tests\TestCase;

class AdvertisingRulesTest extends TestCase
{
    use BuildsInsightPayloads;

    private AdvertisingRules $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rules = new AdvertisingRules();
    }

    private function findings(array $paid, string $type = 'weekly'): array
    {
        $payload = $this->payload($type);
        $payload['paid'] = $paid;

        return $this->rules->findings($this->context($payload));
    }

    public function test_reach_up_with_fewer_clicks_and_a_lower_ctr_says_fewer_responded_and_asks_for_a_review(): void
    {
        // The owner's example: impressions +46.6%, clicks -16.1%, CTR -42.8%.
        $paid = $this->paid([
            'impressions' => $this->m(146620, 100000),
            'clicks' => $this->m(839, 1000),
            'ctr' => $this->m(0.00572, 0.01),
        ]);

        $findings = $this->findings($paid);
        $finding = $this->find($findings, 'reachUpResponseDown');

        $this->assertNotNull($finding);
        $this->assertSame('The ads reached more people but fewer responded, so the audience or the creative may be less relevant.', $this->plain($finding));
        $this->assertSame('Review the ad placements and the ad creative.', $finding->action);
        $this->assertSame(1, $finding->actionPriority);

        $overview = $this->plain($this->find($findings, 'overview'));
        $this->assertStringContainsString('Paid impressions rose +46.6% to 146,620', $overview);
        $this->assertStringContainsString('clicks fell -16.1% to 839', $overview);
    }

    public function test_the_reach_rule_needs_all_three_signals(): void
    {
        $rule = fn (array $overrides) => $this->rules->reachUpResponseDown($this->context($this->payload()), $this->paid($overrides));

        $this->assertNull($rule(['clicks' => $this->m(1100, 1000)]));                                    // clicks up
        $this->assertNull($rule(['impressions' => $this->m(90000, 100000), 'clicks' => $this->m(800, 1000), 'ctr' => $this->m(0.008, 0.01)]));   // reach down
        $this->assertNull($rule(['impressions' => $this->m(120000, 100000), 'clicks' => $this->m(800, 1000), 'ctr' => $this->m(0.0108, 0.01)])); // ctr up
        $this->assertNotNull($rule(['impressions' => $this->m(120000, 100000), 'clicks' => $this->m(800, 1000), 'ctr' => $this->m(0.0067, 0.01)]));
    }

    public function test_the_rule_does_not_interpret_a_metric_beyond_the_large_change_guard(): void
    {
        $payload = $this->payload();
        $payload['paid'] = $this->paid(['impressions' => $this->m(150000, 100000), 'clicks' => $this->m(300, 1000), 'ctr' => $this->m(0.002, 0.01)]);   // clicks -70%
        $context = $this->context($payload);

        $findings = $this->rules->findings($context);

        $this->assertNull($this->find($findings, 'reachUpResponseDown'));   // the clicks signal is not trusted
        $this->assertStringNotContainsString('70.0%', $this->plainAll($findings));   // and the size of the change is not stated as a fact
        $this->assertStringContainsString('clicks was 300 (see the note below on the size of the change)', $this->plainAll($findings));
        $this->assertStringStartsWith('Paid clicks changed by -70.0% versus the previous week and click-through rate changed by -80.0% versus the previous week.', (string) $context->largeChangeSentence());
    }

    public function test_spend_up_with_no_purchases_says_so_and_points_to_booking_engine_visits(): void
    {
        $paid = $this->paid(['cost' => $this->m(5890000, 5000000), 'purchases' => $this->m(0, 0), 'revenue' => $this->m(0, 0)]);

        $findings = $this->findings($paid);
        $finding = $this->find($findings, 'spendWithoutPurchases');

        $this->assertSame(
            'Spend rose without recorded bookings. Hotel booking decisions are slow, so also judge the advertising by booking-engine visits.',
            $this->plain($finding),
        );
        $this->assertSame('Judge the advertising by booking-engine visits as well as purchases.', $finding->action);
        $this->assertSame(3, $finding->actionPriority);
        $this->assertStringContainsString('while no purchases were recorded from paid channels', $this->plain($this->find($findings, 'overview')));
    }

    public function test_the_spend_rule_does_not_fire_with_purchases_or_without_purchase_data(): void
    {
        $rule = fn (array $overrides) => $this->rules->spendWithoutPurchases($this->context($this->payload()), $this->paid($overrides));

        $this->assertNull($rule(['cost' => $this->m(5890000, 5000000), 'purchases' => $this->m(2, 0)]));    // there were purchases
        $this->assertNull($rule(['cost' => $this->m(5890000, 5000000)]));                                   // purchases unknown: never guessed
        $this->assertNull($rule(['cost' => $this->m(4000000, 5000000), 'purchases' => $this->m(0, 0)]));    // spend did not rise
    }

    public function test_more_clicks_at_a_lower_cost_per_click_is_called_more_efficient(): void
    {
        $this->assertSame(
            'Advertising became more efficient: more clicks at a lower cost per click.',
            $this->plain($this->rules->efficiency($this->context($this->payload()), $this->paid([
                'clicks' => $this->m(1200, 1000), 'costPerClick' => $this->m(4000, 5000, lowerIsBetter: true),
            ]))),
        );

        $this->assertNull($this->rules->efficiency($this->context($this->payload()), $this->paid([
            'clicks' => $this->m(1200, 1000), 'costPerClick' => $this->m(6000, 5000, lowerIsBetter: true),
        ])));
    }

    public function test_display_and_awareness_campaigns_are_described_as_awareness_not_demand(): void
    {
        $campaign = fn (string $name, float $cost) => ['campaign' => $name, 'impressions' => 1000.0, 'clicks' => 10.0, 'cost' => $cost];

        $finding = $this->rules->awareness($this->context($this->payload()), $this->paid([], [$campaign('Awareness_GeneralDisplay_2026', 3000000.0), $campaign('Brand', 1.0)]));
        $this->assertSame(
            'Display and awareness campaigns build brand awareness rather than direct demand, so judge them on reach, not on bookings.',
            $this->plain($finding),
        );

        $this->assertNull($this->rules->awareness($this->context($this->payload()), $this->paid([], [$campaign('Conversion_Promotional', 3000000.0)])));
        $this->assertNull($this->rules->awareness($this->context($this->payload()), $this->paid([], [])));
        // Only the three biggest campaigns count.
        $many = [$campaign('A', 5.0), $campaign('B', 4.0), $campaign('C', 3.0), $campaign('Awareness', 2.0)];
        $this->assertNull($this->rules->awareness($this->context($this->payload()), $this->paid([], $many)));

        $this->payload(); // terms come from config
        config(['report.insight.awareness_terms' => ['promo']]);
        $this->assertNotNull($this->rules->awareness($this->context($this->payload()), $this->paid([], [$campaign('Conversion_Promotional', 3000000.0)])));
    }

    public function test_the_cost_is_written_in_the_neutral_color_and_the_overview_always_says_what_changed(): void
    {
        $paid = $this->paid(['cost' => $this->m(5890000, 5000000)]);

        $overview = $this->find($this->findings($paid), 'overview');

        $this->assertTrue($overview->essential);
        $this->assertStringContainsString('⟦neutral|+17.8%⟧', $overview->text);    // cost: gray, not green or red
        $this->assertStringContainsString('⟦pos|+25.0%⟧', $overview->text);        // impressions: a rise is green
        $this->assertStringContainsString('IDR 5,890,000', $this->plain($overview));
    }

    public function test_purchases_from_paid_channels_are_mentioned_when_there_are_some(): void
    {
        $overview = $this->plain($this->find($this->findings($this->paid(['purchases' => $this->m(2, 0)])), 'overview'));

        $this->assertStringContainsString('with 2 purchases recorded from paid channels', $overview);
    }

    public function test_a_zero_previous_value_says_new_this_period_and_never_divides(): void
    {
        $paid = $this->paid(['impressions' => $this->m(1000, 0), 'clicks' => $this->m(0, 0), 'ctr' => $this->m(0.0, null), 'cost' => $this->m(0, 0)]);

        $overview = $this->plain($this->find($this->findings($paid), 'overview'));

        $this->assertStringContainsString('Paid impressions reached 1,000 (new this period)', $overview);
        $this->assertStringContainsString('clicks was 0 (no comparison available)', $overview);
        $this->assertStringNotContainsString('INF', $overview);
        $this->assertStringNotContainsString('NAN', $overview);
    }

    public function test_no_paragraph_without_paid_data(): void
    {
        $context = fn (?array $paid) => $this->context($this->payload() + ['paid' => $paid]);

        $this->assertSame([], $this->rules->findings($context(null)));                                                          // ads_source none
        $this->assertSame([], $this->rules->findings($context(['source' => 'google_ads_api', 'available' => false, 'metrics' => null])));
        $this->assertSame([], $this->rules->findings($context(['error' => ['type' => 'GoogleApiException', 'message' => 'boom']])));
        $this->assertSame([], $this->rules->findings($this->context($this->payload())));                                         // no paid key at all
    }

    public function test_every_rule_names_itself_for_the_trimming_and_the_priority_line(): void
    {
        $paid = $this->paid([
            'impressions' => $this->m(146620, 100000), 'clicks' => $this->m(839, 1000), 'ctr' => $this->m(0.0057, 0.01),
            'cost' => $this->m(5890000, 5000000), 'purchases' => $this->m(0, 0),
        ]);

        $this->assertSame(['overview', 'reachUpResponseDown', 'spendWithoutPurchases'], $this->rules($this->findings($paid)));
    }

    public function test_impressions_and_clicks_both_down_means_the_advertising_reach_shrank(): void
    {
        $paid = $this->paid(['impressions' => $this->m(47535, 79574), 'clicks' => $this->m(1546, 2909), 'ctr' => $this->m(0.0325, 0.0366)]);

        $finding = $this->rules->reachAndResponseDown($this->context($this->payload()), $paid);

        $this->assertSame('Fewer people saw the ads and fewer clicked, so the advertising reach shrank.', $this->plain($finding));
        $this->assertSame('Check campaign budgets, schedules and any paused campaigns.', $finding->action);
        $this->assertSame(3, $finding->actionPriority);

        $this->assertNull($this->rules->reachAndResponseDown($this->context($this->payload()), $this->paid(['impressions' => $this->m(60000, 40000), 'clicks' => $this->m(800, 900)])));
        $this->assertNull($this->rules->reachAndResponseDown($this->context($this->payload()), $this->paid(['impressions' => $this->m(30000, 40000), 'clicks' => $this->m(950, 900)])));
    }

    public function test_clicks_growing_more_slowly_than_impressions_is_explained(): void
    {
        $finding = $this->rules->reachOutpacingResponse($this->context($this->payload()), $this->paid([
            'impressions' => $this->m(480851, 265590), 'clicks' => $this->m(15664, 13299), 'ctr' => $this->m(0.0326, 0.05),
        ]));

        $this->assertSame('Clicks grew more slowly than impressions, so a smaller share of the people who saw the ads clicked.', $this->plain($finding));
        $this->assertNull($finding->action);

        // Clicks falling is the other rule, and a rising CTR is not a problem.
        $this->assertNull($this->rules->reachOutpacingResponse($this->context($this->payload()), $this->paid(['impressions' => $this->m(120, 100), 'clicks' => $this->m(90, 100), 'ctr' => $this->m(0.01, 0.02)])));
        $this->assertNull($this->rules->reachOutpacingResponse($this->context($this->payload()), $this->paid(['impressions' => $this->m(120, 100), 'clicks' => $this->m(150, 100), 'ctr' => $this->m(0.03, 0.02)])));
    }

    public function test_a_higher_cost_per_click_means_less_efficient_advertising(): void
    {
        $up = $this->rules->lessEfficient($this->context($this->payload()), $this->paid(['costPerClick' => $this->m(3175, 1538, lowerIsBetter: true)]));

        $this->assertSame('Advertising became less efficient: each click cost more.', $this->plain($up));
        $this->assertNull($this->rules->lessEfficient($this->context($this->payload()), $this->paid(['costPerClick' => $this->m(1400, 1538, lowerIsBetter: true)])));
        $this->assertNull($this->rules->lessEfficient($this->context($this->payload()), $this->paid(['costPerClick' => $this->m(1538, 1538, lowerIsBetter: true)])));
        // A cost per click that jumped beyond the guard is noted, not interpreted.
        $this->assertNull($this->rules->lessEfficient($this->context($this->payload()), $this->paid(['costPerClick' => $this->m(9000, 1000, lowerIsBetter: true)])));
    }}
