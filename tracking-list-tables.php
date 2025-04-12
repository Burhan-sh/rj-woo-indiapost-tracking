<?php
/**
 * India Post Tracking List Tables
 * 
 * Implements WP_List_Table for displaying EG and CG tracking numbers
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * EG Tracking List Table
 */
class RJ_EG_Tracking_List_Table extends WP_List_Table {
    
    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct(array(
            'singular' => 'eg_tracking',
            'plural'   => 'eg_trackings',
            'ajax'     => false
        ));
    }
    
    /**
     * Get columns
     * 
     * @return array
     */
    public function get_columns() {
        return array(
            'cb'            => '<input type="checkbox" />',
            'tracking_id'   => __('Tracking ID', 'rj-woo-indiapost-tracking'),
            'current_status' => __('Current Status', 'rj-woo-indiapost-tracking'),
            'datetime'      => __('Date Time', 'rj-woo-indiapost-tracking'),
            'upload_userid' => __('Uploaded By', 'rj-woo-indiapost-tracking'),
            'order_id'      => __('Order ID', 'rj-woo-indiapost-tracking'),
            'modified_at'   => __('Modified At', 'rj-woo-indiapost-tracking'),
            'is_accessable' => __('Is Accessible', 'rj-woo-indiapost-tracking'),
            'actions'       => __('Actions', 'rj-woo-indiapost-tracking')
        );
    }
    
    /**
     * Get sortable columns
     * 
     * @return array
     */
    public function get_sortable_columns() {
        return array(
            'tracking_id'   => array('tracking_id', true),
            'current_status' => array('current_status', false),
            'datetime'      => array('datetime', false),
            'upload_userid' => array('upload_userid', false),
            'order_id'      => array('order_id', false),
            'modified_at'   => array('modified_at', false),
            'is_accessable' => array('is_accessable', false)
        );
    }
    
    /**
     * Prepare items for the table
     */
    public function prepare_items() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'EG_india_post_tracking';
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
        
        if (!$table_exists) {
            $this->items = array();
            return;
        }
        
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        
        $this->_column_headers = array($columns, $hidden, $sortable);
        
        // Process bulk actions
        $this->process_bulk_action();
        
        // Pagination setup
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;
        
        // Prepare query
        $search = (isset($_REQUEST['s'])) ? sanitize_text_field($_REQUEST['s']) : '';
        
        $where = '';
        if (!empty($search)) {
            $where = $wpdb->prepare(" WHERE tracking_id LIKE %s", "%{$search}%");
        }
        
        // Order parameters
        $orderby = !empty($_REQUEST['orderby']) ? sanitize_sql_orderby($_REQUEST['orderby']) : 'id';
        $order = !empty($_REQUEST['order']) ? sanitize_text_field($_REQUEST['order']) : 'DESC';
        
        // Get total items
        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} {$where}");
        
        // Get items
        $this->items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );
        
        // Setup pagination
        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ));
    }
    
    /**
     * Get bulk actions
     * 
     * @return array
     */
    public function get_bulk_actions() {
        return array(
            'delete' => __('Delete', 'rj-woo-indiapost-tracking'),
            'make_inaccessible' => __('Make Inaccessible', 'rj-woo-indiapost-tracking'),
            'make_accessible' => __('Make Accessible', 'rj-woo-indiapost-tracking')
        );
    }
    
    /**
     * Process bulk actions
     */
    public function process_bulk_action() {
        // Check if a bulk action is being triggered
        if ('delete' === $this->current_action()) {
            // Verify the nonce
            if (!wp_verify_nonce($_REQUEST['_wpnonce'], 'bulk-' . $this->_args['plural'])) {
                wp_die('Security check failed');
            }
            
            // Process the delete action
            if (isset($_REQUEST['tracking'])) {
                global $wpdb;
                $table_name = $wpdb->prefix . 'EG_india_post_tracking';
                
                $ids = array_map('intval', (array) $_REQUEST['tracking']);
                
                // Delete the selected items
                foreach ($ids as $id) {
                    $wpdb->delete($table_name, array('id' => $id), array('%d'));
                }
                
                // Add an admin notice
                add_action('admin_notices', function() use ($ids) {
                    $count = count($ids);
                    $message = sprintf(_n(
                        '%d tracking number has been deleted.',
                        '%d tracking numbers have been deleted.',
                        $count,
                        'rj-woo-indiapost-tracking'
                    ), $count);
                    
                    echo '<div class="notice notice-success is-dismissible"><p>' . $message . '</p></div>';
                });
            }
        }
        
        // Process make inaccessible action
        if ('make_inaccessible' === $this->current_action()) {
            if (!wp_verify_nonce($_REQUEST['_wpnonce'], 'bulk-' . $this->_args['plural'])) {
                wp_die('Security check failed');
            }
            
            if (isset($_REQUEST['tracking'])) {
                global $wpdb;
                $table_name = $wpdb->prefix . 'EG_india_post_tracking';
                
                $ids = array_map('intval', (array) $_REQUEST['tracking']);
                
                // Update the selected items
                foreach ($ids as $id) {
                    $wpdb->update(
                        $table_name,
                        array('is_accessable' => 'No'),
                        array('id' => $id),
                        array('%s'),
                        array('%d')
                    );
                }
                
                // Add an admin notice
                add_action('admin_notices', function() use ($ids) {
                    $count = count($ids);
                    $message = sprintf(_n(
                        '%d tracking number has been made inaccessible.',
                        '%d tracking numbers have been made inaccessible.',
                        $count,
                        'rj-woo-indiapost-tracking'
                    ), $count);
                    
                    echo '<div class="notice notice-success is-dismissible"><p>' . $message . '</p></div>';
                });
            }
        }
        
        // Process make accessible action
        if ('make_accessible' === $this->current_action()) {
            if (!wp_verify_nonce($_REQUEST['_wpnonce'], 'bulk-' . $this->_args['plural'])) {
                wp_die('Security check failed');
            }
            
            if (isset($_REQUEST['tracking'])) {
                global $wpdb;
                $table_name = $wpdb->prefix . 'EG_india_post_tracking';
                
                $ids = array_map('intval', (array) $_REQUEST['tracking']);
                
                // Update the selected items
                foreach ($ids as $id) {
                    $wpdb->update(
                        $table_name,
                        array('is_accessable' => 'Yes'),
                        array('id' => $id),
                        array('%s'),
                        array('%d')
                    );
                }
                
                // Add an admin notice
                add_action('admin_notices', function() use ($ids) {
                    $count = count($ids);
                    $message = sprintf(_n(
                        '%d tracking number has been made accessible.',
                        '%d tracking numbers have been made accessible.',
                        $count,
                        'rj-woo-indiapost-tracking'
                    ), $count);
                    
                    echo '<div class="notice notice-success is-dismissible"><p>' . $message . '</p></div>';
                });
            }
        }
    }
    
    /**
     * Column default
     * 
     * @param array $item Item data
     * @param string $column_name Column name
     * @return string
     */
    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'tracking_id':
            case 'current_status':
            case 'order_id':
            case 'is_accessable':
                return $item[$column_name];
            case 'datetime':
            case 'modified_at':
                return !empty($item[$column_name]) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($item[$column_name])) : '—';
            case 'upload_userid':
                $user = get_user_by('id', $item[$column_name]);
                return $user ? $user->display_name : $item[$column_name];
            default:
                return print_r($item, true);
        }
    }
    
    /**
     * Get the checkbox column
     * 
     * @param array $item Item data
     * @return string
     */
    public function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="tracking[]" value="%s" />',
            $item['id']
        );
    }
    
    /**
     * Get the actions column
     * 
     * @param array $item Item data
     * @return string
     */
    public function column_actions($item) {
        $tracking_link = 'https://www.indiapost.gov.in/VAS/Pages/trackconsignment.aspx?consignment=' . $item['tracking_id'];
        
        $actions = array(
            'track' => sprintf('<a href="%s" target="_blank">%s</a>', esc_url($tracking_link), __('Track', 'rj-woo-indiapost-tracking')),
            'delete' => sprintf('<a href="%s" class="delete-tracking" data-tracking-id="%s">%s</a>', '#', $item['id'], __('Delete', 'rj-woo-indiapost-tracking'))
        );
        
        return implode(' | ', $actions);
    }
    
    /**
     * No items found message
     */
    public function no_items() {
        _e('No EG tracking numbers found.', 'rj-woo-indiapost-tracking');
    }
}

