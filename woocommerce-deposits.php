<?php
/**
 * Plugin Name: hausgroup Deposits Payment
 * Description: Adds deposits support to WooCommerce.
 * Version: 3.0.3
 * Author: WebPerfection
 * Text Domain: woocommerce-deposits
 */
namespace Webtomizer\WCDP;

use stdClass;

if (!defined('ABSPATH')) {
    exit;
}

require_once('includes/wc-deposits-functions.php');

if (wc_deposits_woocommerce_is_active()) :

    /**
     * @brief Main WC_Deposits class
     *
     */
    class WC_Deposits
    {

        // Components
        public $cart;
        public $add_to_cart;
        public $orders;
        public $taxonomies;
        public $reminders;
        public $emails;
        public $checkout;
        public $compatibility;
        public $gateways;
        public $admin_product;
        public $admin_order;
        public $admin_list_table_orders;
        public $admin_list_table_partial_payments;
        public $admin_settings;
        public $admin_reports;
        public $admin_notices = array();
        public $admin_auto_updates;
        public $wc_version_disabled = false;

        /**
         * @brief Returns the global instance
         *
         * @param array $GLOBALS ...
         * @return mixed
         */
        public static function &get_singleton()
        {
            if (!isset($GLOBALS['wc_deposits']))
                $GLOBALS['wc_deposits'] = new WC_Deposits();


            return $GLOBALS['wc_deposits'];
        }

        /**
         * @brief Constructor
         *
         * @return void
         */
        private function __construct()
        {
            define('WC_DEPOSITS_VERSION', '3.0.3');
            define('WC_DEPOSITS_TEMPLATE_PATH', untrailingslashit(plugin_dir_path(__FILE__)) . '/templates/');
            define('WC_DEPOSITS_PLUGIN_PATH', plugin_dir_path(__FILE__));
            define('WC_DEPOSITS_PLUGIN_URL', untrailingslashit(plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__))));
            define('WC_DEPOSITS_MAIN_FILE', __FILE__);
            define('WC_DEPOSITS_PAYMENT_PLAN_TAXONOMY', 'wcdp_payment_plan');

            $this->compatibility = new stdClass();

            if (version_compare(PHP_VERSION, '5.6.0', '<')) {


                if (is_admin()) {
                    add_action('admin_notices', array($this, 'show_admin_notices'));
                    $this->enqueue_admin_notice(sprintf(__('%s Requiresnpm PHP version %s or higher.'), __('WooCommerce Deposits', 'woocommerce-deposits')  , '5.6'), 'error');
                }

                return;

            }


            add_action('init', array($this, 'load_plugin_textdomain'), 0);
            add_action('init', array($this, 'check_version_disable'), 0);
            add_action('init', array($this, 'register_order_status'));
            add_action('init', array($this, 'register_wcdp_payment_post_type'), 6);

            add_action('woocommerce_init', array($this, 'early_includes'));
            add_action('woocommerce_init', array($this, 'admin_includes'));
            add_action('woocommerce_init', array($this, 'includes'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts_and_styles'));
            add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));

        
            if (is_admin()) {

                //plugin row urls in plugins page
                add_filter('plugin_row_meta', array($this, 'plugin_row_meta'), 10, 2);

                add_action('admin_notices', array($this, 'show_admin_notices'));

                add_action('current_screen', array($this, 'setup_screen'), 10);
                add_action('wp_loaded', array($this, 'update_database'), 10);


            }

        }

        function check_version_disable()
        {
            if (function_exists('WC') && version_compare(WC()->version, '3.7.0', '<')) {

                $this->wc_version_disabled = true;

                if (is_admin()) {
                    add_action('admin_notices', array($this, 'show_admin_notices'));
                    $this->enqueue_admin_notice(sprintf(__('%s Requires PHP version %s or higher.'), __('WooCommerce Deposits', 'woocommerce-deposits')  , '3.7.0'), 'error');
                }
            }

        }

        function register_wcdp_payment_post_type()
        {

            if ($this->wc_version_disabled) return;
            wc_register_order_type(
                'wcdp_payment',

                array(
                    // register_post_type() params
                    'labels' => array(
                        'name' => __('Partial Payments', 'woocommerce-deposits'),
                        'singular_name' => __('Partial Payment', 'woocommerce-deposits'),
                        'edit_item' => _x('Edit Partial Payment', 'custom post type setting', 'woocommerce-deposits'),
                        'search_items' => __('Search Partial Payments', 'woocommerce-deposits'),
                        'parent' => _x('Order', 'custom post type setting', 'woocommerce-deposits'),
                        'menu_name' => __('Partial Payments', 'woocommerce-deposits'),
                    ),
                    'public' => false,
                    'show_ui' => true,
                    'capability_type' => 'shop_order',
                    'capabilities' => array(
                        'create_posts' => 'do_not_allow',
                    ),
                    'map_meta_cap' => true,
                    'publicly_queryable' => false,
                    'exclude_from_search' => true,
                    'show_in_menu' => current_user_can('manage_woocommerce') ? 'woocommerce' : true,
                    'hierarchical' => false,
                    'show_in_nav_menus' => false,
                    'rewrite' => false,
                    'query_var' => false,
                    'supports' => array('title', 'comments', 'custom-fields'),
                    'has_archive' => false,

                    // wc_register_order_type() params
                    'exclude_from_orders_screen' => true,
                    'add_order_meta_boxes' => true,
                    'exclude_from_order_count' => true,
                    'exclude_from_order_views' => true,
                    'exclude_from_order_webhooks' => true,
                    'exclude_from_order_reports' => true,
                    'exclude_from_order_sales_reports' => true,
                    'class_name' => 'WCDP_Payment',
                )

            );


        }

        function plugin_row_meta($links, $file)
        {

            if ($file === 'woocommerce-deposits/woocommerce-deposits.php') {

                $row_meta = array(
                    'settings' => '<a href="' . esc_url(admin_url('/admin.php?page=wc-settings&tab=wc-deposits&section=auto_updates')) . '"> ' . __('Settings', 'woocommerce-deposits') . '</a>',
                    'documentation' => '<a  target="_blank" href="' . esc_url('https://woocommerce-deposits.com/documentation') . '"> ' . __('Documentation', 'woocommerce-deposits') . '</a>',
                    'support' => '<a target="_blank" href="' . esc_url('https://webtomizer.ticksy.com') . '"> ' . __('Support', 'woocommerce-deposits') . '</a>',
                );

                $links = array_merge($links, $row_meta);
            }

            return $links;
        }

        /**
         * @brief Localisation
         *
         * @return void
         */
        public function load_plugin_textdomain()
        {


            load_plugin_textdomain('woocommerce-deposits', false, dirname(plugin_basename(__FILE__)) . '/locale/');
        }

        /**
         * @brief Enqueues front-end styles
         *
         * @return void
         */
        public function enqueue_styles()
        {
            if ($this->wc_version_disabled) return;
            if (!$this->is_disabled()) {
                wp_enqueue_style('toggle-switch', plugins_url('assets/css/toggle-switch.css', __FILE__), array(), '3.0', 'screen');
                wp_enqueue_style('wc-deposits-frontend-styles', plugins_url('assets/css/style.css', __FILE__));

                if (is_cart() || is_checkout()) {
                    $suffix = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '' : '.min';
                    wp_register_script('jquery-tiptip', WC()->plugin_url() . '/assets/js/jquery-tiptip/jquery.tipTip' . $suffix . '.js', array('jquery'), WC_VERSION, true);
                    wp_enqueue_script('wc-deposits-cart', WC_DEPOSITS_PLUGIN_URL . '/assets/js/wc-deposits-cart.js', array('jquery'), WC_DEPOSITS_VERSION, true);
                    wp_enqueue_script('jquery-tiptip');
                }

                //button css fix for storefront theme
                $active_theme = wp_get_theme();
                if ($active_theme->parent()) {
                    $theme_name = $active_theme->parent()->get('Name');

                } else {
                    $theme_name = $active_theme->get('Name');

                }



            }
        }

        /**
         * @brief Early includes
         *
         * @return void
         * @since 1.3
         *
         */
        public function early_includes()
        {
            if ($this->wc_version_disabled) return;
            include('includes/class-wc-deposits-emails.php');
            $this->emails = new WC_Deposits_Emails($this);

            include('includes/class-wc-deposits-reminders.php');
            $this->reminders = new WC_Deposits_Reminders();


        }

        /**
         * @brief Load classes
         *
         * @return void
         */
        public function includes()
        {

            if ($this->wc_version_disabled) return;
            if (!$this->is_disabled()) {

                include('includes/class-wc-deposits-cart.php');
                include('includes/class-wc-deposits-checkout.php');

                $this->cart = new WC_Deposits_Cart($this);
                $this->checkout = new WC_Deposits_Checkout($this);


                if (!wcdp_checkout_mode()) {
                    include('includes/class-wc-deposits-add-to-cart.php');
                    $this->add_to_cart = new WC_Deposits_Add_To_Cart($this);

                }


            }

            include('includes/admin/class-wc-deposits-taxonomies.php');
            require('includes/class-wcdp-payment.php');
            include('includes/class-wc-deposits-orders.php');
            $this->orders = new WC_Deposits_Orders($this);
            $this->taxonomies = new WC_Deposits_Taxonomies();


            /**
             * 3RD PARTY COMPATIBILITY
             */

            if (is_plugin_active('woocommerce-pdf-invoices-packing-slips/woocommerce-pdf-invoices-packingslips.php')) {

                $this->compatibility->pdf_invoices = include('includes/compatibility/pdf-invoices/main.php');
            }
        }


        function setup_screen()
        {

            if ($this->wc_version_disabled) return;

            $screen_id = false;

            if (function_exists('get_current_screen')) {
                $screen = get_current_screen();
                $screen_id = isset($screen, $screen->id) ? $screen->id : '';
            }

            if (!empty($_REQUEST['screen'])) { // WPCS: input var ok.
                $screen_id = wc_clean(wp_unslash($_REQUEST['screen'])); // WPCS: input var ok, sanitization ok.
            }


            switch ($screen_id) {
                case 'edit-shop_order' :
                    include('includes/admin/list-tables/class-wc-deposits-admin-list-table-orders.php');
                    $this->admin_list_table_orders = new WC_Deposits_Admin_List_Table_Orders($this);
                    break;
                case 'edit-wcdp_payment' :
                    include('includes/admin/list-tables/class-wc-deposits-admin-list-table-partial-payments.php');
                    $this->admin_list_table_partial_payments = new WC_Deposits_Admin_List_Table_Partial_Payments();
                    break;

            }
        }

        /**
         * @brief Load admin includes
         *
         * @return void
         */
        public function admin_includes()
        {
            if ($this->wc_version_disabled) return;

            include('includes/admin/class-wc-deposits-admin-settings.php');
            include('includes/admin/class-wc-deposits-admin-order.php');

            $this->admin_settings = new WC_Deposits_Admin_Settings($this);
            $this->admin_order = new WC_Deposits_Admin_Order($this);

            include('includes/admin/class-wc-deposits-admin-product.php');
            $this->admin_product = new WC_Deposits_Admin_Product($this);


            add_filter('woocommerce_admin_reports', array($this, 'admin_reports'));


            /**
             * AUTO UPDATE INSTANCE
             */
            if (is_admin()) {
                $purchase_code = get_option('wc_deposits_purchase_code', '');

                require_once 'includes/admin/class-envato-items-update-client.php';

                $this->admin_auto_updates = new Envato_items_Update_Client(
                    '9249233',
                    'woocommerce-deposits/woocommerce-deposits.php',
                    'https://www.woocommerce-deposits.com/wp-json/crze_eius/v1/update/',
                    'https://www.woocommerce-deposits.com/wp-json/crze_eius/v1/verify-purchase/',
                    $purchase_code
                );

                if (get_option('wc_deposits_purchase_code_verified', 'no') === 'yes') {
                    $this->admin_auto_updates->enable();
                }

            }
        }

        /**
         * @param $reports
         * @return mixed
         */
        public function admin_reports($reports)
        {
            if (!$this->admin_reports) {
                $admin_reports = include('includes/admin/class-wc-deposits-admin-reports.php');
                $this->admin_reports = $admin_reports;
            }
            return $this->admin_reports->admin_reports($reports);
        }

        /**
         * @brief Load admin scripts and styles
         * @return void
         */
        public function enqueue_admin_scripts_and_styles()
        {
            wp_enqueue_script('jquery');
            wp_enqueue_style('wc-deposits-admin-style', plugins_url('assets/css/admin-style.css', __FILE__));
        }

        /**
         * @brief Display all buffered admin notices
         *
         * @return void
         */
        public function show_admin_notices()
        {
            foreach ($this->admin_notices as $notice) {
                ?>
                <div class='notice notice-<?php echo esc_attr($notice['type']); ?>'>
                    <p><?php _e($notice['content'], 'woocommerce-deposits'); ?></p></div>
                <?php
            }
        }

        /**
         * @brief Add a new notice
         *
         * @param $content String notice contents
         * @param $type String Notice class
         *
         * @return void
         */
        public function enqueue_admin_notice($content, $type)
        {
            array_push($this->admin_notices, array('content' => $content, 'type' => $type));
        }

        /**
         * @return bool
         */
        public function is_disabled()
        {
            return get_option('wc_deposits_site_wide_disable') === 'yes';
        }

        public function update_database()
        {


            if (!is_admin()) return;


            if (version_compare(get_option('wc_deposits_db_version', '2.3.9'), '2.4.0', '<')) {


                //2.4 UPDATE REQUIRED

                //save gateways to new multiselect fields
                $deprecated_gateways_option = get_option('wc_deposits_disabled_gateways', array());
                if (!empty($deprecated_gateways_option)) {
                    $selected_gateways = array();
                    foreach ($deprecated_gateways_option as $key => $value) {

                        if ($value === 'yes') {
                            $selected_gateways[] = $key;
                        }

                    }

                    update_option('wc_deposits_disallowed_gateways_for_deposit', $selected_gateways);
                }

                update_option('wc_deposits_db_version', '2.4.0');

            }

            if (version_compare(get_option('wc_deposits_db_version', '2.3.9'), '2.5.0', '<')) {

                set_time_limit(600);

                //2.5.0 UPDATE REQUIRED

                //remove deprecated option
                delete_option('wc_deposits_enable_product_calculation_filter');


                //query for any order with deposit meta enabled


                $statuses = array_keys(wc_get_order_statuses());
                if (isset($statuses['wc-completed'])) unset($statuses['wc-completed']);


                $args = array(
                    'post_type' => 'shop_order',
                    'posts_per_page' => -1,
                    'post_status' => $statuses,
                    'meta_query' => array(
                        'has_deposit' => array(
                            'key' => '_wc_deposits_order_has_deposit',
                            'value' => "yes",
                            'compare' => '=',
                        ),
                        // no need to compare number because order version meta does not exist before this patch
                        array(
                            'key' => '_wc_deposits_order_version',
                            'compare' => 'NOT EXISTS',
                        ),

                    )
                );


                //query for all partially-paid orders
                $deposit_orders = new \WP_Query($args);

                while ($deposit_orders->have_posts()) :

                    $deposit_orders->the_post();
                    $order_id = $deposit_orders->post->ID;


                    $order = wc_get_order($order_id);

                    if (!$order) continue;
                    $deposit_amount = floatval($order->get_meta('_wc_deposits_deposit_amount', true));
                    $second_payment = floatval($order->get_meta('_wc_deposits_second_payment', true));


                    switch ($order->get_status()) {


                        case'completed' :
                        case'trash' :

                            break;

                        case'processing' :
                            $original_total = $order->get_meta('_wc_deposits_original_total', true);
                            if (is_numeric($original_total)) {

                                $order->set_total(floatval($original_total));
                                $order->save();
                            }

                            break;

                        default:

                            $order->set_total(floatval($deposit_amount + $second_payment));
                            $order->save();
                            $payment_schedule = wc_deposits_create_payment_schedule($order, $deposit_amount);

                            if ($order->get_meta('_wc_deposits_second_payment_paid', true) === 'yes') {
                                foreach ($payment_schedule as $payment) {


                                    $payment_order = wc_get_order($payment['id']);

                                    if ($payment_order) {
                                        $payment_order->set_status('completed');
                                        $payment_order->save();
                                    }

                                }

                            } elseif ($order->get_meta('_wc_deposits_deposit_paid', true) === 'yes') {

                                foreach ($payment_schedule as $payment) {

                                    if ($payment['type'] === 'deposit') {

                                        $payment_order = wc_get_order($payment['id']);

                                        if ($payment_order) {
                                            $payment_order->set_status('completed');
                                            $payment_order->save();
                                        }
                                    }
                                }

                            }


                            $order->save();

                            $order->add_meta_data('_wc_deposits_payment_schedule', $payment_schedule, true);

                            $order->update_meta_data('_wc_deposits_order_version', '2.5.0');

                            $order->save();
                            break;
                    }


                endwhile;

//                if (!$deposit_orders->have_posts()) {
                update_option('wc_deposits_db_version', '2.5.0');
//                }
            }

            if (version_compare(get_option('wc_deposits_db_version', '2.3.9'), '3.0.0', '<')) {

                delete_option('wc_deposits_payment_status_text');
                delete_option('wc_deposits_deposit_pending_payment_text');
                delete_option('wc_deposits_deposit_paid_text');
                delete_option('wc_deposits_order_fully_paid_text');
                delete_option('wc_deposits_deposit_previously_paid_text');
                delete_option('wc_deposits_second_payment_amount_text');
            }
        }

        /**
         * @brief Register a custom order status
         *
         * @return void
         * @since 1.3
         *
         */
        public function register_order_status()
        {

            register_post_status('wc-partially-paid', array(
                'label' => _x('Partially Paid', 'Order status', 'woocommerce-deposits'),
                'public' => true,
                'exclude_from_search' => false,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
                'label_count' => _n_noop('Partially Paid <span class="count">(%s)</span>',
                    'Partially Paid <span class="count">(%s)</span>', 'woocommerce-deposits')
            ));

        }


        public static function plugin_activated()
        {

            update_option('wc_deposits_instance', time() + (86400 * 7));

            if (!wp_next_scheduled('woocommerce_deposits_second_payment_reminder')) {
                wp_schedule_event(time(), 'daily', 'woocommerce_deposits_second_payment_reminder');
            }
        }

        public static function plugin_deactivated()
        {
            wp_clear_scheduled_hook('woocommerce_deposits_second_payment_reminder');

        }



    }

    // Install the singleton instance
    WC_Deposits::get_singleton();
    register_activation_hook(__FILE__, array('WC_Deposits', 'plugin_activated'));
    register_deactivation_hook(__FILE__, array('WC_Deposits', 'plugin_deactivated'));


endif;


