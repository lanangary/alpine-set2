<?php
/**
 * Plugin functions and definitions for Admin.
 *
 * For additional information on potential customization options,
 * read the developers' documentation:
 *
 * @package yourpropfirm
 */
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Setup single product checkout mode.
 */
function yourpropfirm_function_setup_single_product_checkout_mode() {
    $single_checkout_mode = get_option('yourpropfirm_connection_single_checkout_mode');

    if ($single_checkout_mode !== '1') {
        return;
    }

    // Disable add to cart messages.
    add_filter('wc_add_to_cart_message_html', '__return_false');

    // Empty the cart before adding a new product.
    add_filter('woocommerce_add_cart_item_data', 'yourpropfirm_function_empty_cart_before_adding_product');

    // Redirect to checkout after adding product.
    add_filter('woocommerce_add_to_cart_redirect', 'yourpropfirm_function_redirect_to_checkout');

    // Check for multiple products in the cart at checkout.
    add_action('woocommerce_before_checkout_form', 'yourpropfirm_function_check_for_multiple_products');

    // Disable order notes field.
    add_filter('woocommerce_enable_order_notes_field', '__return_false');

    // Change the order button text.
    add_filter('woocommerce_order_button_text', 'yourpropfirm_function_customize_order_button_text');

    // Modify WooCommerce billing fields.
    add_filter('woocommerce_checkout_fields', 'yourpropfirm_function_modify_billing_fields');
}
add_action('init', 'yourpropfirm_function_setup_single_product_checkout_mode');

/**
 * Empty the cart before adding a new product.
 */
function yourpropfirm_function_empty_cart_before_adding_product($cart_item_data) {
    WC()->cart->empty_cart(); // Clear the cart.
    return $cart_item_data;
}

/**
 * Redirect to checkout after adding a product to the cart.
 */
function yourpropfirm_function_redirect_to_checkout() {
    return wc_get_checkout_url();
}

/**
 * Check for multiple products in the cart and handle refresh.
 */
function yourpropfirm_function_check_for_multiple_products() {
    if (WC()->cart->get_cart_contents_count() > 1) {
        // Display error notice.
        wc_print_notice(__('Only 1 product can be checked out at a time. Please refresh the cart to keep only the last product.', 'yourpropfirm-checkout'), 'error');

        // Display refresh button.
        echo '<form method="post">';
        echo '<button type="submit" name="refresh_cart" class="button">' . __('Refresh Cart', 'yourpropfirm-checkout') . '</button>';
        echo '</form>';

        // Refresh the cart to keep only the last product.
        if (isset($_POST['refresh_cart'])) {
            yourpropfirm_function_refresh_cart_keep_last_product();
        }
    }
}

/**
 * Refresh the cart and keep only the last product.
 */
function yourpropfirm_function_refresh_cart_keep_last_product() {
    $cart_items = WC()->cart->get_cart();

    // Get the last added product key.
    $last_product_key = array_key_last($cart_items);

    // Clear the cart and re-add the last product.
    WC()->cart->empty_cart();
    $last_product = $cart_items[$last_product_key];
    WC()->cart->add_to_cart(
        $last_product['product_id'],
        $last_product['quantity'],
        $last_product['variation_id'],
        $last_product['variation'],
        $last_product['cart_item_data']
    );

    // Refresh the page.
    wp_safe_redirect(wc_get_checkout_url());
    exit;
}

/**
 * Customize the WooCommerce order button text.
 */
function yourpropfirm_function_customize_order_button_text() {
    return __('PROCEED TO PAYMENT', 'yourpropfirm-checkout');
}

/**
 * Modify billing fields.
 */
function yourpropfirm_function_modify_billing_fields($fields) {
    $fields['billing']['billing_email']['priority'] = 5;
    return $fields;
}