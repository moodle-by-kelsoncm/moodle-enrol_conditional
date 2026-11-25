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

/**
 * Tests of the external function enrol_user.
 *
 * @package   enrol_conditional
 * @copyright 2013 Rajesh Taneja <rajesh@moodle.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(enrol_user::class)]
final class enrol_user_test extends \core_external\tests\externallib_testcase {
    /**
     * Enables the plugin and default instances, which a new site does not have for this plugin.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        \core\plugininfo\enrol::enable_plugin('conditional', true);
        set_config('defaultenrol', 1, 'enrol_conditional');
    }
    /**
     * Test the web service function enrol_conditional_enrol_user.
     */
    public function test_enrol_user(): void {
        global $DB;

        self::resetAfterTest(true);

        $user = self::getDataGenerator()->create_user();
        self::setUser($user);

        $course1 = self::getDataGenerator()->create_course();
        $course2 = self::getDataGenerator()->create_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $user1 = self::getDataGenerator()->create_user();
        $user2 = self::getDataGenerator()->create_user();
        $user3 = self::getDataGenerator()->create_user();
        $user4 = self::getDataGenerator()->create_user();

        $context1 = \context_course::instance($course1->id);
        $context2 = \context_course::instance($course2->id);

        $selfplugin = enrol_get_plugin('conditional');
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $instance1id = $selfplugin->add_instance($course1, ['status' => ENROL_INSTANCE_ENABLED,
                                                                'name' => 'Test instance 1',
                                                                'customint6' => 1,
                                                                'roleid' => $studentrole->id]);
        $instance2id = $selfplugin->add_instance($course2, ['status' => ENROL_INSTANCE_DISABLED,
                                                                'customint6' => 1,
                                                                'name' => 'Test instance 2',
                                                                'roleid' => $studentrole->id]);
        $instance1 = $DB->get_record('enrol', ['id' => $instance1id], '*', MUST_EXIST);
        $instance2 = $DB->get_record('enrol', ['id' => $instance2id], '*', MUST_EXIST);

        self::setUser($user1);

        // Self enrol me.
        $result = enrol_user::execute($course1->id);
        $result = external_api::clean_returnvalue(enrol_user::execute_returns(), $result);

        self::assertTrue($result['status']);
        self::assertEquals(1, $DB->count_records('user_enrolments', ['enrolid' => $instance1->id]));
        self::assertTrue(is_enrolled($context1, $user1));

        // Add password.
        $instance2->password = 'abcdef';
        $DB->update_record('enrol', $instance2);

        // Try instance not enabled.
        try {
            enrol_user::execute($course2->id);
        } catch (\moodle_exception $e) {
            self::assertEquals('canntenrol', $e->errorcode);
        }

        // Enable the instance.
        $selfplugin->update_status($instance2, ENROL_INSTANCE_ENABLED);

        // Try not passing a key.
        $result = enrol_user::execute($course2->id);
        $result = external_api::clean_returnvalue(enrol_user::execute_returns(), $result);
        self::assertFalse($result['status']);
        self::assertCount(1, $result['warnings']);
        self::assertEquals('4', $result['warnings'][0]['warningcode']);

        // Try passing an invalid key.
        $result = enrol_user::execute($course2->id, 'invalidkey');
        $result = external_api::clean_returnvalue(enrol_user::execute_returns(), $result);
        self::assertFalse($result['status']);
        self::assertCount(1, $result['warnings']);
        self::assertEquals('4', $result['warnings'][0]['warningcode']);

        // Try passing an invalid key with hint.
        $selfplugin->set_config('showhint', true);
        $result = enrol_user::execute($course2->id, 'invalidkey');
        $result = external_api::clean_returnvalue(enrol_user::execute_returns(), $result);
        self::assertFalse($result['status']);
        self::assertCount(1, $result['warnings']);
        self::assertEquals('3', $result['warnings'][0]['warningcode']);

        // Everything correct, now.
        $result = enrol_user::execute($course2->id, 'abcdef');
        $result = external_api::clean_returnvalue(enrol_user::execute_returns(), $result);

        self::assertTrue($result['status']);
        self::assertEquals(1, $DB->count_records('user_enrolments', ['enrolid' => $instance2->id]));
        self::assertTrue(is_enrolled($context2, $user1));

        // Try group password now, other user.
        $instance2->customint1 = 1;
        $instance2->password = 'zyx';
        $DB->update_record('enrol', $instance2);

        $group1 = $this->getDataGenerator()->create_group(['courseid' => $course2->id]);
        $group2 = $this->getDataGenerator()->create_group(['courseid' => $course2->id, 'enrolmentkey' => 'zyx']);

        self::setUser($user2);
        // Try passing and invalid key for group.
        $result = enrol_user::execute($course2->id, 'invalidkey');
        $result = external_api::clean_returnvalue(enrol_user::execute_returns(), $result);
        self::assertFalse($result['status']);
        self::assertCount(1, $result['warnings']);
        self::assertEquals('2', $result['warnings'][0]['warningcode']);

        // Now, everything ok.
        $result = enrol_user::execute($course2->id, 'zyx');
        $result = external_api::clean_returnvalue(enrol_user::execute_returns(), $result);

        self::assertTrue($result['status']);
        self::assertEquals(2, $DB->count_records('user_enrolments', ['enrolid' => $instance2->id]));
        self::assertTrue(is_enrolled($context2, $user2));

        // Try multiple instances now, multiple errors.
        $instance3id = $selfplugin->add_instance($course2, ['status' => ENROL_INSTANCE_ENABLED,
                                                                'customint6' => 1,
                                                                'name' => 'Test instance 2',
                                                                'roleid' => $studentrole->id]);
        $instance3 = $DB->get_record('enrol', ['id' => $instance3id], '*', MUST_EXIST);
        $instance3->password = 'abcdef';
        $DB->update_record('enrol', $instance3);

        self::setUser($user3);
        $result = enrol_user::execute($course2->id, 'invalidkey');
        $result = external_api::clean_returnvalue(enrol_user::execute_returns(), $result);
        self::assertFalse($result['status']);
        self::assertCount(2, $result['warnings']);

        // Now, everything ok.
        $result = enrol_user::execute($course2->id, 'zyx');
        $result = external_api::clean_returnvalue(enrol_user::execute_returns(), $result);
        self::assertTrue($result['status']);
        self::assertTrue(is_enrolled($context2, $user3));

        // Now test passing an instance id.
        self::setUser($user4);
        $result = enrol_user::execute($course2->id, 'abcdef', $instance3id);
        $result = external_api::clean_returnvalue(enrol_user::execute_returns(), $result);
        self::assertTrue($result['status']);
        self::assertTrue(is_enrolled($context2, $user3));
        self::assertCount(0, $result['warnings']);
        self::assertEquals(1, $DB->count_records('user_enrolments', ['enrolid' => $instance3->id]));
    }
}
