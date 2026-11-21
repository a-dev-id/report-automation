<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Property extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'active',
        'domain',
        'ga4_property_id',
        'gsc_site_url',
        'booking_engine',
        'ads_source',
        'currency',
        'timezone',
        'language',
        'report_types',
        'brand_color',
        'logo_path',
        'notes',
    ];

    protected $casts = [
        'active' => 'boolean',
        'booking_engine' => 'array',
    ];

    public function recipients(): HasMany
    {
        return $this->hasMany(Recipient::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /** Properties that receive this type of report: their setting is the type itself or "both". */
    public function scopeForReportType(Builder $query, ReportType $type): Builder
    {
        return $query->where(function (Builder $q) use ($type) {
            $q->where('report_types', $type->value)->orWhere('report_types', 'both');
        });
    }
}
