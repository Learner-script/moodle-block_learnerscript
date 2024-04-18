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

namespace block_learnerscript\local;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/lib/evalmath/evalmath.class.php');
require_once($CFG->dirroot . "/course/lib.php");

use block_learnerscript\highcharts\graphicalreport;
use stdclass;
use DateTime;
use DateTimeZone;
use core_date;
use context_system;
use context_course;
use core_course_category;

use block_learnerscript\local\schedule;

define('DAILY', 1);
define('WEEKLY', 2);
define('MONTHLY', 3);
define('ONDEMAND', -1);

define('OPENSANS', 1);
define('PTSANS', 2);

/**
 * A Moodle block to create customizable reports.
 *
 * @package   block_learnerscript
 * @copyright 2023 Moodle India
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ls {
    /**
     * Add new report
     * @param object $data    New report data
     * @param object $context Report context
     * @return int Report ID
     */
    public function add_report($data, $context) {
        global $DB;
        if (!$lastid = $DB->insert_record('block_learnerscript', $data)) {
            throw new \moodle_exception('errorsavingreport', 'block_learnerscript');
        } else {
            $event = \block_learnerscript\event\create_report::create([
                'objectid' => $lastid,
                'context' => $context,
            ]);
            $event->trigger();
            $data->id = $lastid;
            if (in_array($data->type, ['sql', 'statistics'])) {
                self::update_report_sql($data);
            }
        }
        return $lastid;
    }
    /**
     * Update existing report
     * @param  object $data    Updated report data
     * @param  object $context Report context
     */
    public function update_report($data, $context) {
        global $DB;
        $data->global = 1;
        $data->disabletable = isset($data->disabletable) ? $data->disabletable : 0;
        $data->summary = isset($data->description['text']) ? $data->description['text'] : '';
        if (!$DB->update_record('block_learnerscript', $data)) {
            throw new \moodle_exception('errorsavingreport', 'block_learnerscript');
        } else {
            $event = \block_learnerscript\event\update_report::create([
                    'objectid' => $data->id,
                    'context' => $context,
                ]);
            $event->trigger();
            if (in_array($data->type, ['sql', 'statistics'])) {
                self::update_report_sql($data);
            }
        }
    }
    /**
     * Delete report
     * @param  object $report  Report data to delete
     * @param  object $context Report context
     */
    public function delete_report($report, $context) {
        global $DB;
        if ($DB->delete_records('block_learnerscript', ['id' => $report->id])) {
            if ($DB->delete_records('block_ls_schedule', ['reportid' => $report->id])) {
                $event = \block_learnerscript\event\delete_report::create([
                    'objectid' => $report->id,
                    'context' => $context,
                ]);
                $event->add_record_snapshot('role_assignments', $report);
                $event->trigger();
            }
        }
    }
    /**
     * Update report sql
     * @param  object $report Report data
     */
    public function update_report_sql($report) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/blocks/learnerscript/reports/' . $report->type . '/report.class.php');
        $reportclassname = 'block_learnerscript\lsreports\report_' . $report->type;
        $reportproperties = new stdclass();
        $reportclass = new $reportclassname($report->id, $reportproperties);
        $components = self::cr_unserialize($reportclass->config->components);
        $components['customsql']['config'] = $report;
        $reportclass->config->components = (new ls)->cr_serialize($components);
        $DB->update_record('block_learnerscript', $reportclass->config);
    }
    /**
     * Generate report plot
     * @param  object $reportclass Report class
     * @param  object $graphdata   Report graph data
     * @param  array $blockinstanceid Report block instance Id
     */
    public function generate_report_plot($reportclass, $graphdata, $blockinstanceid = null) {
        global $CFG;
        $components = (new ls)->cr_unserialize($reportclass->config->components);
        $seriesvalues = (isset($components['plot']['elements'])) ? $components['plot']['elements'] : [];
        $highcharts = new graphicalreport();
        if (!empty($seriesvalues)) {
            switch ($graphdata['pluginname']) {
                case 'pie':
                    return $highcharts->piechart($reportclass->finalreport->table->data,
                    $graphdata, $reportclass->config, $reportclass->finalreport->table->head, null);
                case 'line':
                    return $highcharts->lbchart($reportclass->finalreport->table->data, $graphdata,
                    $reportclass->config, 'spline', $reportclass->finalreport->table->head, $blockinstanceid);
                case 'bar':
                    return $highcharts->lbchart($reportclass->finalreport->table->data,
                    $graphdata, $reportclass->config, 'bar', $blockinstanceid,
                    $reportclass->finalreport->table->head);
                case 'column':
                    return $highcharts->lbchart($reportclass->finalreport->table->data,
                    $graphdata, $reportclass->config, 'column', $blockinstanceid,
                    $reportclass->finalreport->table->head);
                case 'combination':
                    return $highcharts->combination_chart($reportclass->finalreport->table->data,
                    $graphdata, $reportclass->config, 'combination',
                    $reportclass->finalreport->table->head, $seriesvalues, $blockinstanceid);
            }
        }
        return true;
    }
    /**
     * Get components data
     * @param  int $reportid  Report Id
     * @param  string $component Report component
     * @return array
     */
    public function get_components_data($reportid, $component) {
        global $CFG, $DB;

        if (!$report = $DB->get_record('block_learnerscript', ['id' => $reportid])) {
            throw new \moodle_exception(get_string('noreportexists', 'block_learnerscript'));
        }
        $elements = (new ls)->cr_unserialize($report->components);
        $elements = isset($elements[$component]['elements']) ? $elements[$component]['elements'] : [];

        require_once($CFG->dirroot . '/blocks/learnerscript/components/' . $component . '/component.class.php');
        $componentclassname = 'component_' . $component;
        $compclass = new $componentclassname($report->id);
        $optionsplugins = [];
        if (!empty($compclass->plugins)) {
            $currentplugins = [];
            if ($elements) {
                foreach ($elements as $e) {
                    $currentplugins[] = $e['pluginname'];
                }
            }
            $plugins = get_list_of_plugins('blocks/learnerscript/components/' . $component);
            foreach ($plugins as $p) {
                require_once($CFG->dirroot . '/blocks/learnerscript/components/' . $component . '/' . $p . '/plugin.class.php');
                $pluginclassname = 'block_learnerscript\lsreports\plugin_' . $p;
                $pluginclass = new $pluginclassname($report);
                if (in_array($report->type, $pluginclass->reporttypes)) {
                    if ($pluginclass->unique && in_array($p, $currentplugins)) {
                        continue;
                    }
                    $optionsplugins[] = ['shortname' => $p, 'fullname' => ucfirst($p)];
                }
            }
            asort($optionsplugins);
        }
        return $optionsplugins;
    }
    /**
     * Report table data
     * @param  object $table Report data table
     * @return array
     */
    public function report_tabledata($table) {
        global $COURSE, $PAGE, $OUTPUT;
        if (isset($table->align)) {
            foreach ($table->align as $key => $aa) {
                if ($aa) {
                    $align[$key] = ' text-align:' . fix_align_rtl($aa) . ';'; // Fix for RTL languages.
                } else {
                    $align[$key] = '';
                }
            }
        }
        if (isset($table->size)) {
            foreach ($table->size as $key => $ss) {
                if ($ss) {
                    $size[$key] = ' width:' . $ss . ';';
                } else {
                    $size[$key] = '';
                }
            }
        }
        if (isset($table->wrap)) {
            foreach ($table->wrap as $key => $ww) {
                if ($ww) {
                    $wrap[$key] = ($ww == 'wrap') ? 'word-break:break-all;' : 'word-break:normal;';
                } else {
                    $wrap[$key] = 'word-break:normal;';
                }
            }
        }
        if (empty($table->width)) {
            $table->width = '100%';
        }

        if (empty($table->tablealign)) {
            $table->tablealign = 'center';
        }

        if (!isset($table->cellpadding)) {
            $table->cellpadding = '5';
        }

        if (!isset($table->cellspacing)) {
            $table->cellspacing = '1';
        }

        if (empty($table->class)) {
            $table->class = 'generaltable';
        }

        $tableid = empty($table->id) ? '' : 'id="' . $table->id . '"';
        $countcols = 0;
        $isuserid = -1;
        $countcols = count($table->head);
        $keys = array_keys($table->head);
        $lastkey = end($keys);
        $tableheadkeys = array_keys($table->head);
        foreach ($table->head as $key => $heading) {
            $k = array_search($key, $tableheadkeys);
            $size[$key] = isset($size[$k]) ? $size[$k] : null;
            $wrap[$key] = isset($wrap[$k]) ? $wrap[$k] : 'word-break:normal;';
            $align[$key] = isset($align[$k]) ? $align[$k] : null;
            $tablehead[] = ['key' => $key,
                                    'heading' => $heading,
                                    'size' => $size[$k],
                                    'wrap' => $wrap[$k],
                                    'align' => $align[$k], ];
        }
        $tableproperties = ['width' => $table->width,
                                'tablealign' => $table->tablealign,
                                'cellpadding' => $table->cellpadding,
                                'cellspacing' => $table->cellspacing,
                                'class' => $table->class, ];

        return compact('tablehead', 'tableproperties');
    }
    /**
     * Url encode recursive
     * @param  string $var Recursive variable
     * @return string
     */
    public function urlencode_recursive($var) {
        if (is_object($var)) {
            $newvar = new stdClass();
            $properties = get_object_vars($var);
            foreach ($properties as $property => $value) {
                $newvar->$property = (new self)->urlencode_recursive($value);
            }
        } else if (is_array($var)) {
            $newvar = [];
            foreach ($var as $property => $value) {
                $newvar[$property] = (new self)->urlencode_recursive($value);
            }
        } else if (is_string($var)) {
            $newvar = urlencode($var);
        } else {
            $newvar = $var;
        }

        return $newvar;
    }
    /**
     * Url decode recursive
     * @param  string $var Recursive variable
     * @return string
     */
    public function urldecode_recursive($var) {
        if (is_object($var)) {
            $newvar = new stdClass();
            $properties = get_object_vars($var);
            foreach ($properties as $property => $value) {
                $newvar->$property = self::urldecode_recursive($value);
            }
        } else if (is_array($var)) {
            $newvar = [];
            foreach ($var as $property => $value) {
                $newvar[$property] = self::urldecode_recursive($value);
            }
        } else if (is_string($var)) {
            $newvar = urldecode($var);
        } else {
            $newvar = $var;
        }

        return $newvar;
    }
    /**
     * Get user reports
     * @param  int  $courseid   Course ID
     * @param  int  $userid  User ID
     * @param  boolean $allcourses Courses list
     * @return array
     */
    public function cr_get_my_reports($courseid, $userid, $allcourses = true) {
        global $DB;

        $reports = [];
        if ($courseid == SITEID) {
            $context = context_system::instance();
        } else {
            $context = context_course::instance($courseid);
        }
        if (has_capability('block/learnerscript:managereports', $context, $userid)) {
            if ($courseid == SITEID && $allcourses) {
                $reports = $DB->get_records('block_learnerscript', null, 'name ASC');
            } else {
                $reports = $DB->get_records('block_learnerscript', ['courseid' => $courseid], 'name ASC');
            }

        } else {
            $reports = $DB->get_records_select('block_learnerscript',
            'ownerid = ? AND courseid = ? ORDER BY name ASC', [$userid, $courseid]);
        }
        return $reports;
    }
    /**
     * Reports data serialize
     * @param  string $var Variable
     * @return string
     */
    public function cr_serialize($var) {
        return serialize((new self)->urlencode_recursive($var));
    }
    /**
     * Reports data unserialize
     * @param  string $var Variable
     * @return string|null
     */
    public function cr_unserialize($var) {
        if (!empty($var)) {
            return (new self)->urldecode_recursive(unserialize($var));
        }
    }
    /**
     * Check report permissions
     * @param  object $report  Report data
     * @param  int $userid  User ID
     * @param  object $context User context
     * @return object
     */
    public function cr_check_report_permissions($report, $userid, $context) {
        global $CFG;

        require_once($CFG->dirroot . '/blocks/learnerscript/reports/' . $report->type . '/report.class.php');
        $properties = new stdClass();
        $properties->courseid = $report->id;
        $properties->start = 0;
        $properties->length = 1;
        $properties->search = '';
        $properties->lsstartdate = 0;
        $properties->lsenddate = time();
        $properties->filters = [];
        $classn = 'block_learnerscript\lsreports\report_' . $report->type;
        $classi = new $classn($report->id, $properties);
        return $classi->check_permissions($context, $userid);
    }
    /**
     * Get report plugins
     * @param  int $courseid Course ID
     * @return array
     */
    public function cr_get_report_plugins($courseid) {
        $pluginoptions = [];
        $context = ($courseid == SITEID) ? context_system::instance() : context_course::instance($courseid);
        $plugins = get_list_of_plugins('blocks/learnerscript/reports');
        if ($plugins) {
            foreach ($plugins as $p) {
                if ($p == 'sql' && !has_capability('block/learnerscript:managesqlreports', $context)) {
                    continue;
                }

                $pluginoptions[$p] = get_string('report_' . $p, 'block_learnerscript');
            }
        }
        return $pluginoptions;
    }
    /**
     * Get export plugins
     * @return array Plugin options
     */
    public function cr_get_export_plugins() {
        $plugins = get_list_of_plugins('blocks/learnerscript/export');
        if ($plugins) {
            foreach ($plugins as $p) {
                $pluginoptions[$p] = get_string('export_' . $p, 'block_learnerscript');
            }
        }
        return $pluginoptions;
    }
    /**
     * Get export options
     * @param  int $reportid Report ID
     * @return array Report export options
     */
    public function cr_get_export_options($reportid) {
        global $DB;
        $reportconfig = $DB->get_record('block_learnerscript', ['id' => $reportid]);
        if ($reportconfig->export) {
            $exportoptions = array_filter(explode(', ', $reportconfig->export));
        } else {
            $exportoptions = false;
        }
        return $exportoptions;
    }
    /**
     * Table to excel
     * @param  string $filename File name
     * @param  object $table    Report table
     */
    public function table_to_excel($filename, $table) {
        global $CFG;
        require_once($CFG->dirroot . '/lib/excellib.class.php');
        if (!empty($table->head)) {
            foreach ($table->head as $key => $heading) {
                $matrix[0][$key] = str_replace("\n", ' ', htmlspecialchars_decode(strip_tags(nl2br($heading)),
                                ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401));
            }
        }

        if (!empty($table->data)) {
            foreach ($table->data as $rkey => $row) {
                foreach ($row as $key => $item) {
                    $matrix[$rkey + 1][$key] = str_replace("\n", ' ',
                                    htmlspecialchars_decode(strip_tags(nl2br($item)), ENT_QUOTES));
                }
            }
        }

        $downloadfilename = clean_filename($filename);
        // Creating a workbook.
        $workbook = new \MoodleExcelWorkbook("-");
        // Sending HTTP headers.
        $workbook->send($downloadfilename);
        // Adding the worksheet.
        $myxls = &$workbook->add_worksheet($filename);

        foreach ($matrix as $ri => $col) {
            foreach ($col as $ci => $cv) {
                $myxls->write_string($ri, $ci, $cv);
            }
        }

        $workbook->close();
        exit;
    }
    /**
     * Report categories list
     * @param  array  $list   Reports list
     * @param  array  $parents  Report parent
     * @param  string  $requiredcapability Required capability to access report
     * @param  integer $excludeid         Excluded ID
     * @param  object  $category     Category
     * @param  string  $path         Report path
     */
    public function cr_make_categories_list(&$list, &$parents, $requiredcapability = '',
    $excludeid = 0, $category = null, $path = "") {
        global $DB;
        // For categories list use just this one public function.
        if (empty($list)) {
            $list = [];
        }
        $list += core_course_category::make_categories_list($requiredcapability, $excludeid);
        if (empty($parents)) {
            $parents = [];
        }
        $all = $DB->get_records_sql('SELECT id, parent
        FROM {course_categories}
        WHERE visible = :visible
        ORDER BY sortorder', ['visible' => 1]);
        foreach ($all as $record) {
            if ($record->parent) {
                $parents[$record->id] = array_merge($parents[$record->parent], [$record->parent]);
            } else {
                $parents[$record->id] = [];
            }
        }
    }
    /**
     * Report import XML
     * @param  string $xml    XML file
     * @param  object  $course   Course data
     * @param  boolean $timeprefix Report import time prefix
     * @param  boolean $config     Report config data
     * @return boolean
     */
    public function cr_import_xml($xml, $course, $timeprefix = true, $config = false) {
        global $CFG, $DB, $USER, $PAGE;
        $context = context_system::instance();
        require_once($CFG->dirroot . '/lib/xmlize.php');
        $data = xmlize($xml, 1, 'UTF-8');
        if (isset($data['report']['@']['version'])) {
            $newreport = new stdclass;
            foreach ($data['report']['#'] as $key => $val) {
                if ($key == 'components') {
                    $val[0]['#'] = base64_decode(trim($val[0]['#']));
                    // Fix url_encode " and ' when importing SQL queries.
                    $tempcomponents = (new self)->cr_unserialize($val[0]['#']);
                    if (isset($tempcomponents['customsql'])) {
                        $tempcomponents['customsql']['config']->querysql = str_replace("\'", "'",
                        $tempcomponents['customsql']['config']->querysql);
                        $tempcomponents['customsql']['config']->querysql = str_replace('\"', '"',
                        $tempcomponents['customsql']['config']->querysql);
                    }
                    $val[0]['#'] = (new self)->cr_serialize($tempcomponents);

                }
                $newreport->{$key} = $val[0]['#'];
            }
            $newreport->courseid = $course->id;
            $newreport->ownerid = $USER->id;
            if ($timeprefix) {
                $newreport->name .= " (" . userdate(time()) . ")";
            }
            try {
                $reportid = $DB->insert_record('block_learnerscript', $newreport);
                $event = \block_learnerscript\event\create_report::create([
                    'objectid' => $reportid,
                    'context' => $context,
                ]);
                $event->trigger();
                if ($config && $reportid) {
                    $PAGE->set_context($context);
                    $regions = ['side-db-first', 'side-db-second', 'side-db-third',
                    'side-db-four', 'side-db-one', 'side-db-two',
                    'side-db-three', 'side-db-main', 'center-first', 'center-second', 'reports-db-one', 'reports-db-two',
                    'reportdb-one', 'reportdb-second', 'reportdb-third', 'first-maindb', ];
                    $PAGE->blocks->add_regions($regions);
                    $blocksinstancedata = isset($data['report']['#']['instance']) ? $data['report']['#']['instance'] : 0;
                    $blockspositiondata = isset($data['report']['#']['position']) ? $data['report']['#']['position'] : 0;
                    if (!empty($blocksinstancedata)) {
                        foreach ($blocksinstancedata as $k => $blockinstancedata) {
                            if (isset($blockinstancedata['@']['version'])) {
                                $blockinstance = new stdClass();
                                foreach ($blockinstancedata['#'] as $key => $val) {
                                    $blockinstance->{$key} = trim($val[0]['#']);
                                }
                                $blockexists = $PAGE->blocks->is_known_block_type($blockinstance->blockname, true);
                                if ($blockexists) {
                                    $blockconfig = new stdClass();
                                    $blockconfig->title = $blockinstance->title;
                                    $blockconfig->reportlist = $reportid;
                                    $blockconfig->reportcontenttype = $blockinstance->reportcontenttype;
                                    $blockconfig->reporttype = $blockinstance->reporttype;
                                    $blockconfig->logo = $blockinstance->logo;
                                    $blockconfig->tilescolourpicker = $blockinstance->tilescolourpicker;
                                    if ($blockinstance->blockname == 'reporttiles') {
                                        $blockconfig->tileformat = $blockinstance->tileformat;
                                    } else if ($blockinstance->blockname == 'reportdashboard') {
                                        $blockconfig->disableheader = $blockinstance->disableheader;
                                    }
                                    $blockconfig->reportduration = $blockinstance->reportduration;
                                    $blockconfig->tilescolour = $blockinstance->tilescolour;
                                    $blockconfig->url = $blockinstance->url;
                                    $configdata = base64_encode(serialize($blockconfig));
                                    $PAGE->blocks->add_block($blockinstance->blockname, $blockinstance->defaultregion,
                                    $blockinstance->defaultweight, false, $blockinstance->pagetypepattern,
                                    $blockinstance->subpagepattern);
                                    $lastblockinstanceid = $DB->get_field_sql("SELECT id
                                    FROM {block_instances}
                                    WHERE blockname = :blockname
                                    ORDER BY id DESC", ['blockname' => $blockinstance->blockname],
                                    IGNORE_MULTIPLE);
                                    $DB->set_field('block_instances', 'configdata', $configdata,
                                    ['id' => $lastblockinstanceid]);
                                    if ($lastblockinstanceid) {
                                        if (isset($blockspositiondata[$k]['@']['version'])) {
                                            if (!empty($blockspositiondata[$k]['#'])) {
                                                $blockposition = new stdClass();
                                                $blockposition->blockinstanceid = $lastblockinstanceid;
                                                foreach ($blockspositiondata[$k]['#'] as $key => $val) {
                                                    $blockposition->{$key} = trim($val[0]['#']);
                                                }
                                                $DB->insert_record('block_positions', $blockposition);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            } catch (\dml_exception $ex) {
                return false;
            }
            return $reportid;
        }
        return false;
    }
    /**
     * Get report instance
     * @param  int $reportid Report ID
     * @return object Report data
     */
    public function cr_get_reportinstance($reportid) {
        global $DB;
        if (!$report = $DB->get_record('block_learnerscript', ['id' => $reportid])) {
            throw new \moodle_exception('reportdoesnotexists', 'block_learnerscript');
        }
        return $report;
    }
    /**
     * Create report class
     * @param  int  $reportid     Report ID
     * @param  object $reportproperties Report properties
     * @return object
     */
    public function create_reportclass($reportid, $reportproperties = null) {
        global $CFG;
        $report = (new self)->cr_get_reportinstance($reportid);
        require_once($CFG->dirroot . '/blocks/learnerscript/reports/' . $report->type . '/report.class.php');
        $reportclassname = 'block_learnerscript\lsreports\report_' . $report->type;
        $reportclass = new $reportclassname($report, $reportproperties);
        if ($reportproperties) {
            isset($reportproperties->courseid) ? $reportclass->courseid = $reportproperties->courseid : null;
            isset($reportproperties->lsstartdate) ? $reportclass->lsstartdate = $reportproperties->lsstartdate : null;
            isset($reportproperties->lsenddate) ? $reportclass->lsenddate = $reportproperties->lsenddate : null;
        }
        return $reportclass;
    }
    /**
     * List of report types
     * @param  int  $reportid       Report ID
     * @param  boolean $checktable  Check report table data
     * @param  boolean $componentdata Report component data
     * @return array Report content types
     */
    public function cr_listof_reporttypes($reportid, $checktable = true, $componentdata = true) {
        global $DB;
        $reportcomponents = $DB->get_field('block_learnerscript', 'components', ['id' => $reportid]);
        $components = (new self)->cr_unserialize($reportcomponents);

        $reportcontenttypes = [];
        if (isset($components['plot'])) {
            foreach ($components['plot']['elements'] as $key => $value) {
                if (isset($value['formdata'])) {
                    if ($componentdata) {
                        $reportcontenttypes[$value['id']] = ucfirst($value['formdata']->chartname);
                    } else {
                        $reportcontenttypes[] = ['pluginname' => $value['pluginname'],
                        'chartname' => ucfirst($value['formdata']->chartname), 'chartid' => $value['id'],
                        'title' => get_string($value['pluginname'], 'block_learnerscript'), ];
                    }
                }
            }
        }

        if ($checktable) {
            if ($componentdata) {
                $reportcontenttypes['table'] = get_string('table', 'block_learnerscript');
            } else {
                $disablereporttable = $DB->get_field('block_learnerscript', 'disabletable', ['id' => $reportid]);
                if ($disablereporttable == 0) {
                    $reportcontenttypes[] = ['chartid' => 'table', 'chartname' => get_string('table', 'block_learnerscript')];
                }
            }
        }
        return $reportcontenttypes;
    }
    /**
     * Add custom reports sql
     * @param array $reports Reports list
     */
    public function add_customreports_sql($reports) {
        global $DB, $CFG;
        foreach ($reports as $report) {
            $importurl = urldecode($CFG->wwwroot . '/blocks/learnerscript/reports/sql/customreports/' . $report . '.xml');
            $fcontents = file_get_contents($importurl);
            $course = $DB->get_record("course", ["id" => SITEID]);
            if ($this->cr_import_xml($fcontents, $course, false)) {
                echo '';
            } else {
                throw new \moodle_exception(get_string('errorimporting', 'block_learnerscript'));
            }
        }
    }
    /**
     * List of scheduled reports data
     * @param  boolean $frequency
     * @return array List of scheduled reports
     */
    public function schedulereportsquery($frequency = false) {
        global $DB, $CFG;
        core_date::set_default_server_timezone();
        $now = new DateTime("now", core_date::get_server_timezone_object());
        $datearray = (array) $now;
        $timezone = $datearray['timezone'];
        $timezonetime = (new DateTime('now', new DateTimeZone( $timezone )))->format('P');
        $seconds = strtotime("1970-01-01 $timezonetime");
        if ($seconds > 0 || $seconds < 0) {
            $usertime = date("Y-m-d h:i:s");
        } else if ($timezonetime == 0) {
            $usertime = '1970-01-01 00:00:00';
        }
        $date = $now->format('Y-m-d');
        $hour = $now->format('H');
        $frequencyquery = '';
        if ($frequency == ONDEMAND) {
            $frequencyquery = " AND crs.frequency = $frequency AND crs.timemodified = 0 ";
        } else {
            if ($CFG->dbtype == 'pgsql') {
                $frequencyquery = " AND to_char(to_timestamp(crs.nextschedule), 'YYYY-mm-dd') = '$date'
                AND to_char(to_timestamp(crs.nextschedule), 'HH24')::INTEGER = $hour";
            } else {
                $frequencyquery = " AND DATE(FROM_UNIXTIME(nextschedule)) = '$date'
                AND HOUR(FROM_UNIXTIME(nextschedule)) = $hour";
            }
        }
        $sql = "SELECT crs.*, cr.name, cr.courseid, u.timezone
                  FROM {block_ls_schedule} as crs
                  JOIN {block_learnerscript} as cr ON crs.reportid = cr.id
                  JOIN {user} as u ON crs.userid = u.id
                 WHERE u.confirmed = :confirmed AND u.suspended = :suspended AND u.deleted = :deleted $frequencyquery";
        $scheduledreports = $DB->get_records_sql($sql, ['confirmed' => 1, 'suspended' => 0, 'deleted' => 0]);
        return $scheduledreports;
    }
    /**
     * Processing scheduling reports cron based on frequency
     * @param  int $frequency DAILY/WEEKLY/MONTHLY const values
     * @return  boolean
     */
    public function process_scheduled_reports($frequency = false) {
        global $DB;
        $schedule = new schedule;
        $scheduledreports = (new self)->schedulereportsquery($frequency);
        $totalschedulereports = count($scheduledreports);
        mtrace('Processing ' . $totalschedulereports . ' scheduled reports');
        if ($totalschedulereports > 0) {
            foreach ($scheduledreports as $scheduled) {
                switch ($scheduled->exporttofilesystem) {
                    case REPORT_EXPORT_AND_EMAIL:
                        mtrace('ReportID (' . $scheduled->reportid . ') - ScheduleID (' . $scheduled->id . ')
                        Option:'. get_string('emailsave', 'block_learnerscript'));
                        break;
                    case REPORT_EXPORT:
                        mtrace('ReportID (' . $scheduled->reportid . ') - ScheduleID (' . $scheduled->id . ')
                        Option: '.get_string('schedulesave', 'block_learnerscript'));
                        break;
                    case REPORT_EMAIL:
                        mtrace('ReportID (' . $scheduled->reportid . ') - ScheduleID (' . $scheduled->id . ')
                        Option:'.get_string('emailschedule', 'block_learnerscript'));
                        break;
                }
                $schedule->scheduledreport_send_scheduled_report($scheduled);
                if ($scheduled->frequency == DAILY) {
                    $scheduletype = 'dailyreport';
                } else if ($scheduled->frequency == WEEKLY) {
                    $scheduletype = 'weeklyreport';
                } else if ($scheduled->frequency == MONTHLY) {
                    $scheduletype = 'monthlyreport';
                } else if ($scheduled->frequency == ONDEMAND) {
                    $scheduletype = 'On Demand';
                } else {
                    $scheduletype = 'N/A';
                }

                if ($frequency != ONDEMAND) {
                    $scheduled->nextschedule = $schedule->next($scheduled);
                    $scheduled->timemodified = time();
                    if (!$DB->update_record('block_ls_schedule', $scheduled)) {
                        mtrace('Failed to update next report field for scheduled report id:' . $scheduled->id);
                    }
                }
            }
        }
        return true;
    }
    /**
     * PDF Report Export Header
     * @return string Report Header
     */
    public function pdf_reportheader() {
        $headerimagepath = get_reportheader_imagepath();
        $headerimgpath = "";
        if (@getimagesize($headerimagepath)) {
            $headerimgpath = $headerimagepath;
        }
        if ($headerimgpath) {
            $reportlogoimage =
            '<img src="' . $headerimgpath . '" alt=' . get_string("altreportimage", "block_learnerscript") . ' height="80px">';
        } else {
            $reportlogoimage = "";
        }
        return $reportlogoimage;
    }

    /**
     * Report components list
     * @param  object $report Report data
     * @param  string $comp   Components
     * @return array Report components list
     */
    public function report_componentslist($report, $comp) {
        global $CFG;
        require_once($CFG->dirroot.'/blocks/learnerscript/reports/'.$report->type.'/report.class.php');

        $elements = (new self)->cr_unserialize($report->components);
        $elements = isset($elements[$comp]['elements']) ? $elements[$comp]['elements'] : [];

        require_once($CFG->dirroot.'/blocks/learnerscript/components/'.$comp.'/component.class.php');
        $componentclassname = 'component_'.$comp;
        $compclass = new $componentclassname($report->id);
        if ($compclass->plugins) {
            $currentplugins = [];
            if ($elements) {
                foreach ($elements as $e) {
                    $currentplugins[] = $e['pluginname'];
                }
            }
            $plugins = get_list_of_plugins('blocks/learnerscript/components/'.$comp);
            $optionsplugins = [];
            foreach ($plugins as $p) {
                require_once($CFG->dirroot.'/blocks/learnerscript/components/'.$comp.'/'.$p.'/plugin.class.php');
                $pluginclassname = 'block_learnerscript\lsreports\plugin_'.$p;
                $pluginclass = new $pluginclassname($report);
                if (in_array($report->type, $pluginclass->reporttypes)) {
                    if ($pluginclass->unique && in_array($p, $currentplugins)) {
                        continue;
                    }
                    $optionsplugins[$p] = get_string($p, 'block_learnerscript');
                }
            }
            asort($optionsplugins);
        }
        return $optionsplugins;
    }
    /**
     * Column definations
     * @param  object $reportclass Report data
     * @return array
     */
    public function column_definations($reportclass) {
        $columndefs = [];
        $datacolumns = [];
        $i = 0;
        $re = [];
        if (!empty($reportclass->finalreport->table->head)) {
            $re = array_diff(array_keys($reportclass->finalreport->table->head), $reportclass->orderable);
        }
        if (!empty($reportclass->finalreport->table->head)) {
            foreach ($reportclass->finalreport->table->head as $key => $value) {
                $datacolumns[]['data'] = $value;
                $columndef = new stdClass();
                $align = $reportclass->finalreport->table->align[$i] ? $reportclass->finalreport->table->align[$i] : 'left';
                $wrap = ($reportclass->finalreport->table->wrap[$i] == 'wrap') ? 'break-all' : 'normal';
                $width = ($reportclass->finalreport->table->size[$i]) ? $reportclass->finalreport->table->size[$i] : '';
                $columndef->className = 'dt-body-'. $align;

                $columndef->wrap = $wrap;
                $columndef->width = $width;
                $columndef->targets = $i;
                if ($re[$i]) {
                    $columndef->orderable = false;
                } else {
                    $columndef->orderable = true;
                }
                $i++;
                $columndefs[] = $columndef;
            }
        }
        return compact('datacolumns', 'columndefs');
    }

    /**
     * Check rolewise permissions
     *
     * @param  int $reportid Report ID
     * @param  string $role   Loggedin user role
     * @return boolean
     */
    public function check_rolewise_permission($reportid, $role) {
        global $DB, $USER, $SESSION;
        $context = context_system::instance();
        $roleid = $DB->get_field('role', 'id', ['shortname' => $role]);
        if ((!is_siteadmin() && has_capability('block/learnerscript:managereports', $context, $USER->id))
        || ($role == 'manager' && $SESSION->ls_contextlevel == CONTEXT_SYSTEM)) {
            return true;
        }
        $reportcomponents = $DB->get_field('block_learnerscript', 'components', ['id' => $reportid]);
        $components = (new ls)->cr_unserialize($reportcomponents);
        $permissions = (isset($components['permissions'])) ? $components['permissions'] : [];

        if (empty($permissions['elements'])) {
            return false;
        } else {
            foreach ($permissions['elements'] as $p) {
                if ($p['pluginname'] == 'roleincourse') {
                    if ($roleid == $p['formdata']->roleid && $SESSION->ls_contextlevel == $p['formdata']->contextlevel) {
                        return true;
                    }
                }
            }
            return false;
        }
    }

    /**
     * List of reports by role
     *
     * @param  boolean $coursels
     * @param  boolean $statistics
     * @param  boolean $parentcheck
     * @param  boolean $reportslist
     * @return array Report roles
     */
    public function listofreportsbyrole($coursels = false, $statistics = false, $parentcheck = false, $reportslist = false) {
        global $DB, $PAGE, $SESSION;
        // Course context reports.
        if ($PAGE->context->contextlevel == 50 || $PAGE->context->contextlevel == 70) {
            $coursels = true;
        }

        if ($statistics) {
            $statisticsreports = [];
            $roles = $DB->get_records_sql("SELECT id, shortname FROM {role}");
            foreach ($roles as $role) {
                $rolereports = (new ls)->rolewise_statisticsreports($role->shortname);
                foreach ($rolereports as $key => $value) {
                    $statisticsreports[$value] = $value;
                }
            }
            if (empty($SESSION->role) && !empty($statisticsreports)) {
                list($ssql, $params) = $DB->get_in_or_equal($statisticsreports, SQL_PARAMS_NAMED, 'param', false);
                $params['global'] = 1;
                $params['visible'] = 1;
                $params['type'] = 'statistics';
                $reportlist = $DB->get_records_select_menu('block_learnerscript', "global = :globala AND visible = :visible
                AND id $ssql AND type = :type", $params, '', 'id, name');
            } else {
                $params['global'] = 1;
                $params['visible'] = 1;
                $params['type'] = 'statistics';
                $reportlist = $DB->get_records_select_menu('block_learnerscript', "global = :global AND
                visible = :visible AND type = :type", $params, '', 'id, name');
            }
        } else {
            $params['global'] = 1;
            $params['visible'] = 1;
            $params['type'] = 'statistics';
            $reportlist = $DB->get_records_select_menu('block_learnerscript', "global = :global AND
            visible = :visible AND type != :type", $params, '', 'id, name');
        }

        $rolereports = [];
        if (!empty($reportlist)) {
            $properties = new stdClass();
            $properties->courseid = SITEID;
            $properties->start = 0;
            $properties->length = 1;
            $properties->search = '';
            foreach ($reportlist as $key => $value) {
                if (!empty($SESSION->role)) {
                    $checkrolewisepermission = (new ls)->check_rolewise_permission($key, $SESSION->role);
                    if ($checkrolewisepermission == false) {
                        continue;
                    }
                }
                $reportcontenttypes = (new ls)->cr_listof_reporttypes($key);
                if (count($reportcontenttypes) < 1 && $coursels) {
                    continue;
                }
                $report = $this->create_reportclass($key, $properties);
                if (!$reportslist) {
                    if ($report->parent == false && !$parentcheck && !$coursels) {
                        if ($report->type != 'userprofile') {
                            echo '';
                        }
                    }
                }
                if ($coursels) {
                    if (!$report->courselevel) {
                        continue;
                    }
                }
                $rolereports[] = ['id' => $key, 'name' => $value];
            }
        }
        return $rolereports;
    }

    /**
     * Rolewise statistics reports
     * @param  string $role   Role
     * @return array List of statistic reports
     */
    public function rolewise_statisticsreports($role) {
        global $DB, $SESSION;
        if (empty($role) || ($role == 'manager' && $SESSION->ls_contextlevel == CONTEXT_SYSTEM)) {
            return [];
        }
        $params['global'] = 1;
        $params['visible'] = 1;
        $params['type'] = 'statistics';
        $reportlist = $DB->get_records_select_menu('block_learnerscript', "global = :global AND
        visible = :visible AND type = :type", $params, '', 'id, name');
        $statisticsreports = [];
        if (!empty($reportlist)) {
            foreach ($reportlist as $key => $value) {
                if (!empty($role)) {
                    $checkrolewisepermission = (new ls)->check_rolewise_permission($key, $SESSION->role);
                    if ($checkrolewisepermission == false) {
                        continue;
                    }
                }
                $statisticsreports[] = $key;
            }
        }
        return $statisticsreports;
    }

    /**
     * Get report tiles list
     * @param  string $reporttype        Report type
     * @param  array $reportclassparams  Report class parameters
     * @return string
     */
    public function get_reporttitle($reporttype, $reportclassparams) {
        global $DB;
        $reporttitle = $reporttype;
        if (array_key_exists('filter_courses', $reportclassparams) && $reporttitle != 'Course profile') {
            $coursename = $DB->get_field('course', 'fullname', ['id' => $reportclassparams['filter_courses']]);
            $reporttitle = str_replace('Course', '<b>'.$coursename.'</b> Course', $reporttype);
        }
        if (array_key_exists('filter_status', $reportclassparams) && $reportclassparams['filter_status'] != 'all') {
            $reporttitle = $reporttitle . ' - ' . '<b>' . get_string($reportclassparams['filter_status'],
            'block_learnerscript') . '</b>';
        }
        if (array_key_exists('filter_users', $reportclassparams)) {
            if (is_int($reportclassparams['filter_users'])) {
                $learnername = $DB->get_field_sql("SELECT firstname AS fullname FROM {user}
                                                    WHERE id = (:filterusers) ",
                                                    ['filterusers' => $reportclassparams['filter_users']]);
                $reporttitle = str_replace('Learner', '<b>'.$learnername.'</b>', $reporttitle);
                $reporttitle = str_replace('My', '<b>'.$learnername.'\'s</b>', $reporttitle);
            }
        }
        return $reporttitle;
    }

    /**
     * Import LS user tours
     */
    public function importlsusertours() {
        global $CFG, $DB;
        $usertours = $CFG->dirroot . '/blocks/learnerscript/usertours/';
        $totalusertours = count(glob($usertours . '*.json'));
        $usertoursjson = glob($usertours . '*.json');
        $pluginmanager = new \tool_usertours\manager();
        for ($i = 0; $i < $totalusertours; $i++) {
            $importurl = $usertoursjson[$i];
            if (file_exists($usertoursjson[$i])
                    && pathinfo($usertoursjson[$i], PATHINFO_EXTENSION) == 'json') {
                $data = file_get_contents($importurl);
                $tourconfig = json_decode($data);
                $tourexists = $DB->record_exists('tool_usertours_tours', ['name' => $tourconfig->name]);
                if (!$tourexists) {
                    $tour = $pluginmanager->import_tour_from_json($data);
                }
            }
        }
    }

    /**
     * Learnerscript reports configuration
     */
    public function lsconfigreports() {
        global $CFG, $DB;
        $path = $CFG->dirroot . '/blocks/learnerscript/reportsbackup/';
        $learnerscriptreports = glob($path . '*.xml');
        $lsreportscount = $DB->count_records('block_learnerscript');
        $lsimportlogs = [];
        $lastreport = 0;
        foreach ($lsimportlogs as $lsimportlog) {
            $lslog = unserialize($lsimportlog);
            if ($lslog['status'] == false) {
                $errorreportsposition[$lslog['position']] = $lslog['position'];
            }

            if ($lslog['status'] == true) {
                $lastreportposition = $lslog['position'];
            }
        }

        $importstatus = false;
        if (empty($lsimportlogs) || $lsreportscount < 1) {
            $total = count($learnerscriptreports);
            $current = 1;
            $percentwidth = $current / $total * 100;
            $importstatus = true;
            $errorreportsposition = [];
            $lastreportposition = 0;
        } else {
            $total = 0;
            foreach ($learnerscriptreports as $position => $learnerscriptreport) {
                if ((!empty($errorreportsposition) && in_array($position, $errorreportsposition))
                || $position >= $lastreportposition) {
                    $total++;
                }
            }
            if (empty($errorreportsposition)) {
                $current = $lastreportposition + 1;
                $errorreportsposition = [];
            } else {
                $occuredpositions = array_merge($errorreportsposition, [$lastreportposition]);
                $current = min($occuredpositions);
            }
            if ($total > 0) {
                $importstatus = true;
            }
        }
        $errorreportspositiondata = serialize($errorreportsposition);
        return compact('importstatus', 'total', 'current', 'errorreportspositiondata',
            'lastreportposition');
    }

    /**
     * Get logged in user roles list
     * @param  int $userid      User ID
     * @param  int $contextlevel Loggedin user contextlevel
     * @return array Loggedin user roles list
     */
    public function get_currentuser_roles($userid = false, $contextlevel = null) {
        global $DB, $USER, $SESSION;
        $userid = $userid > 0 ? $userid : $USER->id;
        $rolesql = "SELECT DISTINCT r.id, r.shortname
        FROM {role} r
        JOIN {role_assignments} ra ON ra.roleid = r.id
        JOIN {context} ctx ON ctx.id = ra.contextid
        WHERE ra.userid = :userid";
        if ($contextlevel || !empty($SESSION->ls_contextlevel)) {
            if ($SESSION->ls_contextlevel) {
                $rolesql .= " AND ctx.contextlevel= " .$SESSION->ls_contextlevel;
            } else {
                $rolesql .= " AND ctx.contextlevel=$contextlevel";
            }
        }
        $roles = $DB->get_records_sql_menu($rolesql, ['userid' => $userid]);
        ksort($roles);
        return $roles;
    }

    /**
     * Schedule task for user scorm timespent
     */
    public function userscormtimespent() {
        global $DB;
        $scormrecord = get_config('block_learnerscript', 'userscormtimespent');
        if (empty($scormrecord)) {
            set_config('userscormtimespent', 0, 'block_learnerscript');
        }
        $scormcrontime = get_config('block_learnerscript', 'userscormtimespent');
        $moduleid = $DB->get_field('modules', 'id', ['name' => 'scorm']);
        if ($scormcrontime == 0) {
            $scormdetails = $DB->get_records_sql("SELECT sst.id, sa.userid, sa.scormid, sst.value AS time
            FROM {scorm_scoes_value} sst
            JOIN {scorm_scoes} ss ON ss.id = sst.scoid
            JOIN {scorm_attempt} sa ON sa.id = sst.attemptid
            JOIN {scorm_element} se ON se.id = sst.elementid
            JOIN {scorm_scoes_value} sst1 ON sst1.scoid = sst.scoid AND sa.id = sst1.attemptid
            JOIN {scorm_element} se1 ON se1.id = sst1.elementid
            WHERE se.element LIKE 'cmi.core.total_time' AND sst1.value IN ('passed', 'completed', 'failed')
            AND sa.userid > 2 ");
            $time = time();
            set_config('userscormtimespent', $time, 'block_learnerscript');
        } else if ($scormcrontime > 0) {
            $scormdetails = $DB->get_records_sql("SELECT sst.id, sa.userid, sa.scormid, sst.value AS time
            FROM {scorm_scoes_value} sst
            JOIN {scorm_scoes} ss ON ss.id = sst.scoid
            JOIN {scorm_attempt} sa ON sa.id = sst.attemptid
            JOIN {scorm_element} se ON se.id = sst.elementid
            JOIN {scorm_scoes_value} sst1 ON sst1.scoid = sst.scoid AND sa.id = sst1.attemptid
            JOIN {scorm_element} se1 ON se1.id = sst1.elementid
            WHERE se.element LIKE 'cmi.core.total_time' AND sst1.value IN ('passed', 'completed', 'failed')
            AND sa.userid > 2 AND sst.timemodified > :scormcrontime ",
            ['scormcrontime' => $scormcrontime]);
            $time = time();
            set_config('userscormtimespent', $time, 'block_learnerscript');
        }
        if (empty($scormdetails)) {
            return true;
        }
        foreach ($scormdetails as $scormdetail) {
            $coursemoduleid = $DB->get_field('course_modules', 'id', ['module' => $moduleid,
            'instance' => $scormdetail->scormid, 'visible' => 1, 'deletioninprogress' => 0, ]);
            $courseid = $DB->get_field('scorm', 'course', ['id' => $scormdetail->scormid]);
            $insertdata = new stdClass();
            $insertdata->userid = $scormdetail->userid;
            $insertdata->courseid = $courseid;
            $insertdata->instanceid = $scormdetail->scormid;
            $insertdata->timespent = round($this->timetoseconds($scormdetail->time));
            $insertdata->activityid = $coursemoduleid;
            $insertdata->timecreated = time();
            $insertdata->timemodified = 0;
            $insertdata1 = new stdClass();
            $insertdata1->userid = $scormdetail->userid;
            $insertdata1->courseid = $courseid;
            $insertdata1->timespent = round($this->timetoseconds($scormdetail->time));
            $insertdata1->timecreated = time();
            $insertdata1->timemodified = 0;
            $records1 = $DB->get_records('block_ls_coursetimestats',
                        ['userid' => $insertdata1->userid,
                            'courseid' => $insertdata1->courseid, ]);
            if (!empty($records1)) {
                foreach ($records1 as $record1) {
                    $insertdata1->id = $record1->id;
                    $insertdata1->timespent += round($record1->timespent);
                    $insertdata1->timemodified = time();
                    $DB->update_record('block_ls_coursetimestats', $insertdata1);
                }
            } else {
                $insertdata1->timecreated = time();
                $insertdata1->timemodified = 0;
                $DB->insert_record('block_ls_coursetimestats', $insertdata1);
            }
            $records = $DB->get_records('block_ls_modtimestats',
                        ['courseid' => $insertdata->courseid,
                            'activityid' => $insertdata->activityid,
                            'instanceid' => $insertdata->instanceid,
                            'userid' => $insertdata->userid, ]);
            if ($insertdata->instanceid != 0) {
                if (!empty($records)) {
                    foreach ($records as $record) {
                        $insertdata->id = $record->id;
                        $insertdata->timespent += round($record->timespent);
                        $insertdata->timemodified = time();
                        $DB->update_record('block_ls_modtimestats', $insertdata);
                    }
                } else {
                    $insertdata->timecreated = time();
                    $insertdata->timemodified = 0;
                    $DB->insert_record('block_ls_modtimestats', $insertdata);
                }
            }
        }
    }

    /**
     * Schedule task for user quiz timespent
     */
    public function userquiztimespent() {
        global $DB;
        $quizrecord = get_config('block_learnerscript', 'userquiztimespent');
        if (empty($quizrecord)) {
            set_config('userquiztimespent', 0, 'block_learnerscript');
        }
        $quizcrontime = get_config('block_learnerscript', 'userquiztimespent');
        $moduleid = $DB->get_field('modules', 'id', ['name' => 'quiz']);
        if ($quizcrontime == 0) {
            $quizdetails = $DB->get_records_sql("SELECT DISTINCT qa.id, qa.userid,
            SUM(qa.timefinish - qa.timestart) AS time1, qa.quiz AS quizid, q.course AS courseid
            FROM {user} u
            JOIN {quiz_attempts} qa ON qa.userid = u.id
            JOIN {user_enrolments} ue ON ue.userid = u.id AND ue.status = 0
            JOIN {enrol} e ON e.id = ue.enrolid AND e.status = 0
            JOIN {quiz} q ON q.id = qa.quiz
            WHERE qa.preview = 0 AND q.course = e.courseid AND qa.state = (:finished) AND qa.userid > 2
            GROUP BY qa.userid, qa.quiz, q.course, qa.id", ['finished' => 'finished']);
            $time = time();
            set_config('userquiztimespent', $time, 'block_learnerscript');
        } else if ($quizcrontime > 0) {
            $quizdetails = $DB->get_records_sql("SELECT DISTINCT qa.id, qa.userid,
            SUM(qa.timefinish - qa.timestart) AS time1, qa.quiz AS quizid, q.course AS courseid
            FROM {user} u
            JOIN {quiz_attempts} qa ON qa.userid = u.id
            JOIN {user_enrolments} ue ON ue.userid = u.id AND ue.status = 0
            JOIN {enrol} e ON e.id = ue.enrolid AND e.status = 0
            JOIN {quiz} q ON q.id = qa.quiz
            WHERE qa.preview = 0 AND q.course = e.courseid AND qa.state = (:finished)
            AND qa.timemodified > :quizcrontime AND qa.userid > 2
            GROUP BY qa.userid, qa.quiz, q.course, qa.id", ['finished' => 'finished',
            'quizcrontime' => $quizcrontime, ]);
            $time = time();
            set_config('userquiztimespent', $time, 'block_learnerscript');
        }
        if (empty($quizdetails)) {
            return true;
        }
        foreach ($quizdetails as $quizdetail) {
            $coursemoduleid = $DB->get_field('course_modules', 'id',
            ['module' => $moduleid, 'instance' => $quizdetail->quizid, 'visible' => 1,
            'deletioninprogress' => 0, ]);
            $courseid = $DB->get_field('quiz', 'course', ['id' => $quizdetail->quizid]);
            $insertdata = new stdClass();
            $insertdata->userid = $quizdetail->userid;
            $insertdata->courseid = $courseid;
            $insertdata->instanceid = $quizdetail->quizid;
            $insertdata->timespent = round($quizdetail->time1);
            $insertdata->activityid = $coursemoduleid;
            $insertdata->timecreated = time();
            $insertdata->timemodified = 0;
            $insertdata1 = new stdClass();
            $insertdata1->userid = $quizdetail->userid;
            $insertdata1->courseid = $courseid;
            $insertdata1->timespent = round($quizdetail->time1);
            $insertdata1->timecreated = time();
            $insertdata1->timemodified = 0;
            $records1 = $DB->get_records('block_ls_coursetimestats',
                        ['userid' => $insertdata1->userid,
                            'courseid' => $insertdata1->courseid, ]);
            if (!empty($records1)) {
                foreach ($records1 as $record1) {
                    $insertdata1->id = $record1->id;
                    $insertdata1->timespent += round($record1->timespent);
                    $insertdata1->timemodified = time();
                    $DB->update_record('block_ls_coursetimestats', $insertdata1);
                }
            } else {
                $insertdata1->timecreated = time();
                $insertdata1->timemodified = 0;
                $DB->insert_record('block_ls_coursetimestats', $insertdata1);
            }
            $records = $DB->get_records('block_ls_modtimestats',
                        ['courseid' => $insertdata->courseid,
                            'activityid' => $insertdata->activityid,
                            'instanceid' => $insertdata->instanceid,
                            'userid' => $insertdata->userid, ]);
            if ($insertdata->instanceid != 0) {
                if (!empty($records)) {
                    foreach ($records as $record) {
                        $insertdata->id = $record->id;
                        $insertdata->timespent += round($record->timespent);
                        $insertdata->timemodified = time();
                        $DB->update_record('block_ls_modtimestats', $insertdata);
                    }
                } else {
                    $insertdata->timecreated = time();
                    $insertdata->timemodified = 0;
                    $DB->insert_record('block_ls_modtimestats', $insertdata);
                }
            }
        }
    }
    /**
     * Timestamp to user time conversion
     * @param  int $values  Timestamp
     * @return string User timespent
     */
    public function strtime($values) {
        global $OUTPUT;
        $totalval = $values;
        $day = intval($values / 86400);
        $values -= $day * 86400;
        $hours = intval($values / 3600);
        $values -= $hours * 3600;
        $minutes = intval($values / 60);
        $values -= $minutes * 60;
        $dateimage = $OUTPUT->pix_icon('courseprofile/date', '', 'block_reportdashboard', ['class' => 'dateicon']);
        if (!empty($hours)) {
            $hrs = ($hours == 1) ? $hours. get_string('hr', 'block_learnerscript').' ' :
                            $hours. get_string('hrs', 'block_learnerscript').' ';
        } else {
            $hrs = '';
        }
        if (!empty($minutes)) {
            $min = $minutes. get_string('mins', 'block_learnerscript').' ';
        } else {
            $min = '';
        }
        if (!empty($values)) {
            $sec = $values. get_string('sec', 'block_learnerscript');
        } else {
            $sec = '';
        }
        if ($day == 1) {
            $days = $dateimage . $day. get_string('day', 'block_learnerscript').' ';
        } else if ($day > 1) {
            $days = $dateimage . $day. get_string('days', 'block_learnerscript').' ';
        } else {
            $days = '';
        }
        $timeimage = '';
        if (empty($totalval)) {
            $timeimage = '';
        } else {
            $timeimage = $OUTPUT->pix_icon('courseprofile/time1', '', 'block_reportdashboard', ['class' => 'timeicon']);
        }
        $result = $days . $timeimage . $hrs . $min . $sec;
        return $result;
    }

    /**
     * Switch role options
     */
    public function switchrole_options() {
        global $DB, $USER, $SESSION;
        $data = [];
        if (!empty($SESSION->role)) {
            $data['currentrole'] = $SESSION->role;
            $data['dashboardrole'] = $SESSION->role;
            $data['dashboardcontextlevel'] = $SESSION->ls_contextlevel;
        } else {
            $data['currentrole'] = get_string('switchrole', 'block_learnerscript');
            $data['dashboardrole'] = '';
        }
        if (!is_siteadmin()) {
            $rolesql = "SELECT DISTINCT concat(r.id, '-',ctx.contextlevel) as roleid, r.shortname
                        FROM {role} r
                        JOIN {role_assignments} ra ON ra.roleid = r.id
                        JOIN {context} ctx ON ctx.id = ra.contextid
                        WHERE ra.userid = :userid";
                $roles = $DB->get_records_sql($rolesql, ['userid' => $USER->id]);
                ksort($roles);
        } else {
            $rolesql = "SELECT DISTINCT concat(r.id, '-',rcl.contextlevel)  as roleid, r.shortname
                   FROM {role} r
                   JOIN {role_context_levels} rcl ON rcl.roleid = r.id
                   WHERE 1 = 1 AND rcl.contextlevel != " . CONTEXT_MODULE;
            $roles = $DB->get_records_sql($rolesql);
            ksort($roles);
        }
        if (is_siteadmin() || count($roles) > 0) {
            $data['switchrole'] = true;
        }
        $unusedroles = ['user', 'guest', 'frontpage'];
        foreach ($roles as $key => $value) {
            $rolecontext = explode("-", $key);
            $roleshortname = $DB->get_field('role', 'shortname', ['id' => $rolecontext[0]]);
            if (in_array($roleshortname, $unusedroles)) {
                continue;
            }
            $active = '';

            if ($roleshortname == $SESSION->role && $rolecontext[1] == $SESSION->ls_contextlevel) {
                $active = 'active';
            }
            switch ($roleshortname) {
                case 'manager':
                    $value1 = get_string('manager' , 'role');
                    break;
                case 'coursecreator':
                    $value1 = get_string('coursecreators');
                    break;
                case 'editingteacher':
                    $value1 = get_string('defaultcourseteacher');
                    break;
                case 'teacher':
                    $value1 = get_string('noneditingteacher');
                    break;
                case 'student':
                    $value1 = get_string('defaultcoursestudent');
                    break;
                case 'guest':
                    $value1 = get_string('guest');
                    break;
                case 'user':
                    $value1 = get_string('authenticateduser');
                    break;
                case 'frontpage':
                    $value1 = get_string('frontpageuser', 'role');
                    break;
                // We should not get here, the role UI should require the name for custom roles!
                default:
                    $value1 = $value->shortname;
                break;
            }
            $contexttext = '';
            if ($rolecontext[1] == CONTEXT_SYSTEM) {
                $contexttext = "System";
            } else if ($rolecontext[1] == CONTEXT_COURSECAT) {
                $contexttext = "Category";
            } else if ($rolecontext[1] == CONTEXT_COURSE) {
                $contexttext = "Course";
            }
            $data['roles'][] = ['roleshortname' => $roleshortname, 'rolename' => $contexttext." ".$value1,
                                'active' => $active, 'contextlevel' => $rolecontext[1], ];
        }
        return $data;
    }

    /**
     * Checking loggedin user role is manager
     *
     * @param  int $userid       User ID
     * @param  int $contextlevel User contextlevel
     * @param  string $role      User role
     */
    public function is_manager($userid = null, $contextlevel = null, $role = null) {
        global $USER, $DB, $SESSION;
        $SESSION->role = isset($SESSION->role) ? $SESSION->role : $role;
        $SESSION->ls_contextlevel = isset($SESSION->ls_contextlevel) ? $SESSION->ls_contextlevel : $contextlevel;
        if (isset($SESSION->role) && ($SESSION->role != 'manager' && $SESSION->ls_contextlevel != CONTEXT_SYSTEM)) {
            return false;
        }
        if ($userid == null) {
            $userid = $USER->id;
        }
        $context = context_system::instance();
        if ($SESSION->ls_contextlevel == CONTEXT_SYSTEM) {
            $roleid = $DB->get_field('role', 'id', ['shortname' => 'manager']);
            if (user_has_role_assignment($userid, $roleid, $context->id)) {
                return true;
            }
        }
    }

    /**
     * Converting time to seconds
     *
     * @param  string $timevalue Timespent data
     * @return int Timestamp
     */
    public function timetoseconds($timevalue) {
        $strtime = $timevalue;
        $strtime = preg_replace_callback(
        "/^([\d]{1,2})\:([\d]{2})$/",
        function($matches) {
            return "00:{$matches[1]}:{$matches[2]}";
        },
        $strtime
        );;
        sscanf($strtime, "%d:%d:%d", $hours, $minutes, $seconds);
        $timeseconds = $hours * 3600 + $minutes * 60 + $seconds;
        return $timeseconds;
    }
    /**
     * Summary of userlmsaccess
     * @return void
     */
    public function userlmsaccess() {
        global $DB;
        $start    = new DateTime('monday last week');
        $end      = new DateTime('sunday last week');
        $interval = new \DateInterval('P1D');
        $period   = new \DatePeriod($start, $interval, $end);
        foreach ($period as $dt) {
            $weekdays = $dt->format("l");
            $weekdaysql = $dt->format('m/d/Y');
            $weekdaysdate[] = $weekdaysql;
            $weekdayslist[] = $weekdays;
            strtotime($weekdaysql . ' 09:00:00');
        }
        $timingslist = ['09-10Am', '10-11Am', '11-12Pm', '02-03Pm', '03-04Pm', '04-05Pm', '05-06Pm', '06-07Pm'];
        $timings = ['09-10', '10:00:01-11', '11:00:01-12', '14:00:01-15', '15:00:01-16',
                        '16:00:01-17', '17:00:01-18', '18:00:01-19', ];
        $users = $DB->get_records_sql("SELECT DISTINCT ue.userid AS id
                        FROM {course} c
                        JOIN {enrol} e ON e.courseid = c.id AND e.status = 0
                        JOIN {user_enrolments} ue on ue.enrolid = e.id AND ue.status = 0
                        JOIN {role_assignments}  ra ON ra.userid = ue.userid
                        JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
                        JOIN {context} ctx ON ctx.instanceid = c.id
                        JOIN {user} u ON u.id = ra.userid AND u.confirmed = 1 AND u.deleted = 0
                        WHERE ra.contextid = ctx.id AND ctx.contextlevel = 50 AND c.visible = 1");

        foreach ($users as $user) {
            $timestampdiff = [];
            foreach ($weekdaysdate as $key => $weekdate) {
                for ($i = 0; $i <= 7; $i++) {
                    $currenttime = explode('-', $timings[$i]);
                    if (is_numeric($currenttime[0])) {
                        $starttime = strtotime($weekdate . ' ' . $currenttime[0] . ':00:00');
                    } else {
                        $starttime = strtotime($weekdate . ' ' . $currenttime[0]);
                    }
                    $endtime = strtotime($weekdate . ' ' . $currenttime[1] . ':00:00');
                    $accesscount = $DB->get_records_sql("SELECT id FROM {logstore_standard_log} WHERE action = :action
                                AND userid = :userid AND timecreated BETWEEN :starttime AND :endtime",
                                    ['action' => 'loggedin', 'userid' => $user->id, 'starttime' => $starttime,
                                    'endtime' => $endtime, ]);
                    $timestampdiff[] = [$key, $i, count($accesscount)];
                }
            }
            $options = ["type" => "heatmap",
                            "title" => "LMS access",
                            "xAxis" => $weekdayslist,
                            "yAxis" => $timingslist,
                            "data" => $timestampdiff,
                            ];
            $logindata = json_encode($options, JSON_NUMERIC_CHECK);
            $insertdata = new stdClass();
            $record = $DB->get_field_sql("SELECT id FROM {block_ls_userlmsaccess} WHERE userid = :userid",
                                        ['userid' => $user->id]);
            if (empty($record)) {
                $insertdata->userid = $user->id;
                $insertdata->logindata = $logindata;
                $insertdata->timecreated = time();
                $insertdata->timemodified = 0;
                $DB->insert_record('block_ls_userlmsaccess', $insertdata);
            } else {
                $insertdata->id = $record;
                $insertdata->userid = $user->id;
                $insertdata->logindata = $logindata;
                $insertdata->timemodified = time();
                $DB->update_record('block_ls_userlmsaccess', $insertdata);
            }
        }
        echo get_string('taskcomplete', 'block_learnerscript');
    }
}
