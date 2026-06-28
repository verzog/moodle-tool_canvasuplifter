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

use tool_canvasuplifter\local\build\course_builder;
use tool_canvasuplifter\local\parser\manifest_parser;

/**
 * Tests folding a self-contained interactive HTML exercise (an HTML file plus a
 * folder of js/css/image assets, each a separate webcontent resource) into a
 * single embedded mod_resource.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\parser\manifest_parser
 * @covers     \tool_canvasuplifter\local\build\file_builder
 */
final class html_bundle_test extends \advanced_testcase {
    /**
     * Build a package shaped like a Canvas-exported interactive exercise:
     * web_resources/index.html referencing assets/{js,css,images}, with each
     * asset a separate unreferenced webcontent resource, and one image referenced
     * only by the stylesheet's url().
     *
     * @return string Path to the package root.
     */
    private function build_fixture(): string {
        $dir = make_request_directory();
        mkdir($dir . '/web_resources/assets/js', 0777, true);
        mkdir($dir . '/web_resources/assets/css', 0777, true);
        mkdir($dir . '/web_resources/assets/images', 0777, true);
        $html = '<!DOCTYPE html><html><head><title>Memorization Exercise</title>'
            . '<link rel="stylesheet" href="assets/css/global_style.css"/>'
            . '<script src="assets/js/main.js"></script></head>'
            . '<body><img id="face" src="assets/images/human-head.png"/>'
            . '<a href="https://example.org/help">help</a></body></html>';
        file_put_contents($dir . '/web_resources/index.html', $html);
        file_put_contents($dir . '/web_resources/assets/js/main.js', '// main');
        $css = '.bg{background:url("../images/bullet.png")}';
        file_put_contents($dir . '/web_resources/assets/css/global_style.css', $css);
        file_put_contents($dir . '/web_resources/assets/images/human-head.png', 'PNG-HEAD');
        file_put_contents($dir . '/web_resources/assets/images/bullet.png', 'PNG-BULLET');

        $res = function (string $id, string $path): string {
            return '<resource type="webcontent" identifier="' . $id . '" href="' . $path . '">'
                . '<file href="' . $path . '"/></resource>';
        };
        $manifest = '<?xml version="1.0"?>'
            . '<manifest identifier="m" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">'
            . '<organizations><organization identifier="o"><item identifier="root">'
            . '<item identifier="m1"><title>Module 1</title>'
            . '<item identifier="i_ex" identifierref="res_index"><title>Memorization Exercise</title></item>'
            . '</item></item></organization></organizations><resources>'
            . $res('res_index', 'web_resources/index.html')
            . $res('r_js', 'web_resources/assets/js/main.js')
            . $res('r_css', 'web_resources/assets/css/global_style.css')
            . $res('r_img', 'web_resources/assets/images/human-head.png')
            . $res('r_bullet', 'web_resources/assets/images/bullet.png')
            . '</resources></manifest>';
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        return $dir;
    }

