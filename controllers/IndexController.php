<?php
/**
 * CsvImport_IndexController class - represents the Csv Import index controller
 *
 * @copyright Copyright 2008-2012 Roy Rosenzweig Center for History and New Media
 * @license http://www.gnu.org/licenses/gpl-3.0.txt GNU GPLv3
 * @package CsvImport
 */
class CsvImport_IndexController extends Omeka_Controller_AbstractActionController
{
    protected $_browseRecordsPerPage = 10;
    protected $_pluginConfig = array();

    /**
     * Initialize the controller.
     */
    public function init()
    {
        $this->session = new Zend_Session_Namespace('CsvImport');
        $this->_helper->db->setDefaultModelName('CsvImport_Import');
    }

    /**
     * Configure a new import (first step).
     */
    public function indexAction()
    {
        $form = $this->_getMainForm();
        $this->view->form = $form;

        if (!$this->getRequest()->isPost()) {
            return;
        }

        if (!$form->isValid($this->getRequest()->getPost())) {
            $this->_helper->flashMessenger(__('Invalid form input. Please see errors below and try again.'), 'error');
            return;
        }

        if (!$form->csv_file->receive()) {
            $this->_helper->flashMessenger(__('Error uploading file. Please try again.'), 'error');
            return;
        }

        $filePath = $form->csv_file->getFileName();
        $delimitersList = self::getDelimitersList();
        $columnDelimiterName = $form->getValue('column_delimiter_name');
        $columnDelimiter = isset($delimitersList[$columnDelimiterName])
            ? $delimitersList[$columnDelimiterName]
            : $form->getValue('column_delimiter');
        $enclosuresList = self::getEnclosuresList();
        $enclosureName = $form->getValue('enclosure_name');
        $enclosure = isset($enclosuresList[$enclosureName])
            ? $enclosuresList[$enclosureName]
            : $form->getValue('enclosure');

        $file = new CsvImport_File($filePath, $columnDelimiter, $enclosure);

        if (!$file->parse()) {
            $this->_helper->flashMessenger(__('Your file is incorrectly formatted.')
                . ' ' . $file->getErrorString(), 'error');
            return;
        }

        $identifierField = $this->_elementNameFromPost($form->getValue('identifier_field'));

        $this->session->setExpirationHops(2);
        $this->session->originalFilename = $_FILES['csv_file']['name'];
        $this->session->filePath = $filePath;
        $this->session->format = $form->getValue('format');
        $this->session->action = $form->getValue('action');
        $this->session->identifierField = $identifierField;
        $this->session->itemTypeId = $form->getValue('item_type_id');
        $this->session->collectionId = $form->getValue('collection_id');
        $this->session->recordsArePublic = $form->getValue('records_are_public');
        $this->session->recordsAreFeatured = $form->getValue('records_are_featured');
        $this->session->elementsAreHtml = $form->getValue('elements_are_html');
        $this->session->createCollections = $form->getValue('create_collections');
        $this->session->automapColumns = $form->getValue('automap_columns');
        $this->session->containsExtraData = $form->getValue('contains_extra_data');
        $this->session->columnDelimiter = $columnDelimiter;
        $this->session->enclosure = $enclosure;
        $this->session->columnNames = $file->getColumnNames();
        $this->session->columnExamples = $file->getColumnExamples();
        // A bug appears when examples contain UTF-8 characters like 'ГЧ„чŁ'.
        // The bug is only here, not during import of characters into database.
        foreach ($this->session->columnExamples as &$value) {
            $value = iconv('ISO-8859-15', 'UTF-8', @iconv('UTF-8', 'ISO-8859-15' . '//IGNORE', $value));
        }

        $elementDelimiterName = $form->getValue('element_delimiter_name');
        $this->session->elementDelimiter = isset($delimitersList[$elementDelimiterName])
            ? $delimitersList[$elementDelimiterName]
            : $form->getValue('element_delimiter');
        $tagDelimiterName = $form->getValue('tag_delimiter_name');
        $this->session->tagDelimiter = isset($delimitersList[$tagDelimiterName])
            ? $delimitersList[$tagDelimiterName]
            : $form->getValue('tag_delimiter');
        $fileDelimiterName = $form->getValue('file_delimiter_name');
        $this->session->fileDelimiter = isset($delimitersList[$fileDelimiterName])
            ? $delimitersList[$fileDelimiterName]
            : $form->getValue('file_delimiter');

        $this->session->ownerId = $this->getInvokeArg('bootstrap')->currentuser->id;

        // All is valid, so we save settings.
        set_option(CsvImport_ColumnMap_IdentifierField::IDENTIFIER_FIELD_OPTION_NAME, $this->session->identifierField);
        set_option(CsvImport_RowIterator::COLUMN_DELIMITER_OPTION_NAME, $this->session->columnDelimiter);
        set_option(CsvImport_RowIterator::ENCLOSURE_OPTION_NAME, $this->session->enclosure);
        set_option(CsvImport_ColumnMap_Element::ELEMENT_DELIMITER_OPTION_NAME, $this->session->elementDelimiter);
        set_option(CsvImport_ColumnMap_Tag::TAG_DELIMITER_OPTION_NAME, $this->session->tagDelimiter);
        set_option(CsvImport_ColumnMap_File::FILE_DELIMITER_OPTION_NAME, $this->session->fileDelimiter);
        set_option('csv_import_html_elements', $this->session->elementsAreHtml);
        set_option('csv_import_create_collections', $this->session->createCollections);
        set_option('csv_import_extra_data', $this->session->containsExtraData);
        set_option('csv_import_automap_columns', $this->session->automapColumns);

        if ($this->session->containsExtraData == 'manual' && $this->session->format != 'Report') {
            $this->_helper->redirector->goto('map-columns');
        }

        switch ($this->session->format) {
            case 'Manage':
                $this->_helper->redirector->goto('check-manage-csv');
            case 'Report':
                $this->_helper->redirector->goto('check-omeka-csv');
            //Deprecated.
            case 'Mix':
                $this->_helper->redirector->goto('check-mix-csv');
            case 'Update':
                $this->_helper->redirector->goto('check-update-csv');
            default:
                $this->_helper->redirector->goto('map-columns');
        }
    }

