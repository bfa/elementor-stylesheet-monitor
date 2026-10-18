<?php
namespace ElementorStylesMonitor\Log\EmailAlerts;
if (!defined('ABSPATH')) { exit; }

const STATE = 'esm_email_alerts_state_v1';
const HOOK = 'esm_email_followup';
const CHECK_SECONDS = 60;
const RETRY_SECONDS = 300;

function enabled() { $s = \ElementorStylesMonitor\Settings\get(); return $s['email_enabled'] && is_email($s['recipient']); }

// Read our own non-autoloaded state directly: do not trust stale option copies.
function read_row() {
    global $wpdb;
    $old = $wpdb->suppress_errors(true);
    $row = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", STATE), ARRAY_A);
    $error = $wpdb->last_error;
    $wpdb->suppress_errors($old);
    if ($error) { throw new \RuntimeException('Email state read failed'); }
    return $row;
}
function cas($value, $before) {
    global $wpdb;
    $raw = maybe_serialize($value);
    $old = $wpdb->suppress_errors(true);
    if ($before === null) {
        $result = $wpdb->insert($wpdb->options, array('option_name'=>STATE,'option_value'=>$raw,'autoload'=>'no'));
    } else {
        $result = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=%s, autoload='no' WHERE option_name=%s AND BINARY option_value=BINARY %s", $raw, STATE, $before['option_value']));
    }
    $wpdb->suppress_errors($old);
    if ($result !== 1) { return false; }
    wp_cache_delete(STATE, 'options');
    wp_cache_delete('notoptions', 'options');
    $after = read_row();
    return $after && $after['option_value'] === $raw;
}

function decision($health, $state, $now) {
    $status = $health['status'] ?? 'unavailable';
    if ($status === 'awaiting_regeneration') { return 'pending'; }
    if ($status === 'healthy') {
        return !empty($state['active']) && !empty($state['notified']) ? 'recovery' : 'healthy';
    }
    return $status === 'unavailable' ? 'monitor_unavailable' : 'css_failure';
}

function followup($health) {
    if (!enabled()) { return; }
    $when = time() + CHECK_SECONDS;
    if (($health['status'] ?? '') === 'awaiting_regeneration') {
        $purge = strtotime($health['latestPurgeUtc'] ?? '');
        if ($purge !== false) { $when = max($when, $purge + \ElementorStylesMonitor\Settings\get()['grace_seconds'] + 1); }
    }
    $existing = wp_next_scheduled(HOOK);
    if (!$existing) { wp_schedule_single_event($when, HOOK); }
}

function message($kind, $health, $state) {
    $label = \ElementorStylesMonitor\Settings\get()['site_label'];
    $issues = $health['issues'] ?? array();
    $cache_only = $issues && !array_diff($issues, array('stale_individual_cache', 'cache_database_mismatch'));
    $finding = $kind === 'recovery' ? 'Stylesheet checks recovered'
        : ($kind === 'monitor_unavailable' ? 'Stylesheet check unavailable'
        : ($cache_only ? 'CSS cache metadata needs attention' : 'Stylesheet health needs attention'));
    $prefix = $kind === 'recovery' ? '[RECOVERY]' : '[ALARM]';
    $lines = array($label, home_url('/'), '',
        \ElementorStylesMonitor\Status\plain(\ElementorStylesMonitor\Status\rows($health, $state)), '',
        'Findings: ' . implode(', ', array_map('sanitize_key', array_slice($issues, 0, 16))),
        'Monitoring observes files and metadata. It does not regenerate CSS.',
        admin_url('tools.php?page=elementor-stylesheet-monitor'),
        'Checks run on WordPress requests and cron; delivery is not guaranteed to be immediate.');
    return array('subject'=>$prefix . ' ' . $label . ' — ' . $finding, 'body'=>implode("\n", $lines));
}

