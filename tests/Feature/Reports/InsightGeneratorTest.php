<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\ReportType;
use App\Services\Reports\InsightGenerator;
use App\Support\Compare;
use Tests\Concerns\BuildsFakePayloads;
use Tests\Fakes\FakeData;
use Tests\TestCase;

class InsightGeneratorTest extends TestCase
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

    public function test_weekly_insight_from_a_realistic_payload(): void
    {
        $insight = $this->insight($this->fakePayload(FakeData::nandini(), ReportType::Weekly));

        $this->assertSame(
            'This week, sessions rose 25.0% to 10,000 and key events rose 16.7% to 350, compared with the previous week.',
            $insight['headline'],
        );
        $this->assertCount(5, $insight['bullets']);
        $this->assertNull($insight['caution']);

        [$channels, $booking, $country, $purchases, $ai] = $insight['bullets'];

        $this->assertSame('Channels: Organic Search grew the most (+800 sessions, +25.0%) vs the previous week; no channel fell.', $channels);
        $this->assertStringContainsString('Booking engine: 900 sessions reached it (9.0% of all sessions), up 50.0% from 600 in the previous week.', $booking);
        $this->assertStringContainsString('The booking-engine rate was 7.5% in the previous week.', $booking);
        $this->assertStringContainsString('Purchases: 12 purchases worth IDR 48,000,000, up 20.0% from 10 in the previous week.', $purchases);
        $this->assertStringContainsString('AI assistants referred 175 sessions (mostly chatgpt.com), up 65.1% from 106 in the previous week.', $ai);

        // The country is picked on quality, not volume: Indonesia has the most sessions, Australia the best booking rate.
        $this->assertStringStartsWith('Best traffic quality: Australia', $country);
        $this->assertStringContainsString('4.4% of its 1,800 sessions reached the booking engine', $country);
        $this->assertStringContainsString('4 purchasers', $country);
        $this->assertStringContainsString('The largest source of sessions was Indonesia, but it ranks lower on quality.', $country);
    }

    public function test_monthly_insight_uses_month_wording_and_year_ago(): void
    {
        $insight = $this->insight($this->fakePayload(FakeData::nandini(), ReportType::Monthly));

        $this->assertStringStartsWith('This month, sessions rose 25.0% to 10,000', $insight['headline']);
        $this->assertStringContainsString('compared with last month.', $insight['headline']);
        $this->assertStringContainsString('Against the same month last year, sessions are up 100.0% and key events are up 75.0%.', $insight['headline']);
        $this->assertStringContainsString('vs last month', $insight['bullets'][0]);
        $this->assertStringNotContainsString('week', implode(' ', $insight['bullets']).$insight['headline']);
    }

    public function test_a_hotel_with_a_different_currency_is_formatted_with_its_own(): void
    {
        $payload = $this->fakePayload(FakeData::kamandalu(), ReportType::Weekly);

        $purchases = array_values(array_filter($this->insight($payload)['bullets'], fn ($b) => str_starts_with($b, 'Purchases')))[0];
        $this->assertStringContainsString('USD 96,000,000.00', $purchases);   // USD keeps two decimals
    }

    public function test_a_zero_previous_value_never_divides_and_says_so(): void
    {
        $payload = $this->fakePayload(FakeData::nandini(), ReportType::Weekly);
        $payload['site']['sessions'] = Compare::metric(['current' => 10000.0, 'previous' => 0.0]);
        $payload['site']['keyEvents'] = Compare::metric(['current' => 0.0, 'previous' => 0.0]);
        $payload['site']['bookingEngineVisits'] = Compare::metric(['current' => 900.0, 'previous' => 0.0]);
        $payload['site']['purchases'] = Compare::metric(['current' => 0.0, 'previous' => 10.0]);
        $payload['ai_traffic']['total'] = Compare::metric(['current' => 40.0, 'previous' => 0.0]);

        $insight = $this->insight($payload);

        $this->assertStringContainsString('sessions reached 10,000 (none before)', $insight['headline']);
        $this->assertStringContainsString('key events were 0 (no comparison available)', $insight['headline']);
        $text = implode("\n", $insight['bullets']);
        $this->assertStringContainsString('none in the previous week, so no comparison is available', $text);
        $this->assertStringContainsString('Purchases: none recorded this week, compared with 10 in the previous week.', $text);
        $this->assertStringNotContainsString('INF', $text.$insight['headline']);
        $this->assertStringNotContainsString('NAN', $text.$insight['headline']);
    }

    public function test_zero_sessions_do_not_break_the_booking_rate(): void
    {
        $payload = $this->fakePayload(FakeData::nandini(), ReportType::Weekly);
        $payload['site']['sessions'] = Compare::metric(['current' => 0.0, 'previous' => 0.0]);

        $insight = $this->insight($payload);

        $this->assertStringContainsString('sessions were 0 (no comparison available)', $insight['headline']);
        $booking = array_values(array_filter($insight['bullets'], fn ($b) => str_starts_with($b, 'Booking engine')))[0];
        $this->assertStringNotContainsString('of all sessions', $booking);
    }

    public function test_error_sections_are_skipped_without_failing(): void
    {
        $payload = $this->fakePayload(FakeData::nandini(), ReportType::Weekly);
        $error = ['error' => ['type' => 'GoogleApiException', 'message' => 'boom']];
        foreach (['channels', 'country_performance', 'ai_traffic', 'paid', 'trend'] as $key) {
            $payload[$key] = $error;
        }

        $insight = $this->insight($payload);

        $text = implode("\n", $insight['bullets']);
        $this->assertStringNotContainsString('Channels:', $text);
        $this->assertStringNotContainsString('Best traffic quality', $text);
        $this->assertStringNotContainsString('AI assistants', $text);
        $this->assertStringNotContainsString('boom', $text);
        $this->assertStringContainsString('Booking engine', $text);
        $this->assertGreaterThanOrEqual(3, count($insight['bullets']));   // engagement and users fill in
        $this->assertStringStartsWith('This week, sessions rose', $insight['headline']);
    }

    public function test_an_empty_payload_gives_a_neutral_headline(): void
    {
        $insight = $this->insight(['meta' => ['type' => 'monthly']]);

        $this->assertSame('This month: traffic figures are not available for this report.', $insight['headline']);
        $this->assertSame([], $insight['bullets']);
        $this->assertNull($insight['caution']);

        $this->assertSame('This week: traffic figures are not available for this report.', $this->insight([])['headline']);
    }

    public function test_site_totals_in_error_do_not_crash(): void
    {
        $insight = $this->insight(['meta' => ['type' => 'weekly'], 'site' => ['error' => ['message' => 'x']]]);

        $this->assertStringContainsString('not available', $insight['headline']);
    }

    public function test_quality_alerts_become_one_neutral_caution_line(): void
    {
        $payload = $this->fakePayload(FakeData::nandini(), ReportType::Weekly);
        $payload['quality_alerts'] = [
            ['code' => 'country_sum_mismatch', 'message' => 'x', 'details' => []],
            ['code' => 'direct_low_engagement', 'message' => 'x', 'details' => []],
            ['code' => 'currency_mismatch', 'message' => 'x', 'details' => ['ga4Currency' => 'USD', 'propertyCurrency' => 'IDR']],
            ['code' => 'something_new', 'message' => 'A new kind of note.', 'details' => []],
        ];

        $insight = $this->insight($payload);

        $caution = $insight['caution'];
        $this->assertStringStartsWith('Data note:', $caution);
        $this->assertStringContainsString('add up to more than the site total', $caution);
        $this->assertStringContainsString('Direct traffic has very short sessions', $caution);
        $this->assertStringContainsString('reports money in USD', $caution);
        $this->assertStringContainsString('a new kind of note', $caution);
        $this->assertStringContainsString('read these figures with care', $caution);
        $this->assertSame(1, substr_count($caution, 'Data note:'));   // one line, not one per alert
        $this->assertDoesNotMatchRegularExpression('/warning|alert|error|problem|critical|!/i', $caution);

        // The data itself is untouched.
        $this->assertSame($this->insight($this->fakePayload(FakeData::nandini(), ReportType::Weekly))['headline'], $insight['headline']);
    }

    public function test_thresholds_come_from_config(): void
    {
        $payload = $this->fakePayload(FakeData::nandini(), ReportType::Weekly);

        config(['report.insights.min_channel_sessions' => 100000]);
        $this->assertStringNotContainsString('Channels:', implode("\n", $this->insight($payload)['bullets']));

        config(['report.insights.min_channel_sessions' => 50, 'report.insights.min_country_sessions' => 100000]);
        $this->assertStringNotContainsString('Best traffic quality', implode("\n", $this->insight($payload)['bullets']));

        config(['report.insights.min_country_sessions' => 100, 'report.insights.max_bullets' => 3]);
        $this->assertCount(3, $this->insight($payload)['bullets']);
    }

    public function test_the_best_and_worst_channel_movements_are_both_named(): void
    {
        $payload = $this->fakePayload(FakeData::nandini(), ReportType::Weekly);
        $payload['channels']['rows'][1]['sessions'] = Compare::metric(['current' => 1000.0, 'previous' => 2000.0]);   // Direct fell 1000

        $channels = $this->insight($payload)['bullets'][0];

        $this->assertSame('Channels: Organic Search grew the most (+800 sessions, +25.0%), while Direct fell the most (-1,000 sessions, -50.0%) vs the previous week.', $channels);
    }

    public function test_countries_flagged_as_bots_or_with_little_data_are_not_the_best_quality(): void
    {
        $payload = $this->fakePayload(FakeData::nandini(), ReportType::Weekly);
        foreach ($payload['country_performance'] as &$row) {
            if ($row['country'] !== 'Germany') {
                $row['checkForBots'] = true;   // everyone except Germany is excluded
            }
        }
        unset($row);
        $payload['country_performance'][4]['bookingEngineVisits'] = 50.0;   // Germany: flagged bot in the fixture, still has visits
        $payload['country_performance'][4]['checkForBots'] = false;

        $country = array_values(array_filter($this->insight($payload)['bullets'], fn ($b) => str_starts_with($b, 'Best traffic quality')))[0];

        $this->assertStringStartsWith('Best traffic quality: Germany', $country);
    }
}
