# Canvas Uplifter (`tool_canvasuplifter`)

Imports Canvas LMS course exports (IMS Common Cartridge `.imscc`) into Moodle.

> **Status: alpha, Phases 0–7 complete.** This release can both *analyse* a
> Canvas package (report what it contains and how cleanly each part maps to
> Moodle — run as a background task so large packages don't time out the web
> request) and *build* a new Moodle course from it. The builder creates
> sections plus pages (`mod_page`), files (`mod_resource`), URLs (`mod_url`),
> assignments (`mod_assign`) with optional Canvas rubrics on the
> `submissions` grading area, quizzes and question banks (`mod_quiz` /
> `mod_qbank`), forums (`mod_forum`), in-section labels (`mod_label`) for
> Canvas module subheaders, LTI tool placeholders (`mod_lti`), a
> gradebook of categories with Canvas-derived weights, and Canvas learning
> outcomes as course grade outcomes. Canvas announcements are posted into the
> course's news forum. Non-Canvas CC 1.3 packages whose assignments use the IMS
> Assignment profile build through the same `mod_assign` path. For large-scale
> migrations the sibling `tool_automate` plugin can drive this one in bulk (see
> "Bulk migrations" below). See the Roadmap and "Path to beta" sections below.

## Security — import only trusted packages

Imported page and description HTML is stored and displayed **as authored** —
Moodle renders it without re-cleaning — so a Common Cartridge package from an
untrusted source could carry active content (for example `<script>`) that runs
for anyone who views the course. **Only import packages you trust.** The
capability that gates the tool (`tool/canvasuplifter:use`) is restricted to
managers and declares an XSS risk to reflect this; the upload page shows the
same warning.

### Server-side URL fetch (SSRF)

When you import from a *Download URL* rather than an upload, the site fetches
that URL server-side (following up to five redirects), and for some repository
landing pages it derives further API URLs from the fetched HTML. All of these
requests go through Moodle's `\curl` wrapper, so they are subject to the site's
**cURL security settings** at *Site administration > Security > HTTP security*.

On the Moodle versions this plugin supports (5.0+), those settings are **secure
by default**: `curlsecurityblockedhosts` ships blocking loopback and private
ranges (`127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`),
`localhost`, the cloud-metadata address (`169.254.169.254`) and IPv6 loopback
(`::1`), while `curlsecurityallowedport` ships restricted to ports 80 and 443.
So a *Download URL* cannot reach internal services out of the box.

