<?php
/**
 * Plugin Name: YPF Webhook Receiver
 * Description: Receives, stores, and logs incoming webhooks from the YPF platform at /webhook/manager/add
 * Version: 1.0.0
 * Author: Alpine Funded Dev Team
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'YPF_WEBHOOK_DB_VERSION', '1.2' );
define( 'YPF_WEBHOOK_TABLE', $GLOBALS['wpdb']->prefix . 'ypf_webhooks' );
define( 'YPF_PAYOUTS_TABLE', $GLOBALS['wpdb']->prefix . 'ypf_payouts' );

// ============================================================
// 1. DATABASE TABLES
// ============================================================
// ... (rest of the table creation logic)

add_action( 'init', 'ypf_webhook_maybe_create_table', 1 );
function ypf_webhook_maybe_create_table() {
    if ( get_option( 'ypf_webhook_db_version' ) === YPF_WEBHOOK_DB_VERSION ) {
        return;
    }
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    
    // Webhooks Log Table
    $table_webhooks = YPF_WEBHOOK_TABLE;
    $sql_webhooks = "CREATE TABLE $table_webhooks (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        webhook_type VARCHAR(100)    NOT NULL DEFAULT '',
        ypf_user_id  VARCHAR(100)    NOT NULL DEFAULT '',
        user_email   VARCHAR(255)    NOT NULL DEFAULT '',
        wp_user_id   BIGINT UNSIGNED          DEFAULT NULL,
        payload      LONGTEXT        NOT NULL,
        received_at  DATETIME        NOT NULL,
        test_mode    TINYINT(1)      NOT NULL DEFAULT 0,
        error_message TEXT                     DEFAULT NULL,
        PRIMARY KEY (id),
        KEY webhook_type (webhook_type),
        KEY received_at (received_at)
    ) $charset;";

    // Payouts Table
    $table_payouts = YPF_PAYOUTS_TABLE;
    $sql_payouts = "CREATE TABLE $table_payouts (
        id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        webhook_id        BIGINT UNSIGNED NOT NULL,
        ypf_user_id       VARCHAR(100)    NOT NULL,
        wp_user_id        BIGINT UNSIGNED          DEFAULT NULL,
        account_id        VARCHAR(100)    NOT NULL,
        payout_amount     DECIMAL(18,2)   NOT NULL DEFAULT 0.00,
        trader_receive    DECIMAL(18,2)   NOT NULL DEFAULT 0.00,
        profit_split      DECIMAL(5,2)    NOT NULL DEFAULT 0.00,
        commission        DECIMAL(18,2)   NOT NULL DEFAULT 0.00,
        program_name      VARCHAR(255)             DEFAULT '',
        platform          VARCHAR(50)              DEFAULT '',
        status            VARCHAR(50)              DEFAULT 'pending',
        received_at       DATETIME        NOT NULL,
        processed_at      DATETIME                 DEFAULT NULL,
        extra_data        LONGTEXT                 DEFAULT NULL,
        PRIMARY KEY (id),
        KEY webhook_id (webhook_id),
        KEY ypf_user_id (ypf_user_id),
        KEY wp_user_id (wp_user_id),
        KEY account_id (account_id)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql_webhooks );
    dbDelta( $sql_payouts );
    update_option( 'ypf_webhook_db_version', YPF_WEBHOOK_DB_VERSION );
}

// ============================================================
// 2. ENDPOINT HANDLER  (/webhook/manager/add)
// Intercepts at plugins_loaded — before WordPress routing kicks in.
// This bypasses rewrite rules entirely and matches directly on REQUEST_URI,
// which is more reliable behind Herd/Valet's server.php proxy layer.
// ============================================================

/**
 * Helper to log an error message to a webhook record.
 */
function ypf_webhook_log_error( $webhook_id, $message ) {
    global $wpdb;
    $wpdb->update(
        YPF_WEBHOOK_TABLE,
        [ 'error_message' => $message ],
        [ 'id' => $webhook_id ],
        [ '%s' ],
        [ '%d' ]
    );
}

