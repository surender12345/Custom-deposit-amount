jQuery(document).ready(function ($) {
    $('body')
        .on('change', '.woocommerce_order_items input.deposit_paid', function () {
            var row = $(this).closest('tr.item');
            var paid = $(this).val();
            var remaining = $('input.deposit_remaining', row);
            var total = $('input.line_total', row);
            if (paid !== '' && parseFloat(total.val()) - parseFloat(paid) > 0)
                remaining.val(parseFloat(total.val()) - parseFloat(paid));
            else
                remaining.val('');
        })
        .on('change', '.woocommerce_order_items input.line_total', function () {
            var row = $(this).closest('tr.item');
            var remaining = $('input.deposit_remaining', row);
            var paid = $('input.deposit_paid', row);
            var total = $(this).val();
            if (paid.val() !== '' && parseFloat(total) - parseFloat(paid.val()) >= 0)
                remaining.val(parseFloat(total) - parseFloat(paid.val()));
            else
                remaining.val('');
        })
        .on('change', '.woocommerce_order_items input.quantity', function () {
            var row = $(this).closest('tr.item');
            var remaining = $('input.deposit_remaining', row);
            var paid = $('input.deposit_paid', row);
            var total = $('input.line_total');
            setTimeout(function () {
                if (paid.val() !== '' && remaining.val() !== '' && parseFloat(total.val()) - parseFloat(paid.val()) >= 0)
                    remaining.val(parseFloat(total.val()) - parseFloat(paid.val()));
                else
                    remaining.val('');
            }, 0);
        })
        .on('change', '.wc-order-totals .edit input#_order_remaining', function () {
            // update paid amount when remaining changes
            var remaining = $(this);
            var paid = $('.wc-order-totals .edit input#_order_paid');
            var total = $('.wc-order-totals .edit input#_order_total');
            setTimeout(function () {
                if (remaining.val() !== '' && total.val() !== '')
                    paid.val(parseFloat(total.val()) - parseFloat(remaining.val()));
                else
                    paid.val('');
            }, 0);
        })
        .on('change', '.wc-order-totals .edit input#_order_paid', function () {
            // update remaining amount when paid amount changes
            var paid = $(this);
            var remaining = $('.wc-order-totals .edit input#_order_remaining');
            var total = $('.wc-order-totals .edit input#_order_total');
            setTimeout(function () {
                if (paid.val() !== '' && total.val() !== '')
                    remaining.val(parseFloat(total.val()) - parseFloat(paid.val()));
                else
                    remaining.val('');
            }, 100);
        })
        .on('change', '.wc-order-totals .edit input#_order_total', function () {
            // update remaining amount when total amount changes
            var total = $(this);
            var remaining = $('.wc-order-totals .edit input#_order_remaining');
            var paid = $('.wc-order-totals .edit input#_order_paid');
            setTimeout(function () {
                if (paid.val() !== '' && total.val() !== '')
                    remaining.val(parseFloat(total.val()) - parseFloat(paid.val()));
                else
                    remaining.val('');
            }, 0);
        });


    var wcdp_recalculate_modal = {
        target : 'wcdp-modal-recalculate-deposit',
        init : function(){
            $( document.body )
                .on( 'wc_backbone_modal_loaded', this.backbone.init )
                .on( 'wc_backbone_modal_response', this.backbone.response );
        },
        blockOrderItems: function() {
            $( '#woocommerce-order-items' ).block({
                message: null,
                overlayCSS: {
                    background: '#fff',
                    opacity: 0.6
                }
            });
        },
        unblockOrderItems: function() {
            $( '#woocommerce-order-items' ).unblock();
        },
        backbone : {
            init: function( e, target ) {
                if(target === wcdp_recalculate_modal.target ){
                    $.each($('.wcdp_calculator_modal_row'), function (index, row) {
                        var enabled = $($(row).find('input.wcdp_enable_deposit'));
                        var deposit_amount = $($(row).find('input.wc_deposits_deposit_amount'));
                        var payment_plan = $($(row).find('select.wc_deposits_payment_plan')).select2();
                        var amount_type = $($(row).find('select.wc_deposits_deposit_amount_type'));
                        enabled.on('change', function () {
                            if ($(this).is(':checked')) {
                                $(row).find(':input:not(.wcdp_enable_deposit)').removeAttr('disabled');

                            } else {
                                $(row).find(':input:not(.wcdp_enable_deposit)').attr('disabled','disabled');

                            }
                        });
                        //one time exec
                        if(amount_type.val() !== 'payment_plan') payment_plan.next().addClass('wcdp-hidden');

                        amount_type.on('change',function(){
                            if($(this).val() === 'payment_plan'){
                                payment_plan.next().removeClass('wcdp-hidden');
                                deposit_amount.addClass('wcdp-hidden');
                            }else {
                                payment_plan.next().addClass('wcdp-hidden');
                                deposit_amount.removeClass('wcdp-hidden');
                            }
                        });

                    });
                }

            },
            response: function( e, target,form_data ) {
                if(target === wcdp_recalculate_modal.target ){
                    wcdp_recalculate_modal.blockOrderItems();

                        var data = {
                            action: 'wc_deposits_recalculate_deposit',
                            order_id: woocommerce_admin_meta_boxes.post_id,
                            security: woocommerce_admin_meta_boxes.order_item_nonce,
                            order_items: form_data
                        };

                        $.ajax({
                            url: woocommerce_admin_meta_boxes.ajax_url,
                            data: data,
                            type: 'POST',

                            success: function (response) {

                                if (response.success) {
                                    wcdp_recalculate_modal.unblockOrderItems();
                                    // location.reload();
                                    $('#_order_deposit').remove();
                                    location.reload();
                                    // $('#woocommerce-order-items button.save-action').trigger('click');
                                }
                            }
                        });
                }
            }
        }
    };
    wcdp_recalculate_modal.init();


    function reload_metabox() {

        $('#wc_deposits_partial_payments').block({
            message: null,
            overlayCSS: {
                background: '#fff',
                opacity: 0.6
            }
        });

        var data = {
            action: 'wc_deposits_reload_partial_payments_metabox',
            order_id: woocommerce_admin_meta_boxes.post_id,
            security: wc_deposits_data.security
        };

        $.ajax(
            {
                url: wc_deposits_data.ajax_url,
                data: data,
                type: 'POST',
                success: function (res) {
                    if (res.success) {

                        $('#wc_deposits_partial_payments div.inside').empty().append(res.data.html);
                        $('#woocommerce-order-items').unblock();
                        $('#wc_deposits_partial_payments').unblock().trigger('wc_deposits_recalculated');
                        $('#wc_deposits_partial_payments').trigger('wc_deposits_recalculated');

                    }
                }

            }
        );

    }

})
;
