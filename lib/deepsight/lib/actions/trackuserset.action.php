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
 * @copyright  (C) 2014 Onwards Remote-Learner.net Inc (http://www.remote-learner.net)
 * @author     James McQuillan <james.mcquillan@remote-learner.net>
 *
 */

require_once(dirname(__FILE__).'/usersettrack.action.php');

/**
 * Trait containing shared methods.
 */
trait deepsight_action_trackuserset {
    /**
     * Determine whether the current user can manage an association.
     *
     * @param int $trackid The ID of the main element. The is the ID of the 'one', in a 'many-to-one' association.
     * @param int $usersetid The ID of the incoming element. The is the ID of the 'many', in a 'many-to-one' association.
     * @return bool Whether the current can manage (true) or not (false)
     */
    protected function can_manage_assoc($trackid, $usersetid) {
        global $USER;
        $perm = 'local/elisprogram:associate';
        $trkassocctx = pm_context_set::for_user_with_capability('track', $perm, $USER->id);
        $trackassociateallowed = ($trkassocctx->context_allowed($trackid, 'track') === true) ? true : false;
        $clstassocctx = pm_context_set::for_user_with_capability('cluster', $perm, $USER->id);
        $usersetassociateallowed = ($clstassocctx->context_allowed($usersetid, 'cluster') === true) ? true : false;
        // ELIS-9057.
        if ($trackassociateallowed !== true || $usersetassociateallowed !== true) {
            $perm = 'local/elisprogram:userset_associatetrack';
            $clstassoctrkctx = pm_context_set::for_user_with_capability('cluster', $perm, $USER->id);
            if ($clstassoctrkctx->context_allowed($usersetid, 'cluster') !== true) {
                return false;
            }
        }
        return true;
    }
}

/**
 * An action to assign usersets to a track and set the autoenrol flag.
 */
class deepsight_action_trackuserset_assign extends deepsight_action_standard {
    use deepsight_action_trackuserset;

    const TYPE = 'usersettrack_assignedit';
    public $label = 'Assign';
    public $icon = 'elisicon-assoc';

    /**
     * Constructor.
     * @param moodle_database $DB The active database connection.
     * @param string $name The unique name of the action to use.
     * @param string $descsingle The description when the confirmation is for a single element.
     * @param string $descmultiple The description when the confirmation is for the bulk list.
     */
    public function __construct(moodle_database &$DB, $name, $descsingle='', $descmultiple='') {
        parent::__construct($DB, $name);
        $this->label = ucwords(get_string('assign', 'local_elisprogram'));

        $langelements = new stdClass;
        $langelements->baseelement = strtolower(get_string('track', 'local_elisprogram'));
        $langelements->actionelement = strtolower(get_string('cluster', 'local_elisprogram'));
        $this->descsingle = (!empty($descsingle))
                ? $descsingle : get_string('ds_action_assign_confirm', 'local_elisprogram', $langelements);

        $langelements = new stdClass;
        $langelements->baseelement = strtolower(get_string('track', 'local_elisprogram'));
        $langelements->actionelement = strtolower(get_string('clusters', 'local_elisprogram'));
        $this->descmultiple = (!empty($descmultiple))
                ? $descmultiple : get_string('ds_action_assign_confirm_multi', 'local_elisprogram', $langelements);
    }

    /**
     * Provide options to the javascript.
     *
     * @return array An array of options.
     */
    public function get_js_opts() {
        global $CFG;

        $opts = parent::get_js_opts();
        $opts['condition'] = $this->condition;
        $opts['opts']['actionurl'] = $this->endpoint;
        $opts['opts']['autoenroldefault'] = '';
        $opts['opts']['desc_single'] = $this->descsingle;
        $opts['opts']['desc_multiple'] = $this->descmultiple;
        $opts['opts']['mode'] = 'assign';
        $opts['opts']['lang_bulk_confirm'] = get_string('ds_bulk_confirm', 'local_elisprogram');
        $opts['opts']['lang_working'] = get_string('ds_working', 'local_elisprogram');
        $opts['opts']['langautoenrol'] = get_string('usersettrack_auto_enrol', 'local_elisprogram');
        $opts['opts']['langrecursive'] = get_string('usersettrack_recursive', 'local_elisprogram');
        $opts['opts']['langyes'] = get_string('yes', 'moodle');
        $opts['opts']['langno'] = get_string('no', 'moodle');
        return $opts;
    }

    /**
     * Assign usersets to the track.
     * @param array $elements An array of userset information to assign to the track.
     * @param bool $bulkaction Whether this is a bulk-action or not.
     * @return array An array to format as JSON and return to the Javascript.
     */
    protected function _respond_to_js(array $elements, $bulkaction) {
        global $DB;
        $trackid = required_param('id', PARAM_INT);
        $autoenrol = optional_param('autoenrol', 0, PARAM_INT);
        $autounenrol = optional_param('autounenrol', 1, PARAM_INT);
        $recursive = optional_param('recursive', 0, PARAM_INT);
        $failedops = [];
        foreach ($elements as $usersetid => $label) {
            if ($this->can_manage_assoc($trackid, $usersetid) === true) {
                try {
                    clustertrack::associate($usersetid, $trackid, $autounenrol, $autoenrol);
                } catch (\Exception $e) {
                    if ($bulkaction === true || $recursive) {
                        $failedops[] = $usersetid;
                    } else {
                        throw $e;
                    }
                }
                if ($recursive) {
                    $userset = new userset($usersetid);
                    $subsets = $userset->get_all_subsets();
                    foreach ($subsets as $subset) {
                        if (isset($elements[$subset->id])) {
                            continue;
                        }
                        if ($this->can_manage_assoc($trackid, $subset->id) === true) {
                            try {
                                clustertrack::associate($subset->id, $trackid, $autounenrol, $autoenrol);
                            } catch (\Exception $e) {
			        $failedops[] = $usersetid;
                            }
			} else {
			    $failedops[] = $usersetid;
                        }
                    }
                }
            } else {
                $failedops[] = $usersetid;
            }
        }

        if (!empty($failedops)) {
             return [
                'result' => 'partialsuccess',
                'msg' => get_string($recursive ? 'ds_action_generic_recursive' : 'ds_action_generic_bulkfail', 'local_elisprogram'),
                'failedops' => $failedops,
            ];
        } else {
            return array('result' => 'success', 'msg' => 'Success');
        }
    }
}

/**
 * An action to edit the autoenrol flag on a clustertrack assignment.
 */
class deepsight_action_trackuserset_edit extends deepsight_action_standard {
    use deepsight_action_trackuserset;

    const TYPE = 'usersettrack_assignedit';
    public $label = '';
    public $icon = 'elisicon-edit';

    /**
     * Sets the action's label from language string.
     */
    protected function postconstruct() {
        $this->label = get_string('ds_action_edit', 'local_elisprogram');
    }

    /**
     * Provide options to the javascript.
     * @return array An array of options.
     */
    public function get_js_opts() {
        global $CFG;
        $opts = parent::get_js_opts();
        $opts['condition'] = $this->condition;
        $opts['opts']['actionurl'] = $this->endpoint;
        $opts['opts']['desc_single'] = '';
        $opts['opts']['desc_multiple'] = '';
        $opts['opts']['mode'] = 'edit';
        $opts['opts']['lang_bulk_confirm'] = get_string('ds_bulk_confirm', 'local_elisprogram');
        $opts['opts']['lang_working'] = get_string('ds_working', 'local_elisprogram');
        $opts['opts']['langautoenrol'] = get_string('usersettrack_auto_enrol', 'local_elisprogram');
        $opts['opts']['langrecursive'] = get_string('usersettrack_recursive_edit', 'local_elisprogram');
        $opts['opts']['langautoassoc'] = get_string('usersettrack_autoassoc', 'local_elisprogram');
        $opts['opts']['langyes'] = get_string('yes', 'moodle');
        $opts['opts']['langno'] = get_string('no', 'moodle');
        return $opts;
    }

    /**
     * Edit clustertrack information.
     * @param array $elements An array of track information to assign to the userset.
     * @param bool $bulkaction Whether this is a bulk-action or not.
     * @return array An array to format as JSON and return to the Javascript.
     */
    protected function _respond_to_js(array $elements, $bulkaction) {
        global $DB;
        $trackid = required_param('id', PARAM_INT);
        $autoenrol = required_param('autoenrol', PARAM_INT);
        $autounenrol = optional_param('autounenrol', 1, PARAM_INT);
        $recursive = optional_param('recursive', 0, PARAM_INT);
        $autoassoc = optional_param('autoassoc', 0, PARAM_INT);
        $failedops = [];
        foreach ($elements as $usersetid => $label) {
            if ($this->can_manage_assoc($trackid, $usersetid) === true) {
                $association = $DB->get_record(clustertrack::TABLE, array('clusterid' => $usersetid, 'trackid' => $trackid));
                if (!empty($association)) {
                    try {
                        clustertrack::update_autoenrol($association->id, $autoenrol);
                    } catch (\Exception $e) {
                        if ($bulkaction === true || $recursive) {
                            $failedops[] = $usersetid;
                        } else {
                            throw $e;
                        }
                    }
                } else {
                    $failedops[] = $usersetid;
                }
                if ($recursive) {
                    $userset = new userset($usersetid);
                    $subsets = $userset->get_all_subsets();
                    foreach ($subsets as $subset) {
                        if (isset($elements[$subset->id])) {
                            continue;
                        }
                        if ($this->can_manage_assoc($trackid, $subset->id) === true) {
                            $association = $DB->get_record(clustertrack::TABLE, array('clusterid' => $subset->id, 'trackid' => $trackid));
                            if (empty($association)) {
                                if ($autoassoc) {
                                    try {
                                        clustertrack::associate($subset->id, $trackid, $autounenrol, $autoenrol);
                                    } catch (\Exception $e) {
                                        $failedops[] = $usersetid;
                                    }
                                }
                            } else {
                                try {
                                    clustertrack::update_autoenrol($association->id, $autoenrol);
                                } catch (\Exception $e) {
                                    $failedops[] = $usersetid;
                                }
                            }
                        } else {
                            $failedops[] = $usersetid;
                        }
                    }
                }
            } else {
                $failedops[] = $usersetid;
            }
        }

        if (!empty($failedops)) {
             return [
                'result' => 'partialsuccess',
                'msg' => get_string($recursive ? 'ds_action_generic_recursive' : 'ds_action_generic_bulkfail', 'local_elisprogram'),
                'failedops' => $failedops,
            ];
        } else {
            return array('result' => 'success', 'msg' => 'Success');
        }
    }
}

/**
 * An action to unassign usersets from a track.
 */
class deepsight_action_trackuserset_unassign extends deepsight_action_standard {
    use deepsight_action_trackuserset;
    use deepsight_action_usersettrack_trackuserset;

    const TYPE = 'trackuserset_unassign';

    public $label = 'Unassign';
    public $icon = 'elisicon-unassoc';

    /**
     * Constructor.
     * @param moodle_database $DB The active database connection.
     * @param string $name The unique name of the action to use.
     * @param string $descsingle The description when the confirmation is for a single element.
     * @param string $descmultiple The description when the confirmation is for the bulk list.
     */
    public function __construct(moodle_database &$DB, $name, $descsingle='', $descmultiple='') {
        parent::__construct($DB, $name);
        $this->label = ucwords(get_string('unassign', 'local_elisprogram'));

        $langelements = new stdClass;
        $langelements->baseelement = strtolower(get_string('track', 'local_elisprogram'));
        $langelements->actionelement = strtolower(get_string('cluster', 'local_elisprogram'));
        $this->descsingle = (!empty($descsingle))
                ? $descsingle : get_string('ds_action_unassign_confirm', 'local_elisprogram', $langelements);

        $langelements = new stdClass;
        $langelements->baseelement = strtolower(get_string('track', 'local_elisprogram'));
        $langelements->actionelement = strtolower(get_string('clusters', 'local_elisprogram'));
        $this->descmultiple = (!empty($descmultiple))
                ? $descmultiple : get_string('ds_action_unassign_confirm_multi', 'local_elisprogram', $langelements);
    }

    /**
     * Provide options to the javascript.
     * @return array An array of options.
     */
    public function get_js_opts() {
        global $CFG;
        $opts = parent::get_js_opts();
        $opts['condition'] = $this->condition;
        $opts['opts']['actionurl'] = $this->endpoint;
        $opts['opts']['desc_single'] = $this->descsingle;
        $opts['opts']['desc_multiple'] = $this->descmultiple;
        $opts['opts']['mode'] = 'unassign'; // TBD
        $opts['opts']['lang_bulk_confirm'] = get_string('ds_bulk_confirm', 'local_elisprogram');
        $opts['opts']['lang_working'] = get_string('ds_working', 'local_elisprogram');
        $opts['opts']['langrecursive'] = get_string('usersettrack_recursive_unassign', 'local_elisprogram');
        $opts['opts']['langremovefromprogram'] = get_string('track_removefromprogram', 'local_elisprogram');
        $opts['opts']['langremovefromclasses'] = get_string('track_removefromclasses', 'local_elisprogram');
        $opts['opts']['langhasgradedatawarning'] = get_string('usersettrack_warngrades', 'local_elisprogram');
        $opts['opts']['langyes'] = get_string('yes', 'moodle');
        $opts['opts']['langno'] = get_string('no', 'moodle');
        return $opts;
    }

    /**
     * Unassign the usersets from the track.
     * @param array $elements An array of userset information to unassign from the track.
     * @param bool $bulkaction Whether this is a bulk-action or not.
     * @return array An array to format as JSON and return to the Javascript.
     */
    protected function _respond_to_js(array $elements, $bulkaction) {
        $trackid = required_param('id', PARAM_INT);
        $recursive = optional_param('recursive', 0, PARAM_INT);
        $rmfromprg = optional_param('removefromprogram', 0, PARAM_INT);
        $rmfromclasses = optional_param('removefromclasses', 0, PARAM_INT);
        if ($recursive) {
            foreach ($elements as $usersetid => $label) {
                $userset = new userset($usersetid);
                $subsets = $userset->get_all_subsets();
                foreach ($subsets as $subset) {
                    $elements[$subset->id] = '';
                }
            }
        }
        $assocclass = 'clustertrack';
        $assocparams = ['main' => 'trackid', 'incoming' => 'clusterid', 'removefromprogram' => $rmfromprg, 'removefromclasses' => $rmfromclasses];
        return $this->attempt_unassociate_clustertrack($trackid, $elements, $bulkaction, $assocclass, $assocparams);
    }
}