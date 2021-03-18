<?php
/*Copyright: © 2017 Webtomizer.
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

namespace Webtomizer\WCDP;


if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
if (class_exists('WC_Deposits_Cart')) return;

/**
 * Class WC_Deposits_Cart
 */
class WC_Deposits_Cart
{


    public $wc_deposits;
    private $has_payment_plans = false;

    /**
     *
     * WC_Deposits_Cart constructor.
     * @param $wc_deposits
     */
    public function __construct(&$wc_deposits)
    {
        // Hook cart functionality

        $this->wc_deposits = $wc_deposits;

        if (!wcdp_checkout_mode()) {
//            woocommerce_cart_loaded_from_session
            add_action('woocommerce_cart_loaded_from_session', array($this, 'cart_loaded_from_session'));

            add_filter('woocommerce_get_cart_item_from_session', array($this, 'get_cart_item_from_session'), 10, 2);
//            add_action('woocommerce_after_cart_item_quantity_update', array($this, 'after_cart_item_quantity_update'), 10, 2);
            add_action('woocommerce_cart_totals_after_order_total', array($this, 'cart_totals_after_order_total'));
            add_filter('woocommerce_get_item_data', array($this, 'get_item_data'), 10, 2);
            add_action('woocommerce_add_to_cart', array($this, 'is_sold_individually'), 10, 6);

        } else {
            add_action('woocommerce_before_calculate_totals', array($this, 'adjust_cart_subtotal'), 10);

        }

        //have to set very low priority to make sure all other plugins make calculations first
        add_filter('woocommerce_calculated_total', array($this, 'calculated_total'), 1001, 2);

        add_filter('woocommerce_cart_needs_payment', array($this, 'cart_needs_payment'), 10, 2);

    }

    function adjust_cart_subtotal()
    {
        if (!is_ajax()) return;

        if (isset($_POST['post_data'])) {
            parse_str($_POST['post_data'], $post_data);
        }

        if (isset($post_data['deposit-radio']) && $post_data['deposit-radio'] !== 'deposit') return;
        $amount_type = get_option('wc_deposits_checkout_mode_deposit_amount_type');
        if ($amount_type !== 'payment_plan' || !is_checkout()) return;
        $payment_plans = get_option('wc_deposits_checkout_mode_payment_plans', array());
        if (empty($payment_plans)) return;
        $selected_plan = isset($post_data['wcdp-selected-plan']) ? $post_data['wcdp-selected-plan'] : $payment_plans[0];

        if (!$selected_plan) return;
        $deposit_percentage = get_term_meta($selected_plan, 'deposit_percentage', true);


        //if plan is more or less than 100% ,we need to adjust cart total
        $total_percentage = floatval($deposit_percentage);

        $plan_lines = get_term_meta($selected_plan, 'payment_details', true);
        $plan_lines = json_decode($plan_lines, true);
        if (empty($plan_lines)) return;
        foreach ($plan_lines['payment-plan'] as $plan_id => $plan_detail) {
            $total_percentage += floatval($plan_detail['percentage']);
        }


        if ($total_percentage !== 100.0) {


            if(!isset(WC()->cart->deposit_info['original_subtotal'])){
                //set the original total
                if (!is_array(WC()->cart->deposit_info)) {
                    WC()->cart->deposit_info = array('original_subtotal' => 0.0);
                } else {
                    WC()->cart->deposit_info['original_subtotal'] = 0.0;
                }
            }
            foreach (WC()->cart->get_cart_contents() as $cart_item_key => $cart_item) {
                if(!isset(WC()->cart->cart_contents[$cart_item_key]['wcdp_original_total'])){
                    WC()->cart->cart_contents[$cart_item_key]['wcdp_original_total'] = $cart_item['line_subtotal'];
                }
                WC()->cart->deposit_info['original_subtotal'] += $cart_item['wcdp_original_total'] * $cart_item['quantity'];


            }

            foreach (WC()->cart->get_cart_contents() as $cart_item_key => $cart_item) {
                $price = $cart_item['wcdp_original_total'] / 100 * $total_percentage;
                WC()->cart->cart_contents[$cart_item_key]['data']->set_price($price);
            }



        }


    }

    function cart_loaded_from_session($cart)
    {

        if (WC()->cart) {

            foreach (WC()->cart->get_cart_contents() as $cart_item_key => $cart_item) {
                $this->update_deposit_meta($cart_item['data'], $cart_item['quantity'], $cart_item, $cart_item_key);

            }
        }
    }

