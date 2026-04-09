<?php
/**
 * Plugin Name: YPF Subscription Account Details
 * Description: Display YPF account details on view-subscription page
 * Version: 1.0.0
 * Author: Alpine Funded Dev Team
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get API config with request-scoped cache (avoids repeated get_option calls).
 *
 * @return array{api_base_url:string,api_key:string}|null
 */
function ypf_get_api_config() {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $plugin_env = get_option('yourpropfirm_connection_environment');
    if ($plugin_env === 'sandbox') {
        $api_base_url = esc_attr(get_option('yourpropfirm_connection_sandbox_endpoint_url'));
        $api_key = esc_attr(get_option('yourpropfirm_connection_sandbox_test_key'));
    } else {
        $api_base_url = esc_attr(get_option('yourpropfirm_connection_endpoint_url'));
        $api_key = esc_attr(get_option('yourpropfirm_connection_api_key'));
    }
    if (empty($api_base_url) || empty($api_key)) {
        $cache = ['api_base_url' => '', 'api_key' => ''];
        return $cache;
    }
    $cache = ['api_base_url' => $api_base_url, 'api_key' => $api_key];
    return $cache;
}

/**
 * Request-scoped cache for ypf_get_user_accounts (avoids duplicate API calls in same request).
 */
function ypf_get_user_accounts_cached($api_base_url, $api_key, $user_id) {
    static $cache = [];
    $key = $api_base_url . '|' . $user_id;
    if (!isset($cache[$key])) {
        $cache[$key] = function_exists('ypf_get_user_accounts')
            ? ypf_get_user_accounts($api_base_url, $api_key, $user_id)
            : ['status_code' => 500, 'response' => []];
    }
    return $cache[$key];
}

/**
 * Request-scoped cache for ypf_get_account_details.
 */
function ypf_get_account_details_cached($api_base_url, $api_key, $user_id, $account_id) {
    static $cache = [];
    $key = $api_base_url . '|' . $user_id . '|' . $account_id;
    if (!isset($cache[$key])) {
        $cache[$key] = function_exists('ypf_get_account_details')
            ? ypf_get_account_details($api_base_url, $api_key, $user_id, $account_id)
            : ['status_code' => 500, 'response' => []];
    }
    return $cache[$key];
}

/**
 * Get YPF user ID from subscription meta (subscription + parent order). Lightweight, no API.
 *
 * @param WC_Subscription $subscription
 * @return string
 */
function ypf_get_subscription_ypf_user_id($subscription) {
    if (!$subscription || !is_a($subscription, 'WC_Subscription')) {
        return '';
    }
    $ypf_user_id = $subscription->get_meta('_ypf_user_id');
    if (empty($ypf_user_id)) {
        $ypf_user_id = $subscription->get_meta('ypf_user_id');
    }
    if (empty($ypf_user_id)) {
        $ypf_user_id = $subscription->get_meta('_yourpropfirm_user_id');
    }
    if (empty($ypf_user_id)) {
        $parent_order_id = $subscription->get_parent_id();
        if ($parent_order_id) {
            $parent_order = wc_get_order($parent_order_id);
            if ($parent_order) {
                $ypf_user_id = $parent_order->get_meta('_ypf_user_id');
                if (empty($ypf_user_id)) {
                    $ypf_user_id = $parent_order->get_meta('ypf_user_id');
                }
                if (empty($ypf_user_id)) {
                    $ypf_user_id = $parent_order->get_meta('_yourpropfirm_user_id');
                }
            }
        }
    }
    return $ypf_user_id ? (string) $ypf_user_id : '';
}

/**
 * AJAX handler for drawdown reset
 */
add_action('wp_ajax_ypf_reset_drawdown', 'ypf_ajax_reset_drawdown');
function ypf_ajax_reset_drawdown() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ypf_reset_drawdown_nonce')) {
        wp_send_json_error(['message' => 'Security check failed.']);
        return;
    }
    
    // Check user permissions
    if (!current_user_can('view_order')) {
        wp_send_json_error(['message' => 'You do not have permission to perform this action.']);
        return;
    }
    
    // Get parameters
    $user_id = isset($_POST['user_id']) ? sanitize_text_field($_POST['user_id']) : '';
    $account_id = isset($_POST['account_id']) ? sanitize_text_field($_POST['account_id']) : '';
    
    if (empty($user_id) || empty($account_id)) {
        wp_send_json_error(['message' => 'Missing required parameters.']);
        return;
    }
    
    $api_config = ypf_get_api_config();
    $api_base_url = $api_config['api_base_url'] ?? '';
    $api_key = $api_config['api_key'] ?? '';
    if (empty($api_base_url) || empty($api_key)) {
        wp_send_json_error(['message' => 'API configuration not found.']);
        return;
    }
    
    // Call reset function from ypf-unsub-endpoint.php
    if (!function_exists('ypf_reset_single_account')) {
        wp_send_json_error(['message' => 'Reset function not available.']);
        return;
    }
    
    $result = ypf_reset_single_account($api_base_url, $api_key, $user_id, $account_id);
    
    if ($result['status'] === 'success') {
        wp_send_json_success([
            'message' => $result['message'] || 'Drawdown reset successfully!'
        ]);
    } else {
        wp_send_json_error([
            'message' => $result['message'] || 'Failed to reset drawdown.'
        ]);
    }
}

/**
 * Add Drawdown Reset button to subscription actions (same area as cancel button)
 * Uses wcs_view_subscription_actions - the filter used by WooCommerce Subscriptions for view-subscription actions
 */
add_filter('wcs_view_subscription_actions', 'ypf_add_drawdown_reset_button_to_actions', 10, 3);
function ypf_add_drawdown_reset_button_to_actions($actions, $subscription, $user_id = null) {
    // Check if we're on the view subscription page
    if (!function_exists('wcs_is_view_subscription_page') || !wcs_is_view_subscription_page()) {
        return $actions;
    }
    
    // Debug: Log function call
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('YPF Drawdown Reset: Filter function called');
    }
    
    if (!$subscription || !is_a($subscription, 'WC_Subscription')) {
        return $actions;
    }
    
    // Check user permission
    if (!current_user_can('view_order', $subscription->get_id())) {
        return $actions;
    }
    
    $api_config = ypf_get_api_config();
    $api_base_url = $api_config['api_base_url'] ?? '';
    $api_key = $api_config['api_key'] ?? '';
    if (empty($api_base_url) || empty($api_key)) {
        return $actions;
    }
    
    $ypf_user_id = ypf_get_subscription_ypf_user_id($subscription);
    if (empty($ypf_user_id)) {
        return $actions;
    }
    
    // Use shared matching logic (uses cached API calls)
    $matching_account = function_exists('ypf_get_subscription_matching_account') ? ypf_get_subscription_matching_account($subscription) : null;
    $account_id = ($matching_account && isset($matching_account['id'])) ? $matching_account['id'] : '';

    if (!$matching_account || empty($account_id)) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('YPF Drawdown Reset: No matching account found.');
        }
        return $actions;
    }
    
    $program_id_for_reset = isset($matching_account['programId']) ? $matching_account['programId'] : '';
    // Get drawdown reset product ID by program tier (Standard->DDR-APST-P1, Advanced->DDR-APAD-P1, Pro->DDR-APPR-P1)
    $product_id = function_exists('ypf_get_drawdown_reset_product_id_by_program')
        ? ypf_get_drawdown_reset_product_id_by_program($program_id_for_reset, $subscription)
        : 0;
    if (empty($product_id)) {
        foreach ($subscription->get_items() as $item) {
            if (method_exists($item, 'get_product_id')) {
                $product_id = $item->get_product_id();
                if ($product_id) break;
            } else {
                $product_id = $item['product_id'] ?? 0;
                if ($product_id) break;
            }
        }
    }
    
    if (!function_exists('ypf_get_account_details')) {
        return $actions;
    }
    
    $account_details_result = ypf_get_account_details_cached($api_base_url, $api_key, $ypf_user_id, $account_id);
    
    if ($account_details_result['status_code'] < 200 || $account_details_result['status_code'] >= 300) {
        return $actions;
    }
    
    $account_data = isset($account_details_result['response']['data']) ? $account_details_result['response']['data'] : $account_details_result['response'];
    
    // Get initialBalance and balance - check multiple possible locations
    $initial_balance = null;
    $current_balance = null;
    
    // Try from account_data directly
    if (isset($account_data['initialBalance']) && is_numeric($account_data['initialBalance'])) {
        $initial_balance = floatval($account_data['initialBalance']);
    }
    if (isset($account_data['balance']) && is_numeric($account_data['balance'])) {
        $current_balance = floatval($account_data['balance']);
    }
    
    // Try nested structures
    if ($initial_balance === null && isset($account_data['account']) && isset($account_data['account']['initialBalance']) && is_numeric($account_data['account']['initialBalance'])) {
        $initial_balance = floatval($account_data['account']['initialBalance']);
    }
    if ($current_balance === null && isset($account_data['account']) && isset($account_data['account']['balance']) && is_numeric($account_data['account']['balance'])) {
        $current_balance = floatval($account_data['account']['balance']);
    }
    
    // Try from matching_account (basic account info)
    if ($initial_balance === null && isset($matching_account['initialBalance']) && is_numeric($matching_account['initialBalance'])) {
        $initial_balance = floatval($matching_account['initialBalance']);
    }
    if ($current_balance === null && isset($matching_account['balance']) && is_numeric($matching_account['balance'])) {
        $current_balance = floatval($matching_account['balance']);
    }
    
    // Check programId is Alpine pass standard/advanced/pro
    $program_id = isset($matching_account['programId']) ? $matching_account['programId'] : '';
    if (empty($program_id) && isset($account_data['programId'])) {
        $program_id = $account_data['programId'];
    }
    // Get curDrawdownFromInitialBalancePercentage from API
    $cur_drawdown = function_exists('ypf_get_cur_drawdown_percentage') ? ypf_get_cur_drawdown_percentage($account_data, $matching_account) : null;
    $drawdown_percentage = null;
    if ($initial_balance !== null && $current_balance !== null && $initial_balance > 0) {
        $drawdown_percentage = (($current_balance - $initial_balance) / $initial_balance) * 100;
    }
    $show_reset_button = function_exists('ypf_show_reset_button_by_program_id') && ypf_show_reset_button_by_program_id($program_id, $cur_drawdown, $drawdown_percentage);

    // Debug logging
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('YPF Drawdown Reset Debug - Account ID: ' . $account_id . ', programId: ' . $program_id);
        error_log('YPF Drawdown Reset Debug - curDrawdown: ' . ($cur_drawdown !== null ? $cur_drawdown : 'NULL') . ', calculated: ' . ($drawdown_percentage !== null ? $drawdown_percentage . '%' : 'NULL') . ', show: ' . ($show_reset_button ? 'yes' : 'no'));
    }
    
    // Only show button if both conditions are met
    if (!$show_reset_button) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('YPF Drawdown Reset: Button not shown. Drawdown: ' . ($drawdown_percentage !== null ? $drawdown_percentage . '%' : 'N/A'));
        }
        return $actions;
    }
    
    // Add drawdown reset button to actions array (only if drawdown > -1%)
    // This will appear in the same td as cancel and renewal buttons
    // Store user_id, account_id, product_id, and threshold for JavaScript to access
    $config = function_exists('ypf_get_drawdown_reset_program_config') ? ypf_get_drawdown_reset_program_config() : array();
    $threshold = isset($config[$program_id]['threshold']) ? floatval($config[$program_id]['threshold']) : 4.5;
    $transient_key = 'ypf_drawdown_reset_' . $subscription->get_id();
    set_transient($transient_key, array(
        'user_id' => $ypf_user_id,
        'account_id' => $account_id,
        'product_id' => $product_id,
        'threshold' => $threshold
    ), 300); // 5 minutes
    
    $actions['ypf_drawdown_reset'] = array(
        'url'  => '#',
        'name' => 'Drawdown Reset',
    );
    
    return $actions;
}

/**
 * Enqueue script for drawdown reset button
 */
