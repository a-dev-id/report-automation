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
- **Large-change guard** (`insights.large_drop_percent` 60, `insights.large_rise_percent` 200): a metric that moved by more than that
  against its comparison period is not stated as a fact. The headline leaves it out (sessions always stay, as a plain value),
  bullets give only the previous value and point to the note, and the caution gets one neutral sentence, for example: "Key
  events changed by -89.7% versus October 2025. A change this large often comes from a change in how events are tracked, so
  please check the tracking setup before drawing conclusions." The KPI tile still shows the real number and arrow. A zero or
  missing previous value has no percent and is never guarded.
- **Bullets, in this order, each only if its data exists:** channel movement (largest gain and largest loss in sessions, among
  channels with at least `min_channel_sessions`), booking engine (visits, share of sessions, the previous rate), the country with
  the best traffic quality, purchases and revenue (in the property currency), and AI traffic when above 0. If fewer than 3 bullets
  exist, engagement rate and users fill in. At most `max_bullets`.
- **Best traffic quality country:** not the biggest one. Rows are summed per country and ranked by booking-engine visits (and
  purchasers) per session; "Low Data" rows and rows flagged "check for bots" are ignored. The sentence is only written when the
  winner has at least `min_country_sessions` (50) sessions AND a booking-engine rate or purchase rate above
  `min_country_rate_percent` (1%). Otherwise it is left out, and nothing replaces it. When it is written and a different
  country is the biggest, it says "The largest country by sessions was X" and adds "the engaged-sessions leader was Y" if that
  differs from X. Both names come from `countries.largestBySessions` and `countries.engagedLeader`, which look at all countries, not only
the rows shown. The booking and purchase rates are measured on the country's rows in the Country performance table (its top traffic
sources), which is why the sentence says "top-source sessions".
- **Caution:** one neutral line when `quality_alerts` is not empty. It says what was found and that the figures should be read
  with care. The data is never hidden.
- A zero or missing previous value never divides: the text says "none before" or "no comparison available". A section that is
  missing or is an `{error}` object is skipped.

## Charts

`POST {REPORT_CHARTS_BASE_URL}/chart` with a Chart.js v3 config, width 600, devicePixelRatio 2, white background. Timeout
`REPORT_CHARTS_TIMEOUT` (10 s), one retry. Set `REPORT_CHARTS_ENABLED=false` to turn charts off.

- `trend`: sessions per week (8) or month (12), one series in the brand colour. Value labels on the first point, the last point
  and the peak; when two labels would touch, the lower one is placed below its point.
- `channels`: top 6 channels by sessions, horizontal bars. `countries`: top 8 by **engaged sessions**, horizontal bars, titled
  "Top countries by engaged sessions".
- Files: `storage/app/reports/{property_slug}/{type}/{period_start}/{name}.png`. In the email they are CID attachments, so nothing
  is loaded from the internet when the email is opened.
- QuickChart receives only the chart numbers and labels (channel and country names, session counts), nothing that identifies a
  recipient. Failures are logged as warnings and never fail a report.

## Sections of the email

Insight, KPI tiles (sessions, users, engagement rate, key events, purchases and revenue when present; monthly adds vs year ago),
trend, channels (chart and table), "Top countries by engaged sessions" (chart) and the Country performance table (Sessions,
Engaged, Engagement, Booking visits, Quality and the "check for bots" flag; ranked by engaged sessions), Paid (only with data), AI traffic (only with
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
## How to send a test email

`report:send-test` builds the report exactly like the real job (same payload, same charts, same `ReportMail` with CID
images) and sends it with `[TEST] ` in front of the subject, **only** to the address in `--to`.

```
php artisan report:send-test nandini weekly --to=you@example.com
php artisan report:send-test nandini monthly 2026-10-01 --to=you@example.com,colleague@example.com
```

- `--to` is required: without it the command refuses to run.
- It never reads the `recipients` table, never creates `report_deliveries` rows and never writes or updates a `reports`
  record. It reads a stored payload if one exists, else a cached preview payload, else it builds one (which calls Google).
- It prints the mailer in use, the subject and each address. It never prints mail credentials.
- With `MAIL_MAILER=log` (the default) nothing is delivered: the email is only written to `storage/logs/laravel.log`.
  Set `MAIL_MAILER=smtp` in `.env` to receive it.

Put the real values in `.env` (never in `.env.example`) and run `php artisan config:clear` after changing them.

### Example 1: Gmail with an app password

Needs 2-Step Verification on the Google account. Create an app password at <https://myaccount.google.com/apppasswords>
(a 16-character code; use it instead of the account password, and revoke it when you are done).

```
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_SCHEME=smtp
MAIL_USERNAME=your.name@gmail.com
MAIL_PASSWORD=abcd-efgh-ijkl-mnop
MAIL_FROM_ADDRESS="your.name@gmail.com"
MAIL_FROM_NAME="Traffic Reports"
```

Gmail rewrites the From address to the account address, so use that as `MAIL_FROM_ADDRESS`. Port 465 with `MAIL_SCHEME=smtps`
also works.

### Example 2: Mailtrap (a safe inbox that never delivers)

Mailtrap catches every email in a web inbox, so nothing reaches a real person. Copy the SMTP values from the inbox's
"SMTP Settings" page:

```
MAIL_MAILER=smtp
MAIL_HOST=sandbox.smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=your-mailtrap-username
MAIL_PASSWORD=your-mailtrap-password
MAIL_FROM_ADDRESS="reports@example.com"
MAIL_FROM_NAME="Traffic Reports"
```

Then run the command with any address, for example `--to=you@example.com`: the email appears in the Mailtrap inbox, where you
can check the HTML, the plain-text version, the inline images and the spam score.
## Email client notes (Outlook and Gmail)

The HTML is built for the Outlook Word engine as well as Gmail:

- Tables for all layout. Spacing is `td` padding or spacer rows, never `div`/`p` padding or table margins (Outlook ignores them).
  Bullets are table rows, not a `ul`.
- A `bgcolor` attribute next to every CSS background, `align` next to every `text-align`, and `width`/`height` attributes on
  every image (`display:block`, `border:0`, an `alt`). Image heights come from the real PNG, so nothing jumps when Outlook
  ignores `height:auto`.
- No float, flex, grid or position. `max-width` is only on the 640px wrapper table (which also has `width="640"`) and on images.
- Fonts are Arial/Helvetica. There are no links, so nothing gets underlined; `format-detection` stops phones from turning
  numbers into links. Header text is white or near-black depending on the brand colour.
- A hidden preheader (the insight headline, at most 90 characters, cut at a word) is the inbox preview text.
- In the real email the charts and the logo are attached inline (CID) with a filename (`trend.png`, ...) and their own content
  type (`image/png`), so no image is loaded from the internet.