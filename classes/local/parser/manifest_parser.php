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
use tool_canvasuplifter\local\build\ilias_cleaner;
use tool_canvasuplifter\local\build\link_rewriter;
use tool_canvasuplifter\local\build\page_payload;
use tool_canvasuplifter\local\build\safe_path;
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

    /** @var string Detected source system (a source_detector constant), '' before parse(). */
    protected string $source = '';

    /** @var int Count of Canvas platform-boilerplate resources dropped in read_resources(). */
    protected int $canvasboilerplatedropped = 0;

    /**
     * @var array Identifiers of resources consumed as an empty structural
     *            container at some organisation occurrence (so they title a
     *            section/folder rather than build). Kept off the orphan list
     *            without mutating the shared resource, so the SAME resource
     *            referenced elsewhere as a real leaf still attaches and reports.
     */
    private array $containerconsumed = [];

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
        // Recover from a locally-malformed manifest: Canvas ships packages whose
        // unsupported/placeholder resources carry a broken tag (e.g. <filehref=...>),
        // and libxml would otherwise reject the whole document — dropping the good
        // resources with the bad. The DOMDocument::$recover property enables the
        // same behaviour as the LIBXML_RECOVER flag but without depending on that
        // constant being defined in every PHP/libxml build.
        $dom->recover = true;
        // Suppress libxml warnings; we validate the structure ourselves below.
        // LIBXML_NONET blocks any network access while parsing untrusted XML.
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->load($manifestpath, LIBXML_NONET);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        // A manifest truncated partway through its structure recovers to a partial
        // tree, which would otherwise import as a silently incomplete course.
        // libxml reports that as XML_ERR_TAG_NOT_FINISHED (code 77, "Premature end
        // of data"); a localised malformed tag (Canvas's <filehref=...>) never
        // does, so treat truncation as fatal while still recovering the latter.
        $truncated = false;
        foreach ($errors as $error) {
            if ((int) $error->code === 77) {
                $truncated = true;
                break;
            }
        }
        // Also require a recognisable <manifest> root that carries some structure,
        // so a file truncated at the opening "<manifest" (a stub root with no
        // content) raises rather than appearing to import as an empty course.
        $root = $dom->documentElement;
        if (
            !$loaded || $truncated || $root === null || $root->localName !== 'manifest'
            || $root->getElementsByTagName('*')->length === 0
        ) {
            throw new \RuntimeException('errorbadmanifestxml');
        }

        // Windows-authored packages (notably native D2L exports) write backslash
        // path separators in hrefs. Normalise them across the whole manifest DOM
        // up front so every consumer — source detection, classification, the
        // exporter-specific cleanup helpers and the model — sees forward-slash
        // paths that resolve against the zip.
        $this->normalize_dom_separators($dom);

        $this->containerconsumed = [];
        // Recognise the source LMS up front: the report names it, and it gates
        // exporter-specific cleanup (e.g. dropping ANGEL/eXe _UNREFERENCED_ junk).
        $this->source = source_detector::detect($this->basedir, $dom);
        $course = new course_model();
        $course->source = $this->source;
        $course->fullname = $this->read_course_title($dom);
        $course->weightingscheme = $this->read_weighting_scheme();
        $course->gradecategories = $this->read_grade_categories();
        $course->gradeletters = $this->read_grade_letters();
        $course->rubrics = $this->read_rubrics();

        // Build a lookup of every resource by identifier.
        $resources = $this->read_resources($dom);
        $course->canvasboilerplatedropped = $this->canvasboilerplatedropped;

        // Canvas exports announcements as imsdt discussion topics but marks them
        // in the topicMeta companion XML with <type>announcement</type>. Flag
        // those so the builder can route them to the course's news forum.
        $this->mark_announcements($resources);

        // An unpublished assignment/quiz/discussion that no module places (an
        // orphan) carries its draft state only in its own companion metadata
        // (assignment_settings.xml, assessment_meta.xml or its topicMeta), which
        // the module-visibility pass never sees. Derive visibility from that
        // metadata so a draft orphan imports hidden rather than live. Run this
        // before mark_assignment_groups so an external-tool assignment's draft
        // state is read while it is still a KIND_ASSIGNMENT; the re-home to LTI
        // then preserves the hidden flag.
        $this->mark_unpublished_from_metadata($resources);

        // Read per-assignment grade-group references so course_builder can move
        // each built mod_assign into its matching grade category, and re-home
        // external-tool assignments to LTI placeholders.
        $this->mark_assignment_groups($resources);

        // Canvas records which uploaded files/folders are hidden in
        // course_settings/files_meta.xml (e.g. the "Uploaded Media" folder that
        // holds QTI-internal images). Honour that so a hidden file imports hidden
        // rather than surfacing as a visible standalone resource.
        $this->mark_hidden_from_files_meta($resources);

        // Fold eXe/IGEN-style lesson bundles into a single page each so the
        // hundreds of framework asset files those packages ship per lesson
        // don't all surface as standalone mod_resource activities.
        $this->fold_lesson_bundles($resources);

        // Fold a self-contained HTML file's referenced assets (js/css/images,
        // each often a separate webcontent resource) into the file itself, so an
        // interactive exercise builds as one inline resource that works, rather
        // than a broken HTML plus dozens of standalone asset activities.
        $this->fold_html_asset_bundles($resources);

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

        // Files that are only embedded inside another resource's page HTML (Canvas
        // page images/media referenced via $IMS-CC-FILEBASE$) are inlined into the
        // page at build time, so an unplaced one must not also import as a
        // standalone resource. Only unplaced files are suppressed, so an explicitly
        // placed activity is never dropped.
        $this->suppress_embedded_page_assets($resources, $placed);
        // A resource declared only as another (placed) resource's <dependency> is an
        // embedded asset — question-stem images, discussion media — not a standalone
        // download. Its media is embedded into the owning content at build time, so
        // keep it off the orphan list rather than surfacing it in "Additional resources".
        $this->suppress_dependency_assets($resources, $placed);
        // An asset folded into an HTML bundle is now hidden — but only when it
        // is an orphan. One the course also places as its own activity built
        // normally above and must survive, so suppress only the unplaced ones
        // (before the orphan pass, so they do not surface there either).
        foreach ($resources as $identifier => $resourceitem) {
            if (
                $resourceitem->htmlbundlemember
                && empty($placed[$identifier])
                && empty($this->containerconsumed[$identifier])
            ) {
                $resourceitem->kind = item::KIND_UNKNOWN;
                $resourceitem->suppressed = true;
            }
        }
        foreach ($resources as $identifier => $resourceitem) {
            if (
                empty($placed[$identifier])
                && empty($this->containerconsumed[$identifier])
                && $resourceitem->kind !== item::KIND_UNKNOWN
                && !$resourceitem->suppressed
            ) {
                $course->orphans[] = $resourceitem;
            }
        }

        // Canvas exports often ship a question bank alongside its twin quiz
        // assessment, both carrying the same human-readable title. Suffix the
        // question bank so graders can tell the two mod_qbank/mod_quiz
        // activities apart on the course page and in the report.
        $this->disambiguate_questionbank_titles($course);

        return $course;
    }

    /**
     * For any item that will end up as a mod_qbank activity and shares its
     * title with another item in the course, populate item::banktitle with
     * the original title plus a " (question bank)" suffix so the bank build
     * uses a distinct activity name. Covers both:
     *  - KIND_QUESTIONBANK items (always built as banks); and
     *  - orphan KIND_QUIZ items, which course_builder converts to banks via
     *    the question-bank builder (see build_one()'s orphan-quiz handling).
     *
     * Crucially we do NOT mutate $modelitem->title itself: when the
     * quiz_from_bank toggle is on, course_builder hands the SAME orphan
     * quiz model item to quiz_builder after the bank build to also create
     * a runnable mod_quiz, and that runnable copy keeps the unsuffixed name.
     *
     * @param course_model $course The populated course model.
     * @return void
     */
    private function disambiguate_questionbank_titles(course_model $course): void {
        $counts = [];
        $countedids = [];
        foreach ($course->all_items() as $modelitem) {
            $key = $modelitem->title;
            if ($key === '') {
                continue;
            }
            // Count each resource once even when the organisation tree places it
            // in more than one section, so a single bank isn't mistaken for two
            // distinct items that happen to share a title.
            $id = $modelitem->identifier;
            if ($id !== '') {
                if (isset($countedids[$id])) {
                    continue;
                }
                $countedids[$id] = true;
            }
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        $orphanids = [];
        foreach ($course->orphans as $orphan) {
            $orphanids[$orphan->identifier] = true;
        }
        foreach ($course->all_items() as $modelitem) {
            $buildsasbank = $modelitem->kind === item::KIND_QUESTIONBANK
                || ($modelitem->kind === item::KIND_QUIZ && isset($orphanids[$modelitem->identifier]));
            if (!$buildsasbank) {
                continue;
            }
            if (($counts[$modelitem->title] ?? 0) < 2) {
                continue;
            }
            $modelitem->banktitle = $modelitem->title . ' (question bank)';
        }
    }

    /**
     * Suppress unplaced file resources whose only role is being embedded inside
     * another resource's page HTML.
     *
     * Canvas stores page images and media as their own webcontent resources
     * (commonly under web_resources/) and references them from page bodies with a
     * $IMS-CC-FILEBASE$ token, which the page builder inlines into the page. Left
     * as orphans they would also import as standalone mod_resource activities,
     * duplicating the bytes and cluttering "Additional resources". Reuse the
     * builder's own embedder ({@see link_rewriter}) to compute exactly which
     * package files each page embeds, then suppress only an unplaced KIND_FILE
     * resource whose file is one of them — matched on the canonical absolute path,
     * so case and dot segments resolve the way the builder resolves them and a
     * genuinely standalone (or explicitly placed) file is never dropped.
     *
     * @param array $resources The resources keyed by identifier.
     * @param array $placed Identifiers already placed as their own activity.
     * @return void
     */
    protected function suppress_embedded_page_assets(array $resources, array $placed): void {
        $embedded = $this->collect_embedded_files($resources);
        if (empty($embedded)) {
            return;
        }
        foreach ($resources as $identifier => $resourceitem) {
            if ($resourceitem->kind !== item::KIND_FILE || !empty($placed[$identifier])) {
                continue;
            }
            // Compare the single file file_builder would build this resource from;
            // suppressing on an auxiliary <file> would drop an activity whose real
            // payload (e.g. a PDF href) is not the embedded asset at all.
            $built = $this->built_file_payload($resourceitem);
            if ($built !== null && isset($embedded[$built])) {
                $resourceitem->suppressed = true;
            }
        }
    }

    /**
     * Suppress resources that exist only as another placed resource's Common
     * Cartridge <dependency> — a quiz's image resource, a discussion's inline
     * media/attachment. These are embedded into their owning content at build
     * time (question stems, forum posts), so an unplaced dependency target must
     * not also surface as a standalone file in "Additional resources".
     *
     * Scoped to KIND_FILE targets of a parent whose builder embeds owner-relative
     * dependency media — quizzes and question banks (via the QTI writer) and
     * discussions (via the forum builder). Other rich-content builders (pages,
     * books, assignments) do not pass an owner directory to file_embedder, so a
     * dependency they reference from a subfolder is not embedded; hiding it would
     * drop it entirely, so those are left as downloadable orphans. The parent must
     * be one that actually builds (not itself suppressed) — this covers both a
     * placed activity and an unplaced-but-buildable one that course_builder builds
     * from the orphan pass and which embeds the same media. A dependency that is
     * itself placed as its own activity is left alone.
     *
     * @param array $resources The resources keyed by identifier.
     * @param array $placed Set of identifiers placed in the organisation tree.
     * @return void
     */
    protected function suppress_dependency_assets(array $resources, array $placed): void {
        $embeds = [item::KIND_QUIZ, item::KIND_QUESTIONBANK, item::KIND_DISCUSSION];
        foreach ($resources as $parentid => $parent) {
            if (
                empty($parent->dependencies)
                || $parent->suppressed
                || !in_array($parent->kind, $embeds, true)
            ) {
                continue;
            }
            foreach ($parent->dependencies as $dependencyref) {
                if (!isset($resources[$dependencyref]) || !empty($placed[$dependencyref])) {
                    continue;
                }
                $dependency = $resources[$dependencyref];
                if ($dependency->kind === item::KIND_FILE && !$dependency->suppressed) {
                    $dependency->kind = item::KIND_UNKNOWN;
                    $dependency->suppressed = true;
                }
            }
        }
    }

    /**
     * Absolute package paths of the files each page embeds via $IMS-CC-FILEBASE$
     * tokens, taken from the builder's own {@see link_rewriter} so the set matches
     * exactly what is inlined into the built page (URL-encoded tokens, tokens in
     * any attribute, the bare-path/web_resources resolution order, and safe dot
     * segments all included).
     *
     * Only KIND_PAGE resources are scanned: the page/book/lesson builders run
     * file_embedder, whereas file_builder (which builds a KIND_FILE HTML resource)
     * never embeds, so treating its tokens as embedded would drop a real file.
     *
     * @param array $resources The resources keyed by identifier.
     * @return array Set keyed by absolute package path, each value true.
     */
    protected function collect_embedded_files(array $resources): array {
        $rewriter = new link_rewriter();
        $embedded = [];
        foreach ($resources as $resourceitem) {
            // A page prefer_variant() suppressed (its <variant> selected an
            // assignment instead) is never rendered, so it embeds nothing — don't
            // let its tokens suppress a real standalone file.
            if ($resourceitem->kind !== item::KIND_PAGE || $resourceitem->suppressed) {
                continue;
            }
            $html = $this->rendered_page_html($resourceitem);
            if ($html === null) {
                continue;
            }
            // Resolve $IMS-CC-FILEBASE$ references the same way the page/book/lesson
            // builders now do — including the page's own folder as an owner-relative
            // fallback — so media a page embeds with a bare name or a ../ sibling
            // climb is recognised here and kept off the standalone-download orphan list.
            $ownerdir = page_payload::basedir($this->basedir, $resourceitem);
            foreach ($rewriter->rewrite_files($html, $this->basedir, $ownerdir)['files'] as $file) {
                $embedded[$file['package']] = true;
            }
        }
        return $embedded;
    }

    /**
     * The cleaned HTML a page builder actually renders and embeds from: the first
     * readable candidate in page_payload order (files before href), run through
     * {@see ilias_cleaner} exactly as the page/book/lesson builders do before
     * calling file_embedder, so tokens in stripped viewer chrome aren't counted.
     *
     * @param item $resourceitem The resource.
     * @return string|null The cleaned HTML, or null if no candidate is readable.
     */
    protected function rendered_page_html(item $resourceitem): ?string {
        $candidates = $resourceitem->files;
        if ($resourceitem->href !== '') {
            $candidates[] = $resourceitem->href;
        }
        foreach ($candidates as $relative) {
            $absolute = $this->resolve_within((string) $relative);
            if ($absolute === null) {
                continue;
            }
            $html = @file_get_contents($absolute);
            if ($html !== false) {
                return ilias_cleaner::clean((string) $html);
            }
        }
        return null;
    }

    /**
     * The single package file a file_builder would build this resource from —
     * href first, then <file> entries (mirroring file_builder::source_path) — so
     * suppression matches only the resource whose built copy an embedded asset
     * actually duplicates.
     *
     * @param item $resourceitem The resource.
     * @return string|null Absolute path within the package, or null.
     */
    protected function built_file_payload(item $resourceitem): ?string {
        $candidates = [];
        if ($resourceitem->href !== '') {
            $candidates[] = $resourceitem->href;
        }
        $candidates = array_merge($candidates, $resourceitem->files);
        foreach ($candidates as $relative) {
            $absolute = $this->resolve_within((string) $relative);
            if ($absolute !== null && is_file($absolute)) {
                return $absolute;
            }
        }
        return null;
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
        // First pass: prefer a real <title> (or QTI assessment title) from any
        // candidate. Only if every candidate's <title> is empty do we consider a
        // heading, so a page that carries a proper <title> always wins — even when
        // an auxiliary HTML file with an empty <title> is listed before it. Cache
        // each HTML payload so the heading pass doesn't re-read the files.
        $htmlbodies = [];
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
            if ($ishtml) {
                $htmlbodies[] = $html;
            }
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
        // Second pass: only after every <title> is exhausted, fall back to the
        // first heading (common in exported learning-module pages whose <title>
        // is empty). Collapse whitespace and non-breaking spaces.
        foreach ($htmlbodies as $html) {
            if (preg_match('#<h[1-3][^>]*>(.*?)</h[1-3]>#is', $html, $hm)) {
                $heading = html_entity_decode(strip_tags($hm[1]), ENT_QUOTES | ENT_HTML5);
                $heading = trim((string) preg_replace('/[\s\x{00a0}]+/u', ' ', $heading));
                if ($heading !== '') {
                    return $heading;
                }
            }
        }
        // A standalone item bank names itself in the objectbank's <bank_title> metadata
        // rather than an <assessment title> or <title>, and its .xml.qti file was skipped
        // above. Read the exact dump classification matched (objectbankpath) so the report
        // title, the built activity name, and duplicate-title disambiguation all use the same
        // bank name — regardless of the candidate order or directory prefix here.
        if ($modelitem->objectbankpath !== '') {
            $absolute = $this->resolve_within($modelitem->objectbankpath);
            if ($absolute !== null) {
                $title = (new qti_parser())->parse((string) @file_get_contents($absolute))['title'] ?? '';
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

            // Collect every <file href="..."> child. (Backslash separators were
            // normalised across the DOM up front, so these are already clean.)
            $files = $resource->getElementsByTagNameNS('*', 'file');
            foreach ($files as $file) {
                if ($file instanceof DOMElement && $file->getAttribute('href') !== '') {
                    $modelitem->files[] = $file->getAttribute('href');
                }
            }

            // Collect <dependency identifierref="..."> children. In Common Cartridge a
            // dependency is an embedded asset of this resource (a quiz's image resource,
            // a discussion's media) rather than a standalone activity; recording them lets
            // the orphan pass keep an unplaced dependency target off the course page.
            foreach ($resource->getElementsByTagNameNS('*', 'dependency') as $dependency) {
                if ($dependency instanceof DOMElement && $dependency->getAttribute('identifierref') !== '') {
                    $modelitem->dependencies[] = $dependency->getAttribute('identifierref');
                }
            }

            // Some CC 1.3 packages embed the IMS Assignment profile XML
            // inline inside <resource> instead of carrying it as a <file>.
            // Capture the serialized inline descriptor so assign_builder can
            // parse it even when no on-disk path resolves.
            $modelitem->inlinexml = $this->read_inline_assignment_xml($resource);

            // CC's <variant> extension lets a fallback resource point at a
            // richer preferred resource. Remember the target so the section
            // attach can swap when the variant is a buildable kind.
            $modelitem->variantref = $this->read_variant_ref($resource);

            $modelitem->kind = $this->classify($type, $href, $modelitem->files);
            // Record the standalone item-bank id so the builder imports it through the
            // shared registry keyed by the exact objectbank file (not whichever XML
            // locate_qti happens to pick first), deduping it against a New Quiz draw.
            if ($modelitem->kind === item::KIND_QUESTIONBANK && str_contains($type, 'learning-application-resource')) {
                $bankpath = $this->standalone_objectbank_path($href, $modelitem->files);
                if ($bankpath !== null) {
                    $modelitem->objectbankid = $this->objectbank_id_from_path($bankpath);
                    $modelitem->objectbankpath = $bankpath;
                }
            }
            // An external-link resource (a native D2L "contentlink", or any
            // resource whose href is an absolute URL) carries its target directly
            // in the href rather than in a weblink XML file; record it so
            // url_builder uses it as-is.
            if ($modelitem->kind === item::KIND_URL && preg_match('#^https?://#i', $href)) {
                $modelitem->url = $href;
            }
            $materialtype = $this->read_d2l_material_type($resource);
            // Suppress only known structural placeholders here: D2L's empty
            // contentmodule <resource>s (which exist only to title a module) and
            // its metadata resources (news/syllabus/links). A generic empty
            // container can't be recognised from the resource alone — that it
            // acts as a section/folder is only visible from the organisation
            // tree, so attach_resource() handles that case. Everything else flows
            // through, including unsupported types and missing-payload exports, so
            // the report can flag or skip-and-explain them rather than drop them.
            $ismodulenode = $materialtype === 'contentmodule'
                && $href === '' && empty($modelitem->files) && $modelitem->inlinexml === '';
            // Blackboard exports its build log as a web_content<NNN>.log resource
            // (a junk artifact, never course content); drop it rather than import
            // it as a file. Scoped to that exact naming *and* the instructor-role
            // LOM metadata Blackboard stamps on the artifact, so a course that
            // legitimately publishes a .log (e.g. access.log) — or even a
            // learner-facing file that happens to be named web_content00001.log —
            // is left alone.
            $islogartifact = $this->is_build_log_artifact($resource, $href, $modelitem->files);
            // ANGEL/eXe exports tag leftover duplicate resources (framework UI
            // chrome and unreferenced glossary term fragments) with an
            // _UNREFERENCED_ marker in the identifier. Once such a package is
            // recognised, drop those rather than importing dozens of junk files.
            $isunreferenced = $this->is_unreferenced_artifact($identifier);
            // A Canvas course carries platform boilerplate a Moodle course has no
            // use for: help-guide links to Canvas's own docs, and — when the
            // course was migrated from ANGEL — ANGEL's leftover objects that just
            // duplicate the Canvas-native content. Drop those.
            $iscanvasboilerplate = $this->is_canvas_boilerplate($type, $href, $modelitem->files);
            if ($iscanvasboilerplate) {
                $this->canvasboilerplatedropped++;
            }
            if (
                $ismodulenode || $islogartifact || $isunreferenced || $iscanvasboilerplate
                || in_array($materialtype, self::D2L_METADATA_MATERIAL_TYPES, true)
            ) {
                $modelitem->kind = item::KIND_UNKNOWN;
                $modelitem->suppressed = true;
            } else {
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
            }
            $items[$identifier] = $modelitem;
        }
        return $items;
    }

    /**
     * Whether a resource is Blackboard's export build log: its href and every
     * <file> it carries are named web_content<NNN>.log, the artifact Blackboard
     * writes alongside real content, *and* it carries the instructor-role LOM
     * metadata Blackboard stamps on that artifact. Such a resource is suppressed
     * rather than imported as a junk file. Requiring both signals keeps the drop
     * narrow: a course that legitimately ships a .log as material (e.g.
     * access.log), or even a learner-facing file that happens to be named
     * web_content00001.log, is left alone because it carries no such metadata. A
     * resource with no files is not treated as an artifact.
     *
     * @param DOMElement $resource The <resource> element.
     * @param string $href The resource href, if any.
     * @param array $files The resource's file paths.
     * @return bool
     */
    private function is_build_log_artifact(DOMElement $resource, string $href, array $files): bool {
        $paths = $files;
        if ($href !== '') {
            $paths[] = $href;
        }
        $paths = array_filter($paths, fn($p) => (string) $p !== '');
        if (empty($paths)) {
            return false;
        }
        foreach ($paths as $path) {
            // Match only Blackboard's numbered build log (web_content<NNN>.log),
            // not any .log a course might legitimately publish as material.
            if (!preg_match('~(^|/)web_content\d+\.log$~i', (string) $path)) {
                return false;
            }
        }
        // The basename alone is not enough — require Blackboard's instructor-role
        // metadata so a real learner resource named that way is never dropped.
        return $this->has_instructor_role($resource);
    }

    /**
     * Whether a resource carries LOM educational metadata marking it for the
     * instructor role (<intendedEndUserRole><value>Instructor</value></…>).
     * Blackboard stamps its build-log artifact this way; learner content does
     * not. Matched namespace-agnostically by local name so any LOM prefix works.
     *
     * @param DOMElement $resource The <resource> element.
     * @return bool
     */
    private function has_instructor_role(DOMElement $resource): bool {
        foreach ($resource->getElementsByTagNameNS('*', 'intendedEndUserRole') as $role) {
            if (!($role instanceof DOMElement)) {
                continue;
            }
            foreach ($role->getElementsByTagNameNS('*', 'value') as $value) {
                if (strcasecmp(trim($value->textContent), 'Instructor') === 0) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Whether a resource is an ANGEL/eXe leftover the exporter itself marked as
     * unreferenced (its identifier carries an _UNREFERENCED_ marker) — framework
     * UI chrome and duplicate glossary term fragments that would otherwise import
     * as dozens of junk file resources. Gated on the package being recognised as
     * ANGEL/eXe so the marker is never acted on for an unrelated source.
     *
     * @param string $identifier The resource identifier.
     * @return bool
     */
    private function is_unreferenced_artifact(string $identifier): bool {
        return in_array($this->source, [source_detector::ANGEL, source_detector::EXE], true)
            && stripos($identifier, '_UNREFERENCED_') !== false;
    }

    /**
     * Whether a Canvas resource is platform boilerplate a Moodle course has no
     * use for, gated on the package being a Canvas export so nothing similar is
     * dropped from another source. Two precise, unambiguous cases:
     *
     * - An imswl web-link whose target host is Canvas's own documentation site
     *   (guides.instructure.com / community.canvaslms.com) — help docs, never
     *   course content. Real content links (to files or other sites) are left.
     * - ANGEL's own objects (AngelManifest.xml / AngelObj[...].xml) that an
     *   ANGEL-to-Canvas migration dumps into web_resources; they duplicate the
     *   Canvas-native content, so drop them by their exact filenames. Other
     *   web_resources files (banners, documents, images) are untouched.
     *
     * @param string $type The resource type attribute.
     * @param string $href The resource href, if any.
     * @param array $files The resource's file paths.
     * @return bool
     */
    private function is_canvas_boilerplate(string $type, string $href, array $files): bool {
        if ($this->source !== source_detector::CANVAS) {
            return false;
        }
        $paths = array_values(array_filter(
            array_merge($files, $href !== '' ? [$href] : []),
            fn($p) => (string) $p !== ''
        ));
        // Only drop a resource whose *entire* payload is ANGEL objects. A real
        // page/file that merely lists an AngelObj/AngelManifest support file
        // alongside its content must be kept, not suppressed whole.
        if (!empty($paths)) {
            $allangel = true;
            foreach ($paths as $path) {
                if (!$this->is_angel_object(basename((string) $path))) {
                    $allangel = false;
                    break;
                }
            }
            if ($allangel) {
                return true;
            }
        }
        if (preg_match('#imswl_xmlv1p\d#', $type)) {
            $url = $this->weblink_target($href, $files);
            if (preg_match('#^https?://(guides\.instructure\.com|community\.canvaslms\.com)/#i', $url)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Read the target URL from an imswl web-link resource's XML file, which
     * stores it as <url href="..."/>. Returns '' when no file resolves or no URL
     * is present. Kept Moodle-free (plain file read + regex) so the parser layer
     * stays free of the build layer's weblink reader.
     *
     * @param string $href The resource href, if any.
     * @param array $files The resource's file paths.
     * @return string The target URL, or '' if none.
     */
    private function weblink_target(string $href, array $files): string {
        $paths = $files;
        if ($href !== '') {
            $paths[] = $href;
        }
        foreach ($paths as $path) {
            if (!preg_match('/\.xml$/i', (string) $path)) {
                continue;
            }
            $absolute = $this->resolve_within((string) $path);
            if ($absolute === null) {
                continue;
            }
            $xml = (string) @file_get_contents($absolute);
            if (preg_match('/<url\b[^>]*\bhref="([^"]*)"/i', $xml, $m)) {
                return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5);
            }
        }
        return '';
    }

    /**
     * Whether a filename is one of ANGEL's own migration objects
     * (AngelManifest.xml or AngelObj[...].xml).
     *
     * @param string $basename The file's basename.
     * @return bool
     */
    private function is_angel_object(string $basename): bool {
        return (bool) preg_match('/^AngelManifest\.xml$/i', $basename)
            || (bool) preg_match('/^AngelObj\[.*\]\.xml$/i', $basename);
    }

    /**
     * Read the CC <variant identifierref="..."> child of a <resource>, if any.
     * The variant element lives in the CC extension namespace
     * imscp_extensionv1p2; match it namespace-agnostically by local name so
     * any commonly-used prefix works.
     *
     * @param DOMElement $resource The <resource> element.
     * @return string Variant target identifier, or '' when no variant child.
     */
    private function read_variant_ref(DOMElement $resource): string {
        foreach ($resource->childNodes as $child) {
            if (!($child instanceof DOMElement)) {
                continue;
            }
            if ($child->localName !== 'variant') {
                continue;
            }
            $ref = trim($child->getAttribute('identifierref'));
            if ($ref !== '') {
                return $ref;
            }
        }
        return '';
    }

    /**
     * D2L material types that carry course configuration/metadata rather than
     * learner content, so they are suppressed instead of imported as files.
     * Deliberately a small allowlist: other d2l* types (notably d2lquiz and
     * d2lquestionlibrary assessment exports) are preserved as resources rather
     * than dropped.
     *
     * @var string[]
     */
    private const D2L_METADATA_MATERIAL_TYPES = ['d2lnews', 'd2lsyllabus', 'd2llinks'];

    /**
     * Read a resource's D2L material_type (the d2l_2p0:material_type attribute),
     * matched namespace-agnostically by local name so any prefix works. D2L
     * Brightspace tags every <resource> with one: "content"/"contentmodule" for
     * real material, and "d2lnews"/"d2lsyllabus"/"d2llinks"/etc. for its own
     * metadata. Returns the lower-cased value, or '' when not a D2L resource.
     *
     * @param DOMElement $resource The <resource> element.
     * @return string Lower-cased material type, or '' when absent.
     */
    private function read_d2l_material_type(DOMElement $resource): string {
        $value = $resource->getAttributeNS('http://desire2learn.com/xsd/d2lcp_v2p0', 'material_type');
        if ($value === '') {
            foreach ($resource->attributes as $attr) {
                if ($attr->localName === 'material_type') {
                    $value = (string) $attr->nodeValue;
                    break;
                }
            }
        }
        return strtolower(trim($value));
    }

    /**
     * Normalise backslash path separators to forward slashes on every href
     * attribute across the manifest DOM, in place. Packages authored on Windows
     * (notably native D2L exports) write backslash separators — e.g.
     * "Module 2\Notes.pdf" — which never resolve against the forward-slash paths
     * inside the zip. Doing this once on the DOM means every consumer (source
     * detection, classification, cleanup helpers, the model) sees clean paths.
     *
     * @param DOMDocument $dom The loaded manifest document (modified in place).
     * @return void
     */
    private function normalize_dom_separators(DOMDocument $dom): void {
        foreach ($dom->getElementsByTagName('*') as $element) {
            if (!$element instanceof DOMElement || !$element->hasAttribute('href')) {
                continue;
            }
            $href = $element->getAttribute('href');
            $normalized = self::normalize_separators($href);
            if ($normalized !== $href) {
                $element->setAttribute('href', $normalized);
            }
        }
    }

    /**
     * Normalise path separators in a single href to forward slashes. Leaves
     * genuine URLs untouched (backslashes are not valid in a URL, so a scheme'd
     * href is passed through unchanged).
     *
     * @param string $href The raw href from the manifest.
     * @return string The href with backslashes converted to forward slashes.
     */
    private static function normalize_separators(string $href): string {
        if (preg_match('~^[a-z][a-z0-9+.\-]*://~i', $href)) {
            return $href;
        }
        return str_replace('\\', '/', $href);
    }

    /**
     * Look for an inline CC 1.3 IMS Assignment profile descriptor inside a
     * <resource>. The CC spec allows the <assignment> element to be embedded
     * directly under <resource> instead of referenced via <file>; return the
     * serialized XML of that element so assign_builder can parse it without
     * needing a path on disk.
     *
     * @param DOMElement $resource The <resource> element.
     * @return string Serialized inline descriptor XML, or '' when not present.
     */
    private function read_inline_assignment_xml(DOMElement $resource): string {
        $ns = 'http://www.imsglobal.org/xsd/imscc_extensions/assignment';
        foreach ($resource->getElementsByTagNameNS($ns, 'assignment') as $node) {
            if ($node->parentNode !== $resource) {
                // Only treat a direct child as the inline descriptor; nested
                // <assignment> elements (e.g. inside <extensions>) belong to a
                // parent descriptor and should not be lifted out on their own.
                continue;
            }
            // Use C14N so the captured snippet carries its xmlns declaration
            // inline; saveXML omits ancestor-declared namespaces and the
            // resulting fragment would parse without a namespace context,
            // tripping assignment_settings's CC 1.3 profile detection.
            $xml = $node->C14N();
            return is_string($xml) ? $xml : '';
        }
        return '';
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
     * Downgrade a resource's visibility to hidden when its own companion metadata
     * marks it unpublished. Canvas records an activity's draft state in the file
     * that describes it — assignment_settings.xml for assignments, assessment_meta.xml
     * for quizzes, and the topicMeta for discussions — not only in module_meta.xml.
     * An orphan (no module places it) therefore has no module-level visibility to
     * inherit, so without this it would default to visible and import live. Only
     * ever hides (never reveals), so a module that already hid an item is unaffected.
     *
     * @param item[] $resources Resources keyed by identifier (modified in place).
     * @return void
     */
    protected function mark_unpublished_from_metadata(array &$resources): void {
        // Discussions keep their state in a topicMeta whose <topic_id> points back
        // at the discussion resource, and which is often a separately-named
        // resource, so build a topic_id => isunpublished map across every XML file
        // first (mirroring the announcement pass).
        $topicunpublished = [];
        foreach ($resources as $resourceitem) {
            $candidates = $resourceitem->files;
            if ($resourceitem->href !== '') {
                $candidates[] = $resourceitem->href;
            }
            foreach ($candidates as $relative) {
                if (!preg_match('/\.xml$/i', (string) $relative)) {
                    continue;
                }
                $absolute = $this->resolve_within((string) $relative);
                if ($absolute === null) {
                    continue;
                }
                $info = $this->read_topicmeta_state((string) @file_get_contents($absolute));
                if ($info !== null && $info['isunpublished']) {
                    $topicunpublished[$info['topic_id']] = true;
                }
            }
        }

        foreach ($resources as $resourceitem) {
            if (!$resourceitem->isvisible) {
                continue;
            }
            // Only consult the metadata that actually describes this kind of
            // resource, so a companion file that merely shares a directory with an
            // unrelated activity (e.g. a web-content file beside a quiz's
            // assessment_meta.xml) never drives this item's visibility.
            $xml = '';
            if (
                $resourceitem->kind === item::KIND_DISCUSSION
                && !empty($topicunpublished[$resourceitem->identifier])
            ) {
                $resourceitem->isvisible = false;
                continue;
            } else if ($resourceitem->kind === item::KIND_ASSIGNMENT) {
                $absolute = $this->locate_assignment_settings($resourceitem);
                // A CC 1.3 assignment descriptor can be embedded under <resource>
                // with no settings file on disk; its draft state then lives in the
                // inline XML, so fall back to it.
                $xml = $absolute !== null ? (string) @file_get_contents($absolute) : $resourceitem->inlinexml;
            } else if (in_array($resourceitem->kind, [item::KIND_QUIZ, item::KIND_QUESTIONBANK], true)) {
                $absolute = $this->locate_assessment_meta($resourceitem);
                $xml = $absolute !== null ? (string) @file_get_contents($absolute) : '';
            }
            if ($xml === '') {
                continue;
            }
            if ($this->metadata_marks_unpublished($xml)) {
                $resourceitem->isvisible = false;
            }
        }
    }

    /**
     * Read a topicMeta document's back-reference and draft state, whatever its
     * <type> (ordinary topic or announcement). Returns null for anything that
     * is not a topicMeta so it can be called against every XML file.
     *
     * @param string $xml The candidate XML payload.
     * @return array|null ['topic_id'=>string, 'isunpublished'=>bool] or null.
     */
    protected function read_topicmeta_state(string $xml): ?array {
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
        $topicid = $this->first_child_named($dom->documentElement, 'topic_id');
        $id = $topicid === null ? '' : trim($topicid->textContent);
        if ($id === '') {
            return null;
        }
        $state = $this->first_child_named($dom->documentElement, 'workflow_state');
        return [
            'topic_id' => $id,
            'isunpublished' => $state !== null && strtolower(trim($state->textContent)) === 'unpublished',
        ];
    }

    /**
     * Whether an assignment/quiz descriptor marks its activity unpublished. Scoped
     * to a recognised root element (<assignment> or <quiz>) so an unrelated
     * document that merely mentions the word is never misread, but within that it
     * accepts a <workflow_state>unpublished</workflow_state> wherever the shape
     * carries it: the top level (flat Canvas assignment_settings.xml), the embedded
     * <assignment> of a New Quizzes assessment_meta.xml, or the Canvas extension of
     * an inline CC 1.3 assignment profile.
     *
     * @param string $xml The candidate XML payload.
     * @return bool True when the descriptor's workflow_state is "unpublished".
     */
    protected function metadata_marks_unpublished(string $xml): bool {
        if (trim($xml) === '') {
            return false;
        }
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || $dom->documentElement === null) {
            return false;
        }
        if (!in_array($dom->documentElement->localName, ['assignment', 'quiz'], true)) {
            return false;
        }
        // Every <workflow_state> inside such a descriptor refers to the activity
        // (the flat root, the New-Quiz embedded <assignment>, or the CC 1.3 Canvas
        // extension), so an "unpublished" one anywhere within means it is a draft.
        foreach ($dom->getElementsByTagNameNS('*', 'workflow_state') as $state) {
            if (strtolower(trim($state->textContent)) === 'unpublished') {
                return true;
            }
        }
        return false;
    }

    /**
     * Hide files Canvas marked hidden in course_settings/files_meta.xml. A file is
     * hidden either directly (a <file> whose identifier matches a resource and
     * carries <hidden>true</hidden>) or by living under a hidden <folder> — Canvas
     * puts QTI-internal images under a hidden "Uploaded Media" folder, which would
     * otherwise import as visible standalone resources. Only ever hides.
     *
     * @param item[] $resources Resources keyed by identifier (modified in place).
     * @return void
     */
    protected function mark_hidden_from_files_meta(array &$resources): void {
        $absolute = $this->resolve_within('course_settings/files_meta.xml');
        if ($absolute === null) {
            return;
        }
        $xml = (string) @file_get_contents($absolute);
        if (trim($xml) === '') {
            return;
        }
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || $dom->documentElement === null) {
            return;
        }

        // Collect hidden file identifiers and hidden folder path prefixes. Folder
        // paths in files_meta are relative to the package's web_resources root, so
        // a file href "web_resources/Uploaded Media/x.jpg" sits under folder path
        // "Uploaded Media".
        $hiddenids = [];
        $hiddenprefixes = [];
        foreach ($dom->getElementsByTagName('file') as $file) {
            if (!$file instanceof DOMElement) {
                continue;
            }
            $id = trim($file->getAttribute('identifier'));
            if ($id !== '' && $this->child_flag_true($file, 'hidden')) {
                $hiddenids[$id] = true;
            }
        }
        foreach ($dom->getElementsByTagName('folder') as $folder) {
            if (!$folder instanceof DOMElement) {
                continue;
            }
            $path = trim($folder->getAttribute('path'));
            if ($path !== '' && $this->child_flag_true($folder, 'hidden')) {
                $hiddenprefixes[] = 'web_resources/' . trim($path, '/') . '/';
            }
        }
        if ($hiddenids === [] && $hiddenprefixes === []) {
            return;
        }

        foreach ($resources as $identifier => $resourceitem) {
            if (!$resourceitem->isvisible) {
                continue;
            }
            // A file explicitly listed as hidden by its own identifier is hidden
            // whatever its kind.
            if (isset($hiddenids[$identifier])) {
                $resourceitem->isvisible = false;
                continue;
            }
            // Folder-level hiding applies only to standalone file resources,
            // matched against the file's own payload - never to an auxiliary file
            // an activity (a page, an assignment) merely embeds from a hidden
            // folder, which must not drag the whole activity out of view.
            if ($resourceitem->kind !== item::KIND_FILE) {
                continue;
            }
            $payload = $resourceitem->href !== '' ? $resourceitem->href : ($resourceitem->files[0] ?? '');
            foreach ($hiddenprefixes as $prefix) {
                if (strncmp((string) $payload, $prefix, strlen($prefix)) === 0) {
                    $resourceitem->isvisible = false;
                    break;
                }
            }
        }
    }

    /**
     * Whether a direct child element with the given local name has the boolean
     * text value "true" (case-insensitive).
     *
     * @param DOMElement $parent The parent element.
     * @param string $name Local name of the flag child.
     * @return bool
     */
    protected function child_flag_true(DOMElement $parent, string $name): bool {
        $child = $this->first_child_named($parent, $name);
        return $child !== null && strtolower(trim($child->textContent)) === 'true';
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
        // A resource whose href is itself an absolute URL is an external link,
        // not a local payload — notably a native D2L "contentlink" (exported as
        // webcontent with an http href). Map it to mod_url; file_builder could
        // never read a remote href anyway. IMS web-link/discussion/QTI resources
        // point their href at a local .xml, so this never shadows them.
        if (preg_match('#^https?://#i', $href)) {
            return item::KIND_URL;
        }
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
            // A learning-application-resource whose payload is a native item-bank dump
            // (non_cc_assessments/<id>.xml.qti rooted at <objectbank>) is a standalone
            // Canvas question bank not wired to any quiz; import it as a mod_qbank instead
            // of dropping it as a metadata-only companion. But only when the resource has no
            // HTML page of its own: a resource whose primary payload is HTML (even if it also
            // lists a bank dump as an auxiliary file) is a page, not a bank.
            $hashtml = $this->has_html($href, $files);
            if (!$hashtml && $this->standalone_objectbank_path($href, $files) !== null) {
                return item::KIND_QUESTIONBANK;
            }
            return $hashtml ? item::KIND_PAGE : item::KIND_UNKNOWN;
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
     * The path of a resource's standalone Canvas item-bank file, or null when it has none.
     * A standalone bank is a native non_cc_assessments/<id>.xml.qti dump rooted at an
     * <objectbank> that carries questions or bare question references (not an
     * <assessment>-rooted file, nor a non-QTI metadata companion). An objectbank of only
     * bare references is still recognised so the builder can report the omitted bodies as a
     * skip rather than dropping the bank silently. Returns the exact matched path so the
     * builder targets the right file when a resource lists several.
     *
     * @param string $href The primary href.
     * @param string[] $files All file hrefs.
     * @return string|null
     */
    protected function standalone_objectbank_path(string $href, array $files): ?string {
        foreach (array_merge([$href], $files) as $path) {
            if ($path === '' || !preg_match('#(^|/)non_cc_assessments/[^/]+\.xml\.qti$#i', $path)) {
                continue;
            }
            $absolute = $this->resolve_within($path);
            if ($absolute === null) {
                continue;
            }
            $parsed = (new qti_parser())->parse((string) @file_get_contents($absolute));
            // Require the objectbank to be the file's sole QTI payload: a document that also
            // carries an <assessment> is an assessment dump (which quiz_builder owns), not a
            // standalone bank, so classifying it here would import its questions twice.
            if (!empty($parsed['hasassessment'])) {
                continue;
            }
            if (!empty($parsed['hasobjectbank']) && (!empty($parsed['questions']) || (int) ($parsed['unresolved'] ?? 0) > 0)) {
                return $path;
            }
        }
        return null;
    }

    /**
     * The item-bank id for a standalone objectbank file path: its basename without the
     * .xml.qti suffix (stripped case-insensitively, matching the classifier's and
     * locate_qti's case-insensitive extension handling), which equals a New Quiz's
     * sourcebank_ref so the two dedupe to one import.
     *
     * @param string $path The objectbank file path.
     * @return string
     */
    protected function objectbank_id_from_path(string $path): string {
        return (string) preg_replace('/\.xml\.qti$/i', '', basename($path));
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
            // Combine the module occurrence's state with any resource-level hidden
            // state (files_meta.xml, or draft metadata): a file Canvas marked
            // hidden must stay hidden even when a published module also places it,
            // so only ever hide - never let an active module reveal it.
            $modelitem->isvisible = $isvisible && $resources[$ref]->isvisible;
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

        // A Canvas ContextExternalTool placed directly in a module carries an
        // inline LTI launch <url> but often no matching Common Cartridge LTI
        // resource (its identifierref resolves to nothing). Rather than drop the
        // tool, synthesise a hidden mod_lti placeholder from the inline URL, just
        // as the cartridge-backed LTI path does.
        if ($contenttype === 'ContextExternalTool') {
            $url = $this->child_text($node, 'url');
            if (preg_match('#^https?://#i', $url) !== 1) {
                return null;
            }
            $id = $node->getAttribute('identifier');
            $modelitem = new item($id, $title);
            $modelitem->kind = item::KIND_LTI;
            $modelitem->launchurl = $url;
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

        // Peel pass-through wrappers above the modules level. Stop when
        // either (a) we'd descend to more than one node — those become the
        // sections — or (b) the single node's children are all activity
        // leaves: that single node IS the only section, and the leaves are
        // its activities. The second rule keeps a single-module export like
        // root → "Week 1" → [Welcome, Essay] from being mis-peeled into
        // activity-titled sections.
        $rootitems = $this->child_items($organization);
        while (count($rootitems) === 1) {
            $children = $this->child_items($rootitems[0]);
            if (empty($children) || $this->all_activity_leaves($children)) {
                break;
            }
            $rootitems = $children;
        }

        foreach ($rootitems as $sectionnode) {
            $title = $this->item_title($sectionnode);
            // CC manifests where the org tree is a single untitled <item root>
            // wrapping activity leaves give us an empty section name; fall back
            // to the course title so the section reads as something useful
            // instead of Moodle's "Section 1" default.
            if ($title === '' && $course->fullname !== '') {
                $title = $course->fullname;
            }
            $section = new section_model($title);
            if ($sectionnode->getAttribute('identifierref') !== '') {
                // Attach the section node's own resource as the section's first
                // item; attach_resource() skips it when the node is an empty
                // structural container (a module/folder that only titles the
                // section), so it doesn't surface as a phantom payload-less item.
                $this->attach_resource($sectionnode, $resources, $section);
            }
            // Walk the whole subtree so descendants inside folder wrappers
            // (items with no identifierref of their own) still attach with
            // their org-tree titles. Folder wrappers commonly appear as
            // intermediate <item>s carrying just a <title> in CC packages.
            $this->collect_leaf_resources($sectionnode, $resources, $section);
            $course->add_section($section);
        }
    }

    /**
     * Whether every supplied <item> is an activity leaf — i.e. has no nested
     * <item> children of its own. Used by build_sections() to decide whether
     * the current single peeling target is the section itself (children are
     * its activities) or a wrapper that should be peeled further.
     *
     * @param DOMElement[] $items
     * @return bool
     */
    protected function all_activity_leaves(array $items): bool {
        foreach ($items as $item) {
            if (count($this->child_items($item)) > 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * Recursively attach every descendant <item> that carries an identifierref
     * to the given section, flattening folder wrappers along the way. Avoids
     * adding the same identifier twice if multiple <item>s point at it.
     *
     * @param \DOMNode $node The parent <item> to walk under.
     * @param item[] $resources Resources keyed by identifier.
     * @param section_model $section The section to add to.
     * @return void
     */
    protected function collect_leaf_resources(\DOMNode $node, array $resources, section_model $section): void {
        foreach ($this->child_items($node) as $child) {
            if ($child->getAttribute('identifierref') !== '') {
                $this->attach_resource($child, $resources, $section);
            }
            // Recurse regardless: a folder may have its own identifierref AND
            // wrap more children, and both should land in the section.
            $this->collect_leaf_resources($child, $resources, $section);
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
        $resourceitem = $this->prefer_variant($resources[$ref], $resources);
        // Skip resources we've deliberately set aside: bundle assets folded
        // by fold_lesson_bundles(), and the deliberate-skip KIND_UNKNOWN
        // cases (quiz/ assets, metadata-only learning-application
        // companions). Genuinely unsupported resource types stay visible so
        // the report can call them out as unmappable rows.
        if ($resourceitem->bundlemember || $resourceitem->suppressed) {
            return;
        }
        // An item that nests child <item>s but whose resource is an empty plain
        // file is a structural container (a section/folder node — a D2L
        // contentmodule, or a plain empty webcontent folder), so it only titles
        // the section. Skip it here instead of attaching a phantom payload-less
        // activity ahead of the real children, and record the identifier so the
        // orphan pass leaves it out — without mutating the shared resource, so
        // the same resource referenced elsewhere as a real leaf still attaches.
        // Buildable kinds (a missing-payload assignment/quiz/…) and unknown types
        // are NOT containers: a leaf, or any non-file kind, is left to attach so
        // it still reaches its builder / the report.
        if ($this->is_empty_container($node, $resourceitem)) {
            $this->containerconsumed[$resourceitem->identifier] = true;
            return;
        }
        $title = $this->item_title($node);
        if ($title !== '') {
            $resourceitem->title = $title;
        }
        $section->add_item($resourceitem);
    }

    /**
     * Whether an organisation <item> acts as an empty structural container — it
     * nests child <item>s and the resource it references is an empty plain file
     * (KIND_FILE with no href, <file> or inline descriptor), so it exists only
     * to title the section/folder rather than to be built. Buildable kinds and
     * unknown types are excluded: a missing-payload assignment or an unsupported
     * resource must still attach so the report can skip-and-explain it.
     *
     * @param DOMElement $node The organisation <item>.
     * @param item $resourceitem The resource the item references.
     * @return bool
     */
    private function is_empty_container(DOMElement $node, item $resourceitem): bool {
        if ($resourceitem->kind !== item::KIND_FILE) {
            return false;
        }
        if (count($this->child_items($node)) === 0) {
            return false;
        }
        return $resourceitem->href === ''
            && empty($resourceitem->files)
            && $resourceitem->inlinexml === '';
    }

    /**
     * Follow a CC <variant identifierref="..."> to its preferred target when
     * the variant points at a richer buildable resource than the fallback the
     * organisation tree references. CC cartridges commonly point items at a
     * webcontent fallback and put the real assignment_xmlv1p0 resource behind
     * a variant; without this swap the fallback HTML is what lands in the
     * section while the assignment becomes an orphan.
     *
     * Marks the fallback as suppressed so the orphan pass doesn't surface it
     * separately. Swaps for KIND_ASSIGNMENT targets, where the intent ("the
     * assignment is the real activity") is unambiguous - including an external-tool
     * assignment already re-homed to a KIND_LTI launch placeholder, which is still
     * the real activity behind the webcontent fallback.
     *
     * @param item $fallback The resource the organisation tree references.
     * @param item[] $resources All resources keyed by identifier.
     * @return item Either the fallback or its preferred variant target.
     */
    private function prefer_variant(item $fallback, array $resources): item {
        if (
            $fallback->variantref === ''
            || $fallback->variantref === $fallback->identifier
            || !isset($resources[$fallback->variantref])
        ) {
            return $fallback;
        }
        $preferred = $resources[$fallback->variantref];
        // An external-tool assignment is converted to KIND_LTI before section
        // building, so accept that re-homed launch placeholder too (a launch URL
        // marks it as one) - otherwise the fallback HTML would occupy the module
        // and the real tool would be orphaned.
        $isrehomedassignment = $preferred->kind === item::KIND_LTI && $preferred->launchurl !== '';
        if ($preferred->kind !== item::KIND_ASSIGNMENT && !$isrehomedassignment) {
            return $fallback;
        }
        $fallback->suppressed = true;
        // Carry the fallback identifier as an alias so the URL map records
        // the preferred resource's URL under both IDs. Any
        // $CANVAS_OBJECT_REFERENCE$ link elsewhere in the package that
        // targets the fallback (the one named in the organisation tree)
        // still resolves to the built assignment instead of landing on
        // an unresolved placeholder.
        if (!in_array($fallback->identifier, $preferred->aliasids, true)) {
            $preferred->aliasids[] = $fallback->identifier;
        }
        // Likewise carry the fallback's source path: a page that links by
        // relative path to the fallback HTML (rather than by object reference)
        // must still resolve to the preferred activity, but the fallback is
        // suppressed and so never reaches the path map on its own.
        $fallbackpath = $this->primary_path($fallback);
        if ($fallbackpath !== '' && !in_array($fallbackpath, $preferred->aliaspaths, true)) {
            $preferred->aliaspaths[] = $fallbackpath;
        }
        return $preferred;
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
     * Try to read the course title, preferring Canvas's course_settings.xml
     * and falling back to the IMS LOMIMSCC metadata block in the manifest.
     *
     * @param DOMDocument $dom The parsed manifest document.
     * @return string Empty string if not found.
     */
    protected function read_course_title(DOMDocument $dom): string {
        $path = $this->basedir . '/course_settings/course_settings.xml';
        if (is_readable($path)) {
            $previous = libxml_use_internal_errors(true);
            $xml = simplexml_load_file($path, 'SimpleXMLElement', LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            // The Canvas course_settings.xml exposes <title> for the course name.
            if ($xml !== false && isset($xml->title)) {
                $value = trim((string) $xml->title);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        // Fall back to the IMS LOM metadata title carried in the manifest
        // itself: <manifest><metadata><lomimscc:lom><lomimscc:general>
        // <lomimscc:title><lomimscc:string>...</...>. Question-bank-only
        // Canvas exports and non-Canvas CC packages have no
        // course_settings.xml so this is the only title available. Restrict
        // the lookup to a direct <metadata> child of the document element;
        // CC resources can carry their own per-resource LOM metadata and we
        // must not borrow a resource title as the course title.
        $root = $dom->documentElement;
        if ($root === null) {
            return '';
        }
        $metadata = null;
        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'metadata') {
                $metadata = $child;
                break;
            }
        }
        if ($metadata !== null) {
            foreach ($metadata->getElementsByTagNameNS('*', 'title') as $titlenode) {
                foreach ($titlenode->getElementsByTagNameNS('*', 'string') as $stringnode) {
                    $value = trim($stringnode->textContent);
                    if ($value !== '') {
                        return $value;
                    }
                }
                // No <string> child (some authoring tools inline the text): take
                // the title element's own text content.
                $value = trim($titlenode->textContent);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        // Final fallback: the IMS CC organisation's own <title> (a direct child
        // of <organization>), the conventional place CC packages without a
        // Canvas course_settings.xml or manifest LOM metadata carry the course
        // name. Only a direct title child counts — never an <item> title, which
        // is a module/resource name, not the course name.
        return $this->read_organization_title($root);
    }

    /**
     * Read the course title from a direct <title> child of the first
     * <organizations>/<organization> in the manifest.
     *
     * @param DOMElement $root The manifest document element.
     * @return string Empty string if not found.
     */
    protected function read_organization_title(DOMElement $root): string {
        foreach ($root->childNodes as $child) {
            if (!($child instanceof DOMElement) || $child->localName !== 'organizations') {
                continue;
            }
            foreach ($child->childNodes as $org) {
                if (!($org instanceof DOMElement) || $org->localName !== 'organization') {
                    continue;
                }
                foreach ($org->childNodes as $kid) {
                    if ($kid instanceof DOMElement && $kid->localName === 'title') {
                        $value = trim($kid->textContent);
                        if ($value !== '') {
                            return $value;
                        }
                    }
                }
            }
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
     * Read Canvas's letter-grade scheme (grading_standards.xml) into a list of
     * grade letters, but only when course_settings.xml switches a grading
     * standard on. Canvas stores each scheme's boundaries as a JSON array of
     * [letter, fraction] pairs (e.g. [["A",0.895],["B",0.795]]); each becomes a
     * Moodle grade letter with the fraction expressed as a percentage lower
     * boundary. Returns the letters highest-boundary first, or [] when the
     * course uses the default scheme or the files are missing/unparseable.
     * Moodle-free so it stays testable from XML strings.
     *
     * @return array<int, array{letter: string, lowerboundary: float}>
     */
    protected function read_grade_letters(): array {
        $settingspath = $this->basedir . '/course_settings/course_settings.xml';
        $standardspath = $this->basedir . '/course_settings/grading_standards.xml';
        if (!is_readable($settingspath) || !is_readable($standardspath)) {
            return [];
        }
        $previous = libxml_use_internal_errors(true);
        $settings = simplexml_load_file($settingspath, 'SimpleXMLElement', LIBXML_NONET);
        $standards = simplexml_load_file($standardspath, 'SimpleXMLElement', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($settings === false || $standards === false) {
            return [];
        }
        // Only import when Canvas has a grading standard enabled. The flag is an
        // XML boolean, so accept both the "true" and numeric "1" serialisations.
        $enabled = strtolower(trim((string) ($settings->grading_standard_enabled ?? '')));
        if (!in_array($enabled, ['true', '1'], true)) {
            return [];
        }
        $ref = trim((string) ($settings->grading_standard_identifier_ref ?? ''));

        // Install the standard the course references. With no ref, only fall back
        // to a lone standard: when several are present, guessing which one applies
        // could install the wrong letters, so decline rather than pick the first.
        $chosen = null;
        if ($ref === '') {
            if (count($standards->gradingStandard) === 1) {
                $chosen = $standards->gradingStandard[0];
            }
        } else {
            foreach ($standards->gradingStandard as $standard) {
                if ((string) ($standard['identifier'] ?? '') === $ref) {
                    $chosen = $standard;
                    break;
                }
            }
        }
        if ($chosen === null) {
            return [];
        }
        $data = json_decode(trim((string) $chosen->data), true);
        if (!is_array($data)) {
            return [];
        }
        $letters = [];
        foreach ($data as $pair) {
            if (!is_array($pair) || count($pair) < 2) {
                continue;
            }
            $letter = trim((string) $pair[0]);
            $fraction = (float) $pair[1];
            if ($letter === '' || $fraction < 0 || $fraction > 1) {
                continue;
            }
            $letters[] = ['letter' => $letter, 'lowerboundary' => round($fraction * 100, 5)];
        }
        // Highest boundary first, matching Moodle's grade-letter table order.
        usort($letters, fn($a, $b) => $b['lowerboundary'] <=> $a['lowerboundary']);
        return $letters;
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
                'long_description' => $this->child_text($ratingnode, 'long_description'),
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
            $xml = '';
            $absolute = $this->locate_assignment_settings($resourceitem);
            if ($absolute !== null) {
                $xml = (string) @file_get_contents($absolute);
            } else if ($resourceitem->inlinexml !== '') {
                // Inline CC 1.3 descriptors live on the model rather than on
                // disk; without this fallback their <extensions>'
                // assignment_group_identifierref and rubric_identifierref
                // wouldn't reach the item, losing grade-category placement
                // and rubric attachment for the built assignment.
                $xml = $resourceitem->inlinexml;
            }
            if ($xml === '') {
                continue;
            }
            $settings = assignment_settings::parse($xml);
            // A Canvas external-tool assignment (Quizzes.Next, SCORM, any LTI) is
            // really an LTI launch, not a file/text submission. Re-home it as an
            // LTI item carrying the launch URL so it builds as a hidden mod_lti
            // placeholder (consistent with the cartridge-LTI path) rather than a
            // near-empty mod_assign that silently drops the tool.
            if ($settings->is_external_tool()) {
                $resourceitem->kind = item::KIND_LTI;
                $resourceitem->launchurl = $settings->externaltoolurl;
                // Preserve the assignment prompt on the launch placeholder. The CC
                // 1.3 profile carries it inline (<text>); a flat Canvas assignment
                // keeps it in a sibling HTML that lti_builder reads at build time.
                $resourceitem->launchdescription = $settings->description;
                // Record the profile file's folder so media the inline instructions
                // embed resolves against it, not the resource href (they can differ).
                if ($absolute !== null) {
                    $resourceitem->launchdescriptiondir = safe_path::package_dir($this->basedir, $absolute);
                }
                // An inline CC 1.3 profile may hold the title only in its <title>
                // (no href/files slug to derive from, and lti_builder no longer
                // parses inlinexml), so carry it over rather than letting the
                // activity fall back to the generic "External tool" name.
                if ($resourceitem->title === '' && $settings->title !== '') {
                    $resourceitem->title = $settings->title;
                }
                continue;
            }
            if ($settings->gradegroupref !== '') {
                $resourceitem->gradegroupref = $settings->gradegroupref;
            }
            if ($settings->rubricref !== '') {
                $resourceitem->rubricref = $settings->rubricref;
                $resourceitem->rubricforgrading = $settings->rubricforgrading;
            }
        }
        $this->mark_quiz_groups($resources);
    }

    /**
     * Read each quiz/assessment's Canvas assignment-group reference from its
     * assessment_meta.xml (the group id lives on the embedded <assignment>, the
     * same place its workflow_state does) and stash it on the model item so
     * course_builder can route the built quiz into that grade category - just as
     * it already does for assignments.
     *
     * @param item[] $resources Resources keyed by identifier (modified in place).
     * @return void
     */
    protected function mark_quiz_groups(array &$resources): void {
        foreach ($resources as $resourceitem) {
            if (!in_array($resourceitem->kind, [item::KIND_QUIZ, item::KIND_QUESTIONBANK], true)) {
                continue;
            }
            if ($resourceitem->gradegroupref !== '') {
                continue;
            }
            $absolute = $this->locate_assessment_meta($resourceitem);
            if ($absolute === null) {
                continue;
            }
            $ref = $this->read_assessment_group_ref((string) @file_get_contents($absolute));
            if ($ref !== '') {
                $resourceitem->gradegroupref = $ref;
            }
        }
    }

    /**
     * Locate a quiz resource's assessment_meta.xml. It is a sibling of the
     * assessment_qti.xml (it lives in a dependency resource, so it is not in the
     * quiz item's own files), so derive the sibling path when it is not listed
     * directly.
     *
     * @param item $resourceitem The quiz/assessment resource.
     * @return string|null Absolute path within the package, or null.
     */
    private function locate_assessment_meta(item $resourceitem): ?string {
        $candidates = $resourceitem->files;
        if ($resourceitem->href !== '') {
            array_unshift($candidates, $resourceitem->href);
        }
        foreach ($candidates as $relative) {
            // Synthesize the sibling assessment_meta.xml beside each payload. A QTI
            // stored at the package root has dirname() '.', so the sibling is the
            // bare filename - not skipped, or a root-level meta would be missed.
            $dir = str_replace('\\', '/', dirname((string) $relative));
            $candidates[] = ($dir === '.' || $dir === '') ? 'assessment_meta.xml' : $dir . '/assessment_meta.xml';
        }
        foreach (array_unique($candidates) as $relative) {
            if (!str_ends_with((string) $relative, 'assessment_meta.xml')) {
                continue;
            }
            $absolute = $this->resolve_within((string) $relative);
            if ($absolute !== null) {
                return $absolute;
            }
        }
        return null;
    }

    /**
     * Read the Canvas assignment-group reference from an assessment_meta.xml. The
     * <assignment_group_identifierref> sits on the embedded <assignment> for a
     * New Quiz; fall back to a top-level one for older shapes.
     *
     * @param string $xml The assessment_meta.xml contents.
     * @return string The group identifier, or '' when absent.
     */
    protected function read_assessment_group_ref(string $xml): string {
        if (trim($xml) === '') {
            return '';
        }
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || $dom->documentElement === null) {
            return '';
        }
        $direct = $this->first_child_named($dom->documentElement, 'assignment_group_identifierref');
        if ($direct !== null && trim($direct->textContent) !== '') {
            return trim($direct->textContent);
        }
        $assignment = $this->first_child_named($dom->documentElement, 'assignment');
        if ($assignment !== null) {
            $nested = $this->first_child_named($assignment, 'assignment_group_identifierref');
            if ($nested !== null) {
                return trim($nested->textContent);
            }
        }
        return '';
    }

    /**
     * Locate the assignment settings XML for an assignment resource: prefer
     * Canvas's assignment_settings.xml, fall back to a CC 1.3 IMS Assignment
     * profile document so non-Canvas exporters still surface rubric and
     * grade-group references.
     *
     * @param item $resourceitem The assignment resource.
     * @return string|null Absolute path within the package, or null.
     */
    private function locate_assignment_settings(item $resourceitem): ?string {
        foreach ($resourceitem->files as $relative) {
            if (!str_ends_with($relative, 'assignment_settings.xml')) {
                continue;
            }
            $absolute = $this->resolve_within($relative);
            if ($absolute !== null) {
                return $absolute;
            }
        }
        $candidates = $resourceitem->files;
        if ($resourceitem->href !== '') {
            array_unshift($candidates, $resourceitem->href);
        }
        foreach ($candidates as $relative) {
            if (!preg_match('/\.xml$/i', $relative)) {
                continue;
            }
            $absolute = $this->resolve_within($relative);
            if ($absolute === null) {
                continue;
            }
            if (str_contains((string) @file_get_contents($absolute), 'imscc_extensions/assignment')) {
                return $absolute;
            }
        }
        return null;
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
        // Pass 1: identify every folder where some resource's PRIMARY path
        // (href, falling back to files[0]) is index.html. A nested asset
        // folder that merely lists index.html as a secondary <file> entry
        // must not qualify — fold_one_bundle() can only promote a resource
        // whose primary path is the anchor, so attributing a marker to such
        // a folder would let it steal the marker from a real parent bundle
        // and then silently fail to fold either of them.
        //
        // The map value is the real-cased basename so the eventual anchor
        // path matches whatever the exporter actually wrote (Index.html,
        // INDEX.HTML, etc.).
        $anchorfolders = [];
        foreach ($resources as $resourceitem) {
            $primary = $this->primary_path($resourceitem);
            if ($primary === '') {
                continue;
            }
            $basename = basename($primary);
            if (strtolower($basename) !== self::LESSON_BUNDLE_ROOT_MARKER) {
                continue;
            }
            $folder = $this->normalise_folder(dirname($primary));
            $anchorfolders[$folder] = $basename;
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
        foreach (array_keys($anchorfolders) as $folder) {
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
            $folded = $this->fold_one_bundle($resources, $folder, $anchorfolders[$folder]);
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
     * Fold each self-contained HTML file's referenced assets into the file.
     * Canvas (and similar) often export an interactive HTML exercise as one HTML
     * file plus a folder of js/css/image assets, each its own webcontent
     * resource. Left alone the HTML imports as a lone (broken) file resource and
     * every asset as a separate activity. This pass scans each HTML KIND_FILE
     * resource for the local assets it references (and the assets its stylesheets
     * reference), records them on the HTML item so file_builder imports them
     * alongside it and displays it embedded, and marks the now-absorbed
     * standalone asset resources so the orphan pass can drop the unplaced ones.
     * Every file owned by an absorbed resource is folded (not just the statically
     * referenced one), and an asset that is also explicitly placed in the course
     * is left to build as its own activity as well.
     *
     * Best-effort only — a couple of reference forms are not followed, so their
     * targets stay available as their own resources rather than being mis-folded:
     * ES module `import` graphs from an entry script, and HTML embedded as an
     * asset (`<object data="frame.html">`). The exercise still embeds; any
     * unfollowed file simply surfaces separately. A document `<base href>` that
     * is absolute or external is likewise left alone (its refs point outside the
     * package); a local relative `<base href>` is honoured.
     *
     * @param array $resources Resource items keyed by identifier (modified in place).
     * @return void
     */
    private function fold_html_asset_bundles(array &$resources): void {
        // Phase 1: each self-contained HTML file collects the package files it
        // references (transitively through its stylesheets). Assets are kept as
        // package paths so a reference into a sibling folder (../shared/app.js)
        // is preserved rather than dropped.
        $anchors = [];
        $claimants = [];
        foreach ($resources as $resourceitem) {
            if (
                $resourceitem->kind !== item::KIND_FILE || $resourceitem->bundlemember
                || !empty($resourceitem->bundleassets)
            ) {
                continue;
            }
            $primary = $this->primary_path($resourceitem);
            if ($primary === '' || !preg_match('/\.html?$/i', $primary)) {
                continue;
            }
            $absolute = $this->resolve_within($primary);
            if ($absolute === null) {
                continue;
            }
            $sources = $this->collect_html_assets($primary, (string) @file_get_contents($absolute));
            if (empty($sources)) {
                continue;
            }
            $index = count($anchors);
            $anchors[$index] = ['item' => $resourceitem, 'html' => $primary, 'sources' => $sources];
            // Record every anchor that references a path, not just the first, so a
            // resource shared by two exercises feeds both their bundles.
            foreach (array_keys($sources) as $source) {
                $claimants[$source][] = $index;
            }
        }
        if (empty($anchors)) {
            return;
        }
        // Phase 2: a standalone resource whose payload an anchor claimed has all
        // of its files folded into every anchor that references it — so a sibling
        // file the script pulls in at runtime (e.g. a fetched questions.json)
        // comes along too, into each exercise that shares the resource — and is
        // marked for deferred suppression so the orphan pass can later drop it,
        // but only if it was not also placed in the course itself.
        foreach ($resources as $resourceitem) {
            if (
                !empty($resourceitem->bundleassets) || $resourceitem->bundlemember
                || $resourceitem->htmlbundlemember
            ) {
                continue;
            }
            $primary = $this->primary_path($resourceitem);
            if ($primary === '' || empty($claimants[$primary])) {
                continue;
            }
            $owned = $this->resource_paths($resourceitem);
            foreach (array_unique($claimants[$primary]) as $index) {
                $sources = &$anchors[$index]['sources'];
                foreach ($owned as $path) {
                    if (!isset($sources[$path]) && $this->resolve_within($path) !== null) {
                        $sources[$path] = true;
                    }
                }
                unset($sources);
            }
            $resourceitem->htmlbundlemember = true;
        }
        // Phase 3: rebase each bundle's filearea to the common ancestor folder of
        // the HTML and its assets, so a parent-directory reference still resolves
        // when the resource is served, record the assets (plus the HTML's own
        // filearea path) on the anchor, and pin the HTML as the resource's main
        // payload for file_builder.
        foreach ($anchors as $anchor) {
            $html = $anchor['html'];
            $paths = array_keys($anchor['sources']);
            $ancestor = $this->common_ancestor(array_merge([$html], $paths));
            $assets = [];
            foreach ($paths as $source) {
                $assets[] = ['source' => $source, 'relpath' => $this->strip_prefix($ancestor, $source)];
            }
            $item = $anchor['item'];
            $item->bundleassets = $assets;
            $item->bundlehtmlpath = $this->strip_prefix($ancestor, $html);
            // The file builder reads files[] before href, so a resource that
            // lists an asset ahead of the HTML would otherwise serve that asset's
            // bytes under the HTML's name. Mirror fold_lesson_bundles(): make href
            // the HTML and bring it to the front of the file list.
            $item->href = $html;
            if (!empty($item->files)) {
                $reordered = [$html];
                foreach ($item->files as $file) {
                    if (strcasecmp($file, $html) !== 0) {
                        $reordered[] = $file;
                    }
                }
                $item->files = $reordered;
            }
        }
    }

    /**
     * Collect the package files a self-contained HTML file pulls in: assets it
     * references directly (script/img/media/link, plus srcset variants and inline
     * CSS) and, following stylesheets to a fixpoint, the url()/@import assets
     * those reference. Returns a set of package-relative source paths (the keys);
     * the filearea layout is decided later from their common ancestor.
     *
     * A document <base href> shifts the folder the page's own relative references
     * (elements and inline CSS) resolve against; it is honoured when it is a local
     * package folder. An absolute/external/root-absolute base points those refs
     * outside the package, so they are left unfolded (the asset, if any, surfaces
     * as its own resource). url()/@import inside an external stylesheet always
     * resolve against that stylesheet's own folder, never the document base.
     *
     * @param string $htmlpath Package-relative path of the HTML file.
     * @param string $html The HTML content.
     * @return array Set of package paths (path => true).
     */
    private function collect_html_assets(string $htmlpath, string $html): array {
        $htmlfolder = $this->parent_dir($htmlpath);
        $sources = [];
        $cssqueue = [];
        $htmlbase = $this->html_base_folder($htmlfolder, $this->html_base_href($html));
        // A null base means the document's relative refs resolve outside the
        // package (absolute/external/root base): skip the element and inline-CSS
        // refs entirely rather than fold the wrong files.
        foreach ($htmlbase === null ? [] : $this->html_asset_refs($html) as $ref) {
            $source = $this->record_referenced_asset($sources, $htmlbase, $ref);
            if ($source !== null && preg_match('/\.css$/i', $source)) {
                $cssqueue[] = $source;
            }
        }
        // Stylesheets pull in their own url()/@import assets (fonts, images, more
        // stylesheets); follow them to a fixpoint so an imported theme's assets
        // are folded too, resolving each ref against the stylesheet's own folder.
        $scanned = [];
        while ($cssqueue) {
            $css = array_shift($cssqueue);
            if (isset($scanned[$css])) {
                continue;
            }
            $scanned[$css] = true;
            $cssabs = $this->resolve_within($css);
            if ($cssabs === null) {
                continue;
            }
            $cssfolder = $this->parent_dir($css);
            foreach ($this->css_asset_refs((string) @file_get_contents($cssabs)) as $ref) {
                $source = $this->record_referenced_asset($sources, $cssfolder, $ref);
                if ($source !== null && preg_match('/\.css$/i', $source)) {
                    $cssqueue[] = $source;
                }
            }
        }
        return $sources;
    }

    /**
     * Read the href of the document's first href-bearing <base> element,
     * query/fragment stripped. Browsers use the first <base> that *has* an href
     * attribute — even an empty one, which leaves resolution at the document's
     * own folder — and ignore any later base, so the search stops there. '' when
     * the document declares no href-bearing base (or that href is empty).
     *
     * @param string $html The HTML content.
     * @return string The base href, or ''.
     */
    private function html_base_href(string $html): string {
        if (trim($html) === '') {
            return '';
        }
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html,
            LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        foreach ($dom->getElementsByTagName('base') as $node) {
            if ($node instanceof DOMElement && $node->hasAttribute('href')) {
                return (string) preg_replace('/[?#].*$/', '', trim($node->getAttribute('href')));
            }
        }
        return '';
    }

    /**
     * Resolve the package folder that a document's relative references resolve
     * against, given its folder and any <base href>. Returns the HTML's own
     * folder when there is no base; the base's directory (a trailing-slash or
     * dot-segment base is itself a directory, otherwise its last segment is the
     * filename and is dropped after the path is normalised) when the base is a
     * local relative path; and null when the base is absolute, external,
     * root-absolute or escapes the package — cases whose relative refs point
     * outside the package and so cannot be folded.
     *
     * @param string $htmlfolder Package folder of the HTML file ('' at root).
     * @param string $base The document's <base href>, '' when none.
     * @return string|null The resolution folder, or null when refs are non-local.
     */
    private function html_base_folder(string $htmlfolder, string $base): ?string {
        if ($base === '') {
            return $htmlfolder;
        }
        $base = rawurldecode($base);
        if (preg_match('~^([a-z][a-z0-9+.\-]*:|//|/)~i', $base)) {
            return null;
        }
        $combined = $htmlfolder === '' ? $base : $htmlfolder . '/' . $base;
        // A base denotes a directory when it ends in '/' or its last segment is a
        // dot-segment (. or ..); its children resolve against the whole
        // normalised path. Otherwise the last segment is a filename to drop — but
        // only after normalising, so a base like '..' is resolved as a path
        // operation, not mistaken for a file literally named '..'.
        $segments = explode('/', $base);
        $lastsegment = (string) end($segments);
        $isdir = substr($base, -1) === '/' || $lastsegment === '.' || $lastsegment === '..';
        $normalised = $this->collapse_dots($combined);
        if ($normalised === null) {
            return null;
        }
        return $isdir ? $normalised : $this->parent_dir($normalised);
    }

    /**
     * Record one referenced asset if it is a foldable local file, returning the
     * resolved package path (so the caller can follow stylesheets) or null. The
     * reference is resolved relative to $within (the package folder of the file
     * that named it). It may climb into a sibling folder; it is rejected only if
     * it escapes the package root. External URLs, data URIs, absolute paths,
     * in-page anchors and HTML references (links, not embeds) are ignored.
     *
     * @param array $sources Collected package paths (path => true), modified in place.
     * @param string $within Package folder the ref resolves against ('' at root).
     * @param string $ref The raw reference value.
     * @return string|null The resolved package path, or null when not foldable.
     */
    private function record_referenced_asset(array &$sources, string $within, string $ref): ?string {
        $ref = (string) preg_replace('/[?#].*$/', '', trim($ref));
        if ($ref === '' || preg_match('~^([a-z][a-z0-9+.\-]*:|//|/|#)~i', $ref)) {
            return null;
        }
        $source = $this->collapse_dots(($within === '' ? '' : $within . '/') . rawurldecode($ref));
        if ($source === null || $source === '' || preg_match('/\.html?$/i', $source)) {
            return null;
        }
        if (!isset($sources[$source]) && $this->resolve_within($source) === null) {
            return null;
        }
        $sources[$source] = true;
        return $source;
    }

    /**
     * Extract embedded-asset references from HTML: script src, stylesheet/link
     * href, media src/poster/data, the URLs listed in img/source srcset, and the
     * url()/@import references in inline <style> blocks and style="" attributes.
     * Navigational <a href> links are deliberately excluded — they are links to
     * follow, not assets to inline.
     *
     * @param string $html The HTML.
     * @return array Raw reference strings.
     */
    private function html_asset_refs(string $html): array {
        if (trim($html) === '') {
            return [];
        }
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html,
            LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $refs = [];
        $tags = ['script' => 'src', 'img' => 'src', 'source' => 'src', 'track' => 'src',
            'audio' => 'src', 'video' => 'src', 'embed' => 'src', 'object' => 'data', 'link' => 'href'];
        foreach ($tags as $tag => $attr) {
            foreach ($dom->getElementsByTagName($tag) as $node) {
                if (!($node instanceof DOMElement)) {
                    continue;
                }
                if ($node->getAttribute($attr) !== '') {
                    $refs[] = $node->getAttribute($attr);
                }
                if ($tag === 'video' && $node->getAttribute('poster') !== '') {
                    $refs[] = $node->getAttribute('poster');
                }
                if (($tag === 'img' || $tag === 'source') && $node->getAttribute('srcset') !== '') {
                    $refs = array_merge($refs, $this->srcset_urls($node->getAttribute('srcset')));
                }
            }
        }
        // Assets named only by inline CSS: <style> blocks and style="" attributes.
        foreach ($dom->getElementsByTagName('style') as $node) {
            $refs = array_merge($refs, $this->css_asset_refs($node->textContent));
        }
        foreach ($dom->getElementsByTagName('*') as $node) {
            if ($node instanceof DOMElement && $node->getAttribute('style') !== '') {
                $refs = array_merge($refs, $this->css_asset_refs($node->getAttribute('style')));
            }
        }
        return $refs;
    }

    /**
     * Extract the candidate URLs from a srcset attribute, dropping the optional
     * width/density descriptor after each URL ("a.png 1x, b.png 2x").
     *
     * @param string $srcset The srcset attribute value.
     * @return array URL strings.
     */
    private function srcset_urls(string $srcset): array {
        $urls = [];
        foreach (explode(',', $srcset) as $candidate) {
            $parts = preg_split('/\s+/', trim($candidate), -1, PREG_SPLIT_NO_EMPTY);
            if (!empty($parts)) {
                $urls[] = $parts[0];
            }
        }
        return $urls;
    }

    /**
     * Extract url(...) and @import references from a stylesheet.
     *
     * @param string $css The CSS.
     * @return array Raw reference strings.
     */
    private function css_asset_refs(string $css): array {
        $refs = [];
        if (preg_match_all('/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', $css, $matches)) {
            $refs = array_merge($refs, $matches[1]);
        }
        // The bare @import "theme.css"; form, the one without a url() wrapper.
        if (preg_match_all('/@import\s+[\'"]([^\'"]+)[\'"]/i', $css, $matches)) {
            $refs = array_merge($refs, $matches[1]);
        }
        return $refs;
    }

    /**
     * The deepest folder that contains every given package path (their common
     * ancestor directory), '' when they share no leading folder. Used to root a
     * bundle's filearea so the HTML and a sibling-folder asset both fit beneath
     * it and the HTML's relative references still resolve.
     *
     * @param array $paths Package-relative file paths.
     * @return string The common ancestor folder, without trailing slash.
     */
    private function common_ancestor(array $paths): string {
        $common = null;
        foreach ($paths as $path) {
            $folder = $this->parent_dir($path);
            $segments = $folder === '' ? [] : explode('/', $folder);
            if ($common === null) {
                $common = $segments;
                continue;
            }
            $limit = min(count($common), count($segments));
            $shared = 0;
            while ($shared < $limit && $common[$shared] === $segments[$shared]) {
                $shared++;
            }
            $common = array_slice($common, 0, $shared);
        }
        return empty($common) ? '' : implode('/', $common);
    }

    /**
     * Strip a leading ancestor folder (as found by common_ancestor()) from a
     * package path, yielding its filearea-relative path.
     *
     * @param string $ancestor The common ancestor folder, '' for the package root.
     * @param string $path The package path.
     * @return string The path relative to the ancestor.
     */
    private function strip_prefix(string $ancestor, string $path): string {
        if ($ancestor !== '' && strpos($path, $ancestor . '/') === 0) {
            return substr($path, strlen($ancestor) + 1);
        }
        return ltrim($path, '/');
    }

    /**
     * Collapse '.' and '..' segments in a relative path, returning null if it
     * escapes above its root (which cannot be represented in a filearea).
     *
     * @param string $path The relative path.
     * @return string|null The normalised path, or null.
     */
    private function collapse_dots(string $path): ?string {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (empty($segments)) {
                    return null;
                }
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        return implode('/', $segments);
    }

    /**
     * The parent directory of a package-relative path ('' when at the root).
     *
     * @param string $path The path.
     * @return string
     */
    private function parent_dir(string $path): string {
        $pos = strrpos($path, '/');
        return $pos === false ? '' : substr($path, 0, $pos);
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
