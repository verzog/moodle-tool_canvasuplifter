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

namespace tool_canvasuplifter\local\model;

/**
 * One Canvas calendar event parsed from course_settings/events.xml.
 *
 * Plain data holder with no Moodle dependencies, so the events parser can be
 * unit-tested in isolation. Times are already resolved to Unix timestamps by the
 * parser (Canvas stores them as ISO-8601 UTC), so the builder stays thin.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_event {
    /** @var string Event title. */
    public string $title = '';

    /** @var string Event description (HTML), which may carry Canvas link/media tokens. */
    public string $description = '';

    /** @var int Event start, as a Unix timestamp. */
    public int $timestart = 0;

    /** @var int Event duration in seconds (0 for a point-in-time or all-day event). */
    public int $timeduration = 0;

    /** @var bool Whether Canvas marked the event all-day (start pinned to the date's midnight). */
    public bool $allday = false;
}
