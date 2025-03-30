<?php
/**
 * Add tracking input field to WooCommerce orders list table
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class to handle tracking column in WooCommerce admin orders list
 */
class RJ_IndiaPost_Orders_List {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Add custom column to WooCommerce orders table (both traditional and HPOS)
        add_filter('manage_edit-shop_order_columns', array($this, 'add_order_tracking_column'), 20);
        add_filter('woocommerce_admin_order_list_table_columns', array($this, 'add_order_tracking_column'), 20);
        
        // Add content to custom column
        add_action('manage_shop_order_posts_custom_column', array($this, 'add_order_tracking_column_content'), 20, 2);
        add_action('woocommerce_admin_order_list_table_column_tracking', array($this, 'add_hpos_tracking_column_content'), 20);
        
        // Enqueue scripts and styles for admin
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_list_scripts'));
        
        // AJAX handler for saving tracking numbers from list view
        add_action('wp_ajax_rj_indiapost_list_save_tracking', array($this, 'save_tracking_from_list'));
    }
    
    /**
     * Add tracking column to order list
     *
     * @param array $columns Existing columns
     * @return array Modified columns
     */
    public function add_order_tracking_column($columns) {
        // Debug output
        echo '<!-- RJ_IndiaPost columns before: ' . implode(',', array_keys($columns)) . ' -->';
        
        $new_columns = array();
        
        // Insert tracking column before actions column or at a specific position
        foreach ($columns as $key => $name) {
            if ($key === 'order_actions' || $key === 'wc_actions') {
                $new_columns['tracking'] = __('Tracking', 'rj-woo-indiapost-tracking');
            }
            $new_columns[$key] = $name;
        }
        
        // If actions column doesn't exist, add it before the last column
        if (!isset($new_columns['tracking'])) {
            $last_key = array_key_last($columns);
            
            foreach ($columns as $key => $name) {
                if ($key === $last_key) {
                    $new_columns['tracking'] = __('Tracking', 'rj-woo-indiapost-tracking');
                }
                $new_columns[$key] = $name;
            }
        }
        
        // If still not added, add it at the end
        if (!isset($new_columns['tracking'])) {
            $new_columns['tracking'] = __('Tracking', 'rj-woo-indiapost-tracking');
        }
        
        // Debug output
        echo '<!-- RJ_IndiaPost columns after: ' . implode(',', array_keys($new_columns)) . ' -->';
        
        return $new_columns;
    }
    
    /**
     * Add content to tracking column for traditional orders
     *
     * @param string $column Column name
     * @param int $order_id Order ID
     */
    public function add_order_tracking_column_content($column, $order_id) {
        if ($column === 'tracking') {
            $this->render_tracking_field($order_id);
        }
    }
    
    /**
     * Add content to tracking column for HPOS orders
     *
     * @param \WC_Order $order Order object
     */
    public function add_hpos_tracking_column_content($order) {
        if (is_object($order) && method_exists($order, 'get_id')) {
            $this->render_tracking_field($order->get_id());
        }
    }
    
    /**
     * Render tracking field
     *
     * @param int $order_id Order ID
     */
    private function render_tracking_field($order_id) {
        // Get existing tracking number if any
        $tracking_number = get_post_meta($order_id, '_rj_indiapost_tracking_number', true);
        
        if (!empty($tracking_number)) {
            // Display tracking number
            echo '<div class="rj-list-tracking-info" id="rj-tracking-info-' . esc_attr($order_id) . '">';
            echo '<div class="rj-list-tracking-number">' . esc_html($tracking_number) . '</div>';
            echo '<a href="#" class="rj-list-edit-tracking" data-order-id="' . esc_attr($order_id) . '">Edit</a>';
            echo '</div>';
        }
        
        // Display input field and button (hidden if tracking number exists)
        echo '<div class="rj-list-tracking-input" id="rj-tracking-input-' . esc_attr($order_id) . '"' . (!empty($tracking_number) ? ' style="display:none;"' : '') . '>';
        echo '<input type="text" class="rj-list-tracking-number-input" id="rj-tracking-number-' . esc_attr($order_id) . '" value="' . esc_attr($tracking_number) . '" placeholder="' . esc_attr__('Enter tracking #', 'rj-woo-indiapost-tracking') . '">';
        echo '<button type="button" class="button rj-list-add-tracking" data-order-id="' . esc_attr($order_id) . '">' . esc_html__('Add', 'rj-woo-indiapost-tracking') . '</button>';
        echo '<div class="rj-list-message" id="rj-tracking-message-' . esc_attr($order_id) . '"></div>';
        echo '</div>';
    }
    
    /**
     * Enqueue scripts and styles for admin list page
     *
     * @param string $hook Current admin page
     */
    public function enqueue_admin_list_scripts($hook) {
        // Debug output to console to help troubleshoot
        echo '<script>console.log("Current hook: ' . esc_js($hook) . '");</script>';
        
        // Only load on orders list page (both traditional and HPOS)
        if ($hook == 'edit.php' && isset($_GET['post_type']) && $_GET['post_type'] == 'shop_order' || 
            $hook == 'woocommerce_page_wc-orders') {
            
            // Enqueue CSS
            wp_enqueue_style(
                'rj-indiapost-list-style',
                plugin_dir_url(__FILE__) . 'css/orders-list-style.css',
                array(),
                '1.0.0'
            );
            
            // Enqueue JS
            wp_enqueue_script(
                'rj-indiapost-list-script',
                plugin_dir_url(__FILE__) . 'js/orders-list-script.js',
                array('jquery'),
                '1.0.0',
                true
            );
            
            // Localize script
            wp_localize_script(
                'rj-indiapost-list-script',
                'rj_list_vars',
                array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('rj_indiapost_list_nonce'),
                    'adding_text' => __('Adding...', 'rj-woo-indiapost-tracking'),
                    'add_text' => __('Add', 'rj-woo-indiapost-tracking'),
                    'success_text' => __('Saved!', 'rj-woo-indiapost-tracking'),
                    'error_text' => __('Error', 'rj-woo-indiapost-tracking')
                )
            );
        }
    }
    
    /**
     * AJAX handler to save tracking number from list view
     */
    public function save_tracking_from_list() {
        // Check nonce for security
        check_ajax_referer('rj_indiapost_list_nonce', 'security');
        
        // Check if user has permission
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(array('message' => __('You do not have permission to do this.', 'rj-woo-indiapost-tracking')));
            return;
        }
        
        // Get and sanitize data
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $tracking_number = isset($_POST['tracking_number']) ? sanitize_text_field($_POST['tracking_number']) : '';
        
        if (empty($order_id)) {
            wp_send_json_error(array('message' => __('Invalid order ID.', 'rj-woo-indiapost-tracking')));
            return;
        }
        
        // Save tracking number to the order meta
        update_post_meta($order_id, '_rj_indiapost_tracking_number', $tracking_number);
        
        // Return success response
        wp_send_json_success(array(
            'message' => __('Tracking number saved successfully.', 'rj-woo-indiapost-tracking'),
            'tracking_number' => $tracking_number
        ));
    }
}

// Initialize the class
new RJ_IndiaPost_Orders_List(); 