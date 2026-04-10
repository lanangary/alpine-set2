<?php
/**
 * Plugin Name: Alpine Funded — BigQuery Metrics
 * Description: Collects WooCommerce metrics, displays them on an admin dashboard,
 *              and streams hourly snapshots to Google BigQuery.
 */

defined('ABSPATH') || exit;

// ─── Constants ────────────────────────────────────────────────────────────────

define('ALPINE_BQ_OPTION_CREDENTIALS', 'alpine_bq_credentials');
define('ALPINE_BQ_OPTION_DATASET',     'alpine_bq_dataset');
define('ALPINE_BQ_OPTION_TABLE',       'alpine_bq_table');
define('ALPINE_BQ_CRON_HOOK',          'alpine_bq_hourly_snapshot');
define('ALPINE_BQ_TOKEN_TRANSIENT',    'alpine_bq_access_token');
define('ALPINE_BQ_ALPINE_PASS_SKUS',   ['AP-standard', 'AP-pro', 'AP-advance']);

// ─── Cron scheduling (mu-plugins don't fire activation hooks) ────────────────

add_action('init', function () {
    if (!wp_next_scheduled(ALPINE_BQ_CRON_HOOK)) {
        wp_schedule_event(time(), 'hourly', ALPINE_BQ_CRON_HOOK);
    }
});

// ─── Admin Menu ───────────────────────────────────────────────────────────────

add_action('admin_menu', function () {
    add_menu_page(
        'Alpine Metrics',
        'Alpine Metrics',
        'manage_options',
        'alpine-metrics',
        'alpine_bq_dashboard_page',
        'dashicons-chart-bar',
        56
    );
    add_submenu_page(
        'alpine-metrics',
        'Dashboard',
        'Dashboard',
        'manage_options',
        'alpine-metrics',
        'alpine_bq_dashboard_page'
    );
    add_submenu_page(
        'alpine-metrics',
        'Settings',
        'Settings',
        'manage_options',
        'alpine-metrics-settings',
        'alpine_bq_settings_page'
    );
});

// ─── Credentials helpers ─────────────────────────────────────────────────────

function alpine_bq_get_credentials(): ?array {
    $json = get_option(ALPINE_BQ_OPTION_CREDENTIALS, '');
    if (empty($json)) return null;
    return json_decode($json, true) ?: null;
}

function alpine_bq_get_dataset(): string {
    return get_option(ALPINE_BQ_OPTION_DATASET, 'alpine_funded');
}

function alpine_bq_get_table(): string {
    return get_option(ALPINE_BQ_OPTION_TABLE, 'metrics_hourly');
}

// ─── OAuth2 / JWT ─────────────────────────────────────────────────────────────

function alpine_bq_base64url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function alpine_bq_get_access_token(): ?string {
    $cached = get_transient(ALPINE_BQ_TOKEN_TRANSIENT);
    if ($cached) return $cached;

    $creds = alpine_bq_get_credentials();
    if (!$creds) return null;

    $now    = time();
    $header = alpine_bq_base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claim  = alpine_bq_base64url(json_encode([
        'iss'   => $creds['client_email'],
        'scope' => 'https://www.googleapis.com/auth/bigquery',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));

    $sig_input = $header . '.' . $claim;
    $pkey      = openssl_pkey_get_private($creds['private_key']);
    if (!$pkey) return null;

    openssl_sign($sig_input, $signature, $pkey, 'SHA256');
    $jwt = $sig_input . '.' . alpine_bq_base64url($signature);

    $response = wp_remote_post('https://oauth2.googleapis.com/token', [
        'body'    => [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ],
        'timeout' => 15,
    ]);

    if (is_wp_error($response)) return null;

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body['access_token'])) return null;

    $ttl = max(60, intval($body['expires_in'] ?? 3600) - 60);
    set_transient(ALPINE_BQ_TOKEN_TRANSIENT, $body['access_token'], $ttl);

    return $body['access_token'];
}

// ─── BigQuery API ─────────────────────────────────────────────────────────────

