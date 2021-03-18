<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if (!class_exists('WC_Deposits_Email_Customer_Remaining_Reminder')) :

    /**
     * Customer Partially Paid Email
     *
     * An email sent to the customer when a new order is partially paid.
     *
     */
    class WC_Deposits_Email_Customer_Remaining_Reminder extends WC_Email
    {

        private $payment_plan;
        private $partial_payment;

        /**
         * Constructor
         */
        function __construct()
        {

            $this->id = 'customer_second_payment_reminder';
            $this->title = __('Partial Payment Reminder', 'woocommerce-deposits');
            $this->description = __('Reminder of partially-paid order sent to the customer', 'woocommerce-deposits');
            $this->customer_email = true;


            $this->template_html = 'emails/customer-order-remaining-reminder.php';
            $this->template_plain = 'emails/plain/customer-order-remaining-reminder.php';
            // Triggers for this email
            add_action('woocommerce_deposits_second_payment_reminder_email_notification', array($this, 'trigger'), 10, 3);

            // Call parent constructor
            parent::__construct();
            $this->payment_plan = false;
            $this->partial_payment = false;
            $this->template_base = WC_DEPOSITS_TEMPLATE_PATH;
        }

        public function get_default_subject()
        {
            return __('Your {site_title} order partial payment reminder {order_date}', 'woocommerce-deposits');
        }

        public function get_subject()
        {
            $subject = $this->get_option('subject_partial', $this->get_default_subject(true));

            return $this->format_string($subject);
        }

        public function get_default_heading()
        {
            return __('Partial Payment Reminder #{order_number}', 'woocommerce-deposits');
        }

        public function get_heading()
        {
            $heading = $this->get_option('heading_partial', $this->get_default_heading(true));

            return $this->format_string($heading);

        }


        public function get_default_email_text()
        {
            return __("Kindly be reminded that your order\'s partial payment is still pending payment.", 'woocommerce-deposits');

        }

        public function get_default_payment_text()
        {
            return __('To make payment, please visit this link: {wcdp_payment_link}', 'woocommerce-deposits');
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


        /**
         * trigger function.
         *
         * @access public
         * @return void
         */
        function trigger($order_id, $payment_plan = false, $partial_payment = false)
        {



            if ($order_id) {
                $this->object = wc_get_order($order_id);
                $this->recipient = $this->object->get_billing_email();

                $this->placeholders['{order_date}'] = wc_format_datetime($this->object->get_date_created());
                $this->placeholders['{order_number}'] = $this->object->get_order_number();
                if ($this->object->get_status() === 'partially-paid' && get_option('wc_deposits_remaining_payable', 'yes') === 'yes') {
                    $payment_link_text = get_option('wc_deposits_payment_link_text' , __('Payment Link', 'woocommerce-deposits'));
                    $this->placeholders['{wcdp_payment_link}'] = '<a href="' . esc_url($this->object->get_checkout_payment_url()) . '">' . $payment_link_text . '</a>';
                } else {
                    $this->placeholders['{wcdp_payment_link}'] = '';
                }
            }

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
                'additional_content' => version_compare(WOOCOMMERCE_VERSION, '3.7.0', '<') ? '' : $this->get_additional_content(),
                'email_text' => $this->get_email_text(),
                'payment_text' => $this->get_payment_text(),
                'payment_plan' => $this->payment_plan,
                'partial_payment' => $this->partial_payment,
                'sent_to_admin' => false,
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
                'email_text' => $this->get_email_text(),
                'payment_text' => $this->get_payment_text(),
                'additional_content' => version_compare(WOOCOMMERCE_VERSION, '3.7.0', '<') ? '' : $this->get_additional_content(), 'sent_to_admin' => false,
                'payment_plan' => $this->payment_plan,
                'partial_payment' => $this->partial_payment,
                'plain_text' => true,
                'email' => $this,
            ), '', $this->template_base);
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
                    'title' => __('Partial payment reminder subject', 'woocommerce-deposits'),
                    'type' => 'text',
                    'desc_tip' => true,
                    'description' => sprintf(__('For partial payment reminders (%s)', 'woocommerce-deposits'),__('Payment plans', 'woocommerce-deposits')),
                    'placeholder' => $this->get_default_subject(),
                    'default' => $this->get_default_subject(),
                ),
                'heading' => array(
                    'title' => __('Partial payment reminder heading', 'woocommerce'),
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
                    'css' => 'width:400px; height: 75px;',
                    'placeholder' => __('N/A', 'woocommerce'),
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

return new WC_Deposits_Email_Customer_Remaining_Reminder();
