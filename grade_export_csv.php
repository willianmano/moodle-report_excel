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

require_once($CFG->dirroot . '/grade/export/lib.php');
require_once('lib.php');

class grade_export_csv extends grade_export
{

    public $plugin = 'csv';

    /**
     * Constructor should set up all the private variables ready to be pulled
     * @param object $course
     * @param int $groupid id of selected group, 0 means all
     * @param stdClass $formdata The validated data from the grade export form.
     */
    public function __construct($course, $groupid, $formdata)
    {
        parent::__construct($course, $groupid, $formdata);

        // Overrides.
        $this->usercustomfields = true;
    }

    /**
     * To be implemented by child classes
     */
    public function print_grades()
    {
        global $CFG;
        require_once($CFG->dirroot . '/lib/excellib.class.php');

        $export_tracking = $this->track_exports();

        $strgrades = get_string('grades');

        // Calculate file name
        $shortname = format_string($this->course->shortname, true, array('context' => context_course::instance($this->course->id)));
        $downloadfilename = clean_filename("$shortname $strgrades.csv");
        // Creating a workbook
        $workbook = new MoodleExcelWorkbook("-");
        // Sending HTTP headers
        $workbook->send($downloadfilename);
        // Adding the worksheet
        $mycsv = $workbook->add_worksheet($strgrades);

        // Print names of all the fields
        $profilefields = $this->get_user_profile_fields($this->course->id, $this->usercustomfields);
        foreach ($profilefields as $id => $field) {
            $mycsv->write_string(0, $id, $field->fullname);
        }

        $pos = count($profilefields);
        if (!$this->onlyactive) {
            $mycsv->write_string(0, $pos++, get_string("suspended"));
        }
        foreach ($this->columns as $grade_item) {
            foreach ($this->displaytype as $gradedisplayname => $gradedisplayconst) {
                $mycsv->write_string(0, $pos++, $this->format_column_name($grade_item, false, $gradedisplayname));
            }
            // Add a column_feedback column
            if ($this->export_feedback) {
                $mycsv->write_string(0, $pos++, $this->format_column_name($grade_item, true));
            }
        }
        // Last downloaded column header.
        $mycsv->write_string(0, $pos++, get_string('timeexported', 'gradeexport_csv'));

        // Print all the lines of data.
        $i = 0;
        $geub = new grade_export_update_buffer();
        $gui = new csv_graded_users_iterator($this->course, $this->columns, $this->groupid, 'go.name', 'ASC', 'u.firstname', 'ASC');
        $gui->require_active_enrolment($this->onlyactive);
        $gui->allow_user_custom_fields($this->usercustomfields);
        $gui->init();


        while ($userdata = $gui->next_user()) {
            $i++;
            $user = $userdata->user;


            foreach ($profilefields as $id => $field) {
                $fieldvalue = $this->get_user_field_value($user, $field);
                $mycsv->write_string($i, $id, $fieldvalue);
            }
            $j = count($profilefields);
            if (!$this->onlyactive) {
                $issuspended = ($user->suspendedenrolment) ? get_string('yes') : '';
                $mycsv->write_string($i, $j++, $issuspended);
            }
            foreach ($userdata->grades as $itemid => $grade) {
                if ($export_tracking) {
                    $status = $geub->track($grade);
                }
                foreach ($this->displaytype as $gradedisplayconst) {
                    $gradestr = $this->format_grade($grade, $gradedisplayconst);
                    if (is_numeric($gradestr)) {
                        $mycsv->write_number($i, $j++, $gradestr);
                    } else {
                        $mycsv->write_string($i, $j++, $gradestr);
                    }
                }
                // writing feedback if requested
                if ($this->export_feedback) {
                    $mycsv->write_string($i, $j++, $this->format_feedback($userdata->feedbacks[$itemid]));
                }
            }
            // Time exported.
            $mycsv->write_string($i, $j++, time());
        }
        $gui->close();
        $geub->close();

        /// Close the workbook
        $workbook->close();

        exit;
    }

