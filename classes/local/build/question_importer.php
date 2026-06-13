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
     * Import questions into the default category of a context.
     *
     * @param stdClass $course Course record (qformat needs it for context).
     * @param \context $context The context whose default category receives the questions.
     * @param array $questions Supported {@see \tool_canvasuplifter\local\model\qti_question} objects.
     * @param string $imagedir Folder for resolving question images.
     * @return array The ids of the questions created, in import order.
     */
    public function import(stdClass $course, \context $context, array $questions, string $imagedir): array {
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

        $xml = (new question_xml_writer())->to_moodle_xml($questions, $category->name, $imagedir);
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
