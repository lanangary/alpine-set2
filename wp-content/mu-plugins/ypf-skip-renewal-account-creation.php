<?php
/**
 * Plugin Name: YPF Skip Renewal Account Creation
 * Description: Skip account creation in YourPropFirm for subscription renewal orders. Only update next payment date without creating new account.
 * Version: 1.0.0
 * Author: Custom
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Skip account creation for subscription renewal orders
 * This hook runs with priority 5 (before yourpropfirm plugin which uses priority 10)
 * to mark renewal orders as completed before the plugin tries to create accounts
 */
add_action('woocommerce_order_status_changed', 'ypf_skip_renewal_account_creation', 5, 4);
function ypf_skip_renewal_account_creation($order_id, $old_status, $new_status, $order) {
    // Only process when order becomes completed
    if ($new_status !== 'completed' || $old_status === 'completed') {
        return;
    }
    
    // Get the order object if not provided
    if (!($order instanceof WC_Order)) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
    }
    
    // Check if this is a subscription renewal order
    $is_renewal_order = false;
    if (function_exists('wcs_order_contains_renewal')) {
        $is_renewal_order = wcs_order_contains_renewal($order);
    }
    
    // If not detected as renewal, check if order is related to existing subscription
    if (!$is_renewal_order && function_exists('wcs_get_subscriptions_for_order')) {
        $subscriptions = wcs_get_subscriptions_for_order($order);
        if (!empty($subscriptions)) {
            // Check if this is not the parent order (i.e., it's a renewal)
            foreach ($subscriptions as $subscription_id) {
                if (function_exists('wcs_get_subscription')) {
                    $subscription = wcs_get_subscription($subscription_id);
                    if ($subscription && is_a($subscription, 'WC_Subscription')) {
                        $parent_order_id = $subscription->get_parent_id();
                        // If this order is not the parent, it's a renewal
                        if ($parent_order_id && $parent_order_id != $order_id) {
                            $is_renewal_order = true;
                            break;
                        }
                    }
                }
            }
        }
    }
    
    // If this is a renewal order, mark as completed to skip account creation
    if ($is_renewal_order) {
        // Mark as completed to prevent yourpropfirm plugin from creating new account
        update_post_meta($order_id, '_yourpropfirm_connection_completed', 1);
        $order->update_meta_data('_yourpropfirm_connection_completed', 1);
        
        // Add order note about skipping account creation for renewal
        $renewal_note = "--YourPropFirm Status--\n";
        $renewal_note .= "Order Type: Subscription Renewal\n";
        $renewal_note .= "Action: Skipped account creation (renewal order)\n";
        $renewal_note .= "Note: Subscription next payment date will be updated automatically.\n";
        $renewal_note .= "Existing account will remain active with updated expiration.\n";
        $renewal_note .= "--End Status--\n";
        $order->add_order_note($renewal_note);
        $order->save();
        
        // Log the skip action
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $context = array('source' => 'ypf_skip_renewal_account_creation');
            $log_note = "\n--YourPropFirm Renewal Order--\n";
            $log_note .= "Order ID: " . $order_id . "\n";
            $log_note .= "Action: Skipped account creation (renewal order)\n";
            $log_note .= "--End Log--\n";
            $logger->info($log_note, $context);
        }
    }
}
