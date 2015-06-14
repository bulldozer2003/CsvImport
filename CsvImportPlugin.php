<?php
/**
 * CsvImportPlugin class - represents the Csv Import plugin
 *
 * @copyright Copyright 2008-2012 Roy Rosenzweig Center for History and New Media
 * @license http://www.gnu.org/licenses/gpl-3.0.txt GNU GPLv3
 * @package CsvImport
 */

defined('CSV_IMPORT_DIRECTORY') or define('CSV_IMPORT_DIRECTORY', dirname(__FILE__));

/**
 * Csv Import plugin.
 */
class CsvImportPlugin extends Omeka_Plugin_AbstractPlugin
{
    const MEMORY_LIMIT_OPTION_NAME = 'csv_import_memory_limit';
    const PHP_PATH_OPTION_NAME = 'csv_import_php_path';

    /**
     * @var array Hooks for the plugin.
     */
    protected $_hooks = array(
        'initialize',
        'install',
        'uninstall',
        'upgrade',
        'config_form',
        'config',
        'admin_head',
        'define_acl',
    );

    /**
     * @var array Filters for the plugin.
     */
    protected $_filters = array('admin_navigation_main');

    /**
     * @var array Options and their default values.
     */
    protected $_options = array(
        self::MEMORY_LIMIT_OPTION_NAME => '',
        self::PHP_PATH_OPTION_NAME => '',
        CsvImport_ColumnMap_IdentifierField::IDENTIFIER_FIELD_OPTION_NAME => CsvImport_ColumnMap_IdentifierField::DEFAULT_IDENTIFIER_FIELD,
        CsvImport_RowIterator::COLUMN_DELIMITER_OPTION_NAME => CsvImport_RowIterator::DEFAULT_COLUMN_DELIMITER,
        CsvImport_RowIterator::ENCLOSURE_OPTION_NAME => CsvImport_RowIterator::DEFAULT_ENCLOSURE,
        CsvImport_ColumnMap_Element::ELEMENT_DELIMITER_OPTION_NAME => CsvImport_ColumnMap_Element::DEFAULT_ELEMENT_DELIMITER,
        CsvImport_ColumnMap_Tag::TAG_DELIMITER_OPTION_NAME => CsvImport_ColumnMap_Tag::DEFAULT_TAG_DELIMITER,
        CsvImport_ColumnMap_File::FILE_DELIMITER_OPTION_NAME => CsvImport_ColumnMap_File::DEFAULT_FILE_DELIMITER,
        // Option used during the first step only.
        'csv_import_html_elements' => FALSE,
        'csv_import_automap_columns' => TRUE,
        'csv_import_create_collections' => FALSE,
        'csv_import_extra_data' => 'manual',
        // With roles, in particular if Guest User is installed.
        'csv_import_allow_roles' => 'a:1:{i:0;s:5:"super";}',
    );

    /**
     * Add the translations.
     */
    public function hookInitialize()
    {
        add_translation_source(dirname(__FILE__) . '/languages');

        // Get the backend settings from the security.ini file.
        // This simplifies tests too (use of local paths instead of urls).
        // TODO Probably a better location to set this.
        if (!Zend_Registry::isRegistered('csv_import')) {
            $iniFile = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'security.ini';
            $settings = new Zend_Config_Ini($iniFile, 'csv-import');
            Zend_Registry::set('csv_import', $settings);
        }
    }

