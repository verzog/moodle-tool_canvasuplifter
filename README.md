# Canvas Uplifter (`tool_canvasuplifter`)

Imports Canvas LMS course exports (IMS Common Cartridge `.imscc`) into Moodle.

> **Status: Phase 1 — early build.** This release can both *analyse* a Canvas
> package (report what it contains and how cleanly each part maps to Moodle)
> and *build* a new Moodle course from it. The builder currently creates
> sections plus pages (`mod_page`), files (`mod_resource`), URLs (`mod_url`)
> and assignments (`mod_assign`); other content types (forums, quizzes, LTI)
> are reported as skipped and will land in later phases. See the Roadmap below.

## Requirements

- Moodle 5.0+ (question banks are `mod_qbank` activity modules from 5.0)
- PHP 8.2, 8.3 or 8.4 (Moodle 5.0's minimum is 8.2; the `sodium` extension is required by Moodle)

## Install

1. Copy this folder to `admin/tool/canvasuplifter/` in your Moodle tree
   (or upload the zip via *Site administration > Plugins > Install plugins*).
2. Visit *Site administration > Notifications* to complete installation.
3. Open *Site administration > Courses > Canvas Uplifter*.

## Using it

1. In Canvas, export the course as a Common Cartridge (`.imscc`).
2. Upload that file (or paste a download URL) on the Canvas Uplifter page,
   and choose a target course category.
3. **Analyse package** shows a conversion report: section/item counts, the
   planned Moodle target for each content type, mapping confidence, and any
   warnings. Nothing is written in this mode.
4. **Build course** queues a background task that creates a new (hidden) course
   in the chosen category and builds the supported content into it. A status
   page shows progress and a summary of what was created or skipped.

### Known limitations (Phase 1)

- Page link rewriting covers embedded files (`$IMS-CC-FILEBASE$`) and internal
  links to other pages/activities (`$WIKI_REFERENCE$`,
  `$CANVAS_OBJECT_REFERENCE$`). Links whose target isn't built yet (e.g. a
  quiz) can't be resolved until that content type is supported, so those
  references are left unchanged rather than pointed at a broken URL.
- Assignments convert name, description, due/availability dates, points grade
  and online-text/file-upload submission types. Rubrics and advanced grading
  are not carried across.
- Resources not linked from any module are imported into an "Additional
  resources" section so nothing is lost.
- Canvas QTI assessments convert their questions — multiple choice, multiple
  response, fill-in-blank, true/false and essay, with question images. An
  assessment **linked in the course** becomes a Moodle quiz (`mod_quiz`) with the
  questions as slots; a **standalone/unreferenced** assessment becomes a question
  bank (`mod_qbank`). Unsupported question types are skipped, and multi-blank
  fill-in-blank collapses to a single short-answer.
- Forums and LTI tools are reported but not yet built.

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

### Running the checks locally

`tooling/local-ci.sh` mirrors `.github/workflows/moodle-ci.yml`: it provisions
`moodle-plugin-ci` + Moodle + a PostgreSQL database (using Docker if a daemon is
available, otherwise a locally installed cluster) and runs the same steps —
`phplint`, `phpcs`, `phpdoc`, `validate`, `savepoints`, `mustache` and
`phpunit`.

```
tooling/local-ci.sh            # first run provisions, then runs all checks
tooling/local-ci.sh phpunit    # re-run a single step against the installed Moodle
tooling/local-ci.sh --reinstall
MOODLE_BRANCH=MOODLE_502_STABLE tooling/local-ci.sh
```

Everything lives under `~/.moodle-plugin-ci` (outside the repo).

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
