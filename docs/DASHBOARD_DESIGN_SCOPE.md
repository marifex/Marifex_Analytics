# Controlled dashboard design scope

Status: **Approved implementation contract**
Decision date: **2026-08-09**
Phase 5A amendment approved: **2026-08-10**

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

## Phase 5A: deterministic analytical insight layer

Phase 5A extends the approved dashboard from count-oriented reporting to governed movement, flow, composition and concentration analysis. Every statement is produced by a fixed template from certified values. Phase 5A does not introduce AI narrative, prediction, anomaly inference, external benchmarking, arbitrary formulas, custom SQL or causal claims. The fixed calculations named below are approved derived presentations; they do not authorize a user formula editor or unnamed calculations.

### Analytical questions

Phase 5A must help a user answer:

1. What changed during the selected period?
2. Is the movement materially large?
3. Where is the workload or exposure concentrated?
4. Which single certified dimension changed the most?
5. Is the evidence live, snapshot-based, fresh and comparison-ready?
6. Which governed GLPI evidence should the user open?

### Comparison windows and cold start

- Comparisons follow the dashboard's selected 7, 30, 90, 180 or 365-day horizon. The comparison is always the immediately previous equal-length period; Phase 5A does not introduce a separate fixed weekly comparison.
- A comparison requires `2 x horizon` consecutive completed daily snapshots within the active entity scope. A 30-day comparison therefore requires 60 completed daily snapshots. Missing required days keep the comparison unavailable.
- An endpoint-change measure also requires the certified boundary snapshot immediately before each compared period. Boundary snapshots do not alter the `2 x horizon` daily-bucket progress count, but a missing boundary suppresses only the affected endpoint measure as `INSUFFICIENT_HISTORY`.
- Comparison readiness is calculated independently for the active entity scope, group filter, metric, horizon and cutoff. It is not a global dashboard flag.
- Switching from a comparison-ready horizon to an unready horizon immediately removes the previous comparison and shows progress for the newly selected horizon. Switching back restores only the comparison valid for that horizon. Cached calculations must be keyed by entity scope, filter, metric, horizon and cutoff.
- During cold start, current certified absolute values remain visible, while comparison, sustained-direction and contributor-movement claims are suppressed. The insight strip must not say `No material changes` when comparison history is incomplete.
- Cold-start text identifies the selected baseline and progress, for example: `Building 30-day comparison baseline: 37 of 60 completed snapshots.` When the required snapshots are consecutive and scheduled future snapshots are healthy, the UI may also show the calculated availability date.

| Selected horizon | Consecutive completed snapshots required |
|---:|---:|
| 7 days | 14 |
| 30 days | 60 |
| 90 days | 180 |
| 180 days | 360 |
| 365 days | 730 |

### Approved derived measures

All numerators and denominators are filtered to the active GLPI entity scope before aggregation. Ratios across multiple visible entities are calculated from the summed scoped numerators and denominators; entity-level ratios are never averaged or calculated globally and filtered afterward.

| Measure | Controlled definition | Grain and comparison | Zero handling | Direction |
|---|---|---|---|---|
| Net ticket flow | tickets created minus tickets resolved | Sum over selected horizon versus previous equal horizon | Suppress when both values are zero | Negative is improving |
| Resolution coverage | tickets resolved divided by tickets created | Sum over selected horizon versus previous equal horizon | When created is zero, show `No new tickets`; never show infinity | Above 100% indicates resolution exceeding arrival |
| Backlog growth rate | `(open at period end - open at period start) / open at period start` | Selected horizon versus previous equal horizon | When start is zero, show absolute `0 to N` movement only | Negative is improving; zero is neutral |
| Unassigned rate | unassigned open tickets divided by total open tickets at the same completed snapshot | Latest completed cutoff versus the equivalent previous-period cutoff | Suppress when total open is zero | Decreasing is improving |
| High-priority backlog share | open tickets at GLPI priorities 4 High, 5 Very high and 6 Major divided by total open tickets | Same completed snapshot and previous equivalent cutoff | Suppress when total open is zero | Decreasing is improving |
| Top-group workload share | largest assigned-group backlog divided by total assigned-group backlog; Unassigned is excluded | Same completed snapshot and previous equivalent cutoff | Suppress when no tickets are assigned | Neutral unless the informational majority rule applies |
| Open request-source concentration | largest source within `tickets_by_request_source` divided by all open tickets represented by that certified metric | Same completed snapshot and previous equivalent cutoff | Suppress when the represented total is zero | Context-neutral; it must be described as open backlog composition, not incoming demand |
| Stale-inventory exposure | `stale_computer_inventory / asset_inventory_total` using the same completed snapshot | Current completed cutoff versus the equivalent 30-day-ago cutoff when available | Suppress when managed non-template computer count is zero | Decreasing is improving |
| Change net flow | changes created minus changes resolved | Sum over selected horizon versus previous equal horizon | Suppress when both values are zero | Negative is improving |
| Change resolution coverage | resolved changes divided by created changes | Sum over selected horizon versus previous equal horizon | When created is zero, show `No new changes` | Above 100% indicates resolution exceeding arrival |
| Problem net flow | problems created minus problems resolved | Sum over selected horizon versus previous equal horizon | Suppress when both values are zero | Negative is improving |
| Problem resolution coverage | resolved problems divided by created problems | Sum over selected horizon versus previous equal horizon | When created is zero, show `No new problems` | Above 100% indicates resolution exceeding arrival |

