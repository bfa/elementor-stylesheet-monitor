<?php
namespace ElementorStylesMonitor\Updates;
if (!defined('ABSPATH')) { exit; }

const REPOSITORY = 'https://github.com/bfa/elementor-stylesheet-monitor';
const SLUG = 'elementor-stylesheet-monitor';

function release_strategies($strategies) {
    // Never offer an unreviewed branch commit or a tag without a published release.
    return isset($strategies['latest_release'])
        ? array('latest_release' => $strategies['latest_release']) : array();
}

function valid_release($version, $release) {
    if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $version)) { return false; }
    $name = SLUG . '-' . $version . '.zip';
    $found = false;
    foreach ($release->assets ?? array() as $asset) {
        if (preg_match('/^elementor-stylesheet-monitor-[0-9]+\.[0-9]+\.[0-9]+\.zip$/D', $asset->name ?? '')
            && ($asset->name ?? '') !== $name) { return false; }
        if (($asset->name ?? '') === $name
            && ($asset->browser_download_url ?? '') === REPOSITORY . '/releases/download/' . $release->tag_name . '/' . $name) {
            $found = true;
        }
    }
    return $found;
}

function boot($plugin_file) {
    static $checker = null;
    if ($checker !== null || (defined('ESM_DISABLE_UPDATE_CHECKS') && ESM_DISABLE_UPDATE_CHECKS)) { return; }
    try {
        require_once dirname(__DIR__) . '/vendor/plugin-update-checker/plugin-update-checker.php';
        $checker = \YahnisElsts\PluginUpdateChecker\v5p7\PucFactory::buildUpdateChecker(
            REPOSITORY, $plugin_file, SLUG
        );
        $checker->setBranch('main');
        $api = $checker->getVcsApi();
        add_filter('esm_update_release_strategies', __NAMESPACE__ . '\\release_strategies');
        $api->setStrategyFilterName('esm_update_release_strategies');
        $api->setReleaseFilter(__NAMESPACE__ . '\\valid_release', \YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::RELEASE_FILTER_SKIP_PRERELEASE, 1);
        $api->enableReleaseAssets(
            '/^elementor-stylesheet-monitor-[0-9]+\.[0-9]+\.[0-9]+\.zip$/D',
            \YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::REQUIRE_RELEASE_ASSETS
        );
        // WordPress retains control of update installation and its auto-update preference.
        // No site collector credentials are sent to GitHub or used for updates.
    } catch (\Throwable $error) {
        // An unavailable updater must not interrupt site rendering or monitoring.
        error_log('Stylesheet Monitor: update checker could not initialize.');
    }
}
