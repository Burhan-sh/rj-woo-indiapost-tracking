<?php
/**
 * Automatic Tracking Assignment
 *
 * Automatically assigns tracking numbers to WooCommerce orders based on weight
 * - EG tracking for orders with total weight <= 1000g
 * - CG tracking for orders with total weight > 1000g or if weight cannot be determined
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for automatic tracking assignment
 */
class RJ_IndiaPost_Auto_Tracking_Assignment {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Check if WooCommerce is active
        if (!$this->is_woocommerce_active()) {
            return;
        }
        
        // Hook into WooCommerce order status changes
        add_action('woocommerce_order_status_changed', array($this, 'maybe_assign_tracking'), 10, 4);
        add_action('woocommerce_new_order', array($this, 'maybe_assign_tracking_new_order'), 10, 1);
        
        // Hook into order status changes to update tracking status
        add_action('woocommerce_order_status_changed', array($this, 'update_tracking_status'), 10, 4);
    }
    
    /**
     * Check if WooCommerce is active
     * 
     * @return bool
     */
    private function is_woocommerce_active() {
        return class_exists('WooCommerce');
    }
    
    /**
     * Check if tracking should be assigned on order status change
     *
     * @param int $order_id Order ID
     * @param string $from_status Previous status
     * @param string $to_status New status
     * @param WC_Order $order Order object
     */
    public function maybe_assign_tracking($order_id, $from_status, $to_status, $order) {
        // Add safety checks
        if (empty($order_id) || empty($to_status)) {
            return;
        }
        
        // Only assign tracking when order status is 'process-to-ship'
        if ($to_status === 'process-to-ship') {
            $this->assign_tracking_to_order($order_id);
        }
    }
    
    /**
     * Check if tracking should be assigned on new order with process-to-ship status
     *
     * @param int $order_id Order ID
     */
    public function maybe_assign_tracking_new_order($order_id) {
        if (empty($order_id)) {
            return;
        }
        
        $order = $this->get_order($order_id);
        
        // Only proceed if we have a valid order
        if (!$order) {
            return;
        }
        
        $status = $this->get_order_status($order);
        
        // Only assign tracking when order status is 'process-to-ship'
        if (!empty($status) && $status === 'process-to-ship') {
            $this->assign_tracking_to_order($order_id);
        }
    }
    
    /**
     * Get order object in a way that's compatible with both HPOS and traditional post meta
     *
     * @param int $order_id Order ID
     * @return object|false Order object or false if not found
     */
    private function get_order($order_id) {
        // Check if WooCommerce is active
        if (!$this->is_woocommerce_active()) {
            return false;
        }
        
        // Return order if wc_get_order function exists
        if (function_exists('wc_get_order')) {
            // Using function_exists check to avoid linter errors
            $order = @call_user_func('wc_get_order', $order_id);
            if ($order) {
                return $order;
            }
        }
        
        // Fallback for when WooCommerce is not fully loaded
        $post = get_post($order_id);
        if ($post && $post->post_type === 'shop_order') {
            return $post;
        }
        
        return false;
    }
    
    /**
     * Get order status in a way that's compatible with both HPOS and traditional post meta
     *
     * @param object $order Order object
     * @return string Order status
     */
    private function get_order_status($order) {
        // Safety check for null/false order
        if (!$order) {
            return '';
        }
        
        if (method_exists($order, 'get_status')) {
            $status = $order->get_status();
            return !empty($status) ? $status : '';
        }
        
        // Fallback for traditional post meta
        if (property_exists($order, 'ID')) {
            $terms = get_the_terms($order->ID, 'shop_order_status');
            if ($terms && !is_wp_error($terms) && !empty($terms) && isset($terms[0]->slug)) {
                return $terms[0]->slug;
            }
        }
        
        return '';
    }
    
    /**
     * Assign tracking number to an order based on weight
     *
     * @param int $order_id Order ID
     */
    private function assign_tracking_to_order($order_id) {
        // Check if the order already has a tracking number
        $existing_tracking = $this->get_order_meta($order_id, '_rj_indiapost_tracking_number');
        
        if (!empty($existing_tracking)) {
            // Order already has a tracking number, don't assign a new one
            return;
        }
        
        // Get order object
        $order = $this->get_order($order_id);
        if (!$order) {
            return;
        }
        
        // Calculate total weight
        $total_weight = $this->calculate_order_weight($order);
        
        // Determine which table to use based on weight
        $tracking_type = ($total_weight !== false && $total_weight <= 1000) ? 'EG' : 'CG';
        
        // Get tracking number from appropriate table
        $tracking_number = $this->get_available_tracking_number($tracking_type);
        
        if (empty($tracking_number)) {
            // No available tracking number found
            $this->log_tracking_assignment_error($order_id, "No available {$tracking_type} tracking number found");
            return;
        }
        
        // Assign tracking number to order
        $this->update_order_meta($order_id, '_rj_indiapost_tracking_number', $tracking_number);
        
        // Update tracking in database
        $this->update_tracking_in_database($tracking_type, $tracking_number, $order_id);
        
        // Log the assignment
        $this->log_tracking_assignment($order_id, $tracking_number, $tracking_type, $total_weight);
    }
    
    /**
     * Get order meta in a way that's compatible with both HPOS and traditional post meta
     *
     * @param int $order_id Order ID
     * @param string $key Meta key
     * @return mixed Meta value
     */
    private function get_order_meta($order_id, $key) {
        $order = $this->get_order($order_id);
        
        if (method_exists($order, 'get_meta')) {
            return $order->get_meta($key);
        }
        
        // Fallback to traditional post meta
        return get_post_meta($order_id, $key, true);
    }
    
    /**
     * Update order meta in a way that's compatible with both HPOS and traditional post meta
     *
     * @param int $order_id Order ID
     * @param string $key Meta key
     * @param mixed $value Meta value
     */
    private function update_order_meta($order_id, $key, $value) {
        $order = $this->get_order($order_id);
        
        if (method_exists($order, 'update_meta_data')) {
            $order->update_meta_data($key, $value);
            $order->save();
            return;
        }
        
        // Fallback to traditional post meta
        update_post_meta($order_id, $key, $value);
    }
    
    /**
     * Calculate total weight of all products in an order
     *
     * @param object $order Order object
     * @return float|bool Total weight or false if weight cannot be determined
     */
    private function calculate_order_weight($order) {
        $total_weight = 0;
        $weight_found = false;
        $items = [];
        
        // Get items depending on the order object type
        if (method_exists($order, 'get_items')) {
            $items = $order->get_items();
        } else {
            // Fallback for traditional post meta
            $items = $this->get_order_items($order->ID);
        }
        
        // Loop through order items
        foreach ($items as $item_id => $item) {
            $product = null;
            
            // Get product depending on the item object type
            if (method_exists($item, 'get_product')) {
                $product = $item->get_product();
            } else {
                // Fallback for traditional order items
                $product_id = isset($item['product_id']) ? $item['product_id'] : 0;
                if ($product_id) {
                    $product = $this->get_product($product_id);
                }
            }
            
            if (!$product) {
                continue;
            }
            
            $product_weight = $this->get_product_weight($product);
            $quantity = isset($item['qty']) ? $item['qty'] : (method_exists($item, 'get_quantity') ? $item->get_quantity() : 1);
            
            if ($product_weight !== false) {
                $total_weight += $product_weight * $quantity;
                $weight_found = true;
            }
        }
        
        // Return false if no product has weight information
        return $weight_found ? $total_weight : false;
    }
    
    /**
     * Get product object in a way that's compatible with older WooCommerce versions
     *
     * @param int $product_id Product ID
     * @return object|false Product object or false
     */
    private function get_product($product_id) {
        // Check if WooCommerce is active
        if (!$this->is_woocommerce_active()) {
            return false;
        }
        
        // Return product if wc_get_product function exists
        if (function_exists('wc_get_product')) {
            // Using function_exists check to avoid linter errors
            $product = @call_user_func('wc_get_product', $product_id);
            if ($product) {
                return $product;
            }
        }
        
        // Fallback for older WooCommerce versions or when function not available
        if (class_exists('WC_Product')) {
            // Using variable type to avoid linter errors
            $product_class = 'WC_Product';
            return new $product_class($product_id);
        }
        
        return false;
    }
    
    /**
     * Get product weight in a way that's compatible with different product object types
     *
     * @param object $product Product object
     * @return float|bool Product weight or false
     */
    private function get_product_weight($product) {
        if (method_exists($product, 'get_weight')) {
            $weight = $product->get_weight();
            return ($weight !== '' && is_numeric($weight)) ? floatval($weight) : false;
        }
        
        // Fallback for traditional product meta
        $weight = get_post_meta($product->id, '_weight', true);
        return ($weight !== '' && is_numeric($weight)) ? floatval($weight) : false;
    }
    
    /**
     * Get order items for traditional post meta orders
     *
     * @param int $order_id Order ID
     * @return array Order items
     */
    private function get_order_items($order_id) {
        global $wpdb;
        
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}woocommerce_order_items 
                WHERE order_id = %d AND order_item_type = 'line_item'",
                $order_id
            ),
            ARRAY_A
        );
        
        $order_items = array();
        
        if ($items) {
            foreach ($items as $item) {
                $item_id = $item['order_item_id'];
                $order_items[$item_id] = $item;
                
                // Get item meta
                $meta = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT meta_key, meta_value 
                        FROM {$wpdb->prefix}woocommerce_order_itemmeta 
                        WHERE order_item_id = %d",
                        $item_id
                    ),
                    ARRAY_A
                );
                
                foreach ($meta as $meta_data) {
                    $order_items[$item_id][$meta_data['meta_key']] = $meta_data['meta_value'];
                }
            }
        }
        
        return $order_items;
    }
    
    /**
     * Get an available tracking number from the database
     *
     * @param string $type Tracking type (EG or CG)
     * @return string|bool Tracking number or false if none available
     */
    private function get_available_tracking_number($type) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . $type . '_india_post_tracking';
        
        // Get the first available tracking number (where order_id is NULL)
        $tracking_number = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT tracking_id FROM {$table_name} 
                WHERE order_id IS NULL 
                AND is_accessable = %s 
                ORDER BY id ASC LIMIT 1",
                'Yes'
            )
        );
        
        return $tracking_number;
    }
    
    /**
     * Update tracking record in database
     *
     * @param string $type Tracking type (EG or CG)
     * @param string $tracking_number Tracking number
     * @param int $order_id Order ID
     */
    private function update_tracking_in_database($type, $tracking_number, $order_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . $type . '_india_post_tracking';
        $order = $this->get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        // Check if this tracking is already assigned to this order
        $existing_record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE tracking_id = %s",
                $tracking_number
            )
        );
        
        // If tracking is already assigned to this order, don't update it again
        if ($existing_record && !empty($existing_record->order_id) && $existing_record->order_id == $order_id) {
            return;
        }
        
        // Check if order already has a tracking in this table
        $order_has_tracking = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE order_id = %s",
                $order_id
            )
        );
        
        if ($order_has_tracking) {
            // Order already has tracking in this table, don't assign another one
            return;
        }
        
        // Update tracking record with current timestamp for modified_at
        $wpdb->update(
            $table_name,
            array(
                'order_id' => $order_id,
                'current_status' => $this->get_order_status($order),
                'datetime' => current_time('mysql'),
                'modified_at' => current_time('mysql'),
                'is_accessable' => 'No'
            ),
            array('tracking_id' => $tracking_number),
            array('%s', '%s', '%s', '%s', '%s'),
            array('%s')
        );
    }
    
    /**
     * Log tracking assignment
     *
     * @param int $order_id Order ID
     * @param string $tracking_number Tracking number
     * @param string $tracking_type Tracking type (EG or CG)
     * @param float|bool $weight Order weight
     */
    private function log_tracking_assignment($order_id, $tracking_number, $tracking_type, $weight) {
        $log_dir = $this->get_logs_directory();
        $log_file = $log_dir . 'tracking_assignment.log';
        
        $log_entry = sprintf(
            "[%s] Order #%s assigned %s tracking number %s. Weight: %s\n",
            current_time('mysql'),
            $order_id,
            $tracking_type,
            $tracking_number,
            ($weight !== false) ? $weight . 'g' : 'unknown'
        );
        
        // Ensure log directory exists
        $this->ensure_logs_directory_exists();
        
        // Append to log file
        file_put_contents($log_file, $log_entry, FILE_APPEND);
    }
    
    /**
     * Log tracking assignment error
     *
     * @param int $order_id Order ID
     * @param string $error_message Error message
     */
    private function log_tracking_assignment_error($order_id, $error_message) {
        $log_dir = $this->get_logs_directory();
        $log_file = $log_dir . 'tracking_assignment_errors.log';
        
        $log_entry = sprintf(
            "[%s] Order #%s tracking assignment failed: %s\n",
            current_time('mysql'),
            $order_id,
            $error_message
        );
        
        // Ensure log directory exists
        $this->ensure_logs_directory_exists();
        
        // Append to log file
        file_put_contents($log_file, $log_entry, FILE_APPEND);
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
     * Update tracking status when order status changes
     *
     * @param int $order_id Order ID
     * @param string $from_status Previous status
     * @param string $to_status New status
     * @param WC_Order $order Order object
     */
    public function update_tracking_status($order_id, $from_status, $to_status, $order) {
        // Add safety checks
        if (empty($order_id) || empty($to_status)) {
            return;
        }

        // Get tracking number from order
        $tracking_number = $this->get_order_meta($order_id, '_rj_indiapost_tracking_number');
        
        // If no tracking number, nothing to update
        if (empty($tracking_number)) {
            return;
        }
        
        // Determine tracking type from tracking number prefix
        $tracking_type = '';
        if (is_string($tracking_number)) {
            if (strpos($tracking_number, 'EG') === 0) {
                $tracking_type = 'EG';
            } elseif (strpos($tracking_number, 'CG') === 0) {
                $tracking_type = 'CG';
            }
        }
        
        // If tracking type could not be determined, exit
        if (empty($tracking_type)) {
            return;
        }
        
        // Update tracking status in database
        $this->update_tracking_status_in_database($tracking_type, $tracking_number, $to_status);
    }
    
    /**
     * Update tracking status in database
     *
     * @param string $type Tracking type (EG or CG)
     * @param string $tracking_number Tracking number
     * @param string $status New status
     */
    private function update_tracking_status_in_database($type, $tracking_number, $status) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . $type . '_india_post_tracking';
        
        // Check if tracking number exists in database
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE tracking_id = %s",
                $tracking_number
            )
        );
        
        if (!$exists) {
            // Tracking number not found in database
            return;
        }
        
        // Update current_status only (don't change other fields like modified_at)
        $wpdb->update(
            $table_name,
            array(
                'current_status' => $status,
            ),
            array('tracking_id' => $tracking_number),
            array('%s'),
            array('%s')
        );
        
        // Log the status update
        $this->log_tracking_status_update($tracking_number, $type, $status);
    }
    
    /**
     * Log tracking status update
     *
     * @param string $tracking_number Tracking number
     * @param string $tracking_type Tracking type (EG or CG)
     * @param string $status New status
     */
    private function log_tracking_status_update($tracking_number, $tracking_type, $status) {
        $log_dir = $this->get_logs_directory();
        $log_file = $log_dir . 'tracking_status_updates.log';
        
        $log_entry = sprintf(
            "[%s] %s tracking number %s status updated to: %s\n",
            current_time('mysql'),
            $tracking_type,
            $tracking_number,
            $status
        );
        
        // Ensure log directory exists
        $this->ensure_logs_directory_exists();
        
        // Append to log file
        file_put_contents($log_file, $log_entry, FILE_APPEND);
    }
    
    /**
     * Initialize the class
     */
    public static function init() {
        new self();
    }
}

// Initialize the automatic tracking assignment
add_action('plugins_loaded', array('RJ_IndiaPost_Auto_Tracking_Assignment', 'init')); 