<?php

use App\Enums\ReportType;
use App\Models\Property;
use App\Services\Reports\ReportPreview;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 | Report email preview, for local development only (404 anywhere else).
 | /reports/preview/nandini/weekly/2026-11-09          (add ?charts=0 for tables instead of charts)
 | Uses the stored report payload, a cached preview payload, or builds one (which calls Google).
 | Sends nothing and writes no report or delivery rows.
 */
Route::get('/reports/preview/{property}/{type}/{periodStart}', function (string $property, string $type, string $periodStart, ReportPreview $preview) {
    abort_unless(app()->environment('local'), 404);

    $reportType = ReportType::tryFrom($type);
    $model = Property::query()->where('slug', $property)->first();
    abort_if($reportType === null || $model === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStart), 404);

    try {
        $result = $preview->render($model, $reportType, $periodStart, withCharts: request()->query('charts') !== '0');
    } catch (InvalidArgumentException) {
        abort(404);   // not a valid date / monthly not on the 1st
    }

    return response($result['html'])->header('Content-Type', 'text/html; charset=utf-8');
});
