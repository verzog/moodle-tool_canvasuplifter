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
use tool_canvasuplifter\local\parser\lti_cartridge;
use tool_canvasuplifter\local\parser\lti_classifier;

/**
 * Creates a mod_lti placeholder from a Common Cartridge LTI link.
 *
 * Canvas exports LTI tool links as imsbasiclti cartridges
 * (<cartridge_basiclti_link>). They carry a launch URL and title but never
 * carry the consumer key or shared secret — those are site-level credentials
 * the administrator must reapply per Moodle install. This builder creates a
 * mod_lti instance with the launch URL filled in and an intro note flagging
 * that credentials still need to be configured.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lti_builder {
    /** @var string Absolute path to the extracted package root. */
    private string $packageroot;

    /** @var string|null Why the last build returned null, for course_builder's skip report. */
    public ?string $skipreason = null;

    /** @var int External tools recognised as Moodle STACK questions delivered over LTI. */
    public int $stackcount = 0;

    /** @var int External tools recognised as other Moodle-hosted content delivered over LTI. */
    public int $moodlehostedcount = 0;

    /** @var array|null Lazily-read site patterns for the LTI classifier (see lti_classifier). */
    private ?array $classifierpatterns = null;

    /** @var string|null Absolute path the last launch_instructions() read the intro HTML from,
     * or null for inline instructions - the intro's owner directory is derived from it. */
    private ?string $instructionssource = null;

    /** @var media_report|null Shared collector for unresolved media references. */
    private ?media_report $mediareport;

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
     * Create a mod_lti activity in the given section.
     *
     * @param stdClass $course Course record.
     * @param int $sectionnum 0-indexed section number.
     * @param item $modelitem The LTI item from the parsed model.
     * @return int|null Created course module id, or null if the cartridge could not be read.
     */
    public function build(stdClass $course, int $sectionnum, item $modelitem): ?int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/lti/locallib.php');

        // An item may carry an inline launch URL (a Canvas ContextExternalTool
        // placed in a module, or an external-tool assignment) rather than a
        // cartridge XML file; prefer that when present, else read the cartridge.
        $cartridge = $modelitem->launchurl !== ''
            ? self::cartridge_from_launchurl(
                $modelitem->launchurl,
                $modelitem->title,
                $this->launch_instructions($modelitem),
                $modelitem->launchsecureurl
            )
            : $this->read_cartridge($modelitem);
        // Custom parameters recovered from a Canvas external-tool assignment's twin cartridge.
        if ($cartridge !== null && $modelitem->launchcustom !== [] && ($cartridge['custom'] ?? []) === []) {
            $cartridge['custom'] = $modelitem->launchcustom;
        }
        if ($cartridge === null || $cartridge['launchurl'] === '') {
            return null;
        }

        $module = $DB->get_record('modules', ['name' => 'lti']);
        if (!$module) {
            return null;
        }

        $name = $modelitem->title !== ''
            ? $modelitem->title
            : ($cartridge['title'] !== '' ? $cartridge['title'] : 'External tool');

        $moduleinfo = $this->moduleinfo($course, $sectionnum, (int) $module->id, $name, $cartridge);
        // Remember whether a transaction was already open before we call in: if
        // one was, it belongs to the caller and we must not touch it.
        $callerintransaction = $DB->is_transaction_started();
        try {
            $created = add_moduleinfo($moduleinfo, $course);
        } catch (\Throwable $e) {
            // The mod_lti module re-fetches a tool URL it thinks is a cartridge
            // (one ending in .xml, or serving XML) and throws if that remote
            // document can't be read — common when the tool's host is gone. That
            // escapes add_moduleinfo() with its delegated transaction still open,
            // which would otherwise abort the whole adhoc task ("Task left
            // transaction open") and make Moodle retry it, building duplicate
            // courses.
            //
            // Only clean up the transaction add_moduleinfo() opened. If the
            // caller already had one of its own, force_transaction_rollback()
            // would silently discard the caller's earlier writes too, so in that
            // case re-throw and let the caller's own transaction handling deal
            // with it (the course builder calls us with no outer transaction).
            if ($callerintransaction) {
                throw $e;
            }
            if ($DB->is_transaction_started()) {
                $DB->force_transaction_rollback();
            }
            $this->skipreason = 'external tool could not be created (' . $e->getMessage() . ')';
            return null;
        }
        // When the intro carries assignment instructions with package-relative
        // images, import them and rewrite the intro to pluginfile refs (mirroring
        // assign_builder), so a re-homed external-tool assignment's prompt renders.
        if (($cartridge['descriptionhtml'] ?? '') !== '') {
            $context = \context_module::instance((int) $created->coursemodule);
            $ownerdir = $this->intro_owner_dir($modelitem, $this->instructionssource);
            $newintro = (new file_embedder($this->packageroot, $this->mediareport))
                ->embed($context->id, 'mod_lti', 'intro', $moduleinfo->intro, 0, $ownerdir);
            if ($newintro !== $moduleinfo->intro) {
                $DB->set_field('lti', 'intro', $newintro, ['id' => (int) $created->instance]);
            }
        }
        // Flag a link that actually launches Moodle-hosted content (e.g. a STACK question
        // delivered over LTI) so the conversion report can point the migrator at it. The
        // activity itself is built as a normal placeholder either way.
        // Classify against both the activity name and the cartridge's own <blti:title>: a Canvas
        // item renamed by an instructor overwrites the model title, which would otherwise hide a
        // cartridge titled e.g. "STACK Question".
        $kind = lti_classifier::classify(
            $cartridge['launchurl'],
            $cartridge['secureurl'] ?? '',
            trim($name . ' ' . ($cartridge['title'] ?? '')),
            $cartridge['custom'] ?? [],
            $this->classifier_patterns()
        );
        if ($kind === lti_classifier::KIND_STACK) {
            $this->stackcount++;
        } else if ($kind === lti_classifier::KIND_MOODLE) {
            $this->moodlehostedcount++;
        }
        return (int) $created->coursemodule;
    }

    /**
     * The site-configured LTI classifier patterns, read once. Empty when the setting is unset.
     *
     * @return array Patterns as returned by {@see lti_classifier::parse_patterns()}.
     */
    private function classifier_patterns(): array {
        if ($this->classifierpatterns === null) {
            $this->classifierpatterns = lti_classifier::parse_patterns(
                (string) get_config('tool_canvasuplifter', 'ltimoodlepatterns')
            );
        }
        return $this->classifierpatterns;
    }

    /**
     * The package-relative folder the intro's instructions media resolves against
     * - the folder of the file the instructions actually came from - so a
     * $IMS-CC-FILEBASE$name reference, or a ../ climb into a sibling resource
     * folder, embeds instead of being left as a broken placeholder.
     *
     * @param item $modelitem The LTI item.
     * @param string|null $introsource Absolute path the instructions were read from, if any.
     * @return string Package-relative folder ('' at the package root).
     */
    private function intro_owner_dir(item $modelitem, ?string $introsource): string {
        if ($introsource !== null) {
            return safe_path::package_dir($this->packageroot, $introsource);
        }
        // Inline instructions (CC 1.3 <text>): resolve against the profile file's
        // folder recorded when the assignment was re-homed, else the resource's own.
        if ($modelitem->launchdescriptiondir !== '') {
            return $modelitem->launchdescriptiondir;
        }
        $source = $modelitem->href !== '' ? $modelitem->href : (string) ($modelitem->files[0] ?? '');
        $dir = trim(str_replace('\\', '/', dirname($source)), '/');
        return ($dir === '' || $dir === '.') ? '' : $dir;
    }

    /**
     * The instructions HTML to show on a re-homed external-tool assignment's LTI
     * placeholder: the CC 1.3 <text> carried on the model, or a sibling HTML file
     * for a flat Canvas assignment. Empty for a plain LTI link (no assignment
     * prompt to preserve).
     *
     * @param item $modelitem The LTI item.
     * @return string Instructions HTML, or ''.
     */
    private function launch_instructions(item $modelitem): string {
        // Reset the recorded source; an inline description resolves media against
        // the resource's own folder, an HTML sibling against its own folder.
        $this->instructionssource = null;
        if ($modelitem->launchdescription !== '') {
            return $modelitem->launchdescription;
        }
        $candidates = $modelitem->files;
        if ($modelitem->href !== '') {
            $candidates[] = $modelitem->href;
        }
        foreach ($candidates as $relative) {
            if (!preg_match('/\.html?$/i', (string) $relative)) {
                continue;
            }
            $absolute = safe_path::within($this->packageroot, (string) $relative);
            if ($absolute !== null && is_readable($absolute)) {
                $this->instructionssource = $absolute;
                return (string) @file_get_contents($absolute);
            }
        }
        return '';
    }

    /**
     * Read launch URL, secure URL and title out of the LTI cartridge XML.
     *
     * Common Cartridge wraps these in a default namespace plus a blti: prefix
     * (e.g. <blti:launch_url>), so this walks the DOM namespace-agnostically.
     *
     * @param item $modelitem The LTI item.
     * @return array|null ['title','launchurl','secureurl','description'] or null.
     */
    private function read_cartridge(item $modelitem): ?array {
        $candidates = $modelitem->files;
        if ($modelitem->href !== '') {
            $candidates[] = $modelitem->href;
        }
        foreach ($candidates as $relative) {
            if (!preg_match('/\.xml$/i', $relative)) {
                continue;
            }
            $absolute = safe_path::within($this->packageroot, $relative);
            if ($absolute === null || !is_readable($absolute)) {
                continue;
            }
            $cartridge = self::parse_cartridge_xml((string) @file_get_contents($absolute));
            if ($cartridge !== null) {
                return $cartridge;
            }
        }
        return null;
    }

    /**
     * Build a cartridge array from an inline launch URL (no cartridge file), for
     * items that carry only a URL: a Canvas ContextExternalTool placed in a
     * module or an external-tool assignment. Only http(s) URLs are accepted, so a
     * javascript:/data:/file: scheme never reaches mod_lti as a tool endpoint.
     *
     * @param string $launchurl The inline launch URL.
     * @param string $title The item title (used as the cartridge title).
     * @param string $descriptionhtml Instructions HTML to show in the intro (or '').
     * @param string $secureurl A secure launch URL recovered from a twin cartridge (or '').
     * @return array|null Cartridge fields, or null when the URL is not http(s).
     */
    private static function cartridge_from_launchurl(
        string $launchurl,
        string $title,
        string $descriptionhtml = '',
        string $secureurl = ''
    ): ?array {
        $launchurl = lti_cartridge::sanitise_url($launchurl);
        if ($launchurl === '') {
            return null;
        }
        return [
            'title' => trim($title),
            'launchurl' => $launchurl,
            'secureurl' => lti_cartridge::sanitise_url($secureurl),
            'description' => '',
            'descriptionhtml' => trim($descriptionhtml),
            'custom' => [],
        ];
    }

    /**
     * Parse a Common Cartridge LTI XML document. Delegates to the Moodle-free
     * {@see lti_cartridge::parse()} (kept here for callers and tests that read cartridge fields
     * through the builder). A cartridge carries only a plain-text <description>; the raw-HTML
     * instructions slot ('descriptionhtml') is used by the re-homed-assignment path, so it is
     * added empty here.
     *
     * @param string $xml The cartridge XML.
     * @return array|null ['title','launchurl','secureurl','description','descriptionhtml','custom']
     *         or null when the document has no usable http(s) launch URL.
     */
    public static function parse_cartridge_xml(string $xml): ?array {
        $cartridge = lti_cartridge::parse($xml);
        if ($cartridge === null) {
            return null;
        }
        $cartridge['descriptionhtml'] = '';
        return $cartridge;
    }

    /**
     * Assemble the moduleinfo for add_moduleinfo(), mirroring the mod_lti
     * defaults for a tool defined per-activity (typeid=0). Credentials are
     * left blank — the admin must set the consumer key and shared secret on
     * the Moodle side.
     *
     * @param stdClass $course Course record.
     * @param int $sectionnum Section number.
     * @param int $moduleid The lti entry in the modules table.
     * @param string $name Activity name.
     * @param array $cartridge Parsed cartridge fields.
     * @return stdClass
     */
    private function moduleinfo(
        stdClass $course,
        int $sectionnum,
        int $moduleid,
        string $name,
        array $cartridge
    ): stdClass {
        // Surface the imported description and a per-site reminder so an admin
        // looking at the activity knows the tool still needs credentials. A
        // re-homed assignment supplies raw instructions HTML; a cartridge supplies
        // only a plain-text description, which is escaped.
        $note = get_string('lti_placeholder_note', 'tool_canvasuplifter');
        $descriptionhtml = trim($cartridge['descriptionhtml'] ?? '');
        if ($descriptionhtml === '') {
            $plain = trim($cartridge['description']);
            $descriptionhtml = $plain !== '' ? '<p>' . s($plain) . '</p>' : '';
        }
        $intro = $descriptionhtml . '<p><em>' . s($note) . '</em></p>';

        return (object) [
            'modulename' => 'lti',
            'module' => $moduleid,
            'course' => $course->id,
            'section' => $sectionnum,
            // Always start hidden: when a site has a preconfigured LTI tool
            // whose base URL matches the cartridge launch URL, Moodle's
            // lti_force_type_config_settings() will overwrite the
            // LTI_SETTING_NEVER privacy fields below as soon as the activity
            // is launched. Hiding the cm keeps it inert until an admin
            // reviews the tool config and explicitly unhides it.
            'visible' => 0,
            'cmidnumber' => '',
            'name' => shorten_text($name, 255),
            'intro' => $intro,
            'introformat' => FORMAT_HTML,
            // A typeid of 0 means "use the per-activity tool URL"; the admin
            // can later swap it for a preconfigured tool from the LTI registry.
            'typeid' => 0,
            'toolurl' => $cartridge['launchurl'],
            'securetoolurl' => $cartridge['secureurl'],
            // No credentials shipped with the cartridge.
            'resourcekey' => '',
            'password' => '',
            'instructorcustomparameters' => self::format_custom_parameters($cartridge['custom'] ?? []),
            'icon' => '',
            'secureicon' => '',
            'launchcontainer' => LTI_LAUNCH_CONTAINER_DEFAULT,
            'showtitlelaunch' => 1,
            'showdescriptionlaunch' => 0,
            // Conservative defaults: don't send PII or accept grades until
            // an admin reviews the tool. Note these may be overridden by a
            // URL-matched preconfigured tool; the visible=0 above keeps the
            // activity inert until that review happens.
            'instructorchoicesendname' => LTI_SETTING_NEVER,
            'instructorchoicesendemailaddr' => LTI_SETTING_NEVER,
            'instructorchoiceacceptgrades' => LTI_SETTING_NEVER,
            'instructorchoiceallowroster' => LTI_SETTING_NEVER,
            'grade' => 0,
        ];
    }

    /**
     * Render the cartridge's custom parameters in mod_lti's "newline-separated
     * key=value" format. Empty when the cartridge has none.
     *
     * @param array $params Parameter map (string => string).
     * @return string
     */
    private static function format_custom_parameters(array $params): string {
        if (empty($params)) {
            return '';
        }
        $lines = [];
        foreach ($params as $name => $value) {
            $lines[] = $name . '=' . $value;
        }
        return implode("\n", $lines);
    }
}
