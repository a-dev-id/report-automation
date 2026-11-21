<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cron token
    |--------------------------------------------------------------------------
    | Secret sent by the server crontab in the X-Cron-Token header. Anything
    | shorter than 32 characters is rejected, so a forgotten value fails closed.
    | Generate one with: php -r "echo bin2hex(random_bytes(32));"
    */
    'cron_token' => env('CRON_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Google credentials
    |--------------------------------------------------------------------------
    | Path to the service account JSON key. Keep it outside the public folder.
    | The same service account is added as a user on every hotel's GA4 and
    | Search Console property.
    */
    'google' => [
        // ?: because an empty line in .env gives an empty string, which would skip the default.
        'credentials_path' => env('GOOGLE_APPLICATION_CREDENTIALS') ?: storage_path('app/google/service-account.json'),
        // Quota and transient errors are retried with exponential backoff: base_delay_ms, then x2, x4...
        'retry' => [
            'max_attempts' => (int) env('REPORT_GOOGLE_RETRY_ATTEMPTS', 4),
            'base_delay_ms' => (int) env('REPORT_GOOGLE_RETRY_DELAY_MS', 500),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    | max_attempts applies to building a report and to each recipient's email.
    | retry_window_days stops the retry route from reviving very old reports.
    */
    'max_attempts' => (int) env('REPORT_MAX_ATTEMPTS', 3),
    'retry_window_days' => (int) env('REPORT_RETRY_WINDOW_DAYS', 45),

    /*
    |--------------------------------------------------------------------------
    | Search Console data delay
    |--------------------------------------------------------------------------
    | Search Console data is usually 2 to 3 days behind. Used by
    | ReportPeriod::searchConsoleWindow().
    */
    'search_console_delay_days' => (int) env('REPORT_GSC_DELAY_DAYS', 3),

    /*
    |--------------------------------------------------------------------------
    | Monitoring
    |--------------------------------------------------------------------------
    | Optional URL pinged by GET /cron/health (for example a Healthchecks.io
    | check). If cron stops, the monitor stops hearing from the server.
    */
    'heartbeat_url' => env('REPORT_HEARTBEAT_URL'),

    /*
    |--------------------------------------------------------------------------
    | Report content
    |--------------------------------------------------------------------------
    | Top-N sizes: [weekly, monthly].
    */
    'top' => [
        'channels' => [6, 6],
        'countries' => [8, 10],
        'country_performance' => [10, 10],
        'ai_countries' => [3, 3],
        'ai_landing_pages' => [3, 3],
        'search_queries' => [5, 10],
        'search_pages' => [5, 10],
    ],

    /*
    |--------------------------------------------------------------------------
    | Traffic quality labels (Country Performance table)
    |--------------------------------------------------------------------------
    | Applied top to bottom to each country x campaign x source row. "Booking
    | events" are events on the property's booking engine (see
    | BookingEngineFilter). If the property has no booking definition the
    | booking rules are skipped, never guessed.
    |   Low Data   : fewer sessions than min_sessions
    |   High Value : purchasers or booking events reach the thresholds
    |   Potential  : at least one booking event, or a long average session
    |   Low Intent : everything else
    | bot_check flags rows (with at least min_sessions) whose average session
    | is shorter than max_avg_session_seconds and that have no booking visits
    | (sessions that reached the booking engine) and no purchasers.
    */
    'traffic_quality' => [
        'min_sessions' => 10,
        'high_value' => ['min_purchasers' => 1, 'min_booking_events' => 5],
        'potential' => ['min_booking_events' => 1, 'min_avg_session_seconds' => 45],
        'bot_check' => ['max_avg_session_seconds' => 5.0],
    ],

    /*
    |--------------------------------------------------------------------------
    | Quality alerts
    |--------------------------------------------------------------------------
    | Informational warnings added to the payload (quality_alerts). They never fail a build.
    |   country_sum_tolerance_percent : countries.warning is true, and country_sum_mismatch is raised,
    |                                   when the country rows sum to more than the site total by this percent.
    |   direct_min_share_percent      : direct_low_engagement needs Direct above this share of sessions...
    |   direct_max_avg_session_seconds: ...and a Direct average session shorter than this.
    */
    'quality_alerts' => [
        'country_sum_tolerance_percent' => 10,
        'direct_min_share_percent' => 50,
        'direct_max_avg_session_seconds' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Insight text (InsightGenerator)
    |--------------------------------------------------------------------------
    |   min_channel_sessions : a channel needs at least this many sessions (current + previous) to be named
    |                          as the best or worst movement. Keeps tiny channels out of the headline.
    |   min_country_sessions : a country needs at least this many sessions to be named as the top quality country.
    |   max_bullets          : bullets under the headline (the insight has 3 to 5).
    */
    'insights' => [
        'min_channel_sessions' => 50,
        'min_country_sessions' => 100,
        'max_bullets' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Where report files go
    |--------------------------------------------------------------------------
    | Chart PNGs ({slug}/{type}/{period_start}/) and previews (preview/). Tests point this at a throw-away folder so
    | running them never touches real previews.
    */
    'reports_path' => storage_path('app/reports'),

    /*
    |--------------------------------------------------------------------------
    | Charts (QuickChart PNGs)
    |--------------------------------------------------------------------------
    | Charts are rendered by POSTing a Chart.js config to QuickChart and stored under
    | storage/app/reports/{slug}/{type}/{period_start}/. A chart failure never fails a report: the email falls back
    | to a table. The data sent to QuickChart is only the numbers and labels in the chart (channel and country names,
    | sessions), nothing that identifies a recipient.
    */
    'charts' => [
        'enabled' => (bool) env('REPORT_CHARTS_ENABLED', true),
        'base_url' => env('REPORT_CHARTS_BASE_URL', 'https://quickchart.io'),
        'timeout' => (int) env('REPORT_CHARTS_TIMEOUT', 10),
        'retries' => 1,
        'width' => 600,
        'device_pixel_ratio' => 2,
        'neutral_color' => '#B0B7C3',
        'default_color' => '#1F6F8B',
    ],

    /*
    |--------------------------------------------------------------------------
    | AI referral sources
    |--------------------------------------------------------------------------
    | Regular expressions matched (case-insensitive, partial match) against the
    | GA4 sessionSource dimension. They are joined with "|".
    */
    'ai_sources' => [
        'chatgpt\.com',
        'perplexity',
        'gemini',
        'claude\.ai',
        'copilot',
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults for new properties
    |--------------------------------------------------------------------------
    | Values that differ per hotel live in the properties table, not here.
    */
    'defaults' => [
        'currency' => 'IDR',
        'timezone' => 'Asia/Makassar',
        'language' => 'en',
    ],

];
