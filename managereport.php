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

/** LearnerScript - A Moodle block for creating LearnerScript Reports
 * @package   block_learnerscript
 * @copyright 2023 Moodle India Information Solutions Private Limited
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once("../../config.php");
use block_learnerscript\form\import_form;
use block_learnerscript\local\ls as ls;

$courseid = optional_param('courseid', SITEID, PARAM_INT);
$importurl = optional_param('importurl', '', PARAM_RAW);
$contextlevel = optional_param('contextlevel', 10, PARAM_INT);
if (!$course = $DB->get_record("course", ["id" => $courseid])) {
    throw new moodle_exception(get_string('nocourseid', 'block_learnerscript'));
}

// Force user login in course (SITE or Course).
if ($course->id == SITEID) {
    require_login();
    $context = context_system::instance();
} else {
    require_login($course->id);
    $context = context_course::instance($course->id);
}

if (!has_capability('block/learnerscript:managereports', $context)
&& !has_capability('block/learnerscript:manageownreports', $context)) {
    throw new moodle_exception(get_string('badpermissions', 'block_learnerscript'));
}

$PAGE->set_url('/blocks/learnerscript/managereport.php');
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');


$lsreportconfigstatus = get_config('block_learnerscript', 'lsreportconfigstatus');

if (!$lsreportconfigstatus) {
    redirect(new moodle_url($CFG->wwwroot . '/blocks/learnerscript/lsconfig.php?import=1'));
    exit;
}
$PAGE->requires->jquery_plugin('ui-css');

$SESSION->ls_contextlevel = $contextlevel;
$rolecontexts = $DB->get_records_sql("SELECT DISTINCT CONCAT(r.id, '@', rcl.id),
r.shortname, rcl.contextlevel
FROM {role} r
JOIN {role_context_levels} rcl ON rcl.roleid = r.id AND rcl.contextlevel NOT IN (70)
WHERE 1 = 1
ORDER BY rcl.contextlevel ASC");
foreach ($rolecontexts as $rc) {
    if ($rc->contextlevel == 10 && ($rc->shortname == 'manager')) {
        continue;
    }
    $rcontext[] = $rc->shortname .'_'.$rc->contextlevel;
}
$SESSION->rolecontextlist = $rcontext;

if ($importurl) {
    $c = new curl();
    if ($data = $c->get($importurl)) {
         $data = json_decode($data);
         $xml = base64_decode($data->content);
    } else {
        throw new moodle_exception(get_string('errorimporting',  'block_learnerscript'));
    }
    if ((new ls)->cr_import_xml($xml, $course)) {
         redirect("$CFG->wwwroot/blocks/learnerscript/managereport.php", get_string('reportcreated', 'block_learnerscript'));
    } else {
        throw new moodle_exception(get_string('errorimporting',  'block_learnerscript'));
    }
}

$mform = new import_form(null, $course->id);

if ($data = $mform->get_data()) {
    if ($xml = $mform->get_file_content('userfile')) {
        if ((new ls)->cr_import_xml($xml, $course)) {
            redirect("$CFG->wwwroot/blocks/learnerscript/managereport.php", get_string('reportcreated', 'block_learnerscript'));
        } else {
            throw new moodle_exception(get_string('errorimporting',  'block_learnerscript'));
        }
    }
}

$reports = (new block_learnerscript\local\ls)->cr_get_my_reports($course->id, $USER->id);

$title = get_string('reports', 'block_learnerscript');
$PAGE->navbar->add(get_string('managereports', 'block_learnerscript'));

$PAGE->set_title($title);
$PAGE->set_heading($title);
$PAGE->set_cacheable(true);

echo $OUTPUT->header();

$PAGE->requires->js(new moodle_url('/blocks/learnerscript/js/highchart.js'));

if ($reports) {
    $table = new html_table();
    $table->width = "100%";
    $table->head = [get_string('name'),  get_string('type', 'block_learnerscript'),
                    get_string('actions'), ];
    $table->align = ['left', 'left', 'left', 'center', 'center'];
    $table->size = ['20%', '20%', '10%', '20%', '20%'];
    $stredit = get_string('edit');
    $strhide = get_string('hide');
    $strshow = get_string('show');
    $strcopy = get_string('duplicate');
    $strexport = get_string('exportreport', 'block_learnerscript');
    $strschedule = get_string('schedulereport', 'block_learnerscript');

    foreach ($reports as $r) {
        $editcell = '';
        $editcell .= html_writer::link(new \moodle_url($CFG->wwwroot .
        '/blocks/learnerscript/editreport.php', ['id' => $r->id]),
        html_writer::empty_tag('img', ['src' => $OUTPUT->image_url('/t/edit'), 'class' => "iconsmall",
        'alt' => $stredit, ]), ['title' => $stredit]);

        if (!empty($r->visible)) {
            $editcell .= html_writer::link(new \moodle_url('editreport.php',
            ['id' => $r->id, 'hide' => 1, 'sesskey' => $USER->sesskey]),
            html_writer::empty_tag('img', ['src' => $OUTPUT->image_url('/t/hide'), 'class' => "iconsmall",
            'alt' => $strhide, ]), ['title' => $strhide]);
        } else {
            $editcell .= html_writer::link(new moodle_url('editreport.php',
            ['id' => $r->id, 'show' => 1, 'sesskey' => $USER->sesskey]),
            html_writer::empty_tag('img', ['src' => $OUTPUT->image_url('/t/show'), 'class' => "iconsmall",
            'alt' => $strshow, ]), ['class' => 'iconsmall', 'title' => $strshow]);
        }
        $editcell .= html_writer::link(new moodle_url('editreport.php',
        ['id' => $r->id, 'sesskey' => $USER->sesskey]),
        html_writer::empty_tag('img', ['src' => $OUTPUT->image_url('/t/copy'), 'class' => "iconsmall",
        'alt' => $strcopy, ]),
        ['class' => 'iconsmall', 'title' => $strcopy]);
        $editcell .= html_writer::link(new moodle_url('export.php',
        ['id' => $r->id, 'sesskey' => $USER->sesskey]),
        html_writer::empty_tag('img', ['src' => $OUTPUT->image_url('/t/backup'), 'class' => "iconsmall",
        'alt' => $strexport, ]),
        ['class' => 'iconsmall', 'title' => $strexport]);
        $properties = new stdClass();
        $properties->courseid = $courseid;
        $reportclass = (new ls)->create_reportclass($r->id, $properties);
        if ($reportclass->parent && $r->type != 'statistics') {
            $editcell .= html_writer::link(new moodle_url('components/scheduler/schedule.php',
            ['id' => $r->id, 'courseid' => $r->courseid, 'sesskey' => $USER->sesskey]),
            html_writer::empty_tag('img', ['src' => $OUTPUT->image_url('/i/calendar'), 'class' => "iconsmall",
            'alt' => $strschedule, ]),
            ['class' => 'iconsmall', 'title' => $strschedule]);;
        }

        $table->data[] = [html_writer::link(new moodle_url('viewreport.php', ['id' => $r->id]), $r->name),
        get_string('report_' . $r->type, 'block_learnerscript'), $editcell, ];
    }

    $table->id = 'reportslist';
    echo html_writer::div(html_writer::table($table), "cmp_overflow");
} else {
    echo $OUTPUT->heading(get_string('noreportsavailable', 'block_learnerscript'));
}

$mform->display();
echo $OUTPUT->footer();
