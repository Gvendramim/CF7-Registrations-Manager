=== CF7 Registrations Manager ===
Contributors: Gabriel Vendramim Ferreira
Tags: contact form 7, registrations, forms, database, export, dashboard, rest api, excel
License: GPLv2 or later

Plug-and-play: install, activate, and a guided Setup Wizard configures everything. Captures registrations from any Contact Form 7 form, with a dashboard, native CSV/Excel export, a REST API, and optional Excel Online sync — zero Composer, zero terminal, zero code edits.

== Description ==

CF7 Registrations Manager is a production-ready, self-contained plugin. Every dependency (including Excel/.xlsx generation) is bundled natively — there is nothing to install via Composer, SSH, or the command line.

**Setup Wizard** — launches automatically on first activation and walks the administrator through: environment check (PHP/WordPress/Contact Form 7/permissions), form selection, automatic field mapping, database setup, API key generation, and a final system test.

**100% configurable, no code required** — select any Contact Form 7 form and map its fields from the Settings screen. No form ID or field name is ever hard-coded.

**Dashboard** — KPI cards (total, today, this week, this month, and per-status counts) plus four Chart.js graphs: registrations over time, status distribution, registrations by month, and registrations by class.

**Native CSV & Excel export** — CSV uses UTF-8 BOM and an automatically detected delimiter (`;` for locales like pt_BR, de_DE, fr_FR; `,` for en_US), so it always opens correctly in Excel Windows, Excel Online, and LibreOffice. Excel (.xlsx) files are generated with a dependency-free native writer (uses PHP's built-in ZipArchive extension) — no library installation, ever. If ZipArchive is unavailable on a host, the plugin shows a friendly notice and CSV export keeps working; it never throws a fatal error.

**Excel Online integration** — connect via the Microsoft Graph API (Tenant ID, Client ID, Client Secret, Workbook, Worksheet, Table) with a one-click "Test Connection" button. Once connected, every new registration is pushed to the configured table automatically.

**REST API** — auto-registered at `cf7-registrations/v1`, with `GET registrations`, `GET registration/{id}`, `POST registration/status`, `DELETE registration/{id}`, `GET export.csv` and `GET export.xlsx`. Authenticate with a simple API key (auto-generated) via header or query parameter — ideal for Excel, Power BI, or other external tools. Includes built-in rate limiting.

**Logs** — Info / Warning / Error / Critical levels, with search, context filters, CSV download, and one-click clearing.

**Backup & Restore** — export settings only, or a full backup (settings + all registrations) as JSON; import/restore from the Settings screen, with duplicate-safe registration restoration.

**Versioned migrations** — internal database versioning so future updates can evolve the schema safely and automatically.

**Modern UI** — cards, Dashicons, toast notifications, loading indicators during export/sync, confirmation prompts before destructive actions, contextual help on every screen, and dark-mode-aware styling.

== Installation ==

1. Upload the `music-club-registrations` folder to `/wp-content/plugins/`.
2. Activate the plugin from the "Plugins" menu.
3. The Setup Wizard opens automatically — follow the six steps (environment check, form selection, field mapping, database setup, API key, final test).
4. That's it. No Composer, no terminal, no manual library installation.

== Frequently Asked Questions ==

= Do I need to run Composer or install any library? =

No. Every feature, including Excel (.xlsx) export, works immediately after activation using code bundled with the plugin.

= Does the plugin work with any Contact Form 7 form? =

Yes. No form ID or field name is hard-coded — you choose the form and map its fields from the Settings screen (or the Setup Wizard on first activation).

= What happens if Excel export isn't available on my server? =

The plugin checks for the PHP ZipArchive extension (present on the vast majority of hosts). If it's missing, you'll see a friendly notice instead of an error, and CSV export continues to work normally.

= Is the REST API secured? =

Yes. Every endpoint requires a valid API key (sent as a header or query parameter) or an authenticated WordPress session with the right permissions, and requests are rate-limited.

= Are my registrations deleted if I uninstall the plugin? =

Only if you explicitly enable "Remove Data" on the Settings screen. By default, all data is preserved.

== Changelog ==

= 1.0.0 =
* Added support for new registration fields: Child's Age, Additional Email, and Additional Phone — tracked end-to-end through capture, database, REST API, CSV/XLSX export, Excel Online sync, Dashboard, Registrations list, and the detail screen.
* Safe, additive, idempotent database migration for the new columns; existing registrations and forms without these fields keep working unchanged.
* Child's Age is validated (3–13) and exported as a real Excel number; invalid or missing values degrade gracefully to empty, never a fatal error.
* Excel Online automatic column mapping now recognizes the new fields by name.
* Interests/programs (multi-select, including time slots) are always preserved in full and joined with "; " for CSV/XLSX/Excel Online — never truncated to a single option.
* Required-field validation no longer assumes every monitored form has a "parent name" field, so newer forms without it are no longer incorrectly rejected.
* New "Registrations by Program" Dashboard chart.

= 1.1.0 =
* Real-time Excel Online sync via Microsoft Graph OAuth 2.0 (Authorization Code Flow) — clients just click "Connect Microsoft 365", no Tenant ID/Client ID/Client Secret/technical IDs required.
* Automatic workbook/worksheet/table discovery and selection, with automatic column-to-field mapping.
* Background sync queue with automatic retry, exponential backoff, and duplicate prevention.
* New "Advanced > Microsoft Integration" screen for the one-time developer setup (Entra App Registration).
* Excel Sync status column on the Registrations list and a full sync panel on the registration detail screen, with "Sync Again".
* Dashboard card for Excel Online status, pending/synced/failed counts, and last sync time.
* Database migration: added sync-tracking columns to the registrations table (safe, additive, idempotent).

= 1.2.0 =
* Setup Wizard for a fully guided, zero-configuration first run.
* Native, dependency-free .xlsx export (no Composer/PhpSpreadsheet required).
* CSV export rewritten with UTF-8 BOM and automatic delimiter detection.
* Expanded Dashboard with monthly and by-class charts.
* Microsoft Excel Online integration via the Microsoft Graph API.
* REST API expanded (DELETE, export.csv, export.xlsx) with rate limiting.
* Logs: Critical level, search, context filters, CSV download.
* Backup and restore (settings-only or full data) as downloadable JSON.
* Internal database versioning and automatic migrations.
* UI polish: toast notifications, loading states, contextual help, dark-mode-aware styling.

= 1.0.0 =
* Initial release.
