<?php

// This file is part of the EQUELLA Moodle Integration - https://github.com/equella/moodle-module
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

require_once($CFG->dirroot.'/mod/equella/common/lib.php');
require_once($CFG->dirroot.'/lib/filelib.php');
require_once($CFG->dirroot.'/course/lib.php');
require_once(dirname(__FILE__) . '/equella_rest_api.php');

define('EQUELLA_CONFIG_SELECT_RESTRICT_NONE', 'none');
define('EQUELLA_CONFIG_SELECT_RESTRICT_ITEMS_ONLY', 'itemonly');
define('EQUELLA_CONFIG_SELECT_RESTRICT_ATTACHMENTS_ONLY', 'attachmentonly');

define('EQUELLA_CONFIG_INTERCEPT_NONE', 0);
define('EQUELLA_CONFIG_INTERCEPT_ASK',  1);
define('EQUELLA_CONFIG_INTERCEPT_FULL', 2);

define('EQUELLA_ACTION_SELECTORADD', 'selectOrAdd');
define('EQUELLA_ACTION_STRUCTURED', 'structured');

function equella_supports($feature) {
    switch($feature) {
        case FEATURE_MOD_INTRO:               return true;
        case FEATURE_BACKUP_MOODLE2:          return true;
        case FEATURE_MOD_ARCHETYPE:           return MOD_ARCHETYPE_RESOURCE;
        case FEATURE_COMPLETION_TRACKS_VIEWS: return true;

        default: return null;
    }
}

function equella_get_window_options() {
	return array('width' => 620, 'height' => 450, 'resizable' => 1, 'scrollbars' => 1, 'directories' => 0, 'location' => 0, 'menubar' => 0, 'toolbar' => 0, 'status' => 0);
}

function equella_get_courseId($courseid) {
	global $DB;
	$record = $DB->get_record("course", array('id' => $courseid));
	return $record->idnumber;
}

function equella_add_instance($equella) {
	global $DB, $USER, $CFG;
	// Given an object containing all the necessary data,
	// (defined by the form in mod.html) this function
	// will create a new instance and return the id number
	// of the new instance.
	$equella->timecreated = time();
	$equella->timemodified = time();
        // Use popup by default
        if (!empty($CFG->equellaopeninnewwindow)) {
            $equella->windowpopup = 1;
        }
	$equella = equella_postprocess($equella);
	return $DB->insert_record("equella", $equella);
}

/**
 * Validate and process EQUELLA options
 *
 * @param stdClass $resource
 * @return stdClass
 */
function equella_postprocess($resource) {
    if(!empty($resource->windowpopup)) {
        $optionlist = array();
        foreach(equella_get_window_options() as $option => $value) {
            if (($option == 'width' or $option == 'height') and empty($resource->$option)) {
                $resource->$option = $value;
            }
            if (isset($resource->$option)) {
                $optionlist[] = ($option . "=" . $resource->$option);
                unset($resource->$option);
            }
        }
        $resource->popup = implode(',', $optionlist);
        unset($resource->windowpopup);
    } else {
        $resource->popup = '';
    }

    $pattern = "/(?P<uuid>[\w]{8}-[\w]{4}-[\w]{4}-[\w]{4}-[\w]{12})\/(?P<version>[0-9]*)\/(?P<path>.*)/";

    $url = $resource->url;
    preg_match($pattern, $url, $matches);
    $resource->uuid = $matches['uuid'];
    $resource->version = $matches['version'];
    $resource->path = $matches['path'];
    return $resource;
}

function equella_update_instance($equella) {
	global $DB;
	// Given an object containing all the necessary data,
	// will update an existing instance with new data.
	$equella->timemodified = time();
	$equella->id = $equella->instance;
	$equella = equella_postprocess($equella);
	return $DB->update_record("equella", $equella);
}


function equella_delete_instance($id) {
	global $DB, $CFG;
	// Given an ID of an instance of this module,
	// this function will permanently delete the instance
	// and any data that depends on it.

	if (! $equella = $DB->get_record("equella", array("id" => $id))) {
		return false;
	}

	if ($equella->activation)
	{
		$url = str_replace("signon.do", "access/activationwebservice.do", $CFG->equella_url);
		$url = equella_appendtoken($url, 'write')."&activationUuid=".urlencode($equella->activation);
		$curl = curl_init($url);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		$res = curl_exec($curl);
		curl_close($curl);
	}
	$result = true;


	if (! $DB->delete_records("equella", array("id" => $equella->id))) {
		$result = false;
	}

	return $result;
}

function equella_user_outline($course, $user, $mod, $equella) {
	$result = NULL;
	return $result;
}

function equella_user_complete($course, $user, $mod, $equella) {
	print_string("notsubmittedyet", "equella");
}

/**
 * Given a coursemodule object, this function returns the extra
 * information needed to print this activity in various places.
 *
 * @param cm_info $coursemodule
 * @return cached_cm_info info
 */
