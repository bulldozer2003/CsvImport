<?php
/**
 * CsvImport_Import class - represents a csv import event
 *
 * @copyright Copyright 2007-2012 Roy Rosenzweig Center for History and New Media
 * @license http://www.gnu.org/licenses/gpl-3.0.txt GNU GPLv3
 * @package CsvImport
 */
class CsvImport_Import extends Omeka_Record_AbstractRecord implements Zend_Acl_Resource_Interface
{
    const UNDO_IMPORT_RECORD_LIMIT_PER_QUERY = 50;

    const STATUS_QUEUED = 'queued';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';

    const STATUS_QUEUED_UNDO = 'queued_undo';
    const STATUS_IN_PROGRESS_UNDO = 'undo_in_progress';
    const STATUS_COMPLETED_UNDO = 'completed_undo';

    const STATUS_IMPORT_ERROR = 'import_error';
    const STATUS_UNDO_IMPORT_ERROR = 'undo_import_error';
    const STATUS_OTHER_ERROR = 'other_error';

    const STATUS_STOPPED = 'stopped';
    const STATUS_PAUSED = 'paused';

    public $format;
    public $delimiter;
    public $enclosure;

    public $status;
    public $row_count = 0;
    public $skipped_row_count = 0;
    public $skipped_record_count = 0;
    public $updated_record_count = 0;
    public $file_position = 0;

    public $original_filename;
    public $file_path;
    public $serialized_default_values;
    public $serialized_column_maps;

    public $owner_id;
    public $added;

    private $_csvFile;
    private $_isHtml;
    private $_importedCount = 0;

    /**
     * Batch importing is not enabled by default.
     */
    private $_batchSize = 0;

    /**
     * Default values for item type, collection, public, featured...
     */
    private $_defaultValues;

    /**
     * An array of columnMaps, where each columnMap maps a column index number
     * (starting at 0) to an element, tag, and/or file.
     *
     * @var array
     */
    private $_columnMaps;

    /**
     * The mapping of the current row from a CSV file (CsvImport_ColumnMap_Set).
     */
     private $_currentMap;

    protected function _initializeMixins()
    {
        $this->_mixins[] = new Mixin_Owner($this);
        $this->_mixins[] = new Mixin_Timestamp($this, 'added', null);
    }

    /**
     * Get the user object.
     *
     * @return User
     */
    public function getOwner()
    {
        if ($this->owner_id) {
            return $this->getTable('User')->find($this->owner_id);
        }
    }

    /**
     * Sets the format of the imported CSV file.
     *
     * @param int $format The format of the imported CSV File.
     */
    public function setFormat($format)
    {
        $this->format = $format;
    }

    /**
     * Sets the column delimiter in the imported CSV file.
     *
     * @param string The column delimiter of the imported CSV file
     */
    public function setColumnDelimiter($delimiter)
    {
        $this->delimiter = $delimiter;
    }

    /**
     * Sets the enclosure in the imported CSV file
     *
     * @param string The enclosure of the imported CSV file
     */
    public function setEnclosure($enclosure)
    {
        $this->enclosure = $enclosure;
    }

    /**
     * Sets whether the import is raw text or html.
     *
     * @param mixed $flag A boolean representation
     */
    public function setIsHtml($flag)
    {
        $booleanFilter = new Omeka_Filter_Boolean;
        $this->_isHtml = $booleanFilter->filter($flag);
    }

    /**
     * Sets the status of the import.
     *
     * @param string The status of the import
     */
    public function setStatus($status)
    {
        $this->status = (string)$status;
    }

    /**
     * Sets the original filename of the imported CSV file.
     *
     * @param string The original filename of the imported CSV file
     */
    public function setOriginalFilename($filename)
    {
        $this->original_filename = $filename;
    }

    /**
     * Sets the file path of the imported CSV file.
     *
     * @param string The file path of the imported CSV file
     */
    public function setFilePath($path)
    {
        $this->file_path = $path;
    }

    /**
     * Sets default values.
     *
     * @param array $defaultValues
     */
    public function setDefaultValues($defaultValues)
    {
        // Check null.
        if (empty($defaultValues)) {
            $defaultValues = array();
        }
        elseif (!is_array($defaultValues)) {
            throw new InvalidArgumentException("Default values must be an array.");
        }
        $this->_defaultValues = $defaultValues;
    }

    /**
     * Sets the column maps for the import.
     *
     * @param CsvImport_ColumnMap_Set|array $maps The set of column maps
     * @throws InvalidArgumentException
     */
    public function setColumnMaps($maps)
    {
        if ($maps instanceof CsvImport_ColumnMap_Set) {
            $mapSet = $maps;
        } else if (is_array($maps)) {
            $mapSet = new CsvImport_ColumnMap_Set($maps);
        } else {
            throw new InvalidArgumentException("Maps must be either an "
                . "array or an instance of CsvImport_ColumnMap_Set.");
        }
        $this->_columnMaps = $mapSet;
    }

    /**
     * Sets the user id of the owner of the imported items.
     *
     * @param int $id The user id of the owner of the imported items
     */
    public function setOwnerId($id)
    {
        $this->owner_id = (int) $id;
    }

    /**
     * Set the number of items to create before pausing the import.
     *
     * Used primarily for performance reasons, i.e. long-running imports may
     * time out or hog system resources in such a way that prevents other
     * imports from running.  When used in conjunction with Omeka_Job and
     * resume(), this can be used to spawn multiple sequential jobs for a given
     * import.
     *
     * @param int $size
     */
    public function setBatchSize($size)
    {
        $this->_batchSize = (int) $size;
    }

    /**
     * Executes before the record is saved.
     *
     * @param array $args
     */
    protected function beforeSave($args)
    {
        $this->serialized_default_values = serialize($this->getDefaultValues());
        $this->serialized_column_maps = serialize($this->getColumnMaps());
    }

    /**
     * Executes after the record is deleted.
     */
    protected function afterDelete()
    {
        if (file_exists($this->file_path)) {
            unlink($this->file_path);
        }
    }

    /**
     * Returns whether there is an error.
     *
     * @return boolean Whether there is an error
     */
    public function isError()
    {
        return $this->isImportError() ||
               $this->isUndoImportError() ||
               $this->isOtherError();
    }

    /**
     * Returns whether there is an error with the import process.
     *
     * @return boolean Whether there is an error with the import process
     */
    public function isImportError()
    {
        return $this->status == self::STATUS_IMPORT_ERROR;
    }

    /**
     * Returns whether there is an error with the undo import process.
     *
     * @return boolean Whether there is an error with the undo import process
     */
    public function isUndoImportError()
    {
        return $this->status == self::STATUS_UNDO_IMPORT_ERROR;
    }

    /**
     * Returns whether there is an error that is neither related to an import
     * nor undo import process.
     *
     * @return boolean Whether there is an error that is neither related to an
     * import nor undo import process
     */
    public function isOtherError()
    {
        return $this->status == self::STATUS_OTHER_ERROR;
    }

    /**
     * Returns whether the import is stopped.
     *
     * @return boolean Whether the import is stopped
     */
    public function isStopped()
    {
        return $this->status == self::STATUS_STOPPED;
    }

    /**
     * Returns whether the import is queued.
     *
     * @return boolean Whether the import is queued
     */
    public function isQueued()
    {
        return $this->status == self::STATUS_QUEUED;
    }

    /**
     * Returns whether the undo import is queued.
     *
     * @return boolean Whether the undo import is queued
     */
    public function isQueuedUndo()
    {
        return $this->status == self::STATUS_QUEUED_UNDO;
    }

    /**
     * Returns whether the import is completed.
     *
     * @return boolean Whether the import is completed
     */
    public function isCompleted()
    {
        return $this->status == self::STATUS_COMPLETED;
    }

    /**
     * Returns whether the import is undone.
     *
     * @return boolean Whether the import is undone
     */
    public function isUndone()
    {
        return $this->status == self::STATUS_COMPLETED_UNDO;
    }

    /**
     * Imports the CSV file.  This function can only be run once.
     * To import the same csv file, you will have to create another instance of
     * CsvImport_Import and run start.
     * Sets import status to self::STATUS_IN_PROGRESS.
     *
     * @return boolean Whether the import was successful
     */
    public function start()
    {
        $this->status = self::STATUS_IN_PROGRESS;
        $this->_countRows();
        $this->save();
        $this->_log("Started import.");
        $this->_importLoop($this->file_position);
        return !$this->isError();
    }

