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
    
    ypf_handle_account_action('breach');
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
    // Step 1: Get user ID if we have email
    if (!empty($email) && empty($user_id)) {
        $user_data = ypf_unsub_get_user_by_email($api_base_url, $api_key, $email);
        if ($user_data['status_code'] !== 200) {
            return $user_data;
        }
        $user_id = $user_data['user_id'];
    }
    
    // Step 2: If we have specific account_id, target that account only
    if (!empty($account_id) && !empty($user_id)) {
        if ($action === 'reset') {
            return ypf_reset_single_account_wrapper($api_base_url, $api_key, $user_id, $account_id);
        } else {
            return ypf_breach_single_account_wrapper($api_base_url, $api_key, $user_id, $account_id);
        }
    }
    
    // Step 3: Get user accounts and perform action on all matching accounts
    if (!empty($user_id)) {
        if ($action === 'reset') {
            return ypf_get_and_reset_accounts($api_base_url, $api_key, $user_id);
        } else {
            return ypf_unsub_get_and_breach_accounts($api_base_url, $api_key, $user_id);
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
 * Get accounts and breach them
 */
function ypf_unsub_get_and_breach_accounts($api_base_url, $api_key, $user_id) {
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
    
    // Handle both response formats: direct array or object with 'results' key
    $accounts = [];
    if (is_array($body)) {
        if (isset($body['results'])) {
            $accounts = $body['results'];
        } else {
            $accounts = $body; // Direct array response
        }
    }
    
    if (empty($accounts)) {
        return [
            'response' => [
                'status' => 'error',
                'message' => 'No accounts found for this user',
                'user_id' => $user_id,
                'explanation' => 'This user exists in YPF but has no trading accounts created yet.',
                'api_response' => $body,
                'status_code' => $status_code
            ],
            'status_code' => 404
        ];
    }
    
    // Breach all active accounts
    $breached_accounts = [];
    $active_count = 0;
    $skipped_count = 0;
    
    foreach ($accounts as $account) {
        if ($account['state'] === 'Active') {
            $active_count++;
            $breach_result = ypf_unsub_breach_single_account(
                $api_base_url, 
                $api_key, 
                $user_id, 
                $account['id']
            );
            $breached_accounts[] = [
                'account_id' => $account['id'],
                'login' => $account['login'] ?? 'N/A',
                'program_id' => $account['programId'] ?? 'N/A',
                'balance' => $account['balance'] ?? 'N/A',
                'state_before' => 'Active',
                'result' => $breach_result
            ];
        } else {
            $skipped_count++;
        }
    }
    
    return [
        'response' => [
            'status' => $active_count > 0 ? 'success' : 'info',
            'message' => $active_count > 0 
                ? "Successfully breached {$active_count} active account(s)" 
                : 'No active accounts to breach',
            'user_id' => $user_id,
            'total_accounts' => count($accounts),
            'active_accounts_breached' => $active_count,
            'already_breached_or_inactive' => $skipped_count,
            'breached_accounts' => $breached_accounts,
            'all_accounts_summary' => array_map(function($acc) {
                return [
                    'id' => $acc['id'],
                    'login' => $acc['login'] ?? 'N/A',
                    'state' => $acc['state'],
                    'program' => $acc['programId'] ?? 'N/A',
                    'balance' => $acc['balance'] ?? 'N/A'
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
        if (isset($body['results'])) {
            $accounts = $body['results'];
        } else {
            $accounts = $body;
        }
    }

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
                return [
                    'id' => $acc['id'] ?? '',
                    'login' => $acc['login'] ?? 'N/A',
                    'state' => $acc['state'] ?? 'Unknown',
                    'program' => $acc['programId'] ?? 'N/A',
                    'balance' => $acc['balance'] ?? 'N/A'
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
        'reason' => 'Breached via /unsub endpoint at ' . current_time('Y-m-d H:i:s')
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
    // Set headers
    status_header($status_code);
    header('Content-Type: application/json');
    
    // Add debug info
    $response = [
        'timestamp' => current_time('Y-m-d H:i:s'),
        'status_code' => $status_code,
        'data' => $data
    ];
    
    // Pretty print
    echo json_encode($response, JSON_PRETTY_PRINT);
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
 * Get accounts and reset them (only breached accounts)
 */
function ypf_get_and_reset_accounts($api_base_url, $api_key, $user_id) {
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
        if (isset($body['results'])) {
            $accounts = $body['results'];
        } else {
            $accounts = $body;
        }
    }
    
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
    
    // Reset only breached accounts
    $reset_accounts = [];
    $breached_count = 0;
    $skipped_count = 0;
    
    foreach ($accounts as $account) {
        if ($account['state'] === 'Breached') {
            $breached_count++;
            $reset_result = ypf_reset_single_account($api_base_url, $api_key, $user_id, $account['id']);
            $reset_accounts[] = [
                'account_id' => $account['id'],
                'login' => $account['login'] ?? 'N/A',
                'program_id' => $account['programId'] ?? 'N/A',
                'balance' => $account['balance'] ?? 'N/A',
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
                return [
                    'id' => $acc['id'],
                    'login' => $acc['login'] ?? 'N/A',
                    'state' => $acc['state'],
                    'program' => $acc['programId'] ?? 'N/A',
                    'balance' => $acc['balance'] ?? 'N/A'
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
