<?php
/**
 * Plugin functions and definitions for Admin.
 *
 * For additional information on potential customization options,
 * read the developers' documentation:
 *
 * @package yourpropfirm
 */
// Function to handle sending account creation request
function yourpropfirm_challenge_send_account_request($endpoint_url, $user_id, $api_key, $program_id, $competition_id, $yourpropfirm_selection_type, $mt_version, $delay, $order, $order_id, $order_currency, $products_loop_id, $site_language_value, $product_woo_id, $quantity, $profitSplit, $withdrawActiveDays, $withdrawTradingDays) {
    $invoicesId = $order_id;
    $productsId = $product_woo_id;
    $invoicesIdStr = strval($invoicesId);
    $productsIdStr = strval($productsId);
    
    // Retrieve the per-product total from the order
    $order_item = null;
    foreach ($order->get_items() as $item) {
        if ($item->get_product_id() == $productsId) {
            $order_item = $item;
            break;
        }
    }

    if ($order_item) {
        $product_total = $order_item->get_total(); // Total after discount
        $product_subtotal = $order_item->get_subtotal(); // Subtotal before discount
    } else {
        $product_total = 0;
        $product_subtotal = 0;
    }

    $product_fee_total = 0;

    // Calculate fees for the specific product if there are any
    foreach ($order->get_fees() as $fee) {
        if ($fee->get_product_id() == $product_woo_id) {
            $product_fee_total += $fee->get_total();
        }
    }

    // Combine the total for the specific product, including fees
    $order_total_per_product = $product_total + $product_fee_total;

    $api_data_account = array(
        'mtVersion' => $mt_version,
        'programId' => $program_id,
        'InvoiceId' => $invoicesIdStr,
        'ProductId' => $productsIdStr,
        'currency' => $order_currency, // Add currency for the product
        'income' => $order_total_per_product, // Add income (total per product including fees and discounts)
    );

    $addOns = array();
    
    // Add profitSplit to addOns if it is not zero
    if ($profitSplit !== 0 && $profitSplit !== '0') {
        $addOns['profitSplit'] = intval($profitSplit);
    }

    // Add withdrawActiveDays to addOns if it is not zero
    if ($withdrawActiveDays !== 0 && $withdrawActiveDays !== '0') {
        $addOns['withdrawActiveDays'] = intval($withdrawActiveDays);
    }

    // Add withdrawTradingDays to addOns if it is not zero
    if ($withdrawTradingDays !== 0 && $withdrawTradingDays !== '0') {
        $addOns['withdrawTradingDays'] = intval($withdrawTradingDays);
    }

    // Add addOns to api_data_account if there are any entries
    if (!empty($addOns)) {
        $api_data_account['addOns'] = $addOns;
    }

    // Construct the full endpoint URL for the API request
    $endpoint_url_full = $endpoint_url . '/' . urlencode($user_id) . '/accounts';
    // Send API request using your custom function
    $response = yourpropfirm_send_wp_remote_post_request($endpoint_url_full, $api_key, $api_data_account, $delay);
    
    $http_status = $response['http_status'];
    $api_response = $response['api_response'];

    // Handle API response errors using your custom error handling function
    yourpropfirm_handle_api_response_error($order, $http_status, $api_response, $order_id, $yourpropfirm_selection_type, $program_id, $competition_id, $products_loop_id, $mt_version, $site_language_value, $order_currency, $order_total_per_product, $product_woo_id, $quantity, $user_id, $profitSplit, $withdrawActiveDays, $withdrawTradingDays);
}


