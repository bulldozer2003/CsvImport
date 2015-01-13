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
- import of metadata of files and collections;
- update of collections, items, files and any other standard records;
- import extra data of records that are not managed via elements but via
specific tables.

The similar tool [Xml Import] can be useful too, depending on your types of
data.


Installation
------------

Uncompress files and rename plugin folder "CsvImport".

Then install it like any other Omeka plugin and follow the config instructions.

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


Examples
--------

Ten examples of csv files are available in the csv_files folder. They use free
images of [Wikipedia], so import speed depends on the connection:

* `test.csv`

    A basic list of three books with images of Wikipedia, with non Dublin Core
    tags.

* `test_automap.csv`

    The same list with some Dublin Core attributes in order to automap the
    columns with the Omeka fields.

    To try them, you just need to check `Item metadata`, to use the default
    delimiters `,` and, for the second file, to check option `Automap column`.
    Note that even you don't use the Automap option, the plugin will try to get
    matching columns if field names are the same in your file and in the
    drop-down list.

* `test_special_delimiters.csv`

    A file to try any delimiters. Special delimiters of this file are:
    - Column delimiter: tabulation
    - Enclosure: quotation mark "
    - Element delimiter: custom ^^ (used by Csv Report)
    - Tag delimiter: double space
    - File delimiter: semi-colon

* `test_files_metadata.csv`

    A file used to import metadata of files. To try it, you should import items
    before with any of previous csv files, and check `File metadata` in the
    first form and `Filename` in the first row of the second form.

* `test_mixed_records.csv`

    A file used to show how to import metadata of item and files simultaneously,
    and to import files one by one to avoid server overloading. To try it, you
    should check `Mixed records` in the form and choose `tabulation` as column
    delimiter, `"` as enclosure, `#` as element delimiter and `;` as tag
    delimiter. Note that in the csv file, file rows should always be  after the
    item to which they are attached.

* `test_mixed_records_update.csv`

    A file used to show how to update metadata of item and files. To try it, you
    should import "test_mixed_recods.csv" above first, then choose this file and
    check `Update records` in the form.

* `test_collection.csv`

    Add an item into a new collection. A created collection is not removed if an
    error occurs during import. Parameters are "Mixed records", `tabulation` as
    column delimiter and no enclosure.

* `test_collection_update.csv`

    Update metadata of a collection. Parameters are the same as in the previous
    file, except format.

* `test_extra_data.csv`

    Show import of extra data that are not managed as elements, but as data in
    a specific table. The mechanism processes data as post, so it can uses the
    default hooks, specially `after_save_item`. To try this test file, you
    should install [Geolocation] and to import mixed records with `tabulation`
    as column delimiter, no enclosure, and `|` as element, file and tag
    delimiters. You should set the option "Contains extra data" to "Yes" too.

* `test_extra_data_update.csv`

    Show update of extra data. To test it, you need to import the previous file
    first, then this one, with the same options (just change the format to
    "Update").

Columns can be ordered in any order.


Notes
------

* Import and update of extra data

    - The formats "Mixed records" and "Update" should be used.
    - The "Contains extra data" parameter should be set to "Yes".
    - Columns should be named like the post field in the hooks `before_save_*`
    or `after_save_*`. If the plugin does not use these hooks, they can be set
    in a specific plugin.
    - All needed columns should exists in the file, according to the model of
    record and the hooks. For example, the import of data for the [Geolocation]
    plugin implies to set not only "latitude" and "longitude", but "zoom_level",
    "map_type" and "address" too. Their values can be set to empty or not.
    - The header of the columns should be the name used in the manual standard
    form.
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

* Update mode

In the csv file, four columns may be added comparing to a normal file:

    - `updateMode`

        The mode of update can be:
            - "Add" (add values to fields),
            - "Replace" (remove values of the field before inserting new ones),
            - "Replace all" (remove values of all imported fields before
            inserting new ones).
        This column is optional: by default, values are added.

    - `updateIdentifier`

        This column is optional: by default, the identifier is the "internal id"
        of the record. If another one is used, for example
        "Dublin Core:Identifier", "Dublin Core:Title" or "original_filename",
        it should be unique, else only the first existing record will be
        updated.

    - `recordType`

        The default record type is "Item", but each "File", "Collection" or any
        other standard record can be updated too. "Any" can be used only when
        identifier is not the internal id.

    - `recordIdentifier`

        This column is mandatory. According to `updateIdentifier` column, it can
        be an internal id or anything else. If the record doesn't exist, the row
        is skipped.

Note: Currently, to update the collection, the item type, the public and
featured parameters, and the tags, the standard Omeka "Modify" button should
be used. To attach a file to an item, you need to use the column name
`file`.

* Characters encoding

Depending of your environment and database, if you imports items with encoded
urls, they should be decoded when you import files. For example, you can import
an item with the file `Edmond_Dant%C3%A8s.jpg`, but you may import your file
metadata with the filename `Edmond_Dantès.jpg`. Furthermore, filenames may be or
not case sensitive.


Status page
-----------

The status page indicates situation of previous, queued and current imports. You
can make an action on any import process.

Note that you can't undo a files metadata import, because previous ones are
overwritten.

The column "Skipped rows" means that some imported lines were non complete or
with too many columns, so you need to check your import file.

The column "Skipped records" means that an item or a file can't be created,
usually because of a bad url or a bad formatted row. You can check `error.log`
for information.

Currently, only imported items are counted.


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
[fork issues]: https://github.com/Daniel-KM/CsvImport/Issues
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[Center for History & New Media]: http://chnm.gmu.edu
[Daniel-KM]: https://github.com/Daniel-KM "Daniel Berthereau"
[saverkamp]: https://github.com/saverkamp "saverkamp"
[University of Iowa Libraries]: http://www.lib.uiowa.edu
[École des Ponts ParisTech]: http://bibliotheque.enpc.fr
[Pop Up Archive]: http://popuparchive.org
[Mines ParisTech]: http://bib.mines-paristech.fr
