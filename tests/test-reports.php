<?php
// Standalone contract checks: no WordPress, database, network or real mail.
define('ABSPATH', __DIR__);
$GLOBALS['options'] = array('admin_email'=>'test@example.org');
function get_bloginfo($k) { return 'Example'; }
function get_option($k,$d=false) { return $GLOBALS['options'][$k] ?? $d; }
function update_option($k,$v,$autoload=false) { $GLOBALS['options'][$k]=$v; }
function sanitize_text_field($v) { return strip_tags($v); }
function sanitize_email($v) { return filter_var($v,FILTER_VALIDATE_EMAIL) ? $v : ''; }
function absint($v) { return abs((int)$v); }
function add_action(...$a) {}
function wp_timezone() { return new DateTimeZone('America/New_York'); }
function wp_json_encode($v) { return json_encode($v); }
class WP_Error { function __construct(...$a) {} }
class WP_REST_Response { function __construct(...$a) {} }
class Request { function __construct(private $d){} function get_body(){return json_encode($this->d);} }
function check($ok) { if(!$ok) throw new Exception('Contract check failed'); }
require __DIR__.'/../includes/settings.php';
require __DIR__.'/../includes/reports.php';
use ElementorStylesMonitor\Settings as S;
use ElementorStylesMonitor\Reports as R;
check(S\get()['reports_enabled']===false);
$GLOBALS['options'][S\OPTION]=array('collector_enabled'=>true,'recipient'=>'test@example.org');
check(S\get()['reports_enabled']===true);
check(S\get()['report_hour']===7 && S\get()['report_weekday']===1);
$stored=S\clean(array('collector_enabled'=>true,'reports_enabled'=>false,'recipient'=>'test@example.org','report_hour'=>99,'report_weekday'=>-1,'report_frequency'=>'bad'));
check($stored['report_hour']===23 && $stored['report_weekday']===1 && $stored['report_frequency']==='daily');
$GLOBALS['options'][S\OPTION]=$stored;check(!S\get()['reports_enabled']);
$p=R\preferences();check($p['recipient']==='test@example.org' && $p['timezone']==='America/New_York');
$valid=array('schemaVersion'=>1,'revision'=>$p['revision'],'checkedUtc'=>gmdate('c'),'nextUtc'=>null,'lastStatus'=>'none','lastAttemptUtc'=>null,'lastAcceptedUtc'=>null);
check(R\receipt(new Request($valid)) instanceof WP_REST_Response);
$before=$GLOBALS['options'][R\RECEIPT_OPTION];
check(R\receipt(new Request(array_merge($valid,array('revision'=>str_repeat('a',64))))) instanceof WP_Error);
check(R\receipt(new Request(array_merge($valid,array('lastStatus'=>'<script>')))) instanceof WP_Error);
check(R\receipt(new Request(array_merge($valid,array('nextUtc'=>gmdate('c',time()+20*86400))))) instanceof WP_Error);
check($before===$GLOBALS['options'][R\RECEIPT_OPTION]);
echo "PASS: new/existing defaults, disabled persistence, bounds, shared recipient/timezone, revision and receipt validation; no real services\n";
