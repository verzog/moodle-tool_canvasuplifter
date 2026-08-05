# Changelog

All notable changes to `tool_canvasuplifter` are documented here. The format is
loosely based on [Keep a Changelog](https://keepachangelog.com/); while the
plugin is pre-1.0 (`MATURITY_ALPHA`) the version line is `0.x` and may change
quickly.

## [0.50.0] - 2026-08-05

- **Embed quiz and discussion dependency media instead of leaking it as
  downloads.** Canvas references media stored beside a question's or discussion's
  own resource with `$IMS-CC-FILEBASE$name` (a bare name, not a package-root
  path) or with a `../` climb into a sibling resource folder. Previously these
  resolved only against the package root, so the media failed to embed *and* the
  backing resource surfaced as a standalone "Additional resources" download
  (e.g. `img1`, `smiling_dog`).
  - `link_rewriter::resolve_filebase()` (and the question writer's FILEBASE
    resolution) now fall back to the **owning resource's own folder**, collapsing
    `./`/`../` within the package, so question stem/answer/feedback images and
    discussion inline images embed into their content and forum attachments
    written as `../…` import correctly.
  - The manifest parser now reads Common Cartridge `<dependency>` declarations and
    keeps an unplaced dependency target (an embedded asset of a placed quiz or
    discussion) off the orphan list, so it no longer appears as a standalone file
    activity.
  - Extends the `cc_full_test` build test: the quiz's three images land in the
    question file area and the discussion's inline image and attachment land on
    the seeded forum post, with the two former leak resources gone (four file
    resources built, not six). This is progress on the *embedded-media-heavy*
    path-to-beta box.

## [0.49.0] - 2026-08-05

- Add the `cc_full_test` end-to-end build fixture and test — the plugin's first
  full-course *build* regression (the GBIRD fixture is analyse-only). It builds
  the IMS "Validation Cartridge 1" (a broad, standards-conformant Common
  Cartridge) into a real course and asserts the whole module mix: 6 file
  resources, the two discussions as forums (alongside the course news forum), two
  hidden `mod_lti` placeholders, two weblinks, the referenced quiz and its
  question bank, and all 22 questions imported. The cartridge's deliberately
  broken "Non-existent reference" weblink is reported as a single non-fatal skip
  rather than aborting the build or silently inventing an activity.

## [0.48.0] - 2026-08-05

- Resilience fixes surfaced by running Canvas/IMS "Validation Cartridge" test
  packages through the analyser:
  - **Malformed manifests no longer abort the whole import.** Canvas ships
    packages whose unsupported/placeholder resources carry a broken tag (e.g.
    `<filehref=...>`); libxml rejected the entire document, discarding the good
    resources with the bad. `manifest_parser` now parses with `LIBXML_RECOVER`
    (still rejecting a manifest with no usable root), so the valid resources
    import and the broken one is reported as unclassified/skipped — matching how
    Canvas itself recovers.
  - **QTI answers scored by choice text now import.** Some IMS Common Cartridge
    exporters point a question's correct `<varequal>` at the option's displayed
    text (e.g. `<varequal>False</varequal>`) rather than its `response_label`
    ident. `qti_parser` matched only the ident, so such true/false (and multiple
    choice) questions imported with no correct answer and were dropped as
    unsupported; it now falls back to matching the choice's text, case-insensitively.

## [0.47.0] - 2026-08-04

- Build-fidelity fixes surfaced by the GBIRD-Sandbox regression fixture, closing
  the batch of issues found reviewing #124:
  - **External tools as LTI (#125, #128):** a Canvas external-tool assignment
    (`submission_types=external_tool` with an `external_tool_url` — Quizzes.Next,
    SCORM, any LTI) is re-homed as an LTI item and builds as a hidden `mod_lti`
    placeholder carrying the launch URL, instead of a near-empty `mod_assign`
    that dropped the tool. A Canvas `ContextExternalTool` placed directly in a
    module with an inline launch URL (and no backing cartridge resource) is now
    synthesised into the same placeholder rather than being dropped entirely.
    `lti_builder` accepts an inline launch URL, not only a cartridge file.
  - **Quiz grade categories (#130):** a quiz/assessment's Canvas assignment-group
    reference (read from its `assessment_meta.xml`) is now carried onto the model,
    and `course_builder` routes quiz grade items into that grade category too —
    previously only assignments were placed, so quiz gradebook grouping was lost.
  - **Native-QTI question banks (#127):** `questionbank_builder` gains the same
    `non_cc_assessments/<id>.xml.qti` fallback `quiz_builder` uses, so an orphan
    New-Quiz bank whose Common Cartridge QTI is an empty shell recovers its
    questions from the native dump and genuinely builds instead of being skipped.
  - Addresses the review notes raised on the batch: metadata-derived visibility
    only consults the companion file that describes each resource kind, and
    honours a root-level `assessment_meta.xml` sibling; `files_meta.xml`
    folder-hiding applies only to standalone file resources (and survives a
    published module placing a hidden file); a CC 1.3 external-tool submission
    format is recognised; the standalone quiz-from-bank build and the analyse
    report/nudge mirror the grade-category and native-QTI behaviour; a re-homed
    external-tool assignment keeps its instructions on the LTI placeholder and is
    still preferred over its webcontent variant fallback.

## [0.46.0] - 2026-08-04

- Parser accuracy fixes surfaced by the GBIRD-Sandbox regression fixture, all in
  the Moodle-free `parser`/`report` layer:
  - **Question mapping (#129):** Canvas New Quizzes `categorization_question` and
    `ordering_question` items no longer fall through the response-cardinality
    heuristic (which mis-counted them as supported multi-answer / multiple-choice
    questions). `qti_parser::map_type()` now maps both to `TYPE_UNSUPPORTED`, so
    they are reported by name rather than silently mis-imported.
  - **Orphan visibility (#126):** an unpublished assignment, quiz or discussion
    that no module places (an orphan) now imports hidden. Visibility is derived
    from the activity's own companion metadata — `assignment_settings.xml`,
    `assessment_meta.xml` (including the New-Quiz `<assignment>`/`<workflow_state>`
    nesting) and the discussion `topicMeta` — rather than only from a module
    occurrence. The pass only ever hides, so a published orphan stays visible.
  - **Hidden files (#131):** `course_settings/files_meta.xml` is now honoured, so
    a file Canvas marked hidden — directly or by living under a hidden folder such
    as the QTI-internal "Uploaded Media" — imports hidden instead of surfacing as
    a visible standalone resource.

## [0.45.0] - 2026-08-04

- Documentation/release-prep, no code or behaviour change. Correct the README to
  match what the builder actually does today: learning outcomes (0.41.0) build,
  so Phase 6 is marked Done for outcomes, and the stale "LTI tools are reported
  but not yet built" limitation note is corrected — LTI links build as hidden
  `mod_lti` placeholders, which shipped in Phase 4. The roadmap also gains a
  Phase 7 that records the bulk-migration integration shipped in 0.42.0–0.44.0
  (the public `launcher` facade and the `tool_automate` bulk driver, with its
  Staged Canvas imports review page), and the remaining QTI question types move
  to a planned Phase 8. Refresh the "Path to beta" outcomes item (outcomes are
  imported now, not dropped; their rubric/assignment *alignment* remains a known
  non-carry) and add a "Releasing (maintainers)" section describing the checks
  CI actually runs.

## [0.44.0] - 2026-08-03

- Add `launcher::get_job()` to the public facade, so a caller can poll an import
  job's status/progress/`courseid` through the facade instead of reaching into
  `local\job_manager` (whose `get()` is a non-static instance method). Completes
  the queue/list/get/delete/storage surface a bulk driver such as `tool_automate`
  needs.
- Document the bulk-migration integration and the full `launcher` facade in the
  README, and add `docs/bulk-canvas-migration-howto.pdf`, a step-by-step
  administrator guide for large-scale Canvas migrations.

## [0.43.0] - 2026-08-03

- Add `launcher::delete_job()` and `launcher::package_storage_used()` to the
  public facade, so an import-history view can let a user reclaim space:
  `delete_job()` removes a job and frees its stored `.imscc` package (leaving any
  course a build already created in place, and refusing to delete another user's
  job), and `package_storage_used()` totals the bytes the stored packages use.
  `delete_job()` only removes finished (done/failed) jobs — so it never races an
  in-flight task — and frees a shared package only once no other job references
  it. The reference check, file delete and job-row delete run under a per-file
  lock that `queue_job()` also takes, so queuing a build from an analyse job's
  package cannot race that package's deletion.

## [0.42.0] - 2026-08-03

- Add a job-listing API so a caller can list a user's import jobs (newest first,
  filterable by kind/status) — the supported way for an import history view such
  as `tool_automate`'s "Staged Canvas imports" list to find the analyse jobs a
  user has staged for a later build. It is exposed on the public
  `\tool_canvasuplifter\launcher` facade (`launcher::list_jobs()`), backed by
  `job_manager::list_jobs()`, so external callers stay off the internal `local\`
  namespace. Ordered by `timecreated`, then `id`, so jobs created in the same
  second (common in a bulk import) stay deterministically newest-first.
- Give the analyse and build adhoc tasks human-readable names in *Server > Tasks
  > Task logs* ("Analyse Canvas package" / "Build course from Canvas package")
  instead of showing their class names.

## [0.41.0] - 2026-08-03

- Import Canvas learning outcomes (`course_settings/learning_outcomes.xml`) as
  Moodle course grade outcomes, each backed by a scale built from its mastery
  ratings. Ratings are ordered low-to-high by their points; labels are made
  scale-safe (commas normalised, duplicates collapsed, post-normalisation
  collisions disambiguated) and an outcome whose ratings can't form a scale of
  at least two distinct labels is skipped and reported rather than failing the
  build. Untitled outcomes import under a fallback name; file references
  (`$IMS-CC-FILEBASE$`) and internal-link tokens
  (`$WIKI_REFERENCE$`/`$CANVAS_OBJECT_REFERENCE$`) in outcome descriptions are
  resolved like page content. The analyse report previews the outcome counts
  (importable / skipped) and flags a malformed outcomes file. Outcomes are
  course-scoped, non-destructive, and stay hidden until the site's "Enable
  outcomes" advanced setting is on.

## [0.40.0] - 2026-08-03

- Add a public `\tool_canvasuplifter\launcher` facade that queues an analyse or
  build run from a package held as a stored file id, an on-disk file path, or a
  remote URL, returning the job id to poll. This is the supported entry point
  for programmatic and bulk callers (for example `tool_automate`'s bulk Canvas
  import), so they need not reach into `job_manager`, the file-area layout or
  the adhoc task classes. It lives in the plugin's public namespace (not
  `local\`) so it can be relied on as an integration surface. The main upload
  page now drives its own queueing through the same facade, so there is a single
  create-and-queue path. `queue_job()` requires exactly one package source (a
  file id or a URL) and throws otherwise, so an invalid call fails immediately
  rather than leaving a job that can only fail later. Callers remain responsible
  for their own capability checks: `tool/canvasuplifter:use` for any run, plus
  `moodle/course:create` on the target category for a build (an analyse run
  needs no course:create).

## [0.39.17] - 2026-07-12

- Convert a single-blank Canvas fill-in-the-blank question
  (`fill_in_multiple_blanks_question`) to a Moodle **short answer** — accepting
  every answer the blank lists — instead of a degenerate one-option multiple
  choice that discarded the other acceptable answers. A single inline *dropdown*
  still converts to multiple choice.
- Drop Canvas platform boilerplate a Moodle course has no use for: help-guide
  links to Canvas's own documentation (`guides.instructure.com`), and — when the
  course was migrated from ANGEL — ANGEL's leftover objects (`AngelManifest.xml`,
  `AngelObj[…].xml`) that just duplicate the Canvas-native content. Gated on the
  Canvas source and matched precisely, so real content and other files are
  untouched; the build report notes how many were dropped.
- Flag Canvas rubrics the export never linked to an activity: their definitions
  import to the rubric library, but Canvas omits the associations, so they can't
  be attached automatically. The build report now lists how many, rather than
  losing them silently.

## [0.39.16] - 2026-07-12

- Import a Canvas course's letter-grade scheme (`grading_standards.xml`) as
  Moodle course grade letters, when the course has a grading standard enabled.
  Canvas stores each scheme as boundary fractions (e.g. A ≥ 0.895); these become
  Moodle grade letters (A ≥ 89.5%, …) on the course context, the course grade
  display is switched to letters so the scheme actually shows, and the build
  report notes how many boundaries were imported. Courses on Canvas's default
  scheme are left on Moodle's default grade letters.

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