/**
 * CG Tracking List Table
 */
class RJ_CG_Tracking_List_Table extends WP_List_Table {
    
    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct(array(
            'singular' => 'cg_tracking',
            'plural'   => 'cg_trackings',
            'ajax'     => false
        ));
    }
    
    /**
     * Get columns
     * 
     * @return array
     */
    public function get_columns() {
        return array(
            'cb'            => '<input type="checkbox" />',
            'tracking_id'   => __('Tracking ID', 'rj-woo-indiapost-tracking'),
            'current_status' => __('Current Status', 'rj-woo-indiapost-tracking'),
            'datetime'      => __('Date Time', 'rj-woo-indiapost-tracking'),
            'upload_userid' => __('Uploaded By', 'rj-woo-indiapost-tracking'),
            'order_id'      => __('Order ID', 'rj-woo-indiapost-tracking'),
            'modified_at'   => __('Modified At', 'rj-woo-indiapost-tracking'),
            'is_accessable' => __('Is Accessible', 'rj-woo-indiapost-tracking'),
            'actions'       => __('Actions', 'rj-woo-indiapost-tracking')
        );
    }
    
    /**
     * Get sortable columns
     * 
     * @return array
     */
    public function get_sortable_columns() {
        return array(
            'tracking_id'   => array('tracking_id', true),
            'current_status' => array('current_status', false),
            'datetime'      => array('datetime', false),
            'upload_userid' => array('upload_userid', false),
            'order_id'      => array('order_id', false),
            'modified_at'   => array('modified_at', false),
            'is_accessable' => array('is_accessable', false)
        );
    }
    
    /**
     * Prepare items for the table
     */
    public function prepare_items() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'CG_india_post_tracking';
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
        
        if (!$table_exists) {
            $this->items = array();
            return;
        }
        
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        
        $this->_column_headers = array($columns, $hidden, $sortable);
        
        // Process bulk actions
        $this->process_bulk_action();
        
        // Pagination setup
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;
        
        // Prepare query
        $search = (isset($_REQUEST['s'])) ? sanitize_text_field($_REQUEST['s']) : '';
        
        $where = '';
        if (!empty($search)) {
            $where = $wpdb->prepare(" WHERE tracking_id LIKE %s", "%{$search}%");
        }
        
        // Order parameters
        $orderby = !empty($_REQUEST['orderby']) ? sanitize_sql_orderby($_REQUEST['orderby']) : 'id';
        $order = !empty($_REQUEST['order']) ? sanitize_text_field($_REQUEST['order']) : 'DESC';
        
        // Get total items
        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} {$where}");
        
        // Get items
        $this->items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );
        
        // Setup pagination
        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ));
    }
    
    /**
     * Get bulk actions
     * 
     * @return array
     */
    public function get_bulk_actions() {
        return array(
            'delete' => __('Delete', 'rj-woo-indiapost-tracking'),
            'make_inaccessible' => __('Make Inaccessible', 'rj-woo-indiapost-tracking'),
            'make_accessible' => __('Make Accessible', 'rj-woo-indiapost-tracking')
        );
    }
    
    /**
     * Process bulk actions
     */
    public function process_bulk_action() {
        // Check if a bulk action is being triggered
        if ('delete' === $this->current_action()) {
            // Verify the nonce
            if (!wp_verify_nonce($_REQUEST['_wpnonce'], 'bulk-' . $this->_args['plural'])) {
                wp_die('Security check failed');
            }
            
            // Process the delete action
            if (isset($_REQUEST['tracking'])) {
                global $wpdb;
                $table_name = $wpdb->prefix . 'CG_india_post_tracking';
                
                $ids = array_map('intval', (array) $_REQUEST['tracking']);
                
                // Delete the selected items
                foreach ($ids as $id) {
                    $wpdb->delete($table_name, array('id' => $id), array('%d'));
                }
                
                // Add an admin notice
                add_action('admin_notices', function() use ($ids) {
                    $count = count($ids);
                    $message = sprintf(_n(
                        '%d tracking number has been deleted.',
                        '%d tracking numbers have been deleted.',
                        $count,
                        'rj-woo-indiapost-tracking'
                    ), $count);
                    
                    echo '<div class="notice notice-success is-dismissible"><p>' . $message . '</p></div>';
                });
            }
        }
        
        // Process make inaccessible action
        if ('make_inaccessible' === $this->current_action()) {
            if (!wp_verify_nonce($_REQUEST['_wpnonce'], 'bulk-' . $this->_args['plural'])) {
                wp_die('Security check failed');
            }
            
            if (isset($_REQUEST['tracking'])) {
                global $wpdb;
                $table_name = $wpdb->prefix . 'CG_india_post_tracking';
                
                $ids = array_map('intval', (array) $_REQUEST['tracking']);
                
                // Update the selected items
                foreach ($ids as $id) {
                    $wpdb->update(
                        $table_name,
                        array('is_accessable' => 'No'),
                        array('id' => $id),
                        array('%s'),
                        array('%d')
                    );
                }
                
                // Add an admin notice
                add_action('admin_notices', function() use ($ids) {
                    $count = count($ids);
                    $message = sprintf(_n(
                        '%d tracking number has been made inaccessible.',
                        '%d tracking numbers have been made inaccessible.',
                        $count,
                        'rj-woo-indiapost-tracking'
                    ), $count);
                    
                    echo '<div class="notice notice-success is-dismissible"><p>' . $message . '</p></div>';
                });
            }
        }
        
        // Process make accessible action
        if ('make_accessible' === $this->current_action()) {
            if (!wp_verify_nonce($_REQUEST['_wpnonce'], 'bulk-' . $this->_args['plural'])) {
                wp_die('Security check failed');
            }
            
            if (isset($_REQUEST['tracking'])) {
                global $wpdb;
                $table_name = $wpdb->prefix . 'CG_india_post_tracking';
                
                $ids = array_map('intval', (array) $_REQUEST['tracking']);
                
                // Update the selected items
                foreach ($ids as $id) {
                    $wpdb->update(
                        $table_name,
                        array('is_accessable' => 'Yes'),
                        array('id' => $id),
                        array('%s'),
                        array('%d')
                    );
                }
                
                // Add an admin notice
                add_action('admin_notices', function() use ($ids) {
                    $count = count($ids);
                    $message = sprintf(_n(
                        '%d tracking number has been made accessible.',
                        '%d tracking numbers have been made accessible.',
                        $count,
                        'rj-woo-indiapost-tracking'
                    ), $count);
                    
                    echo '<div class="notice notice-success is-dismissible"><p>' . $message . '</p></div>';
                });
            }
        }
    }
    
    /**
     * Column default
     * 
     * @param array $item Item data
     * @param string $column_name Column name
     * @return string
     */
    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'tracking_id':
            case 'current_status':
            case 'order_id':
            case 'is_accessable':
                return $item[$column_name];
            case 'datetime':
            case 'modified_at':
                return !empty($item[$column_name]) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($item[$column_name])) : '—';
            case 'upload_userid':
                $user = get_user_by('id', $item[$column_name]);
                return $user ? $user->display_name : $item[$column_name];
            default:
                return print_r($item, true);
        }
    }
    
    /**
     * Get the checkbox column
     * 
     * @param array $item Item data
     * @return string
     */
    public function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="tracking[]" value="%s" />',
            $item['id']
        );
    }
    
    /**
     * Get the actions column
     * 
     * @param array $item Item data
     * @return string
     */
    public function column_actions($item) {
        $tracking_link = 'https://www.indiapost.gov.in/VAS/Pages/trackconsignment.aspx?consignment=' . $item['tracking_id'];
        
        $actions = array(
            'track' => sprintf('<a href="%s" target="_blank">%s</a>', esc_url($tracking_link), __('Track', 'rj-woo-indiapost-tracking')),
            'delete' => sprintf('<a href="%s" class="delete-tracking" data-tracking-id="%s">%s</a>', '#', $item['id'], __('Delete', 'rj-woo-indiapost-tracking'))
        );
        
        return implode(' | ', $actions);
    }
    
    /**
     * No items found message
     */
    public function no_items() {
        _e('No CG tracking numbers found.', 'rj-woo-indiapost-tracking');
    }
} 