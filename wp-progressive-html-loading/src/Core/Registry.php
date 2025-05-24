<?php
/**
 * Registry class for LHA Progressive HTML Loading.
 *
 * Manages the registration and loading of plugin features.
 * This class ensures that features are loaded correctly and provides a central
 * point for managing different components of the plugin.
 *
 * @package LHA\ProgressiveHTML\Core
 * @since 0.1.0
 * @author Jules
 * @license GPLv2 or later
 */

// Ensure this file is not accessed directly.
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if (!class_exists('LHA_Core_Registry')) { // Adjusted to avoid namespace conflict before autoloader setup
    /**
     * Manages plugin features.
     */
    class LHA_Core_Registry {
        /**
         * Stores registered features and their configurations.
         *
         * @var array
         * @access private
         */
        private $features = array();

        /**
         * Tracks features that have already been loaded.
         *
         * @var array
         * @access private
         */
        private $loaded_features = array();

        /**
         * Registers a feature with the plugin.
         *
         * @param string $feature_name       A unique name for the feature.
         * @param string $class_path         The absolute path to the feature's class file.
         * @param string $class_name         The fully qualified class name of the feature.
         * @param string $boot_function_name The name of the method to call on the feature instance to initialize it.
         *
         * @return void
         * @since 0.1.0
         */
        public function register_feature($feature_name, $class_path, $class_name, $boot_function_name) {
            if (empty($feature_name) || !is_string($feature_name)) {
                LHA_Logging::error("Feature name must be a non-empty string."); // Assumes LHA_Logging exists
                return;
            }
            if (empty($class_path) || !is_string($class_path)) {
                LHA_Logging::error("Class path for feature '{$feature_name}' must be a non-empty string.");
                return;
            }
            if (empty($class_name) || !is_string($class_name)) {
                LHA_Logging::error("Class name for feature '{$feature_name}' must be a non-empty string.");
                return;
            }
            if (empty($boot_function_name) || !is_string($boot_function_name)) {
                LHA_Logging::error("Boot function name for feature '{$feature_name}' must be a non-empty string.");
                return;
            }

            if (isset($this->features[$feature_name])) {
                LHA_Logging::error("Feature '{$feature_name}' is already registered. Overwriting.");
            }

            $this->features[$feature_name] = array(
                'path'  => $class_path,
                'class' => $class_name,
                'boot'  => $boot_function_name,
            );
        }

        /**
         * Loads all registered features.
         *
         * Iterates through the registered features, includes their class files,
         * instantiates them, and calls their boot methods.
         *
         * @return void
         * @since 0.1.0
         */
        public function load_features() {
            foreach ($this->features as $feature_name => $feature_data) {
                if (isset($this->loaded_features[$feature_name])) {
                    continue; // Skip if already loaded
                }

                // Specific check for admin-only features
                // Assuming 'LHA_Admin_SettingsPage' is the class name for admin settings
                if ($feature_data['class'] === 'LHA_Admin_SettingsPage' && !is_admin()) {
                    continue; // Skip loading non-admin page if not in admin area
                }
                
                if (!file_exists($feature_data['path'])) {
                    LHA_Logging::error("Feature file not found: {$feature_data['path']} for feature '{$feature_name}'.");
                    continue;
                }

                try {
                    require_once $feature_data['path'];
                } catch (\Throwable $e) {
                    LHA_Logging::error("Failed to load feature file: {$feature_data['path']}. Error: " . $e->getMessage());
                    continue;
                }

                if (!class_exists($feature_data['class'])) {
                    LHA_Logging::error("Feature class not found: {$feature_data['class']} for feature '{$feature_name}'.");
                    continue;
                }

                $feature_instance = null;
                try {
                    // Ensure class name is directly usable if no namespace is formally declared
                    $class_to_instantiate = $feature_data['class'];
                    if (strpos($class_to_instantiate, '\\') === false && strpos($class_to_instantiate, '_') !== false) {
                        // This logic might need adjustment based on final class naming conventions
                        // For now, assumes class names like 'LHA_Features_HTMLStreaming' are directly usable
                    }
                    $feature_instance = new $class_to_instantiate();
                } catch (\Throwable $e) {
                    LHA_Logging::error("Error instantiating feature class: {$feature_data['class']}. Error: " . $e->getMessage());
                    continue;
                }
                

                if (!method_exists($feature_instance, $feature_data['boot'])) {
                    LHA_Logging::error("Boot method not found: {$feature_data['boot']} in class {$feature_data['class']} for feature '{$feature_name}'.");
                    continue;
                }

                try {
                    $feature_instance->{$feature_data['boot']}();
                } catch (\Throwable $e) {
                    LHA_Logging::error("Error booting feature: {$feature_name}. Error: " . $e->getMessage());
                    continue; 
                }

                $this->loaded_features[$feature_name] = true;
            }
        }
    }
}
?>
