<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportDelivery extends Model
{
    protected $fillable = ['report_id', 'recipient_id', 'status', 'attempts', 'last_error', 'sent_at'];

    protected $casts = [
        'status' => DeliveryStatus::class,
        'sent_at' => 'datetime',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class);
    }
}