    /**
     * Completes the import.
     * Sets import status to self::STATUS_COMPLETED
     *
     * @return boolean Whether the import was successfully completed
     */
    public function complete()
    {
        if ($this->isCompleted()) {
            $this->_log("Cannot complete an import that is already completed.");
            return false;
        }
        $this->status = self::STATUS_COMPLETED;
        $this->save();
        $this->_log("Completed importing $this->_importedCount items ("
            . "updated $this->updated_record_count rows, "
            . "skipped $this->skipped_row_count rows).");
        return true;
    }

    /**
     * Completes the undo import.
     * Sets import status to self::STATUS_COMPLETED_UNDO
     *
     * @return boolean Whether the undo import was successfully completed
     */
    public function completeUndo()
    {
        if ($this->isUndone()) {
            $this->_log("Cannot complete an undo import that is already undone.");
            return false;
        }
        $this->status = self::STATUS_COMPLETED_UNDO;
        $this->save();
        $this->_log("Completed undoing the import.");
        return true;
    }

    /**
     * Resumes the import.
     * Sets import status to self::STATUS_IN_PROGRESS
     *
     * @return boolean Whether the import was successful after it was resumed
     */
    public function resume()
    {
        if (!$this->isQueued() && !$this->isQueuedUndo()) {
            $this->_log("Cannot resume an import or undo import that has not been queued.");
            return false;
        }

        $undoImport = $this->isQueuedUndo();

        if ($this->isQueued()) {
            $this->status = self::STATUS_IN_PROGRESS;
            $this->save();
            $this->_log("Resumed import.");
            $this->_importLoop($this->file_position);
        } else {
            $this->status = self::STATUS_IN_PROGRESS_UNDO;
            $this->save();
            $this->_log("Resumed undo import.");
            $this->_undoImportLoop();
        }

        return !$this->isError();
    }

    /**
     * Stops the import or undo import.
     * Sets import status to self::STATUS_STOPPED
     *
     * @return boolean Whether the import or undo import was stopped due to an error
     */
    public function stop()
    {
        // If the import or undo import loops were prematurely stopped while in
        // progress, then there is an error, otherwise there is no error, i.e.
        // the import or undo import was completed
        if ($this->status != self::STATUS_IN_PROGRESS and
            $this->status != self::STATUS_IN_PROGRESS_UNDO) {
            return false; // no error
        }

        // The import or undo import loop was prematurely stopped
        $logMsg = "Stopped import or undo import due to error";
        if ($error = error_get_last()) {
            $logMsg .= sprintf('[file %s, line %d]: %s', $error['file'], $error['line'], $error['message']);
        } else {
            $logMsg .= '.';
        }
        $this->status = self::STATUS_STOPPED;
        $this->save();
        $this->_log($logMsg, Zend_Log::ERR);
        return true; // stopped with an error
    }

    /**
     * Queue the import.
     * Sets import status to self::STATUS_QUEUED
     *
     * @return boolean Whether the import was successfully queued
     */
    public function queue()
    {
        if ($this->isError()) {
            $this->_log("Cannot queue an import that has an error.");
            return false;
        }

        if ($this->isStopped()) {
            $this->_log("Cannot queue an import that has been stopped.");
            return false;
        }

        if ($this->isCompleted()) {
            $this->_log("Cannot queue an import that has been completed.");
            return false;
        }

        if ($this->isUndone()) {
            $this->_log("Cannot queue an import that has been undone.");
            return false;
        }

        $this->status = self::STATUS_QUEUED;
        $this->save();
        $this->_log("Queued import.");
        return true;
    }

    /**
     * Queue the undo import.
     * Sets import status to self::STATUS_QUEUED_UNDO
     *
     * @return boolean Whether the undo import was successfully queued
     */
    public function queueUndo()
    {
        if ($this->isUndoImportError()) {
            $this->_log("Cannot queue an undo import that has an undo import error.");
            return false;
        }

        if ($this->isOtherError()) {
            $this->_log("Cannot queue an undo import that has an error.");
            return false;
        }

        if ($this->isStopped()) {
            $this->_log("Cannot queue an undo import that has been stopped.");
            return false;
        }

        if ($this->isUndone()) {
            $this->_log("Cannot queue an undo import that has been undone.");
            return false;
        }

        $this->status = self::STATUS_QUEUED_UNDO;
        $this->save();
        $this->_log("Queued undo import.");
        return true;
    }

    /**
     * Undo the import.
     * Sets import status to self::STATUS_IN_PROGRESS_UNDO and then self::STATUS_COMPLETED_UNDO
     *
     * @return boolean Whether the import was successfully undone
     */
    public function undo()
    {
        $this->status = self::STATUS_IN_PROGRESS_UNDO;
        $this->save();
        $this->_log("Started undo import.");
        $this->_undoImportLoop();
        return !$this->isError();
    }

    /**
     * Returns the CsvImport_File object for the import.
     *
     * @return CsvImport_File
     */
    public function getCsvFile()
    {
        if (empty($this->_csvFile)) {
            $this->_csvFile = new CsvImport_File($this->file_path, $this->delimiter, $this->enclosure);
        }
        return $this->_csvFile;
    }

    /**
     * Returns the the default values for the import.
     *
     * @throws UnexpectedValueException
     * @return array The default values for the import
     */
    public function getDefaultValues()
    {
        if ($this->_defaultValues === null) {
            $defaultValues = unserialize($this->serialized_default_values);
            if (empty($defaultValues)) {
                $defaultValues = array();
            }
            elseif (!is_array($defaultValues)) {
                throw new UnexpectedValueException("Default values must be an array. "
                    . "Instead, the following was given: " . var_export($defaultValues, true));
            }
            $this->_defaultValues = $defaultValues;
        }
        return $this->_defaultValues;
    }

    /**
     * Returns the set of column maps for the import.
     *
     * @throws UnexpectedValueException
     * @return CsvImport_ColumnMap_Set The set of column maps for the import
     */
    public function getColumnMaps()
    {
        if ($this->_columnMaps === null) {
            $columnMaps = unserialize($this->serialized_column_maps);
            if (!($columnMaps instanceof CsvImport_ColumnMap_Set)) {
                throw new UnexpectedValueException("Column maps must be "
                    . "an instance of CsvImport_ColumnMap_Set. Instead, the "
                    . "following was given: " . var_export($columnMaps, true));
            }
            $this->_columnMaps = $columnMaps;
        }
        return $this->_columnMaps;
    }

    /**
     * Returns the number of records currently imported.  If a user undoes an
     * import, this number decreases to the number of records left to remove.
     *
     * @return int The number of records imported minus the number of records
     * undone.
     */
    public function getImportedRecordCount()
    {
        return $this->getTable('CsvImport_ImportedRecord')->getTotal($this->id);
    }

    /**
     * Returns the number of rows in the file currently imported.
     *
     * @return int The number of rows of the file, valid or not.
     */
    protected function _countRows()
    {
        $rows = $this->getCsvFile()->getIterator();
        $rows->rewind();
        $rows->skipInvalidRows(true);
        $valid_row_count = 0;
        while ($rows->valid()) {
            $rows->next();
            $valid_row_count++;
        }
        $this->row_count = $valid_row_count + $rows->getSkippedCount();
    }

