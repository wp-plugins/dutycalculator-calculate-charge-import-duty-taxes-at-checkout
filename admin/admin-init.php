<?php

add_action('admin_menu', 'dc_woo_admin_menu');
add_action('admin_notices', 'dc_woo_admin_warnings');

function dc_woo_admin_warnings()
{
    global $woocommerce_dutycalculator_charge, $hook_suffix, $wpdb;
    /** @var  $woocommerce_dutycalculator_charge WooCommerceDutyCalculatorCharge */
        $warnings = array();
        $isApiKey = false;
        if($_POST)
        {
            if($_POST['dc_woo_api_key'])
            {
                $isApiKey = true;
            }
        }
        elseif(get_option('dc_woo_api_key'))
        {
            $isApiKey = true;
        }
        if (!$isApiKey)
        {
            $warnings['no_api_key'] = 'Insert API key and set destination countries for which you want to activate DutyCalculator <a href="' . admin_url( 'admin.php?page=' . $woocommerce_dutycalculator_charge->configPageName ) . '">here</a>.';
        }
        $rates = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates
					WHERE tax_rate_name = '" . $woocommerce_dutycalculator_charge->taxName . "'
					ORDER BY tax_rate_order	");
        if (!count($rates))
        {
            $warnings['no_dc_tax_rates'] = 'Insert DutyCalculator row to at least one of the tax rate classes <a href="' . admin_url( 'admin.php?page=woocommerce_settings&tab=tax&section=standard' ) . '">here</a>.';
        }
        $plugin_data = get_plugin_data( $woocommerce_dutycalculator_charge->pluginFilename, false );

        if (count($warnings))
        {
            echo '<div class="updated"><p>You have to perform the following actions to set up <strong>' .$plugin_data['Name'] . '</strong></p><ul style="list-style:disc; margin-left:15px">';
            foreach ($warnings as $warning_type => $message)
            {
                echo '<li>' . $message . '</li>';
            }
            echo '</ul></div>';
        }
}

function dc_woo_admin_menu()
{
    global $woocommerce_dutycalculator_charge;
    add_submenu_page( $woocommerce_dutycalculator_charge->configPageName , __( 'DutyCalculator Plugin Settings' ), __( 'DutyCalculator Plugin Settings' ), 'manage_options', $woocommerce_dutycalculator_charge->configPageName, 'dc_woo_settings_fields' );
}

function dc_woo_settings_fields()
{
    $dc_settings = apply_filters('dc-settings', array(
        array(
            'title' => __( 'DutyCalculator API Key', 'woocommerce' ),
            'desc'     => __( 'If you do not have a DutyCalculator account, sign up <a target="_blank" href="http://www.dutycalculator.com/compare-plans/">here</a> for your API account.' ),
            'id'       => 'dc_woo_api_key',
            'type' 		=> 'text',
            'css' 		=> 'min-width:300px;',
        ),
        array(
            'title' => __( 'Destination countries', 'woocommerce' ),
            'desc' 		=> __( '<a target="_blank" href="http://www.dutycalculator.com/help_center/what-countries-are-covered-by-the-dutycalculator/">View available countries</a>', 'woocommerce' ),
            'id' 		=> 'dc_woo_allowed_countries',
            'default'	=> 'all',
            'type' 		=> 'select',
            'class'		=> 'chosen_select',
            'css' 		=> 'min-width:300px;',
            'desc_tip'	=>  true,
            'options' => array(
                'all'  => __( 'All Countries', 'woocommerce' ),
                'specific' => __( 'Specific Countries', 'woocommerce' )
            )
        ),
        array(
            'title' => __( '', 'woocommerce' ),
            'desc' 		=> '',
            'id' 		=> 'dc_woo_specific_allowed_countries',
            'css' 		=> '',
            'default'	=> '',
            'type' 		=> 'multi_select_countries'
        ),
        array(
            'title' => __( 'HS tariff codes', 'woocommerce' ),
            'desc'          => __( 'Obtain destination country HS tariff codes for confirmed orders', 'woocommerce' ),
            'id'            => 'dc_woo_enable_hscode',
            'default'       => '1',
            'type'          => 'checkbox',
            'desc_tip'		=>  __( 'When enabled, HS tariff codes for destination country are added to the order details. We advise you to add these HS tariff codes to the commercial invoice of the shipment, to avoid mismatches between import duty & taxes calculated and actually charged.  This is not a free service, you will be charged “Get HS code” rate as per <a target="_blank" href="http://www.dutycalculator.com/compare-plans/">your account plan</a>.', 'woocommerce' )
        ),
    ));
    dc_woo_settings_process($dc_settings);
}

