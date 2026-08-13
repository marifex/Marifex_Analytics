# Changelog

All notable MarifeX Advanced Analytics changes are recorded here from version `0.14.1-dev` onward.

The format follows Keep a Changelog. Development versions remain unreleased until live validation, commit, tag and push are complete.

## [Unreleased]

Release candidate: `0.15.0-dev` (Phase 5A + Phase 5C initial complete Advanced Analytics product).

### Added

- Implemented mandatory Progressive Analytical Activation with independent `CURRENT_STATE`, `OBSERVED_MOVEMENT`, `COMPARABLE_WINDOW` and `CERTIFIED_PERIOD_COMPARISON` gates for each governed metric, scope, filter, horizon and cutoff.
- Added immutable system-owned monitoring baselines, certified daily observation-completion evidence, integrity hashes and bounded establishment audit records.
- Added governed analytical provenance with recursive weakest-input inheritance and calculation-layer rejection of `UNCERTIFIED_RECONSTRUCTION`.
- Added controlled formula/source lineage, client-facing confidence inspection and consistent activation, provenance, coverage and suppression evidence for screen, PDF, CSV, scheduled output and report history.

### Changed

- Restricted materiality and Executive insight generation to certified equal-period comparisons; current values and factual monitoring movement remain outside that pipeline.
- Preserved certified zero observations through the completion manifest even when no rollup row is emitted.
- Matched report insight domains to the selected dashboard composition and preserved exact recursive entity scope in immediate and scheduled reports.
- Added stable observed-movement context to applicable KPI cards without presenting it as period comparison, materiality or Executive insight.
- Preserved formula ownership in mixed analytical output: Phase 5A calculations and suppressions retain `phase5a-1`, while already-existing Phase 5B calculations retain `phase5b-1`.

### Security

- Rejects analytical group filters outside the active authorized entity scope before querying or calculating results.
- Preserves read-only evidence navigation and existing profile/export/scheduling authorization boundaries.

### Validation

- Added structural and calculation coverage for all activation states and horizons, cold start, horizon switching, baseline retention, certified zeroes, provenance inheritance, uncertified rejection, formula/source registry completeness, suppression evidence and export parity.
- Added a compact Laragon integration inspector for real-database horizon, provenance, activation and out-of-scope group-filter verification.

## [0.14.1-dev] - 2026-08-12

### Fixed

- Moved the widget-settings drawer to the document body so GridStack widget stacking order cannot paint charts, tables, resize handles or neighbouring cards over it.
- Preserved widget palette variables in the body-level drawer and focused the first settings control when it opens.
- Added structural regression checks requiring document-level drawer rendering and prohibiting the ineffective nested-card z-index workaround.

### Validation

- Verify settings from early, middle and final dashboard widgets across KPI, chart, table, matrix and donut presentations.
- Verify the complete drawer remains above all widgets while scrolling and that Cancel, Apply & close and Escape work.