    /**
     * Map the columns for an import (second step if needed or wished).
     */
    public function mapColumnsAction()
    {
        if (!$this->_sessionIsValid()) {
            $this->_helper->flashMessenger(__('Import settings expired. Please try again.'), 'error');
            $this->_helper->redirector->goto('index');
            return;
        }

        require_once CSV_IMPORT_DIRECTORY . '/forms/Mapping.php';

        $parameters = array(
            'format' => $this->session->format,
            'columnNames' => $this->session->columnNames,
            'columnExamples' => $this->session->columnExamples,
            'elementDelimiter' => $this->session->elementDelimiter,
            'tagDelimiter' => $this->session->tagDelimiter,
            'fileDelimiter' => $this->session->fileDelimiter,
            // Parameters for all formats.
            'itemTypeId' => $this->session->itemTypeId,
            'automapColumns' => $this->session->automapColumns,
            // Previously managed at main level (saved separately in the base)
            // for "Report", "Item" and "File".
            'collectionId' => $this->session->collectionId,
            'isPublic' => $this->session->recordsArePublic,
            'isFeatured' => $this->session->recordsAreFeatured,
            'elementsAreHtml' => $this->session->elementsAreHtml,
        );
        switch ($this->session->format) {
            case 'Manage':
                $parameters += array(
                    'action' => $this->session->action,
                    'identifierField' => $this->session->identifierField,
                    'createCollections' => true,
                );
                break;
            case 'Report' :
                $parameters += array(
                    'createCollections' => false,
                );
                break;
            case 'Mix':
            case 'Update':
                $parameters += array(
                    'createCollections' => $this->session->createCollections,
                );
                break;
        }

        $form = new CsvImport_Form_Mapping($parameters);
        if (!$form) {
            $this->_helper->flashMessenger(__('Invalid form input. Please try again.'), 'error');
            $this->_helper->redirector->goto('index');
        }
        $this->view->form = $form;
        $this->view->csvFile = basename($this->session->originalFilename);

        if (!$this->getRequest()->isPost()) {
            return;
        }
        if (!$form->isValid($this->getRequest()->getPost())) {
            $this->_helper->flashMessenger(__('Invalid form input. Please try again.'), 'error');
            return;
        }

        $columnMaps = $form->getColumnMaps();
        if (count($columnMaps) == 0) {
            $this->_helper->flashMessenger(__('Please map at least one column to an element, file, or tag.'), 'error');
            return;
        }

        // Check if there is an identifier column for the format "Manage".
        if ($this->session->format == 'Manage') {
            $isSetIdentifier = false;
            foreach ($columnMaps as $columnMap) {
                if ($columnMap instanceof CsvImport_ColumnMap_Identifier) {
                    $isSetIdentifier = true;
                    break;
                }
            }
            if (!$isSetIdentifier) {
                $this->_helper->flashMessenger(__('Please map a column to the special value "Identifier", or change the format.'), 'error');
                return;
            }
        }

        $this->session->columnMaps = $columnMaps;

        $this->_launchImport();
    }

