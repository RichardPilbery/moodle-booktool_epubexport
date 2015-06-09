<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Book epub export lib
 *
 * @package    booktool_epubexport
 * @copyright  2001-3001 Antonio Vicent          {@link http://ludens.es}
 * @copyright  2001-3001 Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @copyright  2011 Petr Skoda                   {@link http://skodak.org}
 * @copyright  2015 Richard Pilbery {@link https://about.me/richardpilbery}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once(dirname(__FILE__).'/lib.php');
require_once($CFG->dirroot.'/mod/book/locallib.php');

/**
 * Export one book as an EPUB
 *
 * @param stdClass $book book instance
 * @param context_module $context
 * @return bool|stored_file
 */
function booktool_epubexport_build_package($book, $context) {
    global $DB;

    $fs = get_file_storage();

    if ($packagefile = $fs->get_file($context->id, 'booktool_epubexport', 'package', $book->revision, '/', 'epub.zip')) {
        return $packagefile;
    }

    // Fix structure and test if chapters present.
    if (!book_preload_chapters($book)) {
        print_error('nochapters', 'booktool_epubexport');
    }

    // Prepare the files associated with each chapter and ...
    // Create files with the chapter content.
    booktool_epubexport_prepare_files($book, $context);

    $packer = get_file_packer('application/zip');
    $areafiles = $fs->get_area_files($context->id, 'booktool_epubexport', 'temp', $book->revision, 'timecreated DESC', false);
    $files = array();
    foreach ($areafiles as $file) {
        if ($file->get_filename() == 'mimetype') {
            $path = '/mimetype';
        } else if ($file->get_filename() == 'container.xml') {
            $path = '/META-INF/container.xml';
        } else {
            $path = '/CONTENTS/'.$file->get_filename();
        }
        $files[$path] = $file;
    }

    unset($areafiles);
    $packagefile = $packer->archive_to_storage($files,
                                               $context->id,
                                               'booktool_epubexport',
                                               'package',
                                               $book->revision,
                                               '/',
                                               'epub.zip');

    // Drop temp area.
    $fs->delete_area_files($context->id, 'booktool_epubexport', 'temp', $book->revision);

    // Delete older versions.
    $sql = "SELECT DISTINCT itemid
              FROM {files}
             WHERE contextid = :contextid AND component = 'booktool_epubexport' AND itemid < :revision";
    $params = array('contextid' => $context->id, 'revision' => $book->revision);
    $revisions = $DB->get_records_sql($sql, $params);
    foreach ($revisions as $rev => $unused) {
        $fs->delete_area_files($context->id, 'booktool_epubexport', 'temp', $rev);
        $fs->delete_area_files($context->id, 'booktool_epubexport', 'package', $rev);
    }

    return $packagefile;
}

/**
 * Prepare temp area with the files used by book html contents
 *
 * @param stdClass $book book instance
 * @param context_module $context
 */
function booktool_epubexport_prepare_files($book, $context) {
    global $CFG, $DB;

    $fs = get_file_storage();

    // Create the mimetype file for the epub.
    $mimetyperecord = array('contextid'=>$context->id, 
                             'component'=>'booktool_epubexport', 
                             'filearea'=>'temp',
                             'itemid'=>$book->revision, 
                             'filepath'=>"/", 
                             'filename'=>'mimetype');

    $mimetypestring = 'application/epub+zip';
    $fs->create_file_from_string($mimetyperecord, $mimetypestring);

    // Create the container.xml file for the epub.
    $containerrecord = array('contextid'=>$context->id, 
                              'component'=>'booktool_epubexport', 
                              'filearea'=>'temp',
                              'itemid'=>$book->revision, 
                              'filepath'=>"/", 
                              'filename'=>'container.xml');

    $containerstring = '<?xml version="1.0"?>' . "\n";
    $containerstring .= '<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">
    <rootfiles>
        <rootfile full-path="CONTENTS/package.opf" media-type="application/oebps-package+xml" />
    </rootfiles>' . "\n";
    $containerstring .= "</container>";
    $fs->create_file_from_string($containerrecord, $containerstring);

    // Obtain book chapters from the database.
    $chapters = $DB->get_records('book_chapters', array('bookid'=>$book->id), 'pagenum');
    $chapterresources = array();

    // Init arrays for package.obf manifest and spine data.
    $manifest = array();

    // Init integer to count number of files
    $numberoffiles = 0;

    $tempfilerecord = array('contextid'=>$context->id, 
                          'component'=>'booktool_epubexport', 
                          'filearea'=>'temp', 
                          'itemid'=>$book->revision);

    // Iterate through each of the chapters.
    foreach ($chapters as $chapter) {
        $files = $fs->get_area_files($context->id, 'mod_book', 'chapter', $chapter->id, "sortorder, itemid, filepath, filename", false);
        foreach ($files as $file) {
            $tempfilerecord['filepath'] = '/'.$chapter->pagenum.$file->get_filepath();
            $fs->create_file_from_storedfile($tempfilerecord, $file);
            $numberoffiles++;

            // Collect file information for package.opf manifest.
            $manifest[] = array('id'=>$file->get_filename(), 
                                'filename'=>$file->get_filename(), 
                                'mimetype'=>$file->get_mimetype());
        }

        if ($file = $fs->get_file($context->id, 'booktool_epubexport', 'temp', $book->revision, "/CONTENTS/", "$chapter->pagenum.html")) {
            $file->delete();
        }
        // Retrieve the content of this chapter as a string.
        $content = booktool_epubexport_chapter_content($chapter, $context);

        // Create a file called $chapter->pagenum.html from the chapter content.
        $indexfilerecord = array('contextid' => $context->id, 
                                   'component' => 'booktool_epubexport', 
                                   'filearea' => 'temp',
                                   'itemid' => $book->revision, 
                                   'filepath' => "/CONTENTS/", 
                                   'filename' => "$chapter->pagenum.html");

        $fs->create_file_from_string($indexfilerecord, $content);

        // Collect file information for package.opf manifest and spine
        $manifest[] = array('id' => 'chapter'.$chapter->pagenum, 
                            'filename' => $chapter->pagenum.'.html', 
                            'mimetype' => 'application/xhtml+xml',
                            'title' => $chapter->title);
    }

    // Create the stylesheet from the epub from the epub-bootstrap.css file in the plugin.
    $cssfilerecord = array('contextid' => $context->id, 
                             'component' => 'booktool_epubexport', 
                             'filearea' => 'temp',
                             'itemid' => $book->revision, 
                             'filepath' => "/CONTENTS/", 
                             'filename' => 'styles.css');

    $fs->create_file_from_pathname($cssfilerecord, dirname(__FILE__).'/epub-bootstrap.css');

    // Obtain the metadata for the epub from the book description field (called intro).
    $metadata = booktool_epubexport_parse_info($book, $context);

    // Set up some variables.
    $package        = '';
    $moodle_release = $CFG->release;
    $moodle_version = $CFG->version;
    $book_version   = get_config('mod_book', 'version');
    $bookname       = format_string($book->name, true, array('context'=>$context));

    // Create the package.opf file.
    // Opening statements.
    $package = '<?xml version="1.0"?>' . "\n";
    $package .= '<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid">' . "\n";
    $package .= '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">' . "\n";
    $package .= '    <dc:identifier id="uid">'.$metadata['uuid'].'</dc:identifier>' . "\n";
    $package .= '    <dc:language>'.$metadata['lang'].'</dc:language>' . "\n";
    $package .= '    <meta property="dcterms:modified">'.date('Y-m-d').'T00:00:00Z</meta>' . "\n";
    $package .= '    <dc:date>'.date('Y-m-d').'T00:00:00Z</dc:date>' . "\n";
    $package .= '    <dc:title>'.$metadata['title'].'</dc:title>' . "\n";
    $package .= '    <dc:description>'.$metadata['description'].'</dc:description>' . "\n";
    $package .= '    <dc:creator>'.$metadata['author'].'</dc:creator>' . "\n";
    $package .= '    <dc:publisher>'.$metadata['publisher'].'</dc:publisher>' . "\n";
    $package .= '    <dc:source>'.$CFG->wwwroot.'</dc:source>' . "\n";
    $package .= '    <meta name="generator" content="EPUB created using the Moodle plugin epubexport by Richard Pilbery, https://github.com/RichardPilbery/moodle-booktool_epubexport" />' . "\n";

    // Check if there's a cover image to add to the manifest.
    // For backwards compatability, it's also added in the metadata too.
    $coverimageinfo = array();

    if(!empty($metadata['coverimage'])) {
        $coverfile = $fs->get_file($context->id, 
                                   'booktool_epubexport', 
                                   'temp', $book->revision, 
                                    '/',
                                     $metadata['coverimage']);
        $coverimageinfo['filename'] = $coverfile->get_filename();
        $coverimageinfo['mimetype'] = $coverfile->get_mimetype();

        $package .= '   <meta name="'.$coverimageinfo['filename'].'" content="cover" />' . "\n";
    }

    $package .= '  </metadata>' . "\n";
    $package .= '  <manifest>' . "\n";

    // Add reference to table of contents file.
    $package .= '    <item id="toc" href="toc.xhtml" media-type="application/xhtml+xml" properties="nav" />' . "\n";

    // Add reference to cover image is there is one.
    if(!empty($metadata['coverimage'])) {
        $package .= '    <item id="cover" href="'.$coverimageinfo['filename'].'" properties="cover-image" media-type="'.$coverimageinfo['mimetype'].'" />' . "\n";
     }

    // Set the manifest.
    foreach($manifest as $m) {
        if(isset($m['id'])) {
            $package .= '    <item id="'.$m['id'].'" href="'.$m['filename'].'" media-type="'.$m['mimetype'].'" />' . "\n";            
        }
    }
    $package .= '    <item id="css" href="styles.css" media-type="text/css" />' . "\n";

    $package .= '  </manifest>' . "\n";

    // Set the spine
    $package .= '  <spine>' . "\n";
    foreach($manifest as $m) {
        if(isset($m['id'])) {
            if(substr($m['id'], 0, 7) == 'chapter' && $m['mimetype'] == 'application/xhtml+xml') {
                $package .= '    <itemref idref="'.$m['id'].'" />' . "\n";
            }
        }
    }
    $package .= '  </spine>' . "\n";
    $package .= '</package>';

    
    $packagefilerecord = array('contextid'=>$context->id, 'component'=>'booktool_epubexport', 'filearea'=>'temp',
            'itemid'=>$book->revision, 'filepath'=>"/", 'filename'=>'package.opf');
    $fs->create_file_from_string($packagefilerecord, $package);

    // Create the table of contents
    booktool_epubexport_toc($metadata['title'], $manifest, $tempfilerecord);

}

/**
 * Returns the html contents of one book's chapter to be exported as IMSCP
 *
 * @param stdClass $chapter the chapter to be exported
 * @param context_module $context context the chapter belongs to
 * @return string the contents of the chapter
 */
function booktool_epubexport_chapter_content($chapter, $context) {

    $options = new stdClass();
    $options->noclean = false;
    $options->context = $context;

    $chaptercontent = str_replace('@@PLUGINFILE@@/', '', $chapter->content);
    $chaptercontent = format_text($chaptercontent, $chapter->contentformat, $options);

    $chaptertitle = format_string($chapter->title, true, array('context'=>$context));

    $content  = '<?xml version="1.0" encoding="UTF-8"?>' ."\n";;
    $content .= '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops">' ."\n";;
    $content .= '  <head>' ."\n";;
    $content .= '    <meta http-equiv="Default-Style" content="text/html; charset=utf-8" />' ."\n";
    $content .= '<link rel="stylesheet" type="text/css" href="styles.css" />' . "\n";
    $content .= '    <title>'.$chaptertitle.'</title>' ."\n";
    $content .= '  </head>' . "\n";;
    $content .= '  <body>' . "\n";
    $content .= '    <h1>' . $chaptertitle . '</h1>' ."\n";
    $content .= '    '.$chaptercontent ."\n";
    $content .= '  </body>' . "\n";
    $content .= '</html>' . "\n";

    return $content;
}


 /**
 * Parses book->intro and returns string of metadata
 * @param stdClass $book book instance
 * @param context_module $context
 * @return array $metadata metadata for epub
 */
function booktool_epubexport_parse_info($book, $context) {

    // Init array and set defaults.
    $metdata                 = array();
    $metadata['author']      = 'Firstname Lastname';
    $metadata['authorrev']   = 'Lastname Firstname';
    $metadata['uuid']        = '00000000-0000-0000-0000-000000000000';
    $metadata['title']       = strip_tags($book->name);
    $metadata['description'] = 'This is the description';
    $metadata['publisher']   = '';
    $metadata['isbn10']      = '';
    $metadata['isbn13']      = '';
    $metadata['lang']        = current_language();
    $metadata['coverimage']  = '';

    //Check top see if there is an image that can be used as the front cover.
    $fs = get_file_storage();
    $coverfiles = $fs->get_area_files($context->id, 'mod_book', 'intro');
    foreach ($coverfiles as $file) {
        $coverfilename = $file->get_filename();
        $covermimetype = $file->get_mimetype();
        if($covermimetype == 'image/jpeg' || $covermimetype == 'image/png') {
            $metadata['uuid'] = booktool_epubexport_generate_uuid($file->get_contenthash());
            $tempfilerecord = array('contextid' => $context->id, 
                                      'component' => 'booktool_epubexport', 
                                      'filearea' => 'temp',
                                      'itemid' => $book->revision, 
                                      'filepath' => "/", 
                                      'filename' => $coverfilename);
            $fs->create_file_from_storedfile($tempfilerecord, $file);
            $metadata['coverimage'] = $coverfilename;
        }
    }

    if(!empty($book->intro)) {
        $dom = new DOMDocument;
        $dom->loadHTML($book->intro);
        foreach($dom->getElementsByTagName('p') as $p) {
            $splitp = explode(":",$p->nodeValue);
            if(strtolower($splitp[0]) == "author") {
                $metadata['author'] = clean_text($splitp[1]);
                $metadata['authorrev'] = join(' ',array_reverse(explode(' ',clean_text($splitp[1]))));
            }
            else if(strtolower($splitp[0]) == "publisher") {
                $metadata['publisher'] = clean_text($splitp[1]);
            }
            else if(strtolower($splitp[0]) == "title") {
                $metadata['title'] = clean_text($splitp[1]);
            }
            else if(strtolower($splitp[0]) == "description") {
                $metadata['description'] = clean_text($splitp[1]);
            }
        }
        
        if(empty($metadata['publisher'])) {
            $metadata['publisher'] = $metadata['author'];
        }
    }

    return $metadata;
 }


/**
 * Returns a UUID based on a given string of 40 characters
 *
 * @param string $hash
 * @return string $uuid
 */
function booktool_epubexport_generate_uuid($hash) {
    $first  = substr($hash, 0, 6);
    $second = substr($hash, 6, 4);
    $third  = substr($hash, 10, 4);
    $fourth = substr($hash, 14, 4);
    $fifth  = substr($hash, 18, 12);

    $uuid = "$first-$second-$third-$fourth-$fifth";

    return $uuid;
}

/**
 * Generate a table of contents for the epub and save it to file
 *
 * @param string $title
 * @param stdClass $chapters
 * @param array $temp_file_record
 */
function booktool_epubexport_toc($title, $chapters, $tempfilerecord) {
    $toc = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $toc .= '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" xml:lang="en" lang="en" dir="ltr">' . "\n";
    $toc .= '  <head>' . "\n";
    $toc .= '    <title>'.$title.'</title>' . "\n";
    $toc .= '    <meta http-equiv="default-style" content="text/html; charset=utf-8"/>' . "\n";
    $toc .= '    <link rel="stylesheet" type="text/css" href="styles.css" />' . "\n";
    $toc .= '  </head>' . "\n";
    $toc .= '  <body epub:type="frontmatter toc">' . "\n";
    $toc .= '    <header>' . "\n";
    $toc .= '      <h1>Table of Contents</h1>' . "\n";
    $toc .= '    </header>' . "\n";
    $toc .= '    <nav epub:type="toc" id="toc">' . "\n";
    $toc .= '      <ol epub:type="list">' . "\n";
    foreach($chapters as $c) {
        if(substr($c['id'], 0, 7) == 'chapter' && $c['mimetype'] == 'application/xhtml+xml') {
            $toc .= '        <li id="'.$c['id'].'" dir="ltr">' . "\n";
            $toc .= '          <a href="'.$c['filename'].'" >'.$c['title'].'</a>' . "\n";
            $toc .= '        </li>' . "\n";
        }
    }
    $toc .= '      </ol>' . "\n";
    $toc .= '    </nav>' . "\n";
    $toc .= '  </body>' . "\n";
    $toc .= '</html>' . "\n";

    $fs = get_file_storage();
    $tempfilerecord['filepath'] = '/CONTENTS/';
    $tempfilerecord['filename'] = 'toc.xhtml';
    $fs->create_file_from_string($tempfilerecord, $toc);
}