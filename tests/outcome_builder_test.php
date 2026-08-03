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

        $builder = new outcome_builder($this->package($xml));
        $created = $builder->build($course);

        $this->assertSame(0, $created);
        // The dropped outcome is counted so the build report can flag the loss.
        $this->assertSame(1, $builder->skippedcount);
        $this->assertSame(0, $DB->count_records('grade_outcomes', ['courseid' => $course->id]));
    }

    /**
     * A rating label whose ratings collapse to a single distinct value (after
     * deduplication) can't form a scale, so the outcome is skipped rather than
     * producing a scale with indistinguishable levels.
     *
     * @return void
     */
    public function test_duplicate_rating_labels_are_deduplicated(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<learningOutcomes xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<learningOutcomeGroup identifier="g1"><title>G</title><learningOutcomes>'
            . '<learningOutcome identifier="o1"><title>Same labels</title><description></description>'
            . '<ratings>'
            . '<rating><description>Met</description><points>1</points></rating>'
            . '<rating><description>met</description><points>0</points></rating>'
            . '</ratings></learningOutcome>'
            . '</learningOutcomes></learningOutcomeGroup></learningOutcomes>';

        $builder = new outcome_builder($this->package($xml));
        $created = $builder->build($course);

        $this->assertSame(0, $created);
        $this->assertSame(1, $builder->skippedcount);
    }

    /**
     * A comma inside a rating label is normalised so Moodle (whose scale items are
     * comma-delimited) keeps it as a single scale item rather than splitting it.
     *
     * @return void
     */
    public function test_comma_in_rating_label_is_not_split(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<learningOutcomes xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<learningOutcomeGroup identifier="g1"><title>G</title><learningOutcomes>'
            . '<learningOutcome identifier="o1"><title>Commas</title><description></description>'
            . '<ratings>'
            . '<rating><description>Meets expectations, with support</description><points>1</points></rating>'
            . '<rating><description>Not yet</description><points>0</points></rating>'
            . '</ratings></learningOutcome>'
            . '</learningOutcomes></learningOutcomeGroup></learningOutcomes>';

        $created = (new outcome_builder($this->package($xml)))->build($course);

        $this->assertSame(1, $created);
        $outcome = $DB->get_record('grade_outcomes', ['courseid' => $course->id], '*', MUST_EXIST);
        $scale = $DB->get_record('scale', ['id' => $outcome->scaleid], '*', MUST_EXIST);
        // Two items, and the comma'd label stayed whole (comma swapped for a
        // fullwidth comma that can't be mistaken for the ASCII delimiter).
        $items = explode(',', $scale->scale);
        $this->assertCount(2, $items);
        $this->assertContains("Meets expectations\u{FF0C} with support", $items);
    }

    /**
     * Labels differing only by comma vs semicolon stay distinct: the comma
     * becomes a fullwidth comma, so `Meets, with support` and `Meets; with
     * support` don't collapse to one item during deduplication.
     *
     * @return void
     */
    public function test_comma_and_semicolon_labels_stay_distinct(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<learningOutcomes xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<learningOutcomeGroup identifier="g1"><title>G</title><learningOutcomes>'
            . '<learningOutcome identifier="o1"><title>Punctuation</title><description></description>'
            . '<ratings>'
            . '<rating><description>Meets, with support</description><points>1</points></rating>'
            . '<rating><description>Meets; with support</description><points>0</points></rating>'
            . '</ratings></learningOutcome>'
            . '</learningOutcomes></learningOutcomeGroup></learningOutcomes>';

        $created = (new outcome_builder($this->package($xml)))->build($course);

        $this->assertSame(1, $created);
        $outcome = $DB->get_record('grade_outcomes', ['courseid' => $course->id], '*', MUST_EXIST);
        $scale = $DB->get_record('scale', ['id' => $outcome->scaleid], '*', MUST_EXIST);
        // Both labels survived as two distinct scale items.
        $this->assertCount(2, explode(',', $scale->scale));
    }

    /**
     * A label that already contains a fullwidth comma stays distinct from one
     * whose ASCII comma is normalised to a fullwidth comma: the collider is
     * suffixed so both mastery levels survive as separate scale items.
     *
     * @return void
     */
    public function test_fullwidth_comma_collision_is_disambiguated(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<learningOutcomes xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<learningOutcomeGroup identifier="g1"><title>G</title><learningOutcomes>'
            . '<learningOutcome identifier="o1"><title>Collide</title><description></description>'
            . '<ratings>'
            . '<rating><description>Meets, with support</description><points>1</points></rating>'
            . "<rating><description>Meets\u{FF0C} with support</description><points>0</points></rating>"
            . '</ratings></learningOutcome>'
            . '</learningOutcomes></learningOutcomeGroup></learningOutcomes>';

        $created = (new outcome_builder($this->package($xml)))->build($course);

        $this->assertSame(1, $created);
        $outcome = $DB->get_record('grade_outcomes', ['courseid' => $course->id], '*', MUST_EXIST);
        $scale = $DB->get_record('scale', ['id' => $outcome->scaleid], '*', MUST_EXIST);
        // Both labels survived as two distinct scale items rather than collapsing.
        $items = explode(',', $scale->scale);
        $this->assertCount(2, $items);
        $this->assertNotSame($items[0], $items[1]);
    }

    /**
     * The scale is ordered by each rating's mastery points, not by the XML
     * document order — so a file listing ratings ascending (or scrambled) still
     * produces a low-to-high Moodle scale with the top level highest.
     *
     * @return void
     */
    public function test_scale_ordered_by_points_not_document_order(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        // Ratings deliberately out of order: points 1, 2, 0.
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<learningOutcomes xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<learningOutcomeGroup identifier="g1"><title>G</title><learningOutcomes>'
            . '<learningOutcome identifier="o1"><title>Order</title><description></description>'
            . '<ratings>'
            . '<rating><description>Middle</description><points>1</points></rating>'
            . '<rating><description>Top</description><points>2</points></rating>'
            . '<rating><description>Bottom</description><points>0</points></rating>'
            . '</ratings></learningOutcome>'
            . '</learningOutcomes></learningOutcomeGroup></learningOutcomes>';

        $created = (new outcome_builder($this->package($xml)))->build($course);

        $this->assertSame(1, $created);
        $outcome = $DB->get_record('grade_outcomes', ['courseid' => $course->id], '*', MUST_EXIST);
        $scale = $DB->get_record('scale', ['id' => $outcome->scaleid], '*', MUST_EXIST);
        // Lowest points first, highest last — regardless of the XML sequence.
        $this->assertSame('Bottom,Middle,Top', $scale->scale);
    }

    /**
     * A learning_outcomes.xml that is present but unreadable as XML sets the
     * malformed flag (so the build can warn) rather than being treated as a
     * package with no outcomes.
     *
     * @return void
     */
    public function test_malformed_file_sets_flag_and_creates_nothing(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $builder = new outcome_builder($this->package('<learningOutcomes><broken'));
        $created = $builder->build($course);

        $this->assertSame(0, $created);
        $this->assertTrue($builder->malformedfile);
        $this->assertSame(0, $DB->count_records('grade_outcomes', ['courseid' => $course->id]));
    }

    /**
     * An outcome Canvas exported without a title is still imported under a
     * fallback name (with its ratings) rather than being dropped silently.
     *
     * @return void
     */
    public function test_untitled_outcome_imports_under_fallback_name(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<learningOutcomes xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<learningOutcomeGroup identifier="g1"><title>G</title><learningOutcomes>'
            . '<learningOutcome identifier="o1"><title></title><description></description>'
            . '<ratings>'
            . '<rating><description>Yes</description><points>1</points></rating>'
            . '<rating><description>No</description><points>0</points></rating>'
            . '</ratings></learningOutcome>'
            . '</learningOutcomes></learningOutcomeGroup></learningOutcomes>';

        $created = (new outcome_builder($this->package($xml)))->build($course);

        $this->assertSame(1, $created);
        $outcome = $DB->get_record('grade_outcomes', ['courseid' => $course->id], '*', MUST_EXIST);
        $this->assertSame(get_string('outcomeuntitled', 'tool_canvasuplifter'), $outcome->fullname);
    }

    /**
     * A file referenced from an outcome description via a Canvas
     * $IMS-CC-FILEBASE$ token is imported into the system-context grade/outcome
     * file area and the token rewritten to @@PLUGINFILE@@ so it resolves.
     *
     * @return void
     */
    public function test_description_file_is_embedded(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $dir = make_request_directory();
        mkdir($dir . '/course_settings', 0777, true);
        mkdir($dir . '/web_resources', 0777, true);
        file_put_contents($dir . '/web_resources/logo.png', 'PNGDATA');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<learningOutcomes xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<learningOutcomeGroup identifier="g1"><title>G</title><learningOutcomes>'
            . '<learningOutcome identifier="o1"><title>With image</title>'
            . '<description>&lt;p&gt;&lt;img src="$IMS-CC-FILEBASE$/web_resources/logo.png"&gt;&lt;/p&gt;</description>'
            . '<ratings>'
            . '<rating><description>Yes</description><points>1</points></rating>'
            . '<rating><description>No</description><points>0</points></rating>'
            . '</ratings></learningOutcome>'
            . '</learningOutcomes></learningOutcomeGroup></learningOutcomes>';
        file_put_contents($dir . '/course_settings/learning_outcomes.xml', $xml);

        $created = (new outcome_builder($dir))->build($course);

        $this->assertSame(1, $created);
        $outcome = $DB->get_record('grade_outcomes', ['courseid' => $course->id], '*', MUST_EXIST);
        // The token is gone, replaced by a pluginfile reference.
        $this->assertStringNotContainsString('$IMS-CC-FILEBASE$', $outcome->description);
        $this->assertStringContainsString('@@PLUGINFILE@@', $outcome->description);
        // The asset landed in the system-context grade/outcome area for this id.
        $systemcontext = \context_system::instance();
        $this->assertTrue(get_file_storage()->file_exists(
            $systemcontext->id,
            'grade',
            'outcome',
            (int) $outcome->id,
            '/web_resources/',
            'logo.png'
        ));
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
