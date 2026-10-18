<?php

namespace ElementorStylesMonitor\Compatibility;

if (!defined('ABSPATH')) {
    exit;
}

function invalidate_metadata_cache() {
    // Core's autoloaded-option deletion can leave an individual cached copy.
    // Run even when the database option was already absent during the purge.
    // A false return can mean the cache entry was already absent.
    if (!\ElementorStylesMonitor\Settings\get()['compatibility_enabled']) { return false; }
    return wp_cache_delete('elementor-custom-breakpoints-files', 'options');
}

// Run after the diagnostic logger and normal after-purge callbacks.
add_action('elementor/core/files/clear_cache', __NAMESPACE__ . '\\invalidate_metadata_cache', PHP_INT_MAX);

// Observe reads without changing the value or short-circuiting WordPress.
function metadata_read($pre) {
    $GLOBALS['esm_metadata_touched'] = true;
    return $pre;
}

// Associative map order is irrelevant; list order and scalar types are not.
function normalize_metadata($value) {
    if (!is_array($value)) {
        return $value;
    }
    if (!array_is_list($value)) {
        ksort($value);
    }
    foreach ($value as $key => $item) {
        $value[$key] = normalize_metadata($item);
    }
    return $value;
}

function remove_conflicting_copy() {
    if (!\ElementorStylesMonitor\Settings\get()['compatibility_enabled']) { return; }
    global $wpdb;
    $key = 'elementor-custom-breakpoints-files';
    $found = false;
    $cached = wp_cache_get($key, 'options', true, $found);
    if (!$found) {
        return;
    }

    // Only autoloaded metadata has a redundant individual cache copy.
    // Non-autoloaded options legitimately use that individual cache entry.
    $sql = $wpdb->prepare(
        "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
        $key
    );
    $row = $wpdb->get_row($sql, ARRAY_A);
    if (!is_array($row) || !function_exists('wp_autoload_values_to_autoload') ||
        !in_array($row['autoload'], wp_autoload_values_to_autoload(), true)) {
        return;
    }
    $database = maybe_unserialize($row['option_value']);
    if (!is_array($database) || normalize_metadata($cached) === normalize_metadata($database)) {
        return;
    }

    // Avoid acting on a snapshot that visibly changed during inspection.
    // This is not a transaction or a guarantee against a later writer.
    $again = $wpdb->get_row($sql, ARRAY_A);
    $still_found = false;
    $still_cached = wp_cache_get($key, 'options', true, $still_found);
    if ($again !== $row || !$still_found || $still_cached !== $cached) {
        return;
    }
    wp_cache_delete($key, 'options');
}

function metadata_written() {
    $GLOBALS['esm_metadata_touched'] = true;
    remove_conflicting_copy();
}

function finish_metadata_request() {
    if (!empty($GLOBALS['esm_metadata_touched'])) {
        remove_conflicting_copy();
    }
}

function register_final_check() {
    // Register after ordinary plugin loading so the logger can finish first.
    add_action('shutdown', __NAMESPACE__ . '\\finish_metadata_request', PHP_INT_MAX);
}

add_filter('pre_option_elementor-custom-breakpoints-files', __NAMESPACE__ . '\\metadata_read');
add_action('add_option_elementor-custom-breakpoints-files', __NAMESPACE__ . '\\metadata_written', PHP_INT_MAX, 0);
add_action('update_option_elementor-custom-breakpoints-files', __NAMESPACE__ . '\\metadata_written', PHP_INT_MAX, 0);
add_action('wp_loaded', __NAMESPACE__ . '\\register_final_check', PHP_INT_MAX);
