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

namespace tool_canvasuplifter;

use tool_canvasuplifter\local\build\link_rewriter;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the Canvas link rewriter.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\tool_canvasuplifter\local\build\link_rewriter::class)]
final class link_rewriter_test extends \advanced_testcase {
    /**
     * File placeholders resolve to package files and become @@PLUGINFILE@@.
     *
     * @return void
     */
    public function test_rewrite_files_imports_and_rewrites(): void {
        $root = make_request_directory();
        mkdir($root . '/web_resources');
        mkdir($root . '/web_resources/Uploaded Media');
        file_put_contents($root . '/web_resources/logo.png', 'PNG');
        file_put_contents($root . '/web_resources/Uploaded Media/p q.png', 'PNG');

        $html = '<img src="$IMS-CC-FILEBASE$/logo.png">'
            . '<img src="$IMS-CC-FILEBASE$/Uploaded%20Media/p%20q.png">';

        $result = (new link_rewriter())->rewrite_files($html, $root);

        $this->assertStringContainsString('@@PLUGINFILE@@/logo.png', $result['html']);
        $this->assertStringContainsString('@@PLUGINFILE@@/Uploaded%20Media/p%20q.png', $result['html']);
        $this->assertStringNotContainsString('IMS-CC-FILEBASE', $result['html']);
        $this->assertCount(2, $result['files']);

        $names = array_column($result['files'], 'filename');
        $this->assertContains('logo.png', $names);
        $this->assertContains('p q.png', $names);
    }

    /**
     * Canvas names a re-uploaded file "name (2).pdf"; the parentheses belong to the path, so
     * the link resolves (query string dropped), while an unquoted CSS url($IMS-CC-FILEBASE$/...)
     * still ends at its closing parenthesis.
     *
     * @return void
     */
    public function test_rewrite_files_keeps_parentheses_in_names(): void {
        $root = make_request_directory();
        mkdir($root . '/web_resources/Uploaded Media', 0777, true);
        file_put_contents($root . '/web_resources/Society.3 (2).pdf', 'PDF');
        file_put_contents($root . '/web_resources/Uploaded Media/Lab 1 (1).pptx', 'PPTX');
        file_put_contents($root . '/web_resources/bg.png', 'PNG');

        $html = '<a href="$IMS-CC-FILEBASE$/Society.3%20(2).pdf?canvas_=1&amp;canvas_qs_wrap=1">PDF</a>'
            . '<a href="$IMS-CC-FILEBASE$/Uploaded%20Media/Lab%201%20(1).pptx">PPTX</a>'
            . '<div style="background:url($IMS-CC-FILEBASE$/bg.png) no-repeat">x</div>';

        $result = (new link_rewriter())->rewrite_files($html, $root);

        $this->assertSame([], $result['unresolved']);
        $names = array_column($result['files'], 'filename');
        sort($names);
        $this->assertSame(['Lab 1 (1).pptx', 'Society.3 (2).pdf', 'bg.png'], $names);
        $this->assertStringContainsString('url(@@PLUGINFILE@@/bg.png) no-repeat', $result['html']);
        $this->assertStringNotContainsString('IMS-CC-FILEBASE', $result['html']);
    }

    /**
     * CSS url() references written back to back with no separator are each rewritten: the
     * text after the first one's closing parenthesis is scanned for the next.
     *
     * @return void
     */
    public function test_rewrite_files_handles_adjacent_css_urls(): void {
        $root = make_request_directory();
        mkdir($root . '/web_resources');
        file_put_contents($root . '/web_resources/a.png', 'PNG');
        file_put_contents($root . '/web_resources/b (2).png', 'PNG');

        $html = '<div style="background-image:url($IMS-CC-FILEBASE$/a.png),url($IMS-CC-FILEBASE$/b%20(2).png)">x</div>';

        $result = (new link_rewriter())->rewrite_files($html, $root);

        $this->assertSame([], $result['unresolved']);
        $this->assertSame(['a.png', 'b (2).png'], array_column($result['files'], 'filename'));
        $this->assertStringContainsString(
            'url(@@PLUGINFILE@@/a.png),url(@@PLUGINFILE@@/b%20%282%29.png)',
            $result['html']
        );
    }