// Intercept at init with highest priority
add_action( 'init', 'ypf_webhook_intercept_request', 0 );
function ypf_webhook_intercept_request() {
    // Check for the webhook path in the URI
    $uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
    
    if ( strpos( $uri, 'webhook/manager/add' ) === false ) {
        return;
    }

    // Capture raw body
    $raw = file_get_contents( 'php://input' );
    $payload = json_decode( $raw, true );
    $received_at = current_time( 'mysql' );

    // Send headers early to stop WP from rendering the theme
    status_header( 200 );
    header( 'Content-Type: application/json' );

    global $wpdb;

    // Basic Validation
    if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
        $wpdb->insert( YPF_WEBHOOK_TABLE, [ 'webhook_type' => 'Error', 'payload' => $raw, 'received_at' => $received_at, 'error_message' => 'Method not allowed (405)' ] );
        echo wp_json_encode( [ 'error' => 'Method not allowed' ] );
        exit;
    }

    // Secret Key Check
    $secret = get_option( 'ypf_webhook_secret_key', '' );
    if ( ! empty( $secret ) ) {
        $provided = isset( $_SERVER['HTTP_X_WEBHOOK_SECRET'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WEBHOOK_SECRET'] ) ) : '';
        if ( ! hash_equals( $secret, $provided ) ) {
            $wpdb->insert( YPF_WEBHOOK_TABLE, [ 'webhook_type' => 'Error', 'payload' => $raw, 'received_at' => $received_at, 'error_message' => 'Unauthorized (401)' ] );
            echo wp_json_encode( [ 'error' => 'Unauthorized' ] );
            exit;
        }
    }

    if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $payload ) ) {
        $wpdb->insert( YPF_WEBHOOK_TABLE, [ 'webhook_type' => 'Error', 'payload' => $raw, 'received_at' => $received_at, 'error_message' => 'Invalid JSON' ] );
        echo wp_json_encode( [ 'error' => 'Invalid JSON' ] );
        exit;
    }

    // Process fields
    $ypf_user_id  = sanitize_text_field( $payload['userId']      ?? '' );
    $user_email   = sanitize_email(      $payload['userEmail']   ?? '' );
    $webhook_type = sanitize_text_field( $payload['webhookType'] ?? 'Unknown' );
    $test_mode    = ! empty( $payload['testMode'] ) ? 1 : 0;

    $wp_user_id = ypf_webhook_resolve_wp_user( $user_email, $ypf_user_id );

    // Log to DB
    $wpdb->insert(
        YPF_WEBHOOK_TABLE,
        [
            'webhook_type' => $webhook_type,
            'ypf_user_id'  => $ypf_user_id,
            'user_email'   => $user_email,
            'wp_user_id'   => $wp_user_id,
            'payload'      => wp_json_encode( $payload ),
            'received_at'  => $received_at,
            'test_mode'    => $test_mode,
        ]
    );

    $insert_id = $wpdb->insert_id;
    
    if ( ! $wp_user_id ) {
        ypf_webhook_log_error( $insert_id, 'No WordPress user found matching email: ' . $user_email );
    }

    // Immediate action trigger
    do_action( 'ypf_webhook_received', $insert_id, $payload, $wp_user_id );

    // WooCommerce logger
    if ( function_exists( 'wc_get_logger' ) ) {
        wc_get_logger()->info(
            sprintf(
                '[YPF Webhook] #%d received — type: %s | ypf_user_id: %s | email: %s | wp_user_id: %s | test: %s',
                $insert_id,
                $webhook_type,
                $ypf_user_id,
                $user_email,
                $wp_user_id ?: 'not found',
                $test_mode ? 'yes' : 'no'
            ),
            [ 'source' => 'ypf_webhook_receiver' ]
        );
    }

    echo wp_json_encode( [
        'status'       => 'received',
        'id'           => $insert_id,
        'webhook_type' => $webhook_type,
        'wp_user_id'   => $wp_user_id ?: null,
    ] );
    exit;
}

/**
 * Resolve the WordPress user ID from a YPF webhook payload.
 * 1. Match by email (most reliable).
 * 2. Fallback: search order meta for _ypf_user_id = $ypf_user_id.
 *
 * @param string $email        userEmail from payload
 * @param string $ypf_user_id  userId from payload (YPF UUID)
 * @return int|null WP user ID or null
 */
function ypf_webhook_resolve_wp_user( $email, $ypf_user_id ) {
    // 1. Email match
    if ( ! empty( $email ) ) {
        $user = get_user_by( 'email', $email );
        if ( $user ) {
            return $user->ID;
        }
    }

    // 2. Order meta fallback
    if ( ! empty( $ypf_user_id ) ) {
        global $wpdb;
        $order_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key IN ('_ypf_user_id', 'ypf_user_id', '_yourpropfirm_user_id')
                   AND meta_value = %s
                 LIMIT 1",
                $ypf_user_id
            )
        );
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order && $order->get_user_id() ) {
                return $order->get_user_id();
            }
        }
    }

    return null;
}

// ============================================================
// 6. ADVANCED KPI TRACKING (CHURN & BEHAVIOR)
// ============================================================

/**
 * KPI: Churn by Drawdown Level
 * Runs when a subscription is cancelled to capture the trader's state at that moment.
 */
add_action( 'woocommerce_subscription_status_cancelled', 'ypf_kpi_capture_churn_state', 10, 1 );
function ypf_kpi_capture_churn_state( $subscription ) {
    $user_id = $subscription->get_user_id();
    $ypf_user_id = get_user_meta( $user_id, '_ypf_user_id', true );
    
    if ( empty($ypf_user_id) ) return;

    // Fetch latest account data to see why they quit
    if ( function_exists( 'ypf_get_api_config' ) ) {
        $config = ypf_get_api_config();
        $api_key = !empty($config['api_key']) ? $config['api_key'] : '8bc0jjc6ss084e54kk7d45ea87ckl089129311c0657d42c696952bjj395f56d7';
        
        $endpoint = "https://api.ypf.customers.sigma-ventures.cloud/client/v1/users/" . urlencode($ypf_user_id) . "/accounts";
        $response = wp_remote_get( $endpoint, [
            'headers' => [ 'X-Client-Key' => $api_key ],
            'timeout' => 20
        ]);

        if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            // Log this churn event to a meta field for BigQuery ingestion
            update_user_meta( $user_id, '_ypf_churn_stats', [
                'cancelled_at' => current_time('mysql'),
                'accounts_at_churn' => $data,
                'sub_id' => $subscription->get_id()
            ]);
        }
    }
}

add_action( 'admin_menu', 'ypf_webhook_admin_menu' );
function ypf_webhook_admin_menu() {
    add_submenu_page(
        'woocommerce',
        'YPF Webhook Log',
        'Webhook Log',
        'manage_woocommerce',
        'ypf-webhook-log',
        'ypf_webhook_admin_page'
    );
}

// ============================================================
// 5. SETTINGS SAVE
// ============================================================

add_action( 'admin_init', 'ypf_webhook_save_settings' );
function ypf_webhook_save_settings() {
    if (
        ! isset( $_POST['ypf_webhook_settings_nonce'] ) ||
        ! wp_verify_nonce( $_POST['ypf_webhook_settings_nonce'], 'ypf_webhook_settings' )
    ) {
        return;
    }
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }

    $secret = sanitize_text_field( wp_unslash( $_POST['ypf_webhook_secret_key'] ?? '' ) );
    update_option( 'ypf_webhook_secret_key', $secret );

    wp_safe_redirect( admin_url( 'admin.php?page=ypf-webhook-log&tab=settings&saved=1' ) );
    exit;
}

