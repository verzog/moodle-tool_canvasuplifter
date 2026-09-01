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

use tool_canvasuplifter\local\parser\events_parser;

/**
 * Tests for the calendar-events parser.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\parser\events_parser
 */
final class events_parser_test extends \basic_testcase {
    /**
     * Wrap event bodies in a Canvas events.xml document.
     *
     * @param string $body The <event> elements.
     * @return string
     */
    private function events(string $body): string {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<events xmlns="http://canvas.instructure.com/xsd/cccv1p0">' . $body . '</events>';
    }

    /**
     * A timed event carries its title, description and start, and its duration is the
     * gap between start_at and end_at (UTC).
     *
     * @return void
     */
    public function test_parses_timed_event(): void {
        $xml = $this->events(
            '<event identifier="e1"><title>Midterm review</title>'
            . '<description>&lt;p&gt;Bring questions&lt;/p&gt;</description>'
            . '<start_at>2026-09-15T10:00:00Z</start_at><end_at>2026-09-15T11:30:00Z</end_at>'
            . '<all_day>false</all_day><workflow_state>active</workflow_state></event>'
        );

        $events = (new events_parser())->parse($xml);

        $this->assertCount(1, $events);
        $this->assertSame('Midterm review', $events[0]->title);
        $this->assertSame('<p>Bring questions</p>', $events[0]->description);
        $this->assertSame(strtotime('2026-09-15T10:00:00Z'), $events[0]->timestart);
        $this->assertSame(90 * 60, $events[0]->timeduration);
        $this->assertFalse($events[0]->allday);
    }

    /**
     * An all-day event pins to its all_day_date at midnight UTC with zero duration,
     * ignoring any start_at time-of-day.
     *
     * @return void
     */
    public function test_parses_all_day_event(): void {
        $xml = $this->events(
            '<event identifier="e2"><title>Reading day</title>'
            . '<start_at>2026-10-01T13:00:00Z</start_at><end_at>2026-10-01T14:00:00Z</end_at>'
            . '<all_day>true</all_day><all_day_date>2026-10-01</all_day_date>'
            . '<workflow_state>active</workflow_state></event>'
        );

        $events = (new events_parser())->parse($xml);

        $this->assertCount(1, $events);
        $this->assertTrue($events[0]->allday);
        $this->assertSame(gmmktime(0, 0, 0, 10, 1, 2026), $events[0]->timestart);
        $this->assertSame(0, $events[0]->timeduration);
    }

    /**
     * A missing/earlier end gives a point-in-time event (zero duration), and a value with
     * no timezone is read as UTC.
     *
     * @return void
     */
    public function test_point_in_time_event_has_zero_duration(): void {
        $xml = $this->events(
            '<event identifier="e3"><title>Office hours start</title>'
            . '<start_at>2026-09-20T09:00:00</start_at><all_day>false</all_day></event>'
        );

        $events = (new events_parser())->parse($xml);

        $this->assertCount(1, $events);
        $this->assertSame(gmmktime(9, 0, 0, 9, 20, 2026), $events[0]->timestart);
        $this->assertSame(0, $events[0]->timeduration);
    }

    /**
     * A non-active (e.g. deleted) event is skipped rather than imported.
     *
     * @return void
     */
    public function test_skips_non_active_event(): void {
        $xml = $this->events(
            '<event identifier="e4"><title>Cancelled talk</title>'
            . '<start_at>2026-09-15T10:00:00Z</start_at>'
            . '<workflow_state>deleted</workflow_state></event>'
        );

        $this->assertSame([], (new events_parser())->parse($xml));
    }

    /**
     * Non-empty content that will not parse as XML is flagged malformed (so the loss is
     * surfaced) rather than treated as simply having no events.
     *
     * @return void
     */
    public function test_malformed_xml_is_flagged(): void {
        $parser = new events_parser();

        $this->assertSame([], $parser->parse('<events><event><title>Broken'));
        $this->assertTrue($parser->malformed);

        $parser->parse($this->events('<event identifier="e5"><title>Fine</title>'
            . '<start_at>2026-09-15T10:00:00Z</start_at></event>'));
        $this->assertFalse($parser->malformed);
    }
}
