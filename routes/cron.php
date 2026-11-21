<?php

use App\Http\Controllers\CronController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Cron routes
|--------------------------------------------------------------------------
| Loaded in bootstrap/app.php with the "throttle:cron" and "cron.token"
| middleware and the "cron" prefix. These are stateless, so CSRF does not apply.
| See the Cron Routes tab of the tracker for the matching crontab lines.
*/

Route::post('weekly-report', [CronController::class, 'weeklyReport']);
Route::post('monthly-report', [CronController::class, 'monthlyReport']);
Route::post('queue-work', [CronController::class, 'queueWork']);
Route::post('retry-failed', [CronController::class, 'retryFailed']);
Route::get('health', [CronController::class, 'health']);
