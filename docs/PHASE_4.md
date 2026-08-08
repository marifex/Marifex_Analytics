# Phase 4: asset, licence, change and problem analytics

Phase 4 extends the governed MarifeX Data Mart and Home dashboard builder to the four domains named in the product roadmap: GLPI computers, software licence compliance, changes and problems. It does not modify GLPI core tables and it does not expose custom SQL.

## Certified metrics

| Domain | Metric | Grain and meaning |
|---|---|---|
| Assets | `asset_inventory_total` | Daily managed, non-template computer count |
| Assets | `asset_inventory_by_state` | Daily computer count by GLPI lifecycle state |
| Assets | `stale_computer_inventory` | Computers whose last inventory is missing or older than 30 days |
| Assets | `prohibited_software_installations` | Installed software whose GLPI software record is marked invalid |
| Assets | `unlicensed_software_installations` | Software installations exceeding valid recorded entitlement seats |
| Assets | `low_disk_capacity_computers` | Computers with a discovered disk below 10 percent free capacity |
| Assets | `computers_in_stock_over_30_days` | Computers in a stock/store lifecycle state for more than 30 days |
| Assets | `incidents_by_operating_system` | Trailing 30-day computer-linked ticket count by operating system |
| Assets | `repeat_incident_computers` | Computers with at least two linked tickets in the trailing 30 days |
| Licences | `software_license_entitlements` | Valid licence seats recorded in GLPI |
| Licences | `software_license_allocations` | Items explicitly allocated to GLPI licence records |
| Licences | `software_license_overallocated_seats` | Allocations above entitlement, summed per licence |
| Licences | `software_license_compliance_rate` | Percentage of allocated seats covered by entitlements; 100% when there are no allocations |
| Changes | `open_changes` | Changes created before the cutoff and not yet solved at the cutoff |
| Changes | `daily_change_volume` | Changes raised during the snapshot day |
| Changes | `daily_change_resolutions` | Changes solved during the snapshot day |
| Changes | `open_change_status_distribution` | Open change workload by GLPI status |
| Problems | `open_problems` | Problems created before the cutoff and not yet solved at the cutoff |
| Problems | `daily_problem_volume` | Problems raised during the snapshot day |
| Problems | `daily_problem_resolutions` | Problems solved during the snapshot day |
| Problems | `open_problem_status_distribution` | Open problem workload by GLPI status |

Every rollup includes `rollup_date` and `entities_id`. API queries intersect those rows with the active GLPI entity scope before aggregation.

## Dashboard templates

- **Asset and Licence Governance**: managed/stale computer KPIs, lifecycle distribution and table, entitlement/allocation trajectories, compliance and over-allocation.
- **Change Control**: open workload, daily demand, resolved work, demand/resolution trajectories and status distribution.
- **Problem Control**: open workload, daily demand, resolved work, demand/resolution trajectories and status distribution.

All Phase 4 widgets are available in the certified widget library. KPI, line, bar, donut and table presentations remain subject to the server-side metric/type allowlist. Native drill-downs route to GLPI Computers, Software Licences, Changes or Problems.

The Executive Operations Command dashboard is upgraded once per user and active entity with a cross-domain summary covering service desk, assets, licences, changes and problems. Asset and Licence Governance, Change Control and Problem Control remain provisioned as optional persona-focused deep dives; Phase 4 does not force users to leave the Executive view. Provisioning keeps the user's current dashboard active and never recreates a deep-dive dashboard after that user deliberately deletes it. The plugin Settings page lists all certified metrics with their latest snapshot, stored grain count and current/stale/missing status for the active entity scope.

## ETL behavior

The existing `dailySnapshot` automatic action now runs `DomainSnapshotBuilder` after ticket rollups. Re-running the same day replaces only Phase 4 metric keys, making the domain rollups idempotent without removing ticket metrics.

Computer lifecycle state, inventory freshness and licence allocations are daily observations. GLPI current tables do not contain a complete historical timeline for every asset state, deletion, inventory and licence-allocation change, so MarifeX does not claim an accurate retroactive Phase 4 backfill. History becomes reproducible from the first scheduled Phase 4 snapshot onward.

Licence compliance is deliberately based on explicit `glpi_items_softwarelicenses` allocations. Unlinked software installations are not silently treated as licensed or unlicensed.

## Acceptance checks

1. The automatic action creates all Phase 4 metric keys without changing GLPI core tables.
2. Re-running a snapshot day produces one rollup per metric/entity/dimension grain.
3. All Phase 4 API results are restricted to active GLPI entities.
4. The Asset, Change and Problem dashboards appear automatically on Home and can be activated, edited, saved and reloaded.
5. Percentage KPIs render with a percent suffix and dimension widgets use domain-specific headings.
6. Phase 4 drill-downs open native GLPI lists.
7. Responsive chart, legend, table and KPI behavior remains valid after widget resizing.
8. Existing ticket metrics and Phase 3 dashboards continue to pass regression tests.
