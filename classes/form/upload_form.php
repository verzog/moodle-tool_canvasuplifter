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
 * Offers three ways to supply a package: the standard Moodle file picker (the
 * main control), a download URL, and an extra chunked-upload field for very
 * large packages that would exceed PHP's per-request upload limit (those hosted
 * on repository sites, for example). The chunked uploader is bundled with this
 * plugin (folded in from the former local_chunkupload), so the field is always
 * available.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upload_form extends moodleform {
    /** Fully-qualified class of the chunkupload form element / file API. */
    private const CHUNKUPLOAD_CLASS = 'tool_canvasuplifter\\chunkupload\\form_element';

    /** @var bool Whether the optional chunkupload field was added to the form. */
    private bool $chunkuploadoffered = false;

    /** @var bool Whether get_uploaded_package_path() resolved the file via the chunkupload field. */
    private bool $resolvedviachunkupload = false;

    /**
     * Define the form fields.
     *
     * @return void
     */
    protected function definition(): void {
        global $CFG;
        $mform = $this->_form;

        // The standard Moodle file picker is the main control.
        $mform->addElement(
            'filepicker',
            'packagefile',
            get_string('packagefile', 'tool_canvasuplifter'),
            null,
            ['accepted_types' => ['.imscc', '.zip']]
        );
        $mform->addHelpButton('packagefile', 'packagefile', 'tool_canvasuplifter');

        // Extra field for very large packages that exceed PHP's per-request
        // upload ceiling. registerElementType is idempotent, so it is safe to
        // call on every form build.
        if (self::chunkupload_available()) {
            \MoodleQuickForm::registerElementType(
                'chunkupload',
                "$CFG->dirroot/admin/tool/canvasuplifter/classes/chunkupload/form_element.php",
                self::CHUNKUPLOAD_CLASS
            );
            $mform->addElement(
                'chunkupload',
                'packagelargefile',
                get_string('packagelargefile', 'tool_canvasuplifter'),
                null,
                // Pass -1, chunkupload's "unlimited" sentinel; the point of the
                // field is to exceed PHP's per-request upload ceiling.
                ['maxbytes' => -1, 'accepted_types' => ['.imscc', '.zip']]
            );
            $mform->addHelpButton('packagelargefile', 'packagelargefile', 'tool_canvasuplifter');
            $this->chunkuploadoffered = true;
        }

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
     * Whether the chunked-upload field can be offered. The uploader is bundled
     * with this plugin, so it is always available; kept as a method so callers
     * read intent rather than a bare true.
     *
     * @return bool
     */
    public static function chunkupload_available(): bool {
        return true;
    }

    /**
     * Whether the most recent get_uploaded_package_path() resolved the package
     * from the chunkupload field (so the caller cleans up the right temp store).
     *
     * @return bool
     */
    public function used_chunkupload(): bool {
        return $this->resolvedviachunkupload;
    }

    /**
     * Whether a chunkupload id belongs to the current user. The submitted
     * packagefile value is an opaque id from the POST body and the chunked
     * uploader scopes none of its own DB helpers by user, so confirm ownership
     * before trusting a token: a forged id pointing at another user's upload
     * must not be accepted (which would copy their package into this user's area
     * and then delete their temp upload).
     *
     * @param int $id The submitted chunkupload id.
     * @return bool
     */
    private function chunkupload_owned(int $id): bool {
        global $DB, $USER;
        return $id > 0 && $DB->record_exists('tool_canvasuplifter_chunks', ['id' => $id, 'userid' => $USER->id]);
    }

    /**
     * Resolve a chunkupload id to the path of a *completed* upload owned by the
     * current user, or null. The uploader creates a tracking token as soon as
     * the field renders, and its is_file_uploaded() reports true for that bare
     * token, so a started-but-empty form (or a URL-only submission, where
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
     * Resolve the supplied package to a readable path on disk, preferring the
     * large-file (chunkupload) field when it carries a completed upload and
     * otherwise copying the file picker's draft to a temp path. Returns null
     * when neither field has a usable file.
     *
     * @param \stdClass $data Submitted form data from get_data().
     * @return string|null Absolute path to the package, or null if none uploaded.
     */
    public function get_uploaded_package_path(\stdClass $data): ?string {
        if ($this->chunkuploadoffered) {
            $path = $this->chunkupload_completed_path((int) ($data->packagelargefile ?? 0));
            if ($path !== null) {
                $this->resolvedviachunkupload = true;
                return $path;
            }
        }
        $this->resolvedviachunkupload = false;
        return $this->save_temp_file('packagefile') ?: null;
    }

    /**
     * Release a chunkupload large-file upload after it has been copied into the
     * plugin's own file area (removing its tracking row and temp file). A file
     * picker upload is a save_temp_file() copy the caller unlinks itself, so
     * there is nothing to do for it here.
     *
     * @param \stdClass $data Submitted form data from get_data().
     * @return void
     */
    public function cleanup_uploaded_package(\stdClass $data): void {
        if (!$this->chunkuploadoffered) {
            return;
        }
        $id = (int) ($data->packagelargefile ?? 0);
        // Only release an upload this user owns — never another user's row.
        if ($this->chunkupload_owned($id)) {
            $class = self::CHUNKUPLOAD_CLASS;
            $class::delete_file($id);
        }
    }

    /**
     * Require exactly one package source: file picker, large-file upload or URL.
     *
     * @param array $data Submitted form data.
     * @param array $files Submitted files.
     * @return array Map of field name to error message.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $hasfile = !empty($this->get_draft_files('packagefile'));
        $haslarge = $this->chunkuploadoffered
            && $this->chunkupload_completed_path((int) ($data['packagelargefile'] ?? 0)) !== null;
        $hasurl = !empty(trim((string)($data['packageurl'] ?? '')));

        $sources = (int) $hasfile + (int) $haslarge + (int) $hasurl;
        if ($sources === 0) {
            $errors['packagefile'] = get_string('errornosource', 'tool_canvasuplifter');
        } else if ($sources > 1) {
            $errors['packageurl'] = get_string('errorbothsources', 'tool_canvasuplifter');
        } else if ($hasurl && !preg_match('#^https?://#i', $data['packageurl'])) {
            $errors['packageurl'] = get_string('errorbadurl', 'tool_canvasuplifter');
        }
        return $errors;
    }
}