function alpine_bq_insert_rows(array $rows): true|string {
    $token = alpine_bq_get_access_token();
    $creds = alpine_bq_get_credentials();
    if (!$token || !$creds) return 'No credentials or failed to get token.';

    $project = $creds['project_id'];
    $dataset = alpine_bq_get_dataset();
    $table   = alpine_bq_get_table();
    
    // Upload API endpoint for Multipart jobs
    $url = "https://bigquery.googleapis.com/upload/bigquery/v2/projects/{$project}/jobs?uploadType=multipart";

    if (empty($rows)) return true;

    // 1. Prepare data (Newline Delimited JSON)
    $data_content = '';
    foreach ($rows as $r) {
        $data_content .= wp_json_encode($r) . "\n";
    }

    // 2. Prepare Metadata
    $metadata = [
        'configuration' => [
            'load' => [
                'destinationTable' => [
                    'projectId' => $project,
                    'datasetId' => $dataset,
                    'tableId'   => $table,
                ],
                'sourceFormat'     => 'NEWLINE_DELIMITED_JSON',
                'writeDisposition' => 'WRITE_APPEND',
            ]
        ]
    ];

    // 3. Build Multipart Body
    $boundary = wp_generate_password(24, false);
    $payload  = "--{$boundary}\r\n";
    $payload .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
    $payload .= wp_json_encode($metadata) . "\r\n";
    $payload .= "--{$boundary}\r\n";
    $payload .= "Content-Type: application/octet-stream\r\n\r\n";
    $payload .= $data_content . "\r\n";
    $payload .= "--{$boundary}--";

    $response = wp_remote_post($url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'multipart/related; boundary=' . $boundary,
        ],
        'body'    => $payload,
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        return $response->get_error_message();
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code !== 200) {
        $msg = $body['error']['message'] ?? wp_remote_retrieve_body($response);
        return 'Load Job Error (HTTP ' . $code . '): ' . $msg;
    }

    return true;
}

function alpine_bq_ensure_dataset(): true|string {
    $token = alpine_bq_get_access_token();
    $creds = alpine_bq_get_credentials();
    if (!$token || !$creds) return 'No credentials or failed to get token.';

    $project = $creds['project_id'];
    $dataset = alpine_bq_get_dataset();

    $check = wp_remote_get(
        "https://bigquery.googleapis.com/bigquery/v2/projects/{$project}/datasets/{$dataset}",
        ['headers' => ['Authorization' => 'Bearer ' . $token], 'timeout' => 10]
    );

    if (!is_wp_error($check) && wp_remote_retrieve_response_code($check) === 200) {
        return true;
    }

    $response = wp_remote_post(
        "https://bigquery.googleapis.com/bigquery/v2/projects/{$project}/datasets",
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode([
                'datasetReference' => ['projectId' => $project, 'datasetId' => $dataset],
                'location'         => 'US',
            ]),
            'timeout' => 15,
        ]
    );

    if (is_wp_error($response)) return $response->get_error_message();

    $code = wp_remote_retrieve_response_code($response);
    return in_array($code, [200, 201], true)
        ? true
        : 'Failed to create dataset (HTTP ' . $code . ')';
}

