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
use tool_canvasuplifter\local\ingest\url_fetcher;
use tool_canvasuplifter\local\job_manager;
use tool_canvasuplifter\local\parser\manifest_parser;
use tool_canvasuplifter\local\report\conversion_report;
use tool_canvasuplifter\task\build_course_task;

admin_externalpage_setup('tool_canvasuplifter');
require_capability('tool/canvasuplifter:use', context_system::instance());

$PAGE->set_title(get_string('pluginname', 'tool_canvasuplifter'));
$PAGE->set_heading(get_string('pluginname', 'tool_canvasuplifter'));

$form = new upload_form();
$report = null;
$error = null;
$storedfileid = null;
$selectedcategory = null;
$selectedpagegrouping = '';
$selectedquizfrombank = 0;

if ($data = $form->get_data()) {
    $extractdir = make_request_directory();
    $temppackage = null;
    $fetcher = null;
    $usedchunkupload = false;
    $buildrequested = !empty($data->buildbutton);
    try {
        if (!empty(trim((string)($data->packageurl ?? '')))) {
            $fetcher = new url_fetcher();
            $temppackage = $fetcher->fetch(trim($data->packageurl));
        } else {
            // Resolve through the form so the chunkupload uploader (when the
            // local_chunkupload plugin is installed) and the stock filepicker
            // both yield a readable package path.
            $temppackage = $form->get_uploaded_package_path($data);
            $usedchunkupload = $form->used_chunkupload();
        }

        // Always persist the package: lets the report page offer a "Build"
        // button without re-uploading, and lets the adhoc task read it.
        $fs = get_file_storage();
        $filerecord = (object) [
            'contextid' => context_system::instance()->id,
            'component' => 'tool_canvasuplifter',
            'filearea' => 'packages',
            'itemid' => $USER->id,
            'filepath' => '/',
            'filename' => 'canvas-' . time() . '.imscc',
            'userid' => $USER->id,
        ];
        $storedfile = $fs->create_file_from_pathname($filerecord, $temppackage);
        $storedfileid = (int) $storedfile->get_id();
        $selectedcategory = (int) $data->categoryid;
        // Capture the build options chosen on the upload form, so the report
        // reflects them and the build-from-report form reuses them without
        // asking again.
        $selectedpagegrouping = clean_param($data->pagegrouping ?? '', PARAM_ALPHA);
        $selectedquizfrombank = empty($data->quizfrombank) ? 0 : 1;

        if ($buildrequested) {
            require_capability(
                'moodle/course:create',
                \context_coursecat::instance($selectedcategory)
            );
            $jobs = new job_manager();
            $jobid = $jobs->create((int) $USER->id, $selectedcategory, $storedfileid);

            $task = new build_course_task();
            $task->set_custom_data([
                'jobid' => $jobid,
                'quizfrombank' => empty($data->quizfrombank) ? 0 : 1,
                // Anything other than 'book'/'lesson' is ignored by course_builder.
                'pagegrouping' => clean_param($data->pagegrouping ?? '', PARAM_ALPHA),
            ]);
            \core\task\manager::queue_adhoc_task($task);

            redirect(new moodle_url('/admin/tool/canvasuplifter/status.php', ['jobid' => $jobid]));
        }

        $root = (new package())->extract($temppackage, $extractdir);
        $course = (new manifest_parser($root))->parse();
        $report = (new conversion_report($course, $root, $selectedpagegrouping))->build();
    } catch (\RuntimeException $e) {
        // The exception message is one of the package::ERROR_* string keys.
        $key = $e->getMessage();
        $error = get_string_manager()->string_exists($key, 'tool_canvasuplifter')
            ? get_string($key, 'tool_canvasuplifter')
            : $e->getMessage();
        if ($fetcher !== null && $fetcher->get_last_detail() !== null && debugging()) {
            $error .= ' (' . s($fetcher->get_last_detail()) . ')';
        }
    } finally {
        if ($usedchunkupload) {
            // The package was copied into our own file area above; let
            // local_chunkupload drop its tracking row and temp file.
            $form->cleanup_uploaded_package($data);
        } else if ($temppackage !== null && file_exists($temppackage)) {
            @unlink($temppackage);
        }
    }
}

