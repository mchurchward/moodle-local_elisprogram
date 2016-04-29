<?php
/**
 * ELIS(TM): Enterprise Learning Intelligence Suite
 * Copyright (C) 2008-2016 Remote-Learner.net Inc (http://www.remote-learner.net)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package    eliswidget_teachingplan
 * @author     Remote-Learner.net Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright  (C) 2015 onwards Remote-Learner.net Inc (http://www.remote-learner.net)
 * @author     Brent Boghosian <brent.boghosian@remote-learner.net>
 *
 */

namespace eliswidget_teachingplan\datatable;

if (!defined('TESTING')) {
    define('TESTING' , 1);
}

/**
 * A datatable implementation for lists of classes.
 */
class classes extends \eliswidget_common\datatable\base {
    /** @var int The ID of the instructor we're getting classes for. */
    protected $userid = null;

    /** @var int The ID of the course we're getting classes for. */
    protected $courseid = null;

    /** @var int The number of results displayed per page of the table. */
    const RESULTSPERPAGE = 1000;

    /**
     * Gets an array of available filters.
     *
     * @return array An array of \deepsight_filter objects that will be available.
     */
    public function get_filters() {
        return [];
    }

    /**
     * Get an array of fields to select in the get_search_results method.
     *
     * @param array $filters An array of requested filter data. Formatted like [filtername]=>[data].
     * @return array Array of fields to select.
     */
    protected function get_select_fields(array $filters = array()) {
        $selectfields = parent::get_select_fields($filters);
        $selectfields[] = 'mdlcrs.id AS moodlecourseid';
        $selectfields[] = 'mdlcrs.fullname AS coursefullname';
        $selectfields[] = 'mdlcrs.shortname AS courseshortname';
        $selectfields[] = 'mdlcrs.idnumber AS courseidnumber';
        $selectfields[] = 'stu.id AS enrol_id';
        $selectfields[] = 'stu.grade AS grade';
        $selectfields[] = 'stu.completestatusid AS completestatusid';
        $selectfields[] = 'stu.completetime AS completetime';
        $selectfields[] = 'waitlist.id AS waitlist_id';
        $selectfields[] = 'waitlist.classid AS waitlist_classid';
        $selectfields[] = 'waitlist.position AS waitlist_position';
        $selectfields[] = 'cls.maxstudents AS maxstudents';
        return $selectfields;
    }

    /**
     * Set the User ID of the instructor we're getting classes for.
     *
     * @param int $userid The User ID of the instructor we're getting classes for.
     */
    public function set_userid($userid) {
        $this->userid = $userid;
    }

    /**
     * Set the Course ID we're getting classes for.
     *
     * @param int $courseid The Course ID we're getting classes for.
     */
    public function set_courseid($courseid) {
        $this->courseid = $courseid;
    }

    /**
     * Static method to get an array of table columns that will always be visible.
     *
     * @return array Array of filter aliases for fields that will always be visible.
     */
    public static function get_visible_tablecolumns() {
        $visiblecolumns = ['class_header', 'moodletime', 'startdate', 'enddate', 'classtime', 'maxstudents', 'enrolled', 'instructors'];
        foreach ($visiblecolumns as $key => $val) {
            $columnenabled = get_config('eliswidget_teachingplan', $val);
            if ($columnenabled !== false && $columnenabled != 1) {
                unset($visiblecolumns[$key]);
            }
        }
        return $visiblecolumns;
    }

    /**
     * Get an array of datafields that will always be visible.
     *
     * @return array Array of filter aliases for fields that will always be visible.
     */
    public function get_fixed_visible_datafields() {
        return static::get_visible_tablecolumns();
    }

    /**
     * Get a list of desired table joins to be used in the get_search_results method.
     *
     * @param array $filters An array of requested filter data. Formatted like [filtername]=>[data].
     * @return array Array with members: First item is an array of JOIN sql fragments, second is an array of parameters used by
     *               the JOIN sql fragments.
     */
    protected function get_join_sql(array $filters = array()) {
        $newsql = [
                'LEFT JOIN {'.\classmoodlecourse::TABLE.'} clsmdl ON clsmdl.classid = cls.id',
                'LEFT JOIN {course} mdlcrs ON mdlcrs.id = clsmdl.moodlecourseid',
                'LEFT JOIN {'.\student::TABLE.'} stu ON stu.classid = cls.id
                           AND stu.userid = ?',
                'LEFT JOIN {'.\waitlist::TABLE.'} waitlist ON waitlist.classid = cls.id
                           AND waitlist.userid = ?'
        ];
        $newparams = [$this->userid, $this->userid];
        return [$newsql, $newparams];
    }

