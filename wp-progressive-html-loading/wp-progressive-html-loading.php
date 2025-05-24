<?php
/**
 * Plugin Name: LHA Progressive HTML Loading
 * Version: 0.1.0
 * Author: Jules
 * Description: Optimizes TTFB by streaming HTML content.
 * License: GPLv2 or later
 * Text Domain: lha-progressive-html
 * Min PHP: 7.1
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if (!defined('LHA_PROGRESSIVE_HTML_PLUGIN_DIR')) {
    define('LHA_PROGRESSIVE_HTML_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

if (!defined('LHA_PROGRESSIVE_HTML_PLUGIN_URL')) {
    define('LHA_PROGRESSIVE_HTML_PLUGIN_URL', plugin_dir_url(__FILE__));
}

if (!defined('LHA_PROGRESSIVE_HTML_VERSION')) {
    define('LHA_PROGRESSIVE_HTML_VERSION', '0.1.0');
}

// Forward declare LHA\Logging for use in Registry
if (!class_exists('LHA\Logging')) {
    /**
     * Basic Logging class (forward declaration).
     * Used for logging errors until a more robust system might be in place.
     * Adjusted to avoid namespace conflict before autoloader setup.
     * @since 0.1.0
     */
    class LHA_Logging {
        /**
         * Logs an error message.
         *
         * @since 0.1.0
         * @param string $message The error message to log.
         * @return void
         */
        public static function error($message) {
            // In a real scenario, this would log to a file or WP_Debug_Log
            error_log("LHA Progressive HTML Loading Error: " . $message);
        }
    }
}


// Ensure core files are included. Paths are relative to this file.
require_once LHA_PROGRESSIVE_HTML_PLUGIN_DIR . 'src/Core/Exception/PluginException.php';
require_once LHA_PROGRESSIVE_HTML_PLUGIN_DIR . 'src/Core/Registry.php';
require_once LHA_PROGRESSIVE_HTML_PLUGIN_DIR . 'src/Core/ServiceLocator.php';


