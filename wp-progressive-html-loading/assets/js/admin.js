(function($) {
    $(document).ready(function() {
        // Check if lha_admin_settings_params is defined
        if (typeof lha_admin_settings_params === 'undefined') {
            console.error('LHA Admin Settings: lha_admin_settings_params is not defined. Make sure wp_localize_script is called correctly.');
            return;
        }

        var optionName = lha_admin_settings_params.option_name;
        var easyFieldIds = lha_admin_settings_params.easy_fields || [];
        var advancedFieldIds = lha_admin_settings_params.advanced_fields || [];
        
        // Section IDs from localized params (optional, if titles need direct manipulation)
        // var easySectionIds = lha_admin_settings_params.easy_section_ids || [];
        // var advancedSectionIds = lha_admin_settings_params.advanced_section_ids || [];

        // Function to get jQuery objects for field rows
        function getFieldRows(fieldIds) {
            return fieldIds.map(function(id) {
                return $('#' + id).closest('tr');
            });
        }
        
        // Function to get jQuery objects for section title/description (if sections are directly targeted)
        // WordPress sections are typically identified by an H2 followed by a form table.
        // The add_settings_section call's $id parameter becomes part of the H2's ID, like "lha_easy_mode_section-title"
        // or it's just the H2 tag before the table for that section.
        // For simplicity, we'll focus on field rows. If section titles need hiding, specific selectors are needed.
        // For example, if a section is <div id="lha_easy_mode_section">...</div>, we could use that.
        // But WordPress default is typically <h2>Section Title</h2><table class="form-table">...</table>
        // To hide section titles/descriptions, one might target the H2/P tags that precede the form table
        // for the specific section. For example:
        // $('h2#lha_easy_mode_section-title') or $('h2:contains("Easy Mode Settings")') which is less reliable.
        // A robust way is to ensure sections are wrapped in divs by the PHP and target those.
        // Since we are not doing that in this iteration, we will focus on field rows.
        // The section headers themselves (the <h> tags and <p> descriptions provided by `add_settings_section`)
        // are harder to toggle without specific wrapper divs.
        // For now, the "Display Mode" section will always be visible, which is fine.

        function toggleSections(mode) {
            var $easyFieldRows = getFieldRows(easyFieldIds);
            var $advancedFieldRows = getFieldRows(advancedFieldIds);

            if (mode === 'easy') {
                $easyFieldRows.forEach(function($el) { if ($el.length) $el.show(); });
                $advancedFieldRows.forEach(function($el) { if ($el.length) $el.hide(); });
                
                // Show/hide section titles if they have specific IDs (e.g. #lha_easy_mode_section-title)
                // Example: $('#lha_easy_mode_section-title, p.lha-section-description').show();
                // $('#lha_advanced_mode_section-title, p.lha-section-description').hide();
                // This requires those elements to have those IDs/classes.
                // The default add_settings_section does not add IDs to the H2s it creates.
                // So we rely on hiding rows. The section title remains.
            } else if (mode === 'advanced') {
                $easyFieldRows.forEach(function($el) { if ($el.length) $el.hide(); });
                $advancedFieldRows.forEach(function($el) { if ($el.length) $el.show(); });
            } else { // In case mode is undefined or unexpected
                $easyFieldRows.forEach(function($el) { if ($el.length) $el.show(); }); // Show all by default
                $advancedFieldRows.forEach(function($el) { if ($el.length) $el.show(); });
            }
        }

        // Initial state based on checked radio button
        var initialMode = $('input[name="' + optionName + '[admin_mode]"]:checked').val();
        if (!initialMode) { // If nothing is checked (e.g. fresh install, no default in DB)
            initialMode = 'easy'; // Default to 'easy'
        }
        toggleSections(initialMode);

        // Event listener for mode change
        $('input[name="' + optionName + '[admin_mode]"]').on('change', function() {
            toggleSections($(this).val());
        });
    });
})(jQuery);
