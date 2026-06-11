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
 * Phase 1 course builder: creates a Moodle course, its sections, and the
 * activity types implemented so far (currently mod_page and mod_url).
 *
 * Activity types not yet implemented (mod_resource, mod_assign, mod_forum,
 * mod_quiz, mod_lti) are counted as skipped in the build report so the admin
 * can see what's still to come.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_builder {
    /** @var int Course category for the new course. */
    private int $categoryid;

    /** @var string Absolute path to the extracted package root. */
    private string $packageroot;

    /**
     * Constructor.
     *
     * @param int $categoryid Target category id.
     * @param string $packageroot Absolute path to the extracted package directory.
     */
    public function __construct(int $categoryid, string $packageroot) {
        $this->categoryid = $categoryid;
        $this->packageroot = rtrim($packageroot, '/');
    }

    /**
     * Create the course, its sections, and the supported activities.
     *
     * @param course_model $coursemodel Parsed package.
     * @return array Build report: courseid, sectioncount, itemcount, created, skipped, warnings.
     */
    public function build(course_model $coursemodel): array {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $fullname = $coursemodel->fullname !== ''
            ? $coursemodel->fullname
            : get_string('defaultcoursename', 'tool_canvasuplifter');
        $shortname = $this->unique_shortname($fullname);

        $courserecord = (object) [
            'category' => $this->categoryid,
            'fullname' => $fullname,
            'shortname' => $shortname,
            'visible' => 0,
            'numsections' => max(1, count($coursemodel->sections)),
        ];
        $course = create_course($courserecord);

        $pagebuilder = new page_builder($this->packageroot);
        $urlbuilder = new url_builder($this->packageroot);

        $createdcounts = [];
        $skippedcounts = [];

        foreach ($coursemodel->sections as $index => $sectionmodel) {
            $sectionnum = $index + 1;
            course_create_sections_if_missing($course, [$sectionnum]);
            $section = get_fast_modinfo($course)->get_section_info($sectionnum);
            if ($sectionmodel->title !== '' && $section) {
                course_update_section($course, $section, ['name' => $sectionmodel->title]);
            }

            foreach ($sectionmodel->items as $modelitem) {
                $cmid = null;
                switch ($modelitem->kind) {
                    case item::KIND_PAGE:
                        $cmid = $pagebuilder->build($course, $sectionnum, $modelitem);
                        break;
                    case item::KIND_URL:
                        $cmid = $urlbuilder->build($course, $sectionnum, $modelitem);
                        break;
                }
                if ($cmid !== null) {
                    $createdcounts[$modelitem->kind] = ($createdcounts[$modelitem->kind] ?? 0) + 1;
                } else {
                    $skippedcounts[$modelitem->kind] = ($skippedcounts[$modelitem->kind] ?? 0) + 1;
                }
            }
        }

        $itemcount = count($coursemodel->all_items());
        $createdtotal = array_sum($createdcounts);
        $skippedtotal = $itemcount - $createdtotal;

        $warnings = [];
        if ($skippedtotal > 0) {
            $warnings[] = get_string('warningskippedfornow', 'tool_canvasuplifter', $skippedtotal);
        }
        if (($skippedcounts[item::KIND_UNKNOWN] ?? 0) > 0) {
            $warnings[] = get_string(
                'warningunclassified',
                'tool_canvasuplifter',
                $skippedcounts[item::KIND_UNKNOWN]
            );
        }

        return [
            'courseid' => (int) $course->id,
            'sectioncount' => count($coursemodel->sections),
            'itemcount' => $itemcount,
            'created' => $createdtotal,
            'createdcounts' => $createdcounts,
            'skipped' => $skippedtotal,
            'skippedcounts' => $skippedcounts,
            'warnings' => $warnings,
        ];
    }

    /**
     * Derive a unique shortname from the course's full name.
     *
     * @param string $fullname Full course name.
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
