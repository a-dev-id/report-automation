<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\Insight;

use App\Services\Reports\Insight\AiRules;
use App\Services\Reports\Insight\SearchConsoleRules;
use App\Support\Compare;
use Tests\Concerns\BuildsInsightPayloads;
use Tests\TestCase;

class AiAndSearchConsoleRulesTest extends TestCase
{
    use BuildsInsightPayloads;

    // ---- AI assistants ------------------------------------------------------------------------------

    private function ai(float $total = 175, float $previous = 106, array $overrides = []): array
    {
        $source = fn (string $name, float $now, float $before, float $share) => ['source' => $name, 'sessions' => $this->m($now, $before), 'share' => ['current' => $share, 'previous' => null]];
        $country = fn (string $name, float $now, float $before, float $share) => [
            'country' => $name, 'sessions' => $now, 'previousSessions' => $before, 'change' => Compare::change($now, $before), 'share' => $share, 'isNew' => $before <= 0 && $now > 0,
        ];

        return $overrides + [
            'total' => $this->m($total, $previous),
            'sources' => [$source('chatgpt.com', 120, 80, 68.6), $source('perplexity.ai', 40, 20, 22.9), $source('gemini.google.com', 15, 0, 8.6)],
            'topCountries' => [$country('United States', 70, 60, 40.0), $country('Indonesia', 50, 0, 28.6), $country('Australia', 30, 20, 17.1)],
            'droppedCountries' => ['Germany'],
            'topLandingPages' => [['landingPage' => '/', 'sessions' => 90.0]],
        ];
    }

    private function aiFindings(array $ai): array
    {
        $payload = $this->payload();
        $payload['ai_traffic'] = $ai;

        return (new AiRules())->findings($this->context($payload));
    }

    public function test_the_ai_paragraph_names_the_top_assistant_new_assistants_and_what_it_means(): void
    {
        $findings = $this->aiFindings($this->ai());

        $this->assertSame(
            'AI assistants referred 175 sessions (+65.1%), led by chatgpt.com with 68.6% of them. New this period: gemini.google.com.',
            $this->plain($this->find($findings, 'overview')),
        );
        $meaning = $this->find($findings, 'meaning');
        $this->assertSame('This suggests guests are researching with AI assistants, so keep key information on the site clear and easy to quote.', $this->plain($meaning));
        $this->assertTrue($meaning->essential);   // the meaning is never trimmed away

        // A single assistant is "all from", not "100% of them".
        $single = $this->aiFindings($this->ai(overrides: ['sources' => [['source' => 'chatgpt.com', 'sessions' => $this->m(37, 40), 'share' => ['current' => 100.0, 'previous' => 100.0]]]]));
        $this->assertStringEndsWith(', all from chatgpt.com.', $this->plain($this->find($single, 'overview')));
    }

    public function test_the_top_ai_countries_show_share_change_new_and_the_dropped_ones(): void
    {
        $text = $this->plain($this->find($this->aiFindings($this->ai()), 'countries'));

        $this->assertSame(
            'Most AI visitors came from United States (40.0% of AI sessions, +16.7%), Indonesia (28.6%, New) and Australia (17.1%, +50.0%). Dropped out of the AI top list: Germany.',
            $text,
        );
        $this->assertStringContainsString('⟦new|New⟧', $this->find($this->aiFindings($this->ai()), 'countries')->text);
    }

    public function test_the_ai_paragraph_needs_a_minimum_number_of_sessions(): void
    {
        $this->assertSame([], $this->aiFindings($this->ai(19, 10)));
        $this->assertNotSame([], $this->aiFindings($this->ai(20, 10)));

        config(['report.insight.ai.min_sessions' => 100]);
        $this->assertSame([], $this->aiFindings($this->ai(50, 40)));
    }

    public function test_no_ai_paragraph_for_missing_error_or_empty_sections(): void
    {
        $rules = new AiRules();

        $this->assertSame([], $rules->findings($this->context($this->payload())));
        $this->assertSame([], $this->aiFindings(['error' => ['message' => 'x']]));
        $this->assertSame([], $this->aiFindings($this->ai(overrides: ['sources' => []])));
    }