    /**
     * Prevents duplicates if the product is set to be individually sold.
     *
     * @throws \Exception if more than 1 item of an individually-sold product is being added to cart.
     */
    public function is_sold_individually($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data)
    {
        $product = wc_get_product($product_id);

        if ($product->is_sold_individually() && isset($cart_item_data['deposit'])) {
            $item_data = $cart_item_data;

            // Get the two possible values of the cart item key.
            if ($item_data['deposit']['enable'] === 'yes') {
                $key_with_deposit = WC()->cart->generate_cart_id($product_id, $variation_id, $variation, $item_data);
                $item_data['deposit']['enable'] = 'no';

                // The value of cart item key if deposit is disabled.
                $key_without_deposit = WC()->cart->generate_cart_id($product_id, $variation_id, $variation, $item_data);
            } else {
                $key_without_deposit = WC()->cart->generate_cart_id($product_id, $variation_id, $variation, $item_data);
                $item_data['deposit']['enable'] = 'yes';

                // The value of cart item key if deposit is enabled.
                $key_with_deposit = WC()->cart->generate_cart_id($product_id, $variation_id, $variation, $item_data);
            }

            // Check if any of the cart item keys is being added more than once.
            $item_count = 0;
            foreach (WC()->cart->get_cart_contents() as $item) {
                if (($item['key'] === $key_with_deposit || $item['key'] === $key_without_deposit)) {
                    $item_count += $item['quantity'];
                }
            }

            if ($item_count > 1) {
                /* translators: %s: product name */
                throw new \Exception(sprintf('<a href="%s" class="button wc-forward">%s</a> %s', wc_get_cart_url(), __('View cart', 'woocommerce'), sprintf(__('You cannot add another "%s" to your cart.', 'woocommerce'), $product->get_name())));
            }
        }
    }

    private function build_payment_schedule($remaining_amounts, $deposit,$cart_items_deposit_amount)
    {


        /**   START BUILD PAYMENT SCHEDULE**/
        $schedule = array();
        $unlimited = array(
            'id' => '',
            'title' => __('Future Payment', 'woocommerce-deposits'),
            'type' => 'second_payment',
            'total' => 0.0,
        );
        $payment_date = current_time('timestamp');
        $second_payment_due_after = get_option('wc_deposits_second_payment_due_after', '');


        if (wcdp_checkout_mode()) {

            $amount_type = get_option('wc_deposits_checkout_mode_deposit_amount_type');
            if ($amount_type === 'payment_plan') {
                $selected_plan = false;
                $available_plans = get_option('wc_deposits_checkout_mode_payment_plans', array());
                if (empty($available_plans)) return array();

                if (is_ajax()) {

                    //calculation when checkout is updated
                    if (isset($_POST['post_data'])) {
                        parse_str($_POST['post_data'], $post_data);
                        if (isset($post_data['wcdp-selected-plan']) && in_array($post_data['wcdp-selected-plan'], $available_plans)) {
                            $selected_plan = $post_data['wcdp-selected-plan'];
                        }
                    }

                    if (isset($_POST['wcdp-selected-plan']) && in_array($_POST['wcdp-selected-plan'], $available_plans)) {
                        //final calculation when order is placed
                        $selected_plan = $_POST['wcdp-selected-plan'];
                    }


                }


                if (!$selected_plan) $selected_plan = $available_plans[0];

                $payment_details = json_decode(get_term_meta($selected_plan, 'payment_details', true), true);

                if (is_array($payment_details) && isset($payment_details['payment-plan']) && !empty($payment_details['payment-plan'])) {

                    $deposit_percentage = get_term_meta($selected_plan, 'deposit_percentage', true);
                    $total_percentage = floatval($deposit_percentage);

                    foreach ($payment_details['payment-plan'] as $plan_id => $plan_detail) {
                        $total_percentage += floatval($plan_detail['percentage']);
                    }

                    $total = floatval(WC()->cart->get_subtotal());

                    if ($total_percentage !== 100.0) {
                        $total = $total / $total_percentage * 100;
                    }

                    foreach ($payment_details['payment-plan'] as $single_payment) {
                        $percentage = $single_payment['percentage'];
                        if (isset($single_payment['date']) && !empty($single_payment['date'])) {
                            $payment_date = strtotime($single_payment['date']);
                        } else {
                            $after = $single_payment['after'];
                            $after_term = $single_payment['after-term'];
                            $payment_date = strtotime(date('Y-m-d', $payment_date) . "+{$after} {$after_term}s");
                        }

                        //calculate base deposit amount for the plan
                        $amount = $total / 100 * $percentage;


                        if (!isset($schedule[$payment_date])) $schedule[$payment_date] = array('type' => 'partial_payment', 'total' => 0.0);
                        $schedule[$payment_date]['total'] = $amount;

                    }
                }


            } else {

                // simple deposit , build schedule based on due date if set
                if (!empty($second_payment_due_after) && is_numeric($second_payment_due_after)) {

                    $timestamp = strtotime("+{$second_payment_due_after} days", current_time('timestamp'));
                    if (!isset($schedule[$timestamp])) $schedule[$timestamp] = array('total' => 0.0);
                    $schedule[$timestamp]['total'] = floatval(WC()->cart->get_subtotal() - $cart_items_deposit_amount);
                    if (!isset($schedule[$timestamp]['type'])) $schedule[$timestamp]['type'] = 'second_payment';

                } else {

                    $unlimited['total'] = floatval(WC()->cart->get_subtotal() - $cart_items_deposit_amount);
                    $unlimited['type'] = 'second_payment';
                }
            }

        } else {
            foreach (WC()->cart->get_cart() as $key => $cart_item) {


                //handle the remaining discount to per item


                //go through all items with deposit
                if (isset($cart_item['deposit']) && $cart_item['deposit']['enable'] === 'yes' && isset($cart_item['deposit']['deposit'])) {

                    if (isset($cart_item['deposit']['payment_schedule'])) {

                        foreach ($cart_item['deposit']['payment_schedule'] as $timestamp => $payment) {
                            if (!isset($schedule[$timestamp])) $schedule[$timestamp] = array('type' => 'partial_payment', 'total' => 0.0);
                            $schedule[$timestamp]['total'] += floatval($payment['amount']);
                        }

                    } else {
                        // simple deposit , build schedule based on due date if set
                        if (!empty($second_payment_due_after) && is_numeric($second_payment_due_after)) {

                            $timestamp = strtotime("+{$second_payment_due_after} days", current_time('timestamp'));
                            if (!isset($schedule[$timestamp])) $schedule[$timestamp] = array('total' => 0.0);
                            $schedule[$timestamp]['total'] += floatval($cart_item['deposit']['remaining']);
                            if (!isset($schedule[$timestamp]['type'])) $schedule[$timestamp]['type'] = 'second_payment';

                        } else {

                            $unlimited['total'] += $cart_item['deposit']['remaining'];
                            $unlimited['type'] = 'second_payment';
                        }
                    }

                }
            }
        }


        // determine the percentage of each payment from schedule total and add remaining amounts according to that percerntage


        $timestamps = array();

        foreach (array_keys($schedule) as $key => $node) {
            $timestamps[$key] = $node;
        }
        array_multisort($timestamps, SORT_ASC, array_keys($schedule));

        $sorted_schedule = array();
        foreach ($timestamps as $timestamp) {

            $sorted_schedule[$timestamp] = $schedule[$timestamp];
        }

        $schedule = $sorted_schedule;
        if ((empty($second_payment_due_after) || !is_numeric($second_payment_due_after)) && $unlimited['total'] > 0) {

            $schedule['unlimited'] = $unlimited;
        }

        // add any fees /taxes / shipping / shipping taxes amounts
        $schedule_total = array_sum(array_column($schedule, 'total'));
        $count = 0;
        $remaining_amounts_record = $remaining_amounts;

        foreach ($schedule as $payment_key => $payment) {
            $percentage = $payment['total'] / $schedule_total * 100;

            //fix for rounding
            $count++;

            $last = $count === count($schedule);
            foreach ($remaining_amounts as $amount_key => $remaining_amount) {

                if ($remaining_amount <= 0) continue;

                if ($last) {
                    if ($amount_key === 'discounts') {
                        $schedule[$payment_key]['total'] -= $remaining_amounts_record[$amount_key];

                    } else {

                        $schedule[$payment_key]['total'] += $remaining_amounts_record[$amount_key];

                    }
                    continue;
                }

                if ($amount_key === 'discounts') {
                    $schedule[$payment_key]['total'] -= round($remaining_amount / 100 * $percentage, wc_get_price_decimals());
                    $remaining_amounts_record[$amount_key] -= round($remaining_amount / 100 * $percentage, wc_get_price_decimals());

                } else {

                    $schedule[$payment_key]['total'] += round($remaining_amount / 100 * $percentage, wc_get_price_decimals());
                    $remaining_amounts_record[$amount_key] -= round($remaining_amount / 100 * $percentage, wc_get_price_decimals());

                }

            }
        }

        return $schedule;
    }


