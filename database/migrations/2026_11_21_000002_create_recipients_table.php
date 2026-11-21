<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            // weekly, monthly or both.
            $table->string('report_type')->default('both');
            $table->boolean('active')->default(true);
            $table->timestamps();

            // The same person can receive several hotels, but only once per hotel.
            $table->unique(['property_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipients');
    }
};