    /**
     * Runs the import loop.
     *
     * @param int $startAt A row number in the CSV file.
     * @throws Exception
     * @return boolean Whether the import loop was successfully run
     */
    protected function _importLoop($startAt = null)
    {
        try {
            register_shutdown_function(array($this, 'stop'));
            $rows = $this->getCsvFile()->getIterator();
            $rows->rewind();
            if ($startAt) {
                $rows->seek($startAt);
            }

            $rows->skipInvalidRows(true);
            $this->_log("Running item import loop at %time%.");
            $this->_log("Memory usage: %memory%.");
            while ($rows->valid()) {
                $row = $rows->current();
                $index = $rows->key();
                $this->skipped_row_count += $rows->getSkippedCount();

                // TODO Mapping row process may be used to clean and normalize
                // data, so the process here (and in all the plugin) may be
                // simpler.

                // Map the row to identified columns.
                $this->_currentMap = $this->getColumnMaps()->map($row);
                $map = &$this->_currentMap;

                // Process returns the record if any, true if success but no
                // record (delete), false in case of error and null in other
                // cases (true skip).
                switch ($this->format) {
                    case 'Manage':
                        $record = $this->_manageFromMappedRow();
                        break;
                    case 'Report':
                    case 'Item':
                        $record = $this->_addItemFromMappedRow();
                        break;
                    // Deprecated.
                    case 'File':
                        $record = $this->_updateFileFromMappedRow();
                        break;
                    case 'Mix':
                        $record = $this->_mixFromMappedRow();
                        break;
                    case 'Update':
                        $record = $this->_updateFromMappedRow();
                        break;
                    default:
                        $record = false;
                }

                if (empty($record)) {
                    $this->skipped_record_count++;
                    $this->_log("Skipped record on row #{$index}.", Zend_Log::WARN);
                }
                elseif ($record === CsvImport_ColumnMap_Action::ACTION_SKIP) {
                    $this->skipped_record_count++;
                }
                elseif (is_object($record)) {
                    release_object($record);
                }

                $this->file_position = $this->getCsvFile()->getIterator()->tell();
                if ($this->_batchSize && ($index % $this->_batchSize == 0)) {
                    $this->_log("Completed processing batch of $this->_batchSize items at %time%.");
                    $this->_log("Memory usage: %memory%.");
                    return $this->queue();
                }
                $rows->next();
            }
            $this->skipped_row_count += $rows->getSkippedCount();
            return $this->complete();
        } catch (Omeka_Job_Worker_InterruptException $e) {
            // Interruptions usually indicate that we should resume from
            // the last stopping position.
            return $this->queue();
        } catch (Exception $e) {
            $this->status = self::STATUS_IMPORT_ERROR;
            $this->save();
            $this->_log($e, Zend_Log::ERR);
            throw $e;
        }
    }

    /**
     * Runs the undo import loop.
     *
     * @throws Exception
     * @return boolean Whether the undo import loop was successfully run
     */
    protected function _undoImportLoop()
    {
        try {
            $recordLimitPerQuery = self::UNDO_IMPORT_RECORD_LIMIT_PER_QUERY;
            $batchSize = intval($this->_batchSize);
            if ($batchSize > 0) {
                $recordLimitPerQuery = min($recordLimitPerQuery, $batchSize);
            }
            register_shutdown_function(array($this, 'stop'));
            $db = $this->getDb();
            $deletedRecordsCount = 0;

            while ($importedRecords = get_records('CsvImport_ImportedRecord', array('import_id' => $this->id), $recordLimitPerQuery)) {
                $deletedRecords = array();
                foreach ($importedRecords as $importedRecord) {
                    $record = $importedRecord->getRecord();
                    // The record may have been deleted automatically, specially
                    // if it's a file.
                    if ($record) {
                        $record->delete();
                        release_object($record);
                    }
                    // Even if the record have been deleted automatically, it's
                    // counted.
                    $deletedRecords[$importedRecord->record_type][] = $importedRecord->record_id;
                    $deletedRecordsCount++;
                    // Limit process to the batch size of the job, and prepare a
                    // new job.
                    if ($batchSize > 0 && $deletedRecordsCount >= $batchSize) {
                        // Remove all deleted records from the list of imported
                        // records.
                        foreach ($deletedRecords as $recordType => $recordIds) {
                            $db->delete($db->CsvImport_ImportedRecord, array(
                                'record_type = ' . $db->quote($recordType),
                                'record_id IN (' . $db->quote($recordIds) . ')',
                            ));
                        }
                        $this->_log("Completed undoing the import of a batch of $batchSize items.");
                        $this->_log("Memory usage: %memory%.");
                        return $this->queueUndo();
                    }
                }
                // Remove all deleted records from the list of imported records.
                foreach ($deletedRecords as $recordType => $recordIds) {
                    $db->delete($db->CsvImport_ImportedRecord, array(
                        'record_type = ' . $db->quote($recordType),
                        'record_id IN (' . $db->quote($recordIds) . ')',
                    ));
                }
            }
            return $this->completeUndo();
        } catch (Omeka_Job_Worker_InterruptException $e) {
            if ($db && $deletedRecords) {
                // Remove all deleted records from the list of imported records.
                foreach ($deletedRecords as $recordType => $recordIds) {
                    $db->delete($db->CsvImport_ImportedRecord, array(
                        'record_type = ' . $db->quote($recordType),
                        'record_id IN (' . $db->quote($recordIds) . ')',
                    ));
                }
            }
            return $this->queueUndo();
        } catch (Exception $e) {
            $this->status = self::STATUS_UNDO_IMPORT_ERROR;
            $this->save();
            $this->_log($e, Zend_Log::ERR);
            throw $e;
        }
    }

    /**
     * Manage a record from a row string in the CSV file and returns it.
     *
     * @return Record|boolean|string The managed record, true if deleted, or false if
     * error.
     */
    protected function _manageFromMappedRow()
    {
        $action = $this->_getMappedValue(CsvImport_ColumnMap::TYPE_ACTION);
        // Avoid a empty choice by the user.
        if (empty($action)) {
            $action = CsvImport_ColumnMap_Action::DEFAULT_ACTION;
        }
        elseif ($action == CsvImport_ColumnMap_Action::ACTION_SKIP) {
            return CsvImport_ColumnMap_Action::ACTION_SKIP;
        }

        $identifierField = $this->_getMappedValue(CsvImport_ColumnMap::TYPE_IDENTIFIER_FIELD);

        // Smart determination of the record type when empty
        $recordType = $this->_getMappedValue(CsvImport_ColumnMap::TYPE_RECORD_TYPE, null);
        if (empty($recordType)) {
            // If there is a non empty column Collection, so this is an item.
            if (!empty($map[CsvImport_ColumnMap::TYPE_COLLECTION])) {
                $recordType = 'Item';
            }
            // If there is a non empty Item Type, this is an item.
            elseif (!empty($map[CsvImport_ColumnMap::TYPE_ITEM_TYPE])) {
                $recordType = 'Item';
            }
            // If there are multiple files, this is an item.
            elseif (count($map[CsvImport_ColumnMap::TYPE_FILE]) > 1) {
                $recordType = 'Item';
            }
            // If there is a non empty column Item, this is a file.
            elseif (!empty($map[CsvImport_ColumnMap::TYPE_ITEM])) {
                $recordType = 'File';
            }
            // Specific file identifiers.
            elseif (in_array(strtolower($identifierField), array(
                    'original filename',
                    'filename',
                    'md5',
                    'authentication',
                    // Deprecated.
                    'original_filename',
                ))) {
                $recordType = 'File';
            }
            // If there are item type metadata, this is an item.
            // By default, this is an item.
            else {
                $recordType = CsvImport_ColumnMap_RecordType::DEFAULT_RECORD_TYPE;
            }
        }

        // Get the identifier (specific column or metadata).
        $identifier = $this->_getMappedValue(CsvImport_ColumnMap::TYPE_IDENTIFIER);
        // If there is no column, get the value of the identifier field column.
        // Note: Empty string ("") is managed below.
        if (is_null($identifier)) {
            if (!empty($identifierField)) {
                // TODO This is a bug: here, the column is an element or an
                // extra data, so we should check one of these columns, not the
                // Identifier Field column directly.
                // Nevertheless, this line is never used because there is always
                // an identifer column. Currently, it returns empty for element
                // field, so it doesn't change anything.
                $identifier = $this->_getMappedValue($identifierField);
            }
        }

        $record = $this->_getRecordByIdentifier($identifier, $recordType, $identifierField);

        // Another way to find a file.
        if (empty($record) && $recordType == 'File') {
            $file = $this->_getMappedValue(CsvImport_ColumnMap::TYPE_FILE);
            if (!empty($file) && count($file) == 1) {
                $identifierField = 'original filename';
                $identifier = $file;
                $record = $this->_getRecordByIdentifier($identifier, $recordType, $identifierField);
            }
        }

        // Manage an exception when a file is added to an item: action can be
        // "Add" for the item, but if there is no file, the file is to be created.
        if ($recordType == 'File'
                && empty($record)
                && !in_array($action, array(
                    CsvImport_ColumnMap_Action::ACTION_UPDATE_ELSE_CREATE,
                    CsvImport_ColumnMap_Action::ACTION_CREATE,
            ))) {
            $item = $this->_getMappedValue(CsvImport_ColumnMap::TYPE_ITEM);
            if (!empty($item)) {
                $action = CsvImport_ColumnMap_Action::ACTION_CREATE;
            }
        }

        // In case there is no identifier or record, the only available action
        // is Create. Else all actions are available.
        if (empty($identifier) || empty($record)) {
            if (!in_array($action, array(
                    CsvImport_ColumnMap_Action::ACTION_UPDATE_ELSE_CREATE,
                    CsvImport_ColumnMap_Action::ACTION_CREATE,
                ))) {
                $msg = __('Cannot process this row: no record found with the identifier "%s".', $identifier);
                $this->_log($msg, Zend_Log::WARN);
                return false;
            }
            $record = null;
            $action = CsvImport_ColumnMap_Action::ACTION_CREATE;
        }

        // Check if a duplicate is to be created.
        if ($record && $action == CsvImport_ColumnMap_Action::ACTION_CREATE) {
            // A same identifier is possible only for different record (internal id).
            if ($identifierField != 'internal id' || get_class($record) == $recordType) {
                $msg = __('Cannot create a second record with the same identifier "%s".', $identifier);
                $this->_log($msg, Zend_Log::WARN);
                return false;
            }
        }

        // In the case where recordType was a special one.
        if ($record) {
            $recordType = get_class($record);
        }

        switch ($action) {
            case CsvImport_ColumnMap_Action::ACTION_CREATE:
                switch ($recordType) {
                    case 'Item':
                        $record = $this->_addItemFromMappedRow();
                        break;
                    case 'File':
                        $record = $this->_addFileFromMappedRow();
                        break;
                    case 'Collection':
                        $record = $this->_addCollectionFromMappedRow();
                        break;
                    case 'Any':
                    default:
                        $msg = __('Type of the record to create is not set.');
                        $this->_log($msg, Zend_Log::WARN);
                        return false;
                }
                break;

            case CsvImport_ColumnMap_Action::ACTION_UPDATE_ELSE_CREATE:
                // As the record is present, "Update else Create" is "Update".
                $action = CsvImport_ColumnMap_Action::ACTION_UPDATE;
            case CsvImport_ColumnMap_Action::ACTION_UPDATE:
            case CsvImport_ColumnMap_Action::ACTION_ADD:
            case CsvImport_ColumnMap_Action::ACTION_REPLACE:
                // Allowed actions here are only the old ones.
                $record = $this->_updateRecord($record, $action);
                // Can't move this _updateRecord().
                $this->updated_record_count++;
                break;
            case CsvImport_ColumnMap_Action::ACTION_DELETE:
                $record = $this->_deleteRecord($record);
                break;
            default:
                return false;
        }

        return $record;
    }

