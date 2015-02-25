<?php
/**
 * CsvImport_Form_Mapping class - represents the form on csv-import/index/map-columns.
 *
 * @copyright Copyright 2007-2012 Roy Rosenzweig Center for History and New Media
 * @license http://www.gnu.org/licenses/gpl-3.0.txt GNU GPLv3
 * @package CsvImport
 */

class CsvImport_Form_Mapping extends Omeka_Form
{
    // Internal parameters for all formats.
    private $_format;
    private $_columnNames = array();
    private $_columnExamples = array();
    private $_elementDelimiter;
    private $_tagDelimiter;
    private $_fileDelimiter;
    private $_automapColumns;
    // Parameters for all formats.
    private $_itemTypeId;
    // Parameteres for "Manage".
    private $_action;
    private $_identifierField;
    // Parameteres for "Manage", "Mix" and "Update".
    private $_collectionId;
    private $_isPublic;
    private $_isFeatured;
    private $_elementsAreHtml;
    private $_createCollections;

    /**
     * Initialize the form.
     */
    public function init()
    {
        parent::init();
        $this->setAttrib('id', 'csvimport-mapping');
        $this->setMethod('post');

        // Prepare elements for each format.
        switch ($this->_format) {
            case 'Manage':
                $elementsByElementSetName = $this->_getElementPairs(true);
                $special = label_table_options(array(
                    'Tags' => 'Tags',
                    'Collection' => 'Collection (for item)',
                    'Item' => 'Item (for file)',
                    'File' => 'Files',
                    'Action' => 'Action',
                    'RecordType' => 'Record type',
                    'itemType' => 'Item type',
                    'IdentifierField' => 'Identifier field',
                    'Identifier' => 'Identifier',
                ));
                break;
            case 'Report':
                return false;
            case 'Item':
                $elementsByElementSetName = $this->_getElementPairs($this->_itemTypeId);
                break;
            case 'File':
                $elementsByElementSetName = $this->_getElementPairsForFiles();
                break;
            // Deprecated.
            case 'Mix':
                $elementsByElementSetName = $this->_getElementPairs(true);
                $special = label_table_options(array(
                    'Tags' => 'Tags',
                    'Collection' => 'Collection',
                    'fileUrl' => 'Zero or one file',
                    'File' => 'Files',
                    'RecordType' => 'Record type',
                    'ItemType' => 'Item type',
                    'Public' => 'Public',
                    'Featured' => 'Featured',
                    // Specific to "Mix" format.
                    'sourceItemId' => 'Source Item Id',
                ));
                break;
            case 'Update':
                $elementsByElementSetName = $this->_getElementPairs(true);
                $special = label_table_options(array(
                    'Tags' => 'Tags',
                    'Collection' => 'Collection',
                    'fileUrl' => 'Zero or one file',
                    'File' => 'Files',
                    'RecordType' => 'Record type',
                    'ItemType' => 'Item type',
                    'Public' => 'Public',
                    'Featured' => 'Featured',
                    // Specific to "Update" format.
                    'updateMode' => 'Update mode',
                    'updateIdentifier' => 'Update identifier',
                    'recordIdentifier' => 'Record identifier',
                ));
                break;
            default:
                return false;
        }
        $elementsByElementSetName = label_table_options($elementsByElementSetName);

        foreach ($this->_columnNames as $index => $colName) {
            $rowSubForm = new Zend_Form_SubForm();
            $selectElement = $rowSubForm->createElement('select',
                'element',
                array(
                    'class' => 'map-element',
                    'multiOptions' => $elementsByElementSetName,
                    'multiple' => false, // see ZF-8452
            ));
            $selectElement->setIsArray(true);
            if ($this->_automapColumns) {
                $selectElement->setValue($this->_getElementIdFromColumnName($colName));
            }

            $rowSubForm->addElement($selectElement);
            $rowSubForm->addElement('checkbox', 'html', array('value' => $this->_elementsAreHtml));
            // If import type is File, add checkbox for file url only because
            // files can't get tags and we just need the url.
            switch ($this->_format) {
                case 'Item':
                    $rowSubForm->addElement('checkbox', 'tags');
                    $rowSubForm->addElement('checkbox', 'file');
                    break;
                case 'File':
                    $rowSubForm->addElement('checkbox', 'file_url');
                    break;
                case 'Manage':
                // Deprecated.
                case 'Mix':
                case 'Update':
                    $specialElement = $rowSubForm->createElement('select',
                        'special',
                        array(
                            'class' => 'map-element',
                            'multiOptions' => $special,
                            'multiple' => false, // see ZF-8452
                    ));
                    // $specialElement->setIsArray(true);
                    if ($this->_automapColumns) {
                        $specialElement->setValue($this->_getSpecialValue($colName, $special));
                    }
                    $rowSubForm->addElement($specialElement);
                    $rowSubForm->addElement('checkbox', 'extra_data', array(
                        'label' => __('Extra data'),
                    ));
                    break;
            }

            $this->_setSubFormDecorators($rowSubForm);
            $this->addSubForm($rowSubForm, "row$index");
        }

        $this->addElement('submit',
            'submit',
            array(
                'label' => __('Import CSV file'),
                'class' => 'submit submit-medium',
        ));
    }

