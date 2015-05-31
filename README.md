CSV Import (plugin for Omeka)
=============================


[CSV Import] is a plugin for [Omeka] that allows users to import or update items
from a simple CSV (comma separated values) file, and then map the CSV column
data to multiple elements, files, and/or tags. Each row in the file represents
metadata for a single item.
This plugin is useful for exporting data from one database and importing that
data into an Omeka site.

This fork adds some improvments:
- more options for import;
- import of metadata of collections and files;
- update of collections, items and files;
- import extra data of records that are not managed via standard elements but
via specific tables.

The similar tool [Xml Import] can be useful too, depending on your types of
data.


Installation
------------

Uncompress files and rename plugin folder "CsvImport".

Then install it like any other Omeka plugin and follow the config instructions.

If you want to use local files inside the file system, the allowed base path or
a parent should be defined before in the file "security.ini" of the plugin.

Set the proper settings in config.ini like so:

```
plugins.CsvImport.columnDelimiter = ","
plugins.CsvImport.enclosure = '"'
plugins.CsvImport.memoryLimit = "128M"
plugins.CsvImport.requiredExtension = "txt"
plugins.CsvImport.requiredMimeType = "text/csv"
plugins.CsvImport.maxFileSize = "10M"
plugins.CsvImport.fileDestination = "/tmp"
plugins.CsvImport.batchSize = "1000"
```

All of the above settings are optional.  If not given, CsvImport uses the
following default values:

```
memoryLimit = current script limit
requiredExtension = "txt" or "csv"
requiredMimeType = "text/csv"
maxFileSize = current system upload limit
fileDestination = current system temporary dir (via sys_get_temp_dir())
batchSize = 0 (no batching)
```

Set a high memory limit to avoid memory allocation issues with imports.
Examples include 128M, 1G, and -1. This will set PHP's memory_limit setting
directly, see PHP's documentation for more info on formatting this number. Be
advised that many web hosts set a maximum memory limit, so this setting may be
ignored if it exceeds the maximum allowable limit. Check with your web host for
more information.

Note that 'maxFileSize' will not affect 'post_max_size' or 'upload_max_filesize'
as is set in 'php.ini'. Having a maxFileSize that exceeds either will still
result in errors that prevent the file upload.

'batchSize': Setting for advanced users.  If you find that your long-running
imports are using too much memory or otherwise hogging system resources, set
this value to split your import into multiple jobs based on the number of CSV
rows to process per job.

For example, if you have a CSV with 150000 rows, setting a batchSize of 5000
would cause the import to be split up over 30 separate jobs.
Note that these jobs run sequentially based on the results of prior jobs,
meaning that the import cannot be parallelized.  The first job will import
5000 rows and then spawn the next job, and so on until the import is completed.

_Important_

On some servers, in particular with shared hosts, an option should be changed in
the application/config/config.ini file:

```
jobs.dispatcher.longRunning = "Omeka_Job_Dispatcher_Adapter_BackgroundProcess"
```

by

```
jobs.dispatcher.longRunning = "Omeka_Job_Dispatcher_Adapter_Synchronous"
```

Note that this change may limit the number of lines imported by job. If so, you
can increase the time limit for process in the server or php configuration.

_Note about local paths_

For security reasons, to import files from local file system is forbidden.
Nevertheless, it can be allowed for a specific path. This allowed base path or a
parent should be defined in the file "security.ini" of the plugin.


Examples
--------

Fourteen examples of csv files are available in the csv_files folder. They are
many because a new one is built for each new feature. The last ones uses all of
them.

They can be all imported with all formats (except "Omeka Report"). For this, you
should select the manual mode. This is the only one for "Item" and "Files", but
optional for others, with the parameter "Contains extra data".

Some files may be updated with a second file to get full data. This is just to
have some examples.

They use free images of [Wikipedia], so import speed depends on the connection.

1. `test.csv`

    A basic list of three books with images of Wikipedia, with non Dublin Core
    tags. To try it, you just need to check `Item metadata`, to use the default
    delimiters `,` and enclosure `"`.

2. `test_automap.csv`

    The same list with some Dublin Core attributes in order to automap the
    columns with the Omeka fields. To try it, use the same parameter than the
    previous file and check option `Automap column`.

    Note that even you don't use the Automap option, the plugin will try to get
    matching columns if field names are the same in your file and in the
    drop-down list.

3. `test_special_delimiters.csv`

    A file to try any delimiters. Special delimiters of this file are:
    - Column delimiter: tabulation
    - Enclosure: quotation mark "
    - Element delimiter: custom ^^ (used by Csv Report)
    - Tag delimiter: double space
    - File delimiter: semi-colon

