if (!Omeka) {
    var Omeka = {};
}

Omeka.CsvImport = {};

(function ($) {
    /**
     * Allow multiple mappings for each field, and add buttons to allow a mapping
     * to be removed.
     */
    Omeka.CsvImport.enableElementMapping = function () {
        $('form#csvimport .map-element').change(function () {
            var select = $(this);
            var addButton = select.siblings('span.add-element');
            if (!addButton.length) {
                var addButton = $('<span class="add-element"></span>');
                addButton.click(function() {
                    var copy = select.clone(true);
                    select.after(copy);
                    $(this).remove();
                });
                select.after(addButton);
            };
        });
    };

    /**
     * Add a little script that selects the right form values if our spreadsheet
     * uses the same names are our Omeka fields (or similar names like Creator_1,
     * Creator_2, and Creator_3 that should be mapped to our Creator Omeka field)
     */
    Omeka.CsvImport.assistWithMapping = function () {
        jQuery.each(jQuery('select[class="map-element"]'), function() {
            $tr = jQuery(this).parent().parent();
            $label = jQuery($tr).find('strong:eq(0)').text();
            $end = $label.lastIndexOf("_");

            if ($end != -1) {
                $label = $label.substring(0, $end);
            }
            $label = $label.replace(/ /g, '');

            jQuery.each(jQuery($tr).find('option'), function() {
                $optionText = jQuery(this).text().replace(/ /g, '');

                if ($optionText == $label) {
                    jQuery(this).attr('selected', 'selected');
                }
            });
        });
    };

    /**
     * Add a confirm step before undoing an import.
     */
    Omeka.CsvImport.confirm = function () {
        $('.csv-undo-import').click(function () {
            return confirm("Undoing an import will delete all of its imported records. Are you sure you want to undo this import?");
        });
    };

    /**
     * Enable/disable options according to selected format.
     */
    Omeka.CsvImport.updateImportOptions = function () {
        var fieldsManage = $('div.field').has('#action, #identifier_field, #item_type_id, #collection_id, #records_are_public, #records_are_featured, #elements_are_html, #contains_extra_data, #column_delimiter_name, #column_delimiter, #enclosure_name, #enclosure, #element_delimiter_name, #element_delimiter, #tag_delimiter_name, #tag_delimiter, #file_delimiter_name, #file_delimiter');
        var fieldsManageNo = $('div.field').has('#create_collections, #automap_columns');
        var fieldsReport = $('div.field').has('#elements_are_html');
        var fieldsReportNo = $('div.field').has('#action, #identifier_field, #item_type_id, #collection_id, #create_collections, #records_are_public, #records_are_featured, #contains_extra_data, #automap_columns, #column_delimiter_name, #column_delimiter, #enclosure_name, #enclosure, #element_delimiter_name, #element_delimiter, #tag_delimiter_name, #tag_delimiter, #file_delimiter_name, #file_delimiter');
        var fieldsItem = $('div.field').has('#item_type_id, #collection_id, #create_collections, #records_are_public, #records_are_featured, #automap_columns, #column_delimiter_name, #column_delimiter, #enclosure_name, #enclosure, #element_delimiter_name, #element_delimiter, #tag_delimiter_name, #tag_delimiter, #file_delimiter_name, #file_delimiter');
        var fieldsItemNo = $('div.field').has('#action, #identifier_field, #elements_are_html, #contains_extra_data');
        // Deprecated.
        var fieldsFile = $('div.field').has('#automap_columns, #column_delimiter_name, #column_delimiter, #enclosure_name, #enclosure, #element_delimiter_name, #element_delimiter, #tag_delimiter_name, #tag_delimiter');
        var fieldsFileNo = $('div.field').has('#action, #identifier_field, #item_type_id, #collection_id, #create_collections, #records_are_public, #records_are_featured, #elements_are_html, #contains_extra_data, #file_delimiter_name, #file_delimiter');
        var fieldsMix = $('div.field').has('#item_type_id, #collection_id, #create_collections, #records_are_public, #records_are_featured, #elements_are_html, #contains_extra_data, #column_delimiter_name, #column_delimiter, #enclosure_name, #enclosure, #element_delimiter_name, #element_delimiter, #tag_delimiter_name, #tag_delimiter, #file_delimiter_name, #file_delimiter');
        var fieldsMixNo = $('div.field').has('#action, #identifier_field, #automap_columns');
        var fieldsUpdate = fieldsMix;
        var fieldsUpdateNo = fieldsMixNo;
        var fieldsAll = $('div.field').has('#action, #identifier_field, #item_type_id, #collection_id, #create_collections, #records_are_public, #records_are_featured, #elements_are_html, #contains_extra_data, #automap_columns, #column_delimiter_name, #column_delimiter, #enclosure_name, #enclosure, #element_delimiter_name, #element_delimiter, #tag_delimiter_name, #tag_delimiter, #file_delimiter_name, #file_delimiter');
        var fieldSets =  $('#fieldset-csv_format, #fieldset-default_values, #fieldset-import_features');
        if ($('#format-Manage').is(':checked')) {
            fieldSets.slideDown();
            fieldsManage.slideDown();
            fieldsManageNo.slideUp();
        } else if ($('#format-Report').is(':checked')) {
            $('#fieldset-default_values').slideDown();
            $('#fieldset-csv_format, #fieldset-import_features').slideUp();
            fieldsReport.slideDown();
            fieldsReportNo.slideUp();
        } else if ($('#format-Item').is(':checked')) {
            fieldSets.slideDown();
            fieldsItem.slideDown();
            fieldsItemNo.slideUp();
        } else if ($('#format-File').is(':checked')) {
            $('#fieldset-default_values').slideUp();
            $('#fieldset-csv_format, #fieldset-import_features').slideDown();
            fieldsFile.slideDown();
            fieldsFileNo.slideUp();
        } else if ($('#format-Mix').is(':checked')) {
            fieldSets.slideDown();
            fieldsMix.slideDown();
            fieldsMixNo.slideUp();
        } else if ($('#format-Update').is(':checked')) {
            fieldSets.slideDown();
            fieldsUpdate.slideDown();
            fieldsUpdateNo.slideUp();
        } else {
            fieldSets.slideUp();
            fieldsAll.slideUp();
        };
    };

    /**
     * Enable/disable column delimiter field.
     */
    Omeka.CsvImport.updateColumnDelimiterField = function () {
        var fieldSelect = $('#column_delimiter_name');
        var fieldCustom = $('#column_delimiter');
        if (fieldSelect.val() == 'custom') {
            fieldCustom.show();
        } else {
            fieldCustom.hide();
        };
    };

    /**
     * Enable/disable enclosure field.
     */
    Omeka.CsvImport.updateEnclosureField = function () {
        var fieldSelect = $('#enclosure_name');
        var fieldCustom = $('#enclosure');
        if (fieldSelect.val() == 'custom') {
            fieldCustom.show();
        } else {
            fieldCustom.hide();
        };
    };

    /**
     * Enable/disable element delimiter field.
     */
    Omeka.CsvImport.updateElementDelimiterField = function () {
        var fieldSelect = $('#element_delimiter_name');
        var fieldCustom = $('#element_delimiter');
        if (fieldSelect.val() == 'custom') {
            fieldCustom.show();
        } else {
            fieldCustom.hide();
        };
    };

    /**
     * Enable/disable tag delimiter field.
     */
    Omeka.CsvImport.updateTagDelimiterField = function () {
        var fieldSelect = $('#tag_delimiter_name');
        var fieldCustom = $('#tag_delimiter');
        if (fieldSelect.val() == 'custom') {
            fieldCustom.show();
        } else {
            fieldCustom.hide();
        };
    };

    /**
     * Enable/disable file delimiter field.
     */
    Omeka.CsvImport.updateFileDelimiterField = function () {
        var fieldSelect = $('#file_delimiter_name');
        var fieldCustom = $('#file_delimiter');
        if (fieldSelect.val() == 'custom') {
            fieldCustom.show();
        } else {
            fieldCustom.hide();
        };
    };

    /**
     * Enable/disable options after loading.
     */
    Omeka.CsvImport.updateOnLoad = function () {
        Omeka.CsvImport.updateImportOptions();
        Omeka.CsvImport.updateColumnDelimiterField();
        Omeka.CsvImport.updateEnclosureField();
        Omeka.CsvImport.updateElementDelimiterField();
        Omeka.CsvImport.updateTagDelimiterField();
        Omeka.CsvImport.updateFileDelimiterField();
    };
})(jQuery);