    /**
     * For management of records.
     */
    public function checkManageCsvAction()
    {
        $skipColumns = array(
            'Action',
            'Identifier',
            'IdentifierField',
            'RecordType',
            'Collection',
            'Item',
            'File',
            'ItemType',
            'Public',
            'Featured',
            'Tags',
        );
        // There is no required column: identifier can be any field.
        $requiredColumns = array();
        // To avoid old mixed and update files.
        $forbiddenColumns = array(
            'updateMode',
            'updateIdentifier',
            'recordType',
            'fileUrl',
        );
        $this->_checkCsv($skipColumns, $requiredColumns, $forbiddenColumns);
    }

    /**
     * For import of Omeka.net CSV.
     */
    public function checkOmekaCsvAction()
    {
        $skipColumns = array(
            'itemType',
            'collection',
            'public',
            'featured',
            'tags',
            'file',
        );
        $this->_checkCsv($skipColumns);
    }

    /**
     * For import with mixed records. Similar to Csv Report, but allows to
     * import files one by one, to import metadata of files and to choose
     * default values and delimiters.
     *
     * @deprecated Since 2.1.1-full.
     */
    public function checkMixCsvAction()
    {
        $skipColumns = array(
            'sourceItemId',
            'recordType',
            'file',
            'fileUrl',
            'itemType',
            'collection',
            'public',
            'featured',
            'tags',
        );
        $forbiddenColumns = array(
            'Action',
            'Identifier',
            'IdentifierField',
        );
        $this->_checkCsv($skipColumns, array(), $forbiddenColumns);
    }

    /**
     * For update of records.
     *
     * @deprecated Since 2.1.1-full.
     */
    public function checkUpdateCsvAction()
    {
        $skipColumns = array(
            'updateMode',
            'updateIdentifier',
            'recordType',
            'file',
            'fileUrl',
            'itemType',
            'collection',
            'public',
            'featured',
            'tags',
        );
        $requiredColumns = array(
            'recordIdentifier',
        );
        $forbiddenColumns = array(
            'sourceItemId',
            'Action',
            'Identifier',
            'IdentifierField',
        );
        $this->_checkCsv($skipColumns, $requiredColumns, $forbiddenColumns);
    }

