<?php
/**
 * Plugin Name: YPF DDR Checkout Trigger
 * Description: When a Drawdown Reset (DDR) product is purchased and payment completed, calls YPF API to reset account balance.
 * Version: 1.0.0
 * Author: Alpine Funded Dev Team
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Debug log helper - writes to WooCommerce log and optionally adds order note.
 *
 * @param string $message
 * @param array $context Optional context data
 * @param WC_Order|null $order If provided, also add as order note
 */
function ypf_ddr_debug_log($message, $context = array(), $order = null) {
    if (function_exists('wc_get_logger')) {
        $logger = wc_get_logger();
        $logger->info('[YPF DDR] ' . $message, array_merge(array('source' => 'ypf_ddr_checkout_trigger'), $context));
    }
    if ($order && is_a($order, 'WC_Order')) {
        $order->add_order_note('[YPF DDR DEBUG] ' . $message);
    }
}

/**
 * Get DDR SKUs from config (from ypf-subscription-account-details).
 *
 * @return array List of DDR SKUs
 */
function ypf_ddr_get_ddr_skus() {
    if (!function_exists('ypf_get_drawdown_reset_program_config')) {
        return array();
    }
    $config = ypf_get_drawdown_reset_program_config();
    $skus = array();
    foreach ($config as $entry) {
        if (!empty($entry['sku'])) {
            $skus[] = $entry['sku'];
        }
    }
    return array_unique($skus);
}

/**
 * Get program ID for a DDR SKU (reverse lookup from config).
 *
 * @param string $sku DDR product SKU
 * @return string|null Program ID or null
 */
function ypf_ddr_get_program_id_by_sku($sku) {
    if (!function_exists('ypf_get_drawdown_reset_program_config') || empty($sku)) {
        return null;
    }
    $config = ypf_get_drawdown_reset_program_config();
    foreach ($config as $program_id => $entry) {
        if (!empty($entry['sku']) && $entry['sku'] === $sku) {
            return $program_id;
        }
    }
    return null;
}

/**
 * Get first DDR SKU from order.
 *
 * @param WC_Order $order
 * @return string|null SKU or null
 */
function ypf_ddr_get_first_ddr_sku_from_order($order) {
    if (!$order || !is_a($order, 'WC_Order')) {
        return null;
    }
    $ddr_skus = ypf_ddr_get_ddr_skus();
    if (empty($ddr_skus)) {
        return null;
    }
    foreach ($order->get_items() as $item) {
        $product = is_object($item) && method_exists($item, 'get_product') ? $item->get_product() : null;
        if (!$product || !is_a($product, 'WC_Product')) {
            continue;
        }
        $sku = $product->get_sku();
        if (!empty($sku) && in_array($sku, $ddr_skus, true)) {
            return $sku;
        }
    }
    return null;
}

/**
 * Check if order contains any DDR product (by SKU).
 *
 * @param WC_Order $order
 * @return bool
 */
function ypf_ddr_order_contains_ddr_product($order) {
    return ypf_ddr_get_first_ddr_sku_from_order($order) !== null;
}

/**
 * Check if subscription has a product with given program ID.
 *
 * @param WC_Subscription $subscription
 * @param string $program_id
 * @return bool
 */
function ypf_ddr_subscription_has_program($subscription, $program_id) {
    if (!$subscription || !is_a($subscription, 'WC_Subscription') || empty($program_id)) {
        return false;
    }
    foreach ($subscription->get_items() as $item) {
        $product = is_object($item) && method_exists($item, 'get_product') ? $item->get_product() : null;
        if (!$product || !is_a($product, 'WC_Product')) {
            continue;
        }
        $pid = $product->get_meta('_yourpropfirm_program_id') ?: $product->get_meta('yourpropfirm_program_id')
            ?: $product->get_meta('_ypf_program_id') ?: $product->get_meta('ypf_program_id');
        if (!empty($pid) && $pid === $program_id) {
            return true;
        }
    }
    return false;
}

/**
 * Call YPF update API to reset account balance.
 *
 * @param string $api_base_url
 * @param string $api_key
 * @param string $user_id YPF user ID
 * @param string $account_id YPF account ID
 * @param float $initial_balance Value for currentBalance (reset to initial)
 * @return array{status:string,message:string,status_code?:int}
 */
