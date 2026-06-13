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

/**
 * Reads a Canvas assignment_settings.xml document into a plain value object.
 *
 * Canvas stores an assignment's grading and submission configuration in an
 * assignment_settings.xml file alongside the HTML description. This parser pulls
 * out the fields Moodle's mod_assign cares about. It has no Moodle dependencies
 * so it can be unit-tested directly from XML strings.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignment_settings {
    /** @var string Assignment title. */
    public string $title = '';

    /** @var int Maximum points (0 when not point-graded). */
    public int $points = 0;

    /** @var string Canvas grading type, e.g. "points", "pass_fail", "not_graded". */
    public string $gradingtype = '';

    /** @var string[] Canvas submission types, e.g. ["online_text_entry", "online_upload"]. */
    public array $submissiontypes = [];

    /** @var string Comma-separated allowed file extensions, e.g. "pdf,docx". */
    public string $allowedextensions = '';

    /** @var int Due date as a Unix timestamp (0 when unset). */
    public int $duedate = 0;

    /** @var int Available-from date as a Unix timestamp (0 when unset). */
    public int $allowfrom = 0;

    /** @var int Cut-off (lock) date as a Unix timestamp (0 when unset). */
    public int $cutoff = 0;

    /**
     * Parse an assignment_settings.xml string.
     *
     * @param string $xml The XML document contents.
     * @return self Populated value object (fields stay at defaults on parse failure).
     */
    public static function parse(string $xml): self {
        $settings = new self();
        if (trim($xml) === '') {
            return $settings;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($doc === false) {
            return $settings;
        }

        $settings->title = trim((string) ($doc->title ?? ''));
        $settings->gradingtype = trim((string) ($doc->grading_type ?? ''));
        $settings->points = (int) round((float) ($doc->points_possible ?? 0));
        $settings->allowedextensions = trim((string) ($doc->allowed_extensions ?? ''));

        $types = trim((string) ($doc->submission_types ?? ''));
        if ($types !== '') {
            foreach (explode(',', $types) as $type) {
                $type = trim($type);
                if ($type !== '') {
                    $settings->submissiontypes[] = $type;
                }
            }
        }

        $settings->duedate = self::timestamp((string) ($doc->due_at ?? ''));
        $settings->allowfrom = self::timestamp((string) ($doc->unlock_at ?? ''));
        $settings->cutoff = self::timestamp((string) ($doc->lock_at ?? ''));

        return $settings;
    }

    /**
     * Convert a Canvas date string to a Unix timestamp.
     *
     * @param string $value An ISO-8601-ish date, or empty.
     * @return int Timestamp, or 0 if empty/unparseable.
     */
    private static function timestamp(string $value): int {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $time = strtotime($value);
        return $time !== false ? $time : 0;
    }

    /**
     * Whether Canvas requested online text-entry submissions.
     *
     * @return bool
     */
    public function wants_onlinetext(): bool {
        return in_array('online_text_entry', $this->submissiontypes, true);
    }

    /**
     * Whether Canvas requested file-upload submissions.
     *
     * @return bool
     */
    public function wants_fileupload(): bool {
        return in_array('online_upload', $this->submissiontypes, true);
    }
}