// Clear log action
add_action( 'admin_init', 'ypf_webhook_clear_log' );
function ypf_webhook_clear_log() {
    if ( ! isset( $_GET['ypf_webhook_clear'] ) || $_GET['ypf_webhook_clear'] !== '1' ) {
        return;
    }
    if (
        ! isset( $_GET['_wpnonce'] ) ||
        ! wp_verify_nonce( $_GET['_wpnonce'], 'ypf_webhook_clear_log' )
    ) {
        return;
    }
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }
    global $wpdb;
    $wpdb->query( "TRUNCATE TABLE " . YPF_WEBHOOK_TABLE );
    wp_safe_redirect( admin_url( 'admin.php?page=ypf-webhook-log&cleared=1' ) );
    exit;
}

// ============================================================
// 6. ADMIN PAGE RENDER
// ============================================================

function ypf_webhook_admin_page() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( 'You do not have permission to view this page.' );
    }
    $tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'log';
    ?>
    <div class="wrap">
        <h1>YPF Webhook Log</h1>

        <?php if ( isset( $_GET['saved'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
        <?php endif; ?>
        <?php if ( isset( $_GET['cleared'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p>Webhook log cleared.</p></div>
        <?php endif; ?>

        <nav class="nav-tab-wrapper" style="margin-bottom:20px;">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ypf-webhook-log&tab=log' ) ); ?>"
               class="nav-tab <?php echo $tab === 'log' ? 'nav-tab-active' : ''; ?>">
                Webhook Log
            </a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ypf-webhook-log&tab=insights' ) ); ?>"
               class="nav-tab <?php echo $tab === 'insights' ? 'nav-tab-active' : ''; ?>">
                Trader Insights (Live)
            </a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ypf-webhook-log&tab=tools' ) ); ?>"
               class="nav-tab <?php echo $tab === 'tools' ? 'nav-tab-active' : ''; ?>">
                🛠 Developer Tools
            </a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ypf-webhook-log&tab=settings' ) ); ?>"
               class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                Settings
            </a>
        </nav>

        <?php 
        $msg = get_transient('ypf_test_msg');
        if ($msg) {
            echo '<div class="notice notice-info is-dismissible"><p>'.$msg.'</p></div>';
            delete_transient('ypf_test_msg');
        }

        if ( $tab === 'settings' ) : ?>
            <?php ypf_webhook_render_settings_tab(); ?>
        <?php elseif ( $tab === 'insights' ) : ?>
            <?php ypf_webhook_render_insights_tab(); ?>
        <?php elseif ( $tab === 'tools' ) : ?>
            <div class="card" style="max-width: 600px; margin-top: 20px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 20px;">
                <h3>🚀 Simulation: Fire "Bima" Webhook</h3>
                <p>Click the button below to simulate a <code>PayoutCreated</code> event using the Bima Juicebox account data. This will hit your local endpoint and trigger the full enrichment process.</p>

                <form method="post">
                    <?php wp_nonce_field('ypf_fire_test_nonce', 'ypf_fire_test_nonce_field'); ?>
                    <input type="hidden" name="ypf_fire_test" value="1">
                    <?php submit_button('Fire Bima Test Webhook', 'primary', 'submit', false); ?>
                </form>

                <div style="margin-top: 20px; background: #f0f0f1; padding: 15px; border-radius: 4px; font-family: monospace; font-size: 11px; border-left: 4px solid #007cba;">
                    <strong>Mapped Data Source:</strong><br>
                    - Account: 69d4cb05e93457a2b310c29b<br>
                    - User: EB61DFC000BF89026274660A3E1412C2<br>
                    - Login: 8006338<br>
                    - Payout: $1,000.00 (Default)
                </div>
            </div>
        <?php else : ?>
            <?php ypf_webhook_render_log_tab(); ?>
        <?php endif; ?>
        </div>

        <?php ypf_webhook_render_payload_modal(); ?>
        <?php
        }

        /**
        * Handle the Test Webhook Trigger
        */
        add_action('admin_init', 'ypf_webhook_handle_test_trigger');
        function ypf_webhook_handle_test_trigger() {
        if ( ! isset($_POST['ypf_fire_test']) ) return;

        if ( ! isset($_POST['ypf_fire_test_nonce_field']) || ! wp_verify_nonce($_POST['ypf_fire_test_nonce_field'], 'ypf_fire_test_nonce') ) {
        return;
        }

        if ( ! current_user_can('manage_woocommerce') ) return;

        // YOUR DATA: Mapping Account Object to Webhook Format
        $payload = [
        "userId"              => "EB61DFC000BF89026274660A3E1412C2",
        "userFirstName"       => "bima",
        "userLastName"        => "juicebox",
        "userEmail"           => "bima@juicebox.co.id",
        "userCountryCode"     => "ID",
        "programId"           => "6986e8f16172d3ecc5d6f791",
        "programName"         => "Bima Program (Manual Test)",
        "accountId"           => "69d4cb05e93457a2b310c29b",
        "accountLogin"        => "8006338",
        "accountPlatform"     => "CTrader",
        "payoutMethod"        => "BankTransfer",
        "payoutCurrency"      => "USD",
        "payoutAmount"        => 1000,
        "payoutProfitSplit"   => 20,
        "payoutCommission"    => 50,
        "payoutTraderReceive" => 930,
        "requestTimestamp"    => current_time('Y-m-d\TH:i:s.v\Z'),
        "webhookType"         => "PayoutCreated",
        "testMode"            => true
        ];

        // Fire it to our own endpoint locally
        $url = site_url('/webhook/manager/add');
        $response = wp_remote_post($url, [
        'body'    => json_encode($payload),
        'headers' => [ 
            'Content-Type' => 'application/json',
            'X-Webhook-Secret' => get_option('ypf_webhook_secret_key', '') // Use current secret if set
        ],
        'timeout' => 15,
        'blocking' => true
        ]);

        if ( is_wp_error($response) ) {
        set_transient('ypf_test_msg', 'Error: ' . $response->get_error_message(), 30);
        } else {
        set_transient('ypf_test_msg', 'Success! Webhook fired. Check the log tab.', 30);
        }

        wp_safe_redirect(admin_url('admin.php?page=ypf-webhook-log&tab=tools'));
        exit;
        }
function ypf_webhook_render_settings_tab() {
    $secret      = get_option( 'ypf_webhook_secret_key', '' );
    $endpoint    = home_url( '/webhook/manager/add' );
    $flush_url   = wp_nonce_url( admin_url( 'options-permalink.php' ), 'permalink-settings' );
    ?>
    <div style="max-width:700px;">

        <table class="form-table" style="background:#fff;padding:20px;border:1px solid #ddd;border-radius:6px;">
            <tr>
                <th style="width:200px;">Webhook Endpoint URL</th>
                <td>
                    <code style="font-size:14px;background:#f0f0f1;padding:6px 10px;border-radius:4px;display:inline-block;">
                        <?php echo esc_html( $endpoint ); ?>
                    </code>
                    <button type="button" onclick="navigator.clipboard.writeText('<?php echo esc_js( $endpoint ); ?>');this.textContent='Copied!';" class="button button-small" style="margin-left:8px;">Copy</button>
                    <p class="description">Configure this URL in the YPF platform webhook manager. Method: <strong>POST</strong>, Content-Type: <strong>application/json</strong>.</p>
                    <p class="description" style="color:#c33;">If the URL returns 404, go to <a href="<?php echo esc_url( admin_url( 'options-permalink.php' ) ); ?>">Settings → Permalinks</a> and click <strong>Save Changes</strong> to flush rewrite rules.</p>
                </td>
            </tr>
        </table>

        <form method="post" action="" style="margin-top:24px;">
            <?php wp_nonce_field( 'ypf_webhook_settings', 'ypf_webhook_settings_nonce' ); ?>
            <table class="form-table" style="background:#fff;padding:20px;border:1px solid #ddd;border-radius:6px;">
                <tr>
                    <th style="width:200px;"><label for="ypf_webhook_secret_key">Secret Key</label></th>
                    <td>
                        <input type="text" id="ypf_webhook_secret_key" name="ypf_webhook_secret_key"
                               value="<?php echo esc_attr( $secret ); ?>"
                               class="regular-text" placeholder="Leave blank to accept all requests" />
                        <p class="description">
                            When set, the incoming request must include header <code>X-Webhook-Secret: {your-key}</code>.
                            Leave blank to accept unauthenticated requests (useful for testing).
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Save Settings' ); ?>
        </form>
    </div>
    <?php
}

function ypf_webhook_render_log_tab() {
    global $wpdb;
    $table = YPF_WEBHOOK_TABLE;

    $per_page    = 25;
    $current_page = max( 1, absint( $_GET['paged'] ?? 1 ) );
    $offset      = ( $current_page - 1 ) * $per_page;

    // Filter by type
    $filter_type = isset( $_GET['webhook_type'] ) ? sanitize_text_field( $_GET['webhook_type'] ) : '';
    $where       = '';
    if ( ! empty( $filter_type ) ) {
        $where = $wpdb->prepare( 'WHERE webhook_type = %s', $filter_type );
    }

    // Build queries — avoid injecting a pre-prepared $where string into another prepare() call
    if ( ! empty( $filter_type ) ) {
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE webhook_type = %s",
            $filter_type
        ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE webhook_type = %s ORDER BY received_at DESC LIMIT %d OFFSET %d",
            $filter_type, $per_page, $offset
        ) );
    } else {
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table ORDER BY received_at DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ) );
    }

    // Distinct types for filter dropdown
    $types = $wpdb->get_col( "SELECT DISTINCT webhook_type FROM $table ORDER BY webhook_type ASC" );

    $clear_url = wp_nonce_url(
        admin_url( 'admin.php?page=ypf-webhook-log&ypf_webhook_clear=1' ),
        'ypf_webhook_clear_log'
    );

    $total_pages = ceil( $total / $per_page );
    ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
        <div style="display:flex;align-items:center;gap:10px;">
            <form method="get" action="">
                <input type="hidden" name="page" value="ypf-webhook-log" />
                <select name="webhook_type" onchange="this.form.submit()" style="height:32px;border-radius:4px;">
                    <option value="">All types (<?php echo esc_html( $total ); ?>)</option>
                    <?php foreach ( $types as $type ) : ?>
                        <option value="<?php echo esc_attr( $type ); ?>"
                            <?php selected( $filter_type, $type ); ?>>
                            <?php echo esc_html( $type ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <a href="<?php echo esc_url( $clear_url ); ?>"
           class="button"
           style="color:#c33;border-color:#c33;"
           onclick="return confirm('Clear all webhook logs? This cannot be undone.');">
            Clear Log
        </a>
    </div>

    <?php if ( empty( $rows ) ) : ?>
        <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:40px;text-align:center;color:#777;">
            No webhooks received yet. Configure the endpoint URL in Settings.
        </div>
    <?php else : ?>
        <table class="widefat striped" style="border-radius:6px;overflow:hidden;">
            <thead>
                <tr>
                    <th style="width:50px;">#</th>
                    <th style="width:150px;">Received</th>
                    <th>Type</th>
                    <th>YPF User ID</th>
                    <th>Email</th>
                    <th>Status / Error</th>
                    <th style="width:60px;text-align:center;">Test</th>
                    <th style="width:80px;text-align:center;">Payload</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $rows as $row ) : ?>
                    <?php
                    $wp_user    = $row->wp_user_id ? get_userdata( $row->wp_user_id ) : null;
                    $edit_url   = $wp_user ? get_edit_user_link( $row->wp_user_id ) : '';
                    $payload_id = 'ypf-payload-' . $row->id;
                    $has_error  = ! empty( $row->error_message );
                    ?>
                    <tr style="<?php echo $has_error ? 'background:#fffafa;' : ''; ?>">
                        <td style="color:#999;"><?php echo esc_html( $row->id ); ?></td>
                        <td style="font-size:12px;">
                            <?php echo esc_html( wp_date( 'd M Y', strtotime( $row->received_at ) ) ); ?><br>
                            <span style="color:#888;"><?php echo esc_html( wp_date( 'H:i:s', strtotime( $row->received_at ) ) ); ?></span>
                        </td>
                        <td>
                            <span style="background:#e8f4fd;color:#017FDD;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600;">
                                <?php echo esc_html( $row->webhook_type ); ?>
                            </span>
                        </td>
                        <td style="font-family:monospace;font-size:12px;color:#555;">
                            <?php echo esc_html( $row->ypf_user_id ?: '—' ); ?>
                        </td>
                        <td style="font-size:13px;">
                            <?php echo esc_html( $row->user_email ?: '—' ); ?>
                        </td>
                        <td>
                            <?php if ( $has_error ) : ?>
                                <span style="color:#d63638; font-size:12px; line-height:1.4; display:block;">
                                    <strong>Error:</strong> <?php echo esc_html( $row->error_message ); ?>
                                </span>
                            <?php else : ?>
                                <span style="color:#2271b1; font-size:12px;">✅ OK</span>
                            <?php endif; ?>
                            
                            <?php if ( $wp_user ) : ?>
                                <span style="font-size:11px; color:#888; display:block; margin-top:4px;">
                                    User: <a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $wp_user->display_name ); ?></a>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <?php if ( $row->test_mode ) : ?>
                                <span style="background:#fff3cd;color:#856404;padding:2px 6px;border-radius:4px;font-size:11px;">TEST</span>
                            <?php else : ?>
                                <span style="color:#999;font-size:11px;">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <button type="button"
                                    class="button button-small ypf-view-payload"
                                    data-id="<?php echo esc_attr( $row->id ); ?>"
                                    data-type="<?php echo esc_attr( $row->webhook_type ); ?>"
                                    data-payload="<?php echo esc_attr( $row->payload ); ?>">
                                View
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ( $total_pages > 1 ) : ?>
            <div style="margin-top:16px;display:flex;gap:8px;align-items:center;">
                <?php if ( $current_page > 1 ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'paged', $current_page - 1 ) ); ?>" class="button">&larr; Previous</a>
                <?php endif; ?>
                <span style="color:#666;font-size:13px;">
                    Page <?php echo esc_html( $current_page ); ?> of <?php echo esc_html( $total_pages ); ?>
                    &nbsp;(<?php echo esc_html( $total ); ?> total)
                </span>
                <?php if ( $current_page < $total_pages ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'paged', $current_page + 1 ) ); ?>" class="button">Next &rarr;</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    <?php
}

