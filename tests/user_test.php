<?php
/**
 * ELIS(TM): Enterprise Learning Intelligence Suite
 * Copyright (C) 2008-2013 Remote-Learner.net Inc (http://www.remote-learner.net)
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
 * @copyright  (C) 2008-2013 Remote Learner.net Inc http://www.remote-learner.net
 *
 */

require_once(dirname(__FILE__).'/../../eliscore/test_config.php');
global $CFG;
require_once($CFG->dirroot.'/local/elisprogram/lib/setup.php');

// Libs.
require_once(elispm::lib('data/course.class.php'));
require_once(elispm::lib('data/curriculum.class.php'));
require_once(elispm::lib('data/curriculumcourse.class.php'));
require_once(elispm::lib('data/curriculumstudent.class.php'));
require_once(elispm::lib('data/pmclass.class.php'));
require_once(elispm::lib('data/student.class.php'));
require_once(elispm::lib('data/userset.class.php'));
require_once(elispm::lib('data/usermoodle.class.php'));
require_once($CFG->dirroot.'/user/profile/lib.php');
require_once(elispm::file('tests/other/datagenerator.php'));

/**
 * Test user functions
 * @group local_elisprogram
 */
class user_testcase extends elis_database_test {

    /**
     * Load initial data from CSVs.
     */
    protected function load_csv_data() {
        $dataset = $this->createCsvDataSet(array(
            'user' => elispm::file('tests/fixtures/mdluser.csv'),
            'user_info_category' => elispm::file('tests/fixtures/user_info_category.csv'),
            'user_info_field' => elispm::file('tests/fixtures/user_info_field.csv'),
            'user_info_data' => elispm::file('tests/fixtures/user_info_data.csv'),
            user::TABLE => elispm::file('tests/fixtures/pmuser.csv'),
            usermoodle::TABLE => elispm::file('tests/fixtures/usermoodle.csv'),
            field::TABLE => elispm::file('tests/fixtures/user_field.csv'),
            field_owner::TABLE => elispm::file('tests/fixtures/user_field_owner.csv'),
        ));
        $this->loadDataSet($dataset);

        // Initialize user context.
        $usercontext = \local_elisprogram\context\user::instance(103);

        // Load field data next (we need the user context ID and context level).
        $dataset = $this->createCsvDataSet(array(
            field_contextlevel::TABLE => elispm::file('tests/fixtures/user_field_contextlevel.csv'),
            field_category_contextlevel::TABLE => elispm::file('tests/fixtures/user_field_category_contextlevel.csv'),
            field_data_int::TABLE => elispm::file('tests/fixtures/user_field_data_int.csv'),
            field_data_char::TABLE => elispm::file('tests/fixtures/user_field_data_char.csv'),
            field_data_text::TABLE => elispm::file('tests/fixtures/user_field_data_text.csv'),
        ));
        $dataset = new PHPUnit\DbUnit\DataSet\ReplacementDataSet($dataset);
        $dataset->addFullReplacement('##USERCTXID##', $usercontext->id);
        $dataset->addFullReplacement('##USERCTXLVL##', CONTEXT_ELIS_USER);
        $this->loadDataSet($dataset);
    }

    /**
     * Test that data class has correct DB fields
     */
    public function test_dataclasshascorrectdbfields() {
        $testobj = new user(false, null, array(), false, array());
        $this->assertTrue($testobj->_test_dbfields(), 'Error(s) with class $_dbfield_ properties.');
    }

    /**
     * Test that data class has correct associations
     */
    public function test_dataclasshascorrectassociations() {
        $testobj = new user(false, null, array(), false, array());
        $this->assertTrue($testobj->_test_associations(), 'Error(s) with class associations.');
    }

    /**
     * Test that a record can be created in the database, and that a
     * corresponding Moodle user is modified.
     */
    public function test_cancreaterecordandsynctomoodle() {
        global $DB;
        // Create a record.
        $src = new user(false, null, array(), false, array());
        $src->username = '_____phpunit_test_';
        $src->password = 'pass';
        $src->idnumber = '_____phpunit_test_';
        $src->firstname = 'John';
        $src->lastname = 'Doe';
        $src->mi = 'F';
        $src->email = 'jdoe@phpunit.example.com';
        $src->country = 'CA';
        $src->save();

        // Map PM user fields to Moodle user fields.
        $fields = array(
            'username' => 'username',
            'password' => 'password',
            'idnumber' => 'idnumber',
            'firstname' => 'firstname',
            'lastname' => 'lastname',
            'mi' => false,
            'email' => 'email',
            'country' => 'country',
        );

        // Read it back.
        $retr = new user($src->id, null, array(), false, array());
        foreach ($fields as $field => $notused) {
            $this->assertEquals($src->$field, $retr->$field);
        }

        // Check that a Moodle user record was created.
        $retr = $DB->get_record('user', array('idnumber' => $src->idnumber), '*', MUST_EXIST);
        foreach ($fields as $pmfield => $mdlfield) {
            if ($mdlfield) {
                $this->assertEquals($src->$pmfield, $retr->$mdlfield);
            }
        }
    }