    /**
     * Manage a record from a row string in the CSV file and returns it.
     *
     * @return Record|boolean The managed record, or false if error.
     */
    protected function _mixFromMappedRow()
    {
        $map = &$this->_currentMap;

        // Check if this is metadata of an item or a file.
        $recordType = $map[CsvImport_ColumnMap::TYPE_RECORD_TYPE];
        if ($recordType === false) {
            return false;
        }

        $file = $map[CsvImport_ColumnMap::TYPE_FILE];

        // Direct determination.
        if ($recordType == 'Item') {
            $record = $this->_addItemFromMappedRow();
        }
        elseif ($recordType == 'File') {
            $record = $this->_addFileFromMappedRow();
        }
        elseif ($recordType == 'Collection') {
            $record = $this->_addCollectionFromMappedRow();
        }
        // If there is no file, this can't be a file.
        elseif (empty($file)) {
            $record = $this->_addItemFromMappedRow();
        }
        // Check if there is one and only one file, as a string (see mapping).
        elseif (!is_array($file)) {
            $record = $this->_addFileFromMappedRow();
        }
        // Else, this is an item, even if the file column has only one url.
        else {
            $record = $this->_addItemFromMappedRow();
        }
        return $record;
    }

    /**
     * Add a new item based on a row string in the CSV file and return it.
     *
     * @return Item|boolean The inserted item or false if an item could not be
     * added.
     */
    protected function _addItemFromMappedRow()
    {
        $map = &$this->_currentMap;

        $recordMetadata = $this->_getItemMetadataFromMappedRow();

        // Create collection if needed.
        if (!empty($this->_defaultValues['createCollections'])
                && !empty($this->_getMappedValue(CsvImport_ColumnMap::TYPE_COLLECTION))
                && empty($recordMetadata[Builder_Item::COLLECTION_ID])
            ) {
            $collection = $this->_createRecordFromIdentifier(
                $this->_getMappedValue(CsvImport_ColumnMap::TYPE_COLLECTION),
                'Collection',
                $this->_defaultValues['IdentifierField']);
            if ($collection) {
                $recordMetadata[Builder_Item::COLLECTION_ID] = $collection->id;
            }
        }

        if (empty($recordMetadata[Builder_Item::ITEM_TYPE_ID])) {
            unset($recordMetadata[Builder_Item::ITEM_TYPE_ID]);
        }
        if (empty($recordMetadata[Builder_Item::ITEM_TYPE_NAME])) {
            unset($recordMetadata[Builder_Item::ITEM_TYPE_NAME]);
        }

        $elementTexts = $map[CsvImport_ColumnMap::TYPE_ELEMENT];
        // Keep only non empty fields to avoid removing them (allow update).
        $elementTexts = array_values(array_filter($elementTexts, 'self::_removeEmptyElement'));
        // Trim metadata to avoid spaces.
        $elementTexts = $this->_trimElementTexts($elementTexts);

        $extraData = $map[CsvImport_ColumnMap::TYPE_EXTRA_DATA];
        // Empty fields should not be removed. Fields are not trimmed.

        try {
            $record = $this->_insert_item($recordMetadata, $elementTexts, array(), $extraData);
        } catch (Omeka_Validator_Exception $e) {
            $this->_log($e, Zend_Log::ERR);
            return false;
        } catch (Omeka_Record_Builder_Exception $e) {
            $this->_log($e, Zend_Log::ERR);
            return false;
        }

        $fileUrls = $map[CsvImport_ColumnMap::TYPE_FILE];
        // Check error.
        if (!$this->_attachFilesToItem($record, $fileUrls)) {
            return false;
        }

        // This identifier will be saved in base. This is used only with format
        // "Mix", so it is deprecated.
        switch ($this->format) {
            case 'Manage':
                $identifier = $this->_getMappedValue(CsvImport_ColumnMap::TYPE_IDENTIFIER);
                break;
            case 'Mix':
                $identifier = isset($map[CsvImport_ColumnMap::TYPE_SOURCE_ITEM_ID])
                    ? $map[CsvImport_ColumnMap::TYPE_SOURCE_ITEM_ID]
                    : '';
                break;
            default:
                $identifier = '';
        }
        // Makes it easy to unimport the record later.
        $this->_recordImportedRecord('Item', $record->id, $identifier);
        return $record;
    }

