<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recipient extends Model
{
    protected $fillable = ['property_id', 'email', 'name', 'report_type', 'active'];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /** Recipients who want this type of report: their choice is the type itself or "both". */
    public function scopeForReportType(Builder $query, ReportType $type): Builder
    {
        return $query->where(function (Builder $q) use ($type) {
            $q->where('report_type', $type->value)->orWhere('report_type', 'both');
        });
    }
}