    /**
     * Test that a record can be modified, and that the corresponding Moodle
     * user is modified.
     */
    public function test_canupdaterecordandsynctomoodle() {
        global $DB;
        require_once(elispm::lib('lib.php'));
        $this->load_csv_data();

        // Read a record.
        $src = new user(103, null, array(), false, array());
        $src->reset_custom_field_list();
        // Modify the data.
        $src->firstname = 'Testuser';
        $src->lastname = 'One';
        $src->field_sometext = 'boo';
        $src->field_sometextfrompm = 'bla';
        $src->save();

        // Read it back.
        $retr = new user($src->id, null, array(), false, array());
        $this->assertEquals($src->firstname, $retr->firstname);
        $this->assertEquals($src->lastname, $retr->lastname);

        // Check the Moodle user.
        $retr = $DB->get_record('user', array('id' => 100));
        profile_load_data($retr);
        fix_moodle_profile_fields($retr);
        $this->assertEquals($src->firstname, $retr->firstname);
        $this->assertEquals($src->lastname, $retr->lastname);

        // Check custom fields.
        $result = new moodle_recordset_phpunit_datatable('user_info_data',
                $DB->get_records('user_info_data', null, '', 'id, userid, fieldid, data'));
        $dataset = new PHPUnit\DbUnit\DataSet\CsvDataSet();
        $dataset->addTable('user_info_data', elispm::file('tests/fixtures/user_info_data.csv'));
        $dataset = new PHPUnit\DbUnit\DataSet\ReplacementDataSet($dataset);
        // Only the second text field should be changed; everything else should be the same.
        $dataset->addFullReplacement('Second text entry field', 'bla');
        $this->assertTablesEqual($dataset->getTable('user_info_data'), $result);
    }

    /**
     * Test that creating a Moodle user also creates a corresponding PM user.
     */
    public function test_creatingmoodleusercreatespmuser() {
        global $DB;
        // Create a record.
        $src = new stdClass;
        $src->username = '_____phpunit_test_';
        $src->password = 'pass';
        $src->idnumber = '_____phpunit_test_';
        $src->firstname = 'John';
        $src->lastname = 'Doe';
        $src->email = 'jdoe@phpunit.example.com';
        $src->country = 'CA';
        $src->confirmed = 1;
        $src->id = $DB->insert_record('user', $src);
        $usercontext = context_user::instance($src->id);
        $eventdata = array(
            'context' => $usercontext,
            'objectid' => $src->id
        );
        $event = \core\event\user_created::create($eventdata);
        $event->trigger();

        // Map PM user fields to Moodle user fields.
        $fields = array(
            'username' => 'username',
            'password' => 'password',
            'idnumber' => 'idnumber',
            'firstname' => 'firstname',
            'lastname' => 'lastname',
            'mi' => false,
            'email' => 'email',
            'country' => 'country',
        );

        // Read it back.
        $retr = user::find(new field_filter('idnumber', $src->idnumber), array(), 0, 0);
        $this->assertTrue($retr->valid());
        $retr = $retr->current();
        foreach ($fields as $pmfield => $mdlfield) {
            if ($mdlfield) {
                $this->assertEquals($src->$mdlfield, $retr->$pmfield);
            }
        }
    }

    /**
     * Test that modifying a Moodle user also updates the corresponding PM user.
     */
    public function test_modifyingmoodleuserupdatespmuser() {
        global $CFG, $DB;
        require_once($CFG->dirroot.'/admin/tool/uploaduser/locallib.php');
        $this->load_csv_data();

        // Update a record.
        $src = new stdClass;
        $src->id = 100;
        $src->firstname = 'Testuser';
        $src->lastname = 'One';
        $src->profile_field_sometext = 'boo';
        $src->profile_field_sometextfrompm = 'bla';
        $DB->update_record('user', $src);
        $mdluser = $DB->get_record('user', array('id' => 100));
        $mcopy = clone($src);
        $mcopy = uu_pre_process_custom_profile_data($mcopy);
        profile_save_data($mcopy);
        $usercontext = context_user::instance($mdluser->id);
        $eventdata = array(
            'context' => $usercontext,
            'objectid' => $mdluser->id
        );
        $event = \core\event\user_updated::create($eventdata);
        $event->trigger();

        // Read the PM user and compare.
        $retr = new user(103, null, array(), false, array());
        $retr->reset_custom_field_list();
        $this->assertEquals($mdluser->firstname, $retr->firstname);
        $this->assertEquals($mdluser->lastname, $retr->lastname);

        // Check custom fields.
        $result = new PHPUnit\DbUnit\DataSet\DefaultDataSet();
        $result->addTable(new moodle_recordset_phpunit_datatable(field_data_int::TABLE,
                $DB->get_recordset(field_data_int::TABLE, null, '', 'contextid, fieldid, data')));
        $result->addTable(new moodle_recordset_phpunit_datatable(field_data_char::TABLE,
                $DB->get_recordset(field_data_char::TABLE, null, '', 'contextid, fieldid, data')));
        $result->addTable(new moodle_recordset_phpunit_datatable(field_data_text::TABLE,
                $DB->get_recordset(field_data_text::TABLE, null, '', 'contextid, fieldid, data')));
        $usercontext = \local_elisprogram\context\user::instance(103);
        $dataset = new PHPUnit\DbUnit\DataSet\CsvDataSet();
        $dataset->addTable(field_data_int::TABLE, elispm::file('tests/fixtures/user_field_data_int.csv'));
        $dataset->addTable(field_data_char::TABLE, elispm::file('tests/fixtures/user_field_data_char.csv'));
        $dataset->addTable(field_data_text::TABLE, elispm::file('tests/fixtures/user_field_data_text.csv'));
        $dataset = new PHPUnit\DbUnit\DataSet\ReplacementDataSet($dataset);
        $dataset->addFullReplacement('##USERCTXID##', $usercontext->id);
        $dataset->addFullReplacement('##USERCTXLVL##', CONTEXT_ELIS_USER);
        // Only the first text field should be changed; everything else should be the same.
        $dataset->addFullReplacement('First text entry field', $src->profile_field_sometext);
        $ret = $dataset->addFullReplacement('Second text entry field', $src->profile_field_sometextfrompm);

        $this->assertDataSetsEqual($dataset, $result);
    }

