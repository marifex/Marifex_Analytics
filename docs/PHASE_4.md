# Phase 4: executive widget framework

Phase 4 replaces the fixed validation dashboard with a curated executive workspace. It remains a top-level Analytics page in GLPI. Plugin configuration continues to contain only administrative settings and pipeline health.

## Widget library

The library includes KPI, line, bar, donut and table widgets. Each catalog entry is tied to one certified semantic metric and a compatible visualization type. Users cannot enter SQL, choose database tables or submit query fragments.

The initial catalog covers:

- Current open tickets
- Average open ticket age
- Historical backlog trajectory
- Historical backlog by assigned group
- Service ownership ranking
- Workload concentration

## Saved personal dashboards

Each user can save one active personal executive dashboard. Its definition contains only the dashboard horizon and validated widget metadata. The service limits definitions to 24 widgets, validates unique IDs, restricts titles and dimensions, and checks every metric and visualization pairing.

Dashboard writes require the MarifeX view right, an authenticated GLPI session and a valid GLPI CSRF token. Saved definitions are associated with the current user and active entity context.

## Executive visual system

The dashboard uses a 12-column responsive grid, theme-aware panels, concise metric provenance, loading skeletons and reduced-motion support. Dark mode uses GLPI theme variables. Logical CSS properties and mirrored control groups keep the workspace ready for right-to-left locales.

The default dashboard is designed as an operations command center rather than a collection of unrelated charts. It balances current state, trajectory, workload concentration and service ownership.
