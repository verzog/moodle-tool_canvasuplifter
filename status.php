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
 * @package    tool_courseuplift
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_courseuplift\local\job_manager;
use tool_courseuplift\local\parser\source_detector;

$jobid = required_param('jobid', PARAM_INT);

admin_externalpage_setup('tool_courseuplift');
require_capability('tool/courseuplift:use', context_system::instance());

$PAGE->set_url(new moodle_url('/admin/tool/courseuplift/status.php', ['jobid' => $jobid]));
$PAGE->set_title(get_string('pluginname', 'tool_courseuplift'));
$PAGE->set_heading(get_string('pluginname', 'tool_courseuplift'));

$jobs = new job_manager();
$job = $jobs->get($jobid);
if (!$job) {
    throw new \moodle_exception('errorjobnotfound', 'tool_courseuplift');
}
// Show a job only to the user who queued it; the same not-found error avoids
// revealing whether another user's job id exists.
if ((int) $job->userid !== (int) $USER->id) {
    throw new \moodle_exception('errorjobnotfound', 'tool_courseuplift');
}

// Auto-refresh while the build is in flight.
if (in_array($job->status, [job_manager::STATUS_QUEUED, job_manager::STATUS_RUNNING], true)) {
    $PAGE->set_periodic_refresh_delay(5);
}

// A printable (Save as PDF) view of a finished analyse report: use the print page
// layout so the browser's print / Save-as-PDF produces a clean document without the
// site chrome. Only a completed analyse job has a report to print.
$print = optional_param('print', false, PARAM_BOOL);
$isanalysedone = $job->status === job_manager::STATUS_DONE && $job->kind === job_manager::KIND_ANALYSE;
if ($print && $isanalysedone) {
    $PAGE->set_pagelayout('print');
}

echo $OUTPUT->header();
$headingkey = $job->kind === job_manager::KIND_ANALYSE ? 'analysestatusheading' : 'buildstatusheading';
echo $OUTPUT->heading(get_string($headingkey, 'tool_courseuplift'));

$statuslabel = get_string('status_' . $job->status, 'tool_courseuplift');
echo html_writer::tag('p', get_string('jobstatusis', 'tool_courseuplift', $statuslabel));

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
    if (get_string_manager()->string_exists($msg, 'tool_courseuplift')) {
        $msg = get_string($msg, 'tool_courseuplift');
    }
    echo $OUTPUT->notification(format_text($msg, FORMAT_PLAIN), \core\output\notification::NOTIFY_ERROR);
}

if ($isanalysedone) {
    // Show the conversion report, a printable (Save as PDF) view, and offer to build.
    $report = json_decode((string) $job->report, true) ?: [];
    $renderer = $PAGE->get_renderer('tool_courseuplift');
    echo $renderer->analysis($report);
    if ($print) {
        // Printable view: a Print / Save as PDF button and a link back to the report,
        // both hidden in the printout itself (tool_courseuplift-noprint, see styles.css).
        $printbutton = html_writer::tag('button', get_string('analyseprint', 'tool_courseuplift'), [
            'type' => 'button',
            'class' => 'btn btn-primary tool_courseuplift-printbutton',
        ]);
        $back = html_writer::link(
            new moodle_url('/admin/tool/courseuplift/status.php', ['jobid' => $jobid]),
            get_string('backtoreport', 'tool_courseuplift'),
            ['class' => 'btn btn-secondary']
        );
        echo html_writer::div($printbutton . ' ' . $back, 'tool_courseuplift-noprint mt-3');
        $PAGE->requires->js_call_amd('tool_courseuplift/printbutton', 'init', ['.tool_courseuplift-printbutton']);
    } else {
        // Offer the printable / Save-as-PDF view, then offer to build the same package.
        echo html_writer::div(
            html_writer::link(
                new moodle_url('/admin/tool/courseuplift/status.php', ['jobid' => $jobid, 'print' => 1]),
                get_string('analyseprint', 'tool_courseuplift'),
                ['class' => 'btn btn-outline-secondary']
            ),
            'mt-3'
        );
        if (!empty($job->fileid)) {
            echo $renderer->build_from_report_form(
                (int) $job->fileid,
                (int) $job->categoryid,
                (string) ($report['pagegrouping'] ?? ''),
                (int) ($report['quizfrombank'] ?? 0)
            );
        }
    }
} else if ($job->status === job_manager::STATUS_DONE && $job->courseid) {
    $report = json_decode((string) $job->report, true) ?: [];
    // A Blackboard-native package builds nothing, so lead with the actionable
    // explanation before the zero-created summary and result table; drop it from the
    // warnings list at the foot so it is not shown twice.
    $buildwarnings = $report['warnings'] ?? [];
    if (($report['source'] ?? '') === source_detector::BLACKBOARD_NATIVE) {
        echo html_writer::tag(
            'p',
            get_string('warnblackboardnative', 'tool_courseuplift'),
            ['class' => 'alert alert-warning']
        );
        $nativetext = get_string('warnblackboardnative', 'tool_courseuplift');
        $buildwarnings = array_values(array_filter($buildwarnings, fn($w) => $w !== $nativetext));
    }
    echo html_writer::tag('p', get_string('builtcoursesummary', 'tool_courseuplift', [
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
            get_string('colkind', 'tool_courseuplift'),
            get_string('colcreated', 'tool_courseuplift'),
            get_string('colskipped', 'tool_courseuplift'),
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
            'tool_courseuplift',
            (int) $report['extraquizzes']
        ));
    }

    if (!empty($buildwarnings)) {
        echo $OUTPUT->heading(get_string('warningsheading', 'tool_courseuplift'), 4);
        echo html_writer::alist(array_map('s', $buildwarnings));
    }

    if (debugging() && !empty($report['skipreasons'])) {
        echo $OUTPUT->heading(get_string('skipreasonsheading', 'tool_courseuplift'), 4);
        echo html_writer::alist(array_map('s', $report['skipreasons']));
    }

    // List the embedded assets the export referenced but did not ship, so an
    // editor can see exactly which inline images/attachments to re-upload.
    if (!empty($report['unresolvedmedia'])) {
        echo $OUTPUT->heading(get_string('unresolvedmediaheading', 'tool_courseuplift'), 4);
        echo html_writer::alist(array_map('s', $report['unresolvedmedia']));
        // The stored list is capped; if the build saw more, say so rather than
        // letting the shown subset read as the complete set of missing assets.
        $shown = count($report['unresolvedmedia']);
        $total = (int) ($report['unresolvedmediacount'] ?? $shown);
        if ($total > $shown) {
            echo html_writer::tag(
                'p',
                get_string('unresolvedmediatruncated', 'tool_courseuplift', $total - $shown)
            );
        }
    }

    echo $OUTPUT->single_button(
        new moodle_url('/course/view.php', ['id' => $job->courseid]),
        get_string('openbuiltcourse', 'tool_courseuplift')
    );
}

// Omit the navigation button in the printable view; the "Back to the report" link
// above (hidden in the printout) returns to the normal page.
if (!$print) {
    echo $OUTPUT->continue_button(new moodle_url('/admin/tool/courseuplift/index.php'));
}

echo $OUTPUT->footer();
