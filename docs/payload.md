# Report payload

`App\Services\Ga4GscReportBuilder::build(Property, ReportPeriod)` returns this array. The job stores it
in `reports.payload` (JSON). To see one for a real hotel without saving or emailing anything:

```
php artisan report:payload nandini weekly
php artisan report:payload nandini monthly --now=2026-11-21
```

`--now` pretends it is that date, so you can rebuild an old period and compare it with the GA4 and
Search Console screens.

## Conventions

- Numbers from GA4 and Search Console are floats. Durations are seconds. Money is in the property's
  currency (`meta.currency`). `ctr` is a fraction (0.025 = 2.5%). Shares are percent (30.0 = 30%).
- A **metric** is the output of `Compare::metric()`:

  ```
  {
    "current": 10000.0, "previous": 8000.0,
    "change": {"difference": 2000.0, "percent": 25.0, "direction": "up", "improved": true},
    "yearAgo": 5000.0,                       // monthly reports only
    "yearAgoChange": {"difference": ..., "percent": ..., "direction": ..., "improved": ...}   // monthly only
  }
  ```

  `direction` is `up`, `down`, `flat` (rounds to 0.0%), `new` (nothing before, something now: `percent` is
  null) or `none` (nothing to compare). `improved` is true or false for up/down, null otherwise. For
  Search Console average position and cost per click lower is better, so a drop has `improved: true`.
- Weekly reports compare with the week before. Monthly reports also compare with the same month last year.
- Section keys are snake_case. Keys inside sections are camelCase and follow the GA4 names.
- Optional sections that fail are replaced by `{"error": {"type": "GoogleApiException", "message": "..."}}`.
  `site` and `search_console` never contain that: if either fails the whole build fails.

## Shape

```
meta
  slug, name, timezone
  currency              properties.currency (what the property is configured as)
  ga4Currency           currencyCode GA4 returned in the response metadata (null if no report ran). Revenue and ad cost are
                        in this currency. If it differs from currency a currency_mismatch alert is raised.
  type                  "weekly" | "monthly"
  periodLabel           "9 to 15 Nov 2026" | "October 2026"
  dateRanges            {current: [start, end], previous: [start, end], yearAgo: [start, end] | null}   // Y-m-d
  generatedAt           ISO 8601 in the property's timezone

site                    all values are metrics
  sessions, totalUsers, averageSessionDuration, purchases, purchaseRevenue
  bookingEngineVisits   metric: sessions that reached the booking engine (hostname, path) or contained its event
                        (event). Same meaning for all three types. null when there is no booking_engine definition
  bookingEvents         metric: eventCount of the booking event. Only for booking_engine type "event"; null for
                        hostname and path (eventCount there would include page_view) and with no definition

channels
  total.sessions        metric
  rows[]                top 6 by current sessions, then an "Other" row
    channel, isOther, sessions (metric), totalUsers (metric)
    averageSessionDuration   metric, seconds ('Other' is the sessions-weighted average of the rest)

countries
  total.sessions        metric (sum of all country rows, per range)
  sessionsSum           sum of the country rows, current period
  sessionsTotal         site.sessions.current, the real total for the same period (null if unknown)
  warning               true when sessionsSum exceeds sessionsTotal by more than quality_alerts.country_sum_tolerance_percent
                        (default 10%). GA4 counts a session in every country it was seen in, so proxy or bot traffic can make
                        the rows add up to more than the real total. The rows themselves are never adjusted.
  rows[]                top 8 (monthly: 10), then "Other"
    country, isOther, sessions (metric)
    share               {current, previous, yearAgo?}   percent of all sessions in that range
    shareChange         {previous: {points, direction}, yearAgo?: {points, direction}}   percentage points

country_performance[]   current period, top 10 by sessions
  country, campaign, sourceMedium
  users, sessions, averageSessionDuration, purchasers, purchaseRevenue
  previousSessions      sessions of the same country + campaign + source in the previous period (0 if none)
  sessionsChange        Compare::change(sessions, previousSessions): {difference, percent, direction, improved}
                        (direction "new" when it had no sessions before). Compared with the previous period only,
                        also in monthly reports. No other column is compared.
  bookingEngineVisits   sessions that contain the booking event / reached the booking engine (BookingEngineFilter);
                        null if no booking definition
  bookingEngineEvents   eventCount under the same filter; null if no booking definition (for hostname and path it includes
                        every event there, page_view included)
  quality               "High Value" | "Potential" | "Low Intent" | "Low Data"   (rules: config/report.php traffic_quality)
  checkForBots          true when sessions >= 10, average session < 5 s (strictly), no booking visits and no purchasers.
                        Always false when bookingEngineVisits is null (no booking definition). Thresholds: traffic_quality.
  flag                  "check for bots" when checkForBots is true, otherwise null

ai_traffic
  total                 metric: sessions from all matching sources
  sources[]             {source, sessions (metric)}, by current sessions. Sources with 0 sessions in both the current and the
                        previous period are left out, even if they had year-ago sessions (total still counts everything)
  topCountries[]        {country, sessions}                top 3, current period, all AI sources together
  topLandingPages[]     {landingPage, sessions}            top 3, same

paid                    null when ads_source is "none"
  source                "ga4" | "google_ads_api"
  currency              GA4's currencyCode for the cost figures (null for google_ads_api)
  available             false for google_ads_api
  note                  "Google Ads API reporting is not implemented yet." for google_ads_api, else null
  metrics               null for google_ads_api, else metrics: impressions, clicks, cost, ctr, costPerClick
                        (ctr and costPerClick are null for a range with zero impressions or clicks)

trend
  granularity           "week" (last 8 ISO weeks) | "month" (last 12 months), ending with the reported period
  points[]              oldest first: {key, start, end, sessions, totalUsers}
                        key is GA4's isoYearIsoWeek ("202646") or yearMonth ("202610"); empty buckets are zeros

search_console
  hasData               false if no day of the period is ready yet (then totals is null and the lists are empty)
  truncated             true when the last days of the period are not available yet
  daysMissing           number of days cut from the end of the period (and of the comparison periods)
  through               last day with data (Y-m-d) or null
  windows               {current: [s, e], previous: [s, e], yearAgo?: [s, e]}   the ranges actually queried
  totals                impressions, clicks, ctr, position (all metrics)
  topQueries[]          top 5 (monthly: 10): {query, clicks, impressions, ctr, position (metrics, no yearAgo)}
  topPages[]            same with `page`
  daily[]               {date, clicks, impressions} for every day of the current window, zeros for days without rows

quality_alerts[]         informational list, empty when all is well. Never fails a build. Each item: {code, message, details}
  country_sum_mismatch   countries.warning is true. details: sessionsSum, sessionsTotal, excessPercent, tolerancePercent
  direct_low_engagement  the Direct channel is above direct_min_share_percent (50) of channel sessions AND its average session is
                         under direct_max_avg_session_seconds (30). details: directSessions, directSharePercent,
                         directAverageSessionDuration, minSharePercent, maxAverageSessionSeconds
  currency_mismatch      meta.ga4Currency differs from meta.currency. details: ga4Currency, propertyCurrency
```

