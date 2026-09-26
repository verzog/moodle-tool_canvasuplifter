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
 * Reads a Common Cartridge LTI link (<cartridge_basiclti_link>) into its launch fields.
 *
 * Canvas wraps these in a default namespace plus a blti: prefix (e.g. <blti:launch_url>), so this
 * walks the DOM namespace-agnostically. Moodle-free so both the builder (which creates the
 * mod_lti activity) and the analyse report (which classifies the link before any build) can read
 * the same fields from the same parser.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lti_cartridge {
    /**
     * Parse a Common Cartridge LTI XML document.
     *
     * @param string $xml The cartridge XML.
     * @return array|null ['title','launchurl','secureurl','description','custom'] or null when the
     *         document is not parseable or carries no usable http(s) launch URL.
     */
    public static function parse(string $xml): ?array {
        if (trim($xml) === '') {
            return null;
        }
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || $dom->documentElement === null) {
            return null;
        }
        $title = self::first_child_text($dom, 'title');
        $launchurl = self::sanitise_url(self::first_child_text($dom, 'launch_url'));
        $secureurl = self::sanitise_url(self::first_child_text($dom, 'secure_launch_url'));
        $description = self::first_child_text($dom, 'description');
        // A cartridge with no usable http(s) URL is not a safe placeholder. Reject javascript:,
        // data:, file:, app-internal schemes and the like so a malformed or malicious package
        // can't create an active LTI endpoint that students could later launch.
        if ($launchurl === '' && $secureurl === '') {
            return null;
        }
        if ($launchurl === '') {
            $launchurl = $secureurl;
        }
        return [
            'title' => $title,
            'launchurl' => $launchurl,
            'secureurl' => $secureurl,
            'description' => $description,
            'custom' => self::read_custom_parameters($dom),
        ];
    }

    /**
     * The Canvas lookup_uuid of a cartridge: the <lticm:property name="lookup_uuid"> in its
     * <blti:extensions platform="canvas.instructure.com">. Canvas writes the same value as an
     * external-tool assignment's resource_link_lookup_uuid, which pairs the two.
     *
     * @param string $xml The cartridge XML.
     * @return string The uuid, or '' when absent or unparseable.
     */
    public static function lookup_uuid(string $xml): string {
        if (trim($xml) === '') {
            return '';
        }
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return '';
        }
        foreach ($dom->getElementsByTagNameNS('*', 'property') as $node) {
            $parent = $node->parentNode;
            if (
                $node instanceof DOMElement && $node->getAttribute('name') === 'lookup_uuid'
                && $parent instanceof DOMElement && $parent->localName === 'extensions'
                && $parent->getAttribute('platform') === 'canvas.instructure.com'
            ) {
                return trim($node->textContent);
            }
        }
        return '';
    }

    /**
     * Validate a candidate launch URL: only http and https are accepted, so javascript:, data:,
     * file:, mailto: and other dangerous or unusable schemes never reach mod_lti as a tool
     * endpoint.
     *
     * @param string $url Candidate URL.
     * @return string The URL if it's http(s), or the empty string.
     */
    public static function sanitise_url(string $url): string {
        $url = trim($url);
        return preg_match('#^https?://#i', $url) === 1 ? $url : '';
    }

    /**
     * Read the cartridge's <blti:custom> parameters. Many deep-linked publisher tools encode the
     * resource id (or which Canvas assignment the link points at) in these parameters.
     *
     * @param DOMDocument $dom The parsed cartridge.
     * @return array Map of parameter name -> value, in document order.
     */
    private static function read_custom_parameters(DOMDocument $dom): array {
        $params = [];
        foreach ($dom->getElementsByTagNameNS('*', 'property') as $node) {
            if (!($node instanceof DOMElement)) {
                continue;
            }
            // Custom parameters live inside <blti:custom>; ignore <lticm:property> elements
            // elsewhere (e.g. inside <blti:extensions>) so platform extensions don't leak into
            // mod_lti's instructor parameters.
            $parent = $node->parentNode;
            if (!($parent instanceof DOMElement) || $parent->localName !== 'custom') {
                continue;
            }
            $name = trim($node->getAttribute('name'));
            if ($name === '') {
                continue;
            }
            $params[$name] = trim($node->textContent);
        }
        return $params;
    }

    /**
     * Return the trimmed text of the first element in the document with the given local name,
     * regardless of namespace prefix.
     *
     * @param DOMDocument $dom The parsed cartridge.
     * @param string $localname Element local name.
     * @return string
     */
    private static function first_child_text(DOMDocument $dom, string $localname): string {
        foreach ($dom->getElementsByTagNameNS('*', $localname) as $node) {
            if ($node instanceof DOMElement) {
                return trim($node->textContent);
            }
        }
        return '';
    }
}
