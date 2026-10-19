<?php
namespace ElementorStylesMonitor\Endpoint;
if (!defined('ABSPATH')) { exit; }
const KEY_OPTION = 'esm_collector_key';
const RECEIPT_OPTION = 'esm_collector_receipt_v1';
function authorize($request) {
    if (!\ElementorStylesMonitor\Settings\get()['collector_enabled']) {
        return new \WP_Error('esm_disabled', 'Monitoring unavailable', array('status'=>503));
    }
    $key = get_option(KEY_OPTION, '');
    $stamp = $request->get_header('x-esm-time');
    $nonce = $request->get_header('x-esm-nonce');
    $signature = $request->get_header('x-esm-signature');
    if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/D', $key)) {
        return new \WP_Error('esm_unavailable','Monitoring unavailable',array('status'=>503));
    }
    $body = $request->get_body();
    if (strlen($body) > 65536 || !preg_match('/^[0-9]{10}$/D', $stamp) || abs(time()-(int)$stamp)>300
        || !preg_match('/^[a-f0-9]{32}$/D',$nonce) || !preg_match('/^[a-f0-9]{64}$/D',$signature)) {
        return new \WP_Error('esm_denied','Unauthorized',array('status'=>401));
    }
    $canonical = $request->get_method() . "\n" . $request->get_route() . "\n" . $stamp . "\n" . $nonce . "\n" . hash('sha256',$body);
    if (!hash_equals(hash_hmac('sha256',$canonical,$key),$signature)) {
        return new \WP_Error('esm_denied','Unauthorized',array('status'=>401));
    }
    // Unique option_name provides a durable, atomic replay reservation.
    // Do not rely on evictable object-cache/transient entries for replay control.
    global $wpdb;
    $name = 'esm_nonce_' . hash('sha256',$nonce);
    $old = $wpdb->suppress_errors(true);
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT 100", $wpdb->esc_like('esm_nonce_') . '%', time()));
    $created = $wpdb->insert($wpdb->options,array('option_name'=>$name,'option_value'=>(string)(time()+660),'autoload'=>'no'));
    $wpdb->suppress_errors($old);
    return $created === 1 ? true : new \WP_Error('esm_denied','Unauthorized',array('status'=>401));
}
function snapshot() {
    $events = get_option(\ElementorStylesMonitor\Log\OPTION,array());
    $events = is_array($events) ? array_slice($events,-50) : array();
    $health = \ElementorStylesMonitor\Log\StylesheetHealth\observe($events,'collector');
    $data = array('schemaVersion'=>1,'site'=>untrailingslashit(home_url()),'generatedUtc'=>gmdate('c'),
        'reportPreferences'=>\ElementorStylesMonitor\Reports\preferences(),
        'events'=>$events,'cssHealth'=>$health,'statusRows'=>\ElementorStylesMonitor\Status\rows($health));
    if (strlen(wp_json_encode($data)) > 500000) { return new \WP_Error('esm_limit','Snapshot unavailable',array('status'=>503)); }
    return new \WP_REST_Response($data,200);
}
function receipt($request) {
    $data = json_decode($request->get_body(),true);
    $at = is_array($data) && is_string($data['checkedUtc'] ?? null) ? strtotime($data['checkedUtc']) : false;
    $count = $data['http']['checked'] ?? null;
    $failed = $data['http']['failed'] ?? null;
    if ($at === false || abs(time()-$at)>300 || !is_int($count) || $count<0 || $count>128
        || !is_int($failed) || $failed<0 || $failed>$count) {
        return new \WP_Error('esm_receipt','Invalid receipt',array('status'=>400));
    }
    // Store only bounded results, never arbitrary remote prose, URLs or labels.
    update_option(RECEIPT_OPTION,array('receivedUtc'=>gmdate('c'),'checkedUtc'=>gmdate('c',$at),
        'http'=>array('checked'=>$count,'failed'=>$failed)),false);
    return new \WP_REST_Response(array('ok'=>true,'receivedUtc'=>gmdate('c')),200);
}
add_action('rest_api_init',static function() {
    register_rest_route('stylesheet-monitor/v1','/snapshot',array('methods'=>'GET',
        'permission_callback'=>__NAMESPACE__ . '\\authorize','callback'=>__NAMESPACE__ . '\\snapshot'));
    register_rest_route('stylesheet-monitor/v1','/report-receipt',array('methods'=>'POST',
        'permission_callback'=>__NAMESPACE__ . '\\authorize','callback'=>'ElementorStylesMonitor\\Reports\\receipt'));
    register_rest_route('stylesheet-monitor/v1','/receipt',array('methods'=>'POST',
        'permission_callback'=>__NAMESPACE__ . '\\authorize','callback'=>__NAMESPACE__ . '\\receipt'));
});
add_filter('rest_post_dispatch',static function($response,$server,$request) {
    if (strpos($request->get_route(),'/stylesheet-monitor/v1/') === 0) {
        $response->header('Cache-Control','private, no-store, max-age=0');
        $response->header('Pragma','no-cache');
    }
    return $response;
},10,3);