    /**
     * Add a new file and add metadata based on a row string in the CSV file and
     * return it.
     *
     * @return File|boolean The inserted file or false if the file could not be
     * added.
     */
    protected function _addFileFromMappedRow()
    {
        $map = &$this->_currentMap;

        // Looking for the item id.
        if ($this->format == 'Manage') {
            // Check if the file url is present.
            $fileUrl = $map[CsvImport_ColumnMap::TYPE_FILE];
            if (count($fileUrl) > 1) {
                $msg = __('A file can have only one url or path.');
                $this->_log($msg, Zend_Log::ERR);
                return false;
            }
            if (empty($fileUrl)) {
                $msg = __('You should give the path or the url of the file to import.');
                $this->_log($msg, Zend_Log::ERR);
                return false;
            }
            $fileUrl = reset($fileUrl);

            $itemIdentifier = $map[CsvImport_ColumnMap::TYPE_ITEM];
            $item = $this->_getRecordByIdentifier($itemIdentifier, 'Item', $this->_defaultValues['IdentifierField']);

            // Create item if it doesn't exist.
            if (empty($item)) {
                if (!empty($itemIdentifier)) {
                    $item = $this->_createRecordFromIdentifier($itemIdentifier, 'Item', $this->_defaultValues['IdentifierField']);
                }
                if (empty($item)) {
                    $msg = __('No item with the identifier "%s" for the file "%s".', $itemIdentifier, $fileUrl);
                    $this->_log($msg, Zend_Log::ERR);
                    return false;
                }
            }
        }
        // Deprecated.
        else {
            // Check if the file url is present.
            $fileUrl = $map[CsvImport_ColumnMap::TYPE_FILE];
            if (empty($fileUrl)) {
                $msg = __('You should give the path or the url of the file to import.');
                $this->_log($msg, Zend_Log::ERR);
                return false;
            }
            elseif (is_array($fileUrl)) {
                if (count($fileUrl) > 1) {
                    $msg = __('A file can have only one url or path.');
                    $this->_log($msg, Zend_Log::ERR);
                    return false;
                }
                $fileUrl = reset($fileUrl);
            }

            // Check if the source item id is present.
            if (empty($map[CsvImport_ColumnMap::TYPE_SOURCE_ITEM_ID])) {
                $msg = __('No indication of the source item to which attach filename.', $fileUrl);
                $this->_log($msg, Zend_Log::ERR);
                return false;
            }

            $sourceItemId = $map[CsvImport_ColumnMap::TYPE_SOURCE_ITEM_ID];
            $csvImportedRecords = get_db()->getTable('CsvImport_ImportedRecord')
                ->findBy(array(
                    'identifier' => $sourceItemId,
                    'record_type' => 'Item',
                    'import_id' => $this->id,
                ), 1);
            if (empty($csvImportedRecords)) {
                $msg = __('No item with the source item id "%s" does exist in the database.', $sourceItemId)
                    . ' ' . __('With depracted "Mix" and "Update" formats, file rows should always be imported after the item to which they are attached.');
                $this->_log($msg, Zend_Log::ERR);
                return false;
            }
            $csvImportedRecord = reset($csvImportedRecords);
            $item = get_record_by_id('Item', $csvImportedRecord->record_id);
        }

        // Set the transfer strategy according to file name.
        $parsedFileUrl = parse_url($fileUrl);
        if (!isset($parsedFileUrl['scheme']) || $parsedFileUrl['scheme'] == 'file') {
            $transferStrategy = 'Filesystem';
            $fileUrl = $parsedFileUrl['path'];
            if (!$this->_allowLocalPath($fileUrl)) {
                $msg = __('Local paths are not allowed by the administrator (%s).', $fileUrl);
                $this->_log($msg, Zend_Log::ERR);
                return false;
            }
        }
        else {
            $transferStrategy = 'Url';
        }

        // Import the file and attach it to the item.
        try {
            $files = insert_files_for_item($item,
                $transferStrategy,
                $fileUrl,
                array('ignore_invalid_files' => false));
        } catch (Omeka_File_Ingest_InvalidException $e) {
            $msg = __("Error occurred when attempting to ingest '%s' as a file: %s",
                $fileUrl, $e->getMessage());
            $this->_log($msg, Zend_Log::ERR);
            return false;
        }
        // Need to release file in order to update all current data, because
        // $file->save() is not enough.
        $file_id = $files[0]->id;
        release_object($files);
        $file = get_record_by_id('File', $file_id);

        // Update file with new metadata.
        $this->_updateRecord($file, CsvImport_ColumnMap_Action::ACTION_ADD);

        // This identifier will be saved in base. This is used only with format
        // "Mix", so it is deprecated.
        switch ($this->format) {
            case 'Manage':
                $identifier = $this->_getMappedValue(CsvImport_ColumnMap::TYPE_IDENTIFIER);
                break;
            default:
                $identifier = '';
        }
        // Makes it easy to unimport the record later.
        $this->_recordImportedRecord('File', $file->id, $identifier);
        return $file;
    }

    /**
     * Add a new collection based on a row string in the CSV file and return it.
     *
     * The used method is setArray() with elements and extra data, available for
     * all Omeka Records.
     *
     * @return Record|boolean The inserted record or false if it can't be added.
     */
    protected function _addCollectionFromMappedRow()
    {
        $map = &$this->_currentMap;

        $recordMetadata = $this->_getCollectionMetadataFromMappedRow();

        $elementTexts = $map[CsvImport_ColumnMap::TYPE_ELEMENT];
        // Keep only non empty fields to avoid removing them (allow update).
        $elementTexts = array_values(array_filter($elementTexts, 'self::_removeEmptyElement'));
        // Trim metadata to avoid spaces.
        $elementTexts = $this->_trimElementTexts($elementTexts);

        $extraData = $map[CsvImport_ColumnMap::TYPE_EXTRA_DATA];
        // Empty fields should not be removed. Fields are not trimmed.

        try {
            $record = $this->_insert_collection($recordMetadata, $elementTexts, $extraData);
        } catch (Omeka_Validator_Exception $e) {
            $this->_log($e, Zend_Log::ERR);
            return false;
        } catch (Omeka_Record_Builder_Exception $e) {
            $this->_log($e, Zend_Log::ERR);
            return false;
        }

        // Makes it easy to unimport the record later.
        $this->_recordImportedRecord('Collection', $record->id, '');
        return $record;
    }

    /**
     * Update a record based on a row string in the CSV file and returns it.
     *
     * @deprecated Since 2.1.1-full.
     *
     * @return Record|boolean The updated record or false if no record could be
     * updated.
     */
    protected function _updateFromMappedRow()
    {
        $map = &$this->_currentMap;

        $updateIdentifier = $this->_getMappedValue(
            CsvImport_ColumnMap::TYPE_UPDATE_IDENTIFIER,
            'internal id');

        $recordType = $this->_getMappedValue(
            CsvImport_ColumnMap::TYPE_RECORD_TYPE,
            CsvImport_ColumnMap_RecordType::DEFAULT_RECORD_TYPE);

        $recordIdentifier = $this->_getMappedValue(CsvImport_ColumnMap::TYPE_RECORD_IDENTIFIER, '');

        $record = $this->_getRecordByIdentifier($recordIdentifier, $recordType, $updateIdentifier);

        // No record can be updated.
        if (empty($record)) {
            $msg = __('You try to update the record "%s", but it does not exist.', $recordIdentifier);
            $this->_log($msg, Zend_Log::ERR);
            return false;
        }

        // If there are files to attach to an item, import it separately.
        if (get_class($record) == 'Item') {
            $fileUrls = $map[CsvImport_ColumnMap::TYPE_FILE];
            if (!$this->_attachFilesToItem($record, $fileUrls, false)) {
                return false;
            }
        }

        // Update of a record.
        $action = $this->_getMappedValue(
            CsvImport_ColumnMap::TYPE_UPDATE_MODE,
            CsvImport_ColumnMap_UpdateMode::DEFAULT_UPDATE_MODE);
        $this->_updateRecord($record, $action);

        $this->updated_record_count++;

        return $record;
    }

    /**
     * Adds file metadata based on a row string in the CSV file and returns it.
     *
     * @return File|boolean The inserted file or false if metadata can't be
     * added.
     */
    protected function _updateFileFromMappedRow()
    {
        $map = &$this->_currentMap;

        $fileUrl = $map[CsvImport_ColumnMap::TYPE_FILE];
        if (empty($fileUrl)) {
            $msg = __('You should give the internal id or the original filename or the url of the file to import.');
            $this->_log($msg, Zend_Log::ERR);
            return false;
        }
        elseif (is_array($fileUrl)) {
            if (count($fileUrl) > 1) {
                $msg = __('A file can have only one url or path.');
                $this->_log($msg, Zend_Log::ERR);
                return false;
            }
            $fileUrl = reset($fileUrl);
        }

        $file = is_numeric($fileUrl) && (integer) $fileUrl > 0
            // The value is the internal record id.
            ? get_db()->getTable('File')->find($fileUrl)
            // The value is the original filename.
            : get_db()->getTable('File')->findBySql('original_filename = ?', array($fileUrl), true);

        if (empty($file)) {
            $msg = __('File "%s" does not exist in the database.', $fileUrl)
                . ' ' . __('No item associated with it was found.')
                . ' ' . __('Add items first before importing file metadata.');
            $this->_log($msg, Zend_Log::ERR);
            return false;
        }

        // Update file with new metadata.
        $this->_updateRecord($file, CsvImport_ColumnMap_Action::ACTION_ADD);

        $this->updated_record_count++;

        return $file;
    }

