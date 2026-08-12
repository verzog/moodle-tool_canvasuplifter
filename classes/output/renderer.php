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

namespace tool_canvasuplifter\output;

use html_table;
use html_writer;
use moodle_url;
use plugin_renderer_base;

/**
 * Renderer for the conversion report shown on the analyse status page.
 *
 * The report is produced asynchronously by analyse_package_task and stored as
 * JSON on the job row; status.php decodes it and hands it here. Kept as a
 * renderer so the (string-returning) markup lives in one place rather than
 * being echoed inline from a page.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends plugin_renderer_base {
    /**
     * Render a full conversion report: summary, mapping table, question-type
     * matrix, per-section item detail, unreferenced resources and warnings.
     *
     * @param array $report The decoded conversion report.
     * @return string HTML.
     */
    public function analysis(array $report): string {
        $out = $this->output->heading(get_string('reportheading', 'tool_canvasuplifter'));

        $out .= html_writer::tag(
            'p',
            get_string('coursename', 'tool_canvasuplifter') . ': ' . s($report['coursename'] ?? '') . ' &middot; ' .
            get_string('sectioncount', 'tool_canvasuplifter') . ': ' . (int) ($report['sectioncount'] ?? 0) . ' &middot; ' .
            get_string('itemcount', 'tool_canvasuplifter') . ': ' . (int) ($report['itemcount'] ?? 0)
        );

        // Name the detected source LMS when we recognised one.
        $source = (string) ($report['source'] ?? '');
        if (
            $source !== '' && $source !== 'generic'
            && get_string_manager()->string_exists('source_' . $source, 'tool_canvasuplifter')
        ) {
            $out .= html_writer::tag('p', get_string(
                'detectedsource',
                'tool_canvasuplifter',
                get_string('source_' . $source, 'tool_canvasuplifter')
            ), ['class' => 'text-muted']);
        }

        // Lead with the unsupported-source explanation for a Blackboard-native
        // package so it is the first thing the user sees, rather than being buried
        // under an otherwise-empty mapping table, sections and orphan list. Shown
        // once here, so drop it from the warnings list rendered at the foot.
        $warnings = $report['warnings'] ?? [];
        $nativeshown = false;
        if (in_array('warnblackboardnative', $warnings, true)) {
            $out .= html_writer::tag(
                'p',
                get_string('warnblackboardnative', 'tool_canvasuplifter'),
                ['class' => 'alert alert-warning']
            );
            $warnings = array_values(array_filter($warnings, fn($w) => $w !== 'warnblackboardnative'));
            $nativeshown = true;
        }

        $out .= html_writer::tag('p', get_string('buildsnowsummary', 'tool_canvasuplifter', [
            'now' => (int) ($report['buildsnowtotal'] ?? 0),
            'later' => (int) ($report['latertotal'] ?? 0),
        ]), ['class' => 'alert alert-info']);

        $out .= $this->mapping_table($report['rows'] ?? []);
        $out .= $this->question_matrix($report['questionmatrix'] ?? []);
        $out .= $this->outcomes($report['outcomes'] ?? []);
        $out .= $this->section_detail($report['sections'] ?? []);
        $out .= $this->orphans($report['orphans'] ?? []);
        if (debugging() && !empty($report['unknowntypes'])) {
            $out .= $this->unknown_types($report['unknowntypes']);
        }
        // Skip the "no warnings, straightforward to convert" footer when the only
        // warning was the Blackboard-native alert already shown at the top — that
        // footer would contradict it. Any other warnings still render normally.
        if (!$nativeshown || !empty($warnings)) {
            $out .= $this->warnings($warnings);
        }
        return $out;
    }

    /**
     * The headline content-type mapping table.
     *
     * @param array $rows Report rows.
     * @return string HTML.
     */
    private function mapping_table(array $rows): string {
        $table = new html_table();
        $table->head = [
            get_string('colkind', 'tool_canvasuplifter'),
            get_string('colcount', 'tool_canvasuplifter'),
            get_string('coltarget', 'tool_canvasuplifter'),
            get_string('colbuildsnow', 'tool_canvasuplifter'),
            get_string('colconfidence', 'tool_canvasuplifter'),
            get_string('colnote', 'tool_canvasuplifter'),
        ];
        foreach ($rows as $row) {
            $table->data[] = [
                s($row['kind']),
                (int) $row['count'],
                s($row['target']),
                get_string($row['buildsnow'] ? 'buildsnow_yes' : 'buildsnow_later', 'tool_canvasuplifter'),
                get_string('confidence_' . $row['confidence'], 'tool_canvasuplifter'),
                get_string($row['note'], 'tool_canvasuplifter'),
            ];
        }
        return html_writer::table($table);
    }

    /**
     * The question-type matrix for quiz/question-bank packages, or '' when none.
     *
     * @param array $matrix The questionmatrix sub-report.
     * @return string HTML.
     */
    private function question_matrix(array $matrix): string {
        if (empty($matrix)) {
            return '';
        }
        $out = $this->output->heading(get_string('matrixheading', 'tool_canvasuplifter'), 4);
        $out .= html_writer::tag('p', get_string('matrixexplain', 'tool_canvasuplifter', [
            'supported' => (int) ($matrix['supported'] ?? 0),
            'total' => (int) ($matrix['total'] ?? 0),
        ]));
        $table = new html_table();
        $table->head = [
            get_string('matrixcoltype', 'tool_canvasuplifter'),
            get_string('colcount', 'tool_canvasuplifter'),
            get_string('matrixcolsupported', 'tool_canvasuplifter'),
        ];
        $statuskeys = [
            'yes' => 'matrixsupported_yes',
            'incomplete' => 'matrixsupported_incomplete',
            'unsupported' => 'matrixsupported_no',
        ];
        foreach ($matrix['rows'] ?? [] as $mrow) {
            $typekey = 'qtype_' . $mrow['label'];
            $typelabel = get_string_manager()->string_exists($typekey, 'tool_canvasuplifter')
                ? get_string($typekey, 'tool_canvasuplifter')
                : $mrow['label'];
            $status = $mrow['status'] ?? ($mrow['supported'] ? 'yes' : 'unsupported');
            $converts = get_string($statuskeys[$status] ?? 'matrixsupported_no', 'tool_canvasuplifter');
            // For skipped rows, name the assessments the dropped questions came
            // from so a shortened quiz can't slip through unnoticed.
            if (!empty($mrow['sources'])) {
                $names = array_map(
                    fn($source) => s($source['name']) . ' (' . (int) $source['count'] . ')',
                    $mrow['sources']
                );
                $converts .= ' &mdash; ' . get_string('matrixskippedfrom', 'tool_canvasuplifter', implode(', ', $names));
            }
            $table->data[] = [
                s($typelabel),
                (int) $mrow['count'],
                $converts,
            ];
        }
        return $out . html_writer::table($table);
    }

    /**
     * The learning-outcomes summary, or '' when the package has none.
     *
     * @param array $outcomes The outcomes sub-report.
     * @return string HTML.
     */
    private function outcomes(array $outcomes): string {
        if (empty($outcomes)) {
            return '';
        }
        $out = $this->output->heading(get_string('outcomesheading', 'tool_canvasuplifter'), 4);
        if (!empty($outcomes['malformed'])) {
            return $out . html_writer::tag('p', get_string('outcomesmalformed', 'tool_canvasuplifter'));
        }
        $out .= html_writer::tag('p', get_string('outcomessummary', 'tool_canvasuplifter', [
            'total' => (int) ($outcomes['total'] ?? 0),
            'importable' => (int) ($outcomes['importable'] ?? 0),
            'skipped' => (int) ($outcomes['skipped'] ?? 0),
        ]));
        return $out;
    }

    /**
     * The per-section item detail, each section collapsed in a <details>.
     *
     * @param array $sections Report sections.
     * @return string HTML.
     */
    private function section_detail(array $sections): string {
        if (empty($sections)) {
            return '';
        }
        $out = $this->output->heading(get_string('itemdetailheading', 'tool_canvasuplifter'), 4);
        foreach ($sections as $section) {
            if (empty($section['items'])) {
                continue;
            }
            $table = new html_table();
            $table->head = [
                get_string('coltitle', 'tool_canvasuplifter'),
                get_string('coltarget', 'tool_canvasuplifter'),
                get_string('colbuildsnow', 'tool_canvasuplifter'),
                get_string('colconfidence', 'tool_canvasuplifter'),
            ];
            foreach ($section['items'] as $detailitem) {
                $title = $detailitem['title'] !== '' ? $detailitem['title'] : $detailitem['kind'];
                $table->data[] = [
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
            $out .= html_writer::tag(
                'details',
                html_writer::tag('summary', s($summarytext)) . html_writer::table($table)
            );
        }
        return $out;
    }

    /**
     * The unreferenced-resources table, or '' when there are none.
     *
     * @param array $orphans Report orphans.
     * @return string HTML.
     */
    private function orphans(array $orphans): string {
        if (empty($orphans)) {
            return '';
        }
        $out = $this->output->heading(get_string('orphansheading', 'tool_canvasuplifter'), 4);
        $out .= html_writer::tag('p', get_string('orphansexplain', 'tool_canvasuplifter'));
        $table = new html_table();
        $table->head = [
            get_string('coltitle', 'tool_canvasuplifter'),
            get_string('colkind', 'tool_canvasuplifter'),
            get_string('coltarget', 'tool_canvasuplifter'),
            get_string('colresourcetype', 'tool_canvasuplifter'),
            get_string('colplacement', 'tool_canvasuplifter'),
        ];
        $placements = ['top' => 'placement_top', 'section0' => 'placement_section0', 'extras' => 'placement_extras'];
        foreach ($orphans as $orphan) {
            $placement = $placements[$orphan['placement'] ?? 'extras'] ?? 'placement_extras';
            $table->data[] = [
                s($orphan['title']),
                s($orphan['kind']),
                s($orphan['target']),
                s($orphan['resourcetype'] !== '' ? $orphan['resourcetype'] : '-'),
                get_string($placement, 'tool_canvasuplifter'),
            ];
        }
        return $out . html_writer::table($table);
    }

    /**
     * The debug-only breakdown of unclassified raw CC resource types.
     *
     * @param array $unknowntypes Map of resource type => count.
     * @return string HTML.
     */
    private function unknown_types(array $unknowntypes): string {
        $out = $this->output->heading(get_string('unknownheading', 'tool_canvasuplifter'), 4);
        $table = new html_table();
        $table->head = [
            get_string('colresourcetype', 'tool_canvasuplifter'),
            get_string('colcount', 'tool_canvasuplifter'),
        ];
        foreach ($unknowntypes as $type => $count) {
            $table->data[] = [s($type), (int) $count];
        }
        return $out . html_writer::table($table);
    }

    /**
     * The notes-and-warnings list. Report warnings are language-string keys.
     *
     * @param array $warnings Report warnings (lang-string keys).
     * @return string HTML.
     */
    private function warnings(array $warnings): string {
        $out = $this->output->heading(get_string('warningsheading', 'tool_canvasuplifter'), 4);
        if (empty($warnings)) {
            return $out . html_writer::tag('p', get_string('nowarnings', 'tool_canvasuplifter'));
        }
        $items = array_map(fn($key) => get_string($key, 'tool_canvasuplifter'), $warnings);
        return $out . html_writer::alist($items);
    }

    /**
     * Render the "Build this course" form shown under a finished analysis: a
     * category selector plus the build options already chosen, posting back to
     * index.php to queue the build of the same stored package.
     *
     * @param int $fileid Stored package file id to build from.
     * @param int|null $categoryid Pre-selected category, or null.
     * @param string $pagegrouping Chosen page-grouping option ('', 'book', 'lesson').
     * @param int $quizfrombank Whether to also build quizzes from standalone banks.
     * @return string HTML.
     */
    public function build_from_report_form(int $fileid, ?int $categoryid, string $pagegrouping, int $quizfrombank): string {
        $out = $this->output->heading(get_string('readytobuildheading', 'tool_canvasuplifter'), 4);
        $out .= html_writer::tag('p', get_string('readytobuildexplain', 'tool_canvasuplifter'));

        $categories = \core_course_category::make_categories_list('moodle/course:create');
        $form = html_writer::start_tag('form', [
            'method' => 'post',
            'action' => new moodle_url('/admin/tool/canvasuplifter/index.php'),
            'class' => 'mform',
        ]);
        $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'buildfromreport', 'value' => $fileid]);
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
            $categoryid,
            false,
            ['id' => 'reportcategoryid', 'class' => 'form-control']
        );
        $form .= html_writer::end_div();
        $form .= html_writer::end_div();
        // Page grouping shapes the report itself (it folds page runs into a
        // book/lesson), so it is fixed at analysis time and carried forward
        // silently rather than re-offered here.
        $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'pagegrouping', 'value' => $pagegrouping]);
        // The quiz-from-bank option only adds runnable quizzes and does not change
        // the report, so expose it as a live checkbox: a user acting on the
        // report's nudge can enable it here without re-analysing the package. A
        // preceding hidden 0 makes an unchecked box submit a definite "off".
        $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'quizfrombank', 'value' => '0']);
        $checkboxattrs = [
            'type' => 'checkbox',
            'name' => 'quizfrombank',
            'value' => '1',
            'id' => 'reportquizfrombank',
            'class' => 'form-check-input',
        ];
        if ($quizfrombank) {
            $checkboxattrs['checked'] = 'checked';
        }
        $form .= html_writer::start_div('form-check mb-2');
        $form .= html_writer::empty_tag('input', $checkboxattrs);
        $form .= html_writer::tag(
            'label',
            get_string('quizfrombank', 'tool_canvasuplifter'),
            ['for' => 'reportquizfrombank', 'class' => 'form-check-label']
        );
        $form .= html_writer::end_div();
        $groupinglabels = [
            '' => get_string('pagegrouping_none', 'tool_canvasuplifter'),
            'book' => get_string('pagegrouping_book', 'tool_canvasuplifter'),
            'lesson' => get_string('pagegrouping_lesson', 'tool_canvasuplifter'),
        ];
        $form .= html_writer::tag('p', get_string('chosenbuildoptions', 'tool_canvasuplifter', [
            'grouping' => $groupinglabels[$pagegrouping] ?? $groupinglabels[''],
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
        return $out . $form;
    }
}