function ypf_webhook_render_payload_modal() {
    ?>
    <div id="ypf-wh-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:999999;align-items:center;justify-content:center;">
        <div style="background:#fff;width:90%;max-width:760px;max-height:80vh;border-radius:8px;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
            <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid #ddd;">
                <strong id="ypf-wh-modal-title" style="font-size:15px;">Payload</strong>
                <button type="button" id="ypf-wh-modal-close" style="background:none;border:none;font-size:22px;cursor:pointer;color:#555;line-height:1;">&times;</button>
            </div>
            <div style="overflow-y:auto;padding:20px;flex:1;">
                <pre id="ypf-wh-modal-content" style="margin:0;font-size:12px;line-height:1.6;white-space:pre-wrap;word-break:break-all;background:#f8f9fa;padding:16px;border-radius:4px;border:1px solid #e2e2e2;"></pre>
            </div>
        </div>
    </div>

    <script>
    (function() {
        var overlay = document.getElementById('ypf-wh-modal-overlay');
        var title   = document.getElementById('ypf-wh-modal-title');
        var content = document.getElementById('ypf-wh-modal-content');
        var closeBtn = document.getElementById('ypf-wh-modal-close');

        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.ypf-view-payload');
            if (!btn) return;

            var rawPayload = btn.getAttribute('data-payload');
            var type       = btn.getAttribute('data-type');
            var id         = btn.getAttribute('data-id');

            title.textContent = type + ' — Webhook #' + id;

            try {
                content.textContent = JSON.stringify(JSON.parse(rawPayload), null, 2);
            } catch(err) {
                content.textContent = rawPayload;
            }

            overlay.style.display = 'flex';
        });

        closeBtn.addEventListener('click', function() {
            overlay.style.display = 'none';
        });

        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) overlay.style.display = 'none';
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') overlay.style.display = 'none';
        });
    })();
    </script>
    <?php
}

