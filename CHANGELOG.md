# Changelog

All notable changes to `tool_canvasuplifter` are documented here. The format is
loosely based on [Keep a Changelog](https://keepachangelog.com/); while the
plugin is pre-1.0 (`MATURITY_ALPHA`) the version line is `0.x` and may change
quickly.

## [0.39.16] - 2026-07-12

- Import a Canvas course's letter-grade scheme (`grading_standards.xml`) as
  Moodle course grade letters, when the course has a grading standard enabled.
  Canvas stores each scheme as boundary fractions (e.g. A ≥ 0.895); these become
  Moodle grade letters (A ≥ 89.5%, …) on the course context, and the build report
  notes how many boundaries were imported. Courses on Canvas's default scheme are
  left on Moodle's default grade letters.

## [0.39.15] - 2026-07-12

Conversion-report honesty improvements, drawn from real Canvas packages that
carry legacy content. All report-layer only (Moodle-free): every resource is
still imported and nothing is dropped.

- Attribute skipped quiz questions to the assessment they came from, so a quiz
  that silently lost questions is distinguishable from a throwaway practice
  quiz. Each skipped question-type row now lists its source assessments
  (most-affected first) in the "Converts" cell, with the (user-supplied) names
  escaped.
- Flag obsolete Flash (`.swf`) resources: they import as a file but no modern
  browser plays them, so they now convert with a distinct note and lowered
  confidence (their own content-type row) and raise a warning, instead of
  sitting among the "Maps cleanly" files.
- Flag likely duplicate files — an original plus a `" (n)"`/`"-n"` copy — with a
  warning, but only when the bare original is also present, so distinct files
  that merely share a numeric suffix (Lesson1 vs Lesson2) are not flagged. The
  copy marker is limited to one–three digits so a year edition
  (`syllabus-2024.pdf` beside `syllabus.pdf`) is treated as a separate file.
- Judge the payload Moodle actually imports when identifying a file resource:
  mirror the builder's href-first ordering and its readable-file fallback (skip
  an unreadable `href` and use the first file that resolves), so the obsolete
  and duplicate checks classify the real payload rather than a stale or
  secondary reference.

## [0.39.14] - 2026-07-10

Make the large-package (chunked) upload survive a flaky connection or a
timed-out chunk:

- The uploader now retries a chunk through transient failures — network errors,
  5xx, and 408/429 — with exponential backoff (up to five attempts) instead of
  silently freezing when a request fails; previously a 504 from a reverse proxy
  left the progress bar stuck with no error, because only HTTP 200 was handled.
- After a failed chunk the browser asks the server how far it actually got (new
  `status` action) and resumes from that position, so a 504 that timed out the
  response but still committed the write no longer dead-ends the upload on a
  chunk-alignment error.
- The server now trusts its stored position when a chunk is re-sent: any bytes a
  half-finished attempt wrote past it are truncated before writing, and a chunk
  already stored is accepted as a no-op — so a retried chunk can never
  double-append and corrupt the package.
- A chunk a proxy rejects as too large (413) now reports a clear message asking
  an administrator to lower the "Chunk size (MB)" setting, rather than failing
  silently.

## [0.39.13] - 2026-07-10

Better handling of ANGEL/eXe-authored Common Cartridge exports:

- Detect the source LMS from the package (Canvas / Blackboard / D2L / ANGEL /
  eXe / generic) and name it in the analysis report.
- Drop the framework junk an ANGEL/eXe export leaves behind — UI chrome images
  and duplicate glossary term fragments the exporter itself tags `_UNREFERENCED_`
  — instead of importing dozens of "NTER"-titled resources into the course. On a
  real 2,100-resource ANGEL export this removed 113 junk resources while keeping
  every one of the 83 real items.
- When a page's `<title>` is empty (common in exported learning-module pages),
  title it from its first heading instead of a slug of the identifier.

## [0.39.12] - 2026-06-29

Pre-publish hardening from a full code review:

- Guard package extraction against a decompression bomb (cap total uncompressed
  size and entry count) and reject Windows drive-letter zip entries.
- Abort a remote package download mid-transfer once it exceeds the size limit,
  instead of only checking after the whole body is on disk.
- Roll back a transaction left open by a failed `add_moduleinfo()` in every
  builder (not just LTI), so a mid-build failure can't make the adhoc task retry
  into a duplicate course.
- Recover a multiple-response quiz question whose correct options are split
  across sibling QTI response conditions (previously only the last survived).
- Tighten a package-path containment check in the analyse report to require a
  directory boundary; restrict the build status page to the job's owner.
- Correct `<base href>`-independent internal consistency: keep the report layer
  free of the build layer, honour leading-dot bundle directories, clamp a
  negative question mark, and count a resource once when disambiguating bank
  titles. Software-only, no behaviour change for well-formed packages.
