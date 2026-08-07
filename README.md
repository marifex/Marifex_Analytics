# MarifeX Advanced Analytics for GLPI

MarifeX is a native analytics plugin for GLPI 11. It stores historical analytics in its own MariaDB or MySQL tables and does not change GLPI core files or tables. The current development version is `0.2.0-dev`.

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

Phase 0 does not parse `glpi_logs`. We need to confirm the log mapping against the installed GLPI 11 release before enabling status and assignment history reconstruction.

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

Name the deployable directory `marifex` and place it in GLPI's `plugins/` directory. Install and activate it from **Setup > Plugins**. Configure GLPI's external automatic actions so `incrementalEtl` and `dailySnapshot` run without depending on web traffic.

## Routes

- Dashboard: `/plugins/marifex/Dashboard`
- Metric API: `/plugins/marifex/api/metrics/{metricKey}`

GLPI authenticates every API request through the current session. The API checks the `plugin_marifex_dashboard` right, uses entity IDs from the active session, validates dates, disables shared caching, and accepts only registered metric keys. Users cannot pass custom SQL.

## Data retention

`retain_analytics_on_uninstall` defaults to `1`. Uninstalling removes plugin rights and automatic-action registrations but leaves the plugin Data Mart in place. An administrator must explicitly set this value to `0` before uninstall to drop MarifeX tables. Operational GLPI tables are never dropped or altered.

## Current limits

- The initial ETL imports ticket-created facts and captures status observations for subsequent ticket changes. Complete historical reconstruction still follows log-forensics validation.
- Initial backfill advances by ticket ID; subsequent updates advance by a composite `date_mod + id` watermark.
- The daily snapshot currently stores open-ticket state; assignment dimensions are reserved but not populated in Phase 0.
- Full integration testing requires a GLPI 11.0.7+ test installation and MariaDB/MySQL.

See [docs/PHASE_0.md](docs/PHASE_0.md) for architecture and verification details.
See [docs/PHASE_1.md](docs/PHASE_1.md) for verified ticket-history ingestion and reconciliation.
