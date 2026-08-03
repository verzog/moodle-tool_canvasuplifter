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

use grade_outcome;
use grade_scale;
use stdClass;
use tool_canvasuplifter\local\model\outcome;
use tool_canvasuplifter\local\parser\outcomes_parser;

/**
 * Imports Canvas learning outcomes as Moodle course grade outcomes.
 *
 * Canvas ships outcomes in course_settings/learning_outcomes.xml, each with a
 * mastery scale (its <ratings>). Each outcome becomes a course-scoped
 * grade_outcome backed by a grade_scale built from those ratings. The outcomes
 * are course-scoped and non-destructive; note that Moodle only surfaces outcomes
 * when the site's "Enable outcomes" advanced setting is on.
 *
 * Alignment of outcomes to specific activities/rubric criteria is not carried
 * across — Canvas records that separately and Moodle models it differently.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class outcome_builder {
    /** @var string Absolute path to the extracted package root. */
    private string $packageroot;

    /** @var int Outcomes dropped because their ratings can't form a usable scale. */
    public int $skippedcount = 0;

    /** @var int[] Ids of the grade_outcomes created, for the post-build link pass. */
    public array $createdids = [];

    /**
     * Constructor.
     *
     * @param string $packageroot Absolute path to the extracted package directory.
     */
    public function __construct(string $packageroot) {
        $this->packageroot = rtrim($packageroot, '/');
    }

    /**
     * Create course grade outcomes from the package's learning_outcomes.xml.
     *
     * @param stdClass $course Course record.
     * @return int Number of outcomes created.
     */
    public function build(stdClass $course): int {
        global $CFG;
        require_once($CFG->libdir . '/grade/grade_scale.php');
        require_once($CFG->libdir . '/grade/grade_outcome.php');

        $path = $this->packageroot . '/course_settings/learning_outcomes.xml';
        if (!is_readable($path)) {
            return 0;
        }
        $outcomes = (new outcomes_parser())->parse((string) @file_get_contents($path));
        $created = 0;
        foreach ($outcomes as $model) {
            if ($this->create_outcome($course, $model)) {
                $created++;
            } else {
                // A named outcome whose ratings can't form a usable scale is
                // dropped; count it so the build report can flag the loss.
                $this->skippedcount++;
            }
        }
        return $created;
    }

    /**
     * Create one course grade outcome (and its backing scale) from a model.
     *
     * @param stdClass $course Course record.
     * @param outcome $model The parsed outcome.
     * @return bool Whether the outcome was created.
     */
    private function create_outcome(stdClass $course, outcome $model): bool {
        $scaleid = $this->create_scale($course, $model);
        if ($scaleid === null) {
            // Moodle outcomes require a scale of at least two items; skip an
            // outcome whose ratings can't form one rather than fail the build.
            return false;
        }
        $name = $this->outcome_name($model);
        $outcome = new grade_outcome();
        $outcome->courseid = (int) $course->id;
        $outcome->fullname = shorten_text($name, 254);
        $outcome->shortname = $this->unique_shortname($course, $name);
        $outcome->scaleid = $scaleid;
        $outcome->description = $model->description;
        $outcome->descriptionformat = FORMAT_HTML;
        $id = $outcome->insert('tool_canvasuplifter');
        if (!$id) {
            return false;
        }
        $this->createdids[] = (int) $id;
        // The description may embed package files via $IMS-CC-FILEBASE$ tokens.
        // grade_outcome::get_description() serves them from the system context's
        // grade/outcome file area keyed by the outcome id, so import them there
        // and rewrite the tokens to @@PLUGINFILE@@ now that the id exists.
        $systemcontext = \context_system::instance();
        $rewritten = (new file_embedder($this->packageroot))
            ->embed($systemcontext->id, 'grade', 'outcome', $model->description, (int) $id);
        if ($rewritten !== $model->description) {
            $outcome->description = $rewritten;
            $outcome->update('tool_canvasuplifter');
        }
        return true;
    }

    /**
     * Create a course scale from the outcome's mastery ratings. Canvas lists
     * ratings highest-first; Moodle scale items run lowest-to-highest, so the
     * order is reversed. Returns null when fewer than two distinct rating labels
     * remain (Moodle scales need at least two items).
     *
     * @param stdClass $course Course record.
     * @param outcome $model The parsed outcome.
     * @return int|null The new scale id, or null when no usable scale.
     */
    private function create_scale(stdClass $course, outcome $model): ?int {
        global $USER;
        // Rating labels normalised to Moodle scale items (reversed, comma-safe,
        // deduplicated); a usable scale needs at least two distinct labels.
        $items = $model->scale_labels();
        if (count($items) < 2) {
            return null;
        }
        $scale = new grade_scale();
        $scale->courseid = (int) $course->id;
        $scale->userid = (int) $USER->id;
        $scale->name = shorten_text($this->outcome_name($model) . ' mastery', 254);
        $scale->scale = implode(',', $items);
        $scale->description = '';
        $scale->descriptionformat = FORMAT_HTML;
        $scale->standard = 0;
        return (int) $scale->insert('tool_canvasuplifter');
    }

    /**
     * The outcome's display name, falling back to a generic label when Canvas
     * exported it without a title, so an untitled-but-rated outcome is still
     * imported (and visible to an admin) rather than dropped.
     *
     * @param outcome $model The parsed outcome.
     * @return string
     */
    private function outcome_name(outcome $model): string {
        $name = trim($model->fullname);
        return $name !== '' ? $name : get_string('outcomeuntitled', 'tool_canvasuplifter');
    }

    /**
     * A course-unique outcome shortname derived from the outcome name, suffixed
     * with a counter on collision so a package with same-named outcomes still
     * imports them all.
     *
     * @param stdClass $course Course record.
     * @param string $fullname The outcome name.
     * @return string
     */
    private function unique_shortname(stdClass $course, string $fullname): string {
        global $DB;
        $base = shorten_text(trim($fullname) !== '' ? trim($fullname) : 'outcome', 240);
        $shortname = $base;
        $suffix = 1;
        while ($DB->record_exists('grade_outcomes', ['courseid' => (int) $course->id, 'shortname' => $shortname])) {
            $suffix++;
            $shortname = $base . ' (' . $suffix . ')';
        }
        return $shortname;
    }
}
