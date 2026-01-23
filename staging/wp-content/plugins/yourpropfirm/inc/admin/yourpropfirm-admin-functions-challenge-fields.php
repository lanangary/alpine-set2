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
add_action('admin_init', 'yourpropfirm_connection_challenge_settings_fields');

// Register and define the settings
function yourpropfirm_connection_challenge_settings_fields() { 
    register_setting('yourpropfirm_connection_challenge_settings', 'yourpropfirm_connection_challenge_enabled', array('sanitize_callback' => 'sanitize_text_field', 'default' => 'disable'));
    register_setting('yourpropfirm_connection_challenge_settings', 'yourpropfirm_connection_mt_version_type_show_message', array('sanitize_callback' => 'sanitize_text_field', 'default' => 'disable'));
    register_setting('yourpropfirm_connection_challenge_settings', 'yourpropfirm_connection_mt_version_type_message', array('sanitize_callback' => 'sanitize_textarea_field', 'default' => ''));

    add_settings_section('yourpropfirm_connection_challenge_settings', 'General Settings', 'yourpropfirm_connection_challenge_section_callback', 'yourpropfirm_connection_challenge_settings');
    add_settings_field('yourpropfirm_connection_challenge_enabled', 'Enable Challenge', 'yourpropfirm_connection_challenge_enabled_callback', 'yourpropfirm_connection_challenge_settings', 'yourpropfirm_connection_challenge_settings');  
    add_settings_field('yourpropfirm_connection_mt_version_type_show_message', 'Enable Message Popup', 'yourpropfirm_connection_mt_version_type_show_message_callback', 'yourpropfirm_connection_challenge_settings', 'yourpropfirm_connection_challenge_settings'); 
    add_settings_field('yourpropfirm_connection_mt_version_type_message', 'Popup Message', 'yourpropfirm_connection_mt_version_type_message_callback', 'yourpropfirm_connection_challenge_settings', 'yourpropfirm_connection_challenge_settings'); 
}

// Render section callback
function yourpropfirm_connection_challenge_section_callback() {
    echo '<p>Configure The Challenge Settings for YourPropFirm plugin.</p>';
}

// Render enable plugin field
function yourpropfirm_connection_challenge_enabled_callback() {
    $option = get_option('yourpropfirm_connection_challenge_enabled');
    echo '<select name="yourpropfirm_connection_challenge_enabled">';
    echo '<option value="enable"' . selected($option, 'enable', false) . '>Enable</option>';
    echo '<option value="disable"' . selected($option, 'disable', false) . '>Disable</option>';
    echo '</select>';
}

// Render enable Show Message Popup
function yourpropfirm_connection_mt_version_type_show_message_callback() {
    $option = get_option('yourpropfirm_connection_mt_version_type_show_message');
    echo '<select name="yourpropfirm_connection_mt_version_type_show_message">';
    echo '<option value="enable"' . selected($option, 'enable', false) . '>Enable</option>';
    echo '<option value="disable"' . selected($option, 'disable', false) . '>Disable</option>';
    echo '</select>';
}

// Render Popup Message Field (Text Area)
function yourpropfirm_connection_mt_version_type_message_callback() {
    $option = get_option('yourpropfirm_connection_mt_version_type_message');
    echo '<textarea name="yourpropfirm_connection_mt_version_type_message" rows="5" cols="50">' . esc_textarea($option) . '</textarea>';
}
