=== CF7 Registrations Manager ===
Contributors: Gabriel Vendramim Ferreira
Tags: contact form 7, registrations, forms, database, export, dashboard, rest api, excel
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.1
Stable tag: 2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

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

= Can I let a teacher take attendance without giving them a WordPress admin account? =

Yes. Add the `[mcr_attendance]` shortcode to any WordPress Page, then set that Page's visibility to "Password Protected" (in the Publish box). Anyone with the page's password can take attendance and view history from that page — without ever seeing wp-admin. The password is checked again on every save, so the attendance form can't be submitted by going around the password screen.

== Changelog ==

= 2.2 =
* Fixed the Attendance screen (both in wp-admin and the public `[mcr_attendance]` page) overflowing horizontally on mobile — the Program/Date form, the Mark all/Copy Last Session/counter toolbar, and the status buttons now stack vertically and fit the screen on narrow viewports, switching back to a single-row layout automatically on tablet/desktop widths. The student table now scrolls horizontally within its own box when needed, instead of forcing the whole page to scroll sideways.

= 2.1 =
* New `[mcr_attendance]` shortcode: add the Attendance screen (Take Attendance + History, with all the same buttons, live counter and warnings as the admin screen) to any front-end WordPress Page. Access is protected by the page's own native WordPress password protection — no new user role or login system to manage. The password is re-verified on save, not just on page view, so the save endpoint can't be reached by skipping the password screen.
= 2.0 =

**Core registrations**
* Captures registrations from any Contact Form 7 form, with fully configurable field mapping (no form ID or field name hard-coded).
* Registration detail screen is fully editable (name, age, class, contacts, programs, message, amount, photo permission), with a Change History log.
* Bulk "Rename Program / Interest" tool to relabel a program everywhere at once.
* Registrations list with search, filters (status, payment, photo permission), sorting, and bulk actions.
* Dashboard with registration trends, status/program/class breakdowns, and optional financial and photo-permission cards.
* Native CSV/XLSX export (no Composer/PhpSpreadsheet required), full or filtered.
* REST API (list, detail, delete, CSV/XLSX export) secured by API key or authenticated session, with rate limiting.
* Backup and restore (settings-only or full data) as downloadable JSON.
* Logs screen with levels, search, context filters, and CSV download.
* Setup Wizard for a guided, zero-configuration first run.

**Payments**
* Simple payment confirmation (Paid/Unpaid) with confirmation date, its own list column/filter, REST API and export support, and optional Dashboard cards.

**Attendance**
* Take attendance by program and date directly from existing registrations — no separate student roster to maintain.
* One-click P/A/L/E status buttons, "Mark all as Present", and "Copy from Last Session".
* Live Present/Absent/Late/Excused counter, and a warning badge for 3+ consecutive absences.
* Unsaved-changes warning before leaving the page.
* History tab with program and date-range filters, and per-session editing.
* Optional Dashboard section with attendance rate and per-program breakdown.

**Excel Online integration**
* Real-time sync via Microsoft Graph OAuth 2.0 — just click "Connect Microsoft 365", no technical IDs required.
* Automatic workbook/worksheet/table discovery and column mapping.
* Independent sync targets for Registrations and Payments, each with its own table and mapping, sharing one connected account.
* Background sync queue with automatic retry, exponential backoff, and duplicate-proof row updates (rows are updated in place, never duplicated).
* Manual "Sync Now" with Pending/Failed/All/Force-resync/Reset options, and per-registration "Sync Again".
* Sync status shown on the Registrations list and on the registration detail screen.

**Security**
* Microsoft Client Secret and OAuth tokens are encrypted (AES-256-GCM) at rest, with automatic backward-compatible migration from older plaintext installs.

= 1.0.0 =
* Initial release.
