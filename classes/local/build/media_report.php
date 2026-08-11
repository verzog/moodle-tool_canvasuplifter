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
 * Collects the embedded-media references a build could not resolve.
 *
 * When rich-text content references an asset via an $IMS-CC-FILEBASE$ token but
 * the target file is not present in the package, {@see link_rewriter} leaves the
 * placeholder untouched (never fabricating or deleting a reference). That is the
 * right, conservative behaviour, but on its own it is silent: a stale
 * cross-course reference leaves a visibly broken image with nothing in the build
 * report to explain it. Each builder that embeds HTML shares one of these, and
 * {@see file_embedder} records every unresolved reference here, so the build
 * report can surface a count of the assets a human editor may need to re-upload.
 *
 * References are deduplicated by their decoded path, so the same missing file
 * referenced from many pages is counted once. This class has no Moodle
 * dependencies so it can be unit-tested in isolation.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class media_report {
    /** @var array Distinct unresolved reference paths, kept as a set (path => true). */
    private array $unresolved = [];

    /**
     * Record one unresolved $IMS-CC-FILEBASE$ reference (decoded, root-relative
     * path). Blank references are ignored; duplicates collapse to one entry.
     *
     * @param string $reference The decoded reference path that could not be resolved.
     * @return void
     */
    public function record(string $reference): void {
        $reference = trim($reference);
        if ($reference === '') {
            return;
        }
        $this->unresolved[$reference] = true;
    }

    /**
     * The number of distinct unresolved references seen across the build.
     *
     * @return int
     */
    public function count(): int {
        return count($this->unresolved);
    }

    /**
     * The distinct unresolved reference paths, sorted for a stable presentation.
     *
     * @return array List of decoded reference paths.
     */
    public function references(): array {
        $refs = array_keys($this->unresolved);
        sort($refs);
        return $refs;
    }
}
