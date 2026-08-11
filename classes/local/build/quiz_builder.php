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
use tool_canvasuplifter\local\parser\quiz_settings;

/**
 * Creates a mod_quiz activity from a Canvas QTI assessment.
 *
 * The questions are imported into the quiz's own context (see
 * {@see question_importer}) and then added to the quiz as slots. Unsupported
 * question types are skipped; if none remain, no quiz is created.
 *
 * The quiz's configuration (time limit, attempts, scoring policy, availability
 * dates, navigation, password, ...) comes from the sibling assessment_meta.xml
 * that Canvas exports alongside the QTI questions; {@see quiz_settings} reads it
 * and {@see settings_overlay()} maps it onto the moduleinfo defaults. When the
 * meta file is absent (e.g. a question-bank-only export) the generic defaults
 * stand.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_builder {
    /** @var string|null Why the last build() returned null, for the skip report. */
    public ?string $skipreason = null;

    /** @var int How many quizzes were built as empty hidden placeholders (no importable questions). */
    public int $placeholdercount = 0;

    /** @var int[] Ids of every question imported across all build() calls, for the link-rewrite pass. */
    public array $importedquestionids = [];

    /** @var string Absolute path to the extracted package root. */
    private string $packageroot;

    /** @var media_report|null Shared collector for unresolved media references. */
    private ?media_report $mediareport;

    /**
     * Constructor.
     *
     * @param string $packageroot Absolute path to the extracted package directory.
     * @param media_report|null $mediareport Shared collector for unresolved media references (null to skip).
     */
    public function __construct(string $packageroot, ?media_report $mediareport = null) {
        $this->packageroot = rtrim($packageroot, '/');
        $this->mediareport = $mediareport;
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
        [$parsed, $supported, $importable] = $this->parse_qti($qtipath);
        // Common Cartridge question media resolves relative to the quiz folder.
        $imagedir = dirname($qtipath);

        // When the Common Cartridge assessment_qti.xml is an empty shell (Canvas
        // routinely exports the questions only to its native dump), fall back to
        // non_cc_assessments/<id>.xml.qti, which holds the real questions in the
        // same QTI 1.2 dialect. Only do this when the CC file yields nothing
        // importable, so a quiz that already converts is left untouched.
        if (empty($importable)) {
            $native = $this->locate_native_qti($modelitem, $qtipath);
            if ($native !== null) {
                [$nativeparsed, $nativesupported, $nativeimportable] = $this->parse_qti($native);
                if (!empty($nativeimportable)) {
                    $parsed = $nativeparsed;
                    $supported = $nativesupported;
                    $importable = $nativeimportable;
                    // Native questions reference media under the package root.
                    $imagedir = $this->packageroot;
                }
            }
        }
        $questions = $parsed['questions'];

        // A placeholder is only justified for a genuine but empty Canvas shell:
        // a readable QTI 1.2 <assessment>/<section>, whether the section is empty
        // or holds bare item references to questions Canvas didn't export. The
        // hasassessment flag already requires that structure, so a stray <item>
        // node in malformed or non-QTI-1.2 XML (where unresolved could be > 0 but
        // there's no real assessment) is not mistaken for a shell.
        $isshell = empty($questions) && ($parsed['hasassessment'] ?? false);

        // Report and skip when nothing is importable and it isn't a shell —
        // either questions are present but unconvertible (unsupported types), or
        // the file isn't a readable QTI 1.2 assessment at all (malformed, or QTI
        // 2.x/3.x). Masking such a conversion failure as a placeholder would
        // hide real data loss.
        if (empty($importable) && !$isshell) {
            $this->skipreason = question_importer::describe_unconvertible($questions, $supported, $parsed['unresolved'] ?? 0);
            return null;
        }

        // Read the sibling assessment_meta.xml for the real quiz configuration;
        // the QTI file carries only the questions.
        $metapath = $this->locate_meta($modelitem, $qtipath);
        $settings = $metapath !== null
            ? quiz_settings::parse((string) @file_get_contents($metapath))
            : new quiz_settings();

        $name = $modelitem->title !== '' ? $modelitem->title
            : ($settings->title !== '' ? $settings->title
            : ($parsed['title'] !== '' ? $parsed['title'] : 'Quiz'));

        $module = $DB->get_record('modules', ['name' => 'quiz']);
        if (!$module) {
            return null;
        }

        // The assessment is a genuine but empty Canvas shell (checked above):
        // build a hidden placeholder carrying the title and settings, with a note
        // asking a teacher to add the questions Canvas didn't export, so nothing
        // bar the absent questions is lost.
        $isplaceholder = $isshell;
        $intro = $settings->description;
        if ($isplaceholder) {
            $this->placeholdercount++;
            $intro = get_string('quizplaceholderintro', 'tool_canvasuplifter') . $intro;
        }

        $moduleinfo = (object) array_merge($this->quiz_defaults(), $this->settings_overlay($settings), [
            'modulename' => 'quiz',
            'module' => $module->id,
            'course' => $course->id,
            'section' => $sectionnum,
            // A placeholder has no questions, so keep it hidden until a teacher
            // adds them; a real quiz is visible (course_builder still hides it
            // afterwards if the Canvas activity was unpublished).
            'visible' => $isplaceholder ? 0 : 1,
            'cmidnumber' => '',
            'name' => $name,
            'intro' => $intro,
            'introformat' => FORMAT_HTML,
        ]);
        $created = add_moduleinfo($moduleinfo, $course);
        $cmid = (int) $created->coursemodule;

        $context = \context_module::instance($cmid);
        // Import any files the description embeds and rewrite the intro to
        // pluginfile refs, mirroring assign_builder's handling. The description
        // comes from assessment_meta.xml, so its owner-relative media resolves
        // against that file's own folder.
        if ($settings->description !== '') {
            $introownerdir = $metapath !== null ? safe_path::package_dir($this->packageroot, $metapath) : '';
            $newintro = (new file_embedder($this->packageroot, $this->mediareport))
                ->embed($context->id, 'mod_quiz', 'intro', $intro, 0, $introownerdir);
            if ($newintro !== $intro) {
                $DB->set_field('quiz', 'intro', $newintro, ['id' => (int) $created->instance]);
            }
        }

        if ($isplaceholder) {
            // No questions to import; leave the hidden placeholder for a teacher.
            return $cmid;
        }

        $questionids = (new question_importer())
            ->import($course, $context, $supported, $imagedir, $this->packageroot, $this->mediareport);
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

        // Record the imported questions so course_builder can resolve any
        // $WIKI_REFERENCE$/$CANVAS_OBJECT_REFERENCE$ links in their text once
        // every activity exists (the URL map is incomplete during this build).
        $this->importedquestionids = array_merge($this->importedquestionids, array_map('intval', $questionids));

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
     * Map the parsed Canvas quiz settings onto the moduleinfo keys that should
     * override quiz_defaults(). Only keys Canvas actually specified are
     * returned, so an absent assessment_meta.xml (or a field Canvas omitted)
     * leaves the generic default in place.
     *
     * @param quiz_settings $settings Parsed assessment_meta.xml.
     * @return array Sparse moduleinfo overrides, merged over quiz_defaults().
     */
    private function settings_overlay(quiz_settings $settings): array {
        $overlay = [];
        if ($settings->timelimit > 0) {
            // Canvas records minutes; Moodle stores the limit in seconds.
            $overlay['timelimit'] = $settings->timelimit * 60;
        }
        if ($settings->allowedattempts !== 0) {
            // Canvas -1 (unlimited) maps to Moodle's 0; a positive count carries over.
            $overlay['attempts'] = $settings->allowedattempts < 0 ? 0 : $settings->allowedattempts;
        }
        $grademethod = $this->map_scoring_policy($settings->scoringpolicy);
        if ($grademethod !== null) {
            $overlay['grademethod'] = $grademethod;
        }
        if ($settings->haspoints) {
            // Carry the Canvas maximum, including an explicit 0 — a zero-point
            // (ungraded) quiz or survey must not inherit the 100-point default.
            $overlay['grade'] = $settings->points;
        }
        if ($settings->shuffleanswers !== null) {
            $overlay['shuffleanswers'] = $settings->shuffleanswers ? 1 : 0;
        }
        if ($settings->onequestionatatime !== null) {
            // One question per page -> 1; all questions on one page -> 0.
            $overlay['questionsperpage'] = $settings->onequestionatatime ? 1 : 0;
        }
        if ($settings->cantgoback !== null) {
            $overlay['navmethod'] = $settings->cantgoback ? QUIZ_NAVMETHOD_SEQ : QUIZ_NAVMETHOD_FREE;
        }
        if ($settings->accesscode !== '') {
            $overlay['quizpassword'] = $settings->accesscode;
        }
        if ($settings->ipfilter !== '') {
            $overlay['subnet'] = $settings->ipfilter;
        }
        if ($settings->unlockat !== 0) {
            $overlay['timeopen'] = $settings->unlockat;
        }
        $close = $settings->close_time();
        if ($close !== 0) {
            $overlay['timeclose'] = $close;
        }
        // When Canvas hides the correct answers, switch off the right-answer
        // review option at every phase; the other review defaults stand.
        if ($settings->showcorrectanswers === false) {
            foreach (['during', 'immediately', 'open', 'closed'] as $when) {
                $overlay['rightanswer' . $when] = 0;
            }
        }
        $this->apply_hide_results($settings, $overlay);
        return $overlay;
    }

    /**
     * Honour Canvas's hide_results setting by clearing the review options that
     * reveal a student's results — score, per-question correctness, feedback and
     * the right answer. 'always' hides them at every phase; 'until_after_last_
     * attempt' hides them while the quiz is in progress or open and reveals them
     * only once it has closed. The attempt-review option is left untouched so a
     * student can still see the questions. Moodle has no exact equivalent of
     * Canvas's per-attempt gate, so this is a faithful best effort.
     *
     * @param quiz_settings $settings Parsed assessment_meta.xml.
     * @param array $overlay Moodle review flags to set (modified in place).
     * @return void
     */
    private function apply_hide_results(quiz_settings $settings, array &$overlay): void {
        if ($settings->hideresults === 'always') {
            // Never reveal results: clear every review option at all four
            // phases. This includes the attempt review itself — left on, it
            // would still expose the questions and the student's own responses.
            $whens = ['during', 'immediately', 'open', 'closed'];
        } else if ($settings->hideresults === 'until_after_last_attempt') {
            // Show results only after the student's last attempt. Moodle can't
            // gate review on "attempts exhausted", so approximate by attempt
            // count, always hiding during the attempt and the immediate
            // post-submit window. For a single attempt (the common case) the
            // first attempt is the last, so reveal results in the open phase —
            // and crucially do NOT clear that phase, which would hide them
            // forever when no close date is set. For multiple or unlimited
            // attempts a non-final attempt would otherwise expose results in
            // the open phase before the last attempt, so hide that phase too and
            // reveal only once the quiz has closed. Canvas always writes
            // allowed_attempts, defaulting to a single attempt when absent.
            $multipleattempts = $settings->allowedattempts === -1 || $settings->allowedattempts >= 2;
            $whens = $multipleattempts ? ['during', 'immediately', 'open'] : ['during', 'immediately'];
        } else {
            return;
        }
        $fields = ['attempt', 'correctness', 'maxmarks', 'marks', 'specificfeedback', 'generalfeedback',
            'rightanswer', 'overallfeedback'];
        foreach ($whens as $when) {
            foreach ($fields as $field) {
                $overlay[$field . $when] = 0;
            }
        }
    }

    /**
     * Map a Canvas scoring_policy to a Moodle grademethod constant.
     *
     * @param string $policy Canvas scoring_policy (keep_highest/keep_latest/keep_average).
     * @return int|null The QUIZ_* grademethod constant, or null when unrecognised/unset.
     */
    private function map_scoring_policy(string $policy): ?int {
        switch ($policy) {
            case 'keep_highest':
                return QUIZ_GRADEHIGHEST;
            case 'keep_latest':
                return QUIZ_ATTEMPTLAST;
            case 'keep_average':
                return QUIZ_GRADEAVERAGE;
        }
        return null;
    }

    /**
     * Parse a QTI assessment file into its question buckets: every parsed
     * question, those of a Moodle-supported type, and those Moodle can actually
     * import as they stand.
     *
     * @param string $path Absolute path to the QTI assessment XML.
     * @return array A three-element list: [parsed array, supported[], importable[]].
     */
    private function parse_qti(string $path): array {
        $parsed = (new qti_parser())->parse((string) @file_get_contents($path));
        $supported = array_filter($parsed['questions'], fn($q) => $q->type !== qti_question::TYPE_UNSUPPORTED);
        $importable = array_filter($supported, fn($q) => $q->is_importable());
        return [$parsed, $supported, $importable];
    }

    /**
     * Find the native Canvas question dump for this quiz, used when the Common
     * Cartridge assessment_qti.xml is an empty shell. Canvas writes the real
     * questions to non_cc_assessments/<resource-id>.xml.qti at the package root,
     * keyed by the same id as the quiz's CC folder. Prefer an explicit
     * non_cc_assessments entry on the item's file list, then fall back to
     * deriving the id from the resolved QTI folder. Never return the file we
     * already parsed.
     *
     * @param item $modelitem The quiz item.
     * @param string $qtipath Absolute path of the resolved CC QTI file.
     * @return string|null Absolute path within the package, or null.
     */
    private function locate_native_qti(item $modelitem, string $qtipath): ?string {
        $already = realpath($qtipath);
        foreach ($modelitem->files as $relative) {
            if (!preg_match('~(^|/)non_cc_assessments/[^/]+\.xml\.qti$~i', $relative)) {
                continue;
            }
            $absolute = safe_path::within($this->packageroot, $relative);
            if ($absolute !== null && is_readable($absolute) && realpath($absolute) !== $already) {
                return $absolute;
            }
        }
        // Canvas keys the native dump by the assessment id, which is the CC folder
        // name for a foldered QTI but the resource identifier when the QTI sits at
        // the package root (where basename(dirname()) yields the extraction dir,
        // not the id). Try both.
        $ids = [basename(dirname($qtipath)), $modelitem->identifier];
        foreach ($ids as $id) {
            if ($id === '' || $id === '.' || $id === '/') {
                continue;
            }
            $candidate = safe_path::within($this->packageroot, 'non_cc_assessments/' . $id . '.xml.qti');
            if ($candidate !== null && is_readable($candidate) && realpath($candidate) !== $already) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Locate the assessment_meta.xml carrying this quiz's configuration. Canvas
     * ships it as a sibling of the QTI assessment file (in the same folder),
     * exported as a separate learning-application resource that the manifest
     * parser suppresses — so it is not on the model item's file list. Prefer an
     * explicit assessment_meta.xml entry when one is present, otherwise look
     * beside the QTI file we already resolved.
     *
     * @param item $modelitem The quiz item.
     * @param string $qtipath Absolute path of the resolved QTI assessment file.
     * @return string|null Absolute path within the package, or null.
     */
    private function locate_meta(item $modelitem, string $qtipath): ?string {
        foreach ($modelitem->files as $relative) {
            if (!str_ends_with($relative, 'assessment_meta.xml')) {
                continue;
            }
            $absolute = safe_path::within($this->packageroot, $relative);
            if ($absolute !== null && is_readable($absolute)) {
                return $absolute;
            }
        }
        $sibling = dirname($qtipath) . '/assessment_meta.xml';
        return is_readable($sibling) ? $sibling : null;
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
            // The sibling assessment_meta.xml is also an .xml on the file list;
            // it carries the quiz configuration, not the questions, so never
            // accept it as the QTI document (which would parse zero questions
            // and drop the quiz).
            if (str_ends_with($relative, 'assessment_meta.xml')) {
                continue;
            }
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