function alpine_bq_create_table(): true|string {
    $token = alpine_bq_get_access_token();
    $creds = alpine_bq_get_credentials();
    if (!$token || !$creds) return 'No credentials or failed to get token.';

    $project = $creds['project_id'];
    $dataset = alpine_bq_get_dataset();
    $table   = alpine_bq_get_table();

    $response = wp_remote_post(
        "https://bigquery.googleapis.com/bigquery/v2/projects/{$project}/datasets/{$dataset}/tables",
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode([
                'tableReference'   => [
                    'projectId' => $project,
                    'datasetId' => $dataset,
                    'tableId'   => $table,
                ],
                'schema'           => [
                    'fields' => [
                        ['name' => 'snapshot_at',        'type' => 'TIMESTAMP', 'mode' => 'REQUIRED'],
                        ['name' => 'total_revenue',       'type' => 'FLOAT',     'mode' => 'NULLABLE'],
                        ['name' => 'net_revenue',         'type' => 'FLOAT',     'mode' => 'NULLABLE'],
                        ['name' => 'order_count',         'type' => 'INTEGER',   'mode' => 'NULLABLE'],
                        ['name' => 'new_customers',       'type' => 'INTEGER',   'mode' => 'NULLABLE'],
                        ['name' => 'returning_customers', 'type' => 'INTEGER',   'mode' => 'NULLABLE'],
                        ['name' => 'alpine_pass_orders',  'type' => 'INTEGER',   'mode' => 'NULLABLE'],
                        ['name' => 'alpine_pass_revenue', 'type' => 'FLOAT',     'mode' => 'NULLABLE'],
                    ],
                ],
                'timePartitioning' => ['type' => 'DAY', 'field' => 'snapshot_at'],
            ]),
            'timeout' => 20,
        ]
    );

    if (is_wp_error($response)) return $response->get_error_message();

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (in_array($code, [200, 201], true)) return true;
    if ($code === 409) return 'already_exists';

    return $body['error']['message'] ?? 'Unknown error (HTTP ' . $code . ')';
}

// ─── Metrics queries ──────────────────────────────────────────────────────────

function alpine_bq_get_metrics(string $date_from, string $date_to): array {
    global $wpdb;

    $statuses_sql = "('wc-completed', 'wc-processing')";

    // Total revenue, net revenue, order count
    $revenue = $wpdb->get_row($wpdb->prepare(
        "SELECT
            COALESCE(SUM(total_sales), 0) AS total_revenue,
            COALESCE(SUM(net_total), 0)   AS net_revenue,
            COUNT(*)                       AS order_count
         FROM {$wpdb->prefix}wc_order_stats
         WHERE status IN {$statuses_sql}
           AND date_created >= %s
           AND date_created <  %s",
        $date_from,
        $date_to
    ));

    // New customers registered in period
    $new_customers = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*)
         FROM {$wpdb->users}
         WHERE user_registered >= %s
           AND user_registered <  %s",
        $date_from,
        $date_to
    ));

    // Returning customers: placed an order in period AND have >1 total completed order overall
    $returning_customers = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT o1.customer_id)
         FROM {$wpdb->prefix}wc_order_stats o1
         WHERE o1.status IN {$statuses_sql}
           AND o1.date_created >= %s
           AND o1.date_created <  %s
           AND o1.customer_id > 0
           AND (
               SELECT COUNT(*)
               FROM {$wpdb->prefix}wc_order_stats o2
               WHERE o2.customer_id = o1.customer_id
                 AND o2.status IN {$statuses_sql}
           ) > 1",
        $date_from,
        $date_to
    ));

    // Alpine Pass orders + revenue
    $skus_escaped = implode(',', array_map(fn($s) => "'" . esc_sql($s) . "'", ALPINE_BQ_ALPINE_PASS_SKUS));

    $alpine = $wpdb->get_row($wpdb->prepare(
        "SELECT
            COUNT(DISTINCT os.order_id)     AS alpine_pass_orders,
            COALESCE(SUM(im.meta_value), 0) AS alpine_pass_revenue
         FROM {$wpdb->prefix}wc_order_stats os
         JOIN {$wpdb->prefix}woocommerce_order_items oi
              ON os.order_id = oi.order_id AND oi.order_item_type = 'line_item'
         JOIN {$wpdb->prefix}woocommerce_order_itemmeta im_pid
              ON oi.order_item_id = im_pid.order_item_id AND im_pid.meta_key = '_product_id'
         JOIN {$wpdb->postmeta} pm
              ON im_pid.meta_value = pm.post_id AND pm.meta_key = '_sku' AND pm.meta_value IN ({$skus_escaped})
         JOIN {$wpdb->prefix}woocommerce_order_itemmeta im
              ON oi.order_item_id = im.order_item_id AND im.meta_key = '_line_total'
         WHERE os.status IN {$statuses_sql}
           AND os.date_created >= %s
           AND os.date_created <  %s",
        $date_from,
        $date_to
    ));

    return [
        'total_revenue'       => round((float) ($revenue->total_revenue ?? 0), 2),
        'net_revenue'         => round((float) ($revenue->net_revenue   ?? 0), 2),
        'order_count'         => (int)  ($revenue->order_count          ?? 0),
        'new_customers'       => $new_customers,
        'returning_customers' => $returning_customers,
        'alpine_pass_orders'  => (int)  ($alpine->alpine_pass_orders    ?? 0),
        'alpine_pass_revenue' => round((float) ($alpine->alpine_pass_revenue ?? 0), 2),
    ];
}