if (!class_exists('LHA\ProgressiveHTMLLoaderPlugin')) {
    /**
     * Main plugin class for LHA Progressive HTML Loading.
     * Handles initialization, loading dependencies, and hooks.
     */
    class ProgressiveHTMLLoaderPlugin { // Adjusted to avoid namespace conflict before autoloader setup, will be LHA\ProgressiveHTMLLoaderPlugin
        /**
         * Action group name for Action Scheduler tasks.
         * @since 0.2.0 // Assuming 0.2.0 for new features
         */
        private const ACTION_GROUP = 'lha-progressive-html-processing';

        /**
         * Singleton instance of the plugin.
         *
         * @var ProgressiveHTMLLoaderPlugin|null
         * @access private
         * @static
         */
        private static $_instance = null;

        /**
         * Instance of the Core Registry.
         *
         * @var LHA\Core\Registry|null
         * @access private
         */
        private $registry;

        /**
         * Instance of the Core Service Locator.
         *
         * @var LHA\Core\ServiceLocator|null
         * @access private
         */
        private $service_locator;

        /**
         * Ensures only one instance of the plugin is loaded.
         *
         * @return ProgressiveHTMLLoaderPlugin
         * @static
         * @since 0.1.0
         */
        public static function instance() {
            if (is_null(self::$_instance)) {
                self::$_instance = new self();
            }
            return self::$_instance;
        }

        /**
         * Private constructor to prevent direct instantiation.
         * Initializes registry, service locator, loads dependencies, and adds hooks.
         *
         * @access private
         * @since 0.1.0
         */
        private function __construct() {
            // Initialize Registry and ServiceLocator
            $this->registry = new LHA_Core_Registry();
            $this->service_locator = new LHA_Core_ServiceLocator();

            $this->load_dependencies();
            $this->add_hooks();
        }

        /**
         * Loads plugin dependencies and registers features/services.
         * Features are registered with the registry.
         * Services are registered with the service locator.
         *
         * @access private
         * @since 0.1.0
         */
        private function load_dependencies() {
            // Existing feature registrations
            $this->registry->register_feature(
                'html_streaming',
                LHA_PROGRESSIVE_HTML_PLUGIN_DIR . 'src/Features/HTMLStreaming.php',
                'LHA_Features_HTMLStreaming', 
                'boot'
            );
            $this->registry->register_feature(
                'admin_settings',
                LHA_PROGRESSIVE_HTML_PLUGIN_DIR . 'src/Admin/SettingsPage.php',
                'LHA_Admin_SettingsPage', 
                'boot'
            );
            $this->registry->register_feature(
                'scheduler',
                LHA_PROGRESSIVE_HTML_PLUGIN_DIR . 'src/Core/Scheduler.php',
                'LHA_Core_Scheduler', 
                'boot'
            );
            $this->registry->register_feature(
                'content_processor',
                LHA_PROGRESSIVE_HTML_PLUGIN_DIR . 'src/Features/ContentProcessor.php',
                'LHA_Features_ContentProcessor',
                'boot' 
            );
            $this->registry->register_feature(
                'storage_manager_boot', 
                LHA_PROGRESSIVE_HTML_PLUGIN_DIR . 'src/Core/StorageManager.php',
                'LHA_Core_StorageManager',
                'boot'
            );

            // Services
            $this->service_locator->register('content_fetcher', function() {
                $fetcher_file = LHA_PROGRESSIVE_HTML_PLUGIN_DIR . 'src/Services/ContentFetcher.php';
                if (file_exists($fetcher_file) && !class_exists('LHA_Services_ContentFetcher')) {
                    require_once $fetcher_file;
                }
                if (!class_exists('LHA_Services_ContentFetcher')) {
                    if (class_exists('LHA_Logging')) { LHA_Logging::error('LHA_Services_ContentFetcher class not found.'); }
                    return null; 
                }
                return new LHA_Services_ContentFetcher();
            });
            $this->service_locator->register('storage_manager', function() {
                $manager_file = LHA_PROGRESSIVE_HTML_PLUGIN_DIR . 'src/Core/StorageManager.php';
                if (file_exists($manager_file) && !class_exists('LHA_Core_StorageManager')) {
                    require_once $manager_file;
                }
                if (!class_exists('LHA_Core_StorageManager')) {
                    if (class_exists('LHA_Logging')) { LHA_Logging::error('LHA_Core_StorageManager class not found.'); }
                    return null; 
                }
                return new LHA_Core_StorageManager();
            });
            $this->service_locator->register('content_processor_instance', function() {
                $processor_file = LHA_PROGRESSIVE_HTML_PLUGIN_DIR . 'src/Features/ContentProcessor.php';
                if (file_exists($processor_file) && !class_exists('LHA_Features_ContentProcessor')) {
                    require_once $processor_file;
                }
                if (!class_exists('LHA_Features_ContentProcessor')) {
                    if (class_exists('LHA_Logging')) { LHA_Logging::error('LHA_Features_ContentProcessor class not found.'); }
                    return null; 
                }
                return new LHA_Features_ContentProcessor($this->service_locator); 
            });
        }

        /**
         * Adds WordPress hooks for activation, deactivation, plugin initialization,
         * and background processing triggers.
         * @access private
         * @since 0.1.0
         */
        private function add_hooks() {
            register_activation_hook(__FILE__, array('LHA_ProgressiveHTMLLoaderPlugin', 'activate'));
            register_deactivation_hook(__FILE__, array('LHA_ProgressiveHTMLLoaderPlugin', 'deactivate'));
            add_action('plugins_loaded', array($this, 'init_plugin'));
            add_action('save_post', array($this, 'handle_save_post'), 20, 2); 
            add_action('delete_post', array($this, 'handle_delete_post'), 10, 1);
        }

        /**
         * Handles the 'save_post' action.
         * Schedules a post for background processing if conditions are met.
         * @since 0.2.0
         * @param int     $post_id The ID of the post being saved.
         * @param \WP_Post $post    The post object.
         * @return void
         */
        public function handle_save_post(int $post_id, \WP_Post $post): void {
            if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
                if (class_exists('LHA_Logging')) { LHA_Logging::info("Save Post: Ignoring revision/autosave for Post ID: {$post_id}."); }
                return;
            }
            $options = get_option('lha_progressive_html_settings');
            if (empty($options['enable_background_processing']) || empty($options['process_on_save'])) {
                if (class_exists('LHA_Logging')) { LHA_Logging::info("Save Post: Background processing or process_on_save disabled. Post ID: {$post_id}."); }
                return;
            }
            $processing_post_types = $options['processing_post_types'] ?? array();
            if (!in_array($post->post_type, $processing_post_types, true)) {
                if (class_exists('LHA_Logging')) { LHA_Logging::info("Save Post: Post type '{$post->post_type}' not selected for processing. Post ID: {$post_id}."); }
                return;
            }
            if (!in_array($post->post_status, array('publish', 'private'), true)) {
                if (class_exists('LHA_Logging')) { LHA_Logging::info("Save Post: Post status '{$post->post_status}' not publish/private. Post ID: {$post_id}."); }
                return;
            }
            if (!$this->service_locator) {
                if (class_exists('LHA_Logging')) { LHA_Logging::error("Save Post: ServiceLocator unavailable. Post ID: {$post_id}."); }
                return;
            }
            $scheduler = $this->service_locator->get('scheduler');
            if ($scheduler instanceof LHA_Core_Scheduler) {
                $action_id = $scheduler->enqueue_async_action('lha_process_post_content_action', array('post_id' => $post_id), true);
                if ($action_id) {
                    if (class_exists('LHA_Logging')) { LHA_Logging::info("Save Post: Scheduled processing for Post ID: {$post_id}. Action ID: {$action_id}."); }
                } elseif ($scheduler->is_action_scheduled('lha_process_post_content_action', array('post_id' => $post_id))) {
                    if (class_exists('LHA_Logging')) { LHA_Logging::info("Save Post: Processing for Post ID {$post_id} already scheduled."); }
                } else {
                    if (class_exists('LHA_Logging')) { LHA_Logging::error("Save Post: Failed to schedule processing for Post ID {$post_id}."); }
                }
            } else {
                if (class_exists('LHA_Logging')) { LHA_Logging::error("Save Post: Scheduler service invalid. Post ID: {$post_id}. Type: " . (is_object($scheduler) ? get_class($scheduler) : gettype($scheduler))); }
            }
        }

        /**
         * Handles the 'delete_post' action.
         * Unschedules pending processing and schedules cleanup of stored data.
         * @since 0.2.0
         * @param int $post_id The ID of the post being deleted.
         * @return void
         */
        public function handle_delete_post(int $post_id): void {
             if (class_exists('LHA_Logging')) { LHA_Logging::info("Delete Post: Triggered for Post ID: {$post_id}."); }
            if (!$this->service_locator) {
                 if (class_exists('LHA_Logging')) { LHA_Logging::error("Delete Post: ServiceLocator unavailable. Post ID: {$post_id}."); }
                return;
            }
            $scheduler = $this->service_locator->get('scheduler');
            if ($scheduler instanceof LHA_Core_Scheduler) {
                $scheduler->unschedule_action('lha_process_post_content_action', array('post_id' => $post_id));
                if (class_exists('LHA_Logging')) { LHA_Logging::info("Delete Post: Unscheduled pending processing for Post ID: {$post_id}."); }
                $action_id = $scheduler->enqueue_async_action('lha_cleanup_post_data_action', array('post_id' => $post_id), true);
                 if ($action_id) {
                    if (class_exists('LHA_Logging')) { LHA_Logging::info("Delete Post: Scheduled cleanup for Post ID: {$post_id}. Action ID: {$action_id}."); }
                } elseif ($scheduler->is_action_scheduled('lha_cleanup_post_data_action', array('post_id' => $post_id))) {
                    if (class_exists('LHA_Logging')) { LHA_Logging::info("Delete Post: Cleanup for Post ID {$post_id} already scheduled."); }
                } else {
                    if (class_exists('LHA_Logging')) { LHA_Logging::error("Delete Post: Failed to schedule cleanup for Post ID {$post_id}."); }
                }
            } else {
                 if (class_exists('LHA_Logging')) { LHA_Logging::error("Delete Post: Scheduler service invalid. Post ID: {$post_id}. Type: " . (is_object($scheduler) ? get_class($scheduler) : gettype($scheduler))); }
            }
        }

        /**
         * Initializes the plugin: loads text domain, features, and admin components.
         * This method is hooked into 'plugins_loaded'.
         * @since 0.1.0
         * @return void
         */
        public function init_plugin() {
            load_plugin_textdomain(
                'lha-progressive-html',
                false,
                dirname(plugin_basename(__FILE__)) . '/languages/'
            );

            if ($this->registry) {
                $this->registry->load_features();
            }

            if (is_admin()) {
                // Metabox
                $metabox_file = LHA_PROGRESSIVE_HTML_PLUGIN_DIR . 'src/Admin/Metabox.php';
                if (file_exists($metabox_file)) {
                    require_once $metabox_file; 
                    if (class_exists('LHA_Admin_Metabox') && method_exists('LHA_Admin_Metabox', 'boot')) {
                        LHA_Admin_Metabox::boot();
                        if (class_exists('LHA_Logging')) { LHA_Logging::info('LHA_Admin_Metabox booted.'); }
                    }
                }
                // Post List Column
                $post_list_file = LHA_PROGRESSIVE_HTML_PLUGIN_DIR . 'src/Admin/PostList.php';
                if (file_exists($post_list_file)) {
                    require_once $post_list_file;
                    if (class_exists('LHA_Admin_PostList') && method_exists('LHA_Admin_PostList', 'boot')) {
                        LHA_Admin_PostList::boot();
                        if (class_exists('LHA_Logging')) { LHA_Logging::info('LHA_Admin_PostList booted.'); }
                    }
                }
                // Dashboard Widget
                $dashboard_widget_file = LHA_PROGRESSIVE_HTML_PLUGIN_DIR . 'src/Admin/DashboardWidget.php';
                if (file_exists($dashboard_widget_file)) {
                    require_once $dashboard_widget_file;
                    if (class_exists('LHA_Admin_DashboardWidget') && method_exists('LHA_Admin_DashboardWidget', 'boot')) {
                        LHA_Admin_DashboardWidget::boot();
                         if (class_exists('LHA_Logging')) { LHA_Logging::info('LHA_Admin_DashboardWidget booted.'); }
                    }
                }
            }
        }

        /**
         * Plugin activation hook.
         * Checks PHP version and sets up default options.
         * @since 0.1.0
         * @return void
         */
        public static function activate() {
            if (version_compare(PHP_VERSION, '7.1', '<')) {
                if (is_plugin_active(plugin_basename(__FILE__))) {
                    deactivate_plugins(plugin_basename(__FILE__));
                }
                wp_die(esc_html__('LHA Progressive HTML Loading plugin requires PHP version 7.1 or higher. The plugin has been deactivated.', 'lha-progressive-html'));
            }
            $settings_page_class_path = LHA_PROGRESSIVE_HTML_PLUGIN_DIR . 'src/Admin/SettingsPage.php';
            if (file_exists($settings_page_class_path) && !class_exists('LHA_Admin_SettingsPage')) {
                require_once $settings_page_class_path;
            }
            if (class_exists('LHA_Admin_SettingsPage')) {
                $option_name = 'lha_progressive_html_settings'; 
                $default_settings = LHA_Admin_SettingsPage::get_default_settings();
                if (false === get_option($option_name)) {
                    add_option($option_name, $default_settings);
                }
            } else {
                LHA_Logging::error('LHA_Admin_SettingsPage class not found during activation.'); 
            }
        }

        /**
         * Plugin deactivation hook.
         * Unschedules all Action Scheduler tasks.
         * @since 0.1.0
         * @return void
         */
        public static function deactivate() {
            if (function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions('', array(), self::ACTION_GROUP);
            }
        }

        /**
         * Getter for the Registry instance.
         * @return LHA\Core\Registry|null The registry instance.
         * @since 0.1.0
         */
        public function get_registry() {
            return $this->registry;
        }

        /**
         * Getter for the Service Locator instance.
         * @return LHA\Core\ServiceLocator|null The service locator instance.
         * @since 0.1.0
         */
        public function get_service_locator() {
            return $this->service_locator;
        }
    }
}

/**
 * Returns the main instance of LHA Progressive HTML Loader Plugin.
 * @since 0.1.0
 * @return ProgressiveHTMLLoaderPlugin
 */
function lha_progressive_html_loader_plugin() {
    return ProgressiveHTMLLoaderPlugin::instance();
}

// Initialize the plugin
lha_progressive_html_loader_plugin();
?>
