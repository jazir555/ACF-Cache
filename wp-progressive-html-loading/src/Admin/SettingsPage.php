<?php // Omitted as per instruction, but required for PHP files. Assuming this is a conceptual omission.
/**
 * Manages the admin settings page for the LHA Progressive HTML Loading plugin.
 *
 * Production Readiness: This class is designed to be production-ready. It includes
 * conditional loading checks, uses the WordPress Settings API for security and structure,
 * and implements sanitization. Modern UI and mode switching will be progressively enhanced.
 *
 * @package LHA\ProgressiveHTML\Admin
 * @since 0.1.0
 * @author Jules
 * @license GPLv2 or later
 */

// Ensure this file is not accessed directly.
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if (!class_exists('LHA_Admin_SettingsPage')) {
    /**
     * Class LHA_Admin_SettingsPage.
     * Handles the admin settings page.
     */
    class LHA_Admin_SettingsPage {

        /**
         * Stores the plugin's options.
         *
         * @var array
         * @access private
         */
        private $options;

        /**
         * Stores the hook suffix of the settings page.
         *
         * @var string|false
         * @access private
         */
        private $page_hook_suffix;

        /**
         * The name of the option stored in the database.
         *
         * @var string
         * @access private
         * @static
         */
        private static $option_name = 'lha_progressive_html_settings';

        /**
         * The settings group name used by register_setting.
         *
         * @var string
         * @access private
         * @static
         */
        private static $settings_group = 'lha_progressive_html_settings_group';

        /**
         * The slug for the settings page URL.
         *
         * @var string
         * @access private
         * @static
         */
        private static $page_slug = 'lha-progressive-html-settings';

        /**
         * Constructor for SettingsPage.
         * Loads existing options or defaults.
         *
         * @since 0.1.0
         */
        public function __construct() {
            $this->options = get_option(self::$option_name, $this->get_default_settings());
        }

        /**
         * Returns the default plugin settings.
         *
         * @since 0.1.0
         * @return array Default settings.
         * @static
         */
        public static function get_default_settings(): array {
            return array(
                'admin_mode' => 'easy', // 'easy' or 'advanced'
                'streaming_enabled_easy' => false,
                'easy_preset' => 'basic', // e.g., 'basic', 'optimized'
                'streaming_enabled_advanced' => false,
                'flush_comment' => '<!-- LHA_FLUSH_NOW -->',
                'enabled_post_types' => array('post', 'page'), // For real-time streaming
                'excluded_user_roles' => array(), 
                'excluded_urls' => '', 
                
                // New settings for background processing and streaming behavior (v0.2.0)
                'enable_background_processing' => true,
                'processing_post_types' => array('post', 'page'), // Post types for background processing
                'process_on_save' => true,
                'processing_strategy_head' => true, // Insert marker after </head>
                // 'processing_strategy_user_markers' => false, // Future: find user-placed markers
                // 'processing_strategy_auto_elements' => false, // Future: auto-insert based on H2, etc.

                'strict_version_match' => true, // For using pre-processed content
                'fallback_behavior' => 'schedule', // 'none', 'schedule', 'realtime'
                'schedule_reprocessing_for_stale_too' => false,

                'processing_user_marker_enabled' => true,
                'processing_user_marker_target' => 'LHA_FLUSH_TARGET',

                'processing_css_selectors_enabled' => false,
                'processing_css_selectors_rules' => array(), // Array of arrays: [ ['selector' => '', 'position' => ''], ... ]
                'processing_nth_element_enabled' => false,
                'processing_nth_element_rules' => array(), // Array of arrays: [ ['selector' => '', 'count' => 0, 'parent_selector' => ''], ... ]
                'processing_min_chunk_size_enabled' => false,
                'processing_min_chunk_size_bytes' => 2048,

                // New defaults for real-time fallback sub-options (v0.2.0)
                'fallback_realtime_strategy_head' => true,
                'fallback_realtime_user_marker_enabled' => false,

                // Default for enabling metabox (v0.2.0)
                'enable_metabox' => true,
                // Default for enabling post list column (v0.2.0)
                'enable_post_list_column' => true,
                // Default for enabling dashboard widget (v0.2.0)
                'enable_dashboard_widget' => true,
                // Default for batch chunk size (v0.2.1)
                'batch_chunk_size' => 25,
            );
        }

        /**
         * Initializes admin features, checks context, and registers WordPress hooks.
         *
         * @since 0.1.0
         * @return void
         */
        public function boot() {
            if (!is_admin()) {
                return;
            }
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_init', array($this, 'register_settings'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

            // AJAX Handlers for Manual Tools
            add_action('wp_ajax_lha_schedule_single_post', array($this, 'handle_ajax_schedule_single_post'));
            add_action('wp_ajax_lha_schedule_batch_processing', array($this, 'handle_ajax_schedule_batch_processing'));
            add_action('wp_ajax_lha_clear_all_processed_content', array($this, 'handle_ajax_clear_all_processed_content'));
        }

        /**
         * Adds the plugin settings page to the WordPress admin menu.
         *
         * @since 0.1.0
         * @return void
         */
        public function add_admin_menu() {
            $this->page_hook_suffix = add_options_page(
                __('LHA Progressive HTML Settings', 'lha-progressive-html'), // Page Title
                __('Progressive HTML', 'lha-progressive-html'),    // Menu Title
                'manage_options',                               // Capability
                self::$page_slug,                               // Menu Slug
                array($this, 'render_settings_page')            // Callback to render page
            );
        }

        /**
         * Registers plugin settings, sections, and fields using the WordPress Settings API.
         *
         * @since 0.1.0
         * @return void
         */
        public function register_settings() {
            register_setting(self::$settings_group, self::$option_name, array($this, 'sanitize_settings'));

            // Mode Selection Section
            add_settings_section(
                'lha_mode_selection_section',
                __('Display Mode', 'lha-progressive-html'),
                array($this, 'render_section_header'),
                self::$page_slug
            );
            add_settings_field(
                'admin_mode',
                __('Settings Mode', 'lha-progressive-html'),
                array($this, 'render_admin_mode_field'),
                self::$page_slug,
                'lha_mode_selection_section'
            );

            // Easy Mode Section
            add_settings_section(
                'lha_easy_mode_section',
                __('Easy Mode Settings', 'lha-progressive-html'),
                array($this, 'render_section_header'),
                self::$page_slug
            );
            add_settings_field(
                'streaming_enabled_easy',
                __('Enable Streaming (Easy)', 'lha-progressive-html'),
                array($this, 'render_checkbox_field'),
                self::$page_slug,
                'lha_easy_mode_section',
                array(
                    'id' => 'streaming_enabled_easy',
                    'label_for' => 'streaming_enabled_easy',
                    'description' => __('Quickly enable or disable HTML streaming with recommended presets.', 'lha-progressive-html')
                )
            );
            add_settings_field(
                'easy_preset',
                __('Streaming Preset', 'lha-progressive-html'),
                array($this, 'render_select_field'),
                self::$page_slug,
                'lha_easy_mode_section',
                array(
                    'id' => 'easy_preset',
                    'label_for' => 'easy_preset',
                    'options' => array(
                        'basic' => __('Basic (Recommended)', 'lha-progressive-html'),
                        'aggressive' => __('Aggressive (May cause issues)', 'lha-progressive-html')
                    ),
                    'description' => __('Choose a preset for streaming. "Basic" is generally safer.', 'lha-progressive-html')
                )
            );

            // Advanced Mode Section
            add_settings_section(
                'lha_advanced_mode_section',
                __('Advanced Mode Settings', 'lha-progressive-html'),
                array($this, 'render_section_header'),
                self::$page_slug
            );
            add_settings_field(
                'streaming_enabled_advanced',
                __('Enable Streaming (Advanced)', 'lha-progressive-html'),
                array($this, 'render_checkbox_field'),
                self::$page_slug,
                'lha_advanced_mode_section',
                array(
                    'id' => 'streaming_enabled_advanced',
                    'label_for' => 'streaming_enabled_advanced',
                    'description' => __('Fine-tune HTML streaming with detailed controls.', 'lha-progressive-html')
                )
            );
            add_settings_field(
                'flush_comment',
                __('Flush Comment/Marker', 'lha-progressive-html'),
                array($this, 'render_text_field'),
                self::$page_slug,
                'lha_advanced_mode_section',
                array(
                    'id' => 'flush_comment',
                    'label_for' => 'flush_comment',
                    'description' => sprintf(
                        /* translators: %s: Default flush comment */
                        __('The HTML comment that triggers a flush. Default: %s', 'lha-progressive-html'),
                        '<code>&lt;!-- LHA_FLUSH_NOW --&gt;</code>'
                    )
                )
            );
            add_settings_field(
                'enabled_post_types',
                __('Enable on Post Types', 'lha-progressive-html'),
                array($this, 'render_post_types_field'),
                self::$page_slug,
                'lha_advanced_mode_section',
                array(
                    'id' => 'enabled_post_types',
                    'description' => __('Select post types where HTML streaming should be active.', 'lha-progressive-html')
                )
            );
            add_settings_field(
                'excluded_user_roles',
                __('Exclude User Roles', 'lha-progressive-html'),
                array($this, 'render_user_roles_field'),
                self::$page_slug,
                'lha_advanced_mode_section',
                array(
                    'id' => 'excluded_user_roles',
                    'description' => __('Select user roles for whom streaming will be disabled.', 'lha-progressive-html')
                )
            );
            add_settings_field(
                'excluded_urls',
                __('Exclude URLs/Slugs (one per line)', 'lha-progressive-html'),
                array($this, 'render_textarea_field'),
                self::$page_slug,
                'lha_advanced_mode_section',
                array(
                    'id' => 'excluded_urls',
                    'description' => __('(For Real-time Streaming) Enter URL paths or slugs to exclude.', 'lha-progressive-html')
                )
            );

            // New fields for streaming behavior (added to existing advanced section)
            add_settings_field(
                'strict_version_match',
                __('Strict Version Match for Stale Content', 'lha-progressive-html'),
                array($this, 'render_checkbox_field'),
                self::$page_slug,
                'lha_advanced_mode_section', // Assuming this is the main advanced section
                array(
                    'id' => 'strict_version_match',
                    'label_for' => 'strict_version_match',
                    'description' => __('If enabled, do not use pre-processed content if generated by an older plugin version. It will be re-queued if fallback allows.', 'lha-progressive-html')
                )
            );
            add_settings_field(
                'fallback_behavior',
                __('Fallback Behavior (if not pre-processed)', 'lha-progressive-html'),
                array($this, 'render_select_field'),
                self::$page_slug,
                'lha_advanced_mode_section',
                array(
                    'id' => 'fallback_behavior',
                    'label_for' => 'fallback_behavior',
                    'options' => array(
                        'none' => __('Do Nothing', 'lha-progressive-html'),
                        'schedule' => __('Schedule for Background Processing', 'lha-progressive-html'),
                        'realtime' => __('Basic Real-time Streaming (Caution)', 'lha-progressive-html')
                    ),
                    'description' => __('Action if a page is requested but not pre-processed, or if content is stale and strict version match is on.', 'lha-progressive-html')
                )
            );
            add_settings_field(
                'fallback_realtime_strategy_head',
                __('Real-time Fallback: Process After </head>', 'lha-progressive-html'),
                array($this, 'render_checkbox_field'),
                self::$page_slug,
                'lha_advanced_mode_section',
                array(
                    'id' => 'fallback_realtime_strategy_head',
                    'label_for' => 'fallback_realtime_strategy_head',
                    'description' => __('If "Basic Real-time Streaming" fallback is active, enable this to insert a flush marker after the `</head>` tag in real-time.', 'lha-progressive-html'),
                    'wrapper_class' => 'lha_fallback_realtime_option' // For JS show/hide
                )
            );
            add_settings_field(
                'fallback_realtime_user_marker_enabled',
                __('Real-time Fallback: Use Target Marker', 'lha-progressive-html'),
                array($this, 'render_checkbox_field'),
                self::$page_slug,
                'lha_advanced_mode_section',
                array(
                    'id' => 'fallback_realtime_user_marker_enabled',
                    'label_for' => 'fallback_realtime_user_marker_enabled',
                    'description' => __('If real-time fallback is active, look for the "Target Marker Comment" (defined in Content Processing Rules) and replace it with a flush marker in real-time.', 'lha-progressive-html'),
                    'wrapper_class' => 'lha_fallback_realtime_option' // For JS show/hide
                )
            );
            add_settings_field(
                'schedule_reprocessing_for_stale_too',
                __('Schedule Reprocessing for Usable Stale Content', 'lha-progressive-html'),
                array($this, 'render_checkbox_field'),
                self::$page_slug,
                'lha_advanced_mode_section',
                array(
                    'id' => 'schedule_reprocessing_for_stale_too',
                    'label_for' => 'schedule_reprocessing_for_stale_too',
                    'description' => __("If 'Strict Version Match' is OFF and stale content is served, also schedule it for background reprocessing.", 'lha-progressive-html')
                )
            );


            // Background Processing Section (Advanced Mode)
            add_settings_section(
                'lha_bg_processing_section',
                __('Background Processing Settings', 'lha-progressive-html'),
                array($this, 'render_section_header'),
                self::$page_slug
            );
            add_settings_field(
                'enable_background_processing',
                __('Enable Background Processing', 'lha-progressive-html'),
                array($this, 'render_checkbox_field'),
                self::$page_slug,
                'lha_bg_processing_section',
                array(
                    'id' => 'enable_background_processing',
                    'label_for' => 'enable_background_processing',
                    'description' => __('Enable to allow the plugin to pre-process content in the background and store it for faster delivery.', 'lha-progressive-html')
                )
            );
            add_settings_field(
                'processing_post_types',
                __('Process These Post Types', 'lha-progressive-html'),
                array($this, 'render_post_types_field'), // Reusing existing callback
                self::$page_slug,
                'lha_bg_processing_section',
                array(
                    'id' => 'processing_post_types',
                    'description' => __('Select post types to automatically queue for background processing.', 'lha-progressive-html')
                )
            );
            add_settings_field(
                'process_on_save',
                __('Process on Save/Publish', 'lha-progressive-html'),
                array($this, 'render_checkbox_field'),
                self::$page_slug,
                'lha_bg_processing_section',
                array(
                    'id' => 'process_on_save',
                    'label_for' => 'process_on_save',
                    'description' => __('Automatically schedule processing when a selected post type is published or updated.', 'lha-progressive-html')
                )
            );

            // Content Processing Rules Section (Advanced Mode)
            add_settings_section(
                'lha_content_rules_section',
                __('Content Processing Rules (for Background Processing)', 'lha-progressive-html'),
                array($this, 'render_section_header'),
                self::$page_slug // Corrected: this should be $page_slug
            );
            add_settings_field(
                'processing_strategy_head',
                __('Process After </head>', 'lha-progressive-html'),
                array($this, 'render_checkbox_field'),
                self::$page_slug,
                'lha_content_rules_section',
                array(
                    'id' => 'processing_strategy_head',
                    'label_for' => 'processing_strategy_head',
                    'description' => __('Automatically insert a flush marker after the `</head>` tag during background processing.', 'lha-progressive-html')
                )
            );
            add_settings_field(
                'processing_user_marker_enabled',
                __('Enable User-Placed Target Markers', 'lha-progressive-html'),
                array($this, 'render_checkbox_field'),
                self::$page_slug,
                'lha_content_rules_section',
                array(
                    'id' => 'processing_user_marker_enabled',
                    'label_for' => 'processing_user_marker_enabled',
                    'description' => __('If enabled, the Content Processor will look for the "Target Marker" comment in your HTML and replace it with a flush marker.', 'lha-progressive-html')
                )
            );
            add_settings_field(
                'processing_user_marker_target',
                __('Target Marker Comment', 'lha-progressive-html'),
                array($this, 'render_text_field'),
                self::$page_slug,
                'lha_content_rules_section',
                array(
                    'id' => 'processing_user_marker_target',
                    'label_for' => 'processing_user_marker_target',
                    'description' => __('The exact text of the HTML comment to look for (e.g., LHA_FLUSH_TARGET). Case-sensitive if not handled by processor.', 'lha-progressive-html') . ' <br><em>' . __('Example: <code>&lt;!-- LHA_FLUSH_TARGET --&gt;</code> would mean you enter <code>LHA_FLUSH_TARGET</code> here.', 'lha-progressive-html') . '</em>'
                )
            );
             add_settings_field(
                'processing_css_selectors_enabled',
                __('Enable CSS Selector Rules', 'lha-progressive-html'),
                array($this, 'render_checkbox_field'),
                self::$page_slug,
                'lha_content_rules_section',
                array(
                    'id' => 'processing_css_selectors_enabled',
                    'label_for' => 'processing_css_selectors_enabled',
                    'description' => __('Process content based on CSS selectors.', 'lha-progressive-html')
                )
            );
            add_settings_field(
                'processing_css_selectors_rules',
                __('CSS Selector Rules', 'lha-progressive-html'),
                array($this, 'render_repeater_field_css_selectors'),
                self::$page_slug,
                'lha_content_rules_section',
                array(
                    'id' => 'processing_css_selectors_rules',
                    'description' => __('One rule per line. Format: <code>CSS_Selector,Position</code>. Supported positions: "before", "after", "replace". Example: <code>img.important,after</code>', 'lha-progressive-html')
                )
            );
            add_settings_field(
                'processing_nth_element_enabled',
                __('Enable Nth Element Rules', 'lha-progressive-html'),
                array($this, 'render_checkbox_field'),
                self::$page_slug,
                'lha_content_rules_section',
                array(
                    'id' => 'processing_nth_element_enabled',
                    'label_for' => 'processing_nth_element_enabled',
                    'description' => __('Process content based on element counts.', 'lha-progressive-html')
                )
            );
            add_settings_field(
                'processing_nth_element_rules',
                __('Nth Element Rules', 'lha-progressive-html'),
                array($this, 'render_repeater_field_nth_element'),
                self::$page_slug,
                'lha_content_rules_section',
                array(
                    'id' => 'processing_nth_element_rules',
                    'description' => __('One rule per line. Format: <code>Target_Tag,Nth_Count[,Parent_CSS_Selector]</code>. Parent selector is optional. Example: <code>p,3,.entry-content</code> or <code>div,5</code>', 'lha-progressive-html')
                )
            );
            add_settings_field(
                'processing_min_chunk_size_enabled',
                __('Enable Minimum Chunk Size', 'lha-progressive-html'),
                array($this, 'render_checkbox_field'),
                self::$page_slug,
                'lha_content_rules_section',
                array(
                    'id' => 'processing_min_chunk_size_enabled',
                    'label_for' => 'processing_min_chunk_size_enabled',
                    'description' => __('Ensure a minimum amount of content between flush markers.', 'lha-progressive-html')
                )
            );
            add_settings_field(
                'processing_min_chunk_size_bytes',
                __('Minimum Bytes Between Markers', 'lha-progressive-html'),
                array($this, 'render_number_field'),
                self::$page_slug,
                'lha_content_rules_section',
                array(
                    'id' => 'processing_min_chunk_size_bytes',
                    'label_for' => 'processing_min_chunk_size_bytes',
                    'description' => __('Minimum number of bytes. Markers too close to the previous one will be removed (approximate).', 'lha-progressive-html'),
                    'min' => 0,
                    'step' => 128
                )
            );


            // Manual Tools Section (Advanced Mode)
            add_settings_section(
                'lha_manual_tools_section',
                __('Manual Tools', 'lha-progressive-html'),
                array($this, 'render_section_header'),
                self::$page_slug
            );
            add_settings_field(
                'manual_process_post_id_field', // This is the field ID, not the option name
                __('Process Single Post ID', 'lha-progressive-html'),
                array($this, 'render_manual_process_post_id_field'),
                self::$page_slug,
                'lha_manual_tools_section'
                // No 'id' in args as it's not a saved option
            );
            add_settings_field(
                'batch_process_field',
                __('Batch Processing', 'lha-progressive-html'),
                array($this, 'render_batch_process_field'),
                self::$page_slug,
                'lha_manual_tools_section'
            );
            add_settings_field(
                'clear_all_cache_field',
                __('Clear Processed Content', 'lha-progressive-html'),
                array($this, 'render_clear_all_cache_field'),
                self::$page_slug,
                'lha_manual_tools_section'
            );
             add_settings_field(
                'view_task_queue_field', // This is the last field in Manual Tools section
                __('View Task Queue', 'lha-progressive-html'),
                array($this, 'render_view_task_queue_field'),
                self::$page_slug,
                'lha_manual_tools_section'
            );
            // Add Batch Chunk Size to Manual Tools or Background Processing section
            add_settings_field(
                'batch_chunk_size',
                __('Batch Chunk Size', 'lha-progressive-html'),
                array($this, 'render_number_field'),
                self::$page_slug,
                'lha_manual_tools_section', // Or 'lha_bg_processing_section'
                array(
                    'id' => 'batch_chunk_size',
                    'label_for' => 'batch_chunk_size',
                    'description' => __('Number of items to process per background task cycle during batch operations. Default 25.', 'lha-progressive-html'),
                    'min' => 1,
                    'step' => 1
                )
            );

            // Admin UI Enhancements Section
            add_settings_section(
                'lha_admin_ui_section',
                __('Admin UI Enhancements', 'lha-progressive-html'),
                array($this, 'render_section_header'),
                self::$page_slug
            );
            add_settings_field(
                'enable_metabox',
                __('Enable Post Edit Metabox', 'lha-progressive-html'),
                array($this, 'render_checkbox_field'),
                self::$page_slug,
                'lha_admin_ui_section',
                array(
                    'id' => 'enable_metabox',
                    'label_for' => 'enable_metabox',
                    'description' => __('Show a metabox on post edit screens with streaming status and actions.', 'lha-progressive-html')
                )
            );
            add_settings_field(
                'enable_post_list_column',
                __('Enable Post List Status Column', 'lha-progressive-html'),
                array($this, 'render_checkbox_field'),
                self::$page_slug,
                'lha_admin_ui_section',
                array(
                    'id' => 'enable_post_list_column',
                    'label_for' => 'enable_post_list_column',
                    'description' => __('Show a column in post lists displaying the streaming pre-processing status.', 'lha-progressive-html')
                )
            );
            add_settings_field(
                'enable_dashboard_widget',
                __('Enable Dashboard Widget', 'lha-progressive-html'),
                array($this, 'render_checkbox_field'),
                self::$page_slug,
                'lha_admin_ui_section',
                array(
                    'id' => 'enable_dashboard_widget',
                    'label_for' => 'enable_dashboard_widget',
                    'description' => __('Show a widget on the main WordPress dashboard with stats and quick links.', 'lha-progressive-html')
                )
            );
        }

        /**
         * Renders the header for a settings section.
         *
         * @since 0.1.0
         * @param array $args Arguments passed by add_settings_section.
         * @return void
         */
        public function render_section_header(array $args) {
            // Example: Output a description for a specific section
            if ('lha_easy_mode_section' === $args['id']) {
                echo '<p class="lha-section-description">' . esc_html__('Simplified settings for quick setup. Choose a preset and go!', 'lha-progressive-html') . '</p>';
            } elseif ('lha_advanced_mode_section' === $args['id']) {
                echo '<p class="lha-section-description">' . esc_html__('Detailed controls for experienced users. Configure specific behaviors.', 'lha-progressive-html') . '</p>';
            } elseif ('lha_mode_selection_section' === $args['id']) {
                 echo '<p class="lha-section-description">' . esc_html__('Choose "Easy" for simplified controls or "Advanced" for all options.', 'lha-progressive-html') . '</p>';
            }
        }

        /**
         * Renders the admin mode selection field (radio buttons).
         *
         * @since 0.1.0
         * @return void
         */
        public function render_admin_mode_field() {
            $current_mode = isset($this->options['admin_mode']) ? $this->options['admin_mode'] : 'easy';
            ?>
            <fieldset>
                <legend class="screen-reader-text"><span><?php esc_html_e('Settings Mode', 'lha-progressive-html'); ?></span></legend>
                <label>
                    <input type="radio" name="<?php echo esc_attr(sprintf('%s[admin_mode]', self::$option_name)); ?>" value="easy" <?php checked($current_mode, 'easy'); ?>>
                    <?php esc_html_e('Easy Mode', 'lha-progressive-html'); ?>
                </label>
                <p class="description"><?php esc_html_e('Simplified settings with presets.', 'lha-progressive-html'); ?></p>
                <br>
                <label>
                    <input type="radio" name="<?php echo esc_attr(sprintf('%s[admin_mode]', self::$option_name)); ?>" value="advanced" <?php checked($current_mode, 'advanced'); ?>>
                    <?php esc_html_e('Advanced Mode', 'lha-progressive-html'); ?>
                </label>
                <p class="description"><?php esc_html_e('Full control over all plugin settings.', 'lha-progressive-html'); ?></p>
            </fieldset>
            <?php
        }

        /**
         * Renders a checkbox field.
         *
         * @since 0.1.0
         * @param array $args Field arguments (id, description).
         * @return void
         */
        public function render_checkbox_field(array $args) {
            $option_id = $args['id'];
            $value = isset($this->options[$option_id]) ? (bool) $this->options[$option_id] : false;
            ?>
            <input type="checkbox" id="<?php echo esc_attr($option_id); ?>"
                   name="<?php echo esc_attr(sprintf('%s[%s]', self::$option_name, $option_id)); ?>"
                   value="1" <?php checked($value, true); ?>>
            <?php if (!empty($args['description'])) : ?>
                <p class="description"><?php echo esc_html($args['description']); ?></p>
            <?php endif; ?>
            <?php
        }

        /**
         * Renders a textarea for CSS selector rules.
         * Each line: selector,position
         *
         * @since 0.2.0
         * @param array $args Field arguments (id, description).
         * @return void
         */
        public function render_repeater_field_css_selectors(array $args) {
            $option_id = $args['id'];
            $rules_array = $this->options[$option_id] ?? array();
            $value = '';
            if (is_array($rules_array)) {
                foreach ($rules_array as $rule) {
                    if (isset($rule['selector']) && isset($rule['position'])) {
                        $value .= esc_textarea(trim($rule['selector']) . ',' . trim($rule['position'])) . "\n";
                    }
                }
            }
            ?>
            <textarea id="<?php echo esc_attr($option_id); ?>"
                      name="<?php echo esc_attr(sprintf('%s[%s]', self::$option_name, $option_id)); ?>"
                      rows="5" class="large-text"><?php echo trim($value); ?></textarea>
            <?php if (!empty($args['description'])) : ?>
                <p class="description"><?php echo wp_kses_post($args['description']); ?></p>
            <?php endif; ?>
            <?php
        }

        /**
         * Renders a textarea for Nth element rules.
         * Each line: tag_selector,count,parent_css_selector
         *
         * @since 0.2.0
         * @param array $args Field arguments (id, description).
         * @return void
         */
        public function render_repeater_field_nth_element(array $args) {
            $option_id = $args['id'];
            $rules_array = $this->options[$option_id] ?? array();
            $value = '';
             if (is_array($rules_array)) {
                foreach ($rules_array as $rule) {
                    if (isset($rule['selector']) && isset($rule['count'])) {
                        $line = esc_textarea(trim($rule['selector']) . ',' . absint($rule['count']));
                        if (!empty($rule['parent_selector'])) {
                            $line .= ',' . esc_textarea(trim($rule['parent_selector']));
                        }
                        $value .= $line . "\n";
                    }
                }
            }
            ?>
            <textarea id="<?php echo esc_attr($option_id); ?>"
                      name="<?php echo esc_attr(sprintf('%s[%s]', self::$option_name, $option_id)); ?>"
                      rows="5" class="large-text"><?php echo trim($value); ?></textarea>
            <?php if (!empty($args['description'])) : ?>
                <p class="description"><?php echo wp_kses_post($args['description']); ?></p>
            <?php endif; ?>
            <?php
        }

        /**
         * Renders a number input field.
         *
         * @since 0.2.0
         * @param array $args Field arguments (id, description, min, step).
         * @return void
         */
        public function render_number_field(array $args) {
            $option_id = $args['id'];
            $value = isset($this->options[$option_id]) ? $this->options[$option_id] : '';
            $min = isset($args['min']) ? (int)$args['min'] : '';
            $step = isset($args['step']) ? (int)$args['step'] : '';
            ?>
            <input type="number" id="<?php echo esc_attr($option_id); ?>"
                   name="<?php echo esc_attr(sprintf('%s[%s]', self::$option_name, $option_id)); ?>"
                   value="<?php echo esc_attr($value); ?>" class="small-text"
                   <?php if ($min !== '') echo 'min="' . esc_attr($min) . '"'; ?>
                   <?php if ($step !== '') echo 'step="' . esc_attr($step) . '"'; ?>>
            <?php if (!empty($args['description'])) : ?>
                <p class="description"><?php echo esc_html($args['description']); ?></p>
            <?php endif; ?>
            <?php
        }


        /**
         * Renders a static HTML content block.
         * Used for informational text within settings sections.
         *
         * @since 0.2.0
         * @param array $args Field arguments, expects 'html' key.
         * @return void
         */
        public function render_static_html_field(array $args) {
            // Ensure the HTML is safe. If it's hardcoded, it's fine.
            // If it comes from a dynamic source, it should be escaped or sanitized.
            // Here, it's expected to be safe HTML from the add_settings_field call.
            echo $args['html'] ?? '';
        }

        /**
         * Renders the manual process post ID field and button.
         *
         * @since 0.2.0
         * @param array $args Field arguments (unused for this field).
         * @return void
         */
        public function render_manual_process_post_id_field(array $args) {
            ?>
            <input type="number" id="lha_manual_post_id_input" name="lha_manual_post_id_input" class="small-text" placeholder="<?php esc_attr_e('Post ID', 'lha-progressive-html'); ?>" min="1">
            <?php
            submit_button(
                __('Schedule Now', 'lha-progressive-html'),
                'secondary', // type
                'lha_schedule_single_button', // name/id
                false, // wrap
                array('id' => 'lha_schedule_single_button') // other attributes
            );
            ?>
            <p class="description"><?php esc_html_e('Enter a Post ID to schedule it for background processing.', 'lha-progressive-html'); ?></p>
            <?php
        }

        /**
         * Renders the batch processing button.
         *
         * @since 0.2.0
         * @param array $args Field arguments (unused for this field).
         * @return void
         */
        public function render_batch_process_field(array $args) {
            submit_button(
                __('Schedule Batch Processing for All Selected Post Types', 'lha-progressive-html'),
                'secondary',
                'lha_schedule_batch_button',
                false,
                array('id' => 'lha_schedule_batch_button')
            );
            ?>
             <p class="description">
                <?php esc_html_e('Queues all posts of the types selected in "Process These Post Types" (Background Processing section) for processing. This can take a long time for many posts.', 'lha-progressive-html'); ?>
             </p>
            <?php
        }

        /**
         * Renders the clear all processed content button.
         *
         * @since 0.2.0
         * @param array $args Field arguments (unused for this field).
         * @return void
         */
        public function render_clear_all_cache_field(array $args) {
            submit_button(
                __('Clear All Pre-processed Content', 'lha-progressive-html'),
                'secondary', // Or 'delete' class for red button, but needs custom styling
                'lha_clear_cache_button',
                false,
                array('id' => 'lha_clear_cache_button')
            );
            ?>
            <p class="description"><?php esc_html_e('Removes all stored pre-processed HTML content from the database.', 'lha-progressive-html'); ?></p>
            <?php
        }

        /**
         * Renders a link to the Action Scheduler admin page.
         *
         * @since 0.2.0
         * @param array $args Field arguments (unused for this field).
         * @return void
         */
        public function render_view_task_queue_field(array $args) {
            $action_scheduler_url = admin_url('tools.php?page=action-scheduler');
            // Potentially add group or hook filters if AS supports them directly in URL
            // For now, just a link to the main AS page.
            // Example for group: LHA_Core_Scheduler::get_action_group()
            // $action_scheduler_url = add_query_arg(array('s' => LHA_Core_Scheduler::get_action_group(), 'status' => 'pending'), $action_scheduler_url);

            if (class_exists('ActionScheduler_AdminView') || class_exists('ActionScheduler\WordPressAdmin\ActionScheduler_AdminView')) { // Check if AS is likely active
                 $group_slug = LHA_Core_Scheduler::get_action_group(); // Assuming LHA_Core_Scheduler is loaded
                 $pending_tasks_url = add_query_arg(array(
                    'page' => 'action-scheduler',
                    'status' => 'pending',
                    'group' => $group_slug // This might not be a direct filter in AS UI, but good for reference
                 ), admin_url('tools.php'));
                 
                 $all_tasks_for_group_url = add_query_arg(array(
                    'page' => 'action-scheduler',
                    's'    => $group_slug, // 's' is the search parameter which often includes hook or group
                 ), admin_url('tools.php'));


                echo '<p><a href="' . esc_url($all_tasks_for_group_url) . '" class="button button-secondary" target="_blank">' .
                     esc_html__('View Background Task Queue', 'lha-progressive-html') .
                     ' <span class="dashicons dashicons-external"></span></a></p>';
                echo '<p class="description">' .
                     sprintf(
                        /* translators: %s: Action Scheduler group name */
                        esc_html__('Shows tasks related to this plugin (group: %s) in Action Scheduler.', 'lha-progressive-html'),
                        '<code>' . esc_html($group_slug) . '</code>'
                     ) .
                     '</p>';
            } else {
                echo '<p>' . esc_html__('Action Scheduler plugin page not found. Is Action Scheduler active?', 'lha-progressive-html') . '</p>';
            }
        }


        /**
         * Renders a text input field.
         *
         * @since 0.1.0
         * @param array $args Field arguments (id, description).
         * @return void
         */
        public function render_text_field(array $args) {
            $option_id = $args['id'];
            $value = isset($this->options[$option_id]) ? $this->options[$option_id] : '';
            ?>
            <input type="text" id="<?php echo esc_attr($option_id); ?>"
                   name="<?php echo esc_attr(sprintf('%s[%s]', self::$option_name, $option_id)); ?>"
                   value="<?php echo esc_attr($value); ?>" class="regular-text">
            <?php if (!empty($args['description'])) : ?>
                <p class="description"><?php echo wp_kses_post($args['description']); ?></p>
            <?php endif; ?>
            <?php
        }

        /**
         * Renders a select field.
         *
         * @since 0.1.0
         * @param array $args Field arguments (id, options, description).
         * @return void
         */
        public function render_select_field(array $args) {
            $option_id = $args['id'];
            $options = $args['options'] ?? array();
            $value = isset($this->options[$option_id]) ? $this->options[$option_id] : '';
            ?>
            <select id="<?php echo esc_attr($option_id); ?>"
                    name="<?php echo esc_attr(sprintf('%s[%s]', self::$option_name, $option_id)); ?>">
                <?php foreach ($options as $val => $label) : ?>
                    <option value="<?php echo esc_attr($val); ?>" <?php selected($value, $val); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (!empty($args['description'])) : ?>
                <p class="description"><?php echo esc_html($args['description']); ?></p>
            <?php endif; ?>
            <?php
        }

        /**
         * Renders checkbox fields for post types.
         *
         * @since 0.1.0
         * @param array $args Field arguments (id, description).
         * @return void
         */
        public function render_post_types_field(array $args) {
            $option_id = $args['id'];
            $saved_post_types = isset($this->options[$option_id]) && is_array($this->options[$option_id]) ? $this->options[$option_id] : array();
            $post_types = get_post_types(array('public' => true), 'objects');
            ?>
            <fieldset>
                <legend class="screen-reader-text"><span><?php esc_html_e('Enable on Post Types', 'lha-progressive-html'); ?></span></legend>
                <?php foreach ($post_types as $post_type) : ?>
                    <?php if ($post_type->name === 'attachment') continue; // Skip attachments by default ?>
                    <label>
                        <input type="checkbox"
                               name="<?php echo esc_attr(sprintf('%s[%s][]', self::$option_name, $option_id)); ?>"
                               value="<?php echo esc_attr($post_type->name); ?>"
                               <?php checked(in_array($post_type->name, $saved_post_types, true)); ?>>
                        <?php echo esc_html($post_type->label); ?>
                    </label><br>
                <?php endforeach; ?>
            </fieldset>
            <?php if (!empty($args['description'])) : ?>
                <p class="description"><?php echo esc_html($args['description']); ?></p>
            <?php endif; ?>
            <?php
        }

        /**
         * Renders checkbox fields for user roles.
         *
         * @since 0.1.0
         * @param array $args Field arguments (id, description).
         * @return void
         */
        public function render_user_roles_field(array $args) {
            $option_id = $args['id'];
            $saved_roles = isset($this->options[$option_id]) && is_array($this->options[$option_id]) ? $this->options[$option_id] : array();
            $roles = get_editable_roles();
            ?>
            <fieldset>
                <legend class="screen-reader-text"><span><?php esc_html_e('Exclude User Roles', 'lha-progressive-html'); ?></span></legend>
                <?php foreach ($roles as $role_key => $role_data) : ?>
                    <label>
                        <input type="checkbox"
                               name="<?php echo esc_attr(sprintf('%s[%s][]', self::$option_name, $option_id)); ?>"
                               value="<?php echo esc_attr($role_key); ?>"
                               <?php checked(in_array($role_key, $saved_roles, true)); ?>>
                        <?php echo esc_html($role_data['name']); ?>
                    </label><br>
                <?php endforeach; ?>
            </fieldset>
            <?php if (!empty($args['description'])) : ?>
                <p class="description"><?php echo esc_html($args['description']); ?></p>
            <?php endif; ?>
            <?php
        }

        /**
         * Renders a textarea field.
         *
         * @since 0.1.0
         * @param array $args Field arguments (id, description).
         * @return void
         */
        public function render_textarea_field(array $args) {
            $option_id = $args['id'];
            $value = isset($this->options[$option_id]) ? $this->options[$option_id] : '';
            ?>
            <textarea id="<?php echo esc_attr($option_id); ?>"
                      name="<?php echo esc_attr(sprintf('%s[%s]', self::$option_name, $option_id)); ?>"
                      rows="5" class="large-text"><?php echo esc_textarea($value); ?></textarea>
            <?php if (!empty($args['description'])) : ?>
                <p class="description"><?php echo esc_html($args['description']); ?></p>
            <?php endif; ?>
            <?php
        }

        /**
         * Renders the main settings page form.
         *
         * @since 0.1.0
         * @return void
         */
        public function render_settings_page() {
            ?>
            <div class="wrap lha-progressive-html-settings">
                <h1><?php echo esc_html_e('LHA Progressive HTML Settings', 'lha-progressive-html'); ?></h1>
                <div id="lha_ajax_message_area" style="margin-top: 10px;"></div>
                <form method="post" action="options.php">
                    <?php settings_fields(self::$settings_group); ?>
                    <?php $this->render_mode_specific_sections(); ?>
                    <?php submit_button(__('Save Settings', 'lha-progressive-html')); ?>
                </form>
            </div>
            <?php
        }

        /**
         * Renders settings sections based on the current mode.
         * Initially, it renders all sections. JS will handle visibility toggling.
         *
         * @since 0.1.0
         * @access private
         * @return void
         */
        private function render_mode_specific_sections() {
            // Initially render all sections. JS will be used to show/hide based on mode.
            // This ensures all settings are available if JS is disabled, though the experience
            // would be less ideal. The sanitize_settings method is designed to handle this.
            do_settings_sections(self::$page_slug);
        }

        /**
         * Sanitizes settings input.
         *
         * @since 0.1.0
         * @param array $input The raw input array from the form.
         * @return array Sanitized output array.
         */
        public function sanitize_settings(array $input): array {
            $output = $this->options; // Start with existing options to preserve settings not on current form (due to mode).
                                      // Or use $this->get_default_settings() if you prefer to reset unsubmitted fields.

            if (isset($input['admin_mode']) && in_array($input['admin_mode'], array('easy', 'advanced'), true)) {
                $output['admin_mode'] = $input['admin_mode'];
            } else {
                // Fallback to existing or default if admin_mode is somehow not submitted or invalid
                $output['admin_mode'] = isset($this->options['admin_mode']) ? $this->options['admin_mode'] : 'easy';
            }
            
            // Sanitize common fields, ensuring they are processed if present in $input
            // Checkbox fields: if not present in $input, it means they were unchecked.
            $output['streaming_enabled_easy'] = !empty($input['streaming_enabled_easy']);
            $output['streaming_enabled_advanced'] = !empty($input['streaming_enabled_advanced']);

            if (isset($input['flush_comment'])) {
                $output['flush_comment'] = sanitize_text_field($input['flush_comment']);
            } else {
                // If not present, retain existing or set default. This case might not happen for text fields
                // unless they are dynamically removed from the form.
                $output['flush_comment'] = $this->options['flush_comment'] ?? self::get_default_settings()['flush_comment'];
            }
            
            // Sanitize 'easy_preset'
            if (isset($input['easy_preset'])) {
                // The add_settings_field for easy_preset defines its options.
                $easy_preset_options = array('basic', 'aggressive');
                if (in_array($input['easy_preset'], $easy_preset_options, true)) {
                    $output['easy_preset'] = $input['easy_preset'];
                } else {
                    $output['easy_preset'] = self::get_default_settings()['easy_preset']; // Fallback to default
                }
            } else {
                $output['easy_preset'] = $this->options['easy_preset'] ?? self::get_default_settings()['easy_preset'];
            }

            // Sanitize 'enabled_post_types' (for real-time streaming)
            if (isset($input['enabled_post_types']) && is_array($input['enabled_post_types'])) {
                $output['enabled_post_types'] = array_map('sanitize_key', $input['enabled_post_types']);
            } elseif ($output['admin_mode'] === 'advanced' && !isset($input['enabled_post_types'])) {
                $output['enabled_post_types'] = array(); // All unchecked in advanced mode
            } else {
                $output['enabled_post_types'] = $this->options['enabled_post_types'] ?? self::get_default_settings()['enabled_post_types'];
            }

            // Sanitize 'excluded_user_roles'
            if (isset($input['excluded_user_roles']) && is_array($input['excluded_user_roles'])) {
                $output['excluded_user_roles'] = array_map('sanitize_key', $input['excluded_user_roles']);
            } elseif ($output['admin_mode'] === 'advanced' && !isset($input['excluded_user_roles'])) {
                $output['excluded_user_roles'] = array(); // All unchecked
            } else {
                $output['excluded_user_roles'] = $this->options['excluded_user_roles'] ?? self::get_default_settings()['excluded_user_roles'];
            }

            // Sanitize 'excluded_urls'
            if (isset($input['excluded_urls'])) {
                $sanitized_urls = sanitize_textarea_field($input['excluded_urls']);
                $url_lines = array_map('trim', explode("\n", $sanitized_urls));
                $url_lines = array_filter($url_lines); 
                $output['excluded_urls'] = implode("\n", $url_lines);
            } else {
                 $output['excluded_urls'] = $this->options['excluded_urls'] ?? self::get_default_settings()['excluded_urls'];
            }

            // New settings for background processing and streaming behavior
            $output['enable_background_processing'] = !empty($input['enable_background_processing']);
            $output['process_on_save'] = !empty($input['process_on_save']);
            $output['processing_strategy_head'] = !empty($input['processing_strategy_head']);
            $output['strict_version_match'] = !empty($input['strict_version_match']);
            $output['schedule_reprocessing_for_stale_too'] = !empty($input['schedule_reprocessing_for_stale_too']);
            $output['processing_user_marker_enabled'] = !empty($input['processing_user_marker_enabled']);
            $output['processing_css_selectors_enabled'] = !empty($input['processing_css_selectors_enabled']);
            $output['processing_nth_element_enabled'] = !empty($input['processing_nth_element_enabled']);
            $output['processing_min_chunk_size_enabled'] = !empty($input['processing_min_chunk_size_enabled']);
            $output['fallback_realtime_strategy_head'] = !empty($input['fallback_realtime_strategy_head']);
            $output['fallback_realtime_user_marker_enabled'] = !empty($input['fallback_realtime_user_marker_enabled']);
            $output['enable_metabox'] = !empty($input['enable_metabox']);
            $output['enable_post_list_column'] = !empty($input['enable_post_list_column']);
            $output['enable_dashboard_widget'] = !empty($input['enable_dashboard_widget']);

            if (isset($input['batch_chunk_size'])) {
                $chunk_size = absint($input['batch_chunk_size']);
                $output['batch_chunk_size'] = ($chunk_size > 0) ? $chunk_size : 25; // Default to 25 if invalid
            } else {
                $output['batch_chunk_size'] = $this->options['batch_chunk_size'] ?? 25;
            }


            if (isset($input['processing_user_marker_target'])) {
                $output['processing_user_marker_target'] = sanitize_text_field($input['processing_user_marker_target']);
            } else {
                $output['processing_user_marker_target'] = $this->options['processing_user_marker_target'] ?? self::get_default_settings()['processing_user_marker_target'];
            }

            // Sanitize CSS Selector Rules (textarea)
            if (isset($input['processing_css_selectors_rules'])) {
                $output['processing_css_selectors_rules'] = $this->sanitize_textarea_repeater_rules(
                    $input['processing_css_selectors_rules'],
                    array('selector' => 'sanitize_text_field', 'position' => 'sanitize_key'),
                    array('selector', 'position'), // required keys
                    array('position' => array('before', 'after', 'replace')) // allowed values for specific keys
                );
            } else {
                $output['processing_css_selectors_rules'] = self::get_default_settings()['processing_css_selectors_rules'];
            }

            // Sanitize Nth Element Rules (textarea)
            if (isset($input['processing_nth_element_rules'])) {
                 $output['processing_nth_element_rules'] = $this->sanitize_textarea_repeater_rules(
                    $input['processing_nth_element_rules'],
                    array('selector' => 'sanitize_key', 'count' => 'absint', 'parent_selector' => 'sanitize_text_field'),
                    array('selector', 'count') // required keys
                );
            } else {
                $output['processing_nth_element_rules'] = self::get_default_settings()['processing_nth_element_rules'];
            }
            
            if (isset($input['processing_min_chunk_size_bytes'])) {
                $output['processing_min_chunk_size_bytes'] = absint($input['processing_min_chunk_size_bytes']);
            } else {
                $output['processing_min_chunk_size_bytes'] = self::get_default_settings()['processing_min_chunk_size_bytes'];
            }


            // Sanitize 'processing_post_types' (for background processing)
            if (isset($input['processing_post_types']) && is_array($input['processing_post_types'])) {
                $output['processing_post_types'] = array_map('sanitize_key', $input['processing_post_types']);
            } elseif ($output['admin_mode'] === 'advanced' && !isset($input['processing_post_types'])) {
                 $output['processing_post_types'] = array(); // All unchecked
            } else {
                $output['processing_post_types'] = $this->options['processing_post_types'] ?? self::get_default_settings()['processing_post_types'];
            }
            
            // Sanitize 'fallback_behavior'
            if (isset($input['fallback_behavior'])) {
                $allowed_fallbacks = array('none', 'schedule', 'realtime');
                if (in_array($input['fallback_behavior'], $allowed_fallbacks, true)) {
                    $output['fallback_behavior'] = $input['fallback_behavior'];
                } else {
                    $output['fallback_behavior'] = self::get_default_settings()['fallback_behavior']; // Fallback
                }
            } else {
                 $output['fallback_behavior'] = $this->options['fallback_behavior'] ?? self::get_default_settings()['fallback_behavior'];
            }
            
            // Manual processing fields are not saved options, so no sanitization here.
            // Their values are handled by AJAX handlers or form submissions directly.

            // Merge the sanitized input with the complete set of default options
            // to ensure all keys are always present in the stored option,
            // especially if new settings are added and an old option exists.
            // However, for fields that can be empty (like arrays for post_types/roles),
            // ensure that an empty array from input (meaning "none selected") isn't
            // overwritten by defaults if the section was active.
            // The logic above handles this by checking if the admin_mode implies the section was active.
            $current_defaults = self::get_default_settings();
            $output = array_merge($current_defaults, $output);


            // Update the primary 'streaming_enabled' key based on the current mode.
            // This is what HTMLStreaming.php will check.
            if ($output['admin_mode'] === 'easy') {
                $output['streaming_enabled'] = $output['streaming_enabled_easy'];
            } else { // 'advanced'
                $output['streaming_enabled'] = $output['streaming_enabled_advanced'];
            }

            return $output;
        }

        /**
         * Enqueues admin scripts and styles for the settings page.
         *
         * @since 0.1.0
         * @param string $hook_suffix The current admin page's hook suffix.
         * @return void
         */
        public function enqueue_admin_scripts(string $hook_suffix) {
            if ($hook_suffix !== $this->page_hook_suffix) {
                return;
            }

            $js_path = LHA_PROGRESSIVE_HTML_PLUGIN_URL . 'assets/js/admin.js';
            $css_path = LHA_PROGRESSIVE_HTML_PLUGIN_URL . 'assets/css/admin.css';
            $js_asset_file = LHA_PROGRESSIVE_HTML_PLUGIN_DIR . 'assets/js/admin.asset.php'; // For WP script build tools

            $dependencies = array('jquery');
            $version = LHA_PROGRESSIVE_HTML_VERSION;

            if (file_exists($js_asset_file)) {
                $asset = require($js_asset_file);
                $version = $asset['version'] ?? $version;
                $dependencies = array_merge($dependencies, $asset['dependencies'] ?? array());
            }
            
            $localized_data = array(
                'option_name'       => self::$option_name, // Used for input name selectors in JS
                'easy_fields'       => array('streaming_enabled_easy', 'easy_preset'),
                'advanced_fields'   => array(
                    'streaming_enabled_advanced', 
                    'flush_comment', 
                    'enabled_post_types',
                    'excluded_user_roles',
                    'excluded_urls',
                    // New advanced fields from v0.2.0
                    'strict_version_match',
                    'fallback_behavior',
                    'schedule_reprocessing_for_stale_too',
                    'enable_background_processing',
                    'processing_post_types',
                    'process_on_save',
                    'processing_strategy_head',
                    'processing_user_marker_enabled', 
                    'processing_user_marker_target',  
                    'processing_css_selectors_enabled', // New
                    'processing_css_selectors_rules',   // New
                    'processing_nth_element_enabled',   
                    'processing_nth_element_rules',     
                    'processing_min_chunk_size_enabled',
                    'processing_min_chunk_size_bytes',  
                    'fallback_realtime_strategy_head',      
                    'fallback_realtime_user_marker_enabled',
                    'enable_metabox', 
                    'enable_post_list_column',
                    'enable_dashboard_widget',
                    'batch_chunk_size', // New setting
                    // css_selector_info, nth_element_info, min_chunk_size_info were removed.
                    'manual_process_post_id_field',
                    'batch_process_field',
                    'clear_all_cache_field',
                    'view_task_queue_field'
                ),
                // IDs of the sections themselves, if we need to target them directly (e.g. for titles)
                // WordPress typically wraps sections in a div with class .inside or directly in tables.
                // Field rows are more consistently targetable.
                // For JS, we target the field rows (TRs) whose inputs are listed above.
                // Hiding the entire section (H2 + form-table) can be done by wrapping sections in divs.
                // For now, the JS hides TRs, which is usually sufficient.
                // Field rows are more consistently targetable.
                // For JS, we target the field rows (TRs) whose inputs are listed above.
                // Hiding the entire section (H2 + form-table) can be done by wrapping sections in divs.
                // For now, the JS hides TRs, which is usually sufficient.
                // Field rows are more consistently targetable.
                // For JS, we target the field rows (TRs) whose inputs are listed above.
                // Hiding the entire section (H2 + form-table) can be done by wrapping sections in divs.
                // For now, the JS hides TRs, which is usually sufficient.
                // Field rows are more consistently targetable.
                // For JS, we target the field rows (TRs) whose inputs are listed above.
                // Hiding the entire section (H2 + form-table) can be done by wrapping sections in divs.
                // For now, the JS hides TRs, which is usually sufficient.
                // The field rows are more consistently targetable.
                'easy_section_ids'    => array('lha_easy_mode_section'), // Keep as is
                'advanced_section_ids'=> array( // Add new section IDs for JS to hide/show if we wrap them
                    'lha_advanced_mode_section', 
                    'lha_bg_processing_section', 
                    'lha_content_rules_section', 
                    'lha_manual_tools_section',
                    'lha_admin_ui_section' // New section ID
                ),
            );
            
            // Main admin page AJAX nonce (lha_admin_ajax_nonce)
            $localized_data['ajax_url'] = admin_url('admin-ajax.php');
            $localized_data['nonce'] = wp_create_nonce('lha_admin_ajax_nonce'); // Used for settings page AJAX
            
            // Nonce for Metabox AJAX (actions defined in LHA_Admin_Metabox)
            // This is for JS that might run on post edit screens, not the settings page itself.
            // However, if admin.js is also enqueued on post edit screens, this could be useful.
            // For now, the primary nonce for metabox JS will be generated by wp_nonce_field in the metabox.
            // If a separate JS file for metabox needs this, it would be localized there.
            // Let's assume admin.js needs it for now if it were to handle metabox actions directly (though it's not planned for this step).
            // $localized_data['metabox_nonce_action'] = 'lha_metabox_actions'; // The action name for check_ajax_referer
            // $localized_data['metabox_nonce_field_value'] = wp_create_nonce('lha_metabox_actions'); // This would be the actual nonce value

            // For JS confirmation dialogs & messages (used on settings page)
            $localized_data['i18n'] = array(
                'confirm_clear_cache' => __('Are you sure you want to clear all pre-processed content?', 'lha-progressive-html'),
                'processing_message' => __('Processing...', 'lha-progressive-html'),
                'error_occurred' => __('An error occurred.', 'lha-progressive-html'),
                // We can add metabox-specific i18n strings here if admin.js handles metabox JS too
                'metabox_reprocess_confirm' => __('Are you sure you want to reprocess this post now?', 'lha-progressive-html'),
                'metabox_delete_confirm' => __('Are you sure you want to delete the processed data for this post?', 'lha-progressive-html'),
            );

            wp_localize_script('lha-admin-js', 'lha_admin_params', $localized_data);

            wp_enqueue_script('lha-admin-js', $js_path, $dependencies, $version, true);
            wp_enqueue_style('lha-admin-css', $css_path, array(), $version);
        }

        /**
         * Sanitizes textarea input that represents a list of rules.
         * Each line in the textarea is expected to be a comma-separated list of values.
         *
         * @since 0.2.0
         * @access private
         * @param string $textarea_input The raw textarea input.
         * @param array  $key_callbacks  Associative array of 'key_name' => 'sanitization_callback'.
         * @param array  $required_keys  Array of key names that must be present in each rule.
         * @param array  $allowed_values Associative array of 'key_name' => array_of_allowed_values.
         * @return array An array of sanitized rule arrays.
         */
        private function sanitize_textarea_repeater_rules(string $textarea_input, array $key_callbacks, array $required_keys = array(), array $allowed_values = array()): array {
            $sanitized_rules = array();
            $lines = explode("\n", sanitize_textarea_field($textarea_input));

            foreach ($lines as $line_number => $line) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }

                $parts = str_getcsv($line); // Handles CSV parsing, including quoted values if needed
                $rule = array();
                $key_names = array_keys($key_callbacks);

                foreach ($required_keys as $req_key_index => $req_key) {
                    if (!isset($parts[$req_key_index]) || trim($parts[$req_key_index]) === '') {
                        if (class_exists('LHA_Logging')) {
                            LHA_Logging::error("Admin Settings: Missing required value for '{$req_key}' in rule line: {$line_number}. Skipping rule.");
                        }
                        continue 2; // Skip this rule and go to the next line
                    }
                }

                foreach ($key_names as $index => $key_name) {
                    if (!isset($parts[$index]) || trim($parts[$index]) === '') {
                        // If not a required key, allow it to be empty (e.g. optional parent_selector)
                        if (in_array($key_name, $required_keys, true)) {
                             if (class_exists('LHA_Logging')) {
                                LHA_Logging::error("Admin Settings: Missing value for '{$key_name}' in rule line: {$line_number} parts: " . print_r($parts, true));
                            }
                            // This should ideally be caught by required_keys check above if the key is truly required.
                            // If it's optional, we assign a default or empty value.
                            $rule[$key_name] = ''; // Or appropriate default
                        } else {
                           $rule[$key_name] = ''; // Optional field, empty is fine
                        }
                        continue;
                    }
                    
                    $value = trim($parts[$index]);
                    $callback = $key_callbacks[$key_name];

                    if (is_callable($callback)) {
                        $sanitized_value = call_user_func($callback, $value);
                    } else {
                        $sanitized_value = sanitize_text_field($value); // Default sanitization
                    }
                    
                    // Validate against allowed values if provided for this key
                    if (isset($allowed_values[$key_name]) && !in_array($sanitized_value, $allowed_values[$key_name], true)) {
                        if (class_exists('LHA_Logging')) {
                             LHA_Logging::error("Admin Settings: Invalid value '{$sanitized_value}' for '{$key_name}' in rule line: {$line_number}. Allowed: " . implode(', ', $allowed_values[$key_name]));
                        }
                        continue 2; // Skip this rule
                    }
                    $rule[$key_name] = $sanitized_value;
                }
                 // Ensure all required keys were actually set (might not be if parts count < required_keys count)
                $missing_keys = array_diff($required_keys, array_keys($rule));
                if(!empty($missing_keys)){
                    if (class_exists('LHA_Logging')) {
                        LHA_Logging::error("Admin Settings: Rule on line {$line_number} is missing required keys: " . implode(', ', $missing_keys) . ". Rule skipped.");
                    }
                    continue;
                }

                $sanitized_rules[] = $rule;
            }
            return $sanitized_rules;
        }


        /*
         * ==========================================================================
         * AJAX Handlers
         * ==========================================================================
         */

        /**
         * Handles AJAX request to schedule a single post for processing.
         *
         * @since 0.2.0
         */
        public function handle_ajax_schedule_single_post() {
            check_ajax_referer('lha_admin_ajax_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('message' => __('Permission denied.', 'lha-progressive-html')), 403);
            }

            $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

            if ($post_id > 0) {
                $scheduler = $this->service_locator->get('scheduler');
                if ($scheduler instanceof LHA_Core_Scheduler) {
                    $action_id = $scheduler->enqueue_async_action('lha_process_post_content_action', array('post_id' => $post_id), true);
                    if ($action_id) {
                        wp_send_json_success(array('message' => sprintf(__('Post ID %d scheduled for processing. Action ID: %s', 'lha-progressive-html'), $post_id, $action_id)));
                    } else {
                         // Check if already scheduled
                        if ($scheduler->is_action_scheduled('lha_process_post_content_action', array('post_id' => $post_id))) {
                             wp_send_json_success(array('message' => sprintf(__('Post ID %d is already scheduled for processing.', 'lha-progressive-html'), $post_id)));
                        } else {
                             wp_send_json_error(array('message' => sprintf(__('Failed to schedule Post ID %d for processing.', 'lha-progressive-html'), $post_id)));
                        }
                    }
                } else {
                    wp_send_json_error(array('message' => __('Scheduler service not available.', 'lha-progressive-html')));
                }
            } else {
                wp_send_json_error(array('message' => __('Invalid Post ID.', 'lha-progressive-html')));
            }
        }

        /**
         * Handles AJAX request to schedule batch processing for selected post types.
         *
         * @since 0.2.0
         */
        public function handle_ajax_schedule_batch_processing() {
            check_ajax_referer('lha_admin_ajax_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('message' => __('Permission denied.', 'lha-progressive-html')), 403);
            }

            $options = get_option(self::$option_name, $this->get_default_settings());
            $processing_post_types = $options['processing_post_types'] ?? array();
            $chunk_size = absint($options['batch_chunk_size'] ?? 25);
            if ($chunk_size <= 0) $chunk_size = 25;


            if (empty($processing_post_types)) {
                 wp_send_json_error(array('message' => __('No post types selected for processing in plugin settings.', 'lha-progressive-html')));
            }

            $args = array(
                'post_type' => $processing_post_types,
                'post_status' => 'publish', // Consider adding other statuses or making configurable
                'posts_per_page' => -1, // Get all matching post IDs
                'fields' => 'ids',      // Only fetch IDs
            );
            $post_ids = get_posts($args);

            if (empty($post_ids)) {
                wp_send_json_success(array('message' => __('No posts found for the selected post types to schedule for batch processing.', 'lha-progressive-html')));
                return;
            }

            $scheduler = $this->service_locator->get('scheduler');
            if ($scheduler instanceof LHA_Core_Scheduler) {
                $action_id = $scheduler->schedule_master_batch_job(
                    'lha_master_content_processing_batch_action', // Master hook name
                    $post_ids,                                   // Array of all post IDs
                    'lha_process_post_content_action',          // Single item processing hook
                    $chunk_size                                  // Chunk size from settings
                );
                if ($action_id) {
                    wp_send_json_success(array('message' => sprintf(__('%d posts scheduled for batch processing via master task (ID: %d). Check task queue for progress.', 'lha-progressive-html'), count($post_ids), $action_id)));
                } else {
                    // Check if a similar master task is already scheduled (if schedule_master_batch_job returns null on unique match)
                    // This requires more complex checking if the master task args need to be identical.
                    // For now, assume if action_id is null, it failed or was a duplicate that shouldn't run.
                     wp_send_json_error(array('message' => __('Failed to schedule master batch processing task. It might already be scheduled or an error occurred.', 'lha-progressive-html')));
                }
            } else {
                wp_send_json_error(array('message' => __('Scheduler service not available.', 'lha-progressive-html')));
            }
        }

        /**
         * Handles AJAX request to clear all pre-processed content.
         *
         * @since 0.2.0
         */
        public function handle_ajax_clear_all_processed_content() {
            check_ajax_referer('lha_admin_ajax_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('message' => __('Permission denied.', 'lha-progressive-html')), 403);
            }

            $storage_manager = $this->service_locator->get('storage_manager');
            if (!$storage_manager instanceof LHA_Core_StorageManager) {
                 wp_send_json_error(array('message' => __('Storage Manager service not available.', 'lha-progressive-html')));
            }

            $options = get_option(self::$option_name, $this->get_default_settings());
            // Consider all post types that *could* have been processed.
            // This includes 'processing_post_types' and 'enabled_post_types' (for realtime if it also stored)
            // For now, just using 'processing_post_types' as those are explicitly background processed.
            $post_types_to_clear = $options['processing_post_types'] ?? array();
            
            // If no specific post types, we might need a more global clear, but that's risky.
            // For now, if no types are specified in settings, we clear nothing to be safe.
            if (empty($post_types_to_clear)) {
                 wp_send_json_success(array('message' => __('No post types are configured for background processing. Nothing to clear based on post types.', 'lha-progressive-html')));
            }

            $args = array(
                'post_type' => $post_types_to_clear,
                'post_status' => 'any', // Clear for all statuses as meta might persist
                'posts_per_page' => -1,
                'fields' => 'ids',
            );
            $post_ids = get_posts($args);
            
            $cleared_count = 0;
            if (!empty($post_ids)) {
                foreach ($post_ids as $post_id) {
                    if ($storage_manager->delete_processed_content($post_id)) {
                        $cleared_count++;
                    }
                }
            }

            wp_send_json_success(array('message' => sprintf(__('Cleared pre-processed content for %d posts based on configured post types.', 'lha-progressive-html'), $cleared_count)));
        }
    }
}

// Note: The opening <?php tag was omitted as per instruction for the content block.
// In a real file, it would be the very first thing.
// Similarly, the closing ?> tag is omitted as per WordPress PHP coding standards.
