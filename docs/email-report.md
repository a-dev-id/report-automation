# Report email

The report email is built from the payload (see `payload.md`) in four steps, all in `app/Services/Reports/`:

| Step | Class | What it does |
|---|---|---|
| 1 | `InsightGenerator` | Rule-based "Traffic Quality Insight": a headline, 3 to 5 bullets and an optional caution line. No external API. |
| 2 | `ChartRenderer` | Three PNG charts through QuickChart. A failed chart is `null` and the email shows a table instead. |
| 3 | `ReportViewData` | Formats every figure once. Both email versions render this, so they always show the same numbers. |
| 4 | `emails/report.blade.php`, `emails/report-text.blade.php` | HTML (tables, inline CSS, 640px, one column) and plain text. |

`App\Mail\ReportMail` ties them together. The subject is `{Property} {Weekly|Monthly} Traffic Report: {period label}`,
for example `Nandini Weekly Traffic Report: 9 to 15 Nov 2026`. `BuildAndSendReport` renders the charts once and gives
the same files to every recipient.

## Insight rules

Thresholds are in `config/report.php` under `insights`. Wording differs for weekly ("this week", "the previous week") and
monthly ("this month", "last month", plus a year-ago clause).

- **Headline:** sessions and key events against the previous period; monthly adds the same month last year.
- **Bullets, in this order, each only if its data exists:** channel movement (largest gain and largest loss in sessions, among
  channels with at least `min_channel_sessions`), booking engine (visits, share of sessions, the previous rate), the country with
  the best traffic quality, purchases and revenue (in the property currency), and AI traffic when above 0. If fewer than 3 bullets
  exist, engagement rate and users fill in. At most `max_bullets`.
- **Best traffic quality country:** not the biggest one. Rows are summed per country and ranked by booking-engine visits (and
  purchasers) per session. Countries with fewer than `min_country_sessions` sessions, rows labelled "Low Data" and rows flagged
  "check for bots" are ignored.
- **Caution:** one neutral line when `quality_alerts` is not empty. It says what was found and that the figures should be read
  with care. The data is never hidden.
- A zero or missing previous value never divides: the text says "none before" or "no comparison available". A section that is
  missing or is an `{error}` object is skipped.

## Charts

`POST {REPORT_CHARTS_BASE_URL}/chart` with a Chart.js v3 config, width 600, devicePixelRatio 2, white background. Timeout
`REPORT_CHARTS_TIMEOUT` (10 s), one retry. Set `REPORT_CHARTS_ENABLED=false` to turn charts off.

- `trend`: sessions per week (8) or month (12) in the brand colour, with the previous period's level as a dashed gray line.
- `channels`: top 6 channels, horizontal bars. `countries`: top 8, horizontal bars.
- Files: `storage/app/reports/{property_slug}/{type}/{period_start}/{name}.png`. In the email they are CID attachments, so nothing
  is loaded from the internet when the email is opened.
- QuickChart receives only the chart numbers and labels (channel and country names, session counts), nothing that identifies a
  recipient. Failures are logged as warnings and never fail a report.

## Sections of the email

Insight, KPI tiles (sessions, users, engagement rate, key events, purchases and revenue when present; monthly adds vs year ago),
trend, channels (chart and table), countries (chart) and Country Performance table, Paid (only with data), AI traffic (only with
data), Search Console (tiles, top 5 queries and pages), Data notes. A section whose payload value is an `{error}` object shows
"This section is temporarily unavailable." An empty section is left out. Ad **cost** changes are gray, because more spend is
neither good nor bad.

Data notes show: "Search Console data through <date>" when the last days are missing, the Search Console time zone note, the
`quality_alerts` messages, and a line when a chart could not be generated.

## Preview (no mail is sent, nothing is saved to reports or deliveries)

```
php artisan report:preview nandini weekly 2026-11-09          # a Monday
php artisan report:preview nandini monthly 2026-10-01 --no-charts
php artisan report:preview nandini weekly --fresh --out=C:\temp\p.html
```

The payload is the stored report if one exists, else a cached copy in `storage/app/reports/preview/`, else a real build
(this calls Google) which is then cached. `--fresh` ignores both. The HTML has its images inlined as data URIs.

In `APP_ENV=local` the same HTML is served at `/reports/preview/{property}/{type}/{periodStart}` (add `?charts=0` for
tables). Everywhere else that route is a 404.