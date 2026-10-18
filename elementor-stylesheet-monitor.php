<?php
/**
 * Plugin Name: Stylesheet Monitor for Elementor
 * Description: Responsive CSS health, bounded purge history, optional cache compatibility and email alarms.
 * Version: 0.4.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Plugin URI: https://github.com/bfa/elementor-stylesheet-monitor
 * Update URI: https://github.com/bfa/elementor-stylesheet-monitor
 * License: GPL-2.0-or-later
 */
namespace ElementorStylesMonitor;
if (!defined('ABSPATH')) { exit; }
const VERSION = '0.4.0';

function legacy_active() {
    return function_exists('LTC\\ElementorPurgeLog\\record_purge')
        || function_exists('LTC\\ElementorCSSCacheCompatibility\\invalidate_metadata_cache')
        || function_exists('LTC\\MonitorEndpoint\\authorize');
}
function activate($network_wide = false) {
    if ($network_wide || legacy_active()) {
        wp_die(esc_html('Activate per site only, after deactivating the previous LTC monitoring and compatibility plugins. Preserve their data for rollback.'));
    }
}
register_activation_hook(__FILE__, __NAMESPACE__ . '\\activate');

function boot() {
    require_once __DIR__ . '/includes/updates.php';
    Updates\boot(__FILE__);
    // Load after all normal plugins, including any legacy plugins.
    // Suspend the entire package rather than register duplicate callbacks.
    if (legacy_active()) {
        add_action('admin_notices', static function() {
            if (current_user_can('manage_options')) {
                echo '<div class="notice notice-warning"><p>Stylesheet Monitor is suspended while legacy LTC tooling is active. Complete the documented migration before using this package.</p></div>';
            }
        });
        return;
    }
    require_once __DIR__ . '/includes/settings.php';
    require_once __DIR__ . '/includes/compatibility.php';
    require_once __DIR__ . '/includes/status.php';
    require_once __DIR__ . '/includes/purge-log.php';
    require_once __DIR__ . '/includes/endpoint.php';
    require_once __DIR__ . '/includes/admin-bar.php';
}
add_action('plugins_loaded', __NAMESPACE__ . '\\boot', PHP_INT_MAX);
function deactivate() { wp_clear_scheduled_hook('esm_email_followup'); }
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\deactivate');
// Data intentionally survives deactivation/uninstall for investigation and rollback.

function plugin_links($links) {
    if (current_user_can('manage_options')) {
        array_unshift($links, '<a href="' . esc_url(admin_url('tools.php?page=elementor-stylesheet-monitor&tab=settings')) . '">' . esc_html__('Settings') . '</a>');
    }
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), __NAMESPACE__ . '\\plugin_links');
