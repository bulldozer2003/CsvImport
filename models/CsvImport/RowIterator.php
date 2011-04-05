<?php
/**
 * CsvImport_RowIterator class
 *
 * @copyright  Center for History and New Media, 2008-2011
 * @license    http://www.gnu.org/licenses/gpl-3.0.txt
 * @version    $Id:$
 **/
class CsvImport_RowIterator implements Iterator
{
    private $_filePath;
    private $_handle;

    private $_currentRow;
    private $_currentRowNumber;
    private $_delimiter = ',';
    private $_valid = true;
    private $_colNames = array();
    private $_colCount = 0;

    /**
     * @param string $filePath
     */
    public function __construct($filePath, $delimiter = null) 
    {
        $this->_filePath = $filePath;
        if ($delimiter) {
            $this->_delimiter = $delimiter;
        }
    }
    
    /**
     * Rewind the Iterator to the first element.
     * Similar to the reset() function for arrays in PHP
     * @return void
     */
    function rewind()
    {
        if ($this->_handle) {
            fclose($this->_handle);
            $this->_handle = null;
        }
        $this->_currentRowNumber = 0;
        $this->_valid = true;
        // First row should always be the header.
        $this->_colNames = $this->_getNextRow();
        $this->_colCount = count($this->_colNames);
        $this->_currentRow = $this->_formatRow($this->_colNames);
    }

    /**
     * Return the current element.
     * Similar to the current() function for arrays in PHP
     * @return mixed current element from the collection
     */
    function current()
    {
        return $this->_currentRow;
    }

    /**
     * Return the identifying key of the current element.
     * Similar to the key() function for arrays in PHP
     * @return mixed either an integer or a string
     */
    function key()
    {
        return $this->_currentRowNumber;
    }

    /**
     * Move forward to next element.
     * Similar to the next() function for arrays in PHP
     * @return void
     */
    function next()
    {
        if ($nextRow = $this->_getNextRow()) {
            $this->_currentRow = $this->_formatRow($nextRow);
        } else {
            $this->_currentRow = array();
        }
        $this->_currentRowNumber++;
        
        if (!$this->_currentRow) {
            fclose($this->_handle);
            $this->_valid = false;
            $this->_handle = null;
        }
    }

    function valid()
    {
        if (!file_exists($this->_filePath)) {
            return false;
        }

        if (!$this->_getFileHandle()) {
            return false;
        }
        return $this->_valid;
    }

    public function getColumnNames()
    {
        if (!$this->_colNames) {
            $this->rewind();
        }
        return $this->_colNames;
    }

    private function _formatRow($row)
    {
        $formattedRow = array();
        if (!isset($this->_colNames)) {
            throw new LogicException("Row cannot be formatted until the column "
                . "names have been set.");
        }
        if (count($row) != $this->_colCount) {
            $printable = var_export($row, true);
            throw new CsvImport_MissingColumnException("Row containing "
                . "$printable does not have the required {$this->_colCount} "
                . "rows.");
        }
        for($i = 0; $i < $this->_colCount; $i++) 
        {
            $formattedRow[$this->_colNames[$i]] = $row[$i];
        }
        return $formattedRow;
    }

    private function _getFileHandle()
    {
        if (!$this->_handle) {
            ini_set('auto_detect_line_endings', true);
            $this->_handle = fopen($this->_filePath, 'r');
        }
        return $this->_handle;
    }

    private function _getNextRow()
    {
        $currentRow = array();
        $handle = $this->_getFileHandle();
        while (($row = fgetcsv($handle, 0, $this->_delimiter)) !== FALSE) {
            return $row;
        }
    }

}