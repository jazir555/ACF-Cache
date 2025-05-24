<?php
/**
 * Manages scheduling and handling of background tasks via Action Scheduler.
 *
 * This class provides an abstraction layer for interacting with Action Scheduler,
 * ensuring that all plugin-specific actions are grouped and can be managed collectively.
 * It assumes Action Scheduler functions (e.g., as_enqueue_async_action) are available.
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

if (!class_exists('LHA_Core_Scheduler')) {
    /**
     * Manages scheduling and handling of background tasks via Action Scheduler.
     *
     * This class provides an abstraction layer for interacting with Action Scheduler,
     * ensuring that all plugin-specific actions are grouped and can be managed collectively.
     * It assumes Action Scheduler functions (e.g., as_enqueue_async_action) are available.
     *
     * @since 0.2.0
     */
    class LHA_Core_Scheduler {

        /**
         * Service Locator instance.
         * Optional, if needed for dependencies in action handlers.
         *
         * @var LHA_Core_ServiceLocator|null
         * @access private
         */
        private $service_locator;

        /**
         * The group name for all actions scheduled by this plugin.
         * This MUST match the ACTION_GROUP constant in ProgressiveHTMLLoaderPlugin.
         *
         * @var string
         * @access private
         * @static
         */
        private static $action_group = 'lha-progressive-html-processing';

        /**
         * Constructor for Scheduler.
         *
         * @since 0.2.0
         * @param LHA_Core_ServiceLocator|null $service_locator Optional service locator instance.
         */
        public function __construct(LHA_Core_ServiceLocator $service_locator = null) {
            $this->service_locator = $service_locator;
            // Note: self::$action_group is hardcoded here. Ensure it matches ProgressiveHTMLLoaderPlugin::ACTION_GROUP.
            // A more robust solution might involve passing it via constructor or using a shared constant definition.
        }

        /**
         * Registers the handlers for actions scheduled by this plugin.
         *
         * This method should be called by the plugin's main registry loader.
         * It maps action hooks (used when scheduling) to their actual callback functions.
         *
         * @since 0.2.0
         * @return void
         */
        public function boot() {
            // Action hook for processing a single post's content
            // This connects the action scheduled by this name to the static handler in ContentProcessor.
            add_action('lha_process_post_content_action', array('LHA_Features_ContentProcessor', 'handle_process_post_action'), 10, 1);

            // Add more action handlers here as they are defined.
            // e.g., add_action('lha_batch_process_posts_action', array('LHA_Features_ContentProcessor', 'handle_batch_process_action'), 10, 1);
            // e.g., add_action('lha_cleanup_post_data_action', array('LHA_Core_StorageManager', 'handle_cleanup_post_action'), 10, 1);
        }

        /**
         * Enqueues an asynchronous action to run as soon as possible.
         *
         * This is preferred for tasks that should run immediately after the current request.
         * Ensures the action is unique by default to prevent duplicates.
         *
         * @since 0.2.0
         * @param string $hook The hook name for the action.
         * @param array  $args Arguments to pass to the action's callback.
         * @param bool   $unique If true, prevent scheduling if an identical action is already pending.
         * @return int|null The action ID if scheduled, null otherwise.
         */
        public function enqueue_async_action(string $hook, array $args = array(), bool $unique = true): ?int {
            if (!function_exists('as_enqueue_async_action')) {
                LHA_Logging::error('Action Scheduler function as_enqueue_async_action() not found.');
                return null;
            }

            if ($unique && $this->is_action_scheduled($hook, $args)) {
                // LHA_Logging::info("Action {$hook} with specified args is already scheduled."); // Optional
                return null; 
            }

            try {
                $action_id = as_enqueue_async_action($hook, $args, self::$action_group);
                if (empty($action_id)) {
                    LHA_Logging::error("Failed to enqueue async action: {$hook}. Action Scheduler returned empty ID.");
                    return null;
                }
                return $action_id;
            } catch (\Throwable $e) {
                LHA_Logging::error("Error enqueuing async action {$hook}: " . $e->getMessage());
                return null;
            }
        }

        /**
         * Schedules an action to run at a specific time.
         *
         * @since 0.2.0
         * @param string $hook The hook name for the action.
         * @param array  $args Arguments to pass to the action's callback.
         * @param int    $timestamp The Unix timestamp for when the action should run. 0 for current time.
         * @param bool   $unique If true, prevent scheduling if an identical action is already pending.
         * @return int|null The action ID if scheduled, null otherwise.
         */
        public function schedule_single_action(string $hook, array $args = array(), int $timestamp = 0, bool $unique = true): ?int {
            if (!function_exists('as_schedule_single_action')) {
                LHA_Logging::error('Action Scheduler function as_schedule_single_action() not found.');
                return null;
            }
            
            $timestamp = ($timestamp > 0) ? $timestamp : time(); // Ensure timestamp is valid for AS

            if ($unique && $this->is_action_scheduled($hook, $args)) {
                 // LHA_Logging::info("Single action {$hook} with specified args is already scheduled."); // Optional
                return null;
            }

            try {
                $action_id = as_schedule_single_action($timestamp, $hook, $args, self::$action_group);
                if (empty($action_id)) {
                    LHA_Logging::error("Failed to schedule single action: {$hook}. Action Scheduler returned empty ID.");
                    return null;
                }
                return $action_id;
            } catch (\Throwable $e) {
                LHA_Logging::error("Error scheduling single action {$hook}: " . $e->getMessage());
                return null;
            }
        }

        /**
         * Schedules a recurring action.
         *
         * @since 0.2.0
         * @param string $hook The hook name for the action.
         * @param array  $args Arguments to pass to the action's callback.
         * @param int    $interval_in_seconds The interval at which the action should recur.
         * @param int|null $first_run_timestamp The Unix timestamp for the first run. Defaults to now.
         * @return int|null The action ID if scheduled, null otherwise.
         */
        public function schedule_recurring_action(string $hook, array $args = array(), int $interval_in_seconds, ?int $first_run_timestamp = null): ?int {
            if (!function_exists('as_schedule_recurring_action')) {
                LHA_Logging::error('Action Scheduler function as_schedule_recurring_action() not found.');
                return null;
            }

            $first_run_timestamp = $first_run_timestamp ?? time();

            // Note: Action Scheduler's as_schedule_recurring_action does not inherently prevent duplicates
            // if called multiple times. You might need to unschedule existing ones first if that's desired.
            // $this->unschedule_action($hook, $args); // Optional: Unschedules previous identical recurring actions

            try {
                $action_id = as_schedule_recurring_action($first_run_timestamp, $interval_in_seconds, $hook, $args, self::$action_group);
                if (empty($action_id)) {
                    LHA_Logging::error("Failed to schedule recurring action: {$hook}. Action Scheduler returned empty ID.");
                    return null;
                }
                return $action_id;
            } catch (\Throwable $e) {
                LHA_Logging::error("Error scheduling recurring action {$hook}: " . $e->getMessage());
                return null;
            }
        }

        /**
         * Checks if a given action is already scheduled and pending.
         *
         * @since 0.2.0
         * @param string $hook The hook name for the action.
         * @param array  $args Arguments to check against.
         * @return bool True if a matching action is pending, false otherwise.
         */
        public function is_action_scheduled(string $hook, array $args = array()): bool {
            if (!function_exists('as_next_scheduled_action')) {
                LHA_Logging::error('Action Scheduler function as_next_scheduled_action() not found. Cannot check if action is scheduled.');
                return false; // Or handle as a more critical error depending on context
            }
            // Note: For exact match, args should be an empty array if no args are passed, not null.
            // AS searches for actions with the same hook, arguments, and group.
            return false !== as_next_scheduled_action($hook, $args, self::$action_group);
        }

        /**
         * Unschedules a specific action.
         *
         * @since 0.2.0
         * @param string $hook The hook name for the action.
         * @param array  $args Arguments to match for unscheduling.
         * @return void
         */
        public function unschedule_action(string $hook, array $args = array()): void {
            if (!function_exists('as_unschedule_action')) {
                LHA_Logging::error('Action Scheduler function as_unschedule_action() not found.');
                return;
            }

            try {
                as_unschedule_action($hook, $args, self::$action_group);
            } catch (\Throwable $e) {
                LHA_Logging::error("Error unscheduling action {$hook}: " . $e->getMessage());
            }
        }

        /**
         * Returns the Action Scheduler group name used by this plugin.
         *
         * @since 0.2.0
         * @return string The action group name.
         */
        public static function get_action_group(): string {
            return self::$action_group;
        }
    }
}
// Note: Closing ?> tag is omitted as per WordPress coding standards.