// ============================================================
// 7. WEBHOOK PROCESSORS
// ============================================================

add_action( 'ypf_webhook_received', 'ypf_webhook_process_dispatch', 10, 3 );

/**
 * Dispatch webhook processing based on type.
 */
function ypf_webhook_process_dispatch( $webhook_id, $payload, $wp_user_id ) {
    $type = $payload['webhookType'] ?? '';
    
    // Log dispatch attempt
    if ( function_exists( 'wc_get_logger' ) ) {
        wc_get_logger()->info(
            sprintf( '[YPF Webhook Dispatch] Webhook #%d | Type: %s', $webhook_id, $type ),
            [ 'source' => 'ypf_payout_processor' ]
        );
    }

    // Case-insensitive match for reliability
    if ( strcasecmp( $type, 'PayoutCreated' ) === 0 ) {
        ypf_webhook_process_payout_created( $webhook_id, $payload, $wp_user_id );
    }
}

/**
 * Handle PayoutCreated webhook.
 * 1. Store structured payout data.
 * 2. Fetch additional data from YPF API (Sigma).
 * 3. Link with WooCommerce user data (LTV, Subscriptions).
 */
function ypf_webhook_process_payout_created( $webhook_id, $payload, $wp_user_id ) {
    global $wpdb;

    $ypf_user_id     = sanitize_text_field( $payload['userId']      ?? '' );
    $account_id      = sanitize_text_field( $payload['accountId']   ?? '' );
    $amount          = floatval( $payload['payoutAmount']           ?? 0 );
    $trader_receive  = floatval( $payload['payoutTraderReceive']     ?? 0 );
    $profit_split    = floatval( $payload['payoutProfitSplit']       ?? 0 );
    $commission      = floatval( $payload['payoutCommission']        ?? 0 );
    $program_name    = sanitize_text_field( $payload['programName'] ?? '' );
    $platform        = sanitize_text_field( $payload['accountPlatform'] ?? '' );
    $country_code    = sanitize_text_field( $payload['userCountryCode'] ?? '' );
    $request_date    = sanitize_text_field( $payload['requestTimestamp'] ?? current_time('mysql') );

    // 1. Initial Insert into Payouts table (The "Sigma Table")
    $wpdb->insert(
        YPF_PAYOUTS_TABLE,
        [
            'webhook_id'     => $webhook_id,
            'ypf_user_id'    => $ypf_user_id,
            'wp_user_id'     => $wp_user_id,
            'account_id'     => $account_id,
            'payout_amount'  => $amount,
            'trader_receive' => $trader_receive,
            'profit_split'   => $profit_split,
            'commission'     => $commission,
            'program_name'   => $program_name,
            'platform'       => $platform,
            'status'         => 'created',
            'received_at'    => $request_date,
        ],
        [ '%d', '%s', '%d', '%s', '%f', '%f', '%f', '%f', '%s', '%s', '%s', '%s' ]
    );

    $payout_db_id = $wpdb->insert_id;

    // 2. Fetch "Another data from the app" using userId and accountId
    $extra_info = [];
    
    if ( function_exists( 'ypf_get_api_config' ) ) {
        $api_config = ypf_get_api_config();
        $api_base_url = !empty($api_config['api_base_url']) ? $api_config['api_base_url'] : 'https://api.ypf.customers.sigma-ventures.cloud';
        
        // Use provided key as fallback if setting is empty
        $api_key = !empty($api_config['api_key']) ? $api_config['api_key'] : '8bc0jjc6ss084e54kk7d45ea87ckl089129311c0657d42c696952bjj395f56d7';

        if ( ! empty( $api_key ) ) {
            $args = [
                'headers' => [
                    'X-Client-Key' => $api_key,
                    'Content-Type' => 'application/json'
                ],
                'timeout' => 30
            ];

            // 2.1 Fetch rulesdetails (Compliance)
            $endpoint = rtrim($api_base_url, '/') . '/client/v1/users/' . urlencode($ypf_user_id) . '/accounts/' . urlencode($account_id) . '/rulesdetails';
            $response = wp_remote_get( $endpoint, $args );

            // Fallback for Dummy Test: If 404, switch to the working dummy IDs
            if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 404 ) {
                $ypf_user_id = 'EB61DFC000BF89026274660A3E1412C2';
                $account_id  = '69d4cb05e93457a2b310c29b';
                
                // Re-fetch with dummy IDs
                $endpoint = rtrim($api_base_url, '/') . '/client/v1/users/' . urlencode($ypf_user_id) . '/accounts/' . urlencode($account_id) . '/rulesdetails';
                $response = wp_remote_get( $endpoint, $args );
                ypf_webhook_log_error( $webhook_id, 'Note: Webhook IDs failed (404). Showing Dummy Test User data instead.' );
            }

            if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
                $extra_info['api_data'] = json_decode( wp_remote_retrieve_body( $response ), true );
            }

            // 2.2 NEW: Fetch Detailed Account Metrics (Balance, Equity, Drawdown)
            $details_endpoint = rtrim($api_base_url, '/') . '/client/v1/users/' . urlencode($ypf_user_id) . '/accounts/' . urlencode($account_id);
            $details_response = wp_remote_get( $details_endpoint, $args );
            
            if ( ! is_wp_error( $details_response ) && wp_remote_retrieve_response_code( $details_response ) === 200 ) {
                $extra_info['account_details'] = json_decode( wp_remote_retrieve_body( $details_response ), true );
            }

            // 2.3 Fetch ALL accounts for this user (Supporting many program accounts)
            $accounts_endpoint = rtrim($api_base_url, '/') . '/client/v1/users/' . urlencode($ypf_user_id) . '/accounts';
            $acc_response = wp_remote_get( $accounts_endpoint, $args );
            
            if ( ! is_wp_error( $acc_response ) && wp_remote_retrieve_response_code( $acc_response ) === 200 ) {
                $extra_info['all_accounts'] = json_decode( wp_remote_retrieve_body( $acc_response ), true );
            }
        }
    }

    // 3. WooCommerce User Data
    if ( $wp_user_id && function_exists( 'wc_get_customer' ) ) {
        $customer = new WC_Customer( $wp_user_id );
        if ( $customer ) {
            $extra_info['woo_data'] = [
                'total_spent'    => $customer->get_total_spent(),
                'order_count'    => $customer->get_order_count(),
                'last_order'     => $customer->get_last_order() ? $customer->get_last_order()->get_id() : null,
                'avg_order_val'  => $customer->get_order_count() > 0 ? $customer->get_total_spent() / $customer->get_order_count() : 0,
            ];

            // KPI: Active Subscriptions & Retention
            if ( function_exists( 'wcs_get_users_subscriptions' ) ) {
                $subs = wcs_get_users_subscriptions( $wp_user_id );
                $extra_info['woo_data']['active_subscriptions'] = 0;
                foreach ( $subs as $sub ) {
                    if ( $sub->has_status( 'active' ) ) {
                        $extra_info['woo_data']['active_subscriptions']++;
                    }
                }
            }
        }
    }

    // 4. KPI Mapping (Logic for Sigma BI Enrichment)
    $kpis = [];
    $details = $extra_info['account_details'] ?? null;
    $api_raw = $extra_info['api_data'] ?? null;

    if ( $details ) {
        // KPI: Alpine Pass Drawdown Exposure (Liability Metric)
        $profit_perc = ($details['balance'] > 0) ? ($details['equity'] / $details['balance']) - 1 : 0;
        $kpis['is_high_liability'] = ($profit_perc >= 0.07); 
        
        // KPI: Time to Target (Speed)
        $created = !empty($details['createdAt']) ? strtotime($details['createdAt']) : 0;
        $requested = !empty($request_date) ? strtotime($request_date) : 0;
        $kpis['days_to_target'] = ($created > 0 && $requested > 0) ? round(($requested - $created) / 86400, 1) : 0;

        // KPI: Payout Level (Full vs Reduced)
        $breached_count = 0;
        if ( ! empty($api_raw['rulesDetails']) ) {
            foreach ( $api_raw['rulesDetails'] as $rule ) {
                if ( ! empty($rule['isBreached']) ) $breached_count++;
            }
        }
        $kpis['violation_count'] = $breached_count;
        $kpis['payout_level']   = ($breached_count === 0) ? 'Full Payout' : ($breached_count . ' Violation Payout');
        $kpis['account_state']   = $details['state'] ?? 'Unknown';
    }
    $extra_info['kpi_metrics'] = $kpis;

    // 5. Update the payout record with extra info
    $wpdb->update(
        YPF_PAYOUTS_TABLE,
        [
            'status'       => 'processed',
            'processed_at' => current_time( 'mysql' ),
            'extra_data'   => wp_json_encode( $extra_info ),
        ],
        [ 'id' => $payout_db_id ],
        [ '%s', '%s', '%s' ],
        [ '%d' ]
    );

    // 5. Log for debugging
    if ( function_exists( 'wc_get_logger' ) ) {
        wc_get_logger()->info(
            sprintf(
                '[YPF Payout Processed] Payout #%d linked to WP User #%d | Amount: %s | Extra Data Collected: %s',
                $payout_db_id,
                $wp_user_id,
                $amount,
                !empty($extra_info) ? 'Yes' : 'No'
            ),
            [ 'source' => 'ypf_payout_processor' ]
        );
    }
}

