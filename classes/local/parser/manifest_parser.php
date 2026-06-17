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
            // Message is a lang string key resolved by the calling page.
            throw new \RuntimeException('errornomanifest');
        }

        $dom = new DOMDocument();
        // Suppress libxml warnings; we validate the structure ourselves below.
        // LIBXML_NONET blocks any network access while parsing untrusted XML.
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->load($manifestpath, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new \RuntimeException('errorbadmanifestxml');
        }

        $course = new course_model();
        $course->fullname = $this->read_course_title();
        $course->weightingscheme = $this->read_weighting_scheme();
        $course->gradecategories = $this->read_grade_categories();
        $course->rubrics = $this->read_rubrics();

        // Build a lookup of every resource by identifier.
        $resources = $this->read_resources($dom);

        // Canvas exports announcements as imsdt discussion topics but marks them
        // in the topicMeta companion XML with <type>announcement</type>. Flag
        // those so the builder can route them to the course's news forum.
        $this->mark_announcements($resources);

        // Read per-assignment grade-group references so course_builder can move
        // each built mod_assign into its matching grade category.
        $this->mark_assignment_groups($resources);

        // Fold eXe/IGEN-style lesson bundles into a single page each so the
        // hundreds of framework asset files those packages ship per lesson
        // don't all surface as standalone mod_resource activities.
        $this->fold_lesson_bundles($resources);

        // Derive titles up front so module_meta clones inherit the recovered name
        // when their module item leaves the title blank, instead of falling back
        // to file slugs. The manifest-organisation path also benefits because
        // attach_resource() only overwrites when the <item> title is non-empty.
        foreach ($resources as $resourceitem) {
            if ($resourceitem->title === '') {
                $resourceitem->title = $this->derive_title($resourceitem);
            }
        }

        // Canvas exports a richer per-module structure in course_settings/module_meta.xml,
        // including published state, in-module subheaders and inline ExternalUrl items;
        // prefer it when present, otherwise fall back to the manifest's <organization>.
        if (!$this->build_sections_from_module_meta($resources, $course)) {
            $this->build_sections($dom, $resources, $course);
        }

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
     * Derive a title for an untitled resource from its HTML <title> element.
     *
     * @param item $modelitem The resource.
     * @return string The decoded title, or '' if none could be read.
     */
    protected function derive_title(item $modelitem): string {
        $candidates = $modelitem->files;
        if ($modelitem->href !== '') {
            $candidates[] = $modelitem->href;
        }
        // Discussion (imsdt), LTI (imsbasiclti), web-link (imswl) and QTI
        // assessment resources are XML but name themselves inside the file,
        // so read those too.
        $allowxml = in_array(
            $modelitem->kind,
            [item::KIND_DISCUSSION, item::KIND_LTI, item::KIND_URL, item::KIND_QUIZ, item::KIND_QUESTIONBANK],
            true
        );
        $isqti = in_array($modelitem->kind, [item::KIND_QUIZ, item::KIND_QUESTIONBANK], true);
        foreach ($candidates as $relative) {
            $ishtml = (bool) preg_match('/\.html?$/i', $relative);
            $isxml = $allowxml && (bool) preg_match('/\.xml$/i', $relative);
            if (!$ishtml && !$isxml) {
                continue;
            }
            $absolute = $this->resolve_within($relative);
            if ($absolute === null) {
                continue;
            }
            $html = (string) @file_get_contents($absolute);
            // QTI assessments name themselves in an <assessment title="..."> attribute.
            if ($isqti && preg_match('/<assessment\b[^>]*\btitle="([^"]*)"/i', $html, $qm)) {
                $title = trim(html_entity_decode($qm[1], ENT_QUOTES | ENT_HTML5));
                if ($title !== '') {
                    return $title;
                }
            }
            // Allow an optional namespace prefix: LTI cartridges name the tool in
            // <blti:title>, while discussions use a plain <title>.
            if (preg_match('#<(?:[\w.-]+:)?title[^>]*>(.*?)</(?:[\w.-]+:)?title>#is', $html, $matches)) {
                $title = trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5));
                // Strip a leading separator (e.g. "- Audio Visual") that some
                // exporters leave behind when they drop the part before a
                // dash. /u so multibyte en/em dashes are matched as
                // characters, not as raw bytes that would corrupt UTF-8 in
                // titles that legitimately start with other punctuation.
                $stripped = preg_replace('/^[\s\-\x{2013}\x{2014}|:]+/u', '', $title);
                if (is_string($stripped)) {
                    $title = trim($stripped);
                }
                if ($title !== '') {
                    return $title;
                }
            }
        }
        return '';
    }

    /**
     * Resolve a package-relative path to a readable file inside the base dir.
     *
     * @param string $relative Package-relative path.
     * @return string|null Absolute path, or null if missing or outside the package.
     */
    protected function resolve_within(string $relative): ?string {
        $absolute = realpath($this->basedir . '/' . ltrim($relative, '/'));
        $root = realpath($this->basedir);
        if ($absolute === false || $root === false) {
            return null;
        }
        if ($absolute !== $root && strpos($absolute, $root . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }
        return is_readable($absolute) ? $absolute : null;
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
            $modelitem->intendeduse = strtolower($resource->getAttribute('intendeduse'));

            // Collect every <file href="..."> child.
            $files = $resource->getElementsByTagNameNS('*', 'file');
            foreach ($files as $file) {
                if ($file instanceof DOMElement && $file->getAttribute('href') !== '') {
                    $modelitem->files[] = $file->getAttribute('href');
                }
            }

            $modelitem->kind = $this->classify($type, $href, $modelitem->files);
            // Mark the deliberate-skip cases (quiz/ assets, metadata-only
            // learning-application resources) so section attach can keep
            // suppressing them when the organisation explicitly references
            // them, without also suppressing genuinely unsupported types.
            $modelitem->suppressed = $this->deliberately_suppressed(
                $modelitem->kind,
                $type,
                $href,
                $modelitem->files
            );
            $items[$identifier] = $modelitem;
        }
        return $items;
    }

    /**
     * Whether classify() returned KIND_UNKNOWN intentionally — i.e. the
     * resource is a known-but-skipped kind (a quiz/ asset that lives inside
     * a QTI question, or a learning-application companion XML carrying no
     * HTML payload) — as opposed to an unsupported resource type that the
     * report should still flag.
     *
     * @param string $kind The classifier's verdict.
     * @param string $type Raw CC resource type string.
     * @param string $href Primary href.
     * @param array $files All file hrefs (strings).
     * @return bool
     */
    protected function deliberately_suppressed(string $kind, string $type, string $href, array $files): bool {
        if ($kind !== item::KIND_UNKNOWN) {
            return false;
        }
        if (
            ($type === 'webcontent' || str_contains($type, 'webcontent'))
            && $this->is_quiz_asset($href, $files)
        ) {
            return true;
        }
        if (
            str_contains($type, 'learning-application-resource')
            && !$this->has_html($href, $files)
        ) {
            // An assignment_settings.xml would have been classified as
            // KIND_ASSIGNMENT, so by here we know it's a metadata-only
            // companion (e.g. topicMeta, assessment_meta, canvas_export.txt).
            return true;
        }
        return false;
    }

    /**
     * Scan the package's topicMeta XML files and flag discussions whose Canvas
     * topicMeta marks them as announcements (<type>announcement</type>).
     *
     * Canvas exports announcements with the same imsdt resource shape as ordinary
     * discussions; the announcement signal lives in the topicMeta companion XML,
     * which carries a <topic_id> back-reference. We build a topic_id -> bool map
     * from those files and then mark matching KIND_DISCUSSION items in place.
     *
     * @param item[] $resources Resources keyed by identifier (modified in place).
     * @return void
     */
    protected function mark_announcements(array &$resources): void {
        // Map of topic_id => bool isunpublished. A discussion only gets the
        // announcement flag when its topicMeta says so; the unpublished state
        // lives in the same XML so it survives when module_meta.xml doesn't
        // list the announcement.
        $announcementinfo = [];
        foreach ($resources as $resourceitem) {
            $candidates = $resourceitem->files;
            if ($resourceitem->href !== '') {
                $candidates[] = $resourceitem->href;
            }
            foreach ($candidates as $relative) {
                if (!preg_match('/\.xml$/i', $relative)) {
                    continue;
                }
                $absolute = $this->resolve_within($relative);
                if ($absolute === null) {
                    continue;
                }
                $info = $this->read_announcement_topicmeta((string) @file_get_contents($absolute));
                if ($info !== null) {
                    $announcementinfo[$info['topic_id']] = $info['isunpublished'];
                }
            }
        }
        foreach ($resources as $resourceitem) {
            if (
                $resourceitem->kind === item::KIND_DISCUSSION
                && array_key_exists($resourceitem->identifier, $announcementinfo)
            ) {
                $resourceitem->isannouncement = true;
                if ($announcementinfo[$resourceitem->identifier]) {
                    // Without this, an announcement that module_meta.xml omits
                    // would default to isvisible=true; the topicMeta is the
                    // only place its unpublished state lives.
                    $resourceitem->isvisible = false;
                }
            }
        }
    }

    /**
     * If the given XML is a Canvas topicMeta for an announcement, return its
     * <topic_id> and unpublished state; otherwise null. Kept narrowly
     * Moodle-free so it stays testable from raw XML strings.
     *
     * @param string $xml The candidate XML payload.
     * @return array|null ['topic_id'=>string, 'isunpublished'=>bool] or null.
     */
    protected function read_announcement_topicmeta(string $xml): ?array {
        if (trim($xml) === '' || stripos($xml, 'topicMeta') === false) {
            return null;
        }
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (
            !$loaded || $dom->documentElement === null
            || $dom->documentElement->localName !== 'topicMeta'
        ) {
            return null;
        }
        $type = $this->first_child_named($dom->documentElement, 'type');
        if ($type === null || strtolower(trim($type->textContent)) !== 'announcement') {
            return null;
        }
        $topicid = $this->first_child_named($dom->documentElement, 'topic_id');
        if ($topicid === null) {
            return null;
        }
        $id = trim($topicid->textContent);
        if ($id === '') {
            return null;
        }
        $state = $this->first_child_named($dom->documentElement, 'workflow_state');
        $isunpublished = $state !== null
            && strtolower(trim($state->textContent)) === 'unpublished';
        return ['topic_id' => $id, 'isunpublished' => $isunpublished];
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
        // Canvas "learning application" resources: assignments and pages live here,
        // but so do metadata-only companions (discussion topicMeta, quiz
        // assessment_meta, canvas_export.txt). Only treat one as a page when it
        // actually carries an HTML payload; otherwise it's the dedicated
        // discussion/quiz/etc. resource that owns the content, so skip it.
        if (str_contains($type, 'learning-application-resource')) {
            foreach ($files as $file) {
                if (str_contains($file, 'assignment_settings.xml')) {
                    return item::KIND_ASSIGNMENT;
                }
            }
            return $this->has_html($href, $files) ? item::KIND_PAGE : item::KIND_UNKNOWN;
        }
        // Plain web content: an HTML page under wiki_content is a page, else a file.
        if ($type === 'webcontent' || str_contains($type, 'webcontent')) {
            // Assets under quiz/ are images/resources embedded in QTI questions,
            // not standalone course files; skip them (the question bank owns them).
            if ($this->is_quiz_asset($href, $files)) {
                return item::KIND_UNKNOWN;
            }
            if (str_contains($href, 'wiki_content/')) {
                return item::KIND_PAGE;
            }
            return item::KIND_FILE;
        }
        return item::KIND_UNKNOWN;
    }

    /**
     * Whether every path of the resource lives under the package's quiz/ folder
     * (i.e. it's an asset belonging to a QTI question, not a course file).
     *
     * @param string $href The primary href.
     * @param string[] $files All file hrefs.
     * @return bool
     */
    protected function is_quiz_asset(string $href, array $files): bool {
        $paths = $files;
        if ($href !== '') {
            $paths[] = $href;
        }
        if (empty($paths)) {
            return false;
        }
        foreach ($paths as $path) {
            if (!preg_match('#^/?quiz/#i', $path)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether any of the resource's paths is an HTML file.
     *
     * @param string $href The primary href.
     * @param string[] $files All file hrefs.
     * @return bool
     */
    protected function has_html(string $href, array $files): bool {
        foreach (array_merge([$href], $files) as $path) {
            if ($path !== '' && preg_match('/\.html?$/i', $path)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build sections from Canvas's course_settings/module_meta.xml when present.
     *
     * Canvas's module_meta.xml is richer than the manifest's <organization> tree:
     * it carries per-item workflow_state (published vs unpublished), Context-
     * ModuleSubHeader rows (in-module labels) and inline ExternalUrl items that
     * have no imswl resource of their own. Each <module> becomes a section, each
     * <item> becomes a resource reference, a subheader, or a synthetic URL item.
     *
     * @param item[] $resources Resources keyed by identifier (modified in place).
     * @param course_model $course The course model to populate.
     * @return bool True if module_meta.xml was found and used; false to fall back.
     */
    protected function build_sections_from_module_meta(array &$resources, course_model $course): bool {
        $path = $this->basedir . '/course_settings/module_meta.xml';
        if (!is_readable($path)) {
            return false;
        }
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->load($path, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return false;
        }
        $modules = $dom->getElementsByTagNameNS('*', 'module');
        if ($modules->length === 0) {
            return false;
        }

        foreach ($modules as $module) {
            if (!($module instanceof DOMElement)) {
                continue;
            }
            $section = new section_model($this->child_text($module, 'title'));
            // Canvas can hide a whole module with the module-level workflow_state
            // even when its items individually carry workflow_state="active". AND
            // the two so the items inherit their parent module's hidden state.
            $moduleisvisible = strtolower($this->child_text($module, 'workflow_state')) !== 'unpublished';
            $itemsnode = $this->first_child_named($module, 'items');
            if ($itemsnode !== null) {
                foreach ($this->children_named($itemsnode, 'item') as $itemnode) {
                    $modelitem = $this->item_from_module_meta($itemnode, $resources, $moduleisvisible);
                    if ($modelitem !== null) {
                        $section->add_item($modelitem);
                    }
                }
            }
            $course->add_section($section);
        }
        return true;
    }

    /**
     * Build a model item from one <item> in module_meta.xml.
     *
     * Handles three shapes:
     *   - ContextModuleSubHeader: a label row with only a title (no resource).
     *   - identifierref pointing to a known manifest resource: reuse it, applying
     *     the per-module title and published state.
     *   - ExternalUrl with an inline <url>: synthesize a URL item when no
     *     matching imswl resource exists.
     *
     * @param DOMElement $node The <item> element from module_meta.xml.
     * @param item[] $resources Resources keyed by identifier (modified in place).
     * @param bool $moduleisvisible Visibility inherited from the parent <module>.
     * @return item|null Built item, or null if it cannot be represented.
     */
    protected function item_from_module_meta(DOMElement $node, array &$resources, bool $moduleisvisible = true): ?item {
        $contenttype = $this->child_text($node, 'content_type');
        $title = $this->child_text($node, 'title');
        $isvisible = $moduleisvisible
            && strtolower($this->child_text($node, 'workflow_state')) !== 'unpublished';

        if ($contenttype === 'ContextModuleSubHeader') {
            $modelitem = new item($node->getAttribute('identifier'), $title);
            $modelitem->kind = item::KIND_SUBHEADER;
            $modelitem->isvisible = $isvisible;
            return $modelitem;
        }

        $ref = $this->child_text($node, 'identifierref');
        if ($ref !== '' && isset($resources[$ref])) {
            // Mirror attach_resource()'s suppression rule: bundle assets and
            // deliberate-skip unknowns stay hidden; real KIND_UNKNOWN
            // resources still flow through so the report can flag them.
            if ($resources[$ref]->bundlemember || $resources[$ref]->suppressed) {
                return null;
            }
            // Clone before mutating: Canvas often reuses the same identifierref in
            // several modules, and per-module title/visibility must not bleed
            // across occurrences (later module would otherwise overwrite earlier).
            $modelitem = clone $resources[$ref];
            if ($title !== '') {
                $modelitem->title = $title;
            }
            $modelitem->isvisible = $isvisible;
            return $modelitem;
        }

        if ($contenttype === 'ExternalUrl') {
            $url = $this->child_text($node, 'url');
            if ($url === '') {
                return null;
            }
            // Some inline ExternalUrls reference their own identifier; key by it so
            // they show up as referenced rather than orphaned.
            $id = $node->getAttribute('identifier');
            $modelitem = new item($id, $title);
            $modelitem->kind = item::KIND_URL;
            $modelitem->url = $url;
            $modelitem->isvisible = $isvisible;
            if ($id !== '') {
                $resources[$id] = $modelitem;
            }
            return $modelitem;
        }

        return null;
    }

    /**
     * Return the trimmed text content of the first child with the given local name.
     *
     * @param DOMElement $parent The parent element.
     * @param string $name Local name to look for (namespace-agnostic).
     * @return string Trimmed text, or '' if no such child exists.
     */
    protected function child_text(DOMElement $parent, string $name): string {
        $child = $this->first_child_named($parent, $name);
        return $child === null ? '' : trim($child->textContent);
    }

    /**
     * Return the first direct child element with the given local name.
     *
     * @param DOMElement $parent The parent element.
     * @param string $name Local name to look for.
     * @return DOMElement|null
     */
    protected function first_child_named(DOMElement $parent, string $name): ?DOMElement {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $name) {
                return $child;
            }
        }
        return null;
    }

    /**
     * Return all direct child elements with the given local name.
     *
     * @param DOMElement $parent The parent element.
     * @param string $name Local name to look for.
     * @return DOMElement[]
     */
    protected function children_named(DOMElement $parent, string $name): array {
        $result = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $name) {
                $result[] = $child;
            }
        }
        return $result;
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
        // Skip resources we've deliberately set aside: bundle assets folded
        // by fold_lesson_bundles(), and the deliberate-skip KIND_UNKNOWN
        // cases (quiz/ assets, metadata-only learning-application
        // companions). Genuinely unsupported resource types stay visible so
        // the report can call them out as unmappable rows.
        if ($resourceitem->bundlemember || $resourceitem->suppressed) {
            return;
        }
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
        $xml = simplexml_load_file($path, 'SimpleXMLElement', LIBXML_NONET);
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

    /**
     * Read Canvas's <group_weighting_scheme> from course_settings.xml.
     *
     * Returns 'percent' when the gradebook uses weighted assignment groups, or
     * the empty string when groups are flat (or course_settings.xml is missing).
     *
     * @return string
     */
    protected function read_weighting_scheme(): string {
        $path = $this->basedir . '/course_settings/course_settings.xml';
        if (!is_readable($path)) {
            return '';
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($path, 'SimpleXMLElement', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false || !isset($xml->group_weighting_scheme)) {
            return '';
        }
        return strtolower(trim((string) $xml->group_weighting_scheme));
    }

    /**
     * Read Canvas's assignment_groups.xml into a list of grade-category specs.
     *
     * Each <assignmentGroup> becomes one entry with the Canvas identifier (used
     * as the per-assignment foreign key), a display title, a sort position and
     * the percentage weight. The list is sorted by position so the gradebook
     * mirrors Canvas's ordering.
     *
     * @return array<int, array{identifier: string, title: string, position: int, weight: float}>
     */
    protected function read_grade_categories(): array {
        $path = $this->basedir . '/course_settings/assignment_groups.xml';
        if (!is_readable($path)) {
            return [];
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($path, 'SimpleXMLElement', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            return [];
        }
        $groups = [];
        foreach ($xml->assignmentGroup as $node) {
            $id = trim((string) ($node['identifier'] ?? ''));
            $title = trim((string) ($node->title ?? ''));
            if ($id === '' || $title === '') {
                continue;
            }
            $groups[] = [
                'identifier' => $id,
                'title' => $title,
                'position' => (int) ($node->position ?? 0),
                'weight' => (float) ($node->group_weight ?? 0),
            ];
        }
        usort($groups, fn($a, $b) => $a['position'] <=> $b['position']);
        return $groups;
    }

    /**
     * For every assignment resource, parse its assignment_settings.xml once and
     * stash the grade-group reference and any rubric reference on the model
     * item so the builder can route the activity into the right grade category
     * and attach the right gradingform_rubric definition.
     *
     * @param item[] $resources Resources keyed by identifier (modified in place).
     * @return void
     */
    /**
     * Read Canvas's course_settings/rubrics.xml into a map keyed by Canvas
     * rubric identifier. Each value carries the title, the free-form-comments
     * and hide-score-total flags, and an ordered list of criteria each with
     * their points-only levels. The shape stays Moodle-free so the rubric
     * library is still testable from XML strings.
     *
     * @return array Map of identifier => rubric hash (see course_model::$rubrics).
     */
    protected function read_rubrics(): array {
        $path = $this->basedir . '/course_settings/rubrics.xml';
        if (!is_readable($path)) {
            return [];
        }
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->load($path, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return [];
        }
        $rubrics = [];
        foreach ($dom->getElementsByTagNameNS('*', 'rubric') as $rubricnode) {
            if (
                !($rubricnode instanceof DOMElement) || $rubricnode->parentNode === null
                || $rubricnode->parentNode->localName !== 'rubrics'
            ) {
                // The outer <rubric> is the top-level entry; an inline <rubric>
                // nested elsewhere (e.g. inside an assignment extension) is read
                // through the assignment path, not here.
                continue;
            }
            $id = trim($rubricnode->getAttribute('identifier'));
            if ($id === '') {
                continue;
            }
            $rubrics[$id] = [
                'title' => $this->child_text($rubricnode, 'title'),
                'free_form_comments' => $this->bool_text($rubricnode, 'free_form_criterion_comments'),
                'hide_score_total' => $this->bool_text($rubricnode, 'hide_score_total'),
                'criteria' => $this->read_criteria($rubricnode),
            ];
        }
        return $rubrics;
    }

    /**
     * Read the <criteria>/<criterion> children of a <rubric> element.
     *
     * @param DOMElement $rubricnode The rubric element.
     * @return array Ordered list of ['id','description','points','levels'].
     */
    protected function read_criteria(DOMElement $rubricnode): array {
        $criteria = [];
        $criterianode = $this->first_child_named($rubricnode, 'criteria');
        if ($criterianode === null) {
            return $criteria;
        }
        foreach ($this->children_named($criterianode, 'criterion') as $criterionnode) {
            $criteria[] = [
                'id' => $this->child_text($criterionnode, 'criterion_id'),
                'description' => $this->child_text($criterionnode, 'description'),
                'points' => (float) $this->child_text($criterionnode, 'points'),
                'levels' => $this->read_ratings($criterionnode),
            ];
        }
        return $criteria;
    }

    /**
     * Read the <ratings>/<rating> children of a <criterion> element. Returns
     * them sorted ascending by points so gradingform_rubric renders them
     * lowest-to-highest left-to-right.
     *
     * @param DOMElement $criterionnode The criterion element.
     * @return array Ordered list of ['description','points'].
     */
    protected function read_ratings(DOMElement $criterionnode): array {
        $levels = [];
        $ratingsnode = $this->first_child_named($criterionnode, 'ratings');
        if ($ratingsnode === null) {
            return $levels;
        }
        foreach ($this->children_named($ratingsnode, 'rating') as $ratingnode) {
            $levels[] = [
                'description' => $this->child_text($ratingnode, 'description'),
                'points' => (float) $this->child_text($ratingnode, 'points'),
            ];
        }
        usort($levels, fn($a, $b) => $a['points'] <=> $b['points']);
        return $levels;
    }

    /**
     * Read the trimmed text of a child element and interpret it as a boolean.
     *
     * @param DOMElement $parent The parent element.
     * @param string $name Local name of the child.
     * @return bool
     */
    protected function bool_text(DOMElement $parent, string $name): bool {
        return filter_var($this->child_text($parent, $name), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * For every assignment resource, parse its assignment_settings.xml once and
     * stash the grade-group reference and any rubric reference on the model
     * item so the builder can route the activity into the right grade category
     * and attach the right gradingform_rubric definition.
     *
     * @param array $resources Resources keyed by identifier (item objects, modified in place).
     * @return void
     */
    protected function mark_assignment_groups(array &$resources): void {
        foreach ($resources as $resourceitem) {
            if ($resourceitem->kind !== item::KIND_ASSIGNMENT) {
                continue;
            }
            foreach ($resourceitem->files as $relative) {
                if (!str_ends_with($relative, 'assignment_settings.xml')) {
                    continue;
                }
                $absolute = $this->resolve_within($relative);
                if ($absolute === null) {
                    continue;
                }
                $settings = assignment_settings::parse((string) @file_get_contents($absolute));
                if ($settings->gradegroupref !== '') {
                    $resourceitem->gradegroupref = $settings->gradegroupref;
                }
                if ($settings->rubricref !== '') {
                    $resourceitem->rubricref = $settings->rubricref;
                    $resourceitem->rubricforgrading = $settings->rubricforgrading;
                }
                break;
            }
        }
    }

    /**
     * Marker filenames that, when present together in a folder, indicate the
     * folder is an eXe / IGEN / DELOS / ILIAS lesson bundle.
     *
     * The anchor file ("index.html") must sit at the folder root, but the
     * theme markers can live anywhere under it — ILIAS exports nest them
     * many levels deep (e.g. style/igencp.css,
     * Customizing/global/skin/igencp/igencp.css,
     * templates/default/delos_cont.css). Either theme marker is enough; both
     * are distinctive ILIAS-specific filenames Canvas exports never carry,
     * so accidental matches stay near-impossible.
     */
    private const LESSON_BUNDLE_ROOT_MARKER = 'index.html';
    /** @var string[] Theme markers; presence of any one anywhere in the subtree confirms a bundle. */
    private const LESSON_BUNDLE_THEME_MARKERS = ['igencp.css', 'delos_cont.css'];

    /**
     * Detect lesson bundles and collapse each into a single mod_page anchored
     * at the folder's index.html. Sibling resources inside the same folder
     * tree are demoted to KIND_UNKNOWN and their files attached to the
     * promoted page as bundle assets so the page's relative
     * <link>/<script>/<img> URLs still resolve once mod_page imports them
     * under pluginfile.
     *
     * Triggered purely from manifest hrefs; the package directory itself is
     * not scanned, keeping the parser fast and Moodle-free. Canvas exports
     * never carry these markers, so existing Canvas imports are unaffected.
     *
     * @param array $resources Resources keyed by identifier (item objects, modified in place).
     * @return void
     */
    protected function fold_lesson_bundles(array &$resources): void {
        // Pass 1a: collect basenames per folder (lower -> real case) so the
        // anchor lookup later can recover an exact filename for exporters
        // that ship "Index.html" / "INDEX.HTML".
        $basenamesbyfolder = [];
        foreach ($resources as $resourceitem) {
            foreach ($this->resource_paths($resourceitem) as $path) {
                $folder = $this->normalise_folder(dirname($path));
                $basenamesbyfolder[$folder][strtolower(basename($path))] = basename($path);
            }
        }
        // Pass 1b: identify every folder that has an index.html at its root.
        $anchorfolders = [];
        foreach ($basenamesbyfolder as $folder => $basenames) {
            if (isset($basenames[self::LESSON_BUNDLE_ROOT_MARKER])) {
                $anchorfolders[$folder] = true;
            }
        }
        // Pass 2: attribute each theme marker to the NEAREST ancestor that
        // owns its own index.html — not every ancestor. Propagating to every
        // ancestor would let a child marker promote a parent landing page,
        // and the shortest-first fold would then swallow the actual lesson
        // bundles below.
        $themesseenbyfolder = [];
        foreach ($resources as $resourceitem) {
            foreach ($this->resource_paths($resourceitem) as $path) {
                $basenamelower = strtolower(basename($path));
                if (!in_array($basenamelower, self::LESSON_BUNDLE_THEME_MARKERS, true)) {
                    continue;
                }
                $owner = $this->nearest_anchor_folder(
                    $this->normalise_folder(dirname($path)),
                    $anchorfolders
                );
                if ($owner !== null) {
                    $themesseenbyfolder[$owner] = true;
                }
            }
        }
        // Pass 3: a folder is a bundle if it has the anchor AND a theme
        // marker that resolved to it. Sort outermost-first so a genuine
        // root-level bundle claims a nested one rather than having the
        // nested fold promote it back to KIND_PAGE.
        $bundlefolders = [];
        foreach ($anchorfolders as $folder => $_) {
            if (!empty($themesseenbyfolder[$folder])) {
                $bundlefolders[] = $folder;
            }
        }
        usort($bundlefolders, fn($a, $b) => strlen($a) - strlen($b));
        $claimedprefixes = [];
        foreach ($bundlefolders as $folder) {
            if ($this->folder_inside_claimed($folder, $claimedprefixes)) {
                continue;
            }
            // Only claim the folder tree once we've actually promoted an
            // anchor: if no resource's primary path is the index.html (the
            // markers happen to be secondary <file> entries of unrelated
            // resources), a nested bundle below should still be allowed to
            // fold on its own.
            $folded = $this->fold_one_bundle(
                $resources,
                $folder,
                $basenamesbyfolder[$folder][self::LESSON_BUNDLE_ROOT_MARKER]
            );
            if ($folded) {
                $claimedprefixes[] = $folder === '' ? '' : ($folder . '/');
            }
        }
    }

    /**
     * Whether the given folder sits inside a folder we've already folded as a
     * bundle. A claimed prefix of '' (root bundle) claims everything.
     *
     * @param string $folder Candidate folder, '' for root.
     * @param array $claimedprefixes Folder prefixes already folded.
     * @return bool
     */
    private function folder_inside_claimed(string $folder, array $claimedprefixes): bool {
        foreach ($claimedprefixes as $prefix) {
            if ($prefix === '') {
                return true;
            }
            if ($folder !== '' && strpos($folder . '/', $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Fold a single detected bundle: pick the anchor resource (index.html in
     * the bundle's folder), promote it to KIND_PAGE, and demote every
     * sibling resource living in the same folder tree, attaching their
     * files to the anchor as bundle assets.
     *
     * @param array $resources Resources keyed by identifier (item objects, modified in place).
     * @param string $folder Bundle folder path, '' for a root-level bundle.
     * @param string $indexbasename The real-cased basename of the index file (e.g. "Index.html").
     * @return void
     */
    private function fold_one_bundle(array &$resources, string $folder, string $indexbasename): bool {
        $folderprefix = $folder === '' ? '' : ($folder . '/');
        $anchor = $folderprefix . $indexbasename;
        // First find the anchor without mutating anything: if no resource's
        // primary path is the index file (e.g. the markers only exist as
        // secondary <file> entries on unrelated resources), nothing gets
        // demoted and a nested bundle below stays available to fold itself.
        $anchoritem = null;
        $todemote = [];
        $assets = [];
        foreach ($resources as $resourceitem) {
            $primary = $this->primary_path($resourceitem);
            if ($primary === '' || !$this->path_inside_bundle($primary, $folderprefix)) {
                continue;
            }
            if (strcasecmp($primary, $anchor) === 0) {
                $anchoritem = $resourceitem;
                // Some packages express the whole bundle as a single resource
                // with the anchor as href and assets as additional <file>
                // children. Pull those siblings off the anchor too.
                foreach ($this->resource_paths($resourceitem) as $assetpath) {
                    if (strcasecmp($assetpath, $anchor) === 0) {
                        continue;
                    }
                    $this->record_bundle_asset($assets, $assetpath, $folderprefix);
                }
                continue;
            }
            $todemote[] = $resourceitem;
            foreach ($this->resource_paths($resourceitem) as $assetpath) {
                $this->record_bundle_asset($assets, $assetpath, $folderprefix);
            }
        }
        if ($anchoritem === null) {
            return false;
        }
        // Anchor confirmed — apply demotions and promote the anchor.
        foreach ($todemote as $sibling) {
            $sibling->kind = item::KIND_UNKNOWN;
            $sibling->bundlemember = true;
        }
        $anchoritem->kind = item::KIND_PAGE;
        // Ensure page_builder reads the HTML payload, not whatever file the
        // manifest happened to list first: pin href to the anchor and bring
        // it to the front of the files array.
        $anchoritem->href = $anchor;
        if (!empty($anchoritem->files)) {
            $reordered = [$anchor];
            foreach ($anchoritem->files as $f) {
                if (strcasecmp($f, $anchor) !== 0) {
                    $reordered[] = $f;
                }
            }
            $anchoritem->files = $reordered;
        }
        $anchoritem->bundleassets = array_values($assets);
        return true;
    }

    /**
     * Append a package path to the asset accumulator, keyed by its
     * relative-to-anchor path so duplicates collapse and the page filearea
     * mirrors the layout the HTML references.
     *
     * @param array $assets Accumulator: relpath => ['source','relpath'] (modified in place).
     * @param string $assetpath Package-relative path.
     * @param string $folderprefix Bundle folder with trailing slash, '' at root.
     * @return void
     */
    private function record_bundle_asset(array &$assets, string $assetpath, string $folderprefix): void {
        $relpath = $folderprefix === ''
            ? ltrim($assetpath, '/')
            : substr($assetpath, strlen($folderprefix));
        if ($relpath === '' || $relpath === false) {
            return;
        }
        $assets[$relpath] = ['source' => $assetpath, 'relpath' => $relpath];
    }

    /**
     * Whether a resource's primary path lives inside a bundle folder tree.
     * Root bundles (folderprefix === '') claim every resource path, matching
     * the same folder-tree semantics nested bundles use.
     *
     * @param string $primary The resource's primary package path.
     * @param string $folderprefix Bundle folder with trailing slash, '' at root.
     * @return bool
     */
    private function path_inside_bundle(string $primary, string $folderprefix): bool {
        if ($folderprefix === '') {
            return true;
        }
        return strpos($primary, $folderprefix) === 0;
    }

    /**
     * Return the resource's primary package path (href, falling back to the
     * first file entry). Empty string when neither is set.
     *
     * @param item $resourceitem
     * @return string
     */
    private function primary_path(item $resourceitem): string {
        if ($resourceitem->href !== '') {
            return $resourceitem->href;
        }
        return (string) ($resourceitem->files[0] ?? '');
    }

    /**
     * Collect every package path a resource owns (href + file entries).
     *
     * @param item $resourceitem
     * @return array
     */
    private function resource_paths(item $resourceitem): array {
        $paths = $resourceitem->files;
        if ($resourceitem->href !== '') {
            $paths[] = $resourceitem->href;
        }
        return array_unique(array_filter($paths, fn($p) => $p !== ''));
    }

    /**
     * Normalise a dirname() result to a package-relative folder path with no
     * leading slash and no trailing slash. dirname() returns "." for top-level
     * files; map that to "".
     *
     * @param string $folder Folder path as returned by dirname().
     * @return string Normalised path.
     */
    private function normalise_folder(string $folder): string {
        if ($folder === '.' || $folder === '/' || $folder === '') {
            return '';
        }
        return ltrim($folder, '/');
    }

    /**
     * Return the given folder plus every ancestor folder, root ('') included.
     * Used by fold_lesson_bundles() to propagate "a marker exists below me"
     * up the tree so bundle detection can see markers nested in subfolders.
     *
     * @param string $folder Normalised folder path; '' for root.
     * @return string[] Folder itself, parent, …, root ('') — no duplicates.
     */
    private function ancestor_folders(string $folder): array {
        $ancestors = [$folder];
        if ($folder === '') {
            return $ancestors;
        }
        $current = $folder;
        while ($current !== '') {
            $parent = $this->normalise_folder(dirname($current));
            if ($parent === $current) {
                break;
            }
            $ancestors[] = $parent;
            $current = $parent;
        }
        return $ancestors;
    }

    /**
     * Return the nearest folder at or above $folder that owns an index.html,
     * or null if none of its ancestors do. Used to attribute a theme marker
     * to the innermost lesson bundle that contains it, so child markers can't
     * promote a parent landing folder above them.
     *
     * @param string $folder Folder containing the theme marker.
     * @param array $anchorfolders Map of folder => true for folders with index.html at root.
     * @return string|null Owning folder, or null.
     */
    private function nearest_anchor_folder(string $folder, array $anchorfolders): ?string {
        foreach ($this->ancestor_folders($folder) as $ancestor) {
            if (isset($anchorfolders[$ancestor])) {
                return $ancestor;
            }
        }
        return null;
    }
}
