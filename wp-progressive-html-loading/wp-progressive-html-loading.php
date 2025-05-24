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
            // Note: Assuming LHA_Core_Registry and LHA_Core_ServiceLocator based on file inclusion strategy
            // If an autoloader were in place, this would be new LHA\Core\Registry();
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
                'LHA_Features_HTMLStreaming', // Adjusted for current no-namespace strategy
                'boot'
            );
            $this->registry->register_feature(
                'admin_settings',
                LHA_PROGRESSIVE_HTML_PLUGIN_DIR . 'src/Admin/SettingsPage.php',
                'LHA_Admin_SettingsPage', // Adjusted for current no-namespace strategy
                'boot'
            );

            // Placeholder for Scheduler
            $this->registry->register_feature(
                'scheduler',
                LHA_PROGRESSIVE_HTML_PLUGIN_DIR . 'src/Core/Scheduler.php',
                'LHA_Core_Scheduler', // Assuming this naming convention
                'boot'
            );

            // Placeholder for Content Processor Feature (likely run by Scheduler)
            $this->registry->register_feature(
                'content_processor',
                LHA_PROGRESSIVE_HTML_PLUGIN_DIR . 'src/Features/ContentProcessor.php',
                'LHA_Features_ContentProcessor',
                'boot' // Its boot method would register actions with the Scheduler
            );

            // Register ContentFetcher with ServiceLocator
            $this->service_locator->register('content_fetcher', function() {
                $fetcher_file = LHA_PROGRESSIVE_HTML_PLUGIN_DIR . 'src/Services/ContentFetcher.php';
                if (file_exists($fetcher_file) && !class_exists('LHA_Services_ContentFetcher')) {
                    require_once $fetcher_file;
                }
                if (!class_exists('LHA_Services_ContentFetcher')) {
                    if (class_exists('LHA_Logging')) {
                        LHA_Logging::error('LHA_Services_ContentFetcher class not found and could not be loaded.');
                    }
                    // Return a dummy or null, or throw an exception, depending on how critical this service is at this stage.
                    // For now, returning null might cause issues later if the service is expected.
                    // Throwing an exception might be better if it's essential.
                    // Let's assume for now it's essential and should throw if not loadable.
                    // However, the original plan was to return null from service locator on failure.
                    // The ServiceLocator's get() method already throws if factory returns null.
                    return null; 
                }
                // $plugin_options = get_option('lha_progressive_html_settings', array()); // Pass options if constructor needs them
                return new LHA_Services_ContentFetcher(/* $plugin_options */);
            });

            // Register StorageManager with ServiceLocator
            $this->service_locator->register('storage_manager', function() {
                $manager_file = LHA_PROGRESSIVE_HTML_PLUGIN_DIR . 'src/Core/StorageManager.php';
                if (file_exists($manager_file) && !class_exists('LHA_Core_StorageManager')) {
                    require_once $manager_file;
                }
                if (!class_exists('LHA_Core_StorageManager')) {
                    if (class_exists('LHA_Logging')) {
                        LHA_Logging::error('LHA_Core_StorageManager class not found for service locator.');
                    }
                    return null; // ServiceLocator's get() will throw an exception
                }
                return new LHA_Core_StorageManager();
            });

            // Register StorageManager with Registry for its boot method
            $this->registry->register_feature(
                'storage_manager_boot', // Unique key for registry
                LHA_PROGRESSIVE_HTML_PLUGIN_DIR . 'src/Core/StorageManager.php',
                'LHA_Core_StorageManager',
                'boot'
            );

            // Register ContentProcessor instance with ServiceLocator
            $this->service_locator->register('content_processor_instance', function() {
                $processor_file = LHA_PROGRESSIVE_HTML_PLUGIN_DIR . 'src/Features/ContentProcessor.php';
                if (file_exists($processor_file) && !class_exists('LHA_Features_ContentProcessor')) {
                    require_once $processor_file;
                }
                if (!class_exists('LHA_Features_ContentProcessor')) {
                    if (class_exists('LHA_Logging')) { // Check if LHA_Logging is available
                        LHA_Logging::error('LHA_Features_ContentProcessor class not found for service locator.');
                    }
                    return null; 
                }
                // Pass $this->service_locator which is the ServiceLocator instance from ProgressiveHTMLLoaderPlugin
                return new LHA_Features_ContentProcessor($this->service_locator); 
            });
        }

        /**
         * Adds WordPress hooks for activation, deactivation, plugin initialization,
         * and future background processing triggers.
         *
         * @access private
         * @since 0.1.0
         */
        private function add_hooks() {
            register_activation_hook(__FILE__, array('LHA_ProgressiveHTMLLoaderPlugin', 'activate'));
            register_deactivation_hook(__FILE__, array('LHA_ProgressiveHTMLLoaderPlugin', 'deactivate'));
            add_action('plugins_loaded', array($this, 'init_plugin'));

            // Placeholder hooks for background processing (to be implemented later)
            // add_action('save_post', array($this, 'handle_save_post'), 10, 2);
            // add_action('delete_post', array($this, 'handle_delete_post'), 10, 1);
        }

        // Placeholder methods for future hook callbacks:
        // public function handle_save_post($post_id, $post) { /* ... schedule processing via Scheduler service ... */ }
        // public function handle_delete_post($post_id) { /* ... schedule cleanup via Scheduler service ... */ }


        /**
         * Initializes the plugin: loads text domain and features.
         * This method is hooked into 'plugins_loaded'.
         *
         * @since 0.1.0
         * @return void
         */
        public function init_plugin() {
            // Load plugin text domain for internationalization
            load_plugin_textdomain(
                'lha-progressive-html',
                false,
                dirname(plugin_basename(__FILE__)) . '/languages/'
            );

            // Load registered features
            if ($this->registry) {
                $this->registry->load_features();
            }
        }

        /**
         * Plugin activation hook.
         *
         * Checks PHP version and sets up default options.
         * If using Action Scheduler, its tables are typically created automatically when it first runs.
         * No specific action needed here for AS table creation unless bundling it manually.
         * This method is called when the plugin is activated.
         * It is production-ready.
         *
         * @since 0.1.0
         * @return void
         */
        public static function activate() {
            // PHP Version Check
            if (version_compare(PHP_VERSION, '7.1', '<')) {
                if (is_plugin_active(plugin_basename(__FILE__))) {
                    deactivate_plugins(plugin_basename(__FILE__));
                }
                wp_die(esc_html__('LHA Progressive HTML Loading plugin requires PHP version 7.1 or higher. The plugin has been deactivated.', 'lha-progressive-html'));
            }

            // Default Settings
            // Ensure LHA_Admin_SettingsPage class is available if not already loaded
            // This path might need adjustment if the main plugin file's location changes relative to src/
            $settings_page_class_path = LHA_PROGRESSIVE_HTML_PLUGIN_DIR . 'src/Admin/SettingsPage.php';
            if (file_exists($settings_page_class_path) && !class_exists('LHA_Admin_SettingsPage')) {
                require_once $settings_page_class_path;
            }

            if (class_exists('LHA_Admin_SettingsPage')) {
                // Use reflection to access static property $option_name, or make get_option_name() public static
                // For simplicity, let's assume we know the option name or have a helper.
                // $option_name = LHA_Admin_SettingsPage::$option_name; // This won't work if property is private
                // Let's define it directly or use a shared constant/getter if available.
                $option_name = 'lha_progressive_html_settings'; // As defined in SettingsPage
                $default_settings = LHA_Admin_SettingsPage::get_default_settings();
                if (false === get_option($option_name)) {
                    add_option($option_name, $default_settings);
                }
            } else {
                // Log error: SettingsPage class not found, cannot set defaults.
                LHA_Logging::error('LHA_Admin_SettingsPage class not found during activation.'); // If logging is available here
                // Consider a wp_die or admin notice if this is critical, though for now we'll let it fail silently after logging.
            }

            // Flush Rewrite Rules (Optional - include commented out for now)
            // flush_rewrite_rules(); // Uncomment if the plugin adds CPTs, taxonomies, or rewrite rules.
        }

        /**
         * Plugin deactivation hook.
         *
         * Unschedules all Action Scheduler tasks associated with this plugin.
         * Settings are preserved by default.
         * This method is called when the plugin is deactivated.
         * It is production-ready.
         *
         * @since 0.1.0
         * @return void
         */
        public static function deactivate() {
            // Unschedule all Action Scheduler tasks if Action Scheduler is active
            if (function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions('', array(), self::ACTION_GROUP);
            }

            // Settings Preservation: By default, plugin settings are NOT removed on deactivation.
            // To remove settings, you would use: delete_option('lha_progressive_html_settings');

            // Also, clear any legacy WP Cron jobs if they were used before or as a fallback
            // Example: wp_clear_scheduled_hook('lha_my_legacy_cron_hook');

            // flush_rewrite_rules(); // Optional: If rewrite rules were added.
        }

        /**
         * Getter for the Registry instance.
         *
         * @return LHA\Core\Registry|null The registry instance.
         * @since 0.1.0
         */
        public function get_registry() {
            return $this->registry;
        }

        /**
         * Getter for the Service Locator instance.
         *
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
 *
 * @since 0.1.0
 * @return ProgressiveHTMLLoaderPlugin
 */
function lha_progressive_html_loader_plugin() {
    // Adjusted to call the non-namespaced class name
    return ProgressiveHTMLLoaderPlugin::instance();
}

// Initialize the plugin
lha_progressive_html_loader_plugin();
?>
