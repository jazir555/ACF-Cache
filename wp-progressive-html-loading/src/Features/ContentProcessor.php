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
            add_action('lha_master_content_processing_batch_action', array(__CLASS__, 'handle_master_content_processing_batch'), 10, 1);
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

            // --- DOMDocument Processing ---
            $doc = new \DOMDocument();
            libxml_use_internal_errors(true);
            // Prepending XML encoding declaration is a common way to hint encoding to loadHTML.
            // LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD prevents DOMDocument from adding <html><body> or <!DOCTYPE>
            // if they are already present, which is typical for full HTML documents.
            if (!@$doc->loadHTML('<?xml encoding="UTF-8">' . $html_content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
                if (class_exists('LHA_Logging')) {
                    LHA_Logging::error("Content Processor: Failed to load HTML into DOMDocument for Post ID: {$post_id}. Libxml errors: " . print_r(libxml_get_errors(), true));
                }
                libxml_clear_errors();
                return false;
            }
            libxml_clear_errors();

            if ($doc->documentElement === null) {
                 if (class_exists('LHA_Logging')) {
                    LHA_Logging::error("Content Processor: DOMDocument created with no documentElement for Post ID: {$post_id}. HTML might be severely malformed.");
                }
                return false;
            }

            $xpath = new \DOMXPath($doc);
            $options = get_option('lha_progressive_html_settings', array()); // Fetch all options
            $flush_marker_text = !empty($options['flush_comment']) ? trim(str_replace(array('<!--', '-->'), '', $options['flush_comment'])) : 'LHA_FLUSH_NOW';

            // Retrieve advanced strategy settings
            $css_selectors_enabled = $options['processing_css_selectors_enabled'] ?? false;
            $css_selectors_rules = $options['processing_css_selectors_rules'] ?? array();

            $nth_element_enabled = $options['processing_nth_element_enabled'] ?? false;
            $nth_element_rules = $options['processing_nth_element_rules'] ?? array();


            // Strategy 1: Insert marker after </head>
            $strategy_head_enabled = $options['processing_strategy_head'] ?? true; // Default from Admin Settings
            if ($strategy_head_enabled) {
                $head_nodes = $doc->getElementsByTagName('head');
                if ($head_nodes->length > 0) {
                    $head_node = $head_nodes->item(0);
                    $flush_marker_comment_node = $doc->createComment($flush_marker_text);
                    
                    // Insert after the <head> tag, within its parent (usually <html>)
                    if ($head_node->parentNode) { // Should always have a parent if it's the head of a document
                        if ($head_node->nextSibling) {
                            $head_node->parentNode->insertBefore($flush_marker_comment_node, $head_node->nextSibling);
                        } else {
                            $head_node->parentNode->appendChild($flush_marker_comment_node);
                        }
                        if (class_exists('LHA_Logging')) {
                            LHA_Logging::info("Content Processor: Inserted flush marker after <head> tag for Post ID: {$post_id}.");
                        }
                    }
                } else {
                     if (class_exists('LHA_Logging')) {
                        LHA_Logging::info("Content Processor: <head> tag not found for Post ID: {$post_id}. Marker not inserted via head strategy.");
                    }
                }
            }

            // Strategy 2: Replace User-Placed Target Markers
            // Defaults should match those in LHA_Admin_SettingsPage::get_default_settings()
            $user_marker_enabled = $options['processing_user_marker_enabled'] ?? true; 
            $user_marker_target = trim($options['processing_user_marker_target'] ?? 'LHA_FLUSH_TARGET'); 

            if ($user_marker_enabled && !empty($user_marker_target)) {
                $comment_nodes = $xpath->query('//comment()');
                foreach ($comment_nodes as $comment_node) {
                    if (trim($comment_node->nodeValue) === $user_marker_target) {
                        $flush_marker_node = $doc->createComment($flush_marker_text);
                        if ($comment_node->parentNode) {
                            $comment_node->parentNode->replaceChild($flush_marker_node, $comment_node);
                            if (class_exists('LHA_Logging')) {
                                LHA_Logging::info("Content Processor: Replaced user target marker '{$user_marker_target}' with flush marker for Post ID: {$post_id}.");
                            }
                        }
                    }
                }
            }

            // Strategy 3: CSS Selector-Based Insertion
            if ($css_selectors_enabled && !empty($css_selectors_rules) && is_array($css_selectors_rules)) {
                foreach ($css_selectors_rules as $rule_index => $rule) {
                    if (!is_array($rule) || empty($rule['selector']) || empty($rule['position'])) {
                        if (class_exists('LHA_Logging')) {
                            LHA_Logging::error("Content Processor: Invalid CSS selector rule (index {$rule_index}) for Post ID: {$post_id}. Rule: " . print_r($rule, true));
                        }
                        continue;
                    }

                    $css_selector = trim($rule['selector']);
                    $position = strtolower(trim($rule['position'])); // 'after', 'before', 'replace'

                    if (!in_array($position, array('after', 'before', 'replace'))) {
                         if (class_exists('LHA_Logging')) {
                            LHA_Logging::error("Content Processor: Invalid position '{$position}' for CSS selector '{$css_selector}' (Post ID: {$post_id}). Must be 'after', 'before', or 'replace'.");
                        }
                        continue;
                    }

                    $xpath_selector = $this->convert_css_to_xpath($css_selector);
                    if (empty($xpath_selector)) {
                        if (class_exists('LHA_Logging')) {
                            LHA_Logging::error("Content Processor: Could not convert CSS selector '{$css_selector}' to XPath for Post ID: {$post_id}.");
                        }
                        continue;
                    }

                    try {
                        $nodes = $xpath->query($xpath_selector);
                        if ($nodes && $nodes->length > 0) {
                            if (class_exists('LHA_Logging')) {
                                LHA_Logging::info("Content Processor: Found {$nodes->length} nodes for CSS selector '{$css_selector}' (XPath: '{$xpath_selector}') for Post ID: {$post_id}.");
                            }
                            // Iterate backwards to avoid issues with node removal/replacement if needed
                            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                                $dom_node = $nodes->item($i);
                                if (!$dom_node || !$dom_node->parentNode) continue; // Node might have been removed by a previous rule

                                $flush_marker_node = $doc->createComment($flush_marker_text);
                                switch ($position) {
                                    case 'after':
                                        if ($dom_node->nextSibling) {
                                            $dom_node->parentNode->insertBefore($flush_marker_node, $dom_node->nextSibling);
                                        } else {
                                            $dom_node->parentNode->appendChild($flush_marker_node);
                                        }
                                        break;
                                    case 'before':
                                        $dom_node->parentNode->insertBefore($flush_marker_node, $dom_node);
                                        break;
                                    case 'replace':
                                        $dom_node->parentNode->replaceChild($flush_marker_node, $dom_node);
                                        break;
                                }
                                if (class_exists('LHA_Logging')) {
                                     LHA_Logging::info("Content Processor: Applied '{$position}' for selector '{$css_selector}' on node " . ($i+1) . " for Post ID: {$post_id}.");
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                         if (class_exists('LHA_Logging')) {
                            LHA_Logging::error("Content Processor: Error processing XPath '{$xpath_selector}' for CSS selector '{$css_selector}' (Post ID: {$post_id}): " . $e->getMessage());
                        }
                    }
                }
            }

            // Strategy 4: Nth Element Count-Based Insertion
            if ($nth_element_enabled && !empty($nth_element_rules) && is_array($nth_element_rules)) {
                foreach ($nth_element_rules as $rule_index => $rule) {
                    if (!is_array($rule) || empty($rule['selector']) || empty($rule['count']) || !is_numeric($rule['count']) || $rule['count'] <= 0) {
                        if (class_exists('LHA_Logging')) {
                            LHA_Logging::error("Content Processor: Invalid Nth element rule (index {$rule_index}) for Post ID: {$post_id}. Rule: " . print_r($rule, true));
                        }
                        continue;
                    }

                    $target_selector = trim($rule['selector']); // e.g., 'p', 'div.my-class'
                    $nth_count = (int)$rule['count'];
                    $parent_css_selector = trim($rule['parent_selector'] ?? '');
                    
                    $context_nodes = array($doc->documentElement); // Default to document root

                    if (!empty($parent_css_selector)) {
                        $parent_xpath = $this->convert_css_to_xpath($parent_css_selector);
                        if (empty($parent_xpath)) {
                            if (class_exists('LHA_Logging')) {
                                LHA_Logging::error("Content Processor: Could not convert parent CSS selector '{$parent_css_selector}' to XPath for Nth element rule (Post ID: {$post_id}).");
                            }
                            continue;
                        }
                        try {
                            $parent_nodes_result = $xpath->query($parent_xpath);
                            if ($parent_nodes_result && $parent_nodes_result->length > 0) {
                                $context_nodes = iterator_to_array($parent_nodes_result);
                            } else {
                                if (class_exists('LHA_Logging')) {
                                    LHA_Logging::info("Content Processor: Nth element rule - parent selector '{$parent_css_selector}' not found for Post ID: {$post_id}. Skipping rule.");
                                }
                                continue;
                            }
                        } catch (\Throwable $e) {
                            if (class_exists('LHA_Logging')) {
                                LHA_Logging::error("Content Processor: Error querying parent selector '{$parent_css_selector}' (XPath '{$parent_xpath}') for Nth element rule (Post ID: {$post_id}): " . $e->getMessage());
                            }
                            continue;
                        }
                    }
                    
                    // Convert target selector to XPath for querying within context nodes
                    // This is tricky if target_selector is complex (e.g. has combinators).
                    // For simplicity, assume target_selector is a simple tag or class for direct children for now.
                    // A robust solution might involve complex XPath like './/p' or using a CSS to XPath library for $target_selector too.
                    // For now, let's assume simple tag name like 'p' for $rule['selector']
                    $element_tag_name = $target_selector; // Assuming rule['selector'] is just a tag name e.g. "p"
                    if (preg_match('/^[a-zA-Z0-9]+$/', $element_tag_name) === 0) { // Basic validation for tag name
                        if (class_exists('LHA_Logging')) {
                            LHA_Logging::error("Content Processor: Nth element rule - target selector '{$element_tag_name}' is not a simple tag name. More complex selectors for Nth element targeting are not yet supported. Post ID: {$post_id}.");
                        }
                        continue;
                    }


                    foreach ($context_nodes as $context_node_index => $context_node) {
                        if (!$context_node instanceof \DOMNode) continue;

                        try {
                            // Query for direct children of the context node matching the tag name.
                            // Example: $xpath->query('./p', $context_node)
                            $elements = $xpath->query('./' . $element_tag_name, $context_node);
                            $current_element_count = 0;

                            if ($elements && $elements->length > 0) {
                                if (class_exists('LHA_Logging')) {
                                    LHA_Logging::info("Content Processor: Nth Element - Context node " . ($context_node_index + 1) . ", found {$elements->length} '{$element_tag_name}' elements for Post ID: {$post_id}.");
                                }
                                foreach ($elements as $element_node) {
                                    if (!$element_node || !$element_node->parentNode) continue;
                                    $current_element_count++;
                                    if ($current_element_count % $nth_count === 0) {
                                        // Avoid inserting after the very last element if it's also the last child of its parent
                                        // or if it's the last matching element in this specific context.
                                        // This check can be refined based on desired behavior.
                                        // if ($current_element_count === $elements->length && !$element_node->nextSibling) continue;

                                        $flush_marker_node = $doc->createComment($flush_marker_text);
                                        if ($element_node->nextSibling) {
                                            $element_node->parentNode->insertBefore($flush_marker_node, $element_node->nextSibling);
                                        } else {
                                            $element_node->parentNode->appendChild($flush_marker_node);
                                        }
                                        if (class_exists('LHA_Logging')) {
                                            LHA_Logging::info("Content Processor: Nth element - Inserted marker after {$current_element_count}-th '{$element_tag_name}' in context node " . ($context_node_index + 1) . " for Post ID: {$post_id}.");
                                        }
                                        // Important: If inserting a node, the $elements NodeList might be live.
                                        // Re-querying or iterating carefully is needed if the DOM structure changes significantly mid-loop.
                                        // However, inserting a comment *after* the current node should be safe for subsequent iterations.
                                    }
                                }
                            }
                        } catch (\Throwable $e) {
                            if (class_exists('LHA_Logging')) {
                                LHA_Logging::error("Content Processor: Error processing Nth element rule for '{$element_tag_name}' in context node " . ($context_node_index + 1) . " (Post ID: {$post_id}): " . $e->getMessage());
                            }
                        }
                    }
                }
            }
            
            
            $modified_html_content_from_dom = $doc->saveHTML();

            if ($modified_html_content_from_dom === false || empty(trim($modified_html_content_from_dom))) {
                 if (class_exists('LHA_Logging')) {
                    LHA_Logging::error("Content Processor: Failed to serialize DOM to HTML (intermediate step) or result was empty for Post ID: {$post_id}.");
                }
                return false;
            }

            // Strategy 5: Minimum Chunk Size/Distance (String Manipulation on DOM output)
            $min_chunk_size_enabled = $options['processing_min_chunk_size_enabled'] ?? false;
            $min_chunk_size_bytes = isset($options['processing_min_chunk_size_bytes']) ? (int)$options['processing_min_chunk_size_bytes'] : 0;
            $final_html_content = $modified_html_content_from_dom; // Start with DOM processed content

            if ($min_chunk_size_enabled && $min_chunk_size_bytes > 0) {
                $actual_flush_comment = '<!--' . $flush_marker_text . '-->';
                $parts = explode($actual_flush_comment, $final_html_content);
                $new_html_parts = array();
                
                if (count($parts) > 1) { // Only if there's at least one marker
                    $new_html_parts[] = $parts[0]; // Always add the first part
                    $previous_part_met_min_size = true; // Assume first part (before first marker) is fine or doesn't count

                    for ($i = 1; $i < count($parts); $i++) {
                        $current_chunk_html = $parts[$i];
                        // The chunk is the content *after* a marker. We need to check its length.
                        // If the *previous* chunk (before this marker) was too small, this marker should be removed.
                        // More accurately: if $parts[$i-1] (content before current marker) is too short,
                        // we don't add the marker.
                        // Let's rephrase: we are deciding whether to keep the marker *before* $parts[$i].
                        
                        // If the content of $parts[$i-1] (the chunk just before the current potential marker)
                        // is too short, then we don't add the marker.
                        // This logic needs to be careful.
                        // Simpler: iterate through chunks. If a chunk (parts[i]) is too small,
                        // merge it with the previous one by not re-adding the delimiter.

                        // Correct logic:
                        // $parts[0] is content before first marker
                        // $parts[1] is content between first and second marker
                        // $parts[i] is content between i-th and (i+1)-th marker
                        
                        // If $parts[i] (content after a marker) is too small, we effectively remove the marker *before* it.
                        if (strlen(trim($current_chunk_html)) < $min_chunk_size_bytes && $i > 0) {
                             // If this chunk is too small, append it to the previous part without the marker.
                             // This means the marker that *preceded* this chunk is effectively removed.
                            $new_html_parts[count($new_html_parts) - 1] .= $current_chunk_html;
                            if (class_exists('LHA_Logging')) {
                                LHA_Logging::info("Content Processor: Min Chunk Size - Merged small chunk (part {$i}, length " . strlen(trim($current_chunk_html)) . " bytes) for Post ID: {$post_id}.");
                            }
                        } else {
                            // Chunk is large enough, or it's the first chunk after the very first marker.
                            // Add the marker and then the chunk.
                            $new_html_parts[] = $actual_flush_comment;
                            $new_html_parts[] = $current_chunk_html;
                        }
                    }
                    $final_html_content = implode('', $new_html_parts);
                }
            }


            $modified_html_content = $final_html_content; // Use the content after potential min_chunk_size adjustments

            if ($modified_html_content === false || empty(trim($modified_html_content))) {
                if (class_exists('LHA_Logging')) {
                    LHA_Logging::error("Content Processor: Failed to serialize DOM to HTML or result was empty for Post ID: {$post_id}.");
                }
                return false;
            }
            
            // Remove the <?xml encoding="UTF-8"> declaration if it was added by saveHTML()
            // This is often added if the input didn't have it and we used the XML prefix in loadHTML.
            $xml_declaration = '<?xml encoding="UTF-8">' . "\n";
            if (strpos($modified_html_content, $xml_declaration) === 0) {
                $modified_html_content = substr($modified_html_content, strlen($xml_declaration));
            }
            // Also handle case where there might not be a newline
             $xml_declaration_no_newline = '<?xml encoding="UTF-8">';
             if (strpos($modified_html_content, $xml_declaration_no_newline) === 0) {
                $modified_html_content = substr($modified_html_content, strlen($xml_declaration_no_newline));
            }


            $storage_manager = $this->service_locator->get('storage_manager');
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

        /**
         * Converts a simple CSS selector to an XPath query.
         *
         * This is a basic converter and supports:
         * - `tag` (e.g., `div`)
         * - `.class` (e.g., `.my-class`)
         * - `#id` (e.g., `#my-id`)
         * - `tag.class` (e.g., `div.my-class`)
         * - `tag#id` (e.g., `div#my-id`)
         * It does NOT support combinators (>, +, ~), attribute selectors (other than id/class), pseudo-classes, etc.
         *
         * @since 0.2.0
         * @access private
         * @param string $css_selector The CSS selector.
         * @return string The corresponding XPath query, or empty string if conversion fails.
         */
        private function convert_css_to_xpath(string $css_selector): string {
            $selector = trim($css_selector);
            $xpath = '';

            // Pattern for tag, id, class (e.g. div#myId.myClass.anotherClass)
            // Allows tag to be optional.
            if (preg_match('/^([a-zA-Z0-9_:-]*)?(#([a-zA-Z0-9_:-]+))?(\.[a-zA-Z0-9_:-]+)*$/', $selector, $matches)) {
                $tag = $matches[1] ?: '*'; // Default to any tag if not specified
                $id = $matches[3] ?? null;  // ID if present
                $classes_str = $matches[4] ?? null; // All .class.another matches

                $xpath = '//' . $tag;
                $conditions = array();

                if ($id) {
                    $conditions[] = "@id='" . $id . "'";
                }

                if ($classes_str) {
                    preg_match_all('/\.([a-zA-Z0-9_:-]+)/', $classes_str, $class_matches);
                    if (!empty($class_matches[1])) {
                        foreach ($class_matches[1] as $class_name) {
                            $conditions[] = "contains(concat(' ', normalize-space(@class), ' '), ' " . $class_name . " ')";
                        }
                    }
                }
                
                if (!empty($conditions)) {
                    $xpath .= '[' . implode(' and ', $conditions) . ']';
                }
                return $xpath;

            } else {
                // Very simple fallbacks for selectors not matching the complex regex (e.g. direct .class or #id)
                if (strpos($selector, '#') === 0 && strpos($selector, '.') === false && strpos($selector, ' ') === false) { // #id
                    $id = substr($selector, 1);
                    if (preg_match('/^[a-zA-Z0-9_:-]+$/', $id)) { // Basic validation for ID
                        return "//*[@id='" . $id . "']";
                    }
                } elseif (strpos($selector, '.') === 0 && strpos($selector, '#') === false && strpos($selector, ' ') === false) { // .class
                    $class = substr($selector, 1);
                     if (preg_match('/^[a-zA-Z0-9_:-]+$/', $class)) { // Basic validation for class name
                        return "//*[contains(concat(' ', normalize-space(@class), ' '), ' " . $class . " ')]";
                    }
                } elseif (preg_match('/^[a-zA-Z0-9_:-]+$/', $selector)) { // tag
                     return "//" . $selector;
                }
            }
            
            if (class_exists('LHA_Logging')) {
                LHA_Logging::error("Content Processor: CSS selector '{$css_selector}' is too complex or invalid for basic XPath conversion.");
            }
            return ''; // Failed to convert
        }

        /**
         * Static handler for the master batch processing task.
         *
         * Processes a chunk of items and reschedules itself for the next chunk.
         *
         * @since 0.2.1
         * @param array $args Arguments passed by Action Scheduler for the master task.
         *                  Expected keys: 'all_item_ids', 'current_offset', 'chunk_size',
         *                                 'single_item_action_hook', 'original_args', 'master_hook_name'.
         * @return void
         */
        public static function handle_master_content_processing_batch(array $args) {
            if (class_exists('LHA_Logging')) {
                LHA_Logging::info("Master Batch Handler: Task started. Args: " . print_r($args, true));
            }

            try {
                $all_item_ids           = $args['all_item_ids'] ?? array();
                $current_offset         = $args['current_offset'] ?? 0;
                $chunk_size             = $args['chunk_size'] ?? 25; // Default chunk size
                $single_item_action_hook = $args['single_item_action_hook'] ?? '';
                $original_args          = $args['original_args'] ?? array();
                $master_hook_name       = $args['master_hook_name'] ?? '';

                if (empty($all_item_ids) || empty($single_item_action_hook) || empty($master_hook_name) || $chunk_size <= 0) {
                    if (class_exists('LHA_Logging')) {
                        LHA_Logging::error("Master Batch Handler: Invalid or missing arguments. Aborting. Args: " . print_r($args, true));
                    }
                    return;
                }

                $item_chunk = array_slice($all_item_ids, $current_offset, $chunk_size);

                if (empty($item_chunk)) {
                     if (class_exists('LHA_Logging')) {
                        LHA_Logging::info("Master Batch Handler: No more items to process. Batch complete for master hook {$master_hook_name}.");
                    }
                    return;
                }

                $scheduler = null;
                if (function_exists('lha_progressive_html_loader_plugin')) {
                    $plugin_instance = lha_progressive_html_loader_plugin();
                    if ($plugin_instance && method_exists($plugin_instance, 'get_service_locator')) {
                        $service_locator = $plugin_instance->get_service_locator();
                        if ($service_locator) $scheduler = $service_locator->get('scheduler');
                    }
                }

                if (!$scheduler instanceof LHA_Core_Scheduler) {
                    if (class_exists('LHA_Logging')) {
                        LHA_Logging::error("Master Batch Handler: Could not retrieve Scheduler service.");
                    }
                    // Potentially reschedule self with a delay if this is a transient issue
                    // This would require access to the original $master_hook_name and $args from the current action.
                    // For now, we log and exit. A robust system might retry.
                    return;
                }

                if (class_exists('LHA_Logging')) {
                    LHA_Logging::info("Master Batch Handler: Processing chunk of " . count($item_chunk) . " items. Offset: {$current_offset}.");
                }

                foreach ($item_chunk as $item_id) {
                    $task_args = array('post_id' => (int)$item_id) + $original_args;
                    $scheduler->enqueue_async_action($single_item_action_hook, $task_args, true);
                }

                $new_offset = $current_offset + count($item_chunk); 

                if ($new_offset < count($all_item_ids)) {
                    $next_master_args = array(
                        'all_item_ids'            => $all_item_ids,
                        'current_offset'          => $new_offset,
                        'chunk_size'              => $chunk_size,
                        'single_item_action_hook' => $single_item_action_hook,
                        'original_args'           => $original_args,
                        'master_hook_name'        => $master_hook_name,
                    );
                    
                    $delay = apply_filters('lha_master_batch_reschedule_delay', MINUTE_IN_SECONDS * 1);
                    $scheduler->schedule_single_action($master_hook_name, $next_master_args, time() + $delay, true);
                    if (class_exists('LHA_Logging')) {
                        LHA_Logging::info("Master Batch Handler: Rescheduled self for next chunk. New offset: {$new_offset}. Master Hook: {$master_hook_name}.");
                    }
                } else {
                     if (class_exists('LHA_Logging')) {
                        LHA_Logging::info("Master Batch Handler: All items processed for master hook {$master_hook_name}. Batch complete.");
                    }
                }

            } catch (\Throwable $e) {
                if (class_exists('LHA_Logging')) {
                    LHA_Logging::error("Master Batch Handler: Uncaught exception: " . $e->getMessage() . " Stack: " . $e->getTraceAsString());
                }
            }
        }
    }
}
// Closing ?> tag omitted as per WordPress coding standards.
