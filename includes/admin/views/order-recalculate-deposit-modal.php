<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if ($order && $order->get_type() !== 'wcdp_payment') {

    $payment_plans = get_terms(array(
            'taxonomy' => WC_DEPOSITS_PAYMENT_PLAN_TAXONOMY,
            'hide_empty' => false
        )
    );

    $all_plans = array();
    foreach ($payment_plans as $payment_plan) {
        $all_plans[$payment_plan->term_id] = $payment_plan->name;
    }
    ?>
    <div class="wc-backbone-modal wcdp-recalculate-deposit-modal">
        <div class="wc-backbone-modal-content">

            <section class="wc-backbone-modal-main" role="main">
                <header class="wc-backbone-modal-header">
                    <h1><?php esc_html_e('Recalculate Deposit', 'woocommerce-deposits'); ?></h1>
                    <button class="modal-close modal-close-link dashicons dashicons-no-alt">
                        <span class="screen-reader-text">Close modal panel</span>
                    </button>
                </header>
                <article>
                    <?php if (wcdp_checkout_mode()) {

                        $deposit_enabled = get_option('wc_deposits_checkout_mode_enabled');
                        $deposit_amount = get_option('wc_deposits_checkout_mode_deposit_amount');
                        $amount_type = get_option('wc_deposits_checkout_mode_deposit_amount_type');
                        if($amount_type === 'percentage'){
                            $deposit_amount = floatval($order->get_subtotal('edit') /100 * $deposit_amount);
                        }
                        ?>
                        <form id="wcdp-modal-recalculate-form" action="" method="post">
                            <table class="widefat">
                                <thead>

                                <tr>
                                    <th><?php esc_html_e('Enable Deposit', 'woocommerce'); ?></th>
                                    <th><?php esc_html_e('Amount Type', 'woocommerce-deposits'); ?></th>
                                    <th><?php esc_html_e('Deposit', 'woocommerce-deposits'); ?></th>
                                </tr>
                                </thead>
                                <tbody>
                                <tr class="wcdp_calculator_modal_row">
                                    <td><input <?php echo $deposit_enabled ? 'checked="checked"' : ''; ?> value="yes"
                                                                                                          name="wc_deposits_deposit_enabled_checkout_mode"
                                                                                                          class="wcdp_enable_deposit"
                                                                                                          type="checkbox"/>
                                    </td>
                                    <td>
                                        <select class="widefat wc_deposits_deposit_amount_type"
                                                name="wc_deposits_deposit_amount_type_checkout_mode" <?php echo $deposit_enabled ? '' : 'disabled'; ?> >
                                            <option <?php selected('fixed', $amount_type); ?>
                                                    value="fixed"><?php _e('Fixed', 'woocommerce-deposits'); ?></option>
                                            <option <?php selected('percent', $amount_type); ?>
                                                    value="percentage"><?php _e('Percentage', 'woocommerce-deposits'); ?></option>
                                            <option <?php selected('payment_plan', $amount_type); ?>
                                                    value="payment_plan"><?php _e('Payment plan', 'woocommerce-deposits'); ?></option>
                                        </select>
                                    </td>
                                    <td style="min-width: 250px;">
                                        <input name="wc_deposits_deposit_amount_checkout_mode" <?php echo $deposit_enabled ? '' : 'disabled'; ?>
                                               type="number" value="<?php echo $deposit_amount; ?>"
                                               class="widefat wc_deposits_deposit_amount <?php echo $amount_type === 'payment_plan' ? ' wcdp-hidden' : ''; ?>"/>
                                        <select <?php echo $deposit_enabled ? '' : 'disabled'; ?>
                                                class="<?php echo $amount_type === 'payment_plan' ? '' : 'wcdp-hidden'; ?> wc_deposits_payment_plan"
                                                name="wc_deposits_payment_plan_checkout_mode">  <?php
                                            foreach ($all_plans as $key => $plan) {
                                                ?>
                                                <option value="<?php echo $key; ?>"><?php echo $plan; ?></option><?php
                                            }
                                            ?>
                                        </select>
                                    </td>
                                </tr>
                                </tbody>
                            </table>
                        </form>
                        <?php
                    } else {
                        ?>
                        <form id="wcdp-modal-recalculate-form" action="" method="post">
                            <table class="widefat">
                                <thead>

                                <tr>
                                    <th><?php esc_html_e('Enable Deposit', 'woocommerce'); ?></th>
                                    <th><?php esc_html_e('Order Item', 'woocommerce'); ?></th>
                                    <th><?php esc_html_e('Amount Type', 'woocommerce-deposits'); ?></th>
                                    <th><?php esc_html_e('Deposit', 'woocommerce-deposits'); ?></th>
                                </tr>
                                </thead>
                                <tbody>

                                <?php
                                foreach ($order->get_items() as $order_Item) {
                                    $item_data = $order_Item->get_meta('wc_deposit_meta', true);
                                    $deposit_enabled = is_array($item_data) && isset($item_data['enable']) && $item_data['enable'] === 'yes';
                                    $product = $order_Item->get_product();
                                    $amount_type = is_array($item_data) && isset($item_data['deposit']) ? 'fixed' : wc_deposits_get_product_deposit_amount_type($product->get_id());
                                    $deposit_amount = is_array($item_data) && isset($item_data['deposit']) ? $item_data['deposit'] : wc_deposits_get_product_deposit_amount($product->get_id());
                                    ?>
                                    <tr class="wcdp_calculator_modal_row">

                                        <td><input <?php echo $deposit_enabled ? 'checked="checked"' : ''; ?>
                                                    value="yes"
                                                    name="wc_deposits_deposit_enabled_<?php echo $order_Item->get_id() ?>"
                                                    class="wcdp_enable_deposit"
                                                    type="checkbox"/>
                                        </td>
                                        <td><?php echo $order_Item->get_name(); ?></td>
                                        <td>
                                            <select class="widefat wc_deposits_deposit_amount_type"
                                                    name="wc_deposits_deposit_amount_type_<?php echo $order_Item->get_id() ?>" <?php echo $deposit_enabled ? '' : 'disabled'; ?> >
                                                <option <?php selected('fixed', $amount_type); ?>
                                                        value="fixed"><?php _e('Fixed', 'woocommerce-deposits'); ?></option>
                                                <option <?php selected('percent', $amount_type); ?>
                                                        value="percentage"><?php _e('Percentage', 'woocommerce-deposits'); ?></option>
                                                <option <?php selected('payment_plan', $amount_type); ?>
                                                        value="payment_plan"><?php _e('Payment plan', 'woocommerce-deposits'); ?></option>
                                            </select>
                                        </td>
                                        <td style="min-width: 250px;">
                                            <input name="wc_deposits_deposit_amount_<?php echo $order_Item->get_id() ?>" <?php echo $deposit_enabled ? '' : 'disabled'; ?>
                                                   type="number" value="<?php echo $deposit_amount; ?>"
                                                   class="widefat wc_deposits_deposit_amount <?php echo $amount_type === 'payment_plan' ? ' wcdp-hidden' : ''; ?>"/>
                                            <select <?php echo $deposit_enabled ? '' : 'disabled'; ?>
                                                    class="widefat <?php echo $amount_type === 'payment_plan' ? '' : 'wcdp-hidden'; ?> wc_deposits_payment_plan"
                                                    name="wc_deposits_payment_plan_<?php echo $order_Item->get_id() ?>">  <?php
                                                foreach ($all_plans as $key => $plan) {
                                                    ?>
                                                    <option
                                                    value="<?php echo $key; ?>"><?php echo $plan; ?></option><?php
                                                }
                                                ?>
                                            </select>
                                        </td>
                                    </tr>
                                    <?php
                                }
                                ?>
                                </tbody>
                            </table>
                        </form>
                        <?php
                    } ?>
                </article>
                <footer>
                    <div class="inner">
                        <!--                        <button id="btn-ok"-->
                        <button id="btn-ok"
                                class="button button-primary button-large"><?php esc_html_e('Save', 'woocommerce-deposits'); ?></button>
                    </div>
                </footer>
            </section>
        </div>
    </div>
    <div class="wc-backbone-modal-backdrop"></div>
    <?php
}
