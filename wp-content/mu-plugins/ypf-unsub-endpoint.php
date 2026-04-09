<?php
/**
 * Plugin Name: YPF Account Management Endpoints
 * Description: Custom endpoints for YPF account breach and reset via /unsub and /reset
 * Version: 2.0.0
 * Author: Alpine Funded Dev Team
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register custom /unsub endpoint
 */
add_action('init', 'ypf_unsub_register_endpoint');
function ypf_unsub_register_endpoint() {
    add_rewrite_rule('^unsub/?$', 'index.php?ypf_unsub=1', 'top');
    add_rewrite_rule('^reset/?$', 'index.php?ypf_reset=1', 'top');
    add_rewrite_rule('^accounts/?$', 'index.php?ypf_accounts=1', 'top');
    add_rewrite_rule('^program-delete/?$', 'index.php?ypf_program_delete=1', 'top');
    add_rewrite_rule('^account-details/?$', 'index.php?ypf_account_details=1', 'top');
}

/**
 * Register AJAX endpoint for breach (alternative to rewrite rule)
 */
add_action('wp_ajax_ypf_breach_account', 'ypf_ajax_breach_account');
add_action('wp_ajax_nopriv_ypf_breach_account', 'ypf_ajax_breach_account');
function ypf_ajax_breach_account() {
    // Get parameters from both GET and POST
    $email = isset($_REQUEST['email']) ? sanitize_email($_REQUEST['email']) : '';
    $user_id = isset($_REQUEST['user_id']) ? sanitize_text_field($_REQUEST['user_id']) : '';
    $account_id = isset($_REQUEST['account_id']) ? sanitize_text_field($_REQUEST['account_id']) : '';
    
    if (empty($email) && empty($user_id)) {
        wp_send_json_error([
            'status' => 'error',
            'message' => 'Please provide email or user_id parameter'
        ], 400);
        return;
    }
    
    // Validate email format if provided
    if (!empty($email) && !is_email($email)) {
        wp_send_json_error([
            'status' => 'error',
            'message' => 'Invalid email format provided'
        ], 400);
        return;
    }
    
    try {
        // Get API configuration
        $plugin_env = get_option('yourpropfirm_connection_environment');
        
        if ($plugin_env === 'sandbox') {
            $api_base_url = esc_attr(get_option('yourpropfirm_connection_sandbox_endpoint_url'));
            $api_key = esc_attr(get_option('yourpropfirm_connection_sandbox_test_key'));
        } else {
            $api_base_url = esc_attr(get_option('yourpropfirm_connection_endpoint_url'));
            $api_key = esc_attr(get_option('yourpropfirm_connection_api_key'));
        }
        
        // Fallback to provided credentials if options not set
        if (empty($api_base_url)) {
            $api_base_url = 'https://api.ypf.customers.sigma-ventures.cloud';
        }
        if (empty($api_key)) {
            $api_key = '8bc0jjc6ss084e54kk7d45ea87ckl089129311c0657d42c696952bjj395f56d7';
        }
        
        // Perform breach action
        $result = ypf_perform_account_action($api_base_url, $api_key, $email, '', $user_id, $account_id, 'breach');
        
        // Format response for AJAX
        if (isset($result['response']) && isset($result['status_code'])) {
            $response_data = [
                'timestamp' => current_time('Y-m-d H:i:s'),
                'status_code' => $result['status_code'],
                'data' => $result['response']
            ];
            
            if ($result['status_code'] >= 200 && $result['status_code'] < 300) {
                wp_send_json_success($response_data, $result['status_code']);
            } else {
                wp_send_json_error($response_data, $result['status_code']);
            }
        } else {
            wp_send_json_error([
                'status' => 'error',
                'message' => 'Unexpected response format from breach function'
            ], 500);
        }
        
    } catch (Exception $e) {
        wp_send_json_error([
            'status' => 'error',
            'message' => 'An error occurred: ' . $e->getMessage(),
            'error_type' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ], 500);
    } catch (Error $e) {
        wp_send_json_error([
            'status' => 'error',
            'message' => 'A fatal error occurred: ' . $e->getMessage(),
            'error_type' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ], 500);
    }
}

/**
 * Add query var for our endpoint
 */
add_filter('query_vars', 'ypf_unsub_query_vars');
function ypf_unsub_query_vars($vars) {
    $vars[] = 'ypf_unsub';
    $vars[] = 'ypf_reset';
    $vars[] = 'ypf_accounts';
    $vars[] = 'ypf_program_delete';
    $vars[] = 'ypf_account_details';
    return $vars;
}

/**
 * Handle the /unsub request (breach accounts)
 */
add_action('template_redirect', 'ypf_unsub_handle_request');
function ypf_unsub_handle_request() {
    global $wp_query;
    
    if (!isset($wp_query->query_vars['ypf_unsub'])) {
        return;
    }
    
    try {
        ypf_handle_account_action('breach');
    } catch (Exception $e) {
        $response = [
            'status' => 'error',
            'message' => 'An error occurred: ' . $e->getMessage(),
            'error_type' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ];
        ypf_unsub_send_response($response, 500);
    } catch (Error $e) {
        $response = [
            'status' => 'error',
            'message' => 'A fatal error occurred: ' . $e->getMessage(),
            'error_type' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ];
        ypf_unsub_send_response($response, 500);
    }
}

/**
 * Handle the /reset request (reset accounts)
 */
add_action('template_redirect', 'ypf_reset_handle_request');
function ypf_reset_handle_request() {
    global $wp_query;
    
    if (!isset($wp_query->query_vars['ypf_reset'])) {
        return;
    }
    
    ypf_handle_account_action('reset');
}

/**
 * Handle the /accounts request (list accounts)
 */
add_action('template_redirect', 'ypf_accounts_handle_request');
function ypf_accounts_handle_request() {
    global $wp_query;

    if (!isset($wp_query->query_vars['ypf_accounts'])) {
        return;
    }

    $email = isset($_GET['email']) ? sanitize_email($_GET['email']) : '';
    $user_id = isset($_GET['user_id']) ? sanitize_text_field($_GET['user_id']) : '';

    if (empty($email) && empty($user_id)) {
        $response = [
            'status' => 'error',
            'message' => 'Please provide email or user_id parameter',
            'usage_examples' => [
                'By user ID:' => 'https://alpine.test/accounts?user_id=C52C43B910EEE83EA18CA3E37BA796DB',
                'By email:' => 'https://alpine.test/accounts?email=someone@example.com'
            ]
        ];
        ypf_unsub_send_response($response, 400);
        return;
    }

    $plugin_env = get_option('yourpropfirm_connection_environment');

    if ($plugin_env === 'sandbox') {
        $api_base_url = esc_attr(get_option('yourpropfirm_connection_sandbox_endpoint_url'));
        $api_key = esc_attr(get_option('yourpropfirm_connection_sandbox_test_key'));
    } else {
        $api_base_url = esc_attr(get_option('yourpropfirm_connection_endpoint_url'));
        $api_key = esc_attr(get_option('yourpropfirm_connection_api_key'));
    }

    if (empty($api_base_url)) {
        $api_base_url = 'https://api.ypf.customers.sigma-ventures.cloud';
    }
    if (empty($api_key)) {
        $api_key = '8bc0jjc6ss084e54kk7d45ea87ckl089129311c0657d42c696952bjj395f56d7';
    }

    // Resolve user_id from email if needed
    if (!empty($email) && empty($user_id)) {
        $user_data = ypf_unsub_get_user_by_email($api_base_url, $api_key, $email);
        if ($user_data['status_code'] !== 200) {
            ypf_unsub_send_response($user_data['response'], $user_data['status_code']);
            return;
        }
        $user_id = $user_data['user_id'];
    }

    $result = ypf_get_user_accounts($api_base_url, $api_key, $user_id);
    ypf_unsub_send_response($result['response'], $result['status_code']);
}

