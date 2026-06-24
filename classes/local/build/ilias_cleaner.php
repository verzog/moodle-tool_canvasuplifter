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

use DOMDocument;
use DOMNode;
use DOMXPath;

/**
 * Strips ILIAS learning-module presentation chrome out of an exported page.
 *
 * ILIAS exports each page as a full HTML document wrapped in its own viewer
 * layout: an "Activities" navigation column listing sibling learning modules,
 * a focus script, a posting form, hidden "this LM is not available" dialog
 * divs, and ILIAS icon images under CC_ICONS/. Imported verbatim, all of that
 * renders inside the Moodle page/book/lesson alongside the real content. This
 * keeps only the content cell (or, on a single learning-module page, the page
 * body) and drops the chrome. Non-ILIAS HTML is returned untouched.
 *
 * Moodle-free (DOM only) so it can be unit-tested from HTML strings.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ilias_cleaner {
    /**
     * Remove ILIAS viewer chrome from a page's HTML, returning just the content.
     *
     * @param string $html The raw exported page HTML.
     * @return string The cleaned content, or the original HTML when it is not an ILIAS page.
     */
    public static function clean(string $html): string {
        // Only ILIAS exporter pages carry these layout wrappers; everything else
        // (Canvas, eXe, plain webcontent) is left exactly as it was.
        if (
            stripos($html, 'ilc_page_cont_PageContainer') === false
            && stripos($html, 'ilc_table_MainTable') === false
        ) {
            return $html;
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // The leading XML declaration pins UTF-8 so accented content survives.
        $loaded = $dom->loadHTML(
            '<?xml encoding="utf-8"?>' . $html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return $html;
        }

        $xpath = new DOMXPath($dom);

        // Drop chrome that can sit anywhere: focus/posting scripts, hidden "not
        // available" dialog divs and the navigation icon images under CC_ICONS/.
        // The "Activities" navigation table is left in place for now so the
        // content column can be told apart from it by structure below.
        $junk = [
            '//script',
            '//*[starts-with(@id, "dialog_")]',
            '//img[starts-with(@src, "CC_ICONS/")]',
        ];
        foreach ($junk as $query) {
            foreach (iterator_to_array($xpath->query($query)) as $node) {
                if ($node->parentNode !== null) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        // On a folder/landing page the content is the layout row's right-hand
        // cell — the one that does NOT hold the "Activities" navigation table.
        // Keep the whole cell (its inner content table carries the title, media
        // and objectives across several cells). A single learning-module page
        // has no such layout table, so fall back to the page body.
        $out = '';
        $maintables = $xpath->query('//table[' . self::has_class('ilc_table_MainTable') . ']');
        foreach ($maintables as $maintable) {
            $out .= self::content_cell_html($dom, $xpath, $maintable);
        }
        if ($out === '') {
            $body = $dom->getElementsByTagName('body')->item(0);
            if ($body !== null) {
                foreach ($body->childNodes as $child) {
                    $out .= self::node_html($dom, $child);
                }
            }
        }

        $out = trim($out);
        return $out === '' ? $html : $out;
    }

    /**
     * Serialise the content cell of a single ILIAS layout table: the direct
     * cell of its first row that does not contain the navigation table.
     *
     * @param DOMDocument $dom The owner document.
     * @param DOMXPath $xpath The document's xpath.
     * @param DOMNode $maintable The ilc_table_MainTable element.
     * @return string The content cell's inner HTML, or '' if none is found.
     */
    private static function content_cell_html(DOMDocument $dom, DOMXPath $xpath, DOMNode $maintable): string {
        // The direct data cells of the layout row (loadHTML wraps rows in tbody).
        $cells = $xpath->query('child::tbody/child::tr[1]/child::td | child::tr[1]/child::td', $maintable);
        foreach ($cells as $cell) {
            if ($xpath->query('descendant::table[' . self::has_class('ilc_table_Navigation') . ']', $cell)->length > 0) {
                continue;
            }
            $html = '';
            foreach ($cell->childNodes as $child) {
                $html .= self::node_html($dom, $child);
            }
            return $html;
        }
        return '';
    }

    /**
     * Build an XPath predicate matching a single CSS class token (so
     * "ilc_table_Navigation" doesn't also match a longer class containing it).
     *
     * @param string $class The class name to match.
     * @return string XPath predicate body.
     */
    private static function has_class(string $class): string {
        return 'contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")';
    }

    /**
     * Serialise a single DOM node back to HTML.
     *
     * @param DOMDocument $dom The owner document.
     * @param DOMNode $node The node to serialise.
     * @return string
     */
    private static function node_html(DOMDocument $dom, DOMNode $node): string {
        return (string) $dom->saveHTML($node);
    }
}