    /**
     * Helper to get item metadata from a mapped row string in the CSV file.
     *
     * This helper is used to create or to update an item.
     *
     * @return array
     */
    protected function _getItemMetadataFromMappedRow()
    {
        $map = &$this->_currentMap;
        $recordMetadata = array();

        // TODO Check item type (can be a id or a name).
        $itemType = $this->_getMappedValue(CsvImport_ColumnMap::TYPE_ITEM_TYPE);
        // TODO Sometimes, the item type is numeric, sometimes it is a string.
        $builderItemType = is_numeric($itemType)
            ? Builder_Item::ITEM_TYPE_ID
            : Builder_Item::ITEM_TYPE_NAME;
        $itemType = $itemType ?: null;

        // Check collection, if any.
        $collectionId = $this->_getMappedValue(CsvImport_ColumnMap::TYPE_COLLECTION);
        if (!empty($collectionId) && $this->format == 'Manage') {
            $collection = $this->_getRecordByIdentifier($collectionId, 'Collection', $this->_defaultValues['IdentifierField']);
            $collectionId = $collection ? $collection->id : null;
        }
        // Collection should be null, not 0 or "".
        else {
            $collectionId = $collectionId ?: null;
        }

        // Set values. Default and empty are managed directly in column map.
        $recordMetadata[$builderItemType] = $itemType;
        $recordMetadata[Builder_Item::COLLECTION_ID] = $collectionId;
        $recordMetadata[Builder_Item::IS_PUBLIC] =
            $this->_getMappedValue(CsvImport_ColumnMap::TYPE_PUBLIC);
        $recordMetadata[Builder_Item::IS_FEATURED] =
            $this->_getMappedValue(CsvImport_ColumnMap::TYPE_FEATURED);
        $recordMetadata[Builder_Item::TAGS] =
            $this->_getMappedValue(CsvImport_ColumnMap::TYPE_TAG);

        return $recordMetadata;
    }

    /**
     * Helper to get collection metadata from a mapped row string in the CSV
     * file.
     *
     * This helper is used to create or to update a collection.
     *
     * @return array
     */
    protected function _getCollectionMetadataFromMappedRow()
    {
        $map = &$this->_currentMap;

        // Set values. Default and empty are managed directly in column map.
        $recordMetadata = array();
        $recordMetadata[Builder_Item::IS_PUBLIC] =
            $this->_getMappedValue(CsvImport_ColumnMap::TYPE_PUBLIC);
        $recordMetadata[Builder_Item::IS_FEATURED] =
            $this->_getMappedValue(CsvImport_ColumnMap::TYPE_FEATURED);

        return $recordMetadata;
    }

    /**
     * Adds metadata and extra data to an existing record.
     *
     * @param Record $record An existing and checked record object.
     * @param string $action Allowed actions are "Update", "Add" and "Replace".
     *
     * @return Record|boolean
     * The updated record or false if metadata can't be updated.
     */
    protected function _updateRecord(
        $record,
        $action = CsvImport_ColumnMap_Action::DEFAULT_ACTION
    ) {
        $map = &$this->_currentMap;

        // Check action.
        if (!in_array($action, array(
                CsvImport_ColumnMap_Action::ACTION_UPDATE,
                CsvImport_ColumnMap_Action::ACTION_ADD,
                CsvImport_ColumnMap_Action::ACTION_REPLACE))
            ) {
            return false;
        }

        // Builder doesn't allow action "Update", only add and replace, and
        // doesn't manage file directly.

        // Prepare element texts.
        $elementTexts = $map[CsvImport_ColumnMap::TYPE_ELEMENT];
        // Trim metadata to avoid spaces.
        $elementTexts = $this->_trimElementTexts($elementTexts);
        // Keep only non empty fields to avoid removing them to allow update.
        if ($action == CsvImport_ColumnMap_Action::ACTION_ADD || $action == CsvImport_ColumnMap_Action::ACTION_REPLACE) {
            $elementTexts = array_values(array_filter($elementTexts, 'self::_removeEmptyElement'));
        }
        // Overwrite existing element text values if wanted.
        if ($action == CsvImport_ColumnMap_Action::ACTION_UPDATE || $action == CsvImport_ColumnMap_Action::ACTION_REPLACE) {
            foreach ($elementTexts as $key => $info) {
                if ($info['element_id']) {
                    $record->deleteElementTextsbyElementId((array) $info['element_id']);
                }
            }
        }
        // To reset keys is needed to avoid bug when there is no DC Title.
        $elementTexts = array_values($elementTexts);

        // Update is different for each record type.
        switch (get_class($record)) {
            case 'Item':
                $recordMetadata = $this->_getItemMetadataFromMappedRow();

                // Create collection if needed.
                if (!empty($this->_defaultValues['createCollections'])
                        && !empty($this->_getMappedValue(CsvImport_ColumnMap::TYPE_COLLECTION))
                        && empty($recordMetadata[Builder_Item::COLLECTION_ID])
                    ) {
                    $collection = $this->_createRecordFromIdentifier($collectionId, 'Collection', $this->_defaultValues['IdentifierField']);
                    if ($collection) {
                        $recordMetadata[Builder_Item::COLLECTION_ID] = $collection->id;
                    }
                }

                // Update specific data of the item.
                switch ($action) {
                    case CsvImport_ColumnMap_Action::ACTION_UPDATE:
                        if (empty($recordMetadata[Builder_Item::ITEM_TYPE_ID])
                               || empty($recordMetadata[Builder_Item::ITEM_TYPE_NAME])
                            ) {
                            // TODO Currently, item type cannot be reset.
                            // $recordMetadata[Builder_Item::ITEM_TYPE_ID] = null;
                            unset($recordMetadata[Builder_Item::ITEM_TYPE_ID]);
                            unset($recordMetadata[Builder_Item::ITEM_TYPE_NAME]);
                        }
                        break;

                    case CsvImport_ColumnMap_Action::ACTION_ADD:
                    case  CsvImport_ColumnMap_Action::ACTION_REPLACE:
                        if (empty($recordMetadata[Builder_Item::COLLECTION_ID])) {
                            $recordMetadata[Builder_Item::COLLECTION_ID] = $record->collection_id;
                        }
                        if (empty($recordMetadata[Builder_Item::ITEM_TYPE_ID])) {
                            $recordMetadata[Builder_Item::ITEM_TYPE_ID] = $record->item_type_id;
                        }
                        if (empty($recordMetadata[Builder_Item::ITEM_TYPE_NAME])) {
                            if (!empty($record->item_type_id)) {
                                $recordMetadata[Builder_Item::ITEM_TYPE_ID] = $record->item_type_id;
                            }
                            unset($recordMetadata[Builder_Item::ITEM_TYPE_NAME]);
                        }
                        break;
                }

                if (empty($recordMetadata[Builder_Item::TAGS])) {
                    unset($recordMetadata[Builder_Item::TAGS]);
                }

                $record = update_item($record, $recordMetadata, $elementTexts);
                break;

            case 'File':
                $record->addElementTextsByArray($elementTexts);
                $record->save();
                break;

            case 'Collection':
                $recordMetadata = $this->_getCollectionMetadataFromMappedRow();
                $record = update_collection($record, $recordMetadata, $elementTexts);
                break;

            default:
                return false;
        }

        $extraData = $map[CsvImport_ColumnMap::TYPE_EXTRA_DATA];
        $this->_setExtraData($record, $extraData, $action);

        if (get_class($record) == 'Item') {
            $fileUrls = $map[CsvImport_ColumnMap::TYPE_FILE];
            // Check error. for files
            if (!$this->_updateAttachedFilesOfItem($record, $fileUrls, false, $action)) {
                return false;
            }
        }

        return $record;
    }

    /**
     * Attach a list of files to an item.
     *
     * @param Item $item
     * @param array $fileUrls An array of the urls of files to attach to item.
     * @param boolean $itemDelete Delete item (default) or not if the file can't
     *   be ingested.
     * @return boolean True if success, false else.
     */
    protected function _attachFilesToItem($item, $fileUrls, $itemDelete = true)
    {
        // Sometime, fileUrls is a null or an empty string.
        if (empty($fileUrls)) {
            return true;
        }

        foreach ($fileUrls as $fileUrl) {
            // Set the transfer strategy according to file name.
            $parsedFileUrl = parse_url($fileUrl);
            if (!isset($parsedFileUrl['scheme']) || $parsedFileUrl['scheme'] == 'file') {
                $transferStrategy = 'Filesystem';
                $fileUrl = $parsedFileUrl['path'];
                if (!$this->_allowLocalPath($fileUrl)) {
                    $msg = __('Local paths are not allowed by the administrator (%s).', $fileUrl);
                    $this->_log($msg, Zend_Log::ERR);
                    if ($itemDelete) {
                        $item->delete();
                    }
                    release_object($item);
                    return false;
                }
            }
            else {
                $transferStrategy = 'Url';
            }

            // Import the file and attach it to the item.
            try {
                $files = insert_files_for_item($item,
                    $transferStrategy,
                    $fileUrl,
                    array('ignore_invalid_files' => false));
            } catch (Omeka_File_Ingest_InvalidException $e) {
                $msg = __("Invalid file URL '%s': %s",
                    $fileUrl, $e->getMessage());
                $this->_log($msg, Zend_Log::ERR);
                if ($itemDelete) {
                    $item->delete();
                }
                release_object($item);
                return false;
            } catch (Omeka_File_Ingest_Exception $e) {
                $msg = __("Could not import file '%s': %s",
                    $fileUrl, $e->getMessage());
                $this->_log($msg, Zend_Log::ERR);
                if ($itemDelete) {
                    $item->delete();
                }
                release_object($item);
                return false;
            }
            release_object($files);
        }
        return true;
    }