    /**
     * @brief Display deposit info in cart item meta area
     * @param $item_data
     * @param $cart_item
     * @return array
     */
    public function get_item_data($item_data, $cart_item)
    {


        if (isset($cart_item['deposit']) && $cart_item['deposit']['enable'] === 'yes' && isset($cart_item['deposit']['deposit'])) {

            $product = $cart_item['data'];
            if (!$product) return $item_data;

            $tax_display = get_option('wc_deposits_tax_display_cart_item', 'no') === 'yes';

            $deposit = $cart_item['deposit']['deposit'];

            $tax = 0.0;
            $tax_total = 0.0;
            if ($tax_display) {
                $tax = $cart_item['deposit']['tax'];
                $tax_total = $cart_item['deposit']['tax_total'];
            }

            $display_deposit = round($deposit + $tax, wc_get_price_decimals());
            $display_remaining = round($cart_item['deposit']['remaining'] + ($tax_total - $tax), wc_get_price_decimals());
            $deposit_amount_text = __(get_option('wc_deposits_deposit_amount_text'), 'woocommerce-deposits');
            if (empty($deposit_amount_text)) {
                $deposit_amount_text = __('Deposit Amount', 'woocommerce-deposits');
            }

            $item_data[] = array(
                'name' => $deposit_amount_text,
                'display' => wc_price($display_deposit),
                'value' => 'wc_deposit_amount',
            );


            $future_payment_amount_text = __(get_option('wc_deposits_second_payment_text'), 'woocommerce-deposits');

            if (empty($future_payment_amount_text)) {
                $future_payment_amount_text = __('Future Payments', 'woocommerce-deposits');
            }


            $item_data[] = array(
                'name' => $future_payment_amount_text,
                'display' => wc_price($display_remaining),
                'value' => 'wc_deposit_future_payments_amount',
            );

            if (isset($cart_item['deposit']['payment_plan'])) {


                $payment_plan = get_term_by('id', $cart_item['deposit']['payment_plan'], WC_DEPOSITS_PAYMENT_PLAN_TAXONOMY);
                $item_data[] = array(
                    'name' => __('Payment plan', 'woocommerce-deposits'),
                    'display' => $payment_plan->name,
                    'value' => WC_DEPOSITS_PAYMENT_PLAN_TAXONOMY,
                );
            }


        }


        return $item_data;


    }

