<?php

if (!defined('ABSPATH')) {
    exit;
}

/* =====================================================
 * RENDER SSO BUTTON FOR NON-LOGGED IN USER
 * ===================================================== */
function ypf_sso_render_block() {

    // Only show on My Account page AND if user not logged in
    if (!is_account_page() || is_user_logged_in()) {
        return;
    }

    $nonce    = wp_create_nonce('ypf_sso_verify_nonce');
    $ajax_url = admin_url('admin-ajax.php');
    ?>

    <style>
    #ypf-sso-login-block {
        margin-bottom: 20px;
    }

    #ypf-sso-login-trigger {
        background-color: #017FDD;
        font-weight: 600;
        fill: #FFFFFF;
        color: #FFFFFF;
        border-style: solid;
        border-width: 1px;
        border-color: #467FF7;
        border-radius: 6px;
        padding: 16px 25px;
        cursor: pointer;
        transition: all 0.25s ease;
    }

    #ypf-sso-login-trigger:hover {
        background-color: #006bc0;
        border-color: #2f6df5;
        color: #FFFFFF;
        transform: translateY(-2px);
        box-shadow: 0 6px 18px rgba(1, 127, 221, 0.25);
    }

    #ypf-sso-login-trigger:active {
        transform: translateY(0);
        box-shadow: 0 3px 10px rgba(1, 127, 221, 0.2);
    }

    #ypf-sso-login-trigger:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none;
        box-shadow: none;
    }

    #ypf-sso-message {
        display: none;
        margin-top: 10px;
        font-size: 14px;
    }
    </style>

    <script>
    (function() {

        document.addEventListener('DOMContentLoaded', function() {

            if (!document.body.classList.contains('woocommerce-account') ||
                !document.body.classList.contains('non-logged-in')) {
                return;
            }

            const container = document.querySelector('.page-content .woocommerce');
            if (!container) return;

            if (document.getElementById('ypf-sso-login-block')) return;

            const block = document.createElement('div');
            block.id = 'ypf-sso-login-block';

            block.innerHTML = `
                <button type="button" id="ypf-sso-login-trigger">
                    SSO Login
                </button>
                <p id="ypf-sso-message"></p>
            `;

            container.prepend(block);

            const trigger   = document.getElementById('ypf-sso-login-trigger');
            const messageEl = document.getElementById('ypf-sso-message');
            const nonce     = <?php echo json_encode($nonce); ?>;
            const ajaxUrl   = <?php echo json_encode($ajax_url); ?>;

            function getCookie(name) {
                const cookies = document.cookie.split(';');
                for (let c of cookies) {
                    const [key, ...rest] = c.trim().split('=');
                    if (key === name) {
                        return decodeURIComponent(rest.join('='));
                    }
                }
                return '';
            }

            function showMessage(text, isError = true) {
                messageEl.style.display = 'block';
                messageEl.textContent = text;
                messageEl.style.color = isError ? '#c33' : '#090';
            }

            trigger.addEventListener('click', function() {

                const token = getCookie('Access-Exchange-Key');

                trigger.disabled = true;
                messageEl.style.display = 'none';

                const formData = new FormData();
                formData.append('action', 'ypf_sso_verify_token');
                formData.append('nonce', nonce);
                if (token) formData.append('ypfToken', token);

                fetch(ajaxUrl, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(r => r.json())
                .then(data => {

                    if (data.success && data.data.redirect) {
                        window.location.href = data.data.redirect;
                        return;
                    }

                    showMessage(data.data?.message || 'Invalid or expired token.');
                    trigger.disabled = false;
                })
                .catch(() => {
                    showMessage('Request failed.');
                    trigger.disabled = false;
                });
            });

        });

    })();
    </script>

    <?php
}
add_action('wp_footer', 'ypf_sso_render_block');


/* =====================================================
 * AJAX HANDLER: VERIFY TOKEN AND LOGIN USER
 * ===================================================== */
add_action('wp_ajax_ypf_sso_verify_token', 'ypf_sso_ajax_verify_token');
add_action('wp_ajax_nopriv_ypf_sso_verify_token', 'ypf_sso_ajax_verify_token');

function ypf_sso_ajax_verify_token() {

    if (!isset($_POST['nonce']) || 
        !wp_verify_nonce($_POST['nonce'], 'ypf_sso_verify_nonce')) {
        wp_send_json_error(['message' => 'Security check failed.']);
    }

    $token = $_POST['ypfToken'] ?? '';

    if (empty($token) && !empty($_COOKIE['Access-Exchange-Key'])) {
        $token = $_COOKIE['Access-Exchange-Key'];
    }

    if (empty($token)) {
        wp_send_json_error(['message' => 'Token not found.']);
    }

    $plugin_env = get_option('yourpropfirm_connection_environment');

    if ($plugin_env === 'sandbox') {
        $api_base_url = get_option('yourpropfirm_connection_sandbox_endpoint_url');
        $api_key      = get_option('yourpropfirm_connection_sandbox_test_key');
    } else {
        $api_base_url = get_option('yourpropfirm_connection_endpoint_url');
        $api_key      = get_option('yourpropfirm_connection_api_key');
    }

    if (empty($api_base_url)) {
        $api_base_url = 'https://api.ypf.customers.sigma-ventures.cloud';
    }

    $endpoint = trailingslashit($api_base_url) . 'client/v1/verifytoken';

    $response = wp_remote_post($endpoint, [
        'headers' => [
            'Content-Type' => 'application/json',
            'X-Client-Key' => $api_key,
        ],
        'body' => json_encode([
            'ypfToken' => $token
        ]),
        'timeout' => 20,
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error(['message' => 'API connection failed.']);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (empty($body['email'])) {
        wp_send_json_error(['message' => 'Invalid or expired token.']);
    }

    $wp_user = get_user_by('email', $body['email']);

    if (!$wp_user) {
        wp_send_json_error(['message' => 'No WordPress account found for this email.']);
    }

    wp_set_current_user($wp_user->ID);
    wp_set_auth_cookie($wp_user->ID, true);

    wp_send_json_success([
        'redirect' => wc_get_page_permalink('myaccount')
    ]);
}
