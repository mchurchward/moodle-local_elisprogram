<?php
/**
 * ELIS(TM): Enterprise Learning Intelligence Suite
 * Copyright (C) 2008-2015 Remote-Learner.net Inc (http://www.remote-learner.net)
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
 * @package    usetenrol_moodleprofile
 * @author     Remote-Learner.net Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright  (C) 2008-2015 Remote-Learner.net Inc (http://www.remote-learner.net)
 *
 */

/**
 * Contains definitions for notification events.
 */

defined('MOODLE_INTERNAL') || die();

$observers = array(
        array (
            'eventname'   => '\core\event\user_updated',
            'includefile' => '/local/elisprogram/enrol/userset/moodleprofile/lib.php',
            'callback'    => 'cluster_profile_update_handler',
            'internal'    => false
        ),
        array (
            'eventname'   => '\core\event\user_created',
            'includefile' => '/local/elisprogram/enrol/userset/moodleprofile/lib.php',
            'callback'    => 'cluster_profile_update_handler',
            'internal'    => false
     )
);