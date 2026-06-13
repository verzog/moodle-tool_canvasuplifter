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
 * Imports package files referenced by a piece of HTML into a Moodle file area
 * and returns the HTML rewritten to @@PLUGINFILE@@ references.
 *
 * This is the Moodle-coupled companion to {@see link_rewriter}: the rewriter
 * works out which package files an HTML fragment points at, and this class does
 * the actual file_storage import so the references resolve through
 * pluginfile.php. It is shared by every builder that stores rich-text content
 * (pages, assignment descriptions, and so on).
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class file_embedder {
    /** @var string Absolute path to the extracted package root. */
    private string $packageroot;

    /**
     * Constructor.
     *
     * @param string $packageroot Absolute path to the extracted package directory.
     */
    public function __construct(string $packageroot) {
        $this->packageroot = rtrim($packageroot, '/');
    }

    /**
     * Import referenced files into a module file area and rewrite the HTML.
     *
     * Files are stored at itemid 0 (the convention for intro/content areas).
     *
     * @param int $contextid Module context id.
     * @param string $component Frankenstyle component, e.g. "mod_page".
     * @param string $filearea File area, e.g. "content" or "intro".
     * @param string $html The HTML to scan and rewrite.
     * @return string The rewritten HTML (unchanged if nothing was embedded).
     */
    public function embed(int $contextid, string $component, string $filearea, string $html): string {
        $result = (new link_rewriter())->rewrite_files($html, $this->packageroot);
        if (empty($result['files'])) {
            return $html;
        }

        $fs = get_file_storage();
        foreach ($result['files'] as $file) {
            $exists = $fs->file_exists(
                $contextid,
                $component,
                $filearea,
                0,
                $file['filepath'],
                $file['filename']
            );
            if ($exists) {
                continue;
            }
            $fs->create_file_from_pathname([
                'contextid' => $contextid,
                'component' => $component,
                'filearea' => $filearea,
                'itemid' => 0,
                'filepath' => $file['filepath'],
                'filename' => $file['filename'],
            ], $file['package']);
        }

        return $result['html'];
    }
}
