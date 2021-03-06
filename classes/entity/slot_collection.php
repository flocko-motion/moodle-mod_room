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
 * Room module slot collection class.
 *
 * @package     mod_room
 * @copyright   2019 Leo Auri <code@leoauri.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_room\entity;

defined('MOODLE_INTERNAL') || die();

use \mod_room\entity\slot;

/**
 * Room module slot collection.
 *
 * @package    mod_room
 * @copyright  2019 Leo Auri <code@leoauri.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class slot_collection implements \IteratorAggregate, \Countable {
    /**
     * @var array collection of slots
     */
    protected $slots;

    public function __construct(array $slots = []) {
        $this->slots = $slots;
    }

    public static function retrieve(array $options) {
        // Convert any DateTime objects to timestamps
        foreach (['start', 'end'] as $timeoption) {
            if (isset($options[$timeoption]) && $options[$timeoption] instanceof \DateTime) {
                $options[$timeoption] = $options[$timeoption]->getTimestamp();
            }
        }
        // FIX: also return in-progress events, i.e. that start before day and haven't ended  :|
        $sql = "SELECT e.id 
            FROM {event} e
            LEFT JOIN {room_slot} rs ON e.id = rs.eventid
            WHERE e.modulename = 'room'";
        $searchoptions = [];

        if (isset($options['start'])) {
            $sql .= ' AND e.timestart >= :start';
            $searchoptions['start'] = $options['start'];
        }

        if (isset($options['end'])) {
            $sql .= ' AND e.timestart <= :end';
            $searchoptions['end'] = $options['end'];
        }

        if (isset($options['instance'])) {
            $sql .= ' AND e.instance = :instance';
            $searchoptions['instance'] = $options['instance'];
        }

        // Limit results to slots in the context tree or events in the course with no context set
        if (isset($options['contextsandcourse'])) {
            $sql .= ' AND (rs.contextid IN (' . 
                implode(',', $options['contextsandcourse']['contexts']) . 
                ') OR rs.contextid IS NULL AND e.courseid = :courseid)';
            $searchoptions['courseid'] = $options['contextsandcourse']['courseid'];
        }

        // Order by start time
        $sql .= ' ORDER BY e.timestart';

        global $DB;

        // TODO: make this an array of slot objects
        $results = array_values($DB->get_records_sql($sql, $searchoptions));
        $slots = [];
        foreach ($results as $result) {
            $slots[] = new slot((int)$result->id);
        }

        return new slot_collection($slots);
    }

    public static function duplicates_consecutive(slot $origslot, int $n_duplicates) {
        if ($n_duplicates < 1) {
            return new slot_collection();
        }

        $duplicateslots = [];
        for ($i = 0; $i < $n_duplicates; $i++) {
            $newslot = clone $origslot;

            $timemodifier = ($i + 1) * $origslot->timeduration;
            $newslot->modify_starttime("+$timemodifier seconds");
            
            $duplicateslots[] = $newslot;
        }

        return new slot_collection($duplicateslots);
    }

    public function prepare_display(\context_module $modulecontext) {
        foreach ($this->slots as &$slot) {
            $slot->prepare_display($modulecontext);
        }
        unset($slot);
    }

    public function getIterator() {
        foreach ($this->slots as $slot) {
            yield $slot;
        }
    }

    public function count() {
        return count($this->slots);
    }

    public function save_all_as_new() {
        foreach ($this->slots as $slot) {
            $slot->save_as_new();
        }
    }

    public function get_used_locations() {
        $locations = [];
        foreach ($this->slots as $slot) {
            array_push($locations, $slot->location);
        }
        return array_unique($locations);
    }

    public function slots_for_location($location) {
        $returnslots = [];
        foreach ($this->slots as $slot) {
            if ($slot->location == $location) {
                array_push($returnslots, $slot);
            }
        }
        return $returnslots;
    }
}