    /**
     * Converts an array of requested filter data into an SQL WHERE clause.
     *
     * @param array $filters An array of requested filter data. Formatted like [filtername]=>[data].
     * @return array An array consisting of the SQL WHERE clause, and the parameters for the SQL.
     */
    protected function get_filter_sql(array $filters = array()) {
        return parent::get_filter_sql($filters);
    }

    /**
     * Get an ORDER BY sql fragment to be used in the get_search_results method.
     *
     * @return string An ORDER BY sql fragment, if desired.
     */
    protected function get_sort_sql() {
        return 'ORDER BY element.idnumber ASC';
    }

    /**
     * Get search results/
     *
     * @param array $filters An array of requested filter data. Formatted like [filtername]=>[data].
     * @param int $page The page being displayed.
     * @return array An array of course information.
     */
    public function get_search_results(array $filters = array(), $page = 1) {
        if (defined('TESTING')) {
            $classes = [];
            for ($i = 0; $i <= $this->courseid; ++$i) {
                $cls = new \stdClass;
                $cls->element_id = $i + 2;
                $cls->class_header = '<a href="/" >CI-'.$this->courseid.'-10'.$cls->element_id.'</a><br/>Moodle Course: <a href="/">MC-1A</a>';
                $cls->idnumber = 'CI-'.$this->courseid.'-10'.$cls->element_id;
                $cls->maxstudents = '16';
                $cls->enrolled = '17(3)';
                $cls->startdate = 'Apr 1, 2016';
                $cls->enddate = 'June 30, 2016';
                $cls->classtime = '11:10am to 1:25pm';
                $cls->moodletime = 'Apr 2, 2016';
                $cls->instructors = 'James Dean, Jimmy Steward, Dave Smith, Hank Jones, Pete Jolly';
                $classes[] = $cls;
            }
            return [$classes, 12];
        }
        list($pageresults, $totalresultsamt) = parent::get_search_results($filters, $page);
        $pageresultsar = [];
        $dateformat = get_string('date_format', 'eliswidget_teachingplan');
        foreach ($pageresults as $id => $result) {
            $crsset = '';
            if (!empty($result->courseset)) {
                $crsset = get_string('courseset_format', 'eliswidget_teachingplan', $result->courseset);
            }
            if (!empty($result->moodlecourseid)) {
                $result->header = get_string('moodlecourse_header', 'eliswidget_teachingplan', $result);
                // Change Moodle course header to link.
                $mdlcrslink = new \moodle_url('/course/view.php', ['id' => $result->moodlecourseid]);
                $result->header = \html_writer::link($mdlcrslink, $result->header);
            } else {
                $result->header = get_string('course_header', 'eliswidget_teachingplan', $result);
            }
            $result->header .= $crsset;
            $pageresultsar[$id] = $result;
            if (isset($pageresultsar[$id]->completetime) && !empty($pageresultsar[$id]->completetime) &&
                    $pageresultsar[$id]->completestatusid > \student::STUSTATUS_NOTCOMPLETE) {
                $pageresultsar[$id]->completetime = userdate($pageresultsar[$id]->completetime, $dateformat);
            } else {
                $pageresultsar[$id]->completetime = get_string('date_na', 'eliswidget_teachingplan');
            }
            if (!isset($pageresultsar[$id]->meta)) {
                $pageresultsar[$id]->meta = new \stdClass;
            }
            $pageresultsar[$id]->meta->limit = $result->maxstudents;
            if (!empty($pageresultsar[$id]->waitlist_classid)) {
                $classfilter = new \field_filter('classid', $pageresultsar[$id]->waitlist_classid);
                $pageresultsar[$id]->meta->waiting = \waitlist::count($classfilter);
                $pageresultsar[$id]->meta->total = \student::count($classfilter);
            }
        }
        return [array_values($pageresultsar), $totalresultsamt];
    }
}
