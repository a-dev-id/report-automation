<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\Insight;

use App\Services\Reports\Insight\Verdict;
use App\Support\Rich;
use Tests\Concerns\BuildsInsightPayloads;
use Tests\TestCase;

class VerdictTest extends TestCase
{
    use BuildsInsightPayloads;

    /** @param  array<string, mixed>  $site */
    private function verdict(array $site, string $type = 'weekly'): array
    {
        $result = (new Verdict())->evaluate($this->context($this->payload($type, $site)));

        return ['key' => $result['key'], 'text' => Rich::toPlain($result['headline'])];
    }

    public function test_sessions_up_with_steady_engagement_and_more_booking_visits_is_healthy_growth(): void
    {
        $v = $this->verdict(['sessions' => $this->m(1250, 1000), 'engagementRate' => $this->m(0.62, 0.60), 'bookingEngineVisits' => $this->m(60, 40)]);

        $this->assertSame('healthy growth', $v['key']);
        $this->assertSame(
            'This week is best described as healthy growth: sessions rose ▲ +25.0% to 1,250, engagement rose ▲ +2.0 pts to 62.0% and booking-engine visits rose ▲ +50.0% to 60.',
            $v['text'],
        );
    }

    public function test_sessions_up_but_engagement_down_is_growth_with_weaker_quality(): void
    {
        $v = $this->verdict(['sessions' => $this->m(1250, 1000), 'engagementRate' => $this->m(0.45, 0.60), 'bookingEngineVisits' => $this->m(45, 40)]);

        $this->assertSame('growth with weaker quality', $v['key']);
        $this->assertStringStartsWith('This week is best described as growth with weaker quality: sessions rose', $v['text']);
        $this->assertStringContainsString('engagement fell ▼ -15.0 pts to 45.0%', $v['text']);
    }

    public function test_sessions_up_but_fewer_booking_visits_is_also_weaker_quality(): void
    {
        $this->assertSame('growth with weaker quality', $this->verdict(['sessions' => $this->m(1250, 1000), 'bookingEngineVisits' => $this->m(30, 40)])['key']);
    }

    public function test_sessions_down_with_no_offsetting_gain_is_a_decline(): void
    {
        $v = $this->verdict(['sessions' => $this->m(700, 1000), 'engagementRate' => $this->m(0.58, 0.60), 'bookingEngineVisits' => $this->m(38, 40)]);

        $this->assertSame('decline', $v['key']);
        $this->assertStringStartsWith('This week is best described as a decline: sessions fell ▼ -30.0% to 700', $v['text']);
    }

    public function test_sessions_down_but_better_engagement_or_booking_is_mixed(): void
    {
        $this->assertSame('mixed', $this->verdict(['sessions' => $this->m(800, 1000), 'engagementRate' => $this->m(0.66, 0.60)])['key']);
        $this->assertSame('mixed', $this->verdict(['sessions' => $this->m(800, 1000), 'bookingEngineVisits' => $this->m(60, 40)])['key']);
    }

    public function test_sessions_within_the_stable_band_and_little_else_moving_is_stable(): void
    {
        $v = $this->verdict(['sessions' => $this->m(1030, 1000), 'engagementRate' => $this->m(0.605, 0.60), 'bookingEngineVisits' => $this->m(41, 40)]);

        $this->assertSame('stable', $v['key']);
        $this->assertStringStartsWith('This week is best described as stable:', $v['text']);

        // Exactly +5.0% is still inside the band; just beyond it is growth.
        $this->assertSame('stable', $this->verdict(['sessions' => $this->m(1050, 1000), 'engagementRate' => $this->m(0.60, 0.60), 'bookingEngineVisits' => $this->m(40, 40)])['key']);
        $this->assertSame('healthy growth', $this->verdict(['sessions' => $this->m(1051, 1000), 'engagementRate' => $this->m(0.60, 0.60), 'bookingEngineVisits' => $this->m(40, 40)])['key']);
    }

    public function test_stable_sessions_with_a_big_move_elsewhere_is_mixed(): void
    {
        $this->assertSame('mixed', $this->verdict(['sessions' => $this->m(1000, 1000), 'engagementRate' => $this->m(0.50, 0.60)])['key']);
    }