    public function test_a_guarded_ai_change_is_noted_not_stated(): void
    {
        $payload = $this->payload();
        $payload['ai_traffic'] = $this->ai(100, 20);   // +400%
        $context = $this->context($payload);

        $text = $this->plain($this->find((new AiRules())->findings($context), 'overview'));

        $this->assertStringContainsString('referred 100 sessions (see the note below on the size of the change)', $text);
        $this->assertStringNotContainsString('400', $text);
        $this->assertStringContainsString('AI traffic changed by +400.0% versus the previous week', (string) $context->largeChangeSentence());
    }

    public function test_a_zero_previous_ai_total_is_new_not_an_error(): void
    {
        $text = $this->plain($this->find($this->aiFindings($this->ai(40, 0)), 'overview'));

        $this->assertStringContainsString('referred 40 sessions (New)', $text);
    }

    // ---- Search Console -----------------------------------------------------------------------------

    private function sc(array $totals = [], array $extra = []): array
    {
        return $extra + [
            'hasData' => true, 'truncated' => false, 'daysMissing' => 0, 'through' => '2026-11-15',
            'totals' => $totals + [
                'impressions' => $this->m(12000, 10000),
                'clicks' => $this->m(90, 100),
                'ctr' => $this->m(0.0075, 0.01),
                'position' => $this->m(10.8, 10.0, lowerIsBetter: true),
            ],
            'brand' => null,
        ];
    }

    private function scFindings(array $sc): array
    {
        $payload = $this->payload();
        $payload['search_console'] = $sc;

        return (new SearchConsoleRules())->findings($this->context($payload));
    }

    public function test_more_impressions_but_fewer_clicks_says_the_site_was_shown_more_but_clicked_less(): void
    {
        $findings = $this->scFindings($this->sc());

        $finding = $this->find($findings, 'shownNotClicked');
        $this->assertSame(
            'The site appeared more often but fewer people clicked and the average position slipped: the page title or description may not be convincing.',
            $this->plain($finding),
        );
        $this->assertSame('Review the titles and descriptions of the pages that get the most impressions.', $finding->action);
        $this->assertSame(2, $finding->actionPriority);

        // The position held or improved: the position is only a possible cause.
        $improved = $this->scFindings($this->sc(['position' => $this->m(9.0, 10.0, lowerIsBetter: true)]));
        $this->assertStringContainsString('or the position may have slipped', $this->plain($this->find($improved, 'shownNotClicked')));
    }

    public function test_that_rule_needs_impressions_up_and_clicks_down(): void
    {
        $this->assertNull($this->find($this->scFindings($this->sc(['clicks' => $this->m(110, 100)])), 'shownNotClicked'));
        $this->assertNull($this->find($this->scFindings($this->sc(['impressions' => $this->m(9000, 10000)])), 'shownNotClicked'));
    }

    public function test_the_average_position_is_described_with_a_lower_number_being_better(): void
    {
        $slipped = $this->find($this->scFindings($this->sc()), 'overview');
        $this->assertStringContainsString('The average position slipped from 10.0 to 10.8', $this->plain($slipped));
        $this->assertStringContainsString('a lower number is better', $this->plain($slipped));
        $this->assertStringContainsString('⟦neg|+8.0%⟧', $slipped->text);   // the number went up, which is worse: red

        $improved = $this->find($this->scFindings($this->sc(['position' => $this->m(9.0, 10.0, lowerIsBetter: true)])), 'overview');
        $this->assertStringContainsString('The average position improved from 10.0 to 9.0', $this->plain($improved));
        $this->assertStringContainsString('⟦pos|-10.0%⟧', $improved->text);    // the number went down, which is better: green
    }

    public function test_the_search_overview_reads_as_a_sentence(): void
    {
        $text = $this->plain($this->find($this->scFindings($this->sc()), 'overview'));

        $this->assertStringStartsWith('In Google Search, impressions rose +20.0% to 12,000, clicks fell -10.0% to 90 and the click-through rate fell', $text);
    }

    public function test_the_brand_share_is_skipped_without_brand_terms(): void
    {
        $this->assertNull($this->find($this->scFindings($this->sc()), 'brandShare'));
    }