    /**
     * For direct import of a file from Omeka.net or with mixed records.
     * Check if all needed Elements are present.
     */
    protected function _checkCsv(
        array $skipColumns = array(),
        array $requiredColumns = array(),
        array $forbiddenColumns = array()
    ) {
        if (empty($this->session->columnNames)) {
            $this->_helper->redirector->goto('index');
        }

        $elementTable = get_db()->getTable('Element');

        $skipColumnsWrapped = array();
        foreach ($skipColumns as $skipColumn) {
            $skipColumnsWrapped[] = "'" . $skipColumn . "'";
        }
        $skipColumnsText = '( ' . implode(', ', $skipColumnsWrapped) . ' )';

        // For the formats Mix and Update, no check of element names is done if
        // there are extra data or if they should be ignored.
        $hasError = false;
        if (!(in_array($this->session->format, array('Manage', 'Mix', 'Update')) && $this->session->containsExtraData != 'no')) {
            foreach ($this->session->columnNames as $columnName) {
                if (!in_array($columnName, $skipColumns) && !in_array($columnName, $requiredColumns)) {
                    $data = explode(':', $columnName);
                    if (count($data) != 2) {
                        $msg = __('Invalid column name: "%s".', $columnName)
                            . ' ' . __('Column names must either be one of the following %s, or have the following format: {ElementSetName}:{ElementName}.', $skipColumnsText);
                        $this->_helper->flashMessenger($msg, 'error');
                        $hasError = true;
                        break;
                    }
                }
            }

            if (!$hasError) {
                foreach ($this->session->columnNames as $columnName) {
                    if (!in_array($columnName, $skipColumns) && !in_array($columnName, $requiredColumns)) {
                        $data = explode(':', $columnName);
                        // $data is like array('Element Set Name', 'Element Name');
                        $elementSetName = $data[0];
                        $elementName = $data[1];
                        $element = $elementTable->findByElementSetNameAndElementName($elementSetName, $elementName);
                        if (empty($element)) {
                            $msg = __('Element "%s" is not found in element set "%s".', $elementName, $elementSetName);
                            $this->_helper->flashMessenger($msg, 'error');
                            $hasError = true;
                        }
                    }
                }
            }
        }

        // Check required columns.
        foreach ($this->session->columnNames as $columnName) {
            $required = array_search($columnName, $requiredColumns);
            if ($required !== false) {
                unset($requiredColumns[$required]);
            }
        }
        if (!empty($requiredColumns)) {
            $msg = __('Columns "%s" are required with the format "%s".', implode('", "', $requiredColumns), $this->session->format);
            $this->_helper->flashMessenger($msg, 'error');
            $hasError = true;
        }

        // Check forbidden columns.
        $forbiddenColumnsCheck = array();
        foreach ($this->session->columnNames as $columnName) {
            $forbidden = array_search($columnName, $forbiddenColumns);
            if ($forbidden !== false) {
                $forbiddenColumnsCheck[] = $columnName;
            }
        }
        if (!empty($forbiddenColumnsCheck)) {
            $msg = __('Columns "%s" are forbidden with the format "%s".', implode('", "', $forbiddenColumnsCheck), $this->session->format);
            $this->_helper->flashMessenger($msg, 'error');
            $hasError = true;
        }

        // Special check for Manage format : the column from the IdentfierField
        // is required when there is no IdentifierField column (else the check
        // is done during import).
        if ($this->session->format == 'Manage') {
            if (!in_array('Identifier', $this->session->columnNames)
                    && !in_array('IdentifierField', $this->session->columnNames)
                ) {
                $identifierField = $this->session->identifierField;
                if (empty($identifierField)) {
                    $msg = __('There is no "IdentifierField" column or a default identifier field.', $this->session->identifierField);
                    $this->_helper->flashMessenger($msg, 'error');
                    $hasError = true;
                }
                elseif ($identifierField != 'internal id') {
                    $elementField = $identifierField;
                    if (is_numeric($identifierField)) {
                        $element = get_record_by_id('Element', $identifierField);
                        if (!$element) {
                            $msg = __('The identifier field "%s" does not exist.', $this->session->identifierField);
                            $this->_helper->flashMessenger($msg, 'error');
                            $hasError = true;
                        }
                        else {
                            $elementField = $element->set_name . ':' . $element->name;
                        }
                    }
                    if (!in_array($elementField, $this->session->columnNames)) {
                        $msg = __('There is no "IdentifierField" column or the default "%s" column.', $elementField);
                        $this->_helper->flashMessenger($msg, 'error');
                        $hasError = true;
                    }
                }
            }
        }

        if ($hasError) {
            $msg = __('The file has error with format "%s", or parameters are not adapted to it. Check them.', $this->session->format);
            $this->_helper->flashMessenger($msg, 'info');
            $this->_helper->redirector->goto('index');
        }

        $this->_helper->redirector->goto('omeka-csv', 'index', 'csv-import');
    }

