<?php
/**
 * @version $Id$
 * @copyright Center for History and New Media, 2008-2011
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 * @package CsvImport
 */

/**
 * The CvsImport index controller class.
 *
 * @package CsvImport
 * @author CHNM
 * @copyright Center for History and New Media, 2008-2011
 */
class CsvImport_IndexController extends Omeka_Controller_Action
{
    protected $_browseRecordsPerPage = 10;

    public function init()
    {
        $this->session = new Zend_Session_Namespace('CsvImport');
        $this->_helper->db->setDefaultModelName('CsvImport_Import');
    }

    public function preDispatch()
    {
        if (($this->getRequest()->getActionName() != 'error')
            && !$this->_hasValidPHPCliPath()
        ) {
            $this->_helper->redirector->goto('error');    
        }

        $this->view->navigation($this->_getNavigation());
    }

    public function indexAction() 
    {
        $form = $this->_getMainForm();
        $this->view->form = $form;

        if (!$this->getRequest()->isPost()) {
            return;
        }

        if (!$form->isValid($this->getRequest()->getPost())) {
            return;
        }
        if (!$form->csv_file->receive()) {
            return $this->flashError("Error uploading file.  Please try again.");
        }

        $filePath = $form->csv_file->getFileName();
        $delimiter = $form->getValue('column_delimiter');
        $file = new CsvImport_File($filePath, $delimiter);
        
        if (!$file->isValid(2)) {                    
            return $this->flashError('Your file is incorrectly formatted. '
                . 'Please select a valid CSV file.');
        }

        $this->session->filePath = $filePath;
        $this->session->columnDelimiter = $delimiter;
        $this->session->itemTypeId = $form->getValue('item_type_id');
        $this->session->itemsArePublic = 
            $form->getValue('items_are_public');
        $this->session->itemsAreFeatured = 
            $form->getValue('items_are_featured');
        $this->session->collectionId = $form->getValue('collection_id');
        $this->session->stopOnError = 
            $form->getValue('stop_on_file_error');
        // Cache columns to avoid revalidation.
        $this->session->columnNames = $file->getColumnNames();
        $this->session->columnExamples = $file->getColumnExamples();
        $this->_helper->redirector->goto('map-columns');   
    }
    
    public function errorAction()
    {
        if ($this->_hasValidPHPCliPath()) {
            $this->_helper->redirector->goto('index');    
        }
    }
    
    public function mapColumnsAction()
    {
        if (!$this->_sessionIsValid()) {
            return $this->_helper->redirector->goto('index');
        }

        $file = new CsvImport_File($this->session->filePath);
        require_once CSV_IMPORT_DIRECTORY . '/forms/Mapping.php';
        $form = new CsvImport_Form_Mapping(array(
            'file' => $file,
            'itemTypeId' => $this->session->itemTypeId,
            'columnNames' => $this->session->columnNames,
            'columnExamples' => $this->session->columnExamples,
        ));
        $this->view->form = $form;
                
        if (!$this->getRequest()->isPost()) {
            return;
        }
        if (!$form->isValid($this->getRequest()->getPost())) {
            return;
        }

        $columnMaps = $form->getMappings();
        if (count($columnMaps) == 0) {
            return $this->flashError('Please map at least one column to an '
                . 'element, file, or tag.');
        }
        
        $csvImport = new CsvImport_Import();
        $csvImport->initialize($file->getFilePath(), 
                               $this->session->itemTypeId, 
                               $this->session->collectionId, 
                               $this->session->itemsArePublic, 
                               $this->session->itemsAreFeatured, 
                               $this->session->stopOnError, 
                               $columnMaps);
        $csvImport->status = CsvImport_Import::STATUS_IN_PROGRESS_IMPORT;
        $csvImport->save();
        
        Zend_Registry::get('job_dispatcher')->send('CsvImport_ImportTask',
            array('importId' => $csvImport->id,
                  'memoryLimit' => $this->_getMemoryLimit()));

        $this->session->unsetAll();
        $this->flashSuccess('Successfully started the import. Reload this page '
            . 'for status updates.');
        $this->_helper->redirector->goto('browse');
    }
    
    public function undoImportAction()
    {
        $cit = $this->getTable('CsvImport_Import');
        $importId = $this->_getParam("id");
        $csvImport = $cit->find($importId);
        if ($csvImport) {
            
            $csvImport->status = CsvImport_Import::STATUS_IN_PROGRESS_UNDO_IMPORT;
            $csvImport->save();

            Zend_Registry::get('job_dispatcher')->send('CsvImport_ImportTask',
                array('importId' => $csvImport->id, 'method' => 'undoImport'));
        }
        $this->flashSuccess('Successfully started to undo the import. Reload '
            . 'this page for status updates.');
        $this->_helper->redirector->goto('status');
    }
    
    public function clearHistoryAction()
    {
        $cit = $this->getTable('CsvImport_Import');
        $importId = $this->_getParam("id");
        $csvImport = $cit->find($importId);
        if ($csvImport) {
            if ($csvImport->status == 
                CsvImport_Import::STATUS_COMPLETED_UNDO_IMPORT || 
                $csvImport->status == 
                CsvImport_Import::STATUS_IMPORT_ERROR_INVALID_CSV_FILE) {
                $csvImport->delete();
                $this->flashSuccess("Successfully cleared the history of the '
                    . 'import.");
            }
        }
        $this->_helper->redirector->goto('status');
    }
    
    private function _getMainForm()
    {
        require_once CSV_IMPORT_DIRECTORY . '/forms/Main.php';
        $config = $this->getInvokeArg('bootstrap')->config->plugins;
        if ($config && isset($config->CsvImport)) {
            $csvConfig = $config->CsvImport->toArray();
        }
        if (!array_key_exists('fileDestination', $csvConfig)) {
            $csvConfig['fileDestination'] = 
                Zend_Registry::get('storage')->getTempDir();
        }
        $form = new CsvImport_Form_Main($csvConfig);
        return $form;
    }

    private function _getMemoryLimit()
    {
        $config = $this->getInvokeArg('bootstrap')->config;
        if (isset($config->plugins->CsvImport)) {
            return (string)$config->plugins->CsvImport->memoryLimit;
        }
    }

    private function _getNavigation()
    {
        return new Zend_Navigation(array(
            array(
                'label' => 'Import Items',
                'action' => 'index',
                'module' => 'csv-import',
            ),
            array(
                'label' => 'Status',
                'action' => 'browse',
                'module' => 'csv-import',
            ),
        ));
    }
    
    private function _hasValidPHPCliPath()
    {
        try {
            $p = ProcessDispatcher::getPHPCliPath();
        } catch (Exception $e) {
            $this->flashError("Your PHP-CLI path setting is invalid.\n"  
                . "Please change the setting in " . CONFIG_DIR 
                . "/config.ini\nIf you do not know how to do this, please check "
                . "with your system or server administrator.");
            return false;
        }
        return true;
    }

    private function _sessionIsValid()
    {
        $requiredKeys = array('itemsArePublic', 'itemsAreFeatured', 
            'stopOnError', 'collectionId', 'itemTypeId');

        foreach ($requiredKeys as $key) {
            if (!isset($this->session->$key) 
                || !is_numeric($this->session->$key)
            ) {
                return false;
            }
        }
        return true;
    }
}
