<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if (!class_exists('WC_Deposits_Email_Customer_Deposit_Paid')) :

    /**
     * Customer Partially Paid Email
     *
     * An email sent to the customer when a new order is partially paid.
     *
     */
    class WC_Deposits_Email_Customer_Deposit_Paid extends WC_Email
    {

        /**
         * Constructor
         */
        function __construct()
        {

            $this->id = 'customer_deposit_partially_paid';
            $this->title = __('Deposit Payment Received', 'woocommerce-deposits');
            $this->customer_email = true;

            $this->description = __('This is an order notification sent to the customer after partial-payment, containing order details and a link to pay the remaining balance.', 'woocommerce-deposits');


            $this->template_html = 'emails/customer-order-partially-paid.php';
            $this->template_plain = 'emails/plain/customer-order-partially-paid.php';

            // Triggers for this email
            add_action('woocommerce_order_status_pending_to_partially-paid_notification', array($this, 'trigger'));
            add_action('woocommerce_order_status_on-hold_to_partially-paid_notification', array($this, 'trigger'));
            add_action('woocommerce_order_status_failed_to_partially-paid_notification', array($this, 'trigger'));

            // Call parent constructor
            parent::__construct();

            $this->template_base = WC_DEPOSITS_TEMPLATE_PATH;
        }


        /**
         * Trigger the sending of this email.
         *
         * @param int $order_id The order ID.
         * @param WC_Order $order Order object.
         */
        public function trigger($order_id, $order = false)
        {
            if ($order_id && !is_a($order, 'WC_Order')) {
                $order = wc_get_order($order_id);

            }

            if (is_a($order, 'WC_Order')) {
                $this->object = $order;


                $this->placeholders['{order_date}'] = wc_format_datetime($this->object->get_date_created());
                $this->placeholders['{order_number}'] = $this->object->get_order_number();
                if ($this->object->get_status() === 'partially-paid' && get_option('wc_deposits_remaining_payable', 'yes') === 'yes') {
                    $payment_link_text = get_option('wc_deposits_payment_link_text' , __('Payment Link', 'woocommerce-deposits'));
                    $this->placeholders['{wcdp_payment_link}'] = '<a href="' . esc_url($this->object->get_checkout_payment_url()) . '">' . $payment_link_text . '</a>';
                } else {
                    $this->placeholders['{wcdp_payment_link}'] = '';
                }

            }
            $this->recipient = $this->object->get_billing_email();

            if (!$this->is_enabled() || !$this->get_recipient()) {
                return;
            }

            $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
        }


        /**
         * get_content_html function.
         *
         * @access public
         */
        function get_content_html()
        {

            return wc_get_template_html($this->template_html, array(
                'order' => $this->object,
                'email_heading' => $this->get_heading(),
                'email_text' => $this->get_email_text(),
                'payment_text' => $this->get_payment_text(),
                'additional_content' => version_compare(WOOCOMMERCE_VERSION, '3.7.0', '<') ? '' : $this->get_additional_content(), 'sent_to_admin' => false,
                'plain_text' => false,
                'email' => $this,
            ), '', $this->template_base);
        }

        /**
         * get_content_plain function.
         *
         * @access public
         */
        function get_content_plain()
        {
            
            return wc_get_template_html($this->template_html, array(
                'order' => $this->object,
                'email_heading' => $this->get_heading(),
                'additional_content' => version_compare(WOOCOMMERCE_VERSION, '3.7.0', '<') ? '' : $this->get_additional_content(), 'sent_to_admin' => false,
                'email_text' => $this->get_email_text(),
                'payment_text' => $this->get_payment_text(),
                'plain_text' => true,
                'email' => $this,
            ), '', $this->template_base);
        }


        public function get_default_subject()
        {
            return __('Your {site_title} order receipt from {order_date}', 'woocommerce-deposits');
        }

        /**
         * Get email heading.
         *
         * @return string
         * @since  3.1.0
         */
        public function get_default_heading()
        {
            return __('Thank you for your order', 'woocommerce-deposits');
        }

        public function get_default_email_text()
        {
            return __("Your deposit has been received and your order is now being processed. Your order details are shown below for your reference:", 'woocommerce-deposits');

        }

        public function get_default_payment_text()
        {
            return __('To pay the remaining balance, please visit this {wcdp_payment_link}', 'woocommerce-deposits');
        }

        function get_email_text()
        {
            $text = $this->get_option('email_text', $this->get_default_email_text());
            return $this->format_string($text);
        }

        function get_payment_text()
        {
            $text = $this->get_option('payment_text', $this->get_default_payment_text());
            return $this->format_string($text);
        }

        public function init_form_fields()
        {
            /* translators: %s: list of placeholders */
            $placeholder_text = sprintf(__('Available placeholders: %s', 'woocommerce'), '<code>' . esc_html(implode('</code>, <code>', array_keys($this->placeholders))) . '</code>');
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable this email notification', 'woocommerce'),
                    'default' => 'yes',
                ),
                'subject' => array(
                    __('Subject', 'woocommerce'),
                    'type' => 'text',
                    'description' => $placeholder_text,
                    'desc_tip' => true,
                    'placeholder' => $this->get_default_subject(),
                    'default' => $this->get_default_subject(),
                ),
                'heading' => array(
                    'title' => __('Email heading', 'woocommerce'),
                    'type' => 'text',
                    'desc_tip' => true,
                    'description' => $placeholder_text,
                    'placeholder' => $this->get_default_heading(),
                    'default' => $this->get_default_heading(),
                ),
                'email_text' => array(
                    'title' => __('Email text', 'woocommerce'),
                    'placeholder' => $this->get_default_email_text(),
                    'default' => $this->get_default_email_text(),
                    'css' => 'width:400px; height: 75px;',
                    'type' => 'textarea',
                    'desc_tip' => true,
                ),
                'payment_text' => array(
                    'title' => __('Payment text', 'woocommerce-deposits'),
                    'description' => __('Text to appear with payment link', 'woocommerce-deposits') . ' ' . $placeholder_text,
                    'placeholder' => $this->get_default_payment_text(),
                    'default' => $this->get_default_payment_text(),
                    'css' => 'width:400px; height: 75px;',
                    'type' => 'textarea',
                    'desc_tip' => true,
                ),
                'additional_content' => array(
                    'title' => __('Additional content', 'woocommerce'),
                    'description' => __('Text to appear below the main email content.', 'woocommerce') . ' ' . $placeholder_text,
                    'placeholder' => __('N/A', 'woocommerce'),
                    'css' => 'width:400px; height: 75px;',
                    'type' => 'textarea',
                    'default' => $this->get_default_additional_content(),
                    'desc_tip' => true,
                ),
                'email_type' => array(
                    'title' => __('Email type', 'woocommerce'),
                    'type' => 'select',
                    'description' => __('Choose which format of email to send.', 'woocommerce'),
                    'default' => 'html',
                    'class' => 'email_type wc-enhanced-select',
                    'options' => $this->get_email_type_options(),
                    'desc_tip' => true,
                ),
            );
        }

    }

endif;

return new WC_Deposits_Email_Customer_Deposit_Paid();
