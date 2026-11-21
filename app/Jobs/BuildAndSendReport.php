<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\ReportBuilder;
use App\Enums\DeliveryStatus;
use App\Enums\ReportStatus;
use App\Mail\ReportMail;
use App\Models\Recipient;
use App\Services\Reports\ChartRenderer;
use App\Models\Report;
use App\Models\ReportDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Builds one report for one property and period, then emails it to that property's recipients.
 *
 * The job can be run again for the same report. It claims the report atomically, reuses the stored
 * payload instead of calling GA4 again, and skips recipients who already received the email.
 */
class BuildAndSendReport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Failures are recorded on the report and retried by /cron/retry-failed, not by the queue. */
    public int $tries = 1;

    public int $timeout = 600;

    /** Seconds before the unique lock expires if a worker dies mid-job. */
    public int $uniqueFor = 900;

    public function __construct(public readonly int $reportId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->reportId;
    }

    public function handle(ReportBuilder $builder): void
    {
        if (! $this->claim()) {
            Log::info('Report skipped: it is already being built or is finished.', ['report_id' => $this->reportId]);

            return;
        }

        $report = Report::query()->with('property')->findOrFail($this->reportId);

        try {
            if ($report->payload === null) {
                $payload = $builder->build($report->property, $report->period());
                $report->forceFill(['payload' => $payload, 'built_at' => now()])->save();
            }

            $this->deliver($report);
        } catch (Throwable $e) {
            $report->forceFill([
                'status' => ReportStatus::Failed->value,
                'error' => Str::limit($e->getMessage(), 2000),
            ])->save();

            Log::error('Report failed.', [
                'report_id' => $report->id,
                'property' => $report->property->slug,
                'exception' => $e,
            ]);
        }
    }

    /** Moves the report to "building". Returns false if another worker got there first or it is done. */
    private function claim(): bool
    {
        $claimable = [ReportStatus::Pending->value, ReportStatus::Failed->value, ReportStatus::Partial->value];

        return Report::query()
            ->whereKey($this->reportId)
            ->whereIn('status', $claimable)
            ->update([
                'status' => ReportStatus::Building->value,
                'error' => null,
                'build_attempts' => DB::raw('build_attempts + 1'),
                'updated_at' => now(),
            ]) === 1;
    }

    private function deliver(Report $report): void
    {
        $recipients = Recipient::query()
            ->where('property_id', $report->property_id)
            ->active()
            ->forReportType($report->type)
            ->get();

        if ($recipients->isEmpty()) {
            $report->forceFill([
                'status' => ReportStatus::Failed->value,
                'error' => 'No active recipients for this property and report type.',
            ])->save();

            return;
        }

        $maxAttempts = (int) config('report.max_attempts');

        // Charts are rendered once and shared by every recipient. A chart that fails is null (a table is shown instead);
        // it can never fail the report.
        $charts = app(ChartRenderer::class)->render($report->property, $report->period(), $report->payload ?? []);

        foreach ($recipients as $recipient) {
            $delivery = ReportDelivery::query()->firstOrCreate(
                ['report_id' => $report->id, 'recipient_id' => $recipient->id],
                ['status' => DeliveryStatus::Pending->value],
            );

            if ($delivery->status === DeliveryStatus::Sent || $delivery->attempts >= $maxAttempts) {
                continue;
            }

            try {
                Mail::to($recipient->email)->send(new ReportMail($report, $report->property, $charts));

                $delivery->forceFill([
                    'status' => DeliveryStatus::Sent->value,
                    'attempts' => $delivery->attempts + 1,
                    'last_error' => null,
                    'sent_at' => now(),
                ])->save();
            } catch (Throwable $e) {
                $delivery->forceFill([
                    'status' => DeliveryStatus::Failed->value,
                    'attempts' => $delivery->attempts + 1,
                    'last_error' => Str::limit($e->getMessage(), 2000),
                ])->save();

                Log::warning('Report email failed.', [
                    'report_id' => $report->id,
                    'recipient_id' => $recipient->id,
                    'exception' => $e,
                ]);
            }
        }

        $sent = ReportDelivery::query()
            ->where('report_id', $report->id)
            ->whereIn('recipient_id', $recipients->pluck('id'))
            ->where('status', DeliveryStatus::Sent->value)
            ->count();

        if ($sent === $recipients->count()) {
            $report->forceFill(['status' => ReportStatus::Sent->value, 'sent_at' => now(), 'error' => null])->save();
        } elseif ($sent > 0) {
            $report->forceFill([
                'status' => ReportStatus::Partial->value,
                'error' => ($recipients->count() - $sent).' of '.$recipients->count().' emails failed.',
            ])->save();
        } else {
            $report->forceFill(['status' => ReportStatus::Failed->value, 'error' => 'All emails failed.'])->save();
        }
    }
}
