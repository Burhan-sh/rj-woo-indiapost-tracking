<?php
/**
 * India Post Tracking CSV Upload Functionality with Logging
 * 
 * Adds a menu in WordPress admin dashboard for uploading tracking CSV files
 * and maintains logs of upload operations
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main class for India Post Tracking CSV Upload
 */
class RJ_IndiaPost_Tracking_CSV_Upload {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Add admin menu
        add_action('admin_menu', array($this, 'add_tracking_menu'));
        
        // Register scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'register_admin_assets'));
        
        // Add AJAX handler for CSV upload
        add_action('wp_ajax_rj_indiapost_upload_csv', array($this, 'handle_csv_upload'));
        
        // New actions for GST reports
        add_action('wp_ajax_rj_generate_gst_report', array($this, 'handle_gst_report_generation'));
        add_action('wp_ajax_rj_download_gst_report', array($this, 'handle_gst_report_download'));
        
        // Initialize tables on plugin activation
        register_activation_hook(__FILE__, array($this, 'create_tracking_tables'));
        
        // Create logs directory if it doesn't exist
        $this->ensure_logs_directory_exists();
    }
    
    /**
     * Add Tracking menu to admin dashboard
     */
    public function add_tracking_menu() {
        add_menu_page(
            __('Trackings', 'rj-woo-indiapost-tracking'),
            __('Trackings', 'rj-woo-indiapost-tracking'),
            'manage_options',
            'indiapost-trackings',
            array($this, 'render_tracking_page'),
            'dashicons-media-spreadsheet',
            25
        );
    }
    
    /**
     * Register admin assets
     */
    public function register_admin_assets($hook) {
        // Only load on tracking page
        if ($hook != 'toplevel_page_indiapost-trackings') {
            return;
        }
        
        // Enqueue CSS
        wp_enqueue_style(
            'rj-indiapost-tracking-csv',
            plugin_dir_url(__FILE__) . 'css/tracking-csv.css',
            array(),
            '1.0.3'
        );
        
        // Enqueue JS
        wp_enqueue_script(
            'rj-indiapost-tracking-csv-js',
            plugin_dir_url(__FILE__) . 'js/tracking-csv.js',
            array('jquery'),
            '1.0.0',
            true
        );
        
        // Add new script for GST reports functionality
        wp_enqueue_script(
            'rj-indiapost-gst-reports-js',
            plugin_dir_url(__FILE__) . 'js/gst-reports.js',
            array('jquery'),
            '1.0.0',
            true
        );
        
        // Localize script with necessary data
        wp_localize_script(
            'rj-indiapost-tracking-csv-js',
            'rj_tracking_vars',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('rj_indiapost_tracking_csv_nonce'),
                'uploading_text' => __('Uploading...', 'rj-woo-indiapost-tracking'),
                'upload_success' => __('CSV uploaded successfully!', 'rj-woo-indiapost-tracking'),
                'upload_error' => __('Error uploading CSV. Please try again.', 'rj-woo-indiapost-tracking'),
                'processing_text' => __('Processing CSV File...', 'rj-woo-indiapost-tracking'),
                'processing_subtext' => __('Please wait while we process your tracking numbers.', 'rj-woo-indiapost-tracking')
            )
        );
        
        // Localize script for GST reports
        wp_localize_script(
            'rj-indiapost-gst-reports-js',
            'rj_gst_vars',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('rj_indiapost_gst_nonce'),
                'processing_text' => __('Generating GST Report...', 'rj-woo-indiapost-tracking'),
                'processing_subtext' => __('Please wait while we process your data.', 'rj-woo-indiapost-tracking')
            )
        );
    }
    
    /**
     * Get count of available tracking numbers
     * 
     * @return array Counts of available EG and CG tracking numbers
     */
    private function get_available_tracking_counts() {
        global $wpdb;
        
        $eg_table_name = $wpdb->prefix . 'EG_india_post_tracking';
        $cg_table_name = $wpdb->prefix . 'CG_india_post_tracking';
        
        $eg_count = $wpdb->get_var("SELECT COUNT(*) FROM {$eg_table_name} WHERE order_id IS NULL OR order_id = ''");
        $cg_count = $wpdb->get_var("SELECT COUNT(*) FROM {$cg_table_name} WHERE order_id IS NULL OR order_id = ''");
        
        return array(
            'eg_count' => (int)$eg_count,
            'cg_count' => (int)$cg_count
        );
    }
    
    /**
     * Render the tracking page
     */
    public function render_tracking_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Get available tracking counts
        $available_counts = $this->get_available_tracking_counts();
        
        // Include the list tables class
        if (!class_exists('WP_List_Table')) {
            require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
        }
        
        // Include our list table classes
        require_once plugin_dir_path(__FILE__) . 'tracking-list-tables.php';
        
        // Get the active tab
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'eg';
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <!-- Processing Overlay -->
            <div id="processing-overlay" class="processing-overlay">
                <div class="processing-spinner"></div>
                <div class="processing-text"><?php _e('Processing CSV File...', 'rj-woo-indiapost-tracking'); ?></div>
                <div class="processing-subtext"><?php _e('Please wait while we process your tracking numbers. This may take a moment depending on the file size.', 'rj-woo-indiapost-tracking'); ?></div>
            </div>
            
            <!-- Available Tracking Numbers Summary -->
            <div class="tracking-summary" style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h3 style="margin-top: 0;"><?php _e('Available Tracking Numbers', 'rj-woo-indiapost-tracking'); ?></h3>
                <div class="tracking-counts" style="display: flex; gap: 20px;">
                    <div class="eg-count" style="flex: 1; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                        <h4 style="margin: 0; color: #1e88e5;"><?php _e('EG Tracking Numbers', 'rj-woo-indiapost-tracking'); ?></h4>
                        <p style="font-size: 24px; margin: 10px 0; color: #2196f3;"><?php echo number_format($available_counts['eg_count']); ?></p>
                        <span style="color: #666;"><?php _e('available for assignment', 'rj-woo-indiapost-tracking'); ?></span>
                    </div>
                    <div class="cg-count" style="flex: 1; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                        <h4 style="margin: 0; color: #43a047;"><?php _e('CG Tracking Numbers', 'rj-woo-indiapost-tracking'); ?></h4>
                        <p style="font-size: 24px; margin: 10px 0; color: #4caf50;"><?php echo number_format($available_counts['cg_count']); ?></p>
                        <span style="color: #666;"><?php _e('available for assignment', 'rj-woo-indiapost-tracking'); ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Upload Form -->
            <div class="tracking-upload-container">
                <h2><?php _e('Upload Tracking CSV', 'rj-woo-indiapost-tracking'); ?></h2>
                <div class="tracking-upload-form">
                    <form id="tracking-csv-upload-form" method="post" enctype="multipart/form-data">
                        <div class="form-group">
                            <label for="tracking-csv-file"><?php _e('Choose a CSV file (1 column with tracking numbers):', 'rj-woo-indiapost-tracking'); ?></label>
                            <input type="file" name="tracking_csv_file" id="tracking-csv-file" accept=".csv" required>
                        </div>
                        
                        <div class="form-actions">
                            <?php wp_nonce_field('rj_indiapost_tracking_csv_upload', 'tracking_csv_nonce'); ?>
                            <button type="submit" id="upload-csv-btn" class="button button-primary">
                                <?php _e('Upload CSV', 'rj-woo-indiapost-tracking'); ?>
                            </button>
                        </div>
                    </form>
                    <div id="upload-response" class="upload-response"></div>
                </div>
            </div>
            
            <!-- Tabs for tracking lists -->
            <h2 class="nav-tab-wrapper">
                <a href="?page=indiapost-trackings&tab=eg" class="nav-tab <?php echo $active_tab == 'eg' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('EG Tracking List', 'rj-woo-indiapost-tracking'); ?>
                </a>
                <a href="?page=indiapost-trackings&tab=cg" class="nav-tab <?php echo $active_tab == 'cg' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('CG Tracking List', 'rj-woo-indiapost-tracking'); ?>
                </a>
                <a href="?page=indiapost-trackings&tab=logs" class="nav-tab <?php echo $active_tab == 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Upload Logs', 'rj-woo-indiapost-tracking'); ?>
                </a>
                <a href="?page=indiapost-trackings&tab=gst" class="nav-tab <?php echo $active_tab == 'gst' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('GST Reports', 'rj-woo-indiapost-tracking'); ?>
                </a>
            </h2>
            
            <!-- Display the appropriate content based on tab -->
            <div class="tracking-list-container">
                <?php
                if ($active_tab == 'eg') {
                    $eg_list_table = new RJ_EG_Tracking_List_Table();
                    $eg_list_table->prepare_items();
                    $eg_list_table->display();
                } elseif ($active_tab == 'cg') {
                    $cg_list_table = new RJ_CG_Tracking_List_Table();
                    $cg_list_table->prepare_items();
                    $cg_list_table->display();
                } elseif ($active_tab == 'logs') {
                    $this->display_logs_tab();
                } elseif ($active_tab == 'gst') {
                    $this->display_gst_reports_tab();
                }
                ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Display the logs tab content
     */
    private function display_logs_tab() {
        $logs_dir = $this->get_logs_directory();
        $log_files = glob($logs_dir . '*.log');
        
        if (empty($log_files)) {
            echo '<div class="notice notice-info"><p>' . __('No upload logs available yet.', 'rj-woo-indiapost-tracking') . '</p></div>';
            return;
        }
        
        // Sort by most recent first
        usort($log_files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        // View a specific log if requested
        if (isset($_GET['log']) && !empty($_GET['log'])) {
            $log_file = sanitize_file_name($_GET['log']);
            $log_path = $logs_dir . $log_file;
            
            if (file_exists($log_path)) {
                $this->display_single_log($log_path, $log_file);
                return;
            }
        }
        
        // Display list of logs
        ?>
        <div class="log-files-list">
            <h3><?php _e('Available Upload Logs', 'rj-woo-indiapost-tracking'); ?></h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Date', 'rj-woo-indiapost-tracking'); ?></th>
                        <th><?php _e('Log File', 'rj-woo-indiapost-tracking'); ?></th>
                        <th><?php _e('Size', 'rj-woo-indiapost-tracking'); ?></th>
                        <th><?php _e('Actions', 'rj-woo-indiapost-tracking'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($log_files as $log_file): ?>
                        <?php 
                        $filename = basename($log_file);
                        $file_date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), filemtime($log_file));
                        $file_size = size_format(filesize($log_file));
                        $view_url = add_query_arg(array('log' => $filename), admin_url('admin.php?page=indiapost-trackings&tab=logs'));
                        ?>
                        <tr>
                            <td><?php echo esc_html($file_date); ?></td>
                            <td><?php echo esc_html($filename); ?></td>
                            <td><?php echo esc_html($file_size); ?></td>
                            <td>
                                <a href="<?php echo esc_url($view_url); ?>" class="button button-small">
                                    <?php _e('View', 'rj-woo-indiapost-tracking'); ?>
                                </a>
                                <a href="<?php echo esc_url(content_url('/uploads/indiapost-tracking-logs/' . $filename)); ?>" class="button button-small" download>
                                    <?php _e('Download', 'rj-woo-indiapost-tracking'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    /**
     * Display a single log file content
     * 
     * @param string $log_path Path to log file
     * @param string $log_file Log filename
     */
    private function display_single_log($log_path, $log_file) {
        $back_url = admin_url('admin.php?page=indiapost-trackings&tab=logs');
        $log_content = file_get_contents($log_path);
        $file_date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), filemtime($log_path));
        
        ?>
        <div class="log-file-viewer">
            <div class="log-file-header">
                <a href="<?php echo esc_url($back_url); ?>" class="button">&larr; <?php _e('Back to Logs', 'rj-woo-indiapost-tracking'); ?></a>
                <h3><?php echo esc_html(sprintf(__('Log File: %s', 'rj-woo-indiapost-tracking'), $log_file)); ?></h3>
                <p><?php echo esc_html(sprintf(__('Date: %s', 'rj-woo-indiapost-tracking'), $file_date)); ?></p>
            </div>
            
            <div class="log-file-content">
                <pre><?php echo esc_html($log_content); ?></pre>
            </div>
        </div>
        <?php
    }
    
    /**
     * Handle CSV upload via AJAX
     */
    public function handle_csv_upload() {
        // Check nonce for security
        check_ajax_referer('rj_indiapost_tracking_csv_nonce', 'security');
        
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to upload files.', 'rj-woo-indiapost-tracking')));
            return;
        }
        
        // Check if file is uploaded
        if (empty($_FILES['tracking_csv_file'])) {
            wp_send_json_error(array('message' => __('No file was uploaded.', 'rj-woo-indiapost-tracking')));
            return;
        }
        
        $file = $_FILES['tracking_csv_file'];
        
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(array('message' => __('Error uploading file.', 'rj-woo-indiapost-tracking')));
            return;
        }
        
        // Check file type
        $file_type = wp_check_filetype(basename($file['name']), array('csv' => 'text/csv'));
        if (!$file_type['ext']) {
            wp_send_json_error(array('message' => __('Invalid file type. Please upload a CSV file.', 'rj-woo-indiapost-tracking')));
            return;
        }
        
        // Process the CSV file
        $result = $this->process_csv_file($file['tmp_name'], $file['name']);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
            return;
        }
        
        wp_send_json_success(array(
            'message' => $result['message'],
            'eg_count' => $result['eg_count'],
            'cg_count' => $result['cg_count'],
            'duplicates' => $result['duplicates'],
            'log_file' => $result['log_file']
        ));
    }
    
    /**
     * Process the uploaded CSV file
     *
     * @param string $file_path Path to the uploaded file
     * @param string $file_name Original file name
     * @return array|WP_Error Result of the processing
     */
    private function process_csv_file($file_path, $file_name) {
        // Create database tables if they don't exist
        $this->create_tracking_tables();
        
        // Open the CSV file
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return new WP_Error('csv_error', __('Could not open the CSV file.', 'rj-woo-indiapost-tracking'));
        }
        
        global $wpdb;
        $eg_table_name = $wpdb->prefix . 'EG_india_post_tracking';
        $cg_table_name = $wpdb->prefix . 'CG_india_post_tracking';
        
        $eg_count = 0;
        $cg_count = 0;
        $duplicate_count = 0;
        $invalid_count = 0;
        $user_id = get_current_user_id();
        $current_date = current_time('mysql');
        
        // Begin transaction
        $wpdb->query('START TRANSACTION');
        
        // Start logging
        $log_file = 'tracking_upload_' . date('Y-m-d_H-i-s') . '.log';
        $log_path = $this->get_logs_directory() . $log_file;
        
        $log_content = "==========================================================\n";
        $log_content .= "India Post Tracking CSV Upload Log\n";
        $log_content .= "==========================================================\n";
        $log_content .= "Date: " . date_i18n(get_option('date_format') . ' ' . get_option('time_format')) . "\n";
        $log_content .= "File: " . $file_name . "\n";
        $log_content .= "User: " . wp_get_current_user()->display_name . " (ID: $user_id)\n";
        $log_content .= "==========================================================\n\n";
        $log_content .= "PROCESSING RESULTS:\n";
        $log_content .= "----------------------------------------------------------\n\n";
        
        // Track successful and failed uploads
        $successful_tracking = array();
        $failed_tracking = array();
        
        try {
            // Process each line in the CSV
            $line_number = 0;
            
            while (($data = fgetcsv($handle)) !== false) {
                $line_number++;
                
                if (empty($data[0])) {
                    $failed_tracking[] = array(
                        'line' => $line_number,
                        'tracking' => '(empty)',
                        'reason' => 'Empty tracking number'
                    );
                    continue; // Skip empty lines
                }
                
                $tracking_number = sanitize_text_field(trim($data[0]));
                
                // Check if it's a valid India Post tracking number format
                if (!preg_match('/^[A-Z]{2}[0-9]+IN$/', $tracking_number)) {
                    $invalid_count++;
                    $failed_tracking[] = array(
                        'line' => $line_number,
                        'tracking' => $tracking_number,
                        'reason' => 'Invalid format (should be like EG123456IN or CG123456IN)'
                    );
                    continue; // Skip invalid format
                }
                
                // Check if tracking number exists in ANY of the tables (EG or CG)
                $exists_in_eg = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$eg_table_name} WHERE tracking_id = %s",
                    $tracking_number
                ));
                
                $exists_in_cg = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$cg_table_name} WHERE tracking_id = %s",
                    $tracking_number
                ));
                
                // If tracking number exists in either table, skip it
                if ($exists_in_eg || $exists_in_cg) {
                    $duplicate_count++;
                    $failed_tracking[] = array(
                        'line' => $line_number,
                        'tracking' => $tracking_number,
                        'reason' => 'Duplicate (already exists in database)'
                    );
                    continue; // Skip duplicates
                }
                
                // Determine which table to use
                if (strpos($tracking_number, 'EG') === 0) {
                    $table_name = $eg_table_name;
                    $eg_count++;
                    $type = 'EG';
                } elseif (strpos($tracking_number, 'CG') === 0) {
                    $table_name = $cg_table_name;
                    $cg_count++;
                    $type = 'CG';
                } else {
                    $failed_tracking[] = array(
                        'line' => $line_number,
                        'tracking' => $tracking_number,
                        'reason' => 'Unknown prefix (not EG or CG)'
                    );
                    continue; // Skip if not EG or CG
                }
                
                // Insert into database
                $result = $wpdb->insert(
                    $table_name,
                    array(
                        'tracking_id' => $tracking_number,
                        'current_status' => null,
                        'datetime' => null,
                        'upload_userid' => $user_id,
                        'order_id' => null,
                        'modified_at' => null,
                        'is_accessable' => 'Yes'
                    ),
                    array('%s', '%s', '%s', '%d', '%s', '%s', '%s')
                );
                
                if ($result) {
                    $successful_tracking[] = array(
                        'line' => $line_number,
                        'tracking' => $tracking_number,
                        'type' => $type
                    );
                } else {
                    $failed_tracking[] = array(
                        'line' => $line_number,
                        'tracking' => $tracking_number,
                        'reason' => 'Database error: ' . $wpdb->last_error
                    );
                }
            }
            
            // Write successful tracking numbers to log
            $log_content .= "SUCCESSFUL UPLOADS (" . count($successful_tracking) . "):\n";
            $log_content .= "----------------------------------------------------------\n";
            
            if (!empty($successful_tracking)) {
                foreach ($successful_tracking as $item) {
                    $log_content .= "Line {$item['line']}: {$item['tracking']} ({$item['type']}) - Successfully added\n";
                }
            } else {
                $log_content .= "No tracking numbers were successfully uploaded.\n";
            }
            
            $log_content .= "\n";
            
            // Write failed tracking numbers to log
            $log_content .= "FAILED UPLOADS (" . count($failed_tracking) . "):\n";
            $log_content .= "----------------------------------------------------------\n";
            
            if (!empty($failed_tracking)) {
                foreach ($failed_tracking as $item) {
                    $log_content .= "Line {$item['line']}: {$item['tracking']} - Failed: {$item['reason']}\n";
                }
            } else {
                $log_content .= "No tracking numbers failed to upload.\n";
            }
            
            $log_content .= "\n";
            
            // Write summary to log
            $log_content .= "SUMMARY:\n";
            $log_content .= "----------------------------------------------------------\n";
            $log_content .= "Total lines processed: " . $line_number . "\n";
            $log_content .= "Successfully added EG tracking numbers: " . $eg_count . "\n";
            $log_content .= "Successfully added CG tracking numbers: " . $cg_count . "\n";
            $log_content .= "Total successfully added: " . ($eg_count + $cg_count) . "\n";
            $log_content .= "Duplicates skipped: " . $duplicate_count . "\n";
            $log_content .= "Invalid format skipped: " . $invalid_count . "\n";
            $log_content .= "Total skipped: " . ($duplicate_count + $invalid_count) . "\n";
            
            // Save log file
            file_put_contents($log_path, $log_content);
            
            // Commit transaction
            $wpdb->query('COMMIT');
            
            // Close the file
            fclose($handle);
            
            // Return success message
            return array(
                'message' => sprintf(
                    __('CSV processed successfully. Added %d EG and %d CG tracking numbers. %d duplicates were skipped. View <a href="%s">upload log</a> for details.', 'rj-woo-indiapost-tracking'),
                    $eg_count,
                    $cg_count,
                    $duplicate_count,
                    admin_url('admin.php?page=indiapost-trackings&tab=logs&log=' . $log_file)
                ),
                'eg_count' => $eg_count,
                'cg_count' => $cg_count,
                'duplicates' => $duplicate_count,
                'log_file' => $log_file
            );
            
        } catch (Exception $e) {
            // Log the error
            $log_content .= "\nERROR:\n";
            $log_content .= "----------------------------------------------------------\n";
            $log_content .= "An error occurred during processing: " . $e->getMessage() . "\n";
            $log_content .= "Processing was aborted and changes were rolled back.\n";
            
            // Save log file even on error
            file_put_contents($log_path, $log_content);
            
            // Rollback transaction on error
            $wpdb->query('ROLLBACK');
            fclose($handle);
            return new WP_Error('db_error', $e->getMessage());
        }
    }
    
    /**
     * Create the tracking tables if they don't exist
     */
    public function create_tracking_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $eg_table_name = $wpdb->prefix . 'EG_india_post_tracking';
        $cg_table_name = $wpdb->prefix . 'CG_india_post_tracking';
        
        // SQL for creating tables
        $eg_sql = "CREATE TABLE IF NOT EXISTS $eg_table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            tracking_id varchar(50) NOT NULL,
            current_status varchar(100) DEFAULT NULL,
            datetime datetime DEFAULT NULL,
            upload_userid bigint(20) NOT NULL,
            order_id varchar(50) DEFAULT NULL,
            modified_at datetime DEFAULT NULL,
            is_accessable varchar(3) NOT NULL DEFAULT 'Yes',
            PRIMARY KEY  (id),
            UNIQUE KEY tracking_id (tracking_id)
        ) $charset_collate;";
        
        $cg_sql = "CREATE TABLE IF NOT EXISTS $cg_table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            tracking_id varchar(50) NOT NULL,
            current_status varchar(100) DEFAULT NULL,
            datetime datetime DEFAULT NULL,
            upload_userid bigint(20) NOT NULL,
            order_id varchar(50) DEFAULT NULL,
            modified_at datetime DEFAULT NULL,
            is_accessable varchar(3) NOT NULL DEFAULT 'Yes',
            PRIMARY KEY  (id),
            UNIQUE KEY tracking_id (tracking_id)
        ) $charset_collate;";
        
        // Include WordPress database upgrade functions
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Create the tables
        dbDelta($eg_sql);
        dbDelta($cg_sql);
    }
    
    /**
     * Get the logs directory path
     * 
     * @return string Logs directory path
     */
    private function get_logs_directory() {
        $upload_dir = wp_upload_dir();
        $logs_dir = $upload_dir['basedir'] . '/indiapost-tracking-logs/';
        return $logs_dir;
    }
    
    /**
     * Ensure logs directory exists
     */
    private function ensure_logs_directory_exists() {
        $logs_dir = $this->get_logs_directory();
        
        // Create directory if it doesn't exist
        if (!file_exists($logs_dir)) {
            wp_mkdir_p($logs_dir);
        }
        
        // Add index.php to prevent directory listing
        if (!file_exists($logs_dir . 'index.php')) {
            file_put_contents($logs_dir . 'index.php', '<?php // Silence is golden');
        }
        
        // Add .htaccess to further secure the directory
        if (!file_exists($logs_dir . '.htaccess')) {
            file_put_contents($logs_dir . '.htaccess', 'deny from all');
        }
    }
    
    /**
     * Display the GST reports tab content
     */
    private function display_gst_reports_tab() {
        ?>
        <div class="gst-reports-container">
            <div class="gst-upload-section">
                <h3><?php _e('Upload Tracking Numbers for GST Report', 'rj-woo-indiapost-tracking'); ?></h3>
                <form id="gst-tracking-upload-form" method="post" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="gst-tracking-file"><?php _e('Choose Excel/CSV file (with Article Number column):', 'rj-woo-indiapost-tracking'); ?></label>
                        <input type="file" name="gst_tracking_file" id="gst-tracking-file" accept=".csv,.xlsx,.xls" required>
                    </div>
                    
                    <div class="form-actions">
                        <?php wp_nonce_field('rj_indiapost_gst_upload', 'gst_upload_nonce'); ?>
                        <button type="submit" id="upload-gst-btn" class="button button-primary">
                            <?php _e('Generate GST Report', 'rj-woo-indiapost-tracking'); ?>
                        </button>
                    </div>
                </form>
                <div id="gst-upload-response" class="upload-response"></div>
            </div>
            
            <div class="gst-download-section" style="display: none;">
                <h3><?php _e('Download GST Report', 'rj-woo-indiapost-tracking'); ?></h3>
                <button id="download-gst-report" class="button button-primary">
                    <?php _e('Download Report', 'rj-woo-indiapost-tracking'); ?>
                </button>
            </div>
        </div>
        <?php
    }
    
    /**
     * Handle GST report generation
     */
    public function handle_gst_report_generation() {
        // Check nonce for security
        check_ajax_referer('rj_indiapost_gst_nonce', 'security');
        
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to generate reports.', 'rj-woo-indiapost-tracking')));
            return;
        }
        
        // Check if file is uploaded
        if (empty($_FILES['gst_tracking_file'])) {
            wp_send_json_error(array('message' => __('No file was uploaded.', 'rj-woo-indiapost-tracking')));
            return;
        }
        
        $file = $_FILES['gst_tracking_file'];
        
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(array('message' => __('Error uploading file.', 'rj-woo-indiapost-tracking')));
            return;
        }
        
        // Process the file and generate report
        $result = $this->generate_gst_report($file['tmp_name']);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
            return;
        }
        
        wp_send_json_success(array(
            'message' => __('GST report generated successfully!', 'rj-woo-indiapost-tracking'),
            'report_id' => $result['report_id']
        ));
    }
    
    /**
     * Generate GST report from uploaded file
     *
     * @param string $file_path Path to the uploaded file
     * @return array|WP_Error Result of the processing
     */
    private function generate_gst_report($file_path) {
        global $wpdb;
        
        // Create temporary directory for reports if it doesn't exist
        $upload_dir = wp_upload_dir();
        $reports_dir = $upload_dir['basedir'] . '/indiapost-gst-reports/';
        if (!file_exists($reports_dir)) {
            wp_mkdir_p($reports_dir);
        }
        
        // Generate unique report ID
        $report_id = uniqid('gst_report_');
        $report_file = $reports_dir . $report_id . '.csv';
        
        // Open input file
        $input = fopen($file_path, 'r');
        if (!$input) {
            return new WP_Error('file_error', __('Could not open input file.', 'rj-woo-indiapost-tracking'));
        }
        
        // Create output file
        $output = fopen($report_file, 'w');
        if (!$output) {
            fclose($input);
            return new WP_Error('file_error', __('Could not create output file.', 'rj-woo-indiapost-tracking'));
        }
        
        try {
            // Write headers
            $headers = array(
                'Month',
                'Order date',
                'Order Number',
                'Tracking Number',
                'Order Status',
                'State',
                'Pin',
                'GST number',
                'HSNcode',
                'CGST rate',
                'SGST rate',
                'CGST amount',
                'SGST amount',
                'Total amount'
            );
            fputcsv($output, $headers);
            
            // Skip header row in input file
            $header = fgetcsv($input);
            if (!$header) {
                throw new Exception(__('Empty input file.', 'rj-woo-indiapost-tracking'));
            }
            
            // Find Article Number column index
            $article_col = array_search('Article Number', $header);
            if ($article_col === false) {
                throw new Exception(__('Article Number column not found in input file.', 'rj-woo-indiapost-tracking'));
            }
            
            // Process each tracking number
            while (($data = fgetcsv($input)) !== false) {
                if (empty($data[$article_col])) continue;
                
                $tracking_number = sanitize_text_field(trim($data[$article_col]));
                
                // Find order ID from tracking number
                $order_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} 
                    WHERE meta_key = '_rj_indiapost_tracking_number' 
                    AND meta_value = %s",
                    $tracking_number
                ));
                
                if (!$order_id) continue;
                
                // Get order details
                $order = wc_get_order($order_id);
                if (!$order) continue;
                
                // Get order data
                $order_data = $order->get_data();
                $state_code = $order_data['shipping']['state'];
                
                // Get full state name using WooCommerce function
                $states = WC()->countries->get_states('IN');
                $shipping_state = isset($states[$state_code]) ? $states[$state_code] : $state_code;
                $shipping_postcode = $order_data['shipping']['postcode'];
                
                // Get HSN codes and GST rates
                $hsn_codes = array();
                $gst_rates = array();
                $total_tax = 0;
                
                foreach ($order->get_items() as $item) {
                    $product_id = $item->get_product_id();
                    
                    // Get HSN code from WooCommerce product
                    $hsn = get_post_meta($product_id, '_hsn_code', true);
                    if ($hsn) {
                        $hsn_codes[] = $hsn;
                    }
                    
                    // Get GST rate from ACF field
                    $gst_rate = get_field('gst_rate_in_persentage', $product_id);
                    if ($gst_rate) {
                        $gst_rates[] = floatval($gst_rate);
                        
                        // Calculate tax amount for this item
                        $item_total = $item->get_total();
                        $tax_rate = $gst_rate / 100;
                        $total_tax += $item_total * $tax_rate;
                    }
                }
                
                // Prepare row data
                $row = array(
                    date('Y-m-d'), // Month (today's date)
                    $order->get_date_created()->date('Y-m-d'), // Order date
                    $order_id, // Order number
                    $tracking_number, // Tracking number
                    $order->get_status() === 'completed' ? 'completed' : '', // Order status
                    $shipping_state, // State
                    $shipping_postcode, // PIN
                    '', // GST number (empty as requested)
                    implode(',', array_unique($hsn_codes)), // HSN codes
                    '', // CGST rate (empty if multiple products)
                    '', // SGST rate (empty if multiple products)
                    '', // CGST amount
                    '', // SGST amount
                    $order->get_total() // Total amount
                );
                
                // If we have exactly one GST rate, calculate CGST and SGST
                if (count(array_unique($gst_rates)) === 1) {
                    $gst_rate = $gst_rates[0];
                    $cgst_rate = $sgst_rate = $gst_rate / 2;
                    
                    $row[9] = number_format($cgst_rate, 2); // CGST rate
                    $row[10] = number_format($sgst_rate, 2); // SGST rate
                    
                    // Calculate tax amounts
                    $cgst_amount = $total_tax / 2;
                    $row[11] = number_format($cgst_amount, 2); // CGST amount
                    $row[12] = number_format($cgst_amount, 2); // SGST amount
                }
                
                fputcsv($output, $row);
            }
            
            fclose($input);
            fclose($output);
            
            return array(
                'report_id' => $report_id,
                'file_path' => $report_file
            );
            
        } catch (Exception $e) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            if (file_exists($report_file)) unlink($report_file);
            
            return new WP_Error('processing_error', $e->getMessage());
        }
    }
    
    /**
     * Handle GST report download
     */
    public function handle_gst_report_download() {
        // Check nonce
        check_ajax_referer('rj_indiapost_gst_nonce', 'security');
        
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to download reports.', 'rj-woo-indiapost-tracking'));
        }
        
        // Get report ID
        $report_id = isset($_POST['report_id']) ? sanitize_text_field($_POST['report_id']) : '';
        if (empty($report_id)) {
            wp_die(__('Invalid report ID.', 'rj-woo-indiapost-tracking'));
        }
        
        // Get report file path
        $upload_dir = wp_upload_dir();
        $report_file = $upload_dir['basedir'] . '/indiapost-gst-reports/' . $report_id . '.csv';
        
        // Check if file exists
        if (!file_exists($report_file)) {
            wp_die(__('Report file not found.', 'rj-woo-indiapost-tracking'));
        }
        
        // Set headers for file download
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="gst_report_' . date('Y-m-d') . '.csv"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Output file contents
        readfile($report_file);
        
        // Delete the file after download
        unlink($report_file);
        
        exit;
    }
    
    /**
     * Initialize the class
     */
    public static function init() {
        new self();
    }
}

// Initialize the CSV upload functionality
add_action('plugins_loaded', array('RJ_IndiaPost_Tracking_CSV_Upload', 'init')); 