    /**
     * The exercise builds as one embedded resource holding the HTML and all its
     * assets (including the image referenced only by the stylesheet), and the
     * standalone asset resources do not surface as their own activities.
     *
     * @return void
     */
    public function test_exercise_folds_into_one_embedded_resource(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/resourcelib.php');
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_fixture();
        $coursemodel = (new manifest_parser($root))->parse();
        $category = $this->getDataGenerator()->create_category();
        $report = (new course_builder($category->id, $root))->build($coursemodel);

        // One file activity, not five (the four assets folded in, not separate).
        $this->assertSame(1, $report['createdcounts']['file'] ?? 0);
        $this->assertEquals(1, $DB->count_records('resource'));

        $resource = $DB->get_record('resource', []);
        $this->assertEquals('Memorization Exercise', $resource->name);
        $this->assertEquals(RESOURCELIB_DISPLAY_EMBED, (int) $resource->display);

        // The resource's filearea holds the HTML plus every asset at its relative
        // path — including bullet.png, which only the stylesheet's url() names.
        $cm = get_coursemodule_from_instance('resource', $resource->id);
        $context = \context_module::instance($cm->id);
        $fs = get_file_storage();
        $paths = [];
        foreach ($fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'id', false) as $f) {
            $paths[] = $f->get_filepath() . $f->get_filename();
        }
        sort($paths);
        $this->assertSame([
            '/assets/css/global_style.css',
            '/assets/images/bullet.png',
            '/assets/images/human-head.png',
            '/assets/js/main.js',
            '/index.html',
        ], $paths);
    }

    /**
     * Build a richer exercise whose assets live in sibling folders (referenced
     * with ../), arrive via srcset, inline <style>, a recursive CSS @import, and
     * an extra file a script fetches at runtime; one asset (the video) is also
     * placed in the course on its own.
     *
     * @return string Path to the package root.
     */
    private function build_rich_fixture(): string {
        $dir = make_request_directory();
        $subdirs = ['exercise/pages', 'exercise/shared/data', 'exercise/assets/fonts',
            'exercise/assets/img', 'exercise/media'];
        foreach ($subdirs as $sub) {
            mkdir($dir . '/' . $sub, 0777, true);
        }
        $html = '<!DOCTYPE html><html><head>'
            . '<link rel="stylesheet" href="../assets/base.css"/>'
            . '<script src="../shared/app.js"></script>'
            . '<style>.hero{background:url(../assets/img/inline-bg.png)}</style>'
            . '</head><body>'
            . '<img src="../assets/img/small.png" '
            . 'srcset="../assets/img/small.png 1x, ../assets/img/large.png 2x"/>'
            . '<video src="../media/demo.mp4"></video>'
            . '</body></html>';
        file_put_contents($dir . '/exercise/pages/index.html', $html);
        file_put_contents($dir . '/exercise/shared/app.js', "fetch('data/config.json');");
        file_put_contents($dir . '/exercise/shared/data/config.json', '{"ok":true}');
        file_put_contents($dir . '/exercise/assets/base.css', '@import "theme.css";');
        file_put_contents($dir . '/exercise/assets/theme.css', '@font-face{src:url(fonts/f.woff2)}');
        file_put_contents($dir . '/exercise/assets/fonts/f.woff2', 'WOFF2');
        file_put_contents($dir . '/exercise/assets/img/small.png', 'SMALL');
        file_put_contents($dir . '/exercise/assets/img/large.png', 'LARGE');
        file_put_contents($dir . '/exercise/assets/img/inline-bg.png', 'INLINE');
        file_put_contents($dir . '/exercise/media/demo.mp4', 'MP4');

        $res = function (string $id, string $href, array $files): string {
            $xml = '<resource type="webcontent" identifier="' . $id . '" href="' . $href . '">';
            foreach ($files as $f) {
                $xml .= '<file href="' . $f . '"/>';
            }
            return $xml . '</resource>';
        };
        $manifest = '<?xml version="1.0"?>'
            . '<manifest identifier="m" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">'
            . '<organizations><organization identifier="o"><item identifier="root">'
            . '<item identifier="m1"><title>Module 1</title>'
            . '<item identifier="i_ex" identifierref="res_index"><title>Interactive Exercise</title></item>'
            . '<item identifier="i_demo" identifierref="r_demo"><title>Demo video</title></item>'
            . '</item></item></organization></organizations><resources>'
            . $res('res_index', 'exercise/pages/index.html', ['exercise/pages/index.html'])
            . $res('r_app', 'exercise/shared/app.js', ['exercise/shared/app.js', 'exercise/shared/data/config.json'])
            . $res('r_basecss', 'exercise/assets/base.css', ['exercise/assets/base.css'])
            . $res('r_theme', 'exercise/assets/theme.css', ['exercise/assets/theme.css'])
            . $res('r_font', 'exercise/assets/fonts/f.woff2', ['exercise/assets/fonts/f.woff2'])
            . $res('r_small', 'exercise/assets/img/small.png', ['exercise/assets/img/small.png'])
            . $res('r_large', 'exercise/assets/img/large.png', ['exercise/assets/img/large.png'])
            . $res('r_inlinebg', 'exercise/assets/img/inline-bg.png', ['exercise/assets/img/inline-bg.png'])
            . $res('r_demo', 'exercise/media/demo.mp4', ['exercise/media/demo.mp4'])
            . '</resources></manifest>';
        file_put_contents($dir . '/imsmanifest.xml', $manifest);
        return $dir;
    }

    /**
     * The richer exercise folds sibling-folder (../), srcset, inline-CSS and
     * recursively-imported assets plus a script's runtime-fetched data file into
     * one embedded resource (its filearea rebased to the common ancestor so the
     * ../ references still resolve), while the separately placed video still
     * builds as its own activity.
     *
     * @return void
     */
    public function test_rich_exercise_folds_siblings_and_keeps_placed_asset(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/resourcelib.php');
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $root = $this->build_rich_fixture();
        $coursemodel = (new manifest_parser($root))->parse();
        $category = $this->getDataGenerator()->create_category();
        $report = (new course_builder($category->id, $root))->build($coursemodel);

        // Two file activities: the embedded exercise and the standalone video
        // that the course also placed on its own (not hidden by the fold).
        $this->assertSame(2, $report['createdcounts']['file'] ?? 0);
        $this->assertEquals(2, $DB->count_records('resource'));

        $exercise = $DB->get_record('resource', ['name' => 'Interactive Exercise']);
        $this->assertNotEmpty($exercise, 'the exercise should build as its own resource');
        $this->assertEquals(RESOURCELIB_DISPLAY_EMBED, (int) $exercise->display);
        $this->assertNotEmpty(
            $DB->get_record('resource', ['name' => 'Demo video']),
            'a separately placed asset must still build as its own activity'
        );

        // The exercise filearea holds the HTML (kept in its pages/ subfolder so
        // ../ links resolve) and every folded asset at its common-ancestor path —
        // including the srcset variant, the inline-CSS background, the recursively
        // @imported theme and font, the video, and the script's fetched data.
        $cm = get_coursemodule_from_instance('resource', $exercise->id);
        $context = \context_module::instance($cm->id);
        $fs = get_file_storage();
        $paths = [];
        foreach ($fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'id', false) as $f) {
            $paths[] = $f->get_filepath() . $f->get_filename();
        }
        sort($paths);
        $this->assertSame([
            '/assets/base.css',
            '/assets/fonts/f.woff2',
            '/assets/img/inline-bg.png',
            '/assets/img/large.png',
            '/assets/img/small.png',
            '/assets/theme.css',
            '/media/demo.mp4',
            '/pages/index.html',
            '/shared/app.js',
            '/shared/data/config.json',
        ], $paths);
    }

    /**
     * Return the sorted filearea paths of a resource's content area.
     *
     * @param int $resourceid The mod_resource instance id.
     * @return array Sorted '/path/name' strings.
     */
    private function resource_filearea_paths(int $resourceid): array {
        $cm = get_coursemodule_from_instance('resource', $resourceid);
        $context = \context_module::instance($cm->id);
        $fs = get_file_storage();
        $paths = [];
        foreach ($fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'id', false) as $f) {
            $paths[] = $f->get_filepath() . $f->get_filename();
        }
        sort($paths);
        return $paths;
    }

    /**
     * An asset resource shared by two exercises (its runtime-fetched data file
     * owned alongside the script) is folded into both bundles, not just the
     * first one to claim it.
     *
     * @return void
     */
    public function test_shared_asset_resource_folds_into_every_bundle(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/resourcelib.php');
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $dir = make_request_directory();
        mkdir($dir . '/ex1', 0777, true);
        mkdir($dir . '/ex2', 0777, true);
        mkdir($dir . '/shared/data', 0777, true);
        $page = fn(string $t) => '<!DOCTYPE html><html><head><title>' . $t . '</title>'
            . '<script src="../shared/app.js"></script></head><body></body></html>';
        file_put_contents($dir . '/ex1/a.html', $page('Exercise One'));
        file_put_contents($dir . '/ex2/b.html', $page('Exercise Two'));
        file_put_contents($dir . '/shared/app.js', "fetch('data/config.json');");
        file_put_contents($dir . '/shared/data/config.json', '{"ok":true}');
        $manifest = '<?xml version="1.0"?>'
            . '<manifest identifier="m" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">'
            . '<organizations><organization identifier="o"><item identifier="root">'
            . '<item identifier="m1"><title>Module 1</title>'
            . '<item identifier="i1" identifierref="res_ex1"><title>Exercise One</title></item>'
            . '<item identifier="i2" identifierref="res_ex2"><title>Exercise Two</title></item>'
            . '</item></item></organization></organizations><resources>'
            . '<resource type="webcontent" identifier="res_ex1" href="ex1/a.html"><file href="ex1/a.html"/></resource>'
            . '<resource type="webcontent" identifier="res_ex2" href="ex2/b.html"><file href="ex2/b.html"/></resource>'
            . '<resource type="webcontent" identifier="r_app" href="shared/app.js">'
            . '<file href="shared/app.js"/><file href="shared/data/config.json"/></resource>'
            . '</resources></manifest>';
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $coursemodel = (new manifest_parser($dir))->parse();
        $category = $this->getDataGenerator()->create_category();
        (new course_builder($category->id, $dir))->build($coursemodel);

        $this->assertEquals(2, $DB->count_records('resource'));
        foreach (['Exercise One', 'Exercise Two'] as $name) {
            $resource = $DB->get_record('resource', ['name' => $name]);
            $this->assertNotEmpty($resource, "$name should build");
            $this->assertContains(
                '/shared/data/config.json',
                $this->resource_filearea_paths((int) $resource->id),
                "$name must carry the shared resource's runtime-fetched data file"
            );
        }
    }

    /**
     * When the HTML resource lists an asset <file> before the HTML href, the
     * embedded resource still serves the HTML as its main file, not the asset's
     * bytes under the HTML's name.
     *
     * @return void
     */
    public function test_html_pinned_as_main_file_despite_file_order(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/resourcelib.php');
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $dir = make_request_directory();
        mkdir($dir . '/page', 0777, true);
        $htmlbody = '<!DOCTYPE html><html><head><title>Ordered</title>'
            . '<link rel="stylesheet" href="style.css"/></head><body>MARKER</body></html>';
        file_put_contents($dir . '/page/index.html', $htmlbody);
        file_put_contents($dir . '/page/style.css', '.x{color:red}');
        // The exporter lists the stylesheet <file> ahead of the HTML href.
        $manifest = '<?xml version="1.0"?>'
            . '<manifest identifier="m" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">'
            . '<organizations><organization identifier="o"><item identifier="root">'
            . '<item identifier="m1"><title>Module 1</title>'
            . '<item identifier="i_ex" identifierref="res_index"><title>Ordered Exercise</title></item>'
            . '</item></item></organization></organizations><resources>'
            . '<resource type="webcontent" identifier="res_index" href="page/index.html">'
            . '<file href="page/style.css"/><file href="page/index.html"/></resource>'
            . '</resources></manifest>';
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $coursemodel = (new manifest_parser($dir))->parse();
        $category = $this->getDataGenerator()->create_category();
        (new course_builder($category->id, $dir))->build($coursemodel);

        $resource = $DB->get_record('resource', ['name' => 'Ordered Exercise']);
        $this->assertNotEmpty($resource);
        $cm = get_coursemodule_from_instance('resource', $resource->id);
        $context = \context_module::instance($cm->id);
        $fs = get_file_storage();
        $html = $fs->get_file($context->id, 'mod_resource', 'content', 0, '/', 'index.html');
        $this->assertNotEmpty($html, 'the HTML must be stored under its own name');
        $this->assertStringContainsString(
            'MARKER',
            $html->get_content(),
            'index.html must hold the HTML payload, not the stylesheet bytes'
        );
    }

    /**
     * A local relative <base href> shifts where the page's relative references
     * resolve, so the sibling-folder assets it points at are folded in (with the
     * filearea rebased so the base still resolves once embedded).
     *
     * @return void
     */
    public function test_relative_base_href_rebases_asset_resolution(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/resourcelib.php');
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $dir = make_request_directory();
        mkdir($dir . '/pages', 0777, true);
        mkdir($dir . '/assets', 0777, true);
        $html = '<!DOCTYPE html><html><head><base href="../assets/"/>'
            . '<link rel="stylesheet" href="theme.css"/>'
            . '<script src="app.js"></script></head><body></body></html>';
        file_put_contents($dir . '/pages/index.html', $html);
        file_put_contents($dir . '/assets/app.js', '// app');
        file_put_contents($dir . '/assets/theme.css', '.x{}');
        $manifest = '<?xml version="1.0"?>'
            . '<manifest identifier="m" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">'
            . '<organizations><organization identifier="o"><item identifier="root">'
            . '<item identifier="m1"><title>Module 1</title>'
            . '<item identifier="i_ex" identifierref="res_index"><title>Based Exercise</title></item>'
            . '</item></item></organization></organizations><resources>'
            . '<resource type="webcontent" identifier="res_index" href="pages/index.html">'
            . '<file href="pages/index.html"/></resource>'
            . '<resource type="webcontent" identifier="r_app" href="assets/app.js"><file href="assets/app.js"/></resource>'
            . '<resource type="webcontent" identifier="r_css" href="assets/theme.css">'
            . '<file href="assets/theme.css"/></resource>'
            . '</resources></manifest>';
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $coursemodel = (new manifest_parser($dir))->parse();
        $category = $this->getDataGenerator()->create_category();
        (new course_builder($category->id, $dir))->build($coursemodel);

        $this->assertEquals(1, $DB->count_records('resource'));
        $resource = $DB->get_record('resource', ['name' => 'Based Exercise']);
        $this->assertEquals(RESOURCELIB_DISPLAY_EMBED, (int) $resource->display);
        $this->assertSame([
            '/assets/app.js',
            '/assets/theme.css',
            '/pages/index.html',
        ], $this->resource_filearea_paths((int) $resource->id));
    }

    /**
     * An absolute/external <base href> makes the document's relative references
     * point outside the package, so nothing is folded and the HTML is left as a
     * plain file resource rather than mis-folding the wrong local files.
     *
     * @return void
     */
    public function test_external_base_href_is_left_unfolded(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/resourcelib.php');
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $dir = make_request_directory();
        mkdir($dir . '/ex', 0777, true);
        $html = '<!DOCTYPE html><html><head><base href="https://cdn.example.com/"/>'
            . '<script src="app.js"></script></head><body></body></html>';
        file_put_contents($dir . '/ex/index.html', $html);
        file_put_contents($dir . '/ex/app.js', '// local file the external base does NOT point at');
        $manifest = '<?xml version="1.0"?>'
            . '<manifest identifier="m" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">'
            . '<organizations><organization identifier="o"><item identifier="root">'
            . '<item identifier="m1"><title>Module 1</title>'
            . '<item identifier="i_ex" identifierref="res_index"><title>CDN Exercise</title></item>'
            . '<item identifier="i_js" identifierref="r_app"><title>App Script</title></item>'
            . '</item></item></organization></organizations><resources>'
            . '<resource type="webcontent" identifier="res_index" href="ex/index.html">'
            . '<file href="ex/index.html"/></resource>'
            . '<resource type="webcontent" identifier="r_app" href="ex/app.js"><file href="ex/app.js"/></resource>'
            . '</resources></manifest>';
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $coursemodel = (new manifest_parser($dir))->parse();
        $category = $this->getDataGenerator()->create_category();
        (new course_builder($category->id, $dir))->build($coursemodel);

        // The HTML was not folded: it builds as a plain (non-embedded) resource,
        // and the local app.js still builds as its own placed activity.
        $this->assertEquals(2, $DB->count_records('resource'));
        $resource = $DB->get_record('resource', ['name' => 'CDN Exercise']);
        $this->assertNotEquals(RESOURCELIB_DISPLAY_EMBED, (int) $resource->display);
        $this->assertSame(['/index.html'], $this->resource_filearea_paths((int) $resource->id));
    }

    /**
     * A dot-segment base without a trailing slash (e.g. <base href="..">) is a
     * path operation, not a filename: it must normalise to the parent folder, so
     * a root-level asset is folded — not a same-named file beside the HTML.
     *
     * @return void
     */
    public function test_dot_segment_base_href_normalises_to_parent(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/resourcelib.php');
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $dir = make_request_directory();
        mkdir($dir . '/pages', 0777, true);
        $html = '<!DOCTYPE html><html><head><base href=".."/>'
            . '<script src="app.js"></script></head><body></body></html>';
        file_put_contents($dir . '/pages/index.html', $html);
        // The real asset is at the package root; a decoy sits beside the HTML.
        file_put_contents($dir . '/app.js', '// root app the base points at');
        file_put_contents($dir . '/pages/app.js', '// decoy beside the HTML');
        $manifest = '<?xml version="1.0"?>'
            . '<manifest identifier="m" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">'
            . '<organizations><organization identifier="o"><item identifier="root">'
            . '<item identifier="m1"><title>Module 1</title>'
            . '<item identifier="i_ex" identifierref="res_index"><title>Dotted Exercise</title></item>'
            . '</item></item></organization></organizations><resources>'
            . '<resource type="webcontent" identifier="res_index" href="pages/index.html">'
            . '<file href="pages/index.html"/></resource>'
            . '<resource type="webcontent" identifier="r_app" href="app.js"><file href="app.js"/></resource>'
            . '</resources></manifest>';
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $coursemodel = (new manifest_parser($dir))->parse();
        $category = $this->getDataGenerator()->create_category();
        (new course_builder($category->id, $dir))->build($coursemodel);

        $resource = $DB->get_record('resource', ['name' => 'Dotted Exercise']);
        $this->assertEquals(RESOURCELIB_DISPLAY_EMBED, (int) $resource->display);
        // The root app.js (folded at /app.js) is pulled in, beside the HTML kept
        // in its pages/ subfolder — not the pages/app.js decoy.
        $this->assertSame([
            '/app.js',
            '/pages/index.html',
        ], $this->resource_filearea_paths((int) $resource->id));
    }

    /**
     * The first <base> with an href attribute wins, even when that href is
     * empty: an empty first base leaves resolution at the HTML's own folder, so
     * a later base is ignored and the same-folder asset is folded.
     *
     * @return void
     */
    public function test_first_empty_base_href_wins_over_later_base(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/resourcelib.php');
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $dir = make_request_directory();
        mkdir($dir . '/ex', 0777, true);
        mkdir($dir . '/assets', 0777, true);
        $html = '<!DOCTYPE html><html><head><base href=""/><base href="../assets/"/>'
            . '<script src="app.js"></script></head><body></body></html>';
        file_put_contents($dir . '/ex/index.html', $html);
        file_put_contents($dir . '/ex/app.js', '// same-folder app the empty base keeps');
        file_put_contents($dir . '/assets/app.js', '// decoy the ignored ../assets/ base would pick');
        $manifest = '<?xml version="1.0"?>'
            . '<manifest identifier="m" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">'
            . '<organizations><organization identifier="o"><item identifier="root">'
            . '<item identifier="m1"><title>Module 1</title>'
            . '<item identifier="i_ex" identifierref="res_index"><title>Empty Base Exercise</title></item>'
            . '</item></item></organization></organizations><resources>'
            . '<resource type="webcontent" identifier="res_index" href="ex/index.html">'
            . '<file href="ex/index.html"/></resource>'
            . '<resource type="webcontent" identifier="r_app" href="ex/app.js"><file href="ex/app.js"/></resource>'
            . '<resource type="webcontent" identifier="r_decoy" href="assets/app.js">'
            . '<file href="assets/app.js"/></resource>'
            . '</resources></manifest>';
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $coursemodel = (new manifest_parser($dir))->parse();
        $category = $this->getDataGenerator()->create_category();
        (new course_builder($category->id, $dir))->build($coursemodel);

        $resource = $DB->get_record('resource', ['name' => 'Empty Base Exercise']);
        $this->assertEquals(RESOURCELIB_DISPLAY_EMBED, (int) $resource->display);
        // The same-folder app.js is folded (filearea root 'ex'); the ../assets/
        // decoy would instead produce /assets/app.js + /ex/index.html.
        $this->assertSame([
            '/app.js',
            '/index.html',
        ], $this->resource_filearea_paths((int) $resource->id));
    }

    /**
     * When an external <base> disables folding, the HTML still builds as the
     * activity's main file even if the manifest lists a secondary asset ahead of
     * it in the resource's <file> list.
     *
     * @return void
     */
    public function test_external_base_keeps_html_as_main_file(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/resourcelib.php');
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $dir = make_request_directory();
        mkdir($dir . '/ex', 0777, true);
        $html = '<!DOCTYPE html><html><head><base href="https://cdn.example.com/"/>'
            . '<link rel="stylesheet" href="style.css"/></head><body>MARKER</body></html>';
        file_put_contents($dir . '/ex/index.html', $html);
        file_put_contents($dir . '/ex/style.css', '.x{color:red}');
        // The exporter lists the stylesheet <file> ahead of the HTML href.
        $manifest = '<?xml version="1.0"?>'
            . '<manifest identifier="m" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1">'
            . '<organizations><organization identifier="o"><item identifier="root">'
            . '<item identifier="m1"><title>Module 1</title>'
            . '<item identifier="i_ex" identifierref="res_index"><title>CDN Ordered Exercise</title></item>'
            . '</item></item></organization></organizations><resources>'
            . '<resource type="webcontent" identifier="res_index" href="ex/index.html">'
            . '<file href="ex/style.css"/><file href="ex/index.html"/></resource>'
            . '</resources></manifest>';
        file_put_contents($dir . '/imsmanifest.xml', $manifest);

        $coursemodel = (new manifest_parser($dir))->parse();
        $category = $this->getDataGenerator()->create_category();
        (new course_builder($category->id, $dir))->build($coursemodel);

        $resource = $DB->get_record('resource', ['name' => 'CDN Ordered Exercise']);
        $this->assertNotEmpty($resource);
        $this->assertSame(['/index.html'], $this->resource_filearea_paths((int) $resource->id));
        $cm = get_coursemodule_from_instance('resource', $resource->id);
        $context = \context_module::instance($cm->id);
        $fs = get_file_storage();
        $main = $fs->get_file($context->id, 'mod_resource', 'content', 0, '/', 'index.html');
        $this->assertNotEmpty($main, 'the HTML must be the main file, not the stylesheet');
        $this->assertStringContainsString('MARKER', $main->get_content());
    }
}
