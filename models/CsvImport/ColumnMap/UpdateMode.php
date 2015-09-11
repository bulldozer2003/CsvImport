<?php
/**
 * CsvImport_ColumnMap_UpdateMode class
 *
 * @deprecated Since 2.1.1-full.
 *
 * @package CsvImport
 */
class CsvImport_ColumnMap_UpdateMode extends CsvImport_ColumnMap
{
    const MODE_UPDATE = 'Update';
    const MODE_ADD = 'Add';
    const MODE_REPLACE = 'Replace';

    const DEFAULT_UPDATE_MODE = 'Update';

    /**
     * @param string $columnName
     */
    public function __construct($columnName)
    {
        parent::__construct($columnName);
        $this->_type = CsvImport_ColumnMap::TYPE_UPDATE_MODE;
    }

    /**
     * Map a row to the update mode for a record.
     *
     * @param array $row The row to map
     * @param array $result
     * @return string|boolean Update mode for a record.
     */
    public function map($row, $result)
    {
        $result = ucfirst(strtolower(trim($row[$this->_columnName])));
        if (empty($result)) {
            $result = self::DEFAULT_UPDATE_MODE;
        }
        elseif (!in_array($result, array(
                self::MODE_UPDATE,
                self::MODE_ADD,
                self::MODE_REPLACE,
            ))) {
            $result = false;
        }
        return $result;
    }
}