### Approved analytical use of existing measures

- SLA exposure context combines the separately certified `sla_breach_count`, `sla_breach_rate` and `tickets_approaching_sla_breach` presentations. Phase 5A does not create a combined SLA risk percentage and does not replace the approved approaching-breach definition with a percentage-of-target threshold.
- `unsatisfied_survey_responses` may show count movement because it is a historical integer series. A dissatisfaction rate remains unapproved until total survey responses are certified as its denominator.
- `repeat_incident_computers` may show count and ranking movement using its existing trailing 30-day definition. Phase 5A does not change it to 90 days and does not create a rate without a certified incident-linked-asset denominator.
- `latest_solution_refused_tickets` remains a live current count and evidence list. It must not show previous-period movement until a historical refused-solution rollup is separately certified, and it must not show a refusal rate until the proposed-solution denominator is certified.
- Existing `software_license_compliance_rate` and `software_license_overallocated_seats` may show governed movement in the Asset and Licence dashboard. Phase 5A does not create an aggregate licence-utilization or coverage-gap ratio that could conceal title-level overallocation, and licence ratios do not enter the Executive brief.
- Created-ticket demand by request source is analytically distinct from the approved open-ticket request-source metric and is deferred for separate certification.

### Phase 5A technical formula appendix

This appendix is normative. Implementation, structural tests, browser tests and exports must use these definitions exactly. Developers must not reconstruct formulas from UI wording or conversation history. The initial formula-set identifier is `phase5a-1` and must be retained with exported or scheduled insight evidence. A formula change requires a new identifier and a written scope amendment.

#### Period boundaries

For a completed snapshot cutoff date `C` and selected horizon `H` days:

- current period daily buckets are `C-H+1` through `C`, inclusive;
- previous period daily buckets are `C-2H+1` through `C-H`, inclusive;
- each period must contain exactly `H` consecutive completed buckets;
- point-in-time measures compare the completed snapshot at `C` with the completed snapshot at `C-H`;
- endpoint-change measures use `C-H` as the current-period start boundary and `C-2H` as the previous-period start boundary; these boundary snapshots are evidence requirements, not additional daily buckets;
- a group-filtered insight uses the same boundaries after applying that certified group filter; and
- no partial period is scaled, annualized or compared with a complete period.

#### Formula input map