// Handle the "Build this course" button on the report page.
$buildfromreport = optional_param('buildfromreport', 0, PARAM_INT);
if ($buildfromreport > 0) {
    require_sesskey();
    $categoryid = required_param('categoryid', PARAM_INT);

    // Enforce the same category permission the original upload form applies
    // via make_categories_list('moodle/course:create'). The category id is
    // user-controlled (hidden POST field), so check it server-side too.
    require_capability(
        'moodle/course:create',
        \context_coursecat::instance($categoryid)
    );

    $fs = get_file_storage();
    $file = $fs->get_file_by_id($buildfromreport);
    if (!$file || $file->get_component() !== 'tool_canvasuplifter' || (int) $file->get_userid() !== (int) $USER->id) {
        throw new \moodle_exception('errorjobnotfound', 'tool_canvasuplifter');
    }
    $quizfrombank = optional_param('quizfrombank', 0, PARAM_INT);
    $pagegrouping = optional_param('pagegrouping', '', PARAM_ALPHA);
    $jobs = new job_manager();
    $jobid = $jobs->create((int) $USER->id, $categoryid, $buildfromreport);
    $task = new build_course_task();
    $task->set_custom_data([
        'jobid' => $jobid,
        'quizfrombank' => $quizfrombank ? 1 : 0,
        // Anything other than 'book'/'lesson' is ignored by course_builder.
        'pagegrouping' => $pagegrouping,
    ]);
    \core\task\manager::queue_adhoc_task($task);
    redirect(new moodle_url('/admin/tool/canvasuplifter/status.php', ['jobid' => $jobid]));
}

echo $OUTPUT->header();

if ($error !== null) {
    echo $OUTPUT->notification($error, \core\output\notification::NOTIFY_ERROR);
}

