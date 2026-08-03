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

namespace tool_canvasuplifter\local\model;

/**
 * One Canvas learning outcome parsed from course_settings/learning_outcomes.xml.
 *
 * Plain data holder with no Moodle dependencies so the outcomes parser stays
 * unit-testable in isolation.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class outcome {
    /** @var string The outcome's display name (Canvas <title>). */
    public string $fullname = '';

    /** @var string The outcome description (HTML). */
    public string $description = '';

    /**
     * @var array Mastery ratings in Canvas order (highest first). Each row:
     *            ['description' => string, 'points' => float].
     */
    public array $ratings = [];

    /**
     * The rating labels as Moodle scale items: reversed to Moodle's
     * lowest-to-highest order, commas swapped for a fullwidth comma (Moodle scale
     * items are comma-delimited with no escaping, and a fullwidth comma can't
     * collide with the ASCII delimiter or another label's punctuation), blanks
     * dropped, and deduplicated case-insensitively (Moodle can't tell identical
     * scale items apart). A usable Moodle scale needs at least two of these.
     *
     * Moodle-free so both the builder and the analyse report can share it.
     *
     * @return array The distinct scale-item labels, low to high.
     */
    public function scale_labels(): array {
        $items = [];
        $seen = [];
        foreach (array_reverse($this->ratings) as $rating) {
            $label = trim(str_replace(',', "\u{FF0C}", (string) ($rating['description'] ?? '')));
            if ($label === '') {
                continue;
            }
            $key = mb_strtolower($label, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $items[] = $label;
        }
        return $items;
    }
}
