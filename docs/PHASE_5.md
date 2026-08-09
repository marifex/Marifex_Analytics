# Phase 5: scheduled reporting and exports

Phase 5 implements the roadmap boundary for governed CSV/PDF dashboard exports, headless-browser PDF generation and scheduled email delivery of static dashboard reports.

## Export and rendering

- Saved dashboards expose PDF and CSV exports only to profiles with the dedicated export right.
- CSV output contains certified widget data for the saved dashboard horizon and entity scope. Cells beginning with spreadsheet formula operators are escaped.
- PDF output is rendered from a static, self-contained HTML dashboard through a locally installed headless Chrome or Edge executable. It contains KPI, line, bar, donut and table presentations without requiring an authenticated browser session.
- Static exports preserve every approved dashboard presentation: KPI, line, bar, donut, ranking table, insight, attention list, record-detail table and priority/category matrix.
- Summary counts are computed before bounded detail-list truncation so exported operational attention totals remain consistent with certified KPIs.
- In Linux containers, the renderer adds Chromium's `--no-sandbox` compatibility flag because Docker blocks the browser namespace sandbox; non-container hosts retain the browser sandbox.
- Every generated artifact is stored below GLPI's protected plugin document directory, hashed with SHA-256 and recorded in immutable run history.

## Scheduling and delivery

- Profiles with the dedicated scheduling right can create daily, weekly or monthly PDF/CSV schedules for dashboards they own in the active entity.
- Schedules persist a delivery hour, IANA timezone, bounded recipient set and current recursive-entity context.
- Every recipient must be an active GLPI user who can view dashboards in the scheduled entity.
- The `scheduledReports` GLPI automatic action rechecks the owner's export/scheduling rights and entity access before every run, then uses GLPI's configured mail transport for delivery.
- Revoked ownership, entity access or recipient access blocks delivery and records the reason. Permission loss disables the schedule.

## Retention and administration

- Settings reports whether the headless PDF engine is available and permits an explicit executable path.
- Generated files expire after the configured 1-365 day retention window. Run history remains after file expiry.
- Settings shows recent report results in the active entity scope with controlled download links.

## Acceptance checks

1. Unauthorized profiles cannot export, configure schedules or download report files.
2. CSV and PDF exports contain the active saved dashboard, horizon and entity-scoped certified metrics.
3. PDF pages render without clipped text, overlapping cards or unreadable charts.
4. Daily, weekly and monthly next-run calculations respect the selected timezone.
5. Schedule writes enforce GLPI CSRF protection and validate dashboard ownership and recipients.
6. Scheduled execution revalidates the owner, entity and recipients before rendering or delivery.
7. GLPI mail delivery failures and permission blocks appear in report history without leaking recipient addresses.
8. Expired files are deleted only from the protected MarifeX report directory.
9. Home widgets do not display the implementation label `Analytics Data Mart`; Data Mart health remains visible in Settings.
