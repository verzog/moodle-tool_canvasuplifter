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
    use qti_source_locator;

    /** @var string|null Why the last build() returned null, for the skip report. */
    public ?string $skipreason = null;

    /** @var int[] Ids of every question imported across all build() calls, for the link-rewrite pass. */
    public array $importedquestionids = [];

    /** @var int How many referenced item banks the last build() resolved (for the quiz-from-bank toggle). */
    public int $lastbankdraws = 0;

    /** @var bool Whether the last build()'s bank draws were missing or partially imported. */
    public bool $lastbankincomplete = false;

    /** @var bool Whether the last build() returned null purely because its questions live in shared banks. */
    public bool $lasthandledviabank = false;

    /** @var int How many item-bank draws the last build()'s assessment authored (resolved or not). */
    public int $lastbankselections = 0;

    /** @var string Absolute path to the extracted package root. */
    private string $packageroot;

    /** @var media_report|null Shared collector for unresolved question media references. */
    private ?media_report $mediareport;

    /** @var item_bank_registry Shared importer for the item banks an orphan New Quiz draws from. */
    private item_bank_registry $bankregistry;

    /**
     * Constructor.
     *
     * @param string $packageroot Absolute path to the extracted package directory.
     * @param media_report|null $mediareport Shared collector for unresolved media references (null to skip).
     * @param item_bank_registry|null $bankregistry Shared item-bank importer; one is created when null.
     */
    public function __construct(
        string $packageroot,
        ?media_report $mediareport = null,
        ?item_bank_registry $bankregistry = null
    ) {
        $this->packageroot = rtrim($packageroot, '/');
        $this->mediareport = $mediareport;
        $this->bankregistry = $bankregistry ?? new item_bank_registry($this->packageroot, $this->mediareport);
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
        $this->lastbankdraws = 0;
        $this->lastbankincomplete = false;
        $this->lasthandledviabank = false;
        $this->lastbankselections = 0;

        $qtipath = $this->locate_qti($modelitem);
        if ($qtipath === null) {
            $this->skipreason = 'no QTI assessment file found';
            return null;
        }
        // Parse the assessment, falling back to the native non_cc_assessments dump
        // when the Common Cartridge file is an empty shell (exactly as quiz_builder
        // does), and read any item-bank draws.
        [$parsed, $supported, $importable, $imagedir, $selections] =
            $this->resolve_assessment_source($modelitem, $qtipath);
        $questions = $parsed['questions'];

        // A standalone Canvas item bank (this resource's file is a
        // non_cc_assessments/<id>.xml.qti rooted at <objectbank>) is imported through the
        // shared registry keyed by its file basename, so a bank a New Quiz also draws from
        // (sourcebank_ref = that id), or the same bank reached from another resource, is
        // imported exactly once. The registry-created mod_qbank is this item's own module.
        if (!empty($parsed['hasobjectbank']) && ($bankid = $this->objectbank_bankid($qtipath)) !== null) {
            $bank = $this->bankregistry->import_bank($course, $bankid);
            if ($bank === null) {
                $this->skipreason = question_importer::describe_unconvertible(
                    $questions,
                    $supported,
                    $parsed['unresolved'] ?? 0
                );
                return null;
            }
            return (int) $bank['cmid'];
        }

        // A Canvas New Quiz that isn't linked from a module is routed here rather than
        // to quiz_builder; it can draw its questions from a separate item bank via
        // <selection_ordering>/<sourcebank_ref>. Import each referenced bank (once,
        // shared) so those questions aren't dropped, even when the assessment carries
        // no inline questions of its own. The imported bank is shared infrastructure —
        // it isn't this item's own module, so it's never returned as the build result
        // (which would mis-key its link/visibility to the quiz item) nor deleted with a
        // failed inline import below; it simply survives, holding the drawn questions.
        $importedbanks = $this->import_bank_draws($course, $selections);
        $this->lastbankdraws = $importedbanks;

        if (empty($importable)) {
            // Nothing of our own converts to a standalone bank. The item is only truly
            // "handled via shared banks" when it carried NO inline questions of its own —
            // no parsed questions AND no bare item references whose bodies Canvas didn't
            // export (tracked in 'unresolved', not $questions) — and its draws resolved:
            // then its questions are safe in the shared bank and it is not a data-loss
            // skip, so the caller counts it created. If it had inline questions that were
            // unconvertible, unsupported, or unresolved, those would be lost, so keep an
            // honest skip even when banks resolved — never mark it handled.
            $bankonly = empty($questions) && (int) ($parsed['unresolved'] ?? 0) === 0 && $importedbanks > 0;
            $this->lasthandledviabank = $bankonly;
            $this->skipreason = $bankonly
                ? sprintf(
                    'questions imported into %d shared item bank(s); no standalone bank built for this New Quiz',
                    $importedbanks
                )
                : question_importer::describe_unconvertible($questions, $supported, $parsed['unresolved'] ?? 0);
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
        // Collect missing question media into a provisional report, merged into the
        // shared build report only if this bank is kept, so a rejected-and-deleted
        // import reports no phantom assets.
        $localreport = $this->mediareport !== null ? new media_report() : null;
        $questionids = (new question_importer())
            ->import($course, $context, $supported, $imagedir, $this->packageroot, $localreport);
        if (empty($questionids)) {
            // Nothing imported despite some questions looking convertible; don't
            // leave an empty bank behind. Its provisional media is dropped with it.
            course_delete_module($cmid);
            $this->skipreason = sprintf(
                "Moodle's importer rejected all %d convertible question(s)",
                count($importable)
            );
            return null;
        }
        if ($this->mediareport !== null && $localreport !== null) {
            $this->mediareport->merge($localreport);
        }
        // Record the imported questions so course_builder can resolve any
        // internal Canvas links in their text once every activity exists.
        $this->importedquestionids = array_merge($this->importedquestionids, array_map('intval', $questionids));
        return $cmid;
    }

    /**
     * The bank id for a standalone item-bank file, or null when the file is not one the
     * shared registry can key. The registry derives non_cc_assessments/<id>.xml.qti from
     * the id, so only a file in that folder qualifies; its basename (minus .xml.qti) is
     * the id, matching a New Quiz's sourcebank_ref so the two dedupe to one import.
     *
     * @param string $qtipath Absolute path of the located QTI file.
     * @return string|null
     */
    private function objectbank_bankid(string $qtipath): ?string {
        if (!preg_match('#(^|/)non_cc_assessments/[^/]+\.xml\.qti$#i', $qtipath)) {
            return null;
        }
        return basename($qtipath, '.xml.qti');
    }

    /**
     * Import the item banks a New Quiz draws from through the shared registry, so an
     * orphan bank-backed quiz's questions aren't lost. Each referenced bank is imported
     * once (shared across the whole build) as its own always-visible mod_qbank; an
     * explicit zero-question draw imports nothing. This is a side effect — the imported
     * banks are shared and are not this item's own module. Sets $lastbankincomplete when
     * a referenced bank is missing, only partially imported, or asked for more questions
     * than it holds (a single over-sized draw, or repeated draws that together outrun the
     * pool), so a caller that doesn't hand the quiz to quiz_builder can still warn that a
     * draw is short — mirroring quiz_builder::populate_from_banks().
     *
     * @param stdClass $course Course record.
     * @param array $selections Parsed selections: each ['bank' => id, 'count' => n|null, 'points' => p|null].
     * @return int The number of distinct referenced banks that resolved to an import.
     */
    private function import_bank_draws(stdClass $course, array $selections): int {
        // Record how many draws the assessment authored (each has a sourcebank_ref),
        // resolved or not, so a caller can still build quiz_builder's hidden placeholder
        // for a bank-backed quiz whose banks are all missing.
        $this->lastbankselections = count($selections);
        $imported = [];
        $remaining = [];
        $incomplete = false;
        foreach ($selections as $selection) {
            if (($selection['count'] ?? null) !== null && (int) $selection['count'] < 1) {
                // An authored empty draw imports no bank.
                continue;
            }
            $bankid = (string) ($selection['bank'] ?? '');
            if ($bankid === '') {
                continue;
            }
            $bank = $this->bankregistry->import_bank($course, $bankid);
            if ($bank === null) {
                // The referenced bank couldn't be read or held nothing importable.
                $incomplete = true;
                continue;
            }
            if (!$bank['full']) {
                // Unsupported/unresolved candidates shrank the imported pool.
                $incomplete = true;
            }
            $imported[$bankid] = true;
            if (!array_key_exists($bankid, $remaining)) {
                $remaining[$bankid] = (int) $bank['count'];
            }
            // A missing selection_number requests the whole bank (its full count, like
            // quiz_builder::populate_from_banks); an explicit count requests that many.
            // Either way the request is charged against what's left of the pool, so a draw
            // of 5 from a two-question bank — or repeated groups that together outrun it,
            // including two draw-all groups — is flagged short even when every source
            // question imported (full === true).
            $want = ($selection['count'] ?? null) === null ? (int) $bank['count'] : (int) $selection['count'];
            if ($want > $remaining[$bankid]) {
                $incomplete = true;
            }
            $remaining[$bankid] = max(0, $remaining[$bankid] - $want);
        }
        $this->lastbankincomplete = $incomplete;
        return count($imported);
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