    /**
     * Returns an array of user profile fields to be included in export
     *
     * @param int $courseid
     * @param bool $includecustomfields
     * @return array An array of stdClass instances with customid, shortname, datatype, default and fullname fields
     */
    protected function get_user_profile_fields($courseid, $includecustomfields = false)
    {
        global $CFG, $DB;

        // Gets the fields that have to be hidden
        $hiddenfields = array_map('trim', explode(',', $CFG->hiddenuserfields));
        $context = context_course::instance($courseid);
        $canseehiddenfields = has_capability('moodle/course:viewhiddenuserfields', $context);
        if ($canseehiddenfields) {
            $hiddenfields = array();
        }

        $fields = array();
        require_once($CFG->dirroot . '/user/lib.php');                // Loads user_get_default_fields()
        require_once($CFG->dirroot . '/user/profile/lib.php');        // Loads constants, such as PROFILE_VISIBLE_ALL
        $userdefaultfields = user_get_default_fields();

        // Sets the list of profile fields
        // $userprofilefields = array_map('trim', explode(',', $CFG->grade_export_userprofilefields));
        $userprofilefields = array_map('trim', explode(',', 'fullname,email'));
        if (!empty($userprofilefields)) {
            foreach ($userprofilefields as $field) {
                $field = trim($field);
                if (in_array($field, $hiddenfields) || !in_array($field, $userdefaultfields)) {
                    continue;
                }
                $obj = new stdClass();
                $obj->customid = 0;
                $obj->shortname = $field;
                $obj->fullname = get_string($field);
                $fields[] = $obj;
            }
        }

        // Adicionado na mao o polo do aluno
        $obj = new stdClass();
        $obj->customid = 0;
        $obj->shortname = 'group';
        $obj->fullname = get_string('group');
        $fields[] = $obj;

        // Sets the list of custom profile fields
        $customprofilefields = array_map('trim', explode(',', $CFG->grade_export_customprofilefields));
        if ($includecustomfields && !empty($customprofilefields)) {
            list($wherefields, $whereparams) = $DB->get_in_or_equal($customprofilefields);
            $customfields = $DB->get_records_sql("SELECT f.*
                                                FROM {user_info_field} f
                                                JOIN {user_info_category} c ON f.categoryid=c.id
                                                WHERE f.shortname $wherefields
                                                ORDER BY c.sortorder ASC, f.sortorder ASC", $whereparams);

            foreach ($customfields as $field) {
                // Make sure we can display this custom field
                if (!in_array($field->shortname, $customprofilefields)) {
                    continue;
                } else if (in_array($field->shortname, $hiddenfields)) {
                    continue;
                } else if ($field->visible != PROFILE_VISIBLE_ALL && !$canseehiddenfields) {
                    continue;
                }

                $obj = new stdClass();
                $obj->customid = $field->id;
                $obj->shortname = $field->shortname;
                $obj->fullname = format_string($field->name);
                $obj->datatype = $field->datatype;
                $obj->default = $field->defaultdata;
                $fields[] = $obj;
            }
        }

        $fields = array_filter($fields, function ($field) {
            // Prevents add two fullname fields and discards first and lastname fields
            return !in_array($field->shortname, array('firstname', 'lastname', 'fullname'));
        });

        // Sets fullname field for user
        $obj = new stdClass();
        $obj->customid = 0;
        $obj->shortname = 'fullname';
        $obj->fullname = get_string('fullname');
        array_unshift($fields, $obj);

        return $fields;
    }

    /**
     * Returns the value of a field from a user record
     *
     * @param stdClass $user object
     * @param stdClass $field object
     * @return string value of the field
     */
    protected function get_user_field_value($user, $field)
    {
        if (!empty($field->customid)) {
            $fieldname = 'customfield_' . $field->shortname;
            if (!empty($user->{$fieldname}) || is_numeric($user->{$fieldname})) {
                $fieldvalue = $user->{$fieldname};
            } else {
                $fieldvalue = $field->default;
            }
        } else {

            if ($field->shortname == 'fullname') {
                $fieldvalue = $user->firstname . ' ' . $user->lastname;
            } else if ($field->shortname == 'group') {
                $fieldvalue = $user->groupname;
            } else {
                $fieldvalue = $user->{$field->shortname};
            }
        }
        return $fieldvalue;
    }
}
