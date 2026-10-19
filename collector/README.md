# Google collector — report scheduling v1 (ESM 0.5.0)

Use a private Google Apps Script project with Collector.gs, Reports.gs and ReportSchedule.gs, or concatenate those three files into Code.gs. Do not install both forms. V8 and the supplied manifest are required. This is a pull collector; no public web-app deployment is needed.

## Configuration

Script Properties holds `ESM_CONFIG` using config.example.json, plus one private `ESM_KEY_<site-id>` per site, matching the WordPress collector key. Keep site IDs unchanged to retain history and deduplication. Use private Drive folders; project editors can read credentials. Never put real keys or client records in source or public issues.

Each site's configured recipient remains the independent collector alarm/failure destination. ESM 0.5.0 reports use the WordPress alarm recipient delivered by the authenticated snapshot; there is no separate report recipient. Site title/label configuration and CSS base URLs remain unchanged. If a plugin has not yet upgraded, daily reports retain the configured recipient, local zone and global reportHour (default 7).

## Report controls

WordPress Settings controls enabled/disabled, daily/weekly, preferred local hour, and weekly day (Monday default). Existing collector-enabled installations preserve daily reports at 7 AM; new installations default reports off. WordPress site timezone and alarm recipient are authoritative. Collection and alarms do not stop when reports are disabled.

Google checks current preferences before every report attempt. Failed requests pause reports rather than reuse stale enabled settings. Weekly sends run on the selected local day. Delivery is within an hourly scheduling window; delayed executions may catch up later that day. Missing DST hours fall forward to the next available local hour. Evidence windows are capped at seven days.

Settings acknowledgments, next expected windows, last attempts and accepted-submission timestamps are returned to WordPress through a signed, revision-matched POST receipt. Mail acceptance is not verified inbox delivery. Calendar-period reservations prevent duplicate sends after retries or setting changes. A failed/uncertain send is not retried automatically in the same period; inspect Apps Script execution history before any manual recovery. Do not clear report state casually.

## Deployment and triggers

1. Preserve current project source and keep its private properties and journals unchanged.
2. Update the collector source, then update the WordPress plugin. Until the plugin upgrades, the collector uses the old daily preferences.
3. Run `esmSyncReportSettings` to acknowledge settings without sending mail or changing schedules.
4. For an existing installation, run `esmInstallHourlyReports` once. It is idempotent through `ESM_REPORT_HOURLY_TRIGGER`; it leaves the collection trigger untouched. Remove the previous daily `esmReportAll` trigger after confirming the hourly one. If both run during handoff, locks/reservations prevent duplicate reporting.
5. New projects use `esmInstallTriggers` to install one hourly collection and one hourly report check; it refuses existing ESM triggers.
6. Confirm the WordPress Status report rows and inspect subsequent executions. Running `esmReportAll` manually can send real mail if due; `esmCollectAll` can send real alarms/recoveries.

No new OAuth scopes are required over the existing collector. Keep alarm/report state (`ESM_STATE_<id>`), signing keys and configuration private. Scheduler marker: `ESM_REPORT_HOURLY_TRIGGER`. `esmRemoveTriggers` removes this project's ESM collection/report triggers only. No journals or properties are automatically deleted.

## Limits and rollback

Up to 10 sites; each site has its own key, folder, state and report preferences. Source snapshots are bounded to 500 KB, journals to 100 collections/4 MB per UTC day, source events to 50 per snapshot, and report event details to 50. HTTP samples check up to six required CSS assets, status 200 and CSS content type; this is not a visual test or continuous availability guarantee.

Restore both collector source and plugin for rollback; restore the former daily trigger and remove the hourly report trigger if reverting the scheduler. Retain report cursors and keys. An older collector ignores the new report switch, so do not leave mismatched components after intentionally disabling reports.
