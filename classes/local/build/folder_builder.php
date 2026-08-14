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
 * Creates a mod_folder activity from a multi-file item.
 *
 * Used to recover a suppressed multi-file dependency whose owner activity failed
 * to build: a mod_resource would serve only its single main file, leaving the
 * other members unreachable, so the members are copied into a mod_folder instead,
 * which lists every one of them for download. Each file keeps its package
 * subdirectory so members that share a basename in different folders stay distinct.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class folder_builder {
    /** @var string Absolute path to the extracted package root. */
    private string $packageroot;

    /** @var media_report|null Collector to record the package files this folder embeds. */
    private ?media_report $mediareport;

    /**
     * Constructor.
     *
     * @param string $packageroot Absolute path to the extracted package directory.
     * @param media_report|null $mediareport Collector to record embedded files into (null to skip).
     */
    public function __construct(string $packageroot, ?media_report $mediareport = null) {
        $this->packageroot = rtrim($packageroot, '/');
        $this->mediareport = $mediareport;
    }

    /**
     * Create a mod_folder activity in the given section.
     *
     * @param stdClass $course Course record.
     * @param int $sectionnum 0-indexed section number.
     * @param item $modelitem The multi-file item from the parsed model.
     * @return int|null Created course module id, or null if no readable member could be placed.
     */
    public function build(stdClass $course, int $sectionnum, item $modelitem): ?int {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->libdir . '/filelib.php');
        require_once($CFG->dirroot . '/mod/folder/lib.php');

        $module = $DB->get_record('modules', ['name' => 'folder']);
        if (!$module) {
            return null;
        }

        // Collect the members to place, href first then the file list, each under its
        // own package subdirectory so two members with the same basename in different
        // folders stay distinct. A member a surviving owner already embedded is left
        // there rather than duplicated here, mirroring course_builder's reconciliation.
        $draftitemid = file_get_unused_draft_itemid();
        $usercontext = \context_user::instance($USER->id);
        $fs = get_file_storage();
        $embedded = [];
        $seen = [];
        $candidates = $modelitem->href !== '' ? array_merge([$modelitem->href], $modelitem->files) : $modelitem->files;
        foreach ($candidates as $relative) {
            $rel = ltrim((string) $relative, '/');
            $fileabs = safe_path::within($this->packageroot, $rel);
            if ($fileabs === null || !is_file($fileabs) || !is_readable($fileabs)) {
                continue;
            }
            if ($this->mediareport !== null && $this->mediareport->was_embedded($fileabs)) {
                continue;
            }
            $slash = strrpos($rel, '/');
            $filepath = clean_param($slash === false ? '/' : '/' . substr($rel, 0, $slash + 1), PARAM_PATH);
            $filename = clean_param($slash === false ? $rel : substr($rel, $slash + 1), PARAM_FILE);
            $key = $filepath . $filename;
            if ($filename === '' || isset($seen[$key])) {
                continue;
            }
            $fs->create_file_from_pathname([
                'contextid' => $usercontext->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftitemid,
                'filepath' => $filepath,
                'filename' => $filename,
                'sortorder' => 0,
            ], $fileabs);
            $seen[$key] = true;
            $embedded[] = $fileabs;
        }
        // Nothing readable to place — let the caller record the skip.
        if ($embedded === []) {
            return null;
        }

        $title = $modelitem->title !== '' ? $modelitem->title : pathinfo((string) reset($embedded), PATHINFO_FILENAME);
        $moduleinfo = (object) [
            'modulename' => 'folder',
            'module' => $module->id,
            'course' => $course->id,
            'section' => $sectionnum,
            'visible' => 1,
            'name' => $title,
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'files' => $draftitemid,
            'display' => FOLDER_DISPLAY_PAGE,
            'showexpanded' => 1,
            'showdownloadfolder' => 1,
            'forcedownload' => 0,
            'revision' => 1,
        ];

        $created = add_moduleinfo($moduleinfo, $course);
        // The module now exists, so its files are genuinely embedded: record them so
        // course_builder's reconciliation does not also recover any as a separate download.
        if ($this->mediareport !== null) {
            foreach ($embedded as $packagepath) {
                $this->mediareport->record_embedded($packagepath);
            }
        }
        return (int) $created->coursemodule;
    }
}
