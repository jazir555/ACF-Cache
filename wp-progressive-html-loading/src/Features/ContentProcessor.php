<?php
/**
 * Handles background processing of post content to prepare it for HTML streaming.
 *
 * This class is responsible for fetching raw HTML content, analyzing it,
 * and inserting flush markers based on configured strategies. It is typically
 * invoked by tasks managed by LHA_Core_Scheduler.
 *
 * @package LHA\ProgressiveHTML\Features
 * @since 0.2.0
 * @author Jules
 * @license GPLv2 or later
 */

// Ensure this file is not accessed directly.
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if (!class_exists('LHA_Features_ContentProcessor')) {
    /**
     * Handles background processing of post content to prepare it for HTML streaming.
     *
     * This class is responsible for fetching raw HTML content, analyzing it,
     * and inserting flush markers based on configured strategies. It is typically
     * invoked by tasks managed by LHA_Core_Scheduler.
     *
     * @since 0.2.0
     */
    class LHA_Features_ContentProcessor {

        /**
         * Service Locator instance.
         *
         * @var LHA_Core_ServiceLocator
         * @access private
         */
        private $service_locator;

        /**
         * Constructor for ContentProcessor.
         *
         * @since 0.2.0
         * @param LHA_Core_ServiceLocator $service_locator The service locator instance.
         */
        public function __construct(LHA_Core_ServiceLocator $service_locator) {
            $this->service_locator = $service_locator;
        }

        /**
         * Registers handlers for Action Scheduler tasks.
         *
         * This method should be called by the plugin's main registry loader if this
         * class is registered as a feature.
         *
         * @since 0.2.0
         * @return void
         */
        public static function boot() {
            add_action('lha_process_post_content_action', array(__CLASS__, 'handle_process_post_action'), 10, 1);
            // Add more action handlers here if other task types are defined later.
        }

        /**
         * Static handler for the Action Scheduler task 'lha_process_post_content_action'.
         *
         * Retrieves an instance of ContentProcessor and calls the processing method.
         * Catches and logs any exceptions to prevent task failures from halting the queue.
         *
         * @since 0.2.0
         * @param int $post_id The ID of the post to process.
         * @return void
         */
        public static function handle_process_post_action(int $post_id) {
            if (class_exists('LHA_Logging')) { // Check if LHA_Logging is available
                LHA_Logging::info("Content Processor: Starting task 'lha_process_post_content_action' for Post ID: {$post_id}.");
            }
            
            try {
                // Ensure plugin instance and service locator are available
                if (!function_exists('lha_progressive_html_loader_plugin')) {
                     if (class_exists('LHA_Logging')) {
                        LHA_Logging::error("Content Processor: Main plugin function 'lha_progressive_html_loader_plugin' not found.");
                     }
                    return;
                }
                $plugin_instance = lha_progressive_html_loader_plugin();
                if (!method_exists($plugin_instance, 'get_service_locator')) {
                    if (class_exists('LHA_Logging')) {
                        LHA_Logging::error("Content Processor: Method 'get_service_locator' not found on plugin instance.");
                    }
                    return;
                }
                $service_locator = $plugin_instance->get_service_locator();
                if (!$service_locator || !method_exists($service_locator, 'get')) {
                    if (class_exists('LHA_Logging')) {
                        LHA_Logging::error("Content Processor: Service locator not available or 'get' method missing.");
                    }
                    return;
                }

                $instance = $service_locator->get('content_processor_instance');
                
                if ($instance instanceof LHA_Features_ContentProcessor) {
                    $result = $instance->process_post($post_id);
                    if ($result) {
                        if (class_exists('LHA_Logging')) {
                            LHA_Logging::info("Content Processor: Successfully processed Post ID: {$post_id}.");
                        }
                    } else {
                        if (class_exists('LHA_Logging')) {
                            LHA_Logging::error("Content Processor: Failed to process Post ID: {$post_id}. See previous logs for details.");
                        }
                    }
                } else {
                    if (class_exists('LHA_Logging')) {
                        LHA_Logging::error("Content Processor: Could not retrieve valid ContentProcessor instance for Post ID: {$post_id}. Instance type: " . (is_object($instance) ? get_class($instance) : gettype($instance)));
                    }
                }
            } catch (\Throwable $e) {
                if (class_exists('LHA_Logging')) {
                    LHA_Logging::error("Content Processor: Uncaught exception in handle_process_post_action for Post ID {$post_id}: " . $e->getMessage() . " Stack: " . $e->getTraceAsString());
                }
            }
        }

        /**
         * Fetches, processes, and saves the content for a single post.
         *
         * @since 0.2.0
         * @param int $post_id The ID of the post to process.
         * @return bool True on success, false on failure.
         */
        public function process_post(int $post_id): bool {
            $post_status = get_post_status($post_id);
            if (!$post_status || !in_array($post_status, array('publish', 'private'))) { // Only process published or private posts
                if (class_exists('LHA_Logging')) {
                    LHA_Logging::info("Content Processor: Post ID {$post_id} is not published or private (status: {$post_status}). Skipping.");
                }
                return false; 
            }

            $url = get_permalink($post_id);
            if (!$url) {
                if (class_exists('LHA_Logging')) {
                    LHA_Logging::error("Content Processor: Could not get permalink for Post ID: {$post_id}.");
                }
                return false;
            }

            if (class_exists('LHA_Logging')) {
                LHA_Logging::info("Content Processor: Fetching content for Post ID: {$post_id} from URL: {$url}.");
            }
            
            $content_fetcher = $this->service_locator->get('content_fetcher');
            // Explicitly check type to ensure methods are available
            if (!($content_fetcher instanceof LHA_Services_ContentFetcher)) { 
                if (class_exists('LHA_Logging')) {
                    LHA_Logging::error("Content Processor: Could not retrieve valid ContentFetcher service for Post ID: {$post_id}. Type: " . (is_object($content_fetcher) ? get_class($content_fetcher) : gettype($content_fetcher)));
                }
                return false;
            }
            
            $html_content = $content_fetcher->fetch_content($url);

            if (is_wp_error($html_content)) {
                if (class_exists('LHA_Logging')) {
                    LHA_Logging::error("Content Processor: Failed to fetch content for Post ID {$post_id}. Error: " . $html_content->get_error_message());
                }
                return false;
            }
            if (empty($html_content)) {
                if (class_exists('LHA_Logging')) {
                    LHA_Logging::error("Content Processor: Fetched content is empty for Post ID {$post_id}.");
                }
                return false;
            }

            // --- Basic Marker Insertion Strategy (after </head>) ---
            $modified_html_content = $html_content; // Default to original if no modifications
            $options = get_option('lha_progressive_html_settings', array()); // Get current settings
            // Default to true if not set, for initial testing. In production, this might default to false.
            $strategy_enabled = $options['processing_strategy_head'] ?? true; 

            if ($strategy_enabled) { 
                $insertion_point = '</head>';
                // Use flush_comment from settings, or default if not set.
                $marker = !empty($options['flush_comment']) ? $options['flush_comment'] : '<!-- LHA_FLUSH_NOW -->';
                
                $pos = stripos($modified_html_content, $insertion_point);
                if ($pos !== false) {
                    $modified_html_content = substr_replace($modified_html_content, $insertion_point . $marker, $pos, strlen($insertion_point));
                    if (class_exists('LHA_Logging')) {
                        LHA_Logging::info("Content Processor: Inserted flush marker after </head> for Post ID: {$post_id}.");
                    }
                } else {
                    if (class_exists('LHA_Logging')) {
                        LHA_Logging::info("Content Processor: '</head>' tag not found for Post ID: {$post_id}. Marker not inserted via head strategy.");
                    }
                }
            }
            // --- End Basic Strategy ---
            
            // TODO: Implement more advanced strategies based on plugin settings (e.g., user-placed markers, element-based)

            $storage_manager = $this->service_locator->get('storage_manager');
            // Explicitly check type
            if (!($storage_manager instanceof LHA_Core_StorageManager)) { 
                if (class_exists('LHA_Logging')) {
                    LHA_Logging::error("Content Processor: Could not retrieve valid StorageManager service for Post ID: {$post_id}. Type: " . (is_object($storage_manager) ? get_class($storage_manager) : gettype($storage_manager)));
                }
                return false;
            }

            // Save the processed HTML content.
            $save_result = $storage_manager->save_processed_content($post_id, $modified_html_content);
            if (!$save_result) {
                if (class_exists('LHA_Logging')) {
                    LHA_Logging::error("Content Processor: Failed to save processed content for Post ID: {$post_id}.");
                }
                return false;
            }
            
            if (class_exists('LHA_Logging')) {
                LHA_Logging::info("Content Processor: Successfully processed and saved content for Post ID: {$post_id}.");
            }
            return true;
        }
    }
}
// Closing ?> tag omitted as per WordPress coding standards.
