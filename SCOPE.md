# Canvas → Moodle Course Converter — Project Scope

**Plugin:** `tool_canvasuplifter` ("Canvas Uplifter")
**Target:** Moodle 5.0+ · PHP 8.2+ · published, supported plugin · GPL-3.0
**Run model:** Administrator uploads a Canvas `.imscc` export inside Moodle; the plugin builds the course in place using Moodle's own APIs.
**Locale:** Australian (AU/UK English in language strings, DD/MM/YYYY, AUD).

---

## 1. What this plugin is (in plain terms)

Canvas only lets you export a course one way: a **Common Cartridge** file (`.imscc`). That file is really just a `.zip` with a manifest (`imsmanifest.xml`) listing every piece of content, plus folders holding the actual pages, files and questions. Canvas writes the **1.1** version of that standard and adds its own extra files describing the course structure.

Moodle's built-in "Restore" only understands Common Cartridge **1.0**, so importing a Canvas file the normal way is lossy — files come across, but quizzes arrive as plain text, and assignments, links and pages don't transfer properly. There is **no fully developed Canvas-to-Moodle tool today**, so this plugin fills a real gap.

The plugin reads the Canvas package, understands it, and rebuilds it as a proper Moodle course — sections, pages, assignments, forums, files, quizzes and question banks — with a report telling the admin exactly what converted and what needs a manual look.

---

## 2. Plugin identity

