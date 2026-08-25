# MarifeX Advanced Analytics administrator setup

This guide covers the server and GLPI administration required to install and operate MarifeX Advanced Analytics 0.15.1.

## Requirements

- GLPI 11.0.7 or later
- PHP 8.2 or later
- MariaDB or MySQL
- GLPI external automatic actions
- Chromium or Google Chrome for PDF generation
- A working GLPI outgoing-mail configuration for scheduled delivery

## Install the plugin

1. Back up the GLPI database and the existing `marifex` plugin directory.
2. Extract `marifex-0.15.1.zip` into `GLPI_ROOT/plugins/`.
3. Confirm the resulting path is `GLPI_ROOT/plugins/marifex/setup.php`.
4. Assign the plugin directory to the GLPI web-server user.
5. Open **Setup > Plugins** in GLPI.
6. Install and activate **MarifeX Advanced Analytics**.

## Configure access

1. Open the GLPI profile that requires analytics access.
2. Grant dashboard viewing rights to authorised users.
3. Grant analytics administration rights only to administrators who manage dashboards, schedules and plugin settings.
4. Confirm that users can access only their authorised entity scope.

## Configure automatic actions

Configure GLPI's external automatic-action process, then confirm these MarifeX actions are enabled and running:

- `incrementalEtl`
- `incrementalLogEtl`
- `dailySnapshot`
- `scheduledReports`

The daily snapshot builds certified analytical history over time. Historical comparisons activate progressively as sufficient daily observations become available.

## Configure PDF reports

Install Chromium or Google Chrome in the same runtime environment as GLPI. Confirm that the GLPI web-server user can launch the browser and write to the GLPI temporary-files directory.

Generate a PDF from **Home > Analytics** and verify:

- page headers and footers do not overlap report content
- dashboard totals reconcile with report detail
- charts, tables and labels are readable
- the report contains only the active authorised entity scope

## Configure scheduled email delivery

1. Configure outgoing mail under GLPI notification settings.
2. Send GLPI's test email successfully.
3. Confirm the `scheduledReports` automatic action is running.
4. Create a test schedule in Analytics.
5. Verify the recipient receives the expected PDF or CSV report.

## Verify the installation

- Open **Home > Analytics** and load each supplied dashboard.
- Change the reporting horizon and authorised group filter.
- Move and resize widgets, save the layout, and reload the page.
- Confirm charts and content adapt without overlap or clipping.
- Export PDF and CSV reports and reconcile their figures with the dashboard.
- Confirm unauthorised profiles cannot access the dashboard or report endpoints.
- Review browser and GLPI logs for errors.

## Upgrade

1. Back up the GLPI database and current plugin directory.
2. Replace the plugin directory with the new canonical release.
3. Preserve ownership by the GLPI web-server user.
4. Run the plugin update from **Setup > Plugins** when GLPI reports **To update**.
5. Confirm the plugin is active and repeat the installation verification checks.

## Support

Visit [MarifeX Technologies](https://www.marifextech.com) or email [mohammed@marifextech.com](mailto:mohammed@marifextech.com).