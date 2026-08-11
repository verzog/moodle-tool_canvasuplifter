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

    /** @var media_report|null Shared collector for unresolved media references, or null to not track. */
    private ?media_report $mediareport;

    /**
     * Constructor.
     *
     * @param string $packageroot Absolute path to the extracted package directory.
     * @param media_report|null $mediareport Shared collector into which references to package
     *        files absent from the export are recorded, so the build report can surface them
     *        (null to skip tracking).
     */
    public function __construct(string $packageroot, ?media_report $mediareport = null) {
        $this->packageroot = rtrim($packageroot, '/');
        $this->mediareport = $mediareport;
    }

    /**
     * Import referenced files into a module file area and rewrite the HTML.
     *
     * Files default to itemid 0 (the convention for intro/content areas); pass an
     * explicit itemid for areas keyed by record (e.g. a forum post).
     *
     * @param int $contextid Module context id.
     * @param string $component Frankenstyle component, e.g. "mod_page".
     * @param string $filearea File area, e.g. "content" or "intro".
     * @param string $html The HTML to scan and rewrite.
     * @param int $itemid File area item id (default 0).
     * @param string $ownerdir Package-relative folder of the resource carrying the HTML ('' to skip),
     *        so media stored beside the owning resource (e.g. a discussion's inline image referenced
     *        with a ../ climb into a sibling folder) resolves rather than being left unembedded.
     * @return string The rewritten HTML (unchanged if nothing was embedded).
     */
    public function embed(
        int $contextid,
        string $component,
        string $filearea,
        string $html,
        int $itemid = 0,
        string $ownerdir = ''
    ): string {
        $result = (new link_rewriter())->rewrite_files($html, $this->packageroot, $ownerdir);
        // Record references to files absent from the package before returning, so a
        // fragment whose only tokens are unresolvable is still counted (its 'files'
        // list is empty, but the broken references matter to the build report).
        if ($this->mediareport !== null) {
            foreach ($result['unresolved'] as $reference) {
                $this->mediareport->record($reference);
            }
        }
        if (empty($result['files'])) {
            return $html;
        }

        $fs = get_file_storage();
        foreach ($result['files'] as $file) {
            $exists = $fs->file_exists(
                $contextid,
                $component,
                $filearea,
                $itemid,
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
                'itemid' => $itemid,
                'filepath' => $file['filepath'],
                'filename' => $file['filename'],
            ], $file['package']);
        }

        return $result['html'];
    }
}
