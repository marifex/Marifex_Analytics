# Controlled dashboard design scope

Status: **Approved implementation contract**
Decision date: **2026-08-09**

This document is the single controlled specification for the MarifeX dashboard presentation redesign and the narrowly approved supporting data additions. It consolidates the decisions drawn from the five supplied dashboard samples, the two Gemini reviews, the Claude review and the final MarifeX scope decisions.

If this document conflicts with an earlier dashboard-layout statement, this document wins. Existing security, entity isolation, certified semantic metrics, GLPI Home integration, Vue 3, Apache ECharts and plugin-owned Data Mart architecture remain unchanged except for the explicit additions below.

## Change-control rule

- No metric, dimension, widget type, dataset, filter, module or architectural change may be implemented unless it is explicitly named here or subsequently approved by the user in writing and added here first.
- Examples shown in competitor material are not approved scope by implication.
- Silence, visual similarity and technical convenience are not approval.
- Implementation must use the exact certified metric meanings; titles must not silently change their business definitions.
- Scope additions must be documented before product code is changed.

## Design problem being corrected

The current Executive dashboard presents too many equally prominent, oversized cards. It creates a long wall of bricks, weak hierarchy and excessive scrolling. The redesign must increase decision-useful information before the first scroll while remaining readable, responsive and user-editable.

## Approved lessons from the five supplied samples

1. Use a short KPI strip rather than chart-height number cards.
2. Give KPIs an immediately previous, equal-length period comparison where historical data exists.
3. Permit text insight KPIs that show the leading label from an existing certified dimension metric.
4. Mix compact tables, rankings, distributions and trends in the same viewport; tables are not automatically lower-priority than charts.
5. Use consistent row heights with deliberately different column spans to establish hierarchy.
6. Use area fills selectively for magnitude and trend emphasis.
7. Use horizontal bars for rankings and long GLPI labels.
8. Use donuts only for small part-to-whole sets, with the chart left and a complete legend right.
9. Keep detailed, actionable information visible through compact lists and native GLPI drill-downs.
10. Avoid gauges, speedometers, oversized single-number cards, overflowing legends, truncated titles and decorative chart variety.
11. Dual-axis correlation charts are allowed only after both related measures are separately certified and the relationship is analytically defensible.
12. The samples guide presentation patterns; their example business metrics are not automatically MarifeX metrics.

## Executive dashboard: required first-screen composition

The default Executive Operations Command dashboard at a 1440 x 900 viewport uses the following composition after GLPI navigation chrome. Heights are target rendered heights with a tolerance of 8 pixels for GLPI theme differences.

| Row | Grid | Required content | Target height |
|---|---:|---|---:|
| Dashboard toolbar | 12 | Title, dashboard selector, horizon, group filter, refresh, export, schedule and edit actions | 48 px |
| KPI strip | six widgets at 2 columns each | Open tickets; unassigned tickets; open SLA breaches; approaching SLA breach; average ticket age; low-disk computers | 96 px |
| Primary analysis | 7 + 5 | Created versus resolved area/trend; technician workload horizontal ranking | 244 px |
| Action and composition | 7 + 5 | Composite operational-attention list; open tickets by priority | 244 px |

The rows use 16-pixel gaps. The first-screen target is approximately 664 pixels of dashboard content. At 1920 x 1080 the same hierarchy remains; widgets may gain internal plotting space but must not become unnecessarily taller.

All other approved metrics remain accessible below the first screen and through optional focused dashboards. “Available” does not mean every metric must be an independent large card.

## Required KPI behaviour

Compact KPI widgets contain:

- a short sentence-case label;
- the current certified value;
- the previous equal-length period value when the metric has historical rollups;
- a direction indicator and percentage or absolute delta;
- an optional micro-sparkline derived from the same certified series;
- a unit where required; and
- semantic status styling only when the metric has an approved threshold meaning.

Comparison values and sparklines are derived presentations, not new business metrics. A live-only value without comparable history must display context without inventing a delta.

## Approved presentation types

- Compact KPI with comparison and optional sparkline
- Text insight KPI derived from the leading row of an approved dimension metric
- Composite operational-attention list
- Area or line trend
- Two-series comparison trend using a shared unit
- Horizontal ranking bar
- Vertical age-band bar
- Stacked bar only when its components form an approved part-to-whole comparison
- Donut with no more than five visible slices plus an automatic Other grouping
- Compact ranked table with 6 to 8 visible rows
- Detailed table with native GLPI drill-down
- Section heading without an additional enclosing card

Pie charts and gauges are not approved. Dual axes are not approved until the paired metrics are added to this document. New chart types do not authorize new metrics.

## Approved derived text insights

The following are alternative presentations of existing certified dimensions and do not create new metric definitions:

- technician with the most SLA breaches from `sla_breaches_by_technician`;
- operating system with the most incidents from `incidents_by_operating_system`;
- group with the largest backlog from `historical_group_backlog`;
- leading request source from `tickets_by_request_source`; and
- highest workload technician from `technician_workload_distribution`.

The widget must show both the leading label and its value and must link to an applicable governed drill-down where available.

## Composite operational-attention list

One compact Executive widget may combine the latest values of these existing certified findings:

- open SLA breaches;
- tickets approaching SLA breach;
- unassigned open tickets;
- unsatisfied survey responses;
- stale computer inventory;
- low-disk computers;
- computers in stock over 30 days;
- invalid software installations;
- installations above entitlement; and
- repeat-incident computers.

The list must identify the finding, current count, severity class and drill-down target. It must not merge or reinterpret the underlying metric definitions.

## Below-the-fold organization

Remaining widgets are organized under these section headings rather than rendered as one uninterrupted card stream:

1. Service health
2. Workload and ownership
3. Customer experience
4. Asset attention
5. Software and licence risk
6. Change and problem control

Standard analytical rows use 7 + 5, 8 + 4 or 6 + 6 spans according to information importance and label length. A 12-column table is allowed when its records need full-width evidence. Rows must align at their top and bottom edges.

## Approved new certified data products

These additions are approved scope. Their implementation must use plugin-owned governed services, active-entity restrictions and fixed field allowlists.

### `latest_solution_refused_tickets`

- Meaning: non-closed tickets whose latest GLPI ITIL solution has status `Refused` at the observation cutoff.
- Presentations: daily count KPI and compact current ticket list.
- Required list fields: ticket ID, title, priority, status, assigned group, latest solution date and native GLPI ticket link.
- The implementation must use GLPI's latest-solution semantics; any refused historical solution that is not the latest must not qualify.

### `open_incidents_by_assignment_group`

- Meaning: open ticket records of GLPI incident type, grouped by their current assigned group at the observation cutoff.
- Presentations: horizontal ranking bar and ranked table.
- Unassigned incidents are represented explicitly as Unassigned, not dropped.
- Group labels remain entity-disambiguated.

### `open_tickets_priority_category_matrix`

- Meaning: open tickets grouped simultaneously by current GLPI priority and ITIL category at the observation cutoff.
- Presentation: heatmap matrix or stacked horizontal bar.
- The Data Mart may add the minimum plugin-owned two-dimensional rollup structure required for this metric. It must not alter GLPI core tables and must not encode arbitrary user-defined dimensions.
- Empty/unspecified categories remain explicit.

### `active_sla_exceptions`

- Meaning: current open tickets that are already beyond their time-to-resolve SLA deadline or fall within the approved approaching-breach window.
- Presentation: compact governed exception list with 6 to 8 visible rows.
- Fixed fields: ticket ID, title, priority, assigned group, SLA deadline, state (`Breached` or `Approaching`), elapsed overdue or remaining time, and native GLPI link.
- This is a current operational dataset, not a historical metric claim. It may query current GLPI tables through a fixed, entity-scoped service; arbitrary SQL and user-selected columns are prohibited.
- Ordering: breached first, then greatest overdue duration, then nearest approaching deadline.

## Existing metrics used as views, not duplicated metrics

- “Very-high-priority open incidents/tickets” is a filtered or leading-category presentation of `open_tickets_by_priority`. The UI must use GLPI priority terminology and must not call it P1 without a separately approved organizational mapping.
- “Incident-prone OS” is a text insight from `incidents_by_operating_system`.
- “Top SLA breach technician” is a text insight from `sla_breaches_by_technician`.
- Period comparisons and micro-sparklines use existing historical series.

## Visual system

### Structure

- Page background: `#F7F8FA`
- Normal chart/table surface: white or the selected palette's lightest accessible tint
- Border: `1px solid #E5E7EB`
- Normal radius: 6 px
- Normal widget shadow: none
- Grid gap: 16 px
- Chart/table padding: 12 px
- KPI padding: 8 px vertical and 12 px horizontal
- Cream-to-gold restrained gradient remains the default dashboard-header treatment

### Typography

| Element | Size | Weight | Colour |
|---|---:|---:|---|
| Dashboard title | 16 px | 600 | `#1F2937` or accessible header contrast |
| Widget title | 13 px | 500 | `#374151` |
| KPI label | 11 px | 500 | `#6B7280` or accessible fill contrast |
| KPI value | 28 px | 700 | `#1F2937` or accessible fill contrast |
| KPI comparison | 11 px | 400 | `#9CA3AF` or accessible fill contrast |
| Table header | 11 px | 600 | `#374151` |
| Table cell | 12 px | 400 | `#1F2937` |
| Chart axis | 10 px | 400 | `#6B7280` minimum contrast |
| Chart data label | 11 px | 500 | `#374151` |

