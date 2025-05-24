<?php
/**
 * Handles the HTML streaming functionality.
 *
 * Production Readiness: This class is designed to be production-ready. It includes
 * conditional hook registration, robust error handling, and configurable streaming logic.
 * It relies on plugin settings for its activation and behavior. Thorough testing in
 * various environments and with different themes/plugins is recommended.
 *
 * @package LHA\ProgressiveHTML\Features
 * @since 0.1.0
 * @author Jules
 * @license GPLv2 or later
 */

// Ensure this file is not accessed directly.
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if (!class_exists('LHA_Features_HTMLStreaming')) {
    /**
     * Class LHA_Features_HTMLStreaming.
     * Handles the HTML streaming functionality.
     */
    class LHA_Features_HTMLStreaming {

        /**
         * Flag to indicate if streaming is currently active for this request.
         *
         * @var bool
         * @access private
         */
        private $streaming_active = false;

        /**
         * The HTML comment used to trigger a flush.
         *
         * @var string
         * @access private
         */
        private $flush_comment = '<!-- LHA_FLUSH_NOW -->';

        /**
         * Stores plugin settings relevant to streaming.
         *
         * @var array|null
         * @access private
         */
        private $settings = null;

        /**
         * Constructor for HTMLStreaming.
         *
         * @since 0.1.0
         * // @param LHA_Core_ServiceLocator $service_locator Optional service locator instance.
         */
        public function __construct(/* LHA_Core_ServiceLocator $service_locator = null */) {
            // Initialize properties, potentially using $service_locator if needed for settings
            // For now, settings are fetched in boot()
        }

        /**
         * Initializes the feature, checks settings, and registers WordPress hooks if active.
         *
         * @since 0.1.0
         * @return void
         */
        public function boot() {
            $options = get_option('lha_progressive_html_settings');

            // Default settings if not yet saved
            if (false === $options) {
                 $options = array(
                    'streaming_enabled' => true, // Default to true for initial setup
                    'flush_comment'     => '<!-- LHA_FLUSH_NOW -->',
                    // 'enabled_post_types' => array('post', 'page'), // Example for future use
                );
            }
            $this->settings = $options;


            if (empty($this->settings['streaming_enabled']) || !$this->settings['streaming_enabled']) {
                return;
            }

            $this->flush_comment = !empty($this->settings['flush_comment']) ? $this->settings['flush_comment'] : '<!-- LHA_FLUSH_NOW -->';

            add_action('template_redirect', array($this, 'maybe_start_streaming'), 1);
        }

        /**
         * Checks conditions and decides whether to initiate output buffering for streaming.
         *
         * @since 0.1.0
         * @return void
         */
        public function maybe_start_streaming() {
            if (is_admin() || is_feed() || is_preview() || is_robots() || is_trackback()) {
                return;
            }
            if ((defined('DOING_AJAX') && DOING_AJAX) || (defined('DOING_CRON') && DOING_CRON) || (defined('WP_CLI') && WP_CLI)) {
                return;
            }

            // Example for post type checking (requires settings to be structured accordingly)
            // $enabled_post_types = isset($this->settings['enabled_post_types']) ? $this->settings['enabled_post_types'] : array('post', 'page');
            // if (!empty($enabled_post_types) && !is_singular($enabled_post_types)) {
            //     return;
            // }
            
            // Check for DONOTCACHEPAGE or other conflicting constants/plugins if necessary
            if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) {
                return;
            }

            if (headers_sent($file, $line)) {
                LHA_Logging::error("HTML Streaming cannot start: headers already sent from {$file}:{$line}.");
                return;
            }

            $this->streaming_active = true;
            
            // Using chunk_size 1 as per prompt. Note: This can be inefficient.
            // A larger chunk size (e.g., 4096) with buffer accumulation in the callback
            // might be more performant.
            if (!ob_start(array($this, 'streaming_callback'), 1)) {
                LHA_Logging::error('Failed to start output buffering for HTML streaming.');
                $this->streaming_active = false;
            } else {
                // Optional: Register shutdown function to ensure buffer is flushed
                // Consider potential conflicts with other plugins that use shutdown functions.
                register_shutdown_function(array($this, 'final_flush'));
            }
        }

        /**
         * Callback for ob_start. Flushes content in chunks based on the configured flush comment/marker.
         * Uses the revised simpler logic.
         *
         * @param string $buffer The buffer content.
         * @param int    $phase  The output buffering phase.
         * @return string The processed buffer content.
         * @since 0.1.0
         */
        public function streaming_callback($buffer, $phase) {
            if (!$this->streaming_active) {
                return $buffer;
            }

            $processed_output_for_echo = ''; // What we will echo
            $remaining_buffer_for_next_iteration = ''; // What we will return to PHP's buffer

            // Ensure flush_comment is set, default if not (though it should be by boot())
            $flush_marker = !empty($this->flush_comment) ? $this->flush_comment : '<!-- LHA_FLUSH_NOW -->';

            if (strpos($buffer, $flush_marker) !== false) {
                $parts = explode($flush_marker, $buffer);
                foreach ($parts as $i => $part) {
                    $processed_output_for_echo .= $part;
                    if ($i < count($parts) - 1) { // If not the last part, means a marker was here
                        $processed_output_for_echo .= $flush_marker;
                        if (strlen(trim($processed_output_for_echo)) > 0) {
                            echo $processed_output_for_echo;
                            if (ob_get_level() > 0) {
                                @ob_flush();
                            }
                            flush();
                        }
                        $processed_output_for_echo = ''; // Reset for next segment
                    }
                }
                // After loop, $processed_output_for_echo contains the last part of the buffer (after the last marker)
                // This part should not be echoed yet, but returned to PHP's buffer
                $remaining_buffer_for_next_iteration = $processed_output_for_echo;
            } else {
                // No marker found in this chunk, so return it to PHP's buffer
                $remaining_buffer_for_next_iteration = $buffer;
            }

            // Handle final flush when PHP is ending the output buffer
            if ($phase & PHP_OUTPUT_HANDLER_FINAL) {
                if (strlen(trim($remaining_buffer_for_next_iteration)) > 0) {
                    echo $remaining_buffer_for_next_iteration;
                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }
                    flush();
                }
                $this->streaming_active = false;
                return ''; // Nothing left, all flushed
            }

            // Return the part of the buffer that has not been flushed yet
            return $remaining_buffer_for_next_iteration;
        }

        /**
         * Ensures any remaining output in the buffer is flushed at the end of the request.
         * This is particularly useful if the script terminates unexpectedly or if the
         * final buffer content from PHP doesn't trigger PHP_OUTPUT_HANDLER_FINAL in the callback.
         *
         * @since 0.1.0
         * @return void
         */
        public function final_flush() {
            if ($this->streaming_active && ob_get_level() > 0) {
                // Try to flush all active output buffers.
                // Loop while there are active output buffers.
                $levels = ob_get_level();
                for ($i = 0; $i < $levels; $i++) {
                     // Check if buffer is truly active and then end/flush
                     // ob_get_status() can give more details if needed
                    if (ob_get_length() !== false) { // Check if there's content or buffer is active
                         @ob_end_flush(); // Send content and turn off buffering
                    } else {
                        // If a buffer is listed by ob_get_level() but has no content or is inactive,
                        // attempting to ob_end_flush() might cause errors or warnings.
                        // Depending on PHP version and configuration, @ob_end_clean() might be safer
                        // if the goal is just to clean up without sending.
                        // Given this is a "final flush", ob_end_flush is more appropriate.
                    }
                }
                // Final system flush, if not already handled by ob_end_flush iterations
                @flush();
            }
            $this->streaming_active = false;
        }
    }
}
?>
