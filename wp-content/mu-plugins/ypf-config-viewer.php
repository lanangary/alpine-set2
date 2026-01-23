<?php
/**
 * YPF Config Viewer
 * Visit: https://alpine.test/ypf-config to see current settings
 */

add_action('init', 'ypf_config_viewer_endpoint');
function ypf_config_viewer_endpoint() {
    add_rewrite_rule('^ypf-config/?$', 'index.php?ypf_config=1', 'top');
}

add_filter('query_vars', 'ypf_config_query_vars');
function ypf_config_query_vars($vars) {
    $vars[] = 'ypf_config';
    return $vars;
}

add_action('template_redirect', 'ypf_config_display');
function ypf_config_display() {
    global $wp_query;
    
    if (!isset($wp_query->query_vars['ypf_config'])) {
        return;
    }
    
    status_header(200);
    header('Content-Type: application/json');
    
    $config = [
        'environment' => get_option('yourpropfirm_connection_environment'),
        'live' => [
            'endpoint_url' => get_option('yourpropfirm_connection_endpoint_url'),
            'api_key' => substr(get_option('yourpropfirm_connection_api_key'), 0, 20) . '...',
        ],
        'sandbox' => [
            'endpoint_url' => get_option('yourpropfirm_connection_sandbox_endpoint_url'),
            'test_key' => substr(get_option('yourpropfirm_connection_sandbox_test_key'), 0, 20) . '...',
        ],
        'other_settings' => [
            'plugin_enabled' => get_option('yourpropfirm_connection_enable'),
            'challenge_enabled' => get_option('yourpropfirm_connection_challenge_enabled'),
            'competition_enabled' => get_option('yourpropfirm_connection_competition_enabled'),
        ]
    ];
    
    echo json_encode($config, JSON_PRETTY_PRINT);
    exit;
}
