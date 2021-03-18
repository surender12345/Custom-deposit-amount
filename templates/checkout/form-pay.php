<?php
/**
 * Pay for order form
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/checkout/form-pay.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see https://docs.woocommerce.com/document/template-structure/
 * @package WooCommerce/Templates
 * @version 3.4.0
 */

defined( 'ABSPATH' ) || exit;

$parent = wc_get_order($order->get_parent_id());
$totals = $parent->get_order_item_totals(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.OverrideProhibited
  $order_data = $parent->get_data();
 //echo"<pre>"; print_R($order_data);
  $order_ids = $order_data['id'];
   $_order_number = get_post_meta($order_ids,'_order_number',true);
$pay_payment=get_post_meta($order_ids,'_wc_deposits_deposit_amount',true);
$second_payment=get_post_meta($order_ids,'_wc_deposits_second_payment',true);
foreach($order_data as $itemss=>$datas){
                  
                  }
               $billing= $order_data['billing'];
              // echo"<pre>";print_r($billing);
               $first_name= $billing['first_name'];
               $last_name=$billing['last_name'];
               $company=$billing['company'];
               $address_1=$billing['address_1'];
               $address_2=$billing['address_2'];
               $city=$billing['city'];
               $state=$billing['state'];
               $postcode=$billing['postcode'];
               $country=$billing['country'];
               $phone=$billing['phone'];
                $shipping= $order_data['shipping'];
              //  echo"<pre>";print_r($shipping);
                 $first_name_shipping= $shipping['first_name'];
               $last_name_shipping=$shipping['last_name'];
               $company_shipping=$shipping['company'];
               $address_1_shipping=$shipping['address_1'];
               $address_2_shipping=$shipping['address_2'];
               $city_shipping=$shipping['city'];
               $state_shipping=$shipping['state'];
               $postcode_shipping=$shipping['postcode'];
               $country_shipping=$shipping['country'];
              $order_meta_shipping = $order_data['meta_data'];

  $order_ids = $order_data['id'];
$shipping_phone_number= get_post_meta($order_ids,'_shipping_phone',true);
$shipping_email=get_post_meta($order_ids,'_shipping_email',true);
 $total= $order_data['total'];
 $pay_amount = $order_data['deposit']['total'];


?>
<form id="order_review" method="post">

	<table class="shop_table" style="width:50%;float:left;">
		<thead>
			<tr>
				<th class="product-name"><?php esc_html_e( 'Product', 'woocommerce' ); ?></th>
				<th class="product-quantity"><?php esc_html_e( 'Qty', 'woocommerce' ); ?></th>
				<th class="product-total"><?php esc_html_e( 'Totals', 'woocommerce' ); ?></th>
			</tr>
		</thead>
		<tbody>
				<?php if ( count( $parent->get_items() ) > 0 ) :


			 ?>
				<?php $i = 1;foreach ( $parent->get_items() as $item_id => $item ) : 
					if($i == 1):
					 ?>
					<?php
					if ( ! apply_filters( 'woocommerce_order_item_visible', true, $item ) ) {
						continue;
					}
					?>
					<tr class="<?php echo esc_attr( apply_filters( 'woocommerce_order_item_class', 'order_item', $item, $order ) ); ?>"></tr>
					   <span clas="top_data">
						<tr><td class="product-name"></td></tr>
						<td class="product-name">
							<th class="product"style="display: none"><?php _e('Order Summery', 'woocommerce-pdf-invoices-packing-slips' ); ?></th>
							<td style="display: none"><?php echo $order_ids;?></td>
						</td></tr><tr>
						<td class="product-name">
							<th class="product"><?php _e('Transaction ID ', 'woocommerce-pdf-invoices-packing-slips' ); ?></th>
							<td><?php echo $_order_number;?></td>
						</td></tr>
						<tr class="border_bottom">
						<td class="product-name">
							<th class="product"><?php _e('Customer Billing Details ', 'woocommerce-pdf-invoices-packing-slips' ); ?></th></td></tr><tr><td colspan="2"></td>
							<tr><td colspan="2"></td><td><?php echo $first_name." ". $last_name;?></td></tr>
							<tr><td colspan="2"></td><td><?php echo $company." <br>" .$address_1;?></td></tr>
							<tr><td colspan="2"></td><td><?php echo $address_2." " .$city;?></td></tr>
							<tr><td colspan="2"></td><td><?php echo $state." " .$postcode;?></td></tr>
							<tr><td colspan="2"></td><td><?php echo $country;?></td></tr>
							<tr><td colspan="2"></td><td><?php echo $phone;?></td></tr>
						
					</tr>
					
						<tr class="border_bottom-second">
						<td class="product-name">
							<th class="product"><?php _e('Shipping Details', 'woocommerce-pdf-invoices-packing-slips' ); ?></th></td></tr>
                        <tr><td colspan="2"></td>
							<td><?php echo $first_name_shipping." ". $last_name_shipping;?>
							<?php echo $company_shipping."<br> " .$address_1_shipping;?><br>
							<?php echo $address_2_shipping." " .$city_shipping;?><br>
							<?php echo $state_shipping." " .$postcode_shipping;?><br>
							<?php echo $country_shipping;?><br>
							<?php echo $phone;?><br>
						</td>
						
					</tr>
					<tr class="bottom-order">
						<td class="product-name">
							<th class="product"><?php _e('Order Total ($) ', 'woocommerce-pdf-invoices-packing-slips' ); ?></th>
							<td><?php echo wc_price($total);?></td></td>
							</tr>
							<tr class="bottom-order1">
						<td class="product-name">
							<th class="product"><?php _e('Amount Paid ', 'woocommerce-pdf-invoices-packing-slips' ); ?></th>
							<td><?php echo wc_price($pay_payment);?></td></td>
							</tr>
							<tr class="bottom-order2">
						<td class="product-name">
							<th class="product"><?php _e('Balance Due ', 'woocommerce-pdf-invoices-packing-slips' ); ?></th>
							<td><?php echo wc_price($second_payment);?></td></td>
							</tr>
				<?php $i++; endif; endforeach; ?>
			<?php endif; ?>
		</tbody>

	</table>
	<div id="payment" style="width:50%;float:right">
		<?php if ( $order->needs_payment() ) : ?>
			<ul class="wc_payment_methods payment_methods methods">
				<?php
				if ( ! empty( $available_gateways ) ) {
					foreach ( $available_gateways as $gateway ) {
						wc_get_template( 'checkout/payment-method.php', array( 'gateway' => $gateway ) );
					}
				} else {
					echo '<li class="woocommerce-notice woocommerce-notice--info woocommerce-info">' . apply_filters( 'woocommerce_no_available_payment_methods_message', esc_html__( 'Sorry, it seems that there are no available payment methods for your location. Please contact us if you require assistance or wish to make alternate arrangements.', 'woocommerce' ) ) . '</li>'; // @codingStandardsIgnoreLine
				}
				?>
			</ul>
		<?php endif; ?>
		<div class="form-row">
			<input type="hidden" name="woocommerce_pay" value="1" />

			<?php wc_get_template( 'checkout/terms.php' ); ?>

			<?php do_action( 'woocommerce_pay_order_before_submit' ); ?>

			<?php echo apply_filters( 'woocommerce_pay_order_button_html', '<button type="submit" class="button alt" id="place_order jjjj" value="' . esc_attr( $order_button_text ) . '" data-value="' . esc_attr( $order_button_text ) . '">Confirm Payment</button>' ); // @codingStandardsIgnoreLine ?>

			<?php do_action( 'woocommerce_pay_order_after_submit' ); ?>

			<?php wp_nonce_field( 'woocommerce-pay', 'woocommerce-pay-nonce' ); ?>
		</div>
	</div>
</form>
<style>
	.payment_method_nab_dp .form-row-first {  float: left; width: 100% !important;}
.payment_method_nab_dp .form-row-last { float: left; width: 49.4%;}
.payment_method_nab_dp .payment_methods li .payment_box fieldset label { width: 25% !important;}
 #payment .payment_methods li .payment_box fieldset label { width: 100%;}
 .border_bottom:after {content: ""; background-color: #000; width: 39%; height: 1px;position: absolute;top: 88px; left: 0;}
 .border_bottom{position: relative;}
 .border_bottom-second{position: relative;}
  .border_bottom-second:after {content: ""; background-color: #000; width: 39%; height: 1px;position: absolute; left: 0; bottom: -358px;}
  .page-id-461 .elementor-widget.elementor-widget-divider { margin-bottom: 20px;}
 .bottom-order:after {content: ""; background-color: #000; width: 39%; height: 1px;position: absolute;bottom: -514px; left: 0;}
 .bottom-order{position: relative;}
  



</style>
