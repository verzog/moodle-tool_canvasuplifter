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

namespace tool_canvasuplifter\local\parser;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use Exception;
use tool_canvasuplifter\local\model\course_event;

/**
 * Parses a Canvas course_settings/events.xml file into course-event models.
 *
 * Canvas exports each calendar event as an <event> with a title, an HTML
 * description, ISO-8601 start_at/end_at timestamps (UTC), and an all_day flag with
 * an all_day_date. This class is Moodle-free so it can be unit-tested directly from
 * XML strings; it resolves the timestamps to Unix time here so the builder stays a
 * thin Moodle wrapper.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class events_parser {
    /**
     * @var bool Whether the last parse() was handed non-empty content it could not
     *           load as XML (truncated or malformed) — distinct from a file that is
     *           simply empty or has no events, so a caller can warn rather than
     *           treat the events as silently absent.
     */
    public bool $malformed = false;

    /**
     * Parse an events.xml document into course-event models.
     *
     * @param string $xml The events.xml contents.
     * @return array List of {@see course_event}, in document order.
     */
    public function parse(string $xml): array {
        $this->malformed = false;
        $events = [];
        if (trim($xml) === '') {
            return $events;
        }
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            // Non-empty input that won't parse: flag it so the loss is surfaced.
            $this->malformed = true;
            return $events;
        }
        foreach ($dom->getElementsByTagNameNS('*', 'event') as $node) {
            if (!($node instanceof DOMElement)) {
                continue;
            }
            // Skip anything Canvas did not mark active (e.g. deleted events), matching
            // how the rest of the importer treats a non-active workflow_state.
            $state = trim($this->direct_child_text($node, 'workflow_state'));
            if ($state !== '' && $state !== 'active') {
                continue;
            }
            $model = new course_event();
            $model->title = trim($this->direct_child_text($node, 'title'));
            $model->description = trim($this->direct_child_text($node, 'description'));
            $model->allday = $this->is_true($this->direct_child_text($node, 'all_day'));

            $start = $this->timestamp($this->direct_child_text($node, 'start_at'));
            if ($model->allday) {
                // An all-day event has no time-of-day, but Canvas encodes it as local
                // midnight in start_at (a timezone-aware instant), so prefer that to keep
                // the event on the right calendar day for viewers outside UTC. Fall back to
                // all_day_date read as UTC midnight only when start_at is absent.
                $date = trim($this->direct_child_text($node, 'all_day_date'));
                $model->timestart = $start ?? ($date !== '' ? ($this->timestamp($date) ?? 0) : 0);
                $model->timeduration = 0;
            } else {
                $end = $this->timestamp($this->direct_child_text($node, 'end_at'));
                $model->timestart = $start ?? 0;
                // Duration is the gap to end_at when it is after the start; a missing or
                // earlier end is a point-in-time event (zero duration).
                $model->timeduration = ($start !== null && $end !== null && $end > $start) ? $end - $start : 0;
            }

            // Keep any event carrying content — a title, a description, or a resolvable
            // start. An entry with none is empty noise; but one with authored content and
            // no usable start is retained so the builder counts and reports it as skipped
            // rather than dropping it silently here.
            if ($model->title !== '' || $model->description !== '' || $model->timestart > 0) {
                $events[] = $model;
            }
        }
        return $events;
    }

    /**
     * Resolve a Canvas ISO-8601 timestamp (or plain date) to a Unix timestamp. A value
     * with no timezone is read as UTC, matching Canvas's export; an unparseable or empty
     * value yields null so the caller can treat the field as absent.
     *
     * @param string $value The raw timestamp text.
     * @return int|null The Unix timestamp, or null when absent/unparseable.
     */
    private function timestamp(string $value): ?int {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            $datetime = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Exception $e) {
            return null;
        }
        // PHP does not throw on a syntactically plausible but invalid date (e.g. Feb 30 is
        // silently rolled to Mar 2); treat any parse warning/error as an unusable value so a
        // bad timestamp is reported as a skipped event rather than imported on the wrong day.
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
            return null;
        }
        return $datetime->getTimestamp();
    }

    /**
     * Whether a Canvas boolean-ish field reads as true ("true"/"1"/"yes").
     *
     * @param string $value The raw field text.
     * @return bool
     */
    private function is_true(string $value): bool {
        return in_array(strtolower(trim($value)), ['true', '1', 'yes'], true);
    }

    /**
     * Text of the first direct-child element with the given local name, so an event's
     * own field is not confused with a like-named element nested deeper.
     *
     * @param DOMElement $parent The parent element.
     * @param string $name The child local name.
     * @return string The child's text content, or '' when absent.
     */
    private function direct_child_text(DOMElement $parent, string $name): string {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $name) {
                return $child->textContent;
            }
        }
        return '';
    }
}
