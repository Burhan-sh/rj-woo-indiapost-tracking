jQuery(document).ready(function($) {
    // Generate QR code if tracking number exists
    function generateQRCode(trackingNumber) {
        var qrContainer = $('#rj_indiapost_qr_code');
        if (qrContainer.length && trackingNumber) {
            qrContainer.empty();
            
            // Create QR code
            var qr = qrcode(0, 'M');
            qr.addData(trackingNumber);
            qr.make();
            
            // Append QR code to container
            qrContainer.html(qr.createImgTag(4));
        }
    }
    
    // Generate QR code on page load if tracking number exists
    var qrContainer = $('#rj_indiapost_qr_code');
    if (qrContainer.length) {
        var trackingNumber = qrContainer.data('tracking');
        if (trackingNumber) {
            generateQRCode(trackingNumber);
        }
    }
    
    // Enable/disable the process button based on tracking number input
    $('#rj_indiapost_tracking_number').on('input', function() {
        var trackingNumber = $(this).val().trim();
        $('#rj_indiapost_add_tracking_btn').prop('disabled', trackingNumber === '');
    });
    
    // Process tracking number when button is clicked
    $('#rj_indiapost_add_tracking_btn').on('click', function() {
        var btn = $(this);
        var container = $('.rj-indiapost-tracking-container');
        var trackingNumber = $('#rj_indiapost_tracking_number').val().trim();
        var orderId = container.data('order-id');
        
        if (!trackingNumber) {
            showMessage('Please enter a tracking number.', 'error');
            return;
        }
        
        // Disable button and show loading state
        btn.prop('disabled', true).text(tracking_vars.saving_text);
        
        // Send AJAX request
        $.ajax({
            url: tracking_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'rj_indiapost_save_tracking',
                order_id: orderId,
                tracking_number: trackingNumber,
                security: tracking_vars.nonce
            },
            success: function(response) {
                if (response.success) {
                    showMessage(response.data.message, 'success');
                    
                    // Refresh the page to show QR code
                    location.reload();
                } else {
                    showMessage(response.data.message, 'error');
                }
                
                // Reset button state
                btn.prop('disabled', false).text(tracking_vars.add_text);
            },
            error: function() {
                showMessage(tracking_vars.error_text, 'error');
                
                // Reset button state
                btn.prop('disabled', false).text(tracking_vars.add_text);
            }
        });
    });
    
    // Reset tracking number when reset button is clicked
    $('#rj_indiapost_reset_tracking_btn').on('click', function() {
        var btn = $(this);
        var container = $('.rj-indiapost-tracking-container');
        var orderId = container.data('order-id');
        var trackingInput = $('#rj_indiapost_tracking_number');
        
        // Confirm before resetting
        if (!confirm(tracking_vars.reset_confirm_text || 'Are you sure you want to reset the tracking number?')) {
            return;
        }
        
        // Disable button and show loading state
        btn.prop('disabled', true);
        $('#rj_indiapost_add_tracking_btn').prop('disabled', true);
        
        // Clear the input field
        trackingInput.val('');
        
        // Send AJAX request to save empty tracking number
        $.ajax({
            url: tracking_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'rj_indiapost_save_tracking',
                order_id: orderId,
                tracking_number: '',
                security: tracking_vars.nonce
            },
            success: function(response) {
                if (response.success) {
                    showMessage(tracking_vars.reset_success_text || 'Tracking number has been reset.', 'success');
                    
                    // Refresh the page to remove QR code
                    location.reload();
                } else {
                    showMessage(response.data.message, 'error');
                }
                
                // Reset button state
                btn.prop('disabled', false);
                $('#rj_indiapost_add_tracking_btn').prop('disabled', true);
            },
            error: function() {
                showMessage(tracking_vars.error_text, 'error');
                
                // Reset button state
                btn.prop('disabled', false);
            }
        });
    });
    
    // Helper function to show messages
    function showMessage(message, type) {
        var messageElement = $('#tracking_message');
        messageElement.removeClass('success error').addClass(type);
        messageElement.html(message).show();
        
        // Hide message after 5 seconds
        setTimeout(function() {
            messageElement.fadeOut();
        }, 5000);
    }
});