add_action('wp_enqueue_scripts', 'ypf_enqueue_drawdown_reset_script');
function ypf_enqueue_drawdown_reset_script() {
    // Check if WooCommerce and WooCommerce Subscriptions are active
    if (!class_exists('WooCommerce') || !function_exists('wcs_is_view_subscription_page')) {
        return;
    }
    
    // Check if we're on the view subscription page
    if (!wcs_is_view_subscription_page()) {
        return;
    }
    
    // Get subscription ID
    $subscription_id = absint(get_query_var('view-subscription'));
    if (!$subscription_id) {
        return;
    }
    
    // Get subscription to get product_id as fallback
    $subscription = wcs_get_subscription($subscription_id);
    $product_id_fallback = null;
    if ($subscription && is_a($subscription, 'WC_Subscription')) {
        foreach ($subscription->get_items() as $item) {
            if (method_exists($item, 'get_product_id')) {
                $product_id_fallback = $item->get_product_id();
                if ($product_id_fallback) {
                    break;
                }
            } else {
                $product_id_fallback = $item['product_id'] ?? 0;
                if ($product_id_fallback) {
                    break;
                }
            }
        }
    }
    
    // Get stored user_id, account_id, product_id, and threshold from transient
    // Transient only exists if drawdown condition is met (drawdown exceeds threshold)
    $transient_key = 'ypf_drawdown_reset_' . $subscription_id;
    $reset_data = get_transient($transient_key);
    
    $user_id_js = isset($reset_data['user_id']) ? $reset_data['user_id'] : '';
    $account_id_js = isset($reset_data['account_id']) ? $reset_data['account_id'] : '';
    $product_id_js = isset($reset_data['product_id']) ? $reset_data['product_id'] : $product_id_fallback;
    
    // Get drawdown threshold for JS: from transient, or compute from subscription products (4.5 or 9.5)
    $drawdown_threshold = isset($reset_data['threshold']) ? floatval($reset_data['threshold']) : null;
    if ($drawdown_threshold === null && $subscription && is_a($subscription, 'WC_Subscription') && function_exists('ypf_get_drawdown_reset_program_config')) {
        $config = ypf_get_drawdown_reset_program_config();
        $max_threshold = 4.5;
        foreach ($subscription->get_items() as $item) {
            $product = is_object($item) && method_exists($item, 'get_product') ? $item->get_product() : null;
            if ($product && is_a($product, 'WC_Product')) {
                $pid = $product->get_meta('_yourpropfirm_program_id') ?: $product->get_meta('yourpropfirm_program_id') ?: $product->get_meta('_ypf_program_id') ?: $product->get_meta('ypf_program_id');
                if (!empty($pid) && isset($config[$pid]['threshold'])) {
                    $t = floatval($config[$pid]['threshold']);
                    if ($t > $max_threshold) {
                        $max_threshold = $t;
                    }
                }
            }
        }
        $drawdown_threshold = $max_threshold;
    }
    if ($drawdown_threshold === null) {
        $drawdown_threshold = 4.5;
    }
    
    // Always enqueue script - JavaScript will check drawdown from HTML if transient doesn't exist
    wp_enqueue_script(
        'ypf-drawdown-reset',
        content_url('mu-plugins/ypf-drawdown-reset.js'),
        array('jquery'),
        '1.0.0',
        true
    );
    
    // Build checkout URL
    $checkout_url = '';
    if (!empty($product_id_js)) {
        $checkout_url = home_url('/checkout/?add-to-cart=' . $product_id_js);
    }
    
    // Localize script with data
    wp_localize_script('ypf-drawdown-reset', 'ypfDrawdownReset', array(
        'userId' => $user_id_js,
        'accountId' => $account_id_js,
        'productId' => $product_id_js,
        'checkoutUrl' => $checkout_url,
        'drawdownThreshold' => $drawdown_threshold,
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ypf_reset_drawdown_nonce')
    ));
}

/**
 * Get WooCommerce product by YPF program ID (from product meta).
 * When multiple products share the same programId (e.g. Standard and Standard-Upgraded),
 * use $prefer_upgraded to return the product with "upgraded" in the name.
 *
 * @param string $program_id YPF program ID
 * @param bool $prefer_upgraded When true, prefer product with "upgraded" in name (e.g. Active account from Upgraded parent)
 * @return WC_Product|null Product or null if not found
 */
function ypf_get_wc_product_by_program_id($program_id, $prefer_upgraded = false) {
    if (empty($program_id) || !function_exists('wc_get_product')) {
        return null;
    }
    $meta_keys = array('_yourpropfirm_program_id', 'yourpropfirm_program_id', '_ypf_program_id', 'ypf_program_id');
    foreach ($meta_keys as $meta_key) {
        $args = array(
            'post_type'      => 'product',
            'posts_per_page' => $prefer_upgraded ? -1 : 1,
            'post_status'    => 'publish',
            'meta_query'     => array(
                array('key' => $meta_key, 'value' => $program_id, 'compare' => '='),
            ),
            'fields'         => 'ids',
        );
        $posts = get_posts($args);
        if (empty($posts)) {
            continue;
        }
        if ($prefer_upgraded && count($posts) > 0) {
            $fallback = null;
            foreach ($posts as $post_id) {
                $product = wc_get_product($post_id);
                if (!$product || !is_a($product, 'WC_Product')) {
                    continue;
                }
                if (!$fallback) {
                    $fallback = $product;
                }
                $name = strtolower($product->get_name());
                if (strpos($name, 'upgraded') !== false) {
                    return $product;
                }
            }
            return $fallback;
        }
        $product = wc_get_product($posts[0]);
        return $product ? $product : null;
    }
    return null;
}

/**
 * Get drawdown reset program config: programId => [ 'threshold' => float, 'sku' => string ].
 * Group 1 (4.5%): Standard/Advanced/Pro.
 * Group 2 (9.5%): Standard-Upgraded/Advanced-Upgraded/Pro-Upgraded.
 *
 * @return array
 */
function ypf_get_drawdown_reset_program_config() {
    return apply_filters('ypf_drawdown_reset_program_config', array(
        '6985f4024d05c7e5eb3ebf14' => array('threshold' => 4.5, 'sku' => 'DDR-APST-P1'),
        '6986e8cdb421465b83d59f16' => array('threshold' => 4.5, 'sku' => 'DDR-APAD-P1'),
        '6986e8f16172d3ecc5d6f791' => array('threshold' => 4.5, 'sku' => 'DDR-APPR-P1'),
        '69a7130b14773bf7991aec20' => array('threshold' => 9.5, 'sku' => 'DDR-APST-P2'),
        '69a713259a00cce57f59841b' => array('threshold' => 9.5, 'sku' => 'DDR-APAD-P2'),
        '69a71338997ab78db55c8ace' => array('threshold' => 9.5, 'sku' => 'DDR-APPR-P2'),
    ));
}

/**
 * Check if reset button should show by programId and curDrawdownFromInitialBalancePercentage.
 *
 * @param string $program_id YPF program ID
 * @param float|null $cur_drawdown curDrawdownFromInitialBalancePercentage from API
 * @param float|null $calculated_drawdown Fallback calculated drawdown
 * @return bool
 */
function ypf_show_reset_button_by_program_id($program_id, $cur_drawdown, $calculated_drawdown = null) {
    $config = ypf_get_drawdown_reset_program_config();
    if (empty($program_id) || !isset($config[$program_id])) {
        return false;
    }
    $threshold = floatval($config[$program_id]['threshold']);
    if ($cur_drawdown !== null && $cur_drawdown !== '' && is_numeric($cur_drawdown)) {
        $v = floatval($cur_drawdown);
        return ($v > $threshold) || ($v < -$threshold);
    }
    if ($calculated_drawdown !== null && is_numeric($calculated_drawdown)) {
        return floatval($calculated_drawdown) < -$threshold;
    }
    return false;
}

/**
 * Get drawdown reset product ID by programId (dynamic checkout via SKU).
 *
 * @param string $program_id YPF program ID
 * @return int Product ID or 0 if not found
 */
function ypf_get_drawdown_reset_product_id_by_program($program_id, $subscription = null) {
    $config = ypf_get_drawdown_reset_program_config();
    if (empty($program_id) || !isset($config[$program_id]) || empty($config[$program_id]['sku'])) {
        return 0;
    }
    $sku = $config[$program_id]['sku'];
    if (!function_exists('wc_get_product_id_by_sku')) {
        return 0;
    }
    $product_id = wc_get_product_id_by_sku($sku);
    return $product_id ? absint($product_id) : 0;
}

/**
 * Check if product name contains "Upgraded" (e.g. Alpine Pass Standard - Upgraded).
 * Uses WooCommerce product name, not account state.
 *
 * @param string $program_id YPF program ID
 * @param WC_Subscription|null $subscription Optional subscription to get product from items
 * @return bool
 */
function ypf_is_alpine_pass_upgraded_product($program_id, $subscription = null) {
    if (empty($program_id)) {
        return false;
    }
    // Use prefer_upgraded=true so we get the Upgraded product when API programId matches Standard-Upgraded
    $product = function_exists('ypf_get_wc_product_by_program_id') ? ypf_get_wc_product_by_program_id($program_id, true) : null;
    if ($product && is_a($product, 'WC_Product')) {
        $name = strtolower($product->get_name());
        return strpos($name, 'upgraded') !== false;
    }
    if ($subscription && is_a($subscription, 'WC_Subscription')) {
        foreach ($subscription->get_items() as $item) {
            $product = is_object($item) && method_exists($item, 'get_product') ? $item->get_product() : null;
            if (!$product || !is_a($product, 'WC_Product')) {
                continue;
            }
            $pid = $product->get_meta('_yourpropfirm_program_id') ?: $product->get_meta('yourpropfirm_program_id') ?: $product->get_meta('_ypf_program_id') ?: $product->get_meta('ypf_program_id');
            if (!empty($pid) && $pid === $program_id) {
                $name = strtolower($product->get_name());
                return strpos($name, 'upgraded') !== false;
            }
        }
    }
    return false;
}

/**
 * Check if programId belongs to Alpine pass standard, advanced, or pro.
 *
 * @param string $program_id YPF program ID
 * @param WC_Subscription|null $subscription Optional subscription to get program IDs from subscription items
 * @return bool
 */
function ypf_is_alpine_pass_program($program_id, $subscription = null) {
    if (empty($program_id)) {
        return false;
    }
    $allowed = apply_filters('ypf_alpine_pass_reset_program_ids', array());
    if (!empty($allowed) && is_array($allowed)) {
        return in_array($program_id, $allowed);
    }
    if ($subscription && is_a($subscription, 'WC_Subscription')) {
        foreach ($subscription->get_items() as $item) {
            $product = is_object($item) && method_exists($item, 'get_product') ? $item->get_product() : null;
            if (!$product || !is_a($product, 'WC_Product')) {
                continue;
            }
            $name = strtolower($product->get_name());
            $has_tier = (strpos($name, 'standard') !== false || strpos($name, 'advanced') !== false || strpos($name, 'pro') !== false);
            $is_alpine = (strpos($name, 'alpine') !== false);
            if ($has_tier && $is_alpine) {
                $pid = $product->get_meta('_yourpropfirm_program_id') ?: $product->get_meta('yourpropfirm_program_id') ?: $product->get_meta('_ypf_program_id') ?: $product->get_meta('ypf_program_id');
                if (!empty($pid) && $pid === $program_id) {
                    return true;
                }
            }
        }
    }
    $product = ypf_get_wc_product_by_program_id($program_id);
    if ($product) {
        $name = strtolower($product->get_name());
        $has_tier = (strpos($name, 'standard') !== false || strpos($name, 'advanced') !== false || strpos($name, 'pro') !== false);
        $is_alpine = (strpos($name, 'alpine') !== false);
        return $has_tier && $is_alpine;
    }
    return false;
}

/**
 * Recursively search for a key in nested array (case-insensitive key match).
 *
 * @param array  $arr Array to search
 * @param string $key Key to find (e.g. curDrawdownFromInitialBalancePercentage)
 * @return mixed Found value or null
 */
function ypf_array_find_key_recursive($arr, $key) {
    if (!is_array($arr)) {
        return null;
    }
    $key_lower = strtolower($key);
    foreach ($arr as $k => $v) {
        if (strtolower((string) $k) === $key_lower) {
            return $v;
        }
        if (is_array($v)) {
            $found = ypf_array_find_key_recursive($v, $key);
            if ($found !== null) {
                return $found;
            }
        }
    }
    return null;
}

/**
 * Get curDrawdownFromInitialBalancePercentage from account data (API response).
 * Returns null if not found. Handles both positive (5.2 = 5.2% drawdown) and negative (-5.2) conventions.
 *
 * @param array $account_data Account details from API
 * @param array $account Basic account info (optional)
 * @return float|null
 */
function ypf_get_cur_drawdown_percentage($account_data, $account = array()) {
    $keys = array('curDrawdownFromInitialBalancePercentage', 'curDrawdownFromInitialBalance');
    foreach ($keys as $key) {
        $val = ypf_array_find_key_recursive($account_data, $key);
        if ($val !== null && $val !== '' && is_numeric($val)) {
            return floatval($val);
        }
        if (!empty($account)) {
            $val = isset($account[$key]) ? $account[$key] : null;
            if ($val !== null && $val !== '' && is_numeric($val)) {
                return floatval($val);
            }
        }
    }
    return null;
}

/**
 * Check if drawdown exceeds 4.5% (for reset button eligibility).
 * Prioritizes curDrawdownFromInitialBalancePercentage from API when available.
 * Only falls back to calculated when API value is not present.
 *
 * @param float|null $cur_drawdown API curDrawdownFromInitialBalancePercentage (e.g. 1.6 = 1.6%, 5.2 = 5.2%)
 * @param float|null $calculated_drawdown Calculated from (balance - initialBalance) / initialBalance * 100 (negative = loss)
 * @param float $threshold Threshold in percent (default 4.5; use 9.5 for Alpine Pass Upgraded accounts)
 * @return bool
 */