| Derived measure | Certified input keys | Exact calculation |
|---|---|---|
| Net ticket flow | `created_vs_resolved_tickets` | `sum(Created_current) - sum(Resolved_current)` |
| Resolution coverage | `created_vs_resolved_tickets` | `sum(Resolved_current) / sum(Created_current) * 100` |
| Backlog growth rate | `historical_open_backlog` | `(Backlog_C - Backlog_C-H) / Backlog_C-H * 100` |
| Unassigned rate | `unassigned_open_tickets`, `historical_open_backlog` | `Unassigned_C / Backlog_C * 100` |
| High-priority backlog share | `open_tickets_by_priority` | `sum(value_C where GLPI priority in [4,5,6]) / sum(all priority values_C) * 100` |
| Top-group workload share | `historical_group_backlog` | `max(assigned group value_C) / sum(all assigned group values_C) * 100`; exclude the certified `Unassigned` dimension from numerator and denominator |
| Open request-source concentration | `tickets_by_request_source` | `max(source value_C) / sum(all source values_C) * 100` |
| Stale-inventory exposure | `stale_computer_inventory`, `asset_inventory_total` | `StaleComputers_C / ManagedNonTemplateComputers_C * 100` |
| Change net flow | `daily_change_volume`, `daily_change_resolutions` | `sum(ChangeCreated_current) - sum(ChangeResolved_current)` |
| Change resolution coverage | `daily_change_volume`, `daily_change_resolutions` | `sum(ChangeResolved_current) / sum(ChangeCreated_current) * 100` |
| Problem net flow | `daily_problem_volume`, `daily_problem_resolutions` | `sum(ProblemCreated_current) - sum(ProblemResolved_current)` |
| Problem resolution coverage | `daily_problem_volume`, `daily_problem_resolutions` | `sum(ProblemResolved_current) / sum(ProblemCreated_current) * 100` |

The immediately previous value of a period calculation is produced by applying the identical formula to the previous-period buckets. The previous backlog growth rate is therefore `(Backlog_C-H - Backlog_C-2H) / Backlog_C-2H * 100`. The immediately previous value of a point-in-time calculation is produced from the `C-H` cutoff. Input dimensions are matched by their certified semantic identifiers, never by translated display labels.

#### Changes, units and rounding

- Count and net-flow values are integers.
- Ratios, rates, composition shares and coverage values are percentages rounded to one decimal place for display. Calculations and materiality evaluation use unrounded values.
- `absolute_change = current_value - previous_value` in the measure's native unit.
- For percentage-valued measures, `percentage_point_change = current_percentage - previous_percentage`, rounded to one decimal place for display.
- For a non-zero previous value, `relative_change_percent = (current_value - previous_value) / abs(previous_value) * 100`, rounded to one decimal place for display.
- Relative change is not calculated when the previous value is zero. The approved `New from zero` handling applies instead.
- A direction arrow reflects the sign of the absolute change. Semantic improving/worsening classification separately follows the direction defined in the approved-measures table; context-neutral measures receive no healthy/risk classification.
- A displayed value of `0.0%` must be a computed zero, not a missing, suppressed or below-denominator result.

#### Denominator suppression

- Every operational ratio, rate, coverage or share requires a denominator of at least 5 unless a stricter metric-specific floor is approved below.
- The future refused-solution rate requires at least 10 proposed solutions and remains deferred until its denominator and historical numerator are certified.
- The future dissatisfaction rate requires at least 30 survey responses and remains deferred until its denominator is certified. Any future configurable floor must never be lower than 20.
- A measure below its floor is not zero. It returns suppression reason `DENOMINATOR_BELOW_MINIMUM`, displays `Insufficient data: N of M required`, and is excluded from materiality, ranking, contributor analysis and the Executive brief.
- Coverage calculations with a zero arrival denominator use the approved `No new tickets`, `No new changes` or `No new problems` text and do not return a numeric percentage.

#### Previous-period movement and materiality

- Count and net-flow materiality use the absolute change in their native integer unit and the relative change percentage.
- Rate and composition materiality use the absolute percentage-point change and the relative change percentage of the prior rate/share.
- The two normal gates are inclusive: a movement passes when the absolute change is greater than or equal to its absolute floor and the absolute relative change is greater than or equal to 10%.
- `materiality_score = min(abs(absolute_change) / absolute_floor, abs(relative_change_percent) / 10)` for a normal comparable movement.
- For an approved zero-baseline transition, `materiality_score = abs(absolute_change) / absolute_floor`.
- `New from zero` requires the current absolute value to meet the applicable absolute floor.
- `Cleared to zero` requires the previous absolute value to meet the applicable absolute floor.
- A suppressed, missing, stale, unauthorized or comparison-unready measure has no materiality score.

#### Largest contributing dimension change

For an approved dimension metric with the same certified grain in both periods:

1. Apply entity, profile and dashboard filters before aggregation.
2. Build the union of authorized dimension identifiers present in either period.
3. Treat an absent authorized dimension value as zero for that period.
4. Calculate `dimension_delta = current_dimension_value - previous_dimension_value` for every authorized identifier.
5. Select the identifier with the largest absolute `dimension_delta`.
6. Break an exact tie by the stable certified dimension identifier, ascending.
7. Display the dimension delta in its native unit, for example `Service Desk L1 +6 tickets`.

