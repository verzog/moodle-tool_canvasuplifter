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

namespace tool_canvasuplifter\local\model;

/**
 * One piece of course content found in a Canvas package.
 *
 * This is a plain data holder with no Moodle dependencies, so it can be
 * built and tested in isolation. Nothing here touches the database.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class item {
    /** Page (Canvas wiki page) -> mod_page. */
    public const KIND_PAGE = 'page';
    /** File / web resource -> course files / mod_resource. */
    public const KIND_FILE = 'file';
    /** Web link -> mod_url. */
    public const KIND_URL = 'url';
    /** Assignment -> mod_assign. */
    public const KIND_ASSIGNMENT = 'assignment';
    /** Quiz / assessment -> mod_quiz. */
    public const KIND_QUIZ = 'quiz';
    /** Question bank -> mod_qbank. */
    public const KIND_QUESTIONBANK = 'questionbank';
    /** Discussion topic -> mod_forum. */
    public const KIND_DISCUSSION = 'discussion';
    /** External (LTI) tool -> mod_lti. */
    public const KIND_LTI = 'lti';
    /** Canvas ContextModuleSubHeader (a label inside a module) -> mod_label. */
    public const KIND_SUBHEADER = 'subheader';
    /** Could not be classified. */
    public const KIND_UNKNOWN = 'unknown';

    /**
     * @var string[] Item kinds course_builder can create in the current phase.
     *               Defined here on the model so the Moodle-free report layer can
     *               consult it without depending on the build layer.
     */
    public const BUILDS_NOW = [
        self::KIND_PAGE, self::KIND_URL, self::KIND_FILE, self::KIND_ASSIGNMENT,
        self::KIND_QUIZ, self::KIND_QUESTIONBANK, self::KIND_DISCUSSION,
        self::KIND_SUBHEADER, self::KIND_LTI,
    ];

    /** @var string Canvas resource identifier. */
    public string $identifier = '';

    /** @var string Human-readable title. */
    public string $title = '';

    /** @var string Detected kind, one of the KIND_* constants. */
    public string $kind = self::KIND_UNKNOWN;

    /** @var string Raw Common Cartridge resource type string. */
    public string $resourcetype = '';

    /** @var string Canvas "intendeduse" hint, e.g. "syllabus" or "assignment". */
    public string $intendeduse = '';

    /** @var string Primary file path within the package, if any. */
    public string $href = '';

    /** @var string[] All file paths belonging to this resource. */
    public array $files = [];

    /** @var string[] Identifiers of resources this one declares as a Common Cartridge dependency
     * (embedded assets: question-stem images, discussion media). Their media is embedded into
     * this resource's content at build time, so an unplaced dependency target is not surfaced
     * as a standalone "Additional resources" download. */
    public array $dependencies = [];

    /** @var bool Whether the item is published (visible to students) in Canvas. */
    public bool $isvisible = true;

    /** @var bool Whether a discussion is actually a Canvas announcement (topicMeta type="announcement"). */
    public bool $isannouncement = false;

    /** @var string Inline URL for items that carry it (e.g. Canvas ExternalUrl). */
    public string $url = '';

    /** @var string For KIND_LTI items with an inline launch URL (no cartridge file), e.g. a Canvas
     *              ContextExternalTool or an external-tool assignment. When set, lti_builder uses it
     *              directly instead of reading a cartridge XML. */
    public string $launchurl = '';

    /** @var string For an external-tool assignment re-homed to KIND_LTI: its instructions HTML (the
     *              CC 1.3 <text>), so the launch placeholder keeps the assignment prompt. Empty for a
     *              flat Canvas assignment, whose instructions lti_builder reads from the sibling HTML. */
    public string $launchdescription = '';

    /** @var string Package-relative folder of the profile/settings file $launchdescription was read from,
     *              so its owner-relative media resolves against that folder rather than the resource href. */
    public string $launchdescriptiondir = '';

    /** @var string For assignments: Canvas <assignment_group_identifierref>. */
    public string $gradegroupref = '';

    /** @var string For assignments: Canvas <rubric_identifierref>, the rubric library id. */
    public string $rubricref = '';

    /** @var bool For assignments: whether the linked rubric is used to compute the grade. */
    public bool $rubricforgrading = true;

    /**
     * @var array For bundle-promoted pages (eXe/IGEN lessons): sibling files
     *            to import into the page's filearea so relative refs in the
     *            HTML keep working. Each entry: ['source','relpath'] where
     *            source is package-relative and relpath is relative to the
     *            filearea root (the bundle's common-ancestor folder).
     */
    public array $bundleassets = [];

    /**
     * @var string For a folded HTML bundle, the filearea path the main HTML
     *             file is stored at (relative to the bundle's common-ancestor
     *             root). Usually just the basename, but set to a subfolder path
     *             when the bundle was rebased so a parent-directory asset
     *             reference resolves. Empty for a plain (non-bundle) file.
     */
    public string $bundlehtmlpath = '';

    /**
     * @var bool True when fold_lesson_bundles() demoted this item as a sibling
     *           of a lesson bundle. Distinguishes "asset folded into another
     *           page" from "genuinely unclassified resource" so the report
     *           still surfaces real unknowns in sections.
     */
    public bool $bundlemember = false;

    /**
     * @var bool True when fold_html_asset_bundles() folded this resource's
     *           payload into an HTML exercise. Unlike $bundlemember the decision
     *           to hide it is deferred: it is suppressed only if the orphan pass
     *           finds it was not also placed in the course, so an asset that is
     *           explicitly published as its own activity still builds.
     */
    public bool $htmlbundlemember = false;

    /**
     * @var bool True when the manifest parser deliberately classified this
     *           resource as KIND_UNKNOWN to skip it (quiz/ assets under a
     *           QTI resource, learning-application metadata files without
     *           an HTML payload). Distinguished from genuinely unsupported
     *           resource types so the section path can keep suppressing
     *           the former while still surfacing the latter.
     */
    public bool $suppressed = false;

    /**
     * @var string For assignments: serialized CC 1.3 IMS Assignment profile
     *             XML when the manifest embeds the descriptor inline inside
     *             the <resource> instead of pointing at it via <file>. The
     *             assign_builder feeds this directly to assignment_settings
     *             when no on-disk settings XML is locatable.
     */
    public string $inlinexml = '';

    /**
     * @var string CC variant <variant identifierref="..."> target. CC 1.3
     *             cartridges may point the organization tree at a fallback
     *             resource (e.g. a plain webcontent HTML) and put the
     *             preferred resource (e.g. an assignment_xmlv1p0) behind a
     *             variant. Captured so the section attach can swap to the
     *             preferred target when it's a richer buildable kind.
     */
    public string $variantref = '';

    /**
     * @var string[] Extra Canvas identifiers under which this item should be
     *               recorded in course_builder's URL map. Populated when a
     *               variant swap redirects a fallback resource to its
     *               preferred target: any $CANVAS_OBJECT_REFERENCE$ link in
     *               the package may still address the fallback identifier
     *               (the one the organisation tree references) rather than
     *               the preferred one we actually built.
     */
    public array $aliasids = [];

    /**
     * @var string[] Package-relative source paths of variant fallback resources
     *               this (preferred) item stands in for. A page that links by
     *               relative path to the fallback's HTML must resolve to this
     *               item's activity, just as $aliasids does for object-reference
     *               links. Populated by the manifest parser's variant swap.
     */
    public array $aliaspaths = [];

    /**
     * @var string Alternative activity name used when this item builds as a
     *             mod_qbank (always for KIND_QUESTIONBANK, and for orphan
     *             KIND_QUIZ items that course_builder converts to banks).
     *             Set by the manifest_parser's disambiguation pass when the
     *             item shares its title with another item; left empty
     *             otherwise. Kept separate from $title so the runnable quiz
     *             that the quiz_from_bank toggle builds from the same model
     *             item keeps the unsuffixed name.
     */
    public string $banktitle = '';

    /**
     * @var string For a standalone Canvas item bank (a learning-application-resource
     *             whose file is a non_cc_assessments/<id>.xml.qti objectbank), the bank
     *             id — its file basename without the .xml.qti suffix. Equals a New Quiz's
     *             sourcebank_ref, so the shared item_bank_registry imports the bank once
     *             whether reached from this resource or a quiz draw. Empty for anything
     *             else.
     */
    public string $objectbankid = '';

    /**
     * @var string For a standalone Canvas item bank, the exact package-relative path of the
     *             objectbank dump that classification matched (e.g. non_cc_assessments/
     *             pool.xml.qti, or a nested/case-varied path). Threaded to the builder and
     *             report so they resolve the same physical file rather than reconstructing a
     *             root-level path from the id. Empty for anything else.
     */
    public string $objectbankpath = '';

    /**
     * Constructor.
     *
     * @param string $identifier Canvas resource identifier.
     * @param string $title Human-readable title.
     */
    public function __construct(string $identifier = '', string $title = '') {
        $this->identifier = $identifier;
        $this->title = $title;
    }

    /**
     * Whether this item is the Canvas course syllabus page.
     *
     * Canvas marks it authoritatively with intendeduse="syllabus"; we also fall
     * back to a "syllabus" hint in the identifier/href/files for exporters that
     * omit it.
     *
     * @return bool
     */
    public function is_syllabus(): bool {
        if ($this->kind !== self::KIND_PAGE) {
            return false;
        }
        if ($this->intendeduse === 'syllabus') {
            return true;
        }
        $haystacks = array_merge([$this->identifier, $this->href], $this->files);
        foreach ($haystacks as $haystack) {
            if ($haystack !== '' && stripos($haystack, 'syllabus') !== false) {
                return true;
            }
        }
        return false;
    }
}