    protected function _getElementIdFromColumnName($columnName, $columnNameDelimiter = ':')
    {
        $element = $this->_getElementFromColumnName($columnName, $columnNameDelimiter);
        if ($element) {
            return $element->id;
        }
        else {
            return null;
        }
    }

    /**
     * Return the element from the column name.
     *
     * @param string $columnName The name of the column
     * @param string $columnNameDelimiter The column name delimiter
     * @return Element|null The element from the column name
     */
    protected function _getElementFromColumnName($columnName, $columnNameDelimiter = ':')
    {
        $element = null;
        // $columnNameParts is an array like array('Element Set Name', 'Element Name')
        if (strlen($columnNameDelimiter) > 0) {
            if ($columnNameParts = explode($columnNameDelimiter, $columnName)) {
                if (count($columnNameParts) == 2) {
                    $elementSetName = trim($columnNameParts[0]);
                    $elementName = trim($columnNameParts[1]);
                    $element = get_db()
                        ->getTable('Element')
                        ->findByElementSetNameAndElementName($elementSetName, $elementName);
                }
            }
        }
        return $element;
    }

    protected function _getSpecialValue($colName, $special)
    {
        $array = array_combine(array_keys($special), array_map('strtolower', array_keys($special)));
        return array_search(strtolower($colName), $array);
    }

    /**
     * Load the default decorators.
     */
    public function loadDefaultDecorators()
    {
        $this->setDecorators(array(
            array('ViewScript', array(
                'viewScript' => 'index/map-columns-form.php',
                'itemTypeId' => $this->_itemTypeId,
                'form' => $this,
                'format' => $this->_format,
                'columnExamples' => $this->_columnExamples,
                'columnNames' => $this->_columnNames,
            )),
        ));
    }

    /**
     * Set the import type.
     *
     * @param string $format The type of import
     */
    public function setFormat($format)
    {
        $this->_format = $format;
    }

    /**
     * Set the column names.
     *
     * @param array $columnNames The array of column names (which are strings)
     */
    public function setColumnNames($columnNames)
    {
        $this->_columnNames = $columnNames;
    }

    /**
     * Set the column examples.
     *
     * @param array $columnExamples The array of column examples (which are
     * strings)
     */
    public function setColumnExamples($columnExamples)
    {
        $this->_columnExamples = $columnExamples;
    }

    /**
     * Set the element delimiter.
     *
     * @param string $elementDelimiter The element delimiter
     */
    public function setElementDelimiter($elementDelimiter)
    {
        $this->_elementDelimiter = $elementDelimiter;
    }

    /**
     * Set the tag delimiter.
     *
     * @param string $tagDelimiter The tag delimiter
     */
    public function setTagDelimiter($tagDelimiter)
    {
        $this->_tagDelimiter = $tagDelimiter;
    }

    /**
     * Set the file delimiter.
     *
     * @param string $fileDelimiter The file delimiter
     */
    public function setFileDelimiter($fileDelimiter)
    {
        $this->_fileDelimiter = $fileDelimiter;
    }

    /**
     * Set whether or not to automap column names to elements.
     *
     * @param boolean $flag Whether or not to automap column names to elements
     */
    public function setAutomapColumns($flag)
    {
        $this->_automapColumns = (boolean) $flag;
    }

    /**
     * Set the item type id.
     *
     * @param int $itemTypeId The id of the item type
     */
    public function setItemTypeId($itemTypeId)
    {
        $this->_itemTypeId = $itemTypeId;
    }

    /**
     * Set the action.
     *
     * @param string $action The action
     */
    public function setAction($action)
    {
        $this->_action = $action;
    }

    /**
     * Set the identifier field.
     *
     * @param string $identifierField
     */
    public function setIdentifierField($identifierField)
    {
        $this->_identifierField = $identifierField;
    }

    /**
     * Set the collection id.
     *
     * @param string $action The collection id
     */
    public function setCollectionId($collectionId)
    {
        $this->_collectionId = $collectionId;
    }

    /**
     * Set whether or not to records are public.
     *
     * @param boolean $flag Whether or not records are public
     */
    public function setIsPublic($flag)
    {
        $this->_isPublic = (boolean) $flag;
    }

