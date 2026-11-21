<?php

namespace Tests\Feature;

use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Jobs\BuildAndSendReport;
use App\Models\Property;
use App\Models\Report;
use App\Support\ReportPeriod;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CronRoutesTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = str_repeat('a', 48);
        config(['report.cron_token' => $this->token]);
    }

    private function cronPost(string $uri): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($uri, [], ['X-Cron-Token' => $this->token]);
    }

    private function hotel(string $slug, array $overrides = []): Property
    {
        return Property::create(array_merge([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'active' => true,
            'timezone' => 'Asia/Makassar',
            'report_types' => 'both',
        ], $overrides));
    }

    public function test_a_wrong_token_gets_a_404(): void
    {
        $this->postJson('/cron/weekly-report', [], ['X-Cron-Token' => 'wrong'])->assertNotFound();
        $this->postJson('/cron/weekly-report')->assertNotFound();
    }

    public function test_an_unconfigured_token_refuses_every_request(): void
    {
        config(['report.cron_token' => '']);

        $this->postJson('/cron/weekly-report', [], ['X-Cron-Token' => ''])->assertNotFound();
    }

    public function test_weekly_route_queues_one_job_per_active_property(): void
    {
        Queue::fake();
        $this->hotel('nandini');
        $this->hotel('kamandalu');
        $this->hotel('dormant', ['active' => false]);
        $this->hotel('monthly-only', ['report_types' => 'monthly']);

        $this->cronPost('/cron/weekly-report')
            ->assertStatus(202)
            ->assertJsonPath('queued', 2);

        Queue::assertPushed(BuildAndSendReport::class, 2);
        $this->assertSame(2, Report::count());
    }

    public function test_a_report_that_was_already_sent_is_skipped(): void
    {
        Queue::fake();
        $property = $this->hotel('nandini');

        $period = ReportPeriod::forType(ReportType::Weekly, now(), new DateTimeZone($property->timezone));
        Report::create([
            'property_id' => $property->id,
            'type' => ReportType::Weekly->value,
            'period_start' => $period->startDate(),
            'period_end' => $period->endDate(),
            'status' => ReportStatus::Sent->value,
        ]);

        $this->cronPost('/cron/weekly-report')
            ->assertStatus(202)
            ->assertJsonPath('queued', 0)
            ->assertJsonPath('results.0.status', 'skipped_already_sent');

        Queue::assertNothingPushed();
    }

    public function test_the_property_parameter_runs_a_single_hotel(): void
    {
        Queue::fake();
        $this->hotel('nandini');
        $this->hotel('kamandalu');

        $this->cronPost('/cron/monthly-report?property=kamandalu')
            ->assertStatus(202)
            ->assertJsonPath('queued', 1)
            ->assertJsonPath('results.0.property', 'kamandalu');

        $this->cronPost('/cron/monthly-report?property=unknown')->assertNotFound();
    }

    public function test_retry_route_requeues_failed_reports_within_the_attempt_limit(): void
    {
        Queue::fake();
        $property = $this->hotel('nandini');

        $make = fn (string $start, ReportStatus $status, int $attempts) => Report::create([
            'property_id' => $property->id,
            'type' => ReportType::Weekly->value,
            'period_start' => $start,
            'period_end' => $start,
            'status' => $status->value,
            'build_attempts' => $attempts,
        ]);

        $retry = $make('2026-11-02', ReportStatus::Failed, 1);
        $make('2026-11-09', ReportStatus::Failed, 3);   // out of attempts
        $make('2026-11-16', ReportStatus::Sent, 1);     // finished

        $this->cronPost('/cron/retry-failed')->assertStatus(202)->assertJsonPath('queued', [$retry->id]);

        Queue::assertPushed(BuildAndSendReport::class, 1);
    }
}
