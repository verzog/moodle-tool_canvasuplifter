# Changelog

All notable changes to `tool_canvasuplifter` are documented here. The format is
loosely based on [Keep a Changelog](https://keepachangelog.com/); while the
plugin is pre-1.0 (`MATURITY_ALPHA`) the version line is `0.x` and may change
quickly.

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
