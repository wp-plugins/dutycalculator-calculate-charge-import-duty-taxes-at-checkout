<?php
/*
Plugin Name: DutyCalculator: Calculate & charge import duty & taxes at checkout
Plugin URI: http://www.dutycalculator.com
Description: Calculate & charge import duty & taxes at checkout to provide your customers with DDP service - requires <a href="http://www.dutycalculator.com">API key</a>
Version: 1.0.5
Author: DutyCalculator
Author URI: http://www.dutycalculator.com/team/
*/
defined( 'ABSPATH' ) OR exit;

if (!class_exists('WooCommerceDutyCalculatorCharge'))
{
    /**
     * Class WooCommerceDutyCalculatorCharge
     */
    class WooCommerceDutyCalculatorCharge
    {
        public $version = '1.0.5';
        public $pluginFilename = __FILE__;
        public $taxName = 'Import Duty & Taxes';
        public $isSaveFailed = '0';
        public $failedCalculationCartText = 'Import duty & taxes may be due upon delivery';
        public $failedCalculationCartTextIncludingTax = 'No';
        public $configPageName = 'woocommerce_dutycalculator_charge_settings';
        public $calculation;
        public $order;
        public $plugin_url;
        public $plugin_path;

        /** @var  WooCommerceDutyCalculatorAPI */
        public $api;

        public function __construct() {
            // called just before the woocommerce template functions are included
            add_action( 'init', array( $this, 'init' ), 0 );
//            add_action( 'init', array( $this, 'include_template_functions' ), 20 );

            // called only after woocommerce has finished loading
            add_action( 'woocommerce_init', array( $this, 'woocommerce_loaded' ) );

            // called after all plugins have loaded
            add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ) );


            // indicates we are running the admin
            if ( is_admin() ) {
                // ...
            }

            // indicates we are being served over ssl
            if ( is_ssl() ) {
                // ...
            }

            // Include required files
            $this->includes();

            // Hooks
            add_filter('plugin_action_links', array($this, 'dc_woo_plugin_action_links'), 10, 2);
            add_filter('woocommerce_calculate_totals', array($this, 'woocommerce_dutycalculator_change_cart_charges'));
            add_action('woocommerce_checkout_update_order_meta', array($this, 'dc_woo_calculation_response_to_order_meta'), 10, 2); // for the order section ajax
            add_filter('woocommerce_checkout_order_processed', array($this, 'add_hs_meta_to_order_items'), 10, 2);
            add_filter('woocommerce_admin_order_data_after_shipping_address', array($this, 'dc_woo_order_section_add_link_to_calculation_after_shipping_address'));
            add_filter('woocommerce_admin_order_data_after_shipping_address', array($this, 'dutycalculator_woocommerce_calc_line_taxes_ajax_request')); // for the order section ajax
            add_action('wp_ajax_dutycalculator_woocommerce_calc_line_taxes', array($this,'dc_woo_calc_line_taxes_ajax_response'));
            add_action('woocommerce_new_order_item', array($this,'get_order_from_new_order_item'), 10, 3);
            add_action('woocommerce_ajax_add_order_item_meta', array($this,'add_order_item_hs_code_meta_ajax'), 10, 2);
            add_filter('woocommerce_product_options_tax', array($this, 'woocommerce_dutycalculator_show_widget')); //dc classification widget
            add_action('save_post_product', array($this, 'add_dc_post_meta')); //save dc widget data
            add_filter('woocommerce_cart_tax_totals', array($this, 'change_taxes_if_failed_calculation'), 1, 2);
            add_filter('woocommerce_order_tax_totals', array($this, 'change_taxes_if_failed_calculation'), 1, 2);
            add_action('woocommerce_saved_order_items', array($this, 'order_items_saved'), 1, 2);

            // Loaded action
            do_action( 'woocommerce_dutycalculator_charge_loaded' );
        }

        public function change_taxes_if_failed_calculation($tax_totals, $cartOrOrder)
        {
            global $woocommerce; /** @var  $woocommerce Woocommerce */
            $rawXml = $woocommerce->session->_dc_cart_calculation_response;
            try
            {
                if (stripos($rawXml, '<?xml') === false)
                {
                    throw new Exception($rawXml);
                }
                $answer = new SimpleXMLElement($rawXml);
//            $calcAnswerAttributes = $answer->attributes();
//            $isCalculationFailed = (bool)(string)$calcAnswerAttributes['failed-calculation'];
                $isCalculationFailed = ($answer->getName() == 'error');
                foreach ( $tax_totals as $code => $tax )
                {
                    if ($tax->label == $this->taxName)
                    {
                        if ($isCalculationFailed)
                        {
                            if (get_option('woocommerce_tax_display_cart') == 'incl')
                            {
                                $tax->formatted_amount = $this->failedCalculationCartTextIncludingTax;
                            }
                            else
                            {
                                $tax->formatted_amount = $this->failedCalculationCartText;
                            }
                        }
                        elseif ($tax->amount == 0 && get_option('woocommerce_tax_display_cart') == 'incl')
                        {
                            $tax->formatted_amount = '';
                        }
                    }
                }
            }
            catch (Exception $e)
            {}
            return $tax_totals;
        }

        public function store_api_request_to_order($order)
        {
            add_post_meta($order->id, '_dc_api_requests', $this->api->requestUri); // just in case
        }

        public function order_items_saved($order_id, $items)
        {
            global $woocommerce;
            if ( version_compare( $woocommerce->version, '2.3.10', '>' ) && null !== $woocommerce->version ) {

                if (defined('DOING_AJAX') && DOING_AJAX) {
                    global $wpdb;
                    global $woocommerce;

                    // Order items + fees
                    $subtotal = 0;
                    $total = 0;
                    $subtotal_tax = 0;
                    $total_tax = 0;
                    $taxes = array('items' => array(), 'shipping' => array());

                    $DcItems = array();

                    $order = wc_get_order($order_id);

                    if (sizeof($items) > 0) {
                        $sendApiRequest = false;
                        $requestParams = array();
                        $requestParams['cat'] = array();
                        $requestParams['desc'] = array();
                        $requestParams['qty'] = array();
                        $requestParams['value'] = array();
                        $requestParams['reference'] = array();
                        $requestParams['weight'] = array();
                        $requestParams['weight_unit'] = array();

                        foreach ($items['order_item_id'] as $item_id) {

                            $item_id = absint($item_id);
                            $tax_class = esc_attr($items['order_item_tax_class'][$item_id]);
                            $product_id = $order->get_item_meta($item_id, '_product_id', true);

                            if (!$item_id || $tax_class == '0')
                                continue;

                            // Get product details
                            if (get_post_type($product_id) == 'product') {
                                $_product = get_product($product_id);
//                        $items[$item_id]['order_item_id'] = $item_id;
                                $DcItems[$item_id]['product_id'] = $product_id;
                                $item_tax_status = $_product->get_tax_status();
                            } else {
                                $item_tax_status = 'taxable';
                            }
                            // Only calc if taxable
                            if ($item_tax_status == 'taxable') {

                                if ($this->is_dc_settings_country($order->shipping_country)) {
                                    $dcTax = $this->get_dc_tax_by_class($items['order_item_tax_class'][$item_id]);
                                    if ($dcTax) {
                                        $DcItems[$item_id]['dc_tax_id'] = $dcTax->tax_rate_id;
                                        $sendApiRequest = true;
                                    }
                                }

                                // collecting item data for api request
                                if ($DcItems[$item_id]['dc_tax_id']) {
                                    $idx = (string)$product_id; // do not use more than one identical products in order (just increase its quantity)
                                    $product = $_product;
                                    /** @var $product WC_Product */
                                    $requestParams['cat'][$idx] = $product->dc_duty_category_id ? $product->dc_duty_category_id : '';
                                    $requestParams['desc'][$idx] = $product->get_title();
                                    $requestParams['qty'][$idx] = $items['order_item_qty'][$item_id];
                                    $requestParams['value'][$idx] = ($items['line_total'][$item_id] / $items['order_item_qty'][$item_id]);
                                    $requestParams['reference'][$idx] = $idx;
                                    $requestParams['weight'][$idx] = $product->get_weight() ? $product->get_weight() : 0;
                                    $requestParams['weight_unit'][$idx] = get_option('woocommerce_weight_unit') ? get_option('woocommerce_weight_unit') : 'lb';
                                }
                            }
                        }

                        if ($sendApiRequest) {
                            // api request
                            $countryFrom = get_option('woocommerce_default_country');
                            $countryTo = $order->shipping_country;
                            $state = $order->shipping_state;
                            $currency = get_option('woocommerce_currency');
                            $shipping = $order->order_shipping;
                            $requestParams['from'] = substr($countryFrom, 0, 2);
                            $requestParams['to'] = $countryTo;
                            if ($state) {
                                $requestParams['province'] = $state;
                            }
                            $requestParams['classify_by'] = 'cat desc';
                            $requestParams['insurance'] = 0;
                            $requestParams['currency'] = $currency;
                            $requestParams['output_currency'] = $currency;
                            $requestParams['shipping'] = $shipping;
                            $requestParams['commercial_importer'] = '';
                            $requestParams['imported_wt'] = 0;
                            $requestParams['imported_value'] = 0;
                            $requestParams['detailed_result'] = 1;
                            $requestParams['save_failed'] = $this->isSaveFailed;
                            $requestParams['use_defaults'] = 1;

                            $dcApi = $this->api->send_request_and_get_response($this->api->actionCalculation, $requestParams);
                            $rawXml = $dcApi->response;

                            $this->calculation = new WooCommerceDutyCalculatorApiCalculation($rawXml);
                            $this->dc_woo_calculation_response_to_order_meta($order_id); // setting new calculation response to order data
                            $this->store_api_request_to_order($order);

                            try {
                                if (stripos($rawXml, '<?xml') === false) {
                                    throw new Exception($rawXml);
                                }
                                $answer = new SimpleXMLElement($rawXml);
                                $isCalculationError = ($answer->getName() == 'error' ? true : false);

                                if ($isCalculationError) {
                                    $linkToCalculation = '<span style="color:#FF0000">Unable to calculate import duty & taxes!</br>' . (string)$answer->message . ' (Error code: ' . (string)$answer->code . ')</span>';
                                } else {
                                    $calcAnswerAttributes = $answer->attributes();
                                    $linkToCalculation = '<a target="_blank" href="' . $this->api->dutyCalculatorApiHost . '/' . $this->api->dutyCalculatorSavedCalculationUrl . $calcAnswerAttributes['id'] . '/">Import duty & tax calculation</a>'; //redrawing calculation URL
                                    $this->save_dc_calculation_for_order($order); // save calculation
                                    $this->store_api_request_to_order($order);
                                }

                                $responseItems = $answer->xpath('item');
                                foreach ($responseItems as $responseItem) {
                                    $responseItemAttributes = $responseItem->attributes();
                                    $productIdFromResponse = (string)$responseItemAttributes['reference'];
                                    foreach ($items['order_item_id'] as $item_id) {
                                        if ($productIdFromResponse == $DcItems[$item_id]['product_id']) { // update ORDER items to dc taxes
                                            $responseItemTax = (float)$responseItem->total->amount;

                                            $items['line_subtotal_tax'][$item_id][$DcItems[$item_id]['dc_tax_id']] = $responseItemTax; // important
                                            $items['line_tax'][$item_id][$DcItems[$item_id]['dc_tax_id']] = $responseItemTax; // important

                                            //   $taxes[$item['dc_tax_id']] += $responseItemTax; // important
                                            //  $item_tax += $responseItemTax; // important
                                        }
                                    }
                                }
                            } catch (Exception $e) {
                            }
                        }
                    }

                    if (isset($items['order_item_id'])) {
                        $line_total = $line_subtotal = $line_tax = $line_subtotal_tax = array();

                        foreach ($items['order_item_id'] as $item_id) {

                            $item_id = absint($item_id);

                            if (isset($items['order_item_name'][$item_id])) {
                                $wpdb->update(
                                    $wpdb->prefix . 'woocommerce_order_items',
                                    array('order_item_name' => wc_clean($items['order_item_name'][$item_id])),
                                    array('order_item_id' => $item_id),
                                    array('%s'),
                                    array('%d')
                                );
                            }


                            // Get values. Subtotals might not exist, in which case copy value from total field
                            $line_total[$item_id] = isset($items['line_total'][$item_id]) ? $items['line_total'][$item_id] : 0;
                            $line_subtotal[$item_id] = isset($items['line_subtotal'][$item_id]) ? $items['line_subtotal'][$item_id] : $line_total[$item_id];
                            $line_tax[$item_id] = isset($items['line_tax'][$item_id]) ? $items['line_tax'][$item_id] : array();
                            $line_subtotal_tax[$item_id] = isset($items['line_subtotal_tax'][$item_id]) ? $items['line_subtotal_tax'][$item_id] : $line_tax[$item_id];

                            // Format taxes
                            $line_taxes = array_map('wc_format_decimal', $line_tax[$item_id]);
                            $line_subtotal_taxes = array_map('wc_format_decimal', $line_subtotal_tax[$item_id]);

                            // Update values
                            wc_update_order_item_meta($item_id, '_line_subtotal', wc_format_decimal($line_subtotal[$item_id]));
                            wc_update_order_item_meta($item_id, '_line_total', wc_format_decimal($line_total[$item_id]));
                            wc_update_order_item_meta($item_id, '_line_subtotal_tax', array_sum($line_subtotal_taxes));
                            wc_update_order_item_meta($item_id, '_line_tax', array_sum($line_taxes));

                            // Save line tax data - Since 2.2
                            wc_update_order_item_meta($item_id, '_line_tax_data', array('total' => $line_taxes, 'subtotal' => $line_subtotal_taxes));
                            $taxes['items'][] = $line_taxes;

                            // Total up
                            $subtotal += wc_format_decimal($line_subtotal[$item_id]);
                            $total += wc_format_decimal($line_total[$item_id]);
                            $subtotal_tax += array_sum($line_subtotal_taxes);
                            $total_tax += array_sum($line_taxes);

                            // Clear meta cache
                            wp_cache_delete($item_id, 'order_item_meta');
                        }
                    }

                    // Taxes
                    $order_taxes = isset($items['order_taxes']) ? $items['order_taxes'] : array();
                    $taxes_items = array();
                    $taxes_shipping = array();
                    $total_tax = 0;
                    $total_shipping_tax = 0;

                    // Sum items taxes
                    foreach ($taxes['items'] as $rates) {

                        foreach ($rates as $id => $value) {

                            if (isset($taxes_items[$id])) {
                                $taxes_items[$id] += $value;
                            } else {
                                $taxes_items[$id] = $value;
                            }
                        }
                    }

                    // Update order taxes
                    foreach ($order_taxes as $item_id => $rate_id) {

                        if (isset($taxes_items[$rate_id])) {
                            $_total = wc_format_decimal($taxes_items[$rate_id]);
                            wc_update_order_item_meta($item_id, 'tax_amount', $_total);

                            $total_tax += $_total;
                        }

                        if (isset($taxes_shipping[$rate_id])) {
                            $_total = wc_format_decimal($taxes_shipping[$rate_id]);
                            wc_update_order_item_meta($item_id, 'shipping_tax_amount', $_total);

                            $total_shipping_tax += $_total;
                        }
                    }

                    // Update totals
                    update_post_meta($order_id, '_order_total', wc_format_decimal($items['_order_total']));

                    // Update tax
                    update_post_meta($order_id, '_order_tax', wc_format_decimal($total_tax));

                    // Update version after saving
                    update_post_meta($order_id, '_order_version', WC_VERSION);
                }
            }

        }

        public function get_order_from_new_order_item($itemId, $item, $orderId)
        {
            global $woocommerce;
            $this->order = new WC_Order($orderId);

            if (version_compare( $woocommerce->version, '2.3.10', '>' ) && null !== $woocommerce->version) {

                if ($item['order_item_type'] == 'tax') {
                    $items = $this->order->get_items();

                    foreach ($items as $iId => $i) {
                        $lineTaxData = unserialize($i['line_tax_data']);

                        $taxId = key($lineTaxData['total']);

                        $lineTaxData['total'][$taxId] = $i['line_tax'];
                        $lineTaxData['subtotal'][$taxId] = $i['line_subtotal_tax'];

                        wc_update_order_item_meta($iId, '_line_tax_data', array('total' => $lineTaxData['total'], 'subtotal' => $lineTaxData['subtotal']));
                    }
                }
            }
        }

        public function add_order_item_hs_code_meta_ajax($itemId, $item)
        {
            $product = new WC_Product_Simple($item['product_id']);
            $dcTax = $this->get_dc_tax_by_class($product->get_tax_class());
            if (!$dcTax)
            {
                return;
            }
            $order = $this->order;

            if ($order->shipping_country)
            {
                $params = array();
                $params['to'] = $order->shipping_country;
                if ($order->shipping_state)
                {
                    $params['province'] = $order->shipping_state;
                }
                $params['classify_by'] = 'cat desc';
                $params['detailed_result'] = 1;
                $params['cat'] = array();
                $params['desc'] = array();
                $idx = $item['product_id'];
                $params['cat'][$idx] = $product->dc_duty_category_id;
                $params['desc'][$idx] = $item['name'];
                $dcApi = $this->api->send_request_and_get_response($this->api->actionGetHsCode, $params);
                try
                {
                    $rawXml = $dcApi->response;
                    if (stripos($rawXml, '<?xml') === false)
                    {
                        throw new Exception($rawXml);
                    }
                    $answer = new SimpleXMLElement($rawXml);
                    if ($answer->getName() != 'error')
                    {
                        $node = current($answer->xpath('classification'));
                        $hsCode = (string)current($node->xpath('hs-code'));
                        wc_add_order_item_meta($itemId, 'HS code destination country', $hsCode);
                        $this->store_api_request_to_order($order);
                    }
                }
                catch (Exception $e)
                {}
            }
        }

        public function dc_woo_calc_line_taxes_ajax_response()
        {
            global $wpdb;

            check_ajax_referer( 'calc-totals', 'security' );

            header( 'Content-Type: application/json; charset=utf-8' );

            $tax = new WC_Tax();
            $taxes = $tax_rows = $item_taxes = $shipping_taxes = array();
            $order_id 		= absint( $_POST['order_id'] );
            $order          = new WC_Order( $order_id );
            $country 		= strtoupper( esc_attr( $_POST['country'] ) );
            $state 			= strtoupper( esc_attr( $_POST['state'] ) );
            $postcode 		= strtoupper( esc_attr( $_POST['postcode'] ) );
            $city 			= sanitize_title( esc_attr( $_POST['city'] ) );
            $items          = isset( $_POST['items'] ) ? $_POST['items'] : array();
            $shipping		= $_POST['shipping'];
            $item_tax		= 0;
            parse_str( $_POST['serializedItems'], $serializedItems );


            // Calculate sales tax first
            if ( sizeof( $items ) > 0 )
            {
                $sendApiRequest = false;
                $requestParams = array();
                $requestParams['cat'] = array();
                $requestParams['desc'] = array();
                $requestParams['qty'] = array();
                $requestParams['value'] = array();
                $requestParams['reference'] = array();
                $requestParams['weight'] = array();
                $requestParams['weight_unit'] = array();

                foreach( $items as $item_id => $item ) {

                    $item_id		= absint( $item_id );
                    $line_subtotal 	= isset( $item['line_subtotal']  ) ? esc_attr( $item['line_subtotal'] ) : '';
                    $line_total		= esc_attr( $item['line_total'] );
                    $tax_class 		= esc_attr( $item['tax_class'] );
                    $product_id     = $order->get_item_meta( $item_id, '_product_id', true );

                    if ( ! $item_id || $tax_class == '0' )
                        continue;

                    // Get product details
                    if ( get_post_type( $product_id ) == 'product' ) {
                        $_product			= get_product( $product_id );
                        $items[$item_id]['order_item_id'] = $item_id;
                        $items[$item_id]['product_id'] = $product_id;
                        $item_tax_status 	= $_product->get_tax_status();
                    } else {
                        $item_tax_status 	= 'taxable';
                    }
                    // Only calc if taxable
                    if ( $item_tax_status == 'taxable' ) {

                        if($this->is_dc_settings_country($order->shipping_country))
                        {
                            $dcTax = $this->get_dc_tax_by_class($item['tax_class']);
                            if ($dcTax)
                            {
                                $items[$item_id]['dc_tax_id'] = $dcTax->tax_rate_id;
                                $sendApiRequest = true;
                            }
                        }

                        $tax_rates = $tax->find_rates( array(
                            'country' 	=> $country,
                            'state' 	=> $state,
                            'postcode' 	=> $postcode,
                            'city'		=> $city,
                            'tax_class' => $tax_class
                        ) );

                        $line_subtotal_taxes = $tax->calc_tax( $line_subtotal, $tax_rates, false );
                        $line_taxes = $tax->calc_tax( $line_total, $tax_rates, false );

                        $line_subtotal_tax = $tax->round( array_sum( $line_subtotal_taxes ) );
                        $line_tax = $tax->round( array_sum( $line_taxes ) );

                        if ( $line_subtotal_tax < 0 )
                            $line_subtotal_tax = 0;

                        if ( $line_tax < 0 )
                            $line_tax = 0;

                        $item_taxes[ $item_id ] = array(
                            'line_subtotal_tax' => wc_format_localized_price( $line_subtotal_tax ),
                            'line_tax'          => wc_format_localized_price( $line_tax )
                        );

                        $item_tax += $line_tax;

                        // Sum the item taxes
                        foreach ( array_keys( $taxes + $line_taxes ) as $key )
                            $taxes[ $key ] = ( isset( $line_taxes[ $key ] ) ? $line_taxes[ $key ] : 0 ) + ( isset( $taxes[ $key ] ) ? $taxes[ $key ] : 0 );

                        // collecting item data for api request
                        if ($items[$item_id]['dc_tax_id'])
                        {
                            $idx = (string)$product_id; // do not use more than one identical products in order (just increase its quantity)
                            $product = $_product; /** @var $product WC_Product */
                            $requestParams['cat'][$idx] = $product->dc_duty_category_id ? $product->dc_duty_category_id : '';
                            $requestParams['desc'][$idx] = $product->get_title();
                            $requestParams['qty'][$idx] = $item['quantity'];
                            $requestParams['value'][$idx] = ($item['line_total'] / $item['quantity']);
                            $requestParams['reference'][$idx] = $idx;
                            $requestParams['weight'][$idx] = $product->get_weight() ? $product->get_weight() : 0;
                            $requestParams['weight_unit'][$idx] = get_option('woocommerce_weight_unit') ? get_option('woocommerce_weight_unit') : 'lb';
                        }
                    }
                }

                if ($sendApiRequest)
                {
                    // api request
                    $countryFrom = get_option('woocommerce_default_country');
                    $countryTo = $order->shipping_country;
                    $state = $order->shipping_state;
                    $currency = get_option('woocommerce_currency');
                    $shipping = $order->order_shipping;
                    $requestParams['from'] = substr($countryFrom,0,2);
                    $requestParams['to'] = $countryTo;
                    if ($state)
                    {
                        $requestParams['province'] = $state;
                    }
                    $requestParams['classify_by'] = 'cat desc';
                    $requestParams['insurance'] = 0;
                    $requestParams['currency'] = $currency;
                    $requestParams['output_currency'] = $currency;
                    $requestParams['shipping'] = $shipping;
                    $requestParams['commercial_importer'] = '';
                    $requestParams['imported_wt'] = 0;
                    $requestParams['imported_value'] = 0;
                    $requestParams['detailed_result'] = 1;
                    $requestParams['save_failed'] = $this->isSaveFailed;
                    $requestParams['use_defaults'] = 1;

                    $dcApi = $this->api->send_request_and_get_response($this->api->actionCalculation, $requestParams);
                    $rawXml = $dcApi->response;

                    $this->calculation = new WooCommerceDutyCalculatorApiCalculation($rawXml);
                    $this->dc_woo_calculation_response_to_order_meta($order_id); // setting new calculation response to order data
                    $this->store_api_request_to_order($order);

                    try
                    {
                        if (stripos($rawXml, '<?xml') === false)
                        {
                            throw new Exception($rawXml);
                        }
                        $answer = new SimpleXMLElement($rawXml);
                        $isCalculationError = ($answer->getName() == 'error' ? true : false);

                        if ($isCalculationError)
                        {
                            $linkToCalculation = '<span style="color:#FF0000">Unable to calculate import duty & taxes!</br>' . (string)$answer->message . ' (Error code: ' .(string)$answer->code. ')</span>';
                        }
                        else
                        {
                            $calcAnswerAttributes = $answer->attributes();
                            $linkToCalculation = '<a target="_blank" href="' . $this->api->dutyCalculatorApiHost . '/' . $this->api->dutyCalculatorSavedCalculationUrl . $calcAnswerAttributes['id'].'/">Import duty & tax calculation</a>'; //redrawing calculation URL
                            $this->save_dc_calculation_for_order($order); // save calculation
                            $this->store_api_request_to_order($order);
                        }

                        $responseItems = $answer->xpath('item');
                        foreach ($responseItems as $responseItem)
                        {
                            $responseItemAttributes = $responseItem->attributes();
                            $productIdFromResponse = (string)$responseItemAttributes['reference'];
                            foreach ($items as $idItem => $item)
                            {
                                if ($productIdFromResponse == $item['product_id'])
                                { // update ORDER items to dc taxes
                                    $responseItemTax = (float)$responseItem->total->amount;
                                    $serializedItems['line_subtotal_tax'][$idItem][$item['dc_tax_id']] = $item_taxes[$idItem]['line_subtotal_tax'] += $responseItemTax; // important
                                    $serializedItems['line_tax'][$idItem][$item['dc_tax_id']] = $item_taxes[$idItem]['line_tax'] += $responseItemTax; // important
                                    $item_taxes[$idItem]['lineTaxHtml'] = wc_price( wc_round_tax_total( $item_taxes[$idItem]['line_tax'] ), array( 'currency' => $order->get_order_currency() ) );

                                    $taxes[$item['dc_tax_id']] += $responseItemTax; // important
                                    $item_tax += $responseItemTax; // important
                                }
                            }
                        }
                    }
                    catch (Exception $e)
                    {
                    }
                }
            }

            // Now calculate shipping tax
            $matched_tax_rates = array();

            $tax_rates = $tax->find_rates( array(
                'country' 	=> $country,
                'state' 	=> $state,
                'postcode' 	=> $postcode,
                'city'		=> $city,
                'tax_class' => ''
            ) );

            if ( $tax_rates ) {
                foreach ( $tax_rates as $key => $rate ) {
                    if ( isset( $rate['shipping'] ) && 'yes' == $rate['shipping'] ) {
                        $matched_tax_rates[ $key ] = $rate;
                    }
                }
            }

            $shipping_taxes = $tax->calc_shipping_tax( $shipping, $matched_tax_rates );
            $shipping_tax   = $tax->round( array_sum( $shipping_taxes ) );

            // Remove old tax rows
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE order_item_id IN ( SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id = %d AND order_item_type = 'tax' )", $order_id ) );

            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id = %d AND order_item_type = 'tax'", $order_id ) );

            // Get tax rates
            $rates = $wpdb->get_results( "SELECT tax_rate_id, tax_rate_country, tax_rate_state, tax_rate_name, tax_rate_priority FROM {$wpdb->prefix}woocommerce_tax_rates ORDER BY tax_rate_name" );

            $tax_codes = array();

            foreach( $rates as $rate ) {
                $code = array();

                $code[] = $rate->tax_rate_country;
                $code[] = $rate->tax_rate_state;
                $code[] = $rate->tax_rate_name ? sanitize_title( $rate->tax_rate_name ) : 'TAX';
                $code[] = absint( $rate->tax_rate_priority );

                $tax_codes[ $rate->tax_rate_id ] = strtoupper( implode( '-', array_filter( $code ) ) );
            }

            // Now merge to keep tax rows
            ob_start();

            foreach ( array_keys( $taxes + $shipping_taxes ) as $key ) {

                $item                        = array();
                $item['rate_id']             = $key;
                $item['name']                = $tax_codes[ $key ];
                $item['label']               = $tax->get_rate_label( $key );
                $item['compound']            = $tax->is_compound( $key ) ? 1 : 0;
                $item['tax_amount']          = wc_format_decimal( isset( $taxes[ $key ] ) ? $taxes[ $key ] : 0 );
                $item['shipping_tax_amount'] = wc_format_decimal( isset( $shipping_taxes[ $key ] ) ? $shipping_taxes[ $key ] : 0 );

                if ( ! $item['label'] ) {
                    $item['label'] = WC()->countries->tax_or_vat();
                }



                // Add line item
                $item_id = wc_add_order_item( $order_id, array(
                    'order_item_name' => $item['name'],
                    'order_item_type' => 'tax'
                ) );

                // Add line item meta
                if ( $item_id ) {
                    wc_add_order_item_meta( $item_id, 'rate_id', $item['rate_id'] );
                    wc_add_order_item_meta( $item_id, 'label', $item['label'] );
                    wc_add_order_item_meta( $item_id, 'compound', $item['compound'] );
                    wc_add_order_item_meta( $item_id, 'tax_amount', $item['tax_amount'] );
                    wc_add_order_item_meta( $item_id, 'shipping_tax_amount', $item['shipping_tax_amount'] );
                }

                include( WC()->plugin_path() . '/includes/admin/post-types/meta-boxes/views/html-order-tax.php' );
            }

            wc_save_order_items( $order_id, $serializedItems );

            $order = wc_get_order( $order_id );

            $taxes = wc_price( wc_round_tax_total( $item_tax ), array( 'currency' => $order->get_order_currency() ) );

            $tax_row_html = ob_get_clean();

            // Return
            echo json_encode( array(
                'item_tax'     => $item_tax,
                'itemTaxHtml' => wc_price( wc_round_tax_total( $item_tax ), array( 'currency' => $order->get_order_currency() ) ),
                'item_taxes'   => $item_taxes,
                'shipping_tax' => $shipping_tax,
                'tax_row_html' => $tax_row_html,
                'order_total' => $serializedItems['_order_total'],
                'currency' => get_woocommerce_currency_symbol($order->get_order_currency()),
                'link_to_calculation' => $linkToCalculation
            ) );

            // Quit out
            die();
        }

        public function woocommerce_dutycalculator_change_cart_charges()
        {
            global $woocommerce, $wpdb;
            /** @var  $woocommerce Woocommerce */
            $totalDcTaxes = 0;
            $cart = $woocommerce->cart;
            $cartItems = $woocommerce->cart->cart_contents;
            $countryFrom = get_option('woocommerce_default_country');
            $countryTo = $woocommerce->customer->get_shipping_country();
            $state = $woocommerce->customer->get_shipping_state();
            $currency = get_option('woocommerce_currency');
            $shipping = $cart->shipping_total;
            $unitWeight = get_option('woocommerce_weight_unit');
            $isCountryMatch = $this->is_dc_settings_country($countryTo);

            if ($isCountryMatch)
            {
                $requestParams = array('from' => substr($countryFrom,0,2), 'to' => $countryTo);
                if ($state)
                {
                    $requestParams['province'] = $state;
                }
                $requestParams['classify_by'] = 'cat desc';
                $requestParams['cat'] = array();
                $requestParams['desc'] = array();
                $requestParams['qty'] = array();
                $requestParams['value'] = array();
                $requestParams['reference'] = array();
                $requestParams['weight'] = array();
                $requestParams['weight_unit'] = array();
                $requestParams['insurance'] = 0;
                $requestParams['currency'] = $currency;
                $requestParams['output_currency'] = $currency;
                $requestParams['shipping'] = $shipping;
                $requestParams['commercial_importer'] = false;
                $requestParams['imported_wt'] = 0;
                $requestParams['imported_value'] = 0;
                $requestParams['detailed_result'] = 1;
                $requestParams['save_failed'] = $this->isSaveFailed;
                $requestParams['use_defaults'] = 1;
                $idx = 0;
                $dcTaxes = array();
                foreach ($cartItems as $cartItem)
                {
                    $dcTax = null;
                    $product = $cartItem['data']; /** @var $product WC_Product */
                    $dcTax = $this->get_dc_tax_by_class($product->get_tax_class());
                    if (!$dcTax)
                    {
                        continue;
                    }
                    $dcTaxes[$product->id] = $dcTax;
                    $requestParams['cat'][$idx] = $product->dc_duty_category_id;
                    $requestParams['desc'][$idx] = $product->get_title();
                    $requestParams['qty'][$idx] = $cartItem['quantity'];
                    $requestParams['value'][$idx] = $product->get_price();
                    $requestParams['reference'][$idx] = urlencode($product->id);
                    $requestParams['weight'][$idx] = $product->get_weight() ? $product->get_weight() : 0;
                    $requestParams['weight_unit'][$idx] = $unitWeight ? $unitWeight : 'lb';
                    $idx++;
                }
                if (!count($dcTaxes))
                {
                    return;
                }
                $dcApi = $this->api->send_request_and_get_response($this->api->actionCalculation, $requestParams);
                $rawXml = $dcApi->response;
                $woocommerce->session->set('_dc_cart_calculation_response', $rawXml); // storing last calculation response into woocommerce session
                try
                {
                    if (stripos($rawXml, '<?xml') === false)
                    {
                        throw new Exception($rawXml);
                    }
                    $answer = new SimpleXMLElement($rawXml);
                    $this->calculation = new WooCommerceDutyCalculatorApiCalculation($rawXml);

                    $responseItems = $answer->xpath('item');
                    foreach ($responseItems as $responseItem)
                    {
                        $responseItemAttributes = $responseItem->attributes();
                        foreach ($dcTaxes as $productId => $dcTax)
                        {
                            $responseReference = (string)$responseItemAttributes['reference'];
                            if ($productId == $responseReference)
                            {
                                $this->calculation->WcProductsApiData[$responseReference]['api_response_node'] = $responseItem; // setting up nexus WC items and DC items
                                $responseItemTax = (float)$responseItem->total->amount;
                                $cart->taxes[$dcTax->tax_rate_id] += $responseItemTax; // important
                                $totalDcTaxes += $responseItemTax;
                                foreach ($cartItems as $cart_item_key => $cartItem)
                                {
                                    if ($cartItem['product_id'] == $responseReference)
                                    { // important
                                        $product = $product = $cartItem['data'];
                                        $this->calculation->WcProductsApiData[$responseReference]['wc_title'] = $product->post->post_title; // setting up nexus WC items and DC items
                                        $cart->cart_contents[$cart_item_key]['line_tax'] 		  += $responseItemTax;
                                        $cart->cart_contents[$cart_item_key]['line_subtotal_tax'] += $responseItemTax;
                                    }
                                }
                            }
                        }
                    }
                }
                catch (Exception $e)
                {
                }
                // important
                $cart->tax_total += $totalDcTaxes;
                $cart->total     += $totalDcTaxes;
            }
            else
            {   // unsetting all 'Import Duty & Taxes' taxes
                $ratesToUnset = $wpdb->get_results(
                    "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates
                            WHERE tax_rate_name = '" . $this->taxName . "'
                            ORDER BY tax_rate_order	");
                foreach ($ratesToUnset as $rate)
                {
                    unset ($cart->taxes[$rate->tax_rate_id]);
                    unset ($cart->shipping_taxes[$rate->tax_rate_id]);
                }
            }
        }

        public function dutycalculator_woocommerce_calc_line_taxes_ajax_request()
        {
            global $woocommerce;
            if ( version_compare( $woocommerce->version, '2.0.20', '<' ) && null !== $woocommerce->version )
            {
                wp_register_script('dc_woo_order_calc_taxes', $this->plugin_url() . '/js/order-section_till_wc_209.js', array('jquery'), $this->version);
                wp_enqueue_script('dc_woo_order_calc_taxes', $this->plugin_url() . '/js/order-section_till_wc_209.js', array('jquery'), $this->version);
            }
            elseif (version_compare( $woocommerce->version, '2.3.10', '<' ) && null !== $woocommerce->version)
            {
                wp_register_script('dc_woo_order_calc_taxes', $this->plugin_url() . '/js/order-section.js', array('jquery'), $this->version);
                wp_enqueue_script('dc_woo_order_calc_taxes', $this->plugin_url() . '/js/order-section.js', array('jquery'), $this->version);
            }
        }

        public function dc_woo_calculation_response_to_order_meta($orderId, $posted = false)
        {
            update_post_meta($orderId, '_dc_calculation_response', (string)$this->calculation->rawAnswer);
        }

        public function dc_woo_order_section_add_link_to_calculation_after_shipping_address($order)
        {
            try
            {
                $rawXml = get_post_meta($order->id, '_dc_calculation_response', true);
                if (stripos($rawXml, '<?xml') === false)
                {
                    throw new Exception($rawXml);
                }
                $calcAnswer = new SimpleXMLElement($rawXml);
                $calcAnswerAttributes = $calcAnswer->attributes();
                $isCalculationFailed = ($calcAnswer->getName() == 'error');
                if (!$isCalculationFailed)
                {
                    echo '<p id="link_to_calculation"><a target="_blank" href="' . $this->api->dutyCalculatorApiHost . '/' . $this->api->dutyCalculatorSavedCalculationUrl . $calcAnswerAttributes['id'].'/">Import duty & tax calculation</a></p>';
                }
                else
                {
                    echo '<p id="link_to_calculation" style="color:#FF0000">Unable to calculate import duty & taxes!</br>' . (string)$calcAnswer->message . ' (Error code: ' .(string)$calcAnswer->code. ')</p>';
                }
            }
            catch (Exception $e)
            {}
        }

        public function save_dc_calculation_for_order(WC_Order $order)
        {
            try
            {
                $rawXml = get_post_meta( $order->id, '_dc_calculation_response', true );
                if (stripos($rawXml, '<?xml') === false)
                {
                    throw new Exception($rawXml);
                }
                $calcAnswer = new SimpleXMLElement($rawXml);
                $calcAnswerAttributes = $calcAnswer->attributes();
                $params = array('calculation_id' => $calcAnswerAttributes['id'], 'order_id' => $order->id,
                    'order_type' => 'order',
                    'shipment_id' => $order->shipping_method);
                return $this->api->send_request_and_get_response($this->api->actionStoreCalculation, $params);
            }
            catch (Exception $e)
            {
                return false;
            }
        }

        public function add_hs_meta_to_order_items($orderId, $checkoutPosted)
        {
            $order = new WC_Order($orderId);
            $params = array();
            $params['to'] = $order->shipping_country;
            if ($order->shipping_state)
            {
                $params['province'] = $order->shipping_state;
            }
            $params['classify_by'] = 'cat desc';
            $params['detailed_result'] = 1;
            $params['cat'] = array();
            $params['desc'] = array();
            $idx = 0;
            foreach ($this->calculation->WcProductsApiData as $itemData)
            {
                $apiItem = $itemData['api_response_node']; /** @var $apiItem SimpleXMLElement */
                if (!$apiItem)
                {
                    continue;
                }
                $itemAttributes = $apiItem->attributes();
                $params['cat'][$idx] = (string)$itemAttributes['id'];
                $params['desc'][$idx] = $itemData['wc_title'];
                $idx++;
            }
            $dcApi = $this->api->send_request_and_get_response($this->api->actionGetHsCode, $params);
            try
            {
                $rawXml = $dcApi->response;
                $this->store_api_request_to_order($order);
                if (stripos($rawXml, '<?xml') === false)
                {
                    throw new Exception($rawXml);
                }
                $answer = new SimpleXMLElement($rawXml);
                $rates = $answer->xpath('classification');
                foreach ($rates as $rate)
                { /** @var  $rate SimpleXMLElement */
                    $catDesc = current($rate->xpath('duty-category-description'));
                    $itemAttr = $catDesc->item->attributes();
                    $responseItemId = (string)$itemAttr['id'];
                    foreach ($this->calculation->WcProductsApiData as $wcItemId => $itemData)
                    {
                        $apiItem = $itemData['api_response_node']; /** @var $apiItem SimpleXMLElement */
                        $itemAttributes = $apiItem->attributes();
                        if ((string)$itemAttributes['id'] == $responseItemId)
                        {
                            $this->calculation->WcProductsApiData[$wcItemId]['hs_code'] = (string)current($rate->xpath('hs-code'));
                        }
                    }
                }
                // adding meta to order item
                foreach ($order->get_items() as $orderItemId => $orderItem)
                {
                    foreach ($this->calculation->WcProductsApiData as $productId => $productData)
                    {
                        $hsCode = $productData['hs_code'];
                        if ($orderItem['product_id'] == $productId)
                        {
                            woocommerce_add_order_item_meta($orderItemId, 'HS code destination country', $hsCode);
                        }
                    }
                }
                $this->save_dc_calculation_for_order($order);
                $this->store_api_request_to_order($order);
            }
            catch (Exception $ex)
            {}
        }

        public function woocommerce_dutycalculator_show_widget()
        {
            global $post;
            /** @var  $woocommerce Woocommerce */
            ?>
            <style>
                .woocommerce_options_panel input {
                    float: left;
                    width: 100%;
            </style>
            <table>
                <tr>
                    <td style="padding-left: 10px">
                        <input type="hidden" name="dc_duty_category_id" id="dc_duty_category_id" value="<?php echo current(get_post_meta($post->ID,'_dc_duty_category_id'))?>"/>
                        <input type="hidden" name="dc_your_product_description" id="dc_your_product_description" value="<?php echo current(get_post_meta($post->ID,'_dc_your_product_description'))?current(get_post_meta($post->ID,'_dc_your_product_description')):$post->post_title;?>"/>
                        <input type="hidden" name="dc_classification_request_id" id="dc_classification_request_id" value="<?php echo current(get_post_meta($post->ID,'_dc_classification_request_id'))?>"/>
                        <script>
                            var dc$ = jQuery.noConflict();
                            function dc_LoadWidget(){
                                var apiKey='<?php echo $this->api->apiKey?>'
                                var dcIdInputField='dc_duty_category_id'
                                var dcDescInputField='dc_your_product_description'
                                var dcIdClassificationRequestInputField='dc_classification_request_id'
                                var widgetMountPointId='dc_UniversalWidget'
                                var host=(("https:" == document.location.protocol) ? "<?php echo $this->api->dutyCalculatorApiHost ?>/" : "<?php echo $this->api->dutyCalculatorApiHost ?>/")
                                var url = host+"widget_universal_classify/"+apiKey+"/get-widget/?dc_id_input="+dcIdInputField+"&dc_desc_input="+dcDescInputField+"&dc_classification_request_id_input="+dcIdClassificationRequestInputField+"&callback=?"
                                dc$.getJSON(url, null, function(response) {
                                    dc$('#'+widgetMountPointId).html(response.html)
                                });
                            }
                            dc$(document).ready(function(){
                                dc_LoadWidget()
                            })
                        </script>
                        <div id="dc_UniversalWidget" style="text-align:left">Loading universal classification widget...</div>
                    </td>
                </tr>
            </table>
        <?php
        }

        public function add_dc_post_meta($product_id)
        {
            /* if ( get_post_type( $post ) == "product" ) { update_post_meta... }
             or like this add_action( 'save_post_product' ) */

            update_post_meta( $product_id, '_dc_duty_category_id', $_POST['dc_duty_category_id'] );
            update_post_meta( $product_id, '_dc_your_product_description', $_POST['dc_your_product_description'] );
            update_post_meta( $product_id, '_dc_classification_request_id', $_POST['dc_classification_request_id'] );
        }

        /**
         * @param WC_Product $product
         * @return stdClass
         */
        public function get_dc_tax_by_class($taxClass)
        { // not includes DC countries
            global $wpdb;
            $rates = $wpdb->get_results(
                "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates
                            WHERE tax_rate_name = '" . $this->taxName . "'
                            AND tax_rate_class = '" . $taxClass . "'
                            ORDER BY tax_rate_order	");
            return array_shift(array_values($rates));
        }

        public function is_dc_settings_country($country)
        {
            if (get_option('dc_woo_allowed_countries') == 'all')
            {
                return true;
            }
            if (in_array($country, get_option('dc_woo_specific_allowed_countries')))
            {
                return true;
            }
            else
            {
                return false;
            }
        }

        public function dc_woo_plugin_action_links( $links, $file )
        {
            if ( $file == plugin_basename( dirname(__FILE__).'/woocommerce-dutycalculator-charge.php' ) ) {
                $links[] = '<a href="' . admin_url( 'admin.php?page=' . $this->configPageName ) . '">'.__( 'Settings' ).'</a>';
                $links[] = '<a href="' . $this->api->dutyCalculatorApiHost . '">'.__( 'Docs' ).'</a>';
                $links[] = '<a href="' . $this->api->dutyCalculatorApiHost . '">'.__( 'Premium Support' ).'</a>';
            }
            return $links;
        }

        public function init()
        {
            $this->api = new WooCommerceDutyCalculatorAPI();
        }

        function includes()
        {
            if (is_admin())
            {
                $this->admin_includes();
            }
            include_once('classes/WooCommerceDutycalculatorAPI.php');
            include_once('classes/WooCommerceDutyCalculatorApiCalculation.php');
        }

        public function admin_includes()
        {
            if (!in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) )
            {
                add_action( 'admin_menu', 'dc_validate_plugin_activity_on_load' );
            }
            else
            {
                include_once 'admin/admin-init.php';
            }
        }

        /**
         * Take care of anything that needs woocommerce to be loaded.
         * For instance, if you need access to the $woocommerce global
         */
        public function woocommerce_loaded()
        {
//            $GLOBALS['woocommerce_dutycalculator_charge'] = new self;
        }

        /**
         * Take care of anything that needs all plugins to be loaded
         */
        public function plugins_loaded() {
            // ...
        }

        public function plugin_url()
        {
            if ($this->plugin_url) return $this->plugin_url;
            return $this->plugin_url = untrailingslashit(plugins_url('/', __FILE__));
        }

        public function plugin_path() {
            if ($this->plugin_path) return $this->plugin_path;

            return $this->plugin_path = untrailingslashit(plugin_dir_path(__FILE__));
        }
    }

    $GLOBALS['woocommerce_dutycalculator_charge'] = new WooCommerceDutyCalculatorCharge();
}