    /**
     * A file whose name has an unmatched opening parenthesis still resolves inside an unquoted
     * CSS url(), where the closing parenthesis belongs to the url() rather than the name.
     *
     * @return void
     */
    public function test_rewrite_files_resolves_unmatched_open_paren_in_css_url(): void {
        $root = make_request_directory();
        mkdir($root . '/web_resources');
        file_put_contents($root . '/web_resources/foo(bar.png', 'PNG');
        file_put_contents($root . '/web_resources/b.png', 'PNG');

        $html = '<div style="background:url($IMS-CC-FILEBASE$/foo(bar.png),url($IMS-CC-FILEBASE$/b.png)">x</div>';

        $result = (new link_rewriter())->rewrite_files($html, $root);

        $this->assertSame([], $result['unresolved']);
        $this->assertSame(['foo(bar.png', 'b.png'], array_column($result['files'], 'filename'));
        $this->assertStringNotContainsString('IMS-CC-FILEBASE', $result['html']);
    }

    /**
     * A query string ends at a closing parenthesis, so an unmatched "(" name with a query does
     * not swallow the CSS that follows; a reference to a missing file followed by a long run
     * of closing parentheses is looked up a bounded number of times, not once per ")".
     *
     * @return void
     */
    public function test_rewrite_files_bounds_query_and_paren_runs(): void {
        $root = make_request_directory();
        mkdir($root . '/web_resources');
        file_put_contents($root . '/web_resources/foo(bar.png', 'PNG');
        file_put_contents($root . '/web_resources/b.png', 'PNG');

        $html = '<div style="background:url($IMS-CC-FILEBASE$/foo(bar.png?x=1),url($IMS-CC-FILEBASE$/b.png)">x</div>';
        $result = (new link_rewriter())->rewrite_files($html, $root);
        $this->assertSame([], $result['unresolved']);
        $this->assertSame(['foo(bar.png', 'b.png'], array_column($result['files'], 'filename'));
        $this->assertStringContainsString('),url(@@PLUGINFILE@@/b.png)', $result['html']);

        $html = '<p title=$IMS-CC-FILEBASE$/missing(' . str_repeat(')', 100000) . '>x</p>';
        $started = microtime(true);
        $result = (new link_rewriter())->rewrite_files($html, $root);
        $this->assertLessThan(2.0, microtime(true) - $started);
        $this->assertSame($html, $result['html']);
        $this->assertCount(1, $result['unresolved']);
    }

    /**
     * A missing file with an unmatched "(" in an unquoted CSS url() does not swallow the url()
     * after it: the next file is still imported, and the unresolved report names only the
     * missing file.
     *
     * @return void
     */
    public function test_rewrite_files_missing_paren_name_keeps_next_url(): void {
        $root = make_request_directory();
        mkdir($root . '/web_resources');
        file_put_contents($root . '/web_resources/b.png', 'PNG');

        $html = '<div style="background:url($IMS-CC-FILEBASE$/missing(foo.png),url($IMS-CC-FILEBASE$/b.png)">x</div>';
        $result = (new link_rewriter())->rewrite_files($html, $root);

        $this->assertSame(['b.png'], array_column($result['files'], 'filename'));
        $this->assertStringContainsString('url($IMS-CC-FILEBASE$/missing(foo.png),url(@@PLUGINFILE@@/b.png)', $result['html']);
        $this->assertCount(1, $result['unresolved']);
        $this->assertStringEndsWith('missing(foo.png', $result['unresolved'][0]);
    }

