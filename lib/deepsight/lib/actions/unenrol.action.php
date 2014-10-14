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
 * @copyright  (C) 2013 Remote Learner.net Inc http://www.remote-learner.net
 * @author     James McQuillan <james.mcquillan@remote-learner.net>
 *
 */

/**
 * An action to unenrol a user from a class.
 */
class deepsight_action_unenrol extends deepsight_action_confirm {
    public $label = 'Unenrol User';
    public $icon = 'elisicon-unassoc';

    /**
     * Constructor
     * @param moodle_database $DB The active database connection.
     * @param string $name The unique name of the action to use.
     * @param string $descsingle The description when the confirmation is for a single element.
     * @param string $descmultiple The description when the confirmation is for the bulk list.
     */
    public function __construct(moodle_database &$DB, $name, $descsingle='', $descmultiple='') {
        parent::__construct($DB, $name);
        $this->label = get_string('ds_action_unenrol', 'local_elisprogram');

        $this->descsingle = (!empty($descsingle))
                ? $descsingle : get_string('ds_action_unenrol_confirm', 'local_elisprogram');
        $this->descmultiple = (!empty($descmultiple))
                ? $descmultiple : get_string('ds_action_unenrol_confirm_multi', 'local_elisprogram');
    }

    /**
     * Unenrol the user from the class.
     *
     * @param array $elements An array of elements to perform the action on.
     * @param bool $bulkaction Whether this is a bulk-action or not.
     * @return array An array to format as JSON and return to the Javascript.
     */
    protected function _respond_to_js(array $elements, $bulkaction) {
        global $DB;
        $classid = required_param('id', PARAM_INT);

        $assocclass = 'student';
        $assocparams = ['main' => 'classid', 'incoming' => 'userid'];
        return $this->attempt_unassociate($classid, $elements, $bulkaction, $assocclass, $assocparams);
    }

    /**
     * Determine whether the current user can manage an association.
     *
     * @param int $classid The ID of the main element. The is the ID of the 'one', in a 'many-to-one' association.
     * @param int $userid The ID of the incoming element. The is the ID of the 'many', in a 'many-to-one' association.
     * @return bool Whether the current can manage (true) or not (false)
     */
    protected function can_manage_assoc($classid, $userid) {
        global $DB;
        $association = ['userid' => $userid, 'classid' => $classid];
        $student = new student($association);
        $student->load();
        if (empty(elis::$config->local_elisprogram->force_unenrol_in_moodle)) {
            // Check whether the user is enrolled in the Moodle course via any plugin other than the elis plugin.
            $mcourse = $student->pmclass->classmoodle;
            $muser = $student->users->get_moodleuser();
            if ($mcourse->valid() && $muser) {
                $mcourse = $mcourse->current()->moodlecourseid;
                if ($mcourse) {
                    $ctx = context_course::instance($mcourse);
                    if ($DB->record_exists_select('role_assignments', "userid = ? AND contextid = ? AND component != 'enrol_elis'",
                                                  array($muser->id, $ctx->id))) {
                        // User is assigned a role other than via the elis enrolment plugin.
                        return false;
                    }
                }
            }
        }
        return student::can_manage_assoc($student->userid, $student->classid);
    }
}