<?php
/**
 * Plugin Name: RJ WooCommerce India Post Tracking
 * Description: Adds tracking number input field and custom processing button for Indian Post Courier
 * Version: 2.2
 * Author: Burhan Hasanfatta
 * Text Domain: rj-woo-indiapost-tracking
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 3.0
 * WC tested up to: 8.2
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main plugin class
 */
class RJ_WooCommerce_IndiaPost_Tracking {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Check if WooCommerce is active before adding hooks
        if ($this->is_woocommerce_active()) {
            // Add metabox to order screen
            add_action('add_meta_boxes', array($this, 'rj_indiapost_add_order_custom_metabox'));
            
            // Enqueue scripts and styles
            add_action('admin_enqueue_scripts', array($this, 'rj_indiapost_enqueue_admin_scripts'));
            
            // AJAX handler for saving tracking number
            add_action('wp_ajax_rj_indiapost_save_tracking', array($this, 'rj_indiapost_save_tracking_number'));
            
            // Add QR code above billing address
            add_action('woocommerce_admin_order_data_after_shipping_address', array($this, 'display_tracking_qr_code'), 10, 1);
        } else {
            // Add admin notice if WooCommerce is not active
            add_action('admin_notices', array($this, 'rj_indiapost_woocommerce_missing_notice'));
        }
    }
    
    /**
     * Check if WooCommerce is active
     * 
     * @return bool
     */
    public function is_woocommerce_active() {
        // Check if WooCommerce class exists
        if (class_exists('WooCommerce')) {
            return true;
        }
        
        // Alternative check: Look for the WooCommerce plugin using is_plugin_active
        if (!function_exists('is_plugin_active')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        return is_plugin_active('woocommerce/woocommerce.php');
    }
    
    /**
     * Display admin notice if WooCommerce is not active
     */
    public function rj_indiapost_woocommerce_missing_notice() {
        ?>
        <div class="error notice">
            <p><?php _e('RJ WooCommerce India Post Tracking requires WooCommerce to be installed and activated.', 'rj-woo-indiapost-tracking'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Add a custom metabox to the order page
     */
    public function rj_indiapost_add_order_custom_metabox() {
        $screen = $this->rj_indiapost_get_order_screen_id();
        
        add_meta_box(
            'rj_indiapost_tracking',
            __('India Post Tracking', 'rj-woo-indiapost-tracking'),
            array($this, 'rj_indiapost_render_custom_metabox_content'),
            $screen,
            'side',
            'high'
        );
    }
    
    /**
     * Get the appropriate screen ID based on whether HPOS is enabled
     * 
     * @return string Screen ID for orders
     */
    private function rj_indiapost_get_order_screen_id() {
        // Simplified approach to check for HPOS
        if (
            class_exists('WooCommerce') && 
            function_exists('wc_get_page_screen_id') && 
            get_option('woocommerce_custom_orders_table_enabled') === 'yes'
        ) {
            return wc_get_page_screen_id('shop-order');
        } else {
            return 'shop_order';
        }
    }
    
    /**
     * Enqueue admin scripts and styles
     * 
     * @param string $hook Current admin page
     */
    public function rj_indiapost_enqueue_admin_scripts($hook) {
        $screen = $this->rj_indiapost_get_order_screen_id();
        
        // Only enqueue on order edit page
        if ($hook === 'post.php' || $hook === 'post-new.php' || $hook === 'woocommerce_page_wc-orders') {
            // Enqueue CSS
            wp_enqueue_style(
                'rj-indiapost-admin-style',
                plugin_dir_url(__FILE__) . 'css/admin-style.css',
                array(),
                '1.0.0'
            );
            
            // Enqueue QR code library
            wp_enqueue_script(
                'qrcode-js',
                'https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js',
                array(),
                '1.4.4',
                true
            );
            
            // Enqueue JS
            wp_enqueue_script(
                'rj-indiapost-tracking-validation',
                plugin_dir_url(__FILE__) . 'js/tracking-validation.js',
                array('jquery', 'qrcode-js'),
                '1.0.0',
                true
            );
            
            // Localize script with necessary data
            wp_localize_script(
                'rj-indiapost-tracking-validation',
                'tracking_vars',
                array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('rj_indiapost_tracking_nonce'),
                    'saving_text' => __('Saving...', 'rj-woo-indiapost-tracking'),
                    'add_text' => __('Add', 'rj-woo-indiapost-tracking'),
                    'error_text' => __('Something went wrong. Please try again.', 'rj-woo-indiapost-tracking'),
                    'reset_confirm_text' => __('Are you sure you want to reset the tracking number?', 'rj-woo-indiapost-tracking'),
                    'reset_success_text' => __('Tracking number has been reset.', 'rj-woo-indiapost-tracking')
                )
            );
        }
    }
    
    /**
     * AJAX handler to save tracking number
     */
    public function rj_indiapost_save_tracking_number() {
        // Check nonce for security
        check_ajax_referer('rj_indiapost_tracking_nonce', 'security');
        
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
            'message' => __('Tracking number saved successfully.', 'rj-woo-indiapost-tracking')
        ));
    }
    
    /**
     * Render the metabox content
     * 
     * @param WP_Post|object $object The post or order object
     */
    public function rj_indiapost_render_custom_metabox_content($object) {
        // Get the order ID
        $order_id = is_a($object, 'WP_Post') ? $object->ID : $object->get_id();
        
        // Get existing tracking number if any
        $tracking_number = get_post_meta($order_id, '_rj_indiapost_tracking_number', true);
        
        // Get order number for display
        $order_number = '';
        if (is_a($object, 'WP_Post')) {
            $order_number = $order_id;
        } else {
            $order_number = method_exists($object, 'get_order_number') ? $object->get_order_number() : $order_id;
        }
        
        ?>
        <div class="rj-indiapost-tracking-container" data-order-id="<?php echo esc_attr($order_id); ?>">
            <!-- <p><?php _e('Order Number:', 'rj-woo-indiapost-tracking'); ?> <?php echo $order_number; ?></p> -->
            
            <p>
                <input type="text" id="rj_indiapost_tracking_number" name="rj_indiapost_tracking_number" 
                    value="<?php echo esc_attr($tracking_number); ?>" class="widefat" 
                    placeholder="<?php _e('Enter tracking number', 'rj-woo-indiapost-tracking'); ?>">
            </p>
            
            <p>
                <button type="button" id="rj_indiapost_add_tracking_btn" class="button button-primary">
                    <?php _e('Add', 'rj-woo-indiapost-tracking'); ?>
                </button>
                <button type="button" id="rj_indiapost_reset_tracking_btn" class="button">
                    <?php _e('Reset', 'rj-woo-indiapost-tracking'); ?>
                </button>
            </p>
            
            <div id="tracking_message"></div>
        </div>
        <?php
    }
    
    /**
     * Display QR code above billing address
     * 
     * @param WC_Order $order The order object
     */
    public function display_tracking_qr_code($order) {
        $T_number = get_post_meta($order->get_id(), '_rj_indiapost_tracking_number', true);
        $tracking_number = 'https://m.aftership.com/india-post/'.$T_number;
        
        if (!empty($T_number)) {
            ?>
            <div class="rj-indiapost-qr-container">
                <h4><?php _e('India Post Tracking QR Code', 'rj-woo-indiapost-tracking'); ?></h4>
                <div id="rj_indiapost_qr_code" data-tracking="<?php echo esc_attr($tracking_number); ?>"></div>
                <button type="button" class="button copy-tracking-btn" data-tracking="<?php echo esc_attr($tracking_number); ?>">
                    <?php _e('Copy Tracking Link', 'rj-woo-indiapost-tracking'); ?>
                </button>
                <span class="copy-success-message" style="display:none; color:green; margin-left:10px;"><?php _e('Copied!', 'rj-woo-indiapost-tracking'); ?></span>
            </div>
            <?php
            
            // Add inline script to ensure QR code is generated
            ?>
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Generate QR code directly
                if (typeof qrcode === 'function') {
                    var qrContainer = $('#rj_indiapost_qr_code');
                    var trackingNumber = '<?php echo esc_js($tracking_number); ?>';
                    
                    if (qrContainer.length && trackingNumber) {
                        var qr = qrcode(0, 'M');
                        qr.addData(trackingNumber);
                        qr.make();
                        qrContainer.html(qr.createImgTag(4));
                    }
                }
                
                // Copy tracking number functionality
                $('.copy-tracking-btn').on('click', function() {
                    var trackingNumber = $(this).data('tracking');
                    var successMessage = $(this).next('.copy-success-message');
                    
                    // Create temporary textarea to copy from
                    var tempTextarea = $('<textarea>');
                    tempTextarea.val(trackingNumber);
                    $('body').append(tempTextarea);
                    tempTextarea.select();
                    document.execCommand('copy');
                    tempTextarea.remove();
                    
                    // Show success message
                    successMessage.fadeIn().delay(2000).fadeOut();
                });
            });
            </script>
            <?php
        }
    }
    
    /**
     * Initialize the plugin
     */
    public static function rj_indiapost_init() {
        new self();
    }
}

// Initialize the plugin
add_action('plugins_loaded', array('RJ_WooCommerce_IndiaPost_Tracking', 'rj_indiapost_init'));

// Include frontend display file
require_once plugin_dir_path(__FILE__) . 'view-in-myaccount.php';

// Include orders list tracking file
require_once plugin_dir_path(__FILE__) . 'wp-orders-listing.php';