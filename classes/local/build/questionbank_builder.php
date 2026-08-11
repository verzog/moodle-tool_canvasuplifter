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
use tool_canvasuplifter\local\model\qti_question;
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
    /** @var string|null Why the last build() returned null, for the skip report. */
    public ?string $skipreason = null;

    /** @var int[] Ids of every question imported across all build() calls, for the link-rewrite pass. */
    public array $importedquestionids = [];

    /** @var string Absolute path to the extracted package root. */
    private string $packageroot;

    /** @var media_report|null Shared collector for unresolved question media references. */
    private ?media_report $mediareport;

    /**
     * Constructor.
     *
     * @param string $packageroot Absolute path to the extracted package directory.
     * @param media_report|null $mediareport Shared collector for unresolved media references (null to skip).
     */
    public function __construct(string $packageroot, ?media_report $mediareport = null) {
        $this->packageroot = rtrim($packageroot, '/');
        $this->mediareport = $mediareport;
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
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');

        $this->skipreason = null;

        $qtipath = $this->locate_qti($modelitem);
        if ($qtipath === null) {
            $this->skipreason = 'no QTI assessment file found';
            return null;
        }
        [$parsed, $supported, $importable] = $this->parse_qti($qtipath);
        // Question media in a Common Cartridge assessment resolves relative to the
        // quiz folder that holds the QTI.
        $imagedir = dirname($qtipath);

        // When the Common Cartridge assessment_qti.xml is an empty shell (Canvas
        // routinely exports the questions only to its native dump), fall back to
        // non_cc_assessments/<id>.xml.qti, exactly as quiz_builder does, so an
        // orphan New-Quiz bank recovers its questions instead of being skipped.
        // Only when the CC file yields nothing importable, so a bank that already
        // converts is left untouched.
        if (empty($importable)) {
            $native = $this->locate_native_qti($modelitem, $qtipath);
            if ($native !== null) {
                [$nativeparsed, $nativesupported, $nativeimportable] = $this->parse_qti($native);
                if (!empty($nativeimportable)) {
                    $parsed = $nativeparsed;
                    $supported = $nativesupported;
                    $importable = $nativeimportable;
                    // Native questions reference media under the package root.
                    $imagedir = $this->packageroot;
                }
            }
        }
        $questions = $parsed['questions'];
        if (empty($importable)) {
            $this->skipreason = question_importer::describe_unconvertible($questions, $supported, $parsed['unresolved'] ?? 0);
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

        $context = \context_module::instance($cmid);
        $questionids = (new question_importer())
            ->import($course, $context, $supported, $imagedir, $this->packageroot, $this->mediareport);
        if (empty($questionids)) {
            // Nothing imported despite some questions looking convertible; don't
            // leave an empty bank behind.
            course_delete_module($cmid);
            $this->skipreason = sprintf(
                "Moodle's importer rejected all %d convertible question(s)",
                count($importable)
            );
            return null;
        }
        // Record the imported questions so course_builder can resolve any
        // internal Canvas links in their text once every activity exists.
        $this->importedquestionids = array_merge($this->importedquestionids, array_map('intval', $questionids));
        return $cmid;
    }

    /**
     * Parse a QTI file into [parsed, supported, importable] the same way
     * quiz_builder does, so the CC file and the native dump are handled uniformly.
     *
     * @param string $path Absolute path to the QTI file.
     * @return array [parsed array, supported questions, importable questions].
     */
    private function parse_qti(string $path): array {
        $parsed = (new qti_parser())->parse((string) @file_get_contents($path));
        $supported = array_filter($parsed['questions'], fn($q) => $q->type !== qti_question::TYPE_UNSUPPORTED);
        $importable = array_filter($supported, fn($q) => $q->is_importable());
        return [$parsed, $supported, $importable];
    }

    /**
     * Find the native Canvas question dump for this bank, used when the Common
     * Cartridge assessment_qti.xml is an empty shell. Canvas writes the real
     * questions to non_cc_assessments/<resource-id>.xml.qti at the package root,
     * keyed by the same id as the assessment's CC folder. Prefer an explicit
     * non_cc_assessments entry on the item's file list, then fall back to deriving
     * the id from the resolved QTI folder. Never return the file already parsed.
     *
     * @param item $modelitem The bank item.
     * @param string $qtipath Absolute path of the resolved CC QTI file.
     * @return string|null Absolute path within the package, or null.
     */
    private function locate_native_qti(item $modelitem, string $qtipath): ?string {
        $already = realpath($qtipath);
        foreach ($modelitem->files as $relative) {
            if (!preg_match('~(^|/)non_cc_assessments/[^/]+\.xml\.qti$~i', $relative)) {
                continue;
            }
            $absolute = safe_path::within($this->packageroot, $relative);
            if ($absolute !== null && is_readable($absolute) && realpath($absolute) !== $already) {
                return $absolute;
            }
        }
        // Canvas keys the native dump by the assessment id, which is the CC folder
        // name for a foldered QTI but the resource identifier when the QTI sits at
        // the package root (where basename(dirname()) yields the extraction dir,
        // not the id). Try both.
        $ids = [basename(dirname($qtipath)), $modelitem->identifier];
        foreach ($ids as $id) {
            if ($id === '' || $id === '.' || $id === '/') {
                continue;
            }
            $candidate = safe_path::within($this->packageroot, 'non_cc_assessments/' . $id . '.xml.qti');
            if ($candidate !== null && is_readable($candidate) && realpath($candidate) !== $already) {
                return $candidate;
            }
        }
        return null;
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
