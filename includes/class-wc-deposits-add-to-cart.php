<?php
/*Copyright: Â© 2017 Webtomizer.
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

namespace Webtomizer\WCDP;


if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Class WC_Deposits_Add_To_Cart
 */
class WC_Deposits_Add_To_Cart
{


    private $appointment_cost = null;


    /**
     * WC_Deposits_Add_To_Cart constructor.
     * @param $wc_deposits
     */
    public function __construct()
    {
        // Add the required styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'), 20);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_inline_styles'), 20);
        add_filter('woocommerce_bookings_booking_cost_string', array($this, 'calculate_bookings_cost'));

        //appointments plugin
        add_filter('woocommerce_appointments_appointment_cost_html', array($this, 'calculate_appointment_cost_html'));
        add_filter('appointment_form_calculated_appointment_cost', array($this, 'get_appointment_cost'), 100);
        // Hook the add to cart form

//        add_action('woocommerce_single_variation', array($this, 'before_add_to_cart_button'), 999);
        add_action('woocommerce_before_add_to_cart_button', array($this, 'before_add_to_cart_button'), 999);
        add_filter('woocommerce_add_cart_item_data', array($this, 'add_cart_item_data'), 10, 3);

        //update html container via ajax listener
        add_action('wp_ajax_wc_deposits_update_deposit_container', array($this, 'ajax_update_deposit_container'));
        add_action('wp_ajax_nopriv_wc_deposits_update_deposit_container', array($this, 'ajax_update_deposit_container'));


    }

    function ajax_update_deposit_container()
    {
        $price = isset($_POST['price']) ? $_POST['price'] : false;
        $product_id = isset($_POST['product_id']) ? $_POST['product_id'] : false;
        if ($product_id) {
            $deposit_slider_html = $this->get_deposit_container($product_id, $price);
            wp_send_json_success($deposit_slider_html);

        } else {
            wp_send_json_error();
        }
        wp_die();
    }


    /**
     * @brief Load the deposit-switch logic
     *
     * @return void
     */
    public function enqueue_scripts()
    {
        wp_enqueue_script('wc-deposits-add-to-cart', WC_DEPOSITS_PLUGIN_URL . '/assets/js/add-to-cart.js');

        $message_deposit = get_option('wc_deposits_message_deposit');
        $message_full_amount = get_option('wc_deposits_message_full_amount');

        $message_deposit = stripslashes($message_deposit);
        $message_full_amount = stripslashes($message_full_amount);

        $script_args = array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'message' => array(
                'deposit' => __($message_deposit, 'woocommerce-deposits'),
                'full' => __($message_full_amount, 'woocommerce-deposits')
            )
        );