Phase 5A does not convert the dimension delta into a causal percentage contribution. When the aggregate movement is zero, contributor text is suppressed even if dimensions moved in offsetting directions. When the selected dimension is not authorized or no comparable dimension exists, contributor text is omitted without suppressing the parent insight.

#### Fixed deterministic insight template

Every expanded insight is assembled from these fields in this order:

1. certified metric label;
2. direction and current value;
3. absolute movement and relative or percentage-point movement;
4. `versus previous H days` comparison text;
5. largest contributing dimension change when valid;
6. source classification: `Snapshot as of timestamp` or `Live GLPI at timestamp`;
7. governed evidence action; and
8. calculation-inspection action exposing formula identifier `phase5a-1` and its inputs.

The template engine uses allowlisted phrases. Free-generated narrative and causal connectors are prohibited.

#### Standard suppression reasons

Phase 5A uses these stable reasons across screen, PDF, CSV and scheduled output:

| Code | Meaning |
|---|---|
| `INSUFFICIENT_HISTORY` | Two complete selected horizons or required cutoff snapshots are unavailable |
| `DENOMINATOR_BELOW_MINIMUM` | A ratio denominator is below its approved floor |
| `MISSING_SOURCE` | A required source has never completed or has no certified data |
| `UNAVAILABLE_SOURCE` | A required governed pipeline is disabled or unavailable |
| `STALE_SOURCE` | A required source exceeded its cadence-based freshness deadline |
| `UNAUTHORIZED_DIMENSION` | Contributor evidence is outside the active entity/profile scope |
| `NO_MATERIAL_CHANGE` | Valid movement failed one or both materiality gates |
| `NO_ACTIVITY` | Both sides of an approved flow calculation are zero |

Exports may include the stable suppression code and safe explanatory text, but must not include unauthorized dimension labels, record identifiers or recipient addresses.

### Materiality, zero transitions and ranking

An insight enters the Executive brief only after passing the approved materiality rules. Normal movement requires both the absolute and relative gates.

| Measure class | Absolute gate | Relative gate |
|---|---:|---:|
| Count | 5 records | 10% of previous value |
| Rate | 3 percentage points | 10% of previous value |
| Net flow | 10 records | 10% of previous value |
| Composition or concentration share | 5 percentage points | 10% of previous value |

- Thresholds are fixed in a version-controlled analytical rule registry. Users cannot enter formulas or arbitrary thresholds. A changed threshold is a controlled product/scope change.
- When the previous value is zero, relative movement is undefined. The absolute gate still applies; qualifying movement is labelled `New from zero`.
- When the current value becomes zero, qualifying movement is labelled `Cleared to zero`. The previous value must meet the normal absolute floor.
- Only a metric explicitly named in the version-controlled critical-bypass registry may bypass the absolute floor for a zero transition. Phase 5A creates no user-configurable bypass.
- For comparable non-zero movements, the normalized materiality score is the lesser of `absolute change / absolute gate` and `absolute relative change / relative gate`. For an approved zero transition, the score is `absolute change / absolute gate`.
- Insights rank worsening movements first, then improving movements. Within a direction they rank by normalized materiality score, then by this fixed class order: SLA exposure; backlog/flow/unassigned/priority; customer quality; asset/licence; change/problem. Exact ties use the certified metric key as a stable final ordering.
- No more than five insights appear simultaneously. If none passes and history is ready, the brief says `No material snapshot changes in the selected period.`

### Sustained direction and contributor analysis

- `Momentum`, predictive-force wording and forecast language are prohibited. A sustained-direction statement is allowed only after the same certified measure moved in the same direction for at least three consecutive equal-length comparison periods.
- Phase 5A identifies at most one largest contributing dimension change for a material metric and only when the same certified dimensional grain exists for both periods.
- Contributor analysis is computed inside the active entity and profile scope. A dimension value that the user cannot view must not appear.
- Approved wording is `Largest contributing dimension change`. The words `caused by`, `because`, `due to`, `resulted in`, `primary driver` and equivalent causal language are prohibited.
- Multi-dimensional decomposition is outside Phase 5A.

