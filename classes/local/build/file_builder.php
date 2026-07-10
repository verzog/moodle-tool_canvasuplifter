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

use stdClass;
use tool_canvasuplifter\local\model\item;

/**
 * Creates a mod_resource activity from a file item.
 *
 * Copies the source file out of the extracted package directory into a draft
 * filearea owned by the current $USER, then hands the draftitemid to
 * {@see add_moduleinfo()} which moves the file into mod_resource's content
 * area as part of creating the activity.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class file_builder {
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
     * Create a mod_resource activity in the given section.
     *
     * @param stdClass $course Course record.
     * @param int $sectionnum 0-indexed section number.
     * @param item $modelitem The file item from the parsed model.
     * @return int|null Created course module id, or null if the file could not be located.
     */
    public function build(stdClass $course, int $sectionnum, item $modelitem): ?int {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->libdir . '/filelib.php');
        require_once($CFG->libdir . '/resourcelib.php');

        $sourcepath = $this->source_path($modelitem);
        if ($sourcepath === null) {
            if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
                mtrace(sprintf(
                    'tool_canvasuplifter: file "%s" skipped — no readable payload (href=%s, files=%s)',
                    $modelitem->title,
                    $modelitem->href,
                    implode(',', $modelitem->files)
                ));
            }
            return null;
        }

        $module = $DB->get_record('modules', ['name' => 'resource']);
        if (!$module) {
            return null;
        }

        $title = $modelitem->title !== ''
            ? $modelitem->title
            : pathinfo($sourcepath, PATHINFO_FILENAME);

        // Copy the file into a new draft area; add_moduleinfo will pick it
        // up via the 'files' field and move it into mod_resource/content.
        // A folded HTML bundle pins the main file at its rebased filearea path
        // (a subfolder when a parent-directory asset forced the root up a level)
        // so the page's own relative links keep resolving; a plain file lands at
        // the root under its basename.
        $mainrel = ltrim($modelitem->bundlehtmlpath, '/');
        if ($mainrel === '') {
            $mainrel = basename($sourcepath);
        }
        $mainslash = strrpos($mainrel, '/');
        $mainpath = $mainslash === false ? '/' : '/' . substr($mainrel, 0, $mainslash + 1);
        $mainname = $mainslash === false ? $mainrel : substr($mainrel, $mainslash + 1);
        $draftitemid = file_get_unused_draft_itemid();
        $usercontext = \context_user::instance($USER->id);
        $fs = get_file_storage();
        $fs->create_file_from_pathname([
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => clean_param($mainpath, PARAM_PATH),
            'filename' => clean_param($mainname, PARAM_FILE),
            'sortorder' => 1,
        ], $sourcepath);

        // A self-contained HTML file (an interactive exercise) folds its assets
        // (js/css/images) in alongside it, each at its path relative to the HTML
        // so the page's own relative links resolve, and displays embedded so it
        // renders inline and works. The HTML keeps sortorder 1 as the main file.
        $display = RESOURCELIB_DISPLAY_AUTO;
        foreach ($modelitem->bundleassets as $asset) {
            $assetabs = safe_path::within($this->packageroot, (string) ($asset['source'] ?? ''));
            if ($assetabs === null || !is_file($assetabs) || !is_readable($assetabs)) {
                continue;
            }
            $relpath = ltrim((string) ($asset['relpath'] ?? ''), '/');
            $slash = strrpos($relpath, '/');
            $filepath = $slash === false ? '/' : '/' . substr($relpath, 0, $slash + 1);
            $filename = $slash === false ? $relpath : substr($relpath, $slash + 1);
            if ($filename === '') {
                continue;
            }
            $fs->create_file_from_pathname([
                'contextid' => $usercontext->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftitemid,
                'filepath' => clean_param($filepath, PARAM_PATH),
                'filename' => clean_param($filename, PARAM_FILE),
                'sortorder' => 0,
            ], $assetabs);
            $display = RESOURCELIB_DISPLAY_EMBED;
        }

        $moduleinfo = (object) [
            'modulename' => 'resource',
            'module' => $module->id,
            'course' => $course->id,
            'section' => $sectionnum,
            'visible' => 1,
            'name' => $title,
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'files' => $draftitemid,
            'display' => $display,
            'displayoptions' => serialize([
                'printintro' => 0,
                'printlastmodified' => 1,
                'showsize' => 1,
                'showtype' => 1,
                'showdate' => 0,
            ]),
            'showsize' => 1,
            'showtype' => 1,
            'showdate' => 0,
            'printintro' => 0,
            'printlastmodified' => 1,
            'filterfiles' => 0,
        ];

        $created = add_moduleinfo($moduleinfo, $course);
        return (int) $created->coursemodule;
    }

    /**
     * Locate the underlying file inside the extracted package.
     *
     * Tries href first (the resource's declared main payload) then the explicit
     * file list, skipping anything that isn't a regular readable file. Preferring
     * href keeps the right file as the activity's main payload even when the
     * manifest lists a secondary asset ahead of it — without relying on the fold
     * pass having reordered the file list (it does not run for a resource whose
     * folding was disabled, e.g. by an external document base).
     *
     * @param item $modelitem
     * @return string|null Absolute path, or null.
     */
    private function source_path(item $modelitem): ?string {
        $candidates = [];
        if ($modelitem->href !== '') {
            $candidates[] = $modelitem->href;
        }
        $candidates = array_merge($candidates, $modelitem->files);
        foreach ($candidates as $relative) {
            $absolute = safe_path::within($this->packageroot, $relative);
            if ($absolute !== null && is_file($absolute) && is_readable($absolute)) {
                return $absolute;
            }
        }
        return null;
    }
}