    /**
     * Create and queue a new import from Omeka.net or with mixed records.
     */
    public function omekaCsvAction()
    {
        $format = $this->session->format;
        // Specify the export format's file and tag delimiters.
        switch ($format) {
            case 'Manage':
                $elementDelimiter = $this->session->elementDelimiter;
                $tagDelimiter = $this->session->tagDelimiter;
                $fileDelimiter = $this->session->fileDelimiter;
                $itemTypeId = $this->session->itemTypeId;
                $collectionId = $this->session->collectionId;
                $isPublic = $this->session->recordsArePublic;
                $isFeatured = $this->session->recordsAreFeatured;
                $isHtml = $this->session->elementsAreHtml;
                $identifierField = $this->session->identifierField;
                $createCollections = true;
                $containsExtraData = $this->session->containsExtraData;
                if ($containsExtraData == 'manual') {
                    $containsExtraData = 'no';
                }
                break;
            case 'Report':
                // Do not allow the user to specify it.
                $tagDelimiter = ',';
                $fileDelimiter = ',';
                $itemTypeId = null;
                $collectionId = null;
                $action = null;
                $identifierField = null;
                $isPublic = null;
                $isFeatured = null;
                // Nevertheless, user can choose to import all elements as html
                // or as raw text.
                $isHtml = (boolean) $this->session->elementsAreHtml;
                $createCollections = false;
                $containsExtraData = 'no';
                break;
            // Deprecated.
            case 'Mix':
            case 'Update':
                $elementDelimiter = $this->session->elementDelimiter;
                $tagDelimiter = $this->session->tagDelimiter;
                $fileDelimiter = $this->session->fileDelimiter;
                $itemTypeId = $this->session->itemTypeId;
                $collectionId = $this->session->collectionId;
                $isPublic = $this->session->recordsArePublic;
                $isFeatured = $this->session->recordsAreFeatured;
                $isHtml = $this->session->elementsAreHtml;
                $action = null;
                $identifierField = null;
                $createCollections = $this->session->createCollections;
                $containsExtraData = $this->session->containsExtraData;
                if ($containsExtraData == 'manual') {
                    $containsExtraData = 'no';
                }
                break;
            default:
                $this->_helper->flashMessenger(__('Invalid call.'), 'error');
                $this->_helper->redirector->goto('index');
        }

        $headings = $this->session->columnNames;
        $columnMaps = array();
        $isSetIdentifier = false;
        foreach ($headings as $heading) {
            switch ($heading) {
                case 'Identifier':
                    $columnMaps[] = new CsvImport_ColumnMap_Identifier($heading);
                    $isSetIdentifier = true;
                    break;
                // Deprecated.
                case 'sourceItemId':
                    $columnMaps[] = new CsvImport_ColumnMap_SourceItemId($heading);
                    break;
                // Deprecated.
                case 'updateMode':
                    $columnMaps[] = new CsvImport_ColumnMap_UpdateMode($heading);
                    break;
                case 'Action':
                    $columnMaps[] = new CsvImport_ColumnMap_Action($heading, $action);
                    break;
                case 'IdentifierField':
                    $columnMaps[] = new CsvImport_ColumnMap_IdentifierField($heading, $identifierField);
                    break;
                // Deprecated.
                case 'updateIdentifier':
                    $columnMaps[] = new CsvImport_ColumnMap_UpdateIdentifier($heading);
                    break;
                case 'RecordType':
                // Deprecated.
                case 'recordType':
                    $columnMaps[] = new CsvImport_ColumnMap_RecordType($heading);
                    break;
                // Deprecated.
                case 'recordIdentifier':
                    $columnMaps[] = new CsvImport_ColumnMap_RecordIdentifier($heading);
                    break;
                case 'ItemType':
                // Used by Csv Report.
                case 'itemType':
                    $columnMaps[] = new CsvImport_ColumnMap_ItemType($heading, $itemTypeId);
                    break;
                case 'Item':
                    $columnMaps[] = new CsvImport_ColumnMap_Item($heading);
                    break;
                case 'Collection':
                // Used by Csv Report, Mixed and Update.
                case 'collection':
                    $columnMaps[] = new CsvImport_ColumnMap_Collection($heading,
                        $collectionId,
                        $createCollections,
                        $format != 'Manage');
                    break;
                case 'Public':
                // Used by Csv Report.
                case 'public':
                    $columnMaps[] = new CsvImport_ColumnMap_Public($heading, $isPublic);
                    break;
                case 'Featured':
                // Used by Csv Report.
                case 'featured':
                    $columnMaps[] = new CsvImport_ColumnMap_Featured($heading, $isFeatured);
                    break;
                case 'Tags':
                // Used by Csv Report.
                case 'tags':
                    $columnMaps[] = new CsvImport_ColumnMap_Tag($heading, $tagDelimiter);
                    break;
                // Deprecated.
                case 'fileUrl':
                    $columnMaps[] = new CsvImport_ColumnMap_File($heading, '', true);
                    break;
                case 'File':
                // Used by Csv Report.
                case 'file':
                    $columnMaps[] = new CsvImport_ColumnMap_File($heading, $fileDelimiter);
                    break;
                // Default can be a normal element or, if not, an extra data
                // element that can be added via the hook csv_import_extra_data.
                // This doesn't work with "Report" format.
                default:
                    switch ($format) {
                        case 'Report':
                            $columnMap = new CsvImport_ColumnMap_ExportedElement($heading);
                            $options = array(
                                'columnNameDelimiter' => $columnMap::DEFAULT_COLUMN_NAME_DELIMITER,
                                'elementDelimiter' => $elementMap::DEFAULT_ELEMENT_DELIMITER,
                                'isHtml' => $isHtml,
                            );
                            break;
                        case 'Manage':
                        // Deprecated.
                        case 'Mix':
                        case 'Update':
                            $columnMap = new CsvImport_ColumnMap_MixElement($heading, $elementDelimiter);
                            // If extra data are not used or if this is an element.
                            if ($containsExtraData != 'yes' || $columnMap->getElementId()) {
                                $options = array(
                                    'columnNameDelimiter' => $columnMap::DEFAULT_COLUMN_NAME_DELIMITER,
                                    'elementDelimiter' => $elementDelimiter,
                                    'isHtml' => $isHtml,
                                );
                            }
                            // Allow extra data when this is not a true element.
                            else {
                                $columnMap = new CsvImport_ColumnMap_ExtraData($heading, $elementDelimiter);
                                $options = array(
                                    'columnNameDelimiter' => $columnMap::DEFAULT_COLUMN_NAME_DELIMITER,
                                    'elementDelimiter' => $elementDelimiter,
                                );
                            }

                            // Memorize the identifier if needed, after cleaning.
                            if ($format == 'Manage' && $isSetIdentifier === false) {
                                $cleanHeading = explode(
                                    CsvImport_ColumnMap_MixElement::DEFAULT_COLUMN_NAME_DELIMITER,
                                    $heading);
                                $cleanHeading = implode(CsvImport_ColumnMap_MixElement::DEFAULT_COLUMN_NAME_DELIMITER,
                                    array_map('trim', $cleanHeading));
                                if ($identifierField == $cleanHeading) {
                                    $isSetIdentifier = null;
                                    $identifierHeading = $heading;
                                }
                            }
                            break;
                    }
                    $columnMap->setOptions($options);
                    $columnMaps[] = $columnMap;
                    break;
            }
        }

        // Manage requires et special check;
        if ($format == 'Manage') {
            // Manage format require that a column for identifier, but this
            // canbe any column, specially Dublin Core:Identifier.
            if ($isSetIdentifier === null) {
                $columnMaps[] = new CsvImport_ColumnMap_Identifier($identifierHeading);
                $isSetIdentifier = true;
            }
            if (!$isSetIdentifier) {
                $msg = __('There is no "Identifier" or identifier field "%s" column.', $identifierField);
                $this->_helper->flashMessenger($msg, 'error');
                $this->_helper->redirector->goto('index');
            }
        }

        $this->session->columnMaps = $columnMaps;

        $this->_launchImport();
    }

