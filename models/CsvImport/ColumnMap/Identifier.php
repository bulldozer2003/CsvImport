<?php
/**
 * CsvImport_ColumnMap_Identifier class
 *
 * @package CsvImport
 */
class CsvImport_ColumnMap_Identifier extends CsvImport_ColumnMap
{
    /**
     * @param string $columnName
     */
    public function __construct($columnName)
    {
        parent::__construct($columnName);
        $this->_type = CsvImport_ColumnMap::TYPE_IDENTIFIER;
    }

    /**
     * Map a row to the identifier of a record.
     *
     * @param array $row The row to map
     * @param array $result
     * @return string Identifier of the record.
     */
    public function map($row, $result)
    {
        $result = trim($row[$this->_columnName]);
        return $result;
    }
}
