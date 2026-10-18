<?php
namespace ElementorStylesMonitor\Status;
if (!defined('ABSPATH')) { exit; }
function row($id, $label, $state, $detail, $source, $checked) {
    return array('id'=>$id,'label'=>$label,'status'=>$state,'detail'=>$detail,'source'=>$source,'checkedUtc'=>$checked);
}
function rows($health, $mail_state = null) {
    $checked = $health['checkedUtc'] ?? null;
    $source = $health['source'] ?? 'unknown';
    $applicable = $health['responsiveCssRequired'] ?? true;
    $valid = ($health['status'] ?? '') !== 'unavailable';
    $pending = ($health['status'] ?? '') === 'awaiting_regeneration';
    $issues = $health['issues'] ?? array();
    $file_bad = array_intersect($issues, array('directory_unreadable','files_missing','files_empty','files_unreadable'));
    $meta_bad = array_intersect($issues, array('generation_metadata_missing','required_metadata_missing','file_metadata_timestamp_mismatch'));
    $meta = $health['metadata'] ?? array();
    $cache_bad = array_intersect($issues, array('stale_individual_cache','cache_database_mismatch'));
    $s = \ElementorStylesMonitor\Settings\get();
    $rows = array(
        row('files','CSS files', !$applicable ? 'Disabled' : (!$valid ? 'Unknown' : ($file_bad ? ($pending ? 'Pending' : 'Attention') : 'Healthy')),
            ($health['presentExpectedCount'] ?? '?') . '/' . ($health['expectedFileCount'] ?? '?') . ' expected files present' . (!$applicable ? '; custom responsive CSS is not required by this configuration' : ''), $source,$checked),
        row('metadata','Generation metadata', !$applicable ? 'Disabled' : (empty($meta['databaseCheckOk']) ? 'Unknown' : ($meta_bad ? ($pending ? 'Pending' : 'Attention') : 'Healthy')),
            !$applicable ? 'Custom responsive CSS is not required' : (empty($meta['databaseCheckOk']) ? 'Database metadata could not be checked' : ($meta_bad ? implode(', ', $meta_bad) : 'File timestamps and generation metadata agree')), $source,$checked),
        row('cache','Object cache', empty($meta['databaseCheckOk']) ? 'Unknown' : ($cache_bad ? 'Attention' : 'Healthy'),
            empty($meta['databaseCheckOk']) ? 'Cache comparison could not be completed' : ($cache_bad ? implode(', ', $cache_bad) : 'No conflicting option cache observed'), $source,$checked),
        row('directory','CSS directory', !$applicable ? 'Disabled' : (!isset($health['directory']) ? 'Unknown' : (empty($health['directory']['writable']) ? 'Attention' : 'Healthy')),
            'Mode ' . ($health['directory']['mode'] ?? 'unknown') . '; ' . (!empty($health['directory']['writable']) ? 'writable' : 'not writable'), $source,$checked),
        row('compatibility','Cache compatibility',$s['compatibility_enabled'] ? 'Enabled' : 'Disabled',
            'Targeted invalidation; does not prove future race immunity', 'plugin configuration', gmdate('c')),
        row('versions','Elementor / Pro',defined('ELEMENTOR_VERSION') ? 'Unknown' : 'Attention',
            (defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : 'Elementor unavailable') . ' / ' . (defined('ELEMENTOR_PRO_VERSION') ? ELEMENTOR_PRO_VERSION : 'Pro not installed') . '; exact-version behavior has not been certified by this package', 'runtime constants',gmdate('c')),
        row('purge','Latest purge',empty($health['latestPurgeUtc']) ? 'Unknown' : 'Recorded',
            empty($health['latestPurgeUtc']) ? 'No deletion routine recorded' : 'Deletion routine observed; this alone does not prove deletion', 'purge trace',$health['latestPurgeUtc'] ?? null)
    );
    $receipt = get_option('esm_collector_receipt_v1', array());
    $receipt = is_array($receipt) ? $receipt : array();
    $received = strtotime($receipt['receivedUtc'] ?? '') ?: 0;
    $fresh = $received && time() - $received <= 7200;
    $http = is_array($receipt['http'] ?? null) ? $receipt['http'] : array();
    $rows[] = row('collector','External collection', !$s['collector_enabled'] ? 'Disabled' : (!$received ? 'Unknown' : ($fresh ? 'Healthy' : 'Stale')),
        'Latest signed receipt after Drive storage; report delivery is separate', 'authenticated collector receipt', $receipt['receivedUtc'] ?? null);
    $rows[] = row('http','Public stylesheet URLs', !$s['collector_enabled'] || !$received ? 'Unknown' : (!$fresh ? 'Stale' : (empty($http['checked']) ? 'Unknown' : (!empty($http['failed']) ? 'Attention' : 'Healthy'))),
        ($http['checked'] ?? 0) . ' checked; ' . ($http['failed'] ?? 0) . ' failed; HTTP checks do not prove visual correctness', 'authenticated collector receipt',$receipt['checkedUtc'] ?? null);
    try {
        if ($mail_state === null) {
            $mail_row = \ElementorStylesMonitor\Log\EmailAlerts\read_row();
            $mail = $mail_row ? maybe_unserialize($mail_row['option_value']) : array();
        } else { $mail = $mail_state; }
        $mail = is_array($mail) ? $mail : array();
        $mail_status = $mail['mailStatus'] ?? 'No attempt recorded';
        $last_check = $mail['latest']['checkedUtc'] ?? null;
        $rows[] = row('email','Plugin email alarms',!$s['email_enabled'] ? 'Disabled' : (!is_email($s['recipient']) || $mail_status === 'rejected_by_wordpress' ? 'Attention' : 'Enabled'),
            $mail_status . '; inbox delivery is not verified', 'private email state',$last_check);
        if (!empty($mail['lastAttempt'])) {
            $rows[] = row('incident','Latest alarm attempt','Recorded',($mail['lastKind'] ?? 'unknown') . '; ' . $mail_status, 'private email state',gmdate('c', (int)$mail['lastAttempt']));
        }
        if ($s['email_enabled']) {
            $age = $last_check ? time() - (strtotime($last_check) ?: 0) : null;
            $rows[] = row('freshness','Email check freshness',$age === null ? 'Unknown' : ($age > 300 ? 'Stale' : 'Healthy'),
                $age === null ? 'No check recorded' : 'Last recorded check ' . max(0,$age) . ' seconds ago; request-driven checks', 'private email state',$last_check);
        }
    } catch (\Throwable $e) {
        $rows[] = row('email','Plugin email alarms','Unknown','Email state could not be read','private email state',null);
    }
    // Feature switches keep a fixed group even when disabled or needing attention.
    $groups = array(
        array('files','http','metadata','cache','directory','collector','freshness'),
        array('compatibility','email'),
        array('versions','purge','incident')
    );
    $rank = array('Attention'=>0,'Stale'=>1,'Pending'=>2,'Unknown'=>3,'Disabled'=>4,'Enabled'=>5,'Healthy'=>5);
    $position = static function($id) use ($groups) {
        foreach ($groups as $group => $ids) {
            $index = array_search($id, $ids, true);
            if ($index !== false) { return array($group, $index); }
        }
        return array(2, 99);
    };
    usort($rows, static function($a,$b) use ($position,$rank) {
        list($ag,$ai) = $position($a['id']); list($bg,$bi) = $position($b['id']);
        if ($ag !== $bg) { return $ag <=> $bg; }
        if ($ag === 0) {
            $severity = ($rank[$a['status']] ?? 6) <=> ($rank[$b['status']] ?? 6);
            if ($severity) { return $severity; }
        }
        return $ai <=> $bi;
    });
    return $rows;
}
// Presentation only: retain raw status values for collectors and existing journals.
function badge($row) {
    $state = $row['status'];
    if ($state === 'Attention' && in_array($row['id'], array('files','metadata','directory','http'), true)) { return '🔴 Failure'; }
    $icons = array('Healthy'=>'🟢','Enabled'=>'🟢','Pending'=>'🟡','Attention'=>'🟠','Stale'=>'🟠','Disabled'=>'⚪','Unknown'=>'⚪','Recorded'=>'🔵');
    if ($row['id'] === 'versions' && $state === 'Unknown') { return '🔵 Informational'; }
    return ($icons[$state] ?? '⚪') . ' ' . $state;
}
function summary($rows) {
    $checks = array_values(array_filter($rows, static function($r) { return in_array($r['id'], array('files','metadata','cache','directory'), true); }));
    foreach ($checks as $r) { if (strpos(badge($r), '🔴') === 0) { return '🔴 Stylesheet checks failed'; } }
    foreach ($checks as $r) { if (in_array($r['status'], array('Attention','Stale'), true)) { return '🟠 Stylesheet checks need attention'; } }
    foreach ($checks as $r) { if ($r['status'] === 'Pending') { return '🟡 Awaiting stylesheet regeneration'; } }
    if (!$checks) { return '⚪ Stylesheet status unknown'; }
    foreach ($checks as $r) { if ($r['status'] === 'Unknown') { return '⚪ Stylesheet status unknown'; } }
    return '🟢 Stylesheets healthy';
}
function plain($rows) {
    $lines = array(summary($rows), 'STATUS | CHECK | DETAIL | SOURCE | CHECKED');
    foreach ($rows as $r) {
        $lines[] = implode(' | ', array(badge($r),$r['label'],$r['detail'],$r['source'],$r['checkedUtc'] ?? 'Not checked'));
    }
    return implode("\n", $lines);
}
function render($rows) {
    echo '<h2>Status at a glance</h2><p><strong>' . esc_html(summary($rows)) . '</strong></p><p><a href="' . esc_url(admin_url('tools.php?page=elementor-stylesheet-monitor&tab=settings')) . '">Settings</a> · Unknown and Stale are explicit gaps in evidence. Times use the WordPress site timezone.</p><table class="widefat striped"><thead><tr><th>Status</th><th>Check</th><th>Details</th><th>Source</th><th>Checked</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td><strong>' . esc_html(badge($r)) . '</strong></td><td>' . esc_html($r['label']) . '</td><td>' . esc_html($r['detail']) . '</td><td>' . esc_html($r['source']) . '</td><td>' . esc_html(\ElementorStylesMonitor\Log\StylesheetHealth\eastern($r['checkedUtc'])) . '</td></tr>';
    }
    echo '</tbody></table>';
}
