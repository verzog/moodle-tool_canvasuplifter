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
use tool_canvasuplifter\local\parser\qti_parser;

/**
 * Creates a mod_qbank activity from a Canvas QTI assessment and imports its
 * questions into the bank.
 *
 * The QTI is parsed (see {@see qti_parser}) and rendered to Moodle import XML
 * (see {@see question_xml_writer}), then loaded through Moodle's own qformat_xml
 * importer into the new bank's default question category. Question banks must be
 * created in section 0, so the section number is ignored.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class questionbank_builder {
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
     * Create a mod_qbank and import the assessment's questions.
     *
     * @param stdClass $course Course record.
     * @param int $sectionnum Ignored; question banks are created in section 0.
     * @param item $modelitem The quiz/question-bank item.
     * @return int|null Created course module id, or null if there's nothing to import.
     */
    public function build(stdClass $course, int $sectionnum, item $modelitem): ?int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/question/format.php');
        require_once($CFG->dirroot . '/question/format/xml/format.php');

        $qtipath = $this->locate_qti($modelitem);
        if ($qtipath === null) {
            return null;
        }
        $parsed = (new qti_parser())->parse((string) @file_get_contents($qtipath));
        $supported = array_filter(
            $parsed['questions'],
            fn($q) => $q->type !== \tool_canvasuplifter\local\model\qti_question::TYPE_UNSUPPORTED
        );
        if (empty($supported)) {
            return null;
        }

        $name = $modelitem->title !== '' ? $modelitem->title
            : ($parsed['title'] !== '' ? $parsed['title'] : 'Question bank');

        $module = $DB->get_record('modules', ['name' => 'qbank']);
        if (!$module) {
            return null;
        }

        $moduleinfo = (object) [
            'modulename' => 'qbank',
            'module' => $module->id,
            'course' => $course->id,
            'section' => 0,
            'visible' => 1,
            'name' => $name,
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'type' => \core_question\local\bank\question_bank_helper::TYPE_STANDARD,
        ];
        $created = add_moduleinfo($moduleinfo, $course);
        $cmid = (int) $created->coursemodule;

        $this->import_questions($course, $cmid, $supported, dirname($qtipath));
        return $cmid;
    }

    /**
     * Render the questions to Moodle XML and import them into the bank's
     * default category via qformat_xml.
     *
     * @param stdClass $course Course record.
     * @param int $cmid The new qbank course module id.
     * @param array $questions Supported {@see qti_question} objects.
     * @param string $imagedir Folder for resolving question images.
     * @return void
     */
    private function import_questions(stdClass $course, int $cmid, array $questions, string $imagedir): void {
        $context = \context_module::instance($cmid);
        $category = question_get_default_category($context->id, true);
        $contexts = new \core_question\local\bank\question_edit_contexts($context);

        $xml = (new question_xml_writer())->to_moodle_xml($questions, $category->name, $imagedir);
        $dir = make_request_directory();
        $file = $dir . '/questions.xml';
        file_put_contents($file, $xml);

        $qformat = new \qformat_xml();
        $qformat->setCategory($category);
        $qformat->setContexts($contexts->having_one_edit_tab_cap('import'));
        $qformat->setCourse($course);
        $qformat->setFilename($file);
        $qformat->setRealfilename('questions.xml');
        $qformat->setMatchgrades('nearest');
        $qformat->setCatfromfile(false);
        $qformat->setContextfromfile(false);
        $qformat->setStoponerror(false);

        // The qformat importer echoes import progress/notifications; keep it out of the build output.
        ob_start();
        try {
            if ($qformat->importpreprocess()) {
                $qformat->importprocess();
                $qformat->importpostprocess();
            }
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Find the QTI assessment XML file for this resource.
     *
     * @param item $modelitem The item.
     * @return string|null Absolute path, or null.
     */
    private function locate_qti(item $modelitem): ?string {
        $candidates = $modelitem->files;
        if ($modelitem->href !== '') {
            $candidates[] = $modelitem->href;
        }
        foreach ($candidates as $relative) {
            if (!preg_match('/\.xml(\.qti)?$/i', $relative)) {
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
