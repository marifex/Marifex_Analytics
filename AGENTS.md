# MarifeX Pro Dash agent rules

These rules are mandatory for every task in this repository. The controlling scope and research documents remain authoritative; do not substitute personal design or deployment preferences.

## Scope discipline

- Read the relevant scope and research sections before changing architecture, dashboard behavior, metrics, phases, UI, packaging, or deployment.
- Do not add, remove, defer, or reinterpret scope without the user's explicit written approval.
- Preserve existing uncommitted user work and unrelated changes.

## Release packaging

- Maintain one version consistently in `setup.php`, `composer.json`, `marifex.xml`, `package.json`, `package-lock.json`, and `README.md`.
- Create only the canonical release name `marifex-<version>.zip` under `versions/<version>/`.
- Build ZIP entries with POSIX `/` separators. Reject the package if any entry contains `\`.
- Before handoff, verify the ZIP SHA-256, required `marifex/setup.php` and dashboard bundle entries, bundle hash, version consistency, and zero backslash entries.
- Never rename an older ZIP to represent a new build.

## Cloud deployment protocol

- The deployment target is container `glpi-11-0-8`; the user uploads the canonical ZIP to `$HOME` on the GCP VM.
- Preserve the previously proven upload/extract/ownership/restart/hash command structure. Do not append new maintenance operations to that command or replace it with an improvised flow.
- Container extraction and ownership operations may run as root. GLPI console commands must never run as root.
- If GLPI reports `To update`, perform plugin install/activation as a separate command using container user `www-data`:

  `sudo docker exec -u www-data glpi-11-0-8 sh -lc 'cd /var/www/glpi && php bin/console glpi:plugin:install --force --no-interaction marifex && php bin/console glpi:plugin:activate --no-interaction marifex'`

- Do not add `--allow-superuser`; do not combine the separate GLPI update with the proven file-deployment command.
- Give the user one concise command for the current state. Do not repeat already successful upload or extraction steps during recovery.
- Confirm the deployed dashboard bundle hash after restart. Wait for live browser confirmation before commit, tag, and push.

## Verification

- Run type checking, production build, structural tests, and browser integration tests before packaging.
- Browser tests must cover actual drag, resize, collision reflow, released-space compaction, chart/content adaptation, persistence after reload, edit/view geometry parity, standard desktop sizes, mobile presentation without desktop-layout overwrite, overlap/clipping, and console errors.
- Email delivery remains excluded until the user explicitly resumes it.
