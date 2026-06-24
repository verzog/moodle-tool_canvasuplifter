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
 * When the optional local_chunkupload plugin is installed the package field
 * uses its chunked uploader, so a large .imscc is sent in small pieces over
 * AJAX and isn't bound by PHP's upload_max_filesize/post_max_size or a
 * reverse-proxy upload timeout. Without that plugin the form falls back to the
 * stock filepicker and behaves exactly as before — the dependency is soft.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upload_form extends moodleform {
    /** Fully-qualified class of the chunkupload form element / file API. */
    private const CHUNKUPLOAD_CLASS = 'local_chunkupload\\chunkupload_form_element';

    /** @var bool Whether this form rendered the package field as a chunkupload element. */
    private bool $usingchunkupload = false;

    /**
     * Define the form fields.
     *
     * @return void
     */
    protected function definition(): void {
        global $CFG;
        $mform = $this->_form;

        if (self::chunkupload_available()) {
            // Register and use local_chunkupload's element. registerElementType
            // is idempotent, so it is safe to call on every form build.
            \MoodleQuickForm::registerElementType(
                'chunkupload',
                "$CFG->dirroot/local/chunkupload/classes/chunkupload_form_element.php",
                self::CHUNKUPLOAD_CLASS
            );
            $mform->addElement(
                'chunkupload',
                'packagefile',
                get_string('packagefile', 'tool_canvasuplifter'),
                null,
                // Pass -1, chunkupload's "unlimited" sentinel. Capping at the
                // site upload limit ($CFG->maxbytes) would defeat the purpose:
                // that limit is usually low because of PHP's per-request
                // ceiling, which chunked upload exists to get around.
                ['maxbytes' => -1, 'accepted_types' => ['.imscc', '.zip']]
            );
            $this->usingchunkupload = true;
        } else {
            $mform->addElement(
                'filepicker',
                'packagefile',
                get_string('packagefile', 'tool_canvasuplifter'),
                null,
                ['accepted_types' => ['.imscc', '.zip']]
            );
        }
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
     * Whether the optional local_chunkupload plugin is installed, so the package
     * field can use its chunked uploader for large files.
     *
     * @return bool
     */
    public static function chunkupload_available(): bool {
        global $CFG;
        return file_exists("$CFG->dirroot/local/chunkupload/classes/chunkupload_form_element.php");
    }

    /**
     * Whether this form rendered the package field with the chunkupload element.
     *
     * @return bool
     */
    public function used_chunkupload(): bool {
        return $this->usingchunkupload;
    }

    /**
     * Whether a chunkupload id belongs to the current user. The submitted
     * packagefile value is an opaque id from the POST body and local_chunkupload
     * scopes none of its own lookups by user, so confirm ownership before
     * trusting a token: a forged id pointing at another user's upload must not
     * be accepted (which would copy their package into this user's area and then
     * delete their temp upload).
     *
     * @param int $id The submitted chunkupload id.
     * @return bool
     */
    private function chunkupload_owned(int $id): bool {
        global $DB, $USER;
        return $id > 0 && $DB->record_exists('local_chunkupload_files', ['id' => $id, 'userid' => $USER->id]);
    }

    /**
     * Resolve a chunkupload id to the path of a *completed* upload owned by the
     * current user, or null. local_chunkupload creates a tracking token as soon
     * as the field renders, and its is_file_uploaded() reports true for that
     * bare token, so a started-but-empty form (or a URL-only submission, where
     * the token still exists) would otherwise look like an uploaded file —
     * tripping the "both sources" error or reaching the file copy with no file.
     * Confirm completion from the file itself: present, readable and non-empty.
     *
     * @param int $id The submitted chunkupload id.
     * @return string|null Absolute path to the completed upload, or null.
     */
    private function chunkupload_completed_path(int $id): ?string {
        if (!$this->chunkupload_owned($id)) {
            return null;
        }
        $class = self::CHUNKUPLOAD_CLASS;
        $path = $class::get_path_for_id($id);
        return (is_string($path) && is_readable($path) && filesize($path) > 0) ? $path : null;
    }

    /**
     * Resolve the uploaded package to a readable path on disk, regardless of
     * which uploader the form used. For chunkupload the submitted value is the
     * upload id; for the filepicker we copy the draft file to a temp path.
     *
     * @param \stdClass $data Submitted form data from get_data().
     * @return string|null Absolute path to the package, or null if none uploaded.
     */
    public function get_uploaded_package_path(\stdClass $data): ?string {
        if ($this->usingchunkupload) {
            return $this->chunkupload_completed_path((int) ($data->packagefile ?? 0));
        }
        return $this->save_temp_file('packagefile') ?: null;
    }

    /**
     * Release the uploaded package's temporary storage after it has been copied
     * into the plugin's own file area. For chunkupload this removes its tracking
     * row and temp file; for the filepicker the caller unlinks its own
     * save_temp_file() copy, so there is nothing to do here.
     *
     * @param \stdClass $data Submitted form data from get_data().
     * @return void
     */
    public function cleanup_uploaded_package(\stdClass $data): void {
        if (!$this->usingchunkupload) {
            return;
        }
        $id = (int) ($data->packagefile ?? 0);
        // Only release an upload this user owns — never another user's row.
        if ($this->chunkupload_owned($id)) {
            $class = self::CHUNKUPLOAD_CLASS;
            $class::delete_file($id);
        }
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
        if ($this->usingchunkupload) {
            $hasfile = $this->chunkupload_completed_path((int) ($data['packagefile'] ?? 0)) !== null;
        } else {
            $hasfile = !empty($this->get_draft_files('packagefile'));
        }
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
