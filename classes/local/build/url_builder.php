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
 * Creates a mod_url activity from an IMS web-link resource.
 *
 * Web-link resources in Common Cartridge store the target URL inside a small
 * XML file (often called weblink.xml) with shape
 * <webLink><url href="https://example.com"/></webLink>. This builder reads
 * that file, validates the URL, and creates the mod_url activity.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class url_builder {
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
     * Create a mod_url activity in the given section.
     *
     * @param stdClass $course Course record.
     * @param int $sectionnum 0-indexed section number.
     * @param item $modelitem The URL item from the parsed model.
     * @return int|null Created course module id, or null if the target URL could not be read.
     */
    public function build(stdClass $course, int $sectionnum, item $modelitem): ?int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->libdir . '/resourcelib.php');

        $externalurl = $this->extract_url($modelitem);
        if ($externalurl === null) {
            return null;
        }

        $module = $DB->get_record('modules', ['name' => 'url']);
        if (!$module) {
            return null;
        }

        $title = $modelitem->title !== '' ? $modelitem->title : $externalurl;

        $moduleinfo = (object) [
            'modulename' => 'url',
            'module' => $module->id,
            'course' => $course->id,
            'section' => $sectionnum,
            'visible' => 1,
            'name' => $title,
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'externalurl' => $externalurl,
            'display' => RESOURCELIB_DISPLAY_AUTO,
            'displayoptions' => serialize([]),
            'parameters' => serialize([]),
            'printintro' => 0,
        ];

        $created = add_moduleinfo($moduleinfo, $course);
        return (int) $created->coursemodule;
    }

    /**
     * Read the target URL out of the IMS web-link XML file.
     *
     * @param item $modelitem
     * @return string|null Validated http(s) URL, or null.
     */
    private function extract_url(item $modelitem): ?string {
        $candidates = $modelitem->files;
        if ($modelitem->href !== '') {
            $candidates[] = $modelitem->href;
        }
        foreach ($candidates as $relative) {
            $absolute = safe_path::within($this->packageroot, $relative);
            if ($absolute === null || !is_readable($absolute)) {
                continue;
            }
            $previous = libxml_use_internal_errors(true);
            $xml = simplexml_load_file($absolute, 'SimpleXMLElement', LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if ($xml === false) {
                continue;
            }
            $url = (string) ($xml->url['href'] ?? '');
            if ($url === '' && isset($xml->url)) {
                $url = trim((string) $xml->url);
            }
            if (preg_match('#^https?://#i', $url)) {
                return $url;
            }
        }
        return null;
    }
}