    /**
     * A quoted reference to a file whose name has an unmatched "(" resolves the whole name.
     *
     * @return void
     */
    public function test_rewrite_files_quoted_unmatched_open_paren(): void {
        $root = make_request_directory();
        mkdir($root . '/web_resources');
        file_put_contents($root . '/web_resources/foo(bar.png', 'PNG');
        file_put_contents($root . '/web_resources/foo', 'decoy');

        $result = (new link_rewriter())->rewrite_files('<img src="$IMS-CC-FILEBASE$/foo(bar.png">', $root);

        $this->assertSame([], $result['unresolved']);
        $this->assertSame(['foo(bar.png'], array_column($result['files'], 'filename'));
    }

    /**
     * A long run of back-to-back CSS url() references is handled in one pass, without
     * recursion, so it cannot exhaust memory or the call stack.
     *
     * @return void
     */
    public function test_rewrite_files_handles_long_adjacent_url_list(): void {
        $root = make_request_directory();
        mkdir($root . '/web_resources');
        file_put_contents($root . '/web_resources/a.png', 'PNG');

        $count = 20000;
        $started = microtime(true);
        $html = '<div style="background:' . implode(',', array_fill(0, $count, 'url($IMS-CC-FILEBASE$/a.png)')) . '">x</div>';

        $result = (new link_rewriter())->rewrite_files($html, $root);

        $this->assertLessThan(2.0, microtime(true) - $started);
        $this->assertSame($count, substr_count($result['html'], 'url(@@PLUGINFILE@@/a.png)'));
        $this->assertCount(1, $result['files']);
    }

    /**
     * An owner-relative $IMS-CC-FILEBASE$ reference - a bare name beside the owning
     * resource, or a ../ climb into a sibling resource folder - resolves against the
     * owner directory and stores under a clean (no "/../") filearea path, even when
     * the package also has a web_resources/ directory whose ".." would otherwise
     * collapse back to the root.
     *
     * @return void
     */
    public function test_rewrite_files_resolves_owner_relative(): void {
        $root = make_request_directory();
        mkdir($root . '/web_resources');
        mkdir($root . '/I_00003_R');
        mkdir($root . '/I_media_R');
        file_put_contents($root . '/I_00003_R/img1.png', 'PNG');
        file_put_contents($root . '/I_media_R/dog.jpg', 'JPG');

        $html = '<img src="$IMS-CC-FILEBASE$img1.png">'
            . '<img src="$IMS-CC-FILEBASE$../I_media_R/dog.jpg">';

        // Without the owner directory neither resolves (they are not at the root).
        $bare = (new link_rewriter())->rewrite_files($html, $root);
        $this->assertStringContainsString('IMS-CC-FILEBASE', $bare['html']);
        $this->assertSame([], $bare['files']);

        $result = (new link_rewriter())->rewrite_files($html, $root, 'I_00003_R');

        // Each resolves under a clean filearea path derived from the owning folder;
        // crucially neither the URL nor the stored path contains a "/../" segment.
        $this->assertStringContainsString('@@PLUGINFILE@@/I_00003_R/img1.png', $result['html']);
        $this->assertStringContainsString('@@PLUGINFILE@@/I_media_R/dog.jpg', $result['html']);
        $this->assertStringNotContainsString('IMS-CC-FILEBASE', $result['html']);
        $this->assertStringNotContainsString('..', $result['html']);
        $filepaths = array_column($result['files'], 'filepath');
        $this->assertNotContains('/../I_media_R/', $filepaths);
        $this->assertEqualsCanonicalizing(['/I_00003_R/', '/I_media_R/'], $filepaths);
    }

