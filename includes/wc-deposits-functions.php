<?php
/*Copyright: © 2017 Webtomizer.
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * @return mixed
 */
function wc_deposits_deposit_breakdown_tooltip()
{

    $display_tooltip = get_option('wc_deposits_breakdown_cart_tooltip') === 'yes';


    $tooltip_html = '';

    if ($display_tooltip && isset(WC()->cart->deposit_info['deposit_breakdown']) && is_array(WC()->cart->deposit_info['deposit_breakdown'])) {

        $labels = apply_filters('wc_deposits_deposit_breakdown_tooltip_labels', $labels = array(
            'cart_items' => __('Cart items', 'woocommerce-deposits'),
            'fees' => __('Fees', 'woocommerce-deposits'),
            'taxes' => __('Tax', 'woocommerce-deposits'),
            'shipping' => __('Shipping', 'woocommerce-deposits'),
            'shipping_taxes' => __('Shipping Tax', 'woocommerce-deposits'),

        ));

        $deposit_breakdown = WC()->cart->deposit_info['deposit_breakdown'];
        $tip_information = '<ul>';
        foreach ($deposit_breakdown as $component_key => $component) {

            if ($component === 0) {
                continue;
            }
            switch ($component_key) {
                case 'cart_items' :
                    $tip_information .= '<li>' . $labels['cart_items'] . ' : ' . wc_price($component) . '</li>';

                    break;
                case 'fees' :
                    $tip_information .= '<li>' . $labels['fees'] . ' : ' . wc_price($component) . '</li>';
                    break;
                case 'taxes' :
                    $tip_information .= '<li>' . $labels['taxes'] . ' : ' . wc_price($component) . '</li>';

                    break;
                case 'shipping' :
                    $tip_information .= '<li>' . $labels['shipping'] . ' : ' . wc_price($component) . '</li>';

                    break;
                case 'shipping_taxes' :
                    $tip_information .= '<li>' . $labels['shipping_taxes'] . ' : ' . wc_price($component) . '</li>';

                    break;
                default :
                    break;
            }
        }

        $tip_information .= '</ul>';

        $tooltip_html = '<span id="deposit-help-tip" data-tip="' . esc_attr($tip_information) . '">&#63;</span>';
    }

    return apply_filters('woocommerce_deposits_tooltip_html', $tooltip_html);
}

/**
 * Check if WooCommerce is active
 */
function wc_deposits_woocommerce_is_active()
{
    if (!function_exists('is_plugin_active_for_network'))
        require_once(ABSPATH . '/wp-admin/includes/plugin.php');
    // Check if WooCommerce is active
    if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
        return is_plugin_active_for_network('woocommerce/woocommerce.php');
    }
    return true;
}

/** http://jaspreetchahal.org/how-to-lighten-or-darken-hex-or-rgb-color-in-php-and-javascript/
 * @param $color_code
 * @param int $percentage_adjuster
 * @return array|string
 * @author Jaspreet Chahal
 */
function wc_deposits_adjust_colour($color_code, $percentage_adjuster = 0)
{
    $percentage_adjuster = round($percentage_adjuster / 100, 2);

    if (is_array($color_code)) {
        $r = $color_code["r"] - (round($color_code["r"]) * $percentage_adjuster);
        $g = $color_code["g"] - (round($color_code["g"]) * $percentage_adjuster);
        $b = $color_code["b"] - (round($color_code["b"]) * $percentage_adjuster);

        $adjust_color = array("r" => round(max(0, min(255, $r))),
            "g" => round(max(0, min(255, $g))),
            "b" => round(max(0, min(255, $b))));
    } elseif (preg_match("/#/", $color_code)) {
        $hex = str_replace("#", "", $color_code);
        $r = (strlen($hex) == 3) ? hexdec(substr($hex, 0, 1) . substr($hex, 0, 1)) : hexdec(substr($hex, 0, 2));
        $g = (strlen($hex) == 3) ? hexdec(substr($hex, 1, 1) . substr($hex, 1, 1)) : hexdec(substr($hex, 2, 2));
        $b = (strlen($hex) == 3) ? hexdec(substr($hex, 2, 1) . substr($hex, 2, 1)) : hexdec(substr($hex, 4, 2));
        $r = round($r - ($r * $percentage_adjuster));
        $g = round($g - ($g * $percentage_adjuster));
        $b = round($b - ($b * $percentage_adjuster));

        $adjust_color = "#" . str_pad(dechex(max(0, min(255, $r))), 2, "0", STR_PAD_LEFT)
            . str_pad(dechex(max(0, min(255, $g))), 2, "0", STR_PAD_LEFT)
            . str_pad(dechex(max(0, min(255, $b))), 2, "0", STR_PAD_LEFT);

    } else {
        $adjust_color = new WP_Error('', 'Invalid Color format');
    }


    return $adjust_color;
}

