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
use tool_canvasuplifter\local\model\qti_question;

/**
 * Imports parsed QTI questions into a Moodle question category.
 *
 * Renders the questions to Moodle import XML ({@see question_xml_writer}) and
 * loads them through Moodle's own qformat_xml importer into the default question
 * category of the given context, returning the created question ids. Shared by
 * the question-bank and quiz builders.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_importer {
    /**
     * Summarise why a set of parsed questions yielded nothing importable, for
     * the skip report: how many were parsed, how many were a supported Moodle
     * type, and the Canvas profiles (or types) of those that can't convert.
     *
     * @param array $all All parsed qti_question objects.
     * @param array $supported Those of a supported Moodle type.
     * @param int $unresolved Count of bare item references whose body is absent.
     * @return string
     */
    public static function describe_unconvertible(array $all, array $supported, int $unresolved = 0): string {
        if (count($all) === 0) {
            // Bare references mean Canvas listed questions but did not export
            // their bodies (typical of New Quizzes); say so rather than calling
            // the assessment empty, so the data loss is visible.
            if ($unresolved > 0) {
                return sprintf(
                    'references %d question(s) whose content is not present in the package '
                        . '(not exported by Canvas)',
                    $unresolved
                );
            }
            // A truly empty assessment (e.g. an exam shell with an empty
            // <section/>) is not a conversion failure; say so plainly.
            return 'assessment contains no questions';
        }
        $profiles = [];
        foreach ($all as $question) {
            if ($question->is_importable()) {
                continue;
            }
            $label = $question->profile !== '' ? $question->profile
                : ($question->type !== qti_question::TYPE_UNSUPPORTED ? $question->type : '(unknown)');
            $profiles[$label] = ($profiles[$label] ?? 0) + 1;
        }
        $parts = [];
        foreach ($profiles as $label => $count) {
            $parts[] = $label . ' (' . $count . ')';
        }
        return sprintf(
            'no convertible questions: %d parsed, %d of a supported type, 0 importable; unconvertible: %s',
            count($all),
            count($supported),
            $parts ? implode(', ', $parts) : 'none'
        );
    }

    /**
     * Import questions into the default category of a context.
     *
     * @param stdClass $course Course record (qformat needs it for context).
     * @param \context $context The context whose default category receives the questions.
     * @param array $questions Supported {@see \tool_canvasuplifter\local\model\qti_question} objects.
     * @param string $imagedir Folder for resolving relative question images.
     * @param string|null $filebase Package root for resolving $IMS-CC-FILEBASE$ image tokens.
     * @param media_report|null $mediareport Shared collector for question media absent from the package.
     * @return array The ids of the questions created, in import order.
     */
    public function import(
        stdClass $course,
        \context $context,
        array $questions,
        string $imagedir,
        ?string $filebase = null,
        ?media_report $mediareport = null
    ): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/question/format.php');
        require_once($CFG->dirroot . '/question/format/xml/format.php');

        // Drop questions Moodle's importer would reject (e.g. a choice question
        // with fewer than two answers). One rejected question makes qformat_xml
        // roll the in-progress transaction back and abandon the rest of the
        // batch, so filtering them out up front keeps the good questions.
        $questions = array_values(array_filter($questions, fn($q) => $q->is_importable()));
        if (empty($questions)) {
            return [];
        }

        $category = question_get_default_category($context->id, true);
        $contexts = new \core_question\local\bank\question_edit_contexts($context);

        // Missing question media is recorded into the caller's report. The quiz/bank
        // builders pass a provisional report they only merge into the shared build
        // report once the activity is kept, so a rejected-and-deleted import reports
        // nothing; keeping that decision in the builder also covers the intro media the
        // builder embeds before calling here.
        $xml = (new question_xml_writer())->to_moodle_xml($questions, $category->name, $imagedir, $filebase, $mediareport);
        $dir = make_request_directory();
        $file = $dir . '/questions.xml';
        file_put_contents($file, $xml);

        $qformat = new \qformat_xml();
        $qformat->setCategory($category);
        $qformat->setContexts($contexts->having_one_edit_tab_cap('import'));
        $qformat->setCourse($course);
        $qformat->setFilename($file);
        $qformat->setRealfilename('questions.xml');
        $qformat->setMatchgrades('nearest');
        $qformat->setCatfromfile(false);
        $qformat->setContextfromfile(false);
        $qformat->setStoponerror(false);

        // The qformat importer echoes import progress/notifications; keep it out of the build output.
        ob_start();
        try {
            if ($qformat->importpreprocess()) {
                $qformat->importprocess();
                $qformat->importpostprocess();
            }
        } finally {
            ob_end_clean();
        }
        // The qformat importer can leave ids of rolled-back questions in its
        // list; only return ids that correspond to a stored question.
        $ids = [];
        foreach ($qformat->questionids as $id) {
            if ($DB->record_exists('question', ['id' => $id])) {
                $ids[] = (int) $id;
            }
        }
        $this->restore_cloze_marks($questions, $ids);
        return $ids;
    }

    /**
     * Restore the Canvas mark on imported Cloze (multianswer) questions.
     *
     * Moodle's multianswer importer derives the question's default mark from the sum
     * of its inline field weights (one per blank), overriding the Canvas
     * points_possible the writer emitted — so a three-blank question worth one point
     * in Canvas would become a three-mark question, skewing its weight in a quiz. The
     * per-blank fractions are unaffected (multianswer grading returns a fraction
     * normalised by the field weights), so resetting the question's default mark to
     * the Canvas value restores the intended weight while keeping the blanks even.
     *
     * The mark is applied per created id, matched to its model by the (plain) question
     * name Moodle stored, rather than by list position — so a batch where qformat_xml
     * skipped some questions (leaving fewer ids than models) still restores the mark on
     * every surviving Cloze. A name shared by Cloze questions that carry different Canvas
     * marks is left untouched (the imported questions can't be told apart); a repeated
     * name whose occurrences agree on the mark is still restored, since the value is the
     * same whichever question it lands on.
     *
     * @param array $questions The imported model questions, in import order.
     * @param array $ids The created question ids.
     * @return void
     */
    private function restore_cloze_marks(array $questions, array $ids): void {
        global $DB;
        $marks = [];
        $ambiguous = [];
        foreach ($questions as $question) {
            if ($question->type !== qti_question::TYPE_CLOZE) {
                continue;
            }
            $name = $this->plain_name((string) $question->name);
            $mark = max(0.0, (float) $question->defaultmark);
            // A repeated name is only ambiguous when its occurrences disagree on the
            // mark: identical name-to-mark mappings resolve to the same value whichever
            // imported question they land on, so they are safe to restore. Only a name
            // that maps to two different marks can't be told apart and is left alone.
            if (array_key_exists($name, $marks) && abs($marks[$name] - $mark) > 1e-9) {
                $ambiguous[$name] = true;
            }
            $marks[$name] = $mark;
        }
        if ($marks === []) {
            return;
        }
        foreach ($ids as $id) {
            $record = $DB->get_record('question', ['id' => $id], 'id, name, qtype');
            if ($record === false || $record->qtype !== 'multianswer') {
                continue;
            }
            if (isset($ambiguous[$record->name]) || !isset($marks[$record->name])) {
                continue;
            }
            if ($marks[$record->name] > 0) {
                $DB->set_field('question', 'defaultmark', $marks[$record->name], ['id' => $id]);
            }
        }
    }

    /**
     * The plain-text form of a question name, matching how {@see question_xml_writer}
     * emits it into the import XML, so an imported question can be matched back to its
     * model by the name Moodle actually stored.
     *
     * @param string $name The raw model question name.
     * @return string
     */
    private function plain_name(string $name): string {
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($name, ENT_QUOTES | ENT_HTML5))));
        return $text !== '' ? $text : 'Question';
    }
}