/**
 * Handle the /program-delete request (delete a specific program)
 */
add_action('template_redirect', 'ypf_program_delete_handle_request');
function ypf_program_delete_handle_request() {
    global $wp_query;

    if (!isset($wp_query->query_vars['ypf_program_delete'])) {
        return;
    }

    $program_id = isset($_GET['program_id']) ? sanitize_text_field($_GET['program_id']) : '';

    if (empty($program_id)) {
        $response = [
            'status' => 'error',
            'message' => 'Please provide program_id parameter',
            'usage_examples' => [
                'Delete program:' => 'https://alpine.test/program-delete?program_id=PROGRAM_ID'
            ]
        ];
        ypf_unsub_send_response($response, 400);
        return;
    }

    $plugin_env = get_option('yourpropfirm_connection_environment');

    if ($plugin_env === 'sandbox') {
        $api_base_url = esc_attr(get_option('yourpropfirm_connection_sandbox_endpoint_url'));
        $api_key = esc_attr(get_option('yourpropfirm_connection_sandbox_test_key'));
    } else {
        $api_base_url = esc_attr(get_option('yourpropfirm_connection_endpoint_url'));
        $api_key = esc_attr(get_option('yourpropfirm_connection_api_key'));
    }

    if (empty($api_base_url)) {
        $api_base_url = 'https://api.ypf.customers.sigma-ventures.cloud';
    }
    if (empty($api_key)) {
        $api_key = '8bc0jjc6ss084e54kk7d45ea87ckl089129311c0657d42c696952bjj395f56d7';
    }

    $result = ypf_delete_program($api_base_url, $api_key, $program_id);
    ypf_unsub_send_response($result['response'], $result['status_code']);
}

/**
 * Generic account action handler (breach or reset)
 */
function ypf_handle_account_action($action = 'breach') {
    // Get parameters
    $email = isset($_GET['email']) ? sanitize_email($_GET['email']) : '';
    $subscription_id = isset($_GET['subscription_id']) ? sanitize_text_field($_GET['subscription_id']) : '';
    $user_id = isset($_GET['user_id']) ? sanitize_text_field($_GET['user_id']) : '';
    $account_id = isset($_GET['account_id']) ? sanitize_text_field($_GET['account_id']) : '';
    
    // Response array
    $response = [
        'status' => 'error',
        'message' => '',
        'data' => []
    ];
    
    // Validate input
    if (empty($email) && empty($subscription_id) && empty($user_id)) {
        $response['message'] = 'Please provide email, subscription_id, or user_id parameter';
        ypf_unsub_send_response($response, 400);
        return;
    }
    
    // Validate email format if provided
    if (!empty($email) && !is_email($email)) {
        $response['message'] = 'Invalid email format provided';
        ypf_unsub_send_response($response, 400);
        return;
    }
    
    // YPF API Configuration - Read from WordPress options
    $plugin_env = get_option('yourpropfirm_connection_environment');
    
    if ($plugin_env === 'sandbox') {
        $api_base_url = esc_attr(get_option('yourpropfirm_connection_sandbox_endpoint_url'));
        $api_key = esc_attr(get_option('yourpropfirm_connection_sandbox_test_key'));
    } else {
        $api_base_url = esc_attr(get_option('yourpropfirm_connection_endpoint_url'));
        $api_key = esc_attr(get_option('yourpropfirm_connection_api_key'));
    }
    
    // Fallback to provided credentials if options not set
    if (empty($api_base_url)) {
        $api_base_url = 'https://api.ypf.customers.sigma-ventures.cloud';
    }
    if (empty($api_key)) {
        $api_key = '8bc0jjc6ss084e54kk7d45ea87ckl089129311c0657d42c696952bjj395f56d7';
    }
    
    // Debug info
    $debug_info = [
        'action' => $action,
        'api_url' => $api_base_url,
        'api_key_set' => !empty($api_key),
        'environment' => $plugin_env ?: 'live (default)',
        'input' => [
            'email' => $email,
            'user_id' => $user_id,
            'account_id' => $account_id
        ]
    ];

    
    // Try to perform the action
    $result = ypf_perform_account_action($api_base_url, $api_key, $email, $subscription_id, $user_id, $account_id, $action);
    
    // Add debug info to response
    if (isset($result['response'])) {
        $result['response']['debug'] = $debug_info;
    }
    
    ypf_unsub_send_response($result['response'], $result['status_code']);
}

/**
 * Perform account action (breach or reset)
 */
