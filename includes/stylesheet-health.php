<?php
namespace ElementorStylesMonitor\Log\StylesheetHealth;

if (!defined('ABSPATH')) { exit; }
const OPTION = 'esm_css_health_v1';
const META_KEY = 'elementor-custom-breakpoints-files';

function responsive_css_required() {
    return !class_exists('Elementor\\Plugin') || !isset(\Elementor\Plugin::$instance->breakpoints)
        || \Elementor\Plugin::$instance->breakpoints->has_custom_breakpoints();
}

function required_files() {
    // Only universal core files are assumed. Other expectations come from
    // persisted Elementor metadata and administrator-selected required files.
    return array_values(array_unique(array_merge(
        responsive_css_required() ? array('custom-frontend.min.css', 'custom-apple-webkit.min.css') : array(),
        \ElementorStylesMonitor\Settings\get()['required_files']
    )));
}

function same_metadata($a, $b) {
    return \ElementorStylesMonitor\Compatibility\normalize_metadata($a)
        === \ElementorStylesMonitor\Compatibility\normalize_metadata($b);
}

function latest_purge($rows) {
    foreach (array_reverse(is_array($rows) ? $rows : array()) as $row) {
        foreach (($row['trace'] ?? array()) as $frame) {
            if (($frame['call'] ?? '') === 'Elementor\\Core\\Files\\Manager->clear_cache') {
                return $row;
            }
        }
    }
    return null;
}

