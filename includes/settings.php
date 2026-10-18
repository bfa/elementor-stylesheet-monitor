<?php
namespace ElementorStylesMonitor\Settings;
if (!defined('ABSPATH')) { exit; }
const OPTION = 'esm_settings_v1';
function defaults() {
    return array('site_label'=>get_bloginfo('name'), 'recipient'=>get_option('admin_email', ''),
        'email_enabled'=>false, 'compatibility_enabled'=>false, 'collector_enabled'=>false,
        'grace_seconds'=>300, 'reminder_seconds'=>86400, 'required_files'=>array());
}
function clean($input) {
    $d = defaults();
    $input = is_array($input) ? $input : array();
    $files = $input['required_files'] ?? array();
    if (is_string($files)) { $files = preg_split('/[\s,]+/', $files, -1, PREG_SPLIT_NO_EMPTY); }
    $files = is_array($files) ? $files : array();
    $files = array_values(array_unique(array_filter($files, static function($name) {
        return is_string($name) && strlen($name) <= 128 && preg_match('/^custom-[a-z0-9-]+\.min\.css$/D', $name);
    })));
    $label = $d['site_label']; // Always follow the current WordPress site title.
    $recipient = $input['recipient'] ?? $d['recipient'];
    if (is_string($recipient) && trim($recipient) === '') { $recipient = $d['recipient']; }
    return array('site_label'=>substr(sanitize_text_field(is_scalar($label) ? (string)$label : $d['site_label']), 0, 120),
        'recipient'=>sanitize_email(is_scalar($recipient) ? (string)$recipient : ''),
        'collector_enabled'=>!empty($input['collector_enabled']),
        'email_enabled'=>!empty($input['email_enabled']), 'compatibility_enabled'=>!empty($input['compatibility_enabled']),
        'grace_seconds'=>max(60, min(1800, absint($input['grace_seconds'] ?? 300))),
        'reminder_seconds'=>max(3600, min(604800, absint($input['reminder_seconds'] ?? 86400))),
        'required_files'=>array_slice($files, 0, 126));
}
function get() { return clean(get_option(OPTION, defaults())); }
function save() {
    if (!current_user_can('manage_options')) { wp_die('Access denied.'); }
    check_admin_referer('esm_save_settings');
    $input = wp_unslash($_POST['esm'] ?? array());
    $input = is_array($input) ? $input : array();
    // Display human units while retaining existing seconds-based storage/runtime.
    $input['grace_seconds'] = max(1, min(30, (float)($input['grace_minutes'] ?? 5))) * 60;
    $input['reminder_seconds'] = max(1, min(168, (float)($input['reminder_hours'] ?? 24))) * 3600;
    $settings = clean($input);
    if ($settings['email_enabled'] && !is_email($settings['recipient'])) {
        wp_die('Enter a valid recipient before enabling email alarms.');
    }
    $key = trim((string)wp_unslash($_POST['esm_collector_key'] ?? ''));
    if ($key !== '' && !preg_match('/^[a-f0-9]{64}$/D',$key)) { wp_die('Collector key must be 64 lowercase hexadecimal characters.'); }
    $effective_key = $key !== '' ? $key : get_option('esm_collector_key','');
    if ($settings['collector_enabled'] && !preg_match('/^[a-f0-9]{64}$/D',$effective_key)) { wp_die('Configure a collector key before enabling remote collection.'); }
    if ($key !== '') { update_option('esm_collector_key',$key,false); }
    update_option(OPTION, $settings, false);
    if (!$settings['email_enabled']) { wp_clear_scheduled_hook('esm_email_followup'); }
    wp_safe_redirect(admin_url('tools.php?page=elementor-stylesheet-monitor&tab=settings&saved=1'));
    exit;
}
add_action('admin_post_esm_save_settings', __NAMESPACE__ . '\\save');
function render() {
    if (!current_user_can('manage_options')) { return; }
    $s = get();
    echo '<h2>Settings</h2>';
    if (isset($_GET['saved'])) { echo '<div class="notice notice-success"><p>Settings saved.</p></div>'; }
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('esm_save_settings');
    echo '<input type="hidden" name="action" value="esm_save_settings"><table class="form-table"><tbody>';
    echo '<tr><th><label for="esm-recipient">Alarm recipient</label></th><td><input class="regular-text" id="esm-recipient" type="email" name="esm[recipient]" value="' . esc_attr($s['recipient']) . '"><p>Defaults to the WordPress administration email address. Leave blank to use that address.</p></td></tr>';
    foreach (array(
        'grace_minutes'=>array('Purge grace period (minutes)', $s['grace_seconds'] / 60, 1, 30),
        'reminder_hours'=>array('Alarm reminder interval (hours)', $s['reminder_seconds'] / 3600, 1, 168)
    ) as $key=>$field) {
        echo '<tr><th><label for="esm-' . esc_attr($key) . '">' . esc_html($field[0]) . '</label></th><td><input class="small-text" id="esm-' . esc_attr($key) . '" type="number" step="any" min="' . esc_attr($field[2]) . '" max="' . esc_attr($field[3]) . '" name="esm[' . esc_attr($key) . ']" value="' . esc_attr($field[1]) . '"></td></tr>';
    }
    foreach (array('compatibility_enabled'=>'Enable targeted cache compatibility', 'email_enabled'=>'Enable email alarms', 'collector_enabled'=>'Enable signed remote collection') as $key=>$label) {
        echo '<tr><th>' . esc_html($label) . '</th><td><label><input type="checkbox" name="esm[' . esc_attr($key) . ']" value="1" ' . checked($s[$key], true, false) . '> Enabled</label></td></tr>';
    }
    echo '<tr><th><label for="esm-files">Additional required stylesheets</label></th><td><textarea id="esm-files" name="esm[required_files]" rows="6" cols="60">' . esc_textarea(implode("\n", $s['required_files'])) . '</textarea><p>One custom-*.min.css filename per line. Core frontend and Apple WebKit files plus valid persisted Elementor metadata are checked automatically. Add widget files here when they must remain expected even if metadata disappears.</p></td></tr>';
    echo '<tr><th><label for="esm-key">Collector signing key</label></th><td><input type="password" autocomplete="new-password" id="esm-key" name="esm_collector_key" value="" class="regular-text"><p>64 lowercase hexadecimal characters. Leave blank to preserve the existing key. The saved key is never displayed. Configure the same key in your private Apps Script properties.</p></td></tr>';
    echo '</tbody></table><p>The optional collector uses signed requests; configure it separately. Enabling compatibility removes conflicting metadata cache copies; it does not write CSS or database metadata. Email checks depend on WordPress requests and cron.</p>';
    submit_button('Save settings');
    echo '</form>';
}