function ypf_perform_account_action($api_base_url, $api_key, $email = '', $subscription_id = '', $user_id = '', $account_id = '', $action = 'breach') {
    $related_order_ids = [];
    
    // Step 1: Try to get YPF user ID and related order IDs from subscription if subscription_id is provided
    if (!empty($subscription_id) && empty($user_id) && empty($email)) {
        if (function_exists('wcs_get_subscription')) {
            $subscription = wcs_get_subscription($subscription_id);
            if ($subscription && is_a($subscription, 'WC_Subscription')) {
                // Get email from subscription
                $email = $subscription->get_billing_email();
                
                // Get all related order IDs (parent + renewal orders)
                $parent_order_id = $subscription->get_parent_id();
                if ($parent_order_id) {
                    $related_order_ids[] = $parent_order_id;
                }
                
                // Get all renewal orders
                $renewal_orders = $subscription->get_related_orders('ids');
                if (!empty($renewal_orders)) {
                    $related_order_ids = array_merge($related_order_ids, $renewal_orders);
                }
                
                // Remove duplicates
                $related_order_ids = array_unique($related_order_ids);
                
                // Try to get YPF user ID from subscription meta
                $ypf_user_id = $subscription->get_meta('_ypf_user_id');
                if (empty($ypf_user_id)) {
                    $ypf_user_id = $subscription->get_meta('ypf_user_id');
                }
                if (empty($ypf_user_id)) {
                    $ypf_user_id = $subscription->get_meta('_yourpropfirm_user_id');
                }
                
                // If not found, try parent order
                if (empty($ypf_user_id) && $parent_order_id) {
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
                
                // If YPF user ID found, use it directly
                if (!empty($ypf_user_id)) {
                    $user_id = $ypf_user_id;
                }
            }
        }
    }
    
    // Step 2: Get YPF user ID from API if we have email but no user_id
    if (!empty($email) && empty($user_id)) {
        $user_data = ypf_unsub_get_user_by_email($api_base_url, $api_key, $email);
        if ($user_data['status_code'] !== 200) {
            return $user_data;
        }
        $user_id = $user_data['user_id'];
    }
    
    // Step 3: If we have specific account_id, target that account only
    if (!empty($account_id) && !empty($user_id)) {
        if ($action === 'reset') {
            return ypf_reset_single_account_wrapper($api_base_url, $api_key, $user_id, $account_id);
        } else {
            return ypf_breach_single_account_wrapper($api_base_url, $api_key, $user_id, $account_id);
        }
    }
    
    // Step 4: Get user accounts and perform action on accounts matching subscription
    if (!empty($user_id)) {
        if ($action === 'reset') {
            // Use specific reset if order IDs provided, otherwise reset all
            if (!empty($related_order_ids)) {
                return ypf_get_and_reset_specific_accounts($api_base_url, $api_key, $user_id, $related_order_ids);
            } else {
                return ypf_get_and_reset_all_accounts($api_base_url, $api_key, $user_id);
            }
        } else {
            // Use specific breach if order IDs provided, otherwise breach all
            if (!empty($related_order_ids)) {
                return ypf_unsub_get_and_breach_specific_accounts($api_base_url, $api_key, $user_id, $related_order_ids);
            } else {
                return ypf_unsub_get_and_breach_all_accounts($api_base_url, $api_key, $user_id);
            }
        }
    }
    
    // No valid input
    $response = [
        'status' => 'error',
        'message' => 'Please provide email, user_id, or user_id + account_id parameters',
        'usage_examples' => [
            'By email:' => 'https://alpine.test/' . ($action === 'reset' ? 'reset' : 'unsub') . '?email=sony@juicebox.co.id',
            'By user ID:' => 'https://alpine.test/' . ($action === 'reset' ? 'reset' : 'unsub') . '?user_id=C6577B9895F085F369956436A1DCBF96',
            'Specific account:' => 'https://alpine.test/' . ($action === 'reset' ? 'reset' : 'unsub') . '?user_id=C6577B9895F085F369956436A1DCBF96&account_id=ACCOUNT_ID'
        ]
    ];
    
    return [
        'response' => $response,
        'status_code' => 400
    ];
}

/**
 * Get user by email
 */
function ypf_unsub_get_user_by_email($api_base_url, $api_key, $email) {
    $endpoint = $api_base_url . '/client/v1/users?email=' . urlencode($email);
    
    $args = [
        'headers' => [
            'X-Client-Key' => $api_key,
            'Content-Type' => 'application/json'
        ],
        'timeout' => 30
    ];
    
    $wp_response = wp_remote_get($endpoint, $args);
    
    if (is_wp_error($wp_response)) {
        return [
            'response' => [
                'status' => 'error',
                'message' => 'Failed to fetch user: ' . $wp_response->get_error_message()
            ],
            'status_code' => 500
        ];
    }
    
    $status_code = wp_remote_retrieve_response_code($wp_response);
    $body = json_decode(wp_remote_retrieve_body($wp_response), true);
    
    if ($status_code !== 200 || empty($body['results'])) {
        return [
            'response' => [
                'status' => 'error',
                'message' => 'User not found with email: ' . $email,
                'api_response' => $body
            ],
            'status_code' => 404
        ];
    }
    
    return [
        'status_code' => 200,
        'user_id' => $body['results'][0]['id'],
        'user_data' => $body['results'][0]
    ];
}

/**
 * Verify SSO token via YPF API (verifytoken endpoint)
 * Used for dashboard SSO login.
 *
 * @param string $api_base_url YPF API base URL
 * @param string $api_key X-Client-Key value
 * @param string $token Token to verify (from URL or request)
 * @return array { 'status_code' => int, 'valid' => bool, 'user_id' => string|null, 'email' => string|null, 'response' => array }
 */
function ypf_verify_token($api_base_url, $api_key, $token) {
    $verify_url = rtrim($api_base_url, '/') . '/client/v1/verifytoken';
    $url = add_query_arg('token', $token, $verify_url);

    $args = [
        'headers' => [
            'X-Client-Key' => $api_key,
            'Content-Type' => 'application/json',
        ],
        'timeout' => 30,
    ];

    $wp_response = wp_remote_get($url, $args);

    if (is_wp_error($wp_response)) {
        return [
            'status_code' => 500,
            'valid' => false,
            'user_id' => null,
            'email' => null,
            'response' => [
                'status' => 'error',
                'message' => $wp_response->get_error_message(),
            ],
        ];
    }

    $status_code = (int) wp_remote_retrieve_response_code($wp_response);
    $body_raw = wp_remote_retrieve_body($wp_response);
    $body = json_decode($body_raw, true);
    if (!is_array($body)) {
        $body = [];
    }

    $valid = $status_code >= 200 && $status_code < 300;
    $user_id = null;
    $email = null;

    if ($valid) {
        $user_id = isset($body['id']) ? (string) $body['id'] : (isset($body['user_id']) ? (string) $body['user_id'] : null);
        $email = isset($body['email']) ? sanitize_email($body['email']) : (isset($body['emailAddress']) ? sanitize_email($body['emailAddress']) : null);
        if (empty($email) && !empty($body['results'][0]['email'])) {
            $email = sanitize_email($body['results'][0]['email']);
        }
        if (empty($user_id) && !empty($body['results'][0]['id'])) {
            $user_id = (string) $body['results'][0]['id'];
        }
    }

    return [
        'status_code' => $status_code,
        'valid' => $valid,
        'user_id' => $user_id,
        'email' => $email,
        'response' => $body,
    ];
}

/**
 * Get all accounts for a user and breach all active ones
 * This breaches ALL active accounts belonging to the user (no subscription filter)
 * 
 * @param string $api_base_url YPF API base URL
 * @param string $api_key YPF API key
 * @param string $user_id YPF user ID
 * @return array Response with breach results
 */
function ypf_unsub_get_and_breach_all_accounts($api_base_url, $api_key, $user_id) {
    return ypf_unsub_get_and_breach_accounts_internal($api_base_url, $api_key, $user_id, []);
}

/**
 * Get accounts for a user and breach only those matching subscription/order IDs
 * This breaches ONLY accounts that belong to the specific subscription (filtered by invoiceId)
 * 
 * @param string $api_base_url YPF API base URL
 * @param string $api_key YPF API key
 * @param string $user_id YPF user ID
 * @param array $related_order_ids Array of order IDs to filter accounts by invoiceId
 * @return array Response with breach results
 */
function ypf_unsub_get_and_breach_specific_accounts($api_base_url, $api_key, $user_id, $related_order_ids) {
    if (empty($related_order_ids)) {
        // Fallback to all if no order IDs provided
        return ypf_unsub_get_and_breach_all_accounts($api_base_url, $api_key, $user_id);
    }
    return ypf_unsub_get_and_breach_accounts_internal($api_base_url, $api_key, $user_id, $related_order_ids);
}

/**
 * Internal function to get accounts for a user and breach them
 * Can breach all accounts or filter by subscription/order IDs
 * 
 * @param string $api_base_url YPF API base URL
 * @param string $api_key YPF API key
 * @param string $user_id YPF user ID (single user, not all users)
 * @param array $related_order_ids Optional array of order IDs to filter accounts by invoiceId
 *                                  If empty, breaches all active accounts for the user
 *                                  If provided, breaches only accounts matching those order IDs
 * @return array Response with breach results
 */
function ypf_unsub_get_and_breach_accounts_internal($api_base_url, $api_key, $user_id, $related_order_ids = []) {
    // Get user accounts
    $endpoint = $api_base_url . '/client/v1/users/' . $user_id . '/accounts';
    
    $args = [
        'headers' => [
            'X-Client-Key' => $api_key,
            'Content-Type' => 'application/json'
        ],
        'timeout' => 30
    ];
    
    $wp_response = wp_remote_get($endpoint, $args);
    
    if (is_wp_error($wp_response)) {
        return [
            'response' => [
                'status' => 'error',
                'message' => 'Failed to fetch accounts: ' . $wp_response->get_error_message()
            ],
            'status_code' => 500
        ];
    }
    
    $status_code = wp_remote_retrieve_response_code($wp_response);
    $body_raw = wp_remote_retrieve_body($wp_response);
    $body = json_decode($body_raw, true);
    
    // Debug: Log the raw response for troubleshooting
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('YPF Accounts API Response - Status: ' . $status_code);
        error_log('YPF Accounts API Response - Raw Body: ' . substr($body_raw, 0, 1000));
        error_log('YPF Accounts API Response - Parsed Body Type: ' . gettype($body));
        if (is_array($body)) {
            error_log('YPF Accounts API Response - Body Keys: ' . implode(', ', array_keys($body)));
        }
    }
    
    // Handle both response formats: direct array or object with 'results' key
    $accounts = [];
    
    // Check if response is an error (404, etc.)
    if ($status_code === 404) {
        // API returned 404 - user has no accounts
        return [
            'response' => [
                'status' => 'info',
                'message' => 'No accounts found for this user',
                'user_id' => $user_id,
                'explanation' => 'This user exists in YPF but has no trading accounts created yet. No breach action needed.',
                'api_response' => $body,
                'status_code' => $status_code
            ],
            'status_code' => 200 // Return 200 as this is a valid state, not an error
        ];
    }
    
    // Parse response body - handle multiple possible formats
    if (is_array($body)) {
        // Check for 'results' key (common API format)
        if (isset($body['results']) && is_array($body['results'])) {
            $accounts = $body['results'];
        }
        // Check for 'data' key (alternative API format)
        elseif (isset($body['data']) && is_array($body['data'])) {
            $accounts = $body['data'];
        }
        // Check for 'accounts' key (another possible format)
        elseif (isset($body['accounts']) && is_array($body['accounts'])) {
            $accounts = $body['accounts'];
        }
        // Check for 'items' key (pagination format)
        elseif (isset($body['items']) && is_array($body['items'])) {
            $accounts = $body['items'];
        }
        // Check if body itself is an array of accounts (direct array response)
        elseif (!empty($body)) {
            $first_key = array_key_first($body);
            // If first key is numeric (0, 1, 2...) or null (empty array), it's likely a list
            if (is_numeric($first_key) || $first_key === null || $first_key === 0) {
                $accounts = $body; // Direct array response
            }
            // Check if body has account-like structure (has 'id' or 'accountId' key)
            elseif (isset($body['id']) || isset($body['accountId']) || isset($body['state']) || isset($body['login'])) {
                // Single account object, wrap in array
                $accounts = [$body];
            }
        }
    } elseif (is_object($body)) {
        // Handle object response
        $body_array = (array) $body;
        if (isset($body_array['results']) && is_array($body_array['results'])) {
            $accounts = $body_array['results'];
        } elseif (isset($body_array['data']) && is_array($body_array['data'])) {
            $accounts = $body_array['data'];
        } elseif (isset($body_array['accounts']) && is_array($body_array['accounts'])) {
            $accounts = $body_array['accounts'];
        }
    }
    
    // Filter out non-array items and ensure all accounts are arrays
    $accounts = array_filter($accounts, function($account) {
        return is_array($account) && !empty($account);
    });
    
    // Re-index array after filtering
    $accounts = array_values($accounts);
    
    // Debug: Log parsed accounts count
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('YPF Accounts API - Parsed Accounts Count: ' . count($accounts));
        if (!empty($accounts)) {
            error_log('YPF Accounts API - First Account Keys: ' . implode(', ', array_keys($accounts[0])));
        }
    }
    
    if (empty($accounts)) {
        // No accounts found - but let's provide detailed debug info
        $debug_info = [
            'status_code' => $status_code,
            'body_type' => gettype($body),
            'body_is_array' => is_array($body),
            'body_is_null' => is_null($body),
            'body_empty' => empty($body),
        ];
        
        if (is_array($body)) {
            $debug_info['body_keys'] = array_keys($body);
            $debug_info['body_count'] = count($body);
        }
        
        if (!empty($body_raw)) {
            $debug_info['raw_body_preview'] = substr($body_raw, 0, 500);
        }
        
        // Check if JSON decode failed
        if (json_last_error() !== JSON_ERROR_NONE) {
            $debug_info['json_error'] = json_last_error_msg();
        }
        
        return [
            'response' => [
                'status' => 'info',
                'message' => 'No accounts found for this user',
                'user_id' => $user_id,
                'explanation' => 'This user exists in YPF but has no trading accounts created yet. No breach action needed.',
                'api_response' => $body,
                'debug_info' => $debug_info,
                'status_code' => $status_code
            ],
            'status_code' => 200 // Return 200 as this is a valid state, not an error
        ];
    }
    
    // Filter accounts by invoiceId if related_order_ids provided (for subscription-specific breach)
    if (!empty($related_order_ids)) {
        // Convert order IDs to strings for comparison (invoiceId is usually string)
        $related_order_ids_str = array_map('strval', $related_order_ids);
        
        $filtered_accounts = [];
        foreach ($accounts as $account) {
            if (!is_array($account)) {
                continue;
            }
            
            // Check invoiceId in various possible field names
            $invoice_id = null;
            if (isset($account['invoiceId'])) {
                $invoice_id = strval($account['invoiceId']);
            } elseif (isset($account['invoice_id'])) {
                $invoice_id = strval($account['invoice_id']);
            } elseif (isset($account['InvoiceId'])) {
                $invoice_id = strval($account['InvoiceId']);
            }
            
            // If account has invoiceId matching any related order, include it
            if ($invoice_id && in_array($invoice_id, $related_order_ids_str, true)) {
                $filtered_accounts[] = $account;
            }
        }
        
        // If we found matching accounts, use only those; otherwise use all (fallback)
        if (!empty($filtered_accounts)) {
            $accounts = $filtered_accounts;
        }
        // If no matching accounts found but we have order IDs, it means no accounts for this subscription
        // We'll still process but log this case
    }
    
    // Breach active accounts (now filtered to subscription-specific if order IDs provided)
    $breached_accounts = [];
    $active_count = 0;
    $skipped_count = 0;
    
    foreach ($accounts as $account) {
        // Ensure $account is an array before accessing its properties
        if (!is_array($account)) {
            $skipped_count++;
            continue;
        }
        
        $account_state = isset($account['state']) ? $account['state'] : '';
        $account_id = isset($account['id']) ? $account['id'] : null;
        
        if ($account_state === 'Active' && $account_id) {
            $active_count++;
            $breach_result = ypf_unsub_breach_single_account(
                $api_base_url, 
                $api_key, 
                $user_id, 
                $account_id
            );
            $breached_accounts[] = [
                'account_id' => $account_id,
                'login' => isset($account['login']) ? $account['login'] : 'N/A',
                'program_id' => isset($account['programId']) ? $account['programId'] : 'N/A',
                'balance' => isset($account['balance']) ? $account['balance'] : 'N/A',
                'invoice_id' => isset($account['invoiceId']) ? $account['invoiceId'] : (isset($account['invoice_id']) ? $account['invoice_id'] : 'N/A'),
                'state_before' => 'Active',
                'result' => $breach_result
            ];
        } else {
            $skipped_count++;
        }
    }
    
    // Build message based on whether filtering was applied
    // Note: Both "all" and "specific" are still within the same user account
    $filtered_message = !empty($related_order_ids) 
        ? "Successfully breached {$active_count} active account(s) for this subscription" 
        : "Successfully breached {$active_count} active account(s) for this user";
    
    $no_accounts_message = !empty($related_order_ids)
        ? 'No active accounts found for this subscription to breach'
        : 'No active accounts found for this user to breach';
    
    return [
        'response' => [
            'status' => $active_count > 0 ? 'success' : 'info',
            'message' => $active_count > 0 ? $filtered_message : $no_accounts_message,
            'user_id' => $user_id,
            'subscription_filtered' => !empty($related_order_ids),
            'related_order_ids' => $related_order_ids,
            'total_accounts' => count($accounts),
            'active_accounts_breached' => $active_count,
            'already_breached_or_inactive' => $skipped_count,
            'breached_accounts' => $breached_accounts,
            'all_accounts_summary' => array_map(function($acc) {
                if (!is_array($acc)) {
                    return [
                        'id' => 'N/A',
                        'login' => 'N/A',
                        'state' => 'Invalid',
                        'program' => 'N/A',
                        'balance' => 'N/A'
                    ];
                }
                return [
                    'id' => isset($acc['id']) ? $acc['id'] : 'N/A',
                    'login' => isset($acc['login']) ? $acc['login'] : 'N/A',
                    'state' => isset($acc['state']) ? $acc['state'] : 'Unknown',
                    'program' => isset($acc['programId']) ? $acc['programId'] : 'N/A',
                    'balance' => isset($acc['balance']) ? $acc['balance'] : 'N/A'
                ];
            }, $accounts)
        ],
        'status_code' => 200
    ];
}

