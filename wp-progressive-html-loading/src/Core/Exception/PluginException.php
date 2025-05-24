<?php
/**
 * PluginException class for LHA Progressive HTML Loading.
 *
 * This class extends the base Exception class to provide a custom exception type
 * for plugin-specific errors. This allows for more granular error handling
 * and identification of issues originating from this plugin.
 *
 * @package LHA\ProgressiveHTML\Core\Exception
 * @since 0.1.0
 * @author Jules
 * @license GPLv2 or later
 */

// Ensure this file is not accessed directly.
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if (!class_exists('LHA_Core_Exception_PluginException')) { // Adjusted to avoid namespace conflict before autoloader setup
    /**
     * Custom exception for plugin-specific errors.
     */
    class LHA_Core_Exception_PluginException extends \Exception { // Adjusted to avoid namespace conflict
        // You can add custom properties or methods here if needed in the future.
        // For now, it inherits all functionality from the base Exception class.
    }
}
?>