/**
 * @brief returns the frontend colours from the WooCommerce settings page, or the defaults.
 *
 * @return array
 */

function wc_deposits_woocommerce_frontend_colours()
{
    $colors = (array)get_option('woocommerce_colors');
    if (empty($colors['primary']))
        $colors['primary'] = '#ad74a2';
    if (empty($colors['secondary']))
        $colors['secondary'] = '#f7f6f7';
    if (empty($colors['highlight']))
        $colors['highlight'] = '#85ad74';
    if (empty($colors['content_bg']))
        $colors['content_bg'] = '#ffffff';
    return $colors;
}


/**
 * @return bool
 */
function wcdp_checkout_mode()
{

    return get_option('wc_deposits_checkout_mode_enabled') === 'yes';
}

/**
 * @param $product
 * @return float
 */
function wc_deposits_calculate_product_deposit($product)
{


    $deposit_enabled = wc_deposits_is_product_deposit_enabled($product->get_id());
    $product_type = $product->get_type();
    if ($deposit_enabled) {


        $deposit = wc_deposits_get_product_deposit_amount($product->get_id());
        $amount_type = wc_deposits_get_product_deposit_amount_type($product->get_id());


        $woocommerce_prices_include_tax = get_option('woocommerce_prices_include_tax');

        if ($woocommerce_prices_include_tax === 'yes') {

            $amount = wc_get_price_including_tax($product);

        } else {
            $amount = wc_get_price_excluding_tax($product);

        }

        switch ($product_type) {


            case 'subscription' :
                if (class_exists('WC_Subscriptions_Product')) {

                    $amount = \WC_Subscriptions_Product::get_sign_up_fee($product);
                    if ($amount_type === 'fixed') {
                    } else {
                        $deposit = $amount * ($deposit / 100.0);
                    }

                }
                break;
            case 'yith_bundle' :
                $amount = $product->price_per_item_tot;
                if ($amount_type === 'fixed') {
                } else {
                    $deposit = $amount * ($deposit / 100.0);
                }
                break;
            case 'variable' :

                if ($amount_type === 'fixed') {
                } else {
                    $deposit = $amount * ($deposit / 100.0);
                }
                break;

            default:


                if ($amount_type !== 'fixed') {

                    $deposit = $amount * ($deposit / 100.0);
                }

                break;
        }

        return floatval($deposit);
    }
}

/**
 * @brief checks if deposit is enabled for product
 * @param $product_id
 * @return mixed
 */
function wc_deposits_is_product_deposit_enabled($product_id)
{
    $enabled = false;
    $product = wc_get_product($product_id);
    if ($product) {

        // if it is a variation , check variation directly
        if ($product->get_type() === 'variation') {


            $override = $product->get_meta('_wc_deposits_override_product_settings', true) === 'yes';

            if ($override) {


                $enabled = $product->get_meta('_wc_deposits_enable_deposit', true) === 'yes';


            } else {

                $parent_id = $product->get_parent_id();
                $parent = wc_get_product($parent_id);
                if ($parent) {

                    $enabled = $parent->get_meta('_wc_deposits_enable_deposit', true) === 'yes';
                }
            }

        } else {
            $enabled = $product->get_meta('_wc_deposits_enable_deposit', true) === 'yes';
        }
    }


    return apply_filters('wc_deposits_product_enable_deposit', $enabled, $product_id);

}