4. `test_files_metadata.csv`

    A file used to import metadata of files. To try it, you should import items
    before with any of previous csv files, select `tabulation` as column
    delimiter, no enclosure, and `|` as element, file and tag delimiters, and
    check `File metadata` in the first form and `Filename` in the first row of
    the second form.

5. `test_mixed_records.csv`

    A file used to show how to import metadata of item and files simultaneously,
    and to import files one by one to avoid server overloading or timeout. To
    try it, check `Mixed records` in the form and choose `tabulation` as column
    delimiter, no enclosure, and `|` as element, file and tag delimiters.

    Note: in the csv file, the file rows should always be after the item to
    which they are attached, else they are skipped.

6. `test_mixed_records_update.csv`

    A file used to show how to update metadata of item and files. To try it,
    import `test_mixed_recods.csv` above first, then choose this file and check
    `Update records` in the form.

7. `test_collection.csv`

    Add an item into a new collection. A created collection is not removed if an
    error occurs during import. Parameters are `Mixed records`, `tabulation` as
    column delimiter, no enclosure and `|` as element, file and tag delimiters.

8. `test_collection_update.csv`

    Update metadata of a collection. Parameters are the same as in the previous
    file, except format.

9. `test_extra_data.csv`

    Show import of extra data that are not managed as elements, but as data in
    a specific table. The mechanism processes data as post, so it can uses the
    default hooks, specially `after_save_item`.
    The last row shows an example to import one item with attached files on one
    row (unused columns, specially sourceItemId and recordtType, can be
    removed). This simpler format can be used if you don't need files metadata
    or if you don't have a lot of files attached to each item.
    To try this test file, install [Geolocation] and check `Mixed records` with
    `tabulation` as column delimiter, no enclosure, and `|` as element, file and
    tag delimiters. You should set the option "Contains extra data" to "Yes" too
    (or "Manual" to check manually).
    Use the update below to get full data for all items.

10. `test_extra_data_manual.csv`

    This file has the same content than the previous, but header are not set, so
    you should set "Contains extra data" to "Manual" to map them to the Omeka
    metadata. Note that extra data should kept their original headers.

11. `test_extra_data_update.csv`

    Show update of extra data. To test it, you need to import one of the two
    previous files first, then this one, with the same parameters, except the
    `Update records` format.

12. `test_manage_one.csv`

13. `test_manage_two.csv`

14. `test_manage_script.csv`

    These files show how to use the "Manage" process. They don't use a specific
    column, but any field. So, each row is independant from others.
    The first allows to import some data and the second, similar, has got new
    and updated content. The third is like a script where each row is processed
    one by one, with a different action for each row.
    To try them, you may install [Geolocation] and to check `Manage Records`
    with `tabulation` as column delimiter, no enclosure, `|` as element, file,
    and tag delimiters, and `Dublin Core:Identifier` as the field identifier.
    If you import them manually, the special value "Identifier" should be set
    too for the Dublin Core:Identifier, so this column will be used as
    identifier and as a metadata. The third should be imported after the first
    and the second to see changes.


Formats
-------

1. `Manage Records`

    `Manage records` allows to manage creation, update and deletion of all
    records with the same file (or different ones if you want). See below for
    possible actions.

    Be warned that if you use always the same csv file and that you update
    records from the Omeka admin board too, they can be desynchronized and
    overwritten.

    Each row is independant from the other. So a file can be imported before an
    item and an item in a collection that doesn't exist yet.

    Three columns may be used to identify records between the csv file and
    Omeka. If they are not present, the default values will be used.

    - `Identifier`
    All records should have a unique identifier. According to `IdentifierField`
    column or the default parameter, it can be an internal id or any other
    metadata field. It can be a specific identifier of the current file too, but
    in that case, the identifier is available only for the current import.
    When the identifier field is a metadata, this column is optional as long as
    this metadata has got a column.
    If it is empty and identifier is not set in a metadata column, the only
    available action is "Create". If the record doesn't exist when updating, the
    row will be skipped.
    Note: When the mapping is done manually and when the field is a metadata,
    the column should be mapped twice, one as a metadata and the second as a
    special value "Identifier".

    - `IdentifierField`
    This column is optional: by default, the identifier field is set in the main
    form. It should be unique, else only the first existing record will be
    updated. It can be the "internal id" of the record in Omeka. Recommendation
    is to use a specific field, in particular "Dublin Core:Identifier" or an
    added internal field. Files can be identified by their "original filename",
    Omeka "filename" and "md5" authentication sum too.

    - `RecordType`
    The record type can be "Collection", "Item" or "File". "Any" can be used
    only when identifier is not the internal id and when the identifier is
    unique accross all records. If empty, the record type is determined
    according to other columns when possible. If not, the record is an item.

