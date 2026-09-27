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

namespace tool_canvasuplifter\local\parser;

/**
 * Best-effort classifier for LTI external-tool links that actually launch Moodle-hosted
 * content — most notably STACK questions delivered into Canvas over LTI 1.3.
 *
 * Such a link arrives in a Canvas package as an ordinary LTI cartridge (or inline launch URL);
 * nothing marks it as Moodle-hosted, and the package never carries the question source or the
 * LTI 1.3 registration, so this cannot reconstruct a native qtype_stack question. Its only job is
 * to *recognise* the link — from the launch URL, title and custom parameters the builder already
 * reads — so the conversion report can flag "this external tool is Moodle-hosted (e.g. a STACK
 * question); its source likely lives in your Moodle" rather than leaving an anonymous launch.
 *
 * Because there is no universal marker, matching is deliberately conservative and a site can
 * extend it with its own patterns (see {@see parse_patterns()}). Moodle-free so it is unit-testable
 * from strings.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lti_classifier {
    /** Classification: the link launches a Moodle STACK question. */
    public const KIND_STACK = 'stack';

    /** Classification: the link launches other Moodle-hosted content over LTI. */
    public const KIND_MOODLE = 'moodle';

    /** Classification: not recognised as Moodle-hosted (an ordinary external tool). */
    public const KIND_NONE = '';

    /**
     * @var string[] URL path fragments that identify a Moodle "publish as LTI tool" endpoint
     *               (enrol_lti) or a Moodle LTI activity, matched case-insensitively.
     */
    private const MOODLE_URL_MARKERS = ['/enrol/lti/', '/mod/lti/'];

    /**
     * Classify an LTI link. STACK is the more specific result and wins over a generic Moodle
     * match. Returns one of the KIND_* constants.
     *
     * @param string $launchurl The tool launch URL.
     * @param string $secureurl The secure launch URL, if any.
     * @param string $title The tool/activity title.
     * @param array $custom Custom LTI parameters (name => value).
     * @param array $patterns Extra site patterns from {@see parse_patterns()}: each entry is
     *        ['kind' => KIND_STACK|KIND_MOODLE, 'needle' => lowercase substring].
     * @return string One of the KIND_* constants.
     */
    public static function classify(
        string $launchurl,
        string $secureurl,
        string $title,
        array $custom,
        array $patterns = []
    ): string {
        $urltext = strtolower($launchurl . ' ' . $secureurl);
        $all = $urltext . ' ' . strtolower(
            $title . ' ' . implode(' ', array_keys($custom)) . ' ' . implode(' ', array_values($custom))
        );

        // STACK, the most specific: the token "stack" in the URL, title or a custom parameter, or
        // a configured stack pattern. Boundaries are ASCII letters/digits rather than \b so that
        // identifier forms like "stack_lti" or "stack_question_id" match (PCRE \b treats an
        // underscore as a word character), while "stackexchange"/"beanstalk" still do not.
        if (preg_match('/(?<![a-z0-9])stack(?![a-z0-9])/', $all) === 1) {
            return self::KIND_STACK;
        }
        foreach ($patterns as $pattern) {
            if (self::pattern_hits($pattern, self::KIND_STACK, $all)) {
                return self::KIND_STACK;
            }
        }

        // Moodle-hosted content published over LTI (enrol_lti / mod/lti endpoints), or a
        // configured Moodle pattern.
        foreach (self::MOODLE_URL_MARKERS as $marker) {
            if (str_contains($urltext, $marker)) {
                return self::KIND_MOODLE;
            }
        }
        foreach ($patterns as $pattern) {
            if (self::pattern_hits($pattern, self::KIND_MOODLE, $all)) {
                return self::KIND_MOODLE;
            }
        }
        return self::KIND_NONE;
    }

    /**
     * Whether a parsed pattern of the given kind matches the haystack.
     *
     * @param array $pattern A ['kind' => string, 'needle' => string] entry.
     * @param string $kind The kind to match (KIND_STACK or KIND_MOODLE).
     * @param string $haystack The lowercase text to search.
     * @return bool
     */
    private static function pattern_hits(array $pattern, string $kind, string $haystack): bool {
        return $pattern['kind'] === $kind && $pattern['needle'] !== '' && str_contains($haystack, $pattern['needle']);
    }

    /**
     * Parse the admin-configured extra patterns into a list the classifier consumes. One pattern
     * per line; a line may be prefixed "stack:" or "moodle:" to force a classification (default
     * "moodle"). The needle is matched case-insensitively as a substring of the URL, title and
     * custom parameters. Blank lines and lines with an empty needle are ignored.
     *
     * @param string $raw The raw setting value (newline-separated).
     * @return array List of ['kind' => string, 'needle' => string].
     */
    public static function parse_patterns(string $raw): array {
        $patterns = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $kind = self::KIND_MOODLE;
            if (preg_match('/^(stack|moodle):(.*)$/i', $line, $matches) === 1) {
                $kind = strtolower($matches[1]) === self::KIND_STACK ? self::KIND_STACK : self::KIND_MOODLE;
                $line = trim($matches[2]);
            }
            $needle = strtolower($line);
            if ($needle !== '') {
                $patterns[] = ['kind' => $kind, 'needle' => $needle];
            }
        }
        return $patterns;
    }
}
