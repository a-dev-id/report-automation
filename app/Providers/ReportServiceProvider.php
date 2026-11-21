<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Ga4Client;
use App\Contracts\ReportBuilder;
use App\Contracts\SearchConsoleClient;
use App\Services\Ga4GscReportBuilder;
use App\Services\Google\Ga4ApiClient;
use App\Services\Google\Retrier;
use App\Services\Google\SearchConsoleApiClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class ReportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The clients are created lazily: nothing reads the credentials file until a report runs.
        $this->app->singleton(Ga4Client::class, fn () => new Ga4ApiClient($this->credentialsPath(), $this->retrier()));
        $this->app->singleton(SearchConsoleClient::class, fn () => new SearchConsoleApiClient($this->credentialsPath(), $this->retrier()));

        // PlaceholderReportBuilder is kept in app/Services but no longer bound.
        $this->app->bind(ReportBuilder::class, Ga4GscReportBuilder::class);
    }

    public function boot(): void
    {
        // The crontab makes roughly 2 calls a minute. 60 a minute leaves room for manual runs
        // and caps guessing of the token.
        RateLimiter::for('cron', fn (Request $request) => Limit::perMinute(60)->by((string) $request->ip()));
    }

    private function credentialsPath(): string
    {
        return (string) config('report.google.credentials_path');
    }

    private function retrier(): Retrier
    {
        return new Retrier(
            (int) config('report.google.retry.max_attempts'),
            (int) config('report.google.retry.base_delay_ms'),
        );
    }
}
