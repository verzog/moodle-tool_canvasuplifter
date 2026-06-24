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
use DOMElement;
use stdClass;
use tool_canvasuplifter\local\model\item;

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

    /**
     * Constructor.
     *
     * @param string $packageroot Absolute path to the extracted package directory.
     */
    public function __construct(string $packageroot) {
        $this->packageroot = rtrim($packageroot, '/');
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

        $cartridge = $this->read_cartridge($modelitem);
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
        try {
            $created = add_moduleinfo($moduleinfo, $course);
        } catch (\Throwable $e) {
            // The mod_lti module re-fetches a tool URL it thinks is a cartridge
            // (one ending in .xml, or serving XML) and throws if that remote
            // document can't be read — common when the tool's host is gone. That
            // escapes add_moduleinfo() with its delegated transaction still open,
            // which would otherwise abort the whole adhoc task ("Task left
            // transaction open") and make Moodle retry it, building duplicate
            // courses. Roll the orphaned transaction back and skip just this tool
            // so the rest of the course still builds.
            if ($DB->is_transaction_started()) {
                $DB->force_transaction_rollback();
            }
            $this->skipreason = 'external tool could not be created (' . $e->getMessage() . ')';
            return null;
        }
        return (int) $created->coursemodule;
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
     * Parse a Common Cartridge LTI XML document. Static and Moodle-free so it
     * can be unit-tested directly from XML strings.
     *
     * @param string $xml The cartridge XML.
     * @return array|null ['title','launchurl','secureurl','description','custom'] or null.
     */
    public static function parse_cartridge_xml(string $xml): ?array {
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
        // A cartridge with no usable http(s) URL is not a safe placeholder.
        // Reject javascript:, data:, file:, app-internal schemes and the like
        // so a malformed or malicious package can't create an active LTI
        // endpoint that students could later launch.
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
     * Validate a candidate launch URL: only http and https are accepted, so
     * javascript:, data:, file:, mailto: and other dangerous or unusable
     * schemes never reach mod_lti as a tool endpoint.
     *
     * @param string $url Trimmed candidate URL.
     * @return string The URL if it's http(s), or the empty string.
     */
    private static function sanitise_url(string $url): string {
        return preg_match('#^https?://#i', $url) === 1 ? $url : '';
    }

    /**
     * Read the cartridge's <blti:custom> parameters. Many deep-linked
     * publisher tools encode the resource id (or which Canvas assignment the
     * link points at) in these parameters, so dropping them would leave the
     * imported activity pointing at the tool's generic endpoint.
     *
     * @param DOMDocument $dom The parsed cartridge.
     * @return array<string, string> Map of parameter name -> value, in document order.
     */
    private static function read_custom_parameters(DOMDocument $dom): array {
        $params = [];
        foreach ($dom->getElementsByTagNameNS('*', 'property') as $node) {
            if (!($node instanceof DOMElement)) {
                continue;
            }
            // Custom parameters live inside <blti:custom>; ignore <lticm:property>
            // elements elsewhere (e.g. inside <blti:extensions>) so platform
            // extensions don't leak into mod_lti's instructor parameters.
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
     * Return the trimmed text of the first element in the document with the
     * given local name, regardless of namespace prefix.
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
        // looking at the activity knows the tool still needs credentials.
        $description = trim($cartridge['description']);
        $note = get_string('lti_placeholder_note', 'tool_canvasuplifter');
        $intro = $description !== ''
            ? '<p>' . s($description) . '</p><p><em>' . s($note) . '</em></p>'
            : '<p><em>' . s($note) . '</em></p>';

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
