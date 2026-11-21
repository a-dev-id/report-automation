<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Support\ReportPeriod;
use DateTimeZone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Report extends Model
{
    protected $fillable = [
        'property_id',
        'type',
        'period_start',
        'period_end',
        'status',
        'build_attempts',
        'payload',
        'built_at',
        'sent_at',
        'error',
    ];

    // period_start and period_end are deliberately not cast to dates: they are plain Y-m-d strings,
    // so equality lookups behave the same on MySQL and SQLite.
    protected $casts = [
        'type' => ReportType::class,
        'status' => ReportStatus::class,
        'payload' => 'array',
        'built_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(ReportDelivery::class);
    }

    /** The period this report covers, rebuilt in the property's timezone. */
    public function period(): ReportPeriod
    {
        return ReportPeriod::fromStart(
            $this->type,
            substr((string) $this->period_start, 0, 10),
            new DateTimeZone($this->property->timezone),
        );
    }
}