    /**
     * Set default values in the case where a needed column doesn't exist.
     *
     * This doesn't include default values that are set directly in column maps
     * and that are useless without them (i.e. isHtml is set with Element).
     */
    protected function _setDefaultValues()
    {
        $defaultValues = array();
        $defaultValues[CsvImport_ColumnMap::TYPE_ITEM_TYPE] = $this->session->itemTypeId;
        $defaultValues[CsvImport_ColumnMap::TYPE_COLLECTION] = $this->session->collectionId;
        $defaultValues[CsvImport_ColumnMap::TYPE_PUBLIC] = $this->session->recordsArePublic;
        $defaultValues[CsvImport_ColumnMap::TYPE_FEATURED] = $this->session->recordsAreFeatured;
        switch ($this->session->format) {
            case 'Manage':
                $defaultValues[CsvImport_ColumnMap::TYPE_ACTION] = $this->session->action;
                $defaultValues[CsvImport_ColumnMap::TYPE_IDENTIFIER_FIELD] = $this->session->identifierField;
                $defaultValues['createCollections'] = true;
                break;
            case 'Report':
                $defaultValues['createCollections'] = false;
                break;
            case 'Mix':
            case 'Update':
                $defaultValues['createCollections'] = $this->session->createCollections;
                break;
        }

        $this->session->defaultValues = $defaultValues;
    }

