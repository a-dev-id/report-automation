<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\Insight;

use App\Services\Reports\Insight\SourceRules;
use Tests\Concerns\BuildsInsightPayloads;
use Tests\TestCase;

class SourceRulesTest extends TestCase
{
    use BuildsInsightPayloads;

    private SourceRules $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rules = new SourceRules();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $others
     */
    private function findings(array $rows, array $others = []): array
    {
        $payload = $this->payload();
        $payload['channels'] = ['total' => ['sessions' => $this->m(1000, 800)], 'otherChannels' => $others, 'rows' => $rows];

        return $this->rules->findings($this->context($payload));
    }

    public function test_the_overview_names_the_largest_sources_with_their_share_and_what_the_rest_came_from(): void
    {
        $findings = $this->findings([
            $this->channel('Direct', 4406, 3713, 60.0, 50.0),
            $this->channel('Display', 795, 1592, 20.0, 25.0),
            $this->channel('Organic Search', 350, 299, 10.0, 8.0),
            $this->channel('Paid Search', 150, 123, 5.0, 4.0),
        ], [['channel' => 'Referral', 'sessions' => 60.0, 'share' => 3.0], ['channel' => 'Email', 'sessions' => 40.0, 'share' => 2.0]]);

        $this->assertSame(
            'Direct was the largest source at 60.0% of sessions (+18.7% in sessions), followed by Display (20.0%) and Organic Search (10.0%). '
            .'The remaining 10.0% came from Paid Search, Referral and Email.',
            $this->plain($this->find($findings, 'overview')),
        );
        $this->assertTrue($this->find($findings, 'overview')->essential);
    }

    public function test_many_small_channels_are_summarised(): void
    {
        $others = array_map(fn (string $n) => ['channel' => $n, 'sessions' => 10.0, 'share' => 1.0], ['A', 'B', 'C', 'D']);

        $text = $this->plain($this->find($this->findings([$this->channel('Direct', 100, 90, 90.0, 90.0)], $others), 'overview'));

        $this->assertStringEndsWith('The remaining 4.0% came from A, B, C and smaller channels.', $text);
    }

    public function test_a_dominant_channel_with_very_low_engagement_is_called_likely_automated_traffic(): void
    {
        $findings = $this->findings([
            $this->channel('Direct', 4406, 3713, 68.1, 50.0, engagement: 0.139, duration: 21.8),
            $this->channel('Display', 795, 1592, 12.1, 21.0),
        ]);

        $finding = $this->find($findings, 'dominantLowQuality');

        $this->assertSame(
            'Only 13.9% of Direct visits were engaged (average visit 22s). '
            .'This is likely automated or low-quality traffic that inflates sessions and lowers averages, so do not read it as demand.',
            $this->plain($finding),
        );
        $this->assertSame('Check bot and referral filtering in GA4.', $finding->action);
        $this->assertSame(1, $finding->actionPriority);
    }

    public function test_the_dominant_channel_share_is_not_repeated_in_the_same_paragraph_finding(): void
    {
        $findings = $this->findings([
            $this->channel('Direct', 4406, 3713, 68.1, 50.0, engagement: 0.139, duration: 21.8),
            $this->channel('Display', 795, 1592, 12.1, 21.0),
        ]);

        // The overview states the 68.1% share; the quality finding must not state it again.
        $this->assertSame(1, substr_count($this->plain($this->find($findings, 'overview')).' '.$this->plain($this->find($findings, 'dominantLowQuality')), '68.1%'));
    }
    public function test_a_very_short_average_visit_also_counts_even_with_ok_engagement(): void
    {
        $findings = $this->findings([$this->channel('Direct', 500, 400, 55.0, 50.0, engagement: 0.5, duration: 3.0)]);

        $this->assertNotNull($this->find($findings, 'dominantLowQuality'));
    }

    public function test_a_dominant_channel_with_healthy_visits_is_not_flagged(): void
    {
        $this->assertNull($this->find($this->findings([$this->channel('Organic Search', 500, 400, 70.0, 60.0, engagement: 0.6, duration: 120.0)]), 'dominantLowQuality'));
    }

    public function test_the_share_and_engagement_limits_are_strict_and_configurable(): void
    {
        $flag = fn (float $share, float $engagement, float $duration = 90.0) => $this->find(
            $this->findings([$this->channel('Direct', 500, 400, $share, 40.0, engagement: $engagement, duration: $duration)]),
            'dominantLowQuality',
        );

        $this->assertNull($flag(40.0, 0.10));        // exactly 40% is not "over 40%"
        $this->assertNotNull($flag(40.1, 0.10));
        $this->assertNull($flag(60.0, 0.25));        // exactly 25% engagement is not "under 25%"
        $this->assertNotNull($flag(60.0, 0.249));
        $this->assertNull($flag(60.0, 0.60, 5.0));   // exactly the 5 s bot threshold is not "under" it
        $this->assertNotNull($flag(60.0, 0.60, 4.9));

        config(['report.insight.dominant_channel.min_share_percent' => 70]);
        $this->assertNull($flag(60.0, 0.10));
    }

    public function test_only_the_dominant_channel_is_judged_not_every_low_engagement_channel(): void
    {
        $findings = $this->findings([
            $this->channel('Organic Search', 700, 600, 70.0, 60.0, engagement: 0.7),
            $this->channel('Direct', 200, 100, 20.0, 10.0, engagement: 0.05, duration: 2.0),   // low quality, but only 20% of sessions
        ]);

        $this->assertNull($this->find($findings, 'dominantLowQuality'));
    }

    public function test_a_share_that_moved_by_more_than_ten_points_gets_a_sentence(): void
    {
        $findings = $this->findings([
            $this->channel('Direct', 500, 400, 50.0, 40.0),
            $this->channel('Display', 350, 200, 35.0, 20.0),     // +15 points
            $this->channel('Organic Search', 150, 400, 15.0, 40.0), // -25 points
        ]);

        $moves = array_values(array_filter($findings, fn ($f) => $f->rule === 'shareMoves'));

        $this->assertCount(2, $moves);
        $this->assertSame("Organic Search's share of sessions fell -25.0 pts from 40.0%.", $this->plain($moves[0]));   // biggest move first
        $this->assertSame("Display's share of sessions rose +15.0 pts from 20.0%.", $this->plain($moves[1]));
        $this->assertStringContainsString('⟦zero|', $moves[0]->text);   // a share moving is neither good nor bad: gray
    }

    public function test_exactly_ten_points_is_not_a_big_move(): void
    {
        $findings = $this->findings([$this->channel('Direct', 500, 400, 50.0, 40.0)]);

        $this->assertNull($this->find($findings, 'shareMoves'));

        config(['report.insight.share_move_points' => 5]);
        $this->assertNotNull($this->find($this->findings([$this->channel('Direct', 500, 400, 50.0, 40.0)]), 'shareMoves'));
    }

    public function test_no_paragraph_without_usable_channels(): void
    {
        $context = fn (array $payload) => $this->context($this->payload() + $payload);

        $this->assertSame([], $this->rules->findings($context([])));
        $this->assertSame([], $this->rules->findings($context(['channels' => ['error' => ['message' => 'x']]])));
        $this->assertSame([], $this->rules->findings($context(['channels' => ['rows' => []]])));
        // Only the "Other" bucket: nothing to name.
        $this->assertSame([], $this->rules->findings($context(['channels' => ['rows' => [$this->channel('Other', 10, 10, 100.0, 100.0, other: true)]]])));
    }
}
