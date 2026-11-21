<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards every /cron route. The crontab sends the secret in the X-Cron-Token header.
 *
 * A wrong, missing or unconfigured token gets a plain 404, so the routes are not discoverable.
 * If CRON_TOKEN is empty or shorter than 32 characters the middleware refuses every request.
 */
class VerifyCronToken
{
    private const MIN_TOKEN_LENGTH = 32;

    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('report.cron_token');

        if (strlen($expected) < self::MIN_TOKEN_LENGTH) {
            Log::error('Cron route called but CRON_TOKEN is missing or shorter than 32 characters.', [
                'path' => $request->path(),
            ]);
            abort(404);
        }

        if (! hash_equals($expected, (string) $request->header('X-Cron-Token', ''))) {
            Log::warning('Cron route called with a wrong token.', [
                'path' => $request->path(),
                'ip' => $request->ip(),
            ]);
            abort(404);
        }

        Log::info('Cron call', [
            'method' => $request->method(),
            'path' => $request->path(),
            'query' => $request->query(),
            'ip' => $request->ip(),
        ]);

        return $next($request);
    }
}
