# MarifeX Advanced Analytics for GLPI

MarifeX is a native analytics plugin for GLPI 11. It stores historical analytics in its own MariaDB or MySQL tables and does not change GLPI core files or tables. The current development version is `0.15.1-dev`.

## Ownership

MarifeX Advanced Analytics is authored and maintained by MarifeX. Product architecture, implementation, documentation and release maintenance are attributed to MarifeX.

## What Phase 0 includes

- GLPI 11 plugin bootstrap, automatic controller discovery, Twig dashboard and menu entry
- Profile rights for dashboard access and analytics administration
- Entity-scoped JSON metric endpoint with a fixed semantic metric registry
- One live metric: `current_open_tickets`
- One Data Mart metric: `historical_open_backlog`
- Idempotent raw events, state intervals, logical daily snapshots and daily rollups
- High-water-mark checkpoint and stale-lock recovery scaffolding
- GLPI automatic actions for incremental ETL and daily snapshots
- Vue 3 + Apache ECharts frontend with isolated CSS, dark-theme tokens, RTL-safe layout and responsive behavior
- Retain-by-default uninstall behavior for analytics data
- Native plugin configuration page for ETL, timezone, retention and pipeline health

Phase 1 added runtime-verified GLPI event mappings and incremental log ingestion. Phase 2 rebuilds deterministic intervals and delivers the approved service-desk operational metrics. Phase 3 delivers the Home-integrated, pointer-resizable dashboard builder with aligned responsive layouts. Phase 4 adds governed asset, software licence, change and problem analytics, including the approved asset-risk metrics. Phase 5 adds governed CSV/PDF exports and scheduled email delivery.

Dashboard presentation changes and the narrowly approved supporting data additions are controlled by [docs/DASHBOARD_DESIGN_SCOPE.md](docs/DASHBOARD_DESIGN_SCOPE.md). Competitor examples are not implementation scope unless that contract names them explicitly.

## Development

```bash
composer dump-autoload
composer lint
composer test
npm install
npm run typecheck
npm run build
```

## Local version archive

Before installing or upgrading a build, save its deployable package under `versions/<version>/marifex`. Git ignores the `versions/` directory, so tested local builds remain available without adding release copies to the repository.

Create the ZIP with `powershell -ExecutionPolicy Bypass -File tools/package_release.ps1`. The packager writes POSIX `/` entry paths and rejects backslashes so Linux and PHP `ZipArchive` extract the `marifex/` directory correctly.

Name the deployable directory `marifex` and place it in GLPI's `plugins/` directory. Install and activate it from **Setup > Plugins**. Configure GLPI's external automatic actions so `incrementalEtl` and `dailySnapshot` run without depending on web traffic.

## Routes

- Home Analytics tab: `/front/central.php?forcetab=GlpiPlugin%5CMarifex%5CHomeDashboardTab%241`
- Legacy dashboard route (redirects to Home): `/plugins/marifex/Dashboard`
- Metric API: `/plugins/marifex/api/metrics/{metricKey}`

GLPI authenticates every API request through the current session. The API checks the `plugin_marifex_dashboard` right, uses entity IDs from the active session, validates dates, disables shared caching, and accepts only registered metric keys. Users cannot pass custom SQL.

## Data retention

`retain_analytics_on_uninstall` defaults to `1`. Uninstalling removes plugin rights and automatic-action registrations but leaves the plugin Data Mart in place. An administrator must explicitly set this value to `0` before uninstall to drop MarifeX tables. Operational GLPI tables are never dropped or altered.

## Current limits

- Status and assignment history are supported; priority history remains excluded until its GLPI runtime mapping is verified.
- Initial backfill advances by ticket ID; subsequent updates advance by a composite `date_mod + id` watermark.
- Asset lifecycle and licence metrics are daily observations from Phase 4 activation onward; GLPI does not provide enough native history for an accurate retroactive lifecycle backfill.
- Licence compliance measures GLPI licence entitlements against items explicitly allocated to those licence records; it does not infer installations that GLPI has not linked to a licence.
- Phase 5 governed PDF/CSV exports and report scheduling are implemented. Scheduled email delivery requires a working GLPI outgoing-mail configuration; cloud mail acceptance is tracked separately from the plugin build.
- Full integration testing requires a GLPI 11.0.7+ test installation and MariaDB/MySQL.

See [docs/PHASE_0.md](docs/PHASE_0.md) for architecture and verification details.
See [docs/PHASE_1.md](docs/PHASE_1.md) for verified ticket-history ingestion and reconciliation.
See [docs/PHASE_2.md](docs/PHASE_2.md) for deterministic intervals, logical snapshots and certified rollups.
See [docs/PHASE_3.md](docs/PHASE_3.md) for the complete Home-integrated dashboard builder.
See [docs/PHASE_4.md](docs/PHASE_4.md) for asset, licence, change and problem analytics.
See [docs/PHASE_5.md](docs/PHASE_5.md) for governed report exports, scheduling and delivery.
