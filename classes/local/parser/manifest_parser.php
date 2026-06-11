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
use tool_canvasuplifter\local\model\course_model;
use tool_canvasuplifter\local\model\section_model;
use tool_canvasuplifter\local\model\item;

/**
 * Reads an extracted Canvas Common Cartridge package into a {@see course_model}.
 *
 * Deliberately free of Moodle dependencies so it can be unit-tested on its own.
 * It does NOT create anything in Moodle - that is the job of later phases.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manifest_parser {
    /** @var string Absolute path to the extracted package directory. */
    protected string $basedir;

    /**
     * Constructor.
     *
     * @param string $basedir Absolute path to the extracted .imscc directory.
     */
    public function __construct(string $basedir) {
        $this->basedir = rtrim($basedir, '/');
    }

    /**
     * Parse the package and return the course model.
     *
     * @return course_model
     * @throws \RuntimeException If the manifest is missing or unreadable.
     */
    public function parse(): course_model {
        $manifestpath = $this->basedir . '/imsmanifest.xml';
        if (!is_readable($manifestpath)) {
            throw new \RuntimeException('imsmanifest.xml not found in package.');
        }

        $dom = new DOMDocument();
        // Suppress libxml warnings; we validate the structure ourselves below.
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->load($manifestpath);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new \RuntimeException('imsmanifest.xml could not be parsed as XML.');
        }

        $course = new course_model();
        $course->fullname = $this->read_course_title();

        // Build a lookup of every resource by identifier.
        $resources = $this->read_resources($dom);

        // Walk the organisation tree to build sections and place items.
        $this->build_sections($dom, $resources, $course);

        // Any resource never referenced by the organisation tree becomes an orphan.
        $placed = [];
        foreach ($course->sections as $section) {
            foreach ($section->items as $placeditem) {
                $placed[$placeditem->identifier] = true;
            }
        }
        foreach ($resources as $identifier => $resourceitem) {
            if (empty($placed[$identifier]) && $resourceitem->kind !== item::KIND_UNKNOWN) {
                $course->orphans[] = $resourceitem;
            }
        }

        return $course;
    }

    /**
     * Read all <resource> elements into classified items keyed by identifier.
     *
     * @param DOMDocument $dom The parsed manifest.
     * @return item[] Keyed by resource identifier.
     */
    protected function read_resources(DOMDocument $dom): array {
        $items = [];
        $resources = $dom->getElementsByTagNameNS('*', 'resource');
        foreach ($resources as $resource) {
            if (!($resource instanceof DOMElement)) {
                continue;
            }
            $identifier = $resource->getAttribute('identifier');
            if ($identifier === '') {
                continue;
            }
            $type = $resource->getAttribute('type');
            $href = $resource->getAttribute('href');

            $modelitem = new item($identifier);
            $modelitem->resourcetype = $type;
            $modelitem->href = $href;

            // Collect every <file href="..."> child.
            $files = $resource->getElementsByTagNameNS('*', 'file');
            foreach ($files as $file) {
                if ($file instanceof DOMElement && $file->getAttribute('href') !== '') {
                    $modelitem->files[] = $file->getAttribute('href');
                }
            }

            $modelitem->kind = $this->classify($type, $href, $modelitem->files);
            $items[$identifier] = $modelitem;
        }
        return $items;
    }

    /**
     * Decide what Moodle activity a Common Cartridge resource maps to.
     *
     * @param string $type The CC resource type string.
     * @param string $href The primary href.
     * @param string[] $files All file hrefs for the resource.
     * @return string One of the item::KIND_* constants.
     */
    protected function classify(string $type, string $href, array $files): string {
        // Web link -> URL.
        if (preg_match('#imswl_xmlv1p\d#', $type)) {
            return item::KIND_URL;
        }
        // Discussion topic -> forum.
        if (preg_match('#imsdt_xmlv1p\d#', $type)) {
            return item::KIND_DISCUSSION;
        }
        // Basic LTI external tool.
        if (preg_match('#imsbasiclti_xmlv1p\d#', $type)) {
            return item::KIND_LTI;
        }
        // IMS Common Cartridge 1.3 assignment profile (used by non-Canvas exporters).
        if (preg_match('#assignment_xmlv1p\d#', $type)) {
            return item::KIND_ASSIGNMENT;
        }
        // QTI assessment or question bank.
        if (str_contains($type, 'question-bank')) {
            return item::KIND_QUESTIONBANK;
        }
        if (preg_match('#imsqti#', $type) || str_contains($type, 'assessment')) {
            return item::KIND_QUIZ;
        }
        // Canvas "learning application" resources: assignments and pages live here.
        if (str_contains($type, 'learning-application-resource')) {
            foreach ($files as $file) {
                if (str_contains($file, 'assignment_settings.xml')) {
                    return item::KIND_ASSIGNMENT;
                }
            }
            return item::KIND_PAGE;
        }
        // Plain web content: an HTML page under wiki_content is a page, else a file.
        if ($type === 'webcontent' || str_contains($type, 'webcontent')) {
            if (str_contains($href, 'wiki_content/')) {
                return item::KIND_PAGE;
            }
            return item::KIND_FILE;
        }
        return item::KIND_UNKNOWN;
    }

    /**
     * Walk the first organisation tree and build sections with their items.
     *
     * @param DOMDocument $dom The parsed manifest.
     * @param item[] $resources Resources keyed by identifier.
     * @param course_model $course The course model to populate.
     * @return void
     */
    protected function build_sections(DOMDocument $dom, array $resources, course_model $course): void {
        $organizations = $dom->getElementsByTagNameNS('*', 'organization');
        if ($organizations->length === 0) {
            return;
        }
        $organization = $organizations->item(0);

        // The organisation usually has a single root <item> wrapping the modules.
        $rootitems = $this->child_items($organization);
        if (count($rootitems) === 1 && count($this->child_items($rootitems[0])) > 0) {
            $rootitems = $this->child_items($rootitems[0]);
        }

        foreach ($rootitems as $sectionnode) {
            $section = new section_model($this->item_title($sectionnode));
            $children = $this->child_items($sectionnode);

            if (count($children) === 0) {
                // A leaf at the top level: treat the section node itself as an activity.
                $this->attach_resource($sectionnode, $resources, $section);
            } else {
                foreach ($children as $childnode) {
                    $this->attach_resource($childnode, $resources, $section);
                }
            }
            $course->add_section($section);
        }
    }

    /**
     * Attach the resource referenced by an <item> to a section, with its title.
     *
     * @param DOMElement $node The <item> element.
     * @param item[] $resources Resources keyed by identifier.
     * @param section_model $section The section to add to.
     * @return void
     */
    protected function attach_resource(DOMElement $node, array $resources, section_model $section): void {
        $ref = $node->getAttribute('identifierref');
        if ($ref === '' || !isset($resources[$ref])) {
            return;
        }
        $resourceitem = $resources[$ref];
        $title = $this->item_title($node);
        if ($title !== '') {
            $resourceitem->title = $title;
        }
        $section->add_item($resourceitem);
    }

    /**
     * Return the direct child <item> elements of a node.
     *
     * @param \DOMNode $node The parent node.
     * @return DOMElement[]
     */
    protected function child_items(\DOMNode $node): array {
        $result = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'item') {
                $result[] = $child;
            }
        }
        return $result;
    }

    /**
     * Read the <title> text of an <item> element.
     *
     * @param DOMElement $node The <item> element.
     * @return string
     */
    protected function item_title(DOMElement $node): string {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'title') {
                return trim($child->textContent);
            }
        }
        return '';
    }

    /**
     * Try to read the course title from the Canvas course_settings file.
     *
     * @return string Empty string if not found.
     */
    protected function read_course_title(): string {
        $path = $this->basedir . '/course_settings/course_settings.xml';
        if (!is_readable($path)) {
            return '';
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($path);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            return '';
        }
        // The Canvas course_settings.xml exposes <title> for the course name.
        if (isset($xml->title)) {
            return trim((string) $xml->title);
        }
        return '';
    }
}
