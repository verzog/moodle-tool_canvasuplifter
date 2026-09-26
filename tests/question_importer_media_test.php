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

namespace tool_canvasuplifter;

use tool_canvasuplifter\local\build\media_report;
use tool_canvasuplifter\local\build\question_importer;
use tool_canvasuplifter\local\model\qti_question;

/**
 * Tests that the question importer claims question media only for questions Moodle stored.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\build\question_importer
 */
final class question_importer_media_test extends \advanced_testcase {
    /**
     * A two-option multiple choice question whose stem embeds the given package image.
     *
     * @param string $name The question name.
     * @param string $image The image file name at the package root.
     * @return qti_question
     */
    private function question(string $name, string $image): qti_question {
        $question = new qti_question();
        $question->type = qti_question::TYPE_MULTICHOICE;
        $question->name = $name;
        $question->questiontext = '<p><img src="$IMS-CC-FILEBASE$/' . $image . '"></p>';
        $question->answers = [
            ['text' => 'Right', 'fraction' => 1.0, 'feedback' => ''],
            ['text' => 'Wrong', 'fraction' => 0.0, 'feedback' => ''],
        ];
        return $question;
    }

    /**
     * When Moodle stores every question, all their media counts as embedded; when it rejects
     * one, only the stored question's media does, so the rejected question's image is left for
     * course_builder to recover as a download.
     *
     * @return void
     */
    public function test_media_of_rejected_question_is_not_claimed(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $context = \context_module::instance($qbank->cmid);
        $dir = make_request_directory();
        file_put_contents($dir . '/kept.png', 'PNG');
        file_put_contents($dir . '/lost.png', 'PNG');
        $questions = [$this->question('Kept', 'kept.png'), $this->question('Lost', 'lost.png')];

        $report = new media_report();
        $ids = (new question_importer())->import($course, $context, $questions, $dir, $dir, $report);
        $this->assertCount(2, $ids);
        $this->assertTrue($report->was_embedded(realpath($dir . '/kept.png')));
        $this->assertTrue($report->was_embedded(realpath($dir . '/lost.png')));

        // Simulate Moodle rejecting the second question of the batch.
        $importer = new class extends question_importer {
            /**
             * Import as usual, then drop the last stored question as if Moodle rejected it.
             *
             * @param \stdClass $course Course record.
             * @param \stdClass $category The question category.
             * @param \core_question\local\bank\question_edit_contexts $contexts Edit contexts.
             * @param string $file Path of the Moodle XML file.
             * @return int[]
             */
            protected function run_import(
                \stdClass $course,
                \stdClass $category,
                \core_question\local\bank\question_edit_contexts $contexts,
                string $file
            ): array {
                $ids = parent::run_import($course, $category, $contexts, $file);
                question_delete_question((int) array_pop($ids));
                return $ids;
            }
        };
        $report = new media_report();
        $ids = $importer->import($course, $context, $questions, $dir, $dir, $report);
        $this->assertCount(1, $ids);
        $this->assertTrue($report->was_embedded(realpath($dir . '/kept.png')));
        $this->assertFalse($report->was_embedded(realpath($dir . '/lost.png')));
    }
}