    /**
     * Test PM user method moodle_fullname
     */
    public function test_pmuser_moodle_fullname() {
        global $DB;
        // Create a Moodle user
        $src = new stdClass;
        $src->username = '_____phpunit_test_';
        $src->password = 'pass';
        $src->idnumber = '_____phpunit_test_';
        $src->firstname = 'John';
        $src->lastname = 'Doe';
        $src->email = 'jdoe@phpunit.example.com';
        $src->country = 'CA';
        $src->confirmed = 1;
        $src->id = $DB->insert_record('user', $src);
        $usercontext = context_user::instance($src->id);
        $eventdata = array(
            'context' => $usercontext,
            'objectid' => $src->id
        );
        $event = \core\event\user_created::create($eventdata);
        $event->trigger();

        // Get the PM user
        $retr = user::find(new field_filter('idnumber', $src->idnumber), array(), 0, 0);
        $this->assertTrue($retr->valid());
        $retr = $retr->current();
        $mdluser = $DB->get_record('user', array('id' => $src->id));
        $this->assertEquals(fullname($mdluser), $retr->moodle_fullname());
    }

    /**
     * Data Provider for test_cancreaterecordandsynctomoodle
     * @return array of arrays of parameters.
     */
    public function dataprovider_synchronizemoodleuser() {
        return [
            'syncmoodleuser1' => [
            [   // ELIS User
                'username' => '_____phpunit_test_',
                'password' => 'pass',
                'idnumber' => '_____phpunit_test_',
                'firstname' => 'John',
                'lastname' => 'Doe',
                'mi' => 'F',
                'email' => 'jdoe@phpunit.example.com',
                'country' => 'CA'],
            [   // New Moodle User
                'username' => '_____phpunit_test_',
                'password' => 'pass',
                'idnumber' => '_____phpunit_test_',
                'firstname' => 'John',
                'lastname' => 'Doe',
                'email' => 'jdoe@phpunit.example.com',
                'country' => 'CA'],
            'new_idnumber', true],
        ];
    }

    /**
     * Test that an ELIS User record can be created & updated in the database, and that a
     * corresponding Moodle user is modified as well and both allow idnumber updating (ELIS-9373).
     * @param array the initial ELIS User object to create.
     * @param array the expected Moodle User object properties to verify.
     * @param string $newidnumber if not empty, idnumber to update ELIS User with and sync to Moodle.
     * @param bool $strictmatch whether we should use ELIs-Moodle user association table.
     * @dataProvider dataprovider_synchronizemoodleuser
     */
    public function test_synchronizemoodleuser($euser, $muser, $newidnumber, $strictmatch) {
        global $DB;
        // Create a record.
        $src = new user(false, null, array(), false, array());
        foreach ($euser as $field => $val) {
            $src->$field = $val;
        }
        $src->save($strictmatch);

        // Read it back.
        $euser = new user($src->id, null, array(), false, array());
        foreach ($src as $field => $expected) {
            $this->assertEquals($expected, $euser->$field);
        }

        // Check that a Moodle user record was created.
        $retr = $DB->get_record('user', array('idnumber' => $src->idnumber), '*', MUST_EXIST);
        foreach ($muser as $field => $mdlval) {
            $this->assertEquals($mdlval, $retr->$field);
        }

        if (!empty($newidnumber)) {
            // Modify ELIS User idnumber.
            $src->idnumber = $newidnumber;
            $src->save($strictmatch);
            $euser = new user($src->id, null, array(), false, array());
            $this->assertEquals($newidnumber, $euser->idnumber);
            $retr = $DB->get_record('user', array('id' => $retr->id), '*', MUST_EXIST);
            $this->assertEquals($newidnumber, $retr->idnumber);
        }
    }
}