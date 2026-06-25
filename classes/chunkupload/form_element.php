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

/**
 * Chunked-upload form element.
 *
 * Folded in from local_chunkupload (2020 Justus Dieckmann WWU) so a large
 * package can be uploaded in chunks without a separate plugin dependency.
 *
 * @package    tool_canvasuplifter
 * @copyright  2020 Justus Dieckmann WWU
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_canvasuplifter\chunkupload;

use core_form\filetypes_util;
use html_writer;
use renderer_base;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/repository/lib.php');
require_once($CFG->libdir . '/pear/HTML/QuickForm/button.php');
require_once($CFG->libdir . '/form/templatable_form_element.php');

/**
 * Chunked-upload form element.
 *
 * @package    tool_canvasuplifter
 * @copyright  2020 Justus Dieckmann WWU
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class form_element extends \HTML_QuickForm_input implements \templatable {
    use \templatable_form_element {
        export_for_template as export_for_template_base;
    }

    // The underscore-prefixed property names below are the QuickForm element API
    // (Moodle's addHelpButton() writes to $_helpbutton by that name), so the
    // PSR2 "no underscore" rule does not apply — same idiom as core's filepicker.
    /** @var string html for help button, if empty then no help icon will be displayed. */
    public $_helpbutton = ''; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

    // PHP doesn't support 'key' => $value1 | $value2 in class definition
    // We cannot do $_options = array('return_types'=> FILE_INTERNAL | FILE_REFERENCE);.
    // So I have to set null here, and do it in constructor.
    /** @var array options provided to initialise the filepicker. */
    protected $_options = ['maxbytes' => 0, 'accepted_types' => '*']; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

    /**
     * Constructor.
     *
     * @param string $elementname (optional) name of the filepicker
     * @param string $elementlabel (optional) filepicker label
     * @param array $attributes (optional) Either a typical HTML attribute string or an associative array
     * @param array $options set of options to initialise the filepicker
     */
    public function __construct($elementname = null, $elementlabel = null, $attributes = null, $options = null) {
        $options = (array) $options;
        foreach ($options as $name => $value) {
            if (array_key_exists($name, $this->_options)) {
                $this->_options[$name] = $value;
            }
        }
        $this->_type = 'filepicker';
        parent::__construct($elementname, $elementlabel, $attributes);
    }

    /**
     * Returns html for help button.
     *
     * @return string html for help button
     */
    public function gethelpbutton() {
        return $this->_helpbutton;
    }

    /**
     * Returns type of filepicker element.
     *
     * @return string
     */
    public function getelementtemplatetype() {
        if ($this->_flagFrozen) {
            return 'nodisplay';
        } else {
            return 'default';
        }
    }

    /**
     * Returns HTML for the filepicker form element.
     *
     * @return string
     */
    public function tohtml() {
        global $CFG, $PAGE, $OUTPUT;
        $id = $this->_attributes['id'];
        $elname = $this->_attributes['name'];
        $showfinishedicon = false;
        $filenamestring = null;

        if ($value = $this->getvalue()) {
            global $DB;
            if ($record = $DB->get_record('tool_canvasuplifter_chunks', ['id' => $value])) {
                if ($record->state == state_type::UPLOAD_COMPLETED) {
                    $filenamestring = $record->filename;
                    $showfinishedicon = true;
                }
            } else {
                $value = $this->create_token();
            }
        } else {
            $value = $this->create_token();
        }
        if (!$filenamestring) {
            $filenamestring = get_string('choosefile', 'mod_feedback');
        }

        $context = [
                'elid' => $id,
                'elname' => $elname,
                'value' => $value,
                'filenamestring' => $filenamestring,
                'showicon' => $showfinishedicon,
                'showdelete' => $showfinishedicon,
                'filesize' => display_size((int) $this->_options['maxbytes']),
        ];

        $html = $OUTPUT->render_from_template('tool_canvasuplifter/chunkupload_filepicker', $context);

        // Need these three to filter repositories list.
        $acceptedtypes = $this->_options['accepted_types'] ? $this->_options['accepted_types'] : '*';
        $util = new \core_form\filetypes_util();
        if ($acceptedtypes !== '*') {
            $acceptedtypes = $util->expand($acceptedtypes);
            $html .= html_writer::tag('p', get_string('filesofthesetypes', 'form'));
            $filetypes = $acceptedtypes;
            $filetypedescriptions = $util->describe_file_types($filetypes);
            $html .= $OUTPUT->render_from_template('core_form/filetypes-descriptions', $filetypedescriptions);
        }

        // Fall back to 64 MB if the admin setting has not been written yet, so a
        // zero chunk size can never stall the uploader.
        $chunksize = (int) get_config('tool_canvasuplifter', 'chunksize');
        if ($chunksize <= 0) {
            $chunksize = 64;
        }

        $PAGE->requires->js_call_amd('tool_canvasuplifter/chunkupload', 'init', [
                'elementid' => $id,
                'acceptedTypes' => $acceptedtypes,
                'maxBytes' => (int) $this->_options['maxbytes'],
                'wwwroot' => $CFG->wwwroot,
                'chunksize' => $chunksize * 1024 * 1024,
                'browsetext' => get_string('choosefile', 'mod_feedback'),
        ]);
        return $html;
    }

    /**
     * Export uploaded file.
     *
     * @param array $submitvalues values submitted.
     * @param bool $assoc specifies if returned array is associative
     * @return array
     */
    public function exportvalue(&$submitvalues, $assoc = false) {
        $fileid = $this->_findValue($submitvalues);
        if (null === $fileid) {
            $fileid = $this->getValue();
        }

        return $this->_prepareValue($fileid, true);
    }

    /**
     * Exports the data for the mustache template.
     *
     * @param renderer_base $output The renderer.
     * @return array The data for the template.
     */
    public function export_for_template(renderer_base $output) {
        $context = $this->export_for_template_base($output);
        $context['html'] = $this->tohtml();
        return $context;
    }

    /**
     * Check that the file has the allowed type.
     *
     * @param array $value Draft item id with the uploaded files.
     * @return string|null Validation error message or null.
     */
    public function validatesubmitvalue($value) {
        global $DB;
        if (is_null($value)) {
            return "";
        }
        $record = $DB->get_record('tool_canvasuplifter_chunks', ['id' => $value]);
        if (!$record || $record->state == state_type::UNUSED_TOKEN_GENERATED) {
            return "";
        }
        if ($record->state == state_type::UPLOAD_STARTED) {
            return get_string('uploadnotfinished', 'tool_canvasuplifter');
        }
        $path = self::get_path_for_id($value);
        if ($path == null || !file_exists($path)) {
            return get_string('nofile', 'error');
        }
        if ($this->_options['maxbytes'] !== -1 && filesize($path) > $this->_options['maxbytes']) {
            unlink($path);
            $DB->delete_records('tool_canvasuplifter_chunks', ['id' => $value]);
            return get_string('errorfiletoobig', 'moodle', $this->_options['maxbytes']);
        }

        $util = new filetypes_util();
        $allowlist = $util->normalize_file_types($this->_options['accepted_types']);
        $filename = $record->filename;
        if (!$util->is_allowed_file_type($filename, $allowlist)) {
            unlink($path);
            $DB->delete_records('tool_canvasuplifter_chunks', ['id' => $value]);
            $filetype = substr($filename, strrpos($filename, '.'));
            return get_string('invalidfiletype', 'core_repository', $filetype);
        }
        return null;
    }

    /**
     * Creates an id for a chunked upload.
     *
     * @return int|null The chunkupload id.
     */
    public function create_token() {
        global $DB, $PAGE, $USER;

        if (isguestuser()) {
            // Ensure guests can't upload.
            return null;
        }

        do {
            $id = random_int(0, 10000000000);
        } while ($DB->record_exists('tool_canvasuplifter_chunks', ['id' => $id]));

        $record = new \stdClass();
        $record->id = $id;
        $record->userid = $USER->id;
        $record->contextid = $PAGE->context->id;
        $record->maxlength = $this->_options['maxbytes'];
        $record->lastmodified = time();
        $DB->insert_record_raw('tool_canvasuplifter_chunks', $record, false, false, true);
        return $id;
    }

    /**
     * Returns the base folder where the chunked files are stored.
     *
     * @return string The base folder.
     */
    public static function get_base_folder() {
        global $CFG;
        return "$CFG->dataroot/tool_canvasuplifter/chunks/";
    }

    /**
     * Returns the filepath for the chunkupload with the given id.
     *
     * @param int $id The id.
     * @return string|null The filepath.
     */
    public static function get_path_for_id($id) {
        if ($id) {
            return self::get_base_folder() . $id;
        } else {
            return null;
        }
    }

    /**
     * Exports the uploaded file referenced by the $chunkuploadid to the given filearea.
     *
     * @param int $chunkuploadid The chunkupload id of the file to export.
     * @param int $newcontextid The contextid for the filearea.
     * @param string $newcomponent The component for the filearea.
     * @param string $newfilearea The filearea where to export the file to.
     * @param string $newfilepath The filepath where to export the file to.
     * @return \stored_file|null The file that is stored in the filearea.
     */
    public static function export_to_filearea(
        $chunkuploadid,
        $newcontextid,
        $newcomponent,
        $newfilearea,
        $newfilepath = '/'
    ) {
        global $DB;
        $fs = get_file_storage();
        $record = $DB->get_record('tool_canvasuplifter_chunks', ['id' => $chunkuploadid], '*', IGNORE_MISSING);
        if (!$record || $record->state !== state_type::UPLOAD_COMPLETED) {
            return null;
        }

        $filerecord = ['contextid' => $newcontextid, 'component' => $newcomponent,
                'filearea' => $newfilearea, 'itemid' => $chunkuploadid, 'filepath' => $newfilepath,
                'filename' => $record->filename, 'userid' => $record->userid, ];

        \core_php_time_limit::raise();

        // Increase memory limit.
        raise_memory_limit(MEMORY_EXTRA);
        $file = $fs->create_file_from_pathname($filerecord, self::get_path_for_id($chunkuploadid));
        reduce_memory_limit(MEMORY_STANDARD);

        return $file;
    }

    /**
     * Remove chunkupload file.
     *
     * @param string $chunkuploadid token of the chunkupload job.
     */
    public static function delete_file($chunkuploadid) {
        global $DB;
        $DB->delete_records('tool_canvasuplifter_chunks', ['id' => $chunkuploadid]);
        $path = self::get_path_for_id($chunkuploadid);
        if ($path !== null && file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * Returns whether a file is uploaded for a given chunkupload id.
     *
     * @param int $id the chunkupload id.
     * @return bool whether a file was uploaded.
     */
    public static function is_file_uploaded($id) {
        global $DB;
        if (is_null($id)) {
            return false;
        }
        $record = $DB->get_record('tool_canvasuplifter_chunks', ['id' => $id]);
        if (!$record) {
            return false;
        }

        if ($record->state != state_type::UPLOAD_COMPLETED) {
            return false;
        }
        return true;
    }
}
