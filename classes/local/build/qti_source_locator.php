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

use tool_canvasuplifter\local\model\item;
use tool_canvasuplifter\local\model\qti_question;
use tool_canvasuplifter\local\parser\qti_parser;

/**
 * Shared QTI-source helpers for the quiz and question-bank builders.
 *
 * Both builders parse a QTI assessment the same way and recover the questions
 * Canvas exported only to its native dump (non_cc_assessments/<id>.xml.qti), so
 * that logic lives here once. The using class must expose a $packageroot
 * property (the extracted package root) for path resolution.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait qti_source_locator {
    /**
     * Parse a QTI file into [parsed, supported, importable], so the Common
     * Cartridge file and the native dump are handled uniformly.
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
     * Resolve the assessment source a builder should evaluate: parse the Common
     * Cartridge QTI, and when it yields nothing importable fall back to the native
     * non_cc_assessments dump — adopting it when it has inline questions or its own
     * item-bank draws, so a New Quiz whose real content (inline or bank-backed) lives
     * only in the dump is recovered. Item-bank selections are taken only from a
     * genuine assessment, so stray <selection> markup in malformed XML can't
     * fabricate draws. The using class must expose a $packageroot property.
     *
     * @param item $modelitem The assessment item.
     * @param string $qtipath Absolute path of the resolved Common Cartridge QTI file.
     * @return array [parsed, supported, importable, imagedir, selections, sequence].
     */
    private function resolve_assessment_source(item $modelitem, string $qtipath): array {
        [$parsed, $supported, $importable] = $this->parse_qti($qtipath);
        // Common Cartridge question media resolves relative to the quiz folder.
        $imagedir = dirname($qtipath);
        $selections = ($parsed['hasassessment'] ?? false) ? ($parsed['selections'] ?? []) : [];

        // The native non_cc_assessments dump can supply the real inline questions when the
        // CC file is an empty shell (Canvas exports them only there) and/or the item-bank
        // draws (Canvas often stores <selection_ordering> only in the dump). Inspect it
        // whenever the CC parse is missing either — including when the CC questions convert
        // cleanly but carry no draws of their own, so bank-backed content isn't dropped.
        if (empty($importable) || empty($selections)) {
            $native = $this->locate_native_qti($modelitem, $qtipath);
            if ($native !== null) {
                [$nativeparsed, $nativesupported, $nativeimportable] = $this->parse_qti($native);
                $nativeselections = ($nativeparsed['hasassessment'] ?? false)
                    ? ($nativeparsed['selections'] ?? []) : [];
                if (empty($importable) && !empty($nativeimportable)) {
                    // The real questions live in the native dump (the CC shell was empty), so
                    // adopt it wholesale, including its draws. Native questions reference media
                    // under the package root. Keep any CC draws when the dump has none.
                    $parsed = $nativeparsed;
                    $supported = $nativesupported;
                    $importable = $nativeimportable;
                    $imagedir = $this->packageroot;
                    $selections = !empty($nativeselections) ? $nativeselections : $selections;
                } else if (empty($selections) && !empty($nativeselections)) {
                    // Take the item-bank draws from the native dump, but keep the CC parse (its
                    // questions — importable or not — and their media base): swapping in the
                    // questionless native parse would hide unconvertible inline questions and
                    // drop bank draws that belong to a quiz whose inline questions convert.
                    $selections = $nativeselections;
                }
            }
        }
        // The authored order of inline items vs. bank draws is meaningful only when both
        // come from the same file. When the selections were taken from a different source
        // than the questions (CC inline questions + native-dump draws), there is no shared
        // order to preserve, so leave the sequence empty and the builder falls back to
        // inline-questions-then-bank-draws.
        $sequence = ($selections === ($parsed['selections'] ?? [])) ? ($parsed['sequence'] ?? []) : [];
        return [$parsed, $supported, $importable, $imagedir, $selections, $sequence];
    }

    /**
     * Find the native Canvas question dump for an assessment, used when the Common
     * Cartridge assessment_qti.xml is an empty shell. Canvas writes the real
     * questions to non_cc_assessments/<resource-id>.xml.qti at the package root,
     * keyed by the same id as the assessment's CC folder. Prefer an explicit
     * non_cc_assessments entry on the item's file list, then fall back to deriving
     * the id from the resolved QTI folder. Never return the file already parsed.
     *
     * @param item $modelitem The assessment item.
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
}
