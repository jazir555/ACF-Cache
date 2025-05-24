<?php
/**
 * Handles the metabox on post edit screens for LHA Progressive HTML Loading.
 *
 * Provides status information about pre-processed content and actions
 * like reprocessing or deleting the stored data for a specific post.
 *
 * @package LHA\ProgressiveHTML\Admin
 * @since 0.2.0
 * @author Jules
 * @license GPLv2 or later
 */

// Ensure this file is not accessed directly.
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if (!class_exists('LHA_Admin_Metabox')) {
    /**
     * Class LHA_Admin_Metabox.
     * Manages the post edit screen metabox.
     *
     * @since 0.2.0
     */
    class LHA_Admin_Metabox {

        /**
         * Nonce action for metabox operations.
         * @var string
         */
        private static $nonce_action = 'lha_metabox_actions';

        /**
         * Initializes metabox functionality by adding WordPress hooks.
         *
         * This method should be called if is_admin() is true.
         *
         * @since 0.2.0
         * @return void
         */
        public static function boot() {
            add_action('add_meta_boxes', array(__CLASS__, 'add_metabox_on_selected_post_types'));
            
            // AJAX Handlers
            add_action('wp_ajax_lha_metabox_reprocess_post', array(__CLASS__, 'handle_ajax_reprocess_post'));
            add_action('wp_ajax_lha_metabox_delete_data', array(__CLASS__, 'handle_ajax_delete_data'));
        }

        /**
         * Adds the metabox to selected post types if enabled in settings.
         *
         * @since 0.2.0
         * @param string $post_type The current post type.
         * @return void
         */
        public static function add_metabox_on_selected_post_types(string $post_type) {
            $options = get_option('lha_progressive_html_settings', array());
            
            if (empty($options['enable_metabox'])) {
                return;
            }

            // Use 'processing_post_types' as these are the ones we'd have data for or want to process.
            $target_post_types = $options['processing_post_types'] ?? array(); 
            
            if (in_array($post_type, $target_post_types, true)) {
                add_meta_box(
                    'lha_progressive_html_metabox',
                    __('LHA Progressive HTML', 'lha-progressive-html'),
                    array(__CLASS__, 'render_metabox_content'),
                    $post_type,
                    'side', // context
                    'low'   // priority
                );
            }
        }

        /**
         * Renders the content of the metabox.
         *
         * Displays status of pre-processed content and action buttons.
         *
         * @since 0.2.0
         * @param \WP_Post $post The current post object.
         * @return void
         */
        public static function render_metabox_content(\WP_Post $post) {
            // Use a more specific name for the nonce field to be sent via AJAX
            wp_nonce_field(self::$nonce_action, 'lha_metabox_ajax_nonce_field'); 
            $post_id = $post->ID;

            $status_message = __('Status: Unknown', 'lha-progressive-html');
            $processed_data = null;
            $storage_manager = null;
            $scheduler = null;

            if (function_exists('lha_progressive_html_loader_plugin')) {
                $plugin_instance = lha_progressive_html_loader_plugin();
                if ($plugin_instance && method_exists($plugin_instance, 'get_service_locator')) {
                    $service_locator = $plugin_instance->get_service_locator();
                    if ($service_locator) {
                        $storage_manager = $service_locator->get('storage_manager');
                        $scheduler = $service_locator->get('scheduler');
                    }
                }
            }

            if ($storage_manager instanceof LHA_Core_StorageManager) {
                $processed_data = $storage_manager->get_processed_content($post_id);
                if ($processed_data) {
                    $current_plugin_version = defined('LHA_PROGRESSIVE_HTML_VERSION') ? LHA_PROGRESSIVE_HTML_VERSION : '0.1.0';
                    $is_stale = version_compare($processed_data['version'], $current_plugin_version, '<');
                    $status_message = sprintf(
                        __('Status: Processed (Version: %s, Timestamp: %s). %s', 'lha-progressive-html'),
                        esc_html($processed_data['version']),
                        esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $processed_data['timestamp'])),
                        $is_stale ? '<strong style="color:orange;">' . __('Stale', 'lha-progressive-html') . '</strong>' : '<strong style="color:green;">' . __('Current', 'lha-progressive-html') . '</strong>'
                    );
                } else {
                    $status_message = __('Status: Not yet processed.', 'lha-progressive-html');
                }
            } else {
                $status_message = __('Status: Storage Manager not available.', 'lha-progressive-html');
            }
            
            if ($scheduler instanceof LHA_Core_Scheduler && $scheduler->is_action_scheduled('lha_process_post_content_action', array('post_id' => $post_id))) {
                $status_message .= ' <em style="color:#0073aa;">' . __('(Queued for processing)', 'lha-progressive-html') . '</em>';
            }

            echo '<p id="lha-metabox-status">' . wp_kses_post($status_message) . '</p>';
            echo '<div id="lha-metabox-message-area" style="margin-bottom:10px;"></div>';

            submit_button(
                __('Reprocess Now', 'lha-progressive-html'),
                'secondary',
                'lha_metabox_reprocess_button',
                false, // don't wrap in p
                array('data-post-id' => $post_id)
            );

            if ($processed_data) {
                submit_button(
                    __('Delete Processed Data', 'lha-progressive-html'),
                    'delete', // Adds 'delete' class for styling (often red)
                    'lha_metabox_delete_data_button',
                    false,
                    array('data-post-id' => $post_id)
                );
            }
        }

        /**
         * Handles AJAX request to reprocess a single post.
         *
         * @since 0.2.0
         */
        public static function handle_ajax_reprocess_post() {
            // Nonce field name must match what JS sends. For check_ajax_referer, action is used as the handle.
            check_ajax_referer(self::$nonce_action, 'nonce'); // JS should send 'nonce' parameter
            $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

            if (!current_user_can('edit_post', $post_id)) {
                wp_send_json_error(array('message' => __('Permission denied.', 'lha-progressive-html')), 403);
            }

            if ($post_id > 0) {
                $scheduler = null;
                if (function_exists('lha_progressive_html_loader_plugin')) {
                     $plugin_instance = lha_progressive_html_loader_plugin();
                     if ($plugin_instance && method_exists($plugin_instance, 'get_service_locator')) {
                        $service_locator = $plugin_instance->get_service_locator();
                        if ($service_locator) $scheduler = $service_locator->get('scheduler');
                     }
                }

                if ($scheduler instanceof LHA_Core_Scheduler) {
                    // Unschedule existing to ensure it runs ASAP if already scheduled for later
                    $scheduler->unschedule_action('lha_process_post_content_action', array('post_id' => $post_id));
                    $action_id = $scheduler->enqueue_async_action('lha_process_post_content_action', array('post_id' => $post_id), false); // Don't make unique, always schedule
                    
                    if ($action_id) {
                        wp_send_json_success(array('message' => sprintf(__('Post ID %d scheduled for reprocessing. Action ID: %s', 'lha-progressive-html'), $post_id, $action_id)));
                    } else {
                        wp_send_json_error(array('message' => sprintf(__('Failed to schedule reprocessing for Post ID %d.', 'lha-progressive-html'), $post_id)));
                    }
                } else {
                    wp_send_json_error(array('message' => __('Scheduler service not available.', 'lha-progressive-html')));
                }
            } else {
                wp_send_json_error(array('message' => __('Invalid Post ID.', 'lha-progressive-html')));
            }
        }

        /**
         * Handles AJAX request to delete processed data for a single post.
         *
         * @since 0.2.0
         */
        public static function handle_ajax_delete_data() {
            check_ajax_referer(self::$nonce_action, 'nonce'); // JS should send 'nonce' parameter
            $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

            if (!current_user_can('edit_post', $post_id)) {
                wp_send_json_error(array('message' => __('Permission denied.', 'lha-progressive-html')), 403);
            }

            if ($post_id > 0) {
                $storage_manager = null;
                 if (function_exists('lha_progressive_html_loader_plugin')) {
                     $plugin_instance = lha_progressive_html_loader_plugin();
                     if ($plugin_instance && method_exists($plugin_instance, 'get_service_locator')) {
                        $service_locator = $plugin_instance->get_service_locator();
                        if ($service_locator) $storage_manager = $service_locator->get('storage_manager');
                     }
                }

                if ($storage_manager instanceof LHA_Core_StorageManager) {
                    if ($storage_manager->delete_processed_content($post_id)) {
                        wp_send_json_success(array('message' => sprintf(__('Processed data for Post ID %d deleted.', 'lha-progressive-html'), $post_id)));
                    } else {
                        wp_send_json_error(array('message' => sprintf(__('Failed to delete processed data for Post ID %d, or no data existed.', 'lha-progressive-html'), $post_id)));
                    }
                } else {
                    wp_send_json_error(array('message' => __('Storage Manager service not available.', 'lha-progressive-html')));
                }
            } else {
                wp_send_json_error(array('message' => __('Invalid Post ID.', 'lha-progressive-html')));
            }
        }
    }
}
// Closing ?> tag omitted as per WordPress coding standards.
