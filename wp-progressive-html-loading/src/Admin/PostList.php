<?php
/**
 * Adds a custom column to post list tables to show LHA Progressive HTML status.
 *
 * Provides a quick overview of which posts have been pre-processed,
 * their version, and if they are queued.
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

if (!class_exists('LHA_Admin_PostList')) {
    /**
     * Class LHA_Admin_PostList.
     * Manages custom columns in post list tables.
     *
     * @since 0.2.0
     */
    class LHA_Admin_PostList {

        /**
         * Initializes post list column functionality by adding WordPress hooks.
         *
         * This method should be called if is_admin() is true.
         * It checks plugin settings to determine which post types should have the column.
         *
         * @since 0.2.0
         * @return void
         */
        public static function boot() {
            $options = get_option('lha_progressive_html_settings', array());
            
            if (empty($options['enable_post_list_column'])) {
                return;
            }

            // Use 'processing_post_types' as these are the ones relevant for status.
            $target_post_types = $options['processing_post_types'] ?? array(); 

            if (empty($target_post_types) || !is_array($target_post_types)) {
                return;
            }

            foreach ($target_post_types as $post_type) {
                if (post_type_exists($post_type)) {
                    // Add column header
                    add_filter("manage_{$post_type}_posts_columns", array(__CLASS__, 'add_status_column_header'));
                    // Add column content
                    add_action("manage_{$post_type}_posts_custom_column", array(__CLASS__, 'render_status_column_content'), 10, 2);
                    
                    // Add bulk action filters
                    add_filter("bulk_actions-edit-{$post_type}", array(__CLASS__, 'add_custom_bulk_actions'));
                    add_filter("handle_bulk_actions-edit-{$post_type}", array(__CLASS__, 'handle_custom_bulk_actions'), 10, 3);
                }
            }
            // Hook for displaying admin notices after bulk actions
            add_action('admin_notices', array(__CLASS__, 'display_bulk_action_admin_notices'));
        }

        /**
         * Adds the 'Streaming Status' column header to the post list table.
         *
         * @since 0.2.0
         * @param array $columns Existing columns.
         * @return array Modified columns array.
         */
        public static function add_status_column_header(array $columns): array {
            // Add column before 'date' or at the end if 'date' is not found
            $new_columns = array();
            $date_column_found = false;

            foreach ($columns as $key => $title) {
                if ($key === 'date') {
                    $new_columns['lha_streaming_status'] = __('Streaming Status', 'lha-progressive-html');
                    $date_column_found = true;
                }
                $new_columns[$key] = $title;
            }

            if (!$date_column_found) {
                $new_columns['lha_streaming_status'] = __('Streaming Status', 'lha-progressive-html');
            }
            
            return $new_columns;
        }

        /**
         * Renders the content for the 'Streaming Status' column.
         *
         * Displays status: Processed (version, date), Queued, or Not Processed.
         *
         * @since 0.2.0
         * @param string $column_name The name of the current column.
         * @param int    $post_id     The ID of the current post.
         * @return void
         */
        public static function render_status_column_content(string $column_name, int $post_id): void {
            if ($column_name !== 'lha_streaming_status') {
                return;
            }

            $status_html = '<span style="color:#aaa;">' . esc_html__('N/A', 'lha-progressive-html') . '</span>';
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
                    $status_html = sprintf(
                        '<span title="%s: %s | %s: %s">%s (%s)</span>',
                        esc_attr__('Version', 'lha-progressive-html'),
                        esc_attr($processed_data['version']),
                        esc_attr__('Timestamp', 'lha-progressive-html'),
                        esc_attr(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $processed_data['timestamp'])),
                        esc_html__('Processed', 'lha-progressive-html'),
                        $is_stale ? '<strong style="color:orange;">' . esc_html__('Stale', 'lha-progressive-html') . '</strong>' : '<strong style="color:green;">' . esc_html__('Current', 'lha-progressive-html') . '</strong>'
                    );
                } else {
                    $status_html = '<span style="color:#777;">' . esc_html__('Not Processed', 'lha-progressive-html') . '</span>';
                }
            } else {
                $status_html = '<span style="color:red;">' . esc_html__('Error: SM Missing', 'lha-progressive-html') . '</span>';
            }
            
            if ($scheduler instanceof LHA_Core_Scheduler && $scheduler->is_action_scheduled('lha_process_post_content_action', array('post_id' => $post_id))) {
                $status_html .= ' <em style="color:#0073aa; display:block; font-size:0.9em;">(' . esc_html__('Queued', 'lha-progressive-html') . ')</em>';
            }

            echo wp_kses_post($status_html); // Use wp_kses_post as we are outputting HTML with strong, em, span tags
        }

        /**
         * Adds custom bulk actions to the post list table.
         *
         * @since 0.2.0
         * @param array $bulk_actions Existing bulk actions.
         * @return array Modified bulk actions.
         */
        public static function add_custom_bulk_actions(array $bulk_actions): array {
            $bulk_actions['lha_bulk_process'] = __('Process for Streaming', 'lha-progressive-html');
            $bulk_actions['lha_bulk_clear'] = __('Clear Processed Data', 'lha-progressive-html');
            return $bulk_actions;
        }

        /**
         * Handles the execution of custom bulk actions.
         *
         * @since 0.2.0
         * @param string $redirect_to The URL to redirect to after handling the action.
         * @param string $action_name The name of the action to perform.
         * @param array  $post_ids    An array of post IDs to perform the action on.
         * @return string The redirect URL.
         */
        public static function handle_custom_bulk_actions(string $redirect_to, string $action_name, array $post_ids): string {
            if ('lha_bulk_process' !== $action_name && 'lha_bulk_clear' !== $action_name) {
                return $redirect_to;
            }

            check_admin_referer('bulk-posts');

            // Basic capability check, adjust if a more specific one is needed.
            if (!current_user_can('edit_others_posts')) {
                if (class_exists('LHA_Logging')) {
                    LHA_Logging::error('Bulk Actions: User does not have capability to edit others posts.');
                }
                // Optionally add an admin notice about insufficient permissions.
                set_transient('lha_bulk_action_admin_notice', __('You do not have sufficient permissions to perform this action.', 'lha-progressive-html'), 30);
                return $redirect_to;
            }

            $scheduler = null;
            if (function_exists('lha_progressive_html_loader_plugin')) {
                $plugin_instance = lha_progressive_html_loader_plugin();
                if ($plugin_instance && method_exists($plugin_instance, 'get_service_locator')) {
                    $service_locator = $plugin_instance->get_service_locator();
                    if ($service_locator) {
                        $scheduler = $service_locator->get('scheduler');
                    }
                }
            }

            if (!$scheduler instanceof LHA_Core_Scheduler) {
                if (class_exists('LHA_Logging')) {
                    LHA_Logging::error('Bulk Actions: Scheduler service not available.');
                }
                set_transient('lha_bulk_action_admin_notice', __('Error: Scheduler service not available.', 'lha-progressive-html'), 30);
                return $redirect_to;
            }

            $processed_count = 0;
            foreach ($post_ids as $post_id) {
                $post_id_int = absint($post_id);
                if ($post_id_int <= 0) continue;

                if ('lha_bulk_process' === $action_name) {
                    $scheduler->enqueue_async_action('lha_process_post_content_action', array('post_id' => $post_id_int), true);
                    $processed_count++;
                } elseif ('lha_bulk_clear' === $action_name) {
                    $scheduler->unschedule_action('lha_process_post_content_action', array('post_id' => $post_id_int));
                    $scheduler->enqueue_async_action('lha_cleanup_post_data_action', array('post_id' => $post_id_int), true);
                    $processed_count++;
                }
            }

            if ($processed_count > 0) {
                $message = '';
                if ('lha_bulk_process' === $action_name) {
                    $message = sprintf(
                        _n(
                            '%d post scheduled for processing.',
                            '%d posts scheduled for processing.',
                            $processed_count,
                            'lha-progressive-html'
                        ),
                        $processed_count
                    );
                } elseif ('lha_bulk_clear' === $action_name) {
                     $message = sprintf(
                        _n(
                            'Cleanup scheduled for %d post and any pending processing unscheduled.',
                            'Cleanup scheduled for %d posts and any pending processing unscheduled.',
                            $processed_count,
                            'lha-progressive-html'
                        ),
                        $processed_count
                    );
                }
                set_transient('lha_bulk_action_admin_notice', $message, 30);
            }
            
            // WordPress typically adds query args like `?processed=$count&ids=...`
            // We can use our transient for a more specific message.
            // $redirect_to = add_query_arg( array( 'lha_processed_count' => $processed_count, 'lha_action_done' => $action_name ), $redirect_to );
            return $redirect_to;
        }

        /**
         * Displays admin notices after custom bulk actions.
         *
         * @since 0.2.0
         */
        public static function display_bulk_action_admin_notices() {
            if ($notice = get_transient('lha_bulk_action_admin_notice')) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notice) . '</p></div>';
                delete_transient('lha_bulk_action_admin_notice');
            }
        }
    }
}
// Closing ?> tag omitted as per WordPress coding standards.