function yourpropfirm_get_challenge_api_data($order, $order_id, $product_woo_id, $program_id_value, $mt_version_value, $site_language_value, $order_currency, $order_total, $profitSplit, $withdrawActiveDays, $withdrawTradingDays) {
    $invoicesId = $order->get_id();
    $productsId = $product_woo_id;
    $invoicesIdStr = strval($invoicesId);
    $productsIdStr = strval($productsId);
    $user_email = $order->get_billing_email();
    $user_first_name = $order->get_billing_first_name();
    $user_last_name = $order->get_billing_last_name();
    $user_address = $order->get_billing_address_1();
    $user_city = $order->get_billing_city();
    $user_zip_code = $order->get_billing_postcode();
    $user_country = $order->get_billing_country();
    $user_phone = $order->get_billing_phone();
    $order_total_val = floatval($order_total);

    // Retrieve the per-product total from the order
    $order_item = null;
    foreach ($order->get_items() as $item) {
        if ($item->get_product_id() == $productsId) {
            $order_item = $item;
            break;
        }
    }

    if ($order_item) {
        $product_total = $order_item->get_total(); // Total after discount
        $product_subtotal = $order_item->get_subtotal(); // Subtotal before discount
    } else {
        $product_total = 0;
        $product_subtotal = 0;
    }

    // Sum all fees (fees are general, not per product)
    $product_fee_total = 0;
    foreach ($order->get_fees() as $fee) {
        $product_fee_total += $fee->get_total();
    }

    // Combine the total for the specific product, including fees
    $order_total_per_product = $product_total + $product_fee_total;

    $data = array(
        'email' => $user_email,
        'firstname' => $user_first_name,
        'lastname' => $user_last_name,
        'programId' => $program_id_value,
        'mtVersion' => $mt_version_value,
        'addressLine' => $user_address,
        'city' => $user_city,
        'zipCode' => $user_zip_code,
        'country' => $user_country,
        'phone' => $user_phone,
        'language' => $site_language_value,
        'currency' => $order_currency,
        'income' => $order_total_per_product,
        'invoiceId' => $invoicesIdStr,
        'productId' => $productsIdStr
    );

    $addOns = array();

    if ($profitSplit !== 0 && $profitSplit !== '0') {
        $addOns['profitSplit'] = intval($profitSplit);
    }

    if ($withdrawActiveDays !== 0 && $withdrawActiveDays !== '0') {
        $addOns['withdrawActiveDays'] = intval($withdrawActiveDays);
    }

    if ($withdrawTradingDays !== 0 && $withdrawTradingDays !== '0') {
        $addOns['withdrawTradingDays'] = intval($withdrawTradingDays);
    }

    if (!empty($addOns)) {
        $data['addOns'] = $addOns;
    } else {
        if (isset($data['addOns'])) {
            unset($data['addOns']);
        }
    }

    return $data;
}


function yourpropfirm_get_competition_api_data($order, $order_id, $product_woo_id, $mt_version_value, $site_language_value, $order_currency, $order_total, $profitSplit, $withdrawActiveDays, $withdrawTradingDays) {  
    // Get order and user data
    $invoiceId = $order->get_id();  // Invoice ID
    $productId = $product_woo_id;   // Product ID
    $invoiceIdStr = strval($invoiceId);
    $user_email = $order->get_billing_email();
    $user_first_name = $order->get_billing_first_name();
    $user_last_name = $order->get_billing_last_name();
    $user_address = $order->get_billing_address_1();
    $user_city = $order->get_billing_city();
    $user_zip_code = $order->get_billing_postcode();
    $user_country = $order->get_billing_country();
    $user_phone = $order->get_billing_phone();
    $order_total_val = floatval($order_total);

    // Prepare the main data array
    $data = array(
        'email' => $user_email,
        'firstname' => $user_first_name,
        'lastname' => $user_last_name,
        'roleIds' => array('Challenge'),  // Only "Challenge" role users can join competition
        'language' => $site_language_value,
        'mtVersion' => $mt_version_value,
        'invoiceId' => $invoiceIdStr,
        'productId' => strval($productId),
        'currency' => $order_currency,
        'income' => $order_total_val,
        'attributes' => array(  // Attributes like address, city, country, etc.
            'addressLine' => $user_address,
            'city' => $user_city,
            'country' => $user_country,
            'phone' => $user_phone,
            'zipCode' => $user_zip_code
        )
    );

    // Initialize addOns array
    $addOns = array();

    // Add profitSplit if it is not 0 or '0'
    if ($profitSplit !== 0 && $profitSplit !== '0') {
        $addOns['profitSplit'] = floatval($profitSplit);
    }

    // Add withdrawActiveDays if it is not 0 or '0'
    if ($withdrawActiveDays !== 0 && $withdrawActiveDays !== '0') {
        $addOns['withdrawActiveDays'] = intval($withdrawActiveDays);
    }

    // Add withdrawTradingDays if it is not 0 or '0'
    if ($withdrawTradingDays !== 0 && $withdrawTradingDays !== '0') {
        $addOns['withdrawTradingDays'] = intval($withdrawTradingDays);
    }

    // Only add addOns to the main data array if it's not empty
    if (!empty($addOns)) {
        $data['addOns'] = $addOns;
    }

    // Return the final data array
    return $data;
}