    /**
     * @param $cart_item
     * @param $values
     * @return mixed
     */
    public function get_cart_item_from_session($cart_item, $values)
    {

        if (!empty($values['deposit'])) {
            $cart_item['deposit'] = $values['deposit'];
        }
        return $cart_item;
    }


    /**
     * @brief Calculate Deposit and update cart item meta with new values
     * @param $product
     * @param $quantity
     * @param $cart_item_data
     * @param $cart_item_key
     */
    function update_deposit_meta($product, $quantity, &$cart_item_data, $cart_item_key)
    {
        if ($product) {
            $product_type = $product->get_type();
            $deposit_enabled = wc_deposits_is_product_deposit_enabled($product->get_id());
            if ($deposit_enabled && isset($cart_item_data['deposit']) &&
                $cart_item_data['deposit']['enable'] === 'yes'
            ) {

                if (isset($cart_item_data['deposit']['payment_plan'])) {

                    $payment_plan = get_term_by('id', $cart_item_data['deposit']['payment_plan'], WC_DEPOSITS_PAYMENT_PLAN_TAXONOMY);

                    if ($payment_plan) {
                        if (!isset($cart_item_data['deposit']['original_line_subtotal'])) {
                            $cart_item_data['deposit']['original_line_subtotal'] = $cart_item_data['line_subtotal'] / $quantity;
                        }

                        $subtotal = $cart_item_data['deposit']['original_line_subtotal'];
                        //get plan details from meta
                        $deposit_percentage = get_term_meta($cart_item_data['deposit']['payment_plan'], 'deposit_percentage', true);
                        $deposit_amount = ($subtotal / 100 * $deposit_percentage) * $quantity;
                        $plan_lines = get_term_meta($cart_item_data['deposit']['payment_plan'], 'payment_details', true);
                        $plan_lines = json_decode($plan_lines, true);

                        if (!is_array($plan_lines)) {
                            return;
                        }

                        $total_percentage = floatval($deposit_percentage);
                        $payment_date = current_time('timestamp');
                        $price_total = $deposit_amount;
                        $schedule = array();
                        foreach ($plan_lines['payment-plan'] as $plan_id => $plan_detail) {
                            $total_percentage += floatval($plan_detail['percentage']);

                            if (isset($plan_detail['date']) && !empty($plan_detail['date'])) {
                                $payment_date = strtotime($plan_detail['date']);
                            } else {
                                $after = $plan_detail['after'];
                                $after_term = $plan_detail['after-term'];
                                $payment_date = strtotime(date('Y-m-d', $payment_date) . "+{$after} {$after_term}s");
                            }

                            if (!isset($schedule[$payment_date])) {
                                $schedule[$payment_date] = array();
                            }
                            $schedule[$payment_date]['amount'] = ((($subtotal / 100) * $quantity) * $plan_detail['percentage']);

                            $price_total += $schedule[$payment_date]['amount'];
                        }

                        //set the line item and product total to the amount totaled by payment plan percentage
                        $amount = $price_total;

                        if ($price_total !== $cart_item_data['deposit']['original_line_subtotal']) {
                            $cart_item_data['data']->set_price($amount / $quantity);
                        }

                        $tax_handling = get_option('wc_deposits_taxes_handling');
                        $tax_total = (wc_get_price_including_tax($cart_item_data['data']) - wc_get_price_excluding_tax($cart_item_data['data'])) * $quantity;
                        $cart_item_data['deposit']['tax_total'] = $tax_total;
                        if ($tax_handling === 'deposit') {
                            $cart_item_data['deposit']['tax'] = $tax_total;

                        } elseif ($tax_handling === 'split') {
                            //adjust deposit percentage
                            $deposit_percentage = $deposit_amount / $cart_item_data['line_subtotal'] * 100;
                            $cart_item_data['deposit']['tax'] = (($tax_total * $deposit_percentage / 100));
                        } else {
                            $cart_item_data['deposit']['tax'] = 0;

                        }
                        //calculate deposit amount for the plan

                        $cart_item_data['deposit']['deposit'] = $deposit_amount;
                        $cart_item_data['deposit']['remaining'] = $amount - $deposit_amount;
                        $cart_item_data['deposit']['total'] = $amount;
                        $cart_item_data['deposit']['payment_schedule'] = $schedule;

                        $this->has_payment_plans = true;
                    } else {
                        $cart_item_data['deposit']['enable'] = 'no';
                    }

                } else {

                    if ($product_type === 'variation') {
                        //check override
                        $override = $product->get_meta('_wc_deposits_override_product_settings', true) === 'yes';
                        if ($override) {

                            $amount_type = $product->get_meta('_wc_deposits_amount_type', true);
                            $deposit_amount_meta = floatval($product->get_meta('_wc_deposits_deposit_amount', true));

                        } else {
                            $parent = wc_get_product($product->get_parent_id());
                            $amount_type = $parent->get_meta('_wc_deposits_amount_type', true);
                            $deposit_amount_meta = floatval($parent->get_meta('_wc_deposits_deposit_amount', true));
                        }

                    } else {

                        $deposit_amount_meta = $product->get_meta('_wc_deposits_deposit_amount', true);
                        $amount_type = $product->get_meta('_wc_deposits_amount_type', true);

                    }

                    $amount = $cart_item_data['line_subtotal'];

                    switch ($product_type) {

                        case 'booking':

                            if (class_exists('WC_Booking')) {


                                if ($product->has_persons() && $product->get_meta('_wc_deposits_enable_per_person', true) == 'yes') {
                                    $persons = array_sum($cart_item_data['booking']['_persons']);
                                    if ($amount_type === 'fixed') {
                                        $deposit = $deposit_amount_meta * $persons;
                                    } else { // percent

                                        $deposit = $deposit_amount_meta / 100.0 * $amount;

                                    }
                                } else {
                                    if ($amount_type === 'fixed') {
                                        $deposit = $deposit_amount_meta;
                                    } elseif ($amount_type === 'percent') {
                                        $deposit = $deposit_amount_meta / 100.0 * $amount;
                                    }
                                }


                            } else {


                                $amount = wc_get_price_excluding_tax($product, array('qty' => $quantity));

                                if ($amount_type === 'fixed') {

                                    $deposit = floatval($deposit_amount_meta) * $quantity;

                                } else {
                                    $deposit = $amount * (floatval($deposit_amount_meta) / 100.0);
                                }
                            }

                            break;
                        case 'subscription' :
                            if (class_exists('WC_Subscriptions_Product')) {

                                $amount = \WC_Subscriptions_Product::get_sign_up_fee($product);
                                if ($amount_type === 'fixed') {
                                    $deposit = floatval($deposit_amount_meta) * $quantity;
                                } else {
                                    $deposit = $amount * ($deposit_amount_meta / 100.0);
                                }

                            }
                            break;
                        case 'yith_bundle' :
                            $amount = $product->price_per_item_tot;
                            if ($amount_type === 'fixed') {
                                $deposit = floatval($deposit_amount_meta) * $quantity;
                            } else {
                                $deposit = $amount * ($deposit_amount_meta / 100.0);
                            }
                            break;

                        case 'phive_booking':

                            $amount = $cart_item_data['phive_booked_price'];
                            if ($amount_type === 'fixed') {
                                $deposit = $deposit_amount_meta;

                            } else {
                                $deposit = $amount * ($deposit_amount_meta / 100.0);
                            }

                            break;
                        default:


                            if ($amount_type === 'fixed') {

                                $deposit = floatval($deposit_amount_meta) * $quantity;

                            } else {
                                $deposit = $amount * (floatval($deposit_amount_meta) / 100.0);
                            }

                            break;
                    }
                    $woocommerce_prices_include_tax = get_option('woocommerce_prices_include_tax');

                    $tax_handling = get_option('wc_deposits_taxes_handling');
                    $tax_total = $cart_item_data['line_subtotal_tax'] / $quantity;
                    $cart_item_data['deposit']['tax_total'] = $tax_total;

                    if ($tax_handling === 'deposit') {
                        $cart_item_data['deposit']['tax'] = $tax_total;

                    } elseif ($tax_handling === 'split') {

                        if ($woocommerce_prices_include_tax === 'yes') {
                            $deposit_percentage = $deposit * 100 / $amount;
                        } else {
                            $deposit_percentage = $deposit * 100 / $amount;
                        }


                        $cart_item_data['deposit']['tax'] = $tax_total * $deposit_percentage / 100;

                    } else {

                        $cart_item_data['deposit']['tax'] = 0;

                    }

                    if ($deposit < $amount && $deposit > 0) {

                        $discount_percentage = 0;
                        if (floatval(WC()->cart->get_cart_discount_total()) && floatval(WC()->cart->get_subtotal()) > 0) {
                            $discount_percentage = WC()->cart->get_cart_discount_total() / WC()->cart->get_subtotal() * 100;
                        }

                        if ($discount_percentage > 0) {
                            $discount = $deposit / 100 * $discount_percentage;
                            $cart_item_data['deposit']['percent_discount'] = $discount;

                        }
                    }
                    if ($deposit < $amount) {

                        $cart_item_data['deposit']['deposit'] = $deposit;
                        $cart_item_data['deposit']['remaining'] = $amount - $deposit;
                        $cart_item_data['deposit']['total'] = $amount;
                    } else {
                        $cart_item_data['deposit']['enable'] = 'no';
                    }

                }
                WC()->cart->cart_contents[$cart_item_key]['deposit'] = apply_filters('wc_deposits_cart_item_deposit_data', $cart_item_data['deposit'], $cart_item_data);

            }

        }
    }