    /**
     * Install the plugin.
     */
    public function hookInstall()
    {
        $db = $this->_db;

        // Create csv imports table.
        // Note: CsvImport_Import and CsvImport_ImportedRecord are standard Zend
        // records, but not Omeka ones fully.
        $db->query("CREATE TABLE IF NOT EXISTS `{$db->prefix}csv_import_imports` (
            `id` int(10) unsigned NOT NULL auto_increment,
            `format` varchar(255) collate utf8_unicode_ci NOT NULL,
            `delimiter` varchar(1) collate utf8_unicode_ci NOT NULL,
            `enclosure` varchar(1) collate utf8_unicode_ci NOT NULL,
            `status` varchar(255) collate utf8_unicode_ci,
            `row_count` int(10) unsigned NOT NULL,
            `skipped_row_count` int(10) unsigned NOT NULL,
            `skipped_record_count` int(10) unsigned NOT NULL,
            `updated_record_count` int(10) unsigned NOT NULL,
            `file_position` bigint unsigned NOT NULL,
            `original_filename` text collate utf8_unicode_ci NOT NULL,
            `file_path` text collate utf8_unicode_ci NOT NULL,
            `serialized_default_values` text collate utf8_unicode_ci NOT NULL,
            `serialized_column_maps` text collate utf8_unicode_ci NOT NULL,
            `owner_id` int unsigned NOT NULL,
            `added` timestamp NOT NULL default '0000-00-00 00:00:00',
            PRIMARY KEY  (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;");

        // Create csv imported records table.
        $db->query("CREATE TABLE IF NOT EXISTS `{$db->prefix}csv_import_imported_records` (
            `id` int(10) unsigned NOT NULL auto_increment,
            `import_id` int(10) unsigned NOT NULL,
            `record_type` varchar(50) collate utf8_unicode_ci NOT NULL,
            `record_id` int(10) unsigned NOT NULL,
            `identifier` varchar(255) collate utf8_unicode_ci NOT NULL,
            PRIMARY KEY  (`id`),
            KEY (`import_id`),
            KEY `record_type_record_id` (`record_type`, `record_id`),
            KEY (`identifier`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;");

        $this->_installOptions();
    }

    /**
     * Upgrade the plugin.
     */
    public function hookUpgrade($args)
    {
        $oldVersion = $args['old_version'];
        $newVersion = $args['new_version'];
        $db = $this->_db;

        if (version_compare($oldVersion, '2.0-dev', '<=')) {
            $sql = "UPDATE `{$db->prefix}csv_import_imports` SET `status` = ? WHERE `status` = ?";
            $db->query($sql, array('other_error', 'error'));
        }

        if (version_compare($oldVersion, '2.0', '<=')) {
            set_option(CsvImport_RowIterator::COLUMN_DELIMITER_OPTION_NAME, CsvImport_RowIterator::DEFAULT_COLUMN_DELIMITER);
            set_option(CsvImport_ColumnMap_Element::ELEMENT_DELIMITER_OPTION_NAME, CsvImport_ColumnMap_Element::DEFAULT_ELEMENT_DELIMITER);
            set_option(CsvImport_ColumnMap_Tag::TAG_DELIMITER_OPTION_NAME, CsvImport_ColumnMap_Tag::DEFAULT_TAG_DELIMITER);
            set_option(CsvImport_ColumnMap_File::FILE_DELIMITER_OPTION_NAME, CsvImport_ColumnMap_File::DEFAULT_FILE_DELIMITER);
            set_option('csv_import_html_elements', $this->_options['csv_import_html_elements']);
            set_option('csv_import_automap_columns', $this->_options['csv_import_automap_columns']);
        }

        if (version_compare($oldVersion, '2.0.1', '<=')) {
            $sql = "
                ALTER TABLE `{$db->prefix}csv_import_imports`
                CHANGE `item_type_id` `item_type_id` INT( 10 ) UNSIGNED NULL,
                CHANGE `collection_id` `collection_id` INT( 10 ) UNSIGNED NULL
            ";
            $db->query($sql);
        }

        if (version_compare($oldVersion, '2.0.3', '<=')) {
            set_option(CsvImport_RowIterator::ENCLOSURE_OPTION_NAME, CsvImport_RowIterator::DEFAULT_ENCLOSURE);
            $sql = "
                ALTER TABLE `{$db->prefix}csv_import_imports`
                ADD `format` varchar(255) collate utf8_unicode_ci NOT NULL AFTER `collection_id`,
                ADD `enclosure` varchar(1) collate utf8_unicode_ci NOT NULL AFTER `delimiter`,
                ADD `row_count` int(10) unsigned NOT NULL AFTER `status`
            ";
            $db->query($sql);

            // Update index. Item id is no more unique, because CsvImport can
            // import files separately, so an item can be updated. Furthermore,
            // now, any metadata can be updated individualy too.
            $sql = "
                ALTER TABLE `{$db->prefix}csv_import_imported_items`
                ADD `source_item_id` varchar(255) collate utf8_unicode_ci NOT NULL AFTER `item_id`,
                DROP INDEX `item_id`,
                ADD INDEX `source_item_id_import_id` (`source_item_id`, `import_id`)
            ";
            $db->query($sql);
        }

        if (version_compare($oldVersion, '2.1.1-full', '<')) {
            // Move all default values into a specific field.
            $sql = "
                ALTER TABLE `{$db->prefix}csv_import_imports`
                ADD `serialized_default_values` text collate utf8_unicode_ci NOT NULL AFTER `file_path`,
                ADD `updated_record_count` int(10) unsigned NOT NULL AFTER `skipped_item_count`
            ";
            $db->query($sql);

            // Keep previous default values.
            $table = $db->getTable('CsvImport_Import');
            $alias = $table->getTableAlias();
            $select = $table->getSelect();
            $select->reset(Zend_Db_Select::COLUMNS);
            $select->from(array(), array(
                $alias . '.id',
                $alias . '.item_type_id',
                $alias . '.collection_id',
                $alias . '.is_public',
                $alias . '.is_featured',
            ));
            $result = $table->fetchAll($select);
            $sql = "
                UPDATE `{$db->prefix}csv_import_imports`
                SET `serialized_default_values` = ?
                WHERE `id` = ?
            ";
            foreach ($result as $values) {
                $bind = $values;
                unset($bind['id']);
                $db->query($sql, array(serialize($bind), $values['id']));
            }

            // Reorder columns and change name of "skipped_item_count" column.
            $sql = "
                ALTER TABLE `{$db->prefix}csv_import_imports`
                DROP `item_type_id`,
                DROP `collection_id`,
                DROP `is_public`,
                DROP `is_featured`,
                CHANGE `format` `format` varchar(255) collate utf8_unicode_ci NOT NULL AFTER `id`,
                CHANGE `delimiter` `delimiter` varchar(1) collate utf8_unicode_ci NOT NULL AFTER `format`,
                CHANGE `enclosure` `enclosure` varchar(1) collate utf8_unicode_ci NOT NULL AFTER `delimiter`,
                CHANGE `status` `status` varchar(255) collate utf8_unicode_ci AFTER `enclosure`,
                CHANGE `row_count` `row_count` int(10) unsigned NOT NULL AFTER `status`,
                CHANGE `skipped_row_count` `skipped_row_count` int(10) unsigned NOT NULL AFTER `row_count`,
                CHANGE `skipped_item_count` `skipped_record_count` int(10) unsigned NOT NULL AFTER `skipped_row_count`,
                CHANGE `updated_record_count` `updated_record_count` int(10) unsigned NOT NULL AFTER `skipped_record_count`,
                CHANGE `file_position` `file_position` bigint unsigned NOT NULL AFTER `updated_record_count`,
                CHANGE `original_filename` `original_filename` text collate utf8_unicode_ci NOT NULL AFTER `file_position`,
                CHANGE `file_path` `file_path` text collate utf8_unicode_ci NOT NULL AFTER `original_filename`,
                CHANGE `serialized_default_values` `serialized_default_values` text collate utf8_unicode_ci NOT NULL AFTER `file_path`,
                CHANGE `serialized_column_maps` `serialized_column_maps` text collate utf8_unicode_ci NOT NULL AFTER `serialized_default_values`,
                CHANGE `owner_id` `owner_id` int unsigned NOT NULL AFTER `serialized_column_maps`,
                CHANGE `added` `added` timestamp NOT NULL default '0000-00-00 00:00:00' AFTER `owner_id`
            ";
            $db->query($sql);

            $sql = "
                ALTER TABLE `{$db->prefix}csv_import_imported_items`
                ADD `record_type` varchar(50) collate utf8_unicode_ci NOT NULL DEFAULT ''  AFTER `id`,
                CHANGE `import_id` `import_id` int(10) unsigned NOT NULL AFTER `id`,
                CHANGE `item_id` `record_id` int(10) unsigned NOT NULL AFTER `record_type`,
                CHANGE `source_item_id` `identifier` varchar(255) COLLATE 'utf8_unicode_ci' NOT NULL AFTER `record_id`,
                DROP INDEX `source_item_id_import_id`,
                ADD INDEX  `record_type_record_id` (`record_type`, `record_id`),
                ADD INDEX  `identifier` (`identifier`),
                RENAME TO `{$db->prefix}csv_import_imported_records`
            ";
            $db->query($sql);

            // Fill all record identifiers as Item.
            $sql = "UPDATE `{$db->prefix}csv_import_imported_records` SET `record_type` = 'Item'";
            $db->query($sql);
        }
    }

    /**
     * Uninstall the plugin.
     */
    public function hookUninstall()
    {
        $db = $this->_db;

        // Drop the tables.
        $sql = "DROP TABLE IF EXISTS `{$db->prefix}csv_import_imports`";
        $db->query($sql);
        $sql = "DROP TABLE IF EXISTS `{$db->prefix}csv_import_imported_records`";
        $db->query($sql);

        $this->_uninstallOptions();
    }

    /**
     * Shows plugin configuration page.
     */
    public function hookConfigForm($args)
    {
        $view = get_view();
        echo $view->partial(
            'plugins/csv-import-config-form.php'
        );
    }

    /**
     * Saves plugin configuration page.
     *
     * @param array Options set in the config form.
     */
    public function hookConfig($args)
    {
        $post = $args['post'];
        foreach (array(
                'csv_import_allow_roles',
            ) as $posted) {
            $post[$posted] = isset($post[$posted])
                ? serialize($post[$posted])
                : serialize(array());
        }
        foreach ($post as $key => $value) {
            set_option($key, $value);
        }
    }

    /**
     * Defines the plugin's access control list.
     *
     * @param array $args
     */
    public function hookDefineAcl($args)
    {
        $acl = $args['acl'];
        $resource = 'CsvImport_Index';

        // TODO This is currently needed for tests for an undetermined reason.
        if (!$acl->has($resource)) {
            $acl->addResource($resource);
        }
        // Hack to disable CRUD actions.
        $acl->deny(null, $resource, array('show', 'add', 'edit', 'delete'));
        $acl->deny(null, $resource);

        $roles = $acl->getRoles();

        // Check that all the roles exist, in case a plugin-added role has
        // been removed (e.g. GuestUser).
        $allowRoles = unserialize(get_option('csv_import_allow_roles')) ?: array();
        $allowRoles = array_intersect($roles, $allowRoles);
        if ($allowRoles) {
            $acl->allow($allowRoles, $resource);
        }

        $denyRoles = array_diff($roles, $allowRoles);
        if ($denyRoles) {
            $acl->deny($denyRoles, $resource);
        }
  }

    /**
     * Configure admin theme header.
     *
     * @param array $args
     */
    public function hookAdminHead($args)
    {
        $request = Zend_Controller_Front::getInstance()->getRequest();
        if ($request->getModuleName() == 'csv-import') {
            queue_css_file('csv-import');
            queue_js_file('csv-import');
        }
    }

    /**
     * Add the Simple Pages link to the admin main navigation.
     *
     * @param array Navigation array.
     * @return array Filtered navigation array.
     */
    public function filterAdminNavigationMain($nav)
    {
        $nav[] = array(
            'label' => __('CSV Import'),
            'uri' => url('csv-import'),
            'resource' => 'CsvImport_Index',
            'privilege' => 'index',
        );
        return $nav;
    }
}
