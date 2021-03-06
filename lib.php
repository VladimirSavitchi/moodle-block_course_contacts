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
 * Block Course_Contacts lib file.
 *
 * @package    block_course_contacts
 * @author     Mark Ward
 *             2016 Richard Oelmann
 * @copyright  Mark Ward
 * @credits    2016 R. Oelmann
 *
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function quickmail_format_time($time) {
    return date("l, d F Y, h:i A", $time);
}

function quickmail_cleanup($table, $itemid) {
    global $DB;

    // Clean up the files associated with this email.
    if ($courseid = $DB->get_field($table, 'courseid', array('id' => $itemid))) {
        $fs = get_file_storage();
        $context = context_course::instance($courseid);
        $files = $fs->get_area_files($context->id, $table, 'attachment', $itemid, 'id');
        foreach ($files as $file) {
            $file->delete();
        }
    }
    return $DB->delete_records($table, array('id' => $itemid));
}

function quickmail_history_cleanup($itemid) {
    return quickmail_cleanup('block_quickmail_log', $itemid);
}

function quickmail_draft_cleanup($itemid) {
    return quickmail_cleanup('block_quickmail_drafts', $itemid);
}

function quickmail_process_attachments($context, $email, $table, $id) {
    global $CFG, $USER;

    $basepath = "temp/block_quickmail/{$USER->id}";
    $moodlebase = "$CFG->dataroot/$basepath";
    if (!file_exists($moodlebase)) {
        make_upload_directory($basepath);
    }

    $zipname = $zip = $actualzip = '';
    if (!empty($email->attachment)) {
        $zipname = "attachment.zip";
        $zip = "$basepath/$zipname";
        $actualzip = "$moodlebase/$zipname";

        $packer = get_file_packer();
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'block_quickmail_'.$table, 'attachment', $id, 'id');
        $storedfiles = array();
        foreach ($files as $file) {
            if ($file->is_directory() ) {
                if ($file->get_filename() == '.') {
                    continue;
                }
            }
            $storedfiles[$file->get_filepath().$file->get_filename()] = $file;
        }

        $packer->archive_to_pathname($storedfiles, $actualzip);
    }

    return array($zipname, $zip, $actualzip);
}

function quickmail_attachment_names($draft) {
    global $USER;

    $usercontext = context_user::instance($USER->id);

    $fs = get_file_storage();
    $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draft, 'id');
    $onlyfiles = array_filter($files, function($file) {
        return !$file->is_directory() and $file->get_filename() != '.';
    });

    return implode(',', array_map(
        function($file) {
            return $file->get_filename();
        },
        $onlyfiles));
}

function quickmail_filter_roles($userroles, $masterroles) {
    return array_uintersect($masterroles, $userroles, function($a, $b) {
        return strcmp($a->shortname, $b->shortname);
    });
}

function quickmail_load_config($courseid) {
    global $CFG, $DB;

    $config = $DB->get_records_menu('block_quickmail_config',
                                    array('coursesid' => $courseid), '', 'name,value');

    $names = array(
        'allowstudents',
        'roleselection',
        'courseinsubject',
        'breadcrumbsinbody',
    );

    foreach ($names as $name) {
        if (!array_key_exists($name, $config)) {
            $config[$name] = $CFG->{"block_quickmail_$name"};
        }
    }

    return $config;
}

function quickmail_default_config($courseid) {
    global $DB;
    $DB->delete_records('block_quickmail_config', array('coursesid' => $courseid));
}

function quickmail_save_config($courseid, $data) {
    global $DB;

    quickmail_default_config($courseid);

    foreach ($data as $name => $value) {
        $config = new stdClass;
        $config->coursesid = $courseid;
        $config->name = $name;
        $config->value = $value;
        $DB->insert_record('block_quickmail_config', $config);
    }
}
