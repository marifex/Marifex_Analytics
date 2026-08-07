# MarifeX Advanced Analytics for GLPI

Phase 0 foundation for a GLPI 11 native analytics plugin. MarifeX keeps historical analytics in plugin-owned MariaDB/MySQL tables and leaves GLPI core files and tables unchanged. Current development version: `0.1.4-dev`.

## Phase 0 capabilities

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

This phase deliberately does not parse `glpi_logs`. That mapping must be verified against the installed GLPI 11 release before status and assignment history reconstruction is enabled.

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

Before a build is installed or upgraded, preserve its deployable runtime package under `versions/<version>/marifex`. The `versions/` directory is intentionally local-only and excluded from Git so each tested build remains available without duplicating release artifacts in source history.

The deployable directory must be named `marifex` and placed in GLPI's `plugins/` directory. Install and activate it from **Setup → Plugins**, then configure GLPI's external automatic actions so `incrementalEtl` and `dailySnapshot` run without depending on web traffic.

## Routes

- Dashboard: `/plugins/marifex/Dashboard`
- Metric API: `/plugins/marifex/api/metrics/{metricKey}`

The API is session-authenticated by GLPI, checks the `plugin_marifex_dashboard` right, derives entity IDs only from the active GLPI session, validates dates, disables shared caching, and accepts only registered metric keys. It has no custom SQL parameter.

## Data retention

`retain_analytics_on_uninstall` defaults to `1`. Uninstalling removes plugin rights and automatic-action registrations but leaves the plugin Data Mart in place. An administrator must explicitly set this value to `0` before uninstall to drop MarifeX tables. Operational GLPI tables are never dropped or altered.

## Current limits

- The initial ETL imports ticket-created facts and captures status observations for subsequent ticket changes. Complete historical reconstruction still follows log-forensics validation.
- Initial backfill advances by ticket ID; subsequent updates advance by a composite `date_mod + id` watermark.
- The daily snapshot currently stores open-ticket state; assignment dimensions are reserved but not populated in Phase 0.
- Full integration testing requires a GLPI 11.0.7+ test installation and MariaDB/MySQL.

See [docs/PHASE_0.md](docs/PHASE_0.md) for architecture and verification details.
