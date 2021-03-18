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
 * @brief Adds UI elements to the order editor in the admin area
 *
 */
class WC_Deposits_Admin_Order
{
    /**
     * WC_Deposits_Admin_Order constructor.
     * @param $wc_deposits
     */
    public function __construct(&$wc_deposits)
    {
        if (!wcdp_checkout_mode()) {
            add_action('woocommerce_admin_order_item_headers', array($this, 'admin_order_item_headers'));
            add_action('woocommerce_admin_order_item_values', array($this, 'admin_order_item_values'), 10, 3);
        }

        // Hook the order admin page

        add_action('woocommerce_admin_order_totals_after_total', array($this, 'admin_order_totals_after_total'));
//        add_action('woocommerce_saved_order_items', array($this, 'saved_order_items'), 10, 2);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_wc_deposits_recalculate_deposit', array($this, 'recalculate_deposit_callback'));
        add_action('woocommerce_order_item_add_action_buttons', array($this, 'recalculate_deposit_button'));
        add_filter('woocommerce_order_actions', array($this, 'order_actions'));
        add_filter('woocommerce_resend_order_emails_available', array($this, 'resend_order_emails_available'));

        add_action('woocommerce_ajax_add_order_item_meta', array($this, 'add_order_item_meta'), 10, 2);
        add_action('wp_ajax_wc_deposits_reload_partial_payments_metabox', array($this, 'ajax_partial_payments_summary'), 10);
        add_action('wp_ajax_wc_deposits_get_recalculate_deposit_modal', array($this, 'get_recalculate_deposit_modal'), 10);

        add_action('add_meta_boxes', array($this, 'partial_payments_metabox'), 31);

        add_filter('request', array($this, 'request_query'));

        //reminder for after X days setting
        $second_payment_reminder = get_option('wc_deposits_enable_second_payment_reminder');

        add_action('woocommerce_order_action_customer_second_payment_reminder', array($this, 'customer_second_payment_reminder'));

        add_action('admin_footer', array($this, 'remove_statuses_for_partial_payment'));
        add_action('woocommerce_order_after_calculate_totals', array($this, 'totals_recalculated_callback'), 10, 2);

        add_action('woocommerce_process_shop_order_meta', array($this, 'process_payment_date_datepicker_values'));
    }

    function totals_recalculated_callback($and_taxes, $order)
    {


        $this->maybe_adjust_deposit_order_total($order);
    }


    function maybe_adjust_deposit_order_total($order)
    {


        $payment_schedule = $order->get_meta('_wc_deposits_payment_schedule', true);

        if (!empty($payment_schedule) && is_array($payment_schedule)) {
            $payment = null;
            $second_payment_order = null;
            $total = 0.0;

            $due_payments = array();
            $due_payments_total = 0.0;
            foreach ($payment_schedule as $payment) {

                $payment_order = wc_get_order($payment['id']);
                if ($payment['type'] !== 'deposit' && $payment_order->get_status() !== 'completed') {
                    $due_payments[] = $payment_order;
                    $due_payments_total += floatval($payment_order->get_total());

                }

                $total += floatval($payment_order->get_total());
            }

            $difference = floatval($order->get_total()) - $total;
            if ($difference > 0 || $difference < 0) {

                $positive = $difference > 0;
                //rounding fix
                $difference = abs($difference);
                $diff_record = $difference;
                $count = 0;

                foreach ($due_payments as $key => $due_payment) {
                    $count++;
                    //calculate percentage
                    $percentage = floatval($due_payment->get_total()) / $due_payments_total * 100;
                    $amount = $difference / 100 * $percentage;
                    if (count($due_payments) === $count) {
                        //last item
                        $amount = $diff_record;
                    } else {
                        $diff_record -= $amount;
                    }

                    if ($positive) {
                        foreach ($due_payment->get_fees() as $item) {
                            $item->set_total(floatval($item->get_total()) + $amount);
                            $item->save();
                        }

                    } else {

                        foreach ($due_payment->get_fees() as $item) {
                            $item->set_total(floatval($item->get_total()) - $amount);
                            $item->save();
                        }
                    }

                    $due_payment->calculate_totals(false);
                    $due_payment->save();

                }

                //update legacy meta
                $second_payment = $order->get_meta('_wc_deposits_second_payment', true);

                if ($positive) {
                    $second_payment += $difference;
                } else {
                    $second_payment -= $difference;

                }
                //update value

                $order->update_meta_data('_wc_deposits_second_payment', wc_format_decimal(floatval($second_payment)));
                $order->save();
            }

        }

    }

    function remove_statuses_for_partial_payment()
    {

        $partial_payment_editor = false;
        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if ($screen)
                $partial_payment_editor = $screen->id === 'wcdp_payment';
        }

