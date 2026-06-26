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
}
