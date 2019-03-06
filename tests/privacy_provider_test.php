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
 * Privacy test for the ELIS program local plugin.
 *
 * @package    local_elisprogram
 * @author     Remote-Learner.net Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright  (C) 2019 Remote Learner.net Inc http://www.remote-learner.net
 */

defined('MOODLE_INTERNAL') || die();

use \local_elisprogram\privacy\provider;

/**
 * Privacy test for the ELIS program local plugin.
 *
 * @package    local_elisprogram
 * @author     Remote-Learner.net Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright  (C) 2019 Remote Learner.net Inc http://www.remote-learner.net
 * @group local_eliscore
 */
class local_elisprogram_privacy_testcase extends \core_privacy\tests\provider_testcase {
    /**
     * Tests set up.
     */
    public function setUp() {
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Load initial data from a CSV files.
     */
    protected function load_csv_data() {
        global $CFG;
        require_once($CFG->dirroot.'/local/elisprogram/lib/setup.php');
        require_once(\elispm::lib('data/student.class.php'));
        require_once(\elispm::lib('data/usermoodle.class.php'));

        $dataset = $this->createCsvDataSet(array(
            'course' => elispm::file('tests/fixtures/mdlcourse.csv'),
            'user' => elispm::file('tests/fixtures/mdluser.csv'),
            'context' => elispm::file('tests/fixtures/pptcontext.csv'),
            classmoodlecourse::TABLE => elispm::file('tests/fixtures/class_moodle_course.csv'),
            course::TABLE => elispm::file('tests/fixtures/pmcourse.csv'),
            pmclass::TABLE => elispm::file('tests/fixtures/pmclass.csv'),
            usermoodle::TABLE => elispm::file('tests/fixtures/usermoodle.csv'),
            user::TABLE => elispm::file('tests/fixtures/pmuser.csv'),
            student::TABLE => elispm::file('tests/fixtures/student.csv'),
            student_grade::TABLE => elispm::file('tests/fixtures/class_graded.csv'),
            curriculum::TABLE => elispm::file('tests/fixtures/curriculum.csv'),
            track::TABLE => elispm::file('tests/fixtures/ppttrack.csv'),
            usertrack::TABLE => elispm::file('tests/fixtures/pptusertrack.csv'),
            userset::TABLE => elispm::file('tests/fixtures/userset.csv'),
            clusterassignment::TABLE => elispm::file('tests/fixtures/pptusersetassign.csv'),
        ));
        $this->loadDataSet($dataset);
    }

    /**
     * Check that a user context is returned if there is any user data for this user.
     */
    public function test_get_contexts_for_userid() {
        $this->resetAfterTest();

        // Set up import data.
        $this->load_csv_data();
        $user1 = (object)['id' => 100];
        $user2 = (object)['id' => 101];

        $contextlist = provider::get_contexts_for_userid($user1->id);
        $this->assertCount(1, $contextlist);
        $contextlist = provider::get_contexts_for_userid($user2->id);
        $this->assertCount(1, $contextlist);

        // Check that a context is returned and is the expected context.
        $usercontext = \context_user::instance($user2->id);
        $this->assertEquals($usercontext->id, $contextlist->get_contextids()[0]);
    }

    /**
     * Test that only users with a user context are fetched.
     */
    public function test_get_users_in_context() {
        $this->resetAfterTest();

        $component = 'local_elisprogram';

        // Set up import data.
        $this->load_csv_data();
        $user1 = (object)['id' => 100];
        $usercontext = context_user::instance($user1->id);

        $userlist = new \core_privacy\local\request\userlist($usercontext, $component);
        provider::get_users_in_context($userlist);
        $this->assertCount(1, $userlist);

        $expected = [$user1->id];
        $actual = $userlist->get_userids();
        $this->assertEquals($expected, $actual);

        // The list of users for system context should not return any users.
        $userlist = new \core_privacy\local\request\userlist(context_system::instance(), $component);
        provider::get_users_in_context($userlist);
        $this->assertCount(0, $userlist);
    }

    /**
     * Test that user data is exported correctly.
     */
    public function test_export_user_data() {
        global $DB;

        $this->resetAfterTest();

        // Set up import data.
        $this->load_csv_data();
        $usercontext = \context_user::instance(100);
        $user1 = (object)['id' => 100];
        $writer = \core_privacy\local\request\writer::with_context($usercontext);
        $this->assertFalse($writer->has_any_data());
        $approvedlist = new core_privacy\local\request\approved_contextlist($user1, 'local_elisprogram', [$usercontext->id]);
        provider::export_user_data($approvedlist);
        $data = $writer->get_data([get_string('privacy:metadata:local_elisprogram', 'local_elisprogram')]);
        $this->assertEquals('__phpunit_test1__', $data->username);
        $this->assertEquals('__idnumber__phpunit_test1__', $data->idnumber);
        $this->assertEquals('test1@phpunit.example.com', $data->email);
        $this->assertCount(1, $data->classenrolments);
        $this->assertEquals('Test Course', $data->classenrolments[0]['classname']);
        $this->assertEquals('Class not completed', $data->classenrolments[0]['completestatusid']);
        $this->assertCount(2, $data->learningobjectives);
        $this->assertEquals('Test Course', $data->learningobjectives[1]['classname']);
        $this->assertEquals('80.00000', $data->learningobjectives[1]['gradeachieved']);
        $this->assertCount(1, $data->tracks);
        $this->assertEquals('TRK1', $data->tracks[0]['trackname']);
        $this->assertEquals('TRK-1 Description', $data->tracks[0]['trackdescription']);
        $this->assertCount(1, $data->usersets);
        $this->assertEquals('Some parent user set', $data->usersets[0]['name']);
        $this->assertEquals('No', $data->usersets[0]['leader']);
    }

    /**
     * Test deleting all user data for a specific context.
     */
    public function test_delete_data_for_all_users_in_context() {
        $this->resetAfterTest();

        // Set up import data.
        $this->load_csv_data();
        $user1 = (object)['id' => 100];
        $user1epid = 103;
        $user1context = \context_user::instance($user1->id);

        // Do the pre-delete tests.
        $this->pre_user_delete_tests($user1epid);

        // Delete everything for the first user context.
        provider::delete_data_for_all_users_in_context($user1context);

        // Do the pre-delete tests.
        $this->post_user_delete_tests($user1epid);
    }

    /**
     * This should work identical to the above test.
     */
    public function test_delete_data_for_user() {
        $this->resetAfterTest();

        // Set up import data.
        $this->load_csv_data();
        $user1 = (object)['id' => 100];
        $user1epid = 103;
        $user1context = \context_user::instance($user1->id);

        // Do the pre-delete tests.
        $this->pre_user_delete_tests($user1epid);

        // Delete everything for the first user.
        $approvedlist = new \core_privacy\local\request\approved_contextlist($user1, 'local_eliscore', [$user1context->id]);
        provider::delete_data_for_user($approvedlist);

        // Do the pre-delete tests.
        $this->post_user_delete_tests($user1epid);
    }

    /**
     * Test that data for users in approved userlist is deleted.
     */
    public function test_delete_data_for_users() {
        $this->resetAfterTest();

        $component = 'local_elisprogram';

        // Set up import data.
        $this->load_csv_data();
        $user1 = (object)['id' => 100];
        $user1epid = 103;
        $user1context = \context_user::instance($user1->id);
        $user2 = (object)['id' => 101];
        $user2context = \context_user::instance($user2->id);

        // The list of users for usercontext1 should return user1.
        $userlist1 = new \core_privacy\local\request\userlist($user1context, $component);
        provider::get_users_in_context($userlist1);
        $this->assertCount(1, $userlist1);
        $expected = [$user1->id];
        $actual = $userlist1->get_userids();
        $this->assertEquals($expected, $actual);

        // The list of users for usercontext2 should return user2.
        $userlist2 = new \core_privacy\local\request\userlist($user2context, $component);
        provider::get_users_in_context($userlist2);
        $this->assertCount(1, $userlist2);
        $expected = [$user2->id];
        $actual = $userlist2->get_userids();
        $this->assertEquals($expected, $actual);

        // Do the pre-delete tests.
        $this->pre_user_delete_tests($user1epid);

        // Add userlist1 to the approved user list.
        $approvedlist = new \core_privacy\local\request\approved_userlist($user1context, $component, $userlist1->get_userids());
        // Delete user data using delete_data_for_user for usercontext1.
        provider::delete_data_for_users($approvedlist);

        // Do the post-delete tests.
        $this->post_user_delete_tests($user1epid);

        // Re-fetch users in usercontext1 - The user list should now be empty.
        $userlist1 = new \core_privacy\local\request\userlist($user1context, $component);
        provider::get_users_in_context($userlist1);
        $this->assertCount(0, $userlist1);

        // Re-fetch users in usercontext2 - The user list should not be empty (user2).
        $userlist2 = new \core_privacy\local\request\userlist($user2context, $component);
        provider::get_users_in_context($userlist2);
        $this->assertCount(1, $userlist2);

        // User data should be only removed in the user context.
        $systemcontext = context_system::instance();
        // Add userlist2 to the approved user list in the system context.
        $approvedlist = new \core_privacy\local\request\approved_userlist($systemcontext, $component, $userlist2->get_userids());
        // Delete user1 data using delete_data_for_user.
        provider::delete_data_for_users($approvedlist);
        // Re-fetch users in usercontext2 - The user list should not be empty (user2).
        $userlist2 = new \core_privacy\local\request\userlist($user2context, $component);
        provider::get_users_in_context($userlist2);
        $this->assertCount(1, $userlist2);
    }

    /**
     * Tests for delete user are all the same. Do the pre-delete tests here.
     *
     */
    private function pre_user_delete_tests($userid) {
        global $DB;

        // Get all accounts. There should be two.
        $this->assertCount(2, $DB->get_records('local_elisprogram_usr_mdl', []));
        $this->assertCount(2, $DB->get_records('local_elisprogram_usr', []));

        // Confirm other data exists.
        $this->assertCount(1, $DB->get_records('local_elisprogram_cls_enrol', ['userid' => $userid]));
        $this->assertCount(2, $DB->get_records('local_elisprogram_cls_graded', ['userid' => $userid]));
        $this->assertCount(1, $DB->get_records('local_elisprogram_usr_trk', ['userid' => $userid]));
        $this->assertCount(1, $DB->get_records('local_elisprogram_uset_asign', ['userid' => $userid]));
        $this->assertCount(1, $DB->get_records('local_elisprogram_uset_asign', ['userid' => $userid]));
    }

    /**
     * Tests for delete user are all the same. Do the post-delete tests here.
     *
     */
    private function post_user_delete_tests($userid) {
        global $DB;

        // Only the user1 record should be gone.
        $this->assertCount(0, $DB->get_records('local_elisprogram_usr_mdl', ['cuserid' => $userid]));
        $this->assertCount(1, $DB->get_records('local_elisprogram_usr_mdl', []));
        $this->assertCount(0, $DB->get_records('local_elisprogram_usr', ['id' => $userid]));
        $this->assertCount(1, $DB->get_records('local_elisprogram_usr', []));

        $this->assertCount(0, $DB->get_records('local_elisprogram_cls_enrol', ['userid' => $userid]));
        $this->assertCount(0, $DB->get_records('local_elisprogram_cls_graded', ['userid' => $userid]));
        $this->assertCount(0, $DB->get_records('local_elisprogram_usr_trk', ['userid' => $userid]));
        $this->assertCount(0, $DB->get_records('local_elisprogram_uset_asign', ['userid' => $userid]));
        $this->assertCount(0, $DB->get_records('local_elisprogram_uset_asign', ['userid' => $userid]));
    }
}