    /**
     * @brief triggers update deposit for all cart items when cart is updated
     * @param $cart_item_key
     * @param $quantity
     */
    public
    function after_cart_item_quantity_update($cart_item_key, $quantity)
    {
        $product = WC()->cart->cart_contents[$cart_item_key]['data'];
        $this->update_deposit_meta($product, $quantity, WC()->cart->cart_contents[$cart_item_key], $cart_item_key);
    }


    /**
     * @brief Calculate total Deposit in cart totals area
     *
     * @param mixed $cart_total ...
     * @param mixed $cart ...
     *
     * @return float
     */
    public
    function calculated_total($cart_total, $cart)
    {


        //user restriction
        if (is_user_logged_in()) {

            $disabled_user_roles = get_option('wc_deposits_disable_deposit_for_user_roles', array());
            if (!empty($disabled_user_roles)) {

                foreach ($disabled_user_roles as $disabled_user_role) {

                    if (wc_current_user_has_role($disabled_user_role)) return $cart_total;

                }

            }
        } else {
            $allow_deposit_for_guests = get_option('wc_deposits_restrict_deposits_for_logged_in_users_only', 'no');

            if ($allow_deposit_for_guests !== 'no') return $cart_total;
        }


        $cart_original = $cart_total;
        $deposit_amount = 0;
        $deposit_total = 0;
        $full_amount_products = 0;
        $full_amount_taxes = 0;
        $deposit_product_taxes = 0;
        $deposit_enabled = false;
        $deposit_in_cart = false;

        if (wcdp_checkout_mode()) {

            $this->has_payment_plans = false;

            $deposit_in_cart = true;

            $deposit_amount_meta = get_option('wc_deposits_checkout_mode_deposit_amount');
            $amount_type = get_option('wc_deposits_checkout_mode_deposit_amount_type');


            $deposit_total = WC()->cart->get_subtotal();

            switch ($amount_type) {
                case 'payment_plan' :

                    $payment_plans = get_option('wc_deposits_checkout_mode_payment_plans', array());
                    if (empty($payment_plans)) return $cart_total;

                    $selected_plan = false;
                    if (is_ajax()) {

                        if (isset($_POST['post_data'])) {

                            parse_str($_POST['post_data'], $post_data);
                            $selected_plan = isset($post_data['wcdp-selected-plan']) ? $post_data['wcdp-selected-plan'] : $payment_plans[0];
                        }
                        if (isset($_POST['wcdp-selected-plan'])) {

                            $selected_plan = isset($_POST['wcdp-selected-plan']) ? $_POST['wcdp-selected-plan'] : $payment_plans[0];
                        }


                    }

                    if (!$selected_plan) {
                        //choose first plan as default selected
                        foreach ($payment_plans as $key => $plan_id) {
                            if (term_exists(absint($plan_id), WC_DEPOSITS_PAYMENT_PLAN_TAXONOMY)) {
                                $selected_plan = $payment_plans[$key];
                                break;
                            }
                        }

                    }
                    $deposit_percentage = get_term_meta($selected_plan, 'deposit_percentage', true);
                    $subtotal = isset(WC()->cart->deposit_info['original_subtotal']) ? WC()->cart->deposit_info['original_subtotal'] : WC()->cart->get_subtotal();
                    $deposit_amount = ($subtotal * $deposit_percentage) / 100;
                    $this->has_payment_plans = true;

                    break;

                case 'percentage' :
                $c = WC()->session->get( 'custom_deposit_amount');
               
                if(isset($c) && !empty($c)){
                    $deposit_amount = $c;
                }else{

                    $items = WC()->cart->get_cart();
                    
                    foreach($items as $item => $values) { 
                      
                       $variation_id = $values['variation_id'];
                        if(isset($variation_id) && !empty($variation_id)){
                            $price = get_post_meta($variation_id, '_sale_price', true);
                        }else{
                       $price = get_post_meta($values['product_id'] , '_price', true);
                   }
                         $quantity= $values['quantity'];
                         $totalas[] = ($price)* ($quantity);
                    } 
                    $a =  array_sum($totalas);
                     $deposit_amount = ($a * $deposit_amount_meta) / 100;
                }
                
                    break;
                case 'fixed':
                    $deposit_amount = $deposit_amount_meta;
                    break;
                default :
                    break;
            }
        } else {
            $this->has_payment_plans = false;
            foreach (WC()->cart->get_cart_contents() as $cart_item_key => &$cart_item) {

                if (isset($cart_item['deposit']) && $cart_item['deposit']['enable'] === 'yes' && isset($cart_item['deposit']['deposit'])) {
                    $deposit_in_cart = true;
                    $product = wc_get_product($cart_item['product_id']);
                    $deposit_amount += $cart_item['deposit']['deposit'];
                    $deposit_product_taxes += $cart_item['deposit']['tax'];
                    $deposit_total += $cart_item['deposit']['total'];
                    if ($product->get_type() === 'subscription' && class_exists('WC_Subscriptions_Product')) {
                        $deposit_amount += \WC_Subscriptions_Product::get_price($product);
                    }

                    if (isset($cart_item['deposit']['payment_plan'])) {
                        $this->has_payment_plans = true;
                    }
                } else {
                    //YITH bundle compatiblity
                    if (isset($cart_item['bundled_by'])) {

                        $bundled_by = $cart->cart_contents[$cart_item['bundled_by']];
                        if (isset($bundled_by['deposit']) && $bundled_by['deposit']['enable'] === 'yes') {

                            if (!(isset($bundled_by['data']->per_items_pricing) && $bundled_by['data']->per_items_pricing)) {
                                $full_amount_products += $cart_item['line_subtotal'];
                            }
                        } else {

                            $full_amount_products += $cart_item['line_subtotal'];
                        }

                    } else {

                        $full_amount_products += $cart_item['line_subtotal'];
                        $full_amount_taxes += $cart_item['line_subtotal_tax'];
                    }
                }
            }
        }

        if ($deposit_in_cart && $deposit_amount < ($deposit_total + $cart->fee_total + $cart->tax_total + $cart->shipping_total)) {
            if (!wcdp_checkout_mode()) {
                $deposit_amount += $full_amount_products;
                $deposit_enabled = true;
            } else {


                if (is_ajax() && isset($_POST['deposit-radio']) && $_POST['deposit-radio'] === 'deposit') {
                    $deposit_enabled = true;
                    $do_calculations = true;

                    //check for an payment plan
                    if (isset($post_data['wcdp-selected-plan']) && !empty($post_data['wcdp-selected-plan'])) {

                        $available_plan = get_term_by('id', $post_data['wcdp-selected-plan'], WC_DEPOSITS_PAYMENT_PLAN_TAXONOMY);
                        if ($available_plan) {

                            //get plan details from meta
                            $deposit_percentage = get_term_meta($post_data['wcdp-selected-plan'], 'deposit_percentage', true);


                            //calculate deposit amount for the plan
                            $deposit_amount = WC()->cart->get_subtotal() / 100 * $deposit_percentage;

                            

                            //calculate future payments
                            //details
                            $payment_details = get_term_meta($post_data['wcdp-selected-plan'], 'payment_details', true);
                            $payment_details = json_decode($payment_details, true);

                            if (!is_array($payment_details) || !is_array($payment_details['payment-plan']) || empty($payment_details['payment-plan'])) {
                                $deposit_enabled = false;
                            }
                            $future_percentage = 0.0;
                            foreach ($payment_details['payment-plan'] as $payment_detail) {
                                $future_percentage += $payment_detail['percentage'];


                            }
                            $future_amount = WC()->cart->get_subtotal() / 100 * $future_percentage;
//                            WC()->cart->add_fee($future_amount);
                            $cart_total = $future_amount;
                        }
                    }


                } elseif (is_ajax() && isset($_POST['deposit-radio']) && $_POST['deposit-radio'] === 'full') {

                    $deposit_enabled = false;
                } else {

                    $deposit_enabled = true;
                }
            }
        }
        $deposit_breakdown = null;

        /*
         * Additional fees handling.
         */
        $fees_handling = get_option('wc_deposits_fees_handling');
        $taxes_handling = get_option('wc_deposits_taxes_handling');
        $shipping_handling = get_option('wc_deposits_shipping_handling');
        $shipping_taxes_handling = get_option('wc_deposits_shipping_taxes_handling');

        // Default option: collect fees with the second payment.
        $deposit_fees = 0.0;
        $deposit_taxes = $full_amount_taxes;
        $deposit_shipping = 0.0;
        $deposit_shipping_taxes = 0.0;
        $division = WC()->cart->get_subtotal();

        if (wcdp_checkout_mode()) {
            $division = $division == 0 ? 1 : $division;
            $deposit_percentage = $deposit_amount * 100 / floatval($division);

        } else {
            $division = $division == 0 ? 1 : $division;
            $deposit_percentage = $deposit_amount * 100 / floatval($division);

        }

        // all the amounts that should be paid with future payments
        $remaining_amounts = array();

        /*
        /*
        * Fees handling.
        */

        $fee_taxes = 0;
        switch ($fees_handling) {
            case 'deposit' :


                $deposit_fees = floatval($cart->fee_total + $fee_taxes);
                break;

            case 'split' :
                $deposit_fees = floatval($cart->fee_total + $fee_taxes) * $deposit_percentage / 100;

                break;
        }
        $remaining_amounts['fees'] = ($cart->fee_total + $fee_taxes) - $deposit_fees;

        /*
         * Taxes handling.
         */
        if (wcdp_checkout_mode()) {
            switch ($taxes_handling) {
                case 'deposit' :
                    $deposit_taxes = $cart->get_subtotal_tax() + $full_amount_taxes;
                    break;

                case 'split' :

                    $deposit_taxes = ($cart->get_subtotal_tax() + $full_amount_taxes) * $deposit_percentage / 100;
                    break;
            }
        } else {
            $deposit_taxes += $deposit_product_taxes;
        }
        $remaining_amounts['taxes'] = $cart->get_subtotal_tax() - $deposit_taxes;

        /*
         * Shipping handling.
         */
        switch ($shipping_handling) {
            case 'deposit' :
                $deposit_shipping = $cart->shipping_total;
                break;

            case 'split' :
                $deposit_shipping = $cart->shipping_total * $deposit_percentage / 100;
                break;
        }
        $remaining_amounts['shipping'] = $cart->shipping_total - $deposit_shipping;

        /*
         * Shipping taxes handling.
         */
        switch ($shipping_taxes_handling) {
            case 'deposit' :
                $deposit_shipping_taxes = $cart->shipping_tax_total;
                break;

            case 'split' :
                $deposit_shipping_taxes = $cart->shipping_tax_total * $deposit_percentage / 100;
                break;
        }
        $remaining_amounts['shipping_taxes'] = $cart->shipping_tax_total - $deposit_shipping_taxes;

        // Add fees, taxes, shipping and shipping taxes to the deposit amount.
        $cart_items_deposit_amount = $deposit_amount;

        //discount handling
        if (!wcdp_checkout_mode()) {

            foreach (WC()->cart->get_cart_contents() as $cart_item_key => $cart_item) {
                if (isset($cart_item['deposit']) && $cart_item['deposit']['enable'] === 'yes' && isset($cart_item['deposit']['percent_discount'])) {

                    $cart_items_deposit_amount -= $cart_item['deposit']['percent_discount'];
                }
            }
        }

        $deposit_amount += $deposit_fees + $deposit_shipping;

        // Deposit breakdown tooltip.
        $deposit_breakdown = array(
            'cart_items' => $cart_items_deposit_amount,
            //'fees' => $deposit_fees,
            //'taxes' => $deposit_taxes,
            //'shipping' => $deposit_shipping,
            //'shipping_taxes' => $deposit_shipping_taxes,
        );


        $discount_from_deposit = get_option('wc_deposits_coupons_handling', 'second_payment');
        $discount_total = WC()->cart->get_cart_discount_total() + WC()->cart->get_cart_discount_tax_total();
        $remaining_amounts['discounts'] = 0.0;
        if ($discount_from_deposit === 'deposit') {

            if ($discount_total > $deposit_amount || $discount_total == $deposit_amount) {

                // make sure the deposit is not negative
                $remaining_amounts['discounts'] = $discount_total - $deposit_amount;
                $deposit_amount = 0.0;
            } else {
                //whole discount taken from deposit;
                $deposit_amount -= $discount_total;
            }

        } elseif ($discount_from_deposit === 'split') {
            $discount_deposit = $discount_total / 100 * $deposit_percentage;
            $deposit_amount -= $discount_deposit;
            $remaining_amounts['discounts'] = $discount_total - $discount_deposit;

        }


        //round decimals according to woocommerce
        $deposit_amount = round($deposit_amount, wc_get_price_decimals());

        $deposit_amount = apply_filters('woocommerce_deposits_cart_deposit_amount', $deposit_amount, $cart_total);


        // no point of having deposit if second payment as 0 or in negative
        if ($cart_total - $deposit_amount <= 0) {
            $deposit_enabled = false;
        }


//        if(!$deposit_enabled){
//            //do not display stripe payment request if there is deposit
//            add_filter('wc_stripe_show_payment_request_on_checkout','__return_false');
//        }

        WC()->cart->deposit_info = array();
        WC()->cart->deposit_info['deposit_enabled'] = $deposit_enabled;
        WC()->cart->deposit_info['deposit_breakdown'] = $deposit_breakdown;
        WC()->cart->deposit_info['deposit_amount'] = $deposit_amount;
        WC()->cart->deposit_info['has_payment_plans'] = $this->has_payment_plans;
        $payment_schedule = $this->build_payment_schedule($remaining_amounts, $deposit_amount,$cart_items_deposit_amount);

        WC()->cart->deposit_info['payment_schedule'] = $payment_schedule;

        return $cart_original;

    }

