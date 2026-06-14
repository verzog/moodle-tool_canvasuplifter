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

use stdClass;
use tool_canvasuplifter\local\model\item;
use tool_canvasuplifter\local\model\qti_question;
use tool_canvasuplifter\local\parser\qti_parser;

/**
 * Creates a mod_quiz activity from a Canvas QTI assessment.
 *
 * The questions are imported into the quiz's own context (see
 * {@see question_importer}) and then added to the quiz as slots. Unsupported
 * question types are skipped; if none remain, no quiz is created.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_builder {
    /** @var string|null Why the last build() returned null, for the skip report. */
    public ?string $skipreason = null;

    /** @var string Absolute path to the extracted package root. */
    private string $packageroot;

    /**
     * Constructor.
     *
     * @param string $packageroot Absolute path to the extracted package directory.
     */
    public function __construct(string $packageroot) {
        $this->packageroot = rtrim($packageroot, '/');
    }

    /**
     * Create a mod_quiz and add the assessment's questions.
     *
     * @param stdClass $course Course record.
     * @param int $sectionnum Section to place the quiz in.
     * @param item $modelitem The quiz item.
     * @return int|null Created course module id, or null if there's nothing to import.
     */
    public function build(stdClass $course, int $sectionnum, item $modelitem): ?int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $this->skipreason = null;

        $qtipath = $this->locate_qti($modelitem);
        if ($qtipath === null) {
            $this->skipreason = 'no QTI assessment file found';
            return null;
        }
        $parsed = (new qti_parser())->parse((string) @file_get_contents($qtipath));
        $questions = $parsed['questions'];
        $supported = array_filter($questions, fn($q) => $q->type !== qti_question::TYPE_UNSUPPORTED);
        $importable = array_filter($supported, fn($q) => $q->is_importable());
        if (empty($importable)) {
            $this->skipreason = question_importer::describe_unconvertible($questions, $supported, $parsed['unresolved'] ?? 0);
            return null;
        }

        $name = $modelitem->title !== '' ? $modelitem->title
            : ($parsed['title'] !== '' ? $parsed['title'] : 'Quiz');

        $module = $DB->get_record('modules', ['name' => 'quiz']);
        if (!$module) {
            return null;
        }

        $moduleinfo = (object) array_merge($this->quiz_defaults(), [
            'modulename' => 'quiz',
            'module' => $module->id,
            'course' => $course->id,
            'section' => $sectionnum,
            'visible' => 1,
            'cmidnumber' => '',
            'name' => $name,
            'intro' => '',
            'introformat' => FORMAT_HTML,
        ]);
        $created = add_moduleinfo($moduleinfo, $course);
        $cmid = (int) $created->coursemodule;

        $context = \context_module::instance($cmid);
        $questionids = (new question_importer())->import($course, $context, $supported, dirname($qtipath));
        if (empty($questionids)) {
            // Nothing imported despite some questions looking convertible; don't
            // leave an empty quiz behind.
            course_delete_module($cmid);
            $this->skipreason = sprintf(
                "Moodle's importer rejected all %d convertible question(s)",
                count($importable)
            );
            return null;
        }

        $quiz = $DB->get_record('quiz', ['id' => $created->instance], '*', MUST_EXIST);
        $quiz->cmid = $cmid;
        foreach ($questionids as $questionid) {
            quiz_add_quiz_question((int) $questionid, $quiz);
        }
        \mod_quiz\quiz_settings::create((int) $quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
        return $cmid;
    }

    /**
     * Default quiz settings for a freshly imported quiz.
     *
     * Mirrors mod_quiz's own test generator defaults: sensible review options,
     * deferred feedback, unlimited attempts and a 100-point maximum grade.
     * add_moduleinfo() processes the per-state review flags into bitmasks.
     *
     * @return array
     */
    private function quiz_defaults(): array {
        $fields = ['attempt', 'correctness', 'maxmarks', 'marks',
            'specificfeedback', 'generalfeedback', 'rightanswer', 'overallfeedback'];
        $review = [];
        foreach (['during', 'immediately', 'open', 'closed'] as $when) {
            foreach ($fields as $field) {
                $review[$field . $when] = 1;
            }
        }
        // These are off by default in mod_quiz: no during/closed overall feedback etc.
        $review['overallfeedbackduring'] = 0;
        return array_merge($review, [
            'timeopen' => 0,
            'timeclose' => 0,
            'timelimit' => 0,
            'overduehandling' => 'autosubmit',
            'graceperiod' => 86400,
            'preferredbehaviour' => 'deferredfeedback',
            'canredoquestions' => 0,
            'attempts' => 0,
            'attemptonlast' => 0,
            'grademethod' => QUIZ_GRADEHIGHEST,
            'decimalpoints' => 2,
            'questiondecimalpoints' => -1,
            'questionsperpage' => 1,
            'navmethod' => QUIZ_NAVMETHOD_FREE,
            'shuffleanswers' => 1,
            'sumgrades' => 0,
            'grade' => 100,
            'quizpassword' => '',
            'subnet' => '',
            'browsersecurity' => '',
            'delay1' => 0,
            'delay2' => 0,
            'showuserpicture' => 0,
            'showblocks' => 0,
        ]);
    }

    /**
     * Find the QTI assessment XML file for this resource.
     *
     * @param item $modelitem The item.
     * @return string|null Absolute path, or null.
     */
    private function locate_qti(item $modelitem): ?string {
        $candidates = $modelitem->files;
        if ($modelitem->href !== '') {
            $candidates[] = $modelitem->href;
        }
        foreach ($candidates as $relative) {
            if (!preg_match('/\.xml(\.qti)?$/i', $relative)) {
                continue;
            }
            $absolute = safe_path::within($this->packageroot, $relative);
            if ($absolute !== null && is_readable($absolute)) {
                return $absolute;
            }
        }
        return null;
    }
}
