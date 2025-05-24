(function($) {
    $(document).ready(function() {
        // Check if lha_admin_params is defined (updated from lha_admin_settings_params)
        if (typeof lha_admin_params === 'undefined') {
            console.error('LHA Admin Settings: lha_admin_params is not defined. Make sure wp_localize_script is called correctly.');
            return;
        }

        var optionName = lha_admin_params.option_name;
        var easyFieldIds = lha_admin_params.easy_fields || [];
        var advancedFieldIds = lha_admin_params.advanced_fields || [];
        
        // Section IDs from localized params
        var easySectionIds = lha_admin_params.easy_section_ids || [];
        var advancedSectionIds = lha_admin_params.advanced_section_ids || [];

        // Function to get jQuery objects for field rows (TRs)
        function getFieldRows(fieldIds) {
            return fieldIds.map(function(id) {
                var $field = $('#' + id); // Input, select, textarea
                if ($field.length > 0) {
                    return $field.closest('tr');
                }
                // For custom fields like buttons that are not direct inputs with the ID
                var $buttonContainer = $('#' + id + '_button_container'); // Assuming a convention if needed
                if ($buttonContainer.length > 0) {
                    return $buttonContainer.closest('tr');
                }
                // Fallback for fields where the ID might be on the TR itself, or a wrapper div
                var $directContainer = $('#' + id + '-row'); // Example if TR has ID like 'my_field-row'
                if ($directContainer.length > 0 && $directContainer.is('tr')) {
                    return $directContainer;
                }
                // Handle cases for non-form elements like processing_user_marker_info (static text)
                // These might be wrapped in a <tr><td>...</td></tr> or similar.
                // If the ID from PHP is on the <td>, then .closest('tr') works.
                // If the ID is on a <p> tag inside the td, it also works.
                // For the static HTML field 'processing_user_marker_info', its ID is on the field definition.
                // WordPress settings API usually wraps fields in <tr><th>Label</th><td>Field</td></tr>.
                // If #id is the input/select/textarea, .closest('tr') is correct.
                // For the static field, it's the content itself that has the ID.
                // Let's assume the PHP add_settings_field for static HTML places the ID on a wrapping element
                // that is inside a <td>, or the <td> itself.
                var $staticFieldContainer = $('#' + id);
                if($staticFieldContainer.length && $staticFieldContainer.closest('tr').length) {
                    return $staticFieldContainer.closest('tr');
                }

                return $([]); // Return empty jQuery object if no row found
            });
        }
        
        // Function to get jQuery objects for section titles (H2s) and their following form tables
        // This targets the H2 tag that has an ID like 'section_id-title'
        // and the .form-table that immediately follows it.
        function getSectionElements(sectionIds) {
            var elements = [];
            sectionIds.forEach(function(id) {
                // WordPress sections don't have a single wrapper div by default.
                // The title (H2) and the table are separate.
                // We look for the H2. WordPress doesn't add IDs to these H2s by default.
                // We'll assume our PHP code in `render_settings_page` or similar wraps sections
                // in divs like <div id="lha_easy_mode_section_wrapper"> or similar if we need to hide titles.
                // For now, this function will be less effective unless such wrappers are added.
                // The `getFieldRows` function handles hiding individual field rows.
                
                // If section titles need to be hidden, they need IDs.
                // Example: $('h2#lha_easy_mode_section-title')
                // For now, let's assume we are primarily hiding rows.
                // If the provided ID is for a wrapper div around the section:
                var $sectionWrapper = $('#' + id + '_wrapper'); // e.g. #lha_easy_mode_section_wrapper
                if ($sectionWrapper.length) {
                    elements.push($sectionWrapper);
                }
            });
            return elements;
        }


        function toggleSections(mode) {
            var $easyFieldRows = getFieldRows(easyFieldIds);
            // For advanced, collect all rows from all advanced sections
            var allAdvancedFieldRows = [];
            advancedFieldIds.forEach(function(id) {
                var $field = $('#' + id);
                if ($field.length > 0) {
                     allAdvancedFieldRows.push($field.closest('tr'));
                } else {
                    // Handle buttons or static fields that don't have input IDs but are listed in advancedFieldIds
                    // Their visibility is often tied to their section.
                    // For example, for 'manual_process_post_id_field', the button is #lha_schedule_single_button
                    // and the input is #lha_manual_post_id_input.
                    // The `advancedFieldIds` from PHP lists the add_settings_field ID, not necessarily the input ID.
                    // The getFieldRows should ideally handle this.
                    var $row = getFieldRows([id])[0]; // Get the first (and only) element
                    if ($row && $row.length) {
                        allAdvancedFieldRows.push($row);
                    }
                }
            });


            // Hide/Show sections based on mode (assuming sections are wrapped in divs with IDs like 'lha_easy_mode_section-section')
            // This part needs careful coordination with how sections are rendered in PHP.
            easySectionIds.forEach(function(secId) {
                $('#' + secId).toggle(mode === 'easy'); // Assuming section H2 or wrapper has this ID
                $('.' + secId + '-wrapper').toggle(mode === 'easy'); // If custom wrappers used
            });
            advancedSectionIds.forEach(function(secId) {
                 $('#' + secId).toggle(mode === 'advanced');
                 $('.' + secId + '-wrapper').toggle(mode === 'advanced');
            });


            if (mode === 'easy') {
                $easyFieldRows.forEach(function($el) { if ($el.length) $el.show(); });
                allAdvancedFieldRows.forEach(function($el) { if ($el.length) $el.hide(); });
                
                // Show H2s and form-tables for easy sections, hide for advanced
                easySectionIds.forEach(id => {
                    const sectionTitle = $(`h2:contains("${id.replace(/_/g, ' ').replace('lha ', '').replace(' section', '')}")`); // Heuristic
                    sectionTitle.show().next('.form-table').show();
                });
                advancedSectionIds.forEach(id => {
                    const sectionTitle = $(`h2:contains("${id.replace(/_/g, ' ').replace('lha ', '').replace(' section', '')}")`);
                    sectionTitle.hide().next('.form-table').hide();
                });


            } else if (mode === 'advanced') {
                $easyFieldRows.forEach(function($el) { if ($el.length) $el.hide(); });
                allAdvancedFieldRows.forEach(function($el) { if ($el.length) $el.show(); });

                easySectionIds.forEach(id => {
                    const sectionTitle = $(`h2:contains("${id.replace(/_/g, ' ').replace('lha ', '').replace(' section', '')}")`);
                    sectionTitle.hide().next('.form-table').hide();
                });
                advancedSectionIds.forEach(id => {
                    const sectionTitle = $(`h2:contains("${id.replace(/_/g, ' ').replace('lha ', '').replace(' section', '')}")`);
                    sectionTitle.show().next('.form-table').show();
                });
            }
        }

        var initialMode = $('input[name="' + optionName + '[admin_mode]"]:checked').val();
        if (!initialMode) {
            initialMode = 'easy'; 
        }
        toggleSections(initialMode); // Apply initial visibility

        $('input[name="' + optionName + '[admin_mode]"]').on('change', function() {
            toggleSections($(this).val());
        });

        // AJAX Handlers for Manual Tools
        var ajaxUrl = lha_admin_params.ajax_url;
        var nonce = lha_admin_params.nonce;
        var i18n = lha_admin_params.i18n || {
            confirm_clear_cache: 'Are you sure you want to clear all pre-processed content?',
            processing_message: 'Processing...',
            error_occurred: 'An error occurred.'
        };
        var $messageArea = $('#lha_ajax_message_area');

        function showAjaxMessage(message, isError) {
            $messageArea.html(message).removeClass('notice-success notice-error notice-warning').addClass(isError ? 'notice notice-error' : 'notice notice-success').show();
            setTimeout(function() {
                $messageArea.fadeOut(function() { $(this).empty(); });
            }, 5000);
        }

        // Schedule Single Post
        $('#lha_schedule_single_button').on('click', function(e) {
            e.preventDefault();
            var $button = $(this);
            var postId = $('#lha_manual_post_id_input').val(); // Corrected ID

            if (!postId || !/^\d+$/.test(postId) || parseInt(postId, 10) <= 0) {
                showAjaxMessage('Please enter a valid Post ID.', true);
                return;
            }

            $button.prop('disabled', true);
            showAjaxMessage(i18n.processing_message, false);

            $.post(ajaxUrl, {
                action: 'lha_schedule_single_post',
                nonce: nonce,
                post_id: postId
            }, function(response) {
                if (response.success) {
                    showAjaxMessage(response.data.message, false);
                } else {
                    showAjaxMessage((response.data && response.data.message) ? response.data.message : i18n.error_occurred, true);
                }
            }).fail(function() {
                showAjaxMessage(i18n.error_occurred + ' (Request failed)', true);
            }).always(function() {
                $button.prop('disabled', false);
                $('#lha_manual_post_id_input').val(''); 
            });
        });

        // Schedule Batch Processing
        $('#lha_schedule_batch_button').on('click', function(e) {
            e.preventDefault();
            var $button = $(this);
            $button.prop('disabled', true);
            showAjaxMessage(i18n.processing_message, false);

            $.post(ajaxUrl, {
                action: 'lha_schedule_batch_processing',
                nonce: nonce
            }, function(response) {
                if (response.success) {
                    showAjaxMessage(response.data.message, false);
                } else {
                    showAjaxMessage((response.data && response.data.message) ? response.data.message : i18n.error_occurred, true);
                }
            }).fail(function() {
                showAjaxMessage(i18n.error_occurred + ' (Request failed)', true);
            }).always(function() {
                $button.prop('disabled', false);
            });
        });

        // Clear All Processed Content
        $('#lha_clear_cache_button').on('click', function(e) {
            e.preventDefault();
            if (!confirm(i18n.confirm_clear_cache)) {
                return;
            }
            var $button = $(this);
            $button.prop('disabled', true);
            showAjaxMessage(i18n.processing_message, false);

            $.post(ajaxUrl, {
                action: 'lha_clear_all_processed_content',
                nonce: nonce
            }, function(response) {
                if (response.success) {
                    showAjaxMessage(response.data.message, false);
                } else {
                    showAjaxMessage((response.data && response.data.message) ? response.data.message : i18n.error_occurred, true);
                }
            }).fail(function() {
                showAjaxMessage(i18n.error_occurred + ' (Request failed)', true);
            }).always(function() {
                $button.prop('disabled', false);
            });
        });
    });
})(jQuery);
