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
 * Creates a mod_label activity from a Canvas ContextModuleSubHeader item.
 *
 * Canvas teachers drop subheaders between activities in a module to group them
 * (e.g. "Before Class"). They have no payload of their own — only a title — so
 * we mirror them as Moodle labels carrying the title as their introduction.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class label_builder {
    /**
     * Create a mod_label activity in the given section.
     *
     * @param stdClass $course Course record.
     * @param int $sectionnum 0-indexed section number.
     * @param item $modelitem The subheader item from the parsed model.
     * @return int|null Created course module id, or null on failure.
     */
    public function build(stdClass $course, int $sectionnum, item $modelitem): ?int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/modlib.php');

        $title = trim($modelitem->title);
        if ($title === '') {
            return null;
        }

        $module = $DB->get_record('modules', ['name' => 'label']);
        if (!$module) {
            return null;
        }

        // mod_label uses <h3> for subheader-style labels in many course themes; a
        // small bold paragraph travels better across themes and matches Canvas's
        // own subheader styling.
        $intro = '<p><strong>' . s($title) . '</strong></p>';

        $moduleinfo = (object) [
            'modulename' => 'label',
            'module' => (int) $module->id,
            'course' => $course->id,
            'section' => $sectionnum,
            'visible' => 1,
            'name' => $title,
            'intro' => $intro,
            'introformat' => FORMAT_HTML,
        ];

        $created = add_moduleinfo($moduleinfo, $course);
        return (int) $created->coursemodule;
    }
}
