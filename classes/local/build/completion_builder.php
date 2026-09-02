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

use availability_completion\condition as completion_condition;
use core_availability\tree;
use stdClass;

/**
 * Applies Canvas module gating to the built course as Moodle activity completion + availability.
 *
 * Canvas gates at the module level ("finish module A before module B"). Moodle has no
 * module-completion availability condition, so this second pass — run after every activity
 * exists and the id map is known — maps each Canvas module prerequisite to a section-level
 * "Restrict access" rule on the dependent module (a Moodle section), combining a completion
 * condition on each activity of the prerequisite module(s). Those prerequisite activities are
 * given automatic view-completion so the rule can be satisfied, and course completion is
 * enabled. A per-item Canvas completion_requirement, when present, sets that activity's
 * completion config instead of the view default.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completion_builder {
    /** @var int Sections given an availability restriction from a Canvas prerequisite. */
    public int $gatedsections = 0;

    /** @var int Activities given automatic completion tracking. */
    public int $completionset = 0;

    /** @var int Canvas prerequisites that referenced a module with no built, gateable activity. */
    public int $unresolvedprereqs = 0;

    /** @var bool Whether gating was skipped because the site's completion tracking is disabled. */
    public bool $sitecompletiondisabled = false;

    /** @var array Set of course-module ids (keys) gated on a passing grade rather than a plain
     * completion, populated by a min_score requirement so the section rule can expect the
     * COMPLETION_COMPLETE_PASS state instead of the generic COMPLETION_COMPLETE. */
    protected array $passgradecmids = [];

    /**
     * Apply the gating. Both inputs come from course_builder's build pass.
     *
     * @param stdClass $course Course record.
     * @param array $sections One row per built section: ['canvasid' => string, 'sectionnum' => int,
     *        'prerequisites' => array of prerequisite Canvas module ids, 'droppedrequired' => bool
     *        (a Canvas-required item of this module failed to build)].
     * @param array $itemcompletions One row per built activity carrying an explicit Canvas
     *        completion requirement: ['cmid' => int, 'requirement' => string, 'minscore' => string].
     * @return int Number of sections gated.
     */
    public function apply(stdClass $course, array $sections, array $itemcompletions): int {
        global $DB, $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        // Completion tracking must be enabled site-wide, or a course's completion never
        // produces records and every gate would stay permanently locked. Skip gating and
        // report the prerequisites as unapplied rather than writing rules that can't be met.
        if (empty($CFG->enablecompletion)) {
            foreach ($sections as $row) {
                $this->unresolvedprereqs += count($row['prerequisites']);
            }
            $this->sitecompletiondisabled = $this->unresolvedprereqs > 0;
            return 0;
        }

        // Map each Canvas module id to the section number it built into, so a prerequisite
        // (which names a module) resolves to that module's section and its activities. Also
        // note which modules dropped a Canvas-required item: a prerequisite on such a module
        // can never be fully honoured (the surviving activities would unlock the gate without
        // the required item ever completed), so it is treated as unresolved below.
        $sectionbycanvasid = [];
        $droppedbycanvasid = [];
        foreach ($sections as $row) {
            if ($row['canvasid'] !== '' && $row['sectionnum'] > 0) {
                $sectionbycanvasid[$row['canvasid']] = (int) $row['sectionnum'];
                $droppedbycanvasid[$row['canvasid']] = !empty($row['droppedrequired']);
            }
        }

        $modinfo = get_fast_modinfo($course);
        $changed = false;

        // Honour an explicit per-item completion requirement first, so a prerequisite activity
        // that has one keeps it rather than being overwritten by the view default below.
        $explicit = [];
        foreach ($itemcompletions as $row) {
            $cmid = (int) $row['cmid'];
            if ($cmid <= 0 || !isset($modinfo->cms[$cmid])) {
                continue;
            }
            if ($this->set_requirement_completion($modinfo->cms[$cmid], (string) $row['requirement'], (string) $row['minscore'])) {
                $explicit[$cmid] = true;
                $changed = true;
            }
        }

        foreach ($sections as $row) {
            if (empty($row['prerequisites'])) {
                continue;
            }
            // Collect the completion-trackable activities of every prerequisite module,
            // counting each prerequisite that resolves to none (missing module, or a module
            // with no gateable activity — e.g. only labels or hidden items) once.
            $prereqcmids = [];
            foreach ($row['prerequisites'] as $prereqid) {
                $cmids = isset($sectionbycanvasid[$prereqid])
                    ? $this->gateable_cmids($modinfo, $sectionbycanvasid[$prereqid]) : [];
                // Count as unresolved a prerequisite that resolves to no gateable activity, or one
                // whose module dropped a Canvas-required item (gating on the survivors alone would
                // under-restrict — the dependent section would unlock without the required item).
                if (empty($cmids) || !empty($droppedbycanvasid[$prereqid])) {
                    $this->unresolvedprereqs++;
                    continue;
                }
                foreach ($cmids as $cmid) {
                    $prereqcmids[$cmid] = true;
                }
            }
            if (empty($prereqcmids)) {
                // Every prerequisite was unresolvable (already counted above); write no rule.
                continue;
            }
            // Give each prerequisite activity automatic view-completion (unless it already has
            // an explicit requirement) so the restriction can be satisfied.
            foreach (array_keys($prereqcmids) as $cmid) {
                if (!isset($explicit[$cmid])) {
                    $this->set_view_completion($modinfo->cms[$cmid]);
                }
            }
            // Write the "&"-combined completion restriction onto the dependent section. An
            // activity gated on a passing grade (a min_score requirement) must expect
            // COMPLETION_COMPLETE_PASS — the generic COMPLETION_COMPLETE also accepts a failing
            // grade (COMPLETION_COMPLETE_FAIL), which would unlock the section on a fail.
            $children = [];
            foreach (array_keys($prereqcmids) as $cmid) {
                $expected = isset($this->passgradecmids[$cmid]) ? COMPLETION_COMPLETE_PASS : COMPLETION_COMPLETE;
                $children[] = completion_condition::get_json($cmid, $expected);
            }
            $json = json_encode(tree::get_root_json($children, tree::OP_AND, true));
            $sectionid = (int) $modinfo->get_section_info((int) $row['sectionnum'])->id;
            $DB->set_field('course_sections', 'availability', $json, ['id' => $sectionid]);
            $this->gatedsections++;
            $changed = true;
        }

        if ($changed) {
            // Course completion must be on for activity completion and restrictions to work.
            if (empty($course->enablecompletion)) {
                $DB->set_field('course', 'enablecompletion', 1, ['id' => $course->id]);
                $course->enablecompletion = 1;
            }
            rebuild_course_cache((int) $course->id, true);
        }
        return $this->gatedsections;
    }

    /**
     * The completion-trackable course-module ids in a section: visible activities whose module
     * can track completion by view (so a completion condition on them is meaningful). A hidden
     * activity (students can't complete it) or a module that can't track view completion (e.g. a
     * label) is skipped so it is not written into a restriction it can never meet.
     *
     * @param \course_modinfo $modinfo The course's module info.
     * @param int $sectionnum The section number.
     * @return array List of course-module ids.
     */
    protected function gateable_cmids(\course_modinfo $modinfo, int $sectionnum): array {
        $cmids = [];
        foreach ($modinfo->get_sections()[$sectionnum] ?? [] as $cmid) {
            $cm = $modinfo->get_cm((int) $cmid);
            // A hidden (unpublished) activity can never be viewed or completed by students, so
            // gating a section on its completion would lock that section permanently. Only gate
            // on visible activities whose module can track view-completion.
            if ($cm->visible && plugin_supports('mod', $cm->modname, FEATURE_COMPLETION_TRACKS_VIEWS, false)) {
                $cmids[] = (int) $cmid;
            }
        }
        return $cmids;
    }

    /**
     * Give an activity automatic view-completion, so a completion restriction can be satisfied.
     *
     * @param \cm_info $cm The course module.
     * @return void
     */
    protected function set_view_completion(\cm_info $cm): void {
        global $DB;
        $DB->update_record('course_modules', (object) [
            'id' => $cm->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionview' => 1,
        ]);
        $this->completionset++;
    }

    /**
     * Set an activity's completion config from an explicit Canvas completion requirement. A
     * min_score sets the grade item's passing threshold and requires a passing grade;
     * must_view/must_mark_done/must_submit/must_contribute fall back to view-completion, which
     * every gateable module supports. Returns whether a rule was written (false when the module
     * cannot track completion by view).
     *
     * @param \cm_info $cm The course module.
     * @param string $requirement The Canvas requirement type.
     * @param string $minscore The min_score value (for min_score requirements).
     * @return bool
     */
    protected function set_requirement_completion(\cm_info $cm, string $requirement, string $minscore): bool {
        global $DB;
        if (!plugin_supports('mod', $cm->modname, FEATURE_COMPLETION_TRACKS_VIEWS, false)) {
            return false;
        }
        $update = (object) ['id' => $cm->id, 'completion' => COMPLETION_TRACKING_AUTOMATIC];
        if (
            $requirement === 'min_score'
            && plugin_supports('mod', $cm->modname, FEATURE_GRADE_HAS_GRADE, false)
            && $this->set_grade_pass($cm, $minscore)
        ) {
            // Require a passing grade: the activity's grade item now carries a gradepass scaled
            // from Canvas's min_score, and completionpassgrade tells Moodle to compare against it.
            $update->completiongradeitemnumber = 0;
            $update->completionpassgrade = 1;
            $this->passgradecmids[(int) $cm->id] = true;
        } else {
            // The must_view requirement, and the submit/contribute ones (whose module-specific
            // rules vary), map to "must view" — the closest uniform Moodle equivalent. A min_score
            // on an ungraded activity (no grade item to compare) also lands here: without a grade
            // item a passing-grade rule can't be enforced, so view-completion is the safe fallback.
            $update->completionview = 1;
        }
        $DB->update_record('course_modules', $update);
        $this->completionset++;
        return true;
    }

    /**
     * Set an activity's grade-item passing threshold (gradepass) from a Canvas min_score. Canvas
     * min_score is a raw points value on the same scale as the imported activity's grademax (the
     * importer sets the activity's grade from Canvas's points_possible), so it maps directly,
     * clamped to the grade item's [0, grademax] range. Returns false when the activity has no
     * gradeable item to set the threshold on (so the caller falls back to view-completion).
     *
     * @param \cm_info $cm The course module.
     * @param string $minscore The Canvas min_score value.
     * @return bool Whether a passing threshold was set.
     */
    protected function set_grade_pass(\cm_info $cm, string $minscore): bool {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $gradeitem = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => $cm->modname,
            'iteminstance' => $cm->instance,
            'itemnumber' => 0,
            'courseid' => $cm->course,
        ]);
        if (!$gradeitem || (float) $gradeitem->grademax <= 0) {
            return false;
        }
        $gradeitem->gradepass = min(max((float) $minscore, 0.0), (float) $gradeitem->grademax);
        $gradeitem->update();
        return true;
    }
}