### Informational majority concentration

- If one assignment group holds strictly more than 50% of the assigned workload, at least three groups have non-zero assigned workload and the normal ratio denominator gate is satisfied, the workload widget may show an informational blue `Majority concentration` indicator.
- The indicator is factual and neutral. It must not be labelled critical, unhealthy, imbalanced or worsening.
- Majority concentration enters the Executive brief only when its period movement also passes the normal composition-share materiality gates.
- The threshold is fixed and non-configurable in Phase 5A. Per-group or specialist-group thresholds require a future governed threshold policy.

### Freshness and analytical readiness

- A pipeline-derived source becomes stale at `last successful completion + 1.5 x expected interval`, rounded upward to the nearest whole hour. For example, a six-hour cadence becomes stale after nine hours and a daily cadence after 36 hours.
- The expected interval comes from the governed GLPI automatic-action schedule. A required pipeline that has never completed is `Missing`; a disabled required pipeline is `Unavailable`.
- Live products show their query timestamp and are not assigned pipeline freshness.
- A derived measure using multiple sources inherits the worst source state and uses the oldest contributing source timestamp as its effective freshness time.
- Missing, unavailable or stale measures cannot enter the Executive brief. Their dependent callouts are individually suppressed; unrelated healthy insights remain available.
- Settings shows factual analytical readiness coverage rather than a composite quality score, for example: `31 of 43 certified metrics are current; 8 have sufficient comparison history.`
- The core comparison-readiness list is: `historical_open_backlog`, `created_vs_resolved_tickets`, `unassigned_open_tickets`, `tickets_approaching_sla_breach`, `sla_breach_count`, `sla_breach_rate`, `open_tickets_by_priority`, `historical_group_backlog`, `technician_workload_distribution` and `sla_breaches_by_technician`.
- Live operational availability is reported separately for `current_open_tickets`, `active_sla_exceptions`, `operational_attention` and `latest_solution_refused_tickets`.
- When a core insight source is not ready, the dashboard displays a compact readiness warning and Settings names the affected metrics.

### Insight presentation and calculation transparency

- KPI tiles retain their approved height. They add the absolute movement, direction, previous value and comparison context without creating another count card.
- Existing charts, rankings and tables may highlight the relevant material point, row or segment and show a compact deterministic callout. These annotations must not reduce plot or label legibility.
- A thin insight strip sits between the KPI and primary-analysis rows. Its collapsed state is approximately 32 px and shows the strongest material findings in one line rather than only a count of findings. Its user-expanded state is approximately 160 to 200 px and shows at most five insights. Expansion is deliberate and may move lower content below the fold.
- Each expanded insight contains: metric and direction; current and previous values; absolute and percentage or percentage-point movement; comparison window; largest contributing dimension when valid; source and freshness; governed evidence action; and calculation-inspection action.
- Calculation inspection displays the exact numerator, denominator, source values, formula version, comparison window, zero/suppression handling and result.
- When insufficient history, denominator or freshness suppresses an insight, the UI states the exact reason and does not render an empty card.
- Insight-level tabs are prohibited. Phase 5A uses the existing dashboard selector, existing Executive and focused dashboards, existing user-owned dashboards, duplication, templates, drag, resize and saved layouts. It creates no second view-management system and does not restrict the Phase 3 dashboard ownership model.

### Export, scheduling, security and audit

- PDF exports place a compact insight summary on page one and include the effective snapshot and freshness context. Evidence may use a governed authenticated GLPI link or a clear record reference; a page reference is allowed only when the referenced evidence is actually embedded in that PDF.
- The existing CSV export remains one CSV file. Phase 5A uses an explicit `record_type` discriminator such as `metric`, `derived_measure` or `insight`; CSV output must not claim workbook sheets. Calculation fields use self-documenting headers or columns.
- Screen, PDF, CSV and scheduled output apply identical denominator, materiality, freshness and authorization rules. Suppressed screen insights do not reappear as unsupported claims in exports.
- Scheduled reports continue to execute with the owner's current rights and entity context, revalidating owner, entity and recipients before generation. Phase 5A does not introduce unapproved per-recipient impersonation or cached cross-session insights.
- Entity and profile filtering is applied before aggregation and contributor selection. A derived value, contributor or evidence link must never expose a group, entity or record outside the current authorization scope.
- Export and scheduled-report history stores the insight formula-version identifier, scoped source values, comparison values, applicable gates, pass/fail results and surfaced insights. Configuration and scope-version changes are audited.
- Phase 5A does not log every successful interactive dashboard render. Interactive failures may use bounded operational logs. Any future per-render behavioral audit requires an approved retention and privacy policy.

