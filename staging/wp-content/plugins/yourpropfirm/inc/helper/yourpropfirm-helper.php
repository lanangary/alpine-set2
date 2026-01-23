<?php
/**
 * Plugin functions and definitions for Helper.
 *
 * For additional information on potential customization options,
 * read the developers' documentation:
 *
 * @package yourpropfirm
 */
function yourpropfirm_connection_response_logger() {
    $logger = wc_get_logger();
    $context = array('source' => 'yourpropfirm_connection_response_log');
    return array('logger' => $logger, 'context' => $context);
}

function yourpropfirm_connection_mask_api_key($api_key) {
    $key_length = strlen($api_key);
    if ($key_length <= 8) {
        return str_repeat('*', $key_length); // If the key is too short to mask in the desired way, mask the whole key
    }
    $start = substr($api_key, 0, 4);
    $end = substr($api_key, -4);
    $masked = str_repeat('*', $key_length - 8); // Number of asterisks to add in the middle
    return $start . $masked . $end;
}

// Hook for adding admin scripts
add_action('admin_enqueue_scripts', 'yourpropfirm_enqueue_admin_assets');
// Function to enqueue admin scripts and styles
function yourpropfirm_enqueue_admin_assets() {
    // Enqueue CSS file
    wp_enqueue_style('yourpropfirm-admin-css', plugin_dir_url(__FILE__) . '../../assets/css/yourpropfirm-admin.css');
    // Enqueue JS file
    wp_enqueue_script('yourpropfirm-admin-js', plugin_dir_url(__FILE__) . '../../assets/js/yourpropfirm-admin.js', array('jquery'), null, true);
}