<?php
/*Copyright: © 2017 Webtomizer.
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/
namespace Webtomizer\WCDP;


if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Class WC_Deposits_Checkout
 */
class WC_Deposits_Checkout
{

    public $wc_deposits;
    public $deposit_enabled;
    public $deposit_amount;
    public $second_payment;





    /**
     * WC_Deposits_Checkout constructor.
     * @param $wc_deposits
     */
    public function __construct(&$wc_deposits)
    {
        $this->wc_deposits = $wc_deposits;





        if (wcdp_checkout_mode()) {

            add_action('wc_deposits_enqueue_deposit_button_scripts', array($this, 'enqueue_scripts'), 20);
            add_action('woocommerce_checkout_update_order_review', array($this, 'update_order_review'), 10, 1);
            add_action('woocommerce_review_order_after_order_total', array($this, 'checkout_deposit_button'), 50);
        } else {
            add_action('woocommerce_checkout_create_order_line_item', array($this, 'checkout_create_order_line_item'), 10, 4);
        }


        add_action('woocommerce_checkout_update_order_meta', array($this, 'checkout_update_order_meta'), 10, 2);
        add_action('woocommerce_review_order_after_order_total', array($this, 'review_order_after_order_total'));
        // Hook the payments gateways filter to remove the ones we don't want
        add_filter('woocommerce_available_payment_gateways', array($this, 'available_payment_gateways'));

        add_action("wp_ajax_pay_deposit",array($this, 'pay_deposit'));
        add_action("wp_ajax_nopriv_pay_deposit", array($this, 'pay_deposit'));



       

     
      

    }

    /**
     *
     * @param $posted_data_string
     */
    public function update_order_review($posted_data_string)
    {


        parse_str($posted_data_string, $posted_data);
        if (!is_array(WC()->cart->deposit_info)) WC()->cart->deposit_info = array();

        if (isset($posted_data['deposit-radio']) && $posted_data['deposit-radio'] === 'deposit') {
            WC()->cart->deposit_info['deposit_enabled'] = true;
            WC()->session->set('deposit_enabled', true);

        } elseif (isset($posted_data['deposit-radio']) && $posted_data['deposit-radio'] === 'full') {
            WC()->cart->deposit_info['deposit_enabled'] = false;
            WC()->session->set('deposit_enabled', false);
        } else {
            $default = get_option('wc_deposits_default_option');
           
            WC()->cart->deposit_info['deposit_enabled'] = $default === 'deposit' ? true : false;
            WC()->session->set('deposit_enabled', $default === 'deposit' ? true : false);
        }
       
           
       




    }

       

