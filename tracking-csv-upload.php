<?php
/**
 * India Post Tracking CSV Upload Functionality
 * 
 * Adds a menu in WordPress admin dashboard for uploading tracking CSV files
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
            '1.0.0'
        );
        
        // Enqueue JS
        wp_enqueue_script(
            'rj-indiapost-tracking-csv-js',
            plugin_dir_url(__FILE__) . 'js/tracking-csv.js',
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
                'upload_error' => __('Error uploading CSV. Please try again.', 'rj-woo-indiapost-tracking')
            )
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
                                <a href="<?php echo esc_url($log_file); ?>" class="button button-small" download>
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
     * Initialize the class
     */
    public static function init() {
        new self();
    }
}

// Initialize the CSV upload functionality
add_action('plugins_loaded', array('RJ_IndiaPost_Tracking_CSV_Upload', 'init')); 