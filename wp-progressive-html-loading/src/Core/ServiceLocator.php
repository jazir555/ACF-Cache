<?php
/**
 * ServiceLocator class for LHA Progressive HTML Loading.
 *
 * Manages services and their dependencies within the plugin. This class provides
 * a central point for accessing shared services, promoting loose coupling and
 * easier testing.
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

// Ensure PluginException is available.
// This assumes PluginException.php is included by the main plugin file or an autoloader.
// For robustness, especially if this file could be loaded independently in some context:
if (!class_exists('LHA_Core_Exception_PluginException')) {
    $exception_path = LHA_PROGRESSIVE_HTML_PLUGIN_DIR . 'src/Core/Exception/PluginException.php';
    if (file_exists($exception_path)) {
        require_once $exception_path;
    } else {
        // Fallback or error logging if PluginException cannot be loaded
        // This is a safeguard; ideally, the main plugin file handles includes.
        error_log("LHA ServiceLocator: PluginException class not found and could not be loaded.");
    }
}


if (!class_exists('LHA_Core_ServiceLocator')) { // Adjusted to avoid namespace conflict before autoloader setup
    /**
     * Manages services and their dependencies.
     */
    class LHA_Core_ServiceLocator {
        /**
         * Stores service definitions (factories and shared status).
         *
         * @var array
         * @access private
         */
        private $services = array();

        /**
         * Stores shared instances of services.
         *
         * @var array
         * @access private
         */
        private $instances = array();

        /**
         * Registers a service with the locator.
         *
         * @param string   $key     A unique key to identify the service.
         * @param callable $factory A callable that creates the service instance.
         * @param bool     $shared  Whether the service should be a shared instance (singleton).
         *                        Defaults to true.
         *
         * @return void
         * @since 0.1.0
         * @throws LHA_Core_Exception_PluginException If the key is empty or factory is not callable.
         */
        public function register($key, $factory, $shared = true) {
            if (empty($key) || !is_string($key)) {
                // Assuming LHA_Logging is available globally or handle error differently
                LHA_Logging::error("Service key must be a non-empty string.");
                if (class_exists('LHA_Core_Exception_PluginException')) {
                    throw new LHA_Core_Exception_PluginException("Service key must be a non-empty string.");
                } else {
                    // Fallback if PluginException is not available
                    throw new \Exception("Service key must be a non-empty string.");
                }
            }

            if (!is_callable($factory)) {
                 LHA_Logging::error("Factory for service '{$key}' is not callable.");
                if (class_exists('LHA_Core_Exception_PluginException')) {
                    throw new LHA_Core_Exception_PluginException("Factory for service '{$key}' is not callable.");
                } else {
                     throw new \Exception("Factory for service '{$key}' is not callable.");
                }
            }

            if (isset($this->services[$key])) {
                LHA_Logging::error("Service '{$key}' is already registered. Overwriting.");
            }

            $this->services[$key] = array(
                'factory' => $factory,
                'shared'  => (bool) $shared,
            );
        }

        /**
         * Retrieves a service instance.
         *
         * If the service is registered as shared and an instance already exists,
         * it returns the existing instance. Otherwise, it creates a new instance
         * using the registered factory. If the service is shared, the new instance
         * is stored for future retrievals.
         *
         * @param string $key The key of the service to retrieve.
         *
         * @return mixed The service instance.
         * @since 0.1.0
         * @throws LHA_Core_Exception_PluginException If the service is not found, the factory is invalid,
         *                                            or an error occurs during service instantiation.
         */
        public function get($key) {
            if (empty($key) || !is_string($key)) {
                LHA_Logging::error("Service key must be a non-empty string for get().");
                 if (class_exists('LHA_Core_Exception_PluginException')) {
                    throw new LHA_Core_Exception_PluginException("Service key must be a non-empty string for get().");
                } else {
                     throw new \Exception("Service key must be a non-empty string for get().");
                }
            }

            // Check for existing shared instance
            if (isset($this->instances[$key])) {
                return $this->instances[$key];
            }

            // Check if the service is registered
            if (!isset($this->services[$key])) {
                LHA_Logging::error("Service not found: " . $key);
                if (class_exists('LHA_Core_Exception_PluginException')) {
                    throw new LHA_Core_Exception_PluginException("Service not found: " . $key);
                } else {
                     throw new \Exception("Service not found: " . $key);
                }
            }

            $service_config = $this->services[$key];
            $instance = null;

            try {
                $instance = call_user_func($service_config['factory']);
            } catch (\Throwable $e) {
                LHA_Logging::error("Error creating service '{$key}': " . $e->getMessage());
                 if (class_exists('LHA_Core_Exception_PluginException')) {
                    throw new LHA_Core_Exception_PluginException("Error creating service '{$key}': " . $e->getMessage(), 0, $e);
                } else {
                     throw new \Exception("Error creating service '{$key}': " . $e->getMessage(), 0, $e);
                }
            }

            if ($instance === null) {
                // Factory might have returned null explicitly, or an error occurred that didn't throw Throwable
                LHA_Logging::error("Factory for service '{$key}' returned null or failed without throwing an exception.");
                 if (class_exists('LHA_Core_Exception_PluginException')) {
                    throw new LHA_Core_Exception_PluginException("Factory for service '{$key}' failed to produce an instance.");
                } else {
                     throw new \Exception("Factory for service '{$key}' failed to produce an instance.");
                }
            }

            // If it's a shared service, store the instance
            if ($service_config['shared']) {
                $this->instances[$key] = $instance;
            }

            return $instance;
        }

        /**
         * Checks if a service is registered or a shared instance exists.
         *
         * @param string $key The key of the service to check.
         *
         * @return bool True if the service is registered or a shared instance exists,
         *              false otherwise.
         * @since 0.1.0
         */
        public function has($key) {
            if (empty($key) || !is_string($key)) {
                LHA_Logging::error("Service key must be a non-empty string for has().");
                return false;
            }
            return isset($this->instances[$key]) || isset($this->services[$key]);
        }
    }
}
?>
