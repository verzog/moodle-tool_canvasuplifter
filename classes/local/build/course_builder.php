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

use tool_canvasuplifter\local\job_manager;
use tool_canvasuplifter\local\model\course_model;
use tool_canvasuplifter\local\model\item;

/**
 * Phase 1 course builder: creates a Moodle course, its sections, and the
 * activity types implemented so far (mod_page, mod_url, mod_resource).
 *
 * Activity types not yet implemented (mod_assign, mod_forum, mod_quiz,
 * mod_lti) are counted as skipped in the build report so the admin can see
 * what's still to come.
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

    /** @var job_manager|null Used to report progress; null in tests. */
    private ?job_manager $jobs;

    /** @var int Job id whose progress this builder reports against. */
    private int $jobid;

    /**
     * Constructor.
     *
     * @param int $categoryid Target category id.
     * @param string $packageroot Absolute path to the extracted package directory.
     * @param job_manager|null $jobs Optional, used to report progress.
     * @param int $jobid Job id, required when $jobs is set.
     */
    public function __construct(
        int $categoryid,
        string $packageroot,
        ?job_manager $jobs = null,
        int $jobid = 0
    ) {
        $this->categoryid = $categoryid;
        $this->packageroot = rtrim($packageroot, '/');
        $this->jobs = $jobs;
        $this->jobid = $jobid;
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
            'format' => 'topics',
            'numsections' => max(1, count($coursemodel->sections)),
        ];
        $course = create_course($courserecord);

        // Re-fetch the course so we have every field add_moduleinfo() looks at
        // (in particular 'format'); the object returned by create_course is
        // built from the input record and can be missing defaults.
        global $DB;
        $course = $DB->get_record('course', ['id' => $course->id], '*', MUST_EXIST);

        $this->report_progress(5, get_string('progresscoursecreated', 'tool_canvasuplifter'));

        $pagebuilder = new page_builder($this->packageroot);
        $urlbuilder = new url_builder($this->packageroot);
        $filebuilder = new file_builder($this->packageroot);

        $createdcounts = [];
        $skippedcounts = [];
        $skipreasons = [];
        $totalitems = max(1, count($coursemodel->all_items()));
        $processed = 0;

        foreach ($coursemodel->sections as $index => $sectionmodel) {
            $sectionnum = $index + 1;
            course_create_sections_if_missing($course, [$sectionnum]);
            $section = get_fast_modinfo($course)->get_section_info($sectionnum);
            if ($sectionmodel->title !== '' && $section) {
                course_update_section($course, $section, ['name' => $sectionmodel->title]);
            }

            foreach ($sectionmodel->items as $modelitem) {
                $cmid = null;
                try {
                    switch ($modelitem->kind) {
                        case item::KIND_PAGE:
                            $cmid = $pagebuilder->build($course, $sectionnum, $modelitem);
                            break;
                        case item::KIND_URL:
                            $cmid = $urlbuilder->build($course, $sectionnum, $modelitem);
                            break;
                        case item::KIND_FILE:
                            $cmid = $filebuilder->build($course, $sectionnum, $modelitem);
                            break;
                    }
                } catch (\Throwable $e) {
                    $msg = sprintf(
                        'failed to build %s "%s": %s',
                        $modelitem->kind,
                        $modelitem->title,
                        $e->getMessage()
                    );
                    mtrace('tool_canvasuplifter: ' . $msg);
                    $skipreasons[] = $msg;
                    $cmid = null;
                }
                if ($cmid === null && !in_array($modelitem->kind, [
                    item::KIND_ASSIGNMENT, item::KIND_DISCUSSION, item::KIND_QUIZ,
                    item::KIND_QUESTIONBANK, item::KIND_LTI, item::KIND_UNKNOWN,
                ], true)) {
                    // The kind has a builder but it returned null.
                    $skipreasons[] = sprintf(
                        '%s "%s" (id=%s) — builder could not find payload; href="%s" files=[%s]',
                        $modelitem->kind,
                        $modelitem->title,
                        $modelitem->identifier,
                        $modelitem->href,
                        implode(',', $modelitem->files)
                    );
                }
                if ($cmid !== null) {
                    $createdcounts[$modelitem->kind] = ($createdcounts[$modelitem->kind] ?? 0) + 1;
                } else {
                    $skippedcounts[$modelitem->kind] = ($skippedcounts[$modelitem->kind] ?? 0) + 1;
                }
                $processed++;
                $percent = 5 + (int) round(90 * $processed / $totalitems);
                $this->report_progress($percent, get_string('progressitem', 'tool_canvasuplifter', [
                    'done' => $processed,
                    'total' => $totalitems,
                    'kind' => $modelitem->kind,
                ]));
            }
        }

        $itemcount = count($coursemodel->all_items());
        $createdtotal = array_sum($createdcounts);
        $skippedtotal = $itemcount - $createdtotal;

        // Make sure section caches reflect everything we just built.
        rebuild_course_cache($course->id, true);

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
            'skipreasons' => array_slice($skipreasons, 0, 50),
            'warnings' => $warnings,
        ];
    }

    /**
     * Forward a progress update to the job_manager, if one was supplied.
     *
     * @param int $percent 0-100.
     * @param string $message Short status message.
     * @return void
     */
    private function report_progress(int $percent, string $message): void {
        if ($this->jobs !== null && $this->jobid > 0) {
            $this->jobs->set_progress($this->jobid, $percent, $message);
        }
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
