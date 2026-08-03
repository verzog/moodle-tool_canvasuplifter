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
}
