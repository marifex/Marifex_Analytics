# Changelog

All notable MarifeX Advanced Analytics changes are recorded here.

The format follows Keep a Changelog.

## [Unreleased]

## [0.15.1] - 2026-08-24

### Fixed

- Declared MarifeX consistently as the author and maintainer in GLPI, Composer, npm package and project documentation metadata.
- Added release-package checks that reject missing MarifeX authorship metadata.
- Added the explicitly approved chrome-free mobile dashboard route for the MarifeX Android WebView, using the existing dashboard authorization and endpoint wiring without changing the GLPI Home dashboard.
- Loaded GridStack and the dashboard bundle at the end of the mobile page so the layout engine and Vue mount point are ready in the correct order.
- Removed the unsupported generated PDF page counter that rendered as Page 0.
- Distinguished live SLA exceptions from the certified SLA snapshot in operational attention.
- Added live and certified snapshot timestamps to PDF and CSV measures, and labelled donut totals as the records represented by that certified distribution.
- Replaced internal phase, activation, provenance and generic dimension terms in the client PDF with clear business language while retaining exact technical evidence in CSV and audit records.
- Corrected PDF pagination, typography, section placement, long-label wrapping and blank-widget presentation so headings and report cards remain readable without overlap or clipping.

- Corrected the public website and support contact across release metadata and the user guide.
- Replaced the internal recursive-entity phrase “and descendants” with the client-facing scope description “enterprise-wide.”
### Licensing

- Released MarifeX Advanced Analytics under the GNU General Public License version 3.



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
- Redesigned PDF exports as governed executive reports with a compact scope header, executive insight brief, key certified measures, sectioned analytical content, repeated page furniture and a separate analytical confidence appendix.
- Reordered CSV exports into a business-first report structure: findings and readable metric summaries appear before suppressed-calculation status and supporting evidence detail, while retaining formula, activation, provenance, scope and materiality fields.
- Replaced internal entity identifiers in report presentation with the authorized human-readable entity and group scope labels.

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
