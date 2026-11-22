<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\Insight;

use App\Contracts\InsightGenerator;
use App\Enums\ReportType;
use App\Mail\ReportMail;
use App\Models\Property;
use App\Models\Report;
use App\Services\Reports\Insight\RuleBasedInsightGenerator;
use App\Support\Compare;
use App\Support\Rich;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFakePayloads;
use Tests\Concerns\BuildsInsightPayloads;
use Tests\Fakes\FakeData;
use Tests\TestCase;

/** The whole Traffic Quality Insight: structure, order, length, priority, wording and guards. */
class InsightGeneratorTest extends TestCase
{
    use BuildsFakePayloads;
    use BuildsInsightPayloads;
    use RefreshDatabase;

    private InsightGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeGoogle();
        $this->generator = $this->app->make(InsightGenerator::class);
    }

    private function words(array $insight): int
    {
        $total = Rich::words($insight['headline']) + Rich::words($insight['priority'] ?? '');
        foreach ($insight['paragraphs'] as $p) {
            $total += Rich::words($p['title']) + Rich::words($p['text']);
        }

        return $total;
    }

    private function allText(array $insight): string
    {
        return Rich::toPlain($insight['headline'].' '.implode(' ', array_column($insight['paragraphs'], 'text')).' '.($insight['priority'] ?? '').' '.($insight['caution'] ?? ''));
    }

    public function test_the_interface_is_bound_to_the_rule_based_generator(): void
    {
        $this->assertInstanceOf(RuleBasedInsightGenerator::class, $this->generator);
    }

    public function test_the_insight_has_a_verdict_headline_titled_paragraphs_in_order_and_a_neutral_caution_slot(): void
    {
        $insight = $this->generator->generate($this->fakePayload(FakeData::nandini(), ReportType::Weekly));

        $this->assertSame(['verdict', 'headline', 'paragraphs', 'priority', 'caution'], array_keys($insight));
        $this->assertSame('healthy growth', $insight['verdict']);
        $this->assertStringStartsWith('This week is best described as healthy growth:', Rich::toPlain($insight['headline']));
        $this->assertSame(
            ['Advertising', 'Website traffic', 'Where visitors came from', 'Countries', 'AI assistants', 'Search Console'],
            array_column($insight['paragraphs'], 'title'),
        );
        foreach ($insight['paragraphs'] as $paragraph) {
            $this->assertGreaterThanOrEqual(12, Rich::words($paragraph['text']), $paragraph['title'].' is more than a few numbers');
        }
        $this->assertNull($insight['caution']);   // the clean fixture raises nothing
    }

    public function test_every_paragraph_explains_meaning_and_not_only_numbers(): void
    {
        $payload = $this->fakePayload(FakeData::nandini(), ReportType::Monthly);
        $insight = $this->generator->generate($payload);

        // What each paragraph says beyond the numbers (a meaning or a thing to check), from the fixture:
        $meaning = [
            'Advertising' => 'became more efficient',
            'Website traffic' => 'showed interest in booking',
            'Where visitors came from' => 'The remaining',
            'Countries' => 'Ranked by engaged sessions',
            'AI assistants' => 'researching with AI assistants',
            'Search Console' => 'a lower number is better',
        ];
        foreach ($insight['paragraphs'] as $paragraph) {
            $this->assertStringContainsString($meaning[$paragraph['title']], Rich::toPlain($paragraph['text']), $paragraph['title']);
            // A paragraph is never only numbers: strip numbers and symbols and real words remain.
            $words = preg_replace('/[\d.,%+\-▲▼▬()]+/u', ' ', Rich::toPlain($paragraph['text']));
            $this->assertGreaterThanOrEqual(10, str_word_count((string) $words), $paragraph['title']);
        }
    }

    public function test_the_length_stays_within_the_weekly_and_monthly_limits(): void
    {
        $weekly = $this->generator->generate($this->fakePayload(FakeData::nandini(), ReportType::Weekly));
        $monthly = $this->generator->generate($this->fakePayload(FakeData::nandini(), ReportType::Monthly));

        $this->assertLessThanOrEqual(350, $this->words($weekly));
        $this->assertLessThanOrEqual(400, $this->words($monthly));
        $this->assertGreaterThan(150, $this->words($weekly), 'a full report is not squeezed');
    }

    public function test_when_it_is_too_long_the_least_important_optional_sentences_go_first_and_essential_ones_stay(): void
    {
        $payload = $this->fakePayload(FakeData::nandini(), ReportType::Weekly);
        $full = $this->generator->generate($payload);
        $fullText = $this->allText($full);
        $this->assertStringContainsString('The remaining', $fullText);

        config(['report.insight.max_words.weekly' => $this->words($full) - 25]);
        $shorter = $this->generator->generate($payload);

        $this->assertLessThanOrEqual($this->words($full) - 25, $this->words($shorter));
        $this->assertSame(array_column($full['paragraphs'], 'title'), array_column($shorter['paragraphs'], 'title'), 'no paragraph disappears');
        $this->assertLessThan(strlen($fullText), strlen($this->allText($shorter)));

        // A limit nothing can meet: only the essential sentences remain, and the headline is kept.
        config(['report.insight.max_words.weekly' => 10]);
        $minimal = $this->generator->generate($payload);
        $this->assertSame($full['headline'], $minimal['headline']);
        $this->assertStringContainsString('Paid impressions', $this->allText($minimal));            // essential
        $this->assertStringNotContainsString('became more efficient', $this->allText($minimal));    // optional
        $this->assertStringNotContainsString('showed interest in booking', $this->allText($minimal));
    }

    public function test_weekly_and_monthly_use_their_own_wording_and_monthly_compares_with_last_year(): void
    {
        $weekly = $this->generator->generate($this->fakePayload(FakeData::nandini(), ReportType::Weekly));
        $monthly = $this->generator->generate($this->fakePayload(FakeData::nandini(), ReportType::Monthly));

        $w = $this->allText($weekly);
        $m = $this->allText($monthly);

        $this->assertStringContainsString('This week', $w);
        $this->assertStringNotContainsString('This month', $w);
        $this->assertStringNotContainsString('last month', $w);
        $this->assertStringNotContainsString('Compared with October 2025', $w);

        $this->assertStringContainsString('This month', $m);
        $this->assertStringNotContainsString('This week', $m);
        $this->assertStringContainsString('Compared with October 2025, sessions are up ▲ +100.0%', $m);
    }

    public function test_the_priority_line_gives_the_one_or_two_most_useful_actions_from_the_rules_that_fired(): void
    {
        $payload = $this->payload('weekly', [
            'sessions' => $this->m(1250, 1000), 'engagementRate' => $this->m(0.45, 0.60), 'bookingEngineVisits' => $this->m(30, 40),
        ]);
        $payload['channels'] = [
            'total' => ['sessions' => $this->m(1250, 1000)], 'otherChannels' => [],
            'rows' => [$this->channel('Direct', 900, 500, 72.0, 50.0, engagement: 0.1, duration: 3.0), $this->channel('Organic Search', 350, 500, 28.0, 50.0)],
        ];

        $insight = $this->generator->generate($payload);

        // Three actions fired (bots, booking step, low-quality growth). The two most urgent are kept.
        $this->assertSame(
            'Priority: Check bot and referral filtering in GA4. Check the booking button and the pages most visitors land on.',
            Rich::toPlain($insight['priority']),
        );
    }

    public function test_one_action_gives_a_one_action_priority_and_no_action_gives_none(): void
    {
        $one = $this->payload('weekly', ['sessions' => $this->m(1250, 1000), 'bookingEngineVisits' => $this->m(30, 40)]);
        $this->assertSame('Priority: Check the booking button and the pages most visitors land on.', Rich::toPlain($this->generator->generate($one)['priority']));

        $none = $this->payload('weekly', ['sessions' => $this->m(1050, 1000)]);
        $this->assertNull($this->generator->generate($none)['priority']);
    }

    public function test_the_same_action_is_not_listed_twice(): void
    {
        $payload = $this->payload('weekly', ['sessions' => $this->m(1250, 1000), 'bookingEngineVisits' => $this->m(30, 40)]);
        $payload['paid'] = $this->paid(['cost' => $this->m(6000000, 5000000), 'purchases' => $this->m(0, 0), 'impressions' => $this->m(60000, 40000), 'clicks' => $this->m(700, 900), 'ctr' => $this->m(0.0117, 0.0225)]);

        $priority = Rich::toPlain($this->generator->generate($payload)['priority']);

        $this->assertSame(1, substr_count($priority, 'Priority:'));
        $this->assertLessThanOrEqual(2, substr_count($priority, '.'));
    }

    public function test_a_collapse_of_key_events_versus_last_year_is_a_caution_not_a_fact(): void
    {
        $payload = $this->fakePayload(FakeData::nandini(), ReportType::Monthly);
        $payload['site']['keyEvents'] = Compare::metric(['current' => 732.0, 'previous' => 764.0, 'yearAgo' => 7102.0]);

        $insight = $this->generator->generate($payload);

        $this->assertSame(
            'Key events changed by -89.7% versus October 2025. A change this large often comes from a change in how events are tracked, '
            .'so please check the tracking setup before drawing conclusions.',
            $insight['caution'],
        );
        $this->assertStringNotContainsString('89.7', Rich::toPlain($insight['headline'].' '.implode(' ', array_column($insight['paragraphs'], 'text'))));
        $this->assertDoesNotMatchRegularExpression('/collaps|surg|plung|crash/i', $this->allText($insight));
        $this->assertSame('healthy growth', $insight['verdict']);   // key events are not part of the verdict
    }

    public function test_guard_notes_and_data_quality_alerts_become_one_neutral_caution_paragraph(): void
    {
        $payload = $this->fakePayload(FakeData::nandini(), ReportType::Weekly);
        $payload['site']['keyEvents'] = Compare::metric(['current' => 100.0, 'previous' => 700.0]);
        $payload['quality_alerts'] = [
            ['code' => 'country_sum_mismatch', 'message' => 'x', 'details' => []],
            ['code' => 'direct_low_engagement', 'message' => 'x', 'details' => []],
            ['code' => 'currency_mismatch', 'message' => 'x', 'details' => ['ga4Currency' => 'USD']],
        ];

        $caution = $this->generator->generate($payload)['caution'];

        $this->assertStringStartsWith('Key events changed by -85.7% versus the previous week.', $caution);
        $this->assertStringContainsString('Data note: some sessions are counted in more than one country', $caution);
        $this->assertStringContainsString('Direct traffic has very short sessions', $caution);
        $this->assertStringContainsString('reports money in USD', $caution);
        $this->assertDoesNotMatchRegularExpression('/warning|alarm|critical|problem|!/i', $caution);
        $this->assertSame(1, substr_count($caution, 'Data note:'));
    }

    public function test_the_guard_state_does_not_leak_between_reports(): void
    {
        $guarded = $this->fakePayload(FakeData::nandini(), ReportType::Weekly);
        $guarded['site']['keyEvents'] = Compare::metric(['current' => 100.0, 'previous' => 700.0]);

        $this->assertNotNull($this->generator->generate($guarded)['caution']);
        $this->assertNull($this->generator->generate($this->fakePayload(FakeData::nandini(), ReportType::Weekly))['caution']);   // same instance
    }

    public function test_sections_that_failed_are_skipped_without_a_trace_of_their_error(): void
    {
        $payload = $this->fakePayload(FakeData::nandini(), ReportType::Weekly);
        $error = ['error' => ['type' => 'GoogleApiException', 'message' => 'INVALID_ARGUMENT secret detail']];
        foreach (['paid', 'channels', 'countries', 'country_performance', 'ai_traffic', 'trend'] as $key) {
            $payload[$key] = $error;
        }

        $insight = $this->generator->generate($payload);

        $this->assertSame(['Website traffic', 'Search Console'], array_column($insight['paragraphs'], 'title'));
        $this->assertStringNotContainsString('INVALID_ARGUMENT', $this->allText($insight));
        $this->assertSame('healthy growth', $insight['verdict']);
    }

    public function test_a_paragraph_without_data_is_left_out(): void
    {
        $kamandalu = $this->fakePayload(FakeData::kamandalu(), ReportType::Weekly);   // ads_source none: no paid section
        $this->assertNull($kamandalu['paid']);
        $this->assertNotContains('Advertising', array_column($this->generator->generate($kamandalu)['paragraphs'], 'title'));

        $payload = $this->fakePayload(FakeData::nandini(), ReportType::Weekly);
        $payload['ai_traffic']['total'] = $this->m(15, 10);
        $payload['search_console'] = ['hasData' => false, 'totals' => null];
        $titles = array_column($this->generator->generate($payload)['paragraphs'], 'title');

        $this->assertNotContains('AI assistants', $titles);      // below the 20 session minimum
        $this->assertNotContains('Search Console', $titles);
    }

    public function test_zero_and_missing_previous_values_never_cause_errors(): void
    {
        $payload = $this->fakePayload(FakeData::nandini(), ReportType::Monthly);
        $zero = fn (float $current) => Compare::metric(['current' => $current, 'previous' => 0.0, 'yearAgo' => null]);
        foreach (['sessions', 'totalUsers', 'keyEvents', 'bookingEngineVisits', 'purchases', 'purchaseRevenue'] as $key) {
            $payload['site'][$key] = $zero(100.0);
        }
        $payload['site']['keyEvents'] = $zero(0.0);   // nothing now and nothing before: no comparison at all
        $payload['site']['engagementRate'] = Compare::metric(['current' => 0.5, 'previous' => null, 'yearAgo' => null]);
        foreach ($payload['paid']['metrics'] as $key => $metric) {
            $payload['paid']['metrics'][$key] = Compare::metric(['current' => $metric['current'], 'previous' => 0.0, 'yearAgo' => 0.0]);
        }
        $payload['search_console']['totals']['clicks'] = Compare::metric(['current' => 5.0, 'previous' => 0.0, 'yearAgo' => null]);

        $insight = $this->generator->generate($payload);
        $text = $this->allText($insight);

        $this->assertStringNotContainsString('INF', $text);
        $this->assertStringNotContainsString('NAN', $text);
        $this->assertStringContainsString('new this period', $text);
        $this->assertStringContainsString('no comparison available', $text);
    }

    public function test_an_empty_payload_gives_a_neutral_headline_and_nothing_else(): void
    {
        $insight = $this->generator->generate(['meta' => ['type' => 'weekly']]);

        $this->assertSame('This week: traffic figures are not available for this report.', $insight['headline']);
        $this->assertNull($insight['verdict']);
        $this->assertSame([], $insight['paragraphs']);
        $this->assertNull($insight['priority']);
        $this->assertNull($insight['caution']);

        $this->assertSame('This week: traffic figures are not available for this report.', $this->generator->generate([])['headline']);
    }

    public function test_the_text_carries_bold_and_colored_markup_that_the_plain_rendering_removes(): void
    {
        $insight = $this->generator->generate($this->fakePayload(FakeData::nandini(), ReportType::Weekly));

        $this->assertStringContainsString('⟦pos|▲ +25.0%⟧', $insight['headline']);
        $this->assertStringContainsString('⟦b|', $insight['paragraphs'][1]['text']);
        $this->assertStringNotContainsString('⟦', Rich::toPlain($insight['headline']));
        $this->assertStringContainsString('▲ +25.0%', Rich::toPlain($insight['headline']));   // the sign and arrow survive without color
    }

    public function test_another_generator_can_replace_the_rule_based_one_with_one_binding(): void
    {
        $this->app->bind(InsightGenerator::class, fn () => new class implements InsightGenerator
        {
            public function generate(array $payload): array
            {
                return ['verdict' => null, 'headline' => 'Written by a different generator.', 'paragraphs' => [['title' => 'Custom', 'text' => 'Only numbers from the payload: '.$payload['site']['sessions']['current']]], 'priority' => null, 'caution' => null];
            }
        });

        $property = Property::create(['name' => 'Nandini', 'slug' => 'nandini', 'ga4_property_id' => FakeData::NANDINI_ID, 'gsc_site_url' => 'sc-domain:nandini.test', 'currency' => 'IDR', 'timezone' => 'Asia/Makassar']);
        $period = $this->period(ReportType::Weekly);
        $report = Report::create([
            'property_id' => $property->id, 'type' => 'weekly', 'period_start' => $period->startDate(), 'period_end' => $period->endDate(),
            'status' => 'pending', 'payload' => $this->fakePayload($property, ReportType::Weekly),
        ]);

        $html = (new ReportMail($report->load('property'), $property, ['trend' => null, 'channels' => null, 'countries' => null]))->render();

        $this->assertStringContainsString('Written by a different generator.', $html);
        $this->assertStringContainsString('Only numbers from the payload: 10000', $html);
        $this->assertStringNotContainsString('best described as', $html);
    }
}