    /**
     * @brief Display Deposit and remaining amount in cart totals area
     */
    public
    function cart_totals_after_order_total()
    {

        if (isset(WC()->cart->deposit_info['deposit_enabled']) && WC()->cart->deposit_info['deposit_enabled'] === true) :


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
                <th><?php echo $to_pay_text ?>&nbsp;&nbsp;<?php echo $deposit_breakdown_tooltip; ?>
                </th>
                <td data-title="<?php echo $to_pay_text; ?>">
                    <strong><?php echo wc_price(WC()->cart->deposit_info['deposit_amount']); ?></strong></td>
            </tr>
            <tr class="order-remaining">
                <th><?php echo $future_payment_text; ?></th>
                <td data-title="<?php echo $future_payment_text; ?>">
                    <strong><?php echo wc_price(WC()->cart->get_total('edit') - WC()->cart->deposit_info['deposit_amount']); ?></strong>
                </td>
            </tr>
        <?php
        endif;
    }


    function cart_needs_payment($needs_payment, $cart)
    {


        if (wcdp_checkout_mode() && isset($_POST['post_data'])) {
            parse_str($_POST['post_data'], $post_data);
            if (isset($post_data['deposit-radio']) && $post_data['deposit-radio'] === 'deposit') {
                $deposit_enabled = true;
            } elseif (isset($post_data['deposit-radio']) && $post_data['deposit-radio'] === 'full') {
                $deposit_enabled = false;
            }
        }


        $deposit_enabled = isset(WC()->cart->deposit_info['deposit_enabled'], WC()->cart->deposit_info['deposit_amount'])
            && WC()->cart->deposit_info['deposit_enabled'] === true && WC()->cart->deposit_info['deposit_amount'] <= 0;


        if ($deposit_enabled) {
            $needs_payment = false;
        }
        return $needs_payment;

    }


}