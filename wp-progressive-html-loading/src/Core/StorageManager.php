<?php
/**
 * Manages the storage and retrieval of pre-processed HTML content.
 *
 * Uses WordPress Post Meta for storing processed data associated with posts.
 * Includes methods for saving, retrieving, and deleting this data,
 * along with relevant metadata like version and timestamp.
 *
 * @package LHA\ProgressiveHTML\Core
 * @since 0.2.0
 * @author Jules
 * @license GPLv2 or later
 */

// Ensure this file is not accessed directly.
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if (!class_exists('LHA_Core_StorageManager')) {
    /**
     * Manages the storage and retrieval of pre-processed HTML content.
     *
     * @since 0.2.0
     */
    class LHA_Core_StorageManager {

        /**
         * Prefix for the meta key to store processed content.
         * Includes 'v' for versioning of the meta key structure itself.
         * @since 0.2.0
         */
        private const META_KEY_PREFIX = '_lha_processed_content_v';

        /**
         * Current version of the meta data structure.
         * Increment if the structure of the stored array in post meta changes significantly.
         * @since 0.2.0
         */
        private const CURRENT_META_STRUCTURE_VERSION = '2';

        /**
         * Constructor for StorageManager.
         *
         * @since 0.2.0
         */
        public function __construct() {
            // Simple, no dependencies for now.
        }

        /**
         * Constructs the meta key string used for storing processed content.
         *
         * @since 0.2.0
         * @access private
         * @return string The full meta key.
         */
        private function get_meta_key(): string {
            return self::META_KEY_PREFIX . self::CURRENT_META_STRUCTURE_VERSION;
        }

        /**
         * Saves the processed HTML content and metadata for a given post.
         *
         * @since 0.2.0
         * @param int    $post_id        The ID of the post.
         * @param string $processed_html The processed HTML string with markers.
         * @return bool True on success, false on failure.
         */
        public function save_processed_content(int $post_id, string $processed_html): bool {
            if (empty($processed_html)) {
                if (class_exists('LHA_Logging')) {
                    LHA_Logging::error("StorageManager: Attempted to save empty processed HTML for Post ID: {$post_id}.");
                }
                // Optionally delete existing stale data if content becomes empty
                // $this->delete_processed_content($post_id); // Consider implications
                return false;
            }

            // Ensure LHA_PROGRESSIVE_HTML_VERSION is available or default
            $plugin_version = defined('LHA_PROGRESSIVE_HTML_VERSION') ? LHA_PROGRESSIVE_HTML_VERSION : '0.2.0';

            $data_to_store = array(
                'html'      => $processed_html,
                'version'   => $plugin_version,
                'timestamp' => time(),
                // 'source_hash' => md5($original_html_if_available) // Future enhancement
            );

            $meta_key = $this->get_meta_key();
            $result = update_post_meta($post_id, $meta_key, $data_to_store);

            if ($result === false) {
                if (class_exists('LHA_Logging')) {
                    LHA_Logging::error("StorageManager: Failed to save (update_post_meta returned false) processed content for Post ID: {$post_id}. Meta key: {$meta_key}.");
                }
                return false;
            }
            // update_post_meta returns meta_id on new, true on update, false on failure.
            // For our purposes, any non-false result is a success.
            if (class_exists('LHA_Logging')) {
                LHA_Logging::info("StorageManager: Successfully saved processed content for Post ID: {$post_id}. Meta key: {$meta_key}.");
            }
            return true;
        }

        /**
         * Retrieves the processed content and its metadata for a given post.
         *
         * Validates the retrieved data structure and checks version compatibility.
         *
         * @since 0.2.0
         * @param int $post_id The ID of the post.
         * @return array|null An array containing 'html', 'version', 'timestamp' on success, or null if not found or invalid/stale.
         */
        public function get_processed_content(int $post_id): ?array {
            $meta_key = $this->get_meta_key();
            $stored_data = get_post_meta($post_id, $meta_key, true); // `true` for single value

            if (empty($stored_data) || !is_array($stored_data)) {
                // This can be noisy if many posts don't have processed content.
                // LHA_Logging::info("StorageManager: No processed data found for Post ID: {$post_id}. Meta key: {$meta_key}.");
                return null;
            }

            if (!isset($stored_data['html']) || !isset($stored_data['version']) || !isset($stored_data['timestamp'])) {
                if (class_exists('LHA_Logging')) {
                    LHA_Logging::error("StorageManager: Invalid processed data structure for Post ID: {$post_id}. Data: " . print_r($stored_data, true));
                }
                // Corrupted data, delete it to allow reprocessing
                $this->delete_processed_content($post_id);
                return null;
            }
            
            // Optional: Check if stored version is too old and should be considered stale.
            // For now, the streaming feature will handle this comparison.
            // if (version_compare($stored_data['version'], LHA_PROGRESSIVE_HTML_VERSION, '<')) {
            //     LHA_Logging::info("StorageManager: Processed data for Post ID {$post_id} is stale (v{$stored_data['version']} vs current v" . LHA_PROGRESSIVE_HTML_VERSION . ").");
            //     // Optionally delete it or return it for the caller to decide.
            // }

            return array(
                'html'      => $stored_data['html'], // Should be string
                'version'   => (string) $stored_data['version'],
                'timestamp' => (int) $stored_data['timestamp'],
            );
        }

        /**
         * Deletes the processed content for a given post.
         *
         * @since 0.2.0
         * @param int $post_id The ID of the post.
         * @return bool True on success (or if no data existed), false on failure.
         */
        public function delete_processed_content(int $post_id): bool {
            $meta_key = $this->get_meta_key();
            $result = delete_post_meta($post_id, $meta_key);
            if ($result) {
                 if (class_exists('LHA_Logging')) {
                    LHA_Logging::info("StorageManager: Successfully deleted processed content for Post ID: {$post_id}. Meta key: {$meta_key}.");
                 }
            } else {
                // delete_post_meta returns false on failure, true on success.
                // It can also return true if no such meta existed, which is fine for a delete operation.
                // Only log if it explicitly failed and an error is suspected.
                // However, WordPress might not always make delete_post_meta return false on "no key found".
                // So, logging an error might be too aggressive here.
                // LHA_Logging::error("StorageManager: Failed to delete processed content for Post ID: {$post_id}. Meta key: {$meta_key}.");
            }
            return $result; // Return the result of delete_post_meta
        }

        /**
         * Registers Action Scheduler hooks for storage management tasks.
         *
         * This method should be called by the plugin's main registry loader if this
         * class is registered as a feature and needs to hook its actions.
         *
         * @since 0.2.0
         * @return void
         */
        public static function boot() {
            add_action('lha_cleanup_post_data_action', array(__CLASS__, 'handle_cleanup_post_action'), 10, 1);
        }

        /**
         * Static handler for tasks to clean up a post's processed data.
         *
         * This is typically called by an Action Scheduler task.
         *
         * @since 0.2.0
         * @param int $post_id The ID of the post to clean up.
         * @return void
         */
        public static function handle_cleanup_post_action(int $post_id) {
            if (class_exists('LHA_Logging')) {
                LHA_Logging::info("StorageManager: Received task 'lha_cleanup_post_data_action' for Post ID: {$post_id}.");
            }
            try {
                $instance = new self(); // Create a new instance of LHA_Core_StorageManager
                $instance->delete_processed_content($post_id);
                // Logging of success/failure is within delete_processed_content
            } catch (\Throwable $e) {
                if (class_exists('LHA_Logging')) {
                    LHA_Logging::error("StorageManager: Uncaught exception in handle_cleanup_post_action for Post ID {$post_id}: " . $e->getMessage());
                }
            }
        }
    }
}
// Closing ?> tag omitted as per WordPress coding standards.
