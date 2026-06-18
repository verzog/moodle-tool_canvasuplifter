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

    /** @var string Canvas <assignment_group_identifierref>, used to place into a grade category. */
    public string $gradegroupref = '';

    /** @var string Canvas <rubric_identifierref>, foreign key into course_settings/rubrics.xml. */
    public string $rubricref = '';

    /** @var bool Canvas <rubric_use_for_grading>: whether the rubric drives the grade. */
    public bool $rubricforgrading = true;

    /** @var string CC 1.3 IMS assignment-profile <text> body (HTML), or '' for Canvas's flat shape. */
    public string $description = '';

    /** XML namespace marking the IMS Common Cartridge 1.3 assignment profile. */
    private const IMSCC_ASSIGNMENT_NS = 'http://www.imsglobal.org/xsd/imscc_extensions/assignment';

    /** XML namespace for Canvas's per-platform extension fields. */
    private const CANVAS_NS = 'http://canvas.instructure.com/xsd/cccv1p0';

    /**
     * Parse an assignment settings XML string. Handles both Canvas's flat
     * assignment_settings.xml (root <assignment xmlns="canvas...">) and the
     * CC 1.3 IMS Assignment profile (root <assignment xmlns="imscc_extensions/
     * assignment">) where Canvas-specific fields live in an <extensions> child.
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
        // LIBXML_NOCDATA flattens <text><![CDATA[...]]></text> so $doc->text
        // yields the HTML description directly.
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($doc === false) {
            return $settings;
        }

        $namespaces = $doc->getNamespaces(false);
        $rootns = $namespaces[''] ?? '';
        if ($rootns === self::IMSCC_ASSIGNMENT_NS) {
            self::populate_from_imscc_profile($doc, $settings);
        } else {
            $settings->title = trim((string) ($doc->title ?? ''));
            self::apply_canvas_fields($doc, $settings);
        }

        return $settings;
    }

    /**
     * Populate from a CC 1.3 IMS Assignment profile root element: title, HTML
     * description, gradable points, submission formats, then drill into
     * <extensions> for Canvas-specific dates and rubric/group references.
     *
     * @param \SimpleXMLElement $doc Root <assignment> in the IMS profile namespace.
     * @param self $settings Settings to populate in place.
     * @return void
     */
    private static function populate_from_imscc_profile(\SimpleXMLElement $doc, self $settings): void {
        $settings->title = trim((string) ($doc->title ?? ''));
        $settings->description = trim((string) ($doc->text ?? ''));

        if (isset($doc->gradable)) {
            $points = (string) $doc->gradable['points_possible'];
            if ($points !== '') {
                $settings->points = (int) round((float) $points);
            }
            $gradable = filter_var(trim((string) $doc->gradable), FILTER_VALIDATE_BOOLEAN);
            $settings->gradingtype = $gradable && $settings->points > 0 ? 'points' : 'not_graded';
        }

        if (isset($doc->submission_formats)) {
            foreach ($doc->submission_formats->format as $format) {
                $mapped = self::map_submission_format((string) $format['type']);
                if ($mapped !== '' && !in_array($mapped, $settings->submissiontypes, true)) {
                    $settings->submissiontypes[] = $mapped;
                }
            }
        }

        // Drill into <extensions> for the Canvas extension element (in the
        // Canvas namespace) — same flat shape as a stand-alone assignment_settings.xml.
        if (isset($doc->extensions)) {
            foreach ($doc->extensions->children(self::CANVAS_NS) as $ext) {
                if ($ext->getName() !== 'assignment') {
                    continue;
                }
                self::apply_canvas_fields($ext, $settings);
            }
        }
    }

    /**
     * Map a CC 1.3 submission_formats/format @type to a Canvas submission type
     * name the rest of the parser already understands.
     *
     * @param string $format CC 1.3 format type (html, file, url, external_tool).
     * @return string Canvas submission_types name, or '' if not representable.
     */
    private static function map_submission_format(string $format): string {
        return match (strtolower(trim($format))) {
            'html' => 'online_text_entry',
            'file' => 'online_upload',
            'url' => 'online_url',
            default => '',
        };
    }

    /**
     * Apply the Canvas-namespaced flat assignment fields (grading_type, dates,
     * rubric reference, ...) to $settings. Shared between the stand-alone
     * assignment_settings.xml shape and the CC 1.3 <extensions> child.
     *
     * @param \SimpleXMLElement $node Canvas-namespaced <assignment> element.
     * @param self $settings Settings to populate in place.
     * @return void
     */
    private static function apply_canvas_fields(\SimpleXMLElement $node, self $settings): void {
        if (isset($node->grading_type)) {
            $value = trim((string) $node->grading_type);
            if ($value !== '') {
                $settings->gradingtype = $value;
            }
        }
        if (isset($node->points_possible) && trim((string) $node->points_possible) !== '') {
            $settings->points = (int) round((float) $node->points_possible);
        }
        if (isset($node->allowed_extensions)) {
            $settings->allowedextensions = trim((string) $node->allowed_extensions);
        }
        $types = trim((string) ($node->submission_types ?? ''));
        if ($types !== '') {
            foreach (explode(',', $types) as $type) {
                $type = trim($type);
                if ($type !== '' && !in_array($type, $settings->submissiontypes, true)) {
                    $settings->submissiontypes[] = $type;
                }
            }
        }
        if (isset($node->due_at) && trim((string) $node->due_at) !== '') {
            $settings->duedate = self::timestamp((string) $node->due_at);
        }
        if (isset($node->unlock_at) && trim((string) $node->unlock_at) !== '') {
            $settings->allowfrom = self::timestamp((string) $node->unlock_at);
        }
        if (isset($node->lock_at) && trim((string) $node->lock_at) !== '') {
            $settings->cutoff = self::timestamp((string) $node->lock_at);
        }
        if (isset($node->assignment_group_identifierref)) {
            $settings->gradegroupref = trim((string) $node->assignment_group_identifierref);
        }
        if (isset($node->rubric_identifierref)) {
            $settings->rubricref = trim((string) $node->rubric_identifierref);
        }
        // The <rubric_use_for_grading> element is present only when a rubric
        // is attached; default to true so an attached rubric drives the grade
        // unless Canvas explicitly opts out.
        if (isset($node->rubric_use_for_grading)) {
            $settings->rubricforgrading = filter_var((string) $node->rubric_use_for_grading, FILTER_VALIDATE_BOOLEAN);
        }
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
