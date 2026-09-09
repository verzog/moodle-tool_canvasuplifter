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

use tool_canvasuplifter\local\build\lti_classifier;

/**
 * Tests the Moodle/STACK LTI link classifier.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\build\lti_classifier
 */
final class lti_classifier_test extends \basic_testcase {
    /**
     * A launch URL pointing at a Moodle enrol_lti or mod/lti endpoint is recognised as
     * Moodle-hosted content.
     *
     * @return void
     */
    public function test_moodle_publish_as_lti_url_is_moodle(): void {
        $this->assertSame(
            lti_classifier::KIND_MOODLE,
            lti_classifier::classify('https://moodle.example.edu/enrol/lti/launch.php?id=7', '', 'Weekly task', [])
        );
        $this->assertSame(
            lti_classifier::KIND_MOODLE,
            lti_classifier::classify('https://moodle.example.edu/mod/lti/view.php?id=9', '', 'Tool', [])
        );
    }

    /**
     * "stack" as a whole word in the title, a custom parameter or the URL host marks the link as
     * a STACK question, and STACK wins over a generic Moodle URL match.
     *
     * @return void
     */
    public function test_stack_signal_in_title_custom_or_host(): void {
        $this->assertSame(
            lti_classifier::KIND_STACK,
            lti_classifier::classify('https://tool.example.edu/launch', '', 'STACK: integral practice', [])
        );
        $this->assertSame(
            lti_classifier::KIND_STACK,
            lti_classifier::classify('https://tool.example.edu/launch', '', 'Question', ['product' => 'STACK'])
        );
        $this->assertSame(
            lti_classifier::KIND_STACK,
            lti_classifier::classify('https://stack.example.edu/lti/launch', '', 'Question', [])
        );
        // A Moodle-published STACK question: URL says Moodle, title says STACK -> STACK wins.
        $this->assertSame(
            lti_classifier::KIND_STACK,
            lti_classifier::classify('https://moodle.example.edu/enrol/lti/launch.php?id=1', '', 'STACK quiz', [])
        );
    }

    /**
     * A word merely containing "stack" as a substring must not trigger a STACK match.
     *
     * @return void
     */
    public function test_stack_substring_does_not_false_match(): void {
        $this->assertSame(
            lti_classifier::KIND_NONE,
            lti_classifier::classify('https://beanstalk.example.com/x', '', 'Stackexchange helper', [])
        );
    }

    /**
     * An ordinary third-party tool with no Moodle or STACK signal is unrecognised.
     *
     * @return void
     */
    public function test_generic_tool_is_unrecognised(): void {
        $this->assertSame(
            lti_classifier::KIND_NONE,
            lti_classifier::classify(
                'https://publisher.example.com/lti/launch',
                '',
                'Publisher courseware',
                ['resource_link_id' => 'abc']
            )
        );
    }

    /**
     * Site-configured patterns extend detection: a bare pattern defaults to Moodle, a "stack:"
     * prefix forces STACK, and the needle is matched across URL, title and custom parameters.
     *
     * @return void
     */
    public function test_configured_patterns(): void {
        $patterns = lti_classifier::parse_patterns("moodle-host.example.edu\nstack:stackassessment.example.edu");
        $this->assertSame(
            lti_classifier::KIND_MOODLE,
            lti_classifier::classify('https://moodle-host.example.edu/tool', '', 'Tool', [], $patterns)
        );
        $this->assertSame(
            lti_classifier::KIND_STACK,
            lti_classifier::classify('https://stackassessment.example.edu/launch', '', 'Q', [], $patterns)
        );
        // Without the configured pattern the same generic host is unrecognised.
        $this->assertSame(
            lti_classifier::KIND_NONE,
            lti_classifier::classify('https://moodle-host.example.edu/tool', '', 'Tool', [])
        );
    }

    /**
     * parse_patterns trims lines, drops blanks, lowercases needles, and reads the kind prefix.
     *
     * @return void
     */
    public function test_parse_patterns(): void {
        $patterns = lti_classifier::parse_patterns("  Foo.Example  \n\nSTACK:Bar\nmoodle:/enrol/\n");
        $this->assertSame([
            ['kind' => lti_classifier::KIND_MOODLE, 'needle' => 'foo.example'],
            ['kind' => lti_classifier::KIND_STACK, 'needle' => 'bar'],
            ['kind' => lti_classifier::KIND_MOODLE, 'needle' => '/enrol/'],
        ], $patterns);
        $this->assertSame([], lti_classifier::parse_patterns(''));
    }
}
