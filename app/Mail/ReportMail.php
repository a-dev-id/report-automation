<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Property;
use App\Models\Report;
use App\Services\Reports\ChartRenderer;
use App\Services\Reports\InsightGenerator;
use App\Services\Reports\ReportViewData;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The report email: an HTML version with inline CID images (charts, logo) and a plain-text version, both
 * rendered from the same ReportViewData.
 *
 * $charts are PNG paths from ChartRenderer. The job renders them once and passes them in so every recipient
 * gets the same images. Without them (null) they are rendered here, and a failed chart is just a fallback table.
 *
 * $subjectPrefix is "" for a real report and "[TEST] " for report:send-test.
 */
class ReportMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** @param  array<string, string|null>|null  $charts */
    public function __construct(public Report $report, public Property $property, public ?array $charts = null, public string $subjectPrefix = '')
    {
    }

    public function envelope(): Envelope
    {
        // Example: "Nandini Weekly Traffic Report: 9 to 15 Nov 2026"
        return new Envelope(subject: $this->subjectPrefix.ReportViewData::subject($this->property, $this->report->period()));
    }

    public function content(): Content
    {
        $period = $this->report->period();
        $payload = $this->report->payload ?? [];
        $charts = $this->charts ?? app(ChartRenderer::class)->render($this->property, $period, $payload);

        $view = ReportViewData::make(
            $this->property,
            $period,
            $payload,
            app(InsightGenerator::class)->generate($payload),
            $charts,
            self::logoPath($this->property),
        );

        return new Content(
            view: 'emails.report',
            text: 'emails.report-text',
            with: ['view' => $view, 'charts' => $charts],
        );
    }

    /** Absolute path of the property's logo if it is a file on the public disk, else null (no logo). */
    public static function logoPath(Property $property): ?string
    {
        $logo = (string) $property->logo_path;
        if ($logo === '') {
            return null;
        }

        try {
            $disk = Storage::disk('public');

            return $disk->exists($logo) ? $disk->path($logo) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
