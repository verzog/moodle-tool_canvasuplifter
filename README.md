# Canvas Uplifter (`tool_canvasuplifter`)

Imports Canvas LMS course exports (IMS Common Cartridge `.imscc`) into Moodle.

> **Status: Phase 3 — early build.** This release can both *analyse* a Canvas
> package (report what it contains and how cleanly each part maps to Moodle)
> and *build* a new Moodle course from it. The builder currently creates
> sections plus pages (`mod_page`), files (`mod_resource`), URLs (`mod_url`),
> assignments (`mod_assign`), quizzes and question banks (`mod_quiz` /
> `mod_qbank`) and forums (`mod_forum`); remaining content types (LTI,
> announcements) are reported as skipped and will land in later phases.
> See the Roadmap below.

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
  response, fill-in-blank, true/false and essay. Bundled media in question text
  (images, video, audio and attachments) is imported with the question; external
  embeds such as YouTube are left as-is. An assessment **linked in the course**
  becomes a Moodle quiz (`mod_quiz`) with the questions as slots; a
  **standalone/unreferenced** assessment becomes a question bank (`mod_qbank`),
  and a build-time toggle ("Also build a runnable quiz from each standalone
  question bank") will additionally seed a `mod_quiz` from each such bank.
  Unsupported question types are skipped, and multi-blank fill-in-blank collapses
  to a single short-answer.
- Discussion topics build as forums, seeded with the Canvas prompt as the
  opening post. Canvas does not export the replies, so existing threads do not
  carry across.
- LTI tools are reported but not yet built.

### Canvas quiz exports (QTI 1.2)

Background on how Canvas ships quizzes — and why some arrive without questions:

- **Canvas only exports quizzes as QTI 1.2**, wrapped in the `.imscc` package.
  This holds for Classic Quizzes and, through the standard Canvas UI, New Quizzes
  too; native QTI 2.1/3.0 export is a long-standing community request but is not
  yet available. This tool reads the **QTI 1.2 / Common Cartridge** shape only
  (`<item>`/`<presentation>`, `cc.*` profiles); a package built on QTI 2.1 or 3.0
  parses as zero questions, so down-convert it to **QTI 1.2** before importing.
- **Question types that convert:** multiple choice (`render_choice`), true/false,
  fill-in-the-blank / short answer (`render_fib` / string matching), multiple
  answers, and essay. Other interaction types are reported and skipped.
- **"Referenced but not present" quizzes:** when a quiz draws its questions from a
  randomised group or an external question pool/bank instead of hard-coding them
  inside the `<section>`, Canvas may export only the shell — a bare
  `<item ident="…"/>` with no body — and leave the pool items out of the package.
  The bodies are then genuinely absent, so the quiz is skipped and reported as
  *"references N question(s) whose content is not present in the package"*. To
  recover these, re-export from Canvas with the item banks included, or migrate
  the New Quizzes to Classic Quizzes first.
- **Re-importing into Canvas (`ident` reuse):** many authoring tools reuse
  assessment/item `ident`s, and Canvas treats matching ids as separate objects
  unless you tick **"Overwrite assessment content with matching IDs"** during the
  GUI import. This doesn't affect importing *into Moodle*, but matters if you ever
  round-trip content back to Canvas.

## How it's built

A pipeline kept in separate, testable pieces:

- `classes/local/ingest/package.php` — unzip and validate the package
- `classes/local/parser/manifest_parser.php` — read the IMS manifest + Canvas
  extension files into a neutral model
- `classes/local/model/` — the neutral course/section/item model (no Moodle deps)
- `classes/local/report/conversion_report.php` — summarise mappability
- `classes/local/build/` — Moodle-coupled builders (`course_builder` plus per
  kind `page_builder`, `file_builder`, `url_builder`, `assign_builder`,
  `forum_builder`, `quiz_builder`, `questionbank_builder`) with shared helpers
  (`link_rewriter`, `file_embedder`, `safe_path`, `question_importer`)
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

| Phase | Adds | Status |
|---|---|---|
| 0 | Ingest, parse, model, read-only report | Done |
| 1 | Build: course, sections, pages, files, URLs, assignments | Done |
| 2 | Discussions → forums | Done (announcements still pending) |
| 3 | Quizzes + `mod_qbank` question banks (QTI import) | Done |
| 4 | Rubrics, gradebook, LTI placeholders | Planned |

## Licence

GPL-3.0-or-later. "Canvas" is a trademark of Instructure, Inc.; this is an
independent interoperability tool and is not affiliated with or endorsed by
Instructure.
