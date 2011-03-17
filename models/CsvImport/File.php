<?php
/**
 * CsvImport_File class
 *
 * @copyright  Center for History and New Media, 2008-2011
 * @license    http://www.gnu.org/licenses/gpl-3.0.txt
 * @version    $Id:$
 **/

/**
 * CsvImport_File - represents a csv file
 * 
 * @package CsvImport
 * @author CHNM
 **/
class CsvImport_File 
{

    private $_filePath;
    private $_lineCount = 0;
    private $_isValid;

    private $_rowCount = 0;
    private $_columnCount = 0;
    private $_columnNames = array();
    private $_columnExamples = array();
    private $_delimiter;

    private $_rowIterator;

    /**
     * Gets an array of CsvImport_File objects from the plugin directory
     * 
     * @internal Remove this.
     * @return array
     */
    public static function getFiles() 
    {
        $filenames = array();
        $paths = new DirectoryIterator(CSV_IMPORT_CSV_FILES_DIRECTORY);
        foreach ($paths as $file) {
            if (!$file->isDot() && !$file->isDir()) {
                if (strrchr($file, '.') == '.csv') {
                    $filenames[] = $file->getFilename();                    
                }
            }
        }

        // sort the files by filenames
        natsort($filenames); 

        // create CsvImport_File objects for each filename
        $csvFiles = array();
        foreach ($filenames as $filename) {
            $csvFile = new CsvImport_File(CSV_IMPORT_CSV_FILES_DIRECTORY . '/' 
                . $filename);
            $csvFiles[] = $csvFile;
        }
        return $csvFiles;
    }

    /**
     * @param string $filePath Absolute path to the file.
     * @param string|null $delimiter Optional Column delimiter for the CSV file.
     */
    public function __construct($filePath, $delimiter = null) 
    {
        $this->_filePath = $filePath;
        if ($delimiter) {
            $this->_delimiter = $delimiter;
        }
    }

    /**
     * Absolute path to the file.
     * 
     * @return string
     */
    public function getFilePath() 
    {
        return $this->_filePath;
    }

    /**
     * Get an array of headers for the column names
     * 
     * @return array
     */
    public function getColumnNames() 
    {
        if (!$this->_columnNames) {
            throw new LogicException("CSV file must be validated before "
                . "retrieving the list of columns.");
        }
        return $this->_columnNames;    
    }

    /**
     * Get an array of example data for the columns.
     * 
     * @return array Examples have the same order as the column names.
     */
    public function getColumnExamples() 
    {
        if (!$this->_columnExamples) {
            throw new LogicException("CSV file must be validated before "
                . "retrieving list of column examples.");
        }
        return $this->_columnExamples;    
    }

    /**
     * Get the number of columns in the csv file,
     * assuming that the header row has the same number of columns as all other 
     * rows
     * 
     * @return int
     */
    public function getColumnCount() 
    {
        if (!$this->_columnExamples) {
            throw new LogicException("CSV file must be validated before "
                . "retrieving column count.");
        }
        return $this->_columnCount;
    }

    /**
     * Get the number of rows for the csv file, excluding the header row
     * 
     * @return int
     */
    public function getRowCount() 
    {
        if (!$this->_columnExamples) {
            throw new LogicException("CSV file must be validated before "
                . "retrieving row count.");
        }
        return $this->_rowCount;
    }

    /**
     * Get row iterator.
     * 
     * @return array   if valid csv file, returns an iterator of rows, where 
     * each row is an associative array keyed with the column names, else 
     * returns an empty array
     */
    public function getRowIterator()
    {
        if (!$this->_rowIterator) {
            $this->_rowIterator = new CsvImport_Rows(
                $this->getFilePath(), $this->_delimiter);
        }
        return $this->_rowIterator;
    }

    /**
     * Test whether the csv file has the correct format
     * 
     * CSV files should have:
     *    The first row should contain the column names
     *    The file should have at least one row of data
     *
     * @param int $maxRowsToValidate The maximum number of rows to validate. if 
     * null, it validates all of the rows
     * @return boolean
     **/
    public function isValid($maxRowsToValidate = null) 
    {
        // The same file instance should not be validated twice.  Validating large 
        // CSV files is an expensive operation and throwing an exception 
        // will hopefully discourage sloppy coding.
        if ($this->_isValid !== null 
            && $maxRowsToValidate !== $this->_maxRows
        ) {
            throw new LogicException("Cannot validate twice using same "
                . "CsvImport_File instance.");
        }

        if ($this->_isValid === null) {
            $this->_isValid = $this->_validate($maxRowsToValidate);
        }
        return $this->_isValid;
    }

    /**
     * Validates the csv file, making sure it has a valid format.
     * If so, it initializes the column count, row count, column names, and 
     * column examples.
     * This process can take a very long time for large files.  If don't need 
     * complete validation, specify the maximum number of rows to validate 
     * instead.
     * 
     * @param int $maxRowsToValidate The maximum number of rows to validate. if 
     * null, it validates all of the rows
     * @return boolean
     **/
    private function _validate($maxRowsToValidate = null)
    {
        $this->_maxRows = $maxRowsToValidate;
        $colCount = 0;
        $rowCount = 0;

        $iter = $this->getRowIterator();
        if (!$iter->valid()) {
            return false;
        }
        foreach ($iter as $index => $row) {
            if ($index == 0) {
                $this->_columnNames = array_values($row);
            } else if ($index == 1) {
                $this->_columnExamples = array_values($row);
            }
            $rowCount++;
            if ($maxRowsToValidate !== null 
                && $rowCount >= (int)$maxRowsToValidate) {
                break;
            }
        }

        // make sure the file has a header column and at least one data column
        if ($rowCount < 2) {
            return false;
        }

        // initialize the row count
        $this->_rowCount = $rowCount;
        return true;
    }

    public function testInfo()
    {
        echo 'valid first 2 lines:' . $this->isValid(2) . '<br/><br/>';
        echo 'valid:' . $this->isValid() . '<br/><br/>';
        echo 'column count:' . $this->getColumnCount() . '<br/><br/>';
        echo 'row count: ' . $this->getRowCount() . '<br/><br/>';
        print_r($this->getColumnNames());
        print_r($this->getColumnExamples());
    } 
}
