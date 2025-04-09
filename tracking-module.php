<?php
/**
 * Tracking Module for RJ WooCommerce India Post Tracking
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}


// Create the tracking numbers table
function rj_create_tracking_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'indiapost_tracking_number';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        tracking_id varchar(255) NOT NULL,
        order_id bigint(20) NOT NULL,
        allocated_status varchar(50) DEFAULT 'pending' NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY tracking_id (tracking_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
add_action('plugins_loaded', 'rj_create_tracking_table');

// Add admin menu
function rj_add_tracking_menu() {
    add_menu_page(
        __('Tracking', 'rj-woo-indiapost-tracking'),
        __('Tracking', 'rj-woo-indiapost-tracking'),
        'manage_options',
        'rj_tracking',
        'rj_tracking_page',
        'dashicons-admin-generic',
        6
    );
}
add_action('admin_menu', 'rj_add_tracking_menu');

// Display the tracking page
function rj_tracking_page() {
    ?>
    <div class="wrap">
        <h1><?php _e('Add New Tracking', 'rj-woo-indiapost-tracking'); ?></h1>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="tracking_csv" accept=".csv" required>
            <input type="submit" name="upload_tracking" class="button button-primary" value="<?php _e('Upload Tracking CSV', 'rj-woo-indiapost-tracking'); ?>">
        </form>
    </div>
    <?php

    if (isset($_POST['upload_tracking'])) {
        rj_handle_csv_upload($_FILES['tracking_csv']);
    }
}

// Handle CSV upload
function rj_handle_csv_upload($file) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return;
    }

    $csvFile = fopen($file['tmp_name'], 'r');
    global $wpdb;
    $table_name = $wpdb->prefix . 'indiapost_tracking_number';

    // Read the first row to check for the header
    $headers = fgetcsv($csvFile);
    if (count($headers) !== 1 || $headers[0] !== 'India_post_tracking') {
        echo '<div class="error"><p>' . __('Invalid CSV format. Please ensure the first row contains only "India_post_tracking" as the header.', 'rj-woo-indiapost-tracking') . '</p></div>';
        fclose($csvFile);
        return;
    }

    while (($row = fgetcsv($csvFile)) !== FALSE) {
        $tracking_id = sanitize_text_field($row[0]);

        // Check for duplicates
        $existing = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE tracking_id = %s", $tracking_id));
        if ($existing == 0) {
            // Insert new tracking number
            $wpdb->insert($table_name, array(
                'tracking_id' => $tracking_id,
                'order_id' => null, // Assuming order_id is not provided in this case
                'allocated_status' => 'pending'
            ));
        }
    }
    fclose($csvFile);
    echo '<div class="updated"><p>' . __('Tracking numbers uploaded successfully.', 'rj-woo-indiapost-tracking') . '</p></div>';
}

// Update order meta when tracking number is allocated
function rj_update_order_meta($order_id, $tracking_number) {
    $this->handle_order_meta($order_id, '_rj_indiapost_tracking_number', $tracking_number, false);
}

// Hook into order status change to update tracking status
add_action('woocommerce_order_status_changed', 'rj_update_tracking_status', 10, 1);
function rj_update_tracking_status($order_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'indiapost_tracking_number';
    $tracking_number = $wpdb->get_var($wpdb->prepare("SELECT tracking_id FROM $table_name WHERE order_id = %d", $order_id));

    if ($tracking_number) {
        // Get the current order status
        $order = wc_get_order($order_id);
        $current_status = $order->get_status(); // Get the current status of the order

        // Update the allocated status based on the current order status
        $wpdb->update($table_name, array('allocated_status' => $current_status), array('order_id' => $order_id));
    }
}