function ypf_drawdown_exceeds_threshold($cur_drawdown, $calculated_drawdown = null, $threshold = 4.5) {
    // API value takes precedence - use it when available
    if ($cur_drawdown !== null && $cur_drawdown !== '' && is_numeric($cur_drawdown)) {
        $v = floatval($cur_drawdown);
        return ($v > $threshold) || ($v < -$threshold);
    }
    // Fallback: only when API does not return curDrawdownFromInitialBalancePercentage
    if ($calculated_drawdown !== null && is_numeric($calculated_drawdown)) {
        return floatval($calculated_drawdown) < -$threshold;
    }
    return false;
}

/**
 * When the displayed account is the new Active account (replacing Upgraded), sync subscription:
 * - Update _ypf_account_id to the new account ID
 * - If the new account has a different program, update subscription line items and total to the new product.
 *
 * @param WC_Subscription $subscription Subscription object
 * @param array          $display_account Account we are displaying (e.g. Active account)
 * @param array          $subscription_account_ids Stored account IDs on subscription/orders
 */
function ypf_sync_subscription_to_active_account($subscription, $display_account, $subscription_account_ids) {
    if (!$subscription || !is_a($subscription, 'WC_Subscription')) {
        return;
    }
    $new_account_id = isset($display_account['id']) ? $display_account['id'] : '';
    if (empty($new_account_id)) {
        return;
    }
    // Update subscription meta to the new active account ID
    $subscription->update_meta_data('_ypf_account_id', $new_account_id);

    $program_id = isset($display_account['programId']) ? $display_account['programId'] : '';
    if (empty($program_id) || !function_exists('ypf_get_wc_product_by_program_id')) {
        $subscription->save();
        return;
    }
    // Prefer product with "Upgraded" in name when syncing to Active account from Upgraded parent
    $new_product = ypf_get_wc_product_by_program_id($program_id, true);
    if (!$new_product || !is_a($new_product, 'WC_Product')) {
        $subscription->save();
        return;
    }
    $new_product_id = $new_product->get_id();
    $current_product_ids = array();
    foreach ($subscription->get_items() as $item) {
        $pid = method_exists($item, 'get_product_id') ? $item->get_product_id() : (isset($item['product_id']) ? $item['product_id'] : 0);
        $vid = method_exists($item, 'get_variation_id') ? $item->get_variation_id() : (isset($item['variation_id']) ? $item['variation_id'] : 0);
        if ($pid) {
            $current_product_ids[] = $pid;
        }
        if ($vid) {
            $current_product_ids[] = $vid;
        }
    }
    $current_product_ids = array_unique($current_product_ids);
    $already_has_new_product = in_array($new_product_id, $current_product_ids);
    if ($already_has_new_product) {
        $subscription->save();
        return;
    }
    // Replace line items with the new product and recalculate totals
    $items_to_remove = array();
    foreach ($subscription->get_items() as $item) {
        $items_to_remove[] = $item;
    }
    foreach ($items_to_remove as $item) {
        $subscription->remove_item($item->get_id());
    }
    $subscription->add_product($new_product, 1);
    $subscription->calculate_totals();
    $subscription->save();
}

/**
 * Run sync when view-subscription page loads, before the subscription details and totals tables,
 * so product and total reflect the new active account (e.g. Standard-Upgraded when API programId matches).
 */
add_action('woocommerce_subscription_details_table', 'ypf_maybe_sync_subscription_to_active_account_on_view', 1, 1);
add_action('woocommerce_subscription_totals_table', 'ypf_maybe_sync_subscription_to_active_account_on_view', 0, 1);
function ypf_maybe_sync_subscription_to_active_account_on_view($subscription) {
    if (!function_exists('wcs_is_view_subscription_page') || !wcs_is_view_subscription_page()) {
        return;
    }
    if (!$subscription || !is_a($subscription, 'WC_Subscription')) {
        return;
    }
    if (!current_user_can('view_order', $subscription->get_id())) {
        return;
    }
    // Prevent running twice (hooked to both details_table and totals_table)
    static $synced_ids = [];
    $sub_id = $subscription->get_id();
    if (isset($synced_ids[$sub_id])) {
        return;
    }
    $synced_ids[$sub_id] = true;

    $subscription_account_ids = array();
    $sub_account_id = $subscription->get_meta('_ypf_account_id');
    if (empty($sub_account_id)) {
        $sub_account_id = $subscription->get_meta('ypf_account_id');
    }
    if (empty($sub_account_id)) {
        $sub_account_id = $subscription->get_meta('_yourpropfirm_account_id');
    }
    if (empty($sub_account_id)) {
        $sub_account_id = $subscription->get_meta('yourpropfirm_account_id');
    }
    if (!empty($sub_account_id)) {
        $subscription_account_ids[] = $sub_account_id;
    }
    $parent_order_id = $subscription->get_parent_id();
    if ($parent_order_id) {
        $parent_order = wc_get_order($parent_order_id);
        if ($parent_order) {
            $order_account_id = $parent_order->get_meta('_ypf_account_id');
            if (empty($order_account_id)) {
                $order_account_id = $parent_order->get_meta('ypf_account_id');
            }
            if (empty($order_account_id)) {
                $order_account_id = $parent_order->get_meta('_yourpropfirm_account_id');
            }
            if (!empty($order_account_id)) {
                $subscription_account_ids[] = $order_account_id;
            }
        }
    }
    $subscription_account_ids = array_unique($subscription_account_ids);
    $matching_account = function_exists('ypf_get_subscription_matching_account') ? ypf_get_subscription_matching_account($subscription) : null;
    if (!$matching_account || empty($matching_account['id'])) {
        return;
    }
    // Always sync: update account meta and ensure product matches API programId (e.g. Standard-Upgraded)
    ypf_sync_subscription_to_active_account($subscription, $matching_account, $subscription_account_ids);
}

/**
 * Display account details on view-subscription page
 */
/**
 * Update ypfDrawdownReset with dynamic checkout URL from transient (set after subscription table renders).
 * Transient contains correct DDR product_id from ypf_get_drawdown_reset_product_id_by_program.
 */
add_action('woocommerce_subscription_details_after_subscription_table', 'ypf_update_drawdown_reset_checkout_url_for_js', 5, 1);
function ypf_update_drawdown_reset_checkout_url_for_js($subscription) {
    if (!$subscription || !is_a($subscription, 'WC_Subscription')) {
        return;
    }
    $subscription_id = $subscription->get_id();
    $transient_key = 'ypf_drawdown_reset_' . $subscription_id;
    $reset_data = get_transient($transient_key);
    if (empty($reset_data['product_id'])) {
        return;
    }
    $checkout_url = home_url('/checkout/?add-to-cart=' . $reset_data['product_id']);
    $inline = 'if(typeof ypfDrawdownReset!=="undefined"){ypfDrawdownReset.checkoutUrl=' . json_encode($checkout_url) . ';ypfDrawdownReset.productId=' . json_encode($reset_data['product_id']) . ';}';
    wp_add_inline_script('ypf-drawdown-reset', $inline, 'before');
}