// Request-driven checks are throttled. A database CAS lease prevents overlapping sends.
// Injected services are used only by isolated tests; no HTTP interface exposes this.
function check($health = null, $services = null) {
    if (!enabled()) { return; }
    if ($services === null) {
        $services = array('read'=>__NAMESPACE__ . '\\read_row', 'cas'=>__NAMESPACE__ . '\\cas',
            'collect'=>static function() { return \ElementorStylesMonitor\Log\StylesheetHealth\observe(get_option(\ElementorStylesMonitor\Log\OPTION, array()), 'plugin_email_check'); },
            'mail'=>static function($mail) { return wp_mail(\ElementorStylesMonitor\Settings\get()['recipient'], $mail['subject'], $mail['body']); },
            'schedule'=>__NAMESPACE__ . '\\followup', 'now'=>time());
    }
    try {
        $now = $services['now'];
        $row = ($services['read'])();
        $state = $row ? maybe_unserialize($row['option_value']) : array();
        if (!is_array($state) || ($state['leaseUntil'] ?? 0) > $now || ($state['nextCheck'] ?? 0) > $now) { return; }
        $state['leaseToken'] = bin2hex(random_bytes(16));
        $state['leaseUntil'] = $now + 300;
        $state['nextCheck'] = $now + CHECK_SECONDS;
        if (!($services['cas'])($state, $row)) { return; }
        $owned = array('option_value'=>maybe_serialize($state));
        try {
            // Recheck enablement after acquiring the lease.
            if (!enabled()) { return; }
            $health = $health ?? ($services['collect'])();
            $state['latest'] = array('checkedUtc'=>$health['checkedUtc'] ?? null,'status'=>$health['status'] ?? 'unavailable');
            $kind = decision($health, $state, $now);
            if ($kind === 'pending') { ($services['schedule'])($health); return; }
            if ($kind === 'healthy') { $state['active'] = false; $state['notified'] = false; return; }
            $due = $kind === 'recovery' || empty($state['active']) || empty($state['lastSent']) || $now - $state['lastSent'] >= \ElementorStylesMonitor\Settings\get()['reminder_seconds'];
            if (!$due || $now - ($state['lastAttempt'] ?? 0) < RETRY_SECONDS) { return; }
            if ($kind !== 'recovery') { $state['active'] = true; }
            $state['lastAttempt'] = $now;
            $state['lastKind'] = $kind;
            $state['mailStatus'] = 'attempt_reserved';
            // Commit before mailing: failed/interrupted runs cannot immediately spam.
            if (!($services['cas'])($state, $owned)) { return; }
            $owned = array('option_value'=>maybe_serialize($state));
            $sent = ($services['mail'])(message($kind, $health, $state));
            $state['mailStatus'] = $sent ? 'accepted_by_wordpress' : 'rejected_by_wordpress';
            if ($sent) {
                $state['lastSent'] = $now;
                $state['notified'] = $kind !== 'recovery';
                if ($kind === 'recovery') { $state['active'] = false; }
            } else { ($services['schedule'])($health); }
        } finally {
            $state['leaseUntil'] = 0;
            ($services['cas'])($state, $owned);
        }
    } catch (\Throwable $error) {
        // Alerting must never break production requests or expose exception details.
        error_log('Stylesheet Monitor email: check could not complete; inspect private state and mail delivery.');
    }
}

function request_check() {
    if (defined('WP_CLI') && WP_CLI) { return; }
    check();
}
function scheduled_check() { check(); }
add_action(HOOK, __NAMESPACE__ . '\\scheduled_check');

function render_status() {
    echo '<h2>Plugin email alarms</h2><p>' . (enabled() ? 'Enabled' : 'Disabled') . ' · Recipient: ' . esc_html(\ElementorStylesMonitor\Settings\get()['recipient']) . '</p>';
    echo '<p>Checks run on website requests, at most once per minute. A recent purge gets the configured grace period to regenerate. Scheduled follow-ups need working WordPress cron and traffic. External monitoring, if configured separately, remains independent.</p>';
    try {
        $row = read_row(); $state = $row ? maybe_unserialize($row['option_value']) : array();
        echo '<p>Last plugin check: ' . esc_html(\ElementorStylesMonitor\Log\StylesheetHealth\eastern($state['latest']['checkedUtc'] ?? null)) . ' · Mail status: ' . esc_html($state['mailStatus'] ?? 'No attempt recorded') . '</p>';
        echo '<p>WordPress accepting mail does not prove inbox delivery. Persistent incidents use the configured reminder interval; failed sends retry after five minutes when checks run.</p>';
    } catch (\Throwable $error) { echo '<p>Email state unavailable.</p>'; }
}
function deactivate() { wp_clear_scheduled_hook(HOOK); }