function wc_deposits_is_product_deposit_forced($product_id)
{
    $forced = false;
    $product = wc_get_product($product_id);

    if ($product) {

        if ($product->get_type() === 'variation') {


            $override = $product->get_meta('_wc_deposits_override_product_settings', true) === 'yes';

            if ($override) {

                $forced = $product->get_meta('_wc_deposits_force_deposit', true) === 'yes';

            } else {

                $parent_id = $product->get_parent_id();
                $parent = wc_get_product($parent_id);
                if ($parent) {
                    $forced = $parent->get_meta('_wc_deposits_force_deposit', true) === 'yes';
                }
            }

        } else {
            $forced = $product->get_meta('_wc_deposits_force_deposit', true) === 'yes';
        }
    }


    return apply_filters('wc_deposits_product_force_deposit', $forced, $product_id);

}

function wc_deposits_get_product_deposit_amount($product_id)
{

    $amount = false;
    $product = wc_get_product($product_id);

    if ($product) {

        if ($product->get_type() === 'variation') {


            $override = $product->get_meta('_wc_deposits_override_product_settings', true) === 'yes';

            if ($override) {

                $amount = $product->get_meta('_wc_deposits_deposit_amount', true);

            } else {

                $parent_id = $product->get_parent_id();
                $parent = wc_get_product($parent_id);

                if ($parent) {

                    $amount = $parent->get_meta('_wc_deposits_deposit_amount', true);
                }
            }

        } else {
            $amount = $product->get_meta('_wc_deposits_deposit_amount', true);
        }
    }


    return apply_filters('wc_deposits_product_deposit_amount', $amount, $product_id);

}

function wc_deposits_get_product_deposit_amount_type($product_id)
{

    $amount_type = false;
    $product = wc_get_product($product_id);

    if ($product) {

        if ($product->get_type() === 'variation') {


            $override = $product->get_meta('_wc_deposits_override_product_settings', true) === 'yes';

            if ($override) {

                $amount_type = $product->get_meta('_wc_deposits_amount_type', true);

            } else {

                $parent_id = $product->get_parent_id();
                $parent = wc_get_product($parent_id);
                if ($parent) {

                    if ($parent) {

                        $amount_type = $parent->get_meta('_wc_deposits_amount_type', true);
                    }
                }
            }

        } else {
            $amount_type = $product->get_meta('_wc_deposits_amount_type', true);
        }
    }


    return apply_filters('wc_deposits_product_deposit_amount_type', $amount_type, $product_id);
}


function wc_deposits_delete_current_schedule($order)
{

    $payment_schedule = $order->get_meta('_wc_deposits_payment_schedule', true);

    if (!is_array($payment_schedule) || empty($payment_schedule)) return;

    foreach ($payment_schedule as $payment) {
        wp_delete_post(absint($payment['id']), true);
    }

    $order->delete_meta_data('_wc_deposits_payment_schedule');
    $order->save();

}