    public function test_it_looks_at_sessions_engagement_and_booking_together_never_one_alone(): void
    {
        // The same +25% sessions gives three different verdicts depending on engagement and booking.
        $base = ['sessions' => $this->m(1250, 1000)];

        $this->assertSame('healthy growth', $this->verdict($base + ['engagementRate' => $this->m(0.60, 0.60), 'bookingEngineVisits' => $this->m(40, 40)])['key']);
        $this->assertSame('growth with weaker quality', $this->verdict($base + ['engagementRate' => $this->m(0.50, 0.60), 'bookingEngineVisits' => $this->m(40, 40)])['key']);
        $this->assertSame('growth with weaker quality', $this->verdict($base + ['engagementRate' => $this->m(0.60, 0.60), 'bookingEngineVisits' => $this->m(20, 40)])['key']);
    }

    public function test_the_thresholds_come_from_config(): void
    {
        $site = ['sessions' => $this->m(1250, 1000), 'engagementRate' => $this->m(0.585, 0.60), 'bookingEngineVisits' => $this->m(40, 40)];
        $this->assertSame('healthy growth', $this->verdict($site)['key']);   // -1.5 points: under the 2 point limit

        config(['report.insight.verdict.engagement_points' => 1.0]);
        $this->assertSame('growth with weaker quality', $this->verdict($site)['key']);

        config(['report.insight.verdict.stable_percent' => 30.0]);
        $this->assertSame('mixed', $this->verdict($site)['key']);   // +25% is now "stable", but engagement dipped
    }

    public function test_without_engagement_or_booking_data_sessions_decide_alone_and_the_sentence_stays_true(): void
    {
        $v = $this->verdict(['sessions' => $this->m(1250, 1000), 'engagementRate' => null, 'bookingEngineVisits' => null]);

        $this->assertSame('healthy growth', $v['key']);
        $this->assertSame('This week is best described as healthy growth: sessions rose ▲ +25.0% to 1,250.', $v['text']);
    }

    public function test_sessions_that_are_new_count_as_growth_and_a_zero_previous_never_divides(): void
    {
        $v = $this->verdict(['sessions' => $this->m(100, 0), 'engagementRate' => $this->m(0.5, null), 'bookingEngineVisits' => $this->m(0, 0)]);

        $this->assertSame('healthy growth', $v['key']);
        $this->assertStringContainsString('sessions reached 100 (new this period)', $v['text']);
    }

    public function test_a_guarded_sessions_change_gives_no_verdict_and_only_the_value(): void
    {
        $payload = $this->payload('weekly', ['sessions' => $this->m(10000, 800)]);
        $context = $this->context($payload);

        $result = (new Verdict())->evaluate($context);

        $this->assertNull($result['key']);
        $this->assertSame('This week: sessions were 10,000 (see the note below on the size of the change).', Rich::toPlain($result['headline']));
        $this->assertStringContainsString('Sessions changed by +1,150.0% versus the previous week.', (string) $context->largeChangeSentence());
    }

    public function test_a_guarded_engagement_or_booking_change_is_not_used_for_the_verdict(): void
    {
        // Booking visits +400% is beyond the guard: it cannot make this "weaker quality" or "healthy".
        $v = $this->verdict(['sessions' => $this->m(1250, 1000), 'engagementRate' => $this->m(0.60, 0.60), 'bookingEngineVisits' => $this->m(200, 40)]);

        $this->assertSame('healthy growth', $v['key']);
        $this->assertStringContainsString('booking-engine visits was 200 (see the note below on the size of the change)', $v['text']);
    }

    public function test_monthly_wording(): void
    {
        $v = $this->verdict(['sessions' => $this->m(1250, 1000)], 'monthly');

        $this->assertStringStartsWith('This month is best described as healthy growth:', $v['text']);
    }

    public function test_a_missing_site_section_gives_a_neutral_headline(): void
    {
        $weekly = (new Verdict())->evaluate($this->context(['meta' => ['type' => 'weekly']]));
        $monthly = (new Verdict())->evaluate($this->context(['meta' => ['type' => 'monthly'], 'site' => ['error' => ['message' => 'x']]]));

        $this->assertNull($weekly['key']);
        $this->assertSame('This week: traffic figures are not available for this report.', $weekly['headline']);
        $this->assertSame('This month: traffic figures are not available for this report.', $monthly['headline']);
    }
}
