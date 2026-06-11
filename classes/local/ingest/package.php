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

namespace tool_canvasuplifter\local\ingest;

use ZipArchive;

/**
 * Unpacks and validates an uploaded Canvas Common Cartridge package.
 *
 * A .imscc file is just a zip, so this extracts it and confirms the manifest
 * is present before any parsing happens. No Moodle dependencies, so it is
 * unit-testable on its own.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class package {

    /** Returned when the file is not a readable zip. */
    public const ERROR_NOT_ZIP = 'errornotzip';
    /** Returned when no manifest can be found inside. */
    public const ERROR_NO_MANIFEST = 'errornomanifest';

    /**
     * Extract a package to a target directory and locate the manifest root.
     *
     * @param string $zippath Path to the uploaded .imscc (or .zip) file.
     * @param string $targetdir Empty directory to extract into.
     * @return string Absolute path to the directory containing imsmanifest.xml.
     * @throws \RuntimeException With an error code (one of the ERROR_* constants).
     */
    public function extract(string $zippath, string $targetdir): string {
        $zip = new ZipArchive();
        if ($zip->open($zippath) !== true) {
            throw new \RuntimeException(self::ERROR_NOT_ZIP);
        }
        if (!$zip->extractTo($targetdir)) {
            $zip->close();
            throw new \RuntimeException(self::ERROR_NOT_ZIP);
        }
        $zip->close();

        $root = $this->find_manifest_root($targetdir);
        if ($root === null) {
            throw new \RuntimeException(self::ERROR_NO_MANIFEST);
        }
        return $root;
    }

    /**
     * Find the directory holding imsmanifest.xml (root, or one level down).
     *
     * Some packages wrap everything in a single top-level folder, so we check
     * the root first and then any immediate subdirectory.
     *
     * @param string $dir Directory to search.
     * @return string|null Absolute path, or null if not found.
     */
    protected function find_manifest_root(string $dir): ?string {
        $dir = rtrim($dir, '/');
        if (is_readable($dir . '/imsmanifest.xml')) {
            return $dir;
        }
        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
            if (is_readable($sub . '/imsmanifest.xml')) {
                return $sub;
            }
        }
        return null;
    }
}