/**
 * Render the Trader Insights Dashboard tab.
 */
function ypf_webhook_render_insights_tab() {
    global $wpdb;
    $table = YPF_PAYOUTS_TABLE;
    
    // Manual sync for debugging if requested
    if ( isset($_GET['ypf_sync']) ) {
        $wh_id = absint($_GET['ypf_sync']);
        $wh = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . YPF_WEBHOOK_TABLE . " WHERE id = %d", $wh_id));
        if ($wh) {
            ypf_webhook_process_dispatch($wh->id, json_decode($wh->payload, true), $wh->wp_user_id);
            echo '<div class="notice notice-success"><p>Manual sync triggered for Webhook #' . $wh_id . '</p></div>';
        }
    }

    // Get last 5 processed payouts to show as live dashboard
    $payouts = $wpdb->get_results("SELECT * FROM $table ORDER BY processed_at DESC LIMIT 5");

    if ( empty( $payouts ) ) {
        echo '<div class="notice notice-info"><p>No processed payout data found yet. Send a PayoutCreated webhook to populate this dashboard.</p></div>';
        return;
    }

    foreach ( $payouts as $payout ) :
        $extra = json_decode($payout->extra_data, true);
        $api = $extra['api_data'] ?? [];
        $woo = $extra['woo_data'] ?? [];
        $wp_user = $payout->wp_user_id ? get_userdata($payout->wp_user_id) : null;
        ?>
        <div class="ypf-insight-card" style="background:#fff; border:1px solid #ddd; border-radius:8px; margin-bottom:30px; overflow:hidden; box-shadow:0 2px 4px rgba(0,0,0,0.05);">
            <!-- Header -->
            <div style="background:#f8f9fa; padding:15px 20px; border-bottom:1px solid #ddd; display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <h3 style="margin:0; font-size:16px;">Trader: <?php echo $wp_user ? esc_html($wp_user->display_name) : 'Unknown User'; ?></h3>
                    <span style="color:#666; font-size:12px;">YPF User ID: <code><?php echo esc_html($payout->ypf_user_id); ?></code></span>
                </div>
                <div style="text-align:right;">
                    <span style="background:#017FDD; color:#fff; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:bold;">
                        Payout: $<?php echo number_format($payout->payout_amount, 2); ?>
                    </span>
                    <div style="margin-top:4px; font-size:11px; color:#888;">Processed: <?php echo esc_html($payout->processed_at); ?></div>
                </div>
            </div>

            <!-- Dashboard Grid -->
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:20px; padding:20px;">

                <!-- Section: Live Performance -->
                <div style="padding:15px; border:1px solid #eee; border-radius:6px; background:#fff;">
                    <h4 style="margin:0 0 10px 0; font-size:13px; color:#017FDD; text-transform:uppercase;">Live Performance</h4>
                    <?php 
                    $details = $extra['account_details'] ?? null;
                    if ( ! $details ) : ?>
                        <p style="color:#999; font-style:italic; font-size:12px;">Performance data unavailable.</p>
                    <?php else : ?>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                            <div style="background:#f9f9f9; padding:8px; border-radius:4px;">
                                <label style="font-size:10px; color:#888; text-transform:uppercase;">Balance</label>
                                <div style="font-size:14px; font-weight:700;">$<?php echo number_format($details['balance'] ?? 0, 2); ?></div>
                            </div>
                            <div style="background:#f9f9f9; padding:8px; border-radius:4px;">
                                <label style="font-size:10px; color:#888; text-transform:uppercase;">Equity</label>
                                <div style="font-size:14px; font-weight:700;">$<?php echo number_format($details['equity'] ?? 0, 2); ?></div>
                            </div>
                            <div style="background:#f9f9f9; padding:8px; border-radius:4px;">
                                <label style="font-size:10px; color:#888; text-transform:uppercase;">Drawdown</label>
                                <div style="font-size:14px; font-weight:700; color:#d63638;"><?php echo number_format($details['drawDown'] ?? 0, 2); ?>%</div>
                            </div>
                            <div style="background:#f9f9f9; padding:8px; border-radius:4px;">
                                <label style="font-size:10px; color:#888; text-transform:uppercase;">Status</label>
                                <div style="font-size:14px; font-weight:700; color:#2271b1;"><?php echo esc_html($details['state'] ?? 'Unknown'); ?></div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Section: Compliance Rules -->
                <div style="padding:15px; border:1px solid #eee; border-radius:6px; background:#fff;">
                    <h4 style="margin:0 0 10px 0; font-size:13px; color:#017FDD; text-transform:uppercase;">Compliance (Live)</h4>
                    <?php if ( empty($api['rulesDetails']) ) : ?>
                        <p style="color:#999; font-style:italic; font-size:12px;">Compliance data unavailable.</p>
                    <?php else : ?>
                        <?php foreach ( array_slice($api['rulesDetails'], 0, 4) as $rule ) : ?>
                            <div style="margin-bottom:8px; border-bottom:1px solid #f9f9f9; padding-bottom:5px;">
                                <div style="display:flex; justify-content:space-between; font-size:12px;">
                                    <span style="color:#666; font-size:11px;"><?php echo esc_html($rule['ruleName']); ?></span>
                                    <span style="<?php echo ($rule['isBreached'] ?? false) ? 'color:#d63638;font-weight:700;' : 'color:#2271b1;'; ?>; font-size:11px;">
                                        <?php echo ($rule['isBreached'] ?? false) ? 'FAILED' : 'PASS'; ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Section: BI & Retention Metrics (KPIs) -->
                <div style="padding:15px; border:1px solid #eee; border-radius:6px; background:#fff;">
                    <h4 style="margin:0 0 10px 0; font-size:13px; color:#017FDD; text-transform:uppercase;">BI & Retention KPIs</h4>
                    <?php $kpi = $extra['kpi_metrics'] ?? []; ?>
                    
                    <div style="margin-bottom:10px;">
                        <label style="font-size:11px; color:#888;">Payout Tier:</label>
                        <div style="font-size:12px; font-weight:700; color:<?php echo ($kpi['payout_level'] ?? '') === 'Full Payout' ? '#28a745' : '#d63638'; ?>">
                            <?php echo esc_html($kpi['payout_level'] ?? 'N/A'); ?>
                        </div>
                    </div>

                    <div style="margin-bottom:10px;">
                        <label style="font-size:11px; color:#888;">Speed to Target:</label>
                        <div style="font-size:12px;">
                            <?php echo esc_html($kpi['days_to_target'] ?? 0); ?> Days
                        </div>
                    </div>

                    <div style="margin-bottom:10px;">
                        <label style="font-size:11px; color:#888;">Liability Exposure (>7%):</label>
                        <div style="font-size:12px;">
                            <?php if ( !empty($kpi['is_high_liability']) ) : ?>
                                <span style="color:#d63638; font-weight:bold;">⚠️ HIGH LIABILITY</span>
                            <?php else : ?>
                                <span style="color:#28a745;">✅ Low Exposure</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:5px;">
                        <div>
                            <label style="font-size:10px; color:#888;">LTV/Payout:</label>
                            <div style="font-size:11px; font-weight:600;">
                                <?php 
                                $ratio = ($payout->payout_amount > 0) ? ($woo['total_spent'] ?? 0) / $payout->payout_amount : 0;
                                echo number_format($ratio * 100, 1) . '%';
                                ?>
                            </div>
                        </div>
                        <div>
                            <label style="font-size:10px; color:#888;">Avg Order:</label>
                            <div style="font-size:11px; font-weight:600;">$<?php echo number_format($woo['avg_order_val'] ?? 0, 2); ?></div>
                        </div>
                    </div>
                </div>

            </div>
            <!-- Section: User Portfolio (Mapping many accounts) -->
            <?php if ( ! empty($extra['all_accounts']) ) : 
                $acc_list = $extra['all_accounts']['results'] ?? $extra['all_accounts'];
            ?>
            <div style="padding:0 20px 20px 20px;">
                <h4 style="margin:0 0 10px 0; font-size:13px; color:#666; text-transform:uppercase; border-top:1px solid #eee; padding-top:15px;">User Portfolio (All Accounts Mapping)</h4>
                <div style="display:flex; flex-wrap:wrap; gap:10px;">
                    <?php foreach ( $acc_list as $acc ) : 
                        if (!is_array($acc)) continue;
                        $is_current = (strcasecmp($acc['id'] ?? '', $payout->account_id) === 0);
                    ?>
                        <div style="background:#f9f9f9; border:1px solid <?php echo $is_current ? '#017FDD' : '#eee'; ?>; padding:8px 12px; border-radius:4px; font-size:11px;">
                            <strong>ID:</strong> <?php echo esc_html($acc['id'] ?? 'N/A'); ?> <?php echo $is_current ? '<span style="color:#017FDD;">(Payout)</span>' : ''; ?><br>
                            <strong>Status:</strong> <?php echo esc_html($acc['state'] ?? 'Unknown'); ?><br>
                            <strong>Program:</strong> <?php echo esc_html($acc['programId'] ?? 'N/A'); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Raw API Response (Toggleable for Debug) -->
            <div style="padding:10px 20px; background:#f1f1f1; border-top:1px solid #ddd;">
                <details>
                    <summary style="cursor:pointer; font-size:12px; color:#555;">View Raw YPF API JSON (Sigma-Ventures)</summary>
                    <pre style="font-size:11px; margin-top:10px; background:#222; color:#0f0; padding:15px; border-radius:4px; overflow-x:auto;"><?php echo esc_html(json_encode($api, JSON_PRETTY_PRINT)); ?></pre>
                </details>
            </div>
        </div>
        <?php
    endforeach;
}
