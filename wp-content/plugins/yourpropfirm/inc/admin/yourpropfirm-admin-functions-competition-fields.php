<?php
/**
 * Plugin functions and definitions for Admin.
 *
 * For additional information on potential customization options,
 * read the developers' documentation:
 *
 * @package yourpropfirm
 */

// Hook for adding admin settings
add_action('admin_init', 'yourpropfirm_connection_competition_settings_fields');

// Register and define the settings
function yourpropfirm_connection_competition_settings_fields() { 
    register_setting('yourpropfirm_connection_competition_settings', 'yourpropfirm_connection_competition_enabled', array('sanitize_callback' => 'sanitize_text_field', 'default' => 'disable'));
    add_settings_section('yourpropfirm_connection_competition_settings', 'General Settings', 'yourpropfirm_connection_competition_section_callback', 'yourpropfirm_connection_competition_settings');
    add_settings_field('yourpropfirm_connection_competition_enabled', 'Enable Competition', 'yourpropfirm_connection_competition_enabled_callback', 'yourpropfirm_connection_competition_settings', 'yourpropfirm_connection_competition_settings');  
}

// Render section callback
function yourpropfirm_connection_competition_section_callback() {
    echo '<p>Configure The Competition Settings for YourPropFirm plugin.</p>';
}

// Render enable plugin field
function yourpropfirm_connection_competition_enabled_callback() {
    $option = get_option('yourpropfirm_connection_competition_enabled');
    echo '<select name="yourpropfirm_connection_competition_enabled">';
    echo '<option value="enable"' . selected($option, 'enable', false) . '>Enable</option>';
    echo '<option value="disable"' . selected($option, 'disable', false) . '>Disable</option>';
    echo '</select>';
}
