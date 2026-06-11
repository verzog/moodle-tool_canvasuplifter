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

use tool_canvasuplifter\local\model\course_model;
use tool_canvasuplifter\local\model\item;

/**
 * Phase 1 course builder: creates a Moodle course and its sections from a parsed
 * Canvas package, and stubs counts for each content kind it sees.
 *
 * Activity creation (page, file, URL, assignment) and link rewriting are added
 * in follow-up patches; this scaffold establishes the course/section structure
 * and the build-report shape that the status page reads.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_builder {
    /** @var int Course category for the new course. */
    private int $categoryid;

    /**
     * @param int $categoryid Target category id.
     */
    public function __construct(int $categoryid) {
        $this->categoryid = $categoryid;
    }

    /**
     * Create the course and its sections.
     *
     * @param course_model $coursemodel Parsed package.
     * @return array{courseid: int, sectioncount: int, itemcount: int, skipped: int, warnings: string[]}
     */
    public function build(course_model $coursemodel): array {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $fullname = $coursemodel->fullname !== '' ? $coursemodel->fullname : get_string('defaultcoursename', 'tool_canvasuplifter');
        $shortname = $this->unique_shortname($fullname);

        $courserecord = (object) [
            'category' => $this->categoryid,
            'fullname' => $fullname,
            'shortname' => $shortname,
            'visible' => 0,
            'numsections' => max(1, count($coursemodel->sections)),
        ];
        $course = create_course($courserecord);

        // Walk the parsed sections and rename each section as it is created.
        foreach ($coursemodel->sections as $index => $sectionmodel) {
            $sectionnum = $index + 1;
            course_create_sections_if_missing($course, [$sectionnum]);
            $section = get_fast_modinfo($course)->get_section_info($sectionnum);
            if ($sectionmodel->title !== '' && $section) {
                course_update_section($course, $section, [
                    'name' => $sectionmodel->title,
                ]);
            }
        }

        // Counts for the build report. Actual activity creation will land in
        // follow-up patches; for now everything except "section" counts as
        // skipped-for-now so admins know nothing in the course yet.
        $itemcount = count($coursemodel->all_items());
        $kindcounts = [];
        foreach ($coursemodel->all_items() as $modelitem) {
            $kindcounts[$modelitem->kind] = ($kindcounts[$modelitem->kind] ?? 0) + 1;
        }

        $warnings = [];
        if ($itemcount > 0) {
            $warnings[] = get_string('warningnoactivitiesyet', 'tool_canvasuplifter');
        }
        if (($kindcounts[item::KIND_UNKNOWN] ?? 0) > 0) {
            $warnings[] = get_string('warningunclassified', 'tool_canvasuplifter', $kindcounts[item::KIND_UNKNOWN]);
        }

        return [
            'courseid' => (int) $course->id,
            'sectioncount' => count($coursemodel->sections),
            'itemcount' => $itemcount,
            'skipped' => $itemcount,
            'warnings' => $warnings,
        ];
    }

    /**
     * Derive a unique shortname from the course's full name.
     *
     * @param string $fullname
     * @return string
     */
    private function unique_shortname(string $fullname): string {
        global $DB;
        $base = clean_param(substr($fullname, 0, 80), PARAM_TEXT);
        if ($base === '') {
            $base = 'canvas-import';
        }
        $candidate = $base;
        $suffix = 1;
        while ($DB->record_exists('course', ['shortname' => $candidate])) {
            $suffix++;
            $candidate = $base . ' ' . $suffix;
        }
        return $candidate;
    }
}
