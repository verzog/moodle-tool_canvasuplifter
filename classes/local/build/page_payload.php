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

use tool_canvasuplifter\local\model\item;

/**
 * Locate and read the HTML payload that backs a Canvas page item.
 *
 * Shared by the page builder and the grouped book/lesson builders so the
 * "where does this page's HTML live, and what title should it carry" rules stay
 * in one place. Filesystem-only (no Moodle DB dependency).
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page_payload {
    /**
     * Resolve the HTML file inside the package that backs a page item.
     *
     * Canvas usually puts pages under wiki_content/<slug>.html; other CC
     * exporters drop them at the resource's href. We try files[] first (the
     * manifest's explicit file list) and fall back to href.
     *
     * @param string $packageroot Absolute path to the extracted package root.
     * @param item $modelitem The page item.
     * @return string|null Absolute path, or null if nothing is readable.
     */
    public static function locate(string $packageroot, item $modelitem): ?string {
        $found = self::locate_relative($packageroot, $modelitem);
        return $found === null ? null : $found[1];
    }

    /**
     * Package-relative directory of the HTML file that actually backs a page
     * item, so relative links inside it resolve against the right base. Uses the
     * same files-then-href order as {@see locate()}, so the base directory always
     * matches the file whose content was read (not whichever path comes first in
     * the manifest).
     *
     * @param string $packageroot Absolute path to the extracted package root.
     * @param item $modelitem The page item.
     * @return string Package-relative directory ('' at the package root, or when nothing is readable).
     */
    public static function basedir(string $packageroot, item $modelitem): string {
        $found = self::locate_relative($packageroot, $modelitem);
        if ($found === null) {
            return '';
        }
        $dir = dirname(ltrim($found[0], '/'));
        return ($dir === '.' || $dir === '/' || $dir === '\\') ? '' : $dir;
    }

    /**
     * Find the first readable candidate file backing a page item, returning both
     * its manifest-relative path and its absolute path.
     *
     * @param string $packageroot Absolute path to the extracted package root.
     * @param item $modelitem The page item.
     * @return array|null [relative path, absolute path], or null if none is readable.
     */
    private static function locate_relative(string $packageroot, item $modelitem): ?array {
        $root = rtrim($packageroot, '/');
        $candidates = $modelitem->files;
        if ($modelitem->href !== '') {
            $candidates[] = $modelitem->href;
        }
        foreach ($candidates as $relative) {
            $absolute = safe_path::within($root, $relative);
            if ($absolute !== null && is_readable($absolute)) {
                return [$relative, $absolute];
            }
        }
        return null;
    }

    /**
     * Read the HTML payload backing a page item.
     *
     * @param string $packageroot Absolute path to the extracted package root.
     * @param item $modelitem The page item.
     * @return string|null The HTML, or null when no non-empty payload is readable.
     */
    public static function html(string $packageroot, item $modelitem): ?string {
        $path = self::locate($packageroot, $modelitem);
        if ($path === null) {
            return null;
        }
        $content = (string) @file_get_contents($path);
        return $content === '' ? null : $content;
    }

    /**
     * Derive a display title for a page item, falling back to its file base name.
     *
     * @param item $modelitem The page item.
     * @return string
     */
    public static function title(item $modelitem): string {
        if ($modelitem->title !== '') {
            return $modelitem->title;
        }
        return pathinfo($modelitem->href, PATHINFO_FILENAME);
    }
}