function wc_deposits_create_payment_schedule($order,$sorted_schedule = array())
{

    /**   START BUILD PAYMENT SCHEDULE**/
    try {


        //create the payments
        $deposit_id = null;
        //fix wpml language
        $wpml_lang = $order->get_meta('wpml_language',true);
        foreach ($sorted_schedule as $partial_key => $payment) {

            $partial_payment = new WCDP_Payment();


            //migrate all fields from parent order


            $partial_payment->set_customer_id($order->get_user_id());

            $amount = $payment['total'];
            $name = __('Partial Payment for order %s', 'woocommerce-deposits');
            $partial_payment_name = apply_filters('wc_deposits_partial_payment_name', sprintf($name, $order->get_order_number()), $payment, $order->get_id());


            $item = new WC_Order_Item_Fee();


            $item->set_props(
                array(
                    'total' => $amount
                )
            );

            $item->set_name($partial_payment_name);
            $partial_payment->add_item($item);

            $is_vat_exempt = $order->get_meta('is_vat_exempt', true);

            $partial_payment->set_parent_id($order->get_id());
            $partial_payment->add_meta_data('is_vat_exempt', $is_vat_exempt);
            $partial_payment->add_meta_data('_wc_deposits_payment_type', $payment['type'], true);
            $partial_payment->set_currency($order->get_currency());
            $partial_payment->set_prices_include_tax($order->get_prices_include_tax());
            $partial_payment->set_customer_ip_address($order->get_customer_ip_address());
            $partial_payment->set_customer_user_agent($order->get_customer_user_agent());

            if ($order->get_status() === 'partially-paid' && $payment['type'] === 'deposit') {

                //we need to save to generate id first

                $partial_payment->set_status('completed');

            }

            if(!empty($wpml_lang)){
                $partial_payment->update_meta_data('wpml_language',$wpml_lang);
            }


            $partial_payment->set_total($amount);
            if(floatval($partial_payment->get_total()) == 0.0) $partial_payment->set_status('completed');
            $partial_payment->save();

            $sorted_schedule[$partial_key]['id'] = $partial_payment->get_id();

        }

        return $sorted_schedule;
    } catch (Exception $e) {
        var_dump(new WP_Error('error', $e->getMessage()));
    }

}

function wcdp_get_order_partial_payments($order_id, $args = array(), $object = true)
{
    $default_args = array(
        'post_parent' => $order_id,
        'post_type' => 'wcdp_payment',
        'numberposts' => -1,
        'post_status' => 'any'
    );

    $args = ($args) ? wp_parse_args($args, $default_args) : $default_args;

    $orders = array();

    $partial_payments = get_posts($args);

    foreach ( $partial_payments as $partial_payment) {
        $orders[] = ($object) ? wc_get_order($partial_payment->ID) : $partial_payment->ID;
    }
    return $orders;
}

add_action( 'woocommerce_after_dashboard_status_widget', 'wcdp_status_widget_partially_paid' );
function wcdp_status_widget_partially_paid () {
    if ( ! current_user_can( 'edit_shop_orders' ) ) {
        return;
    }
    $partially_paid_count    = 0;
    foreach ( wc_get_order_types( 'order-count' ) as $type ) {
        $counts            = (array) wp_count_posts( $type );
        $partially_paid_count    += isset( $counts['wc-partially-paid'] ) ? $counts['wc-partially-paid'] : 0;
    }
    ?>
    <li class="partially-paid-orders">
        <a href="<?php echo admin_url( 'edit.php?post_status=wc-partially-paid&post_type=shop_order' ); ?>">
            <?php
            printf(
                _n( '<strong>%s order</strong> partially paid', '<strong>%s orders</strong> partially paid', $partially_paid_count, 'woocommerce-deposits' ),
                $partially_paid_count
            );
            ?>
        </a>
    </li>
    <style>
        #woocommerce_dashboard_status .wc_status_list li.partially-paid-orders a::before {
            content: '\e011';
            color: #ffba00;
    </style>
    <?php
}

function wc_deposits_remove_order_deposit_data($order){

    $order->delete_meta_data('_wc_deposits_payment_schedule');
    $order->delete_meta_data('_wc_deposits_order_version');
    $order->delete_meta_data('_wc_deposits_order_has_deposit');
    $order->delete_meta_data('_wc_deposits_deposit_paid');
    $order->delete_meta_data('_wc_deposits_second_payment_paid');
    $order->delete_meta_data('_wc_deposits_deposit_amount');
    $order->delete_meta_data('_wc_deposits_second_payment');
    $order->delete_meta_data('_wc_deposits_deposit_breakdown');
    $order->delete_meta_data('_wc_deposits_deposit_payment_time');
    $order->delete_meta_data('_wc_deposits_second_payment_reminder_email_sent');
    $order->save();

}