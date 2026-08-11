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
    use qti_source_locator;

    /** @var string|null Why the last build() returned null, for the skip report. */
    public ?string $skipreason = null;

    /** @var int How many quizzes were built as empty hidden placeholders (no importable questions). */
    public int $placeholdercount = 0;

    /** @var int[] Ids of every question imported across all build() calls, for the link-rewrite pass. */
    public array $importedquestionids = [];

    /** @var int How many quizzes were populated by drawing from a Canvas item bank (New Quizzes). */
    public int $bankdrawcount = 0;

    /** @var int How many bank-backed quizzes are missing a group (a referenced bank was absent/short). */
    public int $bankincompletecount = 0;

    /** @var array Canvas item-bank id => ['category' => int, 'count' => int] once imported (null if it failed), memoised. */
    private array $bankcategories = [];

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
                // Adopt the native dump when it yields inline questions or bank
                // selections — a New Quiz that draws solely from item banks has no
                // inline importables, but its <selection_ordering> is the real content.
                $nativeselections = ($nativeparsed['hasassessment'] ?? false)
                    ? ($nativeparsed['selections'] ?? []) : [];
                if (!empty($nativeimportable) || !empty($nativeselections)) {
                    $parsed = $nativeparsed;
                    $supported = $nativesupported;
                    $importable = $nativeimportable;
                    // Native questions reference media under the package root.
                    $imagedir = $this->packageroot;
                }
            }
        }
        $questions = $parsed['questions'];

        // A Canvas New Quiz can draw questions from a separate item bank via
        // <selection_ordering>, on its own or alongside inline questions. Trust bank
        // draws only from a genuine QTI 1.2 assessment, so stray <selection> markup in
        // malformed or non-assessment XML can't fabricate a quiz.
        $selections = ($parsed['hasassessment'] ?? false) ? ($parsed['selections'] ?? []) : [];
        $hasbanks = !empty($selections);

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
        if (empty($importable) && !$isshell && !$hasbanks) {
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
        $isplaceholder = $isshell && !$hasbanks;
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
        // Collect any missing intro/question media into a provisional report; it is only
        // merged into the shared build report if this quiz survives (below), so a quiz
        // whose questions are all rejected and then deleted reports no phantom assets.
        $localreport = $this->mediareport !== null ? new media_report() : null;
        // Import any files the description embeds and rewrite the intro to
        // pluginfile refs, mirroring assign_builder's handling. The description
        // comes from assessment_meta.xml, so its owner-relative media resolves
        // against that file's own folder.
        if ($settings->description !== '') {
            $introownerdir = $metapath !== null ? safe_path::package_dir($this->packageroot, $metapath) : '';
            $newintro = (new file_embedder($this->packageroot, $localreport))
                ->embed($context->id, 'mod_quiz', 'intro', $intro, 0, $introownerdir);
            if ($newintro !== $intro) {
                $DB->set_field('quiz', 'intro', $newintro, ['id' => (int) $created->instance]);
            }
        }

        if ($isplaceholder) {
            // No questions to import; leave the hidden placeholder for a teacher.
            $this->promote_media($localreport);
            return $cmid;
        }

        $quiz = $DB->get_record('quiz', ['id' => $created->instance], '*', MUST_EXIST);
        $quiz->cmid = $cmid;
        $slots = 0;

        // Inline questions, when the assessment carries any (a New Quiz may still add
        // bank draws below).
        if (!empty($importable)) {
            $questionids = (new question_importer())
                ->import($course, $context, $supported, $imagedir, $this->packageroot, $localreport);
            if (!empty($questionids)) {
                // Record the imported questions so course_builder can resolve any
                // $WIKI_REFERENCE$/$CANVAS_OBJECT_REFERENCE$ links in their text once
                // every activity exists (the URL map is incomplete during this build).
                $this->importedquestionids = array_merge($this->importedquestionids, array_map('intval', $questionids));
                foreach ($questionids as $questionid) {
                    quiz_add_quiz_question((int) $questionid, $quiz);
                }
                $slots += count($questionids);
            }
        }

        // Item-bank draws (Canvas New Quizzes), which can accompany inline questions.
        $incomplete = false;
        if ($hasbanks) {
            [$drawn, $incomplete] = $this->populate_from_banks($course, $quiz, $selections);
            if ($drawn > 0) {
                $this->bankdrawcount++;
            }
            $slots += $drawn;
        }

        if ($slots === 0) {
            // Nothing landed. Keep a New Quiz whose banks couldn't be resolved as a
            // hidden placeholder (its settings are preserved); drop a plain quiz whose
            // inline questions were all rejected. Provisional media is dropped with it.
            if ($hasbanks) {
                $this->make_placeholder($cmid, (int) $created->instance);
                $this->promote_media($localreport);
                return $cmid;
            }
            course_delete_module($cmid);
            $this->skipreason = sprintf(
                "Moodle's importer rejected all %d convertible question(s)",
                count($importable)
            );
            return null;
        }
        if ($incomplete) {
            // A referenced bank was missing or held fewer questions than Canvas asked
            // for, so the quiz is short of a group; flag it for a grader.
            $this->bankincompletecount++;
        }
        \mod_quiz\quiz_settings::create((int) $quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
        $this->promote_media($localreport);
        return $cmid;
    }

    /**
     * Merge a kept quiz's provisional missing-media report into the shared build
     * report. A no-op when media tracking is off or nothing was collected.
     *
     * @param media_report|null $localreport The quiz's provisional report, or null.
     * @return void
     */
    private function promote_media(?media_report $localreport): void {
        if ($this->mediareport !== null && $localreport !== null) {
            $this->mediareport->merge($localreport);
        }
    }

    /**
     * Draw random questions for a New Quiz from each referenced Canvas item bank.
     * Each bank is imported once (as a section-0 mod_qbank) and shared across quizzes;
     * the number drawn is capped per bank at the questions it imported, tracked
     * cumulatively so several groups sharing a bank never over-draw it.
     *
     * @param stdClass $course Course record.
     * @param stdClass $quiz Quiz record (with cmid set).
     * @param array $selections Parsed selections: each ['bank' => id, 'count' => n|null, 'points' => p|null].
     * @return array [total random questions added, whether any group was missing/short].
     */
    private function populate_from_banks(stdClass $course, stdClass $quiz, array $selections): array {
        $drawn = 0;
        $incomplete = false;
        $remaining = [];
        foreach ($selections as $selection) {
            $bankid = (string) $selection['bank'];
            if ($selection['count'] !== null && (int) $selection['count'] < 1) {
                // Canvas authored an explicit zero-question draw — an empty group. Skip
                // it without importing the bank or flagging the quiz incomplete.
                continue;
            }
            $bank = $this->import_bank($course, $bankid);
            if ($bank === null) {
                $incomplete = true;
                continue;
            }
            if (!$bank['full']) {
                // Some source questions in the bank couldn't be imported, so the pool
                // Moodle draws from is smaller than Canvas's; flag the shortfall.
                $incomplete = true;
            }
            if (!array_key_exists($bankid, $remaining)) {
                $remaining[$bankid] = $bank['count'];
            }
            // A missing selection_number means "draw the whole bank"; an explicit count
            // caps the draw (and is honoured verbatim, including down to the bank size).
            $want = $selection['count'] === null ? $bank['count'] : (int) $selection['count'];
            $number = min($want, $remaining[$bankid]);
            if ($number < 1) {
                $incomplete = true;
                continue;
            }
            if ($number < $want) {
                // The bank holds fewer questions than this group asked for.
                $incomplete = true;
            }
            $this->add_bank_questions($quiz, $bank['category'], $number, $selection['points']);
            $remaining[$bankid] -= $number;
            $drawn += $number;
        }
        return [$drawn, $incomplete];
    }

    /**
     * Add a number of random questions to the quiz drawing from one bank category, then
     * apply the Canvas per-item points to the slots just added (add_random_questions
     * gives each a max mark of 1) so grade weights carry over.
     *
     * @param stdClass $quiz Quiz record (with cmid set).
     * @param int $categoryid The question category to draw from.
     * @param int $number How many random questions to add.
     * @param float|null $points The per-item points, or null to keep the default.
     * @return void
     */
    private function add_bank_questions(stdClass $quiz, int $categoryid, int $number, ?float $points): void {
        global $DB;
        $quizid = (int) $quiz->id;
        $before = (int) $DB->get_field_sql('SELECT COALESCE(MAX(slot), 0) FROM {quiz_slots} WHERE quizid = ?', [$quizid]);
        // Fresh structure per call so it never draws on stale cached slots.
        \mod_quiz\quiz_settings::create($quizid)->get_structure()
            ->add_random_questions(0, $number, $this->random_filter($categoryid));
        // Honour an explicit per-item weight, including a genuine zero; only a
        // missing points_per_item leaves Moodle's default maxmark untouched.
        if ($points !== null) {
            $DB->set_field_select('quiz_slots', 'maxmark', $points, 'quizid = ? AND slot > ?', [$quizid, $before]);
        }
    }

    /**
     * The question-bank filter condition selecting one category, in the shape
     * mod_quiz\structure::add_random_questions() expects.
     *
     * @param int $categoryid The question category to draw from.
     * @return array
     */
    private function random_filter(int $categoryid): array {
        return [
            'filter' => [
                'category' => [
                    'jointype' => \core_question\local\bank\condition::JOINTYPE_DEFAULT,
                    'values' => [$categoryid],
                    'filteroptions' => ['includesubcategories' => false],
                ],
            ],
        ];
    }

    /**
     * Import a Canvas item bank (non_cc_assessments/<id>.xml.qti) as a section-0
     * mod_qbank and return its default category id plus the number of questions
     * imported. Memoised by bank id so a bank shared by several New Quizzes is imported
     * once; a bank that can't be read or yields nothing importable memoises (and
     * returns) null.
     *
     * @param stdClass $course Course record.
     * @param string $bankid The Canvas sourcebank_ref (a package resource id).
     * @return array|null ['category' => int, 'count' => int, 'full' => bool], or null when unavailable.
     */
    private function import_bank(stdClass $course, string $bankid): ?array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/questionlib.php');
        if (array_key_exists($bankid, $this->bankcategories)) {
            return $this->bankcategories[$bankid];
        }
        $this->bankcategories[$bankid] = null;
        $file = safe_path::within($this->packageroot, 'non_cc_assessments/' . $bankid . '.xml.qti');
        if ($file === null || !is_readable($file)) {
            return null;
        }
        [$parsed, $supported, $importable] = $this->parse_qti($file);
        if (empty($importable)) {
            return null;
        }
        $module = $DB->get_record('modules', ['name' => 'qbank']);
        if (!$module) {
            return null;
        }
        $moduleinfo = (object) [
            'modulename' => 'qbank',
            'module' => $module->id,
            'course' => $course->id,
            // Question banks are course-bank activities, kept in section 0 like
            // questionbank_builder's, not scattered into the quiz's topic section.
            'section' => 0,
            'visible' => 1,
            'name' => $parsed['title'] !== '' ? $parsed['title'] : $this->default_bank_name(),
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'type' => \core_question\local\bank\question_bank_helper::TYPE_STANDARD,
        ];
        $created = add_moduleinfo($moduleinfo, $course);
        $context = \context_module::instance((int) $created->coursemodule);
        // Native bank questions reference media under the package root.
        $localreport = $this->mediareport !== null ? new media_report() : null;
        $questionids = (new question_importer())
            ->import($course, $context, $supported, $this->packageroot, $this->packageroot, $localreport);
        if (empty($questionids)) {
            course_delete_module((int) $created->coursemodule);
            return null;
        }
        if ($this->mediareport !== null && $localreport !== null) {
            $this->mediareport->merge($localreport);
        }
        $this->importedquestionids = array_merge($this->importedquestionids, array_map('intval', $questionids));
        $category = question_get_default_category($context->id, true);
        // The 'full' flag records whether every source question in the bank imported.
        // When the bank holds unsupported types (or the importer rejects some), Moodle's
        // pool is smaller than the one Canvas drew from, so a group sourcing this bank
        // must be reported as incomplete even when it can still fill its requested count.
        $this->bankcategories[$bankid] = [
            'category' => (int) $category->id,
            'count' => count($questionids),
            'full' => count($questionids) === count($parsed['questions']) && (int) ($parsed['unresolved'] ?? 0) === 0,
        ];
        return $this->bankcategories[$bankid];
    }

    /**
     * Turn an already-built quiz into a hidden placeholder (no bank resolved to
     * importable questions), prepending the teacher note to its stored intro.
     *
     * @param int $cmid The quiz course module id.
     * @param int $quizinstance The quiz instance id.
     * @return void
     */
    private function make_placeholder(int $cmid, int $quizinstance): void {
        global $DB;
        $this->placeholdercount++;
        set_coursemodule_visible($cmid, 0);
        // Prepend the teacher note to whatever intro is stored now (already rewritten to
        // pluginfile refs by the embedder), so the embedded media survives.
        $current = (string) $DB->get_field('quiz', 'intro', ['id' => $quizinstance]);
        $note = get_string('quizplaceholderintro', 'tool_canvasuplifter');
        $DB->set_field('quiz', 'intro', $note . $current, ['id' => $quizinstance]);
    }

    /**
     * The default name for an imported item bank when Canvas exported it untitled.
     *
     * @return string
     */
    private function default_bank_name(): string {
        return get_string('quizbankname', 'tool_canvasuplifter');
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
