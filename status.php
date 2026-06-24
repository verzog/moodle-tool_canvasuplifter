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
 * Show the state of a queued/running/finished build job.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_canvasuplifter\local\job_manager;

$jobid = required_param('jobid', PARAM_INT);

admin_externalpage_setup('tool_canvasuplifter');
require_capability('tool/canvasuplifter:use', context_system::instance());

$PAGE->set_url(new moodle_url('/admin/tool/canvasuplifter/status.php', ['jobid' => $jobid]));
$PAGE->set_title(get_string('pluginname', 'tool_canvasuplifter'));
$PAGE->set_heading(get_string('pluginname', 'tool_canvasuplifter'));

$jobs = new job_manager();
$job = $jobs->get($jobid);
if (!$job) {
    throw new \moodle_exception('errorjobnotfound', 'tool_canvasuplifter');
}

// Auto-refresh while the build is in flight.
if (in_array($job->status, [job_manager::STATUS_QUEUED, job_manager::STATUS_RUNNING], true)) {
    $PAGE->set_periodic_refresh_delay(5);
}

echo $OUTPUT->header();
$headingkey = $job->kind === job_manager::KIND_ANALYSE ? 'analysestatusheading' : 'buildstatusheading';
echo $OUTPUT->heading(get_string($headingkey, 'tool_canvasuplifter'));

$statuslabel = get_string('status_' . $job->status, 'tool_canvasuplifter');
echo html_writer::tag('p', get_string('jobstatusis', 'tool_canvasuplifter', $statuslabel));

// Progress bar — shown for queued/running and as a completed bar on done.
$percent = (int) $job->progress;
if ($job->status === job_manager::STATUS_DONE) {
    $percent = 100;
}
$progresslabel = trim((string) $job->progressmessage);
if ($progresslabel === '') {
    $progresslabel = $statuslabel;
}
$bar = html_writer::div('', 'progress-bar bg-primary', [
    'role' => 'progressbar',
    'style' => 'width: ' . $percent . '%;',
    'aria-valuenow' => $percent,
    'aria-valuemin' => 0,
    'aria-valuemax' => 100,
]);
echo html_writer::div($bar, 'progress', ['style' => 'height: 1.25rem;']);
echo html_writer::tag('p', s($progresslabel) . ' (' . $percent . '%)', ['class' => 'text-muted mt-2']);

if ($job->status === job_manager::STATUS_FAILED) {
    // Ingest/package failures are recorded as lang-string keys; resolve them.
    $msg = (string) $job->errormsg;
    if (get_string_manager()->string_exists($msg, 'tool_canvasuplifter')) {
        $msg = get_string($msg, 'tool_canvasuplifter');
    }
    echo $OUTPUT->notification(format_text($msg, FORMAT_PLAIN), \core\output\notification::NOTIFY_ERROR);
}

if ($job->status === job_manager::STATUS_DONE && $job->kind === job_manager::KIND_ANALYSE) {
    // Show the conversion report and offer to build the same package.
    $report = json_decode((string) $job->report, true) ?: [];
    $renderer = $PAGE->get_renderer('tool_canvasuplifter');
    echo $renderer->analysis($report);
    if (!empty($job->fileid)) {
        echo $renderer->build_from_report_form(
            (int) $job->fileid,
            (int) $job->categoryid,
            (string) ($report['pagegrouping'] ?? ''),
            (int) ($report['quizfrombank'] ?? 0)
        );
    }
} else if ($job->status === job_manager::STATUS_DONE && $job->courseid) {
    $report = json_decode((string) $job->report, true) ?: [];
    echo html_writer::tag('p', get_string('builtcoursesummary', 'tool_canvasuplifter', [
        'sectioncount' => (int) ($report['sectioncount'] ?? 0),
        'itemcount' => (int) ($report['itemcount'] ?? 0),
        'created' => (int) ($report['created'] ?? 0),
        'skipped' => (int) ($report['skipped'] ?? 0),
    ]));

    $createdcounts = $report['createdcounts'] ?? [];
    $skippedcounts = $report['skippedcounts'] ?? [];
    if (!empty($createdcounts) || !empty($skippedcounts)) {
        $resulttable = new html_table();
        $resulttable->head = [
            get_string('colkind', 'tool_canvasuplifter'),
            get_string('colcreated', 'tool_canvasuplifter'),
            get_string('colskipped', 'tool_canvasuplifter'),
        ];
        $kinds = array_unique(array_merge(array_keys($createdcounts), array_keys($skippedcounts)));
        sort($kinds);
        foreach ($kinds as $kind) {
            $resulttable->data[] = [
                s($kind),
                (int) ($createdcounts[$kind] ?? 0),
                (int) ($skippedcounts[$kind] ?? 0),
            ];
        }
        echo html_writer::table($resulttable);
    }

    if (!empty($report['extraquizzes'])) {
        echo html_writer::tag('p', get_string(
            'extraquizzesbuilt',
            'tool_canvasuplifter',
            (int) $report['extraquizzes']
        ));
    }

    if (!empty($report['warnings'])) {
        echo $OUTPUT->heading(get_string('warningsheading', 'tool_canvasuplifter'), 4);
        echo html_writer::alist(array_map('s', $report['warnings']));
    }

    if (debugging() && !empty($report['skipreasons'])) {
        echo $OUTPUT->heading(get_string('skipreasonsheading', 'tool_canvasuplifter'), 4);
        echo html_writer::alist(array_map('s', $report['skipreasons']));
    }

    echo $OUTPUT->single_button(
        new moodle_url('/course/view.php', ['id' => $job->courseid]),
        get_string('openbuiltcourse', 'tool_canvasuplifter')
    );
}

echo $OUTPUT->continue_button(new moodle_url('/admin/tool/canvasuplifter/index.php'));

echo $OUTPUT->footer();
