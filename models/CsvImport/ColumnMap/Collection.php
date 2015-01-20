<?php
/**
 * CsvImport_ColumnMap_Collection class
 *
 * @copyright Copyright 2007-2012 Roy Rosenzweig Center for History and New Media
 * @license http://www.gnu.org/licenses/gpl-3.0.txt GNU GPLv3
 * @package CsvImport
 */
class CsvImport_ColumnMap_Collection extends CsvImport_ColumnMap
{
    private $_collectionId;

    /**
     * @internal Due to the nature of Csv Import, designed to import items, the
     * creation of collections, if allowed, should be made here. The new
     * collection is not removed if an error occurs during import of the item.
     */
    private $_createCollection;

    /**
     * Allow to use the direct mode: determine the id here directly via the id
     * or the Dublin Core Title. Used by Csv Report, Mix and Update  formats.
     */
    private $_direct;

    /**
     * @param string $columnName
     * @param integer $collectionId
     * @param boolean $createCollection
     * @param boolean $direct
     */
    public function __construct($columnName, $collectionId = null, $createCollection = false, $direct = false)
    {
        parent::__construct($columnName);
        $this->_type = CsvImport_ColumnMap::TYPE_COLLECTION;
        $this->_collectionId = $collectionId;
        $this->_createCollection = (boolean) $createCollection;
        $this->_direct = $direct;
    }

    /**
     * Map a row to an array that can be parsed by insert_item() or
     * insert_files_for_item().
     *
     * @param array $row The row to map
     * @param array $result
     * @return array The result
     */
    public function map($row, $result)
    {
        $collectionIdentifier = trim($row[$this->_columnName]);
        // In "Manage" format, collection is determined at row level, according
        // to field of the identifier, so only content of the cell is returned.
        if (!$this->_direct) {
            if (empty($collectionIdentifier) && !empty($this->_collectionId)) {
                $collectionIdentifier = $this->_collectionId;
            }
            return $collectionIdentifier;
        }

        $result = null;
        if ($collectionIdentifier !== '') {
            if (is_numeric($collectionIdentifier) && (integer) $collectionIdentifier > 0) {
                $collection = get_record_by_id('Collection', $collectionIdentifier);
            }
            if (empty($collection)) {
                $collection = $this->_getCollectionByTitle($collectionIdentifier);
            }
            if (empty($collection) && $this->_createCollection) {
                $collection = $this->_createCollectionFromTitle($collectionIdentifier);
            }
            if ($collection) {
                $result = $collection->id;
            }
        }
        else {
            $result = $this->_collectionId;
        }
        return $result;
    }

    /**
     * Return the collection id.
     *
     * @return string The collectionId
     */
    public function getCollectionId()
    {
        return $this->_collectionId;
    }

    /**
     * Return the create collection.
     *
     * @return string The create collection
     */
    public function getCreateCollection()
    {
        return $this->_createCollection;
    }

    /**
     * Return the direct.
     *
     * @return string The direct
     */
    public function getDirect()
    {
        return $this->_direct;
    }

    /**
     * Return a collection by its title.
     *
     * @param string $name The collection name
     * @return Collection The collection
     */
    protected function _getCollectionByTitle($name)
    {
        $db = get_db();

        $elementTable = $db->getTable('Element');
        $element = $elementTable->findByElementSetNameAndElementName('Dublin Core', 'Title');

        $collectionTable = $db->getTable('Collection');
        $select = $collectionTable->getSelect();
        $select->joinInner(array('s' => $db->ElementText),
                           's.record_id = collections.id', array());
        $select->where("s.record_type = 'Collection'");
        $select->where("s.element_id = ?", $element->id);
        $select->where("s.text = ?", $name);

        $collection = $collectionTable->fetchObject($select);
        if (!$collection && !$this->_createCollection) {
            _log("Collection not found. Collections must be created with identical names prior to import", Zend_Log::NOTICE);
            return false;
        }
        return $collection;
    }

    /**
     * Create a new collection from a simple raw title.
     *
     * @param string $title
     * @return Collection
     */
    private function _createCollectionFromTitle($title)
    {
        $collection = new Collection;
        $collection->save();
        update_collection($collection, array(), array(
            'Dublin Core' => array(
                'Title' => array(
                    array('text' => $title, 'html' => false),
        ))));
        return $collection;
    }
}
