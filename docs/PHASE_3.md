# Phase 3: assignment history and team workload

Phase 3 adds verified technician and group assignment history. It uses the same GLPI runtime metadata checks as status history and does not assume that search option numbers stay fixed between releases.

## Runtime verification

MarifeX identifies assignment search options by their target table, link field and GLPI role discriminator. The role must be technician type `2`. This prevents requester and observer relationships from being mistaken for assignments.

The mapping is saved for the exact GLPI version and shown on the plugin settings page. The ETL stops if any required mapping is missing or ambiguous.

## Data minimization

GLPI assignment logs contain display labels followed by a numeric reference. MarifeX extracts the numeric user or group ID and does not copy the display label into the Data Mart. The raw source remains in GLPI for administrators who already have permission to view it.

## Membership intervals

A ticket can have more than one assigned technician or group. Assignment history is therefore modeled as membership intervals rather than a single replacement state. The interval identity includes ticket, membership type, reference ID and start time.

Add, remove and replacement records are replayed in event order. A removal without an earlier imported add is treated as membership from ticket creation until the removal. This supports older GLPI histories where the first available record is already a removal.

## Team workload

Completed-day snapshots count open tickets for every assigned group active at the day boundary. The secure metric API exposes `historical_group_backlog`, and the dashboard shows the latest completed-day backlog by group.

The workload count is assignment membership, not ticket ownership. A ticket assigned to two groups contributes to both groups and is counted once in the overall backlog.

## Performance

Status and assignment events share one compound-cursor log scan, but each projector receives only tickets affected by its own event type. This keeps normal incremental batches small while preserving complete per-ticket rebuilding for late-event safety.

The verifier checks status overlap per ticket and assignment overlap per ticket, membership type and reference ID. Different groups or technicians may overlap because concurrent assignment is valid.