### Deferred and excluded analytical capabilities

The following are not approved for Phase 5A implementation:

- created-ticket demand by request source;
- historical refused-solution movement and refused-solution rate;
- dissatisfaction rate without a certified total-response denominator;
- repeat-incident asset rate with a new denominator or changed time grain;
- ticket reopen rate;
- median, P75 or P90 first-response elapsed time;
- multi-dimensional contribution decomposition;
- aggregate licence-utilization or coverage-gap ratios on the Executive dashboard;
- deployment-specific or per-group workload thresholds;
- a new Service Delivery dashboard template or new navigation infrastructure;
- user-entered materiality rules, formulas, dimensions or SQL;
- per-render behavioral audit logging;
- AI narrative, prediction, anomaly inference, external benchmarking or causal conclusions.

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
16. Every Phase 5A derived value follows the numerator, denominator, grain, zero handling and direction defined in this document.
17. Comparison output appears only when two complete selected horizons exist for the active scope and filter; horizon switching never retains an invalid comparison.
18. Cold-start dashboards show baseline progress and current certified values instead of `No material changes` or invented partial-period comparisons.
19. Material insights pass the applicable absolute and relative gates, including the controlled new-from-zero and cleared-to-zero rules.
20. Missing, unavailable or stale sources are excluded from the insight brief according to the approved cadence-based freshness calculation.
21. The Executive brief contains no more than five deterministic insights, uses no causal or predictive language and exposes calculation details and governed evidence.
22. Majority workload concentration uses only the fixed informational rule and never receives negative semantic classification.
23. Existing dashboard selection, ownership, drag, resize and template behaviour remains unchanged; Phase 5A introduces no insight tabs or second view system.
24. PDF, CSV and scheduled output use the same calculations, suppression and authorization rules as the screen; CSV output remains a valid single-file format.
25. Entity and profile restrictions are applied before aggregation and contributor selection, and export history retains formula-version and materiality evidence.
26. No deferred Phase 5B measure or other excluded capability appears in the registry, templates, configuration or output without a later written amendment.

## Phase 5B: certified quality, demand and distribution analytics

This section is the controlled written amendment that authorizes Phase 5B after Phase 5A operational acceptance. Phase 5B extends the existing certified data mart and deterministic insight engine. It does not add AI narrative, prediction, anomaly detection, causal claims, arbitrary user formulas, custom SQL, a second dashboard-management system or a new dashboard template.

Phase 5A is considered operationally accepted when all certified sources are current and at least one supported horizon completes its two-horizon comparison gate. Longer horizons may remain in the approved per-horizon cold-start state while genuine daily snapshots accumulate. Scheduled email delivery is excluded from this acceptance because the deployment mail transport is not configured.

### Phase 5B formula version and common governance

- Formula version: `phase5b-1`.
- Entity, recursive-entity and profile filtering is applied before every numerator, denominator, percentile population and contributor calculation.
- The selected dashboard horizon remains the comparison horizon unless a measure below explicitly defines a fixed trailing window.
- Count and rate movements use the Phase 5A absolute and relative materiality gates. Rates use percentage-point movement for the absolute gate.
- Ratio denominators require at least 5 records unless a stricter measure-specific minimum is defined below.
- Percentile populations require at least 20 valid observations in both current and comparison windows.
- Stale, unavailable, incomplete or below-minimum inputs are suppressed with an explicit reason and never appear as supported findings in screen or export output.
- Equal-length comparison requires two complete horizons. Fixed trailing-window measures compare the current complete trailing window with the immediately preceding equal-length window.
- All contributor language remains non-causal. `Largest contributor` or `largest contributing change` is permitted; `caused by`, `because of`, `due to` and equivalent causal language is prohibited.
- The Executive insight brief remains capped at five findings across Phase 5A and 5B. Adding rules does not add another card row.

### Certified Phase 5B data products and formulas

