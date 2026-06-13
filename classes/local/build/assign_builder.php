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
use tool_canvasuplifter\local\parser\assignment_settings;

/**
 * Creates a mod_assign activity from a Canvas assignment resource.
 *
 * A Canvas assignment is a "learning application resource" that bundles an
 * assignment_settings.xml (grading and submission configuration) with an HTML
 * description. This builder reads both, maps the settings onto Moodle's
 * mod_assign fields, creates the activity, and imports any files the
 * description embeds so images resolve through pluginfile.php.
 *
 * Rubrics and advanced grading are intentionally not carried across.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_builder {
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
     * Create a mod_assign activity in the given section.
     *
     * @param stdClass $course Course record.
     * @param int $sectionnum 0-indexed section number.
     * @param item $modelitem The assignment item from the parsed model.
     * @return int|null Created course module id, or null if settings could not be read.
     */
    public function build(stdClass $course, int $sectionnum, item $modelitem): ?int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/modlib.php');

        $settingspath = $this->locate($modelitem, 'assignment_settings.xml');
        if ($settingspath === null) {
            mtrace(sprintf(
                'tool_canvasuplifter: assignment "%s" skipped — no assignment_settings.xml (files=%s)',
                $modelitem->title,
                implode(',', $modelitem->files)
            ));
            return null;
        }
        $settings = assignment_settings::parse((string) @file_get_contents($settingspath));

        $module = $DB->get_record('modules', ['name' => 'assign']);
        if (!$module) {
            return null;
        }

        $intro = $this->description_html($modelitem, $settingspath);
        $name = $modelitem->title !== '' ? $modelitem->title : ($settings->title !== '' ? $settings->title : 'Assignment');

        $moduleinfo = $this->moduleinfo($course, $sectionnum, $module->id, $name, $intro, $settings);
        $created = add_moduleinfo($moduleinfo, $course);
        $cmid = (int) $created->coursemodule;

        // Import description images/files and rewrite the intro to pluginfile refs.
        if ($intro !== '') {
            $context = \context_module::instance($cmid);
            $newintro = (new file_embedder($this->packageroot))->embed($context->id, 'mod_assign', 'intro', $intro);
            if ($newintro !== $intro) {
                $DB->set_field('assign', 'intro', $newintro, ['id' => (int) $created->instance]);
            }
        }

        return $cmid;
    }

    /**
     * Assemble the moduleinfo for add_moduleinfo(), mirroring the mod_assign
     * form defaults and overlaying the Canvas settings we understand.
     *
     * @param stdClass $course Course record.
     * @param int $sectionnum Section number.
     * @param int $moduleid The assign entry in the modules table.
     * @param string $name Activity name.
     * @param string $intro Description HTML.
     * @param assignment_settings $settings Parsed Canvas settings.
     * @return stdClass
     */
    private function moduleinfo(
        stdClass $course,
        int $sectionnum,
        int $moduleid,
        string $name,
        string $intro,
        assignment_settings $settings
    ): stdClass {
        $onlinetext = $settings->wants_onlinetext() ? 1 : 0;
        $fileupload = $settings->wants_fileupload() ? 1 : 0;
        // If Canvas named no submission type we understand, default to file upload
        // so the activity is still usable.
        if ($onlinetext === 0 && $fileupload === 0) {
            $fileupload = 1;
        }

        return (object) [
            'modulename' => 'assign',
            'module' => $moduleid,
            'course' => $course->id,
            'section' => $sectionnum,
            'visible' => 1,
            'cmidnumber' => '',
            'name' => $name,
            'intro' => $intro,
            'introformat' => FORMAT_HTML,
            'alwaysshowdescription' => 1,
            'activity' => '',
            'activityformat' => FORMAT_HTML,
            'nosubmissions' => 0,
            // Grading: point grade when Canvas gives points, otherwise ungraded.
            'grade' => $settings->points > 0 ? $settings->points : 0,
            // Dates default to zero when Canvas leaves them unset.
            'duedate' => $settings->duedate,
            'allowsubmissionsfromdate' => $settings->allowfrom,
            'cutoffdate' => $settings->cutoff,
            'gradingduedate' => 0,
            'timelimit' => 0,
            // Submission workflow defaults.
            'submissiondrafts' => 0,
            'requiresubmissionstatement' => 0,
            'sendnotifications' => 0,
            'sendlatenotifications' => 0,
            'sendstudentnotifications' => 1,
            'teamsubmission' => 0,
            'requireallteammemberssubmit' => 0,
            'teamsubmissiongroupingid' => 0,
            'preventsubmissionnotingroup' => 0,
            'blindmarking' => 0,
            'hidegrader' => 0,
            'markingworkflow' => 0,
            'markingallocation' => 0,
            'markinganonymous' => 0,
            'attemptreopenmethod' => 'none',
            'maxattempts' => -1,
            'completion' => 0,
            'completionsubmit' => 0,
            'submissionattachments' => 0,
            // Submission plugins.
            'assignsubmission_onlinetext_enabled' => $onlinetext,
            'assignsubmission_onlinetext_wordlimit' => 0,
            'assignsubmission_onlinetext_wordlimitenabled' => 0,
            'assignsubmission_file_enabled' => $fileupload,
            'assignsubmission_file_maxfiles' => 20,
            'assignsubmission_file_maxsizebytes' => 0,
            'assignsubmission_file_filetypes' => $fileupload ? $settings->allowedextensions : '',
            'assignsubmission_comments_enabled' => 0,
            // Feedback plugins.
            'assignfeedback_comments_enabled' => 1,
            'assignfeedback_comments_commentinline' => 0,
            'assignfeedback_file_enabled' => 0,
            'assignfeedback_editpdf_enabled' => 0,
        ];
    }

    /**
     * Read the assignment description HTML from the package.
     *
     * Prefers an HTML file in the resource's file list (other than the settings
     * file), falling back to the href; returns '' when none is readable.
     *
     * @param item $modelitem The assignment item.
     * @param string $settingspath Absolute path of the settings file, to skip it.
     * @return string Description HTML, or ''.
     */
    private function description_html(item $modelitem, string $settingspath): string {
        $candidates = $modelitem->files;
        if ($modelitem->href !== '') {
            $candidates[] = $modelitem->href;
        }
        foreach ($candidates as $relative) {
            if (!preg_match('/\.html?$/i', $relative)) {
                continue;
            }
            $absolute = safe_path::within($this->packageroot, $relative);
            if ($absolute === null || $absolute === $settingspath || !is_readable($absolute)) {
                continue;
            }
            return (string) @file_get_contents($absolute);
        }
        return '';
    }

    /**
     * Find a file belonging to the resource whose name ends with $needle.
     *
     * @param item $modelitem The assignment item.
     * @param string $needle File name suffix to match, e.g. "assignment_settings.xml".
     * @return string|null Absolute path within the package, or null.
     */
    private function locate(item $modelitem, string $needle): ?string {
        foreach ($modelitem->files as $relative) {
            if (!str_ends_with($relative, $needle)) {
                continue;
            }
            $absolute = safe_path::within($this->packageroot, $relative);
            if ($absolute !== null && is_readable($absolute)) {
                return $absolute;
            }
        }
        return null;
    }
}
