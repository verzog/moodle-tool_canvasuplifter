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
    /** @var string[] Item kinds the builder can create in the current phase. */
    public const BUILDS_NOW = [item::KIND_PAGE, item::KIND_URL, item::KIND_FILE];

    /** @var array<string, string> Maps an item kind to its Moodle module name. */
    private const KIND_TO_MOD = [
        item::KIND_PAGE => 'page',
        item::KIND_URL => 'url',
        item::KIND_FILE => 'resource',
    ];

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
        $urlmap = [];          // Canvas reference key => Moodle activity URL.
        $builtpagecmids = [];   // Course module ids of pages, for the link pass.
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
                if ($cmid === null && in_array($modelitem->kind, self::BUILDS_NOW, true)) {
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
                    $this->record_link_target($urlmap, $modelitem, $cmid);
                    if ($modelitem->kind === item::KIND_PAGE) {
                        $builtpagecmids[] = $cmid;
                    }
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

        // Second pass: rewrite internal page links now that every target exists.
        $this->rewrite_internal_links($builtpagecmids, $urlmap);

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
     * Record a built activity's URL so internal Canvas links can resolve to it.
     *
     * Items are keyed by their Canvas identifier, and pages additionally by
     * their wiki slug (the source file's base name), which is how
     * $WIKI_REFERENCE$ links address them.
     *
     * @param array $urlmap Link map being built (modified in place).
     * @param item $modelitem The built item.
     * @param int $cmid Its course module id.
     * @return void
     */
    private function record_link_target(array &$urlmap, item $modelitem, int $cmid): void {
        $mod = self::KIND_TO_MOD[$modelitem->kind] ?? null;
        if ($mod === null) {
            return;
        }
        $url = (new \moodle_url('/mod/' . $mod . '/view.php', ['id' => $cmid]))->out(false);
        if ($modelitem->identifier !== '') {
            $urlmap['id:' . $modelitem->identifier] = $url;
        }
        if ($modelitem->kind === item::KIND_PAGE) {
            $slug = $this->slug_for($modelitem);
            if ($slug !== '') {
                $urlmap['wiki:' . $slug] = $url;
            }
        }
    }

    /**
     * Derive a page's wiki slug from its source file (base name without extension).
     *
     * @param item $modelitem The page item.
     * @return string The slug, or '' if it has no source path.
     */
    private function slug_for(item $modelitem): string {
        $source = $modelitem->files[0] ?? '';
        if ($source === '') {
            $source = $modelitem->href;
        }
        if ($source === '') {
            return '';
        }
        $base = basename($source);
        $dot = strrpos($base, '.');
        return $dot === false ? $base : substr($base, 0, $dot);
    }

    /**
     * Rewrite internal links in every built page once all targets exist.
     *
     * @param int[] $pagecmids Course module ids of the pages to process.
     * @param array $urlmap Canvas reference key => URL.
     * @return void
     */
    private function rewrite_internal_links(array $pagecmids, array $urlmap): void {
        global $DB;
        if (empty($pagecmids) || empty($urlmap)) {
            return;
        }
        $rewriter = new link_rewriter();
        foreach ($pagecmids as $cmid) {
            $cm = get_coursemodule_from_id('page', $cmid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                continue;
            }
            $page = $DB->get_record('page', ['id' => $cm->instance]);
            if (!$page) {
                continue;
            }
            $newcontent = $rewriter->rewrite_internal_links((string) $page->content, $urlmap);
            if ($newcontent !== $page->content) {
                $DB->set_field('page', 'content', $newcontent, ['id' => $page->id]);
            }
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