// ─── Hourly cron snapshot ─────────────────────────────────────────────────────

add_action(ALPINE_BQ_CRON_HOOK, 'alpine_bq_run_hourly_snapshot');

function alpine_bq_run_hourly_snapshot(?string $date_from = null, ?string $date_to = null): true|string {
    if (!$date_from) {
        $date_from = date('Y-m-d H:00:00', strtotime('-24 hours', current_time('timestamp')));
    }
    if (!$date_to) {
        $date_to = current_time('Y-m-d H:i:s');
    }

    $metrics               = alpine_bq_get_metrics($date_from, $date_to);
    $metrics['snapshot_at'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($date_from));

    $result = alpine_bq_insert_rows([$metrics]);
    if ($result !== true) {
        error_log('[Alpine BQ] Hourly snapshot failed: ' . $result);
    }
    return $result;
}

function alpine_bq_get_table_status(): array|string {
    $token = alpine_bq_get_access_token();
    $creds = alpine_bq_get_credentials();
    if (!$token || !$creds) return 'No credentials or failed to get token.';

    $project = $creds['project_id'];
    $dataset = alpine_bq_get_dataset();
    $table   = alpine_bq_get_table();
    $query   = "SELECT count(*) as cnt, max(snapshot_at) as last_ts FROM `{$project}.{$dataset}.{$table}`";

    $response = wp_remote_post("https://bigquery.googleapis.com/bigquery/v2/projects/{$project}/queries", [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ],
        'body'    => wp_json_encode([
            'query' => $query,
            'useLegacySql' => false,
        ]),
        'timeout' => 20,
    ]);

    if (is_wp_error($response)) return $response->get_error_message();

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!empty($body['errors'])) return wp_json_encode($body['errors']);

    $rows = $body['rows'] ?? [];
    if (empty($rows)) return ['count' => 0, 'last' => 'Never'];

    $data = $rows[0]['f'];
    return [
        'count' => (int) $data[0]['v'],
        'last'  => $data[1]['v'] ? date('Y-m-d H:i:s', (float) $data[1]['v']) : 'Never',
    ];
}

// ─── Settings page ────────────────────────────────────────────────────────────

