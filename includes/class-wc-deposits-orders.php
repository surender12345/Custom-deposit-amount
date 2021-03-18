<?php
/*Copyright: © 2017 Webtomizer.
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/
namespace Webtomizer\WCDP;
use WC_Order , WC_Order_Item_Fee , WC_Geolocation , WP_Error , WCDP_Payment;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}


/**
 * Class WC_Deposits_Orders
 */
class WC_Deposits_Orders
{


    /**
     * WC_Deposits_Orders constructor.
     * @param $wc_deposits
     */
    public function __construct(&$wc_deposits)
    {

        $this->wc_deposits = $wc_deposits;


        // Payment complete events
        add_action('woocommerce_order_status_completed', array($this, 'order_status_completed'));
        add_action('woocommerce_order_status_processing', array($this, 'complete_partial_payments'));
        add_filter('woocommerce_payment_complete_reduce_order_stock', array($this, 'payment_complete_reduce_order_stock'), 10, 2);

        // Order statuses
        add_filter('wc_order_statuses', array($this, 'order_statuses'));
        add_filter('wc_order_is_editable', array($this, 'order_is_editable'), 10, 2);
        add_filter('woocommerce_valid_order_statuses_for_payment_complete', array($this, 'valid_order_statuses_for_payment_complete'), 10, 2);
        add_filter('woocommerce_order_has_status', array($this, 'order_has_status'), 10, 3);
        add_action('woocommerce_order_status_changed', array($this, 'order_status_changed'), 10, 3);
        add_filter('woocommerce_order_needs_payment', array($this, 'needs_payment'), 10, 3);

        add_action('before_woocommerce_pay', array($this, 'redirect_legacy_links'));
        // Order handling
        if (!wcdp_checkout_mode()) {
            add_action('woocommerce_new_order_item', array($this, 'add_order_item_meta'), 10, 3);
            add_filter('woocommerce_order_formatted_line_subtotal', array($this, 'order_formatted_line_subtotal'), 10, 3);
//            add_filter('woocommerce_order_amount_item_subtotal', array($this, 'order_amount_item_subtotal'), 10, 3);
        }

        add_filter('woocommerce_payment_complete_order_status', array($this, 'payment_complete_order_status'), 10, 2);


        add_filter('woocommerce_get_order_item_totals', array($this, 'get_order_item_totals'), 10, 2);

        add_filter('woocommerce_hidden_order_itemmeta', array($this, 'hidden_order_item_meta'));

        add_filter('woocommerce_get_checkout_payment_url', array($this, 'checkout_payment_url'), 10, 2);
        add_filter('woocommerce_create_order', array($this, 'create_order'), 10, 2);
        add_action('woocommerce_payment_complete', array($this, 'payment_complete'));


        add_action('woocommerce_thankyou', array($this, 'disable_order_again_for_partial_payments'), 0);
        add_action('woocommerce_order_details_after_order_table', array($this, 'output_myaccount_partial_payments_summary'));

        add_action('delete_post', array($this, 'delete_partial_payments'), 9);
        add_action('wp_trash_post', array($this, 'trash_partial_payments'));
        add_action('untrashed_post', array($this, 'untrash_partial_payments'));
        add_filter('woocommerce_cancel_unpaid_order',array($this,'cancel_partial_payments'),10,2);

        add_filter('pre_trash_post', array($this, 'prevent_user_trash_partial_payments'), 10, 2);
        add_filter('woocommerce_cod_process_payment_order_status', array($this, 'adjust_cod_status_completed'), 10, 2);
        add_action('woocommerce_order_status_partially-paid', 'wc_maybe_reduce_stock_levels');
        add_action('woocommerce_order_status_partially-paid', array($this, 'adjust_second_payment_status'));
        add_filter('woocommerce_order_status_on-hold', array($this, 'set_parent_order_on_hold'));
        add_filter('woocommerce_order_status_failed', array($this, 'set_parent_order_failed'));
        add_action('woocommerce_order_status_partially-paid', array($this, 'adjust_booking_status'));
        add_action('wc_deposits_thankyou', array($this, 'output_parent_order_summary'), 10);


        //
        add_filter('woocommerce_locate_template', array($this, 'locate_form_pay_wcdp'), 99, 3);
        add_filter('woocommerce_order_number', array($this, 'partial_payment_number'), 10, 2);

        //update coupon usage restriction
        add_action( 'woocommerce_order_status_partially-paid','wc_update_coupon_usage_counts' );

    }

    function complete_partial_payments($order_id){
        $order = wc_get_order($order_id);
        if (!$order) return;
        if ($order->get_type() === 'wcdp_payment') {
            $order->update_status('pending');
            $order->payment_complete();
            $order->save();
        }
    }

