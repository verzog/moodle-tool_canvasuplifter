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
 * Canvas Uplifter main page: upload a package and queue an analyse or build run.
 *
 * Both actions run in the background (see analyse_package_task and
 * build_course_task) and redirect to status.php, so extracting, parsing and any
 * remote download happen off the web request rather than risking a gateway
 * timeout on a large package.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_canvasuplifter\form\upload_form;
use tool_canvasuplifter\launcher;
use tool_canvasuplifter\local\job_manager;

admin_externalpage_setup('tool_canvasuplifter');
require_capability('tool/canvasuplifter:use', context_system::instance());

$PAGE->set_title(get_string('pluginname', 'tool_canvasuplifter'));
$PAGE->set_heading(get_string('pluginname', 'tool_canvasuplifter'));

$form = new upload_form();
$error = null;

if ($data = $form->get_data()) {
    $buildrequested = !empty($data->buildbutton);
    $packageurl = trim((string) ($data->packageurl ?? ''));
    $usedchunkupload = false;
    $temppackage = null;
    try {
        $categoryid = (int) $data->categoryid;
        $pagegrouping = clean_param($data->pagegrouping ?? '', PARAM_ALPHA);
        $quizfrombank = empty($data->quizfrombank) ? 0 : 1;
        if ($buildrequested) {
            require_capability('moodle/course:create', \context_coursecat::instance($categoryid));
        }

        // For an uploaded file, persist it now (the upload already happened). For
        // a URL, leave the download to the task so a large fetch can't time out
        // this request.
        $storedfileid = null;
        if ($packageurl === '') {
            $temppackage = $form->get_uploaded_package_path($data);
            $usedchunkupload = $form->used_chunkupload();
            if ($temppackage === null) {
                throw new \RuntimeException('errornosource');
            }
            $fs = get_file_storage();
            // Preserve the uploaded file's name (read before the chunk upload is
            // released below) so a package with no embedded course title can be
            // named after it. A unique filepath lets distinct uploads keep their
            // original names without colliding.
            $origname = clean_param($form->get_uploaded_filename($data), PARAM_FILE);
            if ($origname === '') {
                $origname = 'package.imscc';
            }
            $filerecord = (object) [
                'contextid' => context_system::instance()->id,
                'component' => 'tool_canvasuplifter',
                'filearea' => 'packages',
                'itemid' => $USER->id,
                'filepath' => '/' . uniqid() . '/',
                'filename' => $origname,
                'userid' => $USER->id,
            ];
            $storedfileid = (int) $fs->create_file_from_pathname($filerecord, $temppackage)->get_id();
            // The package is stored; release the temp upload now.
            if ($usedchunkupload) {
                $form->cleanup_uploaded_package($data);
            } else if (file_exists($temppackage)) {
                @unlink($temppackage);
            }
            $temppackage = null;
        }

        $kind = $buildrequested ? job_manager::KIND_BUILD : job_manager::KIND_ANALYSE;
        $jobid = launcher::queue_job(
            (int) $USER->id,
            $categoryid,
            $kind,
            $storedfileid,
            $packageurl !== '' ? $packageurl : null,
            (bool) $quizfrombank,
            $pagegrouping
        );

        redirect(new moodle_url('/admin/tool/canvasuplifter/status.php', ['jobid' => $jobid]));
    } catch (\RuntimeException $e) {
        // The message is one of the package/ingest ERROR_* lang-string keys.
        $key = $e->getMessage();
        $error = get_string_manager()->string_exists($key, 'tool_canvasuplifter')
            ? get_string($key, 'tool_canvasuplifter')
            : $e->getMessage();
    } finally {
        // On success redirect() has already exited; this only runs if queuing
        // failed after a file was resolved.
        if ($usedchunkupload) {
            $form->cleanup_uploaded_package($data);
        } else if ($temppackage !== null && file_exists($temppackage)) {
            @unlink($temppackage);
        }
    }
}

// Handle the "Build this course" button on the analysis status page.
$buildfromreport = optional_param('buildfromreport', 0, PARAM_INT);
if ($buildfromreport > 0) {
    require_sesskey();
    $categoryid = required_param('categoryid', PARAM_INT);

    // Enforce the same category permission the upload form applies via
    // make_categories_list('moodle/course:create'). The id is user-controlled
    // (hidden POST field), so check it server-side too.
    require_capability('moodle/course:create', \context_coursecat::instance($categoryid));

    $fs = get_file_storage();
    $file = $fs->get_file_by_id($buildfromreport);
    if (!$file || $file->get_component() !== 'tool_canvasuplifter' || (int) $file->get_userid() !== (int) $USER->id) {
        throw new \moodle_exception('errorjobnotfound', 'tool_canvasuplifter');
    }
    $quizfrombank = optional_param('quizfrombank', 0, PARAM_INT);
    $pagegrouping = optional_param('pagegrouping', '', PARAM_ALPHA);
    $jobid = launcher::queue_job(
        (int) $USER->id,
        $categoryid,
        job_manager::KIND_BUILD,
        $buildfromreport,
        null,
        (bool) $quizfrombank,
        $pagegrouping
    );
    redirect(new moodle_url('/admin/tool/canvasuplifter/status.php', ['jobid' => $jobid]));
}

echo $OUTPUT->header();

if ($error !== null) {
    echo $OUTPUT->notification($error, \core\output\notification::NOTIFY_ERROR);
}

// Imported page/description HTML is stored and rendered as-authored (Moodle does
// not re-clean it), so an untrusted package could carry active content. Warn the
// admin to only import packages they trust.
echo $OUTPUT->notification(
    get_string('trustedsourcewarning', 'tool_canvasuplifter'),
    \core\output\notification::NOTIFY_WARNING
);

// Point the admin at the built-in chunked-upload field for large packages.
echo html_writer::tag('p', get_string('chunkuploadactive', 'tool_canvasuplifter'), ['class' => 'text-muted']);
$form->display();

echo $OUTPUT->footer();