register_activation_hook(   __FILE__, 'dc_validate_plugin_activity' );

function dc_validate_plugin_activity_on_load()
{
    dc_deactivate_plugin('page_load');
}

function dc_validate_plugin_activity()
{
    if (!in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) )
    {
        dc_deactivate_plugin('plugin_activation');
    }
}

function dc_deactivate_plugin($when)
{
    $plugin = plugin_basename( __FILE__ );
    $plugin_data = get_plugin_data( __FILE__, false );

    deactivate_plugins( $plugin );
    switch ($when)
    {
        case 'plugin_activation':
            wp_die( "<strong>".$plugin_data['Name']."</strong> requires WooCommerce, and has been deactivated! Please install and activate <strong>WooCommerce Plugin</strong><br /><br />Back to the WordPress <a href='".get_admin_url(null, 'plugins.php')."'>Plugins page</a>." );
            break;
        case 'page_load':
            add_action('admin_notices', 'dc_deactivation_notice');
            break;
    }
}

function dc_deactivation_notice(){
    $plugin_data = get_plugin_data( __FILE__, false );
    echo '<div class="error">
	        <p><strong> ' .$plugin_data['Name'] . '</strong> requires WooCommerce, and has been deactivated! Please install and activate <strong>WooCommerce Plugin</strong>.
        	</div>';
}

