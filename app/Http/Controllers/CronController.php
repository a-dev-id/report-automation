<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Jobs\BuildAndSendReport;
use App\Services\Reports\ReportTestSender;
use App\Models\Property;
use App\Models\Report;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Throwable;

/**
 * Endpoints called by the server crontab. Every route is behind the cron.token middleware.
 */
class CronController extends Controller
{
    /** POST /cron/weekly-report[?property=slug] */
    public function weeklyReport(Request $request): JsonResponse
    {
        return $this->queueReports($request, ReportType::Weekly);
    }

    /** POST /cron/monthly-report[?property=slug] */
    public function monthlyReport(Request $request): JsonResponse
    {
        return $this->queueReports($request, ReportType::Monthly);
    }

    /**
     * POST /cron/send-test: MANUAL USE, not in the crontab. Sends one "[TEST] " report email to the single address in
     * "to" and nobody else. Body: property (slug), type (weekly|monthly), period_start (optional, Y-m-d), to (one email).
     * No recipients table, no deliveries, no reports row. Returns {ok, subject, to}.
     */
    public function sendTest(Request $request, ReportTestSender $sender): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'property' => ['required', 'string', 'exists:properties,slug'],
            'type' => ['required', 'in:weekly,monthly'],
            'period_start' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['required', 'string', 'email'],
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'errors' => $validator->errors()], 422);
        }

        // Building a report can take a while when it has to call Google.
        @set_time_limit(180);

        try {
            $result = $sender->send(
                Property::query()->where('slug', $request->input('property'))->firstOrFail(),
                ReportType::from($request->input('type')),
                $request->input('period_start'),
                [(string) $request->input('to')],
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            Log::error('Test email failed.', ['exception' => $e]);

            return response()->json(['ok' => false, 'message' => 'Sending failed: '.class_basename($e)], 500);
        }

        return response()->json(['ok' => true, 'subject' => $result['subject'], 'to' => $result['to'][0]]);
    }

    /** POST /cron/queue-work: processes queued jobs for up to 55 seconds. Not needed if Supervisor runs a worker. */
    public function queueWork(): JsonResponse
    {
        // PHP-FPM kills web requests at max_execution_time (often 30s). The worker needs about a minute.
        @set_time_limit(120);

        $lock = Cache::lock('cron:queue-work', 120);
        if (! $lock->get()) {
            return response()->json(['status' => 'busy']);
        }

        try {
            $exitCode = Artisan::call('queue:work', [
                '--stop-when-empty' => true,
                '--max-time' => 55,
                '--tries' => 1,
            ]);

            return response()->json(['status' => 'ok', 'exit_code' => $exitCode]);
        } finally {
            $lock->release();
        }
    }

    /** POST /cron/retry-failed: queues reports that failed or only partly sent, within the attempt limit. */
    public function retryFailed(): JsonResponse
    {
        $reports = Report::query()
            ->whereIn('status', [ReportStatus::Failed->value, ReportStatus::Partial->value])
            ->where('build_attempts', '<', (int) config('report.max_attempts'))
            ->where('created_at', '>=', now()->subDays((int) config('report.retry_window_days')))
            ->get();

        foreach ($reports as $report) {
            BuildAndSendReport::dispatch($report->id);
        }

        return response()->json(['status' => 'ok', 'queued' => $reports->pluck('id')->all()], 202);
    }

    /** GET /cron/health: last sent report per active property, queue size, and an optional heartbeat ping. */
    public function health(): JsonResponse
    {
        $properties = Property::query()->active()->get()->map(function (Property $property) {
            $lastSent = fn (ReportType $type) => Report::query()
                ->where('property_id', $property->id)
                ->where('type', $type->value)
                ->where('status', ReportStatus::Sent->value)
                ->max('sent_at');

            return [
                'slug' => $property->slug,
                'last_weekly_sent_at' => $lastSent(ReportType::Weekly),
                'last_monthly_sent_at' => $lastSent(ReportType::Monthly),
            ];
        });

        $heartbeat = null;
        if ($url = config('report.heartbeat_url')) {
            try {
                $heartbeat = Http::timeout(5)->get($url)->successful() ? 'ok' : 'failed';
            } catch (Throwable $e) {
                $heartbeat = 'failed';
                Log::warning('Heartbeat ping failed.', ['exception' => $e]);
            }
        }

        return response()->json([
            'status' => 'ok',
            'queue_size' => $this->safely(fn () => Queue::size()),
            'failed_jobs' => $this->safely(fn () => DB::table('failed_jobs')->count()),
            'heartbeat' => $heartbeat,
            'properties' => $properties,
        ]);
    }

    /**
     * Finds the active properties for this report type and queues one job per property.
     * A property whose report for the period is already sent, in progress, or out of attempts is skipped.
     */
    private function queueReports(Request $request, ReportType $type): JsonResponse
    {
        $lock = Cache::lock('cron:reports:'.$type->value, 120);
        if (! $lock->get()) {
            return response()->json(['status' => 'busy'], 409);
        }

        try {
            $slug = $request->query('property');
            $slug = is_string($slug) && $slug !== '' ? $slug : null;

            $properties = Property::query()
                ->active()
                ->forReportType($type)
                ->when($slug !== null, fn ($query) => $query->where('slug', $slug))
                ->get();

            if ($properties->isEmpty()) {
                Log::warning('No active properties for this report run.', ['type' => $type->value, 'property' => $slug]);

                return response()->json(
                    ['type' => $type->value, 'queued' => 0, 'results' => []],
                    $slug !== null ? 404 : 200,
                );
            }

            $now = CarbonImmutable::now();
            $results = $properties
                ->map(fn (Property $property) => $this->queueForProperty($property, $type, $now))
                ->values()
                ->all();

            return response()->json([
                'type' => $type->value,
                'queued' => count(array_filter($results, fn (array $r) => $r['status'] === 'queued')),
                'results' => $results,
            ], 202);
        } finally {
            $lock->release();
        }
    }

    /** @return array{property: string, period: string, status: string} */
    private function queueForProperty(Property $property, ReportType $type, CarbonImmutable $now): array
    {
        $period = ReportPeriod::forType($type, $now, new DateTimeZone($property->timezone));

        $report = Report::query()->firstOrCreate(
            ['property_id' => $property->id, 'type' => $type->value, 'period_start' => $period->startDate()],
            ['period_end' => $period->endDate(), 'status' => ReportStatus::Pending->value],
        );

        $status = match (true) {
            $report->status === ReportStatus::Sent => 'skipped_already_sent',
            $report->status === ReportStatus::Building && $report->updated_at->gt($now->subMinutes(30)) => 'skipped_in_progress',
            $report->build_attempts >= (int) config('report.max_attempts') => 'skipped_max_attempts',
            default => 'queued',
        };

        if ($status === 'queued') {
            BuildAndSendReport::dispatch($report->id);
        }

        return ['property' => $property->slug, 'period' => $period->label(), 'status' => $status];
    }

    private function safely(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }
}