function collect($rows, $source) {
    global $wpdb;
    $now = time();
    $purge = latest_purge($rows);
    $purge_time = $purge ? strtotime($purge['utc'] ?? '') : false;
    $uploads = wp_upload_dir(null, false);
    $directory = rtrim($uploads['basedir'] ?? '', '/\\') . '/elementor/css';
    $old_suppression = $wpdb->suppress_errors(true);
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", META_KEY
    ), ARRAY_A);
    $database_ok = !$wpdb->last_error;
    $wpdb->suppress_errors($old_suppression);
    $database = $row ? maybe_unserialize($row['option_value']) : false;
    $wordpress = get_option(META_KEY, false);
    $found = false;
    $individual = wp_cache_get(META_KEY, 'options', true, $found);
    $individual = $found ? maybe_unserialize($individual) : false;
    $required = required_files();
    $applicable = responsive_css_required() || (bool)$required;
    $names = $required;
    if ($applicable && is_array($database)) {
        foreach (array_keys($database) as $name) {
            if (is_string($name) && strlen($name) <= 124 && preg_match('/^custom-[a-z0-9-]+\.min$/D', $name)) {
                $names[] = $name . '.css';
            }
        }
    }
    $names = array_slice(array_values(array_unique($names)), 0, 128);
    clearstatcache();
    $directory_exists = is_dir($directory);
    $directory_readable = $directory_exists && is_readable($directory);
    $listed = $directory_readable ? glob($directory . '/*.css') : false;
    $files = array(); $missing = array(); $empty = array(); $unreadable = array(); $mismatch = array(); $missing_meta = array();
    $after_purge = 0;
    foreach ($names as $name) {
        $path = $directory . '/' . $name;
        $exists = is_file($path);
        $readable = $exists && is_readable($path);
        $mtime = $exists ? @filemtime($path) : false;
        $size = $exists ? @filesize($path) : false;
        $mode = $exists ? @fileperms($path) : false;
        $meta_key = substr($name, 0, -4);
        $entry = is_array($database) ? ($database[$meta_key] ?? null) : null;
        $meta_time = is_array($entry) ? ($entry['time'] ?? null) : null;
        $meta_time = is_numeric($meta_time) && (int) $meta_time > 0 ? (int) $meta_time : null;
        if ($meta_time === null && in_array($name, $required, true)) { $missing_meta[] = $name; }
        $delta = $mtime !== false && $meta_time !== null ? $mtime - $meta_time : null;
        $matches = $delta === null ? null : abs($delta) <= 2;
        $newer = $purge_time !== false && $mtime !== false ? $mtime >= $purge_time : null;
        if (!$exists) { $missing[] = $name; }
        elseif (!$readable || $mtime === false || $size === false) { $unreadable[] = $name; }
        elseif ($size === 0) { $empty[] = $name; }
        if ($matches === false) { $mismatch[] = $name; }
        if ($exists && $newer) { $after_purge++; }
        $files[] = array('name'=>$name, 'required'=>in_array($name, $required, true),
            'exists'=>$exists, 'readable'=>$readable, 'sizeBytes'=>$size === false ? null : $size,
            'mtime'=>$mtime === false ? null : $mtime,
            'modifiedUtc'=>$mtime === false ? null : gmdate('c', $mtime),
            'mode'=>$mode === false ? null : sprintf('%04o', $mode & 0777),
            'metadataTime'=>$meta_time, 'metadataUtc'=>$meta_time === null ? null : gmdate('c', $meta_time),
            'timestampDeltaSeconds'=>$delta, 'timestampsMatch'=>$matches, 'atOrAfterLatestPurge'=>$newer);
    }
    $issues = array();
    if ($applicable && (!$directory_readable || $listed === false)) { $issues[] = 'directory_unreadable'; }
    if ($applicable && $directory_exists && !is_writable($directory)) { $issues[] = 'directory_not_writable'; }
    if (!$database_ok) { $issues[] = 'database_check_failed'; }
    if ($database_ok && !same_metadata($wordpress, $database)) { $issues[] = 'cache_database_mismatch'; }
    if ($database_ok && $found && ($row === null || !same_metadata($individual, $database))) { $issues[] = 'stale_individual_cache'; }
    if ($applicable && (!$row || !is_array($database))) { $issues[] = 'generation_metadata_missing'; }
    if ($missing_meta) { $issues[] = 'required_metadata_missing'; }
    if ($missing) { $issues[] = 'files_missing'; }
    if ($empty) { $issues[] = 'files_empty'; }
    if ($unreadable) { $issues[] = 'files_unreadable'; }
    if ($mismatch) { $issues[] = 'file_metadata_timestamp_mismatch'; }
    $age = $purge_time === false ? null : max(0, $now - $purge_time);
    $expected_pending = $age !== null && $age <= \ElementorStylesMonitor\Settings\get()['grace_seconds']
        && !array_diff($issues, array('generation_metadata_missing', 'required_metadata_missing', 'files_missing'));
    $status = ($applicable && (!$directory_readable || $listed === false)) || !$database_ok ? 'unavailable'
        : (!$issues ? 'healthy' : ($expected_pending ? 'awaiting_regeneration' : 'attention'));
    $directory_mode = $directory_exists ? @fileperms($directory) : false;
    return array('schemaVersion'=>1, 'checkedUtc'=>gmdate('c', $now), 'source'=>$source, 'status'=>$status, 'responsiveCssRequired'=>$applicable,
        'latestPurgeUtc'=>$purge_time === false ? null : gmdate('c', $purge_time),
        'secondsSincePurge'=>$age, 'graceSeconds'=>\ElementorStylesMonitor\Settings\get()['grace_seconds'],
        'directory'=>array('exists'=>$directory_exists, 'readable'=>$directory_readable,
            'writable'=>$directory_exists && is_writable($directory),
            'mode'=>$directory_mode === false ? null : sprintf('%04o', $directory_mode & 0777)),
        'cssFileCount'=>$listed === false ? null : count($listed), 'expectedFileCount'=>count($names),
        'presentExpectedCount'=>count($names) - count($missing),
        'filesAtOrAfterLatestPurge'=>$purge_time === false ? null : $after_purge,
        'metadata'=>array('databaseCheckOk'=>$database_ok, 'databaseExists'=>$row !== null,
            'autoload'=>$row['autoload'] ?? null, 'cacheDatabaseMatch'=>$database_ok ? same_metadata($wordpress, $database) : null,
            'individualPresent'=>$found, 'individualMatchesDatabase'=>$database_ok && $found ? same_metadata($individual, $database) : null),
        'missingFiles'=>$missing, 'emptyFiles'=>$empty, 'unreadableFiles'=>$unreadable,
        'timestampMismatchFiles'=>$mismatch, 'missingMetadataFiles'=>$missing_meta, 'issues'=>$issues, 'files'=>$files);
}

function summary($health) {
    $result = $health;
    $result['files'] = array_values(array_filter($health['files'], static function ($file) { return $file['required']; }));
    return $result;
}

