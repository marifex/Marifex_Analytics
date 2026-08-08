# Phase 3: dashboard builder

Phase 3 delivers the low-code analytics dashboard builder defined by the product roadmap. MarifeX appears as an additional **Analytics** tab on GLPI Home; users do not need to leave the Home workspace for normal dashboard use.

## Dashboard workspace

- Multiple personal dashboards per user and active entity
- Executive, Service Desk Operations and Team Workload templates
- Create from template, duplicate, switch, rename and delete
- One active dashboard per user/entity context
- Local draft editing with explicit Save and Cancel
- Persisted dashboard horizon, assigned-group focus and auto-refresh interval

## Grid and widget editing

- Responsive 12-column CSS grid
- Drag-and-drop card ordering
- Direct mouse/touch resizing from each widget's corner grip, with live chart reflow
- Add, remove and rename widgets
- Per-widget curated solid, monochrome and gradient palettes, including the default Cream Gold treatment
- KPI, line, bar, donut and table visualizations
- Fixed chart-left/legend-right donut layout without paginated legends

## Certified semantic boundary

Every catalog item maps to a pre-approved metric and compatible visualization. Dashboard definitions cannot contain SQL, tables, columns, formulas, JavaScript or arbitrary query operators. The server validates names, identifiers, widget counts, dimensions, filters and refresh intervals on every write.

## Security and persistence

- Native GLPI authentication and profile rights
- Native GLPI CSRF validation on POST, PUT and DELETE
- Dashboard ownership restricted by user and active entity
- Maximum 20 dashboards per user/entity and 24 widgets per dashboard
- Entity-scoped metric queries and controlled native ticket drilldowns
- Private, non-cacheable JSON responses

## Acceptance checks

1. Analytics is available as an additional tab on GLPI Home.
2. The legacy plugin dashboard URL redirects to the Home Analytics tab.
3. A user can create each template, duplicate it, switch dashboards and persist edits.
4. Drag ordering, width, height, titles, palettes, filters and refresh settings survive reload.
5. Donut charts keep the chart left and all legends right without scrolling.
6. Cross-filtering redraws widgets without a full-page reload.
7. Another user or entity cannot activate, update or delete the dashboard.
8. Requests without a valid CSRF token are rejected.