2. `Omeka Csv Report`

    This is an internal format of Omeka, that you can use if you have such a
    file.

3. `Items`

    This is not really a format, because you can map manually any csv file in a
    second step. See file example `test.csv` or `test_automap.csv.

4. `Files metadata`

    This format allows to update existing files with metadata. The files should
    exist already. So, they should have been added or imported previously.

    Files can be identified with their "internal id", their "original filename",
    their Omeka "filename" or their md5 sum. If used, tjhe filename should be
    unique to avoid update of a vrong file.

    Like for `Items`, the names of the headers are free because a manual mapping
    is made in a second step.

    Because this format updates and overrides existing data if any, it cannot be
    undone, but you can update data muliple times.

5. `Mixed records` (deprecated)

    The formats `Mixed records` and `Update` have been deprecated and replaced
    by `Manage records`, that is simpler and more resilient.

    The columns they use are the next ones.

    - `sourceItemId` (removed)
    Allows to set the relation between a file and an item. This is needed only
    when files are imported separately.

    - `recordType` (replaced by `RecordType`, see above)
    This column is needed only when files that are attached to an item are
    imported separately in order to add their metadata or to avoid a server
    overload or timeout. If you don't use this column and want to import a file
    separately, the file column header should be "fileUrl" or manually mapped to
    "Zero or one file".

    The mapping can be done manually in a second step if wished.

    Files can be imported as in `Items`, with multiple paths or urls in the same
    column and with other metadata of the item, or separately, with one file by
    row, that can avoid avoid server overload or timeout when attached files are
    big or many. To attach a file to an item with `Update`, use the column name
    `file`.

6. `Update records` (deprecated)

    This format is the same than `Mixed records`, except it adds optional
    columns.

    - `updateMode` (replaced by `Action`, see below)
    Only "Update" (default), "Add" and "Replace" modes are available. The
    default is "Update".

    - `recordIdentifier` (replaced by `Identifier`)
    This column is mandatory when files are imported separately from the items.
    According to `updateIdentifier` column, it can be an internal id or anything
    else. If the record doesn't exist, the row is skipped.

    - `updateIdentifier` (replaced by `IdentifierField`)
    This column is optional: by default, the identifier is the "internal id"
    of the record. If another one is used, for example "Dublin Core:Identifier",
    "Dublin Core:Title", it should be unique, else only the first existing
    record will be updated (at least unique for the record type). Files can be
    identified too by their "original filename", Omeka "filename" and "md5" sum.

    Because this format updates and overrides existing data if any, it cannot be
    undone, but you can update data muliple times.


Notes
-----

* Columns

  - Columns can be ordered in any order.
  - Columns names are case sensitive.
  - Spaces can be added before or after the default column name separator `:`,
  except for extra data and the identifier field, when they are imported
  automatically.
  - Manual mapping is slower, conducive to careless mistakes, more boring than
  automatic import, but it allows to map some columns with more than one field.
  For example, a column of tags can be mapped as a Dublin Core Subject too.
  Furthermore, it allows too to set each element as an html one or not.
  - Item type can be changed, but not unset.
  - Tags can be added to an item, but not removed.

* Characters encoding

Depending of your environment and database, if you imports items with encoded
urls, they should be decoded when you import files. For example, you can import
an item with the file `Edmond_Dant%C3%A8s.jpg`, but you may import your file
metadata with the filename `Edmond_Dantès.jpg`. Furthermore, filenames may be or
not case sensitive.

* Update of attached files

Files that are attached to an item can be fully updated. If the url is not the
same than existing ones, the file will be added. If it is the same, no reimport
will be done. To reimport a file with the same url, you should remove it first.
This process avoids many careless errors.
Files are ordered according to the list of files.
Note : This process works only when original filenames are unique.

* Status page

The status page indicates situation of previous, queued and current imports. You
can make an action on any import process.

Note that you can't undo an update, because previous metadata are overwritten.

The column "Skipped rows" means that some imported lines were non complete or
with too many columns, so you need to check your import file.

The column "Skipped records" means that an item or a file can't be created,
usually because of a bad url or a bad formatted row. You can check `error.log`
for information.

The count of imported records can be different from the number of rows, because
some rows can be update ones. Furthermore, with "Manage" format, multiple
records can be created with one row. Files attached directly to items are not
counted.

* Available actions with `Manage` format

The column `Action` used with the `Manage` format allows to set the action to do
for the current row. This parameter is optional and can be set in the first step
of import.

The actions can be (not case sensitive):
    - Empty or "Update else create" (default): Update the record if it exists, else
    create a new one.
    - "Create": Insert a new record if the identifier doesn't exist yet.
    - "Update": Update fields of the record, so remove values of all fields that
    are imported before inserting the new values.
    - "Add": Add values to fields.
    - "Replace": Remove only values of imported fields whose values are not
    empty, then update fields with new values.
    - "Delete": Remove the record (and files, if the record is an item).
    - "Skip": Skip the row and record from any process.

_Important_

This mode doesn't apply to extra data, because the way the plugins manage
updates of their data varies. So existing data may be needed in the update file
in order to not be overwritten (this is the case for the [Geolocation] plugin).

* Management of extra data

Extra data are managed by plugins, so some differences should be noted.
    - The formats `Manage records` should be used. `Mixed records` and
    `Update records` can process them too.
    - The `Contains extra data` parameter should be set to "Yes" or "Manual".
    - The header of each extra data column should be the name used in the manual
    standard form.
    - Columns can't be mapped manually to a specifc plugin, so they should be
    named like the post fields in the hooks `before_save_*` or `after_save_*`.
    If the plugin does not use these hooks, they can be set in a specific
    plugin.
    - All needed columns should exists in the file, according to the model of
    record and the hooks. For example, the import of data for the [Geolocation]
    plugin implies to set not only "latitude" and "longitude", but "zoom_level"
    too. "map_type" and "address" can be needed too in a next release of the
    plugin. Their values can be set to empty or not.
    - If the model allows the data to be multivalued, the column name should be
    appended with a ':'.
    - For update, as the way the plugins manage updates of their data varies,
    the `updateMode` is not used for extra data. So existing data may be needed
    in the update file in order to not be overwritten (this is the case for the
    [Geolocation] plugin).
    - As Omeka jobs don't manage ACL, if a plugin uses it (usually no), the jobs
    displatcher should be the synchronous one and be set in config.ini, so the
    ACL will use the one of the current user:
    ```
    jobs.dispatcher.longRunning = "Omeka_Job_Dispatcher_Adapter_Synchronous"
    ```


Warning
-------

Use it at your own risk.

It's always recommended to backup your files and database regularly so you can
roll back if needed.


Troubleshooting
---------------

See online [Csv Import issues] and [fork issues].


License
-------

This plugin is published under [GNU/GPL].

This program is free software; you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation; either version 3 of the License, or (at your option) any later
version.

This program is distributed in the hope that it will be useful, but WITHOUT
ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
FOR A PARTICULAR PURPOSE. See the GNU General Public License for more
details.

You should have received a copy of the GNU General Public License along with
this program; if not, write to the Free Software Foundation, Inc.,
51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.


Contact
-------

Current maintainers:

* [Center for History & New Media]
* Daniel Berthereau (see [Daniel-KM])

This plugin has been built by [Center for History & New Media]. Next, the
release 1.3.4 has been forked for [University of Iowa Libraries] and upgraded
for [École des Ponts ParisTech] and [Pop Up Archive]. The fork of this plugin
has been upgraded for Omeka 2.0 for [Mines ParisTech].


Copyright
---------

* Copyright Center for History and New Media, 2008-2013
* Copyright Daniel Berthereau, 2012-2015
* Copyright Shawn Averkamp, 2012


[Omeka]: https://omeka.org
[Csv Import]: https://github.com/omeka/plugin-CsvImport
[Xml Import]: https://github.com/Daniel-KM/XmlImport
[Wikipedia]: https://www.wikipedia.org
[Geolocation]: http://omeka.org/add-ons/plugins/geolocation
[Csv Import issues]: http://omeka.org/forums/forum/plugins
[fork issues]: https://github.com/Daniel-KM/CsvImport/issues
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[Center for History & New Media]: http://chnm.gmu.edu
[Daniel-KM]: https://github.com/Daniel-KM "Daniel Berthereau"
[saverkamp]: https://github.com/saverkamp "saverkamp"
[University of Iowa Libraries]: http://www.lib.uiowa.edu
[École des Ponts ParisTech]: http://bibliotheque.enpc.fr
[Pop Up Archive]: http://popuparchive.org
[Mines ParisTech]: http://bib.mines-paristech.fr
