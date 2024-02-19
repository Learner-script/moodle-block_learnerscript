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
 * A Moodle block to create customizable reports.
 *
 * @package   block_learnerscript
 * @copyright 2023 Moodle India
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$settings->add(new admin_setting_configcheckbox('block_learnerscript/sqlsecurity',
get_string('sqlsecurity', 'block_learnerscript'), get_string('sqlsecurityinfo', 'block_learnerscript'), 1));
$settings->add(new admin_setting_configcheckbox('block_learnerscript/exportfilesystem',
get_string('exportfilesystem', 'block_learnerscript'), get_string('exportfilesystem', 'block_learnerscript'), 1));
$settings->add(new admin_setting_configtext('block_learnerscript/exportfilesystempath',
get_string('exportfilesystempath', 'block_learnerscript'), get_string('exportfilesystempathdesc', 'block_learnerscript'),
'learnerscript/reports', PARAM_URL, 40));
$settings->add(new admin_setting_configcolourpicker('block_learnerscript/analytics_color',
get_string('analytics_color', 'block_learnerscript'), get_string('analytics_color_desc', 'block_learnerscript'), '#FFFFFF'));
$settings->add(new admin_setting_configstoredfile('block_learnerscript/logo',
get_string('logo', 'block_learnerscript'), get_string('logo_desc', 'block_learnerscript'), 'logo', 0,
['maxfiles' => 1, 'accepted_types' => ['image']]));
