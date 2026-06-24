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

namespace tool_canvasuplifter\task;

use core\task\adhoc_task;
use stdClass;
use tool_canvasuplifter\local\ingest\url_fetcher;
use tool_canvasuplifter\local\job_manager;

/**
 * Shared base for the adhoc tasks that process a queued package job.
 *
 * Both the analyse and build tasks first have to turn a job row into a readable
 * package file on disk: a URL job is downloaded here (off the web request, so a
 * large fetch can't time out the upload page) and the result stored for reuse,
 * while an upload job just copies its already-stored file out to a temp path.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class package_job_task extends adhoc_task {
    /**
     * Resolve a job to a readable package path on disk. When the job carries a
     * URL and no stored file yet, download it, store it (recording the file id
     * on the job so a later build reuses it), and return the downloaded temp
     * path. Otherwise copy the stored file out to a temp path.
     *
     * @param job_manager $jobs Job manager for progress/file-id updates.
     * @param stdClass $job The job row.
     * @return string Absolute path to the package on disk.
     */
    protected function resolve_package(job_manager $jobs, stdClass $job): string {
        if (empty($job->fileid) && !empty($job->packageurl)) {
            $jobs->set_progress((int) $job->id, 1, get_string('progressfetch', 'tool_canvasuplifter'));
            $temppackage = (new url_fetcher())->fetch((string) $job->packageurl);
            $jobs->set_fileid((int) $job->id, $this->store_fetched_package($temppackage, $job));
            return $temppackage;
        }
        return $this->copy_stored_file_to_temp((int) $job->fileid);
    }

    /**
     * Store a freshly downloaded package into the plugin's file area so a later
     * build can reuse it. Keyed by job id for uniqueness; any stale file from a
     * previous attempt of the same job is removed first.
     *
     * @param string $path Path to the downloaded package on disk.
     * @param stdClass $job The job row.
     * @return int The stored file's id.
     */
    protected function store_fetched_package(string $path, stdClass $job): int {
        $fs = get_file_storage();
        $filerecord = (object) [
            'contextid' => \context_system::instance()->id,
            'component' => 'tool_canvasuplifter',
            'filearea' => 'packages',
            'itemid' => (int) $job->userid,
            'filepath' => '/',
            'filename' => 'canvas-' . (int) $job->id . '.imscc',
            'userid' => (int) $job->userid,
        ];
        $existing = $fs->get_file(
            $filerecord->contextid,
            $filerecord->component,
            $filerecord->filearea,
            $filerecord->itemid,
            $filerecord->filepath,
            $filerecord->filename
        );
        if ($existing) {
            $existing->delete();
        }
        return (int) $fs->create_file_from_pathname($filerecord, $path)->get_id();
    }

    /**
     * Copy a stored package file out to a normal temp file so the ingest
     * pipeline (which uses ZipArchive on a path) can read it.
     *
     * @param int $fileid stored_file id.
     * @return string Path to the temp copy.
     */
    protected function copy_stored_file_to_temp(int $fileid): string {
        $fs = get_file_storage();
        $file = $fs->get_file_by_id($fileid);
        if (!$file) {
            throw new \RuntimeException("Stored file $fileid not found");
        }
        $target = tempnam(make_request_directory(), 'canvasuplifter_');
        $file->copy_content_to($target);
        return $target;
    }
}