    /**
     * Update attached files of an item.
     *
     * @param Item $item
     * @param array $fileUrls An array of the urls of files to attach to item.
     * @param boolean $itemDelete Delete item (default) or not if the file can't
     *   be ingested.
     * @param string $action Allowed actions are "Update", "Add" and "Replace".
     * Here, Update and Replace are the same (one field).
     * @return boolean True if success, false else.
     */
    protected function _updateAttachedFilesOfItem(
        $item,
        $fileUrls,
        $itemDelete = true,
        $action = CsvImport_ColumnMap_Action::DEFAULT_ACTION
    ) {
        // Sometime, fileUrls is a null or an empty string, but empty array
        // should be processed.
        // Null means no value, and empty string is incorrect here.
        if (is_null($fileUrls) || $fileUrls == '') {
            return true;
        }

        // Get list of current file urls.
        $currentFiles = $item->Files;
        $currentFileUrls = array();
        foreach ($currentFiles as $file) {
            $currentFileUrls[$file->id] = $file->original_filename;
        }

        // TODO Ideally, all files should be reimported, even if the url is the
        // same. Currently, there is no option for that, so files should be
        // removed before reimport. This process avoids many careless errors.
        switch ($action) {
            case CsvImport_ColumnMap_Action::ACTION_ADD:
                $newFileUrls = array_diff($fileUrls, $currentFileUrls);
                if (!$this->_attachFilesToItem($item, $newFileUrls, false)) {
                    return false;
                }
                break;

            // Update or Replace means to replace all files, so existing ones
            // should be deleted before. They can be reordered too.
            default:
                $currentFilesById = array();
                foreach ($currentFiles as $file) {
                    $currentFilesById[$file->id] = $file;
                }

                $deleteFileUrls = array_diff($currentFileUrls, $fileUrls);
                foreach ($deleteFileUrls as $id => $url) {
                    $file = $currentFilesById[$id];
                    $file->delete();
                    release_object($file);
                }

                // Add new files.
                $newFileUrls = array_diff($fileUrls, $currentFileUrls);
                if (!$this->_attachFilesToItem($item, $newFileUrls, false)) {
                    return false;
                }

                // Reorder files (only possible with the full list files).
                // Get the updated list of files (don't use Files).
                $currentFiles = $item->getFiles();
                $currentFilesByUrl = array();
                foreach ($currentFiles as $file) {
                    $currentFilesByUrl[$file->original_filename] = $file;
                }
                // All files should be processed, because this is an order.
                $i = 0;
                foreach ($fileUrls as $url) {
                    $file = $currentFilesByUrl[$url];
                    $file->order = ++$i;
                    $file->save();
                }
                break;
        }

        return true;
    }

    /**
     * Delete an existing record.
     *
     * @param Record $record The existing and checked record object to remove.
     * @return boolean Success.
     */
    protected function _deleteRecord($record)
    {
        if ($record instanceof Omeka_Record_AbstractRecord) {
            // Deletion of a record return a boolean.
            try {
                $record = $record->delete();
            } catch (Omeka_Record_Exception $e) {
                $this->_log($e, Zend_Log::WARN);
                return false;
            } catch (Exception $e) {
                $this->_log($e, Zend_Log::ERR);
                return false;
            }
            $this->updated_record_count++;
            return true;
        }
        return false;
    }

    /**
     * Get a record from an identifier.
     *
     * @param string $identifier The identifier of the record to update.
     * @param string $recordType The type of the record to update.
     * @param string $identifierField The type of identifier used to identify
     * the record to update.
     *
     * @return Record|boolean The record to update or false if no one exists.
     */
    protected function _getRecordByIdentifier(
        $identifier,
        $recordType = CsvImport_ColumnMap_RecordType::DEFAULT_RECORD_TYPE,
        $identifierField = CsvImport_ColumnMap_IdentifierField::DEFAULT_IDENTIFIER_FIELD
    ) {
        $record = false;

        if (empty($identifier)) {
        }
        elseif ($identifierField == 'internal id') {
            if (!empty($recordType) && $recordType != 'Any' && class_exists($recordType)) {
                $record = get_record_by_id($recordType, $identifier);
            }
        }
        elseif (in_array(strtolower($identifierField), array(
                'original filename',
                'filename',
                'md5',
                'authentication',
                // Deprecated.
                'original_filename',
            ))) {
            if (empty($recordType) || $recordType == 'Any' || $recordType == 'File') {
                $field = strtolower(str_replace(' ', '_', $identifierField));
                if ($field == 'md5') {
                    $field = 'authentication';
                }
                $record = get_db()->getTable('File')->findBySql($field . ' = ?', array($identifier), true);
            }
        }
        // Record identifier is an existing element text or an internal
        // identifier of the current file.
        else {
            $db = get_db();

            $element = $this->_getElementFromIdentifierField($identifierField);
            if (!empty($element)) {
                // Use of ordered placeholders.
                $bind = array();
                $bind[] = $element->id;
                $bind[] = $identifier;
                $sql_record_type = '';
                if (!empty($recordType) && $recordType != 'Any') {
                    $sql_record_type = 'AND element_texts.record_type = ?';
                    $bind[] = $recordType;
                }

                $sql = "
                    SELECT element_texts.record_type, element_texts.record_id
                    FROM {$db->ElementText} element_texts
                    WHERE element_texts.element_id = ?
                        AND element_texts.text = ?
                        $sql_record_type
                    LIMIT 1
                ";
                $result = $db->fetchRow($sql, $bind);
            }

            // Check if this is an internal identifier of the current
            // import, that is already imported.
            // if (empty($element) || empty($result)) {
            else {
                $bind = array();
                $bind['import_id'] = $this->id;
                $bind['identifier'] = $identifier;
                if (in_array($recordType, array('Collection', 'Item', 'File'))) {
                    $bind['record_type'] = $recordType;
                }
                $csvImportedRecords = get_db()->getTable('CsvImport_ImportedRecord')
                    ->findBy($bind, 1);
                if (!empty($csvImportedRecords)) {
                    $csvImportedRecord = reset($csvImportedRecords);
                    $result = array(
                        'record_type' => $csvImportedRecord->record_type,
                        'record_id' => $csvImportedRecord->record_id,
                    );
                }
            }

            if (!empty($result)) {
                $record = get_record_by_id($result['record_type'], $result['record_id']);
            }
        }

        return $record;
    }

    /**
     * Create a basic record from an identifier, that  should be an element.
     *
     * @param string $identifier The identifier of the record to create.
     * @param string $recordType The type of the record to update.
     * @param string $identifierField The type of identifier used to identify
     * the record to create.
     *
     * @return Record|boolean The created record or false if failure.
     */
    protected function _createRecordFromIdentifier(
        $identifier,
        $recordType = CsvImport_ColumnMap_RecordType::DEFAULT_RECORD_TYPE,
        $identifierField = CsvImport_ColumnMap_IdentifierField::DEFAULT_IDENTIFIER_FIELD
    ) {
        if (!in_array($recordType, array('Collection', 'Item', 'File'))) {
            return false;
        }

        if (in_array(strtolower($identifierField), array('internal id', 'original filename', 'filename', 'original_filename' /* Deprecated */))) {
            return false;
        }

        $element = $this->_getElementFromIdentifierField($identifierField);

        try {
            $record = new $recordType;
            // If the identifier is internal (just for the current csv file),
            // there is no element, but the identifier can be saved in the
            // current import table.
            if ($element) {
                $record->addElementTextsByArray(array(
                    array(
                        'element_id' => $element->id,
                        'text' => $identifier,
                        'html' => false),
                ));
            }
            $record->save();
        } catch (Exception $e) {
            return false;
        }

        $this->_recordImportedRecord($recordType, $record->id, $identifier);
        return $record;
    }

