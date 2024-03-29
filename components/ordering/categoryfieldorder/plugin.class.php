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
namespace block_learnerscript\lsreports;
use block_learnerscript\local\pluginbase;

/** Order by category fields */
class plugin_categoryfieldorder extends pluginbase {

    /** @var $sql */
    public $sql = true;

    /** Category fields */
    public function init() {
        $this->fullname = get_string('categoryfield', 'block_learnerscript');
        $this->form = true;
        $this->unique = true;
        $this->reporttypes = ['categories'];
        $this->sql = true;
    }

    /**
     * Summary
     * @param  object $data
     * @return string
     */
    public function summary($data) {
        return $data->column . ' ' . (strtoupper($data->direction));
    }

    /**
     * Execute
     * @param  object $data
     * @return string
     */
    public function execute($data) {
        global $DB;

        if ($data->direction == 'asc' || $data->direction == 'desc') {
            $direction = strtoupper($data->direction);
            $columns = $DB->get_columns('course_categories');

            $categorycolumns = [];
            foreach ($columns as $c) {
                $categorycolumns[$c->name] = $c->name;
            }
            if (isset($categorycolumns[$data->column])) {
                return 'ca.' . $data->column . ' ' . $direction;
            }
        }
        return '';
    }
    /**
     * Columns
     * @return array
     */
    public function columns() {
        global $DB;
        return $DB->get_columns('course_categories');
    }

}
