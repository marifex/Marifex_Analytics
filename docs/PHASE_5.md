# Phase 5: interaction, focus and drilldown

Phase 5 makes the widget workspace interactive while preserving the certified query boundary.

## Layout interaction

Edit mode allows users to add, remove, rename, reorder and resize widgets. Widgets can be dragged into a new sequence, moved with explicit controls, or cycled through supported widths. Changes remain local until the user saves the dashboard.

The server validates the full definition again when it is saved. Client-side controls are a convenience and are not treated as a security boundary.

## Global filters

The executive horizon supports 7, 30, 90, 180 and 365 completed days. Assigned-group focus is populated from entity-scoped Data Mart results.

Current open tickets and historical backlog apply the selected group on the server. Group visualizations also focus on the selected group. Average age remains an enterprise value until historical group membership can be joined to ticket-age samples without changing its certified definition.

## Cross-filtering

Selecting a donut segment, bar or service ownership row focuses the dashboard on that assigned group. The filter bar always shows whether the user is viewing the enterprise scope or a group scope, and provides a clear reset control.

## GLPI drilldowns

Widget and table links open GLPI's native ticket search. Group drilldowns use GLPI search criteria rather than a plugin-owned ticket list. GLPI therefore remains responsible for ticket visibility, profile rights and entity restrictions.

No dashboard interaction accepts table names, SQL expressions, field names or arbitrary filter operators.