function ypf_ddr_call_update_api($api_base_url, $api_key, $user_id, $account_id, $initial_balance) {
    $api_base_url = rtrim($api_base_url, '/');
    $endpoint = $api_base_url . '/client/v1/users/' . urlencode($user_id) . '/accounts/' . urlencode($account_id) . '/update';

    $body = array(
        'tradingDays' => 0,
        'currentBalance' => floatval($initial_balance),
        'levelUpProfitTradingDays' => 0,
        'withdrawProfitTradingDays' => 0,
        'noteType' => 'Warning',
        'adminNote' => 'Reset Balance',
        'addOns' => array(
            'withdrawActiveDays' => 0,
            'withdrawTradingDays' => 0,
            'withdrawProfitableTradingDays' => 0,
            'levelUpTradingDays' => 0,
            'levelUpProfitableTradingDays' => 0,
            'noBreachTradeOverWeekend' => true,
            'noBreachStopLoss' => true,
            'noBreachNewsTrading' => true,
            'withdrawEligibleTrades' => 0,
            'withdrawPTDProfitPercentage' => 0,
            'levelUpPTDProfitPercentage' => 0,
        ),
    );

    $body_json = wp_json_encode($body);
    $args = array(
        'method' => 'PUT',
        'headers' => array(
            'X-Client-Key' => $api_key,
            'Content-Type' => 'application/json',
        ),
        'body' => $body_json,
        'timeout' => 30,
    );

    $wp_response = wp_remote_request($endpoint, $args);

    if (is_wp_error($wp_response)) {
        return array(
            'status' => 'error',
            'message' => $wp_response->get_error_message(),
        );
    }

    $status_code = (int) wp_remote_retrieve_response_code($wp_response);
    $response_body = wp_remote_retrieve_body($wp_response);

    if ($status_code >= 200 && $status_code < 300) {
        return array(
            'status' => 'success',
            'message' => 'Account balance reset successfully',
            'status_code' => $status_code,
        );
    }

    return array(
        'status' => 'error',
        'message' => 'Update failed',
        'status_code' => $status_code,
        'api_response' => $response_body,
    );
}

/**
 * Get initialBalance from account details API.
 * Tries multiple endpoints and uses recursive key search for various API response structures.
 *
 * @param string $api_base_url
 * @param string $api_key
 * @param string $user_id
 * @param string $account_id
 * @return float|null
 */
function ypf_ddr_get_initial_balance($api_base_url, $api_key, $user_id, $account_id) {
    $sources = array();

    // 1. Try /rulesdetails endpoint (ypf_get_account_details)
    if (function_exists('ypf_get_account_details_cached')) {
        $result = ypf_get_account_details_cached($api_base_url, $api_key, $user_id, $account_id);
        $sources[] = array('name' => 'rulesdetails', 'result' => $result);
    }

    // 2. Try base /accounts/{id} endpoint
    if (function_exists('ypf_get_subscription_account_rulesdetails_bqs')) {
        $result2 = ypf_get_subscription_account_rulesdetails_bqs($api_base_url, $api_key, $user_id, $account_id);
        $sources[] = array('name' => 'accounts_base', 'result' => $result2);
    }

    foreach ($sources as $src) {
        $result = $src['result'];
        if (!is_array($result) || ($result['status_code'] ?? 500) >= 300) {
            continue;
        }
        $data = isset($result['response']['data']) ? $result['response']['data'] : (isset($result['response']) ? $result['response'] : array());
        if (!is_array($data)) {
            continue;
        }

        // Direct keys
        if (isset($data['initialBalance']) && is_numeric($data['initialBalance'])) {
            return floatval($data['initialBalance']);
        }
        if (isset($data['account']['initialBalance']) && is_numeric($data['account']['initialBalance'])) {
            return floatval($data['account']['initialBalance']);
        }
        // Recursive search (handles nested structures)
        if (function_exists('ypf_array_find_key_recursive')) {
            $val = ypf_array_find_key_recursive($data, 'initialBalance');
            if ($val !== null && $val !== '' && is_numeric($val)) {
                return floatval($val);
            }
        }
    }

    // 3. Try user accounts list - account may have initialBalance there
    if (function_exists('ypf_get_user_accounts_cached') && function_exists('ypf_get_api_config')) {
        $accounts_result = ypf_get_user_accounts_cached($api_base_url, $api_key, $user_id);
        if (is_array($accounts_result) && ($accounts_result['status_code'] ?? 500) === 200) {
            $raw = $accounts_result['response']['raw'] ?? $accounts_result['response'] ?? array();
            $accounts = isset($raw['results']) ? $raw['results'] : (is_array($raw) && !isset($raw['status']) ? $raw : array());
            if (!empty($accounts)) {
                foreach ($accounts as $acc) {
                    if (!is_array($acc)) continue;
                    $acc_id = $acc['id'] ?? $acc['_id'] ?? '';
                    if ((string) $acc_id === (string) $account_id) {
                        if (isset($acc['initialBalance']) && is_numeric($acc['initialBalance'])) {
                            return floatval($acc['initialBalance']);
                        }
                        if (function_exists('ypf_array_find_key_recursive')) {
                            $val = ypf_array_find_key_recursive($acc, 'initialBalance');
                            if ($val !== null && $val !== '' && is_numeric($val)) {
                                return floatval($val);
                            }
                        }
                        break;
                    }
                }
            }
        }
    }

    // 4. Allow filter for fallback when API does not return initialBalance
    $program_id_ctx = ypf_ddr_get_program_id_by_sku_from_order_context();
    if ($program_id_ctx && has_filter('ypf_ddr_fallback_initial_balance')) {
        $fallback = apply_filters('ypf_ddr_fallback_initial_balance', null, $program_id_ctx, null, $user_id, $account_id);
        if ($fallback !== null && is_numeric($fallback) && floatval($fallback) > 0) {
            return floatval($fallback);
        }
    }

    return null;
}

