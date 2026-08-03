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

use DOMDocument;
use DOMElement;
use tool_canvasuplifter\local\model\outcome;

/**
 * Parses a Canvas course_settings/learning_outcomes.xml file into outcome models.
 *
 * Canvas nests each <learningOutcome> (title, description and a <ratings> list of
 * mastery levels) inside <learningOutcomeGroup> containers. This class is
 * Moodle-free so it can be unit-tested directly from XML strings.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class outcomes_parser {
    /**
     * @var bool Whether the last parse() was handed non-empty content it could
     *           not load as XML (truncated or malformed) — distinct from a file
     *           that is simply empty or has no outcomes, so a caller can warn
     *           rather than treat the outcomes as silently absent.
     */
    public bool $malformed = false;

    /**
     * Parse a learning_outcomes.xml document into outcome models.
     *
     * @param string $xml The learning_outcomes.xml contents.
     * @return array List of {@see outcome}, in document order.
     */
    public function parse(string $xml): array {
        $this->malformed = false;
        $outcomes = [];
        if (trim($xml) === '') {
            return $outcomes;
        }
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            // Non-empty input that won't parse: flag it so the loss is surfaced.
            $this->malformed = true;
            return $outcomes;
        }
        foreach ($dom->getElementsByTagNameNS('*', 'learningOutcome') as $node) {
            if (!($node instanceof DOMElement)) {
                continue;
            }
            $model = new outcome();
            $model->fullname = trim($this->direct_child_text($node, 'title'));
            $model->description = trim($this->direct_child_text($node, 'description'));
            foreach ($node->getElementsByTagNameNS('*', 'rating') as $rating) {
                if (!($rating instanceof DOMElement)) {
                    continue;
                }
                $description = trim($this->direct_child_text($rating, 'description'));
                if ($description === '') {
                    continue;
                }
                $model->ratings[] = [
                    'description' => $description,
                    'points' => (float) trim($this->direct_child_text($rating, 'points')),
                ];
            }
            // Keep any outcome carrying a title or ratings; a node with neither
            // is empty noise. An untitled-but-rated outcome is retained so the
            // builder surfaces it (imported under a fallback name, or counted as
            // skipped) instead of dropping it silently here.
            if ($model->fullname !== '' || !empty($model->ratings)) {
                $outcomes[] = $model;
            }
        }
        return $outcomes;
    }

    /**
     * Text of the first direct-child element with the given local name. Restricted
     * to direct children so an outcome's own <description> is not confused with a
     * <rating>'s nested <description>.
     *
     * @param DOMElement $parent The parent element.
     * @param string $name The child local name.
     * @return string The child's text content, or '' when absent.
     */
    private function direct_child_text(DOMElement $parent, string $name): string {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $name) {
                return $child->textContent;
            }
        }
        return '';
    }
}
