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

namespace tool_canvasuplifter\form;

use moodleform;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Upload form for a Canvas .imscc package.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upload_form extends moodleform {
    /**
     * Define the form fields.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement(
            'filepicker',
            'packagefile',
            get_string('packagefile', 'tool_canvasuplifter'),
            null,
            ['accepted_types' => ['.imscc', '.zip']]
        );
        $mform->addHelpButton('packagefile', 'packagefile', 'tool_canvasuplifter');

        $mform->addElement(
            'text',
            'packageurl',
            get_string('packageurl', 'tool_canvasuplifter'),
            ['size' => 80, 'placeholder' => 'https://']
        );
        $mform->setType('packageurl', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('packageurl', 'packageurl', 'tool_canvasuplifter');

        $categories = \core_course_category::make_categories_list('moodle/course:create');
        $mform->addElement(
            'select',
            'categoryid',
            get_string('targetcategory', 'tool_canvasuplifter'),
            $categories
        );
        $mform->addHelpButton('categoryid', 'targetcategory', 'tool_canvasuplifter');

        $mform->addElement('advcheckbox', 'quizfrombank', get_string('quizfrombank', 'tool_canvasuplifter'));
        $mform->setDefault('quizfrombank', 0);
        $mform->addHelpButton('quizfrombank', 'quizfrombank', 'tool_canvasuplifter');

        $mform->addElement('select', 'pagegrouping', get_string('pagegrouping', 'tool_canvasuplifter'), [
            '' => get_string('pagegrouping_none', 'tool_canvasuplifter'),
            'book' => get_string('pagegrouping_book', 'tool_canvasuplifter'),
            'lesson' => get_string('pagegrouping_lesson', 'tool_canvasuplifter'),
        ]);
        $mform->setDefault('pagegrouping', '');
        $mform->setType('pagegrouping', PARAM_ALPHA);
        $mform->addHelpButton('pagegrouping', 'pagegrouping', 'tool_canvasuplifter');

        $buttons = [
            $mform->createElement('submit', 'analysebutton', get_string('analyse', 'tool_canvasuplifter')),
            $mform->createElement('submit', 'buildbutton', get_string('buildcourse', 'tool_canvasuplifter')),
            $mform->createElement('cancel'),
        ];
        $mform->addGroup($buttons, 'buttonar', '', [' '], false);
        $mform->closeHeaderBefore('buttonar');
    }

    /**
     * Require exactly one of file or URL.
     *
     * @param array $data Submitted form data.
     * @param array $files Submitted files.
     * @return array Map of field name to error message.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $hasfile = !empty($this->get_draft_files('packagefile'));
        $hasurl = !empty(trim((string)($data['packageurl'] ?? '')));
        if (!$hasfile && !$hasurl) {
            $errors['packagefile'] = get_string('errornosource', 'tool_canvasuplifter');
        } else if ($hasfile && $hasurl) {
            $errors['packageurl'] = get_string('errorbothsources', 'tool_canvasuplifter');
        } else if ($hasurl && !preg_match('#^https?://#i', $data['packageurl'])) {
            $errors['packageurl'] = get_string('errorbadurl', 'tool_canvasuplifter');
        }
        return $errors;
    }
}