| Key | Certified definition | Grain and comparison | Gate / direction |
|---|---|---|---|
| `created_tickets_by_request_source` | Daily count of tickets created in the day, grouped by certified GLPI `requesttypes_id`. Unlike the Phase 5A source metric, this is demand flow and excludes older open tickets. | Daily dimension series; selected equal horizons. | Count movement: 5 records and 10%; neutral composition. |
| `ticket_reopen_events` | Count of verified ticket status transitions from solved or closed (`5` or `6`) to an open status (`1` to `4`) during the day. Multiple valid reopen transitions on one ticket remain separate events. | Daily integer series; selected equal horizons. | 5 events and 10%; decreasing is healthy. |
| `ticket_resolution_events` | Count of verified ticket status transitions from an open status (`1` to `4`) to solved or closed (`5` or `6`) during the day. | Daily integer series; selected equal horizons. | Certified denominator product; not independently classified. |
| `ticket_reopen_event_rate` | `sum(ticket_reopen_events) / sum(ticket_resolution_events) * 100`. This is explicitly an event rate, not a cohort probability; values above 100% are valid and must not be clamped. | Selected current horizon versus immediately preceding equal horizon. | Denominator minimum 5 in both periods; 3 percentage points and 10%; decreasing is healthy. |
| `first_response_p50_seconds` | Nearest-rank 50th percentile of `glpi_tickets.takeintoaccount_delay_stat` for non-deleted tickets created in the trailing 30 days with a recorded `takeintoaccountdate` and non-negative delay. | Recomputed daily trailing 30-day scalar; current cutoff versus cutoff 30 days earlier. | Minimum 20 observations; 300 seconds and 10%; decreasing is healthy. |
| `first_response_p75_seconds` | Same certified population as P50; nearest-rank 75th percentile. | Recomputed daily trailing 30-day scalar; current versus 30 days earlier. | Minimum 20; 600 seconds and 10%; decreasing is healthy. |
| `first_response_p90_seconds` | Same certified population as P50; nearest-rank 90th percentile. | Recomputed daily trailing 30-day scalar; current versus 30 days earlier. | Minimum 20; 900 seconds and 10%; decreasing is healthy. |
| `survey_responses_total` | Count of ticket-satisfaction rows answered in the trailing 30 days with a non-null scaled score. | Recomputed daily trailing 30-day denominator product. | Minimum 30; not independently classified. |
| `dissatisfied_responses_total` | Count within the same population whose `satisfaction_scaled_to_5` is at most 2. | Recomputed daily trailing 30-day numerator product. | Count movement may be shown; decreasing is healthy. |
| `customer_dissatisfaction_rate` | `dissatisfied_responses_total / survey_responses_total * 100`. | Current trailing 30 days versus preceding trailing 30 days. | Denominator minimum 30 in both windows; 3 percentage points and 10%; decreasing is healthy. |
| `solution_proposed_tickets` | Distinct tickets with at least one solution row created in the trailing 30 days. | Recomputed daily trailing 30-day denominator product. | Minimum 10; not independently classified. |
| `solution_refused_tickets` | Distinct tickets with at least one solution row created in the same trailing 30 days whose certified status is refused. | Recomputed daily trailing 30-day numerator product. | Count movement may be shown; decreasing is healthy. |
| `refused_solution_rate` | `solution_refused_tickets / solution_proposed_tickets * 100`. | Current trailing 30 days versus preceding trailing 30 days. | Denominator minimum 10 in both windows; 3 percentage points and 10%; decreasing is healthy. |
| `incident_linked_computers` | Distinct computers linked to at least one incident ticket created in the trailing 90 days. | Recomputed daily trailing 90-day denominator product. | Minimum 5; not independently classified. |
| `repeat_incident_computers_90d` | Distinct computers linked to at least two distinct incident tickets created in the same trailing 90 days. | Recomputed daily trailing 90-day numerator product. | Count movement may be shown; decreasing is healthy. |
| `repeat_incident_asset_rate` | `repeat_incident_computers_90d / incident_linked_computers * 100`. | Current trailing 90 days versus the trailing 90-day window ending 7 days earlier. | Denominator minimum 5 in both windows; 3 percentage points and 10%; decreasing is healthy. |
| `licence_covered_titles` | Count of distinct software titles in the active entity scope having both positive recorded entitlement and positive recorded installation/allocation counts. | Daily scalar readiness product. | Must be at least 50 before aggregate licence ratios are supported. |
| `licence_utilization_rate` | `sum(recorded allocations for covered titles) / sum(recorded entitlements for covered titles) * 100`. Values above 100% are valid and indicate recorded over-allocation; the result is not clamped. | Daily scalar; selected cutoff comparison after readiness gate. | `licence_covered_titles >= 50`, denominator positive; 3 percentage points and 10%; movement toward 100% is contextual, while above 100% is exposure. |
| `licence_coverage_gap_rate` | `count(installed software titles without positive recorded entitlement) / count(installed software titles) * 100`. | Daily scalar; selected cutoff comparison. | At least 50 installed titles; 3 percentage points and 10%; decreasing is healthy. |

