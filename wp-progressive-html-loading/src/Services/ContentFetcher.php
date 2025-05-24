<?php
/**
 * Fetches HTML content for a given internal URL using WordPress HTTP API.
 *
 * This service is responsible for retrieving the raw HTML of a post or page
 * as it would be rendered to a generic, logged-out user. It includes security
 * measures to prevent Server-Side Request Forgery (SSRF).
 *
 * @package LHA\ProgressiveHTML\Services
 * @since 0.2.0
 * @author Jules
 * @license GPLv2 or later
 */

// Ensure this file is not accessed directly.
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if (!class_exists('LHA_Services_ContentFetcher')) {
    /**
     * Fetches HTML content for a given internal URL using WordPress HTTP API.
     *
     * This service is responsible for retrieving the raw HTML of a post or page
     * as it would be rendered to a generic, logged-out user. It includes security
     * measures to prevent Server-Side Request Forgery (SSRF).
     *
     * @since 0.2.0
     */
    class LHA_Services_ContentFetcher {

        /**
         * Default timeout for HTTP requests in seconds.
         *
         * @var int
         * @access private
         */
        private $timeout = 15;

        // private $options; // To store plugin settings if timeout is configurable

        /**
         * Constructor for ContentFetcher.
         *
         * Currently, sets a hardcoded timeout. Future versions might allow
         * this to be configured via plugin options.
         *
         * @since 0.2.0
         * // @param array $plugin_options Optional plugin settings.
         */
        public function __construct(/* array $plugin_options = array() */) {
            // $this->options = $plugin_options;
            // $this->timeout = $this->options['fetcher_timeout'] ?? 15; // Example if timeout were configurable
        }

        /**
         * Fetches HTML content from a given URL.
         *
         * Performs security checks to ensure the URL is internal to the WordPress site.
         * Uses wp_remote_get to fetch the content.
         *
         * @since 0.2.0
         * @param string $url The URL to fetch.
         * @return string|\WP_Error The HTML content as a string on success, or a WP_Error object on failure.
         */
        public function fetch_content(string $url) {
            global $wp_version; // Used for user-agent string

            if (empty($url)) {
                return new \WP_Error('lha_fetcher_empty_url', __('URL cannot be empty.', 'lha-progressive-html'));
            }

            if (!$this->is_internal_url($url)) {
                if (class_exists('LHA_Logging')) {
                    LHA_Logging::error("Content Fetcher: Attempt to fetch external or invalid URL: {$url}");
                }
                return new \WP_Error('lha_fetcher_external_url', __('Invalid URL: Only internal site URLs can be fetched.', 'lha-progressive-html'));
            }

            // Ensure LHA_PROGRESSIVE_HTML_VERSION is available or default
            $plugin_version = defined('LHA_PROGRESSIVE_HTML_VERSION') ? LHA_PROGRESSIVE_HTML_VERSION : '0.2.0';

            $args = array(
                'timeout'     => $this->timeout,
                'redirection' => 5,
                'user-agent'  => sprintf('LHA Progressive HTML Fetcher/%s; WordPress/%s; (+%s)', 
                                         $plugin_version,
                                         $wp_version,
                                         get_home_url()),
                'cookies'     => array(), // Crucial: send no cookies to get generic version
                'sslverify'   => apply_filters('lha_fetcher_sslverify', true), // Allow filtering for dev
            );

            $response = wp_remote_get(esc_url_raw($url), $args);

            if (is_wp_error($response)) {
                if (class_exists('LHA_Logging')) {
                    LHA_Logging::error("Content Fetcher: Failed to fetch URL {$url}. WP_Error: " . $response->get_error_message());
                }
                return $response;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code !== 200) {
                $error_message = sprintf(__('Failed to fetch URL. HTTP Status Code: %d', 'lha-progressive-html'), $response_code);
                if (class_exists('LHA_Logging')) {
                    LHA_Logging::error("Content Fetcher: Failed to fetch URL {$url}. HTTP Status Code: {$response_code}. Body: " . wp_remote_retrieve_body($response));
                }
                return new \WP_Error(
                    'lha_fetcher_http_error',
                    $error_message,
                    array('status' => $response_code)
                );
            }

            $body = wp_remote_retrieve_body($response);
            if (empty($body)) {
                 if (class_exists('LHA_Logging')) {
                    LHA_Logging::error("Content Fetcher: Fetched URL {$url} but response body was empty.");
                 }
                return new \WP_Error('lha_fetcher_empty_body', __('Fetched URL but response body was empty.', 'lha-progressive-html'));
            }

            return $body;
        }

        /**
         * Validates if the given URL is internal to the current WordPress site.
         *
         * This is a security measure to prevent Server-Side Request Forgery (SSRF).
         * It compares the scheme and host of the input URL with the site's home_url.
         *
         * @since 0.2.0
         * @access private
         * @param string $url The URL to validate.
         * @return bool True if the URL is internal, false otherwise.
         */
        private function is_internal_url(string $url): bool {
            if (empty($url)) {
                return false;
            }

            $site_url_parts = parse_url(get_home_url());
            $request_url_parts = parse_url($url);

            // Check for malformed URLs or if essential parts are missing
            if (empty($request_url_parts['host']) || empty($site_url_parts['host'])) {
                return false;
            }
             if (empty($request_url_parts['scheme']) || empty($site_url_parts['scheme'])) {
                // If schemes are not present in one or both, it's ambiguous or malformed for a full URL.
                // However, WordPress often returns home_url() without a scheme if not explicitly set.
                // For robustness, if one has a scheme and the other doesn't, it's a mismatch.
                // If both lack schemes but hosts match, it might be a relative path scenario (though we expect full URLs here).
                // For now, we require both to have schemes for a valid comparison if one does.
                // If one is present and other is not, it's a mismatch.
                if(!empty($request_url_parts['scheme']) xor !empty($site_url_parts['scheme'])){
                     return false;   
                }
            }


            // Compare hosts (case-insensitive)
            if (strtolower($request_url_parts['host']) !== strtolower($site_url_parts['host'])) {
                return false;
            }
            
            // Compare schemes if both are present (case-insensitive)
            if (!empty($site_url_parts['scheme']) && !empty($request_url_parts['scheme'])) {
                if (strtolower($request_url_parts['scheme']) !== strtolower($site_url_parts['scheme'])) {
                    return false;
                }
            }

            // Path check (optional, can be complex with WP subfolder installs and permalinks)
            // The primary SSRF guard is matching host and scheme.
            // If WordPress is in a subdirectory, home_url() includes this path.
            // We should ensure the requested URL path starts with the home_url path.
            $site_path = !empty($site_url_parts['path']) ? rtrim($site_url_parts['path'], '/') : '';
            $request_path = !empty($request_url_parts['path']) ? rtrim($request_url_parts['path'], '/') : '';

            // Normalize paths to start with / if they are not empty
            if ($site_path !== '' && strpos($site_path, '/') !== 0) $site_path = '/' . $site_path;
            if ($request_path !== '' && strpos($request_path, '/') !== 0) $request_path = '/' . $request_path;


            if ($site_path !== '') { // If WP is in a subfolder
                if (strpos($request_path, $site_path) !== 0) {
                    // Example: home_url path is /wp, request path is /somepage -> false
                    // Request path must be /wp/somepage for it to be internal.
                    return false;
                }
            }
            // If site_path is empty (WP at root), any path on the same host is considered internal.

            return true;
        }
    }
}
// Closing ?> tag omitted as per WordPress coding standards.
