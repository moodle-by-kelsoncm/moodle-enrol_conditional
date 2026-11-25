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

namespace enrol_conditional\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_warnings;

/**
 * Enrols the current user in a course through conditional self enrolment.
 *
 * Web service function enrol_conditional_enrol_user.
 *
 * @package   enrol_conditional
 * @copyright 2012 Rajesh Taneja <rajesh@moodle.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_user extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'courseid' => new external_value(PARAM_INT, 'Id of the course'),
                'password' => new external_value(PARAM_RAW, 'Enrolment key', VALUE_DEFAULT, ''),
                'instanceid' => new external_value(PARAM_INT, 'Instance id of self enrolment plugin.', VALUE_DEFAULT, 0),
            ]
        );
    }

    /**
     * Enrols the current user in the given course.
     *
     * @param int $courseid Id of the course.
     * @param string $password Enrolment key.
     * @param int $instanceid Id of the enrolment instance (0 tries every instance of the course).
     * @return array Status and warnings.
     * @throws \moodle_exception
     */
    public static function execute(int $courseid, string $password = '', int $instanceid = 0): array {
        global $CFG;

        require_once($CFG->libdir . '/enrollib.php');

        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'courseid' => $courseid,
                'password' => $password,
                'instanceid' => $instanceid,
            ]
        );

        $warnings = [];

        $course = get_course($params['courseid']);
        $context = \context_course::instance($course->id);
        self::validate_context(\context_system::instance());

        if (!\core_course_category::can_view_course_info($course)) {
            throw new \moodle_exception('coursehidden');
        }

        // Retrieve the self enrolment plugin.
        $enrol = enrol_get_plugin('conditional');
        if (empty($enrol)) {
            throw new \moodle_exception('canntenrol', 'enrol_conditional');
        }

        // We can expect multiple self-enrolment instances.
        $instances = [];
        $enrolinstances = enrol_get_instances($course->id, true);
        foreach ($enrolinstances as $courseenrolinstance) {
            if ($courseenrolinstance->enrol == "conditional") {
                // Instance specified.
                if (!empty($params['instanceid'])) {
                    if ($courseenrolinstance->id == $params['instanceid']) {
                        $instances[] = $courseenrolinstance;
                        break;
                    }
                } else {
                    $instances[] = $courseenrolinstance;
                }
            }
        }
        if (empty($instances)) {
            throw new \moodle_exception('canntenrol', 'enrol_conditional');
        }

        // Try to enrol the user in the instance/s.
        $enrolled = false;
        foreach ($instances as $instance) {
            $enrolstatus = $enrol->can_self_enrol($instance);
            if ($enrolstatus === true) {
                if ($instance->password && $params['password'] !== $instance->password) {
                    // Check if we are using group enrolment keys.
                    if ($instance->customint1) {
                        require_once($CFG->dirroot . "/enrol/conditional/locallib.php");

                        if (!enrol_conditional_check_group_enrolment_key($course->id, $params['password'])) {
                            $warnings[] = [
                                'item' => 'instance',
                                'itemid' => $instance->id,
                                'warningcode' => '2',
                                'message' => get_string('passwordinvalid', 'enrol_conditional'),
                            ];
                            continue;
                        }
                    } else {
                        if ($enrol->get_config('showhint')) {
                            $hint = \core_text::substr($instance->password, 0, 1);
                            $warnings[] = [
                                'item' => 'instance',
                                'itemid' => $instance->id,
                                'warningcode' => '3',
                                // Message is PARAM_TEXT.
                                'message' => s(get_string('passwordinvalidhint', 'enrol_conditional', $hint)),
                            ];
                            continue;
                        } else {
                            $warnings[] = [
                                'item' => 'instance',
                                'itemid' => $instance->id,
                                'warningcode' => '4',
                                'message' => get_string('passwordinvalid', 'enrol_conditional'),
                            ];
                            continue;
                        }
                    }
                }

                // Do the enrolment.
                $data = ['enrolpassword' => $params['password']];
                $enrol->enrol_conditional($instance, (object) $data);
                $enrolled = true;
                break;
            } else {
                $warnings[] = [
                    'item' => 'instance',
                    'itemid' => $instance->id,
                    'warningcode' => '1',
                    'message' => html_to_text((string) $enrolstatus, 0, false),
                ];
            }
        }

        $result = [];
        $result['status'] = $enrolled;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(
            [
                'status' => new external_value(PARAM_BOOL, 'status: true if the user is enrolled, false otherwise'),
                'warnings' => new external_warnings(),
            ]
        );
    }
}