    /**
     * Records that a record was successfully processed in the database.
     *
     * @param string $recordType The type of the imported record.
     * @param int $recordId The id of the imported record.
     * @param string $identifier The identifier of the imported record, if any.
     */
    protected function _recordImportedRecord($recordType, $recordId, $identifier = '')
    {
        $csvImportedRecord = new CsvImport_ImportedRecord();
        $csvImportedRecord->setArray(array(
            'import_id' => $this->id,
            'record_type' => $recordType,
            'record_id' => $recordId,
            'identifier' => $identifier ?: '',
        ));
        $csvImportedRecord->save();
        $this->_importedCount++;
    }

    /**
     * Log an import message
     * Every message will log the import ID.
     * Messages that have %memory% will include memory usage information.
     *
     * @param string $msg The message to log
     * @param int $priority The priority of the message
     */
    protected function _log($msg, $priority = Zend_Log::DEBUG)
    {
        $prefix = "[CsvImport][#{$this->id}]";
        $msg = str_replace('%memory%', memory_get_usage(), $msg);
        $msg = str_replace('%time%', date('Y-m-d G:i:s'), $msg);
        _log("$prefix $msg", $priority);
    }

    /**
     * Return the element from an identifier.
     *
     * @return Element|boolean
     */
    private function _getElementFromIdentifierField($identifierField)
    {
        static $elements = array();

        if (!isset($elements[$identifierField])) {
            $elements[$identifierField] = null;
            if ($parts = explode(
                    CsvImport_ColumnMap_MixElement::DEFAULT_COLUMN_NAME_DELIMITER,
                    $identifierField)
                ) {
                if (count($parts) == 2) {
                    $elementSetName = trim($parts[0]);
                    $elementName = trim($parts[1]);
                    $element = get_db()->getTable('Element')
                        ->findByElementSetNameAndElementName($elementSetName, $elementName);
                    if ($element) {
                        $elements[$identifierField] = $element;
                    }
                }
            }
        }

        return $elements[$identifierField];
    }

    /**
     * Return the mapped value from a row if it exists, else the default value.
     *
     * @param string $columnName
     * @param var $defaultValue Used if no default value exists.
     * @return var
     */
    private function _getMappedValue($columnName, $defaultValue = null)
    {
        if (isset($this->_currentMap[$columnName])) {
            return $this->_currentMap[$columnName];
        }
        if (isset($this->_defaultValues[$columnName])) {
            return $this->_defaultValues[$columnName];
        }
        return $defaultValue;
    }

    /**
     * Check if an element is an element without empty string .
     *
     * @param string $element
     *   Element to check.
     *
     * @return boolean
     *   True if the element is an element without empty string.
     */
    private function _removeEmptyElement($element)
    {
        // Don't remove 0.
        return (isset($element['text']) && $element['text'] !== '');
    }

    /**
     * Check if an element is an element without empty string.
     *
     * @param string $element
     *   Element to check.
     *
     * @return array
     *   Array of trimed element texts.
     */
    private function _trimElementTexts($elementTexts)
    {
        foreach ($elementTexts as &$element) {
            if (isset($element['text'])) {
                $element['text'] = trim($element['text']);
            }
        }
        return $elementTexts;
    }

    /* Functions that override Omeka ones in order to process extra data. */

    /**
     * Insert a new item into the Omeka database.
     *
     * Post data can be added, unlike insert_item().
     *
     * @see insert_item()
     *
     * @param array $metadata
     * @param array $elementTexts
     * @param array $fileMetadata
     * @param array $postData
     * @return Item
     */
    private function _insert_item($metadata = array(), $elementTexts = array(), $fileMetadata = array(), $postData = array())
    {
        $record = insert_item($metadata, $elementTexts, $fileMetadata);
        $result = $this->_setExtraData($record, $postData, CsvImport_ColumnMap_Action::ACTION_ADD);
        return $record;
    }

    /**
     * Insert a new collection into the Omeka database.
     *
     * Post data can be added, unlike insert_collection().
     *
     * @see insert_collection()
     *
     * @param array $metadata
     * @param array $elementTexts
     * @param array $postData
     * @return Item
     */
    private function _insert_collection($metadata = array(), $elementTexts = array(), $postData = array())
    {
        $record = insert_collection($metadata, $elementTexts);
        $result = $this->_setExtraData($record, $postData, CsvImport_ColumnMap_Action::ACTION_ADD);
        return $record;
    }

    /**
     * Helper to set extra data for update of records.
     *
     * @internal $action is currently not used, because the way plugins manage
     * updates of their data varies.
     *
     * @todo Manage action via delete/add data?
     *
     * @see CSVImport_Builder_Item::_addPostData()
     *
     * @param Record $record
     * @param array $extraData
     * @param string $action Currently not used.
     * @return boolean Success or not.
     */
    private function _setExtraData(
        $record,
        $extraData,
        $action = CsvImport_ColumnMap_Action::DEFAULT_ACTION
    ) {
        if (empty($record) || empty($extraData) || empty($action)) {
            return false;
        }

        if (Zend_Registry::get('bootstrap')->config->jobs->dispatcher->longRunning
                == 'Omeka_Job_Dispatcher_Adapter_Synchronous') {
            $record->setPostData($extraData);
        }
        // Workaround for asynchronous jobs.
        else {
            $this->_setPostDataViaSetArray($record, $extraData);
        }

        $record->save();
        return true;
    }

    /**
     * Workaround to add post data to a record via setArray().
     *
     * @see CSVImport_Builder_Item::_setPostDataViaSetArray()
     *
     * @param Record $record
     * @param array $post Post data.
     */
    private function _setPostDataViaSetArray($record, $post)
    {
        // Some default type have a special filter.
        switch (get_class($record)) {
            case 'Item':
                $options = array('inputNamespace' => 'Omeka_Filter');
                $filters = array(
                    // Foreign keys
                    'item_type_id'  => 'ForeignKey',
                    'collection_id' => 'ForeignKey',
                    // Booleans
                    'public' => 'Boolean',
                    'featured' => 'Boolean',
                );
                $filter = new Zend_Filter_Input($filters, null, $post, $options);
                $post = $filter->getUnescaped();
                break;

            case 'File':
                $immutable = array('id', 'modified', 'added', 'authentication', 'filename',
                    'original_filename', 'mime_type', 'type_os', 'item_id');
                foreach ($immutable as $value) {
                    unset($post[$value]);
                }
                break;

            case 'Collection':
                $options = array('inputNamespace' => 'Omeka_Filter');
                // User form input does not allow HTML tags or superfluous whitespace
                $filters = array(
                    'public' => 'Boolean',
                    'featured' => 'Boolean',
                );
                $filter = new Zend_Filter_Input($filters, null, $post, $options);
                $post = $filter->getUnescaped();
                break;

            default:
                return;
        }

        // Avoid an issue when the post is null.
        if (empty($post)) {
            return;
        }

        if (!isset($post['Elements'])) {
            $post['Elements'] = array();
        }

        // Default used in Omeka_Record_Builder_AbstractBuilder::setPostData().
        $post = new ArrayObject($post);
        if (array_key_exists('id', $post)) {
            unset($post['id']);
        }

        $record->setArray(array('_postData' => $post));
    }

    /**
     * Check if a local file is importable.
     *
     * @param $fileUrl
     * @return boolean
     */
    protected function _allowLocalPath($fileUrl)
    {
        $settings = Zend_Registry::get('csv_import');

        // Check the security setting.
        if ($settings->local_folders->allow !== '1') {
            return false;
        }

        // Check the base path.
        $path = $settings->local_folders->base_path;
        $realpath = realpath($path);
        if ($path !== $realpath || strlen($realpath) <= 2) {
            return false;
        }

        // Check the uri.
        if (strpos(realpath($fileUrl), $realpath) !== 0
                || !in_array(substr($fileUrl, strlen($realpath), 1), array('', '/'))
            ) {
            return false;
        }

        return true;
    }

    /**
     * Declare the representative model as relating to the record ACL resource.
     *
     * Required by Zend_Acl_Resource_Interface.
     *
     * @return string
     */
    public function getResourceId()
    {
        return 'CsvImport_Imports';
    }
}
