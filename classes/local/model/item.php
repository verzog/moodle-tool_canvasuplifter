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

    /** @var bool Whether the item is published (visible to students) in Canvas. */
    public bool $isvisible = true;

    /** @var string Inline URL for items that carry it (e.g. Canvas ExternalUrl). */
    public string $url = '';

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
