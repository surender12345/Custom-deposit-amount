jQuery(document).ready(function ($) {
    var options = wc_deposits_add_to_cart_options;

    $.fn.initDepositController = function () {
        var depositController = {
            init: function (form, ajax_reload = false) {
                depositController.update_html_request = false;

                var $cart = $(form);
                var $form = $cart.find('.webtomizer_wcdp_single_deposit_form');
                depositController.$cart = $cart;
                depositController.$form = $form;
                $form.deposit = $form.find('.pay-deposit').get(0);
                $form.full = $form.find('.pay-full-amount').get(0);
                $form.msg = $form.find('.wc-deposits-notice');
                $form.amount = $form.find('.deposit-amount');
                $form.payment_plans_container = $form.find(('.wcdp-payment-plans'));
                $cart.woocommerce_products_addons = $cart.find('#product-addons-total');
                //hide deposit form initially in variable product
                var product_elem = $form.closest('.product');
                if (!ajax_reload && product_elem.hasClass('product-type-variable')) {
                    $form.slideUp();
                }


                if ($cart.woocommerce_products_addons.length > 0) {
                    $cart.on('updated_addons', this.addons_updated);
                }


                $cart.on('change', 'input, select', this.update_status);

                if(!ajax_reload){

                    $cart.on('show_variation', this.update_variation)
                        .on('click', '.reset_variations', function () {
                            $($form).slideUp();
                        });
                    $cart.on('hide_variation', this.hide)

                }

                this.update_status($form);
                $form.on('update_html', this.update_html);

                if ($($form.payment_plans_container).length > 0) {
                    this.update_payment_plans_container();
                }


            },
            hide: function(){
                depositController.$form.slideUp();

            },
            update_payment_plans_container: function () {
                depositController.$form.payment_plans_container.find('a.wcdp-view-plan-details').click(function () {
                    var plan_id = $(this).data('id');
                    var selector = '.plan-details-' + plan_id;
                    if ($(this).data('expanded') === 'no') {
                        var text = $(this).data('hide-text');
                        $(this).text(text);
                        $(this).data('expanded', 'yes');
                        depositController.$form.find(selector).slideDown();
                    } else if ($(this).data('expanded') === 'yes') {
                        var text = $(this).data('view-text');
                        $(this).text(text);
                        $(this).data('expanded', 'no');
                        depositController.$form.find(selector).slideUp();
                    }

                });
            },
            addons_updated: function () {
                var addons_form = depositController.$cart.woocommerce_products_addons;

                var data = {price: 0, product_id: $(addons_form).data('product-id') ,trigger:'woocommerce_product_addons'};
                data.price = $(addons_form).data('price');
                if(depositController.$cart.find('#wc-bookings-booking-form').length){
                    //addons + bookings
                    if($('.wc-bookings-booking-cost').length > 0 ){

                        var booking_price = parseFloat($('.wc-bookings-booking-cost').attr('data-raw-price'));
                        if(!Number.isNaN(booking_price)){
                            data.price =booking_price;
                        }
                    } else {
                        data.price = 0;
                    }
                }

                var addons_price = $(addons_form).data('price_data');
                $.each(addons_price, function (index, single_addon) {
                    data.price = data.price + single_addon.cost;
                });
                depositController.$form.trigger('update_html', data);


            },
            update_html: function (e, data) {

                if (!data) return;
                if (Number.isNaN(data.price)) return;
                if (!data.product_id) return;

                if(depositController.$cart.woocommerce_products_addons.length && data.trigger !== 'woocommerce_product_addons' ) return;

                if(depositController.update_html_request){
                    depositController.update_html_request.abort();
                    depositController.update_html_request = false;
                };


                depositController.$form.block({
                    message: null,
                    overlayCSS: {
                        background: "#fff",
                        backgroundSize: "16px 16px", opacity: .6
                    }
                });

                var data = {
                    action: 'wc_deposits_update_deposit_container',
                    price: data.price,
                    product_id: data.product_id
                };

                depositController.update_html_request =  $.post(options.ajax_url, data).done(function (res) {
                    if (res.success) {
                        depositController.$form.replaceWith(res.data);
                        depositController.init(depositController.$cart, true);
                    } else {

                    }
                    depositController.$form.unblock();
                }).fail(function () {
                    // alert('Error occurred');

                });

            },
            update_status: function () {
                console.log(options.message);
                console.log($(depositController.$form.msg));
                if ($(depositController.$form.deposit).is(':checked')) {
                    if (depositController.$form.payment_plans_container.length > 0) {
                        depositController.$form.payment_plans_container.slideDown();
                    }

                    $(depositController.$form.msg).html(options.message.deposit);
                } else if ($(depositController.$form.full).is(':checked')) {
                    if (depositController.$form.payment_plans_container.length > 0) {
                        depositController.$form.payment_plans_container.slideUp();
                    }
                    $(depositController.$form.msg).html(options.message.full);
                }
            },
            update_variation: function (event, variation) {

                var id = variation.variation_id;
                var data = {
                    product_id: id
                };
                depositController.$form.trigger('update_html', data);
                return;

            }
        };

        depositController.init(this);

    };

    // Quick view
    $('body').find('form.cart').each(function (index, elem) {
        $(elem).initDepositController();
    });

});