    public function test_the_brand_share_says_where_new_guests_come_from(): void
    {
        $brand = fn (float $now, ?float $before) => ['terms' => ['nandini'], 'clicks' => ['current' => 1, 'previous' => 1], 'share' => ['current' => $now, 'previous' => $before], 'sampleTruncated' => false];

        $many = $this->plain($this->find($this->scFindings($this->sc([], ['brand' => $brand(46.0, 45.0)])), 'brandShare'));
        $this->assertSame(
            '46.0% of clicks came from searches that contain a brand term (nandini), up from 45.0%. '
            .'Many clicks come from people who already know the hotel; growth from non-brand searches is where new guests come from.',
            $many,
        );

        $most = $this->plain($this->find($this->scFindings($this->sc([], ['brand' => $brand(62.5, 70.0)])), 'brandShare'));
        $this->assertStringContainsString('62.5% of clicks', $most);
        $this->assertStringContainsString('down from 70.0%', $most);
        $this->assertStringContainsString('Most clicks come from people who already know the hotel', $most);

        $same = $this->plain($this->find($this->scFindings($this->sc([], ['brand' => $brand(46.0, 46.0)])), 'brandShare'));
        $this->assertStringNotContainsString('from 46.0%', $same);
    }

    public function test_the_brand_share_needs_clicks_to_be_meaningful(): void
    {
        $brand = ['terms' => ['nandini'], 'clicks' => ['current' => 0, 'previous' => 0], 'share' => ['current' => null, 'previous' => null], 'sampleTruncated' => false];

        $this->assertNull($this->find($this->scFindings($this->sc(['clicks' => $this->m(0, 0)], ['brand' => $brand])), 'brandShare'));
    }

    public function test_the_data_through_date_is_mentioned_only_when_the_last_days_are_missing(): void
    {
        $this->assertNull($this->find($this->scFindings($this->sc()), 'dataThrough'));

        $text = $this->plain($this->find($this->scFindings($this->sc([], ['truncated' => true, 'through' => '2026-11-14', 'daysMissing' => 1])), 'dataThrough'));
        $this->assertSame('Search Console data runs through 14 Nov 2026.', $text);
    }

    public function test_no_search_console_paragraph_without_data_or_with_an_error(): void
    {
        $rules = new SearchConsoleRules();

        $this->assertSame([], $rules->findings($this->context($this->payload())));
        $this->assertSame([], $this->scFindings(['error' => ['message' => 'x']]));
        $this->assertSame([], $this->scFindings(['hasData' => false, 'totals' => null]));
    }

    public function test_a_zero_previous_search_value_never_divides(): void
    {
        $text = $this->plain($this->find($this->scFindings($this->sc(['clicks' => $this->m(5, 0), 'impressions' => $this->m(100, 0), 'ctr' => $this->m(0.05, null), 'position' => $this->m(12.0, null, lowerIsBetter: true)])), 'overview'));

        $this->assertStringContainsString('(new this period)', $text);
        $this->assertStringContainsString('The average position was 12.0.', $text);
    }

    public function test_impressions_and_clicks_moving_together_says_visibility_improved_or_weakened(): void
    {
        $up = $this->scFindings($this->sc(['impressions' => $this->m(9267, 7752), 'clicks' => $this->m(140, 116)]));
        $this->assertSame('The site was found more often and more people clicked, so its visibility in search improved.', $this->plain($this->find($up, 'visibilityMoved')));
        $this->assertNull($this->find($up, 'visibilityMoved')->action);

        $down = $this->scFindings($this->sc(['impressions' => $this->m(40814, 41940), 'clicks' => $this->m(605, 711)]));
        $finding = $this->find($down, 'visibilityMoved');
        $this->assertSame('The site appeared less often and fewer people clicked, so its visibility in search weakened.', $this->plain($finding));
        $this->assertSame('Check whether key pages lost rankings or were changed recently.', $finding->action);

        // Opposite moves are the "shown but not clicked" case, not this one.
        $this->assertNull($this->find($this->scFindings($this->sc()), 'visibilityMoved'));
        $this->assertNull($this->find($this->scFindings($this->sc(['impressions' => $this->m(9000, 10000), 'clicks' => $this->m(110, 100)])), 'visibilityMoved'));
    }}
