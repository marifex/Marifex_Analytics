# MarifeX Pro Dash working rules

Read `AGENTS.md` first. It is the mandatory repository instruction set. The approved scope and research documents control architecture, UI, metrics, release packaging and deployment.

## Change discipline

- Do not deviate from scope without explicit written approval.
- Diagnose the root cause before editing. Avoid local CSS or z-index patches when the defect crosses component or layout boundaries.
- Preserve uncommitted and unrelated user changes.
- Record every user-visible fix, feature, migration and validation change in `CHANGELOG.md` under the build version.
- Maintain one version across all manifests and use only `marifex-<version>.zip`.

## Dashboard overlays

- Drawers, dialogs and modal controls that must cover the dashboard must render outside `.grid-stack` using a document-level portal/teleport.
- A large child z-index cannot escape a GridStack item stacking context. Do not fix overlay defects by increasing a nested card's z-index.
- Browser-test overlays from early, middle and final widget DOM positions and across KPI, chart, table, matrix and donut types.

## Deployment

- Preserve the proven file deployment command. Never add GLPI console maintenance to its root container session.
- When an update is required, run GLPI install/activation separately as container user `www-data`, exactly as documented in `AGENTS.md`.
- Wait for live confirmation before commit, tag and push.