    /**
     * @brief enqeueue scripts
     */
    public function enqueue_scripts()
    {

        wp_enqueue_script('wc-deposits-checkout', WC_DEPOSITS_PLUGIN_URL . '/assets/js/wc-deposits-checkout.js', array('jquery'), '', true);
        $message_deposit = __(get_option('wc_deposits_message_deposit'), 'woocommerce-deposits');
        $message_full_amount = __(get_option('wc_deposits_message_full_amount'), 'woocommerce-deposits');

        $message_deposit = stripslashes($message_deposit);
        $message_full_amount = stripslashes($message_full_amount);

        $script_args = array(
            'message' => array(
                'deposit' => __($message_deposit, 'woocommerce-deposits'),
                'full' => __($message_full_amount, 'woocommerce-deposits')
            )
        );
        wp_localize_script('wc-deposits-checkout', 'wc_deposits_checkout_options', $script_args);

        // prepare inline styles
        $colors = get_option('wc_deposits_deposit_buttons_colors');
        $fallback_colors = wc_deposits_woocommerce_frontend_colours();
        $gstart = $colors['primary'] ? $colors['primary'] : $fallback_colors['primary'];
        $secondary = $colors['secondary'] ? $colors['secondary'] : $fallback_colors['secondary'];
        $highlight = $colors['highlight'] ? $colors['highlight'] : $fallback_colors['highlight'];
        $gend = wc_deposits_adjust_colour($gstart, 15);


        $style = "@media only screen {
            #wc-deposits-options-form input.input-radio:enabled ~ label { color: {$secondary}; }
            #wc-deposits-options-form div a.wc-deposits-switcher {
              background-color: {$gstart};
              background: -moz-gradient(center top, {$gstart} 0%, {$gend} 100%);
              background: -moz-linear-gradient(center top, {$gstart} 0%, {$gend} 100%);
              background: -webkit-gradient(linear, left top, left bottom, from({$gstart}), to({$gend}));
              background: -webkit-linear-gradient({$gstart}, {$gend});
              background: -o-linear-gradient({$gstart}, {$gend});
              background: linear-gradient({$gstart}, {$gend});
            }
            #wc-deposits-options-form .amount { color: {$highlight}; }
            #wc-deposits-options-form .deposit-option { display: inline; }
          }";

        wp_enqueue_style('wc-deposits-frontend-styles-checkout-mode', WC_DEPOSITS_PLUGIN_URL . '/assets/css/checkout-mode.css');
        wp_add_inline_style('wc-deposits-frontend-styles-checkout-mode', $style);


    }

    /**
     * @brief shows Deposit slider in checkout mode
     */
    public function checkout_deposit_button()
    {


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

        if (isset(WC()->cart->deposit_info['deposit_enabled']) && WC()->cart->deposit_info['deposit_enabled'] !== true) {
            return;
        }


        $force_deposit = get_option('wc_deposits_checkout_mode_force_deposit');
        $deposit_amount = get_option('wc_deposits_checkout_mode_deposit_amount');

        $amount_type = get_option('wc_deposits_checkout_mode_deposit_amount_type');

        if ($amount_type === 'fixed' && $deposit_amount >= WC()->cart->total) {
            return;
        }

        $default_checked = get_option('wc_deposits_default_option', 'deposit');
        $basic_buttons = get_option('wc_deposits_use_basic_radio_buttons', true) === 'yes';
        $deposit_text = __(get_option('wc_deposits_button_deposit'), 'woocommerce-deposits');
        $full_text = __(get_option('wc_deposits_button_full_amount'), 'woocommerce-deposits');
        $deposit_option_text = __(get_option('wc_deposits_deposit_option_text'), 'woocommerce-deposits');

        $post_data = array();

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
        $selected_plan = '';
        $payment_plans = array();

        $amount = isset(WC()->cart->deposit_info ,WC()->cart->deposit_info['deposit_amount']) ? WC()->cart->deposit_info['deposit_amount'] : 0.0 ;



        
        $has_payment_plans = isset(WC()->cart->deposit_info, WC()->cart->deposit_info['has_payment_plans']) && WC()->cart->deposit_info['has_payment_plans'];



        if (is_ajax() && isset($_POST['post_data'])) {
            parse_str($_POST['post_data'], $post_data);
            if (isset($post_data['deposit-radio'])) {
                $default_checked = $post_data['deposit-radio'];
            }

        }
      if($has_payment_plans){

            $available_plans = get_option('wc_deposits_checkout_mode_payment_plans',array());

            if (is_ajax() && !empty($post_data)) {

                if(isset($post_data['wcdp-selected-plan']) && in_array($post_data['wcdp-selected-plan'],$available_plans)) {
                 $selected_plan = $post_data['wcdp-selected-plan'];
                }
            }


            foreach ($available_plans as $plan_id) {
                $available_plan = get_term_by('id', $plan_id, WC_DEPOSITS_PAYMENT_PLAN_TAXONOMY);

                if ($available_plan) {

                    //get plan details from meta
                    $deposit_percentage = get_term_meta($plan_id, 'deposit_percentage', true);


                    //calculate deposit amount for the plan
                   $deposit_amount = WC()->cart->get_subtotal()  /  100 * $deposit_percentage;





                    //details
                    $payment_details = get_term_meta($plan_id, 'payment_details', true);
                    $payment_details = json_decode($payment_details, true);

                    if (!is_array($payment_details) || !is_array($payment_details['payment-plan']) || empty($payment_details['payment-plan'])) {
                        return;
                    }
                    $payment_plans[$available_plan->term_id] = array(
                        'name' => $available_plan->name,
                        'amount' => $deposit_amount,
                        'details' => $payment_details
                    );

                }

            }
        }

        $args = array(
            'force_deposit' => $force_deposit,
            'deposit_amount' => $amount,
            'basic_buttons' => $basic_buttons,
            'deposit_text' => $deposit_text,
            'full_text' => $full_text,
            'deposit_option_text' => $deposit_option_text,
            'default_checked' => $default_checked,
            'has_payment_plan' => $has_payment_plans,
            'payment_plans' => $payment_plans,
            'selected_plan' => $selected_plan,
        );
        wc_get_template('wc-deposits-checkout-mode-slider.php', $args, '', WC_DEPOSITS_TEMPLATE_PATH);

    }

    /**
     * @brief adds deposit meta to order line item when created
     * @param $item
     * @param $cart_item_key
     * @param $values
     * @param $order
     */
    public function checkout_create_order_line_item($item, $cart_item_key, $values, $order)
    {

        if ($order->get_type() === 'wcdp_payment') return;

        $deposit_meta = isset($values['deposit']) ? $values['deposit'] : false;

        if ($deposit_meta) {
            $item->add_meta_data('wc_deposit_meta', $deposit_meta, true);
        }


    }

    /**
     * @brief Display deposit value in checkout order totals review area
     * @param $order
     */
    public function review_order_after_order_total($order)
    {
        if(!is_ajax()) return;
        if (wcdp_checkout_mode()) {
            $deposit_amount = get_option('wc_deposits_checkout_mode_deposit_amount');

            $amount_type = get_option('wc_deposits_checkout_mode_deposit_amount_type');
            if ($amount_type === 'fixed' && $deposit_amount >= WC()->cart->total) {
                WC()->cart->deposit_info['deposit_enabled'] = false;
            }
            $default_checked = get_option('wc_deposits_default_option', 'deposit');

            if ($default_checked === 'deposit' || (is_ajax() && isset($_POST['post_data']))) {

                $display_rows = true;
                if ((is_ajax() && isset($_POST['post_data']))) {
                    parse_str($_POST['post_data'], $post_data);
                    $display_rows = isset($post_data['deposit-radio']) && $post_data['deposit-radio'] === 'deposit';
                }

                if ($display_rows && isset(WC()->cart->deposit_info['deposit_enabled']) && WC()->cart->deposit_info['deposit_enabled'] === true && isset(WC()->cart->deposit_info['deposit_amount'])) {


                    $to_pay_text = __(get_option('wc_deposits_to_pay_text'), 'woocommerce-deposits');
                    $future_payment_text = __(get_option('wc_deposits_second_payment_text'), 'woocommerce-deposits');


                    if ($to_pay_text === false) {
                        $to_pay_text = __('To Pay', 'woocommerce-deposits');
                    }


                    if ($future_payment_text === false) {
                        $future_payment_text = __('Future Payments', 'woocommerce-deposits');
                    }
                    $to_pay_text = stripslashes($to_pay_text);
                    $future_payment_text = stripslashes($future_payment_text);
                    $deposit_breakdown_tooltip = wc_deposits_deposit_breakdown_tooltip();

                    ?>

                   <?php 
                   //echo"<pre>";
                       //print_r(WC()->cart);
                   ?>
                    <tr class="order-paid">
                        <th colspan="4"><?php echo $to_pay_text; ?><?php echo $deposit_breakdown_tooltip ?> </th>
                        <td data-title="<?php echo $to_pay_text; ?>">
                            <strong><?php echo wc_price(WC()->cart->deposit_info['deposit_breakdown']['cart_items']); ?></strong>

                        </td>
                    </tr>
                    <?php
                        $amount_type = get_option('wc_deposits_checkout_mode_deposit_amount_type');
                        if($amount_type == 'percentage'){
                       $items = WC()->cart->get_cart();
//echo"<pre>";print_R($items);
                    foreach($items as $item => $values) { 
                        $price[] = get_post_meta($values['product_id'] , '_price', true);
                       
                    } 
                    $a =  array_sum($price);
                    //print_r(WC()->cart->deposit_info);
                    ?>
                    <th colspan="4">Pay A Deposit Only (Min <?php echo $deposit_amount; ?>%)</th>
                        <td data-title="<?php echo $to_pay_text; ?>">
                            <input type="number" id="change_ammount" name="change_ammount" value="<?php echo WC()->cart->deposit_info['deposit_amount']; ?>">
                            <input type="hidden" value="<?php echo WC()->cart->total; ?>" id="cart_total">
                            <input type="hidden" value="<?php echo $deposit_amount; ?>" id="cart_per">
                            <span class="carterro"></span>

                        </td>
                   <?php } ?>
                    <tr class="order-remaining">
                        <th colspan="4"><?php echo $future_payment_text; ?></th>
                        <td data-title="<?php echo $future_payment_text; ?>">
                            <strong><?php echo wc_price(WC()->cart->get_total('edit') - WC()->cart->deposit_info['deposit_amount']); ?></strong>
                        </td>
                    </tr>
                    <?php


                }
            }

        } elseif (!wcdp_checkout_mode() && (isset(WC()->cart->deposit_info['deposit_enabled']) && WC()->cart->deposit_info['deposit_enabled'] === true)) {

            $to_pay_text = __(get_option('wc_deposits_to_pay_text'), 'woocommerce-deposits');
            $future_payment_text = __(get_option('wc_deposits_second_payment_text'), 'woocommerce-deposits');


            if ($to_pay_text === false) {
                $to_pay_text = __('To Pay', 'woocommerce-deposits');
            }


            if ($future_payment_text === false) {
                $future_payment_text = __('Future Payments', 'woocommerce-deposits');
            }
            $to_pay_text = stripslashes($to_pay_text);
            $future_payment_text = stripslashes($future_payment_text);

            $deposit_breakdown_tooltip = wc_deposits_deposit_breakdown_tooltip();
            ?>

            <tr class="order-paid">
                <th><?php echo $to_pay_text; ?><?php echo $deposit_breakdown_tooltip ?>  </th>
                <td data-title="<?php echo $to_pay_text; ?>">
                    <strong><?php echo wc_price(WC()->cart->deposit_info['deposit_amount']); ?></strong>
                </td>
            </tr>
            <tr class="order-remaining">
                <th><?php echo $future_payment_text; ?></th>
                <td data-title="<?php echo $future_payment_text; ?>">
                    <strong><?php echo wc_price(WC()->cart->get_total('edit') - WC()->cart->deposit_info['deposit_amount']); ?></strong>
                </td>
            </tr>
            <?php
        }


    }

    /**
     * @brief Updates the order metadata with deposit information
     *
     * @return void
     * @throws \WC_Data_Exception
     */
    public
    function checkout_update_order_meta($order_id)
    {

        $order = wc_get_order($order_id);



        if ($order->get_type() === 'wcdp_payment') {
            return;
        }


        if (isset(WC()->cart->deposit_info['deposit_enabled']) && WC()->cart->deposit_info['deposit_enabled'] === true) {

            $deposit = WC()->cart->deposit_info['deposit_amount'];

            $second_payment = WC()->cart->get_total('edit') - $deposit;

            $deposit_breakdown = WC()->cart->deposit_info['deposit_breakdown'];
            $sorted_schedule = WC()->cart->deposit_info['payment_schedule'];


            $deposit_data = array(
                'id' => '',
                'title' => __('Deposit', 'woocommerce-deposits'),
                'type' => 'deposit',
                'total' => $deposit,

            );


            $sorted_schedule = array('deposit' => $deposit_data) + $sorted_schedule;

            $order->add_meta_data('_wc_deposits_payment_schedule', $sorted_schedule, true);
            $order->add_meta_data('_wc_deposits_order_version', WC_DEPOSITS_VERSION, true);
            $order->add_meta_data('_wc_deposits_order_has_deposit', 'yes', true);
            $order->add_meta_data('_wc_deposits_deposit_paid', 'no', true);
            $order->add_meta_data('_wc_deposits_second_payment_paid', 'no', true);
            $order->add_meta_data('_wc_deposits_deposit_amount', $deposit, true);
            $order->add_meta_data('_wc_deposits_second_payment', $second_payment, true);
            $order->add_meta_data('_wc_deposits_deposit_breakdown', $deposit_breakdown, true);
            $order->add_meta_data('_wc_deposits_deposit_payment_time', ' ', true);
            $order->add_meta_data('_wc_deposits_second_payment_reminder_email_sent', 'no', true);


            if(isset($_POST['wcb2bsa_sales_agent']) && !empty($_POST['wcb2bsa_sales_agent'])){
                    $userdas = get_users(array('search' => $_POST['wcb2bsa_sales_agent']));
                    update_post_meta($order->id,'wcb2bsa_sales_agent', $userdas[0]->ID);

            }

        
              update_post_meta( $order->id, '_shipping_first_name', $_POST['shipping_first_name']);
              update_post_meta( $order->id, '_shipping_last_name', $_POST['shipping_last_name']);
              update_post_meta( $order->id, '_shipping_company', $_POST['shipping_company'] );
              update_post_meta( $order->id, '_shipping_address_1', $_POST['shipping_address_1']);
              update_post_meta( $order->id, '_shipping_address_2', $_POST['shipping_address_2']);
              update_post_meta( $order->id, '_shipping_city', $_POST['shipping_city'] );
              update_post_meta( $order->id, '_shipping_state', $_POST['shipping_state'] );
              update_post_meta( $order->id, '_shipping_postcode', $_POST['shipping_postcode']);
              update_post_meta( $order->id, '_shipping_country', $_POST['shipping_country']);
              update_post_meta( $order->id, '_shipping_email', $_POST['billing_email']);
              update_post_meta( $order->id, '_shipping_phone', $_POST['shipping_wooccm9']);
              

             

            $order->save();


        } elseif (isset(WC()->cart->deposit_info['deposit_enabled']) && WC()->cart->deposit_info['deposit_enabled'] !== true) {
            $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true);

            if ($order_has_deposit === 'yes') {

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

                // remove deposit meta from items
                foreach ($order->get_items() as $order_item) {
                    $order_item->delete_meta_data('wc_deposit_meta');
                    $order_item->save();
                }
                $order->save();

            }
        }
    }

    /**
     * @brief Removes the unwanted gateways from the settings page when there's a deposit
     *
     * @return mixed
     */
    public
    function available_payment_gateways($gateways)
    {
        $has_deposit = false;

        $pay_slug = get_option('woocommerce_checkout_pay_endpoint', 'order-pay');
        $order_id = absint(get_query_var($pay_slug));
        $is_paying_deposit = true;
        if ($order_id > 0) {
            $order = wc_get_order($order_id);
            if (!$order || $order->get_type() !== 'wcdp_payment') return $gateways;

            $has_deposit = true;

            if ($order->get_meta('_wc_deposits_payment_type', true) !== 'deposit') {

                $is_paying_deposit = false;
            }


        } else {
            $is_paying_deposit = true;

            if (wcdp_checkout_mode() && is_ajax() && isset($_POST['post_data'])) {
                parse_str($_POST['post_data'], $post_data);

                if (isset($post_data['deposit-radio']) && $post_data['deposit-radio'] === 'deposit') {
                    $has_deposit = true;
                }

            } else {
                if (isset(WC()->cart->deposit_info) && isset(WC()->cart->deposit_info['deposit_enabled']) && WC()->cart->deposit_info['deposit_enabled'] === true) {
                    $has_deposit = true;
                }
            }

        }


        if ($has_deposit) {

            if ($is_paying_deposit) {
                $disallowed_gateways = get_option('wc_deposits_disallowed_gateways_for_deposit');

            } else {
                $disallowed_gateways = get_option('wc_deposits_disallowed_gateways_for_second_payment');

            }

            if (is_array($disallowed_gateways)) {
                foreach ($disallowed_gateways as $value) {
                    unset($gateways[$value]);
                }
            }

        }
        return $gateways;
    }


    public function pay_deposit($cart) {
    	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
            $depositamount =  $_POST['depositamount'];
            if(isset($depositamount) && !empty($depositamount)){
                global $woocommerce;
		   		global $wpdb;	
		   		WC()->session->set( 'custom_deposit_amount', $depositamount);
		   		echo '1';
            }
            die();
    }
}