function observe($rows, $source) {
    $health = collect($rows, $source);
    $saved = get_option(OPTION, array());
    $history = is_array($saved) && is_array($saved['history'] ?? null) ? $saved['history'] : array();
    $history[] = summary($health);
    update_option(OPTION, array('latest'=>$health, 'history'=>array_slice($history, -48)), false);
    return $health;
}

function label($status) {
    $labels = array('healthy'=>'Files present; metadata consistent', 'awaiting_regeneration'=>'Awaiting regeneration (recent purge)',
        'attention'=>'Needs attention', 'unavailable'=>'Check unavailable');
    return $labels[$status] ?? 'Unknown';
}

function eastern($utc) {
    if (!$utc) { return 'Not recorded'; }
    try { return (new \DateTimeImmutable($utc))->setTimezone(wp_timezone())->format('M j, Y · g:i:s A T'); }
    catch (\Exception $error) { return 'Invalid timestamp'; }
}

function render($health, $history) {
    echo '<h2>Stylesheet regeneration checks</h2><p><strong>' . esc_html(label($health['status'])) . '</strong> · Checked ' . esc_html(eastern($health['checkedUtc'])) . '</p>';
    echo '<p>' . esc_html((string) $health['cssFileCount']) . ' CSS files found · ' . esc_html((string) $health['presentExpectedCount']) . '/' . esc_html((string) $health['expectedFileCount']) . ' expected files present · Directory mode ' . esc_html($health['directory']['mode'] ?? 'unknown') . ' · ' . ($health['directory']['writable'] ? 'Writable' : 'Not writable') . '</p>';
    echo '<p>Last recorded purge: ' . esc_html(eastern($health['latestPurgeUtc'])) . '. Checks observe files and generation metadata; they do not trigger regeneration. Timestamp comparisons allow a two-second write boundary. A new timestamp alone does not prove CSS content is correct.</p>';
    if ($health['filesAtOrAfterLatestPurge'] !== null) { echo '<p>Expected files written at or after that purge: ' . esc_html($health['filesAtOrAfterLatestPurge'] . '/' . $health['expectedFileCount']) . '. Files retained through a purge can remain valid without a new write.</p>'; }
    if ($health['issues']) { echo '<p><strong>Findings:</strong> ' . esc_html(str_replace('_', ' ', implode(', ', $health['issues']))) . '</p>'; }
    echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th>Stylesheet</th><th>File check</th><th>Last written</th><th>Generation metadata</th><th>Timestamp comparison</th></tr></thead><tbody>';
    foreach ($health['files'] as $file) {
        $state = !$file['exists'] ? 'Missing' : (!$file['readable'] ? 'Unreadable' : ($file['sizeBytes'] === 0 ? 'Empty' : 'Present (' . $file['sizeBytes'] . ' bytes)'));
        $match = $file['timestampsMatch'] === null ? 'Metadata unavailable' : ($file['timestampsMatch'] ? 'Within 2 seconds' : 'Mismatch (' . $file['timestampDeltaSeconds'] . ' seconds)');
        echo '<tr><td>' . esc_html($file['name']) . '</td><td>' . esc_html($state) . '</td><td>' . esc_html(eastern($file['modifiedUtc'])) . '</td><td>' . esc_html(eastern($file['metadataUtc'])) . '</td><td>' . esc_html($match) . '</td></tr>';
    }
    echo '</tbody></table></div><h3>Recent recorded checks</h3><p>Latest 48 checks retained. Times use the WordPress site timezone. Opening this page shows a live check; hourly collection and purge checks record history. Public HTTP checks appear in the Google daily report.</p><table class="widefat striped"><thead><tr><th>Checked</th><th>Source</th><th>Observed state</th><th>Files present / expected</th></tr></thead><tbody>';
    foreach (array_reverse(is_array($history) ? $history : array()) as $check) {
        echo '<tr><td>' . esc_html(eastern($check['checkedUtc'] ?? null)) . '</td><td>' . esc_html($check['source'] ?? '') . '</td><td>' . esc_html(label($check['status'] ?? '')) . '</td><td>' . esc_html(($check['presentExpectedCount'] ?? '?') . ' / ' . ($check['expectedFileCount'] ?? '?')) . '</td></tr>';
    }
    echo '</tbody></table>';
}
