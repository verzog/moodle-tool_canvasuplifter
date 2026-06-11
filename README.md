# Canvas Uplifter (`tool_canvasuplifter`)

Imports Canvas LMS course exports (IMS Common Cartridge `.imscc`) into Moodle.

> **Status: Phase 0 — read-only.** This release inspects a Canvas package and
> reports what it contains and how cleanly each part will map to Moodle. It does
> **not** create any course content yet. Phase 0 exists so you can point the tool
> at real exports and see exactly what's inside before the builder is written.

## Requirements

- Moodle 5.0+ (question banks are `mod_qbank` activity modules from 5.0)
- PHP 8.2, 8.3 or 8.4 (Moodle 5.0's minimum is 8.2; the `sodium` extension is required by Moodle)

## Install

1. Copy this folder to `admin/tool/canvasuplifter/` in your Moodle tree
   (or upload the zip via *Site administration > Plugins > Install plugins*).
2. Visit *Site administration > Notifications* to complete installation.
3. Open *Site administration > Courses > Canvas Uplifter*.

## Using it (Phase 0)

1. In Canvas, export the course as a Common Cartridge (`.imscc`).
2. Upload that file on the Canvas Uplifter page.
3. Read the conversion report: section/item counts, the planned Moodle target
   for each content type, mapping confidence, and any warnings.

Nothing is written to your site — the uploaded file is inspected and discarded.

## How it's built

A five-stage pipeline, kept in separate, testable pieces:

- `classes/local/ingest/package.php` — unzip and validate the package
- `classes/local/parser/manifest_parser.php` — read the IMS manifest + Canvas
  extension files into a neutral model
- `classes/local/model/` — the neutral course/section/item model (no Moodle deps)
- `classes/local/report/conversion_report.php` — summarise mappability
- `index.php` — the admin page tying it together

The parser, model and report have **no Moodle dependencies**, so they can be
unit-tested in isolation (see `tests/`).

## Roadmap

| Phase | Adds |
|---|---|
| 0 (this) | Ingest, parse, model, read-only report |
| 1 | Build: course, sections, pages, files, URLs, assignments |
| 2 | Discussions → forums, announcements |
| 3 | Quizzes + `mod_qbank` question banks (QTI import) |
| 4 | Rubrics, gradebook, LTI placeholders |

## Licence

GPL-3.0-or-later. "Canvas" is a trademark of Instructure, Inc.; this is an
independent interoperability tool and is not affiliated with or endorsed by
Instructure.