| Item | Recommendation |
|---|---|
| **Plugin type** | `tool` (admin tool) — the idiomatic home for a site-level import/migration utility |
| **Frankenstyle component** | `tool_canvasuplifter` (display name: "Canvas Uplifter") |
| **Directory** | `admin/tool/canvasuplifter/` |
| **Min Moodle** | 5.0 (`$plugin->requires = 2025041400`), because question banks become `mod_qbank` here |
| **PHP** | 8.2, 8.3, 8.4 (Moodle 5.0's minimum is 8.2; `sodium` extension required) |
| **Licence** | GPL-3.0 (matches Moodle plugin directory rules) |

It's a standalone utility, not part of the EduCheckout suite, but it follows the same conventions you already use (clean namespacing, GHA-only CI, AU English).

---

## 3. Architecture — a five-stage pipeline

The whole plugin is one conveyor belt. Keeping the stages separate is what makes it testable and maintainable (and matches how you already structure your tools — parse into a clean model first, then act on the model).

```
.imscc  ─►  1. INGEST  ─►  2. PARSE  ─►  3. MAP  ─►  4. BUILD  ─►  5. REPORT
            (unzip,        (read into     (Canvas    (create in    (what
             validate)      a neutral      → Moodle   Moodle via    converted,
                            model)         decisions) APIs)         what didn't)
```

**1. Ingest.** Admin uploads the `.imscc` through a form. The plugin unzips it to a temporary file area and confirms it really is a Canvas Common Cartridge package (checks for `imsmanifest.xml` and the Canvas `course_settings/` marker files). Large courses are queued as a background **adhoc task** rather than run in the browser — a full import will otherwise blow past PHP's time limit. The admin gets a progress page.

**2. Parse.** Reads the standard manifest (the content tree and resource list) **and** the Canvas extension files — `module_meta.xml` (module/section order), `assignment_groups.xml` (gradebook groupings), `files_meta.xml`, `course_settings.xml` (course name, dates, syllabus). Everything is loaded into a **neutral intermediate model**: a course made of sections made of typed items (`PageItem`, `AssignmentItem`, `QuizItem`, `DiscussionItem`, `FileItem`, `UrlItem`, `LtiItem`). Nothing touches Moodle yet.

**3. Map.** Decides what each item becomes in Moodle and rewrites internal links (see §5). This is pure logic with no side effects, so it's easy to unit-test.

**4. Build.** Creates the Moodle course, sections, then activities **in dependency order** (e.g. question banks before the quizzes that use them; files before the pages that link to them), using Moodle's supported APIs.

**5. Report.** Produces a conversion report: converted ✓, skipped, and "needs manual review" (e.g. an unsupported question type, or an external tool needing credentials). Essential for a supported plugin.

---

## 4. Why "build in place via APIs" (your choice) is the right call

There were two ways to do "upload and a course appears":

- **(A) Emit a Moodle `.mbz` backup file internally, then restore it.** Tempting, but Moodle's backup XML is an internal format that changes between releases — hand-writing it for a plugin you must support across Moodle 5.0/5.1/5.2 is a maintenance trap.
- **(B) Build directly with Moodle's documented APIs** (`create_course`, the module-creation APIs, the question import API). These are the stable, supported surface, and you can test one content type at a time.

You picked the experience that maps to **(B)** — and it's the more robust choice for a long-lived published plugin. The **one** place we deliberately lean on Moodle's own engine is questions: we feed Canvas QTI through Moodle's question-import system (the mechanism behind the existing `qformat_canvas` plugin) instead of writing a QTI parser by hand.

---

## 5. Content mapping (the "everything" coverage)

| Canvas | Moodle target | Difficulty | Notes |
|---|---|---|---|
| Modules | Course sections/topics | Low | Order/structure comes from `module_meta.xml` |
| Pages (wiki) | `mod_page` | Low–Med | HTML link rewriting needed |
| Files | Course files → `mod_resource`/`mod_folder` | Low | Straightforward; mind file-area paths |
| External URLs | `mod_url` | Low | — |
| Assignments | `mod_assign` | Med | Due dates via `userdate()` → DD/MM/YYYY |
| Announcements | Announcements forum | Low | — |
| Discussions | `mod_forum` | Med | Threads → discussions/posts |
| **Quizzes** | `mod_qbank` + `mod_quiz` | **High** | QTI questions; only some types map cleanly |
| **Rubrics** | `gradingform_rubric` (advanced grading) | High | Rubric XML → Moodle definition + attach to assignment |
| **Gradebook** | Grade categories/items | High | `assignment_groups.xml` weights → grade categories |
| LTI / external tools | `mod_lti` (or URL + flag) | Med | Credentials must be reconfigured per site — import as placeholder + report |
| Syllabus | Course summary or a `mod_page` | Low | — |

---

## 6. The genuinely hard parts (risk register)

1. **Internal link rewriting.** Canvas exports replace links with placeholder tokens like `$IMS-CC-FILEBASE$`, `$CANVAS_OBJECT_REFERENCE$`, `$WIKI_REFERENCE$`. Every one has to be rewritten to the correct Moodle `pluginfile` URL or activity link *after* the target exists. This deserves its own component and a solid test suite — it's the most common source of "looks broken after import".

2. **Quiz question types (QTI).** Multiple choice, true/false, short answer, essay, matching and fill-in map well. Canvas-specific types (file-upload questions, some formula/numeric variants) don't. We publish a **support matrix** and report anything unmappable rather than silently dropping it.

3. **Moodle 5 question banks.** Because banks are now `mod_qbank` activities, the builder creates a question bank instance, imports the Canvas questions into it, then wires the quiz to those questions. This is different from pre-5.0 Moodle and is a deliberate design constraint of targeting 5.0+.

4. **Rubrics + gradebook.** Doable but edge-case-heavy; these are the strongest candidates for a later phase rather than day one.

5. **LTI.** External tools can't fully self-configure (they need per-site keys/secrets), so realistically these import as flagged placeholders.

6. **Timeouts/memory.** Handled by running the whole build as an adhoc task, not in the web request.

---

## 7. Published-plugin requirements (your standards, applied)

- **CI:** GitHub Actions only (no Travis). Workflow at `.github/workflows/moodle-ci.yml`, `TZ: Australia/Sydney`, matrix across PHP 8.2/8.3/8.4 and pgsql/mariadb, against Moodle 5.0 and 5.1.
- **Checks:** `phplint`, `codechecker` (4-space indent), `phpdoc` (`@package`/`@copyright`/`@license`), `validate`, `savepoints`, `mustache`, `grunt`, `phpunit`, `behat` (scenarios in DD/MM/YYYY).
- **Language:** AU/UK English throughout `lang/en/` — Customise, Organise, Colour, Behaviour, Enrolment.
- **Security:** `defined('MOODLE_INTERNAL') || die();` in every file; parameterised DB queries.
- **Privacy API:** `privacy/classes/provider.php` required. The plugin processes course *content*, but the upload form and adhoc task touch user-attributable data, so this needs a real (likely metadata) provider with a documented justification — not a null provider by reflex.
- **Docs:** `README.md`, plus a published support matrix for question types and a "what won't transfer" page.

---

## 8. Phased release roadmap

"Everything in version 1.0" is the destination, but shipping it all at once is high-risk. Recommended order — each phase is independently testable and useful:

- **Phase 0 — Skeleton + read-only.** Plugin scaffold, ingest, parser, intermediate model, and the conversion *report* with **nothing built yet**. Lets you point it at real SCCA/Canvas exports and see exactly what's inside before writing any builder code.
- **Phase 1 — Core build.** Course, sections, pages, files, URLs, assignments + link rewriting.
- **Phase 2 — Communication.** Discussions → forums, announcements.
- **Phase 3 — Assessment.** Quizzes + `mod_qbank` question banks via the QTI import path. (Largest single phase.)
- **Phase 4 — Grading.** Rubrics, gradebook categories/weights, LTI placeholders.
- **1.0 = Phases 0–4 complete**, with full CI, Privacy API, docs, and Moodle plugins directory submission.

---

## 9. Open decisions (your steer needed)

1. **Plugin name** — ✅ decided: `tool_canvasuplifter` ("Canvas Uplifter").
2. **Target course** — always create a *new* course, or let the admin pick an existing empty course to build into? (New course is simpler and safer for v1.)
3. **qformat_canvas** — bundle/depend on the existing question-import plugin, or fold an equivalent importer into this plugin so it's self-contained?
4. **Unmappable content** — skip-and-report (recommended) vs best-effort-and-warn?
5. **Question-type priority** — which Canvas question types are must-haves for your real courses? That sets the Phase 3 support matrix.

---

*Australian English throughout. Targets Moodle 5.0+ where question banks are `mod_qbank` activity modules.*
