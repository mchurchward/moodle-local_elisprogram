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

defined('MOODLE_INTERNAL') || die();

require_once elispm::file('/form/selectionform.class.php');

class addroleform extends selectionform {
    function get_submit_button_name() {
        return get_string('assignroles', 'role');
    }

    function definition() {
        $mform =& $this->_form;

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'role');
        $mform->setType('role', PARAM_INT);
        $mform->addElement('hidden', '_assign', 'assign');
        $mform->setType('_assign', PARAM_TEXT);

        // Moodle doesn't enforce starttime
        //$mform->addElement('select', 'starttime', get_string('startingfrom','role'), array(0=>'foo'));

        parent::definition();
    }
}

class removeroleform extends selectionform {
    function get_submit_button_name() {
        return get_string('unassignroles', 'local_elisprogram');
    }

    function definition() {
        $mform =& $this->_form;

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'role');
        $mform->setType('role', PARAM_INT);

        parent::definition();
    }
}
