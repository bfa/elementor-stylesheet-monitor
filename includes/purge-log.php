<?php

namespace ElementorStylesMonitor\Log;

if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/stylesheet-health.php';
require_once __DIR__ . '/email-alerts.php';

const OPTION = 'esm_purge_log_v1';

function record_purge() {
    // Do not capture arguments: they may contain credentials or personal data.
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 24);
    $frames = array();
    foreach ($trace as $frame) {
        $file = isset($frame['file']) ? $frame['file'] : '';
        if ($file && strpos($file, ABSPATH) === 0) {
            $file = substr($file, strlen(ABSPATH));
        } elseif ($file) {
            $file = basename($file);
        }
        $frames[] = array(
            'file' => $file,
            'line' => isset($frame['line']) ? (int) $frame['line'] : 0,
            'call' => (isset($frame['class']) ? $frame['class'] : '')
                . (isset($frame['type']) ? $frame['type'] : '')
                . (isset($frame['function']) ? $frame['function'] : ''),
        );
    }
    global $wp_current_filter;
    $row = array(
        'utc' => gmdate('c'),
        'context' => defined('WP_CLI') && WP_CLI ? 'cli'
            : (wp_doing_cron() ? 'cron' : (wp_doing_ajax() ? 'ajax'
            : (defined('REST_REQUEST') && REST_REQUEST ? 'rest' : (is_admin() ? 'admin' : 'frontend')))),
        'hooks' => is_array($wp_current_filter) ? array_slice($wp_current_filter, -16) : array(),
        'elementor' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : 'unknown',
        'pro' => defined('ELEMENTOR_PRO_VERSION') ? ELEMENTOR_PRO_VERSION : 'unknown',
        'trace' => $frames,
    );
    // Capture the caller now, but observe CSS after all purge callbacks finish.
    $GLOBALS['esm_pending_purge_records'][] = $row;
}
add_action('elementor/core/files/clear_cache', __NAMESPACE__ . '\\record_purge', -9999);

function flush_pending_purges() {
    $pending = $GLOBALS['esm_pending_purge_records'] ?? array();
    if (!$pending) { EmailAlerts\request_check(); return; }
    $GLOBALS['esm_pending_purge_records'] = array();
    $rows = get_option(OPTION, array());
    if (!is_array($rows)) { $rows = array(); }
    $combined = array_merge($rows, $pending);
    $health = StylesheetHealth\observe($combined, 'purge_shutdown');
    foreach ($pending as $row) {
        $row['css_after_purge'] = StylesheetHealth\summary($health);
        $rows[] = $row;
    }
    // Immutable snapshots keep existing collector deduplication stable.
    update_option(OPTION, array_slice($rows, -50), false);
    EmailAlerts\followup($health);
    EmailAlerts\check($health);
}
add_action('shutdown', __NAMESPACE__ . '\\flush_pending_purges', PHP_INT_MAX);

function add_page() {
    add_management_page('Stylesheet Monitor', 'Stylesheet Monitor', 'manage_options',
        'elementor-stylesheet-monitor', __NAMESPACE__ . '\\render_page');
}
add_action('admin_menu', __NAMESPACE__ . '\\add_page');

function describe_event($row) {
    $hooks = isset($row['hooks']) && is_array($row['hooks']) ? $row['hooks'] : array();
    $trace = isset($row['trace']) && is_array($row['trace']) ? $row['trace'] : array();
    $purge = false; $eval = false; $element_cache = false;
    foreach ($trace as $frame) {
        $call = isset($frame['call']) ? (string) $frame['call'] : '';
        $file = isset($frame['file']) ? (string) $frame['file'] : '';
        if ($call === 'Elementor\\Core\\Files\\Manager->clear_cache') { $purge = true; }
        if (strpos($file, 'Eval_Command') !== false || strpos($call, 'Eval_Command') !== false) { $eval = true; }
        if (strpos($call, 'Elementor\\Modules\\ElementCache\\Module->clear_cache') !== false) { $element_cache = true; }
    }
    $title = 'Unknown trigger';
    $labels = array('activated_plugin' => 'Plugin activation', 'deactivated_plugin' => 'Plugin deactivation',
        'upgrader_process_complete' => 'Update process completed', 'switch_theme' => 'Theme change',
        'update_option_elementor_element_cache_ttl' => 'Element cache duration changed');
    foreach ($labels as $hook => $label) {
        if (in_array($hook, $hooks, true)) { $title = $label; break; }
    }
    $context = isset($row['context']) ? (string) $row['context'] : 'unknown';
    if ($context === 'cli' && $eval && !$purge) { $title = 'Direct CLI hook invocation'; }
    $source_labels = array('cli'=>'Command line', 'admin'=>'WordPress admin', 'cron'=>'Scheduled task',
        'frontend'=>'Website request', 'ajax'=>'AJAX request', 'rest'=>'REST API request');
    $source = isset($source_labels[$context]) ? $source_labels[$context] : 'Unknown source';
    $summary = $purge ? 'The trace confirms Elementor’s CSS deletion routine was called. Whether files were deleted is unknown.'
        : 'The logging hook ran, but the captured trace does not show Elementor’s CSS deletion routine. This entry alone does not prove a purge.';
    $caller = $element_cache ? 'Elementor Element Cache' : ($purge ? 'Elementor Files Manager' : ($eval ? 'WP-CLI evaluated command' : 'See technical details'));
    $time = 'Unknown time';
    if (!empty($row['utc'])) {
        try {
            $date = new \DateTimeImmutable((string) $row['utc']);
            $time = $date->setTimezone(wp_timezone())->format('M j, Y · g:i:s A T');
        } catch (\Exception $e) { $time = 'Invalid recorded time'; }
    }
    return array('title'=>$title, 'source'=>$source, 'summary'=>$summary, 'caller'=>$caller, 'time'=>$time,
        'evidence'=>$purge ? 'Deletion routine called; outcome unknown' : 'Hook only; deletion routine not shown');
}