- Document the imported-HTML trust model: a "import only trusted packages"
  warning on the upload page and in the README (the manager-only capability
  already declares the XSS risk; content is deliberately not purified so
  script-driven imported pages keep working).

## [0.39.11] - 2026-06-26

- When folding an interactive HTML exercise, honour a local relative document
  `<base href>` so a page that re-roots its relative references (e.g.
  `<base href="../assets/">`) has the right files folded in. An absolute or
  external base (a CDN, a site-root path) is deliberately left alone — its
  references point outside the package, so the exercise builds as a plain file
  resource rather than mis-folding unrelated local files.

## [0.39.10] - 2026-06-26

- Self-contained interactive HTML exercises (an HTML file plus a folder of
  js/css/image assets, each exported as its own webcontent resource) now build
  as a single embedded `mod_resource` with the assets folded in, so the exercise
  renders inline and works (scripts intact) instead of arriving as a broken HTML
  file plus dozens of standalone asset activities. Folding now follows assets
  referenced by a stylesheet's `url()` and `@import` (recursively), `srcset`
  responsive images, inline `<style>`/`style=""` `url()` references, and
  parent-directory references (the filearea is rebased to the common ancestor so
  `../shared/app.js` still resolves). Every file owned by an absorbed resource is
  folded in (so a script's runtime-`fetch()` data file comes too), and an asset
  that is also explicitly placed in the course still builds as its own activity
  rather than being hidden. A resource shared by several exercises folds into each
  of their bundles, and the HTML is pinned as the embedded resource's main file
  regardless of the order the manifest lists its files in.

## [0.39.9] - 2026-06-26

- Drop Blackboard's `web_content<NNN>.log` export build artifact instead of
  importing it as a junk file resource — but only when it also carries the
  instructor-role LOM metadata Blackboard stamps on that artifact, so a course
  that legitimately publishes a log (even one named that way) is never dropped on
  the basename alone.

## [0.39.6] - 2026-06-26

- Ship the full, verbatim GNU GPL v3 licence text in `LICENSE` (was a stub that
  only linked to it).
- Refresh the README status and roadmap (analyse now runs asynchronously;
  matching and dropdown/blank question support documented) and add this
  changelog.

## [0.36.0 – 0.39.5] - 2026-06 — Canvas quiz question import

- Carry real Canvas quiz settings from `assessment_meta.xml` onto `mod_quiz`:
  time limit, allowed attempts, open/close and unlock dates, scoring policy →
  grade method, points, shuffle, one-question-per-page, navigation, access code,
  IP filter, and review/`hide_results` options.
- Recover questions from Canvas's native QTI dump (`non_cc_assessments/<id>.xml.qti`)
  when the Common Cartridge assessment ships an empty shell.
- Map every Canvas `question_type`, adding **matching**; import inline-dropdown and
  choice-form multiple-blank questions as Moodle matching, gated on the blanks
  sharing one choice set and each carrying a stem (otherwise reported as
  unsupported). Handle `<flow>`-wrapped presentations and preserve all prompt
  fragments. Numerical, calculated and free-text multi-blank remain reported but
  not built.
- Embed question media via the `$IMS-CC-FILEBASE$` token through a shared
  resolver, and rewrite internal Canvas links (`$WIKI_REFERENCE$` /
  `$CANVAS_OBJECT_REFERENCE$`) inside question text, answers and match row stems
  once their targets exist.
- Import question-less Canvas exam/New-Quiz shells as hidden placeholder quizzes
  that keep their settings and a teacher note.
- Fold the chunked uploader in-tree as an optional "Large package" upload field
  (no dependency on a separate plugin), alongside the standard file picker and
  URL importer.
- Run package **analyse** (extract + parse + report, and remote-URL fetch) as a
  background adhoc task so large packages don't time out the web request.

## Phases 0–4 — initial import pipeline

- **Phase 0–1:** ingest/validate/unzip; parse the IMS manifest and Canvas
  extension XML into a neutral model; read-only mappability report; build the
  course, sections, pages (`mod_page`), files (`mod_resource`), URLs (`mod_url`)
  and assignments (`mod_assign`).
- **Phase 2:** discussion topics → forums (`mod_forum`); Canvas announcements →
  the course news forum.
- **Phase 3:** quizzes (`mod_quiz`) and question banks (`mod_qbank`) via QTI
  import.
- **Phase 4:** gradebook categories with Canvas-derived weights, LTI tool
  placeholders (`mod_lti`), in-section labels (`mod_label`) for Canvas module
  subheaders, Canvas rubrics → `gradingform_rubric`, and the CC 1.3 IMS
  Assignment profile path.
