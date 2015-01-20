<?php
    echo head(array('title' => __('CSV Import')));
?>
<?php echo common('csvimport-nav'); ?>
<div id="primary">
    <h2><?php echo __('Status'); ?></h2>
    <?php echo flash(); ?>
    <div class="pagination"><?php echo pagination_links(); ?></div>
    <?php if (iterator_count(loop('CsvImport_Import'))): ?>
    <table class="simple" cellspacing="0" cellpadding="0">
        <thead>
            <tr>
                <?php
                $browseHeadings[__('Import date')] = 'added';
                $browseHeadings[__('CSV file')] = 'original_filename';
                $browseHeadings[__('Import type')] = null;
                $browseHeadings[__('Row count')] = 'row_count';
                $browseHeadings[__('Skipped rows')] = 'skipped_row_count';
                $browseHeadings[__('Imported records')] = null;
                $browseHeadings[__('Updated records')] = 'updated_record_count';
                $browseHeadings[__('Skipped records')] = 'skipped_record_count';
                $browseHeadings[__('Status')] = 'status';
                $browseHeadings[__('Action')] = null;
                echo browse_sort_links($browseHeadings, array('link_tag' => 'th scope="col"', 'list_tag' => ''));
                ?>
            </tr>
        </thead>
        <tbody>
            <?php $key = 0; ?>
            <?php foreach (loop('CsvImport_Import') as $csvImport): ?>
            <tr class="<?php if (++$key%2 == 1) echo 'odd'; else echo 'even'; ?>">

                <td><?php echo html_escape(format_date($csvImport->added, Zend_Date::DATETIME_SHORT)); ?></td>
                <td><?php echo html_escape($csvImport->original_filename); ?></td>
                <td><?php switch ($csvImport->format) {
                    case 'Manage': echo __('Manage records'); break;
                    case 'Report': echo __('Csv Report'); break;
                    case 'Item': echo __('Items'); break;
                    // Deprecated, but kept for old imports.
                    case 'File': echo __('Files metadata'); break;
                    case 'Mix': echo __('Mixed records'); break;
                    case 'Update': echo __('Update records'); break;
                    // Imports made with the standard plugin.
                    default: echo __('Unknown'); break;
                } ?></td>
                <?php $importedRecordCount = $csvImport->getImportedRecordCount(); ?>
                <td><?php echo html_escape($csvImport->row_count); ?></td>
                <td><?php echo html_escape($csvImport->skipped_row_count); ?></td>
                <td><?php echo html_escape($importedRecordCount); ?></td>
                <td><?php echo html_escape($csvImport->updated_record_count); ?></td>
                <td><?php echo html_escape($csvImport->skipped_record_count); ?></td>

                <td><?php echo html_escape(__(Inflector::humanize($csvImport->status, 'all'))); ?></td>
                <td>
                <?php
                    if (!in_array($csvImport->format, array('File', 'Update'))
                        && (($csvImport->isCompleted() && $importedRecordCount > 0)
                            || $csvImport->isStopped()
                            || ($csvImport->isImportError() && $importedRecordCount > 0))):
                        $undoImportUrl = $this->url(array(
                                'action' => 'undo-import',
                                'id' => $csvImport->id,
                            ),
                            'default');
                ?>
                    <a href="<?php echo html_escape($undoImportUrl); ?>" class="csv-undo-import delete-button"><?php echo html_escape(__('Undo Import')); ?></a>
                <?php
                    elseif (
                        ($csvImport->isUndone()
                            || $csvImport->isUndoImportError()
                            || $csvImport->isOtherError()
                            || ($csvImport->isCompleted() && $importedRecordCount == 0)
                            || ($csvImport->isImportError() && $importedRecordCount == 0))):
                        $clearHistoryImportUrl = $this->url(array(
                                'action' => 'clear-history',
                                'id' => $csvImport->id,
                            ),
                            'default');
                ?>
                    <a href="<?php echo html_escape($clearHistoryImportUrl); ?>" class="csv-clear-history delete-button"><?php echo html_escape(__('Clear History')); ?></a>
                <?php
                    else:
                        echo __('No action');
                    endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
        <p><?php echo __('You have no imports yet.'); ?></p>
    <?php endif; ?>

</div>
<script type="text/javascript">
//<![CDATA[
jQuery(document).ready(function () {
    Omeka.CsvImport.confirm();
});
//]]>
</script>
<?php
    echo foot();
?>
