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
 * epub export lib
 *
 * @package    booktool_epubexport
 * @copyright  2011 Petr Skoda {@link http://skodak.org}
 * @copyright  2015 Richard Pilbery {@link https://about.me/richardpilbery}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

/**
 * Adds module specific settings to the settings block
 *
 * @param settings_navigation $settings The settings navigation object
 * @param navigation_node $node The node to add module settings to
 */
function booktool_exportepub_extend_settings_navigation(settings_navigation $settings, navigation_node $node) {
    global $PAGE;
    
    if (has_capability('booktool/epubexport:export', $PAGE->cm->context)) {
        $url = new moodle_url('/mod/book/tool/epubexport/index.php', array('id'=>$PAGE->cm->id));
        $icon = new pix_icon('epubexportlogo', '', 'booktool_epubexport', array('class'=>'icon'));
        $node->add(get_string('generateepub', 'booktool_epubexport'), $url, navigation_node::TYPE_SETTING, null, null, $icon);
    }
}
