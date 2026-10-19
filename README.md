# Stylesheet Monitor for Elementor

A WordPress plugin for observing Elementor responsive stylesheet health, recording purge events, optionally invalidating conflicting metadata cache copies, and sending email alarms.

**Version:** 0.5.0 · **Requires:** WordPress 6.6+, PHP 8.1+ · Individual-site activation only.

## Features

- Admin Bar indicator and tabbed monitor: Status, Stylesheets, Alarms, Events, Logs and Settings.
- Checks expected responsive CSS files, readability, file timestamps and generation metadata; compares WordPress/database/cache views.
- Configurable additional `custom-*.min.css` expectations. Pro widget assets are not universal requirements.
- Optional targeted cache compatibility. Does not write stylesheet files, generation metadata or flush the global cache.
- Optional request-driven email alarms with purge grace, reminders and recovery messages.
- Signed remote snapshot and collector-receipt endpoints for an independently configured monitoring service.
- Update notifications from this repository's published stable GitHub releases, using WordPress's normal updater.

## Installation

Download the packaged `elementor-stylesheet-monitor-VERSION.zip` from GitHub Releases and upload through Plugins → Add Plugin → Upload Plugin. Activate on each site separately. Use Tools → Stylesheet Monitor → Settings. Email alarms, compatibility and signed collection default to disabled. No automatic repair feature is included.

Alarm recipient defaults to the WordPress administration address. Grace is configured in minutes and reminder interval in hours. Core responsive frontend/Apple WebKit files are expected when custom responsive CSS applies; valid persisted metadata expands the expected set. A stylesheet missing from both metadata and configured expectations cannot be discovered as missing.

## What the status means

Healthy describes evidence at the recorded time, not continuous uptime or visual correctness. Pending allows the configured post-purge grace period. Unknown and Stale identify missing/old evidence. Enabled describes configuration, not effectiveness or delivered mail. The toolbar uses stored evidence without starting scans or HTTP checks; local passing evidence older than five minutes becomes Stale. External receipts have a separate two-hour freshness window.

Email checks depend on WordPress requests/cron and are throttled to once per minute. WordPress accepting mail does not verify inbox delivery. Scheduled follow-ups need working cron/traffic. Compatibility cannot guarantee immunity to concurrent requests, unrelated aggregate cache corruption or future Elementor changes. Exact Elementor/Pro versions are reported, not universally certified.

## Updates

The bundled MIT-licensed Plugin Update Checker 5.7 checks public GitHub release information approximately every 12 hours. It provides a manual **Check for updates** link and normal WordPress update notices. Only published stable releases with a matching packaged ZIP are eligible. Branch builds, loose tags, source archives and prereleases are excluded.

No GitHub token is required or stored. Requests expose standard connection information (including origin IP) and updater request headers to GitHub. Collector signing keys, monitoring records and configured alarm recipients are not included in update requests. Hosting firewalls/rate limits may delay checks. Monitoring continues if update discovery is unavailable. Set `ESM_DISABLE_UPDATE_CHECKS` to true in wp-config.php to disable this integration.

The plugin does not enable WordPress automatic updates or change an existing preference. Keep auto-updates off during initial observation. Installing updates remains subject to WordPress filesystem permissions and any fleet-management policy.

## Scheduled reports

Settings includes report enablement, daily/weekly frequency, preferred local hour and weekly day (Monday by default). Reports share the alarm recipient. New installations default reports off, daily at 7 AM; existing sites with signed collection retain daily reports on upgrade. Reports require the updated external collector; the plugin never schedules report mail itself. Collection and plugin alarms are independent of this switch.

The signed snapshot includes report preferences, recipient and a settings revision. Google must check current preferences before sending. An hourly report trigger supports per-site timezones, including daylight saving and fixed offsets. The selected hour is a scheduling window, not exact delivery; delayed runs may catch up later on the scheduled day. Weekly reporting is limited to the selected local day, with history capped at seven days. When the endpoint is unavailable, report sending pauses rather than using potentially disabled/stale preferences; independent collection alarms can still notify.

The Status page shows report enablement, Google settings acknowledgment, next expected window and last submission result. A receipt is not proof of inbox delivery. Mail attempts reserve their calendar period before submission; failed or ambiguous sends are not automatically retried in that period, avoiding duplicates after crashes. Inspect Google execution history when a result needs attention. Changing the hour, recipient or frequency does not resend a report already reserved in the same local period.

Companion source and deployment instructions are in [collector/README.md](collector/README.md) in this repository (not the plugin ZIP). Deploy the updated Google collector before relying on these controls. Older collectors ignore the new preferences and retain their old schedule; the Status page explicitly shows missing acknowledgment. Rollback requires restoring both components. No Google credentials or schedules are included in the plugin ZIP.

## Storage and removal

Per-site options include `esm_settings_v1`, `esm_purge_log_v1` (50 events), `esm_css_health_v1` (48 snapshots), `esm_email_alerts_state_v1`, `esm_collector_key`, `esm_collector_receipt_v1`, `esm_report_receipt_v1`, and the updater's `external_updates-elementor-stylesheet-monitor` cache. Short-lived `esm_nonce_*` rows reserve signed requests; expiry cleanup runs in bounded batches. ESM email follow-ups use `esm_email_followup`; the bundled updater manages its own scheduled checks.

Deactivation stops the email follow-up and the updater's own scheduled checks. Historical settings/evidence intentionally survive deactivation and uninstall. Remove them only after deciding what investigation history to preserve. Legacy duplicate tooling must be deactivated before activation; previous callback namespaces are detected to avoid duplicate monitors.

## Remote collection

Optional signed routes: GET `/wp-json/stylesheet-monitor/v1/snapshot` and POST `/wp-json/stylesheet-monitor/v1/receipt`. A collector must be configured separately with its own private key per site. Use TLS, body-bound HMAC signatures, timestamp window and fresh nonces. Keys never belong in source, ZIPs or public issue reports. This release does not install a collector or schedules in any external service.

## Development and releases

See [RELEASING.md](RELEASING.md), [CHANGELOG.md](CHANGELOG.md), [SECURITY.md](SECURITY.md) and [THIRD-PARTY.md](THIRD-PARTY.md). This plugin is GPL-2.0-or-later; included libraries retain their notices. No Elementor or Elementor Pro code is bundled.
