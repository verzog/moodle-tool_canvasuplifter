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

/**
 * Imports Canvas item banks (non_cc_assessments/<id>.xml.qti) as shared mod_qbank
 * activities, once each, so every builder that references a bank reuses the same
 * import.
 *
 * A Canvas New Quiz draws its questions from a separate item bank via
 * <selection_ordering>/<sourcebank_ref>. Both quiz_builder (a referenced quiz) and
 * questionbank_builder (an orphan quiz or bank) can reference the same bank, and one
 * bank can be shared by several quizzes, so the import is centralised here and
 * memoised by bank id. That gives one section-0 mod_qbank per Canvas bank id across
 * the whole build and a single stream of imported question ids for the link-rewrite
 * pass.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class item_bank_registry {
    use qti_source_locator;

    /** @var int[] Ids of every question imported into a bank, for the link-rewrite pass. */
    public array $importedquestionids = [];

    /** @var string Absolute path to the extracted package root. */
    private string $packageroot;

    /** @var media_report|null Shared collector for unresolved question media references. */
    private ?media_report $mediareport;

    /** @var array Memoised import result per bank id: ['cmid', 'category', 'count', 'full'] or null. */
    private array $banks = [];

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
     * Import a Canvas item bank (non_cc_assessments/<id>.xml.qti) as a section-0
     * mod_qbank and return its course module id, default category id and the number
     * of questions imported. Memoised by bank id so a bank shared by several quizzes
     * (or reached via both builders) is imported once; a bank that can't be read or
     * yields nothing importable memoises (and returns) null.
     *
     * @param stdClass $course Course record.
     * @param string $bankid The Canvas sourcebank_ref (a package resource id).
     * @param string|null $name Preferred activity name (a standalone bank's disambiguated
     *        title); null keeps the bank's own <bank_title>. Renames the module if the
     *        bank was already imported under a different name.
     * @return array|null ['cmid' => int, 'category' => int, 'count' => int, 'full' => bool], or null when unavailable.
     */
    public function import_bank(stdClass $course, string $bankid, ?string $name = null): ?array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        if (array_key_exists($bankid, $this->banks)) {
            $existing = $this->banks[$bankid];
            if ($existing !== null && $name !== null && $name !== '') {
                // The bank was imported earlier (e.g. by a quiz draw) under its own
                // <bank_title>; a standalone resource now supplies the authoritative
                // (possibly disambiguated) name, so rename the module to match.
                $this->rename_bank($course, (int) $existing['cmid'], $name);
            }
            return $existing;
        }
        $this->banks[$bankid] = null;
        $file = $this->bank_dump_path($bankid);
        if ($file === null) {
            return null;
        }
        [$parsed, $supported, $importable] = $this->parse_qti($file);
        if (empty($importable)) {
            return null;
        }
        $module = $DB->get_record('modules', ['name' => 'qbank']);
        if (!$module) {
            return null;
        }
        $moduleinfo = (object) [
            'modulename' => 'qbank',
            'module' => $module->id,
            'course' => $course->id,
            // Question banks are course-bank activities, kept in section 0 like
            // questionbank_builder's, not scattered into a topic section.
            'section' => 0,
            'visible' => 1,
            'name' => $name !== null && $name !== '' ? $name
                : ($parsed['title'] !== '' ? $parsed['title'] : $this->default_bank_name()),
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'type' => \core_question\local\bank\question_bank_helper::TYPE_STANDARD,
        ];
        $created = add_moduleinfo($moduleinfo, $course);
        $context = \context_module::instance((int) $created->coursemodule);
        // Native bank questions reference media under the package root.
        $localreport = $this->mediareport !== null ? new media_report() : null;
        $questionids = (new question_importer())
            ->import($course, $context, $supported, $this->packageroot, $this->packageroot, $localreport);
        if (empty($questionids)) {
            course_delete_module((int) $created->coursemodule);
            return null;
        }
        if ($this->mediareport !== null && $localreport !== null) {
            $this->mediareport->merge($localreport);
        }
        $this->importedquestionids = array_merge($this->importedquestionids, array_map('intval', $questionids));
        $category = question_get_default_category($context->id, true);
        // The 'full' flag records whether every source question in the bank imported.
        // When the bank holds unsupported types (or the importer rejects some), Moodle's
        // pool is smaller than the one Canvas drew from, so a group sourcing this bank
        // must be reported as incomplete even when it can still fill its requested count.
        $this->banks[$bankid] = [
            'cmid' => (int) $created->coursemodule,
            'category' => (int) $category->id,
            'count' => count($questionids),
            'full' => count($questionids) === count($parsed['questions']) && (int) ($parsed['unresolved'] ?? 0) === 0,
        ];
        return $this->banks[$bankid];
    }

    /**
     * Rename an already-imported bank's mod_qbank, so a bank first created by a quiz draw
     * (under its own <bank_title>) adopts the authoritative name a standalone resource
     * later supplies. A no-op when the module is gone or already named that.
     *
     * @param stdClass $course Course record.
     * @param int $cmid The bank's course module id.
     * @param string $name The new activity name.
     * @return void
     */
    private function rename_bank(stdClass $course, int $cmid, string $name): void {
        global $DB;
        $cm = get_coursemodule_from_id('qbank', $cmid, 0, false, IGNORE_MISSING);
        if ($cm === false || $cm->name === $name) {
            return;
        }
        $DB->set_field('qbank', 'name', $name, ['id' => $cm->instance]);
        rebuild_course_cache((int) $course->id, true);
    }

    /**
     * The default name for an imported item bank when Canvas exported it untitled.
     *
     * @return string
     */
    private function default_bank_name(): string {
        return get_string('quizbankname', 'tool_canvasuplifter');
    }
}
