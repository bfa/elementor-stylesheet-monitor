<?php
namespace ElementorStylesMonitor\AdminBar;
if (!defined('ABSPATH')) { exit; }

function indicator($row) {
    $badge = \ElementorStylesMonitor\Status\badge($row);
    $colors = array('🟢'=>'green','🟡'=>'yellow','🟠'=>'orange','🔴'=>'red','🔵'=>'blue','⚪'=>'gray');
    foreach ($colors as $icon => $color) {
        if (strpos($badge, $icon) === 0) {
            return '<span class="esm-light esm-light-' . $color . '" aria-hidden="true"></span>'
                . esc_html(trim(substr($badge, strlen($icon))));
        }
    }
    return esc_html($badge);
}

function menu($bar) {
    if (!current_user_can('manage_options') || !is_admin_bar_showing()) { return; }
    // Read existing evidence only. Rendering the toolbar never runs health checks.
    $saved = get_option('esm_css_health_v1', array());
    $health = is_array($saved) && is_array($saved['latest'] ?? null) ? $saved['latest'] : array();
    $checked = strtotime($health['checkedUtc'] ?? '') ?: 0;
    $fresh = $checked && $checked <= time() + 60 && time() - $checked <= 300;
    $rows = \ElementorStylesMonitor\Status\rows($health);
    if (!$checked) {
        $overall = array('id'=>'toolbar','status'=>'Unknown');
    } else {
        $summary = \ElementorStylesMonitor\Status\summary($rows);
        $state = strpos($summary, '🔴') === 0 ? 'Attention' : (strpos($summary, '🟠') === 0 ? 'Attention' : (strpos($summary, '🟡') === 0 ? 'Pending' : (strpos($summary, '🟢') === 0 ? 'Healthy' : 'Unknown')));
        $overall = array('id'=>strpos($summary, '🔴') === 0 ? 'files' : 'toolbar','status'=>$state);
        // Retain a recorded failure; never present an old passing check as current.
        if (!$fresh && in_array($state, array('Healthy','Pending','Unknown'), true)) { $overall['status'] = 'Stale'; }
    }
    $url = admin_url('tools.php?page=elementor-stylesheet-monitor');
    $label = indicator($overall);
    $bar->add_node(array('id'=>'esm-health','title'=>'Stylesheet Health: ' . $label,'href'=>$url));
    foreach ($rows as $row) {
        if (!in_array($row['id'], array('files','http','metadata','cache','directory','collector','compatibility','email'), true)) { continue; }
        if (!$fresh && in_array($row['id'], array('files','metadata','cache','directory'), true)
            && in_array($row['status'], array('Healthy','Pending'), true)) { $row['status'] = 'Stale'; }
        $bar->add_node(array('id'=>'esm-health-' . $row['id'],'parent'=>'esm-health',
            'title'=>esc_html($row['label']) . ': ' . indicator($row),'href'=>$url,
            'meta'=>array('title'=>$row['detail'])));
    }
    $bar->add_node(array('id'=>'esm-health-checked','parent'=>'esm-health',
        'title'=>esc_html('Last checked: ' . \ElementorStylesMonitor\Log\StylesheetHealth\eastern($health['checkedUtc'] ?? null)),
        'meta'=>array('title'=>'Recorded evidence; older than five minutes is marked stale. Open the monitor for a current check.')));
    $bar->add_node(array('id'=>'esm-health-monitor','parent'=>'esm-health','title'=>'View monitor','href'=>$url));
    $bar->add_node(array('id'=>'esm-health-settings','parent'=>'esm-health','title'=>'Settings','href'=>$url . '&tab=settings'));
}
add_action('admin_bar_menu', __NAMESPACE__ . '\\menu', 100);

function styles() {
    if (!current_user_can('manage_options') || !is_admin_bar_showing()) { return; }
    echo '<style id="esm-admin-bar-css">#wpadminbar .esm-light{display:inline-block;width:9px;height:9px;margin:0 6px 0 3px;border-radius:50%;vertical-align:middle;box-shadow:0 0 0 1px #ffffff40}#wpadminbar .esm-light-green{background:#7ad03a}#wpadminbar .esm-light-yellow{background:#f0c33c}#wpadminbar .esm-light-orange{background:#f39938}#wpadminbar .esm-light-red{background:#e53935}#wpadminbar .esm-light-blue{background:#72aee6}#wpadminbar .esm-light-gray{background:#a7aaad}#wpadminbar #wp-admin-bar-esm-health .ab-sub-wrapper{min-width:330px}#wpadminbar #wp-admin-bar-esm-health-checked>.ab-item{font-size:11px;color:#c3c4c7}</style>';
}
add_action('wp_head', __NAMESPACE__ . '\\styles');
add_action('admin_head', __NAMESPACE__ . '\\styles');
