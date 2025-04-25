jQuery(document).ready(function($) {
    // Handle GST report form submission
    $('#gst-tracking-upload-form').on('submit', function(e) {
        e.preventDefault();
        
        var formData = new FormData(this);
        formData.append('action', 'rj_generate_gst_report');
        formData.append('security', rj_gst_vars.nonce);
        
        // Show processing overlay
        $('#processing-overlay').show();
        $('.processing-text').text(rj_gst_vars.processing_text);
        $('.processing-subtext').text(rj_gst_vars.processing_subtext);
        
        $.ajax({
            url: rj_gst_vars.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    $('#gst-upload-response')
                        .removeClass('error')
                        .addClass('success')
                        .html(response.data.message);
                    
                    // Show download section if report was generated
                    if (response.data.report_id) {
                        $('.gst-download-section').show();
                        $('#download-gst-report').data('report-id', response.data.report_id);
                    }
                } else {
                    $('#gst-upload-response')
                        .removeClass('success')
                        .addClass('error')
                        .html(response.data.message);
                }
            },
            error: function() {
                $('#gst-upload-response')
                    .removeClass('success')
                    .addClass('error')
                    .html('Error processing request. Please try again.');
            },
            complete: function() {
                $('#processing-overlay').hide();
            }
        });
    });
    
    // Handle report download button click
    $('#download-gst-report').on('click', function() {
        var reportId = $(this).data('report-id');
        if (!reportId) {
            return;
        }
        
        // Create form and submit it to trigger file download
        var $form = $('<form>')
            .attr('method', 'post')
            .attr('action', rj_gst_vars.ajax_url)
            .append($('<input>')
                .attr('type', 'hidden')
                .attr('name', 'action')
                .attr('value', 'rj_download_gst_report')
            )
            .append($('<input>')
                .attr('type', 'hidden')
                .attr('name', 'security')
                .attr('value', rj_gst_vars.nonce)
            )
            .append($('<input>')
                .attr('type', 'hidden')
                .attr('name', 'report_id')
                .attr('value', reportId)
            );
        
        $('body').append($form);
        $form.submit();
        $form.remove();
        
        // Disable the download button after use
        $(this).prop('disabled', true);
    });
}); 