Nearest-rank percentile means: sort the valid non-negative delays ascending and select observation `ceil(percentile * N)`, using one-based indexing. Percentiles must never be averaged across days or entities. Recursive-entity scope combines authorized raw observations first, then calculates the percentile once.

### Phase 5B deterministic insight rules

Phase 5B may surface these additional governed findings when their data products pass readiness, freshness, denominator and materiality gates:

1. created-ticket demand movement by leading request source;
2. reopen-event count movement;
3. reopen-event rate movement;
4. P50, P75 and P90 first-response movement, with P90 ranked above P75 and P50;
5. customer-dissatisfaction rate movement;
6. refused-solution count and rate movement;
7. repeat-incident asset count and rate movement;
8. licence-utilization and licence-coverage-gap movement after the fixed population gate.

For a dimension-series movement, calculation evidence may expose the top three absolute contributing dimension changes in deterministic order: absolute delta descending, then dimension identifier ascending. The executive sentence names only the largest contributor. The other two appear only in calculation inspection and exports. This is deterministic decomposition of one certified dimension, not multi-dimensional causal attribution.

### Phase 5B UI and dashboard boundaries

- No new Executive KPI row is added. Phase 5B findings share the existing insight strip and five-finding cap.
- Phase 5B metrics become available to the existing add-widget catalogue using the existing dashboard ownership, drag, resize, palette and persistence model.
- The existing Executive dashboard is not automatically expanded. New default widgets may only be added by a separate written layout amendment.
- No Service Delivery dashboard, new tab, sidebar, view selector or parallel navigation system is introduced in Phase 5B.
- Licence ratios are not automatically placed on the Executive dashboard. They are available to the existing Asset and Licence Governance dashboard and user-owned dashboards after their readiness gates pass.
- Calculation inspection, PDF, CSV and scheduled static reports use formula version `phase5b-1` and the same suppression rules as the screen.

### Phase 5B exclusions

The following remain excluded:

- arbitrary user formulas, SQL, dimensions or editable mathematical expressions;
- user-configurable causal or predictive rules;
- cross-metric or cross-dimensional causal decomposition;
- deployment-specific thresholds that are not represented as controlled, validated configuration with a later written formula amendment;
- per-render behavioural audit logging;
- a new Service Delivery dashboard or other navigation infrastructure;
- AI narrative, prediction, anomaly inference, external benchmarking or causal conclusions;
- Phase 6 MSP/customer comparisons; and
- Phase 7 predictive/AI features.

### Phase 5B acceptance criteria

1. Every Phase 5B registry product matches the exact numerator, denominator, grain, window and zero handling above.
2. Reopen measures use verified status events and distinguish event rate from ticket cohort probability.
3. First-response percentiles use authorized raw observations and the nearest-rank method; percentiles are never averaged.
4. Satisfaction, refused-solution, repeat-incident and licence ratios remain suppressed below their fixed population gates.
5. Created-ticket request-source demand is not confused with the existing open-ticket source composition metric.
6. Phase 5B adds no Executive card row and no new dashboard navigation system.
7. The combined Phase 5A/5B brief remains deterministic, non-causal and capped at five findings.
8. Screen, PDF, CSV and report-history evidence contain identical formula-version, source, gate and suppression outcomes.
9. Existing Phase 3 dashboard ownership, drag, resize, palette and saved-layout behavior remains unchanged.
10. Structural and browser integration tests verify entity scope, cold start, denominator suppression, percentile correctness, responsive layout and export parity.
