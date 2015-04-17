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
 * Functions used by gradebook plugins and reports.
 *
 * @package   gradeexport_csv
 * @copyright 2015 onwards Willian Mano  {@link http://willianmano.net}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * This class iterates over all users that are graded in a course.
 * Returns detailed info about users and their grades.
 *
 * @author Willian Mano <willianmano.net>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class csv_graded_users_iterator {

    /**
     * The couse whose users we are interested in
     */
    protected $course;

    /**
     * An array of grade items or null if only user data was requested
     */
    protected $grade_items;

    /**
     * The group ID we are interested in. 0 means all groups.
     */
    protected $groupid;

    /**
     * A recordset of graded users
     */
    protected $users_rs;

    /**
     * A recordset of user grades (grade_grade instances)
     */
    protected $grades_rs;

    /**
     * Array used when moving to next user while iterating through the grades recordset
     */
    protected $gradestack;

    /**
     * The first field of the users table by which the array of users will be sorted
     */
    protected $sortfield1;

    /**
     * Should sortfield1 be ASC or DESC
     */
    protected $sortorder1;

    /**
     * The second field of the users table by which the array of users will be sorted
     */
    protected $sortfield2;

    /**
     * Should sortfield2 be ASC or DESC
     */
    protected $sortorder2;

    /**
     * Should users whose enrolment has been suspended be ignored?
     */
    protected $onlyactive = false;

    /**
     * Enable user custom fields
     */
    protected $allowusercustomfields = false;

    /**
     * List of suspended users in course. This includes users whose enrolment status is suspended
     * or enrolment has expired or not started.
     */
    protected $suspendedusers = array();

    /**
     * Constructor
     *
     * @param object $course A course object
     * @param array  $grade_items array of grade items, if not specified only user info returned
     * @param int    $groupid iterate only group users if present
     * @param string $sortfield1 The first field of the users table by which the array of users will be sorted
     * @param string $sortorder1 The order in which the first sorting field will be sorted (ASC or DESC)
     * @param string $sortfield2 The second field of the users table by which the array of users will be sorted
     * @param string $sortorder2 The order in which the second sorting field will be sorted (ASC or DESC)
     */
    public function __construct($course, $grade_items=null, $groupid=0,
                                          $sortfield1='lastname', $sortorder1='ASC',
                                          $sortfield2='firstname', $sortorder2='ASC') {
        $this->course      = $course;
        $this->grade_items = $grade_items;
        $this->groupid     = $groupid;
        $this->sortfield1  = $sortfield1;
        $this->sortorder1  = $sortorder1;
        $this->sortfield2  = $sortfield2;
        $this->sortorder2  = $sortorder2;

        $this->gradestack  = array();
    }

    /**
     * Initialise the iterator
     *
     * @return boolean success
     */
    public function init() {
        global $CFG, $DB;

        $this->close();

        export_verify_grades($this->course->id);
        $course_item = grade_item::fetch_course_item($this->course->id);
        if ($course_item->needsupdate) {
            // Can not calculate all final grades - sorry.
            return false;
        }

        $coursecontext = context_course::instance($this->course->id);

        list($relatedctxsql, $relatedctxparams) = $DB->get_in_or_equal($coursecontext->get_parent_context_ids(true), SQL_PARAMS_NAMED, 'relatedctx');
        list($gradebookroles_sql, $params) = $DB->get_in_or_equal(explode(',', $CFG->gradebookroles), SQL_PARAMS_NAMED, 'grbr');
        list($enrolledsql, $enrolledparams) = get_enrolled_sql($coursecontext, '', 0, $this->onlyactive);

        $params = array_merge($params, $enrolledparams, $relatedctxparams);

        $groupfields = '';
        if ($this->groupid) {
            $groupsql = "INNER JOIN {groups_members} gm ON gm.userid = u.id";
            $groupwheresql = "AND gm.groupid = :groupid";
            // $params contents: gradebookroles
            $params['groupid'] = $this->groupid;
        } else {
            $groupsql = "LEFT JOIN {groups_members} gm ON gm.userid = u.id
                         LEFT JOIN {groups} go ON gm.groupid = go.id";
            
            $groupwheresql = "AND go.courseid = :groupcourseid";
            $params['groupcourseid'] = $this->course->id;

            $groupfields = ', go.name as groupname';
        }

        if (empty($this->sortfield1)) {
            // We must do some sorting even if not specified.
            $ofields = ", u.id AS usrt";
            $order   = "usrt ASC";

        } else {
            $ofields = ", $this->sortfield1 AS usrt1";
            $order   = "usrt1 $this->sortorder1";
            if (!empty($this->sortfield2)) {
                $ofields .= ", $this->sortfield2 AS usrt2";
                $order   .= ", usrt2 $this->sortorder2";
            }
            if ($this->sortfield1 != 'id' and $this->sortfield2 != 'id') {
                // User order MUST be the same in both queries,
                // must include the only unique user->id if not already present.
                $ofields .= ", u.id AS usrt";
                $order   .= ", usrt ASC";
            }
        }

        $userfields = 'u.*';
        $customfieldssql = '';
        if ($this->allowusercustomfields && !empty($CFG->grade_export_customprofilefields)) {
            $customfieldscount = 0;
            $customfieldsarray = grade_helper::get_user_profile_fields($this->course->id, $this->allowusercustomfields);
            foreach ($customfieldsarray as $field) {
                if (!empty($field->customid)) {
                    $customfieldssql .= "
                            LEFT JOIN (SELECT * FROM {user_info_data}
                                WHERE fieldid = :cf$customfieldscount) cf$customfieldscount
                            ON u.id = cf$customfieldscount.userid";
                    $userfields .= ", cf$customfieldscount.data AS customfield_{$field->shortname}";
                    $params['cf'.$customfieldscount] = $field->customid;
                    $customfieldscount++;
                }
            }
        }

        $users_sql = "SELECT $userfields $ofields $groupfields
                        FROM {user} u
                        JOIN ($enrolledsql) je ON je.id = u.id
                             $groupsql $customfieldssql
                        JOIN (
                                  SELECT DISTINCT ra.userid
                                    FROM {role_assignments} ra
                                   WHERE ra.roleid $gradebookroles_sql
                                     AND ra.contextid $relatedctxsql
                             ) rainner ON rainner.userid = u.id
                         WHERE u.deleted = 0
                             $groupwheresql
                    ORDER BY $order";

        $this->users_rs = $DB->get_recordset_sql($users_sql, $params);

        if (!$this->onlyactive) {
            $context = context_course::instance($this->course->id);
            $this->suspendedusers = get_suspended_userids($context);
        } else {
            $this->suspendedusers = array();
        }

        if (!empty($this->grade_items)) {
            $itemids = array_keys($this->grade_items);
            list($itemidsql, $grades_params) = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED, 'items');
            $params = array_merge($params, $grades_params);

            $grades_sql = "SELECT g.* $ofields
                             FROM {grade_grades} g
                             JOIN {user} u ON g.userid = u.id
                             JOIN ($enrolledsql) je ON je.id = u.id
                                  $groupsql
                             JOIN (
                                      SELECT DISTINCT ra.userid
                                        FROM {role_assignments} ra
                                       WHERE ra.roleid $gradebookroles_sql
                                         AND ra.contextid $relatedctxsql
                                  ) rainner ON rainner.userid = u.id
                              WHERE u.deleted = 0
                              AND g.itemid $itemidsql
                              $groupwheresql
                         ORDER BY $order, g.itemid ASC";
            $this->grades_rs = $DB->get_recordset_sql($grades_sql, $params);
        } else {
            $this->grades_rs = false;
        }

        return true;
    }

    /**
     * Returns information about the next user
     * @return mixed array of user info, all grades and feedback or null when no more users found
     */
    public function next_user() {
        if (!$this->users_rs) {
            return false; // no users present
        }

        if (!$this->users_rs->valid()) {
            if ($current = $this->_pop()) {
                // this is not good - user or grades updated between the two reads above :-(
            }

            return false; // no more users
        } else {
            $user = $this->users_rs->current();
            $this->users_rs->next();
        }

        // find grades of this user
        $grade_records = array();
        while (true) {
            if (!$current = $this->_pop()) {
                break; // no more grades
            }

            if (empty($current->userid)) {
                break;
            }

            if ($current->userid != $user->id) {
                // grade of the next user, we have all for this user
                $this->_push($current);
                break;
            }

            $grade_records[$current->itemid] = $current;
        }

        $grades = array();
        $feedbacks = array();

        if (!empty($this->grade_items)) {
            foreach ($this->grade_items as $grade_item) {
                if (!isset($feedbacks[$grade_item->id])) {
                    $feedbacks[$grade_item->id] = new stdClass();
                }
                if (array_key_exists($grade_item->id, $grade_records)) {
                    $feedbacks[$grade_item->id]->feedback       = $grade_records[$grade_item->id]->feedback;
                    $feedbacks[$grade_item->id]->feedbackformat = $grade_records[$grade_item->id]->feedbackformat;
                    unset($grade_records[$grade_item->id]->feedback);
                    unset($grade_records[$grade_item->id]->feedbackformat);
                    $grades[$grade_item->id] = new grade_grade($grade_records[$grade_item->id], false);
                } else {
                    $feedbacks[$grade_item->id]->feedback       = '';
                    $feedbacks[$grade_item->id]->feedbackformat = FORMAT_MOODLE;
                    $grades[$grade_item->id] =
                        new grade_grade(array('userid'=>$user->id, 'itemid'=>$grade_item->id), false);
                }
            }
        }

        // Set user suspended status.
        $user->suspendedenrolment = isset($this->suspendedusers[$user->id]);
        $result = new stdClass();
        $result->user      = $user;
        $result->grades    = $grades;
        $result->feedbacks = $feedbacks;
        return $result;
    }

    /**
     * Close the iterator, do not forget to call this function
     */
    public function close() {
        if ($this->users_rs) {
            $this->users_rs->close();
            $this->users_rs = null;
        }
        if ($this->grades_rs) {
            $this->grades_rs->close();
            $this->grades_rs = null;
        }
        $this->gradestack = array();
    }

    /**
     * Should all enrolled users be exported or just those with an active enrolment?
     *
     * @param bool $onlyactive True to limit the export to users with an active enrolment
     */
    public function require_active_enrolment($onlyactive = true) {
        if (!empty($this->users_rs)) {
            debugging('Calling require_active_enrolment() has no effect unless you call init() again', DEBUG_DEVELOPER);
        }
        $this->onlyactive  = $onlyactive;
    }

    /**
     * Allow custom fields to be included
     *
     * @param bool $allow Whether to allow custom fields or not
     * @return void
     */
    public function allow_user_custom_fields($allow = true) {
        if ($allow) {
            $this->allowusercustomfields = true;
        } else {
            $this->allowusercustomfields = false;
        }
    }

    /**
     * Add a grade_grade instance to the grade stack
     *
     * @param grade_grade $grade Grade object
     *
     * @return void
     */
    private function _push($grade) {
        array_push($this->gradestack, $grade);
    }


    /**
     * Remove a grade_grade instance from the grade stack
     *
     * @return grade_grade current grade object
     */
    private function _pop() {
        global $DB;
        if (empty($this->gradestack)) {
            if (empty($this->grades_rs) || !$this->grades_rs->valid()) {
                return null; // no grades present
            }

            $current = $this->grades_rs->current();

            $this->grades_rs->next();

            return $current;
        } else {
            return array_pop($this->gradestack);
        }
    }
}