/**
 * Fetch user accounts without modifying them
 */
function ypf_get_user_accounts($api_base_url, $api_key, $user_id) {
    $endpoint = $api_base_url . '/client/v1/users/' . $user_id . '/accounts';

    $args = [
        'headers' => [
            'X-Client-Key' => $api_key,
            'Content-Type' => 'application/json'
        ],
        'timeout' => 30
    ];

    $wp_response = wp_remote_get($endpoint, $args);

    if (is_wp_error($wp_response)) {
        return [
            'response' => [
                'status' => 'error',
                'message' => 'Failed to fetch accounts: ' . $wp_response->get_error_message()
            ],
            'status_code' => 500
        ];
    }

    $status_code = wp_remote_retrieve_response_code($wp_response);
    $body = json_decode(wp_remote_retrieve_body($wp_response), true);

    $accounts = [];
    if (is_array($body)) {
        if (isset($body['results']) && is_array($body['results'])) {
            $accounts = $body['results'];
        } elseif (is_array($body)) {
            // Check if body is a numeric array (list of accounts)
            $first_key = array_key_first($body);
            if (is_numeric($first_key) || $first_key === null) {
                $accounts = $body; // Direct array response
            }
        }
    }
    
    // Filter out non-array items and ensure all accounts are arrays
    $accounts = array_filter($accounts, function($account) {
        return is_array($account);
    });
    
    // Re-index array after filtering
    $accounts = array_values($accounts);

    if (empty($accounts)) {
        return [
            'response' => [
                'status' => 'info',
                'message' => 'No accounts found for this user',
                'user_id' => $user_id,
                'api_response' => $body,
                'status_code' => $status_code
            ],
            'status_code' => 404
        ];
    }

    return [
        'response' => [
            'status' => 'success',
            'message' => 'Accounts fetched successfully',
            'user_id' => $user_id,
            'total_accounts' => count($accounts),
            'accounts' => array_map(function($acc) {
                if (!is_array($acc)) {
                    return [
                        'id' => '',
                        'login' => 'N/A',
                        'state' => 'Invalid',
                        'program' => 'N/A',
                        'balance' => 'N/A'
                    ];
                }
                return [
                    'id' => isset($acc['id']) ? $acc['id'] : '',
                    'login' => isset($acc['login']) ? $acc['login'] : 'N/A',
                    'state' => isset($acc['state']) ? $acc['state'] : 'Unknown',
                    'program' => isset($acc['programId']) ? $acc['programId'] : 'N/A',
                    'balance' => isset($acc['balance']) ? $acc['balance'] : 'N/A'
                ];
            }, $accounts),
            'raw' => $body
        ],
        'status_code' => 200
    ];
}

