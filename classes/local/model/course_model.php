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

    /** @var section_model[] Ordered list of sections. */
    public array $sections = [];

    /** @var item[] Items not attached to any section. */
    public array $orphans = [];

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