add_action('woocommerce_subscription_details_after_subscription_table', 'ypf_display_account_details_on_subscription_page', 20);
function ypf_display_account_details_on_subscription_page($subscription) {
    // Check if we're on the view subscription page
    if (!function_exists('wcs_is_view_subscription_page') || !wcs_is_view_subscription_page()) {
        return;
    }
    
    // Get subscription ID from query var if not provided
    if (!$subscription || !is_a($subscription, 'WC_Subscription')) {
        $subscription_id = absint(get_query_var('view-subscription'));
        if (!$subscription_id) {
            return;
        }
        $subscription = wcs_get_subscription($subscription_id);
        if (!$subscription || !is_a($subscription, 'WC_Subscription')) {
            return;
        }
    }
    
    // Ensure we use the correct subscription ID
    $current_subscription_id = $subscription->get_id();
    
    // Check user permission
    if (!current_user_can('view_order', $subscription->get_id())) {
        return;
    }
    
    $api_config = ypf_get_api_config();
    $api_base_url = $api_config['api_base_url'] ?? '';
    $api_key = $api_config['api_key'] ?? '';
    if (empty($api_base_url) || empty($api_key)) {
        return;
    }

    $ypf_user_id = ypf_get_subscription_ypf_user_id($subscription);

    if (empty($ypf_user_id)) {
        $email = $subscription->get_billing_email();
        if (!empty($email)) {
            // Use function from ypf-unsub-endpoint.php
            if (function_exists('ypf_unsub_get_user_by_email')) {
                $user_data = ypf_unsub_get_user_by_email($api_base_url, $api_key, $email);
                if ($user_data['status_code'] === 200 && isset($user_data['user_id'])) {
                    $ypf_user_id = $user_data['user_id'];
                }
            }
        }
    }
    
    // If no user_id found, don't display anything
    if (empty($ypf_user_id)) {
        return;
    }
    
    if (!function_exists('ypf_get_user_accounts')) {
        return;
    }

    $accounts_result = ypf_get_user_accounts_cached($api_base_url, $api_key, $ypf_user_id);
    
    if ($accounts_result['status_code'] !== 200) {
        return;
    }
    
    // Use raw accounts if available, otherwise use mapped accounts
    $accounts = [];
    if (isset($accounts_result['response']['raw']) && is_array($accounts_result['response']['raw'])) {
        // Use raw data to get all fields
        $raw_accounts = $accounts_result['response']['raw'];
        if (isset($raw_accounts['results']) && is_array($raw_accounts['results'])) {
            $accounts = $raw_accounts['results'];
        } elseif (is_array($raw_accounts) && !isset($raw_accounts['status'])) {
            // Direct array
            $accounts = $raw_accounts;
        }
    }
    
    // Fallback to mapped accounts if raw is not available
    if (empty($accounts) && !empty($accounts_result['response']['accounts'])) {
        $accounts = $accounts_result['response']['accounts'];
    }
    
    if (empty($accounts)) {
        return;
    }
    
    // Get subscription product IDs for matching
    $subscription_product_ids = [];
    foreach ($subscription->get_items() as $item) {
        if (method_exists($item, 'get_product_id')) {
            $product_id = $item->get_product_id();
            if ($product_id) {
                $subscription_product_ids[] = $product_id;
            }
        } else {
            // Fallback for WC_Order_Item
            $product_id = $item['product_id'] ?? 0;
            if ($product_id) {
                $subscription_product_ids[] = $product_id;
            }
        }
        
        // Also check variation ID
        if (method_exists($item, 'get_variation_id')) {
            $variation_id = $item->get_variation_id();
            if ($variation_id) {
                $subscription_product_ids[] = $variation_id;
            }
        } else {
            $variation_id = $item['variation_id'] ?? 0;
            if ($variation_id) {
                $subscription_product_ids[] = $variation_id;
            }
        }
    }
    $subscription_product_ids = array_unique($subscription_product_ids);
    
    // Get program IDs from product meta
    $subscription_program_ids = [];
    foreach ($subscription_product_ids as $product_id) {
        $product = wc_get_product($product_id);
        if ($product) {
            $program_id = $product->get_meta('_yourpropfirm_program_id');
            if (empty($program_id)) {
                $program_id = $product->get_meta('yourpropfirm_program_id');
            }
            if (empty($program_id)) {
                $program_id = $product->get_meta('_ypf_program_id');
            }
            if (empty($program_id)) {
                $program_id = $product->get_meta('ypf_program_id');
            }
            if (!empty($program_id)) {
                $subscription_program_ids[] = $program_id;
            }
        }
    }
    $subscription_program_ids = array_unique($subscription_program_ids);
    
    // Get subscription created date for matching based on time
    $subscription_created_date = $subscription->get_date_created();
    $subscription_timestamp = $subscription_created_date ? $subscription_created_date->getTimestamp() : 0;
    
    // Get related order IDs to filter accounts
    $parent_order_id = $subscription->get_parent_id();
    $related_order_ids = [];
    if ($parent_order_id) {
        $related_order_ids[] = $parent_order_id;
    }
    $renewal_orders = $subscription->get_related_orders('ids');
    if (!empty($renewal_orders)) {
        $related_order_ids = array_merge($related_order_ids, $renewal_orders);
    }
    $related_order_ids = array_unique($related_order_ids);
    
    // Check if account_id is stored in subscription or order meta
    $subscription_account_ids = [];
    
    // Check in subscription meta - check all possible meta keys
    $sub_account_id = $subscription->get_meta('_ypf_account_id');
    if (empty($sub_account_id)) {
        $sub_account_id = $subscription->get_meta('ypf_account_id');
    }
    if (empty($sub_account_id)) {
        $sub_account_id = $subscription->get_meta('_yourpropfirm_account_id');
    }
    if (empty($sub_account_id)) {
        $sub_account_id = $subscription->get_meta('yourpropfirm_account_id');
    }
    if (empty($sub_account_id)) {
        // Check all meta to find account_id
        $all_meta = $subscription->get_meta_data();
        foreach ($all_meta as $meta) {
            $key = $meta->key;
            $value = $meta->value;
            if (is_string($key) && (
                stripos($key, 'account') !== false || 
                stripos($key, 'ypf') !== false ||
                stripos($key, 'yourpropfirm') !== false
            ) && !empty($value) && is_string($value) && strlen($value) > 10) {
                // This is likely account_id
                $sub_account_id = $value;
                break;
            }
        }
    }
    if (!empty($sub_account_id)) {
        $subscription_account_ids[] = $sub_account_id;
    }
    
    // Check in parent order meta - check all possible meta keys
    if ($parent_order_id) {
        $parent_order = wc_get_order($parent_order_id);
        if ($parent_order) {
            $order_account_id = $parent_order->get_meta('_ypf_account_id');
            if (empty($order_account_id)) {
                $order_account_id = $parent_order->get_meta('ypf_account_id');
            }
            if (empty($order_account_id)) {
                $order_account_id = $parent_order->get_meta('_yourpropfirm_account_id');
            }
            if (empty($order_account_id)) {
                $order_account_id = $parent_order->get_meta('yourpropfirm_account_id');
            }
            if (empty($order_account_id)) {
                // Check all meta to find account_id
                $all_meta = $parent_order->get_meta_data();
                foreach ($all_meta as $meta) {
                    $key = $meta->key;
                    $value = $meta->value;
                    if (is_string($key) && (
                        stripos($key, 'account') !== false || 
                        stripos($key, 'ypf') !== false ||
                        stripos($key, 'yourpropfirm') !== false
                    ) && !empty($value) && is_string($value) && strlen($value) > 10) {
                        // This is likely account_id
                        $order_account_id = $value;
                        break;
                    }
                }
            }
            if (!empty($order_account_id)) {
                $subscription_account_ids[] = $order_account_id;
            }
        }
    }
    
    // Check in all renewal orders meta
    if (!empty($renewal_orders)) {
        foreach ($renewal_orders as $renewal_order_id) {
            $renewal_order = wc_get_order($renewal_order_id);
            if ($renewal_order) {
                $renewal_account_id = $renewal_order->get_meta('_ypf_account_id');
                if (empty($renewal_account_id)) {
                    $renewal_account_id = $renewal_order->get_meta('ypf_account_id');
                }
                if (empty($renewal_account_id)) {
                    $renewal_account_id = $renewal_order->get_meta('_yourpropfirm_account_id');
                }
                if (!empty($renewal_account_id)) {
                    $subscription_account_ids[] = $renewal_account_id;
                }
            }
        }
    }
    
    // Remove duplicates
    $subscription_account_ids = array_unique($subscription_account_ids);
    
    // Filter accounts that match subscription
    $matching_accounts = [];
    
    // Method 1: Match by account_id from meta (highest priority)
    if (!empty($subscription_account_ids)) {
        foreach ($accounts as $account) {
            $account_id = isset($account['id']) ? $account['id'] : '';
            if (!empty($account_id) && in_array($account_id, $subscription_account_ids)) {
                $matching_accounts[] = $account;
            }
        }
    }
    
    // Method 2: Match by invoiceId with order IDs (only if Method 1 has no match)
    if (empty($matching_accounts) && !empty($related_order_ids)) {
        foreach ($accounts as $account) {
            $invoice_id = isset($account['invoiceId']) ? $account['invoiceId'] : '';
            if (empty($invoice_id)) {
                continue;
            }
            
            // Normalize invoiceId (convert to string, trim)
            $invoice_id = trim((string)$invoice_id);
            
            // Check if invoiceId matches any related order ID
            foreach ($related_order_ids as $order_id) {
                // Normalize order_id
                $order_id_str = trim((string)$order_id);
                
                // Exact match (highest priority)
                if ($invoice_id === $order_id_str) {
                    $matching_accounts[] = $account;
                    break;
                }
                // Partial match - invoiceId contains order_id or vice versa
                elseif (strpos($invoice_id, $order_id_str) !== false || strpos($order_id_str, $invoice_id) !== false) {
                    $matching_accounts[] = $account;
                    break;
                }
            }
        }
    }
    
    // Method 3: Check subscription ID in account metadata (only if Method 1 & 2 have no match)
    if (empty($matching_accounts)) {
        foreach ($accounts as $account) {
            // Check if account has subscription_id in metadata
            $account_subscription_id = isset($account['subscriptionId']) ? $account['subscriptionId'] : '';
            if (!empty($account_subscription_id) && $account_subscription_id == $current_subscription_id) {
                $matching_accounts[] = $account;
            }
        }
    }
    
    // Method 4: Fallback - if still no match, find account with invoiceId most similar to parent order
    if (empty($matching_accounts) && !empty($parent_order_id)) {
        $parent_order_id_str = (string)$parent_order_id;
        $best_match = null;
        $best_match_score = 0;
        
        foreach ($accounts as $account) {
            $invoice_id = isset($account['invoiceId']) ? trim((string)$account['invoiceId']) : '';
            if (empty($invoice_id)) {
                continue;
            }
            
            // Calculate similarity score
            $score = 0;
            if ($invoice_id === $parent_order_id_str) {
                $score = 100; // Exact match
            } elseif (strpos($invoice_id, $parent_order_id_str) !== false) {
                $score = 80; // Contains order ID
            } elseif (strpos($parent_order_id_str, $invoice_id) !== false) {
                $score = 60; // Order ID contains invoice ID
            } else {
                // Check if they share common digits at the end
                $invoice_digits = preg_replace('/[^0-9]/', '', $invoice_id);
                $order_digits = preg_replace('/[^0-9]/', '', $parent_order_id_str);
                if (!empty($invoice_digits) && !empty($order_digits)) {
                    if (strpos($invoice_digits, $order_digits) !== false || strpos($order_digits, $invoice_digits) !== false) {
                        $score = 40;
                    }
                }
            }
            
            if ($score > $best_match_score) {
                $best_match_score = $score;
                $best_match = $account;
            }
        }
        
        // If there's a match with minimum score of 40, use it
        if ($best_match && $best_match_score >= 40) {
            $matching_accounts[] = $best_match;
        }
    }
    
    // Method 5: Match by Program ID from account vs product meta
    if (empty($matching_accounts) && !empty($subscription_program_ids)) {
        foreach ($accounts as $account) {
            $account_program_id = isset($account['programId']) ? $account['programId'] : '';
            if (!empty($account_program_id) && in_array($account_program_id, $subscription_program_ids)) {
                $matching_accounts[] = $account;
            }
        }
    }
    
    // Method 6: Match based on created date - account created around the time subscription was created
    if (empty($matching_accounts) && $subscription_timestamp > 0) {
        $best_date_match = null;
        $smallest_time_diff = PHP_INT_MAX;
        
        foreach ($accounts as $account) {
            $account_created = isset($account['createdAt']) ? $account['createdAt'] : '';
            if (empty($account_created)) {
                continue;
            }
            
            // Parse created date
            $account_timestamp = strtotime($account_created);
            if ($account_timestamp === false) {
                continue;
            }
            
            // Calculate time difference (in seconds)
            $time_diff = abs($account_timestamp - $subscription_timestamp);
            
            // If account was created within 7 days after subscription was created, consider as match
            if ($time_diff <= (7 * 24 * 60 * 60) && $time_diff < $smallest_time_diff) {
                $smallest_time_diff = $time_diff;
                $best_date_match = $account;
            }
        }
        
        if ($best_date_match) {
            $matching_accounts[] = $best_date_match;
        }
    }
    
    // Method 7: Get the newest account created (if still no match)
    if (empty($matching_accounts)) {
        $newest_account = null;
        $newest_timestamp = 0;
        
        foreach ($accounts as $account) {
            $account_created = isset($account['createdAt']) ? $account['createdAt'] : '';
            if (empty($account_created)) {
                continue;
            }
            
            $account_timestamp = strtotime($account_created);
            if ($account_timestamp !== false && $account_timestamp > $newest_timestamp) {
                $newest_timestamp = $account_timestamp;
                $newest_account = $account;
            }
        }
        
        // If newest account was created after subscription, use it
        if ($newest_account && $subscription_timestamp > 0 && $newest_timestamp >= $subscription_timestamp) {
            $matching_accounts[] = $newest_account;
        }
    }
    
    // Method 8: Last fallback - if there's only 1 active account, display it
    if (empty($matching_accounts)) {
        $active_accounts = array_filter($accounts, function($account) {
            $state = isset($account['state']) ? $account['state'] : '';
            return $state === 'Active';
        });

        // If there's only 1 active account, it's likely the account for this subscription
        if (count($active_accounts) === 1) {
            $matching_accounts = array_values($active_accounts);
        }
    }

    // Prefer Active, then Breached (after cancel with Reactivate): show same account ID (now Breached), not Upgraded
    if (!empty($matching_accounts)) {
        $active_from_matching = array_filter($matching_accounts, function($acc) {
            $state = isset($acc['state']) ? $acc['state'] : '';
            return $state === 'Active';
        });
        if (!empty($active_from_matching)) {
            $matching_accounts = array_values($active_from_matching);
        } else {
            $breached_from_matching = array_filter($matching_accounts, function($acc) {
                $state = isset($acc['state']) ? $acc['state'] : '';
                return $state === 'Breached';
            });
            if (!empty($breached_from_matching)) {
                $matching_accounts = array_values($breached_from_matching);
            }
        }
    }

    // If we only matched Upgraded accounts but there is at least one Active account, prefer an Active
    // account that belongs to this subscription (child of upgraded parent) so staging/local both show the live account.
    if (!empty($matching_accounts)) {
        $all_active_accounts = array_filter($accounts, function($account) {
            $state = isset($account['state']) ? $account['state'] : '';
            return $state === 'Active';
        });

        $all_matching_are_upgraded = true;
        foreach ($matching_accounts as $acc) {
            $state = isset($acc['state']) ? $acc['state'] : '';
            if ($state !== 'Upgraded') {
                $all_matching_are_upgraded = false;
                break;
            }
        }

        if ($all_matching_are_upgraded && !empty($all_active_accounts)) {
            // Run same matching logic (Methods 2–7 only, not meta ID) over Active accounts to find the child account.
            $active_matches = [];
            if (!empty($related_order_ids)) {
                foreach ($all_active_accounts as $account) {
                    $invoice_id = isset($account['invoiceId']) ? trim((string)$account['invoiceId']) : '';
                    if ($invoice_id === '') continue;
                    foreach ($related_order_ids as $order_id) {
                        $order_id_str = trim((string)$order_id);
                        if ($invoice_id === $order_id_str || strpos($invoice_id, $order_id_str) !== false || strpos($order_id_str, $invoice_id) !== false) {
                            $active_matches[] = $account;
                            break;
                        }
                    }
                }
            }
            if (empty($active_matches)) {
                foreach ($all_active_accounts as $account) {
                    $account_subscription_id = isset($account['subscriptionId']) ? $account['subscriptionId'] : '';
                    if ($account_subscription_id !== '' && $account_subscription_id == $current_subscription_id) {
                        $active_matches[] = $account;
                    }
                }
            }
            if (empty($active_matches) && !empty($parent_order_id)) {
                $parent_order_id_str = (string)$parent_order_id;
                $best = null;
                $best_score = 0;
                foreach ($all_active_accounts as $account) {
                    $invoice_id = isset($account['invoiceId']) ? trim((string)$account['invoiceId']) : '';
                    if ($invoice_id === '') continue;
                    $score = ($invoice_id === $parent_order_id_str) ? 100 : (strpos($invoice_id, $parent_order_id_str) !== false ? 80 : (strpos($parent_order_id_str, $invoice_id) !== false ? 60 : 0));
                    if ($score === 0) {
                        $id = preg_replace('/[^0-9]/', '', $invoice_id);
                        $od = preg_replace('/[^0-9]/', '', $parent_order_id_str);
                        if ($id !== '' && $od !== '' && (strpos($id, $od) !== false || strpos($od, $id) !== false)) $score = 40;
                    }
                    if ($score > $best_score) { $best_score = $score; $best = $account; }
                }
                if ($best && $best_score >= 40) $active_matches[] = $best;
            }
            if (empty($active_matches) && !empty($subscription_program_ids)) {
                foreach ($all_active_accounts as $account) {
                    $program_id = isset($account['programId']) ? $account['programId'] : '';
                    if ($program_id !== '' && in_array($program_id, $subscription_program_ids)) $active_matches[] = $account;
                }
            }
            if (empty($active_matches) && $subscription_timestamp > 0) {
                $best_date = null;
                $smallest = PHP_INT_MAX;
                foreach ($all_active_accounts as $account) {
                    $created = isset($account['createdAt']) ? $account['createdAt'] : '';
                    if ($created === '') continue;
                    $ts = strtotime($created);
                    if ($ts === false) continue;
                    $diff = abs($ts - $subscription_timestamp);
                    if ($diff <= 7 * 24 * 60 * 60 && $diff < $smallest) { $smallest = $diff; $best_date = $account; }
                }
                if ($best_date !== null) $active_matches[] = $best_date;
            }
            if (empty($active_matches) && $subscription_timestamp > 0) {
                $newest = null;
                $newest_ts = 0;
                foreach ($all_active_accounts as $account) {
                    $created = isset($account['createdAt']) ? $account['createdAt'] : '';
                    if ($created === '') continue;
                    $ts = strtotime($created);
                    if ($ts !== false && $ts > $newest_ts) { $newest_ts = $ts; $newest = $account; }
                }
                if ($newest !== null && $newest_ts >= $subscription_timestamp) $active_matches[] = $newest;
            }
            if (!empty($active_matches)) {
                $matching_accounts = array_values($active_matches);
            } elseif (count($all_active_accounts) === 1) {
                $matching_accounts = array_values($all_active_accounts);
            }
        }
    }

    // Sync when showing new Active account (replacing Upgraded) is done in ypf_maybe_sync_subscription_to_active_account_on_view (runs before totals table).

    // Display matched accounts (hidden via CSS but kept in DOM for reset button logic)
    if (!empty($matching_accounts)) {
        echo '<div class="ypf-subscription-account-details">';
        echo '<h3>Account Details</h3>';
        
        foreach ($matching_accounts as $account) {
            $account_id = isset($account['id']) ? $account['id'] : '';
            if (empty($account_id)) {
                continue;
            }
            
            // Get account details
            if (!function_exists('ypf_get_account_details')) {
                continue;
            }
            
            $account_details_result = ypf_get_account_details_cached($api_base_url, $api_key, $ypf_user_id, $account_id);
            
            if ($account_details_result['status_code'] >= 200 && $account_details_result['status_code'] < 300) {
                $account_data = $account_details_result['response']['data'];
                echo ypf_render_subscription_account_details_html($account_data, $ypf_user_id, $account_id, $account);
            } else {
                // If failed to get details, display basic account info
                echo '<div class="ypf-account-details-item">';
                echo '<h4>Account: ' . esc_html($account_id) . '</h4>';
                echo '<p><strong>Status:</strong> ' . esc_html(isset($account['state']) ? $account['state'] : 'Unknown') . '</p>';
                echo '<p><strong>Error:</strong> Failed to fetch account details. Status: ' . esc_html($account_details_result['status_code']) . '</p>';
                echo '</div>';
            }
        }
        
        echo '</div>';

        // Rules Details section (separate from Account Details)
        if (function_exists('ypf_get_subscription_account_rulesdetails_bqs')) {
            echo '<div class="ypf-subscription-rules-details">';
            echo '<h3>Rules Details</h3>';

            foreach ($matching_accounts as $account) {
                $account_id = isset($account['id']) ? $account['id'] : '';
                if (empty($account_id)) {
                    continue;
                }

                $rules_details_result = ypf_get_subscription_account_rulesdetails_bqs($api_base_url, $api_key, $ypf_user_id, $account_id);

                if ($rules_details_result['status_code'] >= 200 && $rules_details_result['status_code'] < 300) {
                    $rules_data = $rules_details_result['response']['data'];
                    echo ypf_render_subscription_rules_details_html($rules_data, $ypf_user_id, $account_id, $account);
                } else {
                    echo '<div class="ypf-rules-details-item">';
                    echo '<h4>Account: ' . esc_html($account_id) . '</h4>';
                    echo '<div class="ypf-rules-api-ids"><p><strong>User ID (used in API):</strong> <code>' . esc_html($ypf_user_id) . '</code></p><p><strong>Account ID (used in API):</strong> <code>' . esc_html($account_id) . '</code></p></div>';
                    echo '<p><strong>Status:</strong> ' . esc_html(isset($account['state']) ? $account['state'] : 'Unknown') . '</p>';
                    echo '<p><strong>Error:</strong> Failed to fetch rules details. Status: ' . esc_html($rules_details_result['status_code']) . '</p>';
                    echo '</div>';
                }
            }

            echo '</div>';
        }
    } else {
        // Debug info (only display if WP_DEBUG is active)
        if (defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options')) {
            $debug_upgraded = array_filter($accounts, function($acc) {
                return (isset($acc['state']) ? $acc['state'] : '') === 'Upgraded';
            });
            $debug_active = array_filter($accounts, function($acc) {
                return (isset($acc['state']) ? $acc['state'] : '') === 'Active';
            });
            $debug_snapshot = function($acc) {
                return [
                    'id' => $acc['id'] ?? 'N/A',
                    'state' => $acc['state'] ?? 'N/A',
                    'invoiceId' => $acc['invoiceId'] ?? 'N/A',
                    'programId' => $acc['programId'] ?? 'N/A',
                    'createdAt' => $acc['createdAt'] ?? 'N/A'
                ];
            };
            echo '<div class="ypf-subscription-account-details" style="border: 2px dashed #ccc; padding: 15px; margin: 20px 0;">';
            echo '<h4>Debug Info (Admin Only)</h4>';
            echo '<p><strong>Subscription ID:</strong> ' . esc_html($current_subscription_id) . '</p>';
            echo '<p><strong>YPF User ID:</strong> ' . esc_html($ypf_user_id) . '</p>';
            echo '<p><strong>Total Accounts:</strong> ' . count($accounts) . '</p>';
            echo '<p><strong>Related Order IDs:</strong> ' . implode(', ', $related_order_ids) . '</p>';
            echo '<p><strong>Subscription Account IDs from Meta:</strong> ' . implode(', ', $subscription_account_ids) . '</p>';
            echo '<p><strong>Subscription Product IDs:</strong> ' . implode(', ', $subscription_product_ids) . '</p>';
            echo '<p><strong>Subscription Program IDs:</strong> ' . implode(', ', $subscription_program_ids) . '</p>';
            echo '<p><strong>Accounts (Upgraded):</strong> ' . count($debug_upgraded) . '</p>';
            if (!empty($debug_upgraded)) {
                echo '<pre>' . esc_html(print_r(array_map($debug_snapshot, array_values($debug_upgraded)), true)) . '</pre>';
            }
            echo '<p><strong>Accounts (Active):</strong> ' . count($debug_active) . '</p>';
            if (!empty($debug_active)) {
                echo '<pre>' . esc_html(print_r(array_map($debug_snapshot, array_values($debug_active)), true)) . '</pre>';
            }
            if (!empty($accounts)) {
                echo '<p><strong>Available Accounts (all, first 10):</strong></p><pre>' . esc_html(print_r(array_map($debug_snapshot, array_slice($accounts, 0, 10)), true)) . '</pre>';
            }
            echo '</div>';
        }
    }
}

