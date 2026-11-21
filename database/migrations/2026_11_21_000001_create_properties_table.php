<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            // Only active properties are included in cron runs.
            $table->boolean('active')->default(false);
            $table->string('domain')->nullable();
            $table->string('ga4_property_id')->nullable();
            // Exact Search Console form: "sc-domain:example.com" or "https://example.com/".
            $table->string('gsc_site_url')->nullable();
            // How this hotel's bookings are tracked in GA4: {"type": "hostname|event|path", "value": "..."}.
            $table->json('booking_engine')->nullable();
            // ga4 (GA4 linked Ads), google_ads_api or none.
            $table->string('ads_source')->default('ga4');
            $table->string('currency', 3)->default('IDR');
            $table->string('timezone')->default('Asia/Makassar');
            $table->string('language', 5)->default('en');
            // weekly, monthly or both.
            $table->string('report_types')->default('both');
            $table->string('brand_color', 7)->nullable();
            $table->string('logo_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