function equella_get_coursemodule_info($coursemodule) {
    global $DB, $CFG;

    $info = new cached_cm_info();

    if( $resource = $DB->get_record("equella", array("id" => $coursemodule->instance)) ) {
        require_once($CFG->libdir.'/filelib.php');

        $url = $resource->url;
        if( $ind = strrpos($url, '?') ) {
            $url = substr($url, 0, $ind);
        }
        $info->icon = equella_guess_icon($url, 24);

        if( !empty($resource->popup) ) {
            $info->onclick = "window.open('$CFG->wwwroot/mod/equella/view.php?inpopup=true&id={$coursemodule->id}', '','{$resource->popup}'); return false;";
        }
    }

    return $info;
}

/**
 * Optimised mimetype detection from general URL.  Copied from /mod/url/locallib.php
 *
 * @param $fullurl
 * @return string mimetype
 */
function equella_guess_icon($fullurl, $size = null) {
    global $CFG;
    require_once("$CFG->libdir/filelib.php");

    if (substr_count($fullurl, '/') < 3 or substr($fullurl, -1) === '/') {
        // Most probably default directory - index.php, index.html, etc. Return null because
        // we want to use the default module icon instead of the HTML file icon.
        return null;
    }

    $icon = file_extension_icon($fullurl, $size);
    $htmlicon = file_extension_icon('.htm', $size);
    $unknownicon = file_extension_icon('', $size);

    // We do not want to return those icon types, the module icon is more appropriate.
    if ($icon === $unknownicon || $icon === $htmlicon) {
        return null;
    }

    return $icon;
}

/**
 * Capture moodle files in modules
 *
 * @param stdClass $event
 * @param array
 */
function equella_capture_files($event) {
    global $CFG, $DB;
    $fs = get_file_storage();
    if (! $cm = get_coursemodule_from_id($event->modulename, $event->cmid)) {
        return array();
    }
    if (! $course = $DB->get_record("course", array("id" => $cm->course))) {
    }
    $context = get_context_instance(CONTEXT_MODULE, $cm->id);
    if ($event->modulename != 'folder' && $event->modulename != 'resource') {
        return array();
    }

    require_once($CFG->dirroot . '/mod/' . $event->modulename . '/lib.php');
    //find out all supported areas
    $functionname     = 'mod_' . $event->modulename . '_get_file_areas';
    $functionname_old = $event->modulename . '_get_file_areas';

    if (function_exists($functionname)) {
        $areas = $functionname($course, $cm, $context);
    } else if (function_exists($functionname_old)) {
        $areas = $functionname_old($course, $cm, $context);
    } else {
        $areas = array();
    }

    $files = array();
    foreach ($areas as $area => $name) {
        $area_files = $fs->get_area_files($context->id, 'mod_' . $event->modulename, $area, false, 'sortorder, itemid', false);
        $files = array_merge($files, $area_files);
    }
    return $files;
}

function equella_find_repository() {
    global $CFG;
    require_once($CFG->dirroot . '/repository/lib.php');
    $instances = repository::get_instances(array('type'=>'equella'));
    foreach ($instances as $e) {
        if ($e->get_option('equella_url') == $CFG->equella_url) {
            return $e;
        }
    }
    return null;
}

function equella_replace_contents_with_references($file, $info) {
    if (empty($info->attachments)) {
        return;
    }
    $items = $info->attachments;
    $fs = get_file_storage();
    // replace moodle files with equella references
    if ($equellarepository = equella_find_repository()) {
        $item = array_pop($items);
        $repositoryid = $equellarepository->id;
        $record = new stdClass;
        $record->filepath  = $file->get_filepath();
        $record->filename  = $item->filename;
        $record->component = $file->get_component();
        $record->filearea  = $file->get_filearea();
        $record->itemid    = $file->get_itemid();
        $record->license   = $file->get_license();
        $record->source    = $source = base64_encode(serialize((object)array('url'=>$item->links->view,'filename'=>$item->filename)));
        $record->contextid = $file->get_contextid();
        $record->userid    = $file->get_userid();
        $now = time();
        $record->timecreated  = $now;
        $record->timemodified = $now;
        $reference = $equellarepository->get_file_reference($source);
        $record->referencelifetime = $equellarepository->get_reference_file_lifetime($reference);
        // delete before create reference to avoid pathnamehash conflict
        $file->delete();
        $fs->create_file_from_reference($record, $repositoryid, $reference);
    }
}

function equella_module_event_handler($event) {
    global $CFG, $DB;
    if (empty($CFG->equella_intercept_files)) {
        return;
    }
    $course = $DB->get_record('course', array('id'=>$event->courseid));
    $params = array();
    $params['moodlemoduletype'] = $event->modulename;
    $params['moodlemodulename'] = $event->name;
    $params['moodlemoduleid']   = $event->cmid;
    $params['moodlecoursefullname']  = $course->fullname;
    $params['moodlecourseshortname'] = $course->shortname;
    $params['moodlecourseid']        = $course->id;
    $params['moodlecourseidnumber']  = $course->idnumber;
    $files = equella_capture_files($event);
    foreach ($files as $file) {
        $handle = $file->get_content_file_handle();
        // pushing files to equella
        $info = equella_rest_api::contribute_file($file->get_filename(), $handle, $params);
        // replace contents
        equella_replace_contents_with_references($file, $info);
    }
    return true;
}

