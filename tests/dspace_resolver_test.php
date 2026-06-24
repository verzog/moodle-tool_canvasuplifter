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

use tool_canvasuplifter\local\ingest\dspace_resolver;

/**
 * Tests resolving a Common Cartridge download from a DSpace 7 repository page.
 *
 * @package    tool_canvasuplifter
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_canvasuplifter\local\ingest\dspace_resolver
 */
final class dspace_resolver_test extends \basic_testcase {
    /** @var string REST API base for the SkillsCommons backend used in fixtures. */
    private const REST = 'https://library.skillscommons.org/server/api';
    /**
     * The un-rendered Angular shell and a server-rendered DSpace page are both
     * recognised as DSpace; unrelated HTML is not.
     *
     * @return void
     */
    public function test_detects_dspace(): void {
        $shell = '<html><head><title>DSpace</title></head><body><ds-app></ds-app>'
            . '<script src="main.js"></script></body></html>';
        $rendered = '<html><head><meta name="Generator" content="DSpace 7.4"></head>'
            . '<body><ds-app ng-version="13.2.6">…</ds-app></body></html>';
        $other = '<html><body><h1>OER Commons</h1><a href="x.imscc">Download</a></body></html>';

        $this->assertTrue(dspace_resolver::looks_like_dspace_shell($shell));
        $this->assertTrue(dspace_resolver::looks_like_dspace_shell($rendered));
        $this->assertFalse(dspace_resolver::looks_like_dspace_shell($other));
    }

    /**
     * A legacy /handle URL yields its Handle; an /items or /entities URL yields
     * the item UUID (lower-cased, /full suffix ignored); anything else is null.
     *
     * @return void
     */
    public function test_parse_reference(): void {
        $this->assertSame(
            ['handle' => 'taaccct/4632'],
            dspace_resolver::parse_reference('https://www.skillscommons.org/handle/taaccct/4632')
        );
        $this->assertSame(
            ['uuid' => '4e465893-02a5-4c68-b2a8-afbbd897e795'],
            dspace_resolver::parse_reference('https://www.skillscommons.org/items/4e465893-02a5-4c68-b2a8-afbbd897e795/full')
        );
        $this->assertSame(
            ['uuid' => '4e465893-02a5-4c68-b2a8-afbbd897e795'],
            dspace_resolver::parse_reference('https://x.edu/entities/publication/4E465893-02A5-4C68-B2A8-AFBBD897E795')
        );
        $this->assertNull(dspace_resolver::parse_reference('https://www.skillscommons.org/communities/abc'));
        $this->assertNull(dspace_resolver::parse_reference('https://example.org/'));
    }

    /**
     * Candidate REST bases prefer an in-page hint, then the UI host's /server,
     * then the sibling library.* host for www.* split-host deployments.
     *
     * @return void
     */
    public function test_rest_base_candidates(): void {
        $url = 'https://www.skillscommons.org/handle/taaccct/4632';

        $this->assertSame(
            ['https://www.skillscommons.org/server', 'https://library.skillscommons.org/server'],
            dspace_resolver::rest_base_candidates($url)
        );

        $html = '<link href="https://library.skillscommons.org/server/opensearch/service" rel="search">';
        $this->assertSame(
            [
                'https://library.skillscommons.org/server',
                'https://www.skillscommons.org/server',
            ],
            dspace_resolver::rest_base_candidates($url, $html)
        );

        $this->assertSame(
            ['https://repo.example.edu/server'],
            dspace_resolver::rest_base_candidates('https://repo.example.edu/items/' . str_repeat('a', 8)
                . '-aaaa-aaaa-aaaa-' . str_repeat('a', 12))
        );
    }

    /**
     * From a bundles?embed=bitstreams response, the .imscc file's content URL is
     * picked ahead of the sibling .docx/.xlsx files.
     *
     * @return void
     */
    public function test_picks_imscc_from_embedded_bundles(): void {
        $bitstreams = dspace_resolver::bitstreams_from_bundles($this->bundles());

        $this->assertCount(3, $bitstreams);
        $this->assertSame(
            self::REST . '/core/bitstreams/c97a5264-087d-4fd2-9302-9436e1bffc77/content',
            dspace_resolver::pick_href($bitstreams)
        );
    }

    /**
     * A .imscc is preferred over a .zip when both are present.
     *
     * @return void
     */
    public function test_prefers_imscc_over_zip(): void {
        $bitstreams = [
            ['name' => 'course.zip', '_links' => ['content' => ['href' => 'https://x/zip']]],
            ['name' => 'course.imscc', '_links' => ['content' => ['href' => 'https://x/imscc']]],
        ];
        $this->assertSame('https://x/imscc', dspace_resolver::pick_href($bitstreams));
    }

    /**
     * When no package file is present, no link is returned.
     *
     * @return void
     */
    public function test_no_package_bitstream_returns_null(): void {
        $bitstreams = [
            ['name' => 'syllabus.pdf', '_links' => ['content' => ['href' => 'https://x/pdf']]],
            ['name' => 'readme.txt', '_links' => ['content' => ['href' => 'https://x/txt']]],
        ];
        $this->assertNull(dspace_resolver::pick_href($bitstreams));
    }

    /**
     * The fallback path reads a bundle's bitstreams link and a flat bitstreams
     * collection response.
     *
     * @return void
     */
    public function test_fallback_bundle_and_collection(): void {
        $this->assertSame(
            [self::REST . '/core/bundles/9a/bitstreams'],
            dspace_resolver::bundle_bitstreams_hrefs($this->bundles())
        );

        $collection = [
            '_embedded' => [
                'bitstreams' => [
                    ['name' => 'course.imscc', '_links' => ['content' => ['href' => 'https://x/imscc']]],
                ],
            ],
        ];
        $this->assertSame(
            'https://x/imscc',
            dspace_resolver::pick_href(dspace_resolver::bitstreams_from_collection($collection))
        );
    }

    /**
     * A realistic items/<uuid>/bundles?embed=bitstreams response with one
     * ORIGINAL bundle holding the .imscc plus two support documents, matching the
     * DSpace 7 nesting (bitstreams two _embedded levels under each bundle).
     *
     * @return array Decoded JSON structure.
     */
    private function bundles(): array {
        return ['_embedded' => ['bundles' => [[
            'name' => 'ORIGINAL',
            'uuid' => '9a',
            '_embedded' => ['bitstreams' => ['_embedded' => ['bitstreams' => [
                $this->bitstream(
                    '1440701797__0__crs_7379_SLF120IntroductiontoSmallFarmViability.imscc',
                    'c97a5264-087d-4fd2-9302-9436e1bffc77'
                ),
                $this->bitstream('IGEN - Quality Note Template.docx', 'b4cb9cb7-06bb-4256-811f-e42ca82d9561'),
                $this->bitstream('IGEN - Evaluation Rubric.xlsx', 'e8e8abe7-1176-4ea0-b72d-0e98b595e1e3'),
            ]]]],
            '_links' => ['bitstreams' => ['href' => self::REST . '/core/bundles/9a/bitstreams']],
        ]]]];
    }

    /**
     * Build one bitstream object with the DSpace content (download) link.
     *
     * @param string $name The stored file name.
     * @param string $uuid The bitstream UUID.
     * @return array
     */
    private function bitstream(string $name, string $uuid): array {
        return [
            'name' => $name,
            'uuid' => $uuid,
            '_links' => ['content' => ['href' => self::REST . '/core/bitstreams/' . $uuid . '/content']],
        ];
    }
}
