<?php
/**
 * Handles the dashboard widget for LHA Progressive HTML Loading.
 *
 * Displays overview stats and quick links.
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

if (!class_exists('LHA_Admin_DashboardWidget')) {
    /**
     * Class LHA_Admin_DashboardWidget.
     * Manages the dashboard widget.
     *
     * @since 0.2.0
     */
    class LHA_Admin_DashboardWidget {

        /**
         * Initializes dashboard widget functionality by adding WordPress hooks.
         *
         * This method should be called if is_admin() is true.
         *
         * @since 0.2.0
         * @return void
         */
        public static function boot() {
            add_action('wp_dashboard_setup', array(__CLASS__, 'register_widget'));
        }

        /**
         * Registers the dashboard widget if enabled in settings.
         *
         * @since 0.2.0
         * @return void
         */
        public static function register_widget() {
            $options = get_option('lha_progressive_html_settings', array());
            
            if (empty($options['enable_dashboard_widget'])) {
                return;
            }

            wp_add_dashboard_widget(
                'lha_streaming_dashboard_widget',
                __('Prog. HTML Streaming Stats', 'lha-progressive-html'),
                array(__CLASS__, 'render_widget_content')
            );
        }

        /**
         * Renders the content of the dashboard widget.
         *
         * Displays stats and quick links.
         *
         * @since 0.2.0
         * @return void
         */
        public static function render_widget_content() {
            $options = get_option('lha_progressive_html_settings', array());
            $processing_post_types = $options['processing_post_types'] ?? array('post', 'page'); // Default to post, page
            
            // --- Stats: Posts Processed ---
            $processed_count = 0;
            // Construct the meta key based on StorageManager constants if possible, or hardcode if stable.
            // Assuming LHA_Core_StorageManager::META_KEY_PREFIX and LHA_Core_StorageManager::CURRENT_META_STRUCTURE_VERSION are not accessible directly here
            // without loading the class or having them defined globally. For now, use the known key.
            $meta_key_for_processed_content = '_lha_processed_content_v2'; // From StorageManager

            if (!empty($processing_post_types)) {
                $query_args = array(
                    'post_type'      => $processing_post_types,
                    'post_status'    => 'publish', // Consider other statuses?
                    'posts_per_page' => -1,
                    'meta_key'       => $meta_key_for_processed_content,
                    'fields'         => 'ids', // More efficient
                );
                $processed_query = new \WP_Query($query_args);
                $processed_count = $processed_query->found_posts;
            }

            // --- Stats: Posts Pending ---
            $pending_count_display = 'N/A';
            if (function_exists('as_get_scheduled_actions')) {
                $pending_actions_args = array(
                    'hook'     => 'lha_process_post_content_action',
                    'status'   => \ActionScheduler_Store::STATUS_PENDING,
                    'per_page' => -1, // Get all
                    'group'    => 'lha-progressive-html-processing', // From ProgressiveHTMLLoaderPlugin::ACTION_GROUP
                );
                $pending_actions = as_get_scheduled_actions($pending_actions_args, 'ids');
                $pending_count_display = count($pending_actions);
            } else {
                $pending_count_display = __('N/A (Action Scheduler not active)', 'lha-progressive-html');
            }

            // --- Render Widget HTML ---
            echo '<div class="lha-dashboard-widget">';
            
            // Stats Section
            echo '<h4>' . esc_html__('Processing Overview', 'lha-progressive-html') . '</h4>';
            echo '<ul>';
            echo '<li>' . sprintf(
                esc_html__('Posts Pre-processed: %s', 'lha-progressive-html'),
                '<strong>' . esc_html($processed_count) . '</strong>'
            ) . '</li>';
            echo '<li>' . sprintf(
                esc_html__('Posts Queued for Processing: %s', 'lha-progressive-html'),
                '<strong>' . esc_html($pending_count_display) . '</strong>'
            ) . '</li>';
            echo '<li>' . esc_html__('Errors Logged: ', 'lha-progressive-html') . '<em>' . esc_html__('Error stats TBD.', 'lha-progressive-html') . '</em></li>';
            echo '</ul>';

            // Quick Links Section
            echo '<h4>' . esc_html__('Quick Links', 'lha-progressive-html') . '</h4>';
            echo '<ul>';
            // Plugin Settings
            echo '<li><a href="' . esc_url(admin_url('options-general.php?page=lha-progressive-html-settings')) . '">' .
                 esc_html__('Plugin Settings', 'lha-progressive-html') . '</a></li>';
            
            // Manual Tools (link to settings page, user to scroll)
            echo '<li><a href="' . esc_url(admin_url('options-general.php?page=lha-progressive-html-settings')) . '">' .
                 esc_html__('Manual Processing Tools (scroll to section on settings page)', 'lha-progressive-html') . '</a></li>';

            // Action Scheduler Queue
            if (function_exists('as_get_scheduled_actions')) {
                $as_group_slug = 'lha-progressive-html-processing'; // From ProgressiveHTMLLoaderPlugin::ACTION_GROUP
                $as_url = add_query_arg(array(
                    'page'   => 'action-scheduler',
                    'status' => 'pending',
                    'group'  => $as_group_slug,
                    // 's' => $as_group_slug, // Use 's' for searching by group in some AS versions
                ), admin_url('tools.php'));
                echo '<li><a href="' . esc_url($as_url) . '" target="_blank">' .
                     esc_html__('View Pending Task Queue', 'lha-progressive-html') .
                     ' <span class="dashicons dashicons-external"></span></a></li>';
            }
            echo '</ul>';

            echo '</div>'; // .lha-dashboard-widget
        }
    }
}
// Closing ?> tag omitted as per WordPress coding standards.
