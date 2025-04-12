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
            var fileInput = $('#tracking-csv-file');
            if (fileInput[0].files.length === 0) {
                $('#upload-response').html('<div class="notice notice-error"><p>' + rj_tracking_vars.upload_error + '</p></div>');
                return;
            }
            
            // Get form data
            var formData = new FormData(this);
            formData.append('action', 'rj_indiapost_upload_csv');
            formData.append('security', rj_tracking_vars.nonce);
            
            // Show progress bar
            $('#upload-progress-container').show();
            $('#progress-status').text(rj_tracking_vars.processing_text);
            $('#progress-bar').css('width', '0%');
            $('#progress-percentage').text('0%');
            
            // Clear previous response
            $('#upload-response').empty();
            
            // Start the upload with progress tracking
            var xhr = new XMLHttpRequest();
            
            // Track progress (can be used for both upload and download)
            xhr.upload.addEventListener('progress', function(e) {
                if (e.lengthComputable) {
                    var percentComplete = Math.round((e.loaded / e.total) * 100);
                    $('#progress-bar').css('width', percentComplete + '%');
                    $('#progress-percentage').text(percentComplete + '%');
                }
            });
            
            // Setup for completion
            xhr.addEventListener('load', function() {
                if (xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        
                        // Update progress to 100% on successful upload
                        $('#progress-bar').css('width', '100%');
                        $('#progress-percentage').text('100% - ' + rj_tracking_vars.upload_success);
                        
                        if (response.success) {
                            // Display success message with details
                            $('#upload-response').html(
                                '<div class="notice notice-success"><p>' + 
                                response.data.message + 
                                '</p></div>'
                            );
                            
                            // Reset form after successful upload
                            $('#tracking-csv-upload-form')[0].reset();
                            
                            // Refresh the page after 2 seconds to show the new data
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            // Show error message
                            $('#upload-response').html(
                                '<div class="notice notice-error"><p>' + 
                                response.data.message + 
                                '</p></div>'
                            );
                        }
                    } catch (e) {
                        // Show parsing error
                        $('#upload-response').html(
                            '<div class="notice notice-error"><p>' + 
                            rj_tracking_vars.upload_error + 
                            '</p></div>'
                        );
                    }
                } else {
                    // Show HTTP error
                    $('#upload-response').html(
                        '<div class="notice notice-error"><p>' + 
                        rj_tracking_vars.upload_error + ' (' + xhr.status + ')' + 
                        '</p></div>'
                    );
                }
            });
            
            // Handle errors
            xhr.addEventListener('error', function() {
                $('#upload-response').html(
                    '<div class="notice notice-error"><p>' + 
                    rj_tracking_vars.upload_error + 
                    '</p></div>'
                );
            });
            
            // Handle abortion
            xhr.addEventListener('abort', function() {
                $('#upload-response').html(
                    '<div class="notice notice-error"><p>' + 
                    'Upload was aborted.' + 
                    '</p></div>'
                );
            });
            
            // Send the data
            xhr.open('POST', rj_tracking_vars.ajax_url, true);
            xhr.send(formData);
            
            // Simulate processing progress after upload completes (just for visual feedback)
            var processingTimer;
            var processingProgress = 0;
            
            function simulateProcessing() {
                processingProgress += 5;
                if (processingProgress <= 90) { // Only go up to 90%, the server will set 100% when done
                    $('#progress-bar').css('width', processingProgress + '%');
                    $('#progress-percentage').text(processingProgress + '%');
                    processingTimer = setTimeout(simulateProcessing, 500);
                }
            }
            
            // Start processing simulation after 1 second (assuming upload is quick)
            setTimeout(function() {
                simulateProcessing();
            }, 1000);
            
            // Stop simulation when we get a response
            xhr.addEventListener('load', function() {
                clearTimeout(processingTimer);
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