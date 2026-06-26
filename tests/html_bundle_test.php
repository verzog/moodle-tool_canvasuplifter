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
}