        wp_localize_script('wc-deposits-add-to-cart', 'wc_deposits_add_to_cart_options', $script_args);

    }


    /**
     * @brief Enqueues front-end styles
     *
     * @return void
     */
    public function enqueue_inline_styles()
    {
        // prepare inline styles
        $colors = get_option('wc_deposits_deposit_buttons_colors');
        $fallback_colors = wc_deposits_woocommerce_frontend_colours();

        $gstart = $colors['primary'] ? $colors['primary'] : $fallback_colors['primary'];
        $secondary = $colors['secondary'] ? $colors['secondary'] : $fallback_colors['secondary'];
        $highlight = $colors['highlight'] ? $colors['highlight'] : $fallback_colors['highlight'];
        $gend = wc_deposits_adjust_colour($gstart, 15);


        $style = "
            .wc-deposits-options-form input.input-radio:enabled ~ label {  color: {$secondary}; }
            .wc-deposits-options-form div a.wc-deposits-switcher {
              background-color: {$gstart};
              background: -moz-gradient(center top, {$gstart} 0%, {$gend} 100%);
              background: -moz-linear-gradient(center top, {$gstart} 0%, {$gend} 100%);
              background: -webkit-gradient(linear, left top, left bottom, from({$gstart}), to({$gend}));
              background: -webkit-linear-gradient({$gstart}, {$gend});
              background: -o-linear-gradient({$gstart}, {$gend});
              background: linear-gradient({$gstart}, {$gend});
            }
            .wc-deposits-options-form .amount { color: {$highlight}; }
            .wc-deposits-options-form .deposit-option { display: inline; }
          ";
        wp_add_inline_style('wc-deposits-frontend-styles', $style);
    }

    /**
     * get the updated booking cost and saves it to be used for html generation
     * @param $cost
     * @return mixed
     */
    public function get_appointment_cost($cost)
    {

        $this->appointment_cost = $cost;

        return $cost;

    }


    /**
     * @brief calculates new booking deposit on booking total change
     * @param $html
     * @return string
     */
    public function calculate_bookings_cost($html)
    {

        $posted = array();
        parse_str($_POST['form'], $posted);

        $product_id = $posted['add-to-cart'];
        $product = wc_get_product($product_id);
        if (version_compare(WC_BOOKINGS_VERSION, '1.15.0', '>=')) {

            $booking_data = wc_bookings_get_posted_data($posted, $product);
            $cost = \WC_Bookings_Cost_Calculation::calculate_booking_cost($booking_data, $product);
            if (is_wp_error($cost)) {
                return $html;
            }

            if ('incl' === get_option('woocommerce_tax_display_shop')) {
                if (function_exists('wc_get_price_excluding_tax')) {
                    $booking_cost = wc_get_price_including_tax($product, array('price' => $cost));
                } else {
                    $booking_cost = $product->get_price_including_tax(1, $cost);
                }
            } else {
                if (function_exists('wc_get_price_excluding_tax')) {
                    $booking_cost = wc_get_price_excluding_tax($product, array('price' => $cost));
                } else {
                    $booking_cost = $product->get_price_excluding_tax(1, $cost);
                }
            }

        } else {
            $booking_cost = $this->booking_cost;
        }

        ob_start();
        ?>
        <script type="text/javascript">
            var data = {
                price:<?php echo $cost ?>,
                product_id: <?php echo $product_id; ?> ,
                trigger: 'woocommerce_bookings'
            };
            jQuery(".wc-deposits-options-form").trigger('update_html', data);
        </script>
        <?php
        $script = ob_get_clean();
        return $html . $script;

    }

    /**
     * @param $html
     * @return string
     */
    public function calculate_appointment_cost_html($html)
    {


        $posted = array();

        parse_str($_POST['form'], $posted);

        $product_id = $posted['add-to-cart'];

        $appointment_cost = $this->appointment_cost;
        ob_start();
        ?>
        <script type="text/javascript">
            var data = {price:<?php echo $appointment_cost ?>, product_id: <?php echo $product_id; ?>  trigger
            :
            'woocommerce_bookings'
            }
            ;
            jQuery(".wc-deposits-options-form").trigger('update_html', data);
        </script>
        <?php
        $script = ob_get_clean();
        return $html . $script;

    }

    function get_deposit_container($product_id, $price = false, $args = array())
    {
        $basic_buttons = get_option('wc_deposits_use_basic_radio_buttons', true) === 'yes';


        ob_start(); ?>
        <div data-product_id="<?php echo $product_id; ?>"
             style="height:60px; width:100%;"
             class='webtomizer_wcdp_single_deposit_form <?php echo $basic_buttons ? 'basic-wc-deposits-options-form' : 'wc-deposits-options-form'; ?>'></div>
        <?php
        $html = ob_get_clean(); // always return empty div

        $default_args = array('show_add_to_cart_button' => false);
        $args = ($args) ? wp_parse_args($args, $default_args) : $default_args;

        $product = wc_get_product($product_id);
        if (!$product_id) return $html;
        //if product is variable , check variations override for product deposit
        if ($product->get_type() === 'variable') {

            $deposit_enabled = wc_deposits_is_product_deposit_enabled($product_id);
            if (!$deposit_enabled) {
                foreach ($product->get_children() as $variation_id) {

                    //if not enabled on global level , check in overrides


                    $variation = wc_get_product($variation_id);
                    if (!is_object($variation)) {
                        continue;

                    }


                    //check override
                    $override = $variation->get_meta('_wc_deposits_override_product_settings', true) === 'yes';

                    if ($override) {
                        $variation_deposit_enabled = wc_deposits_is_product_deposit_enabled($variation_id);

                        if ($variation_deposit_enabled) {
                            //at least 1 variation has deposit enabled
                            $deposit_enabled = true;
                            continue;
                        }
                    }
                }

            }

        } else {
            $deposit_enabled = wc_deposits_is_product_deposit_enabled($product_id);
        }

        if ($product && $deposit_enabled) {
            $price = $price ? $price : $product->get_price();

            $product_type = $product->get_type();
            $amount_type = wc_deposits_get_product_deposit_amount_type($product_id);
            $force_deposit = wc_deposits_is_product_deposit_forced($product_id);
            $deposit_amount = wc_deposits_get_product_deposit_amount($product_id);
            $deposits_enable_per_person = $product->get_meta('_wc_deposits_enable_per_person', true);

            $tax_display = get_option('wc_deposits_tax_display') === 'yes';
            $tax_handling = get_option('wc_deposits_taxes_handling');
            $woocommerce_prices_include_tax = get_option('woocommerce_prices_include_tax');
            $tax = 0;
            $has_payment_plans = false;

            if ($tax_display && $tax_handling === 'deposit') {
                $tax = wc_get_price_including_tax($product, array('price' => $price)) - wc_get_price_excluding_tax($product, array('price' => $price));
            } elseif ($tax_display && $tax_handling === 'split') {

                $tax_total = $tax = wc_get_price_including_tax($product, array('price' => $price)) - wc_get_price_excluding_tax($product, array('price' => $price));
                $deposit_percentage = $deposit_amount * 100 / ($product->get_price());

                if ($amount_type === 'percent') {
                    $deposit_percentage = $deposit_amount;
                }
                $tax = $tax_total * $deposit_percentage / 100;

            }

            if ($woocommerce_prices_include_tax === 'yes') {
                $tax_diff = wc_get_price_including_tax($product, array('price' => $price)) - wc_get_price_excluding_tax($product, array('price' => $price));
                $price -= $tax_diff;
            }
            $deposit_amount = floatval($deposit_amount);

            //amount
            if ($amount_type === 'fixed') {

                if ($woocommerce_prices_include_tax === 'yes') {
                    $amount = $deposit_amount;

                } else {
                    $amount = $deposit_amount + $tax;

                }

                $amount = round($amount, wc_get_price_decimals());

            } elseif ($amount_type === 'percent') {
                //percentage price calculation

                if ($product->get_type() === 'variable' || $product->get_type() === 'composite' || $product->get_type() === 'booking' && !is_ajax()) {
                    $amount = $deposit_amount;
                } elseif ($product->get_type() === 'subscription' && class_exists('WC_Subscriptions_Product')) {
                    $total_signup_fee = \WC_Subscriptions_Product::get_sign_up_fee($product);
                    $amount = $total_signup_fee * ($deposit_amount / 100.0);
                } else {

                    $amount = $price * ($deposit_amount / 100.0);
                    if ($woocommerce_prices_include_tax === 'yes') {
                        $amount += $tax;
                    }
                }


                $amount = round($amount, wc_get_price_decimals());

            } else {


                //payment plan
                $available_plans = $product->get_meta('_wc_deposits_payment_plans');
                if (empty($available_plans)) return '';

                $has_payment_plans = true;
                $payment_plans = array();
                foreach ($available_plans as $plan_id) {
                    $available_plan = get_term_by('id', absint($plan_id), WC_DEPOSITS_PAYMENT_PLAN_TAXONOMY);
                    if ($available_plan) {

                        //we need to calculate total cost in case it is more than 100%
                        $total_percentage = 0.0;


                        //get deposit percentage from meta
                        $deposit_percentage = get_term_meta($plan_id, 'deposit_percentage', true);
                        $total_percentage += $deposit_percentage;

                        //calculate deposit amount for the plan


                        //details
                        $payment_details = get_term_meta($plan_id, 'payment_details', true);
                        $payment_details = json_decode($payment_details, true);

                        foreach ($payment_details['payment-plan'] as $payment_line) {
                            $total_percentage += $payment_line['percentage'];

                        }

                        if (!is_array($payment_details) || !is_array($payment_details['payment-plan']) || empty($payment_details['payment-plan'])) {
                            return '';
                        }

                        $base_price = wc_get_price_excluding_tax($product, array('price' => $price));
                        // prepare display of payment plans
                        $deposit_amount = $base_price / 100 * $deposit_percentage;

                        foreach ($payment_details['payment-plan'] as $key => $payment_line) {

                            $payment_details['payment-plan'][$key]['line_amount'] = $base_price / 100 * $payment_line['percentage'];
                        }

                        $plan_total = wc_get_price_excluding_tax($product, array('price' => $price)) / 100 * $total_percentage;

                        if ($tax_display) {
                            $tax = wc_get_price_including_tax($product) - wc_get_price_excluding_tax($product);
                            if ($tax_handling === 'deposit') {

                                $deposit_amount += $tax;

                            } elseif ($tax_handling === 'split') {

                                $deposit_tax = $tax / 100 * $deposit_percentage;
                                $deposit_amount += $deposit_tax;
                                foreach ($payment_details['payment-plan'] as $key => $payment_line) {

                                    $line_tax = $tax / 100 * $payment_line['percentage'];
                                    $payment_details['payment-plan'][$key]['line_amount'] += $line_tax;
                                }
                            }

                            //add the tax total to plan total;
                            $plan_total += wc_get_price_including_tax($product, array('price' => $plan_total)) - wc_get_price_excluding_tax($product, array('price' => $plan_total));

                        }


                        $payment_plans[$available_plan->term_id] = array(
                            'name' => $available_plan->name,
                            'deposit_percentage' => $deposit_percentage,
                            'plan_total' => $plan_total,
                            'deposit_amount' => $deposit_amount,
                            'details' => $payment_details

                        );


                    }

                }

            }

            if (apply_filters('wc_deposits_product_disable_if_deposit_higher_than_price', true) && $amount_type !== 'payment_plan' && $product_type !== 'booking' && $product_type !== 'variable' && $amount > $product->get_price()) {
                //debug information
                return $html;
            }

            //suffix
            if ($amount_type === 'fixed') {

                if ($product->get_type() === 'booking' && $product->has_persons() && $deposits_enable_per_person === 'yes') {
                    $suffix = __('per person', 'woocommerce-deposits');
                } elseif ($product_type === 'booking') {
                    $suffix = __('per booking', 'woocommerce-deposits');
                } elseif (!$product->is_sold_individually()) {
                    $suffix = __('per item', 'woocommerce-deposits');
                } else {
                    $suffix = '';
                }

            } else {

                if (!is_ajax() && $product->get_type() === 'booking' || $product->get_type() === 'composite') {
                    $amount = '<span class=\'amount\'>' . round($deposit_amount, wc_get_price_decimals()) . '%' . '</span>';

                }

                if (!$product->is_sold_individually()) {
                    $suffix = __('per item', 'woocommerce-deposits');
                } else {
                    $suffix = '';
                }
            }

            $default_checked = get_option('wc_deposits_default_option', 'deposit');
            $deposit_text = get_option('wc_deposits_button_deposit');
            $full_text = get_option('wc_deposits_button_full_amount');
            $deposit_option_text = get_option('wc_deposits_deposit_option_text');

            if ($deposit_text === false) {

                $deposit_text = __('Pay Deposit', 'woocommerce-deposits');

            }
            if ($full_text === false) {
                $full_text = __('Full Amount', 'woocommerce-deposits');

            }

            if ($deposit_option_text === false) {
                $deposit_option_text = __('Deposit Option', 'woocommerce-deposits');
            }

            $deposit_text = stripslashes($deposit_text);
            $full_text = stripslashes($full_text);
            $deposit_option_text = stripslashes($deposit_option_text);

            $local_args = array(
                'deposit_info' => array(
                    //raw amount before calculations
                    'type' => $amount_type,
                    'amount' => $deposit_amount,
                ),
                'product' => $product,
                'suffix' => $suffix,
                'force_deposit' => $force_deposit ? 'yes' : 'no',
                'basic_buttons' => $basic_buttons,
                'deposit_text' => $deposit_text,
                'full_text' => $full_text,
                'deposit_option_text' => $deposit_option_text,
                'default_checked' => $default_checked,
                'has_payment_plans' => false
            );
            if ($has_payment_plans) {
                $local_args['has_payment_plans'] = $has_payment_plans;
                $local_args['payment_plans'] = $payment_plans;
                $local_args['deposit_amount'] = '';
            } else {
                $local_args['deposit_amount'] = $amount;
            }
            $args = ($args) ? wp_parse_args($args, $local_args) : $local_args;
            $args = apply_filters('wc_deposits_product_slider_args', $args, $product_id);
            ob_start();
            wc_get_template('single-product/wc-deposits-product-slider.php', $args, '', WC_DEPOSITS_TEMPLATE_PATH);
            $html = ob_get_clean();
        }
        return $html;
    }

    /**
     * @brief deposit calculation and display
     */
    public function before_add_to_cart_button()
    {
        //deposit already queued
//        if (is_product() && did_action('wc_deposits_enqueue_product_scripts')) return;
        //user restriction
        if (is_user_logged_in()) {

            $disabled_user_roles = get_option('wc_deposits_disable_deposit_for_user_roles', array());
            if (!empty($disabled_user_roles)) {

                foreach ($disabled_user_roles as $disabled_user_role) {

                    if (wc_current_user_has_role($disabled_user_role)) return;
                }
            }
        } else {
            $allow_deposit_for_guests = get_option('wc_deposits_restrict_deposits_for_logged_in_users_only', 'no');

            if ($allow_deposit_for_guests !== 'no') return;
        }

        global $product;

        $product_id = $product->get_id();
        echo $this->get_deposit_container($product_id);

    }


    /**
     * @param $cart_item_meta
     * @param $product_id
     * @param $variation_id
     * @return mixed
     */
    public
    function add_cart_item_data($cart_item_meta, $product_id, $variation_id)
    {


        //user restriction
        if (is_user_logged_in()) {

            $disabled_user_roles = get_option('wc_deposits_disable_deposit_for_user_roles', array());
            if (!empty($disabled_user_roles)) {

                foreach ($disabled_user_roles as $disabled_user_role) {
                    if (wc_current_user_has_role($disabled_user_role)) return $cart_item_meta;
                }

            }
        } else {
            $allow_deposit_for_guests = get_option('wc_deposits_restrict_deposits_for_logged_in_users_only', 'no');

            if ($allow_deposit_for_guests !== 'no') return $cart_item_meta;
        }


        $product = wc_get_product($product_id);
        if ($product->get_type() === 'variable') {

            $deposit_enabled = wc_deposits_is_product_deposit_enabled($variation_id);
            $force_deposit = wc_deposits_is_product_deposit_forced($variation_id);
        } else {
            $deposit_enabled = wc_deposits_is_product_deposit_enabled($product_id);
            $force_deposit = wc_deposits_is_product_deposit_forced($product_id);
        }


        if ($deposit_enabled) {
            $default = get_option('wc_deposits_default_option');
            if (!isset($_POST[$product_id . '-deposit-radio'])) {
                $_POST[$product_id . '-deposit-radio'] = $default ? $default : 'deposit';
            }

            if (isset($variation_id) && isset($_POST[$variation_id . '-deposit-radio'])) {
                $_POST[$product_id . '-deposit-radio'] = $_POST[$variation_id . '-deposit-radio'];
            }

            $cart_item_meta['deposit'] = array(

                'enable' => $force_deposit ? 'yes' : ($_POST[$product_id . '-deposit-radio'] === 'full' ? 'no' : 'yes')
            );


            if ($cart_item_meta['deposit']['enable'] === 'yes') {
                if ((isset($_POST[$product_id . '-selected-plan']))) {
                    //payment plan selected
                    $cart_item_meta['deposit']['payment_plan'] = $_POST[$product_id . '-selected-plan'];

                } elseif (wc_deposits_get_product_deposit_amount_type($product_id) === 'payment_plan') {
                    // default selection is deposit  and deposit type is payment plan, so pick the first payment plan
                    $available_plans = $product->get_meta('_wc_deposits_payment_plans');
                    if (is_array($available_plans)) {
                        $cart_item_meta['deposit']['payment_plan'] = $available_plans[0];
                        $cart_item_meta['deposit']['original_line_subtotal'] = $cart_item_meta['line_subtotal'];

                    }
                }
            }


        }

        return $cart_item_meta;
    }
}