`topQueries` and `topPages` are compared with the previous window's top 1000 rows. A row that is missing
there shows `direction: "new"` even if it simply ranked lower than 1000.

## Dates and time zones

- GA4 reports use each property's own time zone (properties.timezone, Asia/Makassar for Nandini).
- Search Console API dates are **Pacific Time** days. The code sends the same plain calendar dates (Y-m-d) it uses for GA4 and does
  no conversion, so "9 Nov" means 9 Nov in the property's time zone for GA4 and 9 Nov Pacific for Search Console. Pacific is 15 to
  16 hours behind Makassar, so a day's Search Console numbers can differ slightly from a Makassar-day view. The Search Console screen
  also uses Pacific dates, so API and screen agree when given the same dates.
- Search Console queries always ask for search type `web`, and data state is the API default (final data, which is why the last 2 to 3
  days are cut: see `search_console.truncated`).

## Where the numbers come from

| Section | GA4 report (label) | Notes |
|---|---|---|
| site | `site.totals`, `site.booking` | sessions, totalUsers, averageSessionDuration, ecommercePurchases, purchaseRevenue |
| channels | `channels` | sessionDefaultChannelGroup |
| countries | `countries` | country |
| country_performance | `country_performance`, `country_performance.previous`, `country_performance.booking` | country, sessionCampaignName, sessionSourceMedium |
| ai_traffic | `ai.sources`, `ai.countries`, `ai.landing_pages` | sessionSource partial regex, case-insensitive |
| paid | `paid` | advertiserAdImpressions, advertiserAdClicks, advertiserAdCost |
| trend | `trend.weekly`, `trend.monthly` | isoYearIsoWeek or yearMonth |
| search_console | `totals.*`, `queries.*`, `pages.*`, `daily.current` | searchanalytics.query |

## Booking engine

`properties.booking_engine` is `{"type": "hostname|event|path", "value": "..."}`. `App\Reports\BookingEngineFilter`
is the only place that turns it into a GA4 filter:

| type | filter | visits metric | events metric |
|---|---|---|---|
| hostname | hostName equals value | sessions | eventCount |
| path | pagePath begins with value | sessions | eventCount |
| event | eventName equals value | sessions (sessions that contain the event) | eventCount |

Visits and booking events come from one GA4 query (`site.booking`); `eventCount` is requested only for the `event` type.
`site.bookingEvents` is null for hostname and path. Country Performance's per-row `bookingEngineEvents` still uses raw
`eventCount` under the filter (for hostname and path that includes every event on the host or path, so treat it as
"engagement on the booking engine", and note it feeds the quality label and bot flag).

A missing or invalid definition gives null everywhere. It is never read as zero.
