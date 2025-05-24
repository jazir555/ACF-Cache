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
                'enabled_post_types' => array('post', 'page'), // Default enabled post types
                'excluded_user_roles' => array(), // Default no roles excluded
                'excluded_urls' => '', // Default no URLs excluded
                // 'advanced_setting_example' => 'default_value', // Example for future advanced settings
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
                    'description' => __('Enter URL paths or slugs (e.g., /about-us/, /contact) to exclude from streaming.', 'lha-progressive-html')
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
                $allowed_presets = array_keys(self::get_default_settings()['easy_preset'] ?? $this->get_default_settings()['easy_preset'] ?? array('basic', 'aggressive')); // Ensure keys from defaults if dynamic
                 // The add_settings_field for easy_preset defines its options.
                $easy_preset_options = array('basic', 'aggressive');
                if (in_array($input['easy_preset'], $easy_preset_options, true)) {
                    $output['easy_preset'] = $input['easy_preset'];
                } else {
                    $output['easy_preset'] = self::get_default_settings()['easy_preset']; // Fallback to default
                }
            } else {
                // If not set (e.g. easy mode not active), retain existing or set default
                $output['easy_preset'] = $this->options['easy_preset'] ?? self::get_default_settings()['easy_preset'];
            }

            // Sanitize 'enabled_post_types'
            if (isset($input['enabled_post_types']) && is_array($input['enabled_post_types'])) {
                $output['enabled_post_types'] = array_map('sanitize_key', $input['enabled_post_types']);
            } elseif (isset($input['enabled_post_types'])) { // Should be an array if submitted
                $output['enabled_post_types'] = array(); // Not an array, clear it or handle error
            } else { // Not in input, means all unchecked or advanced mode not active
                 // If admin_mode is advanced and this field is not in $input, it means all were unchecked.
                if ($output['admin_mode'] === 'advanced') {
                    $output['enabled_post_types'] = array();
                } else { // Easy mode active, retain existing advanced setting
                    $output['enabled_post_types'] = $this->options['enabled_post_types'] ?? self::get_default_settings()['enabled_post_types'];
                }
            }

            // Sanitize 'excluded_user_roles'
            if (isset($input['excluded_user_roles']) && is_array($input['excluded_user_roles'])) {
                $output['excluded_user_roles'] = array_map('sanitize_key', $input['excluded_user_roles']);
            } elseif (isset($input['excluded_user_roles'])) {
                $output['excluded_user_roles'] = array();
            } else {
                if ($output['admin_mode'] === 'advanced') {
                    $output['excluded_user_roles'] = array();
                } else {
                    $output['excluded_user_roles'] = $this->options['excluded_user_roles'] ?? self::get_default_settings()['excluded_user_roles'];
                }
            }

            // Sanitize 'excluded_urls'
            if (isset($input['excluded_urls'])) {
                $sanitized_urls = sanitize_textarea_field($input['excluded_urls']);
                // Explode by newline, trim each line, remove empty lines
                $url_lines = array_map('trim', explode("\n", $sanitized_urls));
                $url_lines = array_filter($url_lines); // Remove empty lines
                $output['excluded_urls'] = implode("\n", $url_lines); // Store as a string
            } else {
                 // If not in input (e.g. advanced mode not active), retain existing
                 $output['excluded_urls'] = $this->options['excluded_urls'] ?? self::get_default_settings()['excluded_urls'];
            }

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
                    'excluded_urls'
                ),
                // IDs of the sections themselves, if we need to target them directly (e.g. for titles)
                // WordPress typically wraps sections in a div with class .inside or directly in tables.
                // The field rows are more consistently targetable.
                'easy_section_ids'    => array('lha_easy_mode_section'),
                'advanced_section_ids'=> array('lha_advanced_mode_section'),
            );
            wp_localize_script('lha-admin-js', 'lha_admin_settings_params', $localized_data);

            wp_enqueue_script('lha-admin-js', $js_path, $dependencies, $version, true);
            wp_enqueue_style('lha-admin-css', $css_path, array(), $version);
        }
    }
}

// Note: The opening <?php tag was omitted as per instruction for the content block.
// In a real file, it would be the very first thing.
// Similarly, the closing ?> tag is omitted as per WordPress PHP coding standards.
