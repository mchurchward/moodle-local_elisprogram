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
 * @package    eliswidget_common
 * @author     Remote-Learner.net Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright  (C) 2016 Onwards Remote-Learner.net Inc (http://www.remote-learner.net)
 * @author     James McQuillan <james.mcquillan@remote-learner.net>
 * @author     Brent Boghosian <brent.boghosian@remote-learner.net>
 *
 */

namespace eliswidget_common\datatable;

require_once(__DIR__.'/../../../../lib/deepsight/lib/customfieldfilteringtrait.php');
require_once(__DIR__.'/../../../../lib/deepsight/lib/lib.php');

/**
 * Abstract base class for deepsight datatables used in the widget.
 */
abstract class base {
    use \customfieldfilteringtrait;

    /** @var array A list of available deepsight_filter objects for the table, indexed by the filter's $name property. */
    protected $availablefilters = [];

    /** @var array An array of deepsight_filter $name properties determining filters shown on page load. */
    protected $initialfilters = [];

    /** @var string The main table results are pulled from. This forms that FROM clause. */
    protected $maintable = '';

    /** @var \moodle_database A reference to the global database object. */
    protected $DB;

    /** @var array An array of fields that will always be selected, regardless of what has been enabled. */
    protected $fixedfields = [];

    /** @var string URL where all AJAX requests will be sent. */
    protected $endpoint = '';

    /** @var array Array of locked filters */
    protected $lockedfilters = [];

    /**
     * Constructor.
     * @param \moodle_database $DB An active database connection.
     */
    public function __construct(\moodle_database &$DB, $ajaxendpoint) {
        $this->DB =& $DB;
        $this->endpoint = $ajaxendpoint;
        $this->populate();
    }

    /**
     * Populates the class.
     *
     * Sets the class's defined filters, initial filters, and fixed columns. Also ensures properly formatted internal data.
     */
    protected function populate() {
        // Add filters.
        $filters = $this->get_filters();
        foreach ($filters as $filter) {
            if ($filter instanceof \deepsight_filter) {
                $this->availablefilters[$filter->get_name()] = $filter;
            }
        }

        // Add initial filters.
        $this->initialfilters = $this->get_initial_filters();

        // Add fixed fields.
        $this->fixedfields = $this->get_fixed_select_fields();
    }

    /**
     * Gets an array of initial filters.
     *
     * @return array An array of \deepsight_filter $name properties that will be present when the user first loads the list.
     */
    public function get_initial_filters() {
        return [];
    }

    /**
     * Gets an array of available filters.
     *
     * @return array An array of \deepsight_filter objects that will be available.
     */
    public function get_filters() {
        return [];
    }

    /**
     * Searches for and returns a table's filter.
     *
     * @param string $name The name of the requested filter.
     * @return deepsight_filter The requested filter, or null if not found.
     */
    public function get_filter($name) {
        return (isset($this->availablefilters[$name])) ? $this->availablefilters[$name] : null;
    }

    /**
     * Gets an array of fields that will always be selected, regardless of what has been enabled.
     *
     * @return array An array of fields that will always be selected.
     */
    public function get_fixed_select_fields() {
        return [];
    }

    /**
     * Get an array of datafields that will always be visible.
     *
     * @return array Array of filter aliases for fields that will always be visible.
     */
    public function get_fixed_visible_datafields() {
        return [];
    }

    /**
     * Get an array containing a list of visible and hidden datafields.
     *
     * For fields that are not fixed (see self::get_fixed_visible_datafields), additional fields are displayed when the user
     * searches on them. For fields that are not being searched on, they can be viewed by clicking a "more" link.
     *
     * @param array $filters An array of requested filter data. Formatted like [filtername]=>[data].
     * @return array Array of field information, first item is visible fields, second is hidden fields.
     */
    public function get_datafields_by_visibility(array $filters = array()) {
        $visiblefields = [];
        $hiddenfields = [];

        $fixedvisible = array_flip($this->get_fixed_visible_datafields());
        foreach ($this->availablefilters as $filtername => $filter) {
            if (!empty($this->lockedfilters[$filtername])) {
                continue;
            }
            $fields = array_combine(array_values($filter->get_field_list()), array_values($filter->get_column_labels()));
            if (isset($fixedvisible[$filtername]) || isset($filters[$filtername])) {
                $visiblefields = array_merge($visiblefields, $fields);
            } else if (strpos($filtername, 'cf_') !== 0) {
                $hiddenfields = array_merge($hiddenfields, $fields);
            }
        }
        return [array_unique($visiblefields), array_unique($hiddenfields)];
    }

    /**
     * Converts an array of requested filter data into an SQL WHERE clause.
     *
     * @param array $filters An array of requested filter data. Formatted like [filtername]=>[data].
     * @return array An array consisting of the SQL WHERE clause, and the parameters for the SQL.
     */
    protected function get_filter_sql(array $filters = array()) {
        $filtersql = [];
        $filterparams = [];

        // Assemble filter SQL.
        foreach ($filters as $filtername => $data) {
            if (isset($this->availablefilters[$filtername])) {
                list($sql, $params) = $this->availablefilters[$filtername]->get_filter_sql($data);
                if (!empty($sql)) {
                    $filtersql[] = $sql;
                }
                if (!empty($params) && is_array($params)) {
                    $filterparams = array_merge($filterparams, $params);
                }
            } else if (is_numeric($filtername) && isset($data['sql'])) {
                // Raw SQL fragments can be added as filters if they are an array containing at least 'sql', and have a numeric id.
                $filtersql[] = $data['sql'];
                if (isset($data['params']) && is_array($data['params'])) {
                    $filterparams = array_merge($filterparams, $data['params']);
                }
            }
        }

        $filtersql = (!empty($filtersql)) ? 'WHERE '.implode(' AND ', $filtersql) : '';
        return [$filtersql, $filterparams];
    }

    /**
     * Get an array of fields to select in the get_search_results method.
     *
     * @param array $filters An array of requested filter data. Formatted like [filtername]=>[data].
     * @return array Array of fields to select.
     */
    protected function get_select_fields(array $filters = array()) {
        $selectfields = ['element.id AS element_id'];
        foreach ($this->fixedfields as $field => $label) {
            $selectfields[] = $field.' AS '.str_replace('.', '_', $field);
        }

        foreach ($this->availablefilters as $filtername => $filter) {
            if (strpos($filtername, 'cf_') !== 0 || isset($filters[$filtername])) { // ELIS-9231
                $selectfields = array_merge($selectfields, $filter->get_select_fields());
            }
        }
        $selectfields = array_unique($selectfields);
        return $selectfields;
    }

    /**
     * Get a list of desired table joins to be used in the get_search_results method.
     *
     * @param array $filters An array of requested filter data. Formatted like [filtername]=>[data].
     * @return array Array with members: First item is an array of JOIN sql fragments, second is an array of parameters used by
     *               the JOIN sql fragments.
     */
    protected function get_join_sql(array $filters = array()) {
        return [[], []];
    }

    /**
     * Get a list of desired custom field table joins to be used in the get_search_results method.
     *
     * @param array $filters An array of requested filter data. Formatted like [filtername]=>[data].
     * @param int $ctxlevel The context level for the fields.
     * @param array $fields Array of \field objects, indexed by fieldname ("cf_[fieldshortname]")
     * @return array Array of joins needed to select and search on custom fields.
     */
    protected function get_active_filters_custom_field_joins(array $filters = array(), $ctxlevel, array $enabledcfields) {
        foreach ($enabledcfields as $key => $enabledcfield) {
            if (!isset($filters[$key])) {
                unset($enabledcfields[$key]);
            }
        }
        return $this->get_custom_field_joins($ctxlevel, $enabledcfields);
    }

    /**
     * Get an ORDER BY sql fragment to be used in the get_searcH_results method.
     *
     * @return string An ORDER BY sql fragment, if desired.
     */
    protected function get_sort_sql() {
        return '';
    }

    /**
     * Get a GROUP BY sql fragment to be used in the get_search_results method.
     *
     * @return string A GROUP BY sql fragment, if desired.
     */
    protected function get_groupby_sql() {
        return ''; // TBD: 'element.id';
    }

    /**
     * Get search results/
     *
     * @param array $filters An array of requested filter data. Formatted like [filtername]=>[data].
     * @param int $page The page being displayed.
     * @return array An array of table information.
     */
    public function get_search_results(array $filters = array(), $page = 1) {
        global $CFG;

        $selectfields = $this->get_select_fields($filters);
        array_unshift($selectfields, 'element.id');
        $selectfields = array_unique($selectfields);

        list($joinsql, $joinparams) = $this->get_join_sql($filters);
        $joinsql = implode(' ', $joinsql);

        list($filtersql, $filterparams) = $this->get_filter_sql($filters);

        $params = array_merge($joinparams, $filterparams);

        $sortsql = $this->get_sort_sql();
        $groupbysql = $this->get_groupby_sql();

        if (empty($page) || !is_int($page) || $page <= 0) {
            $page = 1;
        }
        $limitfrom = ($page - 1) * static::RESULTSPERPAGE;
        $limitnum = static::RESULTSPERPAGE;

        if (empty($this->maintable)) {
            throw new \coding_error('You must specify a main table ($this->maintable) in subclasses.');
        }
        $resultsarray = [];

        // Get the number of results in the full dataset.
        $newgroupbysql = empty($groupbysql) ? 'GROUP BY element.id' : preg_replace('/GROUP BY (.*)/i', 'GROUP BY element.id, $1', $groupbysql);
        // TBD: strip already existing GROUP BY element.id? Doesn't cause problem in MySQL/MariaDB
        $sqlparts = [
                'SELECT element.id',
                'FROM {'.$this->maintable.'} element',
                $joinsql,
                $filtersql,
                $newgroupbysql,
        ];
        $query = implode(' ', $sqlparts);
        $query = 'SELECT count(1) as count FROM ('.$query.') results';
        $totalresults = $this->DB->count_records_sql($query, $params);
        if ($totalresults == 0) {
            return [$resultsarray, $totalresults];
        }

        // Generate and execute query to determine pages w/o multi-valued custom field rows.
        $sqlparts = [
                'SELECT '.implode(', ', $selectfields),
                'FROM {'.$this->maintable.'} element',
                $joinsql,
                $filtersql,
                $newgroupbysql,
                $sortsql,
        ];
        $query = implode(' ', $sqlparts);
        $resultset = $this->DB->get_recordset_sql($query, $params, $limitfrom, $limitnum);
        $resultsetarray = [];
        foreach ($resultset as $id => $result) {
            $resultsetarray[$id] = $id;
        }
        unset($resultset);
        if (empty($resultsetarray)) {
            return [$resultsarray, $totalresults];
        }
        list($idsql, $idparams) = $this->DB->get_in_or_equal(array_values($resultsetarray));
        $filtersql = !empty($filtersql) ? $filtersql.' AND element.id '.$idsql : 'WHERE element.id '.$idsql;
        $params = array_merge($params, $idparams);
        // Generate and execute query to return all results w/ mutli-valued custom field rows.
        $sqlparts = [
                'SELECT '.implode(', ', $selectfields),
                'FROM {'.$this->maintable.'} element',
                $joinsql,
                $filtersql,
                $groupbysql,
                $sortsql,
        ];
        $query = implode(' ', $sqlparts);
        $results = $this->DB->get_recordset_sql($query, $params);

        $multivaluedflag = false;
        $lastid = null;
        foreach ($results as $id => $result) {
            if (empty($resultsarray[$id])) {
                if ($multivaluedflag && $lastid) {
                    foreach ($this->customfields as $fieldname => $field) {
                        $elem = $fieldname.'_data';
                        if ($field->multivalued && isset($resultsarray[$lastid]->$elem) && is_array($resultsarray[$lastid]->$elem)) {
                            $resultsarray[$lastid]->$elem = array_unique($resultsarray[$lastid]->$elem);
                            $resultsarray[$lastid]->$elem = implode(', ', $resultsarray[$lastid]->$elem);
                        }
                    }
                }
                $resultsarray[$id] = $result;
                $multivaluedflag = false;
                $lastid = $id;
            }
            foreach ($this->customfields as $fieldname => $field) {
                $elem = $fieldname.'_data';
                if (isset($resultsarray[$id]->$elem) && isset($field->params['control']) && $field->params['control'] == 'datetime') {
                    $resultsarray[$id]->$elem = ds_process_displaytime($resultsarray[$id]->$elem);
                } else if ($field->datatype == 'bool') {
                    $resultsarray[$id]->$elem = !empty($resultsarray[$id]->$elem) ? get_string('yes') : get_string('no');
                } else if ($field->multivalued && isset($result->$elem)) {
                    $multivaluedflag = true;
                    // ELIS-9234: Iff filtering on this multi-valued custom field all rows/values won't exist!
                    if (strpos($sqlparts[0], ' '.$fieldname.'.data') !== false) {
                        $fldctxlvl = $this->DB->get_field(\field_contextlevel::TABLE, 'contextlevel', array('fieldid' => $field->id));
                        if (count($resultsarray[$id]->$elem) <= 1 && $fldctxlvl &&
                                ($ctx = $this->DB->get_record('context', array('instanceid' => $id, 'contextlevel' => $fldctxlvl)))) {
                            $resultsarray[$id]->$elem = [];
                            $allvals = \field_data::get_for_context_and_field($ctx, $field->shortname, true);
                            if ($allvals && $allvals->valid()) {
                                foreach ($allvals as $val) {
                                    $resultsarray[$id]->{$elem}[] = $val->data;
                                }
                            }
                            unset($allvals);
                        }
                    } else {
                        if (!is_array($resultsarray[$id]->$elem)) {
                            $resultsarray[$id]->$elem = [$result->$elem];
                        } else {
                            $resultsarray[$id]->{$elem}[] = $result->$elem;
                        }
                    }
                }
            }
        }
        unset($results);
        if ($multivaluedflag && $lastid) {
            foreach ($this->customfields as $fieldname => $field) {
                $elem = $fieldname.'_data';
                if ($field->multivalued && isset($resultsarray[$lastid]->$elem) && is_array($resultsarray[$lastid]->$elem)) {
                    $resultsarray[$lastid]->$elem = array_unique($resultsarray[$lastid]->$elem);
                    $resultsarray[$lastid]->$elem = implode(', ', $resultsarray[$lastid]->$elem);
                }
            }
        }
        return [$resultsarray, $totalresults];
    }
}