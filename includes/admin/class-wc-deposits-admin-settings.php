<?php
namespace Webtomizer\WCDP;


if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * @brief Adds a new panel to the WooCommerce Settings
 *
 */
class WC_Deposits_Admin_Settings
{
    public $wc_deposits;

    public function __construct(&$wc_deposits)
    {

        $this->wc_deposits = $wc_deposits;

        // Hook the settings page
        add_filter('woocommerce_settings_tabs_array', array($this, 'settings_tabs_array'), 21);
        add_action('woocommerce_settings_wc-deposits', array($this, 'settings_tabs_wc_deposits'));
        add_action('woocommerce_update_options_wc-deposits', array($this, 'update_options_wc_deposits'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_settings_script'));

        add_action('woocommerce_admin_field_deposit_buttons_color', array($this, 'deposit_buttons_color'));
        // reminder datepicker
        add_action('woocommerce_admin_field_reminder_datepicker', array($this, 'reminder_datepicker'));


        add_action('wp_ajax_wc_deposits_verify_purchase_code', array($this, 'verify_purchase_code'));
        // check if purchase code exists
        $purchase_code = get_option('wc_deposits_purchase_code');
        $hide_activation_notice = get_option('wc_deposits_hide_activation_notice', 'no');
        if ($hide_activation_notice === 'no' && empty($purchase_code)) {


        }
    }


    public function enqueue_settings_script($hook)
    {

        if (function_exists('get_current_screen')) {

            if (isset($_GET['page']) && $_GET['page'] === 'wc-settings' && isset($_GET['tab']) && $_GET['tab'] === 'wc-deposits') {

                wp_enqueue_style('wp-color-picker');
                wp_enqueue_script('jquery-ui-datepicker');

                wp_enqueue_script('wc-deposits-admin-settings', WC_DEPOSITS_PLUGIN_URL . '/assets/js/admin/admin-settings.js', array('jquery', 'wp-color-picker'));
                wp_localize_script('wc-deposits-admin-settings', 'wc_deposits', array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'strings' => array(
                        'success' => __('Updated successfully', 'woocommerce-deposits')
                    )

                ));
            }

        }


    }


    public function settings_tabs_array($tabs)
    {

        $tabs['wc-deposits'] = __('Deposits', 'woocommerce-deposits');
        return $tabs;
    }

    /**
     * @brief Write out settings html
     *
     * @param array $settings ...
     * @return void
     */
    public function settings_tabs_wc_deposits()
    {

        $mode_notice = wcdp_checkout_mode() ? '<span style="padding:5px 10px; color:#fff; background-color:rgba(146, 52, 129, 0.8);">' . __('Checkout Mode Enabled', 'woocommerce-deposits') . '</span>' : '';
        $debug_mode_notice = get_option('wc_deposits_debug_mode', 'no') === 'yes' ? '<span style="padding:5px 10px; color:#fff; background-color:rgba(255,63,76,0.8);">' . __('Debugging Mode Enabled', 'woocommerce-deposits') . '</span>' : '';
        ?>

        <h2><?php _e('Deposits Settings', 'woocommerce-deposits');
            echo '&nbsp;&nbsp;' . $mode_notice . '&nbsp;&nbsp;' . $debug_mode_notice ?> </h2>
        <?php $settings_tabs = apply_filters('wc_deposits_settings_tabs', array(
        'wcdp_general' => __('General Settings', 'woocommerce-deposits'),
        'display_text' => __('Display & Text', 'woocommerce-deposits'),
        'checkout_mode' => __('Checkout Mode', 'woocommerce-deposits'),
        'second_payment' => __('Future Payments & Reminders', 'woocommerce-deposits'),
        'gateways' => __('Gateways', 'woocommerce-deposits'),
       // 'auto_updates' => __('Automatic Updates', 'woocommerce-deposits'),
//			'advanced' => __( 'Advanced' , 'woocommerce-deposits' ) // to be added in a future version
    )); ?>
        <div class="nav-tab-wrapper wcdp-nav-tab-wrapper">
            <?php
            $count = 0;
            foreach ($settings_tabs as $key => $tab_name) {
                $count++;
                $active = isset($_GET['section']) ? $key === $_GET['section'] ? true : false : $count === 1;

                ?>
                <a class="wcdp nav-tab <?php echo $active ? 'nav-tab-active' : ''; ?>"
                   data-target="<?php echo $key; ?>"><?php echo $tab_name; ?></a>
                <?php
            }
            ?>

        </div>
        <?php
        // echo tabs content
        $count = 0;
        foreach ($settings_tabs as $key => $tab_name) {
            $count++;
            $active = isset($_GET['section']) ? $key === $_GET['section'] ? true : false : $count === 1;
            if (method_exists($this, "tab_{$key}_output")) {
                $this->{"tab_{$key}_output"}($active);

            }
        }
        //allow addons to add their own tab content
        do_action('wc_deposits_after_settings_tabs_content');
    }

    /*** BEGIN TABS CONTENT CALLBACKS **/

    function tab_wcdp_general_output($active)
    {
        $class = $active ? '' : 'hidden';
        ?>
        <div id="wcdp_general" class="wcdp-tab-content <?php echo $class; ?>">

            <?php
            $roles_array = array();
            $user_roles = array_reverse(get_editable_roles());
            foreach ($user_roles as $key => $user_role) {

                $roles_array[$key] = $user_role['name'];
            }

            ?>
            <?php $general_settings = array(

                /*
                 * Site-wide settings
                 */

                'sitewide_title' => array(
                    'name' => __('Site-wide Settings', 'woocommerce-deposits'),
                    'type' => 'title',
                    'desc' => '',
                    'id' => 'wc_deposits_site_wide_title'
                ),
                'deposits_disable' => array(
                    'name' => __('Disable Deposits', 'woocommerce-deposits'),
                    'type' => 'checkbox',
                    'desc' => __('Check this to disable all deposit functionality with one click.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_site_wide_disable',
                ),


                'deposits_default' => array(
                    'name' => __('Default Selection', 'woocommerce-deposits'),
                    'type' => 'radio',
                    'desc' => __('Select the default deposit option.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_default_option',
                    'options' => array(
                        'deposit' => __('Pay Deposit', 'woocommerce-deposits'),
                        'full' => __('Full Amount', 'woocommerce-deposits')
                    ),
                    'default' => 'deposit'
                ),
                'deposits_tax' => array(
                    'name' => __('Display Taxes In Product page', 'woocommerce-deposits'),
                    'type' => 'checkbox',
                    'desc' => __('Check this to count taxes as part of deposits for purposes of display to the customer in product page.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_tax_display',
                ),
                'deposits_tax_cart' => array(
                    'name' => __('Display taxes in cart item Details', 'woocommerce-deposits'),
                    'type' => 'checkbox',
                    'desc' => __('Check this to count taxes as part of deposits for purposes of display to the customer in cart item details', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_tax_display_cart_item',
                ),
                'deposits_breakdown_cart_tooltip' => array(
                    'name' => __('Display Deposit-breakdown Tooltip in cart', 'woocommerce-deposits'),
                    'type' => 'checkbox',
                    'desc' => __('Check to display tooltip in cart totals detailing deposit breakdown)', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_breakdown_cart_tooltip',
                ),
                'fees_handling' => array(
                    'name' => __('Fees Collection Method', 'woocommerce-deposits'),
                    'type' => 'select',
                    'desc' => __('Choose how to handle fees.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_fees_handling',
                    'options' => array(
                        'deposit' => __('with deposit', 'woocommerce-deposits'),
                        'split' => __('Split according to deposit amount', 'woocommerce-deposits'),
                        'full' => __('with future payment(s)', 'woocommerce-deposits')
                    )
                ), 'taxes_handling' => array(
                    'name' => __('Taxes Collection Method', 'woocommerce-deposits'),
                    'type' => 'select',
                    'desc' => __('Choose how to handle taxes.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_taxes_handling',
                    'options' => array(
                        'deposit' => __('with deposit', 'woocommerce-deposits'),
                        'split' => __('Split according to deposit amount', 'woocommerce-deposits'),
                        'full' => __('with future payment(s)', 'woocommerce-deposits')
                    )
                ),
                'shipping_handling' => array(
                    'name' => __('Shipping Handling Method', 'woocommerce-deposits'),
                    'type' => 'select',
                    'desc' => __('Choose how to handle shipping.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_shipping_handling',
                    'options' => array(
                        'deposit' => __('with deposit', 'woocommerce-deposits'),
                        'split' => __('Split according to deposit amount', 'woocommerce-deposits'),
                        'full' => __('with future payment(s)', 'woocommerce-deposits')
                    )
                ),
                'shipping_taxes_handling' => array(
                    'name' => __('Shipping Taxes Handling Method', 'woocommerce-deposits'),
                    'type' => 'select',
                    'desc' => __('Choose how to handle shipping taxes.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_shipping_taxes_handling',
                    'options' => array(
                        'deposit' => __('with deposit', 'woocommerce-deposits'),
                        'split' => __('Split according to deposit amount', 'woocommerce-deposits'),
                        'full' => __('with future payment(s)', 'woocommerce-deposits')
                    )
                ),

                'coupons_handling' => array(
                    'name' => __('Discount Coupons Handling', 'woocommerce-deposits'),
                    'type' => 'select',
                    'desc' => __('Choose how to handle coupon discounts', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_coupons_handling',
                    'options' => array(
                        'deposit' => __('Deduct from deposit', 'woocommerce-deposits'),
                        'split' => __('Split according to deposit amount', 'woocommerce-deposits'),
                        'second_payment' => __('Deduct from future payment(s)', 'woocommerce-deposits')
                    ),
                    'default' => 'second_payment'
                ),
                'deposits_stock' => array(
                    'name' => __('Reduce Stocks On', 'woocommerce-deposits'),
                    'type' => 'radio',
                    'desc' => __('Choose when to reduce stocks.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_reduce_stock',
                    'options' => array(
                        'deposit' => __('Deposit Payment', 'woocommerce-deposits'),
                        'full' => __('Full Payment', 'woocommerce-deposits')
                    ),
                    'default' => 'full'
                ),
                'partially_paid_orders_editable' => array(
                    'name' => __('Make partially paid orders editable', 'woocommerce-deposits'),
                    'type' => 'checkbox',
                    'desc' => __('Check to make orders editable while in "partially paid" status', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_partially_paid_orders_editable',
                ),

                'order_list_table_show_has_deposit' => array(
                    'name' => __('Show "has deposit" column in admin order list table', 'woocommerce-deposits'),
                    'type' => 'checkbox',
                    'desc' => __('Check to show a column in admin order list indicating if order has deposit', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_order_list_table_show_has_deposit',
                ),

                'disable_deposit_for_user_roles' => array(
                    'name' => __('Disable deposit for selected user roles', 'woocommerce-deposits'),
                    'type' => 'multiselect',
                    'class' => 'chosen_select',
                    'options' => $roles_array,
                    'desc' => __('Disable deposit for selected user roles', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_disable_deposit_for_user_roles',
                ),

                'restrict_deposits_for_logged_in_users_only' => array(
                    'name' => __('Restrict deposits for logged-in users only', 'woocommerce-deposits'),
                    'type' => 'checkbox',
                    'desc' => __('Check this to disable all deposit functionality for guests', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_restrict_deposits_for_logged_in_users_only',
                ),
                'sitewide_end' => array(
                    'type' => 'sectionend',
                    'id' => 'wc_deposits_site_wide_end'
                ));


            woocommerce_admin_fields($general_settings);

            ?>
            <?php do_action('wc_deposits_settings_tabs_general_tab'); ?>

        </div>
        <?php
    }

    function tab_display_text_output($active)
    {

        $class = $active ? '' : 'hidden';
        ?>
        <div id="display_text" class="wcdp-tab-content wrap <?php echo $class; ?>">
            <?php
            $text_to_replace = __('Text to replace ', 'woocommerce-deposits');

            $strings_settings = array(

                'display_title' => array(
                    'name' => __('Display & Text', 'woocommerce-deposits'),
                    'type' => 'title',
                    'id' => 'wc_deposits_display_text_title'
                ),
                'override_payment_form' => array(
                    'name' => __('Override payment form', 'woocommerce-deposits'),
                    'type' => 'checkbox',
                    'desc' => __('allow overriding "form-pay.php" template to display original order details during partial payment checkout', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_override_payment_form',
                    'default' => 'no',
                ),
                'display_end' => array(
                    'type' => 'sectionend',
                    'id' => 'wc_deposits_display_text_end'
                ),


                /*
                 * Section for buttons
                 */

                'buttons_title' => array(
                    'name' => __('Buttons', 'woocommerce-deposits'),
                    'type' => 'title',
                    'desc' => __('No HTML allowed. Text will be translated to the user if a translation is available.<br/>Please note that any overflow will be hidden, since button width is theme-dependent.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_buttons_title'
                ),

                'basic_radio_buttons' => array(
                    'name' => __('Use Basic Deposit Buttons', 'woocommerce-deposits'),
                    'type' => 'checkbox',
                    'desc' => __('Use basic radio buttons for deposits, Check this if you are facing issues with deposits slider buttons in product page, ', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_use_basic_radio_buttons',
                    'default' => 'no',
                ),
                'buttons_color' => array(
                    'type' => 'deposit_buttons_color',
                    'class' => 'deposit_buttons_color_html',
                ),
                'buttons_end' => array(
                    'type' => 'sectionend',
                    'id' => 'wc_deposits_buttons_end'
                ),
                'deposit_choice_strings_title' => array(
                    'name' => __('Deposit choice strings', 'woocommerce-deposits'),
                    'type' => 'title',
                    'desc' => __('No HTML allowed. Text will be translated to the user if a translation is available.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_strings_title'
                )
            ,
                'deposits_button_deposit' => array(
                    'name' => __('Deposit Button Text', 'woocommerce-deposits'),
                    'type' => 'text',
                    'desc' => __('Text displayed in the \'Pay Deposit\' button.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_button_deposit',
                    'default' => 'Pay Deposit'
                ),
                'deposits_button_full' => array(
                    'name' => __('Full Amount Button Text', 'woocommerce-deposits'),
                    'type' => 'text',
                    'desc' => __('Text displayed in the \'Full Amount\' button.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_button_full_amount',
                    'default' => 'Full Amount'
                ),
                'deposit_choice_strings_end' => array(
                    'type' => 'sectionend',
                    'id' => 'wc_deposits_deposit_choice_strings_end'
                ),
                'checkout_and_order_strings' => array(
                    'name' => __('Checkout & Order strings', 'woocommerce-deposits'),
                    'type' => 'title',
                    'desc' => __('No HTML allowed. Text will be translated to the user if a translation is available.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_strings_title'
                ),

                'deposits_to_pay_text' => array(
                    'name' => __('To Pay', 'woocommerce-deposits'),
                    'type' => 'text',
                    'desc' => $text_to_replace . '<b>' . __('To Pay', 'woocommerce-deposits') . '</b>',
                    'id' => 'wc_deposits_to_pay_text',
                    'default' => 'To Pay'
                ),
                'deposits_deposit_amount_text' => array(
                    'name' => __('Deposit Amount', 'woocommerce-deposits'),
                    'type' => 'text',
                    'desc' => $text_to_replace . '<b>' . __('Deposit Amount', 'woocommerce-deposits') . '</b>',
                    'id' => 'wc_deposits_deposit_amount_text',
                    'default' => 'Deposit Amount'
                ),
                'deposits_second_payment_text' => array(
                    'name' => __('Future Payments', 'woocommerce-deposits'),
                    'type' => 'text',
                    'desc' => $text_to_replace . '<b>' . __('Future Payments', 'woocommerce-deposits') . '</b>',
                    'id' => 'wc_deposits_second_payment_text',
                    'default' => 'Future Payments'
                ),
                'deposits_deposit_option_text' => array(
                    'name' => __('Deposit Option', 'woocommerce-deposits'),
                    'type' => 'text',
                    'desc' => $text_to_replace . '<b>' . __('Deposit Option', 'woocommerce-deposits') . '</b>',
                    'id' => 'wc_deposits_deposit_option_text',
                    'default' => 'Deposit Option'
                ),

                'deposits_payment_link_text' => array(
                    'name' => __('Payment Link', 'woocommerce-deposits'),
                    'type' => 'text',
                    'desc' => $text_to_replace . '<b>' . __('Payment Link', 'woocommerce-deposits') . '</b>',
                    'id' => 'wc_deposits_payment_link_text',
                    'default' => 'Payment Link'
                ),

                'strings_end' => array(
                    'type' => 'sectionend',
                    'id' => 'wc_deposits_strings_end'
                ),
                /*
                 * Section for messages
                 */

                'messages_title' => array(
                    'name' => __('Messages', 'woocommerce-deposits'),
                    'type' => 'title',
                    'desc' => __('Please check the documentation for allowed HTML tags.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_messages_title'
                ),
                'deposits_message_deposit' => array(
                    'name' => __('Deposit Message', 'woocommerce-deposits'),
                    'type' => 'textarea',
                    'desc' => __('Message to show when \'Pay Deposit\' is selected on the product\'s page.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_message_deposit',
                ),
                'deposits_message_full' => array(
                    'name' => __('Full Amount Message', 'woocommerce-deposits'),
                    'type' => 'textarea',
                    'desc' => __('Message to show when \'Full Amount\' is selected on the product\'s page.', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_message_full_amount',
                ),
                'messages_end' => array(
                    'type' => 'sectionend',
                    'id' => 'wc_deposits_messages_end'
                ),


            );
            woocommerce_admin_fields($strings_settings);
            ?>
            <?php do_action('wc_deposits_settings_tabs_display_text_tab'); ?>
        </div>
        <?php
    }

    function tab_checkout_mode_output($active)
    {
        $class = $active ? '' : 'hidden';
        ?>
        <div id="checkout_mode" class="wcdp-tab-content wrap <?php echo $class; ?>">
            <?php

            $cart_checkout_settings = array(

                'checkout_mode_title' => array(
                    'name' => __('Deposit on Checkout Mode', 'woocommerce-deposits'),
                    'type' => 'title',
                    'desc' => __('changes the way deposits work to be based on total amount at checkout button', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_messages_title'
                ),
                'enable_checkout_mode' => array(
                    'name' => __('Enable checkout mode', 'woocommerce-deposits'),
                    'type' => 'checkbox',
                    'desc' => __('Check this to enable checkout mode, which makes deposits calculate based on total amount during checkout instead of per product', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_checkout_mode_enabled',
                ),
                'checkout_mode_force_deposit' => array(
                    'name' => __('Force deposit', 'woocommerce-deposits'),
                    'type' => 'checkbox',
                    'desc' => __('If you check this, the customer will not be allowed to make a full payment during checkout', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_checkout_mode_force_deposit',
                ),
                'checkout_mode_amount_type' => array(
                    'name' => __('Amount Type', 'woocommerce-deposits'),
                    'type' => 'select',
                    'desc' => __('Choose amount type', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_checkout_mode_deposit_amount_type',
                    'options' => array(
                        'fixed' => __('Fixed', 'woocommerce-deposits'),
                        'percentage' => __('Percentage', 'woocommerce-deposits'),
                        'payment_plan' => __('Payment plan', 'woocommerce-deposits')
                    ),
                    'default' => 'percentage'
                ),
                'checkout_mode_amount_deposit_amount' => array(
                    'name' => __('Deposit Amount', 'woocommerce-deposits'),
                    'type' => 'number',
                    'desc' => __('Amount of deposit ( should not be more than 99 for percentage or more than order total for fixed', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_checkout_mode_deposit_amount',
                    'default' => '50',
                    'custom_attributes' => array(
                        'min' => '0.0',
                        'step' => '0.01'
                    )
                ),


            );


            //payment plans
            $payment_plans = get_terms(array(
                    'taxonomy' => WC_DEPOSITS_PAYMENT_PLAN_TAXONOMY,
                    'hide_empty' => false
                )
            );

            $all_plans = array();
            foreach ($payment_plans as $payment_plan) {
                $all_plans[$payment_plan->term_id] = $payment_plan->name;
            }

            $cart_checkout_settings['checkout_mode_payment_plans'] = array(
                'name' => __('Payment plan(s)', 'woocommerce-deposits'),
                'type' => 'multiselect',
                'class' => 'chosen_select',
                'options' => $all_plans,
                'desc' => __('Selected payment plan(s) will be available for customers to choose from', 'woocommerce-deposits'),
                'id' => 'wc_deposits_checkout_mode_payment_plans',
            );

            $cart_checkout_settings['checkout_mode_end'] = array(
                'type' => 'sectionend',
                'id' => 'wc_deposits_checkout_mode_end'
            );


            woocommerce_admin_fields($cart_checkout_settings);

            ?>
            <?php do_action('wc_deposits_settings_tabs_checkout_mode_tab'); ?>

        </div>

        <?php

    }

    function tab_second_payment_output($active)
    {
        $class = $active ? '' : 'hidden';

        ?>
        <div id="second_payment" class="wcdp-tab-content wrap" <?php echo $class; ?> >


            <?php

            $payment_plan_settings = array();
            $reminder_settings = array(
                'second_payment_settings' => array(
                    'name' => __('Future Payments Settings', 'woocommerce-deposits'),
                    'type' => 'title',
                    'id' => 'wc_deposits_second_payment_settings_title'
                ),
                'deposits_payaple' => array(
                    'name' => __('Enable Future Payments', 'woocommerce-deposits'),
                    'type' => 'checkbox',
                    'desc' => __('Uncheck this to prevent the customer from making any payment beyond deposit. (You\'ll have to manually mark the orders as completed)',
                        'woocommerce-deposits'),
                    'id' => 'wc_deposits_remaining_payable',
                    'default' => 'yes',
                ),
                'second_payment_settings_end' => array(
                    'type' => 'sectionend',
                    'id' => 'wc_deposits_second_payment_settings_end'
                )
            );

            if (get_option('wc_deposits_remaining_payable', 'yes') === 'yes') {

                $reminder_settings['reminder_datepicker_title'] = array(
                    'name' => __('Second Payment Settings', 'woocommerce-deposits'),
                    'type' => 'title',
                    'desc' => __('Second Payment Reminder controls  (You can always send a reminder manually from order actions ) ', 'woocommerce-deposits')
                        . '<br/><span class="wcdp-important">' . __('These settings apply only to orders with fixed / percentage deposit (no payment plan)', 'woocommerce-deposits') . '.</span>',
                    'id' => 'wc_deposits_reminder_datepicker_title'
                );

                $reminder_settings['enable_second_payment_reminder'] = array(
                    'name' => __('Enable Second Payment Reminder after "X" Days', 'woocommerce-deposits'),
                    'type' => 'checkbox',
                    'desc' => __('Check this to enable sending second payment reminder email automatically after X number of days of deposit payment.',
                        'woocommerce-deposits'),
                    'id' => 'wc_deposits_enable_second_payment_reminder',
                    'default' => 'no',
                );

                $reminder_settings['second_payment_reminder_duration'] = array(
                    'name' => __('Days before Second Payment reminder ', 'woocommerce-deposits'),
                    'type' => 'number',
                    'desc' => __('Duration between partial payment and second payment reminder (in days)', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_second_payment_reminder_duration',
                    'default' => '14'
                );


                $reminder_settings['second_payment_due_after'] = array(
                    'name' => __('Days before Second Payment is due', 'woocommerce-deposits'),
                    'type' => 'number',
                    'desc' => __('Number of days before second payment is due ( leave field empty for unlimited days )', 'woocommerce-deposits'),
                    'id' => 'wc_deposits_second_payment_due_after',
                    'default' => ''
                );

                $reminder_settings['reminder_datepicker'] = array(
                    'type' => 'reminder_datepicker',
                    'class' => 'reminder_datepicker_html',
                );
                $reminder_settings['reminder_datepicker_end'] = array(
                    'type' => 'sectionend',
                    'id' => 'wc_deposits_reminder_datepicker_end'
                );


                $payment_plan_settings = array(
                    'payment_plan_settings' => array(
                        'name' => __('Payment plan Settings', 'woocommerce-deposits'),
                        'type' => 'title',
                        'desc' => '<span class="wcdp-important">' . sprintf(__('These settings apply only to orders with (%s).', 'woocommerce-deposits'),__('Payment plans', 'woocommerce-deposits')) . '</span>',
                        'id' => 'wc_deposits_payment_plan_settings_title'
                    ),
                    'enable_second_payment_reminder' => array(
                        'name' => sprintf(__('Enable %s', 'woocommerce-deposits'),__('Partial Payment reminder','woocommerce-dpeosits')),
                        'type' => 'checkbox',
                        'desc' => sprintf(__('Check this to enable %s', 'woocommerce-deposits'),__('Partial Payment reminder','woocommerce-dpeosits')) ,
                        'id' => 'wc_deposits_enable_partial_payment_reminder',
                        'default' => 'yes',
                    ),
                    'partial_payment_reminder_x_days_before_due_date' => array(
                        'name' => __('Partial Payment reminder', 'woocommerce-deposits'),
                        'type' => 'number',
                        'desc' => __('Send a reminder email x days before partial payment due date', 'woocommerce-deposits'),
                        'id' => 'wc_deposits_partial_payment_reminder_x_days_before_due_date',
                        'default' => '3'
                    ),
                    'payment_plan_settings_end' => array(
                        'type' => 'sectionend',
                        'id' => 'wc_deposits_payment_plan_settings_end'
                    )
                );
            }


            woocommerce_admin_fields($reminder_settings);
            woocommerce_admin_fields($payment_plan_settings);

            ?>
            <?php do_action('wc_deposits_settings_tabs_second_payment_tab'); ?>

        </div>

        <?php
    }

    function tab_gateways_output($active)
    {
        $class = $active ? '' : 'hidden';

        ?>
        <div id="gateways" class="wcdp-tab-content wrap <?php echo $class; ?>">

            <?php

            /*
     * Allowed gateways
     */

            $gateways_settings = array();

            $gateways_settings['gateways_title'] = array(
                'name' => __('Disallowed Gateways', 'woocommerce-deposits'),
                'type' => 'title',
                'desc' => __('Disallow the following gateways when there is a deposit in the cart.', 'woocommerce-deposits'),
                'id' => 'wc_deposits_gateways_title'
            );

            $gateways_array = array();
            $gateways = WC()->payment_gateways()->payment_gateways();
            if (isset($gateways['wc-booking-gateway'])) unset($gateways['wc-booking-gateway']);// Protect the wc-booking-gateway

            foreach ($gateways as $key => $gateway) {

                $gateways_array[$key] = $gateway->title;
            }


            $gateways_settings['wc_deposits_disallowed_gateways_for_deposit'] = array(
                'name' => __('Disallowed For Deposits', 'woocommerce-deposits'),
                'type' => 'multiselect',
                'class' => 'chosen_select',
                'options' => $gateways_array,
                'desc' => __('Disallowed For Deposits', 'woocommerce-deposits'),
                'id' => 'wc_deposits_disallowed_gateways_for_deposit',
            );

            $gateways_settings['wc_deposits_disallowed_gateways_for_second_payment'] = array(
                'name' => __('Disallowed For Partial Payments', 'woocommerce-deposits'),
                'type' => 'multiselect',
                'class' => 'chosen_select',
                'options' => $gateways_array,
                'desc' => __('Disallowed For Partial Payments', 'woocommerce-deposits'),
                'id' => 'wc_deposits_disallowed_gateways_for_second_payment',
            );


            $gateways_settings['gateways_end'] = array(
                'type' => 'sectionend',
                'id' => 'wc_deposits_gateways_end'
            );


            woocommerce_admin_fields($gateways_settings);

            ?>
            <?php do_action('wc_deposits_settings_tabs_gateways_tab'); ?>

        </div>

        <?php
    }

    function tab_auto_updates_output($active)
    {
        $class = $active ? '' : 'hidden';
        ?>
        <div id="auto_updates" class="wcdp-tab-content wrap <?php echo $class; ?>">
            <?php
            $purchase_code_guide = 'https://help.market.envato.com/hc/en-us/articles/202822600';

            $auto_updates_fields = array(
                'auto_updates_settings' => array(
                    'name' => __('Automatic Updates', 'woocommerce-deposits'),
                    'type' => 'title',
                    'id' => 'wc_deposits_auto_updates_settings_title'
                ),
                'wc_deposits_purchase_code' => array(
                    'name' => __('Purchase code', 'woocommerce-deposits'),
                    'type' => 'text',
                    'desc' => sprintf(__('Insert your <a  target="_blank" href="%s"> purchase code </a> to receive automatic updates.', 'woocommerce-deposits'), $purchase_code_guide),
                    'id' => 'wc_deposits_purchase_code',
                )
            );
            woocommerce_admin_fields($auto_updates_fields);
            ob_start();
            ?>
            <tr>
                <td></td>
                <td>
                    <?php wp_nonce_field('wcdp_verify_purchase_code', 'wcdp_verify_purchase_code_nonce', false, true); ?>
                    <button class="button button-primary"
                            id="wc_deposits_verify_purchase_code"><?php _e('Verify purchase code', 'woocommerce-deposits'); ?></button>
                    <div id="wcdp_verify_purchase_container">

                    </div>
                </td>
            </tr>
            <?php
            echo ob_get_clean();
            woocommerce_admin_fields(array(
                'auto_updates_end' => array(
                    'type' => 'sectionend',
                    'id' => 'wc_deposits_auto_updates_end'
                )));
            ?>
        </div>

        <?php
    }

    function tab_advanced_output($active)
    {
        $class = $active ? '' : 'hidden';
        ?>
        <div id="advanced" class="wcdp-tab-content wrap <?php echo $class; ?>">
            <?php

            $advanced_fields = array(
                'advanced_title' => array(
                    'name' => __('Advanced', 'woocommerce-deposits'),
                    'type' => 'title',
                    'desc' => '',
                    'id' => 'wc_deposits_advanced_title'
                ),
//                'debug_mode' => array(
//                    'name' => __( 'Enable debug mode' , 'woocommerce-deposits' ) ,
//                    'type' => 'checkbox' ,
//                    'desc' => __( 'Check this to enable debug mode.' , 'woocommerce-deposits' ) ,
//                    'id' => 'wc_deposits_debug_mode' ,
//                ) ,
                'advanced_end' => array(
                    'type' => 'sectionend',
                    'id' => 'wc_deposits_advanced_end'
                )
            );
            woocommerce_admin_fields($advanced_fields);
            ?>
        </div>

        <?php
    }

    /*** END TABS CONTENT CALLBACKS **/

    /*** BEGIN DEPOSIT OPTIONS CUSTOM FIELDS CALLBACKS **/
    function reminder_datepicker()
    {

        $reminder_date = get_option('wc_deposits_reminder_datepicker');
        ob_start();

        ?>
        <script>
            jQuery(function ($) {
                $("#reminder_datepicker").datepicker({

                    dateFormat: "dd-mm-yy",
                    minDate: new Date()

                }).datepicker("setDate", "<?php echo $reminder_date; ?>");
            });
        </script>
        <p>
            <b><?php _e('If you would like to send out all partial payment reminders on a specific date in the future, set a date below.', 'woocommerce-deposits'); ?></b>
        </p>
        <p> <?php _e('Next Custom Reminder Date :', 'woocommerce-deposits') ?> <input type="text"
                                                                                      name="wc_deposits_reminder_datepicker"
                                                                                      id="reminder_datepicker"></p>
        <?php
        echo ob_get_clean();
    }

    public function deposit_buttons_color()
    {

        $colors = get_option('wc_deposits_deposit_buttons_colors');
        $primary_color = $colors['primary'];
        $secondary_color = $colors['secondary'];
        $highlight_color = $colors['highlight'];;

        ?>
        <tr valign="top" class="">
            <th scope="row"
                class="titledesc"><?php _e('Deposit Buttons Primary Colour', 'woocommerce-deposits'); ?></th>
            <td class="forminp forminp-checkbox">
                <fieldset>
                    <input type="text" name="wc_deposits_deposit_buttons_colors_primary" class="deposits-color-field"
                           value="<?php echo $primary_color; ?>">
                </fieldset>
            </td>
        </tr>
        <tr valign="top" class="">
            <th scope="row"
                class="titledesc"><?php _e('Deposit Buttons Secondary Colour', 'woocommerce-deposits'); ?></th>
            <td class="forminp forminp-checkbox">
                <fieldset>
                    <input type="text" name="wc_deposits_deposit_buttons_colors_secondary" class="deposits-color-field"
                           value="<?php echo $secondary_color; ?>">
                </fieldset>
            </td>
        </tr>
        <tr valign="top" class="">
            <th scope="row"
                class="titledesc"><?php _e('Deposit Buttons Highlight Colour', 'woocommerce-deposits'); ?></th>
            <td class="forminp forminp-checkbox">
                <fieldset>
                    <input type="text" name="wc_deposits_deposit_buttons_colors_highlight" class="deposits-color-field"
                           value="<?php echo $highlight_color; ?>">
                </fieldset>
            </td>
        </tr>
        <?php
    }

    /*** END  DEPOSIT OPTIONS CUSTOM FIELDS CALLBACKS **/


    function verify_purchase_code()
    {


        if (!wp_verify_nonce($_POST['nonce'], 'wcdp_verify_purchase_code'))
            wp_die();


        $purchase_code = isset($_POST['purchase_code']) && !empty($_POST['purchase_code']) ? $_POST['purchase_code'] : false;
        if ($purchase_code) {


            update_option('wc_deposits_purchase_code', $purchase_code);

            //verify code
            $verify_code = $this->wc_deposits->admin_auto_updates->verify_purchase_code($purchase_code);

            if ($verify_code === 'valid') {


                //do not show update option anymore
                update_option('wc_deposits_hide_activation_notice', 'yes');
                update_option('wc_deposits_purchase_code_verified', 'yes');

                wp_send_json_success(__('Thank You. Purchase code verified successfully.', 'woocommerce-deposits'));


            } elseif ($verify_code === 'invalid') {

                update_option('wc_deposits_purchase_code_verified', 'no');
                wp_send_json_error(__('Invalid Purchase code', 'woocommerce-deposits'));

            } else {


                //error contacting server please try again later or contact plugin support
                update_option('wc_deposits_purchase_code_verified', 'no');
                wp_send_json_error(sprintf(__('Error verifying purchase code, please try again later. If issue persist, please submit a  ticket to our <a target="_blank" href="%s"> support platform. </a> ', 'woocommerce-deposits'), 'https://webtomizer.ticksy.com'));

            }

        }


        wp_die();

    }

    /**
     * @brief Save all settings on POST
     *
     * @return void
     */
    public function update_options_wc_deposits()
    {
        $allowed_html = array(
            'a' => array('href' => true, 'title' => true),
            'br' => array(), 'em' => array(),
            'strong' => array(), 'p' => array(),
            's' => array(), 'strike' => array(),
            'del' => array(), 'u' => array()
        );

        $settings = array();


        $settings ['wc_deposits_site_wide_disable'] = isset($_POST['wc_deposits_site_wide_disable']) ? 'yes' : 'no';

        $settings['wc_deposits_default_option'] = isset($_POST['wc_deposits_default_option']) ?
            ($_POST['wc_deposits_default_option'] === 'deposit' ? 'deposit' : 'full') : 'deposit';

        $settings['wc_deposits_reduce_stock'] = isset($_POST['wc_deposits_reduce_stock']) ?
            ($_POST['wc_deposits_reduce_stock'] === 'deposit' ? 'deposit' : 'full') : 'full';
        $settings['wc_deposits_tax_display'] = isset($_POST['wc_deposits_tax_display']) ? 'yes' : 'no';
        $settings['wc_deposits_tax_display_cart_item'] = isset($_POST['wc_deposits_tax_display_cart_item']) ? 'yes' : 'no';
        $settings['wc_deposits_breakdown_cart_tooltip'] = isset($_POST['wc_deposits_breakdown_cart_tooltip']) ? 'yes' : 'no';
        $settings['wc_deposits_override_payment_form'] = isset($_POST['wc_deposits_override_payment_form']) ? 'yes' : 'no';
        $settings['wc_deposits_use_basic_radio_buttons'] = isset($_POST['wc_deposits_use_basic_radio_buttons']) ? 'yes' : 'no';

        $settings ['wc_deposits_partially_paid_orders_editable'] = isset($_POST['wc_deposits_partially_paid_orders_editable']) ? 'yes' : 'no';
        $settings ['wc_deposits_order_list_table_show_has_deposit'] = isset($_POST['wc_deposits_order_list_table_show_has_deposit']) ? 'yes' : 'no';
        $settings ['wc_deposits_disable_deposit_for_user_roles'] = isset($_POST['wc_deposits_disable_deposit_for_user_roles']) ? $_POST['wc_deposits_disable_deposit_for_user_roles'] : array();
        $settings ['wc_deposits_restrict_deposits_for_logged_in_users_only'] = isset($_POST['wc_deposits_restrict_deposits_for_logged_in_users_only']) ? 'yes' : 'no';


        //STRINGS
        $settings['wc_deposits_to_pay_text'] = isset($_POST['wc_deposits_to_pay_text']) ? esc_html($_POST['wc_deposits_to_pay_text']) : 'To Pay';
        $settings['wc_deposits_second_payment_text'] = isset($_POST['wc_deposits_second_payment_text']) ? esc_html($_POST['wc_deposits_second_payment_text']) : 'Future Payments';
        $settings['wc_deposits_deposit_amount_text'] = isset($_POST['wc_deposits_deposit_amount_text']) ? esc_html($_POST['wc_deposits_deposit_amount_text']) : 'Deposit Amount';
        $settings['wc_deposits_deposit_option_text'] = isset($_POST['wc_deposits_deposit_option_text']) ? esc_html($_POST['wc_deposits_deposit_option_text']) : 'Deposit Option';
        $settings['wc_deposits_payment_link_text'] = isset($_POST['wc_deposits_payment_link_text']) ? esc_html($_POST['wc_deposits_payment_link_text']) : 'Payment Link';

        $settings['wc_deposits_deposit_buttons_colors'] = array(

            'primary' => isset($_POST['wc_deposits_deposit_buttons_colors_primary']) ? $_POST['wc_deposits_deposit_buttons_colors_primary'] : false,
            'secondary' => isset($_POST['wc_deposits_deposit_buttons_colors_secondary']) ? $_POST['wc_deposits_deposit_buttons_colors_secondary'] : false,
            'highlight' => isset($_POST['wc_deposits_deposit_buttons_colors_highlight']) ? $_POST['wc_deposits_deposit_buttons_colors_highlight'] : false
        );

        $settings['wc_deposits_checkout_mode_enabled'] = isset($_POST['wc_deposits_checkout_mode_enabled']) ? 'yes' : 'no';
        $settings['wc_deposits_checkout_mode_force_deposit'] = isset($_POST['wc_deposits_checkout_mode_force_deposit']) ? 'yes' : 'no';
        $settings['wc_deposits_checkout_mode_deposit_amount'] = isset($_POST['wc_deposits_checkout_mode_deposit_amount']) ? $_POST['wc_deposits_checkout_mode_deposit_amount'] : '0';
        $settings['wc_deposits_checkout_mode_deposit_amount_type'] = isset($_POST['wc_deposits_checkout_mode_deposit_amount_type']) ? $_POST['wc_deposits_checkout_mode_deposit_amount_type'] : 'percentage';
        $settings['wc_deposits_checkout_mode_payment_plans'] = isset($_POST['wc_deposits_checkout_mode_payment_plans']) ? $_POST['wc_deposits_checkout_mode_payment_plans'] : array();

        $settings['wc_deposits_fees_handling'] = isset($_POST['wc_deposits_fees_handling']) ? $_POST['wc_deposits_fees_handling'] : 'full';
        $settings['wc_deposits_taxes_handling'] = isset($_POST['wc_deposits_taxes_handling']) ? $_POST['wc_deposits_taxes_handling'] : 'full';
        $settings['wc_deposits_shipping_handling'] = isset($_POST['wc_deposits_shipping_handling']) ? $_POST['wc_deposits_shipping_handling'] : 'full';
        $settings['wc_deposits_shipping_taxes_handling'] = isset($_POST['wc_deposits_shipping_taxes_handling']) ? $_POST['wc_deposits_shipping_taxes_handling'] : 'full';
        $settings['wc_deposits_coupons_handling'] = isset($_POST['wc_deposits_coupons_handling']) ? $_POST['wc_deposits_coupons_handling'] : 'full';
        $settings['wc_deposits_remaining_payable'] = isset($_POST['wc_deposits_remaining_payable']) ? 'yes' : 'no';
        $settings['wc_deposits_enable_second_payment_reminder'] = isset($_POST['wc_deposits_enable_second_payment_reminder']) ? 'yes' : 'no';
        $settings['wc_deposits_second_payment_due_after'] = isset($_POST['wc_deposits_second_payment_due_after']) ? $_POST['wc_deposits_second_payment_due_after'] : '';
        $settings['wc_deposits_second_payment_reminder_duration'] = isset($_POST['wc_deposits_second_payment_reminder_duration']) ? $_POST['wc_deposits_second_payment_reminder_duration'] : '0';
        $settings['wc_deposits_button_deposit'] = isset($_POST['wc_deposits_button_deposit']) ? esc_html($_POST['wc_deposits_button_deposit']) : 'Pay Deposit';
        $settings['wc_deposits_button_full_amount'] = isset($_POST['wc_deposits_button_full_amount']) ? esc_html($_POST['wc_deposits_button_full_amount']) : 'Full Amount';
        $settings['wc_deposits_message_deposit'] = isset($_POST['wc_deposits_message_deposit']) ? wp_kses($_POST['wc_deposits_message_deposit'], $allowed_html) : '';
        $settings['wc_deposits_message_full_amount'] = isset($_POST['wc_deposits_message_full_amount']) ? wp_kses($_POST['wc_deposits_message_full_amount'], $allowed_html) : '';


        //partial payment reminder
        $settings['wc_deposits_enable_partial_payment_reminder'] = isset($_POST['wc_deposits_enable_partial_payment_reminder']) ? 'yes' : 'no';
        $settings['wc_deposits_partial_payment_reminder_x_days_before_due_date'] = isset($_POST['wc_deposits_partial_payment_reminder_x_days_before_due_date']) ? $_POST['wc_deposits_partial_payment_reminder_x_days_before_due_date'] : '3';

        //gateway options
        $settings ['wc_deposits_disallowed_gateways_for_deposit'] = isset($_POST['wc_deposits_disallowed_gateways_for_deposit']) ? $_POST['wc_deposits_disallowed_gateways_for_deposit'] : array();
        $settings ['wc_deposits_disallowed_gateways_for_second_payment'] = isset($_POST['wc_deposits_disallowed_gateways_for_second_payment']) ? $_POST['wc_deposits_disallowed_gateways_for_second_payment'] : array();


        //custom reminder date
        $settings['wc_deposits_reminder_datepicker'] = isset($_POST['wc_deposits_reminder_datepicker']) ? $_POST['wc_deposits_reminder_datepicker'] : '';


        //advanced settings
//        $settings [ 'wc_deposits_debug_mode' ] = isset( $_POST[ 'wc_deposits_debug_mode' ] ) ? 'yes' : 'no';


        foreach ($settings as $key => $setting) {
            update_option($key, $setting);

        }


    }

}