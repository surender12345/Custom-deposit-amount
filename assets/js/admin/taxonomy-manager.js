jQuery(document).ready(function ($) {
    var plan_container = $('#payment_plan_details');

    if (plan_container.length > 0) {
        var plan_total = 0.0;
        var repeater_instance;
        var data = plan_container.data('populate');
        var update_date_display = function(checkbox){

            var row = $(checkbox).parent().parent();
            if(checkbox.is(':checked')){
                row.find('.wcdp-pp-after').hide().attr('disabled','disabled');
                row.find('.wcdp-pp-after-term').hide().attr('disabled','disabled');
                row.find('.wcdp-pp-date').show().removeAttr('disabled');
            } else {
                row.find('.wcdp-pp-after').show().removeAttr('disabled');
                row.find('.wcdp-pp-after-term').show().removeAttr('disabled');
                row.find('.wcdp-pp-date').hide().attr('disabled','disabled').val('');
            }

        };
        var update_total = function () {

            var plans = plan_container.find('.single_payment');

            plan_total = 0.0;
            $.each(plans, function (index, single_plan) {
                var field = $(single_plan).find('input').first();
                var field_val = $(field).val();
                if (field_val.length > 0) {

                    field_val = parseFloat(field_val);
                    if (typeof field_val === 'number') {
                        plan_total = plan_total + field_val;
                    }
                }
            });
            $('#total_percentage').text(plan_total + '%');
        }

        repeater_instance = plan_container.repeater({
            initEmpty: false,
            isFirstItemUndeletable: true,
            defaultValues: {
                'percent': '1.0',
                'after': '1',
                'after-term': 'day'
            },
            show: function () {

                var siblings_count = $(this).siblings().length;
                var number = siblings_count + 1;

                $(this).children().first('td').text('#' + number);
                $(this).slideDown();

                $('.single_payment input').on('input', function () {
                    update_total();
                });

                //fix issue with jquery repeater, for the init row
                $(this).find('.wcdp_pp_set_date').on('change', function () {
                    update_date_display($(this));
                });

                $.each($(this).find('.wcdp_pp_set_date'),function(index,checkbox){
                    update_date_display($(checkbox));
                });

            },
            hide: function (deleteElement) {
                    var count = 1;
                    $.each($(this).siblings(), function (index, sibling) {
                        $(sibling).children().first('td').text('#' + count);
                        count++;
                    })
                    $(this).slideUp(deleteElement);



            },
            ready: function (){
                update_total();
                $.each(plan_container.find('.wcdp_pp_set_date'),function(index,checkbox){
                    update_date_display($(checkbox));
                });
            }
        });
        if(typeof data['payment-plan'] !== 'undefined'){
            repeater_instance.setList(data['payment-plan']);
        }

        //fix issue with jquery repeater, for the init row
        $('.wcdp_pp_set_date').on('change', function () {
            update_date_display($(this));
        });

        //submission
        $('#edittag').submit(function () {
            var values = plan_container.repeaterVal();
            delete values['deposit-percentage'];
            $(this).append('<input name="payment-details" type="hidden" value=\'' + JSON.stringify(values) + '\'  />');

            return true;
        });
    }


});