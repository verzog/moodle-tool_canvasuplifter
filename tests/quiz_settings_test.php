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

use tool_canvasuplifter\local\parser\quiz_settings;

/**
 * Tests for the Canvas assessment_meta.xml (quiz settings) parser.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\parser\quiz_settings
 */
final class quiz_settings_test extends \basic_testcase {
    /**
     * A typical Canvas assessment_meta.xml parses into the expected values,
     * with the availability dates read from the nested <assignment> child.
     *
     * @return void
     */
    public function test_parse_full(): void {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<quiz identifier="g1" xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<title>Chapter 1 Quiz</title>'
            . '<description>&lt;p&gt;Answer all questions.&lt;/p&gt;</description>'
            . '<shuffle_answers>true</shuffle_answers>'
            . '<scoring_policy>keep_latest</scoring_policy>'
            . '<quiz_type>assignment</quiz_type>'
            . '<points_possible>20.0</points_possible>'
            . '<show_correct_answers>false</show_correct_answers>'
            . '<allowed_attempts>3</allowed_attempts>'
            . '<one_question_at_a_time>true</one_question_at_a_time>'
            . '<cant_go_back>true</cant_go_back>'
            . '<access_code>secret</access_code>'
            . '<ip_filter>10.0.0.0/8</ip_filter>'
            . '<time_limit>45</time_limit>'
            . '<assignment identifier="a1">'
            . '<due_at>2030-09-01T23:59:00Z</due_at>'
            . '<unlock_at>2030-08-01T00:00:00Z</unlock_at>'
            . '<lock_at>2030-09-08T23:59:00Z</lock_at>'
            . '</assignment>'
            . '</quiz>';

        $settings = quiz_settings::parse($xml);

        $this->assertSame('Chapter 1 Quiz', $settings->title);
        $this->assertSame('<p>Answer all questions.</p>', $settings->description);
        $this->assertSame('assignment', $settings->quiztype);
        $this->assertSame(20.0, $settings->points);
        $this->assertSame('keep_latest', $settings->scoringpolicy);
        $this->assertSame(3, $settings->allowedattempts);
        $this->assertSame(45, $settings->timelimit);
        $this->assertTrue($settings->shuffleanswers);
        $this->assertFalse($settings->showcorrectanswers);
        $this->assertTrue($settings->onequestionatatime);
        $this->assertTrue($settings->cantgoback);
        $this->assertSame('secret', $settings->accesscode);
        $this->assertSame('10.0.0.0/8', $settings->ipfilter);
        $this->assertSame(strtotime('2030-09-01T23:59:00Z'), $settings->duedate);
        $this->assertSame(strtotime('2030-08-01T00:00:00Z'), $settings->unlockat);
        $this->assertSame(strtotime('2030-09-08T23:59:00Z'), $settings->lockat);
        // The close_time() helper prefers the hard lock date over the due date.
        $this->assertSame(strtotime('2030-09-08T23:59:00Z'), $settings->close_time());
        $this->assertFalse($settings->is_survey());
    }

    /**
     * Unlimited attempts (-1) and an unlimited time limit (0 minutes) are read
     * verbatim; the builder is responsible for translating them.
     *
     * @return void
     */
    public function test_parse_unlimited(): void {
        $xml = '<quiz xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<title>Practice</title>'
            . '<quiz_type>practice_quiz</quiz_type>'
            . '<allowed_attempts>-1</allowed_attempts>'
            . '<time_limit></time_limit>'
            . '</quiz>';

        $settings = quiz_settings::parse($xml);

        $this->assertSame(-1, $settings->allowedattempts);
        $this->assertSame(0, $settings->timelimit);
        $this->assertSame('practice_quiz', $settings->quiztype);
    }

    /**
     * close_time() falls back to the due date when no hard lock date is set.
     *
     * @return void
     */
    public function test_close_time_falls_back_to_due_date(): void {
        $xml = '<quiz xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<title>Q</title>'
            . '<assignment><due_at>2030-05-01T12:00:00Z</due_at></assignment>'
            . '</quiz>';

        $settings = quiz_settings::parse($xml);

        $this->assertSame(0, $settings->lockat);
        $this->assertSame(strtotime('2030-05-01T12:00:00Z'), $settings->duedate);
        $this->assertSame(strtotime('2030-05-01T12:00:00Z'), $settings->close_time());
    }

    /**
     * A quiz whose points live on the <assignment> child (with none at the quiz
     * root) still surfaces the maximum points.
     *
     * @return void
     */
    public function test_points_fall_back_to_assignment_child(): void {
        $xml = '<quiz xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<title>Q</title>'
            . '<assignment><points_possible>15.0</points_possible></assignment>'
            . '</quiz>';

        $settings = quiz_settings::parse($xml);

        $this->assertSame(15.0, $settings->points);
    }

    /**
     * A graded survey is recognised as a survey.
     *
     * @return void
     */
    public function test_is_survey(): void {
        $xml = '<quiz xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<quiz_type>graded_survey</quiz_type></quiz>';

        $this->assertTrue(quiz_settings::parse($xml)->is_survey());
    }

    /**
     * Missing fields stay at their "Canvas did not say" sentinels: zero/empty
     * for scalars and null for the booleans, so the builder keeps its defaults.
     *
     * @return void
     */
    public function test_parse_minimal(): void {
        $xml = '<quiz xmlns="http://canvas.instructure.com/xsd/cccv1p0">'
            . '<title>Bare</title></quiz>';

        $settings = quiz_settings::parse($xml);

        $this->assertSame('Bare', $settings->title);
        $this->assertSame(0, $settings->allowedattempts);
        $this->assertSame(0, $settings->timelimit);
        $this->assertSame(0.0, $settings->points);
        $this->assertSame('', $settings->scoringpolicy);
        $this->assertNull($settings->shuffleanswers);
        $this->assertNull($settings->showcorrectanswers);
        $this->assertNull($settings->onequestionatatime);
        $this->assertNull($settings->cantgoback);
        $this->assertSame(0, $settings->close_time());
    }

    /**
     * Malformed XML yields a default object rather than throwing.
     *
     * @return void
     */
    public function test_parse_garbage(): void {
        $settings = quiz_settings::parse('not xml at all <<<');

        $this->assertSame('', $settings->title);
        $this->assertSame(0, $settings->allowedattempts);
        $this->assertNull($settings->shuffleanswers);
    }
}
