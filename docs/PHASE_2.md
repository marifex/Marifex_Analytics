# Phase 2: status intervals and logical snapshots

Phase 2 turns verified ticket status events into analytics data that can answer point-in-time questions without querying the GLPI log during dashboard requests.

## Deterministic status timelines

When a batch imports ticket status events, MarifeX rebuilds the affected ticket timelines in event time order. Rebuilding the whole timeline for an affected ticket makes retries safe and handles late events without leaving overlapping intervals.

Each interval records its opening and closing source event. Events that share a timestamp are collapsed to their final observed status. The raw events remain unchanged, so an interval can always be rebuilt.

## Logical daily snapshots

The daily task snapshots the last completed day in the timezone selected in plugin settings. A ticket is included when its status interval was open at that day boundary. This is different from copying the ticket's current status and allows historical backlog to remain correct after a ticket changes later.

The snapshot stores status and age. Priority is set to zero until priority history has its own verified event mapping. This avoids presenting today's priority as if it were historically accurate.

Some imported GLPI records can have an empty business date. MarifeX uses the GLPI record creation timestamp in that case, with the modification timestamp as a final fallback, so one malformed source row cannot stop the full backfill.

## Certified rollups

Each daily snapshot produces the original backlog and age rollups plus the approved operational service-desk rollups:

- Historical open backlog
- Average open ticket age
- Open tickets by priority
- Unassigned open tickets and average current unassigned age
- Tickets approaching SLA breach, breached tickets and SLA breach rate
- SLA breaches and workload by technician
- Open tickets by request source
- Created versus resolved ticket flow
- Technician assignment changes per ticket
- Unsatisfied survey responses
- Resolution-time age bands

The metric API accepts only keys registered in the semantic metric catalog. Average age is weighted by ticket count when a user can see more than one entity.

## Dashboard

The dashboard shows the certified operational metrics from the Data Mart and current open tickets from live GLPI. Default KPI cards align four per desktop row, standard charts align in equal two-column rows, and the layout reflows responsively while preserving mouse drag and resize behavior.

For a new installation, an administrator can run `tools/backfill_analytics.php` from the command line to finish the bounded ETL backlog and build up to 366 completed daily snapshots. Normal operation remains scheduled through GLPI automatic actions.

`tools/verify_analytics.php` reports event, interval, snapshot and rollup counts. It returns a failure status if any status intervals overlap.
