<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Contracts\InsightGenerator;
use App\Enums\ReportType;
use App\Mail\ReportMail;
use App\Models\Property;
use App\Models\Report;
use App\Services\Ga4GscReportBuilder;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * Renders a report email for browsing: no mail is sent, no Report or ReportDelivery row is created.
 *
 * The payload comes from, in order: the stored payload of the reports row for this property, type and period;
 * a cached copy in storage/app/reports/preview (unless $fresh); a real build through Ga4GscReportBuilder (which
 * calls Google), which is then cached. CID images are replaced by data URIs so the file opens anywhere.
 */
class ReportPreview
{
    public function __construct(
        private readonly Ga4GscReportBuilder $builder,
        private readonly ChartRenderer $charts,
        private readonly InsightGenerator $insights,
    ) {
    }

    /**
     * @return array{html: string, subject: string, source: string, period: ReportPeriod, payload: array<string, mixed>}
     */
    public function render(Property $property, ReportType $type, ?string $periodStart = null, bool $withCharts = true, bool $fresh = false): array
    {
        $tz = new DateTimeZone($property->timezone ?: 'UTC');
        $period = $periodStart !== null
            ? ReportPeriod::fromStart($type, $periodStart, $tz)
            : ReportPeriod::forType($type, CarbonImmutable::now(), $tz);

        [$payload, $source] = $this->payload($property, $period, $fresh);

        // --no-charts: render as if charts were switched off, so no "chart missing" note appears either.
        $enabled = config('report.charts.enabled');
        config(['report.charts.enabled' => $enabled && $withCharts]);
        try {
            $charts = $this->charts->render($property, $period, $payload);
            $view = ReportViewData::make($property, $period, $payload, $this->insights->generate($payload), $charts, ReportMail::logoPath($property));
        } finally {
            config(['report.charts.enabled' => $enabled]);
        }

        $html = view('emails.report', [
            'view' => $view,
            'charts' => $charts,
            'inline' => fn (string $path) => self::dataUri($path),
        ])->render();

        return ['html' => $html, 'subject' => $view['subject'], 'source' => $source, 'period' => $period, 'payload' => $payload];
    }

    private function previewDir(): string
    {
        return rtrim((string) config('report.reports_path'), '/\\').'/preview';
    }

    /** Where `report:preview` writes by default. */
    public function defaultPath(Property $property, ReportType $type, ReportPeriod $period): string
    {
        return $this->previewDir()."/{$property->slug}-{$type->value}-{$period->startDate()}.html";
    }

    /** @return array{0: array<string, mixed>, 1: string} payload and where it came from */
    public function payload(Property $property, ReportPeriod $period, bool $fresh): array
    {
        $stored = Report::query()
            ->where('property_id', $property->id)
            ->where('type', $period->type->value)
            ->where('period_start', $period->startDate())
            ->first();

        if ($stored !== null && is_array($stored->payload) && ! $fresh) {
            return [$stored->payload, 'stored report'];
        }

        $cache = $this->previewDir()."/{$property->slug}-{$period->type->value}-{$period->startDate()}.payload.json";
        if (! $fresh && is_file($cache)) {
            $cached = json_decode((string) file_get_contents($cache), true);
            if (is_array($cached)) {
                return [$cached, 'cached preview payload'];
            }
        }

        $payload = $this->builder->buildAt($property, $period, CarbonImmutable::now());

        if (! is_dir(dirname($cache))) {
            mkdir(dirname($cache), 0775, true);
        }
        file_put_contents($cache, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return [$payload, 'built now'];
    }

    private static function dataUri(string $path): string
    {
        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            default => 'image/png',
        };

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }
}
