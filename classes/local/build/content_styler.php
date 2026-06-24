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

namespace tool_canvasuplifter\local\build;

/**
 * Wraps imported HTML in a styled container.
 *
 * The page, lesson and book builders put this wrapper around the content they
 * store so the plugin's styles.css — scoped to the wrapper class, and therefore
 * inert everywhere else in Moodle — can style imported content without touching
 * the rest of the site. Pure string logic with no Moodle dependency.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class content_styler {
    /** @var string The wrapper class that styles.css targets. */
    public const WRAPPER_CLASS = 'canvasuplifter-content';

    /**
     * Wrap imported HTML in the styled container, leaving empty or already
     * wrapped content unchanged.
     *
     * @param string $html The imported HTML to wrap.
     * @return string The wrapped HTML, or the input unchanged when empty or already wrapped.
     */
    public static function wrap(string $html): string {
        if (trim($html) === '') {
            return $html;
        }
        $open = '<div class="' . self::WRAPPER_CLASS . '">';
        if (strpos($html, $open) === 0) {
            return $html;
        }
        return $open . $html . '</div>';
    }
}
