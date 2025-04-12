/**
 * JavaScript for India Post Tracking CSV Upload
 */
(function($) {
    'use strict';
    
    $(document).ready(function() {
        // Initialize the form handling
        initTrackingCsvForm();
        
        // Initialize delete tracking links
        initDeleteTrackingLinks();
        
        // Initialize log file viewer
        initLogFileViewer();
    });
    
    /**
     * Initialize the CSV upload form
     */
    function initTrackingCsvForm() {
        var form = $('#tracking-csv-upload-form');
        var responseContainer = $('#upload-response');
        var submitButton = $('#upload-csv-btn');
        var processingOverlay = $('#processing-overlay');
        
        if (!form.length) {
            return;
        }
        
        form.on('submit', function(e) {
            e.preventDefault();
            
            // Check if file is selected
            var fileInput = $('#tracking-csv-file')[0];
            if (!fileInput.files.length) {
                showResponse(responseContainer, rj_tracking_vars.upload_error + ' ' + 'Please select a CSV file.', 'error');
                return;
            }
            
            // Check file extension
            var fileName = fileInput.files[0].name;
            var fileExt = fileName.split('.').pop().toLowerCase();
            if (fileExt !== 'csv') {
                showResponse(responseContainer, rj_tracking_vars.upload_error + ' ' + 'Please select a valid CSV file.', 'error');
                return;
            }
            
            // Show processing overlay
            processingOverlay.addClass('active');
            // Update the processing text with file name
            $('.processing-text').text(rj_tracking_vars.processing_text);
            $('.processing-subtext').html(rj_tracking_vars.processing_subtext + '<br>' + 
                                          'File: <strong>' + fileName + '</strong>');
            
            // Create FormData object
            var formData = new FormData();
            formData.append('action', 'rj_indiapost_upload_csv');
            formData.append('security', rj_tracking_vars.nonce);
            formData.append('tracking_csv_file', fileInput.files[0]);
            
            // Disable submit button and show loading indicator
            submitButton.prop('disabled', true).text(rj_tracking_vars.uploading_text);
            submitButton.after('<span class="upload-loading"></span>');
            
            // Send AJAX request
            $.ajax({
                url: rj_tracking_vars.ajax_url,
                type: 'POST',
                data: formData,
                contentType: false,
                processData: false,
                success: function(response) {
                    // Reset button
                    submitButton.prop('disabled', false).text('Upload CSV');
                    $('.upload-loading').remove();
                    
                    // Hide processing overlay
                    processingOverlay.removeClass('active');
                    
                    // Handle response
                    if (response.success) {
                        showResponse(responseContainer, response.data.message, 'success');
                        // Reset file input
                        fileInput.value = '';
                        
                        // Reload page after 2 seconds to show the new data
                        setTimeout(function() {
                            // If a log file was created, redirect to the log view
                            if (response.data.log_file) {
                                window.location.href = window.location.href.split('?')[0] + 
                                    '?page=indiapost-trackings&tab=logs&log=' + response.data.log_file;
                            } else {
                                location.reload();
                            }
                        }, 2000);
                    } else {
                        showResponse(responseContainer, response.data.message || rj_tracking_vars.upload_error, 'error');
                    }
                },
                error: function() {
                    // Reset button
                    submitButton.prop('disabled', false).text('Upload CSV');
                    $('.upload-loading').remove();
                    
                    // Hide processing overlay
                    processingOverlay.removeClass('active');
                    
                    // Show error message
                    showResponse(responseContainer, rj_tracking_vars.upload_error, 'error');
                }
            });
        });
    }
    
    /**
     * Initialize delete tracking links
     */
    function initDeleteTrackingLinks() {
        $(document).on('click', '.delete-tracking', function(e) {
            e.preventDefault();
            
            var trackingId = $(this).data('tracking-id');
            
            if (!trackingId) {
                return;
            }
            
            // Confirm deletion
            if (!confirm('Are you sure you want to delete this tracking number?')) {
                return;
            }
            
            // Get the current tab
            var currentTab = $('h2.nav-tab-wrapper .nav-tab-active').text().includes('EG') ? 'eg' : 'cg';
            
            // Prepare data
            var data = {
                action: 'bulk-' + (currentTab === 'eg' ? 'eg_trackings' : 'cg_trackings'),
                _wpnonce: $('#_wpnonce').val(),
                'tracking[]': trackingId,
                'action2': 'delete'
            };
            
            // Create and submit a form
            var $form = $('<form>', {
                'method': 'post',
                'action': window.location.href
            });
            
            // Add form fields
            $.each(data, function(key, value) {
                $form.append($('<input>', {
                    'type': 'hidden',
                    'name': key,
                    'value': value
                }));
            });
            
            // Append to body and submit
            $('body').append($form);
            $form.submit();
        });
    }
    
    /**
     * Initialize log file viewer functionality
     */
    function initLogFileViewer() {
        // Handle download button click
        $('.log-files-list').on('click', '.button[download]', function(e) {
            e.preventDefault();
            
            var downloadUrl = $(this).attr('href');
            var filename = $(this).closest('tr').find('td:nth-child(2)').text();
            
            // Create an invisible link and trigger download
            var link = document.createElement('a');
            link.href = downloadUrl;
            link.download = filename;
            link.style.display = 'none';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        });
    }
    
    /**
     * Show response message
     * 
     * @param {jQuery} container Response container
     * @param {string} message Message to display
     * @param {string} type 'success' or 'error'
     */
    function showResponse(container, message, type) {
        container.removeClass('upload-success upload-error')
                 .addClass('upload-' + type)
                 .html(message)
                 .show();
    }
    
})(jQuery); 