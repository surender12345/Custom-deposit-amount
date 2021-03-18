<?php
/**
 * Order details Summary
 *
 * This template displays a summary of partial payments
 *
 * @package Webtomizer\WCDP\Templates
 * @version 2.5.0
 */


if (!defined('ABSPATH')) {
    exit;
}

if (!$order = wc_get_order($order_id)) {
    return;
}


?> <p> <?php _e('Partial payments summary', 'woocommerce-deposits') ?>

<table class="woocommerce-table  woocommerce_deposits_parent_order_summary">

    <thead>
    <tr>

        <th ><?php esc_html_e('Payment', 'woocommerce-deposits'); ?> </th>
        <th ><?php esc_html_e('Payment ID', 'woocommerce-deposits'); ?> </th>
        <th><?php esc_html_e('Status', 'woocommerce-deposits'); ?> </th>
        <th><?php esc_html_e('Amount', 'woocommerce-deposits'); ?> </th>

    </tr>

    </thead>

    <tbody>
    <?php foreach($schedule as $timestamp => $payment){


        $title = '';
        if(isset($payment['title'])){

            $title  = $payment['title'];
        } else {

            if(!is_numeric($timestamp)){

                if($timestamp === 'unlimited'){
                    $title = __('Future Payments', 'woocommerce-deposits');
                } elseif($timestamp === 'deposit'){
                    $title = __('Deposit', 'woocommerce-deposits');
                } else {
                    $title = $timestamp;
                }

            } else {
                $title =  date_i18n(wc_date_format(),$timestamp);
            }
        }

        $title = apply_filters('wc_deposits_partial_payment_title',$title,$payment);

        $payment_order = false;
        if(isset($payment['id']) && !empty($payment['id'])) $payment_order = wc_get_order($payment['id']);
        if(!$payment_order) continue;
        $payment_id = $payment_order ? $payment_order->get_order_number(): '-';
        $status = $payment_order ? wc_get_order_status_name($payment_order->get_status()) : '-';
        $amount = $payment_order ? $payment_order->get_total() : $payment['total'];
        $price_args = array('currency' => $payment_order->get_currency());

        ?>
        <tr class="order_item">
            <td >
                <?php echo $title; ?>
            </td>
            <td>
                <?php echo $payment_id; ?>
            </td>
            <td >
                <?php echo $status; ?>

            </td>
            <td >
                <?php echo wc_price($amount,$price_args); ?>
            </td>
        </tr>
        <?php
    } ?>

    </tbody>

    <tfoot>


    </tfoot>
</table>
