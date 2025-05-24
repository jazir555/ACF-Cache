(function($) {
    $(document).ready(function() {
        // Check if lha_admin_params is defined
        if (typeof lha_admin_params === 'undefined') {
            console.error('LHA Admin JS: lha_admin_params is not defined.');
            // No return here, as metabox JS might still run if its specific elements are present
        }

        var optionName = (lha_admin_params && lha_admin_params.option_name) ? lha_admin_params.option_name : '';
        var easyFieldIds = (lha_admin_params && lha_admin_params.easy_fields) ? lha_admin_params.easy_fields : [];
        var advancedFieldIds = (lha_admin_params && lha_admin_params.advanced_fields) ? lha_admin_params.advanced_fields : [];
        var easySectionIds = (lha_admin_params && lha_admin_params.easy_section_ids) ? lha_admin_params.easy_section_ids : [];
        var advancedSectionIds = (lha_admin_params && lha_admin_params.advanced_section_ids) ? lha_admin_params.advanced_section_ids : [];

        // --- Admin Settings Page JS (Mode Switching & Fallback Options) ---
        if ($('form[action="options.php"]').length && optionName) { // Only run on the plugin's settings page

            function getFieldRows(fieldIds) {
                return fieldIds.map(function(id) {
                    var $field = $('#' + id); 
                    if ($field.length > 0) {
                        return $field.closest('tr');
                    }
                    var $buttonContainer = $('#' + id + '_button_container'); 
                    if ($buttonContainer.length > 0) {
                        return $buttonContainer.closest('tr');
                    }
                    var $directContainer = $('#' + id + '-row'); 
                    if ($directContainer.length > 0 && $directContainer.is('tr')) {
                        return $directContainer;
                    }
                    var $staticFieldContainer = $('#' + id);
                    if($staticFieldContainer.length && $staticFieldContainer.closest('tr').length) {
                        return $staticFieldContainer.closest('tr');
                    }
                    return $([]); 
                });
            }

            function toggleSections(mode) {
                var $easyFieldRows = getFieldRows(easyFieldIds);
                var allAdvancedFieldRows = [];
                advancedFieldIds.forEach(function(id) {
                    var $row = getFieldRows([id])[0]; 
                    if ($row && $row.length) {
                        allAdvancedFieldRows.push($row);
                    }
                });

                if (mode === 'easy') {
                    $easyFieldRows.forEach(function($el) { if ($el.length) $el.show(); });
                    allAdvancedFieldRows.forEach(function($el) { if ($el.length) $el.hide(); });
                    easySectionIds.forEach(id => { $('h2:contains("' + id.replace(/_/g, ' ').replace(/^lha\s*/i, '').replace(/\s*section$/i, '') + '")').show().next('.form-table').show(); });
                    advancedSectionIds.forEach(id => { $('h2:contains("' + id.replace(/_/g, ' ').replace(/^lha\s*/i, '').replace(/\s*section$/i, '') + '")').hide().next('.form-table').hide(); });
                } else if (mode === 'advanced') {
                    $easyFieldRows.forEach(function($el) { if ($el.length) $el.hide(); });
                    allAdvancedFieldRows.forEach(function($el) { if ($el.length) $el.show(); });
                    easySectionIds.forEach(id => { $('h2:contains("' + id.replace(/_/g, ' ').replace(/^lha\s*/i, '').replace(/\s*section$/i, '') + '")').hide().next('.form-table').hide(); });
                    advancedSectionIds.forEach(id => { $('h2:contains("' + id.replace(/_/g, ' ').replace(/^lha\s*/i, '').replace(/\s*section$/i, '') + '")').show().next('.form-table').show(); });
                }
            }

            var initialMode = $('input[name="' + optionName + '[admin_mode]"]:checked').val();
            if (!initialMode) { initialMode = 'easy'; }
            toggleSections(initialMode);
            $('input[name="' + optionName + '[admin_mode]"]').on('change', function() { toggleSections($(this).val()); });

            function toggleRealtimeFallbackOptions(selectedFallbackBehavior) {
                var $realtimeOptions = $('.lha_fallback_realtime_option').closest('tr');
                if (selectedFallbackBehavior === 'realtime') {
                    $realtimeOptions.show();
                } else {
                    $realtimeOptions.hide();
                }
            }
            var $fallbackBehaviorSelect = $('#fallback_behavior');
            if ($fallbackBehaviorSelect.length) {
                toggleRealtimeFallbackOptions($fallbackBehaviorSelect.val());
                $fallbackBehaviorSelect.on('change', function() { toggleRealtimeFallbackOptions($(this).val()); });
            }

            // AJAX Handlers for Settings Page Manual Tools
            var $settingsMessageArea = $('#lha_ajax_message_area');
            function showSettingsAjaxMessage(message, isError) {
                $settingsMessageArea.html(message).removeClass('notice-success notice-error notice-warning').addClass(isError ? 'notice notice-error' : 'notice notice-success').show();
                setTimeout(function() { $settingsMessageArea.fadeOut(function() { $(this).empty(); }); }, 5000);
            }

            $('#lha_schedule_single_button').on('click', function(e) { /* ... content from Turn 48, using showSettingsAjaxMessage ... */ 
                e.preventDefault();
                var $button = $(this);
                var postId = $('#lha_manual_post_id_input').val();
                if (!postId || !/^\d+$/.test(postId) || parseInt(postId, 10) <= 0) {
                    showSettingsAjaxMessage('Please enter a valid Post ID.', true); return;
                }
                $button.prop('disabled', true);
                showSettingsAjaxMessage(lha_admin_params.i18n.processing_message || 'Processing...', false);
                $.post(lha_admin_params.ajax_url, { action: 'lha_schedule_single_post', nonce: lha_admin_params.nonce, post_id: postId })
                .done(function(r) { showSettingsAjaxMessage(r.data.message, !r.success); })
                .fail(function() { showSettingsAjaxMessage(lha_admin_params.i18n.error_occurred + ' (Request failed)', true); })
                .always(function() { $button.prop('disabled', false); $('#lha_manual_post_id_input').val(''); });
            });
            $('#lha_schedule_batch_button').on('click', function(e) { /* ... content from Turn 48, using showSettingsAjaxMessage ... */
                e.preventDefault();
                var $button = $(this);
                $button.prop('disabled', true);
                showSettingsAjaxMessage(lha_admin_params.i18n.processing_message || 'Processing...', false);
                $.post(lha_admin_params.ajax_url, { action: 'lha_schedule_batch_processing', nonce: lha_admin_params.nonce })
                .done(function(r) { showSettingsAjaxMessage(r.data.message, !r.success); })
                .fail(function() { showSettingsAjaxMessage(lha_admin_params.i18n.error_occurred + ' (Request failed)', true); })
                .always(function() { $button.prop('disabled', false); });
            });
            $('#lha_clear_cache_button').on('click', function(e) { /* ... content from Turn 48, using showSettingsAjaxMessage ... */
                e.preventDefault();
                if (!confirm(lha_admin_params.i18n.confirm_clear_cache || 'Are you sure?')) return;
                var $button = $(this);
                $button.prop('disabled', true);
                showSettingsAjaxMessage(lha_admin_params.i18n.processing_message || 'Processing...', false);
                $.post(lha_admin_params.ajax_url, { action: 'lha_clear_all_processed_content', nonce: lha_admin_params.nonce })
                .done(function(r) { showSettingsAjaxMessage(r.data.message, !r.success); })
                .fail(function() { showSettingsAjaxMessage(lha_admin_params.i18n.error_occurred + ' (Request failed)', true); })
                .always(function() { $button.prop('disabled', false); });
            });
        }


        // --- Metabox Specific JS ---
        // Corrected Metabox ID from 'lha_streaming_status_metabox' to 'lha_progressive_html_metabox'
        var $metabox = $('#lha_progressive_html_metabox');
        if ($metabox.length) {
            // Corrected message area ID
            var $metaboxMessagesDiv = $metabox.find('#lha-metabox-message-area'); 
            var metaboxNonceFieldVal = $metabox.find('#lha_metabox_ajax_nonce_field').val();

            function showMetaboxAjaxMessage(message, isError) {
                $metaboxMessagesDiv.html(message).removeClass('notice-success notice-error notice-warning').addClass(isError ? 'notice notice-error' : 'notice notice-success').show();
                setTimeout(function() {
                    $metaboxMessagesDiv.fadeOut(function() { $(this).empty(); });
                }, 5000);
            }

            // Reprocess Button - Corrected button ID
            $metabox.on('click', '#lha_metabox_reprocess_button', function() {
                var $button = $(this);
                var postId = $button.data('post-id'); // Corrected data attribute name
                var confirmMsg = (lha_admin_params && lha_admin_params.i18n && lha_admin_params.i18n.metabox_reprocess_confirm) 
                                 ? lha_admin_params.i18n.metabox_reprocess_confirm 
                                 : 'Are you sure you want to schedule this post for reprocessing?';

                if (!confirm(confirmMsg)) return;

                showMetaboxAjaxMessage((lha_admin_params.i18n.processing_message || 'Processing...'), false);
                $button.prop('disabled', true);

                $.post(ajaxurl, { // Using global ajaxurl for metabox context
                    action: 'lha_metabox_reprocess_post',
                    nonce: metaboxNonceFieldVal, // Use the nonce from the metabox field
                    post_id: postId
                })
                .done(function(response) {
                    if (response.success) {
                        showMetaboxAjaxMessage(response.data.message || (lha_admin_params.i18n.success || 'Success!'), false);
                        // Optionally update status text - for now, rely on page reload or manual refresh.
                        // For example: $metabox.find('#lha-metabox-status').html('Status: Queued for reprocessing...');
                        // Might require AJAX response to send back new status HTML or specific status key.
                    } else {
                        showMetaboxAjaxMessage(response.data.message || (lha_admin_params.i18n.error_occurred || 'Error!'), true);
                    }
                })
                .fail(function() {
                    showMetaboxAjaxMessage((lha_admin_params.i18n.error_occurred || 'Error!') + ' (Request Failed)', true);
                })
                .always(function() {
                    $button.prop('disabled', false);
                });
            });

            // Delete Data Button - Corrected button ID
            $metabox.on('click', '#lha_metabox_delete_data_button', function() {
                var $button = $(this);
                var postId = $button.data('post-id'); // Corrected data attribute name
                var confirmMsg = (lha_admin_params && lha_admin_params.i18n && lha_admin_params.i18n.metabox_delete_confirm) 
                                 ? lha_admin_params.i18n.metabox_delete_confirm
                                 : 'Are you sure you want to delete processed data for this post?';

                if (!confirm(confirmMsg)) return;
                
                showMetaboxAjaxMessage((lha_admin_params.i18n.processing_message || 'Processing...'), false);
                $button.prop('disabled', true);

                $.post(ajaxurl, { // Using global ajaxurl
                    action: 'lha_metabox_delete_data',
                    nonce: metaboxNonceFieldVal, // Use the nonce from the metabox field
                    post_id: postId
                })
                .done(function(response) {
                    if (response.success) {
                        showMetaboxAjaxMessage(response.data.message || (lha_admin_params.i18n.success || 'Success!'), false);
                        $button.hide(); // Hide delete button after successful deletion
                        // Optionally update status text
                        // $metabox.find('#lha-metabox-status').html('Status: Not yet processed.');
                    } else {
                        showMetaboxAjaxMessage(response.data.message || (lha_admin_params.i18n.error_occurred || 'Error!'), true);
                    }
                })
                .fail(function() {
                    showMetaboxAjaxMessage((lha_admin_params.i18n.error_occurred || 'Error!') + ' (Request Failed)', true);
                })
                .always(function() {
                    if ($button.is(':visible')) { // Don't re-enable if hidden
                         $button.prop('disabled', false);
                    }
                });
            });
        }
    });
})(jQuery);
