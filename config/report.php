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
        'pages' => [5, 5],
        'offers' => [5, 5],
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
    | Traffic Quality Insight (rule-based, see App\Services\Reports\Insight)
    |--------------------------------------------------------------------------
    | Every rule reads its thresholds from here. Per-property overrides go under properties.{slug}.{key}, for example
    | 'properties' => ['nandini' => ['brand_terms' => ['nandini']]].
    |
    | Guards
    |   large_drop_percent / large_rise_percent : a metric that moved by more than this versus its comparison period is not
    |       stated as a fact. A neutral note asks to check the tracking setup instead. The KPI tile still shows the real number.
    |   min_country_sessions, min_country_rate_percent : the "best traffic quality" country is only named when the winner
    |       has at least this many sessions and a booking-engine or purchase rate above this percent.
    |   small_numbers.min_purchases : fewer purchases than this in either period = "numbers are small", not a trend.
    | Length
    |   max_words : weekly and monthly word limits; the least important optional sentences are dropped to fit.
    | Verdict (headline)
    |   verdict.stable_percent : sessions within +/- this percent are "stable".
    |   verdict.engagement_points : engagement rate must move by this many points to count as better or worse.
    |   verdict.booking_percent : booking-engine visits must move by this percent to count as up or down.
    | Rules
    |   min_channel_sessions : a channel needs this many sessions (current + previous) to be named as a mover.
    |   dominant_channel : a channel above min_share_percent of sessions with an engagement rate under max_engagement_percent
    |       (or an average session shorter than traffic_quality.bot_check.max_avg_session_seconds) is flagged as likely
    |       automated or low-quality traffic.
    |   share_move_points : a channel share that moved by more than this many points gets a sentence.
    |   suspicious_engagement_percent : a country that entered the top list below this engagement, with no booking activity, is
    |       called suspicious.
    |   ai.min_sessions : the AI assistants paragraph needs at least this many sessions.
    |   awareness_terms : campaign names containing one of these are described as awareness, not demand.
    |   offer_path_prefix : pages under this path are the hotel's offers (Most visited offers). Empty = no offers section.
    |   priority_countries : your priority markets (names as GA4 spells them). Empty = skipped.
    |   brand_terms : words that mark a brand search query (for Search Console). Empty = brand share is skipped.
    |       REPORT_INSIGHT_BRAND_TERMS can hold a comma separated list.
    */
    'insight' => [
        'min_channel_sessions' => 50,
        'min_country_sessions' => 50,
        'min_country_rate_percent' => 1.0,
        'large_drop_percent' => 60,
        'large_rise_percent' => 200,
        'small_numbers' => ['min_purchases' => 5],
        'max_words' => ['weekly' => 350, 'monthly' => 400],
        'verdict' => ['stable_percent' => 5.0, 'engagement_points' => 2.0, 'booking_percent' => 5.0],
        'dominant_channel' => ['min_share_percent' => 40, 'max_engagement_percent' => 25],
        'share_move_points' => 10,
        'suspicious_engagement_percent' => 20,
        'ai' => ['min_sessions' => 20],
        'awareness_terms' => ['awareness', 'display', 'reach', 'video'],
        'offer_path_prefix' => '/offer/',
        'priority_countries' => [],
        'brand_terms' => array_values(array_filter(array_map('trim', explode(',', (string) env('REPORT_INSIGHT_BRAND_TERMS', ''))))),
        'properties' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Number styling (email)
    |--------------------------------------------------------------------------
    | A change is green when positive and red when negative (so is its arrow and +/- sign, which also carry the meaning
    | without color; a metric where lower is better, like Search Console position, is inverted). A change of 0 is gray
    | and "new" is blue-gray. Metrics listed in neutral_metrics are always gray: more ad spend is not good or bad by itself.
    */
    'styling' => [
        'neutral_metrics' => ['cost', 'costPerClick'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Paid channels
    |--------------------------------------------------------------------------
    | GA4 default channel groups whose purchases and revenue count as "advertising" in the Paid section.
    */
    'paid_channels' => ['Paid Search', 'Paid Social', 'Paid Video', 'Paid Shopping', 'Paid Other', 'Display', 'Cross-network'],
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
