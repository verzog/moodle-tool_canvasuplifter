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

use tool_canvasuplifter\local\parser\outcomes_parser;

/**
 * Tests for the learning-outcomes parser.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\parser\outcomes_parser
 */
final class outcomes_parser_test extends \basic_testcase {
    /**
     * A Canvas learning_outcomes.xml (outcome nested in a group, with a mastery
     * ratings list) parses into one outcome with its title, description and the
     * ratings in document (highest-first) order.
     *
     * @return void
     */
    public function test_parses_outcome_with_ratings(): void {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<learningOutcomes xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<learningOutcomeGroup identifier="g1"><title>Group 1</title>'
            . '<learningOutcomes>'
            . '<learningOutcome identifier="o1">'
            . '<title>New outcome</title>'
            . '<description>&lt;p&gt;This is a new outcome&lt;/p&gt;</description>'
            . '<ratings>'
            . '<rating><description>Exceeds Mastery</description><points>4.0</points></rating>'
            . '<rating><description>Mastery</description><points>3.0</points></rating>'
            . '<rating><description>Near Mastery</description><points>2.0</points></rating>'
            . '</ratings>'
            . '</learningOutcome>'
            . '</learningOutcomes></learningOutcomeGroup></learningOutcomes>';

        $outcomes = (new outcomes_parser())->parse($xml);

        $this->assertCount(1, $outcomes);
        $this->assertSame('New outcome', $outcomes[0]->fullname);
        // The outcome's own description, not a rating's nested <description>.
        $this->assertSame('<p>This is a new outcome</p>', $outcomes[0]->description);
        $this->assertCount(3, $outcomes[0]->ratings);
        $this->assertSame('Exceeds Mastery', $outcomes[0]->ratings[0]['description']);
        $this->assertSame(4.0, $outcomes[0]->ratings[0]['points']);
        $this->assertSame('Near Mastery', $outcomes[0]->ratings[2]['description']);
    }

    /**
     * Multiple outcomes across groups are all returned, in document order.
     *
     * @return void
     */
    public function test_parses_multiple_outcomes(): void {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<learningOutcomes xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<learningOutcomeGroup identifier="g1"><title>Group 1</title><learningOutcomes>'
            . '<learningOutcome identifier="o1"><title>First</title><description></description>'
            . '<ratings><rating><description>Yes</description><points>1</points></rating>'
            . '<rating><description>No</description><points>0</points></rating></ratings>'
            . '</learningOutcome></learningOutcomes></learningOutcomeGroup>'
            . '<learningOutcomeGroup identifier="g2"><title>Group 2</title><learningOutcomes>'
            . '<learningOutcome identifier="o2"><title>Second</title><description></description>'
            . '<ratings><rating><description>Yes</description><points>1</points></rating>'
            . '<rating><description>No</description><points>0</points></rating></ratings>'
            . '</learningOutcome></learningOutcomes></learningOutcomeGroup>'
            . '</learningOutcomes>';

        $outcomes = (new outcomes_parser())->parse($xml);

        $this->assertCount(2, $outcomes);
        $this->assertSame('First', $outcomes[0]->fullname);
        $this->assertSame('Second', $outcomes[1]->fullname);
    }

    /**
     * An outcome without a title is skipped (it can't be created), and a rating
     * with an empty description is dropped so it never becomes a blank scale item.
     *
     * @return void
     */
    public function test_skips_untitled_outcome_and_blank_ratings(): void {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<learningOutcomes xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<learningOutcomeGroup identifier="g1"><title>Group 1</title><learningOutcomes>'
            . '<learningOutcome identifier="o0"><title></title><description></description>'
            . '<ratings><rating><description>A</description><points>1</points></rating></ratings>'
            . '</learningOutcome>'
            . '<learningOutcome identifier="o1"><title>Kept</title><description></description>'
            . '<ratings>'
            . '<rating><description>Good</description><points>1</points></rating>'
            . '<rating><description></description><points>0</points></rating>'
            . '</ratings></learningOutcome>'
            . '</learningOutcomes></learningOutcomeGroup></learningOutcomes>';

        $outcomes = (new outcomes_parser())->parse($xml);

        $this->assertCount(1, $outcomes);
        $this->assertSame('Kept', $outcomes[0]->fullname);
        // The blank-description rating was dropped; only the real one remains.
        $this->assertCount(1, $outcomes[0]->ratings);
        $this->assertSame('Good', $outcomes[0]->ratings[0]['description']);
    }

    /**
     * Empty input and malformed XML both yield an empty list rather than an error.
     *
     * @return void
     */
    public function test_empty_and_malformed_return_no_outcomes(): void {
        $parser = new outcomes_parser();
        $this->assertSame([], $parser->parse(''));
        $this->assertSame([], $parser->parse('   '));
        $this->assertSame([], $parser->parse('<learningOutcomes><broken'));
    }
}
