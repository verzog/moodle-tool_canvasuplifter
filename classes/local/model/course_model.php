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
 * The whole course, as read from a Canvas package.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_model {
    /** @var string Course full name. */
    public string $fullname = '';

    /** @var string Course short name. */
    public string $shortname = '';

    /**
     * @var string Detected authoring/export system, one of the
     *             source_detector constants (e.g. 'canvas', 'angel'); '' when
     *             detection has not run.
     */
    public string $source = '';

    /** @var section_model[] Ordered list of sections. */
    public array $sections = [];

    /** @var item[] Items not attached to any section. */
    public array $orphans = [];

    /**
     * @var array<int, array{identifier: string, title: string, position: int, weight: float}>
     * Canvas assignment groups, ordered by position. Each entry maps to one
     * Moodle grade category. Empty when the package has no assignment_groups.xml.
     */
    public array $gradecategories = [];

    /**
     * @var string Canvas <group_weighting_scheme>: 'percent' enables weighted
     * categories; anything else means treat groups as plain categories.
     */
    public string $weightingscheme = '';

    /**
     * @var array<int, array{letter: string, lowerboundary: float}>
     * Canvas letter-grade scheme (grading_standards.xml), highest boundary
     * first, as percentages. Populated only when the course enables a grading
     * standard; empty otherwise. Each entry becomes one Moodle grade letter.
     */
    public array $gradeletters = [];

    /** @var int Count of Canvas platform-boilerplate resources dropped during parsing. */
    public int $canvasboilerplatedropped = 0;

    /**
     * @var int Count of Canvas course-navigation external tools (course_settings.xml
     * tab_configuration context_external_tool_ entries) that carry no importable
     * configuration in the package and are not already imported as a module item, so
     * the build report can flag them for the admin to add by hand. A nav tool that is
     * also placed as a module item (already a hidden mod_lti) is not counted.
     */
    public int $navtoolsunimported = 0;

    /**
     * @var array Canvas rubric library, keyed by Canvas identifier. Each value
     * is a hash with keys: title (string), free_form_comments (bool),
     * hide_score_total (bool), criteria (array of ['id','description','points',
     * 'levels'=>[['description','points'],...]]). Empty when the package has
     * no course_settings/rubrics.xml.
     */
    public array $rubrics = [];

    /**
     * Add a section.
     *
     * @param section_model $section The section to add.
     * @return void
     */
    public function add_section(section_model $section): void {
        $this->sections[] = $section;
    }

    /**
     * Return every item in the course, across all sections plus orphans.
     *
     * @return item[]
     */
    public function all_items(): array {
        $items = $this->orphans;
        foreach ($this->sections as $section) {
            $items = array_merge($items, $section->items);
        }
        return $items;
    }
}
