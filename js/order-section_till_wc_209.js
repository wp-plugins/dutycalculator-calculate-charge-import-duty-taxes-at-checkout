jQuery( function($){
    $('button.calc_line_taxes').click(function(){
        var canStartAjax = true;
        $(document).ajaxStop(function () {
            if (canStartAjax)
            {
                // Block write panel
                // if woocommerce version higher than 2.1 -> use 'woocommerce_admin_meta_boxes' instead of 'woocommerce_writepanel_params'
                $('.woocommerce_order_items_wrapper').block({ message: null, overlayCSS: { background: '#fff url(' + woocommerce_writepanel_params.plugin_url + '/assets/images/ajax-loader.gif) no-repeat center', opacity: 0.6 } });

                var answer = true;

                if (answer) {

                    var $items = $('#order_items_list').find('tr.item, tr.fee');

                    var shipping_country = $('#_shipping_country').val();
                    var billing_country = $('#_billing_country').val();

                    if (shipping_country) {
                        var country = shipping_country;
                        var state = $('#_shipping_state').val();
                        var postcode = $('#_shipping_postcode').val();
                        var city = $('#_shipping_city').val();
                    } else if(billing_country) {
                        var country = billing_country;
                        var state = $('#_billing_state').val();
                        var postcode = $('#_billing_postcode').val();
                        var city = $('#_billing_city').val();
                    } else {
                        var country = woocommerce_writepanel_params.base_country;
                        var state = '';
                        var postcode = '';
                        var city = '';
                    }

                    // Get items and values
                    var calculate_items = {};

                    $items.each( function() {

                        var $row = $(this);

                        var item_id 		= $row.find('input.order_item_id').val();
                        var line_subtotal	= $row.find('input.line_subtotal').val();
                        var line_total		= $row.find('input.line_total').val();
                        var tax_class		= $row.find('select.tax_class').val();
                        var quantity		= $row.find('input.quantity').val();

                        calculate_items[ item_id ] = {};
                        calculate_items[ item_id ].line_subtotal = line_subtotal;
                        calculate_items[ item_id ].line_total = line_total;
                        calculate_items[ item_id ].tax_class = tax_class;
                        calculate_items[ item_id ].quantity = quantity;
                    } );

                    var data = {
                        action: 		'dutycalculator_woocommerce_calc_line_taxes',
                        order_id: 		woocommerce_writepanel_params.post_id,
                        items:			calculate_items,
                        shipping:		accounting.unformat( $('#_order_shipping').val() ),
                        country:		country,
                        state:			state,
                        postcode:		postcode,
                        city:			city,
                        security: 		woocommerce_writepanel_params.calc_totals_nonce
                    };

                    $.post( woocommerce_writepanel_params.ajax_url, data, function( response ) {

                        if ( response ) {

                            $items.each( function() {
                                var $row = $(this);
                                var item_id = $row.find('input.order_item_id').val();

                                if ( response['item_taxes'][ item_id ] ) {
                                    $row.find('input.line_tax').val( response['item_taxes'][ item_id ]['line_tax'] ).change();
                                    $row.find('input.line_subtotal_tax').val( response['item_taxes'][ item_id ]['line_subtotal_tax'] ).change();
                                }

                                if ( response['tax_row_html'] )
                                    $('#tax_rows').empty().append( response['tax_row_html'] );

                                if (response['link_to_calculation'])
                                {
                                    jQuery('#link_to_calculation').empty().append(response['link_to_calculation']);
                                }
                            } );

                            $('#_order_tax').val( response['item_tax'] ).change();
                            $('#_order_shipping_tax').val( response['shipping_tax'] ).change();

                        }

                        $('.woocommerce_order_items_wrapper').unblock();
                    });
                } else {
                    $('.woocommerce_order_items_wrapper').unblock();
                }
                canStartAjax = false; // no loop
                return false;
            }
        });
    });
});

function renameSalesTax()
{
    jQuery('#woocommerce-order-totals .totals_group').find('label')
        .each(function(){jQuery(this).html(function(i,t){return t.replace('Sales Tax','Duty & Taxes')})});
}