function dc_woo_settings_process($options)
{
    global $woocommerce;
    $suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
    wp_register_script( 'chosen', $woocommerce->plugin_url() . '/assets/js/chosen/chosen.jquery'.$suffix.'.js', array('jquery'), $woocommerce->version );
    wp_enqueue_style( 'dc_chosen_styles', $woocommerce->plugin_url() . '/assets/css/chosen.css' );
    wp_enqueue_style( 'woocommerce_admin_styles', $woocommerce->plugin_url() . '/assets/css/admin.css' );

    if (!empty( $_POST['dc_save_settings']))
    {
        if (function_exists('current_user_can') && !current_user_can('manage_options'))
        {
            wp_die('<p><strong>' . __( 'You dont have access.', 'woocommerce' ) . '</strong></p>');
        }

        update_option('dc_woo_api_key', $_POST['dc_woo_api_key']);
        update_option('dc_woo_allowed_countries', $_POST['dc_woo_allowed_countries']);
        update_option('dc_woo_specific_allowed_countries', $_POST['dc_woo_specific_allowed_countries']);
        update_option('dc_woo_enable_hscode', $_POST['dc_woo_enable_hscode']);

        echo '<div id="message" class="updated fade"><p><strong>' . __( 'Your settings have been saved.', 'woocommerce' ) . '</strong></p></div>';
        do_action('woocommerce_dutycalculator_after_settings_update');
    }

    ?>
    <div class="wrap woocommerce">
    <h3>General Options</h3>
    <form method="post" id="dc_main_config_form" action="" enctype="multipart/form-data">
    <?php
    if (function_exists ('wp_nonce_firld'))
    {
        wp_nonce_field('dc_main_config_form');
    }
    ?>
    <table class="form-table">
    <tbody>
        <?php
        wp_register_script( 'chosen', $woocommerce->plugin_url() . '/assets/js/chosen/chosen.jquery'.$suffix.'.js', array('jquery'), $woocommerce->version );
        wp_enqueue_script( 'ajax-chosen' );
        wp_enqueue_script( 'chosen' );

    foreach ($options as $value)
    {
        if ( ! isset( $value['type'] ) ) continue;
        if ( ! isset( $value['id'] ) ) $value['id'] = '';
        if ( ! isset( $value['title'] ) ) $value['title'] = isset( $value['name'] ) ? $value['name'] : '';
        if ( ! isset( $value['class'] ) ) $value['class'] = '';
        if ( ! isset( $value['css'] ) ) $value['css'] = '';
        if ( ! isset( $value['default'] ) ) $value['default'] = '';
        if ( ! isset( $value['desc'] ) ) $value['desc'] = '';
        if ( ! isset( $value['desc_tip'] ) ) $value['desc_tip'] = false;

        // Custom attribute handling
        $custom_attributes = array();

        if ( ! empty( $value['custom_attributes'] ) && is_array( $value['custom_attributes'] ) )
            foreach ( $value['custom_attributes'] as $attribute => $attribute_value )
                $custom_attributes[] = esc_attr( $attribute ) . '="' . esc_attr( $attribute_value ) . '"';

        // Description handling
        $description = '<span class="description">' . wp_kses_post( $value['desc'] ) . '</span>';

        // Switch based on type
        switch ($value['type'])
        {
            // Checkbox input
            case 'checkbox' :
                $option_value 	= get_option( $value['id'], $value['default'] );

                if ( ! isset( $value['hide_if_checked'] ) ) $value['hide_if_checked'] = false;
                if ( ! isset( $value['show_if_checked'] ) ) $value['show_if_checked'] = false;

                if ( ! isset( $value['checkboxgroup'] ) || ( isset( $value['checkboxgroup'] ) && $value['checkboxgroup'] == 'start' ) ) {
                    ?>
                    <tr valign="top" class="<?php
                    if ( $value['hide_if_checked'] == 'yes' || $value['show_if_checked']=='yes') echo 'hidden_option';
                    if ( $value['hide_if_checked'] == 'option' ) echo 'hide_options_if_checked';
                    if ( $value['show_if_checked'] == 'option' ) echo 'show_options_if_checked';
                    ?>">
                    <th scope="row" class="titledesc"><?php echo esc_html( $value['title'] ) ?></th>
                    <td class="forminp forminp-checkbox">
                    <fieldset>
                <?php
                } else {
                    ?>
                    <fieldset class="<?php
                    if ( $value['hide_if_checked'] == 'yes' || $value['show_if_checked'] == 'yes') echo 'hidden_option';
                    if ( $value['hide_if_checked'] == 'option') echo 'hide_options_if_checked';
                    if ( $value['show_if_checked'] == 'option') echo 'show_options_if_checked';
                    ?>">
                <?php
                }

                ?>
                <legend class="screen-reader-text"><span><?php echo esc_html( $value['title'] ) ?></span></legend>

                <label for="<?php echo $value['id'] ?>">
                    <input
                        name="<?php echo esc_attr( $value['id'] ); ?>"
                        id="<?php echo esc_attr( $value['id'] ); ?>"
                        type="checkbox"
                        value="1"
                        <?php checked( $option_value, '1'); ?>
                        <?php echo implode( ' ', $custom_attributes ); ?>
                        /> <?php echo wp_kses_post( $value['desc'] ) ?></label> <?php echo '<p class="description">' . $value['desc_tip'] . '</p>'; ?>
                <?php

                if ( ! isset( $value['checkboxgroup'] ) || ( isset( $value['checkboxgroup'] ) && $value['checkboxgroup'] == 'end' ) ) {
                    ?>
                    </fieldset>
                    </td>
                    </tr>
                <?php
                } else {
                    ?>
                    </fieldset>
                <?php
                }
                break;

            case 'text':
            $type 			= $value['type'];
            $class 			= '';

            $option_value 	= get_option( $value['id'], $value['default'] );

            ?><tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?></label>
                <?php //echo $tip; ?>
            </th>
            <td class="forminp forminp-<?php echo sanitize_title( $value['type'] ) ?>">
                <input
                    name="<?php echo esc_attr( $value['id'] ); ?>"
                    id="<?php echo esc_attr( $value['id'] ); ?>"
                    type="<?php echo esc_attr( $type ); ?>"
                    style="<?php echo esc_attr( $value['css'] ); ?>"
                    value="<?php echo esc_attr( $option_value ); ?>"
                    class="<?php echo esc_attr( $value['class'] ); ?>"
                    <?php echo implode( ' ', $custom_attributes ); ?>
                    /> <?php echo $description; ?>
            </td>
            </tr><?php
            break;

            // Select boxes
            case 'select' :
            case 'multiselect' :

                $option_value = get_option( $value['id'], $value['default'] );

                ?><tr valign="top">
                <th scope="row" class="titledesc">
                    <label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?></label>
                    <?php //echo $tip; ?>
                </th>
                <td class="forminp forminp-<?php echo sanitize_title( $value['type'] ) ?>">
                    <select
                        name="<?php echo esc_attr( $value['id'] ); ?><?php if ( $value['type'] == 'multiselect' ) echo '[]'; ?>"
                        id="<?php echo esc_attr( $value['id'] ); ?>"
                        style="<?php echo esc_attr( $value['css'] ); ?>"
                        class="<?php echo esc_attr( $value['class'] ); ?>"
                        <?php echo implode( ' ', $custom_attributes ); ?>
                        <?php if ( $value['type'] == 'multiselect' ) echo 'multiple="multiple"'; ?>
                        >
                        <?php
                        foreach ( $value['options'] as $key => $val ) {
                            ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php

                            if ( is_array( $option_value ) )
                                selected( in_array( $key, $option_value ), true );
                            else
                                selected( $option_value, $key );

                            ?>><?php echo $val ?></option>
                        <?php
                        }
                        ?>
                    </select> <?php echo $description; ?>
                </td>
                </tr><?php
            break;

            // Country multi selects
            case 'multi_select_countries' :

                $selections = (array) get_option( $value['id'] );

                $countries = $woocommerce->countries->countries;
                asort( $countries );
                ?><tr valign="top">
                <th scope="row" class="titledesc">
                    <label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?></label>
                    <?php //echo $tip; ?>
                </th>
                <td class="forminp">
                    <select multiple="multiple" name="<?php echo esc_attr( $value['id'] ); ?>[]" style="width:450px;" data-placeholder="<?php _e( 'Choose countries&hellip;', 'woocommerce' ); ?>" title="Country" class="chosen_select">
                        <?php
                        if ( $countries )
                            foreach ( $countries as $key => $val )
                                echo '<option value="'.$key.'" ' . selected( in_array( $key, $selections ), true, false ).'>' . $val . '</option>';
                        ?>
                    </select> <?php echo $description; ?>
                </td>
                </tr><?php
            break;
        }
    }
        ?>
        </tbody>
        </table>
        <p class="submit">
            <?php if ( ! isset( $GLOBALS['hide_save_button'] ) ) : ?>
                <input name="dc_save_settings" class="button-primary" type="submit" value="<?php _e( 'Save changes', 'woocommerce' ); ?>" />
            <?php endif; ?>
            <input type="hidden" name="subtab" id="last_tab" />
        </p>
        </form>
        </div>

    <script type="text/javascript">
        jQuery(window).load(function(){

            // Countries
            jQuery('select#dc_woo_allowed_countries').change(function(){
                if (jQuery(this).val()=="specific") {
                    jQuery(this).parent().parent().next('tr').show();
                } else {
                    jQuery(this).parent().parent().next('tr').hide();
                }
            }).change();

            // Chosen selects
            jQuery("select.chosen_select").chosen();

            jQuery("select.chosen_select_nostd").chosen({
                allow_single_deselect: 'true'
            });
        });
    </script>
<?php
}


