# MarifeX Advanced Analytics for GLPI

Current release: **0.15.1**

## About

MarifeX Advanced Analytics is an enterprise analytics and reporting plugin for GLPI 11. It converts authorised GLPI operational data into executive dashboards, historical comparisons, governed insights and presentation-ready reports without changing GLPI core files or operational tables.

MarifeX Advanced Analytics is authored and maintained by MarifeX.

## Capabilities

- Home-integrated executive analytics dashboards
- Service desk, SLA, workload, asset, software licence, change and problem measures
- Historical trends and controlled period comparisons
- Progressive analytical activation when sufficient history becomes available
- Drag, resize and responsive dashboard layouts
- Governed widget themes and chart colour palettes
- Read-only evidence navigation into authorised GLPI records
- Executive PDF reports and business-readable CSV exports
- Scheduled report generation and email delivery through GLPI
- Entity-aware and profile-aware access control
- Chrome-free mobile dashboard view for authorised app integrations

## Requirements

- GLPI 11.0.7 or later
- PHP 8.2 or later
- MariaDB or MySQL
- GLPI external automatic actions configured
- Chromium or Google Chrome available to the GLPI container for PDF generation
- A working GLPI outgoing-mail configuration for scheduled email delivery

## Installation

1. Copy the release ZIP to the GLPI server.
2. Extract it so the plugin directory is named **marifex** under **GLPI_ROOT/plugins/**.
3. Ensure the web-server user owns the plugin files.
4. Open **Setup > Plugins** in GLPI.
5. Install and activate **MarifeX Advanced Analytics**.
6. Assign dashboard and administration rights to the required GLPI profiles.
7. Confirm the MarifeX automatic actions are running through GLPI's external cron process.

## Opening Analytics

Open **Home > Analytics** in GLPI.

The legacy route **/plugins/marifex/Dashboard** redirects to the Home Analytics view. The mobile route **/plugins/marifex/Dashboard/Mobile** provides the authorised dashboard without the standard GLPI navigation chrome.

## Dashboard controls

- Select the dashboard from the dashboard selector.
- Choose the reporting horizon.
- Filter by an authorised service group when the dashboard supports it.
- Select an automatic refresh interval or keep refresh set to Manual.
- Use **Edit layout** to add, move, resize, configure or remove widgets.
- Drag a widget by its header.
- Resize from its visible edges or corner grips.
- Save the dashboard to preserve the layout and widget settings.

Desktop geometry remains independent from the mobile presentation. Mobile stacking does not overwrite the saved desktop layout.

## Reports

### PDF

PDF export creates a structured executive report containing:

- reporting scope and period
- executive insight brief
- performance summary
- grouped analytical detail
- data coverage and calculation notes

The PDF uses client-facing business language. Technical calculation identifiers remain in audit evidence rather than the presentation layer.

### CSV

CSV export opens with a business-readable summary before the detailed evidence records. Technical evidence remains available for reconciliation and audit.

### Scheduled reports

Authorised users can schedule PDF or CSV reports. Delivery uses the outgoing-mail settings configured in GLPI. The **scheduledReports** automatic action must be running.

## Analytical history

Current operational values are available immediately when their source is ready. Historical comparison measures activate progressively as certified daily observations accumulate. Changing the reporting horizon changes the amount of history required for a complete comparison.

The dashboard identifies whether a result is current-only, tracked since monitoring began, based on one complete period, or ready for a full period comparison.

## Automatic actions

The plugin registers these GLPI automatic actions:

- **incrementalEtl**
- **incrementalLogEtl**
- **dailySnapshot**
- **scheduledReports**

Run them with GLPI's external automatic-action process. Web traffic should not be relied on to maintain analytical history.

## Security and data handling

- Dashboard access follows GLPI profile rights.
- Every query is restricted to the active authorised entity scope.
- Group filters are validated against the same authorised scope.
- Analytics and evidence navigation are read-only.
- The plugin does not permit custom SQL or user-defined formulas.
- Historical analytics are stored in plugin-owned tables.
- GLPI core files and operational tables are not modified.

## Upgrade

1. Back up the GLPI database and the current MarifeX plugin directory.
2. Replace the plugin directory with the new release.
3. Preserve ownership by the GLPI web-server user.
4. Open **Setup > Plugins** and run the MarifeX update when GLPI reports **To update**.
5. Confirm the plugin is active.
6. Confirm the automatic actions and dashboard load successfully.

## Data retention

Analytics data is retained by default when the plugin is uninstalled. To remove the plugin-owned analytical tables during uninstall, an administrator must explicitly disable retention before uninstalling. MarifeX never drops or alters GLPI operational tables.


## License

MarifeX Advanced Analytics is released under the GNU General Public License version 3 or any later version.

See [LICENSE](LICENSE) for the complete licence terms.

## Support

Product information and support are available from [MarifeX](https://marifex.com).