    /**
     * Set whether or not to records are featured.
     *
     * @param boolean $flag Whether or not records are featured
     */
    public function setIsFeatured($flag)
    {
        $this->_isFeatured = (boolean) $flag;
    }

    /**
     * Set whether or not elements are html.
     *
     * @internal Currently not managed
     *
     * @param boolean $flag Whether or not elements are html
     */
    public function setElementsAreHtml($flag)
    {
        $this->_elementsAreHtml = (boolean) $flag;
    }

    /**
     * Set whether or not to create collections or not.
     *
     * @param boolean $flag Whether or not to create collections
     */
    public function setCreateCollections($flag)
    {
        $this->_createCollections = (boolean) $flag;
    }

    /**
     * Returns array of column maps.
     *
     * @return array The array of column maps
     */
    public function getColumnMaps()
    {
        $columnMaps = array();
        foreach ($this->_columnNames as $key => $colName) {
            $map = $this->_getColumnMap($key, $colName);
            if ($map) {
                if (is_array($map)) {
                    $columnMaps = array_merge($columnMaps, $map);
                } else {
                    $columnMaps[] = $map;
                }
            }
        }
        return $columnMaps;
    }

    /**
     * Returns whether a subform row contains a tag mapping.
     *
     * @param int $index The subform row index
     * @return bool Whether the subform row contains a tag mapping
     */
    protected function _isTagMapped($index)
    {
        if (isset($this->getSubForm("row$index")->tags)) {
            return $this->getSubForm("row$index")->tags->isChecked();
        }
    }

    /**
     * Returns whether a subform row contains a file mapping.
     *
     * @param int $index The subform row index
     * @return bool Whether a subform row contains a file mapping
     */
    protected function _isFileMapped($index)
    {
        if (isset($this->getSubForm("row$index")->file)) {
            return $this->getSubForm("row$index")->file->isChecked();
        }
    }

    /**
     * Returns whether a subform row contains a file url.
     *
     * @param int $index The subform row index
     * @return bool Whether a subform row contains a file url
     */
    protected function _isFileUrlMapped($index)
    {
        if (isset($this->getSubForm("row$index")->file_url)) {
            return $this->getSubForm("row$index")->file_url->isChecked();
        }
    }

    /**
     * Returns whether a subform row contains an extra data mapping.
     *
     * @param int $index The subform row index
     * @return bool Whether the subform row contains an extra data mapping
     */
    protected function _isExtraDataMapped($index)
    {
        if (isset($this->getSubForm("row$index")->extra_data)) {
            return $this->getSubForm("row$index")->extra_data->isChecked();
        }
    }

    /**
     * Returns the element id mapped to the subform row.
     *
     * @param int $index The subform row index
     * @return mixed The element id mapped to the subform row
     */
    protected function _getMappedElementId($index)
    {
        return $this->_getRowValue($index, 'element');
    }

    /**
     * Returns the special value mapped to the subform row.
     *
     * @param int $index The subform row index
     * @return mixed The special value mapped to the subform row
     */
    protected function _getMappedSpecialValue($index)
    {
        if (!empty($this->getSubForm("row$index")->special)) {
            return $this->_getRowValue($index, 'special');
        }
    }

    /**
     * Returns a row element value.
     *
     * @param int $index The subform row index
     * @param string $elementName The element name in the row
     * @return mixed The row element value
     */
    protected function _getRowValue($index, $elementName)
    {
        return $this->getSubForm("row$index")->$elementName->getValue();
    }

    /**
     * Adds decorators to a subform.
     *
     * @param Zend_Form_SubForm $subForm The subform
     */
    protected function _setSubFormDecorators($subForm)
    {
        // Get rid of the fieldset tag that wraps subforms by default.
        $subForm->setDecorators(array(
            'FormElements',
        ));

        // Each subform is a row in the table.
        foreach ($subForm->getElements() as $el) {
            $el->setDecorators(array(
                array('decorator' => 'ViewHelper'),
                array(
                    'decorator' => 'HtmlTag',
                    'options' => array('tag' => 'td'),
                ),
            ));
        }
    }

