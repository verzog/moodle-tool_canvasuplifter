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
 * A section of the course (a Canvas "module").
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class section_model {
    /** @var string Section title. */
    public string $title = '';

    /** @var string The Canvas module identifier this section was built from (empty when the
     * section did not come from module_meta.xml), so prerequisites can reference it. */
    public string $canvasid = '';

    /** @var string[] Canvas module identifiers this module lists as prerequisites — the
     * learner must finish those modules before this one. Mapped to a Moodle availability
     * restriction on this section at build time. */
    public array $prerequisites = [];

    /** @var item[] Items contained in this section, in order. */
    public array $items = [];

    /** @var bool True when the parser discarded an item that carried a Canvas completion
     * requirement (its resource was missing/suppressed, or its type unsupported), so the item
     * never reaches the build. The completion pass treats such a module's prerequisite as
     * unresolved rather than gating dependents on the surviving activities alone. */
    public bool $droppedrequired = false;

    /**
     * Constructor.
     *
     * @param string $title Section title.
     */
    public function __construct(string $title = '') {
        $this->title = $title;
    }

    /**
     * Add an item to this section.
     *
     * @param item $item The item to add.
     * @return void
     */
    public function add_item(item $item): void {
        $this->items[] = $item;
    }
}