For defence-in-depth, keep those defaults in place (don't clear the blocklist),
and extend them if your network needs it — for example add IPv6 link-local and
unique-local ranges (`fe80::/10`, `fc00::/7`) on IPv6-connected hosts. The
plugin relies on this standard Moodle mechanism rather than maintaining its own
blocklist, so SSRF protection stays a site-configuration responsibility.
Uploading the `.imscc` file directly avoids the server-side fetch entirely.

## Also in this repository: `repository_largefile`

The [`repository_largefile/`](repository_largefile/README.md) folder is a
**separate, self-contained Moodle repository plugin** kept here for convenience.
It generalises this tool's two large-file features — **import from a URL**
(server-side fetch) and **chunked large-file upload** — into a repository plugin
that appears in *every* file picker, including the course backup restore upload
screen. It installs to `repository/largefile/`, not under `admin/tool/`, and is
validated on its own (it is excluded from this plugin's `moodle-plugin-ci` run via
`.moodle-plugin-ci.yml`). See its [README](repository_largefile/README.md).

## Requirements

- Moodle 5.0+ (question banks are `mod_qbank` activity modules from 5.0)
- PHP 8.2, 8.3 or 8.4 (Moodle 5.0's minimum is 8.2; the `sodium` extension is required by Moodle)

## Install

1. Copy this folder to `admin/tool/canvasuplifter/` in your Moodle tree
   (or upload the zip via *Site administration > Plugins > Install plugins*).
2. Visit *Site administration > Notifications* to complete installation.
3. Open *Site administration > Courses > Canvas Uplifter*.

No extra plugins are required. The upload form includes a built-in
**Large package (chunked upload)** field for packages bigger than the
server's PHP upload limit; its chunked uploader is bundled with this plugin
(folded in from `local_chunkupload`), so there is no separate dependency.
Chunk size and retention are configured at *Site administration > Plugins >
Admin tools > Canvas Uplifter*.

## Using it

1. In Canvas, export the course as a Common Cartridge (`.imscc`).
2. Upload that file (or paste a download URL) on the Canvas Uplifter page,
   and choose a target course category.
3. **Analyse package** shows a conversion report: section/item counts, the
   planned Moodle target for each content type, mapping confidence, and any
   warnings. Nothing is written in this mode.
4. **Build course** queues a background task that creates a new (hidden) course
   in the chosen category and builds the supported content into it. A status
   page shows progress and a summary of what was created or skipped. Two optional
   settings sit on the upload and report pages:
   - *Also build a runnable quiz from each standalone question bank* (see below).
   - *Combine consecutive pages* — off by default. Set it to **Book** or
     **Lesson** to fold each run of two or more adjacent wiki pages in a section
     into one `mod_book` (a chapter per page) or `mod_lesson` (a content page per
     page), named after the section. A lone page between activities stays a
     `mod_page`, and links between the folded pages are rewritten to point at the
     right chapter/page.

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
- The optional "Combine consecutive pages" setting can fold runs of pages into a
  single book or lesson. Book chapters honour each Canvas page's published state;
  lessons have no per-page visibility, so an unpublished page folded into a
  lesson becomes visible.
- Canvas QTI assessments convert their questions — multiple choice, multiple
  response, fill-in-blank, true/false, essay, matching, numerical, calculated
  (formula) and free-text multi-blank (imported as a Moodle Cloze). Canvas
  inline-dropdown questions import as Moodle matching when every blank shares one
  choice set (Moodle's match type has a single answer pool). When a Canvas Common
  Cartridge assessment ships an empty shell, the questions are recovered from
  Canvas's native QTI dump (`non_cc_assessments`).
  Bundled media in question text — images, video, audio and attachments,
  including `$IMS-CC-FILEBASE$` references — is imported with the question, and
  internal Canvas links in question text are rewritten once their targets exist;
  external embeds such as YouTube are left as-is. An assessment **linked in the
  course** becomes a Moodle quiz (`mod_quiz`) with the questions as slots; a
  **standalone/unreferenced** assessment becomes a question bank (`mod_qbank`),
  and a build-time toggle ("Also build a runnable quiz from each standalone
  question bank") will additionally seed a `mod_quiz` from each such bank.
  Question-less Canvas exam/New-Quiz shells import as hidden placeholder quizzes
  that carry their settings and a teacher note, so nothing but the absent
  questions is lost.
- Discussion topics build as forums, seeded with the Canvas prompt as the
  opening post. Canvas does not export the replies, so existing threads do not
  carry across.
- LTI tools build as **hidden** `mod_lti` placeholders carrying the cartridge's
  launch URL and a teacher note. Canvas does not export tool credentials, so an
  admin points each at a configured external tool and unhides it; a cartridge
  with no usable `http(s)` launch URL is reported and skipped rather than built.

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
  answers, matching, numerical (`numerical_question` — exact or `vargte`/`varlte`
  range, mapped to a Moodle numerical answer with tolerance), calculated
  (`calculated_question` — the `<calculated>` formula, variables and pre-generated
  `<var_sets>` become a Moodle calculated question with a dataset per variable),
  free-text multi-blank (`fill_in_multiple_blanks_question` — each blank becomes an
  inline `{1:SHORTANSWER:=…}` field in a Moodle Cloze), and essay. Other interaction
  types are reported and skipped.
- **"Referenced but not present" quizzes:** when a quiz draws its questions from a
  randomised group or an external question pool/bank instead of hard-coding them
  inside the `<section>`, Canvas may export only the shell — a bare
  `<item ident="…"/>` with no body, or an empty `<section/>` (typical of New
  Quizzes / `cc.exam` exports) — and leave the pool items out of the package. The
  bodies are then genuinely absent. Rather than dropping the activity, the quiz
  is built as a **hidden placeholder** carrying the Canvas title and settings
  (time limit, attempts, dates, …) with an intro note asking a teacher to add the
  questions; the build report flags how many were imported this way. To recover
  the original questions, re-export from Canvas with the item banks included, or
  migrate the New Quizzes to Classic Quizzes first.
- **Re-importing into Canvas (`ident` reuse):** many authoring tools reuse
  assessment/item `ident`s, and Canvas treats matching ids as separate objects
  unless you tick **"Overwrite assessment content with matching IDs"** during the
  GUI import. This doesn't affect importing *into Moodle*, but matters if you ever
  round-trip content back to Canvas.

## Bulk migrations (driving Canvas Uplifter from Automate)

For sites moving **many** courses off Canvas at once, the sibling
[`tool_automate`](https://github.com/verzog/moodle-tool_automate) ("Automate")
plugin can drive Canvas Uplifter in bulk. Canvas Uplifter still works entirely
on its own — this is an optional integration that Automate switches on only when
this plugin is installed.

From *Automate > Bulk Canvas import* (or its `cli/import_canvas.php`), an admin
feeds in packages from either or both of:

- a **pasted list of Canvas backup download URLs**, one per line — each fetched
  in the background through the same SSRF-aware `\curl` layer described above; and
- a **server directory of `.imscc`/`.zip` packages** (a staging area).

Each package becomes one background Canvas Uplifter adhoc task, so nothing is
converted inside the web request — a directory or URL list of hundreds of
courses is queued instantly and then worked through by cron rather than timing
out the page. The tasks run under Moodle's ordinary site-wide adhoc-task
scheduling; the plugin does **not** add its own concurrency cap, so how many run
at once is governed by your `Site administration > Server > Tasks` worker
configuration. Each job is either **built now** (a new course per package) or
**analysed for later** (a conversion report an admin reviews before building the
course from Canvas Uplifter's status page).

Packages staged with *Analyse for later* are listed under *Automate > Staged
Canvas imports*, which shows how much space the stored packages occupy and lets
an admin **selectively delete** finished imports to reclaim it. Deleting a
finished job frees its stored `.imscc` package **once no other job still
references it** — an analyse job and any build made from it share one stored
package, so the space is reclaimed only when the last job pointing at it is
deleted — while any course a build already created is left in place.

A printable, step-by-step walkthrough of this whole flow — enabling the
feature, preparing packages, running a bulk import, and reviewing/building the
staged jobs — is in
[`docs/bulk-canvas-migration-howto.pdf`](docs/bulk-canvas-migration-howto.pdf).

### Integration API — `\tool_canvasuplifter\launcher`

That whole flow rides on a small **public facade**,
`\tool_canvasuplifter\launcher`, which is the supported entry point for other
code to drive the pipeline without reaching into the plugin's `local\` classes.
The upload page (`index.php`) uses it too, so callers get exactly the same
create-a-job-and-queue-a-task contract. Callers are responsible for their own
capability checks first — every caller must enforce `tool/canvasuplifter:use`,
and a *build* additionally needs `moodle/course:create` on the target category.

| Method | Purpose |
|---|---|
| `queue_from_url($userid, $categoryid, $kind, $url, $quizfrombank = false, $pagegrouping = '')` | Queue an analyse/build for a remote package URL (fetched in the task). |
| `queue_from_path($userid, $categoryid, $kind, $path, $filename = '', …)` | Store an on-disk package into this plugin's file area, then queue a job for it. **Copies the file synchronously** (see below). |
| `queue_job($userid, $categoryid, $kind, $fileid = null, $packageurl = null, …)` | Lowest-level queue; pass exactly one of a stored `$fileid` or a `$packageurl`. |
| `list_jobs($userid = null, $kind = null, $status = null, $limit = 0)` | List import jobs, newest first, filterable by user/kind/status — powers an import-history view. |
| `get_job($jobid)` | Fetch one job's current record (status/progress/`courseid`) for polling, or `null` if it no longer exists. |
| `delete_job($jobid, $userid = null)` | Delete a **finished** job and free its stored package when no other job shares it (a built course is left in place). **Pass the authenticated `$userid`** to enforce ownership — with the one-argument form the ownership check is skipped, so an integration exposing deletion by job id must supply it. |
| `package_storage_used($userid = null)` | Total bytes of the stored `.imscc` packages, for a storage counter (per-user when `$userid` is given). |
| `store_package($userid, $path, $filename = '')` | Copy an on-disk package into the plugin's `packages` file area, returning the stored file id. |
| `is_fetchable_url($url)` | Whether a string is an absolute `http(s)` URL with a host (the same check the queue methods apply). |

`$kind` is `job_manager::KIND_ANALYSE` (`'analyse'`) or `KIND_BUILD` (`'build'`).
The three **queue** methods (`queue_from_url`, `queue_from_path`, `queue_job`)
each return a new **job id**; poll `\tool_canvasuplifter\launcher::get_job($jobid)`
for that job's status (`queued` / `running` / `done` / `failed`), progress and, for
a completed build, the created `courseid`. The other methods return their own
types, as the table shows (`list_jobs` an array of job records, `delete_job` and
`is_fetchable_url` a bool, `package_storage_used` a byte count, `store_package` a
stored-file id) — those are not job ids, so don't feed them back to `get_job()`.

`queue_from_path()` (and `store_package()`) copy the package into Moodle file
storage **synchronously**, in the calling request — the deferred-to-cron part is
the *conversion*, not the staging. A caller importing many local packages at once
should therefore call them from its own background task rather than looping in a
web request; that is exactly what Automate's bulk import does, which is why its
page returns immediately even for a large directory. `delete_job()` and
`queue_job()` serialise on a per-package lock, so a build queued from an analyse
job's stored package can never race that package's deletion. Because the facade lives in the
plugin's public namespace, a caller should guard it with `class_exists()` /
`method_exists()` (as Automate does) rather than a hard plugin dependency, so the
caller keeps working when Canvas Uplifter is absent or older.

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
| 2 | Discussions → forums, announcements → news forum | Done |
| 3 | Quizzes + `mod_qbank` question banks (QTI import) | Done |
| 4 | Gradebook categories with Canvas weights, LTI placeholders, in-section labels, Canvas rubrics → `gradingform_rubric`, CC 1.3 IMS Assignment profile | Done |
| 5 | Asynchronous **analyse**: run extract + parse + report as an adhoc task behind the existing polled status page, and move the remote-URL fetch into the task too, so large packages don't time out the web request (the build is already async; this closes the server-side gap that complements the built-in chunked-upload support) | Done |
| 6 | Learning outcomes → course grade outcomes (scales from mastery ratings) | Done |
| 7 | Bulk-migration integration: the public `\tool_canvasuplifter\launcher` facade (queue / list / get / delete / storage) and the `tool_automate` bulk driver — URL-list and server-directory sources, build-now or analyse-for-later, and a *Staged Canvas imports* review page with a storage counter and selective delete | Done |
| 8 | Remaining QTI question types (numerical ✓, calculated ✓, free-text multi-blank ✓) | Done |

## Path to beta

Status today is `MATURITY_ALPHA`. The bar to flip to `MATURITY_BETA` and
submit to the Moodle plugin directory is one clean build of each of these
shapes against `main`:

- [ ] A Canvas course exporting **New Quizzes** alongside Classic Quizzes
      (Canvas writes both as QTI 1.2 with subtly different shapes).
- [ ] A **multi-module Canvas course with cross-references** between pages
      (`$WIKI_REFERENCE$`, `$CANVAS_OBJECT_REFERENCE$`) — exercises the
      post-build link rewriter across pages, forums and assignment intros.
      Strong data point: **ANE 260** (Canvas — 165 items: 73 files, 30
      discussions, 22 assessments [18 standalone question banks + 4 quizzes],
      17 assignments, 19 subheaders, 4 URLs) **built cleanly end-to-end —
      165 of 165 items across 19 sections, 0 skipped** — with **583/583
      questions converting**, 18 runnable quizzes seeded from the standalone
      banks, announcements posted to the news forum, unreferenced resources
      collected into an "Additional resources" section, and Canvas
      unpublished → Moodle hidden states preserved. This does not fully close
      the box on its own: ANE 260 carries no wiki pages, so the page↔page
      link rewriter is not exercised — a page-heavy cross-linked course is
      still wanted for that specific path. But it clears the
      multi-module-build-at-scale concern.
- [ ] An **embedded-media-heavy course** — videos, audio, images both in
      page bodies and in QTI question stems — exercises `file_embedder`
      and the question-asset import path end-to-end.
- [x] A **non-Canvas CC export** (Blackboard, D2L Brightspace, Schoology,
      OpenStax Connexions) — exercises the IMS Assignment profile, prefixed
      namespaces, inline descriptors, and variant targets that PR #57 and #59
      added. Validated: **D2L Brightspace** (regression test) and **Blackboard
      CC 1.2** — a real "Law and Ethics in Medicine" export built cleanly (all
      content plus 54/54 questions, 0 skipped; its `web_content*.log` build
      artifact is now filtered, with a regression fixture added). Schoology and
      OpenStax are still untried.
- [ ] An **outcomes-heavy course** — Canvas learning outcomes now import
      as course grade outcomes, each backed by a scale from its mastery
      ratings (Phase 6 / the 0.41.0 changelog). A clean build of a course
      rich in outcomes is still needed to validate that import at scale.
      Note this box validates outcome *import* only: the **alignment** of
      outcomes to specific rubric criteria or assignments
      (`learning_outcome_identifierref`) is a known non-carry — Canvas
      records it separately and Moodle models it differently — so those
      links are dropped, not converted.

Additional coverage (analysed, doesn't close a box above): **ITSE 1411
"Beginning Web Programming"** (Canvas, CC 1.1) carries the first-class CC
question-bank resource type — `imsqti_xmlv1p2/imscc_xmlv1p1/question-bank`,
distinct from the `assessment` type — alongside a Classic `assessment`, with
all questions (multiple-choice + true/false) of supported types. The analysis
classifies and reports it as a question bank rather than only an orphaned
assessment; a completed build is still needed to confirm `mod_qbank` creation
and question import for this package. (Its `Orientation_Learning_Outcomes.html`
is a content page, not a CC outcome object, so it does not count toward the
outcomes box.)

Additional coverage (built, doesn't close a box above): **"Introduction to
Swiss Programming"** (Canvas, CC 1.1) — a **SoftChalk / CHAMP** OER course, a
new source shape. Built cleanly end-to-end: 297 activities created, 0 skipped
(272 files, 14 URLs, 10 pages, 1 forum). It has no quizzes and no outcomes, so
it does not touch those boxes. Its SoftChalk lessons import as external `mod_url`
links (the lesson bodies are hosted on softchalkcloud.com) and their local HTML
becomes file resources. One page's inline image did not resolve — but that is a
missing source asset (a stale `$IMS-CC-FILEBASE$` reference to the `MAC240`
master course this content was copied from; the file isn't in the export), not a
rewriter fault: `link_rewriter` matched the token and correctly left it untouched
when `resolve_filebase()` found nothing. See issue #111 for the follow-up on
friendlier handling of unresolvable filebase references.

Each successful build narrows the shape space; if all five land cleanly
(or only surface small fixes), flip `version.php` to `MATURITY_BETA` and
submit.

## Releasing (maintainers)

The GitHub Actions workflow (`.github/workflows/moodle-ci.yml`) runs
`moodle-plugin-ci` across every supported Moodle branch (5.0–5.2) on the PHP
versions each supports (8.2–8.4), against PostgreSQL, MariaDB and MySQL. The
**blocking** checks are PHP lint, `phpcs` (the Moodle Code Checker), PHPDoc,
`validate`, upgrade `savepoints`, Mustache lint and PHPUnit; it also runs
`phpcpd` and `phpmd` as **advisory** (`continue-on-error`) steps. There is no
Grunt or Behat step (committed AMD under `amd/build/` is rebuilt with `grunt amd`
locally when `amd/src/` changes). `tooling/local-ci.sh` reproduces the blocking
set before you tag — it does not run the advisory `phpcpd`/`phpmd`.

To cut a release:

1. Keep `$plugin->version` (`YYYYMMDDXX`) monotonically increasing, and set
   `$plugin->release` (the `0.x` string) and `$plugin->maturity`.
2. Update `CHANGELOG.md` — add a dated section for the new `release`.
3. Land those on `main` green, then tag it — substituting the same version you
   set in `$plugin->release` in step 1 for `X.Y.Z` (e.g. `v0.45.0`):
   `git tag -a vX.Y.Z -m 'tool_canvasuplifter X.Y.Z' && git push origin vX.Y.Z`.
4. Package the ZIP with a **top-level folder named `canvasuplifter`** (not
   `moodle-tool_canvasuplifter`), which is the directory name Moodle installs it
   under (`admin/tool/canvasuplifter`).

`$plugin->maturity` stays `MATURITY_ALPHA` — and releases are `0.x` — until the
**Path to beta** boxes above are met; flipping to `MATURITY_BETA` (and a first
`1.0`) is the trigger to submit to the Moodle Plugins directory. Alpha `0.x`
releases can be tagged and distributed as ZIPs in the meantime.

## Licence

GPL-3.0-or-later. "Canvas" is a trademark of Instructure, Inc.; this is an
independent interoperability tool and is not affiliated with or endorsed by
Instructure.
