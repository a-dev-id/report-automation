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

## Traffic Quality Insight

The insight is written for a hotel manager. Every paragraph says what changed (with the key numbers), what it means and, when
useful, one thing to check. It is rule based (no external API) and sits behind `App\Contracts\InsightGenerator`, bound to
`App\Services\Reports\Insight\RuleBasedInsightGenerator` in `ReportServiceProvider`. An AI-written version can replace it by
changing that one binding: it only has to return the same shape (`verdict`, `headline`, `paragraphs`, `priority`, `caution`).

**Structure**

1. **Headline**: one sentence verdict, chosen from *healthy growth*, *growth with weaker quality*, *a decline*, *stable* or *mixed*.
   It looks at sessions, engagement rate and booking-engine visits together (`insight.verdict.*`), never at one metric.
2. **Paragraphs**, each with a bold title, in this order, skipped when the section has no data: Advertising, Website traffic,
   Where visitors came from, Countries, AI assistants, Search Console.
3. **Priority:** the one or two most useful actions from the rules that fired (most urgent first).
4. **Caution:** one neutral paragraph (see Guards).

About 350 words for a weekly report and 400 for a monthly one (`insight.max_words`). When the text is too long the least important
optional sentences are dropped first; the first sentence of each paragraph and the AI "meaning" line are never dropped.
"this week" or "this month" wording follows the report type; monthly also compares with the same month last year.

**Wording rules.** The headline reads naturally ("Traffic declined this week: sessions fell -9.8% to 6,545, ..."; the verdict logic is unchanged). Signed values stay bold and colored but carry no ▲▼ arrows inside sentences (arrows remain in the KPI tiles and tables). A figure is never stated twice in one paragraph (for example a dominant channel's share is given once, then its engagement and duration). "Engaged sessions" is explained once, as visits where people actually interacted with the site.

**Rules** (one small class per section in `app/Services/Reports/Insight/`, each rule returns a sentence and an optional action; all
thresholds are in `config/report.php` under `insight`)

| Section | Rules |
|---|---|
| Advertising | impressions up + clicks down + CTR down = reached more people but fewer responded (review placements and creative); clicks up but CTR down; impressions and clicks both down = reach shrank; cost up with no purchases = spend without bookings, judge by booking-engine visits; clicks up + cost per click down = more efficient; cost per click up = less efficient; display or awareness campaigns = awareness, not demand |
| Website traffic | sessions up while engagement or session duration is down = growth from lower-quality traffic (names the channel that grew most); sessions down while engagement is up = fewer but more interested visitors; sessions up while booking-engine visits are flat or down = check the booking button and landing pages; booking-engine rate: interpreted ("more/fewer visitors showed interest in booking") only when it moved by at least `booking_rate.min_points` (0.5 pts) or `booking_rate.min_relative_percent` (15%) AND there were at least `booking_rate.min_visits` (20) booking-engine visits in either period; a smaller move is stated as numbers only, and a negligible one is left out; purchases or revenue under `small_numbers.min_purchases` (5) in both periods = "numbers are small", not a trend; monthly: against the same month last year |
| Where visitors came from | the largest sources with their share and "the remaining X% came from ..."; one channel above `dominant_channel.min_share_percent` (40%) with engagement under 25% or an average visit under the bot threshold = likely automated or low-quality traffic (check bot and referral filtering in GA4); a share that moved by more than `share_move_points` (10) |
| Countries | ranked by engaged sessions; new countries in the top list in three tiers (`new_country`): "shows genuine booking interest" (at least 50% engaged and 3 booking-engine visits), "shows some booking interest" (at least 30% engaged and 1 visit), otherwise "needs a closer look"; "genuine" is never used below 3 visits, and without booking data only engagement is described; countries that dropped out; `priority_countries` combined share (when set); rows flagged for bots; the best traffic quality country |
| AI assistants | only above `ai.min_sessions` (20): top assistant and share, new assistants, top countries with share and "New", dropped countries, and what it means |
| Search Console | impressions up + clicks down = shown more but clicked less (with the position change); both up or both down = visibility improved or weakened; `brand_terms` share of clicks (skipped when empty); the data-through date when truncated |

**Guards**

- **Large-change guard** (`insight.large_drop_percent` 60, `insight.large_rise_percent` 200): a metric that moved by more than that is not
  stated as a fact and no rule interprets it. The caution says, for example: "Key events changed by -89.7% versus October 2025. A
  change this large often comes from a change in how events are tracked, so please check the tracking setup before drawing
  conclusions." The KPI tile still shows the real number and arrow. A zero or missing previous value has no percent and is never
  guarded ("new this period" or "no comparison available").
- **Best traffic quality country** is only named when the winner has at least `min_country_sessions` (50) sessions AND a booking-engine
  or purchase rate above `min_country_rate_percent` (1%). Otherwise nothing replaces it. The rates are measured on the country's rows in the
  Country performance table (its top traffic sources), so the sentence says "top-source sessions". "Largest by sessions" and the
  engaged-sessions leader come from `countries.largestBySessions` and `countries.engagedLeader`, which look at all countries.
- **quality_alerts** from the payload become one neutral "Data note" in the caution.

**Optional settings** (`config/report.php`, per hotel under `insight.properties.{slug}`)

- `insight.brand_terms` (or `REPORT_INSIGHT_BRAND_TERMS=nandini,nandini bali`): words that mark a brand search. Needs one extra Search
  Console query per period. Empty = skipped.
- `insight.priority_countries`: your priority markets, for their combined share of engaged sessions.

**Number styling.** Every value shown with a change is bold. A positive change is green (#15803D), a negative one red (#B91C1C), zero gray
(#6B7280) and "New" blue-gray (#475569); the arrow and the + or - sign are always shown, so meaning never depends on color. Search
Console position is inverted (a lower number is better, so a drop is green). Metrics in `styling.neutral_metrics` (ad cost, cost per
click) are always gray. In the insight text the numbers and changes are bold colored inline spans; the plain-text version has no colors
and keeps the signs.

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

Insight, KPI tiles (sessions, users, engagement rate, average session duration, key events, purchases and revenue when present; monthly adds
vs year ago),
trend, channels (chart and table), "Top countries by engaged sessions" (chart) and the Country performance table (Sessions,
Engaged, Engaged rate, Booking visits and Quality, with "possible bots" as a small second line; ranked by engaged sessions). The countries table is headed "Share of engaged" and "Engaged change" (what the change is computed on); Country performance uses "Share of sessions" and notes that each row shows the country's strongest traffic source, so session totals can differ from the table above, Paid (only with data), AI traffic (only with
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