    /**
     * Get the mappings from one column in the CSV file.
     *
     * Some columns can have multiple mappings; these are represented as an
     * array of maps.
     *
     * @param int $index The subform row index
     * @param string $columnName The name of the CSV file column
     * @return CsvImport_ColumnMap|array|null A ColumnMap or an array of ColumnMaps
     */
    protected function _getColumnMap($index, $columnName)
    {
        $columnMap = array();

        if ($this->_isTagMapped($index)) {
            $columnMap[] = new CsvImport_ColumnMap_Tag($columnName, $this->_tagDelimiter);
        }

        if ($this->_isFileMapped($index)) {
            $columnMap[] = new CsvImport_ColumnMap_File($columnName, $this->_fileDelimiter);
        }

        if ($this->_isFileUrlMapped($index)) {
            $columnMap[] = new CsvImport_ColumnMap_File($columnName, '', true);
        }

        if ($this->_isExtraDataMapped($index)) {
            $columnMap[] = new CsvImport_ColumnMap_ExtraData($columnName, $this->_elementDelimiter);
        }

        $elementIds = $this->_getMappedElementId($index);
        $isHtml = $this->_getRowValue($index, 'html');
        foreach($elementIds as $elementId) {
            // Make sure to skip empty mappings.
            if (!$elementId) {
                continue;
            }

            $elementMap = new CsvImport_ColumnMap_Element($columnName, $this->_elementDelimiter);
            $elementMap->setOptions(array(
                'elementId' => $elementId,
                'isHtml' => $isHtml,
            ));
            $columnMap[] = $elementMap;
        }

        $specialValue = $this->_getMappedSpecialValue($index);
        switch ($specialValue) {
            case 'Identifier':
                $columnMap[] = new CsvImport_ColumnMap_Identifier($columnName);
                break;
            // Deprecated.
            case 'sourceItemId':
                $columnMap[] = new CsvImport_ColumnMap_SourceItemId($columnName);
                break;
            // Deprecated.
            case 'updateMode':
                $columnMap[] = new CsvImport_ColumnMap_UpdateMode($columnName);
                break;
            case 'Action':
                $columnMap[] = new CsvImport_ColumnMap_Action($columnName, $this->_action);
                break;
            case 'IdentifierField':
                $columnMap[] = new CsvImport_ColumnMap_IdentifierField($columnName, $this->_identifierField);
                break;
            // Deprecated.
            case 'updateIdentifier':
                $columnMap[] = new CsvImport_ColumnMap_UpdateIdentifier($columnName);
                break;
            case 'RecordType':
                $columnMap[] = new CsvImport_ColumnMap_RecordType($columnName);
                break;
            // Deprecated.
            case 'recordIdentifier':
                $columnMap[] = new CsvImport_ColumnMap_RecordIdentifier($columnName);
                break;
            case 'ItemType':
                $columnMap[] = new CsvImport_ColumnMap_ItemType($columnName, $this->_itemTypeId);
                break;
            case 'Item':
                $columnMap[] = new CsvImport_ColumnMap_Item($columnName);
                break;
            case 'Collection':
                $columnMap[] = new CsvImport_ColumnMap_Collection($columnName,
                    $this->_collectionId,
                    $this->_createCollections,
                    $this->_format == 'Manage');
                break;
            case 'Public':
                $columnMap[] = new CsvImport_ColumnMap_Public($columnName, $this->_isPublic);
                break;
            case 'Featured':
                $columnMap[] = new CsvImport_ColumnMap_Featured($columnName, $this->_isFeatured);
                break;
            // Deprecated.
            case 'fileUrl':
                $columnMap[] = new CsvImport_ColumnMap_File($columnName, '', true);
                break;
            case 'File':
                $columnMap[] = new CsvImport_ColumnMap_File($columnName, $this->_fileDelimiter);
                break;
            case 'Tags':
                $columnMap[] = new CsvImport_ColumnMap_Tag($columnName, $this->_tagDelimiter);
                break;
        }

        return $columnMap;
    }

    /**
     * Returns element selection array for an item type or Dublin Core.
     * This is used for selecting elements in form dropdowns.
     *
     * @param int|null|boolean $itemTypeId The id of the item type. If null,
     * then it only includes Dublin Core elements. If true, it includes all
     * existing elements.
     * @return array
     */
    protected function _getElementPairs($itemTypeId = null)
    {
        if ($itemTypeId === true) {
            $params = array();
        }
        elseif (empty($itemTypeId)) {
            $params = array('item_type_id' => $itemTypeId);
        }
        else {
            $params = array('exclude_item_type' => true);
        }
        return get_db()->getTable('Element')->findPairsForSelectForm($params);
    }

    /**
     * Returns element selection array for a file.
     * This is used for selecting elements in form dropdowns.
     *
     * @param string|null $recordType The type of record to import.
     * If null, then it only includes Dublin Core elements.
     * @return array
     */
    protected function _getElementPairsForFiles($recordType = null)
    {
        $params = $recordType
            ? array('record_types' => array($recordType))
            : array('record_types' => array('All', 'File'));
        return get_db()->getTable('Element')->findPairsForSelectForm($params);
    }
}