if ($report === null) {
    // Tell the admin how large packages are handled: chunked upload when
    // local_chunkupload is installed, otherwise point at the URL field.
    $hintkey = upload_form::chunkupload_available() ? 'chunkuploadactive' : 'largepackagehint';
    echo html_writer::tag('p', get_string($hintkey, 'tool_canvasuplifter'), ['class' => 'text-muted']);
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

    // How much of the package the builder creates in the current phase.
    echo html_writer::tag('p', get_string('buildsnowsummary', 'tool_canvasuplifter', [
        'now' => $report['buildsnowtotal'],
        'later' => $report['latertotal'],
    ]), ['class' => 'alert alert-info']);

    // Mapping table.
    $table = new html_table();
    $table->head = [
        get_string('colkind', 'tool_canvasuplifter'),
        get_string('colcount', 'tool_canvasuplifter'),
        get_string('coltarget', 'tool_canvasuplifter'),
        get_string('colbuildsnow', 'tool_canvasuplifter'),
        get_string('colconfidence', 'tool_canvasuplifter'),
        get_string('colnote', 'tool_canvasuplifter'),
    ];
    foreach ($report['rows'] as $row) {
        $table->data[] = [
            s($row['kind']),
            $row['count'],
            s($row['target']),
            get_string($row['buildsnow'] ? 'buildsnow_yes' : 'buildsnow_later', 'tool_canvasuplifter'),
            get_string('confidence_' . $row['confidence'], 'tool_canvasuplifter'),
            get_string($row['note'], 'tool_canvasuplifter'),
        ];
    }
    echo html_writer::table($table);

    // Question-type matrix for any quiz/question-bank packages.
    if (!empty($report['questionmatrix'])) {
        $matrix = $report['questionmatrix'];
        echo $OUTPUT->heading(get_string('matrixheading', 'tool_canvasuplifter'), 4);
        echo html_writer::tag('p', get_string('matrixexplain', 'tool_canvasuplifter', [
            'supported' => $matrix['supported'],
            'total' => $matrix['total'],
        ]));
        $matrixtable = new html_table();
        $matrixtable->head = [
            get_string('matrixcoltype', 'tool_canvasuplifter'),
            get_string('colcount', 'tool_canvasuplifter'),
            get_string('matrixcolsupported', 'tool_canvasuplifter'),
        ];
        $statuskeys = [
            'yes' => 'matrixsupported_yes',
            'incomplete' => 'matrixsupported_incomplete',
            'unsupported' => 'matrixsupported_no',
        ];
        foreach ($matrix['rows'] as $mrow) {
            $typekey = 'qtype_' . $mrow['label'];
            $typelabel = get_string_manager()->string_exists($typekey, 'tool_canvasuplifter')
                ? get_string($typekey, 'tool_canvasuplifter')
                : $mrow['label'];
            $status = $mrow['status'] ?? ($mrow['supported'] ? 'yes' : 'unsupported');
            $matrixtable->data[] = [
                s($typelabel),
                $mrow['count'],
                get_string($statuskeys[$status] ?? 'matrixsupported_no', 'tool_canvasuplifter'),
            ];
        }
        echo html_writer::table($matrixtable);
    }

    // Item-by-item detail, collapsed per section to stay manageable.
    if (!empty($report['sections'])) {
        echo $OUTPUT->heading(get_string('itemdetailheading', 'tool_canvasuplifter'), 4);
        foreach ($report['sections'] as $section) {
            if (empty($section['items'])) {
                continue;
            }
            $detailtable = new html_table();
            $detailtable->head = [
                get_string('coltitle', 'tool_canvasuplifter'),
                get_string('coltarget', 'tool_canvasuplifter'),
                get_string('colbuildsnow', 'tool_canvasuplifter'),
                get_string('colconfidence', 'tool_canvasuplifter'),
            ];
            foreach ($section['items'] as $detailitem) {
                $title = $detailitem['title'] !== '' ? $detailitem['title'] : $detailitem['kind'];
                $detailtable->data[] = [
                    s($title),
                    s($detailitem['target']),
                    get_string($detailitem['buildsnow'] ? 'buildsnow_yes' : 'buildsnow_later', 'tool_canvasuplifter'),
                    get_string('confidence_' . $detailitem['confidence'], 'tool_canvasuplifter'),
                ];
            }
            $sectiontitle = $section['title'] !== ''
                ? $section['title']
                : get_string('untitledsection', 'tool_canvasuplifter');
            $summarytext = get_string('sectionitemscount', 'tool_canvasuplifter', [
                'title' => $sectiontitle,
                'count' => count($section['items']),
            ]);
            echo html_writer::tag(
                'details',
                html_writer::tag('summary', s($summarytext)) . html_writer::table($detailtable)
            );
        }
    }

    // Resources present in the package but not linked from any module.
    if (!empty($report['orphans'])) {
        echo $OUTPUT->heading(get_string('orphansheading', 'tool_canvasuplifter'), 4);
        echo html_writer::tag('p', get_string('orphansexplain', 'tool_canvasuplifter'));
        $orphantable = new html_table();
        $orphantable->head = [
            get_string('coltitle', 'tool_canvasuplifter'),
            get_string('colkind', 'tool_canvasuplifter'),
            get_string('coltarget', 'tool_canvasuplifter'),
            get_string('colresourcetype', 'tool_canvasuplifter'),
            get_string('colplacement', 'tool_canvasuplifter'),
        ];
        foreach ($report['orphans'] as $orphan) {
            $placement = ($orphan['placement'] ?? 'extras') === 'top' ? 'placement_top' : 'placement_extras';
            $orphantable->data[] = [
                s($orphan['title']),
                s($orphan['kind']),
                s($orphan['target']),
                s($orphan['resourcetype'] !== '' ? $orphan['resourcetype'] : '-'),
                get_string($placement, 'tool_canvasuplifter'),
            ];
        }
        echo html_writer::table($orphantable);
    }

    // Debug-only breakdown of what raw CC types showed up as "unknown".
    if (debugging() && !empty($report['unknowntypes'])) {
        echo $OUTPUT->heading(get_string('unknownheading', 'tool_canvasuplifter'), 4);
        $debugtable = new html_table();
        $debugtable->head = [
            get_string('colresourcetype', 'tool_canvasuplifter'),
            get_string('colcount', 'tool_canvasuplifter'),
        ];
        foreach ($report['unknowntypes'] as $type => $count) {
            $debugtable->data[] = [s($type), $count];
        }
        echo html_writer::table($debugtable);
    }

    // Warnings.
    echo $OUTPUT->heading(get_string('warningsheading', 'tool_canvasuplifter'), 4);
    if (empty($report['warnings'])) {
        echo html_writer::tag('p', get_string('nowarnings', 'tool_canvasuplifter'));
    } else {
        // Report warnings are language string keys; resolve them here.
        $items = array_map(
            fn($key) => get_string($key, 'tool_canvasuplifter'),
            $report['warnings']
        );
        echo html_writer::alist($items);
    }

    // Offer to build the course from this same package without re-uploading.
    if ($storedfileid !== null) {
        echo $OUTPUT->heading(get_string('readytobuildheading', 'tool_canvasuplifter'), 4);
        echo html_writer::tag('p', get_string('readytobuildexplain', 'tool_canvasuplifter'));

        $categories = \core_course_category::make_categories_list('moodle/course:create');
        $form = html_writer::start_tag('form', [
            'method' => 'post',
            'action' => new moodle_url('/admin/tool/canvasuplifter/index.php'),
            'class' => 'mform',
        ]);
        $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $form .= html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'buildfromreport',
            'value' => $storedfileid,
        ]);
        $form .= html_writer::start_div('form-group row mb-2');
        $form .= html_writer::tag(
            'label',
            get_string('targetcategory', 'tool_canvasuplifter'),
            ['for' => 'reportcategoryid', 'class' => 'col-md-3 col-form-label']
        );
        $form .= html_writer::start_div('col-md-9');
        $form .= html_writer::select(
            $categories,
            'categoryid',
            $selectedcategory,
            false,
            ['id' => 'reportcategoryid', 'class' => 'form-control']
        );
        $form .= html_writer::end_div();
        $form .= html_writer::end_div();
        // The build options were chosen on the upload form before analysing and
        // the report above already reflects them, so carry them through as the
        // values this build will use rather than presenting the controls again.
        $form .= html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'quizfrombank',
            'value' => $selectedquizfrombank ? '1' : '0',
        ]);
        $form .= html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'pagegrouping',
            'value' => $selectedpagegrouping,
        ]);
        $groupinglabels = [
            '' => get_string('pagegrouping_none', 'tool_canvasuplifter'),
            'book' => get_string('pagegrouping_book', 'tool_canvasuplifter'),
            'lesson' => get_string('pagegrouping_lesson', 'tool_canvasuplifter'),
        ];
        $form .= html_writer::tag('p', get_string('chosenbuildoptions', 'tool_canvasuplifter', [
            'grouping' => $groupinglabels[$selectedpagegrouping] ?? $groupinglabels[''],
            'quiz' => $selectedquizfrombank ? get_string('yes') : get_string('no'),
        ]), ['class' => 'text-muted']);
        $form .= html_writer::div(
            html_writer::empty_tag('input', [
                'type' => 'submit',
                'value' => get_string('buildcourse', 'tool_canvasuplifter'),
                'class' => 'btn btn-primary',
            ])
            . ' '
            . html_writer::link(
                new moodle_url('/admin/tool/canvasuplifter/index.php'),
                get_string('analyseanother', 'tool_canvasuplifter'),
                ['class' => 'btn btn-secondary']
            ),
            'mt-3'
        );
        $form .= html_writer::end_tag('form');
        echo $form;
    } else {
        echo $OUTPUT->continue_button(new moodle_url('/admin/tool/canvasuplifter/index.php'));
    }
}

echo $OUTPUT->footer();
