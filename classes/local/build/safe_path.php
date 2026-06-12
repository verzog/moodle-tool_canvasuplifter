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
 * Resolves a package-relative path while guaranteeing it stays inside the
 * extracted package directory.
 *
 * Resource hrefs come from the uploaded manifest, which is attacker-controlled.
 * A crafted href such as "../../../../etc/passwd" must never be followed out of
 * the package root, so every builder routes file lookups through here.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class safe_path {
    /**
     * Resolve $relative under $root, returning the absolute path only if it
     * exists and is contained within $root.
     *
     * @param string $root Absolute path to the package root.
     * @param string $relative Package-relative path from the manifest.
     * @return string|null The contained absolute path, or null if it escapes the root or is missing.
     */
    public static function within(string $root, string $relative): ?string {
        $realroot = realpath($root);
        if ($realroot === false) {
            return null;
        }
        $real = realpath($realroot . '/' . ltrim($relative, '/'));
        if ($real === false) {
            return null;
        }
        // The resolved path must be the root itself or sit beneath it.
        if ($real !== $realroot && strpos($real, $realroot . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }
        return $real;
    }
}