        if ($partial_payment_editor) {

            ob_start(); ?>
            <script>
                jQuery(document).ready(function ($) {
                    $('select#order_status').find('option[value="wc-partially-paid"]').remove();
                    $('select#order_status').find('option[value="wc-processing"]').remove();
                })
            </script>
            <?php echo ob_get_clean();
        }

    }

    /**
     * @brief callback for recalculate deposit button ajax call
     */

    function recalculate_deposit_callback()
    {
        check_ajax_referer('order-item', 'security');

        if (!current_user_can('edit_shop_orders')) {
            wp_die(-1);
        }

        $order_id = isset($_POST['order_id']) && !empty($_POST['order_id']) ? sanitize_text_field($_POST['order_id']) : false;

        if (!$order_id) {
            wp_send_json_error();
            wp_die();
        }

        $order = wc_get_order($order_id);
        $items = $order->get_items();
        if (empty($items)) {
            wp_send_json_error();
            wp_die();
        }


        $recalculated_deposit = 0.0;
        $deposit_enabled = true;
        if (isset($_POST['order_items'])) {
            $form_data = $_POST['order_items'];

            if (wcdp_checkout_mode()) {

                $deposit_amount = $form_data['wc_deposits_deposit_amount_checkout_mode'];
                $amount_type = $form_data['wc_deposits_deposit_amount_type_checkout_mode'];
                $subtotal = $order->get_subtotal();
                if ($subtotal > 0) {

                    if ($amount_type === 'fixed') {
                        $recalculated_deposit = floatval($deposit_amount);
                    } elseif ($amount_type === 'percentage') {
                        $recalculated_deposit += floatval(($subtotal * $deposit_amount) / 100);

                    } else {

                        $selected_plan = $form_data['wc_deposits_payment_plan_checkout_mode'];
                        $deposit_percentage = get_term_meta($selected_plan, 'deposit_percentage', true);
                        $recalculated_deposit = ($order->get_subtotal() * $deposit_percentage) / 100;


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

                            foreach ($order->get_items() as $order_item) {
                                $price = $order_item->get_subtotal('edit') / 100 * $total_percentage;
                                $order_item->set_subtotal($price);
                                $order_item->set_total($price);
                                $order_item->save();
                            }
                            $order->calculate_totals();
                        }
                    }

                }

            } else {
                foreach ($order->get_items() as $order_item) {
                    //remove current order item meta for deposits
                    $order_item->delete_meta_data('wc_deposit_meta');
                    //scan through the submitted form data and build new meta

                    if (isset($form_data['wc_deposits_deposit_enabled_' . $order_item->get_id()]) && $form_data['wc_deposits_deposit_enabled_' . $order_item->get_id()] === 'yes') {

                        $item_amount = $form_data['wc_deposits_deposit_amount_' . $order_item->get_id()];
                        $item_amount_type = $form_data['wc_deposits_deposit_amount_type_' . $order_item->get_id()];
                        $schedule = array();

                        if ($item_amount_type === 'payment_plan') {
                            $selected_plan = $form_data['wc_deposits_payment_plan_' . $order_item->get_id()];
                            $plan_lines = get_term_meta($selected_plan, 'payment_details', true);
                            $plan_lines = json_decode($plan_lines, true);
                            if (!is_array($plan_lines)) {
                                continue;
                            }
                            $deposit_percentage = get_term_meta($selected_plan, 'deposit_percentage', true);
                            $item_deposit_amount = $order_item->get_subtotal('edit') / 100 * $deposit_percentage;

                            $total_percentage = floatval($deposit_percentage);
                            $payment_date = current_time('timestamp');
                            $price_total = floatval($item_deposit_amount);
                            $product = $order_item->get_product();
                            if (!$product) {
                                continue;
                            }

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
                                $schedule[$payment_date]['amount'] = ($product->get_price() / 100 * $plan_detail['percentage']);
                                $price_total += $schedule[$payment_date]['amount'];
                                //set the line item and product total to the amount totaled by payment plan percentage


                            }
                            if ($total_percentage !== 100) {
                                //payment plan total is not exactly 100% so order item SUBTOTAL is changed.
                                $order_item->read_meta_data();
                                $order_item->set_subtotal($price_total * $order_item->get_quantity());
                                $order_item->set_total($price_total * $order_item->get_quantity());
                                $order_item->save();
                            }


                        } elseif ($item_amount_type === 'percentage') {
                            $item_deposit_amount = floatval($order_item->get_subtotal('edit') / 100 * $item_amount);
                        } elseif ($item_amount_type === 'fixed') {
                            $item_deposit_amount = floatval($item_amount);
                        }
                        $deposit_meta['enable'] = 'yes';
                        $deposit_meta['deposit'] = $item_deposit_amount;
                        $deposit_meta['remaining'] = $order_item->get_subtotal('edit') - $item_deposit_amount;
                        $deposit_meta['total'] = $order_item->get_subtotal('edit');
                        if($schedule && is_array($schedule) && !empty($schedule)){
                        $deposit_meta['payment_schedule'] = $schedule;
                        }
                        $order_item->add_meta_data('wc_deposit_meta', $deposit_meta, true);
                        $order_item->save();

                        $recalculated_deposit += $item_deposit_amount;
                    } else {
                        $recalculated_deposit += $order_item->get_total('edit');
                    }


                }
            }
            $order->calculate_totals();
        } else {
            $deposit_enabled = false;
            wc_deposits_remove_order_deposit_data($order);
        }

        if ($deposit_enabled) {
            $cart_items_deposit = $recalculated_deposit;
            $fees_handling = get_option('wc_deposits_fees_handling');
            $taxes_handling = get_option('wc_deposits_taxes_handling');
            $shipping_handling = get_option('wc_deposits_shipping_handling');
            $shipping_taxes_handling = get_option('wc_deposits_shipping_taxes_handling');

            // Default option: collect fees with the second payment.
            $deposit_fees = 0.0;
            $deposit_taxes = 0.0;
            $deposit_shipping = 0.0;
            $deposit_shipping_taxes = 0.0;


            $deposit_percentage = $recalculated_deposit * 100 / floatval($order->get_subtotal());

            /*
             * Fees handling.
             */
            $total_fees = 0.0;

            foreach ($order->get_fees() as $fee) {

                $total_fees += floatval($fee->get_total());
            }

            switch ($fees_handling) {


                case 'deposit' :
                    $deposit_fees = $total_fees;
                    break;

                case 'split' :
                    $deposit_fees = $total_fees * $deposit_percentage / 100;
                    break;
            }

            /*
             * Taxes handling.
             */
            switch ($taxes_handling) {
                case 'deposit' :
                    $deposit_taxes = $order->get_total_tax() - $order->get_shipping_tax();
                    break;

                case 'split' :

                    $deposit_taxes = ($order->get_total_tax() - $order->get_shipping_tax()) * $deposit_percentage / 100;

                    break;
            }

            /*
             * Shipping handling.
             */
            switch ($shipping_handling) {
                case 'deposit' :
                    $deposit_shipping = $order->get_shipping_total();
                    break;

                case 'split' :
                    $deposit_shipping = $order->get_shipping_total() * $deposit_percentage / 100;
                    break;
            }

            /*
             * Shipping taxes handling.
             */
            switch ($shipping_taxes_handling) {
                case 'deposit' :
                    $deposit_shipping_taxes = $order->get_shipping_tax();

                    break;

                case 'split' :
                    $deposit_shipping_taxes = $order->get_shipping_tax() * $deposit_percentage / 100;
                    break;
            }

            // Deposit breakdown.
            $deposit_breakdown = array(
                'cart_items' => $cart_items_deposit,
                'fees' => $deposit_fees,
                'taxes' => $deposit_taxes,
                'shipping' => $deposit_shipping,
                'shipping_taxes' => $deposit_shipping_taxes,
            );

            // store new breakdown
            $order->update_meta_data('_wc_deposits_deposit_breakdown', $deposit_breakdown);

            // Add fees, taxes, shipping and shipping taxes to the deposit amount.
            $recalculated_deposit += $deposit_fees + $deposit_taxes + $deposit_shipping + $deposit_shipping_taxes;


            $discount_from_deposit = get_option('wc_deposits_coupons_handling', 'second_payment');
            if ($discount_from_deposit === 'deposit') {
                $discount_total = $order->get_total_discount();

                $recalculated_deposit -= $discount_total;
            } elseif ($discount_from_deposit === 'split') {

                $discount_deposit = $order->get_total_discount() / 100 * $deposit_percentage;

                $recalculated_deposit -= $discount_deposit;

            }
        }


        //end recalculate deposit
        $recalculated_deposit = round($recalculated_deposit, wc_get_price_decimals());
        if ($deposit_enabled && is_numeric($recalculated_deposit) && $recalculated_deposit < floatval($order->get_total())) {

            $order->update_meta_data('_wc_deposits_order_has_deposit', 'yes');

            //create schedule a nd partial payments
            wc_deposits_delete_current_schedule($order);
            $remaining_amounts = array();
            $remaining_amounts['fees'] = $total_fees - $deposit_fees;
            $remaining_amounts['taxes'] = ($order->get_total_tax() - $order->get_shipping_tax()) - $deposit_taxes;
            $remaining_amounts['shipping'] = $order->get_shipping_total() - $deposit_shipping;
            $remaining_amounts['shipping_taxes'] = $order->get_shipping_tax() - $deposit_shipping_taxes;

            $partial_payments_schedule = $this->build_payment_schedule($remaining_amounts, $order, $recalculated_deposit);
            $deposit_data = array(
                'id' => '',
                'title' => __('Deposit', 'woocommerce-deposits'),
                'type' => 'deposit',
                'total' => $recalculated_deposit,

            );
            $partial_payments_schedule = array('deposit' => $deposit_data) + $partial_payments_schedule;
            $schedule = wc_deposits_create_payment_schedule($order, $partial_payments_schedule);
            $order->update_meta_data('_wc_deposits_payment_schedule', $schedule);

            $second_payment = $order->get_total() - $recalculated_deposit;
            $order->update_meta_data('_wc_deposits_deposit_amount', wc_format_decimal($recalculated_deposit));
            $order->update_meta_data('_wc_deposits_second_payment', wc_format_decimal($second_payment));


        } else {

            wc_deposits_delete_current_schedule($order);
            $order->update_meta_data('_wc_deposits_order_has_deposit', 'no');
            $order->update_meta_data('_wc_deposits_deposit_amount', 0);
            $order->update_meta_data('_wc_deposits_second_payment', 0);

            $recalculated_deposit = 0;
            $second_payment = 0;

        }


        $order->save();


        ob_start();


        wp_send_json_success(array(
            'deposit_html' => wc_price($recalculated_deposit),
            'remaining_html' => wc_price($second_payment),
            'deposit_raw' => $recalculated_deposit,
            'remaining_raw' => $second_payment,
        ));
        wp_die();
    }

    private function build_payment_schedule($remaining_amounts, $order, $deposit)
    {


        /**   START BUILD PAYMENT SCHEDULE**/
        $schedule = array();
        $unlimited = array(
            'id' => '',
            'title' => __('Future Payments', 'woocommerce-deposits'),
            'type' => 'second_payment',
            'total' => 0.0,
        );
        $payment_date = current_time('timestamp');
        $second_payment_due_after = get_option('wc_deposits_second_payment_due_after', '');


        if (wcdp_checkout_mode()) {
            $form_data = $_POST['order_items'];
            $amount_type = $form_data['wc_deposits_deposit_amount_type_checkout_mode'];

            if ($amount_type === 'payment_plan') {
                $selected_plan = $form_data['wc_deposits_payment_plan_checkout_mode'];
                $payment_details = json_decode(get_term_meta($selected_plan, 'payment_details', true), true);
                if (is_array($payment_details) && isset($payment_details['payment-plan']) && !empty($payment_details['payment-plan'])) {

                    $deposit_percentage = get_term_meta($selected_plan, 'deposit_percentage', true);
                    $total_percentage = floatval($deposit_percentage);

                    foreach ($payment_details['payment-plan'] as $plan_id => $plan_detail) {
                        $total_percentage += floatval($plan_detail['percentage']);
                    }

                    $total = floatval($order->get_subtotal());

                    if ($total_percentage !== 100.0) {
                        $total = $total / $total_percentage * 100;
                    }

                    foreach ($payment_details['payment-plan'] as $single_payment) {


                        if (isset($single_payment['date']) && !empty($single_payment['date'])) {
                            $payment_date = strtotime($single_payment['date']);
                        } else {
                            $after = $single_payment['after'];
                            $after_term = $single_payment['after-term'];
                            $payment_date = strtotime(date('Y-m-d', $payment_date) . "+{$after} {$after_term}s");
                        }


                        $percentage = $single_payment['percentage'];
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
                    $schedule[$timestamp]['total'] = floatval($order->get_subtotal('edit') - $deposit);
                    if (!isset($schedule[$timestamp]['type'])) $schedule[$timestamp]['type'] = 'second_payment';

                } else {

                    $unlimited['total'] = floatval($order->get_subtotal('edit') - $deposit);
                    $unlimited['type'] = 'second_payment';
                }
            }


        } else {
            foreach ($order->get_items() as $key => $order_item) {
                $deposit_meta = $order_item->get_meta('wc_deposit_meta');

                //go through all items with deposit
                if (!empty($deposit_meta) && $deposit_meta['enable'] === 'yes' && isset($deposit_meta['deposit'])) {

                    if (isset($deposit_meta['payment_schedule'])) {

                        foreach ($deposit_meta['payment_schedule'] as $timestamp => $payment) {

                            if (!isset($schedule[$timestamp])) $schedule[$timestamp] = array('type' => 'partial_payment', 'total' => 0.0);
                            $schedule[$timestamp]['total'] += floatval($payment['amount']);
                        }

                    } else {

                        // simple deposit , build schedule based on due date if set
                        if (!empty($second_payment_due_after) && is_numeric($second_payment_due_after)) {

                            $timestamp = strtotime("+{$second_payment_due_after} days", current_time('timestamp'));
                            if (!isset($schedule[$timestamp])) $schedule[$timestamp] = array('total' => 0.0);
                            $schedule[$timestamp]['total'] += floatval($deposit_meta['remaining']);
                            if (!isset($schedule[$timestamp]['type'])) $schedule[$timestamp]['type'] = 'second_payment';

                        } else {

                            $unlimited['total'] += $deposit_meta['remaining'];
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
        foreach ($schedule as $payment_key => $payment) {
            $percentage = $payment['total'] / $schedule_total * 100;

            foreach ($remaining_amounts as $amount_key => $remaining_amount) {
                $schedule[$payment_key]['total'] += $remaining_amount / 100 * $percentage;
            }

        }

        return $schedule;
    }


    /**
     * @brief output recalculate deposit button
     * @param $order
     */
    function recalculate_deposit_button($order)
    {

        if (!$order->is_editable())
            return;

        $recalculate_deposit_msg = __('Are you sure? this action is irreversible.', 'woocommerce-deposits');


        ?>
        <button type="button" data-msg="<?php echo $recalculate_deposit_msg; ?>"
                data-order-id="<?php echo $order->get_id(); ?>"
                id="wcdp_recalculate_deposit"
                class="button button-primary"><?php _e('Recalculate Deposit', 'woocommerce-deposits'); ?></button>
        <?php wp_nonce_field('wcdp_recalculate_deposit_verify', 'wcdp_recalculate_deposit_field', true, true); ?>
        <script>
            <?php // this function needs to be inline for now because it wont work after ajax operations if left in file?>


            jQuery(document).ready(function ($) {
                // $('#wcdp_recalculate_deposit').off('click');
                var request = false;
                $('#wcdp_recalculate_deposit').on('click', function () {
                    if (request !== false) return false;
                    var btn = $(this);

                    $('#woocommerce-order-items').block({
                        message: null,
                        overlayCSS: {
                            background: '#fff',
                            opacity: 0.6
                        }
                    });


                    var data = {
                        action: 'wc_deposits_get_recalculate_deposit_modal',
                        order_id: $(this).data('order-id'),
                        security: $('#wcdp_recalculate_deposit_field').val()
                    };

                    request = $.ajax({
                        url: wc_deposits_data.ajax_url,
                        data: data,
                        type: 'POST',

                        success: function (response) {
                            $('#woocommerce-order-items').unblock();
                            if (response.success) {
                                delete window.wp.template.cache["wcdp-modal-recalculate-deposit"];
                                $('#tmpl-wcdp-modal-recalculate-deposit').html(response.data.html);
                                btn.WCBackboneModal({
                                    template: 'wcdp-modal-recalculate-deposit',
                                    events: {
                                        'click .modal-close': 'closeButton',
                                        'click .wcdp_recalculate_deposit_confirm': 'addButton',
                                        'touchstart .wcdp_recalculate_deposit_confirm': 'addButton',
                                    },
                                });

                                request = false;
                            } else {
                                alert(response.data);
                            }

                        }
                    });

                    return false;

                });


            });
        </script>
        <?php
    }

    /**
     *
     * @brief When a product is added via order management, this function checks if deposit is enabled and should be calculaed for this product
     * @param $item_id
     * @param $item
     */
    public function add_order_item_meta($item_id, $item)
    {

        $product = $item->get_product();

        $default_checked = get_option('wc_deposits_default_option', 'deposit');

        //if plugin is in checkout mode return
        if (wcdp_checkout_mode() || $default_checked === 'full')
            return;

        if (wc_deposits_is_product_deposit_enabled($product->get_id())) {
            $deposit = wc_deposits_calculate_product_deposit($product);

            $woocommerce_prices_include_tax = get_option('woocommerce_prices_include_tax');

            if ($woocommerce_prices_include_tax === 'yes') {

                $amount = wc_get_price_including_tax($product);

            } else {
                $amount = wc_get_price_excluding_tax($product);

            }
            $deposit = $deposit * $item->get_quantity();
            $amount = $amount * $item->get_quantity();

            if ($deposit < $amount && $deposit > 0) {

                $deposit_meta['enable'] = 'yes';
                $deposit_meta['deposit'] = $deposit;
                $deposit_meta['remaining'] = $amount - $deposit;
                $deposit_meta['total'] = $amount;
                $item->add_meta_data('wc_deposit_meta', $deposit_meta, true);
                $item->save();


            }
        }

    }

    /**
     *
     * @param $order
     */
    function customer_second_payment_reminder($order)
    {
        do_action('woocommerce_before_resend_order_emails', $order, 'second_payment_reminder');

        // Send reminder email
        do_action('woocommerce_deposits_second_payment_reminder_email', $order->get_id());

        // Note the event.
        $order->add_order_note(__('Partial Payment reminder email manually sent to customer.', 'woocommerce-deposits'), false, true);

        do_action('woocommerce_after_resend_order_email', $order, 'second_payment_reminder');


    }


    /**
     * @param $emails_available
     * @return array
     */
    public
    function resend_order_emails_available($emails_available)
    {

        $emails_available[] = 'customer_partially_paid';
        $emails_available[] = 'customer_second_payment_reminder';

        return $emails_available;
    }


    /**
     * @param $emails_available
     * @return mixed
     */
    public
    function order_actions($emails_available)
    {

        global $post;

        $order = wc_get_order($post->ID);

        if (!$order) return $emails_available;

        if ($order->get_type() === 'wcdp_payment') return $emails_available;
        $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true);

        if ($order_has_deposit === 'yes') {
            $emails_available['customer_second_payment_reminder'] = __('Email Partial Payment Reminder', 'woocommerce-deposits');

        }

        return $emails_available;
    }


    /**
     * @brief enqueue scripts
     */
    function enqueue_scripts()
    {


        $is_order_editor = false;

        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if ($screen)
                $is_order_editor = $screen->id === 'shop_order';
        }

        if ($is_order_editor) {

            $order_id = isset($_GET['post']) && !empty($_GET['post']) ? $_GET['post'] : false;
            $order = $order_id ? wc_get_order($order_id) : false;
            $original_total = $order ? wc_format_localized_price($order->get_meta('_wc_deposits_original_total', true)) : null;


            wp_enqueue_script('jquery.bind-first', WC_DEPOSITS_PLUGIN_URL . '/assets/js/jquery.bind-first-0.2.3.min.js');
            wp_enqueue_script('wc-deposits-admin-orders', WC_DEPOSITS_PLUGIN_URL . '/assets/js/admin/admin-orders.js', array('jquery', 'wc-admin-order-meta-boxes'), false, true);
            wp_localize_script('wc-deposits-admin-orders', 'wc_deposits_data',
                array('decimal_separator' => wc_get_price_decimal_separator(),
                    'thousand_separator' => wc_get_price_thousand_separator(),
                    'number_of_decimals' => wc_get_price_decimals(),
                    'currency_symbol' => get_woocommerce_currency_symbol(),
                    'original_total' => $original_total,
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'security' => wp_create_nonce('wc-deposits-partial-payments-refresh'),

                ));
        }
    }

    /**
     *
     */
    public
    function admin_order_item_headers()
    {

        if (is_ajax()) {
            $order_id = isset($_POST['order_id']) ? $_POST['order_id'] : false;
        } else {
            global $post;
            $order_id = $post ? $post->ID : false;
        }
        if (!$order_id) return;
        $order = wc_get_order($order_id);

        if (!$order || $order->get_type() === 'wcdp_payment') return;


        ?>
        <th class="deposit-paid"><?php _e('Deposit', 'woocommerce-deposits'); ?></th>
        <th class="deposit-remaining"><?php _e('Future Payments', 'woocommerce-deposits'); ?></th>
        <?php
    }

    /**
     * @brief controls order item values in order editor
     * @param $product
     * @param $item
     * @param $item_id
     */
    public
    function admin_order_item_values($product, $item, $item_id)
    {
        $deposit_meta = null;
        if ($product) {
            $deposit_meta = isset($item['wc_deposit_meta']) ? $item['wc_deposit_meta'] : null;

        }

        $order_id = wc_get_order_id_by_order_item_id($item_id);
        $order = wc_get_order($order_id);
        $paid = '';
        $remaining = '';
        $price_args = array();
        if ($order) {
            $price_args = array('currency', $order->get_currency());
        }


        if ($product && isset($deposit_meta) && $deposit_meta['enable'] === 'yes') {
            $item_meta = maybe_unserialize($item['wc_deposit_meta']);
            if (is_array($item_meta) && isset($item_meta['deposit']))
                $paid = $item_meta['deposit'];
            if (is_array($item_meta) && isset($item_meta['remaining']))
                $remaining = $item_meta['remaining'];

        }
        ?>
        <td class="deposit-paid" width="1%">
            <div class="view">
                <?php
                if ($paid)
                    echo wc_price($paid, $price_args);
                ?>
            </div>
            <?php if ($product) { ?>
                <div class="edit" style="display: none;">
                    <input type="text" name="deposit_paid[<?php echo absint($item_id); ?>]"
                           placeholder="<?php echo wc_format_localized_price(0); ?>" value="<?php echo $paid; ?>"
                           class="deposit_paid wc_input_price" data-total="<?php echo $paid; ?>"/>
                </div>
            <?php } ?>
        </td>
        <td class="deposit-remaining" width="1%">
            <div class="view">
                <?php
                if ($remaining)
                    echo wc_price($remaining, $price_args);
                ?>
            </div>
            <?php if ($product) { ?>
                <div class="edit" style="display: none;">
                    <input type="text" disabled="disabled" name="deposit_remaining[<?php echo absint($item_id); ?>]"
                           placeholder="<?php echo wc_format_localized_price(0); ?>" value="<?php echo $remaining; ?>"
                           class="deposit_remaining wc_input_price" data-total="<?php echo $remaining; ?>"/>
                </div>
            <?php } ?>
        </td>
        <?php
    }

    /**
     * @brief controls order totals in order editor
     * @param $order_id
     */
    public
    function admin_order_totals_after_total($order_id)
    {
        $order = wc_get_order($order_id);
        if ($order->get_type() === 'wcdp_payment') return;
        $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true);
        if ($order_has_deposit !== 'yes') return;

        $deposit = $order->get_meta('_wc_deposits_deposit_amount', true);
        $second_payment = $order->get_meta('_wc_deposits_second_payment', true);

        //todo : show detailed deposit breakdown in tooltip
        //		$breakdown = $order->get_meta( '_wc_deposits_deposit_breakdown' , true );

        ?>
        <tr>
            <td class="label"><?php echo wc_help_tip(__('Note: Deposit amount is affected by settings for fees, taxes & shipping handling', 'woocommerce-deposits')); ?><?php _e('Deposit', 'woocommerce-deposits'); ?>
                :
            </td>
            <td width="1%"></td>
            <td class="total paid"><?php echo wc_price($deposit, array('currency' => $order->get_currency())); ?></td>

        </tr>


        <tr class="wcdp-remaining">
            <td class="label"><?php _e('Future Payments', 'woocommerce-deposits'); ?>:</td>
            <td width="1%"></td>
            <td class="total remaining"><?php echo wc_price($second_payment, array('currency' => $order->get_currency())); ?></td>
        </tr> <?php
    }

    /**
     * @brief modify deposit when order is saved from  admin order editor
     * @param $order_id
     * @param $items
     * @throws \WC_Data_Exception
     */
    public function saved_order_items($order_id, $items)
    {


        if (!is_ajax()) return;

        $order = wc_get_order($order_id);
        if (!$order) return;
        $order->read_meta_data(true);

        if (isset($items['order_item_id']) && $_POST['action'] === 'woocommerce_save_order_items') {

            $deposit_paid = isset($items['deposit_paid']) ? $items['deposit_paid'] : array();
            foreach ($items['order_item_id'] as $item_id) {

                $item = $order->get_item($item_id);
                $meta = array();
                $paid = isset($deposit_paid[$item_id]) ? floatval(wc_format_decimal($deposit_paid[$item_id])) : null;
                $total = $item->get_total();

                if ($paid !== null && floatval($paid) >= 0 && floatval($paid) <= floatval($total)) {
                    $meta['deposit'] = floatval($paid);
                    $meta['remaining'] = floatval($total) - floatval($paid);
                }
                if ($paid !== null && $paid > 0) {
                    $meta['enable'] = 'yes';
                } else {
                    $meta['enable'] = 'no';
                }
                $item->update_meta_data('wc_deposit_meta', $meta);
                $item->save();
            }
        }

        $deposit_amount = $_POST['action'] === 'woocommerce_save_order_items' && isset($items['_order_deposit']) ? floatval(wc_format_decimal($items['_order_deposit'])) : floatval(wc_format_decimal($order->get_meta('_wc_deposits_deposit_amount', true)));
        $original_total = $order->get_total();
        $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true);


        if (isset($items['_order_deposit'])) {

            if ($deposit_amount > 0 && $deposit_amount < $original_total) {


                $order->update_meta_data('_wc_deposits_order_has_deposit', 'yes');
                $order_has_deposit = 'yes';

            } else {

                $order->update_meta_data('_wc_deposits_order_has_deposit', 'no');
                $order_has_deposit = 'no';
            }


        }

        $second_payment = $original_total - $deposit_amount;


        if ($deposit_amount > $original_total) {

            $deposit_amount = $original_total;
            $second_payment = 0;
            $order_has_deposit = 'no';
        }


        if ($order_has_deposit === 'yes') {


            //create schedule and partial payments
            wc_deposits_delete_current_schedule($order);
            $schedule = wc_deposits_create_payment_schedule($order, $deposit_amount);

            if ($order->meta_exists('_wc_deposits_payment_schedule')) {

                $order->update_meta_data('_wc_deposits_payment_schedule', $schedule);
            } else {
                $order->add_meta_data('_wc_deposits_payment_schedule', $schedule, true);

            }

            $order->update_meta_data('_wc_deposits_deposit_amount', wc_format_decimal($deposit_amount));
            $order->update_meta_data('_wc_deposits_second_payment', wc_format_decimal($second_payment));
            $order->save();

        } else {

            //create schedule and partial payments
            wc_deposits_delete_current_schedule($order);

            $order->update_meta_data('_wc_deposits_deposit_amount', wc_format_decimal(0));
            $order->update_meta_data('_wc_deposits_second_payment', wc_format_decimal(0));
            $order->save();

        }


    }


    function original_order_metabox()
    {
        global $post;
        $order = wc_get_order($post->ID);
        if (!$order) return;

        $parent = wc_get_order($order->get_parent_id());

        if (!$parent) return;

        ?>
        <p><?php echo sprintf(__('This is a partial payment for order %s', 'woocommerce-deposits'), $parent->get_order_number()); ?>
        </p>
        <a class="button btn" href="
                  <?php echo esc_url($parent->get_edit_order_url()); ?> "> <?php _e('View', 'woocommerce-deposits'); ?> </a>
        <?php
        $payment_date = $order->get_meta('_wc_deposits_partial_payment_date', true);

        ?>
        <hr/>
        <label for="partial_payment_datepicker">

            <?php _e('Due date:', 'woocommerce-deposits') ?>
        </label>
        <input value="<?php echo is_numeric($payment_date) ? date('Y-m-d', $payment_date) : ''; ?>"
               type="date" name="wc_deposits_partial_payment_date" id="partial_payment_datepicker"/>
        <?php
    }

    function partial_payments_metabox()
    {
        global $post;
        if (is_null($post)) return;
        $order = wc_get_order($post->ID);
        if ($order) {

            if ($order->get_type() === 'wcdp_payment') {

                add_meta_box('wc_deposits_partial_payments',
                    __('Partial Payments', 'woocommerce-deposits'),
                    array($this, 'original_order_metabox'),
                    'wcdp_payment',
                    'side',
                    'high'
                );


            } else {
                $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true) === 'yes';

                if ($order_has_deposit || $order->is_editable()) {
                    add_meta_box('wc_deposits_partial_payments',
                        __('Partial Payments', 'woocommerce-deposits'),
                        array($this, 'partial_payments_summary'),
                        'shop_order',
                        'normal',
                        'high');

                }
            }

        }
    }


    function request_query($query_vars)
    {

        if (isset($query_vars['post_type']) && $query_vars['post_type'] === 'wcdp_payment') {
            // Status.
            if (empty($query_vars['post_status'])) {
                $post_statuses = wc_get_order_statuses();

                foreach ($post_statuses as $status => $value) {
                    if (isset($wp_post_statuses[$status]) && false === $wp_post_statuses[$status]->show_in_admin_all_list) {
                        unset($post_statuses[$status]);
                    }
                }

                $query_vars['post_status'] = array_keys($post_statuses);
            }
        }
        return $query_vars;

    }


    function ajax_partial_payments_summary()
    {

        check_ajax_referer('wc-deposits-partial-payments-refresh', 'security');
        if (!current_user_can('edit_shop_orders')) {
            wp_die(-1);
        }

        $order_id = absint($_POST['order_id']);
        $order = wc_get_order($order_id);


        if ($order) {
            ob_start();
            include('views/edit-order-partial-payments.php');
            $html = ob_get_clean();
            wp_send_json_success(array('html' => $html));

        }

        wp_die();

    }

    function get_recalculate_deposit_modal()
    {
        error_log('test');
        check_ajax_referer('wcdp_recalculate_deposit_verify', 'security');
        if (!current_user_can('edit_shop_orders')) {
            wp_die(-1);
        }

        $order_id = absint($_POST['order_id']);
        $order = wc_get_order($order_id);


        if ($order) {
            if ($order->get_status() === 'draft' || $order->get_status() === 'auto-draft') {

                wp_send_json_error(__('Please save order before calculating deposit.'));
                wp_die();
            }


            ob_start();
            include('views/order-recalculate-deposit-modal.php');
            $html = ob_get_clean();
            wp_send_json_success(array('html' => $html));

        }

        wp_die();

    }


    function partial_payments_summary()
    {

        global $post;
        $order = wc_get_order($post->ID);

        include('views/edit-order-partial-payments.php');

    }

    function process_payment_date_datepicker_values($post_id)
    {

        $partial_payment = wc_get_order($post_id);
        if (!$partial_payment || $partial_payment->get_type() !== 'wcdp_payment') return;

        //custom reminder date
        $partial_payment_date = isset($_POST['wc_deposits_partial_payment_date']) ? $_POST['wc_deposits_partial_payment_date'] : '';

        if (!empty($partial_payment_date)) {
            $new_timestamp = strtotime($partial_payment_date);
            $parent = wc_get_order($partial_payment->get_parent_id());
            $payment_schedule = $parent->get_meta('_wc_deposits_payment_schedule', true);

            if (isset($payment_schedule[$new_timestamp])) {

                if ($payment_schedule[$new_timestamp]['id'] !== $partial_payment->get_id()) {
                    wp_die(sprintf(__('Error saving. A payment with this date already exist. <a href="%s"> Return </a>', 'woocommerce-deposits'), $partial_payment->get_edit_order_url()));
                }

            } else {

                foreach ($payment_schedule as $key => $single_payment) {
                    if ($partial_payment->get_id() == $single_payment['id']) {
                        $payment_schedule[$new_timestamp] = $single_payment;
                        unset($payment_schedule[$key]);
                    }
                }

                $timestamps = array();

                foreach (array_keys($payment_schedule) as $key => $node) {
                    $timestamps[$key] = $node;
                }
//                array_multisort($timestamps, SORT_ASC, array_keys($payment_schedule));

                $sorted_schedule = array();
                foreach ($timestamps as $timestamp) {

                    $sorted_schedule[$timestamp] = $payment_schedule[$timestamp];
                }

                $parent->update_meta_data('_wc_deposits_payment_schedule', $sorted_schedule);
                $parent->save();

                $partial_payment->update_meta_data('_wc_deposits_partial_payment_date', $timestamp);
                $partial_payment->save();
            }


        }

    }


}
