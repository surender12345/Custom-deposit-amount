<?php

namespace Webtomizer\WCDP;


class WC_Deposits_Taxonomies
{


    function __construct()
    {

        add_action('init', array($this, 'register_payment_plan_taxonomy'), 10);
        add_action('wcdp_payment_plan_edit_form', array($this, 'payment_plan_table'), 10, 1);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'), 10, 2);
        add_action('edit_terms', array($this, 'edit_terms'), 10, 2);
        add_action('wcdp_payment_plan_term_new_form_tag', array($this, 'new_form_tag'), 10, 2);
        add_action('admin_enqueue_scripts', array($this, 'add_inline_style'));

    }

    function add_inline_style()
    {
        $screen = get_current_screen();
        if ($screen->id === 'edit-wcdp_payment_plan') {
            $style = '.term-slug-wrap , td.slug , th.column-slug , td.column-posts , th.column-posts { display:none!important; }';
            wp_add_inline_style('wc-deposits-admin-style', $style);
        }
    }

    function new_form_tag()
    {
        echo 'data-wcdp-form="yes"';
    }

    function enqueue_scripts()
    {
        $wc_ip_taxonomy = false;

        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if ($screen) {
                $wc_ip_taxonomy = $screen->id === 'edit-' . WC_DEPOSITS_PAYMENT_PLAN_TAXONOMY;
            }

        }

        if ($wc_ip_taxonomy) {
            wp_enqueue_script('wcdp_pb_jquery_repeater', WC_DEPOSITS_PLUGIN_URL . '/assets/js/admin/jquery.repeater.js', array('jquery'));
            wp_enqueue_script('wcdp_pb_taxonomy_manager', WC_DEPOSITS_PLUGIN_URL . '/assets/js/admin/taxonomy-manager.js');
            wp_localize_script('wcdp_pb_taxonomy_manager', 'wcip_data', array(
                'ajax_url' => admin_url('admin-ajax.php'),
            ));

        }
    }

    /**
     * Registers wcdp_payment_plan taxonomy for products
     */
    function register_payment_plan_taxonomy()
    {

        register_taxonomy(WC_DEPOSITS_PAYMENT_PLAN_TAXONOMY,
            array('product'),
            array(
                'label' => __('Payment plans', 'woocommerce-deposits'),
                'labels' => array(
                    'name' => __('Payment plans', 'woocommerce-deposits'),
                    'singular_name' => __('Payment plan', 'woocommerce-deposits'),
                    'menu_name' => __('Payment plans', 'woocommerce-deposits'),
                    'search_items' => __('Search plans', 'woocommerce-deposits'),
                    'all_items' => __('All plans', 'woocommerce-deposits'),
                    'edit_item' => __('Edit plan', 'woocommerce-deposits'),
                    'update_item' => __('Update plan', 'woocommerce-deposits'),
                    'add_new_item' => __('Add new plan', 'woocommerce-deposits'),
                    'new_item_name' => __('New plan name', 'woocommerce-deposits'),
                    'add_or_remove_items' => __('Add or remove plans', 'woocommerce-deposits'),
                    'not_found' => __('No plans found', 'woocommerce-deposits'),
                ),
                'hierarchical' => false,
                'meta_box_cb' => false,
                'show_ui' => true,
                'show_in_nav_menus' => true,
                'query_var' => is_admin(),
                'rewrite' => false,
                'public' => false
            ));

    }

    /**
     * adds payment plan fields to wcdp_payment_plan taxonomy editor page
     * @param $tag
     */
    function payment_plan_table($tag)
    {

        $term_types = array(
            'day' => 'Day(s)',
            'week' => 'Week(s)',
            'month' => 'Month(s)',
            'year' => 'Year(s)'
        );

        $deposit_percentage = get_term_meta($tag->term_id, 'deposit_percentage', true);
        $payment_details = get_term_meta($tag->term_id, 'payment_details', true);
        ob_start();
        ?>
        <hr/>
        <h3> <?php _e('Plan schedule', 'woocommerce-deposits'); ?></h3>
        <br/>
        <table class="widefat striped" data-populate='<?php echo $payment_details; ?>' id="payment_plan_details">
            <thead>

            <tr>
                <th>&nbsp;</th>
                <th><?php _e('Percentage', 'woocommerce-deposits'); ?></th>
                <th colspan="2"> <?php _e('Set date', 'woocommerce-deposits'); ?>
                    / <?php _e('After', 'woocommerce-deposits'); ?> </th>
                <td>&nbsp;</td>
            </tr>

            </thead>

            <tbody data-repeater-list="payment-plan">
            <tr>

                <td><strong> #1 </strong></td>
                <td class="single_payment">
                    <input name="deposit-percentage" type="number" min="0" step="0.1"
                           value="<?php echo $deposit_percentage; ?>"/>
                </td>
                <td colspan="2"><?php _e('Immediately', 'woocommerce-deposits'); ?></td>
                <td>&nbsp;</td>
            </tr>

            <tr class="single_payment" data-repeater-item>
                <td>
                    <strong> #2 </strong>
                </td>
                <td>
                    <input name="percentage" min="0.1" step="0.1" type="number" required="required"/>
                </td>
                <td>
                    <input class="wcdp-pp-date" name="date" type="date" required="required"/>
                    <input class="wcdp-pp-after" name="after" min="1" step="1" type="number" required="required"/>
                    <select class="wcdp-pp-after-term"  required="required" name="after-term">
                        <?php
                        foreach ($term_types as $key => $term_type) {
                            ?>
                        <option value="<?php echo $key; ?>"> <?php echo $term_type; ?> </option><?php
                        }
                        ?>
                    </select>
                </td>
                <td >
                    <input value="on" name="date_checkbox" type="checkbox" class="wcdp_pp_set_date" />
                    <label for="wcdp_pp_set_date"><?php  _e('Set a date','woocommerce-deposits');  ?> </label>
                </td>
                <td>
                    <input data-repeater-delete class="button" type="button" value="Delete"/>
                </td>
            </tr>

            </tbody>
            <tfoot>
            <tr>
                <td colspan="5"><input data-repeater-create class="button" type="button" value="Add"/></td>
            </tr>
            <tr>
                <td colspan="5">
                    <p> <?php _e('Total:','woocommerce-deposits'); ?> <span id="total_percentage"> </span></p>
                </td>
            </tr>
            </tfoot>
        </table>


        <?php
        echo ob_get_clean();
    }

    /**
     * Saves custom term meta for wcdp_payment_plan when payment plan data is saved
     * @param $term_id
     * @param $taxonomy
     */
    function edit_terms($term_id, $taxonomy)
    {

        if ($taxonomy === WC_DEPOSITS_PAYMENT_PLAN_TAXONOMY) {

            $payment_details = isset($_POST['payment-details']) ? $_POST['payment-details'] : array();
            $deposit_percentage = isset($_POST['deposit-percentage']) ? $_POST['deposit-percentage'] : 0.0;

            update_term_meta($term_id, 'deposit_percentage', $deposit_percentage);
            update_term_meta($term_id, 'payment_details', $payment_details);
        }

    }
}

