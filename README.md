# moodle-booktool_epubexport
Moodle plugin which adds ePUB export functionality to the Book plugin.

This plugin adds a link in the administration block to allow for the exporting of book module content as an EPUB v3.0

You can include a cover photo and metadata for an ePUB export by using the 'Edit Settings' link.  In the description fields, upload an image (jpg or png) and include the following labels. NOTE that these must be in a separate pargraph (usually created by hitting the enter key):

+ author: Firstname Lastname
+ title: Title of the book
+ description: A description of the book
+ publisher: The ePUB publisher (defaults to author)

## Installation

Hopefully, this plugin will be available on the [Moodle plugins](https://moodle.org/plugins/) page soon, but in the meantime, you can install the plugin by taking the following steps:

1. Download the plugin files from [GitHub](https://github.com/RichardPilbery/moodle-booktool_epubexport/archive/master.zip)
2. Unzip the file and rename the folder: epubexport
3. Zip the folder again, ensuring that it is named epubxport.zip
4. Head to your Moodle site and select Site administration > Plugins > Install plugins
5. Depending on your version, select Plugin type as: Book / Book tool (booktool)
6. Drag the zip file onto the ZIP package window
7. Tick/check the Acknowledgement box
8. Click the Install plugin from ZIP file button