/**
 * Fetch Rules Details from BQS API for a subscription account (GET).
 *
 * @param string $api_base_url YPF API base URL
 * @param string $api_key YPF client key
 * @param string $user_id YPF user ID
 * @param string $account_id YPF account ID
 * @return array{status_code:int,response:array}
 */
function ypf_get_subscription_account_rulesdetails_bqs($api_base_url, $api_key, $user_id, $account_id) {
    static $cache = [];
    if (empty($api_base_url)) {
        $api_base_url = 'https://api.ypf.customers.sigma-ventures.cloud';
    }
    $api_base_url = rtrim($api_base_url, '/');
    $key = $api_base_url . '|' . $user_id . '|' . $account_id;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $endpoint = $api_base_url . '/client/v1/users/' . urlencode($user_id) . '/accounts/' . urlencode($account_id);

    $args = [
        'headers' => [
            'X-Client-Key' => $api_key,
            'Content-Type' => 'application/json',
        ],
        'timeout' => 30,
    ];

    $wp_response = wp_remote_get($endpoint, $args);

    if (is_wp_error($wp_response)) {
        return [
            'status_code' => 500,
            'response' => [
                'status' => 'error',
                'message' => 'Failed to fetch rules details: ' . $wp_response->get_error_message(),
            ],
        ];
    }

    $status_code = (int) wp_remote_retrieve_response_code($wp_response);
    $body_raw = wp_remote_retrieve_body($wp_response);
    $body = json_decode($body_raw, true);

    $result = [
        'status_code' => $status_code,
        'response' => [
            'status' => ($status_code >= 200 && $status_code < 300) ? 'success' : 'error',
            'data' => $body,
            'raw' => $body_raw,
        ],
    ];
    $cache[$key] = $result;
    return $result;
}

/**
 * Render rules details HTML for subscription page.
 *
 * @param array $data Rules details data from API
 * @param string $user_id User ID
 * @param string $account_id Account ID
 * @param array $account Account basic info
 * @return string HTML output
 */