    /**
     * A package-root $IMS-CC-FILEBASE$ reference whose path contains an in-package
     * dot-segment (a/../b) resolves and is stored under the collapsed path, so the
     * filearea path never contains a ".." Moodle would reject.
     *
     * @return void
     */
    public function test_rewrite_files_collapses_in_package_dot_segments(): void {
        $root = make_request_directory();
        mkdir($root . '/a');
        mkdir($root . '/b');
        file_put_contents($root . '/b/dog.jpg', 'JPG');

        $html = '<img src="$IMS-CC-FILEBASE$a/../b/dog.jpg">';
        $result = (new link_rewriter())->rewrite_files($html, $root);

        $this->assertStringContainsString('@@PLUGINFILE@@/b/dog.jpg', $result['html']);
        $this->assertStringNotContainsString('..', $result['html']);
        $this->assertCount(1, $result['files']);
        $this->assertSame('/b/', $result['files'][0]['filepath']);
        $this->assertSame('dog.jpg', $result['files'][0]['filename']);
    }

    /**
     * A reference to a file that isn't in the package is left untouched and
     * reported as unresolved (deduplicated by decoded path) so the build report
     * can surface it.
     *
     * @return void
     */
    public function test_rewrite_files_leaves_unresolved(): void {
        $root = make_request_directory();
        // The same missing asset referenced twice (once URL-encoded) plus a second
        // distinct missing asset: two distinct unresolved references.
        $html = '<img src="$IMS-CC-FILEBASE$/missing.png">'
            . '<img src="%24IMS-CC-FILEBASE%24/missing.png">'
            . '<a href="$IMS-CC-FILEBASE$/docs/handout.pdf">doc</a>';

        $result = (new link_rewriter())->rewrite_files($html, $root);

        $this->assertSame($html, $result['html']);
        $this->assertSame([], $result['files']);
        $this->assertEqualsCanonicalizing(['missing.png', 'docs/handout.pdf'], $result['unresolved']);
    }

    /**
     * An unresolved owner-relative reference is keyed by its path resolved against the
     * owner directory, so the same bare name referenced from two folders stays two
     * distinct missing assets (unit1/logo.png vs unit2/logo.png).
     *
     * @return void
     */
    public function test_rewrite_files_unresolved_keeps_owner_context(): void {
        $root = make_request_directory();
        $html = '<img src="$IMS-CC-FILEBASE$logo.png">';

        $unit1 = (new link_rewriter())->rewrite_files($html, $root, 'unit1');
        $unit2 = (new link_rewriter())->rewrite_files($html, $root, 'unit2');

        $this->assertSame(['unit1/logo.png'], $unit1['unresolved']);
        $this->assertSame(['unit2/logo.png'], $unit2['unresolved']);
    }

    /**
     * An owner-relative ../ climb that lands in a shared folder is normalised before
     * keying, so the same target reached from two different owners collapses to one
     * missing asset rather than inflating the count.
     *
     * @return void
     */
    public function test_rewrite_files_unresolved_normalises_owner_climb(): void {
        $root = make_request_directory();
        $html = '<img src="$IMS-CC-FILEBASE$../shared/logo.png">';

        $unit1 = (new link_rewriter())->rewrite_files($html, $root, 'unit1');
        $unit2 = (new link_rewriter())->rewrite_files($html, $root, 'unit2');

        $this->assertSame(['shared/logo.png'], $unit1['unresolved']);
        $this->assertSame(['shared/logo.png'], $unit2['unresolved']);
    }

    /**
     * A package-root reference ($IMS-CC-FILEBASE$/path) is the same asset wherever it
     * is referenced from, so it is recorded unqualified even with an owner directory -
     * two owners referencing it must not inflate the missing-asset count.
     *
     * @return void
     */
    public function test_rewrite_files_unresolved_root_reference_is_owner_independent(): void {
        $root = make_request_directory();
        $html = '<img src="$IMS-CC-FILEBASE$/shared/logo.png">';

        $unit1 = (new link_rewriter())->rewrite_files($html, $root, 'unit1');
        $unit2 = (new link_rewriter())->rewrite_files($html, $root, 'unit2');

        $this->assertSame(['shared/logo.png'], $unit1['unresolved']);
        $this->assertSame(['shared/logo.png'], $unit2['unresolved']);
    }

