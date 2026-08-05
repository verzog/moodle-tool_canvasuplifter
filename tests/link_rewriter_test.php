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

/**
 * Tests for the Canvas link rewriter.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\build\link_rewriter
 */
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
     * A reference to a file that isn't in the package is left untouched.
     *
     * @return void
     */
    public function test_rewrite_files_leaves_unresolved(): void {
        $root = make_request_directory();
        $html = '<img src="$IMS-CC-FILEBASE$/missing.png">';

        $result = (new link_rewriter())->rewrite_files($html, $root);

        $this->assertSame($html, $result['html']);
        $this->assertSame([], $result['files']);
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
