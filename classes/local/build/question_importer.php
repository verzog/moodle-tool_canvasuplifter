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
     * @return array The ids of the questions created, in import order.
     */
    public function import(stdClass $course, \context $context, array $questions, string $imagedir,
            ?string $filebase = null): array {
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

        $xml = (new question_xml_writer())->to_moodle_xml($questions, $category->name, $imagedir, $filebase);
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
        return $ids;
    }
}