    /**
     * A missing rooted reference carrying '.'/'..' segments is normalised before
     * keying, so /a/../shared/logo.png and /shared/logo.png are one missing asset.
     *
     * @return void
     */
    public function test_rewrite_files_unresolved_normalises_rooted_dot_segments(): void {
        $root = make_request_directory();
        $climb = (new link_rewriter())->rewrite_files('<img src="$IMS-CC-FILEBASE$/a/../shared/logo.png">', $root);
        $direct = (new link_rewriter())->rewrite_files('<img src="$IMS-CC-FILEBASE$/shared/logo.png">', $root);

        $this->assertSame(['shared/logo.png'], $climb['unresolved']);
        $this->assertSame(['shared/logo.png'], $direct['unresolved']);
    }

    /**
     * A resolvable reference reports no unresolved references.
     *
     * @return void
     */
    public function test_rewrite_files_resolvable_has_no_unresolved(): void {
        $root = make_request_directory();
        mkdir($root . '/web_resources');
        file_put_contents($root . '/web_resources/logo.png', 'PNG');
        $html = '<img src="$IMS-CC-FILEBASE$/logo.png">';

        $result = (new link_rewriter())->rewrite_files($html, $root);

        $this->assertSame([], $result['unresolved']);
    }

    /**
     * Wiki and object references resolve via the supplied map; unknowns stay.
     *
     * @return void
     */
    public function test_rewrite_internal_links(): void {
        $html = '<a href="$WIKI_REFERENCE$/pages/welcome">Welcome</a>'
            . '<a href="$CANVAS_OBJECT_REFERENCE$/assignments/abc123">Essay</a>'
            . '<a href="$WIKI_REFERENCE$/pages/unmapped">Other</a>';
        $urlmap = [
            'wiki:welcome' => 'https://moodle.test/mod/page/view.php?id=5',
            'id:abc123' => 'https://moodle.test/mod/assign/view.php?id=9',
        ];

        $out = (new link_rewriter())->rewrite_internal_links($html, $urlmap);

        $this->assertStringContainsString('href="https://moodle.test/mod/page/view.php?id=5"', $out);
        $this->assertStringContainsString('href="https://moodle.test/mod/assign/view.php?id=9"', $out);
        // The unmapped reference is preserved rather than broken further.
        $this->assertStringContainsString('$WIKI_REFERENCE$/pages/unmapped', $out);
    }

    /**
     * Course-level references ($CANVAS_COURSE_REFERENCE$/modules, /grades, ...) resolve through
     * their "course:" keys, keeping any ?query/#fragment; one naming a single item
     * (/assignments/<id>) resolves like an object reference; unknown pages stay untouched.
     *
     * @return void
     */
    public function test_rewrite_course_references(): void {
        $urlmap = [
            'course:' => 'https://moodle.test/course/view.php?id=4',
            'course:modules' => 'https://moodle.test/course/view.php?id=4',
            'course:grades' => 'https://moodle.test/grade/report/index.php?id=4',
            'course:assignments/syllabus' => 'https://moodle.test/course/view.php?id=4',
            'id:g123' => 'https://moodle.test/mod/assign/view.php?id=9',
        ];
        $html = '<a href="$CANVAS_COURSE_REFERENCE$/modules">Modules</a>'
            . '<a href="%24CANVAS_COURSE_REFERENCE%24/grades?tab=1#top">Grades</a>'
            . '<a href="$CANVAS_COURSE_REFERENCE$/assignments/syllabus">Syllabus</a>'
            . '<a href="$CANVAS_COURSE_REFERENCE$/assignments/g123">Essay</a>'
            . '<a href="$CANVAS_COURSE_REFERENCE$">Home</a>'
            . '<a href="$CANVAS_COURSE_REFERENCE$/collaborations">Collaborations</a>';

        $out = (new link_rewriter())->rewrite_internal_links($html, $urlmap);

        $this->assertSame(3, substr_count($out, 'href="https://moodle.test/course/view.php?id=4"'));
        $this->assertStringContainsString('href="https://moodle.test/grade/report/index.php?id=4&tab=1#top"', $out);
        $this->assertStringContainsString('href="https://moodle.test/mod/assign/view.php?id=9"', $out);
        // A Canvas page with no Moodle counterpart is left as it was.
        $this->assertStringContainsString('$CANVAS_COURSE_REFERENCE$/collaborations', $out);
    }