Titles use sentence case, wrap to at most two lines and never use ellipsis as the default solution. Content must resize inside the widget without changing aligned row boundaries.

### Colour governance

- Preserve the curated per-widget palette selector already approved in Phase 3.
- The selector remains allowlisted; arbitrary user-entered colours are prohibited.
- On chart and table widgets, palettes primarily affect series, accents, borders and very light surface tints rather than saturated card backgrounds.
- KPI widgets may use a stronger selected gradient or semantic fill when contrast remains accessible.
- Semantic colours are reserved for actual status: critical `#D93A3A`, warning `#F08C1A`, healthy `#2D8A56`, informational `#2268D4`.
- Semantic colour must be paired with an icon, label or other non-colour indicator.
- Default categorical series: `#4A6B8C`, `#7B5C82`, `#568774`, `#A37E58`, `#6B7075`.

## Size, drag and resize rules

- Mouse/touch drag and corner-grip resize remain mandatory; developer-facing W/H buttons remain prohibited.
- In edit mode the full widget header is the drag surface; the six-dot icon is only a visual affordance. Settings, remove and resize controls must stop pointer propagation so they never start a move.
- Default compact KPI rendered height is 96 px. Allowed compact/standard KPI size classes are 80, 96 and 120 px.
- Above-fold analytical widgets target 244 px; standard detail charts target 280 px; expanded charts target 320 px.
- Compact tables target 244 or 280 px; detailed tables may use 320 px or a full-width deep-dive view.
- Resizing snaps to the approved type-specific size classes. Users cannot create arbitrary one-pixel heights.
- Width constraints: KPI 2 to 4 columns; standard chart 4 to 8 columns; detailed chart 6 to 12 columns; compact table 5 to 8 columns; detailed table 8 to 12 columns.
- `ResizeObserver` remains widget-local and must be debounced approximately 100 to 200 milliseconds during resize.
- User-selected ordering and placement remain stable. A drop records explicit 12-column canvas X/Y coordinates, including intentional vertical gaps; automatic packing or array-only reordering must not override deliberate placement.
- Empty internal space must be minimized by reflowing chart plot area, axes, legend, labels and table rows for the selected size.
- Widget settings open in a fixed, viewport-contained drawer above the dashboard canvas. Title and palette controls align from the top in one column, remain fully visible below GLPI's sticky header and never change widget geometry.

## Responsive rules

- At 1440 px and wider, the default first screen uses the six-KPI and 7 + 5 layout specified above.
- At 1200 to 1439 px, KPI tiles may reflow to three per row and analytical pairs may use 6 + 6 when label fit requires it.
- At 768 to 1199 px, KPI tiles reflow two per row and analytical widgets become full width.
- Below 768 px, all widgets become one column; chart legends move below only when a right-side legend cannot meet minimum plot width.
- Responsive reflow changes presentation placement, not saved semantic filters or metric meaning.

## Explicit exclusions

The following remain outside approved scope:

- active-technician presence or availability;
- average cost per request or labour-cost analytics;
- major-outage inference without an approved GLPI classification;
- patch-versus-incident or computer-addition correlation;
- P1 terminology without a governed priority mapping;
- AI narrative cards, prediction and anomaly inference;
- arbitrary formulas, custom SQL or custom dimensions;
- user-created data sources;
- external BI embedding;
- TV/kiosk mode;
- new timezone override controls;
- Phase 6 MSP/customer comparison features; and
- Phase 7 predictive/AI features.

## Acceptance criteria

1. At 1440 x 900, the toolbar, six KPIs, primary analysis row and action/composition row are visible without dashboard scrolling, subject only to unavoidable GLPI/browser chrome variation.
2. The Executive dashboard no longer presents all metrics as independent equal-height cards.
3. KPIs show correct previous-period context where governed history exists.
4. The attention list contains only the approved component metrics and preserves their definitions.
5. Text insight KPIs are derived from approved dimension metrics and show label plus value.
6. No normal widget has a heavy shadow; rows align and surfaces remain visually restrained.
7. Donuts show at most five slices plus Other, with chart left and complete legend right.
8. Rankings use horizontal bars and cap visible categories, with governed access to the complete result.
9. Compact tables show 6 to 8 useful rows without internal horizontal scrolling at their approved width.
10. Header drag can place a widget at any unoccupied 12-column canvas coordinate; X/Y placement and size survive save/reload, while cancel restores the previous geometry.
11. Resize, responsive reflow and chart resizing pass browser integration tests, and the settings drawer remains fully visible and top-aligned at supported desktop widths.
12. New data products enforce active GLPI entity scope, profile rights and fixed semantic fields.
13. No excluded metric, widget, field or feature appears in code, configuration or dashboard templates.
14. Structural tests verify the certified allowlists and the first-screen default layout.
15. Any future deviation requires a written scope amendment before implementation.
