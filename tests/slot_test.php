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
 * File containing tests for slot.
 *
 * @package     mod_room
 * @category    test
 * @copyright   2019 Leo Auri <code@leoauri.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_room\entity\slot;

defined('MOODLE_INTERNAL') || die();
require_once('mod_room_test_base.php');

/**
 * The slot test class.
 *
 * @package    mod_room
 * @copyright  2019 Leo Auri <code@leoauri.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_room_slot_testcase extends mod_room_test_base {
    /**
     * Old records have an event but no room_slot record. We create a new room_slot record 
     * when saving the slot
     */
    public function test_save() {

        // Create just calendar event like in mod_room v1.1.1
        $event = calendar_event::create((object)[
            'courseid' => $this->course->id,
            'instance' => $this->roomplan->id,
            'timestart' => time(),
            'timeduration' => 60 * 60,
            'name' => 'Old slot',
            'location' => 'Invalid room',
            'modulename' => 'room',
            'groupid' => 0,
            'userid' => 0,
            'type' => CALENDAR_EVENT_TYPE_STANDARD,
            'eventtype' => ROOM_EVENT_TYPE_SLOT
        ], false);

        $slot = new slot($event->id);

        $this->assertEquals($slot->name, 'Old slot');

        $formproperties = $slot->form_properties();

        $formproperties->spots = 2;

        $slot->set_slot_properties($formproperties, $this->roomplan);
        $slot->save();

        $slot = new slot($event->id);

        $this->assertEquals($slot->spots, 2);
    }

    protected function assertSlotsPropertiesEqual($expectedslot, $actualslot) {
        foreach (['spots', 'timestart', 'timeduration', 'name', 'location'] as $property) {
            $this->assertEquals(
                $expectedslot->$property, 
                $actualslot->$property, 
                "Property $property was not equal."
            );
        }
    }

    public function test_save_as_new_properties() {
        $slotsettings = (object)[
            'courseid' => $this->course->id,
            'instance' => $this->roomplan->id,
            'starttime' => time(),
            'duration' => [
                'hours' => 2,
                'minutes' => 40
            ],
            'spots' => 3,
            'slottitle' => 'Best ever event',
            'room' => $this->roomspace->id
        ];
        $originalslot = new slot();
        $originalslot->set_slot_properties($slotsettings, $this->roomplan);
        $originalslot->save();

        $loadedslot = new slot($originalslot->id);

        $this->assertSlotsPropertiesEqual($originalslot, $loadedslot);
        $this->assertEquals($originalslot->id, $loadedslot->id);
        $this->assertEquals($originalslot->slotid, $loadedslot->slotid);

        $clonedslot = clone $loadedslot;
        $clonedslot->save_as_new();

        $this->assertSlotsPropertiesEqual($loadedslot, $clonedslot);
        $this->assertNotEquals($loadedslot->id, $clonedslot->id);
        $this->assertNotEquals($loadedslot->slotid, $clonedslot->slotid);
    }

    public function test_prepare_display_id_mismatch() {
        // There could be a mismatch between eventid and slotid
        // Test that prepare display links to the slotid
        // Old style calendar event like in mod_room v1.1.1
        calendar_event::create((object)[
            'courseid' => $this->course->id,
            'instance' => $this->roomplan->id,
            'timestart' => time(),
            'timeduration' => 60 * 60,
            'name' => 'Old slot',
            'location' => 'Invalid room',
            'modulename' => 'room',
            'groupid' => 0,
            'userid' => 0,
            'type' => CALENDAR_EVENT_TYPE_STANDARD,
            'eventtype' => ROOM_EVENT_TYPE_SLOT
        ], false);

        $slotsettings = (object)[
            'courseid' => $this->course->id,
            'instance' => $this->roomplan->id,
            'starttime' => time(),
            'duration' => [
                'hours' => 1,
                'minutes' => 0
            ],
            'spots' => 2,
            'slottitle' => 'wonderful event',
            'room' => $this->roomspace->id
        ];
        $newslot = new slot();
        $newslot->set_slot_properties($slotsettings, $this->roomplan);
        $newslot->save();

        $loadedslot = new slot(['slotid' => $newslot->slotid]);
        $this->assertNotEquals($loadedslot->slotid, $loadedslot->id);

        $loadedslot->prepare_display(context_module::instance($this->roomplan->cmid));
        $this->assertEquals($loadedslot->slotid, $loadedslot->spotbooking->action->get_param('slotid'));
    }

    public function test_prepare_display() {
        $now = new DateTimeImmutable();
        $slotsettings = (object)[
            'courseid' => $this->course->id,
            'instance' => $this->roomplan->id,
            'starttime' => $now->add(new DateInterval('P1M'))->getTimestamp(),
            'slottitle' => 'Slot with spot',
            'room' => $this->roomspace->id
        ];
        $slot = new slot();
        $slot->set_slot_properties($slotsettings, $this->roomplan);

        // test no spotbooking without spots
        $slot->prepare_display(context_module::instance($this->roomplan->cmid));

        $this->assertNull($slot->spotbooking);

        // test that spotbooking when there is a free spot
        $slot->spots = 1;
        $slot->save();
        $slot = new slot($slot->id);
        $slot->prepare_display(context_module::instance($this->roomplan->cmid));

        $this->assertNotNull($slot->spotbooking);

        $this->assertEquals('Book spot', $slot->spotbooking->message);
        $this->assertEquals('/moodle/mod/room/spotbook.php', $slot->spotbooking->action->get_path());

        // test that spotbooking when there are free spots
        $slot->spots = 2;
        $slot->save();
        $slot = new slot($slot->id);
        $slot->prepare_display(context_module::instance($this->roomplan->cmid));

        $this->assertNotNull($slot->spotbooking);

        $this->assertEquals('Book spot', $slot->spotbooking->message);
        $this->assertEquals('/moodle/mod/room/spotbook.php', $slot->spotbooking->action->get_path());

        // test that "cancel booking" when user has already booked and slot is in future
        $slot->save();

        $user = $this->datagenerator->create_user();
        $this->setUser($user);

        $slot->prepare_display(context_module::instance($this->roomplan->cmid));
        $this->assertEquals(2, $slot->bookingsfree);
        $this->assertNotNull($slot->spotbooking);

        $slot->new_booking($user->id);

        $slot->prepare_display(context_module::instance($this->roomplan->cmid));
        $this->assertEquals(1, $slot->bookingsfree);
        $this->assertEquals('Cancel booking', $slot->spotbooking->message);
        $this->assertEquals('/moodle/mod/room/spotbook.php', $slot->spotbooking->action->get_path());
        $this->assertEquals('cancel', $slot->spotbooking->action->get_param('action'));

        // test that null spotbooking when user has already booked and slot is in past
        $slotsettings = (object)[
            'courseid' => $this->course->id,
            'instance' => $this->roomplan->id,
            'starttime' => $now->sub(new DateInterval('P1M'))->getTimestamp(),
            'slottitle' => 'Slot with spot',
            'room' => $this->roomspace->id,
            'spots' => 2,
        ];
        $slot = new slot();
        $slot->set_slot_properties($slotsettings, $this->roomplan);
        $slot->save();
        $slot = new slot($slot->id);
        $slot->new_booking($user->id);
        $slot->prepare_display(context_module::instance($this->roomplan->cmid));

        $this->assertNull($slot->spotbooking);

        // test that null spotbooking when slot is in past
        // test that no spotbooking when user does not have capability
        // TODO: test that spotbooking when some spots are booked
        // TODO: test that no spotbooking when all spots are booked
    }

    public function test_prepare_display_context_prefix() {
        // when slot is from outside context tree, name should be prefixed with context
        $enclosingcategory = $this->datagenerator->create_category(['name' => 'enclosing cat']);
        $enclosedcourse1 = $this->datagenerator->create_course(['category' => $enclosingcategory->id]);
        $enclosedcourse2 = $this->datagenerator->create_course(['category' => $enclosingcategory->id]);
        $othercourse = $this->datagenerator->create_course();

        $ec1roomplan = $this->datagenerator->create_module('room', ['course' => $enclosedcourse1->id]);
        $ec2roomplan = $this->datagenerator->create_module('room', ['course' => $enclosedcourse2->id]);
        $ocroomplan = $this->datagenerator->create_module('room', ['course' => $othercourse->id]);

        $slotsettings = (object)[
            'courseid' => $enclosedcourse1->id,
            'instance' => $ec1roomplan->id,
            'starttime' => (new DateTime('2023-07-17 18:00'))->getTimestamp(),
            'slottitle' => 'ec1 slot',
            'room' => $this->roomspace->id,
            'context' => context_coursecat::instance($enclosingcategory->id)->id
        ];

        $slot = new slot();
        $slot->set_slot_properties($slotsettings, $ec1roomplan);
        $slot->save();

        $slot = new slot($slot->id);
        $slot->prepare_display(context_module::instance($ocroomplan->cmid));
        $this->assertEquals('enclosing cat: ec1 slot', $slot->displayname);

        // when slot is from enclosing course category, no context prefix
        $slot = new slot($slot->id);
        $slot->prepare_display(context_module::instance($ec1roomplan->cmid));
        $this->assertEquals('ec1 slot', $slot->displayname);

        $slot = new slot($slot->id);
        $slot->prepare_display(context_module::instance($ec2roomplan->cmid));
        $this->assertEquals('ec1 slot', $slot->displayname);
    }

    public function test_constructor() {
        // check that constructing from slotid works and returns saved values
        $slotsettings = (object)[
            'courseid' => $this->course->id,
            'instance' => $this->roomplan->id,
            'starttime' => time(),
            'duration' => [
                'hours' => 1,
                'minutes' => 0
            ],
            'spots' => 2,
            'slottitle' => 'wonderful event',
            'room' => $this->roomspace->id
        ];
        $slot = new slot();
        $slot->set_slot_properties($slotsettings, $this->roomplan);
        $slot->save();

        $loadedslot = new slot(['slotid' => $slot->slotid]);

        $this->assertEquals($slot->timestart, $loadedslot->timestart, 'timestart not loaded.');
        $this->assertEquals(60 * 60, $loadedslot->timeduration);
        $this->assertEquals(2, $loadedslot->spots);
        $this->assertEquals('wonderful event', $loadedslot->name);
    }

    public function test_new_booking() {
        $slotsettings = (object)[
            'courseid' => $this->course->id,
            'instance' => $this->roomplan->id,
            'starttime' => time(),
            'duration' => [
                'hours' => 1,
                'minutes' => 0
            ],
            'spots' => 1,
            'slottitle' => 'wonderful event',
            'room' => $this->roomspace->id
        ];
        $slot = new slot();
        $slot->set_slot_properties($slotsettings, $this->roomplan);
        $slot->save();

        $slot = new slot($slot->id);
        $slot->prepare_display(context_module::instance($this->roomplan->cmid));
        $this->assertNotNull($slot->spotbooking);
        
        $user = $this->datagenerator->create_user();
        $slot->new_booking($user->id);

        $slot->prepare_display(context_module::instance($this->roomplan->cmid));
        $this->assertNull($slot->spotbooking);

        // Check that booking is loaded correctly
        $loadedslot = new slot(['slotid' => $slot->slotid]);
        $loadedslot->prepare_display(context_module::instance($this->roomplan->cmid));
        $this->assertNull($loadedslot->spotbooking);

        // check the slot with all bookings taken cannot be booked again, even by another user
        $anotheruser = $this->datagenerator->create_user();
        $this->expectException('moodle_exception');
        $loadedslot->new_booking($anotheruser->id);
    }

    public function test_delete() {
        $slotsettings = (object)[
            'courseid' => $this->course->id,
            'instance' => $this->roomplan->id,
            'starttime' => time(),
            'duration' => [
                'hours' => 1,
                'minutes' => 0
            ],
            'spots' => 1,
            'slottitle' => 'wonderful event',
            'room' => $this->roomspace->id
        ];
        $slot = new slot();
        $slot->set_slot_properties($slotsettings, $this->roomplan);
        $slot->save();

        // Test that both event and room_slot records are removed when deleting
        $eventid = $slot->id;
        $slotid = $slot->slotid;

        $slot = new slot($eventid);

        global $DB;
        $this->assertNotFalse($DB->get_record('event', ['id' => $eventid]));
        $this->assertNotFalse($DB->get_record('room_slot', ['id' => $slotid]));

        $slot->delete();

        $this->assertFalse($DB->get_record('event', ['id' => $eventid]));
        $this->assertFalse($DB->get_record('room_slot', ['id' => $slotid]));
    }

    public function test_modify_starttime() {
        $slotsettings = (object)[
            'courseid' => $this->course->id,
            'instance' => $this->roomplan->id,
            'starttime' => 1593561600,
            'duration' => [
                'hours' => 1,
                'minutes' => 0
            ],
            'spots' => 1,
            'slottitle' => 'wonderful event',
            'room' => $this->roomspace->id
        ];
        $slot = new slot();
        $slot->set_slot_properties($slotsettings, $this->roomplan);

        $slot->modify_starttime('+60 seconds');
        $this->assertEquals(1593561660, $slot->timestart);

        $slot->modify_starttime('+4 hours');
        $this->assertEquals(1593576060, $slot->timestart);

        $slot->modify_starttime('+1 day');
        $this->assertEquals(1593662460, $slot->timestart);
    }
}