/**
 * Delete a specific program
 */
function ypf_delete_program($api_base_url, $api_key, $program_id) {
    $endpoint = $api_base_url . '/client/v1/programs/' . $program_id;

    $args = [
        'method' => 'DELETE',
        'headers' => [
            'X-Client-Key' => $api_key,
            'Content-Type' => 'application/json'
        ],
        'timeout' => 30
    ];

    $wp_response = wp_remote_request($endpoint, $args);

    if (is_wp_error($wp_response)) {
        return [
            'response' => [
                'status' => 'error',
                'message' => 'Failed to delete program: ' . $wp_response->get_error_message()
            ],
            'status_code' => 500
        ];
    }

    $status_code = wp_remote_retrieve_response_code($wp_response);
    $body = wp_remote_retrieve_body($wp_response);

    if ($status_code === 204) {
        return [
            'response' => [
                'status' => 'success',
                'message' => 'Program deleted successfully',
                'program_id' => $program_id
            ],
            'status_code' => 200
        ];
    }

    return [
        'response' => [
            'status' => 'error',
            'message' => 'Program delete failed',
            'program_id' => $program_id,
            'status_code' => $status_code,
            'api_response' => $body
        ],
        'status_code' => $status_code
    ];
}

/**
 * Breach a single account
 */
