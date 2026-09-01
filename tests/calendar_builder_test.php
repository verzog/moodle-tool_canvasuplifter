<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace tool_canvasuplifter;

use tool_canvasuplifter\local\build\calendar_builder;

/**
 * End-to-end test for the calendar-events builder.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\build\calendar_builder
 */
final class calendar_builder_test extends \advanced_testcase {
    /**
     * Write a package holding a course_settings/events.xml with the given event bodies.
     *
     * @param string $body The <event> elements.
     * @return string Path to the package root.
     */
    private function package(string $body): string {
        $dir = make_request_directory();
        mkdir($dir . '/course_settings');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<events xmlns="http://canvas.instructure.com/xsd/cccv1p0">' . $body . '</events>';
        file_put_contents($dir . '/course_settings/events.xml', $xml);
        return $dir;
    }

    /**
     * Two Canvas calendar events import as two course-scoped Moodle calendar events with
     * their titles, start times and durations, tied to the course.
     *
     * @return void
     */
    public function test_build_creates_course_calendar_events(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $dir = $this->package(
            '<event identifier="e1"><title>Midterm review</title>'
            . '<description>&lt;p&gt;Bring questions&lt;/p&gt;</description>'
            . '<start_at>2026-09-15T10:00:00Z</start_at><end_at>2026-09-15T11:00:00Z</end_at>'
            . '<all_day>false</all_day><workflow_state>active</workflow_state></event>'
            . '<event identifier="e2"><title>Reading day</title>'
            . '<all_day>true</all_day><all_day_date>2026-10-01</all_day_date>'
            . '<workflow_state>active</workflow_state></event>'
        );

        $created = (new calendar_builder($dir))->build($course);

        $this->assertSame(2, $created);
        $events = $DB->get_records('event', ['courseid' => (int) $course->id], 'timestart ASC');
        $this->assertCount(2, $events);
        $events = array_values($events);
        foreach ($events as $event) {
            $this->assertSame('course', $event->eventtype);
            $this->assertSame((int) $course->id, (int) $event->courseid);
        }
        $this->assertSame('Midterm review', $events[0]->name);
        $this->assertSame(strtotime('2026-09-15T10:00:00Z'), (int) $events[0]->timestart);
        $this->assertSame(3600, (int) $events[0]->timeduration);
        $this->assertStringContainsString('Bring questions', $events[0]->description);
        $this->assertSame('Reading day', $events[1]->name);
        $this->assertSame(gmmktime(0, 0, 0, 10, 1, 2026), (int) $events[1]->timestart);
        $this->assertSame(0, (int) $events[1]->timeduration);
    }

    /**
     * A package with no events.xml imports nothing and does not error.
     *
     * @return void
     */
    public function test_build_with_no_events_file_is_a_noop(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $this->assertSame(0, (new calendar_builder(make_request_directory()))->build($course));
    }

    /**
     * An event with no usable start time is skipped (counted) rather than placed on the
     * calendar with a zero date.
     *
     * @return void
     */
    public function test_event_without_start_is_skipped(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $dir = $this->package('<event identifier="e1"><title>No date</title>'
            . '<all_day>false</all_day><workflow_state>active</workflow_state></event>');
        $builder = new calendar_builder($dir);

        $this->assertSame(0, $builder->build($course));
        $this->assertSame(1, $builder->skippedcount);
        $this->assertSame(0, $DB->count_records('event', ['courseid' => (int) $course->id]));
    }
}
