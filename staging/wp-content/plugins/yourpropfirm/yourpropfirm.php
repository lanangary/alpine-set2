<?php
/**
 * @link              https://yourpropfirm.com
 * @since             1.2.1
 * @package           yourpropfirm
 * GitHub Plugin URI: https://github.com/JMConsultingID/your-propfirm-addon
 * GitHub Branch: develop
 * @wordpress-plugin
 * Plugin Name:       YourPropfirm Connection Dashboard
 * Plugin URI:        https://yourpropfirm.com
 * Description:       This Plugin to Create User and Account to Dashboard YourPropfirm
 * Version:           1.2.1.3
 * Author:            YourPropfirm Team
 * Author URI:        https://yourpropfirm.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       yourpropfirm
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

define( 'YOURPROPFIRM_VERSION', '1.2.1.3' );

if (!function_exists('is_plugin_active')) {
    include_once(ABSPATH . '/wp-admin/includes/plugin.php');
}

global $wpdb;
define('YPF_ADDONS_TABLE', $wpdb->prefix . 'ypf_addons');

// Enqueue external JS in the WooCommerce product admin page from plugin directory
function yourpropfirm_enqueue_admin_script($hook) {
    global $post;
    if ('post.php' === $hook && 'product' === $post->post_type) {
        wp_enqueue_script(
            'yourpropfirm-admin-js',
            plugin_dir_url(__FILE__) . 'assets/js/yourpropfirm-admin.js',
            array('jquery'), 
            YOURPROPFIRM_VERSION,
            true 
        );
    }
}
add_action('admin_enqueue_scripts', 'yourpropfirm_enqueue_admin_script');

function yourpropfirm_enqueue_public_styles() {
    wp_enqueue_style(
        'yourpropfirm-public-css', 
        plugin_dir_url(__FILE__) . 'assets/css/yourpropfirm-public.css',
        array(),
        YOURPROPFIRM_VERSION // Plugin version
    );
}
add_action('wp_enqueue_scripts', 'yourpropfirm_enqueue_public_styles');



require plugin_dir_path( __FILE__ ) . 'inc/yourpropfirm-functions.php';