function ypf_render_subscription_rules_details_html($data, $user_id, $account_id, $account = []) {
    ob_start();
    ?>
    <div class="ypf-rules-details-item">
        <h4>Account: <?php echo esc_html($account_id); ?></h4>
        <div class="ypf-rules-api-ids">
            <p><strong>User ID (used in API):</strong> <code><?php echo esc_html($user_id); ?></code></p>
            <p><strong>Account ID (used in API):</strong> <code><?php echo esc_html($account_id); ?></code></p>
        </div>
        <?php if (!empty($account)): ?>
            <div class="ypf-account-basic-info">
                <?php if (isset($account['state'])): ?>
                    <p><strong>Status:</strong> <?php echo esc_html($account['state']); ?></p>
                <?php endif; ?>
                <?php if (isset($account['accountNumber'])): ?>
                    <p><strong>Account Number:</strong> <?php echo esc_html($account['accountNumber']); ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="ypf-rules-data">
            <pre><?php echo esc_html(wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre>
        </div>
    </div>
    <style>
        .ypf-subscription-rules-details {
            margin: 30px 0;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background: #f9f9f9;
        }
        .ypf-subscription-rules-details h3 {
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 1.5em;
        }
        .ypf-rules-details-item {
            margin-bottom: 30px;
            padding: 15px;
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 3px;
        }
        .ypf-rules-details-item:last-child {
            margin-bottom: 0;
        }
        .ypf-rules-details-item h4 {
            margin-top: 0;
            margin-bottom: 15px;
            color: #333;
        }
        .ypf-rules-api-ids {
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e0e0e0;
        }
        .ypf-rules-api-ids p {
            margin: 5px 0;
            font-size: 14px;
        }
        .ypf-rules-api-ids code {
            background: #f0f0f0;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 13px;
        }
        .ypf-rules-data pre {
            background: #f5f5f5;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 3px;
            overflow-x: auto;
            font-size: 12px;
            line-height: 1.5;
            margin: 0;
        }
    </style>
    <?php
    return ob_get_clean();
}

/**
 * Render account details HTML for subscription page
 * 
 * @param array $data Account details data from API
 * @param string $user_id User ID
 * @param string $account_id Account ID
 * @param array $account Account basic info
 * @return string HTML output
 */
function ypf_render_subscription_account_details_html($data, $user_id, $account_id, $account = []) {
    // Get initialBalance and balance from account data
    $initial_balance = null;
    $current_balance = null;
    
    // Try to get from data array (could be nested or in different structures)
    if (isset($data['initialBalance']) && is_numeric($data['initialBalance'])) {
        $initial_balance = floatval($data['initialBalance']);
    }
    
    if (isset($data['balance']) && is_numeric($data['balance'])) {
        $current_balance = floatval($data['balance']);
    }
    
    // Also check nested structures
    if ($initial_balance === null && isset($data['account']) && isset($data['account']['initialBalance']) && is_numeric($data['account']['initialBalance'])) {
        $initial_balance = floatval($data['account']['initialBalance']);
    }
    if ($current_balance === null && isset($data['account']) && isset($data['account']['balance']) && is_numeric($data['account']['balance'])) {
        $current_balance = floatval($data['account']['balance']);
    }
    
    // Also check in account basic info
    if ($initial_balance === null && isset($account['initialBalance']) && is_numeric($account['initialBalance'])) {
        $initial_balance = floatval($account['initialBalance']);
    }
    if ($current_balance === null && isset($account['balance']) && is_numeric($account['balance'])) {
        $current_balance = floatval($account['balance']);
    }
    
    $program_id = isset($account['programId']) ? $account['programId'] : ($data['programId'] ?? '');
    $cur_drawdown = function_exists('ypf_get_cur_drawdown_percentage') ? ypf_get_cur_drawdown_percentage($data, $account) : null;
    $drawdown_percentage = null;
    if ($initial_balance !== null && $current_balance !== null && $initial_balance > 0) {
        $drawdown_percentage = (($current_balance - $initial_balance) / $initial_balance) * 100;
    }
    $show_reset_button = function_exists('ypf_show_reset_button_by_program_id') && ypf_show_reset_button_by_program_id($program_id, $cur_drawdown, $drawdown_percentage);
    
    $threshold_for_js = 4.5;
    if (function_exists('ypf_get_drawdown_reset_program_config') && !empty($program_id)) {
        $config = ypf_get_drawdown_reset_program_config();
        if (isset($config[$program_id]['threshold'])) {
            $threshold_for_js = floatval($config[$program_id]['threshold']);
        }
    }
    
    // Drawdown Reset checkout URL (same as ypf-subscriptions-accounts-table)
    $reset_product_id_for_checkout = 0;
    $checkout_url_for_js = '';
    if (!empty($program_id) && function_exists('ypf_get_drawdown_reset_product_id_by_program')) {
        $reset_product_id_for_checkout = ypf_get_drawdown_reset_product_id_by_program($program_id, null);
        if (!empty($reset_product_id_for_checkout)) {
            $checkout_url_for_js = home_url('/checkout/?add-to-cart=' . $reset_product_id_for_checkout);
        }
    }
    
    ob_start();
    ?>
    <div class="ypf-account-details-item" data-ypf-drawdown-threshold="<?php echo esc_attr($threshold_for_js); ?>"<?php if (!empty($checkout_url_for_js)) : ?> data-ypf-checkout-url="<?php echo esc_attr($checkout_url_for_js); ?>" data-ypf-product-id="<?php echo esc_attr($reset_product_id_for_checkout); ?>"<?php endif; ?>>
        <h4>Account: <?php echo esc_html($account_id); ?></h4>
        <?php if (!empty($account)): ?>
            <div class="ypf-account-basic-info">
                <?php if (isset($account['state'])): ?>
                    <p><strong>Status:</strong> <?php echo esc_html($account['state']); ?></p>
                <?php endif; ?>
                <?php if (isset($account['accountNumber'])): ?>
                    <p><strong>Account Number:</strong> <?php echo esc_html($account['accountNumber']); ?></p>
                <?php endif; ?>
                <?php if ($initial_balance !== null): ?>
                    <p><strong>Initial Balance:</strong> <?php echo number_format($initial_balance, 2); ?></p>
                <?php endif; ?>
                <?php if ($current_balance !== null): ?>
                    <p><strong>Current Balance:</strong> <?php echo number_format($current_balance, 2); ?></p>
                <?php endif; ?>
                <?php if ($drawdown_percentage !== null): ?>
                    <p><strong>Drawdown:</strong> <span class="<?php echo $show_reset_button ? 'ypf-drawdown-critical' : ''; ?>"><?php echo number_format($drawdown_percentage, 2); ?>%</span></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <div class="ypf-account-data">
            <pre><?php echo esc_html(wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre>
        </div>
    </div>
    <style>
        /* Hide account details section but keep it in DOM for reset button logic */
        .ypf-subscription-rules-details,
        .ypf-subscription-account-details {
            display: none !important;
        }
        /* Original styles kept for reference but hidden */
        /*
        .ypf-subscription-account-details {
            margin: 30px 0;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background: #f9f9f9;
        }
        */
        .ypf-subscription-account-details h3 {
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 1.5em;
        }
        .ypf-account-details-item {
            margin-bottom: 30px;
            padding: 15px;
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 3px;
        }
        .ypf-account-details-item:last-child {
            margin-bottom: 0;
        }
        .ypf-account-details-item h4 {
            margin-top: 0;
            margin-bottom: 15px;
            color: #333;
        }
        .ypf-account-basic-info {
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e0e0e0;
        }
        .ypf-account-basic-info p {
            margin: 5px 0;
            font-size: 14px;
        }
        .ypf-account-data pre {
            background: #f5f5f5;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 3px;
            overflow-x: auto;
            font-size: 12px;
            line-height: 1.5;
            margin: 0;
        }
        .ypf-drawdown-critical {
            color: #dc3545;
            font-weight: bold;
        }
        .ypf-drawdown-reset-action {
            padding: 15px 0 !important;
            text-align: center;
        }
        .ypf-drawdown-reset-btn {
            background: #017FDD;
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 3px;
            cursor: pointer;
            font-weight: bold;
            transition: background 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        .ypf-drawdown-reset-btn:hover {
            background: #0169c4;
            color: #fff;
        }
        .ypf-drawdown-reset-btn:disabled {
            background: #6c757d;
            cursor: not-allowed;
            opacity: 0.6;
        }
        .ypf-reset-message {
            display: block;
            margin-top: 10px;
            font-size: 14px;
        }
        .ypf-reset-message.success {
            color: #28a745;
        }
        .ypf-reset-message.error {
            color: #dc3545;
        }
    </style>
    <?php
    return ob_get_clean();
}

/**
 * Get matching account for subscription (helper function)
 * Uses the same matching logic as ypf_display_account_details_on_subscription_page
 * Returns matching account array or null
 */
function ypf_get_subscription_matching_account($subscription) {
    if (!$subscription || !is_a($subscription, 'WC_Subscription')) {
        return null;
    }

    $api_config = ypf_get_api_config();
    $api_base_url = $api_config['api_base_url'] ?? '';
    $api_key = $api_config['api_key'] ?? '';
    if (empty($api_base_url) || empty($api_key)) {
        return null;
    }

    $ypf_user_id = ypf_get_subscription_ypf_user_id($subscription);

    // If still no user_id, try to get from email
    if (empty($ypf_user_id)) {
        $email = $subscription->get_billing_email();
        if (!empty($email)) {
            // Use function from ypf-unsub-endpoint.php
            if (function_exists('ypf_unsub_get_user_by_email')) {
                $user_data = ypf_unsub_get_user_by_email($api_base_url, $api_key, $email);
                if ($user_data['status_code'] === 200 && isset($user_data['user_id'])) {
                    $ypf_user_id = $user_data['user_id'];
                }
            }
        }
    }
    
    // If no user_id found, return null (need user_id to get accounts)
    if (empty($ypf_user_id)) {
        return null;
    }
    
    if (!function_exists('ypf_get_user_accounts')) {
        return null;
    }

    $accounts_result = ypf_get_user_accounts_cached($api_base_url, $api_key, $ypf_user_id);

    if ($accounts_result['status_code'] !== 200) {
        return null;
    }

    $accounts = [];
    if (isset($accounts_result['response']['raw']) && is_array($accounts_result['response']['raw'])) {
        $raw_accounts = $accounts_result['response']['raw'];
        if (isset($raw_accounts['results']) && is_array($raw_accounts['results'])) {
            $accounts = $raw_accounts['results'];
        } elseif (is_array($raw_accounts) && !isset($raw_accounts['status'])) {
            $accounts = $raw_accounts;
        }
    }
    
    if (empty($accounts) && !empty($accounts_result['response']['accounts'])) {
        $accounts = $accounts_result['response']['accounts'];
    }
    
    if (empty($accounts)) {
        return null;
    }
    
    // Use the same matching logic as ypf_display_account_details_on_subscription_page
    $current_subscription_id = $subscription->get_id();
    $parent_order_id = $subscription->get_parent_id();
    $related_order_ids = [];
    if ($parent_order_id) {
        $related_order_ids[] = $parent_order_id;
    }
    $renewal_orders = $subscription->get_related_orders('ids');
    if (!empty($renewal_orders)) {
        $related_order_ids = array_merge($related_order_ids, $renewal_orders);
    }
    $related_order_ids = array_unique($related_order_ids);
    
    // Get subscription account IDs from meta
    $subscription_account_ids = [];
    $sub_account_id = $subscription->get_meta('_ypf_account_id');
    if (empty($sub_account_id)) {
        $sub_account_id = $subscription->get_meta('ypf_account_id');
    }
    if (empty($sub_account_id)) {
        $sub_account_id = $subscription->get_meta('_yourpropfirm_account_id');
    }
    if (empty($sub_account_id)) {
        $sub_account_id = $subscription->get_meta('yourpropfirm_account_id');
    }
    if (!empty($sub_account_id)) {
        $subscription_account_ids[] = $sub_account_id;
    }
    
    // Check parent order meta
    if ($parent_order_id) {
        $parent_order = wc_get_order($parent_order_id);
        if ($parent_order) {
            $order_account_id = $parent_order->get_meta('_ypf_account_id');
            if (empty($order_account_id)) {
                $order_account_id = $parent_order->get_meta('ypf_account_id');
            }
            if (empty($order_account_id)) {
                $order_account_id = $parent_order->get_meta('_yourpropfirm_account_id');
            }
            if (!empty($order_account_id)) {
                $subscription_account_ids[] = $order_account_id;
            }
        }
    }
    $subscription_account_ids = array_unique($subscription_account_ids);
    
    // Filter accounts that match subscription (same logic as ypf_display_account_details_on_subscription_page)
    $matching_accounts = [];
    
    // Method 1: Match by account_id from meta (highest priority)
    if (!empty($subscription_account_ids)) {
        foreach ($accounts as $account) {
            $account_id = isset($account['id']) ? $account['id'] : '';
            if (!empty($account_id) && in_array($account_id, $subscription_account_ids)) {
                $matching_accounts[] = $account;
            }
        }
    }
    
    // Method 2: Match by invoiceId with order IDs (only if Method 1 has no match)
    if (empty($matching_accounts) && !empty($related_order_ids)) {
        foreach ($accounts as $account) {
            $invoice_id = isset($account['invoiceId']) ? $account['invoiceId'] : '';
            if (empty($invoice_id)) {
                continue;
            }
            
            $invoice_id = trim((string)$invoice_id);
            
            foreach ($related_order_ids as $order_id) {
                $order_id_str = trim((string)$order_id);
                
                if ($invoice_id === $order_id_str) {
                    $matching_accounts[] = $account;
                    break;
                } elseif (strpos($invoice_id, $order_id_str) !== false || strpos($order_id_str, $invoice_id) !== false) {
                    $matching_accounts[] = $account;
                    break;
                }
            }
        }
    }
    
    // Method 3: Check subscription ID in account metadata
    if (empty($matching_accounts)) {
        foreach ($accounts as $account) {
            $account_subscription_id = isset($account['subscriptionId']) ? $account['subscriptionId'] : '';
            if (!empty($account_subscription_id) && $account_subscription_id == $current_subscription_id) {
                $matching_accounts[] = $account;
            }
        }
    }
    
    // Method 4: Fallback - find account with invoiceId most similar to parent order
    if (empty($matching_accounts) && !empty($parent_order_id)) {
        $parent_order_id_str = (string)$parent_order_id;
        $best_match = null;
        $best_match_score = 0;
        
        foreach ($accounts as $account) {
            $invoice_id = isset($account['invoiceId']) ? trim((string)$account['invoiceId']) : '';
            if (empty($invoice_id)) {
                continue;
            }
            
            $score = 0;
            if ($invoice_id === $parent_order_id_str) {
                $score = 100;
            } elseif (strpos($invoice_id, $parent_order_id_str) !== false) {
                $score = 80;
            } elseif (strpos($parent_order_id_str, $invoice_id) !== false) {
                $score = 60;
            } else {
                $invoice_digits = preg_replace('/[^0-9]/', '', $invoice_id);
                $order_digits = preg_replace('/[^0-9]/', '', $parent_order_id_str);
                if (!empty($invoice_digits) && !empty($order_digits)) {
                    if (strpos($invoice_digits, $order_digits) !== false || strpos($order_digits, $invoice_digits) !== false) {
                        $score = 40;
                    }
                }
            }
            
            if ($score > $best_match_score) {
                $best_match_score = $score;
                $best_match = $account;
            }
        }
        
        if ($best_match && $best_match_score >= 40) {
            $matching_accounts[] = $best_match;
        }
    }
    
    // Method 5: Match by Program ID
    if (empty($matching_accounts)) {
        $subscription_product_ids = [];
        foreach ($subscription->get_items() as $item) {
            if (method_exists($item, 'get_product_id')) {
                $product_id = $item->get_product_id();
                if ($product_id) {
                    $subscription_product_ids[] = $product_id;
                }
            } else {
                $product_id = $item['product_id'] ?? 0;
                if ($product_id) {
                    $subscription_product_ids[] = $product_id;
                }
            }
        }
        
        $subscription_program_ids = [];
        foreach ($subscription_product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                $program_id = $product->get_meta('_yourpropfirm_program_id');
                if (empty($program_id)) {
                    $program_id = $product->get_meta('yourpropfirm_program_id');
                }
                if (empty($program_id)) {
                    $program_id = $product->get_meta('_ypf_program_id');
                }
                if (empty($program_id)) {
                    $program_id = $product->get_meta('ypf_program_id');
                }
                if (!empty($program_id)) {
                    $subscription_program_ids[] = $program_id;
                }
            }
        }
        $subscription_program_ids = array_unique($subscription_program_ids);
        
        if (!empty($subscription_program_ids)) {
            foreach ($accounts as $account) {
                $account_program_id = isset($account['programId']) ? $account['programId'] : '';
                if (!empty($account_program_id) && in_array($account_program_id, $subscription_program_ids)) {
                    $matching_accounts[] = $account;
                }
            }
        }
    }
    
    // Method 6: Match based on created date
    if (empty($matching_accounts)) {
        $subscription_created_date = $subscription->get_date_created();
        $subscription_timestamp = $subscription_created_date ? $subscription_created_date->getTimestamp() : 0;
        
        if ($subscription_timestamp > 0) {
            $best_date_match = null;
            $smallest_time_diff = PHP_INT_MAX;
            
            foreach ($accounts as $account) {
                $account_created = isset($account['createdAt']) ? $account['createdAt'] : '';
                if (empty($account_created)) {
                    continue;
                }
                
                $account_timestamp = strtotime($account_created);
                if ($account_timestamp === false) {
                    continue;
                }
                
                $time_diff = abs($account_timestamp - $subscription_timestamp);
                
                if ($time_diff <= (7 * 24 * 60 * 60) && $time_diff < $smallest_time_diff) {
                    $smallest_time_diff = $time_diff;
                    $best_date_match = $account;
                }
            }
            
            if ($best_date_match) {
                $matching_accounts[] = $best_date_match;
            }
        }
    }
    
    // Method 7: Get the newest account created
    if (empty($matching_accounts)) {
        $subscription_created_date = $subscription->get_date_created();
        $subscription_timestamp = $subscription_created_date ? $subscription_created_date->getTimestamp() : 0;
        
        $newest_account = null;
        $newest_timestamp = 0;
        
        foreach ($accounts as $account) {
            $account_created = isset($account['createdAt']) ? $account['createdAt'] : '';
            if (empty($account_created)) {
                continue;
            }
            
            $account_timestamp = strtotime($account_created);
            if ($account_timestamp !== false && $account_timestamp > $newest_timestamp) {
                $newest_timestamp = $account_timestamp;
                $newest_account = $account;
            }
        }
        
        if ($newest_account && $subscription_timestamp > 0 && $newest_timestamp >= $subscription_timestamp) {
            $matching_accounts[] = $newest_account;
        }
    }
    
    // Method 8: Last fallback - if there's only 1 active account
    if (empty($matching_accounts)) {
        $active_accounts = array_filter($accounts, function($account) {
            $state = isset($account['state']) ? $account['state'] : '';
            return $state === 'Active';
        });
        
        if (count($active_accounts) === 1) {
            $matching_accounts = array_values($active_accounts);
        }
    }
    
    // Prefer Active, then Breached (after cancel), over Upgraded: so when Reactivate is shown we keep showing the Breached account ID
    if (!empty($matching_accounts)) {
        $active_from_matching = array_filter($matching_accounts, function($acc) {
            $state = isset($acc['state']) ? $acc['state'] : '';
            return $state === 'Active';
        });
        if (!empty($active_from_matching)) {
            $matching_accounts = array_values($active_from_matching);
        } else {
            $breached_from_matching = array_filter($matching_accounts, function($acc) {
                $state = isset($acc['state']) ? $acc['state'] : '';
                return $state === 'Breached';
            });
            if (!empty($breached_from_matching)) {
                $matching_accounts = array_values($breached_from_matching);
            }
        }
    }

    // If we only matched Upgraded but there is at least one Active, prefer an Active account that belongs to this subscription (same as display logic).
    if (!empty($matching_accounts)) {
        $all_active_accounts = array_filter($accounts, function($account) {
            $state = isset($account['state']) ? $account['state'] : '';
            return $state === 'Active';
        });

        $all_matching_are_upgraded = true;
        foreach ($matching_accounts as $acc) {
            $state = isset($acc['state']) ? $acc['state'] : '';
            if ($state !== 'Upgraded') {
                $all_matching_are_upgraded = false;
                break;
            }
        }

        if ($all_matching_are_upgraded && !empty($all_active_accounts)) {
            $subscription_created_date = $subscription->get_date_created();
            $subscription_timestamp = $subscription_created_date ? $subscription_created_date->getTimestamp() : 0;
            $subscription_program_ids = [];
            foreach ($subscription->get_items() as $item) {
                $product_id = method_exists($item, 'get_product_id') ? $item->get_product_id() : ($item['product_id'] ?? 0);
                if (!$product_id) continue;
                $product = wc_get_product($product_id);
                if (!$product) continue;
                $program_id = $product->get_meta('_yourpropfirm_program_id') ?: $product->get_meta('yourpropfirm_program_id') ?: $product->get_meta('_ypf_program_id') ?: $product->get_meta('ypf_program_id');
                if (!empty($program_id)) $subscription_program_ids[] = $program_id;
            }
            $subscription_program_ids = array_unique($subscription_program_ids);

            $active_matches = [];
            if (!empty($related_order_ids)) {
                foreach ($all_active_accounts as $account) {
                    $invoice_id = isset($account['invoiceId']) ? trim((string)$account['invoiceId']) : '';
                    if ($invoice_id === '') continue;
                    foreach ($related_order_ids as $order_id) {
                        $order_id_str = trim((string)$order_id);
                        if ($invoice_id === $order_id_str || strpos($invoice_id, $order_id_str) !== false || strpos($order_id_str, $invoice_id) !== false) {
                            $active_matches[] = $account;
                            break;
                        }
                    }
                }
            }
            if (empty($active_matches)) {
                foreach ($all_active_accounts as $account) {
                    $account_subscription_id = isset($account['subscriptionId']) ? $account['subscriptionId'] : '';
                    if ($account_subscription_id !== '' && $account_subscription_id == $current_subscription_id) {
                        $active_matches[] = $account;
                    }
                }
            }
            if (empty($active_matches) && !empty($parent_order_id)) {
                $parent_order_id_str = (string)$parent_order_id;
                $best = null;
                $best_score = 0;
                foreach ($all_active_accounts as $account) {
                    $invoice_id = isset($account['invoiceId']) ? trim((string)$account['invoiceId']) : '';
                    if ($invoice_id === '') continue;
                    $score = ($invoice_id === $parent_order_id_str) ? 100 : (strpos($invoice_id, $parent_order_id_str) !== false ? 80 : (strpos($parent_order_id_str, $invoice_id) !== false ? 60 : 0));
                    if ($score === 0) {
                        $id = preg_replace('/[^0-9]/', '', $invoice_id);
                        $od = preg_replace('/[^0-9]/', '', $parent_order_id_str);
                        if ($id !== '' && $od !== '' && (strpos($id, $od) !== false || strpos($od, $id) !== false)) $score = 40;
                    }
                    if ($score > $best_score) { $best_score = $score; $best = $account; }
                }
                if ($best && $best_score >= 40) $active_matches[] = $best;
            }
            if (empty($active_matches) && !empty($subscription_program_ids)) {
                foreach ($all_active_accounts as $account) {
                    $program_id = isset($account['programId']) ? $account['programId'] : '';
                    if ($program_id !== '' && in_array($program_id, $subscription_program_ids)) $active_matches[] = $account;
                }
            }
            if (empty($active_matches) && $subscription_timestamp > 0) {
                $best_date = null;
                $smallest = PHP_INT_MAX;
                foreach ($all_active_accounts as $account) {
                    $created = isset($account['createdAt']) ? $account['createdAt'] : '';
                    if ($created === '') continue;
                    $ts = strtotime($created);
                    if ($ts === false) continue;
                    $diff = abs($ts - $subscription_timestamp);
                    if ($diff <= 7 * 24 * 60 * 60 && $diff < $smallest) { $smallest = $diff; $best_date = $account; }
                }
                if ($best_date !== null) $active_matches[] = $best_date;
            }
            if (empty($active_matches) && $subscription_timestamp > 0) {
                $newest = null;
                $newest_ts = 0;
                foreach ($all_active_accounts as $account) {
                    $created = isset($account['createdAt']) ? $account['createdAt'] : '';
                    if ($created === '') continue;
                    $ts = strtotime($created);
                    if ($ts !== false && $ts > $newest_ts) { $newest_ts = $ts; $newest = $account; }
                }
                if ($newest !== null && $newest_ts >= $subscription_timestamp) $active_matches[] = $newest;
            }
            if (!empty($active_matches)) {
                $matching_accounts = array_values($active_matches);
            } elseif (count($all_active_accounts) === 1) {
                $matching_accounts = array_values($all_active_accounts);
            }
        }
    }

    // Return first matching account
    if (!empty($matching_accounts) && isset($matching_accounts[0])) {
        return $matching_accounts[0];
    }

    return null;
}

/**
 * Get Account Login for subscription (helper function)
 */
function ypf_get_subscription_account_login($subscription) {
    $matching_account = ypf_get_subscription_matching_account($subscription);
    return ($matching_account && isset($matching_account['login'])) ? $matching_account['login'] : '';
}

/**
 * Enqueue script to add Account ID row to subscription details table
 * Uses the same method as drawdown reset button
 */
add_action('wp_enqueue_scripts', 'ypf_enqueue_account_id_table_script');
function ypf_enqueue_account_id_table_script() {
    // Check if WooCommerce and WooCommerce Subscriptions are active
    if (!class_exists('WooCommerce') || !function_exists('wcs_is_view_subscription_page')) {
        return;
    }
    
    // Check if we're on the view subscription page
    if (!wcs_is_view_subscription_page()) {
        return;
    }
    
    // Get subscription ID
    $subscription_id = absint(get_query_var('view-subscription'));
    if (!$subscription_id) {
        return;
    }
    
    // Get subscription
    $subscription = wcs_get_subscription($subscription_id);
    if (!$subscription || !is_a($subscription, 'WC_Subscription')) {
        return;
    }
    
    // Check user permission
    if (!current_user_can('view_order', $subscription->get_id())) {
        return;
    }
    
    // Get account login using helper function
    $account_login = ypf_get_subscription_account_login($subscription);
    
    if (empty($account_login)) {
        return;
    }
    
    // Enqueue script
    wp_enqueue_script(
        'ypf-account-id-table',
        content_url('mu-plugins/ypf-account-id-table.js'),
        array('jquery'),
        '1.0.0',
        true
    );
    
    // Localize script with account login data
    wp_localize_script('ypf-account-id-table', 'ypfAccountId', array(
        'accountLogin' => $account_login
    ));
}

/**
 * Display subscription accounts table on My Account subscriptions list page
 */
add_action('woocommerce_before_account_subscriptions_table', 'ypf_display_subscriptions_accounts_table', 10);
/**
 * Also display the same table on My Account dashboard.
 *
 * The dashboard template does not include this table by default, so we must
 * render the HTML here (JS-only "move" logic can't work if the table doesn't exist).
 */
add_action('woocommerce_account_dashboard', 'ypf_display_subscriptions_accounts_table', 5);
// Fallback for themes/builders that don't call woocommerce_account_dashboard reliably.
// WooCommerce core still calls this hook on the dashboard template.
add_action('woocommerce_before_my_account', 'ypf_display_subscriptions_accounts_table', 5);
function ypf_display_subscriptions_accounts_table() {
    // Check if we're on the subscriptions list page
    if (!is_account_page() || !function_exists('wcs_get_users_subscriptions')) {
        return;
    }

    // When this function is called on the dashboard, we want to render
    // an explicit empty-state instead of returning silently.
    $current_filter = function_exists('current_filter') ? current_filter() : '';
    $is_dashboard_context =
        in_array($current_filter, ['woocommerce_account_dashboard', 'woocommerce_before_my_account'], true)
        || (function_exists('is_wc_endpoint_url') && is_account_page() && !is_wc_endpoint_url());

    // Prevent double render on dashboard (Woo dashboard template calls multiple hooks).
    static $did_render_on_dashboard = false;
    if ($is_dashboard_context) {
        if ($did_render_on_dashboard) {
            return;
        }
        $did_render_on_dashboard = true;
    }
    
    // Get current user
    $current_user_id = get_current_user_id();
    if (!$current_user_id) {
        return;
    }
    
    $api_config = ypf_get_api_config();
    $api_base_url = $api_config['api_base_url'] ?? '';
    $api_key = $api_config['api_key'] ?? '';
    if (empty($api_base_url) || empty($api_key)) {
        return;
    }

    /**
     * Determine YPF user ID untuk customer ini, TANPA mengandalkan ?user_id di URL.
     *
     * PRIORITAS:
     * 1. Meta _ypf_user_id / _yourpropfirm_user_id di subscription atau parent order
     * 2. Billing email dari subscription -> ypf_unsub_get_user_by_email()
     * 3. Email akun WordPress saat ini -> ypf_unsub_get_user_by_email()
     */
    $ypf_user_id           = '';
    $billing_email         = '';
    $fallback_product_id   = null;
    $fallback_product_name = '';
    $subscriptions         = function_exists('wcs_get_users_subscriptions') ? wcs_get_users_subscriptions($current_user_id) : [];

    if (!empty($subscriptions)) {
        foreach ($subscriptions as $subscription) {
            if (!is_a($subscription, 'WC_Subscription')) {
                continue;
            }

            // Simpan billing email pertama yang ketemu untuk fallback ke API
            if (empty($billing_email)) {
                $sub_email = $subscription->get_billing_email();
                if (!empty($sub_email)) {
                    $billing_email = $sub_email;
                }
            }

            // Simpan product_id pertama dari subscription ini sebagai fallback untuk checkout URL
            if ($fallback_product_id === null) {
                foreach ($subscription->get_items() as $item) {
                    if (method_exists($item, 'get_product_id')) {
                        $pid = $item->get_product_id();
                    } else {
                        $pid = isset($item['product_id']) ? $item['product_id'] : 0;
                    }
                    if (!empty($pid)) {
                        $fallback_product_id = $pid;
                        if (empty($fallback_product_name)) {
                            $product = wc_get_product($pid);
                            if ($product) {
                                $fallback_product_name = $product->get_name();
                            }
                        }
                        break;
                    }
                }
            }

            // Coba ambil YPF user id dari meta subscription
            $ypf_user_id = $subscription->get_meta('_ypf_user_id');
            if (empty($ypf_user_id)) {
                $ypf_user_id = $subscription->get_meta('ypf_user_id');
            }
            if (empty($ypf_user_id)) {
                $ypf_user_id = $subscription->get_meta('_yourpropfirm_user_id');
            }

            // Kalau belum ada, coba meta di parent order
            if (empty($ypf_user_id)) {
                $parent_order_id = $subscription->get_parent_id();
                if ($parent_order_id) {
                    $parent_order = wc_get_order($parent_order_id);
                    if ($parent_order) {
                        $ypf_user_id = $parent_order->get_meta('_ypf_user_id');
                        if (empty($ypf_user_id)) {
                            $ypf_user_id = $parent_order->get_meta('ypf_user_id');
                        }
                        if (empty($ypf_user_id)) {
                            $ypf_user_id = $parent_order->get_meta('_yourpropfirm_user_id');
                        }
                    }
                }
            }

            if (!empty($ypf_user_id)) {
                break;
            }
        }
    }

    // Fallback 2: gunakan billing email subscription (lebih dekat ke data YPF)
    if (empty($ypf_user_id) && !empty($billing_email) && function_exists('ypf_unsub_get_user_by_email')) {
        $user_data = ypf_unsub_get_user_by_email($api_base_url, $api_key, $billing_email);
        if (is_array($user_data) && isset($user_data['status_code']) && $user_data['status_code'] === 200 && !empty($user_data['user_id'])) {
            $ypf_user_id = $user_data['user_id'];
        }
    }

    // Fallback 3: resolve YPF user dari email akun WordPress saat ini
    if (empty($ypf_user_id) && function_exists('ypf_unsub_get_user_by_email')) {
        $current_user = wp_get_current_user();
        $email        = ($current_user && !empty($current_user->user_email)) ? $current_user->user_email : '';

        if (!empty($email)) {
            $user_data = ypf_unsub_get_user_by_email($api_base_url, $api_key, $email);
            if (is_array($user_data) && isset($user_data['status_code']) && $user_data['status_code'] === 200 && !empty($user_data['user_id'])) {
                $ypf_user_id = $user_data['user_id'];
            }
        }
    }

    if (empty($ypf_user_id)) {
        if ($is_dashboard_context) {
            echo '<div class="ypf-subscriptions-accounts-table-wrapper">';
            echo '<h3>Subscription Accounts (Limited Drawdown)</h3>';
            echo '<p>No YPF user ID found for this account.</p>';
            echo '</div>';
        }
        return;
    }

    if (!function_exists('ypf_get_user_accounts')) {
        return;
    }

    $accounts_result = ypf_get_user_accounts_cached($api_base_url, $api_key, $ypf_user_id);

    if (!is_array($accounts_result) || !isset($accounts_result['status_code']) || $accounts_result['status_code'] !== 200) {
        return;
    }

    $accounts = [];
    if (isset($accounts_result['response']['raw']) && is_array($accounts_result['response']['raw'])) {
        $raw_accounts = $accounts_result['response']['raw'];
        if (isset($raw_accounts['results']) && is_array($raw_accounts['results'])) {
            $accounts = $raw_accounts['results'];
        } elseif (is_array($raw_accounts) && !isset($raw_accounts['status'])) {
            $accounts = $raw_accounts;
        }
    }

    if (empty($accounts) && !empty($accounts_result['response']['accounts'])) {
        $accounts = $accounts_result['response']['accounts'];
    }

    if (empty($accounts)) {
        if ($is_dashboard_context) {
            echo '<div class="ypf-subscriptions-accounts-table-wrapper">';
            echo '<h3>Subscription Accounts (Limited Drawdown)</h3>';
            echo '<p>No accounts found for this user.</p>';
            echo '</div>';
        }
        return;
    }

    // Collect table rows based on API accounts (not Woo subscriptions).
    $subscription_data = [];

    foreach ($accounts as $account) {
        // Only show Active accounts (skip Upgraded state - old account after upgrade)
        $state = isset($account['state']) ? $account['state'] : '';
        if ($state === 'Upgraded') {
            continue;
        }

        $account_id = isset($account['id']) ? $account['id'] : '';
        if (empty($account_id)) {
            continue;
        }

        $account_login = isset($account['login']) ? $account['login'] : '';

        if (!function_exists('ypf_get_account_details')) {
            continue;
        }

        $account_details_result = ypf_get_account_details_cached($api_base_url, $api_key, $ypf_user_id, $account_id);

        if (!is_array($account_details_result) || $account_details_result['status_code'] < 200 || $account_details_result['status_code'] >= 300) {
            continue;
        }

        $account_data = isset($account_details_result['response']['data'])
            ? $account_details_result['response']['data']
            : $account_details_result['response'];

        // Calculate drawdown
        $initial_balance = null;
        $current_balance = null;
        $drawdown_percentage = null;
        $show_reset_button = false;

        // Try to get balances from multiple locations
        if (isset($account_data['initialBalance']) && is_numeric($account_data['initialBalance'])) {
            $initial_balance = floatval($account_data['initialBalance']);
        }
        if (isset($account_data['balance']) && is_numeric($account_data['balance'])) {
            $current_balance = floatval($account_data['balance']);
        }

        if ($initial_balance === null && isset($account_data['account']['initialBalance']) && is_numeric($account_data['account']['initialBalance'])) {
            $initial_balance = floatval($account_data['account']['initialBalance']);
        }
        if ($current_balance === null && isset($account_data['account']['balance']) && is_numeric($account_data['account']['balance'])) {
            $current_balance = floatval($account_data['account']['balance']);
        }

        if ($initial_balance === null && isset($account['initialBalance']) && is_numeric($account['initialBalance'])) {
            $initial_balance = floatval($account['initialBalance']);
        }
        if ($current_balance === null && isset($account['balance']) && is_numeric($account['balance'])) {
            $current_balance = floatval($account['balance']);
        }

        if ($initial_balance !== null && $current_balance !== null && $initial_balance > 0) {
            $drawdown_percentage = (($current_balance - $initial_balance) / $initial_balance) * 100;
        }

        $program_id = isset($account['programId']) ? $account['programId'] : ($account_data['programId'] ?? '');
        $cur_drawdown = function_exists('ypf_get_cur_drawdown_percentage') ? ypf_get_cur_drawdown_percentage($account_data, $account) : null;
        $show_reset_button = function_exists('ypf_show_reset_button_by_program_id') && ypf_show_reset_button_by_program_id($program_id, $cur_drawdown, $drawdown_percentage);

        if ($show_reset_button) {
            $reset_product_id = function_exists('ypf_get_drawdown_reset_product_id_by_program')
                ? ypf_get_drawdown_reset_product_id_by_program($program_id, null)
                : 0;
            if (empty($reset_product_id)) {
                $reset_product_id = $fallback_product_id;
            }
            // Get product name from program_id (Alpine Pass Standard/Advanced/Pro or Upgraded variant)
            $product_name = $fallback_product_name;
            if (!empty($program_id) && function_exists('ypf_get_wc_product_by_program_id')) {
                $program_product = ypf_get_wc_product_by_program_id($program_id, true);
                if ($program_product && is_a($program_product, 'WC_Product')) {
                    $product_name = $program_product->get_name();
                }
            }
            $subscription_data[] = [
                // We don't have a specific WC subscription here; use account ID as identifier.
                'subscription_id' => $account_id,
                'account_id' => $account_id,
                'account_login' => $account_login,
                'product_name' => $product_name,
                'drawdown_percentage' => $drawdown_percentage,
                'show_reset_button' => $show_reset_button,
                'user_id' => $ypf_user_id,
                'product_id' => $reset_product_id,
            ];
        }
    }
    
    // Only display table if there are subscriptions with limited drawdown
    if (empty($subscription_data)) {
        if ($is_dashboard_context) {
            echo '<div class="ypf-subscriptions-accounts-table-wrapper">';
            echo '<h3>Subscription Accounts (Limited Drawdown)</h3>';
            echo '<table class="shop_table shop_table_responsive ypf-subscriptions-accounts-table">';
            echo '<thead><tr><th>Account ID</th><th>Product</th><th>Status (Drawdown %)</th><th>Action</th></tr></thead>';
            echo '<tbody><tr><td colspan="4">No accounts with limited drawdown found.</td></tr></tbody>';
            echo '</table>';
            echo '</div>';
            echo '<style>
                .ypf-subscriptions-accounts-table-wrapper {
                    margin: 30px 0;
                    padding: 20px;
                    background: #f9f9f9;
                    border: 1px solid #ddd;
                    border-radius: 5px;
                }
                .ypf-subscriptions-accounts-table-wrapper h3 {
                    margin-top: 0;
                    margin-bottom: 20px;
                    font-size: 1.5em;
                }
                .ypf-subscriptions-accounts-table {
                    width: 100%;
                    border-collapse: collapse;
                }
                .ypf-subscriptions-accounts-table th,
                .ypf-subscriptions-accounts-table td {
                    padding: 12px;
                    text-align: left;
                    border-bottom: 1px solid #ddd;
                }
                .ypf-subscriptions-accounts-table th {
                    background: #f5f5f5;
                    font-weight: bold;
                }
            </style>';
        }
        return;
    }
    
    // Display table
    ?>
    <div class="ypf-subscriptions-accounts-table-wrapper">
        <h3>Subscription Accounts (Limited Drawdown)</h3>
        <table class="shop_table shop_table_responsive ypf-subscriptions-accounts-table">
            <thead>
                <tr>
                    <th>Account ID</th>
                    <th>Product</th>
                    <th>Status (Drawdown %)</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($subscription_data as $data): ?>
                    <tr data-subscription-id="<?php echo esc_attr($data['subscription_id']); ?>" 
                        data-account-id="<?php echo esc_attr($data['account_id']); ?>"
                        data-user-id="<?php echo esc_attr($data['user_id']); ?>"
                        data-product-id="<?php echo esc_attr($data['product_id']); ?>">
                        <td data-title="Account ID">
                            <?php echo esc_html($data['account_login'] ? $data['account_login'] : $data['account_id']); ?>
                        </td>
                        <td data-title="Product">
                            <?php echo esc_html($data['product_name']); ?>
                        </td>
                        <td data-title="Status">
                            <span class="ypf-drawdown-status">
                                <?php echo esc_html(number_format($data['drawdown_percentage'], 2) . '%'); ?>
                            </span>
                        </td>
                        <td data-title="Action">
                            <?php if ($data['show_reset_button']): ?>
                                <?php
                                $checkout_url = '';
                                if (!empty($data['product_id'])) {
                                    $checkout_url = home_url('/checkout/?add-to-cart=' . $data['product_id']);
                                }
                                ?>
                                <a href="<?php echo $checkout_url ? esc_url($checkout_url) : '#'; ?>"
                                   class="button ypf-drawdown-reset-btn" target="_blank"
                                   data-subscription-id="<?php echo esc_attr($data['subscription_id']); ?>"
                                   data-account-id="<?php echo esc_attr($data['account_id']); ?>"
                                   data-user-id="<?php echo esc_attr($data['user_id']); ?>">
                                    Reset Drawdown
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <style>
        .ypf-subscriptions-accounts-table-wrapper {
            margin: 30px 0;
            padding: 20px;
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        .ypf-subscriptions-accounts-table-wrapper h3 {
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 1.5em;
        }
        .ypf-subscriptions-accounts-table {
            width: 100%;
            border-collapse: collapse;
        }
        .ypf-subscriptions-accounts-table th,
        .ypf-subscriptions-accounts-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .ypf-subscriptions-accounts-table th {
            background: #f5f5f5;
            font-weight: bold;
        }
        .ypf-drawdown-status {
            color: #dc3545;
            font-weight: bold;
        }
        .ypf-drawdown-reset-btn {
            background: #017FDD !important;
            color: #fff !important;
            border: none;
            padding: 10px 20px;
            border-radius: 3px;
            cursor: pointer;
            font-weight: bold;
            transition: background 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        .ypf-drawdown-reset-btn:hover {
            background: #0169c4 !important;
            color: #fff !important;
        }
        .ypf-reset-button:disabled {
            background: #6c757d !important;
            cursor: not-allowed;
            opacity: 0.6;
        }
        .ypf-reset-message {
            display: block;
            margin-top: 10px;
            font-size: 14px;
        }
        .ypf-reset-message.success {
            color: #28a745;
        }
        .ypf-reset-message.error {
            color: #dc3545;
        }
    </style>
    <?php
}

/**
 * Enqueue script for subscriptions accounts table
 */
add_action('wp_enqueue_scripts', 'ypf_enqueue_subscriptions_accounts_table_script');
function ypf_enqueue_subscriptions_accounts_table_script() {
    // Check if WooCommerce and WooCommerce Subscriptions are active
    if (!class_exists('WooCommerce') || !function_exists('wcs_get_users_subscriptions')) {
        return;
    }
    
    // Check if we're on the subscriptions list page
    if (!is_account_page()) {
        return;
    }
    
    // Get current user
    $current_user_id = get_current_user_id();
    if (!$current_user_id) {
        return;
    }
    
    // Get user's subscriptions
    $subscriptions = wcs_get_users_subscriptions($current_user_id);
    
    if (empty($subscriptions)) {
        return;
    }
    
    // Enqueue script
    wp_enqueue_script(
        'ypf-subscriptions-accounts-table',
        content_url('mu-plugins/ypf-subscriptions-accounts-table.js'),
        array('jquery'),
        '1.0.0',
        true
    );
    
    // Localize script with AJAX data
    wp_localize_script('ypf-subscriptions-accounts-table', 'ypfSubscriptionsAccounts', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ypf_reset_drawdown_nonce')
    ));
}