    /**
     * A relative cross-resource link (ILIAS module-to-module) that resolves to a
     * built resource becomes a $CANVAS_OBJECT_REFERENCE$ token, which the
     * internal-link pass then turns into the activity URL; query/fragment
     * suffixes survive, and the page's own assets are left for bundle rewriting.
     *
     * @return void
     */
    public function test_rewrite_relative_links_resolves_cross_resource(): void {
        $html = '<a href="../NTERID_LM_00011919_R/index.html">Next module</a>'
            . '<a href="../docs/handout.pdf#page=2">Handout</a>'
            . '<img src="media/diagram.png">';
        $pathtoid = [
            'NTERID_LM_00011919_R/index.html' => 'NTERID_LM_00011919_R',
            'docs/handout.pdf' => 'res_handout',
        ];

        $rewriter = new link_rewriter();
        $out = $rewriter->rewrite_relative_links($html, 'NTERID_FOLD_00011918_INTRO_R', $pathtoid);

        $this->assertStringContainsString('href="$CANVAS_OBJECT_REFERENCE$/ilias/NTERID_LM_00011919_R"', $out);
        $this->assertStringContainsString('href="$CANVAS_OBJECT_REFERENCE$/ilias/res_handout#page=2"', $out);
        // The same second pass that handles Canvas object refs resolves these.
        $resolved = $rewriter->rewrite_internal_links($out, [
            'id:NTERID_LM_00011919_R' => 'https://moodle.test/mod/page/view.php?id=42',
            'id:res_handout' => 'https://moodle.test/mod/resource/view.php?id=43',
        ]);
        $this->assertStringContainsString('href="https://moodle.test/mod/page/view.php?id=42"', $resolved);
        $this->assertStringContainsString('href="https://moodle.test/mod/resource/view.php?id=43#page=2"', $resolved);
    }

    /**
     * Absolute URLs, scheme links, in-page anchors and references that don't
     * resolve to a built resource are left exactly as they were.
     *
     * @return void
     */
    public function test_rewrite_relative_links_leaves_non_matching(): void {
        $html = '<a href="https://example.org/page">External</a>'
            . '<a href="mailto:info@aset.org">Mail</a>'
            . '<a href="#section-2">Jump</a>'
            . '<a href="/root/absolute.html">Root</a>'
            . '<a href="../OTHER_LM/index.html">Unmapped</a>'
            . '<link href="style.css">';
        $pathtoid = ['NTERID_LM_00011919_R/index.html' => 'NTERID_LM_00011919_R'];

        $out = (new link_rewriter())->rewrite_relative_links($html, 'NTERID_FOLD_00011918_INTRO_R', $pathtoid);

        $this->assertSame($html, $out);
    }

    /**
     * An empty path map is a no-op, so non-ILIAS imports pay nothing.
     *
     * @return void
     */
    public function test_rewrite_relative_links_empty_map_is_noop(): void {
        $html = '<a href="../NTERID_LM_00011919_R/index.html">Next</a>';
        $this->assertSame($html, (new link_rewriter())->rewrite_relative_links($html, 'a/b', []));
    }

