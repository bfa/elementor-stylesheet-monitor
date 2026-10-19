<?php
namespace ElementorStylesMonitor\Reports;
if (!defined('ABSPATH')) { exit; }
const RECEIPT_OPTION = 'esm_report_receipt_v1';
function preferences() {
    $s = \ElementorStylesMonitor\Settings\get();
    $p = array('schemaVersion'=>1, 'enabled'=>$s['reports_enabled'],
        'frequency'=>$s['report_frequency'], 'hour'=>$s['report_hour'],
        'weekday'=>$s['report_weekday'], 'timezone'=>wp_timezone()->getName(),
        'recipient'=>$s['recipient']);
    $p['revision'] = hash('sha256', wp_json_encode($p));
    return $p;
}
function rows() {
    $p = preferences();
    $s = \ElementorStylesMonitor\Settings\get();
    $r = get_option(RECEIPT_OPTION, array());
    $r = is_array($r) ? $r : array();
    $synced = ($r['revision'] ?? '') === $p['revision'];
    $fresh = !empty($r['receivedUtc']) && (strtotime($r['receivedUtc']) ?: 0) >= time()-7200;
    $state = !$p['enabled'] ? 'Disabled' : (!$s['collector_enabled'] || !is_email($p['recipient']) ? 'Attention' : 'Enabled');
    $detail = 'Uses alarm recipient; collection and alarms are independent';
    if (!$s['collector_enabled'] && $p['enabled']) { $detail = 'Signed remote collection must be enabled for Google reports'; }
    $rows = array(\ElementorStylesMonitor\Status\row('reports','Email reports',$state,$detail,'plugin configuration',gmdate('c')));
    $days = array(1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday');
    $schedule = ucfirst($p['frequency']) . ($p['frequency'] === 'weekly' ? ' on ' . $days[$p['weekday']] : '')
        . ' at ' . ($p['hour'] % 12 ?: 12) . ':00 ' . ($p['hour']<12 ? 'AM' : 'PM') . ' (' . $p['timezone'] . ')';
    if ($p['enabled']) {
        if (!$synced) { $schedule .= '; awaiting Google settings acknowledgment'; }
        elseif (!$fresh) { $schedule .= '; Google report check is stale'; }
        elseif (!empty($r['nextUtc'])) { $schedule .= '; next window ' . \ElementorStylesMonitor\Log\StylesheetHealth\eastern($r['nextUtc']); }
        else { $schedule .= '; no next window acknowledged'; }
    } else { $schedule .= '; reports disabled' . (!$synced ? '; awaiting Google acknowledgment' : ''); }
    $rows[] = \ElementorStylesMonitor\Status\row('report_schedule','Report schedule',!$p['enabled'] ? 'Disabled' : (!$synced ? 'Unknown' : (!$fresh ? 'Stale' : 'Recorded')),
        $schedule,'Google report receipt',$r['receivedUtc'] ?? null);
    $status = $r['lastStatus'] ?? 'No submission recorded';
    $labels = array('accepted'=>'Accepted by Google Mail; inbox delivery not verified',
        'failed'=>'Google mail submission failed; reserved period will not be retried automatically',
        'uncertain'=>'A send was reserved; acceptance is unconfirmed; inspect Google execution history');
    $rows[] = \ElementorStylesMonitor\Status\row('report_result','Last report submission',in_array($status,array('failed','uncertain'),true) ? 'Attention' : ($status === 'accepted' ? 'Recorded' : 'Unknown'),
        $labels[$status] ?? 'No submission recorded', 'Google report receipt',$r['lastAttemptUtc'] ?? null);
    return $rows;
}
function receipt($request) {
    $d = json_decode($request->get_body(),true);
    $p = preferences();
    $at = is_array($d) && is_string($d['checkedUtc'] ?? null) ? strtotime($d['checkedUtc']) : false;
    $status = $d['lastStatus'] ?? 'none';
    if ($at === false || abs(time()-$at)>300 || ($d['schemaVersion'] ?? null) !== 1
        || ($d['revision'] ?? '') !== $p['revision'] || !in_array($status,array('none','accepted','failed','uncertain'),true)) {
        return new \WP_Error('esm_report_receipt','Invalid report receipt',array('status'=>400));
    }
    $times = array();
    foreach (array('nextUtc','lastAttemptUtc','lastAcceptedUtc') as $key) {
        $value = $d[$key] ?? null;
        $stamp = is_string($value) ? strtotime($value) : false;
        if ($value !== null && ($stamp === false || ($key === 'nextUtc' ? ($stamp < time()-300 || $stamp > time()+9*86400) : ($stamp < 946684800 || $stamp > time()+300)))) {
            return new \WP_Error('esm_report_receipt','Invalid report receipt',array('status'=>400));
        }
        $times[$key] = $stamp === false ? null : gmdate('c',$stamp);
    }
    update_option(RECEIPT_OPTION,array_merge($times,array('revision'=>$p['revision'], 'receivedUtc'=>gmdate('c'), 'lastStatus'=>$status)),false);
    return new \WP_REST_Response(array('ok'=>true),200);
}