    /**
     * Save the format in base and launch the job.
     */
    protected function _launchImport()
    {
        $csvImport = new CsvImport_Import();

        $this->_setDefaultValues();

        // This is the clever way that mapColumns action sets the values passed
        // along from indexAction. Many will be irrelevant here, since CsvImport
        // allows variable itemTypes and Collection

        // @TODO: check if variable itemTypes and Collections breaks undo. It probably should, actually
        foreach ($this->session->getIterator() as $key => $value) {
            $setMethod = 'set' . ucwords($key);
            if (method_exists($csvImport, $setMethod)) {
                $csvImport->$setMethod($value);
            }
        }

        if ($csvImport->queue()) {
            $this->_dispatchImportTask($csvImport, CsvImport_ImportTask::METHOD_START);
            $this->_helper->flashMessenger(__('Import started. Reload this page for status updates.'), 'success');
        }
        else {
            $this->_helper->flashMessenger(__('Import could not be started. Please check error logs for more details.'), 'error');
        }
        $this->session->unsetAll();
        $this->_helper->redirector->goto('browse');
    }

    /**
     * Browse the imports.
     */
    public function browseAction()
    {
        if (!$this->_getParam('sort_field')) {
            $this->_setParam('sort_field', 'added');
            $this->_setParam('sort_dir', 'd');
        }
        parent::browseAction();
    }

    /**
     * Undo the import.
     */
    public function undoImportAction()
    {
        $csvImport = $this->_helper->db->findById();
        if ($csvImport->queueUndo()) {
            $this->_dispatchImportTask($csvImport, CsvImport_ImportTask::METHOD_UNDO);
            $this->_helper->flashMessenger(__('Undo import started. Reload this page for status updates.'), 'success');
        } else {
            $this->_helper->flashMessenger(__('Undo import could not be started. Please check error logs for more details.'), 'error');
        }

        $this->_helper->redirector->goto('browse');
    }

    /**
     * Clear the import history.
     */
    public function clearHistoryAction()
    {
        $csvImport = $this->_helper->db->findById();
        $importedRecordCount = $csvImport->getImportedRecordCount();

        if ($csvImport->isUndone()
            || $csvImport->isUndoImportError()
            || $csvImport->isOtherError()
            || ($csvImport->isImportError() && $importedRecordCount == 0)) {
            $csvImport->delete();
            $this->_helper->flashMessenger(__('Cleared import from the history.'), 'success');
        } else {
            $this->_helper->flashMessenger(__('An error occurs during import: Cannot clear import history.'), 'error');
        }
        $this->_helper->redirector->goto('browse');
    }