/**
 * Handle module created event
 */
function equella_handle_mod_created($event) {
    return equella_module_event_handler($event);
}

/**
 * Handle module updated event
 */
function equella_handle_mod_updated($event) {
    return equella_module_event_handler($event);
}

/**
 * Register the ability to handle drag and drop file uploads
 * @return array containing details of the files / types the mod can handle
 */
if ((int)$CFG->equella_intercept_files == EQUELLA_CONFIG_INTERCEPT_ASK) {
    function equella_dndupload_register() {
        return array('files' => array(
            array('extension' => '*', 'message' => get_string('dnduploadresource', 'mod_equella'))
        ));
    }
}

/**
 * Handle a file that has been uploaded
 * @param object $uploadinfo details of the file / content that has been uploaded
 * @return int instance id of the newly created mod
 */
function equella_dndupload_handle($uploadinfo) {
    global $USER;
    $fs = get_file_storage();
    // Gather the required info.
    $data = new stdClass();
    $data->course = $uploadinfo->course->id;
    $data->coursemodule = $uploadinfo->coursemodule;
    $data->files = $uploadinfo->draftitemid;

    $usercontext = get_context_instance(CONTEXT_USER, $USER->id);
    $draftfiles = $fs->get_area_files($usercontext->id, 'user', 'draft', $uploadinfo->draftitemid, 'id', false);

    $moduleid = null;
    foreach ($draftfiles as $file) {
        $handle = $file->get_content_file_handle();
        // pushing files to equella
        $params = array();
        $params['moodlecoursefullname'] = $uploadinfo->course->fullname;
        $params['moodlecourseshortname'] = $uploadinfo->course->shortname;
        $params['moodlecourseid'] = $uploadinfo->course->id;
        $params['moodlecourseidnumber'] = $uploadinfo->course->idnumber;
        $info = equella_rest_api::contribute_file($file->get_filename(), $handle, $params);
        if (isset($info->error)) {
            throw new equella_exception($info->error_description);
        }
        $data = new stdClass;
        $data->name = $info->name;
        $data->intro = $info->description;
        $data->introformat = FORMAT_HTML;
        $item = array_pop($info->attachments);
	$data->attachmentuuid = $item->uuid;
	$data->url = $item->links->view;
        $moduleid = equella_add_instance($data, null);
    }
    return $moduleid;
}

class equella_exception extends Exception {
    function __construct($message, $debuginfo=null) {
        parent::__construct($message, 0);
    }
}

/**
 * Returns all equella instances since a given time.
 *
 * @param array $activities sequentially indexed array of objects
 * @param int   $index
 * @param int   $timestart
 * @param int   $courseid
 * @param int   $cmid
 * @param int   $userid defaults to 0
 * @param int   $groupid defaults to 0
 * @return void adds items into $activities and increases $index
 */
function equella_get_recent_mod_activity(&$activities, &$index, $timestart, $courseid, $cmid, $userid=0, $groupid=0)  {
    global $COURSE, $USER, $DB;

    if ($COURSE->id == $courseid) {
        $course = $COURSE;
    } else {
        $course = $DB->get_record('course', array('id' => $courseid));
    }

    $modinfo = get_fast_modinfo($course);
    $cm = $modinfo->cms[$cmid];
    $context = context_module::instance($cm->id);

    if (!has_capability('mod/glossary:view', $context)) {
        return;
    }

    $viewfullnames = has_capability('moodle/site:viewfullnames', $context);
    $accessallgroups = has_capability('moodle/site:accessallgroups', $context);
    $groupmode = groups_get_activity_groupmode($cm, $course);

    $params['timestart'] = $timestart;

    $eqs = $DB->get_records('equella');
    $sql = "SELECT eq.*
              FROM {equella} eq
             WHERE eq.timemodified > :timestart
          ORDER BY eq.timemodified ASC";
    $instances = $DB->get_records_sql($sql, $params);

    foreach ($instances as $instance) {
        $tmpactivity                       = new stdClass();
        $tmpactivity->type                 = 'equella';
        $tmpactivity->cmid                 = $cm->id;
        $tmpactivity->name                 = format_string($cm->name, true);
        $tmpactivity->sectionnum           = $cm->sectionnum;
        $tmpactivity->timestamp            = $instance->timemodified;
        $tmpactivity->content              = new stdClass();
        $tmpactivity->content->name        = $instance->name;
        $tmpactivity->content->uuid        = $instance->uuid;
        $tmpactivity->content->intro       = $instance->intro;
        $tmpactivity->content->introformat = $instance->introformat;
        $activities[$index++] = $tmpactivity;
    }

    return true;
}
