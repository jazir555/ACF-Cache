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
        private $settings = null; // Retained for storing options fetched in boot/maybe_start_streaming

        /**
         * Service Locator instance.
         *
         * @var LHA_Core_ServiceLocator|null
         * @access private
         */
        private $service_locator;

        /**
         * Constructor for HTMLStreaming.
         *
         * @since 0.1.0 Updated 0.2.0 to accept ServiceLocator.
         * @param LHA_Core_ServiceLocator|null $service_locator Optional service locator instance.
         */
        public function __construct(LHA_Core_ServiceLocator $service_locator = null) {
            if ($service_locator) {
                $this->service_locator = $service_locator;
            } else {
                // Fallback to global accessor if not injected (e.g., if instantiated by Registry directly)
                // This assumes lha_progressive_html_loader_plugin() and its get_service_locator() are available.
                if (function_exists('lha_progressive_html_loader_plugin')) {
                    $plugin_instance = lha_progressive_html_loader_plugin();
                    if (method_exists($plugin_instance, 'get_service_locator')) {
                        $this->service_locator = $plugin_instance->get_service_locator();
                    }
                }
            }

            if (!$this->service_locator && class_exists('LHA_Logging')) {
                 LHA_Logging::error('HTMLStreaming: ServiceLocator not available via constructor or global accessor.');
            }
            // Settings are fetched in boot() or maybe_start_streaming() as they might be needed per-request
        }

        /**
         * Initializes the feature, checks settings, and registers WordPress hooks if active.
         *
         * Note: The primary logic for deciding whether to stream or not, and how,
         * is now in `maybe_start_streaming`. This `boot` method mainly ensures
         * that `maybe_start_streaming` is hooked if the plugin *might* be active.
         * The detailed option checks happen within `maybe_start_streaming`.
         *
         * @since 0.1.0
         * @return void
         */
        public function boot() {
            // Basic check: Is Action Scheduler available? If not, some fallback strategies won't work.
            // This is more of a general health check for the plugin's extended features.
            if (!function_exists('as_enqueue_async_action') && class_exists('LHA_Logging')) {
                LHA_Logging::info('HTMLStreaming: Action Scheduler functions not available. Some fallback strategies may be limited.');
            }
            
            // The settings for flush_comment are still needed for the realtime fallback
            $options = get_option('lha_progressive_html_settings');
            if (is_array($options)) { // Ensure options is an array
                 $this->settings = $options; // Store for potential use in realtime fallback
                 $this->flush_comment = !empty($this->settings['flush_comment']) ? $this->settings['flush_comment'] : '<!-- LHA_FLUSH_NOW -->';
            } else {
                 $this->flush_comment = '<!-- LHA_FLUSH_NOW -->'; // Default if options not found
            }


            // Hook `maybe_start_streaming` to `template_redirect`.
            // The decision to stream or not is made within `maybe_start_streaming`.
            add_action('template_redirect', array($this, 'maybe_start_streaming'), 1);
        }


        /**
         * Checks conditions and decides whether to initiate output buffering for streaming.
         * This method now incorporates logic to use pre-processed content or fall back.
         *
         * @since 0.1.0 (Updated 0.2.0)
         * @return void
         */
        public function maybe_start_streaming() {
            // --- Early Exits ---
            if (is_admin() || is_feed() || is_preview() || is_robots() || is_trackback()) {
                return;
            }
            if ((defined('DOING_AJAX') && DOING_AJAX) || (defined('DOING_CRON') && DOING_CRON) || (defined('WP_CLI') && WP_CLI)) {
                return;
            }
            if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) {
                return;
            }

            // --- Get Post ID ---
            if (!is_singular()) {
                // LHA_Logging::info("HTMLStreaming: Not a singular page. Skipping."); // Optional log
                return;
            }
            $post_id = get_queried_object_id();
            if (empty($post_id)) {
                // LHA_Logging::info("HTMLStreaming: Post ID not found for singular page. Skipping."); // Optional log
                return;
            }

            // --- Get Plugin Options ---
            $options = get_option('lha_progressive_html_settings', $this->get_default_streaming_options());
            $this->settings = $options; // Store for potential use by other methods like realtime fallback
            $this->flush_comment = !empty($this->settings['flush_comment']) ? $this->settings['flush_comment'] : '<!-- LHA_FLUSH_NOW -->';


            $streaming_globally_enabled = !empty($options['streaming_enabled']);
            if (!$streaming_globally_enabled) {
                // LHA_Logging::info("HTMLStreaming: Globally disabled via settings. Skipping for Post ID {$post_id}."); // Optional
                return;
            }

            // New settings introduced in 0.2.0
            $strict_version_match = $options['strict_version_match'] ?? true;
            $fallback_behavior = $options['fallback_behavior'] ?? 'none'; // 'none', 'schedule', 'realtime'

            // --- Ensure Service Locator is available ---
            if (!$this->service_locator) {
                if (class_exists('LHA_Logging')) { // Check if LHA_Logging is available
                    LHA_Logging::error('HTMLStreaming: ServiceLocator is not available. Cannot proceed with fetching or scheduling. Post ID: {$post_id}');
                }
                return; // Critical dependency missing
            }

            // --- Fetch Pre-processed Content ---
            $storage_manager = $this->service_locator->get('storage_manager');
            if (!$storage_manager instanceof LHA_Core_StorageManager) {
                if (class_exists('LHA_Logging')) {
                    LHA_Logging::error("HTMLStreaming: Invalid StorageManager service for Post ID {$post_id}.");
                }
                // If storage manager is critical, we might not want to proceed even with realtime fallback
                // or ensure realtime fallback doesn't depend on it indirectly.
                return; 
            }
            $processed_data = $storage_manager->get_processed_content($post_id);

            // --- Check Pre-processed Data & Version ---
            $use_processed_content = false;
            if ($processed_data && !empty($processed_data['html'])) {
                $data_version = $processed_data['version'] ?? '0.0.0';
                $current_plugin_version = defined('LHA_PROGRESSIVE_HTML_VERSION') ? LHA_PROGRESSIVE_HTML_VERSION : '0.1.0';

                if (version_compare($data_version, $current_plugin_version, '<')) {
                    // Stale content
                    if (class_exists('LHA_Logging')) {
                        LHA_Logging::info("HTMLStreaming: Stale content found for Post ID {$post_id} (v{$data_version} vs current v{$current_plugin_version}).");
                    }
                    if ($strict_version_match) {
                        if (class_exists('LHA_Logging')) {
                            LHA_Logging::info("HTMLStreaming: Strict version match enabled. Stale content rejected for Post ID {$post_id}.");
                        }
                        if ($fallback_behavior === 'schedule') {
                            $this->schedule_reprocessing($post_id);
                        }
                        // $use_processed_content remains false
                    } else {
                        // Use stale content but log it
                        if (class_exists('LHA_Logging')) {
                            LHA_Logging::info("HTMLStreaming: Using stale (but permitted by non-strict mode) content for Post ID {$post_id}.");
                        }
                        $use_processed_content = true;
                        // Optionally schedule reprocessing even if using stale data due to non-strict mode
                        // This could be a new setting: 'schedule_reprocessing_for_used_stale_content'
                        // For now, let's assume if strict is false, we use it and don't force reschedule here.
                        // if ($options['schedule_reprocessing_for_stale_too'] ?? false) { $this->schedule_reprocessing($post_id); }
                    }
                } else {
                    // Content version is current or newer (newer shouldn't happen in normal flow)
                    $use_processed_content = true;
                }
            } else { // No processed data found
                if (class_exists('LHA_Logging')) {
                    LHA_Logging::info("HTMLStreaming: No pre-processed content found for Post ID {$post_id}.");
                }
                if ($fallback_behavior === 'schedule') {
                    $this->schedule_reprocessing($post_id);
                }
            }

            // --- Serve Content or Fallback ---
            if ($use_processed_content && isset($processed_data['html'])) {
                if (headers_sent($file, $line)) {
                    if (class_exists('LHA_Logging')) {
                        LHA_Logging::error("HTMLStreaming: Headers already sent from {$file}:{$line} before serving pre-processed content for Post ID {$post_id}. Cannot stream.");
                    }
                    // If headers are sent, we can't `die()` cleanly. Fallback might be impossible.
                    return;
                }

                $this->streaming_active = true; // For final_flush
                // Start output buffering with our callback.
                // This allows `final_flush` to work consistently if `die()` is interrupted or fails.
                if (ob_start(array($this, 'streaming_callback'), 1)) {
                     if (function_exists('register_shutdown_function')) {
                        register_shutdown_function(array($this, 'final_flush'));
                    }
                    echo $processed_data['html']; // Echo the stored HTML
                    // The streaming_callback will handle flushing based on markers within $processed_data['html']
                    // And final_flush will ensure everything is sent.
                    // We need to ensure that the entire pre-processed content is passed to the buffer.
                    // The `die()` call stops WordPress from rendering the page further.
                    // The output buffer must be flushed before die().
                    // The easiest way is to let the implicit flush of ob_end_flush (called by final_flush or script end) handle it.
                    // However, since streaming_callback is designed for chunks, we need to ensure it receives the *entire* content
                    // and processes it fully. With `echo $processed_data['html']; die();`, the callback might not even run fully
                    // if the content is large.
                    // A better approach for pre-processed content:
                    // 1. Echo the content directly.
                    // 2. Manually trigger flushes if it contains markers.
                    // 3. OR, ensure the streaming_callback handles the *entire* block correctly.

                    // Revised approach for pre-processed: echo directly, then flush, then die.
                    // The streaming_callback is more for real-time.
                    // However, to keep final_flush logic consistent, we can use it.
                    // The key is that $processed_data['html'] itself contains the flush markers.
                    // So, the streaming_callback will work as intended.
                    
                    // Ensure the buffer is flushed before die.
                    // This will pass the content through streaming_callback.
                    if (ob_get_length() > 0) {
                        ob_flush(); // Pass current buffer to streaming_callback
                    }
                    flush(); // System flush

                    die(); // Crucial: Stop WordPress from rendering the page further.

                } else {
                    if (class_exists('LHA_Logging')) {
                        LHA_Logging::error("HTMLStreaming: Failed to start output buffering for pre-processed content for Post ID {$post_id}.");
                    }
                    $this->streaming_active = false;
                }

            } else { // Fallback Logic
                if ($fallback_behavior === 'realtime') {
                    if (class_exists('LHA_Logging')) {
                        LHA_Logging::info("HTMLStreaming: Using real-time streaming fallback for Post ID {$post_id}.");
                    }
                    if (headers_sent($file, $line)) { // Re-check headers_sent for realtime
                        if (class_exists('LHA_Logging')) {
                            LHA_Logging::error("HTMLStreaming (realtime): Cannot start, headers already sent from {$file}:{$line} for Post ID {$post_id}.");
                        }
                        return;
                    }
                    $this->streaming_active = true;
                    if (!ob_start(array($this, 'streaming_callback'), 1)) { // Use the existing callback
                        if (class_exists('LHA_Logging')) {
                            LHA_Logging::error("HTMLStreaming (realtime): Failed to start output buffering for Post ID {$post_id}.");
                        }
                        $this->streaming_active = false;
                    } else {
                        if (function_exists('register_shutdown_function')) {
                            register_shutdown_function(array($this, 'final_flush'));
                        }
                    }
                } elseif ($fallback_behavior === 'none') {
                    if (class_exists('LHA_Logging')) {
                        LHA_Logging::info("HTMLStreaming: Fallback 'none' for Post ID {$post_id}. No streaming will occur.");
                    }
                    // Do nothing, let WordPress render normally
                }
                // 'schedule' fallback was handled above when $processed_data was checked.
            }
        }

        /**
         * Provides default options for streaming, mostly for internal use if settings are not found.
         * @since 0.2.0
         * @return array
         */
        private function get_default_streaming_options(): array {
            return array(
                'streaming_enabled' => false, // Default to false if no settings exist
                'strict_version_match' => true,
                'fallback_behavior' => 'none',
                'flush_comment'     => '<!-- LHA_FLUSH_NOW -->',
            );
        }

        /**
         * Schedules a post for background reprocessing.
         *
         * @since 0.2.0
         * @access private
         * @param int $post_id The ID of the post to reprocess.
         * @return void
         */
        private function schedule_reprocessing(int $post_id) {
            if (!$this->service_locator) {
                if (class_exists('LHA_Logging')) {
                    LHA_Logging::error("HTMLStreaming: ServiceLocator not available, cannot schedule reprocessing for Post ID {$post_id}.");
                }
                return;
            }
            $scheduler = $this->service_locator->get('scheduler');
            if ($scheduler instanceof LHA_Core_Scheduler) {
                // Check if not already scheduled to avoid duplicate tasks
                if (!$scheduler->is_action_scheduled('lha_process_post_content_action', array('post_id' => $post_id))) {
                    $scheduler->enqueue_async_action('lha_process_post_content_action', array('post_id' => $post_id), true);
                    if (class_exists('LHA_Logging')) {
                        LHA_Logging::info("HTMLStreaming: Scheduled reprocessing for Post ID {$post_id}.");
                    }
                } else {
                    if (class_exists('LHA_Logging')) {
                        LHA_Logging::info("HTMLStreaming: Reprocessing for Post ID {$post_id} is already scheduled.");
                    }
                }
            } else {
                 if (class_exists('LHA_Logging')) {
                    LHA_Logging::error('HTMLStreaming: Could not retrieve Scheduler service to schedule reprocessing for Post ID {$post_id}. Scheduler type: ' . (is_object($scheduler) ? get_class($scheduler) : gettype($scheduler)));
                 }
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