function alpine_bq_settings_page(): void {
    $message = '';
    $error   = '';
    $status  = null;

    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'alpine_bq_settings')) {
        goto render;
    }

    if (isset($_POST['alpine_bq_save'])) {
        $json    = trim($_POST['alpine_bq_credentials'] ?? '');
        $dataset = sanitize_text_field($_POST['alpine_bq_dataset'] ?? 'alpine_funded');
        $table   = sanitize_text_field($_POST['alpine_bq_table']   ?? 'metrics_hourly');

        if (!empty($json)) {
            $decoded = json_decode($json, true);
            if (empty($decoded['private_key']) || empty($decoded['client_email'])) {
                $error = 'Invalid JSON — must be a valid Google service account key file.';
                goto render;
            }
            update_option(ALPINE_BQ_OPTION_CREDENTIALS, $json);
            delete_transient(ALPINE_BQ_TOKEN_TRANSIENT);
        }

        update_option(ALPINE_BQ_OPTION_DATASET, $dataset);
        update_option(ALPINE_BQ_OPTION_TABLE,   $table);
        $message = empty($json) ? 'Settings saved (credentials unchanged).' : 'Settings saved.';
    }

    if (isset($_POST['alpine_bq_test'])) {
        $token = alpine_bq_get_access_token();
        if ($token) {
            $message = 'Connection successful — OAuth2 token obtained.';
        } else {
            $error = 'Connection failed — check that the service account JSON is correct and has BigQuery permissions.';
        }
    }

    if (isset($_POST['alpine_bq_setup'])) {
        $ds = alpine_bq_ensure_dataset();
        if ($ds !== true) {
            $error = 'Dataset error: ' . $ds;
        } else {
            $t = alpine_bq_create_table();
            if ($t === true) {
                $message = 'BigQuery dataset and table created successfully.';
            } elseif ($t === 'already_exists') {
                $message = 'Dataset OK. Table already exists — no changes made.';
            } else {
                $error = 'Table creation failed: ' . $t;
            }
        }
    }

    if (isset($_POST['alpine_bq_send_now'])) {
        $res = alpine_bq_run_hourly_snapshot();
        if ($res === true) {
            $message = 'Snapshot sent successfully.';
        } else {
            $error = 'Snapshot failed: ' . $res;
        }
    }

    if (isset($_POST['alpine_bq_check_status'])) {
        $status = alpine_bq_get_table_status();
        if (is_string($status)) {
            $error = 'Status check failed: ' . $status;
            $status = null;
        }
    }

    render:
    $creds      = get_option(ALPINE_BQ_OPTION_CREDENTIALS, '');
    $creds_arr  = $creds ? json_decode($creds, true) : null;
    $dataset    = alpine_bq_get_dataset();
    $table      = alpine_bq_get_table();
    $next_cron  = wp_next_scheduled(ALPINE_BQ_CRON_HOOK);
    ?>
    <div class="wrap">
        <h1>Alpine Metrics — Settings</h1>

        <?php if ($message): ?>
            <div class="notice notice-success is-dismissible"><p><?= esc_html($message) ?></p></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="notice notice-error is-dismissible"><p><?= esc_html($error) ?></p></div>
        <?php endif; ?>

        <?php if ($status): ?>
            <div class="notice notice-info is-dismissible">
                <p>
                    <strong>BigQuery Table Status:</strong>
                    Rows: <code><?= esc_html($status['count']) ?></code> |
                    Last Snapshot: <code><?= esc_html($status['last']) ?></code>
                </p>
            </div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('alpine_bq_settings'); ?>
            <h2>Google BigQuery Connection</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Service Account JSON</th>
                    <td>
                        <textarea name="alpine_bq_credentials" rows="10"
                            style="width:100%;max-width:600px;font-family:monospace;font-size:11px;"
                            placeholder="Paste the full contents of your Google service account JSON key file here…"></textarea>
                        <?php if ($creds_arr): ?>
                            <p class="description">
                                Currently set &mdash;
                                Project: <strong><?= esc_html($creds_arr['project_id']) ?></strong>,
                                Account: <strong><?= esc_html($creds_arr['client_email']) ?></strong>
                            </p>
                        <?php else: ?>
                            <p class="description">No credentials stored yet.</p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">BigQuery Dataset</th>
                    <td>
                        <input type="text" name="alpine_bq_dataset"
                            value="<?= esc_attr($dataset) ?>" class="regular-text">
                        <p class="description">Will be created automatically if it doesn't exist.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">BigQuery Table</th>
                    <td>
                        <input type="text" name="alpine_bq_table"
                            value="<?= esc_attr($table) ?>" class="regular-text">
                    </td>
                </tr>
            </table>

            <p class="submit" style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="submit" name="alpine_bq_save"     class="button button-primary">Save Settings</button>
                <button type="submit" name="alpine_bq_test"     class="button">Test Connection</button>
                <button type="submit" name="alpine_bq_setup"    class="button">Create Dataset &amp; Table</button>
                <button type="submit" name="alpine_bq_send_now" class="button">Send Snapshot Now</button>
                <button type="submit" name="alpine_bq_check_status" class="button">Check Table Status</button>
            </p>
        </form>

        <hr>
        <h2>Cron Schedule</h2>
        <table class="widefat" style="max-width:500px;">
            <tr><th>Hook</th><td><code><?= esc_html(ALPINE_BQ_CRON_HOOK) ?></code></td></tr>
            <tr><th>Frequency</th><td>Hourly</td></tr>
            <tr><th>Next run</th><td><?= $next_cron ? esc_html(date('Y-m-d H:i:s', $next_cron)) . ' UTC' : '<em>Not scheduled</em>' ?></td></tr>
        </table>
    </div>
    <?php
}

