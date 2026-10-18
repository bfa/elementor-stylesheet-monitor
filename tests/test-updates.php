<?php
// Exercise the real vendored GitHub release selector against synthetic API replies.
define('ABSPATH', __DIR__);
$GLOBALS['filters'] = array();
function add_filter($name, $callback, ...$ignored) { $GLOBALS['filters'][$name][] = $callback; }
function apply_filters($name, $value, ...$args) {
    foreach ($GLOBALS['filters'][$name] ?? array() as $callback) { $value = $callback($value, ...$args); }
    return $value;
}
function is_wp_error($value) { return false; }
require dirname(__DIR__) . '/vendor/plugin-update-checker/plugin-update-checker.php';
require dirname(__DIR__) . '/includes/updates.php';
class FixtureApi extends \YahnisElsts\PluginUpdateChecker\v5p7\Vcs\GitHubApi {
    public $reply = null;
    public $calls = array();
    public function __construct() {}
    protected function api($url, $params = array()) { $this->calls[] = $url; return $this->reply; }
}
function check($condition, $label) { if (!$condition) { throw new RuntimeException($label); } }
function release_fixture($changes = array()) {
    return (object)array_replace(array('tag_name'=>'v0.4.1','draft'=>false,'prerelease'=>false,'created_at'=>'2026-10-18T12:00:00Z',
        'zipball_url'=>'https://api.github.com/source.zip','assets'=>array((object)array('name'=>'elementor-stylesheet-monitor-0.4.1.zip','browser_download_url'=>'https://github.com/bfa/elementor-stylesheet-monitor/releases/download/v0.4.1/elementor-stylesheet-monitor-0.4.1.zip','download_count'=>0))), $changes);
}
$api = new FixtureApi();
$api->setStrategyFilterName('esm_update_release_strategies');
add_filter('esm_update_release_strategies', 'ElementorStylesMonitor\\Updates\\release_strategies');
$api->setReleaseFilter('ElementorStylesMonitor\\Updates\\valid_release', $api::RELEASE_FILTER_SKIP_PRERELEASE, 1);
$api->enableReleaseAssets('/^elementor-stylesheet-monitor-[0-9]+\.[0-9]+\.[0-9]+\.zip$/D', $api::REQUIRE_RELEASE_ASSETS);
$api->reply = release_fixture();
$ref = $api->chooseReference('main');
check($ref && $ref->version === '0.4.1' && strpos($ref->downloadUrl, '/releases/download/v0.4.1/') !== false, 'Stable packaged release should be selected');
foreach (array(array('draft'=>true), array('prerelease'=>true), array('assets'=>array()), array('tag_name'=>'v0.4.1-beta'), array('assets'=>array((object)array('name'=>'source.zip','browser_download_url'=>'https://example.com/source.zip')))) as $bad) {
    $api->reply = release_fixture($bad);
    check($api->chooseReference('main') === null, 'Unsafe release must not fall back to tags or branches');
}
$bad = release_fixture();
$bad->assets[0]->browser_download_url = 'https://example.com/foreign.zip';
$api->reply = $bad;
check($api->chooseReference('main') === null, 'Unexpected download origin rejected');
$bad = release_fixture();
$bad->assets[] = (object)array('name'=>'elementor-stylesheet-monitor-9.9.9.zip');
$api->reply = $bad;
check($api->chooseReference('main') === null, 'Ambiguous version assets rejected');
check(count(array_unique($api->calls)) === 1 && $api->calls[0] === '/repos/:user/:repo/releases/latest', 'Only latest published release strategy queried');
echo "PASS: real vendor selection accepts stable packaged ZIP; rejects drafts, prereleases, missing assets, unexpected origins and ambiguous versions; no branch/tag fallback. No live WordPress/network used.\n";
