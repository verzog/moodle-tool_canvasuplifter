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
     * The rating labels as Moodle scale items, ordered low-to-high by their
     * mastery points (Moodle scale items run lowest first). Canvas usually lists
     * ratings highest-first, but not always, so the numeric points — which the
     * parser preserves — decide the order rather than the XML sequence. A usable
     * Moodle scale needs at least two of these.
     *
     * Two passes handle the fact that Moodle scale items are comma-delimited with
     * no escaping. First, genuinely identical labels are collapsed
     * case-insensitively (Moodle can't tell identical items apart). Then commas
     * are swapped for a fullwidth comma so a label stays a single item; because a
     * label could itself already contain a fullwidth comma, any label that
     * collides with one already emitted is suffixed so every distinct mastery
     * level still survives as its own item.
     *
     * Moodle-free so both the builder and the analyse report can share it.
     *
     * @return array The distinct scale-item labels, low to high.
     */
    public function scale_labels(): array {
        $ratings = $this->ratings;
        // Sort ascending by points (stable, so equal-points ratings keep their
        // document order); Moodle scale items run lowest value first.
        usort($ratings, fn($a, $b) => ((float) ($a['points'] ?? 0)) <=> ((float) ($b['points'] ?? 0)));
        $originals = [];
        $seenoriginal = [];
        foreach ($ratings as $rating) {
            $label = trim((string) ($rating['description'] ?? ''));
            if ($label === '') {
                continue;
            }
            $key = mb_strtolower($label, 'UTF-8');
            if (isset($seenoriginal[$key])) {
                continue;
            }
            $seenoriginal[$key] = true;
            $originals[] = $label;
        }
        $items = [];
        $used = [];
        foreach ($originals as $label) {
            $item = str_replace(',', "\u{FF0C}", $label);
            $candidate = $item;
            $suffix = 1;
            while (isset($used[mb_strtolower($candidate, 'UTF-8')])) {
                $suffix++;
                $candidate = $item . ' (' . $suffix . ')';
            }
            $used[mb_strtolower($candidate, 'UTF-8')] = true;
            $items[] = $candidate;
        }
        return $items;
    }
}
