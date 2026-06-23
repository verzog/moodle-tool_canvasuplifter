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
 * is present before any parsing happens. The manifest is located at any depth,
 * and a repository download that wraps the cartridge inside another zip (common
 * for SkillsCommons / DSpace bitstreams) is unwrapped one level. No Moodle
 * dependencies, so it is unit-testable on its own.
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

    /** @var string The Common Cartridge manifest file name (lower-cased for matching). */
    private const MANIFEST = 'imsmanifest.xml';

    /**
     * Extract a package to a target directory and locate the manifest root.
     *
     * @param string $zippath Path to the uploaded .imscc (or .zip) file.
     * @param string $targetdir Empty directory to extract into.
     * @return string Absolute path to the directory containing imsmanifest.xml.
     * @throws \RuntimeException With an error code (one of the ERROR_* constants).
     */
    public function extract(string $zippath, string $targetdir): string {
        $this->extract_zip($zippath, $targetdir);

        $root = $this->find_manifest_root($targetdir);
        if ($root !== null) {
            return $root;
        }

        // No manifest anywhere in the tree. Repository downloads often wrap the
        // real cartridge inside another archive (e.g. course.imscc bundled with
        // a readme), so unwrap the first nested .imscc/.zip and look again.
        $nested = $this->find_nested_archive($targetdir);
        if ($nested !== null) {
            $subdir = $targetdir . '/__cu_nested_' . md5($nested);
            if (is_dir($subdir) || mkdir($subdir)) {
                $this->extract_zip($nested, $subdir);
                $root = $this->find_manifest_root($subdir);
                if ($root !== null) {
                    return $root;
                }
            }
        }

        throw new \RuntimeException(self::ERROR_NO_MANIFEST);
    }

    /**
     * Open a zip, reject zip-slip entries, and extract it into a directory.
     *
     * @param string $zippath Path to the archive.
     * @param string $targetdir Directory to extract into.
     * @return void
     * @throws \RuntimeException ERROR_NOT_ZIP when the file is not a safe, readable zip.
     */
    private function extract_zip(string $zippath, string $targetdir): void {
        $zip = new ZipArchive();
        if ($zip->open($zippath) !== true) {
            throw new \RuntimeException(self::ERROR_NOT_ZIP);
        }
        // Reject zip-slip entries (absolute paths or ".." segments) before
        // extracting, so a crafted archive cannot write outside $targetdir.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }
            if ($name[0] === '/' || $name[0] === '\\' || preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $name)) {
                $zip->close();
                throw new \RuntimeException(self::ERROR_NOT_ZIP);
            }
        }
        if (!$zip->extractTo($targetdir)) {
            $zip->close();
            throw new \RuntimeException(self::ERROR_NOT_ZIP);
        }
        $zip->close();
    }

    /**
     * Find the shallowest directory holding imsmanifest.xml, at any depth.
     *
     * @param string $dir Directory to search.
     * @return string|null Absolute path to the manifest's directory, or null if not found.
     */
    protected function find_manifest_root(string $dir): ?string {
        $path = $this->bfs_find($dir, fn($name) => strtolower($name) === self::MANIFEST);
        return $path === null ? null : dirname($path);
    }

    /**
     * Find a nested archive (.imscc preferred, else .zip) wrapped inside the
     * extracted tree, shallowest first.
     *
     * @param string $dir Directory to search.
     * @return string|null Absolute path to the nested archive, or null if none.
     */
    private function find_nested_archive(string $dir): ?string {
        return $this->bfs_find($dir, fn($name) => (bool) preg_match('/\.imscc$/i', $name))
            ?? $this->bfs_find($dir, fn($name) => (bool) preg_match('/\.zip$/i', $name));
    }

    /**
     * Breadth-first search for the first file whose name satisfies $matches,
     * so the shallowest match wins (a root-level hit beats a nested one).
     *
     * @param string $dir Directory to search under.
     * @param callable $matches Predicate taking a base file name, returning bool.
     * @return string|null Absolute path to the first matching file, or null.
     */
    private function bfs_find(string $dir, callable $matches): ?string {
        $queue = [rtrim($dir, '/')];
        while ($queue !== []) {
            $current = array_shift($queue);
            $subdirs = [];
            foreach (scandir($current) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $current . '/' . $entry;
                if (is_dir($path)) {
                    $subdirs[] = $path;
                } else if ($matches($entry) && is_readable($path)) {
                    return $path;
                }
            }
            foreach ($subdirs as $sub) {
                $queue[] = $sub;
            }
        }
        return null;
    }
}
