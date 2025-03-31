<?php
/**
 * Display tracking information in My Account page
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class to handle frontend display of tracking information
 */
class RJ_IndiaPost_Frontend_Display {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Add tracking column to orders table (using the non-deprecated hook)
        add_filter('woocommerce_account_orders_columns', array($this, 'add_tracking_column'));
        add_action('woocommerce_my_account_my_orders_column_order-tracking', array($this, 'add_tracking_column_content'), 10, 1);
        
        // Enqueue scripts and styles for frontend
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
    }
    
    /**
     * Enqueue scripts and styles for frontend
     */
    public function enqueue_frontend_scripts() {
        // Check if we're on the My Account page without using is_account_page()
        // This is safer as it doesn't rely on WooCommerce functions that might not be available
        global $wp;
        $is_account_page = false;
        
        // Check if this is the my-account endpoint
        if (isset($wp->query_vars['pagename']) && $wp->query_vars['pagename'] === 'my-account') {
            $is_account_page = true;
        }
        
        // Also check for the account page ID if get_option is available
        if (function_exists('get_option')) {
            $account_page_id = get_option('woocommerce_myaccount_page_id');
            if ($account_page_id && is_page($account_page_id)) {
                $is_account_page = true;
            }
        }
        
        // Only load on my account page
        if ($is_account_page) {
            // Enqueue QR code library
            wp_enqueue_script(
                'qrcode-js',
                'https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js',
                array('jquery'),
                '1.4.4',
                true
            );
            
            // Enqueue custom CSS
            wp_enqueue_style(
                'rj-indiapost-frontend-style',
                plugin_dir_url(__FILE__) . 'css/frontend-style.css',
                array(),
                '1.0.0'
            );
            
            // Add inline script for QR code generation
            wp_add_inline_script('qrcode-js', '
                jQuery(document).ready(function($) {
                    // Wait a bit to ensure the QR code library is fully loaded
                    setTimeout(function() {
                        $(".rj-tracking-qr-code").each(function() {
                            var container = $(this);
                            var trackingUrl = container.data("tracking-url");
                            
                            if (trackingUrl && typeof qrcode === "function") {
                                var qr = qrcode(0, "M");
                                qr.addData(trackingUrl);
                                qr.make();
                                container.html(qr.createImgTag(3));
                            }
                        });
                    }, 500);
                });
            ');
        }
    }
    
    /**
     * Add tracking column to orders table
     * 
     * @param array $columns Existing columns
     * @return array Modified columns
     */
    public function add_tracking_column($columns) {
        $new_columns = array();
        
        // Insert tracking column before actions column
        foreach ($columns as $key => $name) {
            if ($key === 'order-actions') {
                $new_columns['order-tracking'] = __('Tracking', 'rj-woo-indiapost-tracking');
            }
            $new_columns[$key] = $name;
        }
        
        return $new_columns;
    }
    
    /**
     * Handle order meta data in a way that works with both traditional posts and HPOS
     * 
     * @param int|WC_Order $order Order ID or order object
     * @param string $key Meta key
     * @param mixed $value Optional meta value to set
     * @param bool $is_get Whether this is a get or update operation
     * @return mixed|void Meta value if getting, void if updating
     */
    private function handle_order_meta($order, $key, $value = null, $is_get = true) {
        // Get WC_Order object if we have an ID
        if (!is_object($order) && function_exists('wc_get_order')) {
            $order = wc_get_order($order);
        }

        // If we couldn't get an order or wc_get_order doesn't exist, fall back to post meta
        if (!is_object($order)) {
            if ($is_get) {
                return get_post_meta($order, $key, true);
            } else {
                update_post_meta($order, $key, $value);
                return;
            }
        }

        // Use WC_Order methods which work with both HPOS and traditional storage
        if ($is_get) {
            return $order->get_meta($key);
        } else {
            $order->update_meta_data($key, $value);
            $order->save();
        }
    }
    
    /**
     * Add content to tracking column
     * 
     * @param WC_Order $order Order object
     */
    public function add_tracking_column_content($order) {
        $tracking_number = $this->handle_order_meta($order, '_rj_indiapost_tracking_number', null, true);
        
        if (!empty($tracking_number)) {
            $tracking_url = 'https://m.aftership.com/india-post/' . $tracking_number;
            ?>
            <div class="rj-tracking-info">
                <div class="rj-tracking-qr-code" data-tracking-url="<?php echo esc_attr($tracking_url); ?>"></div>
                <div class="rj-tracking-number">
                    <small><?php echo esc_html($tracking_number); ?></small>
                </div>
                <a href="<?php echo esc_url($tracking_url); ?>" target="_blank" class="rj-tracking-button">
                    <?php _e('Track', 'rj-woo-indiapost-tracking'); ?>
                </a>
            </div>
            
            <!-- Inline script as a fallback to generate this specific QR code -->
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Try to generate this specific QR code
                var container = $(this).find('.rj-tracking-qr-code');
                var trackingUrl = '<?php echo esc_js($tracking_url); ?>';
                
                // Wait for QR code library to be available
                var checkQRCode = setInterval(function() {
                    if (typeof qrcode === 'function') {
                        clearInterval(checkQRCode);
                        
                        var qr = qrcode(0, 'M');
                        qr.addData(trackingUrl);
                        qr.make();
                        container.html(qr.createImgTag(3));
                    }
                }, 100);
                
                // Stop checking after 5 seconds
                setTimeout(function() {
                    clearInterval(checkQRCode);
                }, 5000);
            });
            </script>
            <?php
        } else {
            echo '<span class="rj-no-tracking">' . __('No tracking', 'rj-woo-indiapost-tracking') . '</span>';
        }
    }
}

// Initialize the class
new RJ_IndiaPost_Frontend_Display(); 