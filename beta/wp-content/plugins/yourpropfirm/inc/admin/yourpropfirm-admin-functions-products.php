<?php
/**
 * Plugin functions and definitions for Admin.
 *
 * For additional information on potential customization options,
 * read the developers' documentation:
 *
 * @package yourpropfirm
 */
// Add the Challenge/Competition select field
function yourpropfirm_add_selection_field() {
    woocommerce_wp_select(
        array(
            'id' => '_yourpropfirm_selection_type',
            'label' => __('Select Type', 'yourpropfirm'),
            'options' => array(
                'challenge' => __('Challenge', 'yourpropfirm'),
                'competition' => __('Competition', 'yourpropfirm'),
            ),
            'wrapper_class' => 'show_if_simple',
        )
    );
}
add_action('woocommerce_product_options_general_product_data', 'yourpropfirm_add_selection_field');

// Save the selected type
function yourpropfirm_save_selection_field($product_id) {
    $selection_type = sanitize_text_field($_POST['_yourpropfirm_selection_type']);
    update_post_meta($product_id, '_yourpropfirm_selection_type', esc_attr($selection_type));
}
add_action('woocommerce_process_product_meta', 'yourpropfirm_save_selection_field');


// Add custom fields for ProgramId and CompetitionId
function yourpropfirm_add_dynamic_fields() {
    global $post;
    
    // Add ProgramId field
    woocommerce_wp_text_input(
        array(
            'id' => '_yourpropfirm_program_id',
            'label' => __('ProgramId (YourPropfirm)', 'yourpropfirm'),
            'placeholder' => __('Enter ProgramId (YourPropfirm)', 'yourpropfirm'),
            'desc_tip' => true,
            'description' => __('Enter ProgramId (YourPropfirm).', 'yourpropfirm'),
            'wrapper_class' => 'show_if_simple',
        )
    );

    // Add CompetitionId field
    woocommerce_wp_text_input(
        array(
            'id' => '_yourpropfirm_competition_id',
            'label' => __('CompetitionId (YourPropfirm)', 'yourpropfirm'),
            'placeholder' => __('Enter CompetitionId (YourPropfirm)', 'yourpropfirm'),
            'desc_tip' => true,
            'description' => __('Enter CompetitionId (YourPropfirm).', 'yourpropfirm'),
            'wrapper_class' => 'show_if_simple',
        )
    );
}
add_action('woocommerce_product_options_general_product_data', 'yourpropfirm_add_dynamic_fields');

// Save ProgramId and CompetitionId fields
function yourpropfirm_save_dynamic_fields($product_id) {
    if (isset($_POST['_yourpropfirm_program_id'])) {
        $program_id = sanitize_text_field($_POST['_yourpropfirm_program_id']);
        update_post_meta($product_id, '_yourpropfirm_program_id', esc_attr($program_id));
    }

    if (isset($_POST['_yourpropfirm_competition_id'])) {
        $competition_id = sanitize_text_field($_POST['_yourpropfirm_competition_id']);
        update_post_meta($product_id, '_yourpropfirm_competition_id', esc_attr($competition_id));
    }
}
add_action('woocommerce_process_product_meta', 'yourpropfirm_save_dynamic_fields');

// Add ProgramID to the products list column in the admin
function yourpropfirm_add_program_id_column_to_admin_products($columns) {
    $enable_challenge = get_option('yourpropfirm_connection_challenge_enabled');
    if ($enable_challenge !== 'enable') {
        return $columns; // Always return columns to avoid breaking the table
    }
    
    $new_columns = array();

    foreach ($columns as $key => $name) {
        $new_columns[$key] = $name;

        if ('sku' === $key) {
            $new_columns['yourpropfirm_program_id'] = __('ProgramID', 'yourpropfirm');
        }
    }

    return $new_columns;
}
add_filter('manage_edit-product_columns', 'yourpropfirm_add_program_id_column_to_admin_products', 20);

// Display ProgramID in the products list column in the admin
function yourpropfirm_display_program_id_in_admin_products($column, $post_id) {
    $enable_challenge = get_option('yourpropfirm_connection_challenge_enabled');
    if ($enable_challenge !== 'enable') {
        return;
    }

    if ('yourpropfirm_program_id' === $column) {
        $program_id = get_post_meta($post_id, '_yourpropfirm_program_id', true);
        if ($program_id) {
            echo '<span id="yourpropfirm_program_id-' . esc_attr($post_id) . '">' . esc_html($program_id) . '</span>';
        } else {
            echo '—';
        }
    }
}
add_action('manage_product_posts_custom_column', 'yourpropfirm_display_program_id_in_admin_products', 10, 2);


// Add CompetitionID to the products list column in the admin
function yourpropfirm_add_competition_id_column_to_admin_products($columns) {
    $enable_competition = get_option('yourpropfirm_connection_competition_enabled');
    if ($enable_competition !== 'enable') {
        return $columns; // Always return columns to avoid breaking the table
    }

    $new_columns = array();

    foreach ($columns as $key => $name) {
        $new_columns[$key] = $name;

        if ('sku' === $key) {
            $new_columns['yourpropfirm_competition_id'] = __('CompetitionID', 'yourpropfirm');
        }
    }

    return $new_columns;
}
add_filter('manage_edit-product_columns', 'yourpropfirm_add_competition_id_column_to_admin_products', 20);

// Display CompetitionID in the products list column in the admin
function yourpropfirm_display_competition_in_admin_products($column, $post_id) {
    $enable_competition = get_option('yourpropfirm_connection_competition_enabled');
    if ($enable_competition !== 'enable') {
        return;
    }

    if ('yourpropfirm_competition_id' === $column) {
        $competition_id = get_post_meta($post_id, '_yourpropfirm_competition_id', true);
        if ($competition_id) {
            echo '<span id="yourpropfirm_competition_id-' . esc_attr($post_id) . '">' . esc_html($competition_id) . '</span>';
        } else {
            echo '—';
        }
    }
}
add_action('manage_product_posts_custom_column', 'yourpropfirm_display_competition_in_admin_products', 10, 2);


function yourpropfirm_save_quick_edit_data($product_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
        return $product_id;

    if (isset($_POST['_inline_edit']) && wp_verify_nonce($_POST['_inline_edit'], 'inlineeditnonce')) {
        if (isset($_POST['_yourpropfirm_program_id'])) {
            update_post_meta($product_id, '_yourpropfirm_program_id', sanitize_text_field($_POST['_yourpropfirm_program_id']));
        }
    }

    return $product_id;
}
add_action('save_post_product', 'yourpropfirm_save_quick_edit_data');

function yourpropfirm_add_program_id_quick_edit_field($column_name, $post_type) {
    if ($column_name != 'yourpropfirm_program_id' || $post_type != 'product') return;

    // Output custom field
    echo '<fieldset class="inline-edit-col-left">
            <div class="inline-edit-col">
                <label class="alignleft">
                    <span class="title">' . __('YPFID', 'yourpropfirm') . '</span>
                    <span class="input-text-wrap">
                        <input type="text" name="_yourpropfirm_program_id" class="ptitle" value="" />
                    </span>
                </label>
            </div>
        </fieldset>';
}
add_action('quick_edit_custom_box', 'yourpropfirm_add_program_id_quick_edit_field', 10, 2);