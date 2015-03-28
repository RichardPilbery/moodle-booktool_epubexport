# moodle-booktool_exportepub
Moodle plugin which adds ePUB export functionality to the Book plugin. It includes the excellent PHP class PHPePUB created by Asbjørn Grandt (https://github.com/Grandt/PHPePub)


Install this plugin as you would any other in Moodle.
You can include a cover photo and metadata for an ePUB export by including an image in the description field of the Book and by using the following tags:
+ author: Firstname Lastname
+ title: Title of the book
+ description: A description of the book
+ publisher: The ePUB publisher (defaults to author)
+ UUID: Unique identification number/code

These should each be in a separate paragraph.