function yourpropfirm_handle_api_response_error($order, $http_status, $api_response, $order_id, $yourpropfirm_selection_type, $program_id_value, $competition_id, $products_loop_id, $mt_version_value, $site_language_value, $order_currency, $order_total, $product_woo_id, $quantity, $user_id, $profitSplit, $withdrawActiveDays, $withdrawTradingDays) {
    global $woocommerce;
    // Ensure the $order object is a valid WC_Order object
    if (!($order instanceof WC_Order)) {
        $order = wc_get_order($order_id);
        if (!$order) return; // Exit if the order cannot be retrieved
    }

    $log_data = yourpropfirm_connection_response_logger();
    $site_language = get_locale();
    
    // Check if WooCommerce is active and the function exists
    if (function_exists('get_woocommerce_currency')) {
        $defaultcurrency = get_woocommerce_currency();
    }

    // Get the currency from the order
    $order_currency_value = $order->get_currency();

    $enable_response_header = get_option('yourpropfirm_connection_enable_response_header'); 

    if (empty($yourpropfirm_selection_type)) {
        $yourpropfirm_selection_type = 'challenge';
    }

    $error_message = 'An error occurred while creating the user. Error Type Unknown.';
    if ($http_status == 201) {
        $error_message = 'success created account.!!';
    } elseif ($http_status == 400) {
        $error_message = isset($api_response['error']) ? $api_response['error'] : 'An error occurred while creating the user. Error Type 400.';
    } elseif ($http_status == 409) {
        $error_message = isset($api_response['error']) ? $api_response['error'] : 'An error occurred while creating the user. Error Type 409.';
    } elseif ($http_status == 500) {
        $error_message = isset($api_response['error']) ? $api_response['error'] : 'An error occurred while creating the user. Error Type 500.';
    } else {
        $error_message = isset($api_response['error']) ? $api_response['error'] : 'An error occurred while creating the user. Error Response : Unknown Error Code.!!';
    }

    $api_response_note = $error_message ." Code : ".$http_status;
    $api_response_logs = $error_message ." Code : ".$http_status ." Message : ".$api_response ;

    // Combine all API responses into one note
    $combined_notes = "--YourPropfirm--\n";
    $combined_notes .= "YPF Type: " . $yourpropfirm_selection_type . "\n";
    $combined_notes .= "Response Loop: " . $products_loop_id . "\n";    
    $combined_notes .= "InvoiceId: " . $order_id . "\n";  
    $combined_notes .= "ProductId: " . $product_woo_id . "\n"; 
    $combined_notes .= "Quantity: " . $quantity . "\n";
    $combined_notes .= "Language: " . $site_language_value . "\n";
    $combined_notes .= "Currency: " . $order_currency . "\n";
    $combined_notes .= "Order Total: " . $order_total . "\n";
    $combined_notes .= "HTTP Response: " . $http_status . "\n";
    if ($yourpropfirm_selection_type === 'challenge') {
        // Append ProgramID to notes if the selection type is 'challenge'
        $combined_notes .= "YPF User ID: " . $user_id . "\n";
    }     
    if ($yourpropfirm_selection_type === 'challenge') {
        // Append ProgramID to notes if the selection type is 'challenge'
        $combined_notes .= "ProgramID: " . $program_id_value . "\n";
    } elseif ($yourpropfirm_selection_type === 'competition') {
        // Append CompetitionID to notes if the selection type is 'competition'
        $combined_notes .= "CompetitionID: " . $competition_id . "\n";
    }
    $combined_notes .= "MTVersion: " . $mt_version_value . "\n";

    // Adding the addOns in the note if applicable
    if ($profitSplit !== 0 && $profitSplit !== '0' || 
        $withdrawActiveDays !== 0 && $withdrawActiveDays !== '0' || 
        $withdrawTradingDays !== 0 && $withdrawTradingDays !== '0') {
        
        $combined_notes .= "addOns:\n";
        
        if ($profitSplit !== 0 && $profitSplit !== '0') {
            $combined_notes .= "- profitSplit: " . $profitSplit . "\n";
        }
        
        if ($withdrawActiveDays !== 0 && $withdrawActiveDays !== '0') {
            $combined_notes .= "- withdrawActiveDays: " . $withdrawActiveDays . "\n";
        }
        
        if ($withdrawTradingDays !== 0 && $withdrawTradingDays !== '0') {
            $combined_notes .= "- withdrawTradingDays: " . $withdrawTradingDays . "\n";
        }
    }

    $combined_notes .= "Response: " . $api_response_note . "\n";
    $combined_notes .= "--End Response--\n";


    // Combine all API responses for Log WC-Logger
    $combined_note_logs = "\n";
    $combined_note_logs .= "--Begin YPF Response-- | " . $yourpropfirm_selection_type . "\n";
    $combined_note_logs .= "Response Loop: " . $products_loop_id . "\n";
    $combined_note_logs .= "YPF API Response: " . $api_response_logs . "\n";
    $combined_note_logs .= "--End Response--\n";


    // Add the combined note
    wc_create_order_note($order_id, $combined_notes, $added_by_user = false, $customer_note = false);
    $order->save(); // Don't forget to save the order to store these meta data
    $log_data['logger']->info($combined_note_logs,  $log_data['context']);
}

