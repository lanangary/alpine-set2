<?php
/**
 * Plugin functions and definitions for Admin.
 *
 * For additional information on potential customization options,
 * read the developers' documentation:
 *
 * @package yourpropfirm
 */
function yourpropfirm_display_custom_field_after_billing_form() {
    $plugin_enabled = get_option('yourpropfirm_connection_enabled');
    $enable_mtversion_field = get_option('yourpropfirm_connection_mt_version_field');
    $default_mt = get_option('yourpropfirm_connection_default_mt_version_field');
    $trading_platforms_options = get_option('yourpropfirm_connection_trading_platforms');
    $field_type = get_option('yourpropfirm_connection_mt_version_type'); // Get the field type option

    if ($plugin_enabled !== 'enable' || $enable_mtversion_field !== 'enable') {
        return;
    }

    $options = [];
    $images = [];

    // Base URL for images
    $images_base_url = plugins_url('assets/images/', dirname(dirname(__FILE__)));    
    if (!empty($trading_platforms_options['enable_mt4'])) {
        $options['MT4'] = __('MT4', 'yourpropfirm');
        $images['MT4'] = $images_base_url . 'mt4.png'; 
    }
    if (!empty($trading_platforms_options['enable_mt5'])) {
        $options['MT5'] = __('MT5', 'yourpropfirm');
        $images['MT5'] = $images_base_url . 'mt5.png';
    }
    if (!empty($trading_platforms_options['enable_ctrader'])) {
        $options['CTrader'] = __('CTrader', 'yourpropfirm');
        $images['CTrader'] = $images_base_url . 'ctrader.svg';
    }
    if (!empty($trading_platforms_options['enable_dx_trade'])) {
        $options['DXTrade'] = __('DX Trade', 'yourpropfirm');
        $images['DXTrade'] = $images_base_url . 'dxtrade.svg';
    }
    if (!empty($trading_platforms_options['enable_sirix'])) {
        $options['Sirix'] = __('Sirix', 'yourpropfirm');
        $images['Sirix'] = $images_base_url . 'sirix.png';
    }
    if (!empty($trading_platforms_options['enable_match_trader'])) {
        $options['MatchTrade'] = __('MatchTrade', 'yourpropfirm');
        $images['MatchTrade'] = $images_base_url . 'matchtrade.png';
    }
    if (!empty($trading_platforms_options['enable_tradelocker'])) {
        $options['tradeLocker'] = __('TradeLocker', 'yourpropfirm');
        $images['tradeLocker'] = $images_base_url . 'tradelocker.png';
    }

    $first_option = array_key_first($options);    
    // Use default from settings if valid, otherwise fall back to first option
$selected_option = $default_mt;

if (empty($selected_option) || !array_key_exists($selected_option, $options)) {
    $selected_option = $first_option;
}

        
    if ($field_type === 'select') {
        ?>
        <div class="yourpropfirm_mt_version yourpropfirm_mt_version_select_field_wrapper">
            <?php
                woocommerce_form_field('yourpropfirm_mt_version', array(
                    'type' => 'select',
                    'class' => array('form-row-wide ypf_mt_version_field'),
                    'label' => __('Trading Platforms', 'yourpropfirm'),
                    'required' => true,
                    'options' => $options,
                ), '');
            ?>
        </div>
    <?php
    } elseif ($field_type === 'radio') {
        ?>
        <div class="yourpropfirm_mt_version yourpropfirm_mt_version_field_radio_wrapper">
            <label class="form-label"><h3><?php echo __('Trading Platforms', 'yourpropfirm'); ?>&nbsp;<abbr class="required" title="required">*</abbr></h3></label>
            <div class="form-row form-row-wide ypf_mt_version_field ypf_mt_version_radio_option validate-required" id="yourpropfirm_mt_version_field" data-priority="">            
            <?php foreach ($options as $value => $label) : ?>
                <div class="field-group yourpropfirm_wrap_<?php echo esc_attr($value); ?>">
                    <input 
                        type="radio" 
                        class="input-radio class-yourpropfirm_mt_version_<?php echo esc_attr($value); ?>" 
                        value="<?php echo esc_attr($value); ?>"
                        mt_value="<?php echo esc_attr($value); ?>"
                        name="yourpropfirm_mt_version" 
                        aria-required="true" 
                        id="yourpropfirm_mt_version_<?php echo esc_attr($value); ?>" 
                        <?php checked($value, $selected_option); ?>
                    >
                    <label for="yourpropfirm_mt_version_<?php echo esc_attr($value); ?>" class="radio">
                        <span><?php echo esc_html($label); ?></span>
                    </label>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php
        $enablePopup = get_option('yourpropfirm_connection_mt_version_type_show_message');
        if ($enablePopup === 'enable') {
            $popupMessage = get_option('yourpropfirm_connection_mt_version_type_message');
        ?>
            <!-- Popup Container -->
            <div id="popupMessage" class="ypf-popup-overlay">
                <div class="ypf-popup-content">
                    <h3 class="text-center">Information</h3>
                    <p><?php echo esc_textarea($popupMessage ); ?></p>
                    <span id="okButton" class="ypf-ok-button">OK</span>
                </div>
            </div>
            <script type="text/javascript">
                document.addEventListener('DOMContentLoaded', function () {
                    const cTraderRadio = document.getElementById('yourpropfirm_mt_version_MT5');
                    const popup = document.getElementById('popupMessage');
                    const okButton = document.getElementById('okButton');

                    // Show popup when CTrader is selected
                    cTraderRadio.addEventListener('change', function () {
                        if (cTraderRadio.checked) {
                            popup.style.display = 'block'; // Show the popup
                        }
                    });

                    // Close popup when 'OK' button is clicked
                    okButton.addEventListener('click', function () {
                        popup.style.display = 'none'; // Hide the popup
                    });
                });
            </script>
        <?php } ?>
    <?php
    }
}

function yourpropfirm_mt_version_validate_field() {
    if (isset($_POST['yourpropfirm_mt_version']) && empty($_POST['yourpropfirm_mt_version'])) {
        wc_add_notice(__('Please select a Trading Platforms.', 'yourpropfirm'), 'error');
    }
}

// Additional Settings fo Woocommerce
// COMMENTED OUT: These filters were preventing WooCommerce account creation settings from working
// If you need to disable these features, use WooCommerce settings instead
// add_filter('woocommerce_checkout_registration_enabled', '__return_false');
// add_filter('woocommerce_checkout_login_enabled', '__return_false');
// add_filter('option_woocommerce_enable_signup_and_login_from_checkout', '__return_false');
// add_filter('pre_option_woocommerce_enable_signup_and_login_from_checkout', '__return_false');


add_action('display_available_addons_in_checkout', 'yourpropfirm_display_custom_field_after_billing_form',10);
add_action('woocommerce_checkout_process', 'yourpropfirm_mt_version_validate_field');