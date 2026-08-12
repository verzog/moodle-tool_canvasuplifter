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

namespace tool_canvasuplifter\local\parser;

use DOMDocument;
use DOMElement;

/**
 * Classifies which LMS authored/exported a Common Cartridge package.
 *
 * The neutral CC structure parses the same regardless of source, but a few
 * exporters leave recognisable fingerprints — D2L stamps every resource with a
 * material_type attribute, Blackboard ships a web_content<NNN>.log build log,
 * ANGEL uses <system>ID_LM_/GLO_/FOLD_/… content identifiers and marks leftover
 * duplicates _UNREFERENCED_, and eXe carries its Yahoo-UI framework payload.
 * Knowing the source lets the report name it and lets the parser drop
 * exporter-specific junk. Pure detection, no Moodle dependency.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_detector {
    /** Canvas LMS export. */
    public const CANVAS = 'canvas';
    /** Blackboard Learn export (a Common Cartridge Blackboard authored/exported). */
    public const BLACKBOARD = 'blackboard';
    /**
     * Blackboard Learn NATIVE export (not Common Cartridge): Blackboard's own
     * IMS-CP variant, fingerprinted by a .bb-package-info marker or x-bb-* resource
     * types, with content in resNNNNN.dat files. This tool imports Canvas Common
     * Cartridge, not this format, so it builds nothing from such a package; detected
     * so the report can say so instead of silently producing zero items.
     */
    public const BLACKBOARD_NATIVE = 'blackboard_native';
    /** D2L Brightspace export. */
    public const D2L = 'd2l';
    /** ANGEL LMS export (often carrying eXe learning modules). */
    public const ANGEL = 'angel';
    /** eXe-authored content (without ANGEL identifiers). */
    public const EXE = 'exe';
    /** Plain IMS Common Cartridge, source not recognised. */
    public const GENERIC = 'generic';

    /**
     * Detect the authoring/export system behind a package from its manifest and
     * layout. Returns one of the class constants.
     *
     * @param string $basedir Absolute path to the extracted package root.
     * @param DOMDocument $manifest The parsed imsmanifest.xml.
     * @return string One of the source constants.
     */
    public static function detect(string $basedir, DOMDocument $manifest): string {
        $angel = false;
        $d2l = false;
        $exe = false;
        $blackboardlog = false;
        $bbmarker = is_file($basedir . '/.bb-package-info');
        $hasxbb = false;
        $hasccontent = false;
        // Recognised Common Cartridge / Canvas content resource types (the ones
        // classify() can build something from), used to tell a wholly-native
        // Blackboard package from one that only mixes in a few x-bb-* settings.
        $ccpattern = '#(webcontent|imswl_xmlv1p|imsdt_xmlv1p|imsbasiclti_xmlv1p'
            . '|assignment_xmlv1p|imsqti|imscc_|learning-application-resource|question-bank)#i';
        foreach ($manifest->getElementsByTagNameNS('*', 'resource') as $resource) {
            if (!($resource instanceof DOMElement)) {
                continue;
            }
            // Blackboard's native export declares proprietary x-bb-* resource types
            // (resource/x-bb-document, assessment/x-bb-qti-test, course/x-bb-*), which
            // no Common Cartridge exporter uses. Track those separately from any
            // recognised CC content type, so a package that mixes a few x-bb-* settings
            // resources with genuine importable CC content is not mistaken for native.
            $type = $resource->getAttribute('type');
            if (stripos($type, 'x-bb-') !== false) {
                $hasxbb = true;
            } else if (preg_match($ccpattern, $type)) {
                $hasccontent = true;
            }
            $identifier = $resource->getAttribute('identifier');
            // ANGEL is recognised by its <system>ID_LM_/FOLD_/GLO_/FRM_/CRS_<n>
            // content identifiers only. The _UNREFERENCED_ marker alone is NOT an
            // ANGEL signal: a non-ANGEL cartridge that happens to use that token in
            // a real identifier must not be classified as ANGEL, or the parser's
            // _UNREFERENCED_ cleanup would drop that genuine resource.
            if (preg_match('/id_(lm|fold|glo|frm|crs)_\d/i', $identifier)) {
                $angel = true;
            }
            foreach ($resource->attributes as $attr) {
                if ($attr->localName === 'material_type') {
                    $d2l = true;
                }
            }
            $paths = $resource->getAttribute('href') !== '' ? [$resource->getAttribute('href')] : [];
            foreach ($resource->getElementsByTagNameNS('*', 'file') as $file) {
                if ($file instanceof DOMElement) {
                    $paths[] = $file->getAttribute('href');
                }
            }
            foreach ($paths as $path) {
                if (preg_match('~(^|/)web_content\d+\.log$~i', (string) $path)) {
                    $blackboardlog = true;
                }
                if (preg_match('~(^|/)(js/yahoo/|exe_)~i', (string) $path)) {
                    $exe = true;
                }
            }
        }
        // The .bb-package-info marker is definitive — only Blackboard's native
        // packager writes it — so it identifies a native package on its own. The
        // x-bb-* resource-type heuristic is softer, so it only concludes "native"
        // when the package carries no importable Common Cartridge content at all; a
        // package that mixes a few x-bb-* settings with real CC content is left to
        // import what it can. Recognised first so a wholly-native package's x-bb-*
        // resources don't fall through as unclassified and build nothing.
        if ($bbmarker || ($hasxbb && !$hasccontent)) {
            return self::BLACKBOARD_NATIVE;
        }
        if ($d2l) {
            return self::D2L;
        }
        if ($blackboardlog) {
            return self::BLACKBOARD;
        }
        if ($angel) {
            return self::ANGEL;
        }
        if (is_file($basedir . '/course_settings/canvas_export.txt') || is_dir($basedir . '/web_resources')) {
            return self::CANVAS;
        }
        if ($exe) {
            return self::EXE;
        }
        return self::GENERIC;
    }
}
