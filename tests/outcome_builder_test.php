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

use tool_canvasuplifter\local\build\outcome_builder;

/**
 * Tests importing Canvas learning outcomes as Moodle course grade outcomes.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\build\outcome_builder
 */
final class outcome_builder_test extends \advanced_testcase {
    /**
     * Write a package with the given learning_outcomes.xml body.
     *
     * @param string $xml The learning_outcomes.xml contents.
     * @return string The package root directory.
     */
    private function package(string $xml): string {
        $dir = make_request_directory();
        mkdir($dir . '/course_settings', 0777, true);
        file_put_contents($dir . '/course_settings/learning_outcomes.xml', $xml);
        return $dir;
    }

    /** A learning_outcomes.xml with one outcome and a five-level mastery scale. */
    private const OUTCOMES = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<learningOutcomes xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
        . '<learningOutcomeGroup identifier="g1"><title>Group 1</title><learningOutcomes>'
        . '<learningOutcome identifier="o1"><title>New outcome</title>'
        . '<description>&lt;p&gt;This is a new outcome&lt;/p&gt;</description>'
        . '<ratings>'
        . '<rating><description>Exceeds Mastery</description><points>4.0</points></rating>'
        . '<rating><description>Mastery</description><points>3.0</points></rating>'
        . '<rating><description>Near Mastery</description><points>2.0</points></rating>'
        . '<rating><description>Below Mastery</description><points>1.0</points></rating>'
        . '<rating><description>No Evidence</description><points>0.0</points></rating>'
        . '</ratings></learningOutcome>'
        . '</learningOutcomes></learningOutcomeGroup></learningOutcomes>';

    /**
     * An outcome is created as a course grade outcome, backed by a scale whose
     * items run lowest-to-highest (Canvas lists them highest-first).
     *
     * @return void
     */
    public function test_creates_outcome_and_scale(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $created = (new outcome_builder($this->package(self::OUTCOMES)))->build($course);

        $this->assertSame(1, $created);
        $outcome = $DB->get_record('grade_outcomes', ['courseid' => $course->id], '*', MUST_EXIST);
        $this->assertSame('New outcome', $outcome->fullname);
        $this->assertSame('<p>This is a new outcome</p>', $outcome->description);

        $scale = $DB->get_record('scale', ['id' => $outcome->scaleid], '*', MUST_EXIST);
        $this->assertSame((int) $course->id, (int) $scale->courseid);
        // Canvas lists mastery highest-first; the Moodle scale runs low-to-high.
        $this->assertSame(
            'No Evidence,Below Mastery,Near Mastery,Mastery,Exceeds Mastery',
            $scale->scale
        );
    }

    /**
     * A package with no learning_outcomes.xml creates nothing.
     *
     * @return void
     */
    public function test_no_outcomes_file_creates_nothing(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $created = (new outcome_builder(make_request_directory()))->build($course);

        $this->assertSame(0, $created);
        $this->assertSame(0, $DB->count_records('grade_outcomes', ['courseid' => $course->id]));
    }

    /**
     * An outcome whose ratings can't form a scale of at least two items is
     * skipped (Moodle scales need two or more items) rather than failing.
     *
     * @return void
     */
    public function test_outcome_with_too_few_ratings_is_skipped(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<learningOutcomes xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<learningOutcomeGroup identifier="g1"><title>Group 1</title><learningOutcomes>'
            . '<learningOutcome identifier="o1"><title>Thin</title><description></description>'
            . '<ratings><rating><description>Only one</description><points>1</points></rating></ratings>'
            . '</learningOutcome></learningOutcomes></learningOutcomeGroup></learningOutcomes>';

        $created = (new outcome_builder($this->package($xml)))->build($course);

        $this->assertSame(0, $created);
        $this->assertSame(0, $DB->count_records('grade_outcomes', ['courseid' => $course->id]));
    }

    /**
     * Two outcomes sharing a name both import, the second getting a suffixed
     * shortname so the unique-shortname constraint is never violated.
     *
     * @return void
     */
    public function test_duplicate_names_get_unique_shortnames(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $one = '<learningOutcome identifier="%s"><title>Same</title><description></description>'
            . '<ratings><rating><description>Yes</description><points>1</points></rating>'
            . '<rating><description>No</description><points>0</points></rating></ratings></learningOutcome>';
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<learningOutcomes xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<learningOutcomeGroup identifier="g1"><title>G</title><learningOutcomes>'
            . sprintf($one, 'o1') . sprintf($one, 'o2')
            . '</learningOutcomes></learningOutcomeGroup></learningOutcomes>';

        $created = (new outcome_builder($this->package($xml)))->build($course);

        $this->assertSame(2, $created);
        $shortnames = $DB->get_fieldset_select(
            'grade_outcomes',
            'shortname',
            'courseid = ?',
            [$course->id]
        );
        $this->assertCount(2, array_unique($shortnames));
    }
}
