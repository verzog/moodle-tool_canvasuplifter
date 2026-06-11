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
 * Canvas Uplifter main page: upload a package and view the conversion report.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_canvasuplifter\form\upload_form;
use tool_canvasuplifter\local\ingest\package;
use tool_canvasuplifter\local\parser\manifest_parser;
use tool_canvasuplifter\local\report\conversion_report;

admin_externalpage_setup('tool_canvasuplifter');
require_capability('tool/canvasuplifter:use', context_system::instance());

$PAGE->set_title(get_string('pluginname', 'tool_canvasuplifter'));
$PAGE->set_heading(get_string('pluginname', 'tool_canvasuplifter'));

$form = new upload_form();
$report = null;
$error = null;

if ($data = $form->get_data()) {
    // Save the uploaded package to a temporary file, then unpack and inspect it.
    $temppackage = $form->save_temp_file('packagefile');
    $extractdir = make_request_directory();
    try {
        $root = (new package())->extract($temppackage, $extractdir);
        $course = (new manifest_parser($root))->parse();
        $report = (new conversion_report($course))->build();
    } catch (\RuntimeException $e) {
        // The exception message is one of the package::ERROR_* string keys.
        $key = $e->getMessage();
        $error = get_string_manager()->string_exists($key, 'tool_canvasuplifter')
            ? get_string($key, 'tool_canvasuplifter')
            : $e->getMessage();
    } finally {
        if (file_exists($temppackage)) {
            @unlink($temppackage);
        }
    }
}

echo $OUTPUT->header();

if ($error !== null) {
    echo $OUTPUT->notification($error, \core\output\notification::NOTIFY_ERROR);
}

if ($report === null) {
    $form->display();
} else {
    echo $OUTPUT->heading(get_string('reportheading', 'tool_canvasuplifter'));

    // Summary line.
    $summary = html_writer::tag(
        'p',
        get_string('coursename', 'tool_canvasuplifter') . ': ' . s($report['coursename']) . ' &middot; ' .
        get_string('sectioncount', 'tool_canvasuplifter') . ': ' . $report['sectioncount'] . ' &middot; ' .
        get_string('itemcount', 'tool_canvasuplifter') . ': ' . $report['itemcount']
    );
    echo $summary;

    // Mapping table.
    $table = new html_table();
    $table->head = [
        get_string('colkind', 'tool_canvasuplifter'),
        get_string('colcount', 'tool_canvasuplifter'),
        get_string('coltarget', 'tool_canvasuplifter'),
        get_string('colconfidence', 'tool_canvasuplifter'),
    ];
    foreach ($report['rows'] as $row) {
        $confidencekey = 'confidence_' . $row['confidence'];
        $table->data[] = [
            s($row['kind']),
            $row['count'],
            s($row['target']),
            get_string($confidencekey, 'tool_canvasuplifter'),
        ];
    }
    echo html_writer::table($table);

    // Warnings.
    echo $OUTPUT->heading(get_string('warningsheading', 'tool_canvasuplifter'), 4);
    if (empty($report['warnings'])) {
        echo html_writer::tag('p', get_string('nowarnings', 'tool_canvasuplifter'));
    } else {
        $items = array_map('s', $report['warnings']);
        echo html_writer::alist($items);
    }

    // Let the user analyse another package.
    echo $OUTPUT->continue_button(new moodle_url('/admin/tool/canvasuplifter/index.php'));
}

echo $OUTPUT->footer();
