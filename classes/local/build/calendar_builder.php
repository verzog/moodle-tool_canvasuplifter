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

namespace tool_canvasuplifter\local\build;

use calendar_event;
use stdClass;
use tool_canvasuplifter\local\model\course_event;
use tool_canvasuplifter\local\parser\events_parser;

/**
 * Imports Canvas calendar events as Moodle course calendar events.
 *
 * Canvas ships manual calendar events in course_settings/events.xml. Each becomes a
 * course-scoped {@see calendar_event} (eventtype 'course') tied to the course
 * context, so no user mapping is needed. Descriptions may carry Canvas link/media
 * tokens; media is embedded here and internal links are resolved in the course
 * builder's second pass once every target exists.
 *
 * Assignment/quiz due dates are not in events.xml (Canvas attaches them to the
 * activity), so there is nothing to de-duplicate against the activities' own dates.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class calendar_builder {
    /** @var string Absolute path to the extracted package root. */
    private string $packageroot;

    /** @var int[] Ids of the calendar events created, for the post-build link pass. */
    public array $createdids = [];

    /** @var int Events dropped because they carried no usable start time. */
    public int $skippedcount = 0;

    /** @var bool Whether the events file was present but unreadable as XML. */
    public bool $malformedfile = false;

    /** @var media_report|null Shared collector for unresolved media references. */
    private ?media_report $mediareport;

    /**
     * Constructor.
     *
     * @param string $packageroot Absolute path to the extracted package directory.
     * @param media_report|null $mediareport Shared collector for unresolved media references (null to skip).
     */
    public function __construct(string $packageroot, ?media_report $mediareport = null) {
        $this->packageroot = rtrim($packageroot, '/');
        $this->mediareport = $mediareport;
    }

    /**
     * Create course calendar events from the package's events.xml.
     *
     * @param stdClass $course Course record.
     * @return int Number of events created.
     */
    public function build(stdClass $course): int {
        global $CFG;
        require_once($CFG->dirroot . '/calendar/lib.php');

        $path = $this->packageroot . '/course_settings/events.xml';
        if (!is_readable($path)) {
            return 0;
        }
        $parser = new events_parser();
        $events = $parser->parse((string) @file_get_contents($path));
        $this->malformedfile = $parser->malformed;
        $created = 0;
        foreach ($events as $model) {
            if ($this->create_event($course, $model)) {
                $created++;
            } else {
                // An event with no placeable start time is dropped; count it so the
                // build report can flag the loss.
                $this->skippedcount++;
            }
        }
        return $created;
    }

    /**
     * Create one course calendar event from a model.
     *
     * @param stdClass $course Course record.
     * @param course_event $model The parsed event.
     * @return bool Whether the event was created.
     */
    private function create_event(stdClass $course, course_event $model): bool {
        global $DB;
        if ($model->timestart <= 0) {
            // Without a start time Moodle cannot place the event on the calendar.
            return false;
        }
        $properties = new stdClass();
        $properties->name = $this->event_name($model);
        $properties->description = $model->description;
        $properties->format = FORMAT_HTML;
        $properties->courseid = (int) $course->id;
        $properties->groupid = 0;
        // A course event belongs to the course, not a person; use the course's context
        // by leaving the author as the importing admin (userid 0 is normalised to the
        // current user by calendar_event, which is fine for a course-type event).
        $properties->userid = 0;
        $properties->eventtype = 'course';
        $properties->timestart = $model->timestart;
        $properties->timeduration = max(0, $model->timeduration);
        $properties->visible = 1;
        // Create without capability checks: the import runs as admin and the event is
        // course-scoped, so the normal per-user calendar capability gate does not apply.
        $event = calendar_event::create($properties, false);
        if (!$event) {
            return false;
        }
        $id = (int) $event->id;
        $this->createdids[] = $id;
        // The description may embed package files via $IMS-CC-FILEBASE$ tokens. Course
        // event descriptions are served from the course context's calendar/event_description
        // area keyed by the event id, so import media there and rewrite the tokens to
        // @@PLUGINFILE@@ now that the id exists. Canvas ships events in
        // course_settings/events.xml, so owner-relative media resolves against that folder.
        $context = \context_course::instance((int) $course->id);
        $rewritten = (new file_embedder($this->packageroot, $this->mediareport))
            ->embed($context->id, 'calendar', 'event_description', $model->description, $id, 'course_settings');
        if ($rewritten !== $model->description) {
            $DB->set_field('event', 'description', $rewritten, ['id' => $id]);
        }
        return true;
    }

    /**
     * The event's display name, falling back to a generic label when Canvas exported it
     * without a title, so a titled-less but scheduled event is still placed on the
     * calendar rather than dropped.
     *
     * @param course_event $model The parsed event.
     * @return string
     */
    private function event_name(course_event $model): string {
        $name = trim($model->title);
        $name = $name !== '' ? $name : get_string('eventuntitled', 'tool_canvasuplifter');
        // Moodle's event.name column is 255 chars; a longer Canvas title would overflow it
        // and abort the whole course build, so cap it as the other builders cap their names.
        return shorten_text($name, 255);
    }
}
