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
 * Web service for Block LearnerScript
 * @package    block_learnerscript
 * @subpackage db
 * @since      Moodle 3.3
 * @copyright  2023 Moodle India Information Solutions Private Limited
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();
$functions = [
'block_learnerscript_rolewiseusers' => [
        'classname' => 'block_learnerscript_external',
        'methodname' => 'rolewiseusers',
        'classpath' => 'blocks/learnerscript/externallib.php',
        'description' => 'case rolewiseusers',
        'ajax' => true,
    ],
'block_learnerscript_generate_plotgraph' => [
        'classname' => 'block_learnerscript_external',
        'methodname' => 'generate_plotgraph',
        'classpath' => 'blocks/learnerscript/externallib.php',
        'description' => 'case generate_plotgraph',
        'ajax' => true,
    ],
'block_learnerscript_frequency_schedule' => [
        'classname' => 'block_learnerscript_external',
        'methodname' => 'frequency_schedule',
        'classpath' => 'blocks/learnerscript/externallib.php',
        'description' => 'case frequency_schedule',
        'ajax' => true,
    ],
'block_learnerscript_deletecomponenet' => [
        'classname' => 'block_learnerscript_external',
        'methodname' => 'deletecomponenet',
        'classpath' => 'blocks/learnerscript/externallib.php',
        'description' => 'Delete a perticular component for the report',
        'ajax' => true,
    ],
'block_learnerscript_reportfilter' => [
        'classname' => 'block_learnerscript_external',
        'methodname' => 'reportfilterform',
        'classpath' => 'blocks/learnerscript/externallib.php',
        'description' => 'case dashboard name edit',
        'ajax' => true,
    ],
'block_learnerscript_importreports' => [
        'classname' => 'block_learnerscript_external',
        'methodname' => 'importreports',
        'classpath' => 'blocks/learnerscript/externallib.php',
        'description' => 'Import Reports',
        'ajax' => true,
    ],
'block_learnerscript_lsreportconfigimport' => [
        'classname' => 'block_learnerscript_external',
        'methodname' => 'lsreportconfigimport',
        'classpath' => 'blocks/learnerscript/externallib.php',
        'description' => 'Import Reports Status',
        'ajax' => true,
    ],
'block_learnerscript_filter_courses' => [
        'classname' => 'block_learnerscript_external',
        'methodname' => 'filter_courses',
        'classpath' => 'blocks/learnerscript/externallib.php',
        'description' => 'Filter Courses',
        'ajax' => true,
    ],
'block_learnerscript_filterusers' => [
        'classname' => 'block_learnerscript_external',
        'methodname' => 'filterusers',
        'classpath' => 'blocks/learnerscript/externallib.php',
        'description' => 'Filter users',
        'ajax' => true,
    ],
];