    /**
     * Get the main Csv Import form.
     *
     * @return CsvImport_Form_Main
     */
    protected function _getMainForm()
    {
        require_once CSV_IMPORT_DIRECTORY . '/forms/Main.php';
        $csvConfig = $this->_getPluginConfig();
        $form = new CsvImport_Form_Main($csvConfig);
        return $form;
    }

    /**
     * Returns the plugin configuration.
     *
     * @return array
     */
    protected function _getPluginConfig()
    {
        if (!$this->_pluginConfig) {
            $config = $this->getInvokeArg('bootstrap')->config->plugins;
            if ($config && isset($config->CsvImport)) {
                $this->_pluginConfig = $config->CsvImport->toArray();
            }
            if (!array_key_exists('fileDestination', $this->_pluginConfig)) {
                $this->_pluginConfig['fileDestination'] =
                    Zend_Registry::get('storage')->getTempDir();
            }
        }
        return $this->_pluginConfig;
    }

    /**
     * Convert Identifier field to name.
     *
     * It's simpler to manage identifiers by name, as they are in csv files.
     *
     * @param integer|string $postIdentifier
     * @return string
     */
    protected function _elementNameFromPost($postIdentifier)
    {
        $postIdentifier = trim($postIdentifier);
        if (empty($postIdentifier) || !is_numeric($postIdentifier)) {
            return $postIdentifier;
        }
        $element = get_record_by_id('Element', $postIdentifier);
        if (!$element) {
            return $postIdentifier;
        }
        return $element->set_name . ':' . $element->name;
    }

    /**
     * Returns whether the session is valid.
     *
     * @return boolean
     */
    protected function _sessionIsValid()
    {
        $requiredKeys = array(
            'format',
            'itemTypeId',
            'collectionId',
            'createCollections',
            'recordsArePublic',
            'recordsAreFeatured',
            'elementsAreHtml',
            'containsExtraData',
            'ownerId',
        );
        foreach ($requiredKeys as $key) {
            if (!isset($this->session->$key)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Dispatch an import task.
     *
     * @param CsvImport_Import $csvImport The import object
     * @param string $method The method name to run in the CsvImport_Import object
     */
    protected function _dispatchImportTask($csvImport, $method = null)
    {
        if ($method === null) {
            $method = CsvImport_ImportTask::METHOD_START;
        }
        $csvConfig = $this->_getPluginConfig();

        $options = array(
            'importId' => $csvImport->id,
            'memoryLimit' => @$csvConfig['memoryLimit'],
            'batchSize' => @$csvConfig['batchSize'],
            'method' => $method,
        );

        $jobDispatcher = Zend_Registry::get('job_dispatcher');
        $jobDispatcher->setQueueName(CsvImport_ImportTask::QUEUE_NAME);
        try {
            $jobDispatcher->sendLongRunning('CsvImport_ImportTask', $options);
        } catch (Exception $e) {
            $csvImport->setStatus(CsvImport_Import::STATUS_OTHER_ERROR);
            $csvImport->save();
            throw $e;
        }
    }

    /**
     * Return the list of standard delimiters.
     *
     * @return array The list of standard delimiters.
     */
    public static function getDelimitersList()
    {
        return array(
            'comma' => ',',
            'semi-colon' => ';',
            'pipe' => '|',
            'tabulation' => "\t",
            'carriage return' => "\r",
            'space' => ' ',
            'double space' => '  ',
            'empty' => '',
        );
    }

    /**
     * Return the list of standard enclosures.
     *
     * @return array The list of standard enclosures.
     */
    public static function getEnclosuresList()
    {
        return array(
            'double-quote' => '"',
            'quote' => "'",
            'empty' => '',
        );
    }
}