    /**
     * Only the href of navigational <a> anchors is rewritten. A relative
     * stylesheet (or other non-anchor reference) whose path also backs a file
     * resource is left alone, so the page keeps its CSS after import.
     *
     * @return void
     */
    public function test_rewrite_relative_links_only_touches_anchors(): void {
        $html = '<link rel="stylesheet" href="../shared/site.css">'
            . '<a href="../shared/site.css">Download the stylesheet</a>';
        $pathtoid = ['shared/site.css' => 'res_css'];

        $out = (new link_rewriter())->rewrite_relative_links($html, 'lessons', $pathtoid);

        // The <link> stylesheet reference is untouched.
        $this->assertStringContainsString('<link rel="stylesheet" href="../shared/site.css">', $out);
        // The <a> navigational link to the same file is rewritten.
        $this->assertStringContainsString('<a href="$CANVAS_OBJECT_REFERENCE$/ilias/res_css">', $out);
    }

    /**
     * A preserved query suffix is joined to the resolved activity URL with "&",
     * not a second "?", so the generated link is valid (the activity URLs
     * already carry ?id=...). Fragments append as-is.
     *
     * @return void
     */
    public function test_object_reference_suffix_joins_without_double_question(): void {
        $rewriter = new link_rewriter();
        $urlmap = ['id:r1' => 'https://moodle.test/mod/page/view.php?id=42'];

        $query = $rewriter->rewrite_internal_links('<a href="$CANVAS_OBJECT_REFERENCE$/ilias/r1?obj=5">x</a>', $urlmap);
        $this->assertStringContainsString('href="https://moodle.test/mod/page/view.php?id=42&obj=5"', $query);
        $this->assertStringNotContainsString('?id=42?', $query);

        $frag = $rewriter->rewrite_internal_links('<a href="$CANVAS_OBJECT_REFERENCE$/ilias/r1#sec">x</a>', $urlmap);
        $this->assertStringContainsString('href="https://moodle.test/mod/page/view.php?id=42#sec"', $frag);

        $both = $rewriter->rewrite_internal_links('<a href="$CANVAS_OBJECT_REFERENCE$/ilias/r1?a=1#s">x</a>', $urlmap);
        $this->assertStringContainsString('href="https://moodle.test/mod/page/view.php?id=42&a=1#s"', $both);
    }

    /**
     * End to end, a relative <a> link carrying a query string resolves to a
     * valid activity URL: the relative rewrite preserves the suffix and the
     * object-reference pass joins it correctly.
     *
     * @return void
     */
    public function test_relative_link_query_suffix_stays_valid(): void {
        $rewriter = new link_rewriter();
        $pathtoid = ['lm_b/index.html' => 'r_b'];

        $tokenised = $rewriter->rewrite_relative_links('<a href="../lm_b/index.html?obj=5">B</a>', 'lm_a', $pathtoid);
        $resolved = $rewriter->rewrite_internal_links($tokenised, ['id:r_b' => 'https://moodle.test/mod/page/view.php?id=7']);

        $this->assertStringContainsString('href="https://moodle.test/mod/page/view.php?id=7&obj=5"', $resolved);
        $this->assertStringNotContainsString('?id=7?', $resolved);
    }

    /**
     * normalize_path() collapses '.'/'..' against the base directory and refuses
     * references that climb above the package root.
     *
     * @return void
     */
    public function test_normalize_path(): void {
        $this->assertSame(
            'NTERID_LM_00011919_R/index.html',
            link_rewriter::normalize_path('NTERID_FOLD_00011918_INTRO_R', '../NTERID_LM_00011919_R/index.html')
        );
        $this->assertSame('a/b/c.html', link_rewriter::normalize_path('a/b', './c.html'));
        $this->assertSame('top.html', link_rewriter::normalize_path('', 'top.html'));
        $this->assertSame('x/z.html', link_rewriter::normalize_path('x/y', '../y/../z.html'));
        // Climbs above the root -> unresolvable.
        $this->assertNull(link_rewriter::normalize_path('a', '../../escape.html'));
    }
}
