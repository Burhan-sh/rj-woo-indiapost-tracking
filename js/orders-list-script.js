/**
 * JavaScript for handling tracking inputs in WooCommerce orders list
 */
jQuery(document).ready(function($) {
    // Handle click on "Add" button
    $(document).on('click', '.rj-list-add-tracking', function(e) {
        e.preventDefault();
        
        var btn = $(this);
        var orderId = btn.data('order-id');
        var inputField = $('#rj-tracking-number-' + orderId);
        var trackingNumber = inputField.val().trim();
        var messageContainer = $('#rj-tracking-message-' + orderId);
        
        // Validation
        if (!trackingNumber) {
            messageContainer.text('Please enter a tracking number').addClass('error').show();
            setTimeout(function() {
                messageContainer.fadeOut();
            }, 3000);
            return;
        }
        
        // Disable button and show loading state
        btn.prop('disabled', true).text(rj_list_vars.adding_text);
        
        // Send AJAX request
        $.ajax({
            url: rj_list_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'rj_indiapost_list_save_tracking',
                order_id: orderId,
                tracking_number: trackingNumber,
                security: rj_list_vars.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Show success message
                    messageContainer.text(rj_list_vars.success_text).removeClass('error').addClass('success').show();
                    
                    // Update display
                    $('#rj-tracking-info-' + orderId + ' .rj-list-tracking-number').text(response.data.tracking_number);
                    
                    // Hide input, show tracking info
                    $('#rj-tracking-input-' + orderId).hide();
                    $('#rj-tracking-info-' + orderId).show();
                    
                    // Hide message after delay
                    setTimeout(function() {
                        messageContainer.fadeOut();
                    }, 2000);
                } else {
                    // Show error message
                    messageContainer.text(response.data.message || rj_list_vars.error_text).removeClass('success').addClass('error').show();
                    
                    setTimeout(function() {
                        messageContainer.fadeOut();
                    }, 3000);
                }
                
                // Reset button state
                btn.prop('disabled', false).text(rj_list_vars.add_text);
            },
            error: function() {
                // Show error message
                messageContainer.text(rj_list_vars.error_text).removeClass('success').addClass('error').show();
                
                setTimeout(function() {
                    messageContainer.fadeOut();
                }, 3000);
                
                // Reset button state
                btn.prop('disabled', false).text(rj_list_vars.add_text);
            }
        });
    });
    
    // Handle click on "Edit" link
    $(document).on('click', '.rj-list-edit-tracking', function(e) {
        e.preventDefault();
        
        var orderId = $(this).data('order-id');
        
        // Hide tracking info, show input
        $('#rj-tracking-info-' + orderId).hide();
        $('#rj-tracking-input-' + orderId).show();
        
        // Focus on input field
        $('#rj-tracking-number-' + orderId).focus();
    });
    
    // Handle Enter key in input field
    $(document).on('keypress', '.rj-list-tracking-number-input', function(e) {
        if (e.which === 13) { // Enter key
            e.preventDefault();
            
            // Trigger click on the corresponding Add button
            var orderId = $(this).attr('id').replace('rj-tracking-number-', '');
            $('.rj-list-add-tracking[data-order-id="' + orderId + '"]').click();
        }
    });
}); 