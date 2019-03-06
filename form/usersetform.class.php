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
 * @package    local_elisprogram
 * @author     Remote-Learner.net Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright  (C) 2008-2015 Remote-Learner.net Inc (http://www.remote-learner.net)
 *
 */

defined('MOODLE_INTERNAL') || die();

require_once(elispm::file('form/cmform.class.php'));
require_once(elispm::file('usersetpage.class.php'));

/**
 * Form for adding and editing clusters
 *
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class usersetform extends cmform {
    /**
     * items in the form
     */
    public function definition() {
        global $CFG;

        parent::definition();

        $mform = &$this->_form;

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'name', get_string('userset_name', 'local_elisprogram'));
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('required'), 'required', NULL, 'client');
        $mform->addHelpButton('name', 'userset_name', 'local_elisprogram');

        $mform->addElement('textarea', 'display', get_string('userset_description', 'local_elisprogram'), array('cols'=>40, 'rows'=>2));
        $mform->setType('display', PARAM_CLEAN);
        $mform->addHelpButton('display', 'userset_description', 'local_elisprogram');

        $current_cluster_id = (isset($this->_customdata['obj']->id)) ? $this->_customdata['obj']->id : '';

        //obtain the non-child clusters that we could become the child of, with availability
        //determined based on the edit capability
        $contexts = usersetpage::get_contexts('local/elisprogram:userset_edit');
        $non_child_clusters = cluster_get_non_child_clusters($current_cluster_id, $contexts);

        //parent dropdown
        if (!empty($non_child_clusters)) {
            $mform->addElement('select', 'parent', get_string('userset_parent', 'local_elisprogram'), $non_child_clusters);
            $mform->addHelpButton('parent', 'userset_parent', 'local_elisprogram');
        } else {
            global $DB;
            $parentid = 0;
            $parentname = get_string('userset_top_level','local_elisprogram');
            if (!empty($current_cluster_id) && !empty($this->_customdata['obj']->parent)) {
                $parentid = $this->_customdata['obj']->parent;
                $parentname = $DB->get_field(userset::TABLE, 'name', array('id' => $parentid));
            }
            $mform->addElement('static', 'staticparent', get_string('userset_parent', 'local_elisprogram'), $parentname);
            $mform->addElement('hidden', 'parent', $parentid);
        }
        $mform->setType('parent', PARAM_INT);

        // allow plugins to add their own fields

        $mform->addElement('header', 'userassociationfieldset', get_string('userset_userassociation', 'local_elisprogram'));

        $plugins = core_component::get_plugin_list(userset::ENROL_PLUGIN_TYPE);
        foreach ($plugins as $plugin => $plugindir) {
            require_once(elis::plugin_file(userset::ENROL_PLUGIN_TYPE.'_'.$plugin, 'lib.php'));
            call_user_func('cluster_' . $plugin . '_edit_form', $this, $mform, $current_cluster_id);
        }

        // custom fields
        $this->add_custom_fields('cluster', 'local/elisprogram:userset_edit', 'local/elisprogram:userset_view', 'cluster');

        $this->add_action_buttons();
    }

    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);

        if ($DB->record_exists_select(userset::TABLE, 'name = ? AND id <> ?', array($data['name'], $data['id']))) {
            $errors['name'] = get_string('badusersetname', 'local_elisprogram');
        }

        $errors += parent::validate_custom_fields($data, 'cluster');
        return $errors;
    }
}

/**
 * Confirm cluster deletion when the cluster has sub-clusters.  Prompt for
 * desired action (delete sub-clusters, promote sub-clusters).
 */
class usersetdeleteform extends cmform {
    public function definition() {
        global $CFG;

        parent::definition();

        $mform = &$this->_form;

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'confirm');
        $mform->setDefault('confirm', 1);
        $mform->setType('confirm', PARAM_INT);

        $radioarray = array();
        $radioarray[] = &$mform->createElement('radio', 'deletesubs', '', get_string('deletesubs', 'local_elisprogram'), 1);
        $radioarray[] = &$mform->createElement('radio', 'deletesubs', '', get_string('promotesubs', 'local_elisprogram'), 0);
        $mform->addGroup($radioarray, 'deletesubs', '', '<br />', false);
        $mform->setDefault('deletesubs', 0);

        $this->add_action_buttons();
    }
}