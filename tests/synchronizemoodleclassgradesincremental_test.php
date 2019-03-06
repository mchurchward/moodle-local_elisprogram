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
 * @package    local_elisprogram
 * @author     Remote-Learner.net Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright  (C) 2008-2016 Remote-Learner.net Inc (http://www.remote-learner.net)
 *
 */

require_once(__DIR__.'/../../eliscore/test_config.php');
global $CFG;
require_once($CFG->dirroot.'/local/elisprogram/lib/setup.php');

// Data objects.
require_once(\elispm::lib('data/course.class.php'));
require_once(\elispm::lib('data/usermoodle.class.php'));
require_once(\elispm::lib('data/student.class.php'));
require_once(\elispm::lib('data/pmclass.class.php'));

// Libs.
require_once(\elispm::lib('lib.php'));
require_once($CFG->dirroot.'/lib/grade/grade_item.php');
require_once($CFG->dirroot.'/lib/enrollib.php');

/**
 * Tests the Synchronize class's main synchronize_moodle_class_grades method using incremental sync.
 *
 * @group local_elisprogram
 */
class synchronizemoodleclassgradesincremental_testcase extends \elis_database_test {

    /**
     * Perform setup before tests.
     */
    protected function setUp() {
        global $DB;
        parent::setUp();

        // Prevent events magic from happening.
        $DB->delete_records('events_handlers');
        \core\event\manager::phpunit_replace_observers(array());
    }

    /**
     * Load base data from CSV file
     *
     * @param boolean $linkclass If true, link the PM class to the Moodle course
     */
    protected function load_csv_data($linkclass = true) {
        $csvs = array(
            // Need PM course to create PM class.
            \course::TABLE => \elispm::file('tests/fixtures/pmcourse.csv'),
            // Need PM classes to create associations.
            \pmclass::TABLE => \elispm::file('tests/fixtures/pmclass.csv'),
            // Need a Moodle course to sync users from.
            'course' => \elispm::file('tests/fixtures/mdlcoursenonsite.csv'),
            // Need both the Moodle and the PM user.
            'user' => \elispm::file('tests/fixtures/mdluser.csv'),
            \user::TABLE => \elispm::file('tests/fixtures/pmuser.csv'),
            \usermoodle::TABLE => \elispm::file('tests/fixtures/usermoodle.csv'),
        );

        if ($linkclass) {
            // Link the course and the class.
            $csvs[\classmoodlecourse::TABLE] = \elispm::file('tests/fixtures/class_moodle_course_nonsite.csv');
        }

        $dataset = $this->createCsvDataSet($csvs);
        $this->loadDataSet($dataset);

        // Make our role a "student" role.
        set_config('gradebookroles', 1);
    }

    /**
     * Set up our main Moodle course to be enrollable
     */
    protected function make_course_enrollable() {
        set_config('enrol_plugins_enabled', 'manual');
        $enrol = enrol_get_plugin('manual');
        $course = new \stdClass;
        $course->id = 2;
        $enrol->add_instance($course);
    }

    /**
     * Validate that a certain number of student records exist
     * @param int $num The expected number of student records
     */
    protected function assert_num_students($num) {
        global $DB;
        $count = $DB->count_records(\student::TABLE);
        $this->assertEquals($num, $count);
    }

    /**
     * Validate that a certain student record exists
     *
     * @param int $classid The id of the appropriate class
     * @param int $userid  The id of the appropriate PM user
     * @param int $grade The class grade
     * @param int $completestatusid The class completion status
     * @param int $completetime The class completion time
     * @param int $credits Number of credits achieved
     * @param int $locked Whether the record is locked
     */
    protected function assert_student_exists($classid, $userid, $grade = null, $completestatusid = null, $completetime = null,
            $credits = null, $locked = null) {
        global $DB;

        // Required fields.
        $params = array('classid' => $classid, 'userid' => $userid);

        // Optional fields.
        if ($grade !== null) {
            $params['grade'] = $grade;
        }
        if ($completestatusid !== null) {
            $params['completestatusid'] = $completestatusid;
        }
        if ($completetime !== null) {
            $params['completetime'] = $completetime;
        }
        if ($credits !== null) {
            $params['credits'] = $credits;
        }
        if ($locked !== null) {
            $params['locked'] = $locked;
        }

        // Validate existence.
        $exists = $DB->record_exists(\student::TABLE, $params);
        if (!$exists) {
            ob_start();
            echo 'assert_student_exists - params:', "\n";
            var_dump($params);
            echo 'assert_student_exists - student table:', "\n";
            var_dump($DB->get_records(\student::TABLE));
            $tmp = ob_get_contents();
            ob_end_clean();
            error_log($tmp);
        }
        $this->assertTrue($exists);
    }

    /**
     * Unlock student record
     * @param int $classid The id of the appropriate class
     * @param int $userid  The id of the appropriate PM user
     */
    protected static function unlock_student_record($classid, $userid) {
        global $DB;
        $stuid = $DB->get_field(student::TABLE, 'id', ['classid' => $classid, 'userid' => $userid]);
        if (!empty($stuid)) {
            $DB->update_record(student::TABLE, (object)['id' => $stuid, 'locked' => 0]);
        }
    }

    /**
     * Validate that a certain number of student grade records exist
     *
     * @param int $num The expected number of student grade records
     */
    protected function assert_num_student_grades($num) {
        global $DB;

        $count = $DB->count_records(\student_grade::TABLE);
        $this->assertEquals($num, $count);
    }

    /**
     * Validate that a certain student grade record exists
     *
     * @param int $classid The id of the appropriate class
     * @param int $userid The id of the appropriate PM user
     * @param int $completionid The id of the appropriate LO
     * @param int $grade The LO grade
     * @param int $locked Whether the LO grade is locked
     * @param int $timegraded The graded time
     */
    protected function assert_student_grade_exists($classid, $userid, $completionid, $grade = null, $locked = null,
            $timegraded = null) {
        global $DB;

        // Required fields.
        $params = array('classid' => $classid, 'userid' => $userid, 'completionid' => $completionid);
        // Optional fields.
        if ($grade !== null) {
            $params['grade'] = $grade;
        }
        if ($locked !== null) {
            $params['locked'] = $locked;
        }
        if ($timegraded !== null) {
            $params['timegraded'] = $timegraded;
        }

        // Validate existence.
        $exists = $DB->record_exists(\student_grade::TABLE, $params);
        $this->assertTrue($exists);
    }

    /**
     * Create a Moodle grade item for our test course
     *
     * @param string $idnumber The grade item's idnumber
     * @param int $grademax The max grade (100 if not specified);
     * @param string $itemtype The grade item's itemtype (default: 'manual').
     * @return int Grade item ID
     */
    protected function create_grade_item($idnumber = 'manualitem', $grademax = null, $itemtype = 'manual') {
        // Required fields.
        $data = array(
            'courseid' => 2,
            'itemtype' => $itemtype,
            'idnumber' => $idnumber,
            'needsupdate' => false,
            'locked' => true
        );
        // Optional fields.
        if ($grademax !== null) {
            $data['grademax'] = $grademax;
        }

        // Save the record.
        $gradeitem = new \grade_item($data);
        $gradeitem->insert();
        return $gradeitem;
    }

    /**
     * Create a Moodle student grade
     *
     * @param int $itemid The grade item id
     * @param int $userid The Moodle user id
     * @param int $finalgrade The student's final grade
     * @param int $rawgrademax The maximum grade
     * @param int $timemodified The graded time
     * @param int $timecreated The grade_grade creation time.
     * @param object& $gg The grade_grade to return if required.
     * @return int The new grade_grade id.
     */
    protected function create_grade_grade($itemid, $userid, $finalgrade, $rawgrademax = 100, $timemodified = null, $timecreated = null, &$gg = null) {
        if (empty($timemodified)) {
            $timemodified = time();
        }
        if (empty($timecreated)) {
            $timecreated = time();
        }
        $gradegrade = new \grade_grade(array(
            'itemid' => $itemid,
            'userid' => $userid,
            'finalgrade' => $finalgrade,
            'rawgrademax' => $rawgrademax,
            'timemodified' => $timemodified,
            'timecreated' => $timecreated
        ));
        if (!is_null($gg)) {
            $gg = $gradegrade;
        }
        return $gradegrade->insert();
    }

    /**
     * Create a new grade history entry.
     *
     * @param array $params Of values.
     * @return object The grade object.
     */
    protected function create_grade_history($params) {
        global $DB;
        $params = (array)$params;

        if (!isset($params['itemid'])) {
            throw new coding_exception('Missing itemid key.');
        }
        if (!isset($params['userid'])) {
            throw new coding_exception('Missing userid key.');
        }

        // Default object.
        $grade = new stdClass;
        $grade->itemid = 0;
        $grade->userid = 0;
        $grade->oldid = 123;
        $grade->rawgrade = 50;
        $grade->finalgrade = 50;
        $grade->timecreated = time();
        $grade->timemodified = time();
        $grade->information = '';
        $grade->informationformat = FORMAT_PLAIN;
        $grade->feedback = '';
        $grade->feedbackformat = FORMAT_PLAIN;
        $grade->usermodified = 2;

        // Merge with data passed.
        $grade = (object)array_merge((array)$grade, $params);

        // Insert record.
        $grade->id = $DB->insert_record('grade_grades_history', $grade);

        return $grade;
    }

    /**
     * Create a PM course completion record
     *
     * @param string $idnumber The idnumber of the course completion / LO
     * @param int $completiongrade The required completion grad
     * @return int The db record id of the element
     */
    protected function create_course_completion($idnumber = 'manualitem', $completiongrade = null) {
        // Required fields.
        $data = array('courseid' => 100, 'idnumber' => $idnumber);

        // Optional fields.
        if ($completiongrade !== null) {
            $data['completion_grade'] = $completiongrade;
        }

        // Save.
        $coursecompletion = new \coursecompletion($data);
        $coursecompletion->save();

        // Return id.
        return $coursecompletion->id;
    }

    /**
     * Validate that the sync depends on course-class associations
     */
    public function test_nosynchappenswhenclassnotassociated() {
        global $DB;

        $this->load_csv_data(false);

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');
        $this->create_grade_item('', 100);

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_students(0);

        // Associate class with course.
        $classmoodlecourse = new \classmoodlecourse(['classid' => 100, 'moodlecourseid' => 2]);
        $classmoodlecourse->save();

        // Reset lastrun time.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_students(1);
    }

    /**
     * Validate that the sync depends on course-class associations when running for a specific user
     */
    public function test_nosynchappenswhenclassnotasociatedforspecificuserid() {
        global $DB;

        $this->load_csv_data(false);

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        enrol_try_internal_enrol(2, 101, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');
        $this->create_grade_item('', 100);

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_students(0);

        // Associate class with course.
        $classmoodlecourse = new \classmoodlecourse(array('classid' => 100, 'moodlecourseid' => 2));
        $classmoodlecourse->save();

        // Reset lastrun time.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_students(1);
    }

    /**
     * Validate that the sync ignores orphaned classes
     */
    public function test_nosynchappenswhenclassassociatedtodeletedmoodlecourse() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');
        $this->create_grade_item('', 100);

        // Orphan the class.
        $DB->delete_records('course');

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_students(0);

        // Re-associate.
        $dataset = $this->createCsvDataSet(array(
            'course' => \elispm::file('tests/fixtures/mdlcoursenonsite.csv')
        ));
        $this->loadDataSet($dataset);

        // Reset lastrun time.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_students(1);
    }

    /**
     * Validate that the sync ignores orphaned classes when running for a specific user
     */
    public function test_nosynchappenswhenclassassociatedtodeletemoodlecourseforspecificuserid() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        enrol_try_internal_enrol(2, 101, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');
        $this->create_grade_item('', 100);

        // Orphan the class.
        $DB->delete_records('course');

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_students(0);

        // Re-associate.
        $dataset = $this->createCsvDataSet(array(
            'course' => \elispm::file('tests/fixtures/mdlcoursenonsite.csv')
        ));
        $this->loadDataSet($dataset);

        // Reset lastrun time.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_students(1);
    }

    /**
     * Validate that the sync has fix for extra slashes in idnumbers
     */
    public function test_methodsupportsgradeitemidnumberswithslashes() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');

        // Set up grade item and completion item.
        $gradeitem = $this->create_grade_item("\'withslashes\'");
        $this->create_grade_grade($gradeitem->id, 100, 75);
        $completionid = $this->create_course_completion("\'withslashes\'");

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_student_grades(1);
        $this->assert_student_grade_exists(100, 103, $completionid, 75);
    }

    /**
     * Validate that the sync has fix for extra slashes in idnumbers when running for a specific user
     */
    public function test_methodsupportsgradeitemidnumberswithslashesforspecificuserid() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        enrol_try_internal_enrol(2, 101, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');

        // Set up grade item and completion item.
        $gradeitem = $this->create_grade_item("\'withslashes\'");
        $this->create_grade_grade($gradeitem->id, 100, 75);
        $this->create_grade_grade($gradeitem->id, 101, 78);
        $completionid = $this->create_course_completion("\'withslashes\'");

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_student_grades(1);
        $this->assert_student_grade_exists(100, 103, $completionid, 75);
    }

    /**
     * Validate that the sync depends on Moodle enrolments
     */
    public function test_nosynchappenswhennousersenrolled() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        $this->create_grade_item('', 100);

        // Call and validate with no enrolments.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_students(0);

        // Set up enrolment.
        enrol_try_internal_enrol(2, 100, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');

        // Reset lastrun time.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Call and validate when enrolment exists.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_students(1);
    }

    /**
     * Validate that the sync depends on Moodle enrolments when running for a specific user
     */
    public function test_nosynchappenswhennousersenrolledforspecificuserid() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        $this->create_grade_item('', 100);

        // Call and validate with no enrolments.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_students(0);

        // Set up enrolments.
        enrol_try_internal_enrol(2, 100, 1);
        enrol_try_internal_enrol(2, 101, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');

        // Reset lastrun time.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Call and validate when enrolment exists.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_students(1);
    }

    /**
     * Validate that the sync ignores courses with no max grade
     */
    public function test_nosynchappenswhencoursehasnomaxgrade() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');

        // Create course grade item with no max grade.
        $gradeitem = $this->create_grade_item('', 0);

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_students(0);

        // Set valid max grade.
        $gradeitem->grademax = 100;
        $gradeitem->update();

        // Reset lastrun time.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_students(1);
    }

    /**
     * Validate that the sync ignores courses with no max grade when running for a specific user
     */
    public function test_nosynchappenswhencoursehasnomaxgradeforspecificuserid() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        enrol_try_internal_enrol(2, 101, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');
        $gradeitem = $this->create_grade_item('', 0);

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_students(0);

        // Set valid max grade.
        $gradeitem->grademax = 100;
        $gradeitem->update();

        // Reset lastrun time.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_students(1);
    }

    /**
     * Validate that the sync prevents grades from syncing into two classes
     */
    public function test_nosynchappenswhencourselinkedtotwoclasses() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');
        $this->create_grade_item('', 100);

        // Create second association.
        $classmoodlecourse = new \classmoodlecourse(['classid' => 101, 'moodlecourseid' => 2]);
        $classmoodlecourse->save();

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_students(0);

        // Reset lastrun time.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Remove second association.
        $DB->delete_records(\classmoodlecourse::TABLE, ['classid' => 101, 'moodlecourseid' => 2]);

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_students(1);
    }

    /**
     * Validate that the sync prevents grades from syncing into two classes when running for a specific user
     */
    public function test_nosynchappenswhencourselinkedtotwoclassesforspecificuserid() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        enrol_try_internal_enrol(2, 101, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');
        $this->create_grade_item('', 100);

        // Create second association.
        $classmoodlecourse = new \classmoodlecourse(['classid' => 101, 'moodlecourseid' => 2]);
        $classmoodlecourse->save();

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_students(0);

        // Remove second association.
        $DB->delete_records(\classmoodlecourse::TABLE, ['classid' => 101, 'moodlecourseid' => 2]);

        // Reset lastrun time.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_students(1);
    }

    /**
     * Validate that recordsets iterate correctly and skip non-matching records when dealing with multiple users
     * NOTE: it doesn't really make sense to have a version of this test case for a single user
     */
    public function test_methodskipsnonmatchingrecords() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        enrol_try_internal_enrol(2, 101, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');

        // Set up grade items and PM completion info.
        $completionid = $this->create_course_completion();
        $gradeitem = $this->create_grade_item();
        $this->create_grade_grade($gradeitem->id, 100, 75, 100, 2);
        $this->create_grade_grade($gradeitem->id, 101, 75, 100, 2);

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_students(2);
        $this->assert_student_exists(100, 103);
        $this->assert_student_exists(100, 104);

        $this->assert_num_student_grades(2);
        $this->assert_student_grade_exists(100, 103, $completionid);
        $this->assert_student_grade_exists(100, 104, $completionid);
    }

    /**
     * Validate that enrolments are successfully created
     */
    public function test_methodcreatesenrolment() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');
        $this->create_grade_item('', 100);

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_students(1);

        // Validate end time since no other unit test does this for creates.
        $this->assert_student_exists(100, 103);
        $enrolment = $DB->get_record(\student::TABLE, ['classid' => 100, 'userid' => 103]);
        $this->assertEquals(0, $enrolment->endtime);
    }

    /**
     * Validate that enrolments are successfully created when running for a specific user
     */
    public function test_methodcreatesenrolmentforspecificuserid() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        enrol_try_internal_enrol(2, 101, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');
        $this->create_grade_item('', 100);

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_students(1);

        // Validate end time since no other unit test does this for creates.
        $this->assert_student_exists(100, 103);
        $enrolment = $DB->get_record(\student::TABLE, array('classid' => 100, 'userid' => 103));
        $this->assertEquals(0, $enrolment->endtime);
    }

    /**
     * Validate that enrolments are successfully updated
     */
    public function test_methodupdatesenrolment() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');

        // Set up grade.
        $gradeitem = $this->create_grade_item('');
        $gradeitem->update();
        $this->create_grade_grade($gradeitem->id, 100, 75);

        // Set up PM enrolments.
        $student = new \student(['userid' => 103, 'classid' => 100, 'grade' => 25]);
        $student->save();

        // Validate setup.
        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, 25);

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_students(1);
        $count = $DB->count_records(\student::TABLE, ['grade' => 75]);
        $this->assertEquals(1, $count);
        $this->assert_student_exists(100, 103, 75);

        // Validate end time since no other unit test does this for updates.
        $enrolment = $DB->get_record(\student::TABLE, ['classid' => 100, 'userid' => 103]);
        $this->assertEquals(0, $enrolment->endtime);
    }

    /**
     * Validate that enrolments are successfully updated for a specific user
     */
    public function test_methodupdatesenrolmentforspecificuserid() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        enrol_try_internal_enrol(2, 101, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');

        // Set up grade.
        $gradeitem = $this->create_grade_item('');
        $this->create_grade_grade($gradeitem->id, 100, 75);
        $this->create_grade_grade($gradeitem->id, 101, 75);

        // Set up PM enrolments.
        $student = new \student(['userid' => 103, 'classid' => 100, 'grade' => 25]);
        $student->save();
        $student = new \student(['userid' => 104, 'classid' => 100, 'grade' => 25]);
        $student->save();

        // Validate setup.
        $this->assert_num_students(2);
        $this->assert_student_exists(100, 103, 25);

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_students(2);
        $count = $DB->count_records(\student::TABLE, ['grade' => 75]);
        $this->assertEquals(1, $count);
        $this->assert_student_exists(100, 103, 75);

        // Validate end time since no other unit test does this for updates.
        $enrolment = $DB->get_record(\student::TABLE, ['classid' => 100, 'userid' => 103]);
        $this->assertEquals(0, $enrolment->endtime);
    }

    /**
     * Validate that PM enrolment end times are set from moodle
     */
    public function test_methodsetsenrolmenttimefrommoodleenrolment() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1, 999999);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');
        $this->create_grade_item('', 100);

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_students(1);
        $exists = $DB->record_exists(\student::TABLE, ['enrolmenttime' => 999999]);
        $this->assertTrue($exists);
    }

    /**
     * Validate that PM enrolment end times are set from moodle when run for a specific user
     */
    public function test_methodsetsenrolmenttimefrommoodleenrolmentforspecificuserid() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1, 999999);
        enrol_try_internal_enrol(2, 101, 1, 999999);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');
        $this->create_grade_item('', 100);

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_students(1);
        $exists = $DB->record_exists(\student::TABLE, ['enrolmenttime' => 999999]);
        $this->assertTrue($exists);
    }

    /**
     * Validate default for PM enrolment end time
     */
    public function test_methodsetsdefaultenrolmenttime() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');
        $this->create_grade_item('', 100);

        // Set start time to an empty value.
        $DB->execute("UPDATE {user_enrolments} SET timestart = 0");

        // Run and collect timing info.
        $mintime = time();
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $maxtime = time();

        // Validate.
        $this->assert_num_students(1);
        $enrolment = $DB->get_record(\student::TABLE, ['userid' => 103]);
        $this->assertNotEmpty($enrolment);
        $this->assertGreaterThanOrEqual($mintime, $enrolment->enrolmenttime);
        $this->assertLessThanOrEqual($maxtime, $enrolment->enrolmenttime);
    }

    /**
     * Validate default for PM enrolment end time when run for a specific user
     */
    public function test_methodsetsdefaultenrolmenttimeforspecificuserid() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        enrol_try_internal_enrol(2, 101, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');
        $this->create_grade_item('', 100);

        // Set start time to an empty value.
        $DB->execute("UPDATE {user_enrolments} SET timestart = 0");

        // Run and collect timing info.
        $mintime = time();
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $maxtime = time();

        // Validate.
        $this->assert_num_students(1);
        $enrolment = $DB->get_record(\student::TABLE, ['userid' => 103]);
        $this->assertNotEmpty($enrolment);
        $this->assertGreaterThanOrEqual($mintime, $enrolment->enrolmenttime);
        $this->assertLessThanOrEqual($maxtime, $enrolment->enrolmenttime);
    }

    /**
     * Data provider for scaling grades
     * @return array An array containing current grade, max grade, and percentage in each position
     */
    public function dataprovider_enrolmentgradescale() {
        return [
                [100, 100, 100],
                [50, 100, 50],
                [1, 5, 20],
                [1, 2, 50],
        ];
    }

    /**
     * Validate that enrolment grade scaling works correctly
     *
     * @param int $finalgrade The user's grade in Moodle
     * @param int $grademax The maximum grade in Moodle
     * @param int $pmgrade The expected PM grade (percentage)
     * @dataProvider dataprovider_enrolmentgradescale
     */
    public function test_methodscalesmoodlecoursegrade($finalgrade, $grademax, $pmgrade) {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');
        $gradeitem = $this->create_grade_item('', $grademax);

        // Create grade grade.
        $gradegrade = new \grade_grade([
            'itemid' => $gradeitem->id,
            'userid' => 100,
            'rawgrademax' => $grademax,
            'finalgrade' => $finalgrade
        ]);
        $gradegrade->insert();

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, $pmgrade);
    }

    /**
     * Validate that enrolment grade scaling works correctly when running for a specific user
     *
     * @param int $finalgrade The user's grade in Moodle
     * @param int $grademax The maximum grade in Moodle
     * @param int $pmgrade The expected PM grade (percentage)
     * @dataProvider dataprovider_enrolmentgradescale
     */
    public function test_methodscalesmoodlecoursegradeforspecificuserid($finalgrade, $grademax, $pmgrade) {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        enrol_try_internal_enrol(2, 101, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');
        $gradeitem = $this->create_grade_item('', $grademax);

        // Create grade grades.
        $gradegrade = new \grade_grade([
            'itemid' => $gradeitem->id,
            'userid' => 100,
            'rawgrademax' => $grademax,
            'finalgrade' => $finalgrade
        ]);
        $gradegrade->insert();
        $gradegrade = new \grade_grade([
            'itemid' => $gradeitem->id,
            'userid' => 101,
            'rawgrademax' => $grademax,
            'finalgrade' => $finalgrade
        ]);
        $gradegrade->insert();

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, $pmgrade);
    }

    /**
     * Validate that when no learning objectives are present, enrolments can be marked as passed when they have a sufficient grade.
     */
    public function test_methodmarkenrolmentpassedwhengradesufficientandnorequiredlearningobjectives() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');

        // Set up PM course completion criteria.
        $pmcourse = new \course(['id' => 100, 'completion_grade' => 50]);
        $pmcourse->save();

        // Set up course grade item info in Moodle.
        $gradeitem = $this->create_grade_item('', 100);

        // Set up PM class enrolment with sufficient grade.
        $gradegrade = new \grade_grade(['itemid' => $gradeitem->id, 'userid' => 100, 'finalgrade' => 100]);
        $gradegrade->insert();

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, null, STUSTATUS_PASSED);
    }

    /**
     * Validate that when no learning objectives are present, enrolments can be marked as passed when they have a sufficient
     * grade when run for a specific user.
     */
    public function test_methodmarkenrolmentpassedwhengradesufficientandnorequiredlearningobjectivesforspecificuserid() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        enrol_try_internal_enrol(2, 101, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');

        // Set up PM course completion criteria.
        $pmcourse = new \course(['id' => 100, 'completion_grade' => 50]);
        $pmcourse->save();

        // Set up course grade item info in Moodle.
        $gradeitem = $this->create_grade_item('', 100);

        // Set up PM class enrolment with sufficient grade.
        $gradegrade = new \grade_grade(['itemid' => $gradeitem->id, 'userid' => 100, 'finalgrade' => 100]);
        $gradegrade->insert();
        $gradegrade = new \grade_grade(['itemid' => $gradeitem->id, 'userid' => 101, 'finalgrade' => 100]);
        $gradegrade->insert();

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, null, STUSTATUS_PASSED);
    }

    /**
     * Validate that enrolment completion times are properly maintained.
     */
    public function test_methodsetscompletiontimefrommoodlegradeitem() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');

        // Set up required PM course grade.
        $pmcourse = new \course(['id' => 100, 'completion_grade' => 50]);
        $pmcourse->save();

        // Set up Moodle course grade item info.
        $gradeitem = $this->create_grade_item('');

        // Assign the user a grade in Moodle.
        $gradegradeparams = [
            'itemid' => $gradeitem->id,
            'userid' => 100,
            'finalgrade' => 100,
        ];
        $coursegradegrade = new \grade_grade($gradegradeparams);
        $coursegradegrade->insert();

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_students(1);
        $timemod = $DB->get_field('grade_grades', 'timemodified', ['itemid' => $gradeitem->id, 'userid' => 100]);
        $this->assert_student_exists(100, 103, null, null, $timemod);
    }

    /**
     * Validate that enrolment completion times are properly maintained when run for a specific user.
     */
    public function test_methodsetscompletiontimefrommoodlegradeitemforspecificuserid() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        enrol_try_internal_enrol(2, 101, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');

        // Set up required PM course grade.
        $pmcourse = new \course(['id' => 100, 'completion_grade' => 50]);
        $pmcourse->save();

        // Set up Moodle course grade item info.
        $gradeitem = $this->create_grade_item('');

        // Assign the user a grade in Moodle.
        $coursegradegrade = new \grade_grade(['itemid' => $gradeitem->id, 'userid' => 100, 'finalgrade' => 100]);
        $coursegradegrade->insert('system');
        $coursegradegrade = new \grade_grade(['itemid' => $gradeitem->id, 'userid' => 101, 'finalgrade' => 100]);
        $coursegradegrade->insert('system');

        $sql = "UPDATE {grade_grades}
                   SET aggregationstatus = 'system'
                 WHERE id > 0";
        $DB->execute($sql, null);

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $timemod = $DB->get_field('grade_grades', 'timemodified', ['itemid' => $gradeitem->id, 'userid' => 100]);
        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, null, null, $timemod);
    }

    /**
     * Validate that default enrolment completion times are used
     */
    public function test_methodsetsdefaultcompletiontime() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');

        // Set up PM course completion grade.
        $pmcourse = new \course(['id' => 100, 'completion_grade' => 50]);
        $pmcourse->save();

        // Set up Moodle course grade item.
        $gradeitem = $this->create_grade_item('');

        // Assign the user a Moodle coruse grade.
        $gradegrade = new \grade_grade(['itemid' => $gradeitem->id, 'userid' => 100, 'finalgrade' => 40]);
        $gradegrade->insert();

        // Run and validate when no learning objectives exist.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, null, null, 0);

        // Reset state and create learning objective.
        $DB->delete_records(\student::TABLE);
        $this->create_course_completion();

        // Reset lastrun time.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Run and validation when a learning objective exists.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, null, null, 0);
    }

    /**
     * Validate that default enrolment completion times are used when run for a
     * specific user
     */
    public function test_methodsetsdefaultcompletiontimeforspecificuserid() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        enrol_try_internal_enrol(2, 101, 1);
        $this->create_grade_item('', 100);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');

        // Set up PM course completion grade.
        $pmcourse = new \course(['id' => 100, 'completion_grade' => 50]);
        $pmcourse->save();

        // Set up Moodle course grade item.
        $gradeitem = $this->create_grade_item('', 0);

        // Assign the user a Moodle coruse grade.
        $gradegrade = new \grade_grade(['itemid' => $gradeitem->id, 'userid' => 100, 'finalgrade' => 40]);
        $gradegrade->insert();
        $gradegrade = new \grade_grade(['itemid' => $gradeitem->id, 'userid' => 101, 'finalgrade' => 40]);
        $gradegrade->insert();

        // Run and validate when no learning objectives exist.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, null, null, 0);

        // Reset state and create learning objective.
        $DB->delete_records(\student::TABLE);
        $this->create_course_completion();

        // Reset lastrun time.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Run and validation when a learning objective exists.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, null, null, 0);
    }

    /**
     * Validate that enrolments are only updated when a key field changes
     */
    public function test_methodonlyupdatesenrolmentifkeyfieldchanged() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');

        // Set up course grade item.
        $gradeitem = $this->create_grade_item('', 100);

        // Assign a course grade.
        $gradegrade = new \grade_grade(['itemid' => $gradeitem->id, 'userid' => 100, 'finalgrade' => 40]);
        $gradegrade->insert();
        $DB->execute("UPDATE {grade_grades} SET information = 'updated', timemodified = 12346");

        // Set a completion grade.
        $pmcourse = new \course(['id' => 100, 'completion_grade' => 50, 'credits' => 1]);
        $pmcourse->save();

        // Validate initial state.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, 40, STUSTATUS_NOTCOMPLETE, 0, 0);

        // Only a bogus db field is updated.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');
        $DB->execute("UPDATE {grade_grades} SET information = 'updated', timemodified = 12346");

        // Verify nothing changed.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, 40, STUSTATUS_NOTCOMPLETE, 0, 0);

        // Update grade.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');
        $DB->execute("UPDATE {grade_grades} SET finalgrade = 45, timemodified = 12346");

        // Verify grade updated.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, 45, STUSTATUS_NOTCOMPLETE, 0, 0);

        // Update completestatusid.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');
        $DB->execute("UPDATE {".\course::TABLE."} SET completion_grade = 45");

        // Verify completion status updated.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, 45, STUSTATUS_PASSED, 12346, 1);

        // Update completetime.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');
        $DB->execute("UPDATE {grade_grades} SET timemodified = 12347");
        // Unlock the student record.
        static::unlock_student_record(100, 103);

        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, 45, STUSTATUS_PASSED, 12347, 1);

        // Update credits.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');
        $DB->execute("UPDATE {".\course::TABLE."} SET credits = 2");
        // Unlock the student record.
        static::unlock_student_record(100, 103);

        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, 45, STUSTATUS_PASSED, 12347, 2);
    }

    /**
     * Validate that enrolments are only updated when a key field changes when
     * run for a specific user
     */
    public function test_methodonlyupdatesenrolmentifkeyfieldchangedforspecificuserid() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        enrol_try_internal_enrol(2, 101, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');

        // Set up course grade item.
        $gradeitem = $this->create_grade_item('');

        // Assign a course grade.
        $gradegrade = new \grade_grade(['itemid' => $gradeitem->id, 'userid' => 100, 'finalgrade' => 40]);
        $gradegrade->insert();
        $gradegrade = new \grade_grade(['itemid' => $gradeitem->id, 'userid' => 101, 'finalgrade' => 40]);
        $gradegrade->insert();
        $DB->execute("UPDATE {grade_grades} SET information = 'updated', timemodified = 12346");

        // Set a completion grade.
        $pmcourse = new \course(['id' => 100, 'completion_grade' => 50, 'credits' => 1]);
        $pmcourse->save();

        // Validate initial state.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, 40, STUSTATUS_NOTCOMPLETE, 0, 0);

        // Only a bogus db field is updated.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');
        $DB->execute("UPDATE {grade_grades} SET information = 'updated', timemodified = 12346");

        // Verify nothing changed.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, 40, STUSTATUS_NOTCOMPLETE, 0, 0);

        // Update grade.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');
        $DB->execute("UPDATE {grade_grades} SET finalgrade = 45, timemodified = 12346");

        // Verify grade updated.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, 45, STUSTATUS_NOTCOMPLETE, 0, 0);

        // Update completestatusid.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');
        $DB->execute("UPDATE {".\course::TABLE."} SET completion_grade = 45");

        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, 45, STUSTATUS_PASSED, 12346, 1);

        // Update completetime.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');
        $DB->execute("UPDATE {grade_grades} SET timemodified = 12347");
        // Unlock the student record.
        static::unlock_student_record(100, 103);

        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, 45, STUSTATUS_PASSED, 12347, 1);

        // Update credits.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');
        $DB->execute("UPDATE {".\course::TABLE."} SET credits = 2");
        // Unlock the student record.
        static::unlock_student_record(100, 103);

        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, 45, STUSTATUS_PASSED, 12347, 2);
    }

    /**
     * Validate that the method respects the locked status
     */
    public function test_methodonlyupdatesunlockedenrolments() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');

        // Set required PM course grade.
        $pmcourse = new \course(['id' => 100, 'completion_grade' => 50]);
        $pmcourse->save();

        // Set up course grade item.
        $gradeitem = $this->create_grade_item('', 100);

        // Assign a student grade.
        $gradegrade = new \grade_grade(['itemid' => $gradeitem->id, 'userid' => 100, 'finalgrade' => 100]);
        $gradegrade->insert();

        // Enrol the student.
        $student = new \student([
            'userid' => 103,
            'classid' => 100,
            'grade' => 0,
            'completestatusid' => STUSTATUS_NOTCOMPLETE,
            'locked' => 1,
        ]);
        $student->save();

        // Call and validate that locked record is not changed.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_student_exists(100, 103, 0, STUSTATUS_NOTCOMPLETE, null, null, 1);
        $DB->execute("UPDATE {".\student::TABLE."} SET locked = 0");

        // Call and validate that unlocked record is changed.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();

        // Validate count.
        $count = $DB->count_records(\student::TABLE, array('completestatusid' => STUSTATUS_PASSED));
        $this->assertEquals(1, $count);

        $this->assert_student_exists(100, 103, 100, STUSTATUS_PASSED, null, null, 1);
    }

    /**
     * Validate that the method respects the locked status when run for a
     * specific user
     */
    public function test_methodonlyupdatesunlockedenrolmentsforspecificuserid() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        enrol_try_internal_enrol(2, 101, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');

        // Set required PM course grade.
        $pmcourse = new \course(['id' => 100, 'completion_grade' => 50]);
        $pmcourse->save();

        // Set up course grade item.
        $gradeitem = $this->create_grade_item('', 100);

        // Assign student grades.
        $gradegrade = new \grade_grade(['itemid' => $gradeitem->id, 'userid' => 100, 'finalgrade' => 100]);
        $gradegrade->insert();
        $gradegrade = new \grade_grade(['itemid' => $gradeitem->id, 'userid' => 101, 'finalgrade' => 100]);
        $gradegrade->insert();

        // Enrol the student.
        $student = new \student;
        $student->userid = 103;
        $student->classid = 100;
        $student->grade = 0;
        $student->completestatusid = STUSTATUS_NOTCOMPLETE;
        $student->locked = 1;
        $student->save();

        // Call and validate that locked record is not changed.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_student_exists(100, 103, 0, STUSTATUS_NOTCOMPLETE, null, null, 1);
        $DB->execute("UPDATE {".\student::TABLE."} SET locked = 0");

        // Reset lastrun time.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Call and validate that unlocked record is changed.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);

        // Validate count.
        $count = $DB->count_records(\student::TABLE, ['completestatusid' => STUSTATUS_PASSED]);
        $this->assertEquals(1, $count);

        $this->assert_student_exists(100, 103, 100, STUSTATUS_PASSED, null, null, 1);
    }

    /**
     * Data provider for Learning Objective grade scaling.
     * @return array An array where each element contains the grade, max grade, and expected PM grade
     */
    public function dataprovider_logradescale() {
        return [
                [100, 100, 100],
                [50, 100, 50],
                [1, 5, 20],
                [1, 2, 50],
        ];
    }

    /**
     * Validate that LO grades are scaled correctly from Moodle grade item grades
     *
     * @param int $finalgrade The assigned grade
     * @param int $grademax The maximum grade
     * @param int $pmgrade The expected PM grade
     * @dataProvider dataprovider_logradescale
     */
    public function test_methodscalesmoodlegradeitemgrade($finalgrade, $grademax, $pmgrade) {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);

        // Set up the LO and related Moodle structure.
        $completionid = $this->create_course_completion();
        $gradeitem = $this->create_grade_item('manualitem', $grademax);
        $this->create_grade_grade($gradeitem->id, 100, $finalgrade, $grademax);

        // Run and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_student_grades(1);
        $this->assert_student_grade_exists(100, 103, $completionid, $pmgrade);
    }

    /**
     * Validate that LO grades are scaled correctly from Moodle grade item grades
     * when run for a specific user
     *
     * @param int $finalgrade The assigned grade
     * @param int $grademax The maximum grade
     * @param int $pmgrade The expected PM grade
     * @dataProvider dataprovider_logradescale
     */
    public function test_methodscalesmoodlegradeitemgradeforspecificuserid($finalgrade, $grademax, $pmgrade) {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        enrol_try_internal_enrol(2, 101, 1);

        // Set up the LO and related Moodle structure.
        $completionid = $this->create_course_completion();
        $gradeitem = $this->create_grade_item('manualitem', $grademax);
        $this->create_grade_grade($gradeitem->id, 100, $finalgrade, $grademax);
        $this->create_grade_grade($gradeitem->id, 101, $finalgrade, $grademax);

        // Run and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_student_grades(1);
        $this->assert_student_grade_exists(100, 103, $completionid, $pmgrade);
    }

    /**
     * Validate that LO grades are graded from Moodle grade item grades during create
     */
    public function test_methodcreateslearningobjectivegrade() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);

        // Set up LO and Moodle grade.
        $gradeitem = $this->create_grade_item();
        $this->create_grade_grade($gradeitem->id, 100, 75);
        $completionid = $this->create_course_completion();

        // Run.
        $mintime = time();
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $maxtime = time();

        // Validate.
        $this->assert_num_student_grades(1);
        $this->assert_student_grade_exists(100, 103, $completionid);

        // Validate time modified since we don't validate it anywhere else for creates.
        $lograde = $DB->get_record(\student_grade::TABLE, ['classid' => 100, 'userid' => 103, 'completionid' => $completionid]);
        $this->assertGreaterThanOrEqual($mintime, $lograde->timemodified);
        $this->assertLessThanOrEqual($maxtime, $lograde->timemodified);
    }

    /**
     * Validate that LO grades are graded from Moodle grade item grades during create
     * when run for a specific user
     */
    public function test_methodcreateslearningobjectivegradeforspecificuserid() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        enrol_try_internal_enrol(2, 101, 1);

        // Set up LO and Moodle grade.
        $gradeitem = $this->create_grade_item();
        $this->create_grade_grade($gradeitem->id, 100, 75);
        $this->create_grade_grade($gradeitem->id, 101, 75);
        $completionid = $this->create_course_completion();

        // Run.
        $mintime = time();
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $maxtime = time();

        // Validate.
        $this->assert_num_student_grades(1);
        $this->assert_student_grade_exists(100, 103, $completionid);

        // Validate time modified since we don't validate it anywhere else for creates.
        $lograde = $DB->get_record(\student_grade::TABLE, ['classid' => 100, 'userid' => 103, 'completionid' => $completionid]);
        $this->assertGreaterThanOrEqual($mintime, $lograde->timemodified);
        $this->assertLessThanOrEqual($maxtime, $lograde->timemodified);
    }

    /**
     * Validate that LO grades are graded from Moodle grade item grades during update
     */
    public function test_methodupdateslearningobjectivegrade() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);

        // Set up LO and Moodle grade.
        $gradeitem = $this->create_grade_item();
        $this->create_grade_grade($gradeitem->id, 100, 75, 100, 1);
        $completionid = $this->create_course_completion();

        // Create LO grade.
        $studentgrade = new \student_grade([
            'userid' => 103,
            'classid' => 100,
            'completionid' => $completionid,
            'grade' => 75,
            'locked' => 0,
            'timegraded' => 1,
        ]);
        $studentgrade->save();

        // Validate setup.
        $this->assert_num_student_grades(1);
        $this->assert_student_grade_exists(100, 103, $completionid, 75);

        // Update Moodle grade.
        $DB->execute("UPDATE {grade_grades} SET finalgrade = 80, timemodified = 2");

        // Run and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_student_grades(1);
        $this->assert_student_grade_exists(100, 103, $completionid, 80);
    }

    /**
     * Validate that LO grades are graded from Moodle grade item grades during update
     * when run for a specific user
     */
    public function test_methodupdateslearningobjectivegradeforspecificuserid() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        enrol_try_internal_enrol(2, 101, 1);

        // Set up LO and Moodle grade.
        $gradeitem = $this->create_grade_item();
        $this->create_grade_grade($gradeitem->id, 100, 75, 100, 1);
        $this->create_grade_grade($gradeitem->id, 101, 75, 100, 1);
        $completionid = $this->create_course_completion();

        // Create LO grade.
        $studentgrade = new \student_grade([
            'userid' => 103,
            'classid' => 100,
            'completionid' => $completionid,
            'grade' => 75,
            'locked' => 0,
            'timegraded' => 1,
        ]);
        $studentgrade->save();
        $studentgrade = new \student_grade([
            'userid' => 104,
            'classid' => 100,
            'completionid' => $completionid,
            'grade' => 75,
            'locked' => 0,
            'timegraded' => 1
        ]);
        $studentgrade->save();

        // Validate setup.
        $this->assert_num_student_grades(2);
        $this->assert_student_grade_exists(100, 103, $completionid, 75);

        // Update Moodle grade.
        $DB->execute("UPDATE {grade_grades} SET finalgrade = 80, timemodified = 2");

        // Run and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_student_grades(2);
        $count = $DB->count_records(\student::TABLE, ['grade' => 80]);
        $this->assertEquals(1, $count);
        $this->assert_student_grade_exists(100, 103, $completionid, 80);
    }

    /**
     * Validate that LO grades are only updated if some key field changes
     */
    public function test_methodonlyupdateslearningobjectivegradeifkeyfieldchanged() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);

        // Set up LO and Moodle grade.
        $gradeitem = $this->create_grade_item();
        $this->create_grade_grade($gradeitem->id, 100, 40, 100, 12346);
        $completionid = $this->create_course_completion('manualitem', 50);

        // Validate setup.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_student_grades(1);
        $this->assert_student_grade_exists(100, 103, $completionid, 40, 0, 12346);

        // Only a bogus db field is updated.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');
        $DB->execute("UPDATE {grade_grades} SET information = 'updated', timemodified = 12346");

        // Verify nothing changed.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_student_grades(1);
        $this->assert_student_grade_exists(100, 103, $completionid, 40, 0, 12346);

        // Update grade.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');
        $DB->execute("UPDATE {grade_grades} SET finalgrade = 45, timemodified = 12347");

        // Verify grade updated.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_student_grades(1);
        $this->assert_student_grade_exists(100, 103, $completionid, 45, 0, 12347);

        // Update timegraded.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');
        $DB->execute("UPDATE {grade_grades} SET timemodified = 12348");

        // Verify time is updated.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_student_grades(1);
        $this->assert_student_grade_exists(100, 103, $completionid, 45, 0, 12348);

        // Update locked.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');
        $DB->execute("UPDATE {".\coursecompletion::TABLE."} SET completion_grade = 45");
        $DB->execute("UPDATE {grade_grades} SET timemodified = 12349");

        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_student_grades(1);
        $this->assert_student_grade_exists(100, 103, $completionid, 45, 1, 12349);
    }

    /**
     * Validate that LO grades are only updated if some key field changes when
     * run for a specific user
     */
    public function test_methodonlyupdateslearningobjectivegradeifkeyfieldchangedforspecificuserid() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        enrol_try_internal_enrol(2, 101, 1);

        // Set up LO and Moodle grade.
        $gradeitem = $this->create_grade_item();
        $this->create_grade_grade($gradeitem->id, 100, 40, 100, 12346);
        $this->create_grade_grade($gradeitem->id, 101, 40, 100, 12346);
        $completionid = $this->create_course_completion('manualitem', 50);

        // Validate setup.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_student_grades(1);
        $this->assert_student_grade_exists(100, 103, $completionid, 40, 0, 12346);

        // Only a bogus db field is updated.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');
        $DB->execute("UPDATE {grade_grades} SET information = 'updated', timemodified = 12346");

        // Verify nothing changed.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_student_grades(1);
        $this->assert_student_grade_exists(100, 103, $completionid, 40, 0, 12346);

        // Update grade.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');
        $DB->execute("UPDATE {grade_grades} SET finalgrade = 45, timemodified = 12347");

        // Verify grade updated.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_student_grades(1);
        $this->assert_student_grade_exists(100, 103, $completionid, 45, 0, 12347);

        // Update timegraded.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');
        $DB->execute("UPDATE {grade_grades} SET timemodified = 12348");

        // Verify time is updated.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_student_grades(1);
        $this->assert_student_grade_exists(100, 103, $completionid, 45, 0, 12348);

        // Update locked.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');
        $DB->execute("UPDATE {".\coursecompletion::TABLE."} SET completion_grade = 45");
        $DB->execute("UPDATE {grade_grades} SET timemodified = 12349");

        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_student_grades(1);
        $this->assert_student_grade_exists(100, 103, $completionid, 45, 1, 12349);
    }

    /**
     * Validate that updating LO grades respects the locked flag
     */
    public function test_methodonlyupdatesunlockedlearningobjectivegrades() {
        global $DB;

        // Set up enrolment.
        $this->load_csv_data();
        $this->make_course_enrollable();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Create LO and Moodle grade.
        $gradeitem = $this->create_grade_item();
        $this->create_grade_grade($gradeitem->id, 100, 75, 100, 12347);
        $completionid = $this->create_course_completion('manualitem', 50);

        // Assign a PM grade.
        $studentgrade = new \student_grade([
            'userid' => 103,
            'classid' => 100,
            'completionid' => $completionid,
            'grade' => 50,
            'locked' => 1,
            'timegraded' => 12346,
        ]);
        $studentgrade->save();

        // Validate setup with element locked.
        enrol_try_internal_enrol(2, 100, 1);
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_student_grades(1);
        $this->assert_student_grade_exists(100, 103, $completionid, 50, 1, 12346);

        // Validate update with element unlocked.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');
        $DB->execute("UPDATE {".\student_grade::TABLE."} SET locked = 0");

        // Run and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_student_grades(1);
        $this->assert_student_grade_exists(100, 103, $completionid, 75, 1, 12347);
    }

    /**
     * Validate that updating LO grades respects the locked flag when run for a
     * specific user
     */
    public function test_methodonlyupdatesunlockedlearningobjectivegradesforspecificuserid() {
        global $DB;

        // Set up enrolment.
        $this->load_csv_data();
        $this->make_course_enrollable();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Create LO and Moodle grade.
        $gradeitem = $this->create_grade_item();
        $this->create_grade_grade($gradeitem->id, 100, 75, 100, 12347);
        $this->create_grade_grade($gradeitem->id, 101, 75, 100, 12347);
        $completionid = $this->create_course_completion('manualitem', 50);

        // Assign a PM grade.
        $studentgrade = new \student_grade([
            'userid' => 103,
            'classid' => 100,
            'completionid' => $completionid,
            'grade' => 50,
            'locked' => 1,
            'timegraded' => 12346,
        ]);
        $studentgrade->save();
        $studentgrade = new \student_grade([
            'userid' => 104,
            'classid' => 100,
            'completionid' => $completionid,
            'grade' => 50,
            'locked' => 1,
            'timegraded' => 12346,
        ]);
        $studentgrade->save();

        // Validate setup with element locked.
        enrol_try_internal_enrol(2, 100, 1);
        enrol_try_internal_enrol(2, 101, 1);
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_student_grades(2);
        $this->assert_student_grade_exists(100, 103, $completionid, 50, 1, 12346);
        $this->assert_student_grade_exists(100, 104, $completionid, 50, 1, 12346);

        // Validate update with element unlocked.
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');
        $DB->execute("UPDATE {".\student_grade::TABLE."} SET locked = 0");

        // Run and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_student_grades(2);
        $this->assert_student_grade_exists(100, 103, $completionid, 75, 1, 12347);
        $this->assert_student_grade_exists(100, 104, $completionid, 50, 0, 12346);
    }

    /**
     * Validate that LO grades are locked when they are created and grade is sufficient.
     */
    public function test_methodlockslearningobjectivegradesduringcreate() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);

        // Create LO and Moodle grade.
        $gradeitem = $this->create_grade_item();
        $this->create_grade_grade($gradeitem->id, 100, 75, 100, 12346);
        $completionid = $this->create_course_completion('manualitem', 50);

        // Run and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_student_grades(1);
        $this->assert_student_grade_exists(100, 103, $completionid, 75, 1, 12346);
    }

    /**
     * Validate that LO grades are locked when they are created and grade
     * is sufficient when run for a specific user
     */
    public function test_methodlockslearningobjectivegradesduringcreateforspecificuserid() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        enrol_try_internal_enrol(2, 101, 1);

        // Create LO and Moodle grade.
        $gradeitem = $this->create_grade_item();
        $this->create_grade_grade($gradeitem->id, 100, 75, 100, 12346);
        $this->create_grade_grade($gradeitem->id, 101, 75, 100, 12346);
        $completionid = $this->create_course_completion('manualitem', 50);

        // Run and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_student_grades(1);
        $this->assert_student_grade_exists(100, 103, $completionid, 75, 1, 12346);
    }

    /**
     * Validate that LO grades are locked when they are updated and grade
     * is sufficient
     */
    public function test_methodlockslearningobjectivegradesduringupdate() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);

        // Create LO and Moodle grade.
        $gradeitem = $this->create_grade_item();
        $this->create_grade_grade($gradeitem->id, 100, 75, 100, 12347);
        $completionid = $this->create_course_completion('manualitem', 50);

        // Enrol in PM class.
        $studentgrade = new \student_grade([
            'userid' => 103,
            'classid' => 100,
            'completionid' => $completionid,
            'grade' => 75,
            'locked' => 0,
            'timegraded' => 12346,
        ]);
        $studentgrade->save();

        // Validate setup.
        $this->assert_num_student_grades(1);
        $count = $DB->count_records(\student_grade::TABLE, ['locked' => 1]);
        $this->assertEquals(0, $count);
        $this->assert_student_grade_exists(100, 103, $completionid, null, 0, 12346);

        // Update Moodle info.
        $DB->execute("UPDATE {grade_grades} SET finalgrade = 80, timemodified = 12347");

        // Run and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_student_grades(1);
        $count = $DB->count_records(\student_grade::TABLE, ['locked' => 1]);
        $this->assertEquals(1, $count);
        $this->assert_student_grade_exists(100, 103, $completionid, null, 1, 12347);
    }

    /**
     * Validate that LO grades are locked when they are updated and grade
     * is sufficient when run for a specific user
     */
    public function test_methodlockslearningobjectivegradesduringupdateforspecificuserid() {
        global $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        enrol_try_internal_enrol(2, 101, 1);

        // Create LO and Moodle grade.
        $gradeitem = $this->create_grade_item();
        $this->create_grade_grade($gradeitem->id, 100, 75, 100, 12347);
        $this->create_grade_grade($gradeitem->id, 101, 75, 100, 12347);
        $completionid = $this->create_course_completion('manualitem', 50);

        // Enrol in PM class.
        $studentgrade = new \student_grade([
            'userid' => 103,
            'classid' => 100,
            'completionid' => $completionid,
            'grade' => 75,
            'locked' => 0,
            'timegraded' => 12346,
        ]);
        $studentgrade->save();
        $studentgrade = new \student_grade([
            'userid' => 104,
            'classid' => 100,
            'completionid' => $completionid,
            'grade' => 75,
            'locked' => 0,
            'timegraded' => 12346,
        ]);
        $studentgrade->save();

        // Validate setup.
        $this->assert_num_student_grades(2);
        $count = $DB->count_records(\student_grade::TABLE, ['locked' => 1]);
        $this->assertEquals(0, $count);
        $this->assert_student_grade_exists(100, 103, $completionid, null, 0, 12346);

        // Update Moodle info.
        $DB->execute("UPDATE {grade_grades} SET finalgrade = 80, timemodified = 12347");

        // Run and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades(100);
        $this->assert_num_student_grades(2);
        $count = $DB->count_records(\student_grade::TABLE, ['locked' => 1]);
        $this->assertEquals(1, $count);
        $this->assert_student_grade_exists(100, 103, $completionid, null, 1, 12347);
    }

    /**
     * Validate that even with duplicate enrolment records, the grade synchronisation still runs correctly and can synchronise
     * data for unique enrolments.
     */
    public function test_gradesyncwithduplicateclassenrolmentrecords() {
        global $DB, $CFG;

        $dataset = $this->createCsvDataSet([
            'context' => \elispm::file('tests/fixtures/gsync_context.csv'),
            'course' => \elispm::file('tests/fixtures/gsync_mdl_course.csv'),
            'grade_grades' => \elispm::file('tests/fixtures/gsync_grade_grades.csv'),
            'grade_items' => \elispm::file('tests/fixtures/gsync_grade_items.csv'),
            'role_assignments' => \elispm::file('tests/fixtures/gsync_role_assignments.csv'),
            'user' => \elispm::file('tests/fixtures/gsync_mdl_user.csv'),
            'user_enrolments' => \elispm::file('tests/fixtures/gsync_user_enrolments.csv'),
            'enrol' => \elispm::file('tests/fixtures/gsync_enrol.csv'),
            \pmclass::TABLE => \elispm::file('tests/fixtures/gsync_class.csv'),
            \student::TABLE => \elispm::file('tests/fixtures/gsync_class_enrolment.csv'),
            \classmoodlecourse::TABLE => \elispm::file('tests/fixtures/gsync_class_moodle.csv'),
            \course::TABLE => \elispm::file('tests/fixtures/gsync_course.csv'),
            \user::TABLE => \elispm::file('tests/fixtures/gsync_user.csv'),
            \usermoodle::TABLE => \elispm::file('tests/fixtures/gsync_user_moodle.csv'),
        ]);
        $this->loadDataSet($dataset);

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        // We need to reset the context cache.
        accesslib_clear_all_caches(true);

        // Make our role a "student" role.
        set_config('gradebookroles', 1);

        // Force synchronisation of grade data from Moodle to ELIS.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();

        $params = [
            'classid' => 1,
            'userid' => 120,
            'grade' => 75.00000
        ];
        $this->assertTrue($DB->record_exists(\student::TABLE, $params));

        $params['userid'] = 100;
        $params['grade'] = 77.0000;
        $params['completestatusid'] = STUSTATUS_PASSED;
        $this->assertTrue($DB->record_exists(\student::TABLE, $params));

        $params['userid'] = 130;
        $params['grade'] = 82.00000;
        $this->assertTrue($DB->record_exists(\student::TABLE, $params));

        $params['userid'] = 110;
        $params['grade'] = 88.00000;
        $this->assertTrue($DB->record_exists(\student::TABLE, $params));
    }

    /**
     * Test the grade synchronisation when there are duplicate course_module.idnumber values present.
     */
    public function test_sync_with_duplicate_course_module_idnumbers() {
        global $CFG, $DB;

        $this->load_csv_data();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12345, 'local_elisprogram');

        $olddebug = null;
        $olddebugdisplay = null;

        // Developer debugging must be enabled and displayed for this test to work.
        if ($CFG->debug < DEBUG_DEVELOPER) {
            $olddebug = $CFG->debug;
            $CFG->debug = DEBUG_DEVELOPER;
        }
        if ($CFG->debugdisplay == false) {
            $olddebugdisplay = false;
            $CFG->debugdisplay = true;
        }

        // Set up grade item and completion item.
        $gradeitem = $this->create_grade_item('duplicateidnumber');
        $this->create_grade_grade($gradeitem->id, 100, 75);
        $completionid = $this->create_course_completion('duplicateidnumber');

        // Insert a couple duplicate course_module 'idnumber' balues but for different course ID values.
        $cmobj = new \stdClass;
        $cmobj->course = 1000;
        $cmobj->module = 20;
        $cmobj->instance = 100;
        $cmobj->section = 1;
        $cmobj->idnumber = 'duplicateidnumber';
        $DB->insert_record('course_modules', $cmobj);

        $cmobj->course = 2000;
        $DB->insert_record('course_modules', $cmobj);

        // Using an output buffer here because the following function will throw a debugging error if more than one record is found.
        ob_start();
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $buffer = ob_get_contents();
        ob_end_clean();

        $this->assertEquals('', $buffer);

        // Restore old values if we modified them in this test.
        if ($olddebug != null) {
            $CFG->debug = $olddebug;
        }
        if ($olddebugdisplay != null) {
            $CFG->debugdisplay = $olddebugdisplay;
        }
    }

    /**
     * Stress test the grade synchronisation when there are many userids passed to get_syncable_users();
     */
    public function test_maximum_syncable_users() {
        // Set up environment.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_maxuserchunk', 100, 'local_elisprogram');
        set_config('incrementalgradesync_maxsyncsecs', 10000, 'local_elisprogram');

        // Set up enrolments.
        $this->load_csv_data();
        $this->make_course_enrollable();

        // Create LO and Moodle grade.
        $gradeitem = $this->create_grade_item();
        $completionid = $this->create_course_completion('manualitem', 50);
        $moodleuserids = [];
        for ($i = 1; $i < 400; ++$i) {
            $elisuser = new user(['username' => 'user'.$i, 'idnumber' => 'user'.$i, 'firstname' => 'Test', 'lastname' => 'User'.$i,
                'email' => "testuser{$i}@noreply.com", 'country' => 'CA']);
            $elisuser->save();
            $moodleuserids[] = $mu = $elisuser->get_moodleuser(false)->id;
            enrol_try_internal_enrol(2, $mu, 1);
            $rndgrade = rand(42, 99);
            $this->create_grade_grade($gradeitem->id, $mu, $rndgrade, 100, 12347);
            // Enrol in PM class.
            $studentgrade = new \student_grade([
                'userid' => $elisuser->id,
                'classid' => 100,
                'completionid' => $completionid,
                'grade' => $rndgrade,
                'locked' => 0,
                'timegraded' => 12346,
            ]);
            $studentgrade->save();
        }
        $sync = new \local_elisprogram\moodle\synchronize;
        $timenow = time();
        $sync->synchronize_moodle_class_grades();
        $this->assertEquals($timenow, get_config('local_elisprogram', 'incrementalgradesync_lastrun'));
        $this->assertEquals(0, get_config('local_elisprogram', 'incrementalgradesync_prevrun'));
        $this->assertEquals($moodleuserids[100], get_config('local_elisprogram', 'incrementalgradesync_nextuser'));

        set_config('incrementalgradesync_maxuserchunk', 100, 'local_elisprogram');
        $sync->synchronize_moodle_class_grades();
        $this->assertEquals($timenow, get_config('local_elisprogram', 'incrementalgradesync_lastrun'));
        $this->assertEquals(0, get_config('local_elisprogram', 'incrementalgradesync_prevrun'));
        $this->assertEquals($moodleuserids[200], get_config('local_elisprogram', 'incrementalgradesync_nextuser'));

        $newgradetime = time();
        for ($i = 1; $i <= 101; ++$i) {
            $elisuser = new user(['username' => 'userx'.$i, 'idnumber' => 'userx'.$i, 'firstname' => 'Test', 'lastname' => 'UserX'.$i,
                'email' => "testuserx{$i}@noreply.com", 'country' => 'CA']);
            $elisuser->save();
            $mu = $elisuser->get_moodleuser(false)->id;
            enrol_try_internal_enrol(2, $mu, 1);
            $rndgrade = rand(42, 99);
            $this->create_grade_grade($gradeitem->id, $mu, $rndgrade, 100, $newgradetime);
            // Enrol in PM class.
            $studentgrade = new \student_grade([
                'userid' => $elisuser->id,
                'classid' => 100,
                'completionid' => $completionid,
                'grade' => $rndgrade,
                'locked' => 0,
                'timegraded' => $newgradetime - 1,
            ]);
            $studentgrade->save();
        }

        set_config('incrementalgradesync_maxuserchunk', 100, 'local_elisprogram');
        $sync->synchronize_moodle_class_grades();
        $this->assertEquals($timenow, get_config('local_elisprogram', 'incrementalgradesync_lastrun'));
        $this->assertEquals(0, get_config('local_elisprogram', 'incrementalgradesync_prevrun'));
        $this->assertEquals($moodleuserids[300], get_config('local_elisprogram', 'incrementalgradesync_nextuser'));

        set_config('incrementalgradesync_maxuserchunk', 100, 'local_elisprogram');
        $sync->synchronize_moodle_class_grades();
        $this->assertEquals($timenow, get_config('local_elisprogram', 'incrementalgradesync_lastrun'));
        $this->assertEquals($timenow, get_config('local_elisprogram', 'incrementalgradesync_prevrun'));
        $this->assertEquals(0, get_config('local_elisprogram', 'incrementalgradesync_nextuser'));

        set_config('incrementalgradesync_maxuserchunk', 100, 'local_elisprogram');
        $sync->synchronize_moodle_class_grades();
        $this->assertNotEquals($timenow, get_config('local_elisprogram', 'incrementalgradesync_lastrun'));
        $this->assertEquals($timenow, get_config('local_elisprogram', 'incrementalgradesync_prevrun'));
        $this->assertGreaterThan(0, get_config('local_elisprogram', 'incrementalgradesync_nextuser'));
    }

    /**
     * Validate that enrolments are successfully updated according to dategraded settings: history.
     *        /
    public function test_methodupdatesenrolmentusinggradehistory() {
        global $DB;

        $this->load_csv_data();
        $ecourse = new course(100);
        $ecourse->completion_grade = 51;
        $ecourse->save();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12340, 'local_elisprogram');
        set_config('gradesyncdateorder', 'event,history', 'local_elisprogram');
        // set_config('gradesyncdebug', '1', 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');

        // Set up grades.
        $gradeitem1 = $this->create_grade_item('');
        $gradeitem1->update();
        $this->create_grade_grade($gradeitem1->id, 100, 75, 100, time() + 100);

        $gradeitem2 = $DB->get_record('grade_items', ['courseid' => 2, 'itemtype' => 'course']);
        $this->create_grade_grade($gradeitem2->id, 100, 75, 100, time() + 101);

        // Setup some grade history.
        $this->create_grade_history(['itemid' => $gradeitem2->id, 'userid' => 100, 'finalgrade' => 65, 'timemodified' => 12347]);
        $this->create_grade_history(['itemid' => $gradeitem2->id, 'userid' => 100, 'finalgrade' => 75, 'timemodified' => 12348]);
        $this->create_grade_history(['itemid' => $gradeitem2->id, 'userid' => 100, 'finalgrade' => 75, 'timemodified' => 12349]);

        // Set up PM enrolments.
        $student = new \student(['userid' => 103, 'classid' => 100, 'grade' => 25]);
        $student->save();

        // Validate setup.
        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, 25);

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_students(1);
        $count = $DB->count_records(\student::TABLE, ['grade' => 75]);
        $this->assertEquals(1, $count);
        $this->assert_student_exists(100, 103, 75);

        $enrolment = $DB->get_record(\student::TABLE, ['classid' => 100, 'userid' => 103]);
        // var_dump($enrolment);
        $this->assertEquals(12348, $enrolment->completetime);
    }

    /**
     * Validate that enrolments are successfully updated according to dategraded settings: event.
     */
    public function test_methodupdatesenrolmentusinggradeevent() {
        global $DB;

        $this->load_csv_data();
        $ecourse = new course(100);
        $ecourse->completion_grade = 51;
        $ecourse->save();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12340, 'local_elisprogram');
        set_config('gradesyncdateorder', 'event,history', 'local_elisprogram');
        // set_config('gradesyncdebug', '1', 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');

        // Set up grades.
        $gradeitem1 = $this->create_grade_item('');
        $gradeitem1->update();
        $this->create_grade_grade($gradeitem1->id, 100, 75, 100, time() + 100);

        // Setup a bogus course_completed event with known timcreated.
        $ctx = context_course::instance(2);
        $mdlcompid = $DB->insert_record('logstore_standard_log', (object)['eventname' => '\core\event\course_completed',
            'relateduserid' => 100, 'courseid' => 2, 'timecreated' => 12348, 'component' => 'core', 'action' => 'completed',
            'userid' => 2, 'target' => 'course', 'crud' => 'u', 'edulevel' => 2, 'contextid' => $ctx->id,
            'contextlevel' => CONTEXT_COURSE, 'contextinstanceid' => 2]);

        // Set up PM enrolments.
        $student = new \student(['userid' => 103, 'classid' => 100, 'grade' => 25]);
        $student->save();

        // Validate setup.
        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, 25);

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_students(1);
        $count = $DB->count_records(\student::TABLE, ['grade' => 75]);
        $this->assertEquals(1, $count);
        $this->assert_student_exists(100, 103, 75);

        $enrolment = $DB->get_record(\student::TABLE, ['classid' => 100, 'userid' => 103]);
        // var_dump($enrolment);
        $this->assertEquals(12348, $enrolment->completetime);
    }

    /**
     * Validate that enrolments are successfully updated according to dategraded settings: creation.
     */
    public function test_methodupdatesenrolmentusinggradecreation() {
        global $DB;

        $this->load_csv_data();
        $ecourse = new course(100);
        $ecourse->completion_grade = 51;
        $ecourse->save();

        // Enable incremental sync.
        set_config('incrementalgradesync', 1, 'local_elisprogram');
        set_config('incrementalgradesync_lastrun', 12340, 'local_elisprogram');
        set_config('gradesyncdateorder', 'creation,history', 'local_elisprogram');
        // set_config('gradesyncdebug', '1', 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');

        // Set up PM enrolments.
        $student = new \student(['userid' => 103, 'classid' => 100, 'grade' => 25]);
        $student->save();

        // Validate setup.
        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, 25);

        // Set up grades.
        $gradeitem1 = $this->create_grade_item('');
        $gradeitem1->update();
        $this->create_grade_grade($gradeitem1->id, 100, 75, 100, time() + 100, 12347);
        $gradeitem2 = $DB->get_record('grade_items', ['courseid' => 2, 'itemtype' => 'course']);
        $this->create_grade_grade($gradeitem2->id, 100, 75, 100, time() + 100, 12348);

        // Call and validate.
        $sync = new \local_elisprogram\moodle\synchronize;
        $sync->synchronize_moodle_class_grades();
        $this->assert_num_students(1);
        $count = $DB->count_records(\student::TABLE, ['grade' => 75]);
        $this->assertEquals(1, $count);
        $this->assert_student_exists(100, 103, 75);

        $enrolment = $DB->get_record(\student::TABLE, ['classid' => 100, 'userid' => 103]);
        // var_dump($enrolment);
        $this->assertEquals(12348, $enrolment->completetime);
    }

    /**
     * Validate that enrolments are correctly updated from user_graded events.
     */
    public function test_methodupdatesenrolmentusingusergradedevent() {
        global $DB;

        $this->load_csv_data();
        $ecourse = new course(100);
        $ecourse->completion_grade = 51;
        $ecourse->save();

        // set_config('gradesyncdebug', '1', 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');

        // Set up grades.
        $gradeitem1 = $this->create_grade_item('auto', null, 'course');
        $gg = 1;
        $this->create_grade_grade($gradeitem1->id, 100, 75, 100, 12348, null, $gg);
        $gg->grade_item = $gradeitem1; // TBD?
        $gg->timecreated = $gg->timemodified = 12348;
        // var_dump($gg);

        // Set up PM enrolments.
        $student = new \student(['userid' => 103, 'classid' => 100, 'grade' => 25]);
        $student->save();

        // Validate setup.
        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, 25);

        // Call and validate.
        $ugevent = \core\event\user_graded::create_from_grade($gg);
        pm_user_graded_event_handler($ugevent);

        $this->assert_num_students(1);
        $count = $DB->count_records(\student::TABLE, ['grade' => 75]);
        $this->assertEquals(1, $count);
        $this->assert_student_exists(100, 103, 75);

        $enrolment = $DB->get_record(\student::TABLE, ['classid' => 100, 'userid' => 103]);
        // var_dump($enrolment);
        $this->assertEquals(12348, $enrolment->completetime);
        $this->assertEquals(1, $enrolment->locked);
    }

    /**
     * Validate that locked enrolments are not updated from user_graded events.
     */
    public function test_methodskipslockedenrolmentusingusergradedevent() {
        global $DB;

        $this->load_csv_data();
        $ecourse = new course(100);
        $ecourse->completion_grade = 51;
        $ecourse->save();

        // set_config('gradesyncdebug', '1', 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');

        // Set up grades.
        $gradeitem1 = $this->create_grade_item('auto', null, 'course');
        $gg = 1;
        $this->create_grade_grade($gradeitem1->id, 100, 75, 100, 12355, null, $gg);
        $gg->grade_item = $gradeitem1; // TBD?
        $gg->timecreated = $gg->timemodified = 12355;
        // var_dump($gg);

        // Set up a locked PM enrolment.
        $student = new \student(['userid' => 103, 'classid' => 100, 'grade' => 55, 'locked' => 1, 'completetime' => 12348,
            'completestatusid' => 2]);
        $student->save();

        // Validate setup.
        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, 55, 2, 12348);

        // Call and validate.
        $ugevent = \core\event\user_graded::create_from_grade($gg);
        pm_user_graded_event_handler($ugevent);

        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, 55, 2, 12348);
    }

    /**
     * Validate that completions are correctly created from user_graded events.
     */
    public function test_methodcreatescompletionusingusergradedevent() {
        global $DB;

        $this->load_csv_data();
        $ecourse = new course(100);
        $ecourse->completion_grade = 51;
        $ecourse->save();

        // set_config('gradesyncdebug', '1', 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');

        // Set up grades.
        $gradeitem1 = $this->create_grade_item('x123');
        $compid = $this->create_course_completion('x123', 51.0);
        $gg = 1;
        $this->create_grade_grade($gradeitem1->id, 100, 75, 100, 12348, null, $gg);
        $gg->grade_item = $gradeitem1; // TBD?
        $gg->timecreated = $gg->timemodified = 12348;
        // var_dump($gg);

        // Set up PM enrolments.
        $student = new \student(['userid' => 103, 'classid' => 100, 'grade' => 25]);
        $student->save();

        // Validate setup.
        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, 25);

        // Call and validate.
        $ugevent = \core\event\user_graded::create_from_grade($gg);
        pm_user_graded_event_handler($ugevent);

        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, 25);
        $this->assert_student_grade_exists(100, 103, $compid, 75, 1);
        $enrolment = $DB->get_record(\student::TABLE, ['classid' => 100, 'userid' => 103]);
        // var_dump($enrolment);
        $this->assertEquals(0, $enrolment->completetime);
        $this->assertEquals(0, $enrolment->locked);
    }

    /**
     * Validate that completions are correctly updated from user_graded events.
     */
    public function test_methodupdatescompletionusingusergradedevent() {
        global $DB;

        $this->load_csv_data();
        $ecourse = new course(100);
        $ecourse->completion_grade = 51;
        $ecourse->save();

        // set_config('gradesyncdebug', '1', 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');

        // Set up grades.
        $gradeitem1 = $this->create_grade_item('x123');
        $compid = $this->create_course_completion('x123', 51.0);
        // Create LO grade.
        $studentgrade = new \student_grade([
            'userid' => 103,
            'classid' => 100,
            'completionid' => $compid,
            'grade' => 25,
            'locked' => 0,
            'timegraded' => 1,
        ]);
        $studentgrade->save();
        $gg = 1;
        $this->create_grade_grade($gradeitem1->id, 100, 75, 100, 12348, null, $gg);
        $gg->grade_item = $gradeitem1; // TBD?
        $gg->timecreated = $gg->timemodified = 12348;
        // var_dump($gg);

        // Set up PM enrolments.
        $student = new \student(['userid' => 103, 'classid' => 100, 'grade' => 25]);
        $student->save();

        // Validate setup.
        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, 25);

        // Call and validate.
        $ugevent = \core\event\user_graded::create_from_grade($gg);
        pm_user_graded_event_handler($ugevent);

        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, 25);
        $this->assert_student_grade_exists(100, 103, $compid, 75, 1);
        $enrolment = $DB->get_record(\student::TABLE, ['classid' => 100, 'userid' => 103]);
        // var_dump($enrolment);
        $this->assertEquals(0, $enrolment->completetime);
        $this->assertEquals(0, $enrolment->locked);
    }

    /**
     * Validate that locked completions are _not_ updated from user_graded events.
     */
    public function test_methodskipslockedcompletionusingusergradedevent() {
        global $DB;

        $this->load_csv_data();
        $ecourse = new course(100);
        $ecourse->completion_grade = 51;
        $ecourse->save();

        // set_config('gradesyncdebug', '1', 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');

        // Set up grades.
        $gradeitem1 = $this->create_grade_item('x123');
        $compid = $this->create_course_completion('x123', 51.0);
        // Create LO grade.
        $studentgrade = new \student_grade([
            'userid' => 103,
            'classid' => 100,
            'completionid' => $compid,
            'grade' => 25,
            'locked' => 1,
            'timegraded' => 1,
        ]);
        $studentgrade->save();
        $gg = 1;
        $this->create_grade_grade($gradeitem1->id, 100, 75, 100, 12348, null, $gg);
        $gg->grade_item = $gradeitem1; // TBD?
        $gg->timecreated = $gg->timemodified = 12348;
        // var_dump($gg);

        // Set up PM enrolments.
        $student = new \student(['userid' => 103, 'classid' => 100, 'grade' => 25]);
        $student->save();

        // Validate setup.
        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, 25);

        // Call and validate.
        $ugevent = \core\event\user_graded::create_from_grade($gg);
        pm_user_graded_event_handler($ugevent);

        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, 25);
        $this->assert_student_grade_exists(100, 103, $compid, 25);
        $enrolment = $DB->get_record(\student::TABLE, ['classid' => 100, 'userid' => 103]);
        // var_dump($enrolment);
        $this->assertEquals(0, $enrolment->completetime);
        $this->assertEquals(0, $enrolment->locked);
    }

    /**
     * Validate that 'failed' completions are _not_ locked when updated from user_graded events.
     */
    public function test_methoddoesnotlockfailedcompletionusingusergradedevent() {
        global $DB;

        $this->load_csv_data();
        $ecourse = new course(100);
        $ecourse->completion_grade = 51;
        $ecourse->save();

        // set_config('gradesyncdebug', '1', 'local_elisprogram');

        // Set up course.
        $this->make_course_enrollable();
        enrol_try_internal_enrol(2, 100, 1);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 12346');

        // Set up grades.
        $gradeitem1 = $this->create_grade_item('x123');
        $compid = $this->create_course_completion('x123', 51.0);
        // Create LO grade.
        $studentgrade = new \student_grade([
            'userid' => 103,
            'classid' => 100,
            'completionid' => $compid,
            'grade' => 25,
            'locked' => 0,
            'timegraded' => 1,
        ]);
        $studentgrade->save();
        $gg = 1;
        $this->create_grade_grade($gradeitem1->id, 100, 50, 100, 12348, null, $gg);
        $gg->grade_item = $gradeitem1; // TBD?
        $gg->timecreated = $gg->timemodified = 12348;
        // var_dump($gg);

        // Set up PM enrolments.
        $student = new \student(['userid' => 103, 'classid' => 100, 'grade' => 25]);
        $student->save();

        // Validate setup.
        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, 25);

        // Call and validate.
        $ugevent = \core\event\user_graded::create_from_grade($gg);
        pm_user_graded_event_handler($ugevent);

        $this->assert_num_students(1);
        $this->assert_student_exists(100, 103, 25);
        $this->assert_student_grade_exists(100, 103, $compid, 50, 0);
        $enrolment = $DB->get_record(\student::TABLE, ['classid' => 100, 'userid' => 103]);
        // var_dump($enrolment);
        $this->assertEquals(0, $enrolment->completetime);
        $this->assertEquals(0, $enrolment->locked);
    }
}