function render_page() {
    if (!current_user_can('manage_options')) { return; }
    $rows = get_option(OPTION, array());
    echo '<div class="wrap"><h1>Stylesheet Monitor</h1>';
    if (!is_array($rows)) { $rows = array(); }
    $tabs = array('status'=>'Status', 'stylesheets'=>'Stylesheets', 'alarms'=>'Alarms', 'events'=>'Events', 'logs'=>'Logs', 'settings'=>'Settings');
    $requested = isset($_GET['tab']) && is_string($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'status';
    $tab = isset($tabs[$requested]) ? $requested : 'status';
    echo '<p>Refresh to check for new information. Times use the WordPress site timezone.</p>';
    echo '<nav class="nav-tab-wrapper" aria-label="Monitor sections">';
    foreach ($tabs as $id => $label) {
        $url = add_query_arg(array('page'=>'elementor-stylesheet-monitor', 'tab'=>$id), admin_url('tools.php'));
        echo '<a class="nav-tab' . ($tab === $id ? ' nav-tab-active' : '') . '" href="' . esc_url($url) . '"' . ($tab === $id ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
    }
    echo '</nav>';
    if ($tab === 'settings') {
        \ElementorStylesMonitor\Settings\render();
        echo '</div>';
        return;
    }
    $health = StylesheetHealth\collect($rows, 'admin_live');
    $saved_health = get_option(StylesheetHealth\OPTION, array());
    if ($tab === 'status') {
        \ElementorStylesMonitor\Status\render(\ElementorStylesMonitor\Status\rows($health));
    } elseif ($tab === 'stylesheets') {
        StylesheetHealth\render($health, $saved_health['history'] ?? array());
    } elseif ($tab === 'alarms') {
        EmailAlerts\render_status();
    } elseif ($tab === 'events') {
    echo '<p>Newest events first · Latest 50 events retained.</p>';
    echo '<p><strong>Reading the log:</strong> An event records a hook invocation, not proof that files disappeared. Existing file protection may block deletion.</p>';
    echo '<p>' . count($rows) . ' recorded events</p>';
    echo '<h2>Purge events</h2>';
    if (!$rows) { echo '<p>No events recorded yet. The logger is waiting for the next hook invocation.</p>'; }
    echo '<div style="overflow-x:auto"><table class="widefat striped"><caption class="screen-reader-text">Recorded events, newest first</caption>';
    echo '<thead><tr><th scope="col">Time</th><th scope="col">Trigger</th><th scope="col">Source</th><th scope="col">Caller</th><th scope="col">Purge evidence</th><th scope="col">End-of-request CSS check</th></tr></thead><tbody>';
    foreach (array_reverse($rows) as $row) {
        if (!is_array($row)) { continue; }
        $event = describe_event($row);
        echo '<tr>';
        foreach (array('time', 'title', 'source', 'caller', 'evidence') as $field) {
            echo '<td style="vertical-align:top;min-width:130px;padding:12px;overflow-wrap:anywhere">' . esc_html($event[$field]) . '</td>';
        }
        $snapshot = $row['css_after_purge'] ?? null;
        echo '<td style="vertical-align:top;min-width:160px;padding:12px">';
        if (is_array($snapshot)) {
            echo esc_html(StylesheetHealth\label($snapshot['status'] ?? '')) . '<br>'
                . esc_html(StylesheetHealth\eastern($snapshot['checkedUtc'] ?? null)) . '<br>'
                . esc_html(($snapshot['presentExpectedCount'] ?? '?') . '/' . ($snapshot['expectedFileCount'] ?? '?') . ' files present');
        } else { echo 'Not captured for this older event'; }
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    } elseif ($tab === 'logs') {
    echo '<h2>Raw diagnostic logs</h2>';
    echo '<details open style="margin-top:24px"><summary style="cursor:pointer;font-size:16px"><strong>Raw log — all records</strong></summary>';
    echo '<p>Complete original records, oldest first. Timestamps remain UTC. Includes versions, hooks, and full captured traces.</p>';
    echo '<pre style="background:#fff;padding:16px;white-space:pre-wrap;overflow-wrap:anywhere">';
    echo esc_html(wp_json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo '</pre></details>';
    echo '<details style="margin-top:24px"><summary><strong>Raw stylesheet check and recorded history</strong></summary><pre style="background:#fff;padding:16px;white-space:pre-wrap;overflow-wrap:anywhere">';
    echo esc_html(wp_json_encode(array('statusRows'=>\ElementorStylesMonitor\Status\rows($health), 'live'=>$health, 'recorded'=>$saved_health), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo '</pre></details>';
    }
    echo '</div>';
}
