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
 * Book imscp export lib
 *
 * @package    ebooktool_exportepub
 * @copyright  2001-3001 Antonio Vicent          {@link http://ludens.es}
 * @copyright  2001-3001 Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @copyright  2011 Petr Skoda                   {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once(dirname(__FILE__).'/lib.php');
require_once($CFG->dirroot.'/mod/book/locallib.php');
require_once(dirname(__FILE__).'/PHPePub/EPub.php');


/**
 * Export one ebook as IMSCP package
 *
 * @param stdClass $ebook ebook instance
 * @param context_module $context
 * @return bool|stored_file
 */
function booktool_exportepub_build_package($book, $context) {
    global $DB, $CFG;
    global $ebook;

    $ebook = new EPub(EPub::BOOK_VERSION_EPUB3, "en", EPub::DIRECTION_LEFT_TO_RIGHT); // Default is ePub 2

    $fs = get_file_storage();

    if ($packagefile = $fs->get_file($context->id, 'booktool_exportepub', 'package', $book->revision, '/', 'imscp.zip')) {
        return $packagefile;
    }

    //var_dump($book->intro);
    //exit;

    // fix structure and test if chapters present
    if (!book_preload_chapters($book)) {
        print_error('nochapters', 'booktool_exportepub');
    }

    // prepare epub book chapters
    booktool_exportepub_prepare_files($book,$context);

    //Check the book intro for an image, which can be used as the cover.
    $coverfiles = $fs->get_area_files($context->id, 'mod_book', 'intro');
   foreach($coverfiles as $file) {
        $cover_filepath = $file->get_filepath();
        $cover_filename = $file->get_filename();
        $cover_mimetype = $file->get_mimetype();
        $cover_itemid = $file->get_itemid();
        $cover_contenthash = $file->get_contenthash();
        $addFile = $fs->get_file($context->id, 'booktool_exportepub', 'temp', $cover_itemid, $cover_filepath, $cover_filename);
        //var_dump($addFile);
       // $cover_fullPath = $CFG->dataroot."/filedir/".$cover_contenthash[0].$cover_contenthash[1]."/".$cover_contenthash[2].$cover_contenthash[3]."/".$cover_contenthash;
        if($cover_mimetype == 'image/jpeg' || $cover_mimetype == 'image/png') {
            $ebook->setCoverImage($cover_filename, $file->get_content(), $cover_mimetype);
        }
    }

    $areafiles = $fs->get_area_files($context->id, 'booktool_exportepub', 'temp', $book->revision, "sortorder, itemid, filepath, filename", false);
    foreach($areafiles as $file) {
        $filepath = $file->get_filepath();
        $filename = $file->get_filename();
        $mimetype = $file->get_mimetype();
        $itemid = $file->get_itemid();
        $contenthash = $file->get_contenthash();
        $addFile = $fs->get_file($context->id, 'booktool_exportepub', 'temp', $itemid, $filepath, $filename);
        //var_dump($addFile);
        $fullPath = $CFG->dataroot."/filedir/".$contenthash[0].$contenthash[1]."/".$contenthash[2].$contenthash[3]."/".$contenthash;

        $ebook->addLargeFile($filename,$filename,$fullPath,$mimetype);
    }
    //$book->buildTOC(NULL, "toc", "Table of Contents", TRUE, TRUE);
    $ebook->finalize(); // Finalize the book, and build the archive.
    $zipData = $ebook->sendBook(clean_filename($book->name));

    unset($areafiles);

    // drop temp area
    $fs->delete_area_files($context->id, 'booktool_exportepub', 'temp', $book->revision);

    // delete older versions
    $sql = "SELECT DISTINCT itemid
              FROM {files}
             WHERE contextid = :contextid AND component = 'booktool_exportepub' AND itemid < :revision";
    $params = array('contextid'=>$context->id, 'revision'=>$book->revision);
    $revisions = $DB->get_records_sql($sql, $params);
    foreach ($revisions as $rev => $unused) {
        $fs->delete_area_files($context->id, 'booktool_exportepub', 'temp', $rev);
        $fs->delete_area_files($context->id, 'booktool_exportepub', 'package', $rev);
    }

    return $packagefile;
}

/**
 * Prepare temp area with the files used by ebook html contents
 *
 * @param stdClass $ebook ebook instance
 * @param context_module $context
 */
function booktool_exportepub_prepare_files($book, $context) {
    global $CFG, $DB;
    global $ebook;

    $ebook->setTitle(strip_tags($book->name)); //OPF file does not like extra tags!

    $metadata = parse_info($book->intro);
    $ebook->setIdentifier($metadata['uuid'],"UUID"); // Could also be the ISBN number, prefered for published books, or a UUID.
   // $book->setLanguage("en"); // Not needed, but included for the example, Language is mandatory, but EPub defaults to "en". Use RFC3066 Language codes, such as "en", "da", "fr" etc.
    
    $ebook->setDescription($metadata['description']);
    $ebook->setAuthor($metadata['author'], $metadata['authorrev']);
    $ebook->setPublisher($metadata['publisher']);

    $fs = get_file_storage();

    $temp_file_record = array('contextid'=>$context->id, 'component'=>'booktool_exportepub', 'filearea'=>'temp', 'itemid'=>$book->revision);


    $chapters = $DB->get_records('book_chapters', array('bookid'=>$book->id), 'pagenum');
    $chapterresources = array();

    foreach ($chapters as $chapter) {

        // Collate all files relating to chapter
        //$chapterresources[$chapter->id] = array();
        $files = $fs->get_area_files($context->id, 'mod_book', 'chapter', $chapter->id, "sortorder, itemid, filepath, filename", false);
        foreach ($files as $file) {
            $temp_file_record['filepath'] = '/'.$file->get_filepath();
            $fs->create_file_from_storedfile($temp_file_record, $file);
        }


        if ($file = $fs->get_file($context->id, 'booktool_exportepub', 'temp', $book->revision, "/", $chapter->id.'.html')) {
            // this should not exist
            $file->delete();
        }

        // Prepare chapter content for epub
        $content = booktool_exportepub_chapter_content($chapter, $context);
        // Add chapter to epub
        $ebook->addChapter($chapter->title, $chapter->id.".html", $content);
    }
    // Create CSS file
    $css_file_record = array('contextid'=>$context->id, 'component'=>'booktool_exportepub', 'filearea'=>'temp',
            'itemid'=>$book->revision, 'filepath'=>"/", 'filename'=>'styles.css');
    $fs->create_file_from_pathname($css_file_record, dirname(__FILE__).'/imscp.css');

    // Need to retrieve contents of CSS file as a string for ePub
    // since it won't read in the file directly.
    $cssfile = $fs->get_file($css_file_record['contextid'],$css_file_record['component'],$css_file_record['filearea'],$css_file_record['itemid'],$css_file_record['filepath'],$css_file_record['filename']);
    $cssData = $cssfile->get_content();

    $ebook->addCSSFile("styles.css","css1",$cssData);

}



/**
  Returns the html contents of one ebook's chapter to be exported as IMSCP
 
 @param stdClass $chapter the chapter to be exported
 @param context_module $context context the chapter belongs to
 @return string the contents of the chapter

 This has now been repurposed to prepare each chapter for epub.

 **/
function booktool_exportepub_chapter_content($chapter, $context) {

    $options = new stdClass();
    $options->noclean = false;
    $options->context = $context;

    $chaptercontent = str_replace('@@PLUGINFILE@@/', '', $chapter->content);
    $chaptercontent = format_text($chaptercontent, $chapter->contentformat, $options);

    #$chaptercontent = replace_iframe_tag($chaptercontent);

    $chaptertitle = format_string($chapter->title, true, array('context'=>$context));

    $content = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
    . "<html xmlns=\"http://www.w3.org/1999/xhtml\" xmlns:epub=\"http://www.idpf.org/2007/ops\">\n"
    . "<head>"
    . "<meta http-equiv=\"Default-Style\" content=\"text/html; charset=utf-8\" />\n"
    . "<link rel=\"stylesheet\" type=\"text/css\" href=\"styles.css\" />\n"
    . "<title>$chaptertitle</title>\n"
    . "</head>\n"
    . "<body>\n";
    $content .= '<h1>' . $chaptertitle . '</h1>' ."\n";
    $content .= $chaptercontent . "\n";
    $content .= '</body>' . "\n";
    $content .= '</html>' . "\n";

    return $content;
}


/**
  Parses the intro text of the book looking for tags with:
 
 Author
 Title
 Description
 Publisher
 UUID


 **/

 function parse_info($info) {
    global $book;
    $metdata = array();
    // Set defaults
    $metadata['author'] = "Firstname Lastname";
    $metadata['authorrev'] = "Lastname Firstname";
    $metadata['uuid'] = "UUID";
    $metadata['title'] = strip_tags($book->name);
    $metadata['description'] = "This is the description";
    $metadata['publisher'] = "";

    $dom = new DOMDocument;
    $dom->loadHTML($info);
    foreach($dom->getElementsByTagName('p') as $p) {
        $splitp = explode(":",$p->nodeValue);
        if(strtolower($splitp[0]) == "author") {
            $metadata['author'] = clean_text($splitp[1]);
            $metadata['authorrev'] = join(' ',array_reverse(explode(' ',clean_text($splitp[1]))));
        }
        elseif(strtolower($splitp[0]) == "uuid") $metadata['uuid'] = clean_text($splitp[1]);
        elseif(strtolower($splitp[0]) == "publisher") $metadata['publisher'] = clean_text($splitp[1]);
        elseif(strtolower($splitp[0]) == "title") $metadata['title'] = clean_text($splitp[1]);
        elseif(strtolower($splitp[0]) == "description") $metadata['description'] = clean_text($splitp[1]);
    }
    if($metadata['publisher'] == '') $metadata['publisher'] = $metadata['author'];

    return $metadata;
 }