function ypf_unsub_breach_single_account($api_base_url, $api_key, $user_id, $account_id) {
    $endpoint = $api_base_url . '/client/v1/users/' . $user_id . '/accounts/' . $account_id . '/manualbreach';
    
    $body = [
        'ruleName' => 'Manual Breach',
        'reason' => 'Cancel Subscription'
    ];
    
    $args = [
        'method' => 'PUT',
        'headers' => [
            'X-Client-Key' => $api_key,
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode($body),
        'timeout' => 30
    ];
    
    $wp_response = wp_remote_request($endpoint, $args);
    
    if (is_wp_error($wp_response)) {
        return [
            'status' => 'error',
            'message' => $wp_response->get_error_message()
        ];
    }
    
    $status_code = wp_remote_retrieve_response_code($wp_response);
    
    return [
        'status' => $status_code === 204 ? 'success' : 'error',
        'status_code' => $status_code
    ];
}

/**
 * Send JSON response
 */
function ypf_unsub_send_response($data, $status_code = 200) {
    // Prevent any output before headers
    if (ob_get_level()) {
        ob_clean();
    }
    
    // Set headers
    status_header($status_code);
    header('Content-Type: application/json; charset=utf-8');
    
    // Add debug info
    $response = [
        'timestamp' => current_time('Y-m-d H:i:s'),
        'status_code' => $status_code,
        'data' => $data
    ];
    
    // Pretty print
    $json = json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    
    if ($json === false) {
        // Fallback if json_encode fails
        $response = [
            'timestamp' => current_time('Y-m-d H:i:s'),
            'status_code' => 500,
            'data' => [
                'status' => 'error',
                'message' => 'Failed to encode response',
                'json_error' => json_last_error_msg()
            ]
        ];
        $json = json_encode($response, JSON_PRETTY_PRINT);
    }
    
    echo $json;
    exit;
}

/**
 * Flush rewrite rules on activation
 */
register_activation_hook(__FILE__, 'ypf_unsub_activate');
function ypf_unsub_activate() {
    ypf_unsub_register_endpoint();
    flush_rewrite_rules();
}

/**
 * Flush rewrite rules on deactivation
 */
register_deactivation_hook(__FILE__, 'ypf_unsub_deactivate');
function ypf_unsub_deactivate() {
    flush_rewrite_rules();
}


/**
 * Wrapper for breaching a single account
 */
function ypf_breach_single_account_wrapper($api_base_url, $api_key, $user_id, $account_id) {
    $result = ypf_unsub_breach_single_account($api_base_url, $api_key, $user_id, $account_id);
    
    return [
        'response' => [
            'status' => $result['status'],
            'message' => $result['status'] === 'success' ? 'Account breached successfully' : 'Failed to breach account',
            'user_id' => $user_id,
            'account_id' => $account_id,
            'result' => $result
        ],
        'status_code' => $result['status'] === 'success' ? 200 : 500
    ];
}

/**
 * Get all accounts for a user and reset all breached ones
 * This resets ALL breached accounts belonging to the user (no subscription filter)
 * 
 * @param string $api_base_url YPF API base URL
 * @param string $api_key YPF API key
 * @param string $user_id YPF user ID
 * @return array Response with reset results
 */
function ypf_get_and_reset_all_accounts($api_base_url, $api_key, $user_id) {
    return ypf_get_and_reset_accounts_internal($api_base_url, $api_key, $user_id, []);
}

/**
 * Get accounts for a user and reset only breached ones matching subscription/order IDs
 * This resets ONLY breached accounts that belong to the specific subscription (filtered by invoiceId)
 * 
 * @param string $api_base_url YPF API base URL
 * @param string $api_key YPF API key
 * @param string $user_id YPF user ID
 * @param array $related_order_ids Array of order IDs to filter accounts by invoiceId
 * @return array Response with reset results
 */
function ypf_get_and_reset_specific_accounts($api_base_url, $api_key, $user_id, $related_order_ids) {
    if (empty($related_order_ids)) {
        // Fallback to all if no order IDs provided
        return ypf_get_and_reset_all_accounts($api_base_url, $api_key, $user_id);
    }
    return ypf_get_and_reset_accounts_internal($api_base_url, $api_key, $user_id, $related_order_ids);
}

/**
 * Internal function to get accounts for a user and reset them (only breached accounts)
 * Can reset all breached accounts or filter by subscription/order IDs
 * 
 * @param string $api_base_url YPF API base URL
 * @param string $api_key YPF API key
 * @param string $user_id YPF user ID (single user, not all users)
 * @param array $related_order_ids Optional array of order IDs to filter accounts by invoiceId
 *                                  If empty, resets all breached accounts for the user
 *                                  If provided, resets only breached accounts matching those order IDs
 * @return array Response with reset results
 */
function ypf_get_and_reset_accounts_internal($api_base_url, $api_key, $user_id, $related_order_ids = []) {
    // Get user accounts
    $endpoint = $api_base_url . '/client/v1/users/' . $user_id . '/accounts';
    
    $args = [
        'headers' => [
            'X-Client-Key' => $api_key,
            'Content-Type' => 'application/json'
        ],
        'timeout' => 30
    ];
    
    $wp_response = wp_remote_get($endpoint, $args);
    
    if (is_wp_error($wp_response)) {
        return [
            'response' => [
                'status' => 'error',
                'message' => 'Failed to fetch accounts: ' . $wp_response->get_error_message()
            ],
            'status_code' => 500
        ];
    }
    
    $status_code = wp_remote_retrieve_response_code($wp_response);
    $body = json_decode(wp_remote_retrieve_body($wp_response), true);
    
    // Handle both response formats
    $accounts = [];
    if (is_array($body)) {
        if (isset($body['results']) && is_array($body['results'])) {
            $accounts = $body['results'];
        } elseif (is_array($body)) {
            // Check if body is a numeric array (list of accounts)
            $first_key = array_key_first($body);
            if (is_numeric($first_key) || $first_key === null) {
                $accounts = $body; // Direct array response
            }
        }
    }
    
    // Filter out non-array items and ensure all accounts are arrays
    $accounts = array_filter($accounts, function($account) {
        return is_array($account);
    });
    
    // Re-index array after filtering
    $accounts = array_values($accounts);
    
    if (empty($accounts)) {
        return [
            'response' => [
                'status' => 'error',
                'message' => 'No accounts found for this user',
                'user_id' => $user_id
            ],
            'status_code' => 404
        ];
    }
    
    // Filter accounts by invoiceId if related_order_ids provided (for subscription-specific reset)
    if (!empty($related_order_ids)) {
        // Convert order IDs to strings for comparison (invoiceId is usually string)
        $related_order_ids_str = array_map('strval', $related_order_ids);
        
        $filtered_accounts = [];
        foreach ($accounts as $account) {
            if (!is_array($account)) {
                continue;
            }
            
            // Check invoiceId in various possible field names
            $invoice_id = null;
            if (isset($account['invoiceId'])) {
                $invoice_id = strval($account['invoiceId']);
            } elseif (isset($account['invoice_id'])) {
                $invoice_id = strval($account['invoice_id']);
            } elseif (isset($account['InvoiceId'])) {
                $invoice_id = strval($account['InvoiceId']);
            }
            
            // If account has invoiceId matching any related order, include it
            if ($invoice_id && in_array($invoice_id, $related_order_ids_str, true)) {
                $filtered_accounts[] = $account;
            }
        }
        
        // If we found matching accounts, use only those; otherwise use all (fallback)
        if (!empty($filtered_accounts)) {
            $accounts = $filtered_accounts;
        }
    }
    
    // Reset only breached accounts (now filtered to subscription-specific if order IDs provided)
    $reset_accounts = [];
    $breached_count = 0;
    $skipped_count = 0;
    
    foreach ($accounts as $account) {
        // Ensure $account is an array before accessing its properties
        if (!is_array($account)) {
            $skipped_count++;
            continue;
        }
        
        $account_state = isset($account['state']) ? $account['state'] : '';
        $account_id = isset($account['id']) ? $account['id'] : null;
        
        if ($account_state === 'Breached' && $account_id) {
            $breached_count++;
            $reset_result = ypf_reset_single_account($api_base_url, $api_key, $user_id, $account_id);
            $reset_accounts[] = [
                'account_id' => $account_id,
                'login' => isset($account['login']) ? $account['login'] : 'N/A',
                'program_id' => isset($account['programId']) ? $account['programId'] : 'N/A',
                'balance' => isset($account['balance']) ? $account['balance'] : 'N/A',
                'invoice_id' => isset($account['invoiceId']) ? $account['invoiceId'] : (isset($account['invoice_id']) ? $account['invoice_id'] : 'N/A'),
                'state_before' => 'Breached',
                'result' => $reset_result
            ];
        } else {
            $skipped_count++;
        }
    }
    
    return [
        'response' => [
            'status' => $breached_count > 0 ? 'success' : 'info',
            'message' => $breached_count > 0 
                ? "Successfully reset {$breached_count} breached account(s)" 
                : 'No breached accounts to reset',
            'user_id' => $user_id,
            'total_accounts' => count($accounts),
            'breached_accounts_reset' => $breached_count,
            'skipped_not_breached' => $skipped_count,
            'reset_accounts' => $reset_accounts,
            'all_accounts_summary' => array_map(function($acc) {
                if (!is_array($acc)) {
                    return [
                        'id' => 'N/A',
                        'login' => 'N/A',
                        'state' => 'Invalid',
                        'program' => 'N/A',
                        'balance' => 'N/A'
                    ];
                }
                return [
                    'id' => isset($acc['id']) ? $acc['id'] : 'N/A',
                    'login' => isset($acc['login']) ? $acc['login'] : 'N/A',
                    'state' => isset($acc['state']) ? $acc['state'] : 'Unknown',
                    'program' => isset($acc['programId']) ? $acc['programId'] : 'N/A',
                    'balance' => isset($acc['balance']) ? $acc['balance'] : 'N/A'
                ];
            }, $accounts)
        ],
        'status_code' => 200
    ];
}

/**
 * Wrapper for resetting a single account
 */
function ypf_reset_single_account_wrapper($api_base_url, $api_key, $user_id, $account_id) {
    $result = ypf_reset_single_account($api_base_url, $api_key, $user_id, $account_id);
    
    return [
        'response' => [
            'status' => $result['status'],
            'message' => $result['status'] === 'success' ? 'Account reset successfully - old account breached, new account created' : 'Failed to reset account',
            'user_id' => $user_id,
            'account_id' => $account_id,
            'result' => $result
        ],
        'status_code' => $result['status'] === 'success' ? 200 : 500
    ];
}

/**
 * Reset a single account
 * Note: This breaches the old account and creates a new one in the same program
 */
function ypf_reset_single_account($api_base_url, $api_key, $user_id, $account_id) {
    $endpoint = $api_base_url . '/client/v1/users/' . $user_id . '/checkout-reset/' . $account_id;
    
    $args = [
        'method' => 'POST',
        'headers' => [
            'X-Client-Key' => $api_key,
            'Content-Type' => 'application/json'
        ],
        'timeout' => 30
    ];
    
    $wp_response = wp_remote_request($endpoint, $args);
    
    if (is_wp_error($wp_response)) {
        return [
            'status' => 'error',
            'message' => $wp_response->get_error_message()
        ];
    }
    
    $status_code = wp_remote_retrieve_response_code($wp_response);
    $response_body = wp_remote_retrieve_body($wp_response);
    
    if ($status_code === 204) {
        return [
            'status' => 'success',
            'status_code' => $status_code,
            'message' => 'Account reset successfully - breached old account and created new one'
        ];
    }
    
    return [
        'status' => 'error',
        'status_code' => $status_code,
        'message' => 'Reset failed',
        'api_response' => $response_body
    ];
}

/**
 * Add user email and user ID to WCSViewSubscription for breach functionality
 */
add_filter('woocommerce_subscriptions_frontend_view_subscription_script_parameters', 'ypf_add_user_email_to_subscription_script', 10, 1);
function ypf_add_user_email_to_subscription_script($script_params) {
    // Check if WooCommerce Subscriptions functions are available
    if (!function_exists('wcs_get_subscription')) {
        return $script_params;
    }
    
    if (isset($script_params['subscription_id'])) {
        $subscription = wcs_get_subscription($script_params['subscription_id']);
        if ($subscription && is_a($subscription, 'WC_Subscription')) {
            $billing_email = $subscription->get_billing_email();
            $wp_user_id = $subscription->get_user_id();
            
            // Get subscription ID for passing to endpoint
            $subscription_id = $subscription->get_id();
            
            // Try to get YPF user ID from subscription or parent order meta
            $ypf_user_id = null;
            
            // Check subscription meta first
            $ypf_user_id = $subscription->get_meta('_ypf_user_id');
            if (empty($ypf_user_id)) {
                $ypf_user_id = $subscription->get_meta('ypf_user_id');
            }
            if (empty($ypf_user_id)) {
                $ypf_user_id = $subscription->get_meta('_yourpropfirm_user_id');
            }
            
            // If not found in subscription, check parent order
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
            
            // Add to script params
            if ($billing_email) {
                $script_params['user_email'] = $billing_email;
            }
            if ($subscription_id) {
                $script_params['subscription_id'] = $subscription_id;
            }
            // Use email as primary identifier since YPF user ID might not be stored
            // The endpoint will use email to get YPF user ID from API
        }
    }
    return $script_params;
}

/**
 * Enqueue custom script for breach on cancel button click
 */
add_action('wp_enqueue_scripts', 'ypf_enqueue_breach_on_cancel_script', 20);
function ypf_enqueue_breach_on_cancel_script() {
    // Check if WooCommerce and WooCommerce Subscriptions are active
    if (!class_exists('WooCommerce') || !function_exists('wcs_is_view_subscription_page')) {
        return;
    }
    
    // Load on view-subscription page (cancel button) or my-account dashboard (reset drawdown modal)
    $load = wcs_is_view_subscription_page();
    if (!$load && function_exists('is_account_page') && is_account_page()) {
        $load = true;
    }
    if (!$load) {
        return;
    }
    
    // On view-subscription page only: require valid subscription and permission
    if (wcs_is_view_subscription_page()) {
        if (!function_exists('wcs_get_subscription')) {
            return;
        }
        $subscription_id = absint(get_query_var('view-subscription'));
        if (!$subscription_id) {
            return;
        }
        $subscription = wcs_get_subscription($subscription_id);
        if (!$subscription || !is_a($subscription, 'WC_Subscription')) {
            return;
        }
        if (!current_user_can('view_order', $subscription->get_id())) {
            return;
        }
    }
    
    $deps = array('jquery');
    if (wcs_is_view_subscription_page()) {
        $deps[] = 'wcs-view-subscription';
    }
    
    // Enqueue the breach on cancel script (modal also used for reset drawdown on dashboard)
    wp_enqueue_script(
        'ypf-breach-on-cancel',
        content_url('mu-plugins/ypf-breach-on-cancel.js'),
        $deps,
        '1.0.0',
        true
    );
    
    if (wcs_is_view_subscription_page()) {
        wp_localize_script('ypf-breach-on-cancel', 'ypfBreachOnCancel', array(
        'breachUrl'       => home_url('/unsub'),
        'confirmMessage' => __('Are you sure you want to cancel your subscription? Your trading account will be permanently closed. You won’t be able to reactivate it, and all progress will be lost.', 'ypf-unsub'),
        ));
    }
}

/**
 * Handle the /account-details request
 */
add_action('template_redirect', 'ypf_account_details_handle_request');
function ypf_account_details_handle_request() {
    global $wp_query;

    if (!isset($wp_query->query_vars['ypf_account_details'])) {
        return;
    }
    
    $user_id = isset($_GET['user_id']) ? sanitize_text_field($_GET['user_id']) : '';
    $account_id = isset($_GET['account_id']) ? sanitize_text_field($_GET['account_id']) : '';
    
    if (empty($user_id) || empty($account_id)) {
        $response = [
            'status' => 'error',
            'message' => 'Please provide both user_id and account_id parameters',
            'usage_example' => 'https://alpine.test/account-details?user_id=USER_ID&account_id=ACCOUNT_ID'
        ];
        ypf_unsub_send_response($response, 400);
        return;
    }
    
    // Get API configuration
    $plugin_env = get_option('yourpropfirm_connection_environment');
    
    if ($plugin_env === 'sandbox') {
        $api_base_url = esc_attr(get_option('yourpropfirm_connection_sandbox_endpoint_url'));
        $api_key = esc_attr(get_option('yourpropfirm_connection_sandbox_test_key'));
    } else {
        $api_base_url = esc_attr(get_option('yourpropfirm_connection_endpoint_url'));
        $api_key = esc_attr(get_option('yourpropfirm_connection_api_key'));
    }
    
    // Fallback to provided credentials if options not set
    if (empty($api_base_url)) {
        $api_base_url = 'https://api.ypf.customers.sigma-ventures.cloud';
    }
    if (empty($api_key)) {
        $api_key = '8bc0jjc6ss084e54kk7d45ea87ckl089129311c0657d42c696952bjj395f56d7';
    }
    
    $result = ypf_get_account_details($api_base_url, $api_key, $user_id, $account_id);
    ypf_unsub_send_response($result['response'], $result['status_code']);
}

/**
 * Get account details from YPF API
 * 
 * @param string $api_base_url YPF API base URL
 * @param string $api_key YPF API key
 * @param string $user_id YPF user ID
 * @param string $account_id YPF account ID
 * @return array Response with account details
 */
function ypf_get_account_details($api_base_url, $api_key, $user_id, $account_id) {
    $endpoint = $api_base_url . '/client/v1/users/' . urlencode($user_id) . '/accounts/' . urlencode($account_id) . '/rulesdetails';
    
    $args = [
        'headers' => [
            'X-Client-Key' => $api_key,
            'Content-Type' => 'application/json'
        ],
        'timeout' => 30
    ];
    
    $wp_response = wp_remote_get($endpoint, $args);
    
    if (is_wp_error($wp_response)) {
        return [
            'response' => [
                'status' => 'error',
                'message' => 'Failed to fetch account details: ' . $wp_response->get_error_message()
            ],
            'status_code' => 500
        ];
    }
    
    $status_code = wp_remote_retrieve_response_code($wp_response);
    $body_raw = wp_remote_retrieve_body($wp_response);
    $body = json_decode($body_raw, true);
    
    if ($status_code >= 200 && $status_code < 300) {
        return [
            'response' => [
                'status' => 'success',
                'message' => 'Account details retrieved successfully',
                'user_id' => $user_id,
                'account_id' => $account_id,
                'data' => $body
            ],
            'status_code' => $status_code
        ];
    } else {
        return [
            'response' => [
                'status' => 'error',
                'message' => 'Failed to retrieve account details',
                'user_id' => $user_id,
                'account_id' => $account_id,
                'api_response' => $body
            ],
            'status_code' => $status_code
        ];
    }
}

/**
 * Display account details (Shortcode)
 * Usage: [ypf_account_details user_id="USER_ID" account_id="ACCOUNT_ID"]
 * 
 * @param array $atts Shortcode attributes
 * @return string HTML output
 */
add_shortcode('ypf_account_details', 'ypf_display_account_details_shortcode');
function ypf_display_account_details_shortcode($atts) {
    $atts = shortcode_atts([
        'user_id' => '',
        'account_id' => ''
    ], $atts);
    
    if (empty($atts['user_id']) || empty($atts['account_id'])) {
        return '<div class="ypf-error">Error: user_id and account_id are required.</div>';
    }
    
    // Get API configuration
    $plugin_env = get_option('yourpropfirm_connection_environment');
    
    if ($plugin_env === 'sandbox') {
        $api_base_url = esc_attr(get_option('yourpropfirm_connection_sandbox_endpoint_url'));
        $api_key = esc_attr(get_option('yourpropfirm_connection_sandbox_test_key'));
    } else {
        $api_base_url = esc_attr(get_option('yourpropfirm_connection_endpoint_url'));
        $api_key = esc_attr(get_option('yourpropfirm_connection_api_key'));
    }
    
    // Fallback to provided credentials if options not set
    if (empty($api_base_url)) {
        $api_base_url = 'https://api.ypf.customers.sigma-ventures.cloud';
    }
    if (empty($api_key)) {
        $api_key = '8bc0jjc6ss084e54kk7d45ea87ckl089129311c0657d42c696952bjj395f56d7';
    }
    
    $result = ypf_get_account_details($api_base_url, $api_key, $atts['user_id'], $atts['account_id']);
    
    if ($result['status_code'] >= 200 && $result['status_code'] < 300) {
        $data = $result['response']['data'];
        return ypf_render_account_details_html($data, $atts['user_id'], $atts['account_id']);
    } else {
        return '<div class="ypf-error">Error: ' . esc_html($result['response']['message']) . '</div>';
    }
}

/**
 * Render account details as HTML
 * 
 * @param array $data Account details data from API
 * @param string $user_id User ID
 * @param string $account_id Account ID
 * @return string HTML output
 */
function ypf_render_account_details_html($data, $user_id, $account_id) {
    ob_start();
    ?>
    <div class="ypf-account-details">
        <h3>Account Details</h3>
        <div class="ypf-account-info">
            <p><strong>User ID:</strong> <?php echo esc_html($user_id); ?></p>
            <p><strong>Account ID:</strong> <?php echo esc_html($account_id); ?></p>
        </div>
        <div class="ypf-account-data">
            <pre><?php echo esc_html(wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre>
        </div>
    </div>
    <style>
        .ypf-account-details {
            margin: 20px 0;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background: #f9f9f9;
        }
        .ypf-account-details h3 {
            margin-top: 0;
        }
        .ypf-account-info {
            margin-bottom: 20px;
        }
        .ypf-account-info p {
            margin: 5px 0;
        }
        .ypf-account-data pre {
            background: #fff;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 3px;
            overflow-x: auto;
            font-size: 12px;
            line-height: 1.5;
        }
        .ypf-error {
            padding: 15px;
            background: #fee;
            border: 1px solid #fcc;
            border-radius: 3px;
            color: #c33;
        }
    </style>
    <?php
    return ob_get_clean();
}

/**
 * Display account details on view-subscription page
 * Moved to ypf-subscription-account-details.php
 */
