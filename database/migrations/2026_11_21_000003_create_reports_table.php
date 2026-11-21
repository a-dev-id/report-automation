<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            // weekly or monthly.
            $table->string('type', 16);
            // Weekly: the Monday. Monthly: the 1st. Stored as plain Y-m-d.
            $table->date('period_start');
            $table->date('period_end');
            // pending, building, sent, partial, failed.
            $table->string('status', 16)->default('pending');
            $table->unsignedTinyInteger('build_attempts')->default(0);
            // The numbers behind the email. Kept so a retry does not call GA4 again.
            $table->json('payload')->nullable();
            $table->timestamp('built_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            // One report per hotel, type and period: a rerun can never send the same report twice.
            $table->unique(['property_id', 'type', 'period_start']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
