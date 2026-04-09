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

define( 'YPF_WEBHOOK_DB_VERSION', '1.1' );
define( 'YPF_WEBHOOK_TABLE', $GLOBALS['wpdb']->prefix . 'ypf_webhooks' );

// ============================================================
// 1. DATABASE TABLE
// ============================================================

add_action( 'init', 'ypf_webhook_maybe_create_table', 1 );
function ypf_webhook_maybe_create_table() {
    if ( get_option( 'ypf_webhook_db_version' ) === YPF_WEBHOOK_DB_VERSION ) {
        return;
    }
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $table   = YPF_WEBHOOK_TABLE;

    $sql = "CREATE TABLE $table (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        webhook_type VARCHAR(100)    NOT NULL DEFAULT '',
        ypf_user_id  VARCHAR(100)    NOT NULL DEFAULT '',
        user_email   VARCHAR(255)    NOT NULL DEFAULT '',
        wp_user_id   BIGINT UNSIGNED          DEFAULT NULL,
        payload      LONGTEXT        NOT NULL,
        received_at  DATETIME        NOT NULL,
        test_mode    TINYINT(1)      NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY webhook_type (webhook_type),
        KEY received_at (received_at)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
    update_option( 'ypf_webhook_db_version', YPF_WEBHOOK_DB_VERSION );
}

// ============================================================
// 2. ENDPOINT HANDLER  (/webhook/manager/add)
// Intercepts at plugins_loaded — before WordPress routing kicks in.
// This bypasses rewrite rules entirely and matches directly on REQUEST_URI,
// which is more reliable behind Herd/Valet's server.php proxy layer.
// ============================================================

add_action( 'plugins_loaded', 'ypf_webhook_intercept_request', 1 );
function ypf_webhook_intercept_request() {
    $uri  = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
    $path = trim( parse_url( $uri, PHP_URL_PATH ), '/' );

    if ( $path !== 'webhook/manager/add' ) {
        return;
    }

    // From this point we own the response — exit after sending it.

    status_header( 200 );
    header( 'Content-Type: application/json' );

    // Only POST
    if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
        status_header( 405 );
        echo wp_json_encode( [ 'error' => 'Method not allowed. Use POST.' ] );
        exit;
    }

    // Secret key check (if configured)
    $secret = get_option( 'ypf_webhook_secret_key', '' );
    if ( ! empty( $secret ) ) {
        $provided = isset( $_SERVER['HTTP_X_WEBHOOK_SECRET'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WEBHOOK_SECRET'] ) )
            : '';
        if ( ! hash_equals( $secret, $provided ) ) {
            status_header( 401 );
            echo wp_json_encode( [ 'error' => 'Unauthorized.' ] );
            exit;
        }
    }

    // Parse JSON body
    $raw     = file_get_contents( 'php://input' );
    $payload = json_decode( $raw, true );

    if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $payload ) ) {
        status_header( 400 );
        echo wp_json_encode( [ 'error' => 'Invalid JSON payload.' ] );
        exit;
    }

    // Extract core fields
    $ypf_user_id  = sanitize_text_field( $payload['userId']      ?? '' );
    $user_email   = sanitize_email(      $payload['userEmail']   ?? '' );
    $webhook_type = sanitize_text_field( $payload['webhookType'] ?? 'Unknown' );
    $test_mode    = ! empty( $payload['testMode'] ) ? 1 : 0;

    // Resolve WordPress user — primary: email match; fallback: order meta
    $wp_user_id = ypf_webhook_resolve_wp_user( $user_email, $ypf_user_id );

    // Persist to DB — wp_user_id omitted from insert when null so the column stores NULL (not 0)
    global $wpdb;
    if ( $wp_user_id ) {
        $wpdb->insert(
            YPF_WEBHOOK_TABLE,
            [
                'webhook_type' => $webhook_type,
                'ypf_user_id'  => $ypf_user_id,
                'user_email'   => $user_email,
                'wp_user_id'   => $wp_user_id,
                'payload'      => wp_json_encode( $payload ),
                'received_at'  => current_time( 'mysql' ),
                'test_mode'    => $test_mode,
            ],
            [ '%s', '%s', '%s', '%d', '%s', '%s', '%d' ]
        );
    } else {
        $wpdb->insert(
            YPF_WEBHOOK_TABLE,
            [
                'webhook_type' => $webhook_type,
                'ypf_user_id'  => $ypf_user_id,
                'user_email'   => $user_email,
                'payload'      => wp_json_encode( $payload ),
                'received_at'  => current_time( 'mysql' ),
                'test_mode'    => $test_mode,
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%d' ]
        );
    }

    $insert_id = $wpdb->insert_id;

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
// 4. ADMIN MENU
// ============================================================

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
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ypf-webhook-log&tab=settings' ) ); ?>"
               class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                Settings
            </a>
        </nav>

        <?php if ( $tab === 'settings' ) : ?>
            <?php ypf_webhook_render_settings_tab(); ?>
        <?php else : ?>
            <?php ypf_webhook_render_log_tab(); ?>
        <?php endif; ?>
    </div>

    <?php ypf_webhook_render_payload_modal(); ?>
    <?php
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
                    <th>WP User</th>
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
                    ?>
                    <tr>
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
                            <?php if ( $wp_user && $edit_url ) : ?>
                                <a href="<?php echo esc_url( $edit_url ); ?>" style="font-size:13px;">
                                    <?php echo esc_html( $wp_user->display_name ); ?>
                                </a>
                                <span style="font-size:11px;color:#888;display:block;">ID: <?php echo esc_html( $row->wp_user_id ); ?></span>
                            <?php elseif ( $wp_user ) : ?>
                                <?php echo esc_html( $wp_user->display_name ); ?>
                            <?php else : ?>
                                <span style="color:#c33;font-size:12px;">Not found</span>
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
