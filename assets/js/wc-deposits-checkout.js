jQuery(document).ready(function($) {

    $( document.body ).on( 'updated_checkout',function(){

        var options = wc_deposits_checkout_options;
        var form = $('#wc-deposits-options-form');
        var deposit = form.find('#pay-deposit');
        var deposit_label = form.find('#pay-deposit-label');
        var full = form.find('#pay-full-amount');
        var full_label = form.find('#pay-full-amount-label');
        var msg = form.find('#wc-deposits-notice');
        var amount = form.find('#deposit-amount');

        var update_message = function() {

            if (deposit.is(':checked')) {

                msg.html(options.message.deposit);
            } else if (full.is(':checked')) {
                msg.html(options.message.full);
            }
        };


        $('[name="wcdp-selected-plan"],[name="deposit-radio"]').on('change',function(){
            $( document.body ).trigger( 'update_checkout');
        });
        $('.checkout').on('change', 'input, select', update_message);
        update_message();

        if ($('#wcdp-payment-plans').length > 0) {


            $('#wcdp-payment-plans a.wcdp-view-plan-details').click(function () {
                var plan_id = $(this).data('id');
                var selector = '#plan-details-' + plan_id;
                if ($(this).data('expanded') === 'no') {
                    var text = $(this).data('hide-text');
                    $(this).text(text);
                    $(this).data('expanded', 'yes');
                    $(selector).slideDown();
                } else if ($(this).data('expanded') === 'yes') {
                    var text = $(this).data('view-text');
                    $(this).text(text);
                    $(this).data('expanded', 'no');
                    $(selector).slideUp();

                }

            });


        }




    });

    setTimeout(function(){
        jQuery(document).on('change','#change_ammount',function(){
              var customammount = jQuery(this).val(); 
              var total = jQuery('#cart_total').val();
              var per = jQuery('#cart_per').val();
              var totalammoun  =  (total * per / 100);
              console.log(per);
             console.log(total);
             // console.log(totalammoun);
              if(customammount < totalammoun){
                jQuery('.carterro').text('Enter your Deposit Amount min $'+totalammoun);
              }else if(customammount > parseFloat(total)){
                jQuery('.carterro').text('Enter your Deposit Amount less than $'+total);
              }else{
                  jQuery.ajax({
                     type : "post",
                     dataType : "json",
                     url : 'https://agent.hausgroup.com.au/wp-admin/admin-ajax.php',
                     data : {action: "pay_deposit", depositamount : customammount},
                     success: function(response) {
                        jQuery( document.body ).trigger( 'update_checkout');
                       
                        
                     }
                  }) 
              }
        });
     }, 5000);
    



});