// ─── Dashboard page ───────────────────────────────────────────────────────────

function alpine_bq_dashboard_page(): void {
    // Date range resolution
    $range     = sanitize_text_field($_GET['range']     ?? 'last_30');
    $date_from = sanitize_text_field($_GET['date_from'] ?? '');
    $date_to   = sanitize_text_field($_GET['date_to']   ?? '');

    $now        = current_time('Y-m-d H:i:s');
    $today      = current_time('Y-m-d') . ' 00:00:00';

    switch ($range) {
        case 'today':
            $date_from = $today;
            $date_to   = $now;
            break;
        case 'last_7':
            $date_from = date('Y-m-d 00:00:00', strtotime('-6 days', current_time('timestamp')));
            $date_to   = $now;
            break;
        case 'this_month':
            $date_from = date('Y-m-01 00:00:00', current_time('timestamp'));
            $date_to   = $now;
            break;
        case 'last_month':
            $date_from = date('Y-m-01 00:00:00', strtotime('first day of last month', current_time('timestamp')));
            $date_to   = date('Y-m-t 23:59:59',  strtotime('last month',              current_time('timestamp')));
            break;
        case 'custom':
            if (!$date_from) $date_from = date('Y-m-d 00:00:00', strtotime('-29 days', current_time('timestamp')));
            if (!$date_to)   $date_to   = $now;
            if (strlen($date_from) === 10) $date_from .= ' 00:00:00';
            if (strlen($date_to)   === 10) $date_to   .= ' 23:59:59';
            break;
        default: // last_30
            $range     = 'last_30';
            $date_from = date('Y-m-d 00:00:00', strtotime('-29 days', current_time('timestamp')));
            $date_to   = $now;
    }

    $range_labels = [
        'today'      => 'Today',
        'last_7'     => 'Last 7 Days',
        'last_30'    => 'Last 30 Days',
        'this_month' => 'This Month',
        'last_month' => 'Last Month',
        'custom'     => date('M j, Y', strtotime($date_from)) . ' – ' . date('M j, Y', strtotime($date_to)),
    ];

    $metrics = alpine_bq_get_metrics($date_from, $date_to);
    $creds   = alpine_bq_get_credentials();
    $next    = wp_next_scheduled(ALPINE_BQ_CRON_HOOK);

    ?>
    <div class="wrap">
        <h1>Alpine Metrics Dashboard</h1>

        <?php if (!$creds): ?>
            <div class="notice notice-warning">
                <p>BigQuery is not configured.
                <a href="<?= esc_url(admin_url('admin.php?page=alpine-metrics-settings')) ?>">Configure settings →</a></p>
            </div>
        <?php else: ?>
            <p style="color:#666;font-size:13px;margin-bottom:4px;">
                Streaming to BigQuery:
                <strong><?= esc_html($creds['project_id']) ?>.<?= esc_html(alpine_bq_get_dataset()) ?>.<?= esc_html(alpine_bq_get_table()) ?></strong>
                &nbsp;|&nbsp; Next snapshot:
                <strong><?= $next ? esc_html(date('H:i', $next)) . ' UTC' : 'Not scheduled' ?></strong>
            </p>
        <?php endif; ?>

        <form method="get" style="margin:16px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <input type="hidden" name="page" value="alpine-metrics">
            <select name="range" onchange="this.form.submit()" style="height:32px;">
                <?php foreach (['today' => 'Today', 'last_7' => 'Last 7 Days', 'last_30' => 'Last 30 Days', 'this_month' => 'This Month', 'last_month' => 'Last Month', 'custom' => 'Custom Range'] as $v => $l): ?>
                    <option value="<?= esc_attr($v) ?>" <?= selected($range, $v, false) ?>><?= esc_html($l) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($range === 'custom'): ?>
                <input type="date" name="date_from" value="<?= esc_attr(substr($date_from, 0, 10)) ?>" style="height:30px;">
                <span>to</span>
                <input type="date" name="date_to" value="<?= esc_attr(substr($date_to, 0, 10)) ?>" style="height:30px;">
                <button type="submit" class="button" style="height:32px;">Apply</button>
            <?php endif; ?>
            <span style="color:#666;font-size:13px;">
                Showing: <strong><?= esc_html($range_labels[$range] ?? '') ?></strong>
            </span>
        </form>

        <style>
            .alp-grid          { display:grid; grid-template-columns:repeat(auto-fill,minmax(210px,1fr)); gap:16px; margin:20px 0; }
            .alp-card          { background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:20px 20px 16px; box-shadow:0 1px 2px rgba(0,0,0,.04); }
            .alp-card .lbl     { font-size:11px; font-weight:600; color:#888; text-transform:uppercase; letter-spacing:.6px; margin-bottom:10px; }
            .alp-card .val     { font-size:30px; font-weight:700; color:#1d2327; line-height:1; }
            .alp-card .sub     { font-size:12px; color:#aaa; margin-top:6px; }
            .alp-card.blue     { border-top:3px solid #2271b1; }
            .alp-card.green    { border-top:3px solid #00a32a; }
            .alp-card.orange   { border-top:3px solid #dba617; }
            .alp-card.purple   { border-top:3px solid #7c3aed; }
            .alp-card.muted    { opacity:.55; }
            .alp-badge         { display:inline-block; background:#f0f0f0; border-radius:3px; padding:1px 6px; font-size:10px; color:#888; margin-left:4px; vertical-align:middle; }
        </style>

        <div class="alp-grid">

            <div class="alp-card blue">
                <div class="lbl">Total Revenue</div>
                <div class="val">$<?= number_format($metrics['total_revenue'], 2) ?></div>
                <div class="sub">Gross WooCommerce sales</div>
            </div>

            <div class="alp-card blue">
                <div class="lbl">Net Revenue</div>
                <div class="val">$<?= number_format($metrics['net_revenue'], 2) ?></div>
                <div class="sub">After refunds</div>
            </div>

            <div class="alp-card muted">
                <div class="lbl">Contribution Margin <span class="alp-badge">Sigma</span></div>
                <div class="val" style="font-size:15px;color:#aaa;">Tracked in Sigma</div>
                <div class="sub">Requires expense data</div>
            </div>

            <div class="alp-card muted">
                <div class="lbl">EBITDA / EBIT <span class="alp-badge">Sigma</span></div>
                <div class="val" style="font-size:15px;color:#aaa;">Tracked in Sigma</div>
                <div class="sub">External accounting</div>
            </div>

            <div class="alp-card green">
                <div class="lbl">Orders</div>
                <div class="val"><?= number_format($metrics['order_count']) ?></div>
                <div class="sub">Completed + Processing</div>
            </div>

            <div class="alp-card green">
                <div class="lbl">New Customers</div>
                <div class="val"><?= number_format($metrics['new_customers']) ?></div>
                <div class="sub">User registrations in period</div>
            </div>

            <div class="alp-card orange">
                <div class="lbl">Returning Customers</div>
                <div class="val"><?= number_format($metrics['returning_customers']) ?></div>
                <div class="sub">Ordered in period + &gt;1 total order</div>
            </div>

            <div class="alp-card purple">
                <div class="lbl">Alpine Pass Orders</div>
                <div class="val"><?= number_format($metrics['alpine_pass_orders']) ?></div>
                <div class="sub">$<?= number_format($metrics['alpine_pass_revenue'], 2) ?> revenue</div>
            </div>

        </div>

        <p style="color:#888;font-size:12px;margin-top:8px;">
            Window: <?= esc_html($date_from) ?> &rarr; <?= esc_html($date_to) ?> (site timezone)
        </p>
    </div>
    <?php
}