    function adjust_second_payment_status($order_id)
    {

        $order = wc_get_order($order_id);

        if (!$order) return;
        $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true);

        if ($order->get_type() !== 'wcdp_payment' && $order_has_deposit === 'yes') {

            $payment_schedule = $order->get_meta('_wc_deposits_payment_schedule', true);

            if (!is_array($payment_schedule) || empty($payment_schedule)) return;

            foreach ($payment_schedule as $payment) {

                // search for second payment and set it to pending
                if (isset($payment['id']) && isset($payment['type']) && $payment['type'] !== 'deposit') {

                    $second_payment = wc_get_order($payment['id']);
                    if ($second_payment && !$second_payment->needs_payment()) {
                        $second_payment->set_status('pending');
                        $second_payment->save();
                    }
                }
            }
        }
    }


    /**
     * @brief allow overriding form-pay.php template to display original order details during partial payment
     * @param $template
     * @param $template_name
     * @param $template_path
     * @return string
     */
    function locate_form_pay_wcdp($template, $template_name, $template_path)
    {

        if ($template_name === 'checkout/form-pay.php' && get_option('wc_deposits_override_payment_form', 'no') === 'yes') {

            global $wp;
            $order_id = $wp->query_vars['order-pay'];
            $order = wc_get_order($order_id);
            if (!$order) return $template;

            if ($order->get_type() === 'wcdp_payment') {
                $template = WC_DEPOSITS_TEMPLATE_PATH . '/checkout/form-pay.php';
            }
        }


        return $template;

    }

    /**
     * @brief woocommerce bookings compatibility , set bookings to partially-paid when deposit is paid
     */
    function adjust_booking_status($order_id)
    {
        if (method_exists('WC_Booking_Data_Store', 'get_booking_ids_from_order_id')) {
            $booking_ids = \WC_Booking_Data_Store::get_booking_ids_from_order_id($order_id);
            if (is_array($booking_ids) && !empty($booking_ids)) {
                foreach ($booking_ids as $booking_id) {
                    $booking = new \WC_Booking($booking_id);
                    $booking->set_status('wc-partial-payment');
                    $booking->save();
                }
            }
        }

    }


    function set_parent_order_failed($order_id)
    {
        $order = wc_get_order($order_id);
        if ($order && $order->get_type() === 'wcdp_payment' && `$order->get_meta('_wc_deposits_payment_type', true) === 'deposit'`) {
            $parent = wc_get_order($order->get_parent_id());
            if ($parent) {

                $parent->update_status('failed');
                $parent->save();
            }
        }
    }


    function set_parent_order_on_hold($order_id)
    {
        $order = wc_get_order($order_id);
        if ($order && $order->get_type() === 'wcdp_payment') {
            $parent = wc_get_order($order->get_parent_id());
            if ($parent) {

                // if child order payment method is bacs,  apply it to parent to send the right email instructions
                if ($order->get_payment_method() === 'bacs') {
                    $parent->set_payment_method('bacs');
                }

                $parent->set_status('on-hold');
                $parent->save();
            }
        }
    }

    function adjust_cod_status_completed($status, $order)
    {

        if ($order->get_type() === 'wcdp_payment') {

            $status = 'on-hold';
        }
        return $status;
    }

    function cancel_partial_payments($cancel, $order)
    {
        if ($order->get_type() === 'wcdp_payment') return false;

        return $cancel;

    }

    function prevent_user_trash_partial_payments($trash, $post)
    {


        if (is_object($post) && $post->post_type === 'wcdp_payment') {

            $order = wc_get_order($post->ID);
            if ($order) {
                $parent = wc_get_order($order->get_parent_id());
                if ($parent && $parent->get_status() !== 'trash') {
                    return 'forbidden'; //if value is not null , partial payment won't be trashed
                }
            }
        }

        return $trash;
    }

    function untrash_partial_payments($id)
    {

        if (!$id) {
            return;
        }

        $post_type = get_post_type($id);

        if ($post_type === 'shop_order') {

            $order = wc_get_order($id);
            if (!$order) return;
            $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true);
            if ($order->get_type() !== 'wcdp_payment' && $order_has_deposit === 'yes') {

                $payment_schedule = $order->get_meta('_wc_deposits_payment_schedule', true);

                if (!is_array($payment_schedule) || empty($payment_schedule)) return;

                foreach ($payment_schedule as $payment) {

                    if (isset($payment['id']) && is_numeric($payment['id'])) {

                        wp_untrash_post($payment['id']);
                    }
                }
            }
        }

    }

    function trash_partial_payments($id)
    {

        if (!current_user_can('delete_posts') || !$id) {
            return;
        }

        $post_type = get_post_type($id);

        if ($post_type === 'shop_order') {

            $order = wc_get_order($id);
            if (!$order) return;
            $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true);
            if ($order->get_type() !== 'wcdp_payment' && $order_has_deposit === 'yes') {

                $payment_schedule = $order->get_meta('_wc_deposits_payment_schedule', true);

                if (!is_array($payment_schedule) || empty($payment_schedule)) return;

                //temporarily remove filter
                remove_filter('pre_trash_post', array($this, 'prevent_user_trash_partial_payments'), 10);
                foreach ($payment_schedule as $payment) {

                    if (isset($payment['id']) && is_numeric($payment['id'])) {

                        wp_trash_post(absint($payment['id']));
                    }
                }
                add_filter('pre_trash_post', array($this, 'prevent_user_trash_partial_payments'), 10, 2);

            }
        }

    }

    function delete_partial_payments($id)
    {

        if (!current_user_can('delete_posts') || !$id) {
            return;
        }

        $post_type = get_post_type($id);

        if ($post_type === 'shop_order') {

            $order = wc_get_order($id);
            if (!$order) return;
            $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true);
            if ($order->get_type() !== 'wcdp_payment' && $order_has_deposit === 'yes') {

                $payment_schedule = $order->get_meta('_wc_deposits_payment_schedule', true);

                if (!is_array($payment_schedule) || empty($payment_schedule)) return;

                foreach ($payment_schedule as $payment) {

                    if (isset($payment['id']) && is_numeric($payment['id'])) {

                        wp_delete_post(absint($payment['id']), true);
                    }
                }
            }
        }

    }

    function disable_order_again_for_partial_payments($order_id)
    {
        // replace


        $order = wc_get_order($order_id);
        if ($order && $order->get_type() === 'wcdp_payment') {

            remove_action('woocommerce_thankyou', 'woocommerce_order_details_table', 10);
            do_action('wc_deposits_thankyou', $order);
            remove_action('woocommerce_order_details_after_order_table', 'woocommerce_order_again_button');
        }

    }

    function output_myaccount_partial_payments_summary($order){
        $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true);

        if (is_account_page() && $order_has_deposit === 'yes' && apply_filters('`wc_deposits_myaccount_show_partial_payments_summary`', true, $order)) {

            $payment_schedule = $order->get_meta('_wc_deposits_payment_schedule', true);
            if (!is_array($payment_schedule)) return;
            wc_get_template(
                'order/wc-deposits-partial-payments-summary.php', array(
                'order_id' => $order->get_id(),
                'schedule' => $payment_schedule
            ),
                '',
                WC_DEPOSITS_TEMPLATE_PATH
            );
        }

    }

    function output_parent_order_summary($partial_payment)
    {


        if ($partial_payment->get_type() === 'wcdp_payment') {
            wc_get_template('order/wc-deposits-order-summary.php', array('partial_payment' => $partial_payment, 'order_id' => $partial_payment->get_parent_id()), '', WC_DEPOSITS_TEMPLATE_PATH);
        }


    }

    function checkout_payment_url($url, $order)
    {

        $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true);
        if ($order_has_deposit === 'yes' && $order->get_type() !== 'wcdp_payment') {

            $payment_schedule = $order->get_meta('_wc_deposits_payment_schedule', true);

            if (!empty($payment_schedule)) {


                foreach ($payment_schedule as $payment) {
                    $payment_order = wc_get_order($payment['id']);

                    if (!$payment_order) continue;//create one


                    if (!$payment_order || !$payment_order->needs_payment()) {
                        continue;
                    }

                    $url = $payment_order->get_checkout_payment_url();
                    $url = add_query_arg(
                        array(
                            'payment' => $payment['type'],
                        ), $url
                    );

                    //already reached a payable payment
                    break;
                }


            }

        }

        return $url;
    }

    function payment_complete($order_id)
    {

        $order = wc_get_order($order_id);

        if (!$order || $order->get_type() !== 'wcdp_payment') return;

        $parent_id = $order->get_parent_id();
        $parent = wc_get_order($parent_id);

        if (!$parent) return;

        if ($order->get_meta('_wc_deposits_payment_type', true) === 'deposit') {

            $parent->update_meta_data('_wc_deposits_deposit_paid', 'yes');

        } elseif ($order->get_meta('_wc_deposits_payment_type', true) === 'second_payment') {
            $parent->update_meta_data('_wc_deposits_second_payment_paid', 'yes');

        }
        $parent->save();

        $parent->payment_complete();

    }


    function redirect_legacy_links()
    {
        global $wp;
        if (!empty($wp->query_vars['order-pay'])) {
            $order_id = absint($wp->query_vars['order-pay']);
            $order = wc_get_order($order_id);
            if (!$order) return;
            $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true);

            if ($order && $order->needs_payment() && $order->get_type() !== 'wcdp_payment' && $order_has_deposit === 'yes') {

                wp_redirect($order->get_checkout_payment_url());
                exit;
            }
        }
    }


    /**
     * @brief filters whether order can be paid for, based on second payment settings
     * @param $needs_payment
     * @param $order
     * @param $valid_statuses
     * @return bool
     */
    public function needs_payment($needs_payment, $order, $valid_statuses)
    {

        $status = $order->get_status();

        if(is_checkout_pay_page()  && $order->get_type() === 'wcdp_payment' &&  $order->get_meta('_wc_deposits_payment_type', true) !== 'deposit' ){

            try {
                $parent = wc_get_order($order->get_parent_id());

                if($parent && $parent->get_status() !== 'partially-paid'){
                    if(did_action('before_woocommerce_pay') && !did_action('after_woocommerce_pay')){
                        $needs_payment = false;
                    }

                }
            } catch ( \Exception $e ) {
                wc_print_notice( $e->getMessage(), 'error' );
            }



        }


        if ($status === 'partially-paid') {
            if (get_option('wc_deposits_remaining_payable', 'yes') === 'yes') {

                $needs_payment = true;
            } else {
                $needs_payment = false;
            }
        }
        return $needs_payment;
    }


    /**
     * @brief hides deposit order item meta from frontend display
     * @param $hidden_meta
     * @return array
     */
    public function hidden_order_item_meta($hidden_meta)
    {

        $hidden_meta[] = 'wc_deposit_meta';

        return $hidden_meta;

    }

    /**
     * @brief update order meta based on order status change
     * @param $order_id
     * @param $old_status
     * @param $new_status
     * @throws \WC_Data_Exception
     * @throws \WC_Data_Exception
     */
    public function order_status_changed($order_id, $old_status, $new_status)
    {

        $order = wc_get_order($order_id);




        $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true);
        if ($order->get_type() !== 'wcdp_payment' && $order_has_deposit === 'yes') {

            $payment_schedule = $order->get_meta('_wc_deposits_payment_schedule', true);

            if (!is_array($payment_schedule) || empty($payment_schedule)) return;


            if ($old_status === 'trash') {


                foreach ($payment_schedule as $payment) {

                    if (isset($payment['id']) && is_numeric($payment['id'])) {

                        wp_untrash_post($payment['id']);
                    }
                }

            }

            $deposit_paid = $order->get_meta('_wc_deposits_deposit_paid', true);

            if ($new_status === 'partially-paid') {

                //manually mark deposit partial payment as completed
                foreach ($payment_schedule as $payment) {
                    if ($payment['type'] !== 'deposit') continue;
                    $partial_payment = wc_get_order($payment['id']);
                    if ($partial_payment && $partial_payment->get_status() !== 'completed') {
                        $partial_payment->set_status('completed');
                        $partial_payment->save();
                    }

                }


                $order->update_meta_data('_wc_deposits_deposit_paid', 'yes');
                $order->update_meta_data('_wc_deposits_second_payment_paid', 'no');
                $order->update_meta_data('_wc_deposits_deposit_payment_time', time());
                $order->update_meta_data('_wc_deposits_second_payment_reminder_email_sent', 'no');
                // if(isset($_POST['wcb2bsa_sales_agent']) && !empty($_POST['wcb2bsa_sales_agent'])){
                //     $user = get_user_by('login', $_POST['wcb2bsa_sales_agent']);
                //     update_post_meta( $order->id,'wcb2bsa_sales_agent',$user->ID );
                // }else{
                //     session_start();
                //     $user = get_user_by('login', $_SESSION['wcb2bsa_sales_agent']);
                //     update_post_meta( $order->id,'wcb2bsa_sales_agent',$user->ID );
                // }
               

            }


            //order marked processing /completed manually
            if ($old_status === 'partially-paid' && ($new_status === 'processing' || $new_status === 'completed') && $deposit_paid === 'yes') {

                $order->update_meta_data('_wc_deposits_deposit_paid', 'yes');
                $order->update_meta_data('_wc_deposits_second_payment_paid', 'yes');


                //manually mark deposit partial payment as completed
                foreach ($payment_schedule as $payment) {

                    $partial_payment = wc_get_order($payment['id']);
                    if ($partial_payment) {
                        $partial_payment->set_status('completed');
                        $partial_payment->save();
                    }

                }
            }

            $order->Save();

        }

    }


    /**
     * @brief update order meta when order is marked completed
     * @param $order_id
     * @throws \WC_Data_Exception
     */
    public function order_status_completed($order_id)
    {

        $order = wc_get_order($order_id);
        if (!$order) return;
        if ($order->get_type() === 'wcdp_payment') {
            //exclude manual editing of parent
            $partial_payment_editor = false;

            if (function_exists('get_current_screen')) {
                $screen = get_current_screen();
                if ($screen)
                    $partial_payment_editor = $screen->id === 'wcdp_payment';
            }

            if ($partial_payment_editor) {
                //make sure we are triggering this only when the partial payment is edited to avoid loop

                $parent = wc_get_order($order->get_parent_id());

                if (!$parent) return;


                if ($order->get_meta('_wc_deposits_payment_type', true) === 'deposit') {
                    $parent->update_meta_data('_wc_deposits_deposit_paid', 'yes');

                } elseif ($order->get_meta('_wc_deposits_payment_type', true) === 'second_payment') {
                    $parent->update_meta_data('_wc_deposits_second_payment_paid', 'yes');

                }
                $parent->save();

                $parent->payment_complete();

            }

        } else {
            $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true);


            if ($order_has_deposit === 'yes') {
                $payment_schedule = $order->get_meta('_wc_deposits_payment_schedule', true);

                if (is_array($payment_schedule)) {
                    foreach ($payment_schedule as $timestamp => $payment) {


                        $payment_order = wc_get_order($payment['id']);

                        if ($payment_order) {
                            $payment_order->set_status('completed');
                            $payment_order->save();
                        }
                    }
                }
                $order->update_meta_data('_wc_deposits_deposit_paid', 'yes');
                $order->update_meta_data('_wc_deposits_second_payment_paid', 'yes');

                $order->save();

            }
        }

    }


    /**
     * @brief returns the proper status for order completion
     * @param $new_status
     * @param $order_id
     * @return string
     */
    public function payment_complete_order_status($new_status, $order_id)
    {

        $order = wc_get_order($order_id);

        if ($order) {


            $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true) === 'yes';

            if ($order_has_deposit) {
                //check if all payments are done before allowing default transition
                $payment_schedule = $order->get_meta('_wc_deposits_payment_schedule', true);

                if (!is_array($payment_schedule) || empty($payment_schedule)) return $new_status;
                $all_payments_made = true;
                foreach ($payment_schedule as $payment) {

                    $payment_order = wc_get_order($payment['id']);
                    if ($payment_order && $payment_order->get_status() !== 'completed') {
                        $all_payments_made = false;
                        break;
                    }
                }

                if (!$all_payments_made) {

                    $new_status = 'partially-paid';
                }


            }
        }


        return $new_status;
    }


    /**
     * @brief handle stock reduction on payment completion
     * @param $reduce
     * @param $order_id
     * @return bool
     */
    public
    function payment_complete_reduce_order_stock($reduce, $order_id)
    {
        $order = wc_get_order($order_id);

        if ($order->get_type() === 'wcdp_payment') return false;


        $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true) === 'yes';

        if ($order_has_deposit) {


            $status = $order->get_status();
            $reduce_on = get_option('wc_deposits_reduce_stock', 'full');

            if ($status === 'partially-paid' && $reduce_on === 'full') {
                $reduce = false;
            } elseif ($status === 'processing' && $reduce_on === 'deposit') {
                $reduce = false;
            }

        }


        return $reduce;
    }


    /**
     * @param $editable
     * @param $order
     * @return bool
     */
    public
    function order_is_editable($editable, $order)
    {


        if ($order->has_status('partially-paid')) {
            $allow_edit = get_option('wc_deposits_partially_paid_orders_editable', 'no') === 'yes';

            if ($allow_edit) {
                $editable = true;

            } else {

                $editable = false;

            }
        }
        return $editable;
    }


    /**
     * @param $statuses
     * @param $order
     * @return array
     */
    public
    function valid_order_statuses_for_payment_complete($statuses, $order)
    {


        if ($order->get_type() !== 'wcdp_payment' && get_option('wc_deposits_remaining_payable', 'yes') === 'yes') {
            $statuses[] = 'partially-paid';
        }
        return $statuses;
    }

    /**
     * @brief Add the new 'Deposit paid' status to orders
     *
     * @return array
     */
    public
    function order_statuses($order_statuses)
    {
        $new_statuses = array();
        // Place the new status after 'Pending payment'
        foreach ($order_statuses as $key => $value) {
            $new_statuses[$key] = $value;
            if ($key === 'wc-pending') {
                $new_statuses['wc-partially-paid'] = __('Partially Paid', 'woocommerce-deposits');
            }
        }
        return $new_statuses;
    }

    /**
     * @brief adds the status partially-paid to woocommerce
     * @param $has_status
     * @param $order
     * @param $status
     * @return bool
     */
    public
    function order_has_status($has_status, $order, $status)
    {
        if ($order->get_status() === 'partially-paid') {
            if (is_array($status)) {
                if (in_array('pending', $status)) {
                    $has_status = true;
                }
            } else {
                if ($status === 'pending') {
                    $has_status = true;
                }
            }
        }
        return $has_status;
    }

    /**
     * @brief adds deposit values to order item meta from cart item meta
     * @param $item_id
     * @param $item
     * @param $order_id
     */
    public
    function add_order_item_meta($item_id, $item, $order_id)
    {

        if (is_array($item) && isset($item['deposit'])) {
            wc_add_order_item_meta($item_id, '_wc_deposit_meta', $item['deposit']);
        }
    }

    /**
     * @brief handles the display of order item totals in pay for order , my account  and email templates
     * @param $total_rows
     * @param $order
     * @return mixed
     */
    public
    function get_order_item_totals($total_rows, $order)
    {


        $order_has_deposit = $order->get_meta('_wc_deposits_order_has_deposit', true) === 'yes';


        if ($order_has_deposit)  :

            $to_pay_text = __(get_option('wc_deposits_to_pay_text'), 'woocommerce-deposits');
            $deposit_amount_text = __(get_option('wc_deposits_deposit_amount_text'), 'woocommerce-deposits');
            $future_payment_amount_text = __(get_option('wc_deposits_second_payment_text'), 'woocommerce-deposits');

            if ($to_pay_text === false) {
                $to_pay_text = __('To Pay', 'woocommerce-deposits');
            }

            if ($deposit_amount_text === false) {
                $deposit_amount_text = __('Deposit Amount', 'woocommerce-deposits');
            }
            if ($future_payment_amount_text === false) {
                $future_payment_amount_text = __('Future Payments', 'woocommerce-deposits');
            }

            $to_pay_text = stripslashes($to_pay_text);
            $deposit_amount_text = stripslashes($deposit_amount_text);
            $future_payment_amount_text = stripslashes($future_payment_amount_text);
            $status = $order->get_status();
            $deposit_amount = floatval($order->get_meta('_wc_deposits_deposit_amount', true));
            $deposit_paid = $order->get_meta('_wc_deposits_deposit_paid', true);
            $second_payment = floatval($order->get_meta('_wc_deposits_second_payment', true));
            $second_payment_paid = $order->get_meta('_wc_deposits_second_payment_paid', true);

            $received_slug = get_option('woocommerce_checkout_order_received_endpoint', 'order-received');
            $pay_slug = get_option('woocommerce_checkout_order_pay_endpoint', 'order-pay');

            $is_checkout = (get_query_var($received_slug) === '' && is_checkout());
            $is_paying_remaining = !!get_query_var($pay_slug) && $status === 'partially-paid';
            $is_email = did_action('woocommerce_email_order_details') > 0;


            if (!$is_checkout || $is_email) {

                $total_rows['deposit_amount'] = array(
                    'label' => $deposit_amount_text,
                    'value' => wc_price($deposit_amount, array('currency' => $order->get_currency()))
                );

                $total_rows['second_payment'] = array(
                    'label' => $future_payment_amount_text,
                    'value' => wc_price($second_payment, array('currency' => $order->get_currency()))
                );


            }


            if ($is_checkout && !$is_paying_remaining && !$is_email) {

                if ($deposit_paid !== 'yes') {
                    $to_pay = $deposit_amount;
                } elseif ($deposit_paid === 'yes' && $second_payment_paid !== 'yes') {
                    $to_pay = $second_payment;
                }


                $total_rows['paid_today'] = array(
                    'label' => $to_pay_text,
                    'value' => wc_price($to_pay, array('currency' => $order->get_currency()))
                );
            }

            if ($is_checkout && $is_paying_remaining && !$is_email ) {

           


                $partial_payment_id = absint(get_query_var($pay_slug));
                $partial_payment = wc_get_order($partial_payment_id);

                //echo "<pre>";
                //print_r($partial_payment);

                $total_rows['paid_today'] = array(
                    'label' => $to_pay_text,
                    'value' => wc_price($partial_payment->get_total(), array('currency' => $order->get_currency()))
                );
            }
        endif;
        return $total_rows;
    }


    /**
     * @brief handles formatted subtotal display for orders with deposit
     * @param $subtotal
     * @param $item
     * @param $order
     * @return string
     */
    public
    function order_formatted_line_subtotal($subtotal, $item, $order)
    {

        if (did_action('woocommerce_email_order_details')) return $subtotal;


        if ($order->get_meta('_wc_deposits_order_has_deposit', true) === 'yes') {


            if (isset($item['wc_deposit_meta'])) {
                $deposit_meta = maybe_unserialize($item['wc_deposit_meta']);
            } else {
                return $subtotal;
            }

            if (is_array($deposit_meta) && isset($deposit_meta['enable']) && $deposit_meta['enable'] === 'yes') {
                $tax = get_option('wc_deposits_tax_display', 'no') === 'yes' ? floatval($item['line_tax']) : 0;

                $woocommerce_prices_include_tax = get_option('woocommerce_prices_include_tax');

                if ($woocommerce_prices_include_tax === 'yes') {

                    $deposit = $deposit_meta['deposit'];

                } else {
                    $deposit = $deposit_meta['deposit'] + $tax;

                }

                return $subtotal . '<br/>(' .
                    wc_price($deposit, array('currency' => $order->get_currency())) . ' ' . __('Deposit', 'woocommerce-deposits') . ')';
            } else {
                return $subtotal;
            }
        } else {
            return $subtotal;
        }
    }

    /**
     * @param $price
     * @param $order
     * @param $item
     * @return float|int
     */
    public
    function order_amount_item_subtotal($price, $order, $item)
    {

        $status = $order->get_status();

        if (isset($item['wc_deposit_meta'])) {
            $deposit_meta = maybe_unserialize($item['wc_deposit_meta']);
        } else {
            return $price;
        }

        if (isset($deposit_meta) && isset($deposit_meta['enable']) && $deposit_meta['enable'] === 'yes') {
            if ($status === 'partially-paid') {
                $price = floatval($deposit_meta['remaining']) / $item['qty'];
            } else {
                $price = floatval($deposit_meta['deposit']) / $item['qty'];
            }
            $price = round($price, wc_get_price_decimals());
        } elseif ($status === 'partially-paid') {
            $price = 0; // ensure that fully paid items are not paid for yet again.
        }



        return $price;
    }


    function create_order($order_id, $checkout)
    {


        //return if there is no deposit in cart
        if (!isset(WC()->cart->deposit_info['deposit_enabled']) || WC()->cart->deposit_info['deposit_enabled'] !== true) {
            return null;
        }

        $data = $checkout->get_posted_data();



        try {
            $order_id = absint(WC()->session->get('order_awaiting_payment'));
            $cart_hash = WC()->cart->get_cart_hash();
            $available_gateways = WC()->payment_gateways->get_available_payment_gateways();

            $order = $order_id ? wc_get_order($order_id) : null;


            /**
             * If there is an order pending payment, we can resume it here so
             * long as it has not changed. If the order has changed, i.e.
             * different items or cost, create a new order. We use a hash to
             * detect changes which is based on cart items + order total.
             */
            if ($order && $order->has_cart_hash($cart_hash) && $order->has_status(array('pending', 'failed'))) {
                // Action for 3rd parties.
                do_action('woocommerce_resume_order', $order_id);

                // Remove all items - we will re-add them later.
                $order->remove_order_items();
            } else {
                $order = new WC_Order();
            }


            $fields_prefix = array(
                'shipping' => true,
                'billing' => true,
            );

            $shipping_fields = array(
                'shipping_method' => true,
                'shipping_total' => true,
                'shipping_tax' => true,
            );

            foreach ($data as $key => $value) {


                if (is_callable(array($order, "set_{$key}"))) {
                    $order->{"set_{$key}"}($value);
                    // Store custom fields prefixed with wither shipping_ or billing_. This is for backwards compatibility with 2.6.x.
                } elseif (isset($fields_prefix[current(explode('_', $key))])) {
                    if (!isset($shipping_fields[$key])) {


                        $order->update_meta_data('_' . $key, $value);
                    }
                }
            }


            $user_agent = wc_get_user_agent();

            $order->set_created_via('checkout');
            $order->set_cart_hash($cart_hash);
            $order->set_customer_id(apply_filters('woocommerce_checkout_customer_id', get_current_user_id()));
            $order_vat_exempt = WC()->cart->get_customer()->get_is_vat_exempt() ? 'yes' : 'no';
            $order->add_meta_data('is_vat_exempt', $order_vat_exempt);
            $order->set_currency(get_woocommerce_currency());
            $order->set_prices_include_tax('yes' === get_option('woocommerce_prices_include_tax'));
            $order->set_customer_ip_address(WC_Geolocation::get_ip_address());
            $order->set_customer_user_agent($user_agent);
            $order->set_customer_note(isset($data['order_comments']) ? $data['order_comments'] : '');
            $order->set_payment_method('');
            $order->set_shipping_total(WC()->cart->get_shipping_total());
            $order->set_discount_total(WC()->cart->get_discount_total());
            $order->set_discount_tax(WC()->cart->get_discount_tax());
            $order->set_cart_tax(WC()->cart->get_cart_contents_tax() + WC()->cart->get_fee_tax());
            $order->set_shipping_tax(WC()->cart->get_shipping_tax());
            $order->set_total(WC()->cart->get_total('edit'));
            $checkout->create_order_line_items($order, WC()->cart);
            $checkout->create_order_fee_lines($order, WC()->cart);
            $checkout->create_order_shipping_lines($order, WC()->session->get('chosen_shipping_methods'), WC()->shipping()->get_packages());
            $checkout->create_order_tax_lines($order, WC()->cart);
            $checkout->create_order_coupon_lines($order, WC()->cart);

            /**
             * Action hook to adjust order before save.
             *
             * @since 3.0.0
             */
            do_action('woocommerce_checkout_create_order', $order, $data);

            // Save the order.
            $order_id = $order->save();

            do_action('woocommerce_checkout_update_order_meta', $order_id, $data);


            //create all payments
            $order->read_meta_data();
            $payment_schedule = $order->get_meta('_wc_deposits_payment_schedule');


            $deposit_id = null;
            foreach ($payment_schedule as $partial_key => $payment) {

                $partial_payment = new WCDP_Payment();


                $partial_payment->set_customer_id(apply_filters('woocommerce_checkout_customer_id', get_current_user_id()));

                $amount = $payment['total'];


                //allow partial payments to be inserted only as a single fee without item details
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


                $partial_payment->set_parent_id($order->get_id());
                $partial_payment->add_meta_data('is_vat_exempt', $order_vat_exempt);
                $partial_payment->add_meta_data('_wc_deposits_payment_type', $payment['type']);

                if(is_numeric($partial_key)){
                    $partial_payment->add_meta_data('_wc_deposits_partial_payment_date', $partial_key);
                }
                $partial_payment->set_currency(get_woocommerce_currency());
                $partial_payment->set_prices_include_tax('yes' === get_option('woocommerce_prices_include_tax'));
                $partial_payment->set_customer_ip_address(WC_Geolocation::get_ip_address());
                $partial_payment->set_customer_user_agent($user_agent);

                $partial_payment->set_total($amount);
                $partial_payment->save();


                $payment_schedule[$partial_key]['id'] = $partial_payment->get_id();

                //fix wpml language
                $wpml_lang = $order->get_meta('wpml_language', true);
                if ($payment['type'] === 'deposit') {

                    //we need to save to generate id first
                    $partial_payment->save();

                    $deposit_id = $partial_payment->get_id();
                    $partial_payment->set_payment_method(isset($available_gateways[$data['payment_method']]) ? $available_gateways[$data['payment_method']] : $data['payment_method']);

                    //add wpml language for all child orders for wpml
                    if (!empty($wpml_lang)) {
                        $partial_payment->update_meta_data('wpml_language', $wpml_lang);
                    }

                    $partial_payment->save();

                }

            }

            //update the schedule meta of parent order
            $order->update_meta_data('_wc_deposits_payment_schedule', $payment_schedule);
            $order->save();

            return absint($deposit_id);

        } catch (Exception $e) {
            return new WP_Error('checkout-error', $e->getMessage());
        }
    }


    function partial_payment_number($number, $order)
    {
        if (is_order_received_page() && did_action('woocommerce_before_thankyou') && !did_action('woocommerce_thankyou')) return $number;


        if ($order && $order->get_type() === 'wcdp_payment') {
            $parent = wc_get_order($order->get_parent_id());
            if ($parent) {
                $payment_schedule = $parent->get_meta('_wc_deposits_payment_schedule', true);
                $count = 0;
                $suffix = '-';
                if (!empty($payment_schedule) && is_array($payment_schedule)) {
                    foreach ($payment_schedule as $payment) {
                        $count++;
                        if ($payment['id'] == $order->get_id()) {
                            $suffix .= $count;
                            break;
                        }
                    }
                }


                $number = $parent->get_order_number() . $suffix;
            }
        }
        return $number;
    }

}

