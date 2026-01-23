<?php
/**
 * Yourpropfirm - Bulk Update YourPropFirm Flag
 *
 * Bulk Update YourPropFirm Completed Flag for all completed WooCommerce orders where it is 0 + display it on order edit screen.
 *
 * @link              https://yourpropfirm.com
 * @since             1.1.1
 * @package           yourpropfirm
 * @wordpress-plugin
 * Plugin Name:       Yourpropfirm - Bulk Update YourPropFirm Flag
 * Plugin URI:        https://yourpropfirm.com
 * Description:       Bulk Update YourPropFirm Completed Flag for all completed WooCommerce orders where it is 0 + display it on order edit screen.
 * Version:           1.1.1
 * Author:            Yourpropfirm Team
 * Author URI:        https://yourpropfirm.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       yourpropfirm
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Bulk_Update_YourPropFirm {

    private $is_processing = false;

    public function __construct() {
        // Bulk-update admin page
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'handle_bulk_update' ] );

        // Show Connection Completed on order edit screen (after Billing column)
        add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'display_yourpropfirm_flag' ], 10, 1 );

        // Show All Order Meta after Shipping column
        add_action( 'woocommerce_admin_order_data_after_shipping_address', [ $this, 'display_all_order_meta_section' ], 10, 1 );
    }

    /* --------------------------------------------------------------
       1. Bulk-update admin page (unchanged, only tiny tweaks)
    -------------------------------------------------------------- */
    public function add_admin_menu() {
        add_submenu_page(
            'tools.php',
            'Bulk Update YourPropFirm Flag',
            'Bulk Update YourPropFirm Flag',
            'manage_woocommerce',
            'wc-bulk-yourpropfirm',
            [ $this, 'admin_page' ]
        );
    }

    public function admin_page() {
        // If processing is happening, don't show the form
        if ( $this->is_processing ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Bulk Update <code>_yourpropfirm_connection_completed</code></h1>
            <p>This will update orders with the following statuses: <strong>Completed</strong>, <strong>On-hold</strong>, and <strong>Processing</strong>.</p>
            <form method="post">
                <?php wp_nonce_field( 'wc_bulk_yourpropfirm', 'wc_bulk_nonce' ); ?>
                <p>
                    <input type="submit" name="run_bulk_update" class="button button-primary"
                           value="Run Update on Orders" />
                </p>
            </form>
        </div>
        <?php
    }

    public function handle_bulk_update() {
        if ( ! isset( $_POST['run_bulk_update'] ) ) {
            return;
        }

        if ( ! isset( $_POST['wc_bulk_nonce'] ) || ! wp_verify_nonce( $_POST['wc_bulk_nonce'], 'wc_bulk_yourpropfirm' ) ) {
            wp_die( 'Security check failed.' );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        // Set processing flag to prevent form from showing
        $this->is_processing = true;

        // Hook into admin page rendering to show progress instead of form
        add_action( 'admin_notices', function() {
            $this->render_bulk_update_progress();
        } );
    }

    private function render_bulk_update_progress() {
        // Start time tracking
        $start_time = microtime( true );

        ?>
        <div class="wrap">
            <h1>🔄 Bulk Update in Progress</h1>
            <div style="background:#fff; border:1px solid #ccd0d4; padding:20px; margin:20px 0; font-family:monospace; font-size:13px; line-height:1.6;">
                <strong>Starting bulk update process...</strong><br><br>
        <?php

        ob_flush();
        flush();

        $per_page = 100;
        $paged    = 1;
        $updated  = 0;
        $total_processed = 0;

        do {
            $orders = wc_get_orders( [
                'status'       => [ 'wc-completed', 'wc-on-hold', 'wc-processing' ],
                'limit'        => $per_page,
                'page'         => $paged,
                'return'       => 'ids',
                'meta_query'   => [
                    'relation' => 'OR',
                    [
                        'key'     => '_yourpropfirm_connection_completed',
                        'value'   => '0',
                        'compare' => '=',
                    ],
                    [
                        'key'     => '_yourpropfirm_connection_completed',
                        'compare' => 'NOT EXISTS',
                    ],
                ],
            ] );

            $batch_count = count( $orders );
            $total_processed += $batch_count;

            if ( $batch_count > 0 ) {
                echo "📦 Processing batch #$paged ($batch_count orders)...<br>";
                ob_flush();
                flush();

                foreach ( $orders as $order_id ) {
                    update_post_meta( $order_id, '_yourpropfirm_connection_completed', '1' );
                    $updated++;

                    // Show progress every 10 orders
                    if ( $updated % 10 === 0 ) {
                        $elapsed = round( microtime( true ) - $start_time, 2 );
                        echo "   ✓ Processed $updated orders (elapsed: {$elapsed}s)<br>";
                        ob_flush();
                        flush();
                    }
                }

                echo "   ✅ Batch #$paged completed!<br><br>";
                ob_flush();
                flush();
            }

            $paged++;

            // Safety limit
            if ( $paged > 500 ) {
                echo "⚠️ <strong>Safety limit reached (500 pages).</strong><br><br>";
                break;
            }

        } while ( $batch_count === $per_page );

        // Calculate final statistics
        $end_time = microtime( true );
        $total_time = round( $end_time - $start_time, 2 );
        $orders_per_second = $updated > 0 ? round( $updated / $total_time, 2 ) : 0;

        // Display final results
        ?>
                <br>
                <hr style="border:1px solid #00a32a; margin:20px 0;">
                <div style="background:#00a32a; color:#fff; padding:15px; margin-top:10px; border-radius:4px;">
                    <h3 style="margin:0 0 10px 0; color:#fff;">✅ Update Completed Successfully!</h3>
                    <table style="color:#fff; width:100%; border-collapse: collapse;">
                        <tr><td style="padding:5px 10px 5px 0;"><strong>Orders Updated:</strong></td><td style="padding:5px 0;"><?php echo number_format( $updated ); ?></td></tr>
                        <tr><td style="padding:5px 10px 5px 0;"><strong>Total Batches Processed:</strong></td><td style="padding:5px 0;"><?php echo ( $paged - 1 ); ?></td></tr>
                        <tr><td style="padding:5px 10px 5px 0;"><strong>Total Time:</strong></td><td style="padding:5px 0;"><?php echo $total_time; ?> seconds</td></tr>
                        <tr><td style="padding:5px 10px 5px 0;"><strong>Average Speed:</strong></td><td style="padding:5px 0;"><?php echo $orders_per_second; ?> orders/second</td></tr>
                    </table>
                </div>
            </div>
            <p style="margin-top:20px;">
                <a href="<?php echo admin_url( 'tools.php?page=wc-bulk-yourpropfirm' ); ?>" class="button button-primary">← Back to Bulk Update Page</a>
            </p>
        </div>
        <?php
    }

    /* --------------------------------------------------------------
       2. DISPLAY YOURPROPFIRM FLAG + ALL META (FIXED TOGGLE)
    -------------------------------------------------------------- */

    /**
     * Show the ORIGINAL value of _yourpropfirm_connection_completed.
     *
     * @param WC_Order $order
     */
    public function display_yourpropfirm_flag( $order ) {
        $value   = get_post_meta( $order->get_id(), '_yourpropfirm_connection_completed', true );
        $display = $value === '' ? '<em>(empty)</em>' : esc_html( $value );

        ?>
        <div class="order_data_column" style="clear:both; width:100%;">
            <h3>YourPropFirm Order Details</h3>
            <div class="address">
                <table class="widefat" style="width:100%; border:1px solid #ddd; margin-top:10px;">
                    <thead>
                        <tr>
                            <th style="padding:10px; border-bottom:1px solid #ddd; background:#f9f9f9; text-align:left;">Field</th>
                            <th style="padding:10px; border-bottom:1px solid #ddd; background:#f9f9f9; text-align:left;">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style="padding:10px; border-bottom:1px solid #ddd;">Connection Completed</td>
                            <td style="padding:10px; border-bottom:1px solid #ddd;"><?php echo $display; ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Wrapper to display all order meta in the shipping section.
     *
     * @param WC_Order $order
     */
    public function display_all_order_meta_section( $order ) {
        $this->display_all_order_meta( $order );
    }

    /**
     * Display ALL meta data for the order in a collapsible box.
     *
     * @param WC_Order $order
     */
    private function display_all_order_meta( $order ) {
        $all_meta = $order->get_meta_data();

        if ( empty( $all_meta ) ) {
            echo '<p><em>No meta data found.</em></p>';
            return;
        }

        $rand = wp_rand( 1000, 9999 );
        $count = count( $all_meta );
        ?>
        <div class="form-field form-field-wide wc-order-meta-box">
            <p>
                <a href="#" class="toggle-all-meta button button-secondary" data-target="meta-<?php echo $rand; ?>">
                    Show All Order Meta (<?php echo $count; ?>)
                </a>
            </p>
            <div id="meta-<?php echo $rand; ?>" class="meta-table-container" style="display:none; margin-top:10px; border:1px solid #ccc; padding:10px; background:#fff;">
                <table class="widefat fixed striped" style="font-size:12px; margin:0;">
                    <thead>
                        <tr>
                            <th style="width:30%;"><strong>Meta Key</strong></th>
                            <th><strong>Value</strong></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ( $all_meta as $meta ) {
                            $key   = $meta->key;
                            $value = $meta->value;

                            if ( is_array( $value ) || is_object( $value ) ) {
                                $value = '<pre style="margin:0; white-space:pre-wrap;">' . esc_html( print_r( $value, true ) ) . '</pre>';
                            } elseif ( $value === '' ) {
                                $value = '<em>(empty)</em>';
                            } else {
                                $value = esc_html( $value );
                            }

                            echo '<tr>';
                            echo '<td><code>' . esc_html( $key ) . '</code></td>';
                            echo '<td>' . $value . '</td>';
                            echo '</tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php
        // Enqueue JS **only on order edit screen**
        add_action( 'admin_footer', function() use ( $rand, $count ) {
            ?>
            <script type="text/javascript">
            jQuery(function($) {
                $('#meta-<?php echo $rand; ?>').prev('p').find('.toggle-all-meta').on('click', function(e) {
                    e.preventDefault();
                    var $target = $('#meta-<?php echo $rand; ?>');
                    var $link   = $(this);

                    $target.slideToggle(200);

                    if ($target.is(':visible')) {
                        $link.text('Hide All Order Meta (<?php echo $count; ?>)');
                    } else {
                        $link.text('Show All Order Meta (<?php echo $count; ?>)');
                    }
                });
            });
            </script>
            <?php
        } );
    }
}

new WC_Bulk_Update_YourPropFirm();