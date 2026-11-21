# Weekly and monthly traffic report: Laravel code

First slice of the project tracked in the Google Sheet "Weekly Traffic Report Automation Tracker".
It contains the foundation (properties, recipients, reports), the secured cron routes, and the
queued job that builds and emails a report. The GA4 and Search Console services are the next slice,
so the report builder is a placeholder that always fails. Nothing can be emailed by accident.

The files mirror a Laravel 11 or 12 project. Copy them into your project, keeping the folder names.

## Install

1. **Copy** `app/`, `config/`, `database/`, `resources/`, `routes/` and `tests/` into your Laravel project.

2. **Register the provider** in `bootstrap/providers.php`:

   ```php
   return [
       App\Providers\AppServiceProvider::class,
       App\Providers\ReportServiceProvider::class,
   ];
   ```

3. **Load the cron routes** in `bootstrap/app.php`. The throttle runs before the token check, so
   guessing the token is rate limited:

   ```php
   use Illuminate\Support\Facades\Route;

   return Application::configure(basePath: dirname(__DIR__))
       ->withRouting(
           web: __DIR__.'/../routes/web.php',
           commands: __DIR__.'/../routes/console.php',
           health: '/up',
           then: function () {
               Route::middleware(['throttle:cron', 'cron.token'])
                   ->prefix('cron')
                   ->group(base_path('routes/cron.php'));
           },
       )
       ->withMiddleware(function (Middleware $middleware) {
           $middleware->alias([
               'cron.token' => \App\Http\Middleware\VerifyCronToken::class,
           ]);
       })
       // ...
   ```

   These routes are outside the `web` group, so there is no session and no CSRF check.

4. **Set `.env`:**

   ```
   CRON_TOKEN=<64 hex characters>        # php -r "echo bin2hex(random_bytes(32));"
   QUEUE_CONNECTION=database
   CACHE_STORE=database                  # locks need database, redis, file or memcached
   APP_TIMEZONE=Asia/Makassar
   GOOGLE_APPLICATION_CREDENTIALS=/path/outside/public/service-account.json
   # REPORT_HEARTBEAT_URL=https://hc-ping.com/your-uuid   (optional)
   ```

5. **Migrate and seed:**

   ```
   php artisan migrate
   php artisan db:seed --class=PropertySeeder
   ```

   Nandini and Kamandalu are created inactive. Fill in each hotel's `ga4_property_id`, `gsc_site_url`
   and `booking_engine`, then set `active` to true.

## Routes

| Route | Method | Purpose |
|---|---|---|
| `/cron/weekly-report` | POST | Queue one weekly report job per active property |
| `/cron/monthly-report` | POST | Queue one monthly report job per active property |
| `/cron/queue-work` | POST | Process the queue for up to 55 seconds (skip if Supervisor runs a worker) |
| `/cron/retry-failed` | POST | Re-queue failed or partly sent reports within the attempt limit |
| `/cron/health` | GET | Last sent report per property, queue size, optional heartbeat ping |

Add `?property=slug` to the two report routes to run a single hotel. Every request needs the
`X-Cron-Token` header. A wrong or missing token gets a 404.

## Crontab

Replace `YOUR_CRON_TOKEN` and `YOUR_DOMAIN`. One set of lines serves every hotel.

```
0 8 * * 1    curl -fsS -m 30 -X POST -H "X-Cron-Token: YOUR_CRON_TOKEN" https://YOUR_DOMAIN/cron/weekly-report  >> /var/log/report-cron.log 2>&1
0 8 3 * *    curl -fsS -m 30 -X POST -H "X-Cron-Token: YOUR_CRON_TOKEN" https://YOUR_DOMAIN/cron/monthly-report >> /var/log/report-cron.log 2>&1
* * * * *    curl -fsS -m 65 -X POST -H "X-Cron-Token: YOUR_CRON_TOKEN" https://YOUR_DOMAIN/cron/queue-work     >> /var/log/report-cron.log 2>&1
0 10 * * *   curl -fsS -m 30 -X POST -H "X-Cron-Token: YOUR_CRON_TOKEN" https://YOUR_DOMAIN/cron/retry-failed    >> /var/log/report-cron.log 2>&1
*/15 * * * * curl -fsS -m 15        -H "X-Cron-Token: YOUR_CRON_TOKEN" https://YOUR_DOMAIN/cron/health          >> /var/log/report-cron.log 2>&1
```

The times assume the server timezone is Asia/Makassar.

## Notes

- **Queue worker and PHP-FPM.** `/cron/queue-work` runs a worker inside a web request, which PHP-FPM may
  stop at `max_execution_time`. The controller raises the limit with `set_time_limit(120)`, but some
  hosts ignore it. If you can run Supervisor, use a real worker and drop that crontab line.
- **Search Console delay.** Search Console data is 2 to 3 days behind. The monthly run on the 3rd is
  complete. A weekly run on Monday is missing Saturday and Sunday for Search Console. `ReportPeriod::searchConsoleWindow()`
  returns the usable range and cuts the comparison week by the same number of days. Either show a
  "data through Friday" note, or move the weekly cron to Wednesday (`0 8 * * 3`).
- **Idempotency.** `reports` has a unique key on property, type and period start, and the job claims a
  report atomically, so a double cron call cannot send the same report twice.
- **Retries.** A failed build or email is recorded on the report. `/cron/retry-failed` retries it up to
  `REPORT_MAX_ATTEMPTS` (default 3). The stored payload is reused, so GA4 is not called again.

## Checks

- `php tests/standalone/period_check.php` tests the date logic and needs no Laravel. It passes.
- `tests/Feature/CronRoutesTest.php` needs a Laravel project (`php artisan test`). It has not been run yet.