function yourpropfirm_selection_type_response_error($order, $order_id, $products_loop_id, $error_type){
    global $woocommerce;
    // Ensure the $order object is a valid WC_Order object
    if (!($order instanceof WC_Order)) {
        $order = wc_get_order($order_id);
        if (!$order) return; // Exit if the order cannot be retrieved
    }
    $log_data = yourpropfirm_connection_response_logger();

    if ( $error_type === 'endpoint-error') {
        // Combine all API responses into one note
        $combined_notes = "--Error YourPropfirm--\n";
        $combined_note  .= "Response Loop: " . $products_loop_id . "\n";
        $combined_notes .= "InvoiceId: " . $order_id . "\n";  
        $combined_notes .= "Error 1001: Empty Endpoint Url, Please enable YPF Challenge or Competition Feature and Set the YPF ID on Each Products Woocommerce.\n";
        $combined_notes .= "--End Response--\n";


        // Combine all API responses for Log WC-Logger
        $combined_note_logs = "\n";
        $combined_note_logs .= "--Begin YPF Response--\n";
        $combined_note_logs .= "Response Loop: " . $products_loop_id . "\n";
        $combined_note_logs .= "InvoiceId: " . $order_id . "\n";
        $combined_note_logs .= "Error 1001: Empty Endpoint Url, Please enable YPF Challenge or Competition Feature and Set the YPF ID on Each Products Woocommerce.\n";
        $combined_note_logs .= "--End Response--\n";
    } elseif ($error_type === 'data-error'){
        // Combine all API responses into one note
        $combined_notes = "--Error YourPropfirm--\n";
        $combined_note  .= "Response Loop: " . $products_loop_id . "\n";
        $combined_notes .= "InvoiceId: " . $order_id . "\n";  
        $combined_notes .= "Error 1001: Empty API Data, Please enable YPF Challenge or Competition Feature and Set the YPF ID on Each Products Woocommerce.\n";
        $combined_notes .= "--End Response--\n";


        // Combine all API responses for Log WC-Logger
        $combined_note_logs = "\n";
        $combined_note_logs .= "--Begin YPF Response--\n";
        $combined_note_logs .= "Response Loop: " . $products_loop_id . "\n";
        $combined_note_logs .= "InvoiceId: " . $order_id . "\n";
        $combined_note_logs .= "Error 1001: Empty API Data, Please enable YPF Challenge or Competition Feature and Set the YPF ID on Each Products Woocommerce.\n";
        $combined_note_logs .= "--End Response--\n";
    }


    // Add the combined note
    wc_create_order_note($order_id, $combined_notes, $added_by_user = false, $customer_note = false);
    $order->save(); // Don't forget to save the order to store these meta data
    $log_data['logger']->info($combined_note_logs,  $log_data['context']);

}

function yourpropfirm_send_wp_remote_post_request($endpoint_url, $api_key, $api_data, $request_delay=0) {
    $api_url = $endpoint_url;
    $headers = array(
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
        'X-Client-Key' => $api_key
    );

    $response = wp_remote_post(
        $api_url,
        array(
            'timeout' => 30,
            'redirection' => 5,
            'headers' => $headers,            
            'body' => json_encode($api_data)
        )
    );

    $http_status = wp_remote_retrieve_response_code($response);
    $api_response = wp_remote_retrieve_body($response);

    // Delay execution if $delay is greater than 0
    if ($request_delay > 0) {
        usleep($request_delay * 1000000); // Delay in micro seconds
    }

    return array(
        'http_status' => $http_status,
        'api_response' => $api_response
    );
}