if ($_GET['tab'] == 'tax' && $_GET['section'])
{
    add_action('woocommerce_settings_tabs', 'woo_admin_tax_rates_settings_page');
}

function woo_admin_tax_rates_settings_page()
{
    // after admin tax tab content is loaded - modifying page content
    add_filter('in_admin_footer', 'dc_woo_admin_settings_tax');
}

function dc_woo_admin_settings_tax()
{
    global $woocommerce_dutycalculator_charge, $woocommerce;
    /** @var $woocommerce_dutycalculator_charge WooCommerceDutyCalculatorCharge */
    ?>
    <script>

        function checkDCName(){
            jQuery('.wc_tax_rates').find('td.name > input').each(function() {

                if (jQuery(this).val() == '<?php echo $woocommerce_dutycalculator_charge->taxName ?>')
                {
                    jQuery(this).attr('readonly', 'readonly').attr('style','color:#949494');
                    jQuery(this).parents('tr').find('td.rate > input').attr('readonly', 'readonly').val('automatic').attr('style','color:#949494');
                    jQuery(this).parents('tr').find('td.country > input').attr('readonly', 'readonly');
                    jQuery(this).parents('tr').find('td.state > input').attr('readonly', 'readonly');
                    jQuery(this).parents('tr').find('td.postcode > input').attr('readonly', 'readonly');
                    jQuery(this).parents('tr').find('td.city > input').attr('readonly', 'readonly');
                    jQuery(this).parents('tr').find('td.compound > input').remove();
                    jQuery(this).parents('tr').find('td.compound').append('<span style="color:#949494">not applied</span>');
                    jQuery(this).parents('tr').find('td.apply_to_shipping > input').attr('onclick', 'return false').attr('checked', 'checked');
                }
            });
        }

        checkDCName();

        function insertDCAction(){
            var $tbody_1 = jQuery('.wc_tax_rates').find('tfoot').find('tr').find('th');
            var codeDC = '<a href="#" class="button plus insert_dc"><?php _e( 'Insert DutyCalculator row', 'woocommerce' ); ?></a>';
            $tbody_1.append(codeDC);
            // to left
//                $tbody_1.prepend(codeDC);
        }

        insertDCAction();

        jQuery('.insert_dc').click(function() {
            var $tbody = jQuery('.wc_tax_rates').find('tbody');
            var size = $tbody.find('tr').size();
            var itemSymbol = '][';

            <?php if (version_compare( $woocommerce->version, '2.0.20', '<' ) && null !== $woocommerce->version) { ?>
                itemSymbol = '][';
            <?php } elseif (version_compare( $woocommerce->version, '2.3.10', '>' ) && null !== $woocommerce->version) { ?>
                itemSymbol = '-';
            <?php }  ?>


            var code = '<tr class="new">\
                            <td class="sort">&nbsp;</td>\
                            <td class="country" width="8%">\
                                <input type="text" placeholder="*" readonly="readonly" name="tax_rate_country[new' + itemSymbol + size + ']" />\
                            </td>\
                            <td class="state" width="8%">\
                                <input type="text" placeholder="*" readonly="readonly" name="tax_rate_state[new' + itemSymbol + size + ']" />\
                            </td>\
                            <td class="postcode">\
                                <input type="text" placeholder="*" readonly="readonly" name="tax_rate_postcode[new' + itemSymbol + size + ']" />\
                            </td>\
                            <td class="city">\
                                <input type="text" placeholder="*" readonly="readonly" name="tax_rate_city[new' + itemSymbol + size + ']" />\
                            </td>\
                            <td class="rate" width="8%">\
                                <input type="number" style="color:#949494" step="any" value="automatic" readonly="readonly" name="tax_rate[new' + itemSymbol + size + ']" />\
                            </td>\
                            <td class="name" width="8%">\
                                <input type="text" style="color:#949494" value="<?php echo $woocommerce_dutycalculator_charge->taxName ?>" readonly="readonly" name="tax_rate_name[new' + itemSymbol + size + ']" />\
                            </td>\
                            <td class="priority" width="8%">\
                                <input type="number" step="1" min="1" value="1" name="tax_rate_priority[new' + itemSymbol + size + ']" />\
                            </td>\
                            <td class="compound" width="8%">\
                                <span>not applied</span>\
                            </td>\
                            <td class="apply_to_shipping" width="8%">\
                                <input type="checkbox" class="checkbox" checked="checked" onclick="return false" name="tax_rate_shipping[new' + itemSymbol + size + ']" checked="checked" />\
                            </td>\
                        </tr>';


            if ( $tbody.find('tr.current').size() > 0 ) {
                $tbody.find('tr.current').after( code );
            } else {
                $tbody.append( code );
            }

            return false;
        });        </script>

<?php

}