<?php
/**
 * CsvImport_ColumnMap_UpdateMode class
 *
 * @package CsvImport
 */
class CsvImport_ColumnMap_UpdateMode extends CsvImport_ColumnMap
{
    const MODE_UPDATE = 'Update';
    const MODE_ADD = 'Add';
    const MODE_REPLACE = 'Replace';

    const DEFAULT_UPDATE_MODE = self::MODE_UPDATE;

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
        $result = $row[$this->_columnName];
        $result = ucfirst(strtolower($result));
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
