# Phase 0 technical foundation

## Architecture boundary

MarifeX has three explicit query paths:

1. **Live GLPI** for indexed, point-in-time operational counts.
2. **Plugin Data Mart** for historical series, durations and reconstructed state.
3. **Hybrid** metrics, added later, that combine a live value with a Data Mart baseline.

The metric controller never accepts a table, column, expression, join, grouping or SQL fragment. A requested key is resolved in `MetricRegistry`, then a dedicated implementation builds the database request. Every implementation adds the current session's active entity IDs.

## Phase 0 tables

| Table | Grain | Purpose |
|---|---|---|
| `glpi_plugin_marifex_etl_checkpoints` | pipeline + source | Watermark, lock, retry and diagnostic state |
| `glpi_plugin_marifex_ticket_events` | immutable logical event | Idempotent raw event foundation |
| `glpi_plugin_marifex_state_intervals` | ticket + state interval | Derived duration-ready state history |
| `glpi_plugin_marifex_daily_snapshots` | day + ticket | Historical point-in-time backlog state |
| `glpi_plugin_marifex_daily_rollups` | day + entity + metric + dimension | Dashboard-ready aggregates |
| `glpi_plugin_marifex_dashboard_definitions` | dashboard | Versionable dashboard metadata |

All tables use the GLPI plugin prefix, InnoDB, `utf8mb4`, and purpose-specific unique/index keys. No foreign keys point into GLPI core, avoiding upgrade and deletion coupling.

## ETL guarantees

- A unique SHA-256 event key makes retries idempotent.
- Separate checkpoints store the initial high-water ticket ID and the composite `date_mod + id` update watermark.
- A UUID-like lock token prevents concurrent completion from releasing another worker's lock.
- Locks older than 30 minutes are recoverable.
- A failed batch records a bounded error and does not advance its watermark.
- Batch size is configurable from 50 to 5,000, default 500.
- Timestamps stored by MarifeX are UTC unless they reproduce an original GLPI timestamp.

Ticket IDs are used only for initial backfill. A second pipeline captures updates using a composite `date_mod + id` watermark. Status intervals created this way are reliable from the point MarifeX begins observing changes; earlier transitions still require verified log reconstruction.

## Security controls

- Native GLPI session authentication
- Explicit view/admin profile rights
- Active-entity allow-list derived server-side
- Fixed metric registry and dedicated query functions
- Strict ISO date parsing and maximum range
- Private/no-store JSON responses
- No ticket subject, content, requester data or other sensitive text in the initial mart
- No unrestricted SQL, formulas or JavaScript from users

## Installation verification

1. Run `composer dump-autoload`, `composer lint`, and `composer test`.
2. Run `npm install`, `npm run typecheck`, and `npm run build`.
3. Copy or link the repository to `plugins/marifex` in a GLPI 11.0.7+ test instance.
4. Install and enable the plugin.
5. Confirm all six plugin tables exist and no core schema changed.
6. Grant the dashboard right to a non-super-admin test profile.
7. Verify the dashboard returns only tickets from active and recursively active entities.
8. Run both automatic actions in CLI mode and rerun them to prove idempotency.
9. Change active entity and confirm both API values change without cross-entity leakage.
10. Test GLPI light/dark themes, a narrow viewport, and an Arabic locale.

## Exit criteria for the next phase

- Integration suite passes on the supported GLPI 11 patch version.
- Profile installation APIs and plugin menu behavior are confirmed against the installed release.
- Entity isolation tests include parent, child, recursive, sibling and root entity contexts.
- Ticket-log event mapping is verified from GLPI source and reproducible fixtures.
- Reconciliation reports zero unexplained differences between source tickets and imported ticket-created events.
- Measured ETL and dashboard timings replace any speculative throughput claims.
