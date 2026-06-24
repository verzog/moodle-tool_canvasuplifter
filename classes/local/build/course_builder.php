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
 * Course builder: creates a Moodle course, its sections, and the activity types
 * implemented so far (mod_page, mod_url, mod_resource, mod_assign, mod_quiz,
 * mod_qbank, mod_forum, mod_label and mod_lti). With the page-grouping option
 * set, consecutive pages are combined into a single mod_book or mod_lesson.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_builder {
    /** @var string[] Item kinds the builder can create in the current phase. */
    public const BUILDS_NOW = [
        item::KIND_PAGE, item::KIND_URL, item::KIND_FILE, item::KIND_ASSIGNMENT,
        item::KIND_QUIZ, item::KIND_QUESTIONBANK, item::KIND_DISCUSSION,
        item::KIND_SUBHEADER, item::KIND_LTI,
    ];

    /** @var string[] Kinds that must be created in section 0 (question banks). */
    private const SECTION_ZERO_KINDS = [item::KIND_QUESTIONBANK];

    /** @var array<string, string> Maps an item kind to its Moodle module name. */
    private const KIND_TO_MOD = [
        item::KIND_PAGE => 'page',
        item::KIND_URL => 'url',
        item::KIND_FILE => 'resource',
        item::KIND_ASSIGNMENT => 'assign',
        item::KIND_QUIZ => 'quiz',
        item::KIND_QUESTIONBANK => 'qbank',
        item::KIND_DISCUSSION => 'forum',
        item::KIND_SUBHEADER => 'label',
        item::KIND_LTI => 'lti',
    ];

    /** @var int Course category for the new course. */
    private int $categoryid;

    /** @var string Absolute path to the extracted package root. */
    private string $packageroot;

    /** @var job_manager|null Used to report progress; null in tests. */
    private ?job_manager $jobs;

    /** @var int Job id whose progress this builder reports against. */
    private int $jobid;

    /** @var bool Also build a runnable quiz from each standalone (orphan) assessment. */
    private bool $quizfrombank;

    /** @var string Combine consecutive pages into a single activity: '' (off), 'book' or 'lesson'. */
    private string $pagegrouping;

    /** @var book_builder|lesson_builder|null Memoised grouped-page builder for the current $pagegrouping. */
    private $groupbuilder = null;

    /** @var bool Whether $groupbuilder has been resolved yet. */
    private bool $groupbuilderinit = false;

    /** @var array<string, int> Canvas assignment-group id -> Moodle grade_category id, for the current build. */
    private array $gradecategoryids = [];

    /** @var array Canvas rubric library (id => spec) for the current build; see course_model::$rubrics. */
    private array $rubrics = [];

    /** @var array<string, string> Package-relative path => identifier, for resolving relative cross-resource links. */
    private array $pathtoid = [];

    /**
     * Constructor.
     *
     * @param int $categoryid Target category id.
     * @param string $packageroot Absolute path to the extracted package directory.
     * @param job_manager|null $jobs Optional, used to report progress.
     * @param int $jobid Job id, required when $jobs is set.
     * @param bool $quizfrombank Also build a runnable quiz from each standalone assessment.
     * @param string $pagegrouping Combine consecutive pages into one activity: '' (off), 'book' or 'lesson'.
     */
    public function __construct(
        int $categoryid,
        string $packageroot,
        ?job_manager $jobs = null,
        int $jobid = 0,
        bool $quizfrombank = false,
        string $pagegrouping = ''
    ) {
        $this->categoryid = $categoryid;
        $this->packageroot = rtrim($packageroot, '/');
        $this->jobs = $jobs;
        $this->jobid = $jobid;
        $this->quizfrombank = $quizfrombank;
        $this->pagegrouping = in_array($pagegrouping, ['book', 'lesson'], true) ? $pagegrouping : '';
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

        // Create one Moodle grade category per Canvas assignment group, so each
        // mod_assign we build can be moved into its matching category. Built
        // up front because Moodle creates a default grade item for every assign
        // on add_moduleinfo() and we re-parent those once we know the cmid.
        $this->gradecategoryids = $this->create_grade_categories($course, $coursemodel);
        // Hold the rubric library so attach_rubric() can look up by Canvas id
        // when each assignment is built.
        $this->rubrics = $coursemodel->rubrics;

        // Map each buildable resource's primary source path to its identifier so
        // the page and grouped book/lesson builders can turn relative
        // cross-resource links (ILIAS learning modules linking to each other by
        // path) into object-reference tokens.
        $this->pathtoid = $this->build_pathtoid($coursemodel);

        $builders = [
            item::KIND_PAGE => new page_builder($this->packageroot, $this->pathtoid),
            item::KIND_URL => new url_builder($this->packageroot),
            item::KIND_FILE => new file_builder($this->packageroot),
            item::KIND_ASSIGNMENT => new assign_builder($this->packageroot),
            item::KIND_QUIZ => new quiz_builder($this->packageroot),
            item::KIND_QUESTIONBANK => new questionbank_builder($this->packageroot),
            item::KIND_DISCUSSION => new forum_builder($this->packageroot),
            item::KIND_SUBHEADER => new label_builder(),
            item::KIND_LTI => new lti_builder($this->packageroot),
        ];

        $createdcounts = [];
        $skippedcounts = [];
        $skipreasons = [];
        $urlmap = [];          // Canvas reference key => Moodle activity URL.
        $builtpagecmids = [];   // Course module ids of pages, for the link pass.
        $rewritetargets = [];   // Grouped book/lesson content rows, for the link pass.
        $extraquizzes = 0;      // Runnable quizzes built from standalone banks (toggle).
        $totalitems = max(1, count($coursemodel->all_items()));
        $processed = 0;

        foreach ($coursemodel->sections as $index => $sectionmodel) {
            $sectionnum = $index + 1;
            $this->prepare_section($course, $sectionnum, $sectionmodel->title);
            foreach ($this->segment_items($sectionmodel->items) as $segment) {
                if ($segment['type'] === 'group') {
                    $this->build_page_group(
                        $course,
                        $sectionnum,
                        $sectionmodel->title,
                        $segment['items'],
                        $builders,
                        $urlmap,
                        $builtpagecmids,
                        $rewritetargets,
                        $createdcounts,
                        $skippedcounts
                    );
                    $processed += count($segment['items']);
                    $this->report_item_progress($processed, $totalitems, item::KIND_PAGE);
                    continue;
                }
                $modelitem = $segment['item'];
                $cmid = $this->build_one($course, $sectionnum, $modelitem, $builders, $urlmap, $builtpagecmids, $skipreasons);
                $this->tally($cmid, $modelitem->kind, $createdcounts, $skippedcounts);
                $this->report_item_progress(++$processed, $totalitems, $modelitem->kind);
            }
        }

        // The Canvas syllabus is exported unlinked; surface it as a "Syllabus"
        // page in the top section instead of burying it among extras. Everything
        // else unreferenced goes into a dedicated "Additional resources" section.
        $extras = [];
        foreach ($coursemodel->orphans as $modelitem) {
            if ($this->is_syllabus($modelitem)) {
                if ($modelitem->title === '') {
                    $modelitem->title = get_string('syllabuspage', 'tool_canvasuplifter');
                }
                $cmid = $this->build_one($course, 0, $modelitem, $builders, $urlmap, $builtpagecmids, $skipreasons, false);
                $this->tally($cmid, $modelitem->kind, $createdcounts, $skippedcounts);
                $this->report_item_progress(++$processed, $totalitems, $modelitem->kind);
            } else {
                $extras[] = $modelitem;
            }
        }

        $orphansection = 0;
        if (!empty($extras)) {
            $orphansection = count($coursemodel->sections) + 1;
            $this->prepare_section($course, $orphansection, get_string('additionalresources', 'tool_canvasuplifter'));
            $orphantitle = get_string('additionalresources', 'tool_canvasuplifter');
            foreach ($this->segment_items($extras) as $segment) {
                if ($segment['type'] === 'group') {
                    $this->build_page_group(
                        $course,
                        $orphansection,
                        $orphantitle,
                        $segment['items'],
                        $builders,
                        $urlmap,
                        $builtpagecmids,
                        $rewritetargets,
                        $createdcounts,
                        $skippedcounts
                    );
                    $processed += count($segment['items']);
                    $this->report_item_progress($processed, $totalitems, item::KIND_PAGE);
                    continue;
                }
                $modelitem = $segment['item'];
                $cmid = $this->build_one(
                    $course,
                    $orphansection,
                    $modelitem,
                    $builders,
                    $urlmap,
                    $builtpagecmids,
                    $skipreasons,
                    false
                );
                $this->tally($cmid, $modelitem->kind, $createdcounts, $skippedcounts);
                // With the toggle on, a standalone assessment built above as a
                // reusable question bank also gets a runnable quiz here.
                if ($this->quizfrombank && $cmid !== null && $modelitem->kind === item::KIND_QUIZ) {
                    if ($this->build_standalone_quiz($course, $orphansection, $modelitem, $builders, $urlmap)) {
                        $extraquizzes++;
                    }
                }
                $this->report_item_progress(++$processed, $totalitems, $modelitem->kind);
            }
        }

        // Second pass: rewrite internal page links now that every target exists.
        $this->rewrite_internal_links($builtpagecmids, $urlmap);
        $this->rewrite_grouped_content($rewritetargets, $urlmap);
        $this->rewrite_forum_links((int) $course->id, $urlmap);
        $this->rewrite_assign_links((int) $course->id, $urlmap);
        $this->rewrite_quiz_links((int) $course->id, $urlmap);

        $itemcount = count($coursemodel->all_items());
        $createdtotal = array_sum($createdcounts);
        $skippedtotal = $itemcount - $createdtotal;
        $sectioncount = count($coursemodel->sections) + ($orphansection > 0 ? 1 : 0);

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
            'sectioncount' => $sectioncount,
            'itemcount' => $itemcount,
            'created' => $createdtotal,
            'createdcounts' => $createdcounts,
            'skipped' => $skippedtotal,
            'skippedcounts' => $skippedcounts,
            'skipreasons' => array_slice($skipreasons, 0, 50),
            'extraquizzes' => $extraquizzes,
            'warnings' => $warnings,
        ];
    }

    /**
     * Build a runnable quiz from a standalone assessment (in addition to the
     * question bank already created for it), recording it as the item's link
     * target. Counted separately from per-item tallies, since it is an extra
     * activity rather than another model item.
     *
     * @param \stdClass $course Course record.
     * @param int $sectionnum Section to place the quiz in.
     * @param item $modelitem The assessment item.
     * @param array $builders Map of kind => builder object.
     * @param array $urlmap Link map (modified in place).
     * @return bool Whether a quiz was created.
     */
    private function build_standalone_quiz(
        \stdClass $course,
        int $sectionnum,
        item $modelitem,
        array $builders,
        array &$urlmap
    ): bool {
        $builder = $builders[item::KIND_QUIZ] ?? null;
        if ($builder === null) {
            return false;
        }
        try {
            $cmid = $builder->build($course, $sectionnum, $modelitem);
        } catch (\Throwable $e) {
            mtrace('tool_canvasuplifter: ' . sprintf(
                'failed to build standalone quiz "%s": %s',
                $modelitem->title,
                $e->getMessage()
            ));
            return false;
        }
        if ($cmid === null) {
            return false;
        }
        $this->record_link_target($urlmap, $modelitem, $cmid);
        if (!$modelitem->isvisible) {
            // The bank built from the same item was already hidden in build_one();
            // mirror that here so an unpublished Canvas assessment doesn't leak as
            // a runnable quiz once the imported course is made available.
            set_coursemodule_visible($cmid, 0);
        }
        return true;
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
     * Map item progress (5%..95%) for a processed count.
     *
     * @param int $processed Items processed so far.
     * @param int $total Total items.
     * @return int Percentage 0-100.
     */
    private function item_percent(int $processed, int $total): int {
        return 5 + (int) round(90 * $processed / max(1, $total));
    }

    /**
     * Report a per-item progress update with the standard "n of m (kind)" message.
     *
     * @param int $processed Items processed so far.
     * @param int $total Total items.
     * @param string $kind The kind just processed.
     * @return void
     */
    private function report_item_progress(int $processed, int $total, string $kind): void {
        $this->report_progress($this->item_percent($processed, $total), get_string(
            'progressitem',
            'tool_canvasuplifter',
            ['done' => $processed, 'total' => $total, 'kind' => $kind]
        ));
    }

    /**
     * Split an ordered item list into build segments. When page grouping is off
     * every item is its own "single" segment; when on, each maximal run of two
     * or more consecutive pages becomes a "group" segment (a lone page stays a
     * single page).
     *
     * @param array $items The items in build order.
     * @return array List of ['type' => 'single', 'item' => item] / ['type' => 'group', 'items' => item[]].
     */
    private function segment_items(array $items): array {
        if ($this->pagegrouping === '') {
            return array_map(fn($modelitem) => ['type' => 'single', 'item' => $modelitem], $items);
        }
        $segments = [];
        $run = [];
        foreach ($items as $modelitem) {
            if ($modelitem->kind === item::KIND_PAGE) {
                $run[] = $modelitem;
                continue;
            }
            $this->flush_run($run, $segments);
            $segments[] = ['type' => 'single', 'item' => $modelitem];
        }
        $this->flush_run($run, $segments);
        return $segments;
    }

    /**
     * Emit the accumulated page run as a group (2+ pages) or as single pages,
     * then reset the run.
     *
     * @param array $run Accumulated consecutive page items (reset to []).
     * @param array $segments Segment list being built (modified in place).
     * @return void
     */
    private function flush_run(array &$run, array &$segments): void {
        if (count($run) >= 2) {
            $segments[] = ['type' => 'group', 'items' => $run];
        } else {
            foreach ($run as $modelitem) {
                $segments[] = ['type' => 'single', 'item' => $modelitem];
            }
        }
        $run = [];
    }

    /**
     * Resolve (and memoise) the grouped-page builder for the configured target.
     *
     * @return book_builder|lesson_builder|null The builder, or null when grouping is off.
     */
    private function group_builder() {
        if (!$this->groupbuilderinit) {
            $this->groupbuilderinit = true;
            if ($this->pagegrouping === 'book') {
                $this->groupbuilder = new book_builder($this->packageroot, $this->pathtoid);
            } else if ($this->pagegrouping === 'lesson') {
                $this->groupbuilder = new lesson_builder($this->packageroot, $this->pathtoid);
            }
        }
        return $this->groupbuilder;
    }

    /**
     * Build a run of consecutive pages as one combined book/lesson activity,
     * recording each page's link target and the chapter/page rows that the
     * second link pass must rewrite. Falls back to one page per item if the
     * combined build fails, so no content is lost.
     *
     * @param \stdClass $course Course record.
     * @param int $sectionnum Section to place the activity in.
     * @param string $groupname Activity name (the section title).
     * @param array $pages The page items in the run.
     * @param array $builders Map of kind => builder object (for the page fallback).
     * @param array $urlmap Link map (modified in place).
     * @param int[] $builtpagecmids Page cmids for the link pass (modified in place).
     * @param array $rewritetargets Grouped content rows for the link pass (modified in place).
     * @param array $createdcounts Created tallies (modified in place).
     * @param array $skippedcounts Skipped tallies (modified in place).
     * @return void
     */
    private function build_page_group(
        \stdClass $course,
        int $sectionnum,
        string $groupname,
        array $pages,
        array $builders,
        array &$urlmap,
        array &$builtpagecmids,
        array &$rewritetargets,
        array &$createdcounts,
        array &$skippedcounts
    ): void {
        $builder = $this->group_builder();
        $result = null;
        if ($builder !== null) {
            try {
                $result = $builder->build_group($course, $sectionnum, $groupname, $pages);
            } catch (\Throwable $e) {
                mtrace('tool_canvasuplifter: ' . sprintf(
                    'failed to build %s from "%s": %s',
                    $this->pagegrouping,
                    $groupname,
                    $e->getMessage()
                ));
                $result = null;
            }
        }
        if ($result === null) {
            $this->build_pages_individually(
                $course,
                $sectionnum,
                $pages,
                $builders,
                $urlmap,
                $builtpagecmids,
                $createdcounts,
                $skippedcounts
            );
            return;
        }

        $built = [];
        foreach ($result['pages'] as $entry) {
            $page = $entry['item'];
            $built[spl_object_id($page)] = true;
            $this->record_link_target($urlmap, $page, (int) $result['cmid'], $entry['url']);
            $rewritetargets[] = $entry['rewrite'];
            $this->tally((int) $result['cmid'], item::KIND_PAGE, $createdcounts, $skippedcounts);
        }
        foreach ($pages as $page) {
            if (empty($built[spl_object_id($page)])) {
                $this->tally(null, item::KIND_PAGE, $createdcounts, $skippedcounts);
            }
        }
    }

    /**
     * Build each page in a run as its own mod_page. Used as the fallback when a
     * combined book/lesson build cannot be created.
     *
     * @param \stdClass $course Course record.
     * @param int $sectionnum Section to place the pages in.
     * @param array $pages The page items.
     * @param array $builders Map of kind => builder object.
     * @param array $urlmap Link map (modified in place).
     * @param int[] $builtpagecmids Page cmids for the link pass (modified in place).
     * @param array $createdcounts Created tallies (modified in place).
     * @param array $skippedcounts Skipped tallies (modified in place).
     * @return void
     */
    private function build_pages_individually(
        \stdClass $course,
        int $sectionnum,
        array $pages,
        array $builders,
        array &$urlmap,
        array &$builtpagecmids,
        array &$createdcounts,
        array &$skippedcounts
    ): void {
        $pagebuilder = $builders[item::KIND_PAGE] ?? null;
        foreach ($pages as $page) {
            $cmid = null;
            if ($pagebuilder !== null) {
                try {
                    $cmid = $pagebuilder->build($course, $sectionnum, $page);
                } catch (\Throwable $e) {
                    $cmid = null;
                }
            }
            if ($cmid !== null) {
                $this->record_link_target($urlmap, $page, $cmid);
                $builtpagecmids[] = $cmid;
                if (!$page->isvisible) {
                    set_coursemodule_visible($cmid, 0);
                }
            }
            $this->tally($cmid, item::KIND_PAGE, $createdcounts, $skippedcounts);
        }
    }

    /**
     * Create the section if needed and set its name.
     *
     * @param \stdClass $course Course record.
     * @param int $sectionnum Section number.
     * @param string $title Section title (ignored when empty).
     * @return void
     */
    private function prepare_section(\stdClass $course, int $sectionnum, string $title): void {
        course_create_sections_if_missing($course, [$sectionnum]);
        $section = get_fast_modinfo($course)->get_section_info($sectionnum);
        if ($title !== '' && $section) {
            course_update_section($course, $section, ['name' => $title]);
        }
    }

    /**
     * Build a single item, recording link targets and skip reasons.
     *
     * @param \stdClass $course Course record.
     * @param int $sectionnum Section number to place it in.
     * @param item $modelitem The item to build.
     * @param array $builders Map of kind => builder object exposing build().
     * @param array $urlmap Link map (modified in place).
     * @param int[] $builtpagecmids Page cmids collected for the link pass (modified in place).
     * @param string[] $skipreasons Diagnostic messages (modified in place).
     * @param bool $referenced Whether the item is linked in the course (vs an orphan).
     * @return int|null Created course module id, or null if it could not be built.
     */
    private function build_one(
        \stdClass $course,
        int $sectionnum,
        item $modelitem,
        array $builders,
        array &$urlmap,
        array &$builtpagecmids,
        array &$skipreasons,
        bool $referenced = true
    ): ?int {
        $cmid = null;
        $kind = $modelitem->kind;
        $builder = $builders[$kind] ?? null;
        $buildsasbank = false;
        // Orphan (unreferenced) assessments become question banks rather than
        // quizzes; banks can only live in section 0.
        if ($kind === item::KIND_QUIZ && !$referenced) {
            $builder = $builders[item::KIND_QUESTIONBANK] ?? $builder;
            $sectionnum = 0;
            $buildsasbank = true;
        } else if (in_array($kind, self::SECTION_ZERO_KINDS, true)) {
            $sectionnum = 0;
            $buildsasbank = $kind === item::KIND_QUESTIONBANK;
        }
        if ($builder !== null) {
            // Use the disambiguated banktitle for the bank build only, so a
            // subsequent quiz_from_bank build can reuse this same model item
            // with the unsuffixed original title. Restored in finally so an
            // exception during build still rolls the swap back.
            $savedtitle = $modelitem->title;
            if ($buildsasbank && $modelitem->banktitle !== '') {
                $modelitem->title = $modelitem->banktitle;
            }
            try {
                $cmid = $builder->build($course, $sectionnum, $modelitem);
            } catch (\Throwable $e) {
                $msg = sprintf('failed to build %s "%s": %s', $modelitem->kind, $modelitem->title, $e->getMessage());
                mtrace('tool_canvasuplifter: ' . $msg);
                $skipreasons[] = $msg;
                $cmid = null;
            } finally {
                $modelitem->title = $savedtitle;
            }
        }
        if ($cmid === null && in_array($modelitem->kind, self::BUILDS_NOW, true)) {
            // The kind has a builder but it returned null. Prefer the builder's
            // own explanation when it left one.
            $reason = ($builder !== null && property_exists($builder, 'skipreason') && $builder->skipreason !== null)
                ? $builder->skipreason
                : 'builder could not find payload';
            $skipreasons[] = sprintf(
                '%s "%s" (id=%s) — %s; href="%s" files=[%s]',
                $modelitem->kind,
                $modelitem->title,
                $modelitem->identifier,
                $reason,
                $modelitem->href,
                implode(',', $modelitem->files)
            );
        }
        if ($cmid !== null) {
            // Builders may publish a custom URL (e.g. announcements share a news
            // forum cm but each one needs its own discuss.php?d=… so internal
            // Canvas links land on the right thread).
            $override = ($builder !== null && property_exists($builder, 'linkurl'))
                ? $builder->linkurl : null;
            $this->record_link_target($urlmap, $modelitem, $cmid, $override);
            // Drop assignments into their matching Canvas-derived grade category.
            if ($modelitem->kind === item::KIND_ASSIGNMENT && $modelitem->gradegroupref !== '') {
                $this->place_in_grade_category($course->id, $cmid, $modelitem->gradegroupref);
            }
            // Attach any Canvas rubric the assignment is linked to.
            if ($modelitem->kind === item::KIND_ASSIGNMENT && $modelitem->rubricref !== '') {
                $this->attach_rubric($cmid, $modelitem);
            }
            if ($modelitem->kind === item::KIND_PAGE) {
                $builtpagecmids[] = $cmid;
            }
            if (!$modelitem->isvisible && !$modelitem->isannouncement) {
                // Builders create everything visible by default; honour Canvas's
                // per-item published state in one place rather than threading the
                // flag through every builder's moduleinfo. Announcements share a
                // single news forum cm, so hiding "an announcement" must not hide
                // the whole forum — forum_builder already skips unpublished ones.
                set_coursemodule_visible($cmid, 0);
            }
        }
        return $cmid;
    }

    /**
     * Increment the created or skipped counter for an item kind.
     *
     * @param int|null $cmid The build result (null = skipped).
     * @param string $kind The item kind.
     * @param array $createdcounts Created tallies (modified in place).
     * @param array $skippedcounts Skipped tallies (modified in place).
     * @return void
     */
    private function tally(?int $cmid, string $kind, array &$createdcounts, array &$skippedcounts): void {
        if ($cmid !== null) {
            $createdcounts[$kind] = ($createdcounts[$kind] ?? 0) + 1;
        } else {
            $skippedcounts[$kind] = ($skippedcounts[$kind] ?? 0) + 1;
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
     * @param string|null $override A builder-supplied URL to record instead of mod/.../view.php.
     * @return void
     */
    private function record_link_target(array &$urlmap, item $modelitem, int $cmid, ?string $override = null): void {
        if ($override !== null && $override !== '') {
            $url = $override;
        } else {
            $mod = self::KIND_TO_MOD[$modelitem->kind] ?? null;
            if ($mod === null) {
                return;
            }
            $url = (new \moodle_url('/mod/' . $mod . '/view.php', ['id' => $cmid]))->out(false);
        }
        if ($modelitem->identifier !== '') {
            $urlmap['id:' . $modelitem->identifier] = $url;
        }
        // Variant swaps replace a fallback resource with its preferred target;
        // any $CANVAS_OBJECT_REFERENCE$ link that still addresses the fallback
        // identifier needs to resolve to the same URL the preferred item was
        // built at. The aliasids list carries those redirected identifiers.
        foreach ($modelitem->aliasids as $aliasid) {
            if ($aliasid !== '') {
                $urlmap['id:' . $aliasid] = $url;
            }
        }
        if ($modelitem->kind === item::KIND_PAGE) {
            $slug = $this->slug_for($modelitem);
            if ($slug !== '') {
                $urlmap['wiki:' . $slug] = $url;
            }
        }
    }

    /**
     * Build a package-path => identifier map of every buildable resource, used
     * to resolve relative cross-resource links (e.g. ILIAS learning modules
     * that link to each other by path rather than Canvas placeholder tokens).
     *
     * Only resources that actually build and get recorded in the URL map are
     * included: bundle members (folded into another page) and deliberately
     * suppressed resources are skipped, as is anything with no buildable module
     * mapping, so a rewritten link never points at a target that won't exist.
     *
     * @param course_model $coursemodel Parsed package.
     * @return array<string, string> Package-relative path => resource identifier.
     */
    private function build_pathtoid(course_model $coursemodel): array {
        $map = [];
        foreach ($coursemodel->all_items() as $modelitem) {
            if ($modelitem->identifier === '' || $modelitem->bundlemember || $modelitem->suppressed) {
                continue;
            }
            if (!isset(self::KIND_TO_MOD[$modelitem->kind])) {
                continue;
            }
            // Map the resource's own primary path, plus the source paths of any
            // variant fallbacks it stands in for (those resources are suppressed
            // and so never appear here themselves, but a relative link to their
            // HTML must still resolve to this preferred activity).
            $primary = $modelitem->href !== '' ? $modelitem->href : ($modelitem->files[0] ?? '');
            foreach (array_merge([$primary], $modelitem->aliaspaths) as $path) {
                $path = ltrim((string) $path, '/');
                if ($path !== '' && !isset($map[$path])) {
                    $map[$path] = $modelitem->identifier;
                }
            }
        }
        return $map;
    }

    /**
     * Whether an item is the Canvas course syllabus page.
     *
     * Canvas marks it authoritatively with intendeduse="syllabus"; we also fall
     * back to a "syllabus" hint in the identifier/href for exporters that omit it.
     *
     * @param item $modelitem The item to test.
     * @return bool
     */
    private function is_syllabus(item $modelitem): bool {
        return $modelitem->is_syllabus();
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
     * Rewrite internal Canvas links in grouped book chapter / lesson page bodies.
     *
     * Pages folded into a book or lesson carry the same
     * $WIKI_REFERENCE$/$CANVAS_OBJECT_REFERENCE$ placeholders that standalone
     * pages do, so they get the same second-pass treatment once every link
     * target (including the chapter/page anchors themselves) is known.
     *
     * @param array $targets Rows to rewrite: each ['table' => string, 'id' => int, 'field' => string].
     * @param array $urlmap Canvas reference key => URL.
     * @return void
     */
    private function rewrite_grouped_content(array $targets, array $urlmap): void {
        global $DB;
        if (empty($targets) || empty($urlmap)) {
            return;
        }
        $rewriter = new link_rewriter();
        foreach ($targets as $target) {
            $field = $target['field'];
            $record = $DB->get_record($target['table'], ['id' => $target['id']], 'id, ' . $field);
            if (!$record) {
                continue;
            }
            $newcontent = $rewriter->rewrite_internal_links((string) $record->$field, $urlmap);
            if ($newcontent !== $record->$field) {
                $DB->set_field($target['table'], $field, $newcontent, ['id' => $target['id']]);
            }
        }
    }

    /**
     * Rewrite internal Canvas links in built forum opening posts.
     *
     * Forum prompts are stored during the first pass, before every link target
     * exists, so their $WIKI_REFERENCE$/$CANVAS_OBJECT_REFERENCE$ placeholders are
     * resolved here once the URL map is complete (mirroring the page pass).
     *
     * @param int $courseid The built course id.
     * @param array $urlmap Canvas reference key => URL.
     * @return void
     */
    private function rewrite_forum_links(int $courseid, array $urlmap): void {
        global $DB;
        if (empty($urlmap)) {
            return;
        }
        $rewriter = new link_rewriter();
        $sql = "SELECT p.id, p.message
                  FROM {forum_posts} p
                  JOIN {forum_discussions} d ON d.firstpost = p.id
                  JOIN {forum} f ON f.id = d.forum
                 WHERE f.course = :courseid";
        foreach ($DB->get_records_sql($sql, ['courseid' => $courseid]) as $post) {
            $newmessage = $rewriter->rewrite_internal_links((string) $post->message, $urlmap);
            if ($newmessage !== $post->message) {
                $DB->set_field('forum_posts', 'message', $newmessage, ['id' => $post->id]);
            }
        }
    }

    /**
     * Rewrite internal Canvas links in built assignment intros.
     *
     * CC 1.3 IMS Assignment profile packages carry the instructions inside the
     * profile's <text> element, which may include $WIKI_REFERENCE$ or
     * $CANVAS_OBJECT_REFERENCE$ placeholders. assign_builder stores that HTML
     * verbatim because the URL map isn't yet complete when each activity is
     * created; resolve them here once every link target exists, mirroring the
     * page and forum passes.
     *
     * @param int $courseid The built course id.
     * @param array $urlmap Canvas reference key => URL.
     * @return void
     */
    private function rewrite_assign_links(int $courseid, array $urlmap): void {
        global $DB;
        if (empty($urlmap)) {
            return;
        }
        $rewriter = new link_rewriter();
        foreach ($DB->get_records('assign', ['course' => $courseid], '', 'id, intro') as $assign) {
            $newintro = $rewriter->rewrite_internal_links((string) $assign->intro, $urlmap);
            if ($newintro !== $assign->intro) {
                $DB->set_field('assign', 'intro', $newintro, ['id' => $assign->id]);
            }
        }
    }

    /**
     * Rewrite internal Canvas links in built quiz intros.
     *
     * quiz_builder carries the Canvas quiz description (from assessment_meta.xml)
     * into the quiz intro, which can include $WIKI_REFERENCE$ or
     * $CANVAS_OBJECT_REFERENCE$ placeholders. As with pages, forums and
     * assignments, the URL map isn't complete when each quiz is created, so
     * resolve them here once every link target exists.
     *
     * @param int $courseid The built course id.
     * @param array $urlmap Canvas reference key => URL.
     * @return void
     */
    private function rewrite_quiz_links(int $courseid, array $urlmap): void {
        global $DB;
        if (empty($urlmap)) {
            return;
        }
        $rewriter = new link_rewriter();
        foreach ($DB->get_records('quiz', ['course' => $courseid], '', 'id, intro') as $quiz) {
            $newintro = $rewriter->rewrite_internal_links((string) $quiz->intro, $urlmap);
            if ($newintro !== $quiz->intro) {
                $DB->set_field('quiz', 'intro', $newintro, ['id' => $quiz->id]);
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

    /**
     * Create a grade_category for each Canvas assignment group and configure the
     * course-level aggregation when Canvas marks the gradebook as weighted.
     *
     * When weightingscheme is 'percent' the course aggregation is set to Natural
     * (GRADE_AGGREGATE_SUM) and each child category's grade_item carries the
     * Canvas group_weight as a custom-weight override (aggregationcoef2 with
     * weightoverride=1). That's the only place Moodle stores explicit per-child
     * weights for category aggregation; setting aggregationcoef on the
     * grade_category itself is ignored. Otherwise the categories are created
     * but no weights are applied, mirroring Canvas's "equally weighted" mode.
     *
     * @param \stdClass $course Course record.
     * @param course_model $coursemodel Parsed package.
     * @return array<string, int> Canvas identifier -> Moodle grade_category id.
     */
    private function create_grade_categories(\stdClass $course, course_model $coursemodel): array {
        global $CFG;
        if (empty($coursemodel->gradecategories)) {
            return [];
        }
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/grade/grade_category.php');

        $weighted = $coursemodel->weightingscheme === 'percent';
        if ($weighted) {
            $coursecat = \grade_category::fetch_course_category((int) $course->id);
            $coursecat->aggregation = GRADE_AGGREGATE_SUM;
            $coursecat->update();
        }

        $map = [];
        foreach ($coursemodel->gradecategories as $spec) {
            $cat = new \grade_category(
                ['courseid' => (int) $course->id, 'fullname' => $spec['title']],
                false
            );
            $cat->insert();
            if ($weighted && $spec['weight'] > 0) {
                // Custom-weight override lives on the category's grade_item, not
                // on the grade_category record. aggregationcoef2 is a fraction
                // of 1.0 (so 15% becomes 0.15), and weightoverride=1 tells
                // Natural aggregation to honour it instead of recomputing.
                $catitem = $cat->load_grade_item();
                $catitem->aggregationcoef2 = ((float) $spec['weight']) / 100.0;
                $catitem->weightoverride = 1;
                $catitem->update();
            }
            $map[$spec['identifier']] = (int) $cat->id;
        }
        return $map;
    }

    /**
     * Install the Canvas rubric the model item points at as a Moodle
     * gradingform_rubric definition on the assignment's submissions grading
     * area. When the Canvas <rubric_use_for_grading> flag is true, make rubric
     * the active grading method so it drives the grade; otherwise the rubric
     * is just defined and the admin can switch to it later.
     *
     * @param int $cmid The assignment's course module id.
     * @param item $modelitem The assignment item.
     * @return void
     */
    private function attach_rubric(int $cmid, item $modelitem): void {
        global $CFG;
        $spec = $this->rubrics[$modelitem->rubricref] ?? null;
        if ($spec === null || empty($spec['criteria'])) {
            return;
        }
        require_once($CFG->dirroot . '/grade/grading/lib.php');
        $cm = get_coursemodule_from_id('assign', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }
        $context = \context_module::instance($cmid);
        $gradingmanager = get_grading_manager($context, 'mod_assign', 'submissions');
        $controller = $gradingmanager->get_controller('rubric');
        if ($controller === null) {
            return;
        }
        $controller->update_definition($this->rubric_definition($spec));
        if ($modelitem->rubricforgrading) {
            $gradingmanager->set_active_method('rubric');
        }
    }

    /**
     * Translate a Canvas rubric spec into the stdClass that
     * gradingform_rubric_controller::update_definition() expects. Keys
     * prefixed "NEWID" tell the controller to insert rows on first save.
     *
     * @param array $spec One entry of course_model::$rubrics.
     * @return \stdClass Definition payload.
     */
    private function rubric_definition(array $spec): \stdClass {
        $criteria = [];
        $criterionseq = 0;
        $levelseq = 0;
        foreach ($spec['criteria'] as $criterion) {
            $levels = [];
            foreach ($criterion['levels'] as $level) {
                // Moodle's gradingform_rubric has no per-level long_description
                // field, so append Canvas's long_description onto the visible
                // level definition as a second paragraph when present.
                $definition = (string) $level['description'];
                $long = trim((string) ($level['long_description'] ?? ''));
                if ($long !== '') {
                    $definition = trim($definition);
                    $definition = $definition === '' ? $long : $definition . "\n\n" . $long;
                }
                $levels['NEWID' . (++$levelseq)] = [
                    'score' => (float) $level['points'],
                    'definition' => $definition,
                    'definitionformat' => FORMAT_HTML,
                ];
            }
            // Empty criterion (no levels): drop a single zero/full pair so
            // gradingform_rubric still has a valid row to render.
            if (empty($levels)) {
                $levels['NEWID' . (++$levelseq)] = [
                    'score' => 0,
                    'definition' => get_string('confidence_none', 'tool_canvasuplifter'),
                    'definitionformat' => FORMAT_HTML,
                ];
                $levels['NEWID' . (++$levelseq)] = [
                    'score' => (float) ($criterion['points'] ?? 0),
                    'definition' => get_string('confidence_full', 'tool_canvasuplifter'),
                    'definitionformat' => FORMAT_HTML,
                ];
            }
            $criteria['NEWID' . (++$criterionseq)] = [
                'sortorder' => $criterionseq,
                'description' => $criterion['description'],
                'descriptionformat' => FORMAT_HTML,
                'levels' => $levels,
            ];
        }
        $definition = new \stdClass();
        $definition->name = (string) ($spec['title'] !== '' ? $spec['title'] : 'Rubric');
        $definition->description_editor = ['text' => '', 'format' => FORMAT_HTML];
        $definition->status = \gradingform_controller::DEFINITION_STATUS_READY;
        $definition->rubric = [
            'criteria' => $criteria,
            'options' => [
                // Ratings rendered ascending (low → high) to match the sort
                // applied in the parser.
                'sortlevelsasc' => 1,
                'showdescriptionteacher' => 1,
                'showdescriptionstudent' => 1,
                'showscoreteacher' => 1,
                'showscorestudent' => empty($spec['hide_score_total']) ? 1 : 0,
                'enableremarks' => 1,
                'showremarksstudent' => 1,
                'lockzeropoints' => 1,
            ],
        ];
        return $definition;
    }

    /**
     * Move a just-built assignment's grade item into the matching Canvas-derived
     * grade category, by re-parenting the auto-created grade_item.
     *
     * @param int $courseid The course id.
     * @param int $cmid The assignment's course module id.
     * @param string $groupref Canvas assignment group identifier from the model.
     * @return void
     */
    private function place_in_grade_category(int $courseid, int $cmid, string $groupref): void {
        global $CFG;
        $categoryid = $this->gradecategoryids[$groupref] ?? 0;
        if ($categoryid <= 0) {
            return;
        }
        $cm = get_coursemodule_from_id('assign', $cmid, $courseid, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }
        require_once($CFG->libdir . '/grade/grade_item.php');
        $item = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => (int) $cm->instance,
            'courseid' => $courseid,
        ]);
        if ($item) {
            $item->set_parent($categoryid);
        }
    }
}
