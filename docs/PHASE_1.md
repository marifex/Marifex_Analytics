# Phase 1: trusted ticket history

Phase 1 starts turning GLPI history into analytics data that can be explained and checked. The first goal is ticket status history. Other event types will follow only after their GLPI mappings have been verified in the same way.

## Runtime event mapping

GLPI stores a numeric search option ID in each log row. MarifeX does not treat that number as a permanent contract. At runtime, it asks GLPI for the Ticket search options and looks for the option whose table is `glpi_tickets` and whose field is `status`.

The mapping is accepted only when one exact match is found. MarifeX stores the result with the GLPI version, semantic event name, mapping version, validation time, and status. If the lookup is missing or ambiguous, the log pipeline stops without advancing its checkpoint.

For GLPI 11.0.8 in the Laragon test environment, the runtime lookup resolves ticket status to search option `12`. This is a verified result for that installation, not a value hardcoded into the ETL.

## Ticket log ingestion

The `incrementalLogEtl` automatic action reads verified ticket status changes from `glpi_logs` in small batches. It uses a compound `date_mod + id` checkpoint so rows that share the same timestamp are processed in order.

Each imported event has an idempotency key built from:

- Source table
- Source row ID
- Semantic event type
- Event timestamp
- Mapping version

Raw events stay separate from state intervals. Phase 1 does not rebuild historical intervals until the complete event sequence has passed reconciliation.

If the source ticket was purged, MarifeX keeps the non-sensitive event with entity `0` and marks the missing source in the event payload. It does not silently delete analytics history.

## Reconciliation

The `reconcileAnalytics` automatic action compares source tickets with imported ticket-created events up to the current backfill checkpoint. It records source rows, analytics rows, missing events, and events whose source ticket no longer exists.

An orphan is reported as a warning and preserved. This follows the default retention policy. A later retention workflow can anonymise or remove that history when a customer policy requires it.

## Settings and health

The settings page now shows:

- ETL checkpoint health
- Runtime-verified event mappings
- Recent reconciliation results

These checks give administrators a clear reason when historical analytics cannot move forward.

## Next work

Before MarifeX derives full time-in-status metrics, the integration suite must confirm ordered log ingestion, mapping failure behavior, entity isolation, purged-ticket handling, and interval rebuilding from a complete fixture history.
