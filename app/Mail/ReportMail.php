<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Property;
use App\Models\Report;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReportMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Report $report, public Property $property)
    {
    }

    public function envelope(): Envelope
    {
        // Example: "Nandini: Weekly Traffic Report, 9 to 15 Nov 2026"
        return new Envelope(
            subject: $this->property->name.': '.$this->report->type->title().', '.$this->report->period()->label(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.report',
            with: [
                'property' => $this->property,
                'report' => $this->report,
                'period' => $this->report->period(),
                'payload' => $this->report->payload ?? [],
            ],
        );
    }
}