/**
 * Helper to get program_id in order context (set by resolve step).
 */
function ypf_ddr_get_program_id_by_sku_from_order_context() {
    return isset($GLOBALS['ypf_ddr_current_program_id']) ? $GLOBALS['ypf_ddr_current_program_id'] : null;
}

/**
 * Resolve ypf_user_id and account_id from order customer.
 * Derives from customer's subscriptions by matching DDR product SKU to subscription program.
 * Works regardless of device or when payment is delayed - no URL params needed.
 *
 * @param WC_Order $order
 * @return array{user_id:string,account_id:string}|null
 */
function ypf_ddr_resolve_user_and_account($order) {
    $ddr_sku = ypf_ddr_get_first_ddr_sku_from_order($order);
    if (empty($ddr_sku)) {
        return null;
    }

    $program_id = ypf_ddr_get_program_id_by_sku($ddr_sku);
    if (empty($program_id)) {
        return null;
    }

    $wp_user_id = $order->get_user_id();
    if (!$wp_user_id) {
        $email = $order->get_billing_email();
        if (!empty($email)) {
            $wp_user = get_user_by('email', $email);
            $wp_user_id = $wp_user ? $wp_user->ID : 0;
        }
    }
    if (!$wp_user_id || !function_exists('wcs_get_users_subscriptions')) {
        return null;
    }

    $subscriptions = wcs_get_users_subscriptions($wp_user_id);
    foreach ($subscriptions as $subscription) {
        if (!is_a($subscription, 'WC_Subscription') || !$subscription->has_status(array('active', 'on-hold'))) {
            continue;
        }
        if (!ypf_ddr_subscription_has_program($subscription, $program_id)) {
            continue;
        }
        $sub_user_id = function_exists('ypf_get_subscription_ypf_user_id') ? ypf_get_subscription_ypf_user_id($subscription) : '';
        if (empty($sub_user_id) && !empty($order->get_billing_email()) && function_exists('ypf_unsub_get_user_by_email')) {
            $api_config = function_exists('ypf_get_api_config') ? ypf_get_api_config() : null;
            if ($api_config && !empty($api_config['api_base_url']) && !empty($api_config['api_key'])) {
                $ud = ypf_unsub_get_user_by_email($api_config['api_base_url'], $api_config['api_key'], $order->get_billing_email());
                if (!empty($ud['user_id'])) {
                    $sub_user_id = $ud['user_id'];
                }
            }
        }
        $sub_account_id = $subscription->get_meta('_ypf_account_id');
        if (empty($sub_account_id)) {
            $sub_account_id = $subscription->get_meta('ypf_account_id');
        }
        if (empty($sub_account_id)) {
            $parent_id = $subscription->get_parent_id();
            if ($parent_id) {
                $parent = wc_get_order($parent_id);
                if ($parent) {
                    $sub_account_id = $parent->get_meta('_ypf_account_id') ?: $parent->get_meta('ypf_account_id');
                }
            }
        }
        if (!empty($sub_user_id) && !empty($sub_account_id)) {
            return array('user_id' => $sub_user_id, 'account_id' => $sub_account_id);
        }
    }
    return null;
}

/**
 * Trigger API when order with DDR product is completed.
 * Hook both status_changed and payment_complete (some gateways use different flows).
 */
add_action('woocommerce_order_status_changed', 'ypf_ddr_on_order_completed', 10, 4);
add_action('woocommerce_payment_complete', 'ypf_ddr_on_payment_complete', 10, 1);

function ypf_ddr_on_payment_complete($order_id) {
    ypf_ddr_debug_log('woocommerce_payment_complete fired for order #' . $order_id, array());
    $order = wc_get_order($order_id);
    if (!$order || !is_a($order, 'WC_Order')) {
        ypf_ddr_debug_log('Order #' . $order_id . ' not found or invalid', array());
        return;
    }
    $status = $order->get_status();
    ypf_ddr_debug_log('Order #' . $order_id . ' status: ' . $status, array());
    if ($status !== 'completed' && $status !== 'processing') {
        ypf_ddr_debug_log('Order #' . $order_id . ' status not completed/processing, skip', array());
        return;
    }
    ypf_ddr_on_order_completed($order_id, '', $status, $order);
}

function ypf_ddr_on_order_completed($order_id, $old_status, $new_status, $order) {
    ypf_ddr_debug_log('ypf_ddr_on_order_completed called: order #' . $order_id . ', old=' . $old_status . ', new=' . $new_status, array());

    $run_statuses = array('completed', 'processing');
    if (!in_array($new_status, $run_statuses, true) || $old_status === $new_status) {
        ypf_ddr_debug_log('Order #' . $order_id . ' skipped: new_status not in run_statuses or old=new', array());
        return;
    }

    if (!($order instanceof WC_Order)) {
        $order = wc_get_order($order_id);
    }
    if (!$order || !is_a($order, 'WC_Order')) {
        ypf_ddr_debug_log('Order #' . $order_id . ' could not be loaded', array());
        return;
    }

    if ($order->get_meta('_ypf_ddr_api_called')) {
        ypf_ddr_debug_log('Order #' . $order_id . ' skipped: API already called (_ypf_ddr_api_called)', array());
        return;
    }

    $has_ddr = ypf_ddr_order_contains_ddr_product($order);
    $ddr_sku = ypf_ddr_get_first_ddr_sku_from_order($order);
    ypf_ddr_debug_log('Order #' . $order_id . ' contains DDR product: ' . ($has_ddr ? 'YES (SKU: ' . $ddr_sku . ')' : 'NO'), array());

    if (!$has_ddr) {
        return;
    }

    $ids = ypf_ddr_resolve_user_and_account($order);
    if (!$ids) {
        $wp_uid = $order->get_user_id();
        $email = $order->get_billing_email();
        ypf_ddr_debug_log('Order #' . $order_id . ' FAILED to resolve user/account. wp_user_id=' . $wp_uid . ', email=' . $email . ', ddr_sku=' . $ddr_sku, array(), $order);
        $order->add_order_note('YPF DDR: Could not resolve account - customer may have no active subscription matching this DDR product.');
        return;
    }
    ypf_ddr_debug_log('Order #' . $order_id . ' resolved: ypf_user_id=' . $ids['user_id'] . ', account_id=' . $ids['account_id'], array(), $order);

    $program_id = ypf_ddr_get_program_id_by_sku($ddr_sku);
    $GLOBALS['ypf_ddr_current_program_id'] = $program_id;

    $api_config = function_exists('ypf_get_api_config') ? ypf_get_api_config() : null;
    if (!$api_config || empty($api_config['api_base_url']) || empty($api_config['api_key'])) {
        ypf_ddr_debug_log('Order #' . $order_id . ' FAILED: API config missing (api_base_url or api_key empty)', array(), $order);
        return;
    }

    $initial_balance = ypf_ddr_get_initial_balance(
        $api_config['api_base_url'],
        $api_config['api_key'],
        $ids['user_id'],
        $ids['account_id']
    );

    if ($initial_balance === null || $initial_balance <= 0) {
        $api_debug = '';
        if (function_exists('ypf_get_subscription_account_rulesdetails_bqs')) {
            $r = ypf_get_subscription_account_rulesdetails_bqs($api_config['api_base_url'], $api_config['api_key'], $ids['user_id'], $ids['account_id']);
            $body = isset($r['response']['raw']) ? $r['response']['raw'] : wp_json_encode($r['response']['data'] ?? array());
            $api_debug = ' API response (first 500 chars): ' . substr($body, 0, 500);
        }
        ypf_ddr_debug_log('Order #' . $order_id . ' FAILED to get initialBalance for account ' . $ids['account_id'] . '.' . $api_debug, array(), $order);
        $order->add_order_note('YPF DDR: Could not fetch initialBalance for account. API update skipped.');
        return;
    }
    ypf_ddr_debug_log('Order #' . $order_id . ' initialBalance=' . $initial_balance, array(), $order);

    $result = ypf_ddr_call_update_api(
        $api_config['api_base_url'],
        $api_config['api_key'],
        $ids['user_id'],
        $ids['account_id'],
        $initial_balance
    );

    if ($result['status'] === 'success') {
        $order->update_meta_data('_ypf_ddr_api_called', '1');
        ypf_ddr_debug_log('Order #' . $order_id . ' API EXECUTED SUCCESS - account ' . $ids['account_id'] . ' reset to ' . $initial_balance, array(), $order);
        $order->add_order_note('YPF DDR: Account ' . $ids['account_id'] . ' balance reset to ' . number_format($initial_balance, 2) . ' via API.');
    } else {
        ypf_ddr_debug_log('Order #' . $order_id . ' API EXECUTED FAILED: ' . wp_json_encode($result), array(), $order);
        $order->add_order_note('YPF DDR: Failed to reset account - ' . ($result['message'] ?? 'Unknown error'));
    }
    $order->save();
}
