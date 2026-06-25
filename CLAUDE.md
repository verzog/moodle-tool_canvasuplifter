# Repository guidance

`tool_canvasuplifter` imports Canvas Common Cartridge (`.imscc`) exports into
Moodle. This file orients automated contributors; see `README.md` for the
user-facing overview and roadmap.

## Architecture (keep these boundaries)

- `classes/local/ingest/` — unzip/validate/fetch the package.
- `classes/local/parser/` — read the IMS manifest + Canvas extension XML into the
  neutral model. **No Moodle dependencies** (unit-testable from strings/files).
- `classes/local/model/` — plain course/section/item data holders. No Moodle deps.
- `classes/local/report/` — analyse-only mappability report. No Moodle deps;
  returns lang-string *keys*, not text.
- `classes/local/build/` — the Moodle-coupled builders (`course_builder` plus per
  kind `page_builder`, `url_builder`, `file_builder`, `assign_builder`). Shared
  helpers: `link_rewriter` (Moodle-free), `file_embedder`, `safe_path`.

Prefer adding new activity types as a `*_builder` with a uniform
`build(stdClass $course, int $sectionnum, item $modelitem): ?int` signature, and
register it in `course_builder`'s `$builders` map + `BUILDS_NOW` + `KIND_TO_MOD`.

- `classes/chunkupload/` — the bundled chunked uploader (`form_element`,
  `state_type`) for the optional "Large package" upload field, folded in from the
  former `local_chunkupload`. Backed by the `tool_canvasuplifter_chunks` table,
  the `chunkupload_ajax.php` endpoint (one script, `action`=start/proceed/delete),
  the `amd/src/chunkupload.js` module and the `cleanup_chunks` scheduled task.
  After editing `amd/src/chunkupload.js`, rebuild `amd/build/` with `grunt amd`.

## Validate locally before pushing

CI (`.github/workflows/moodle-ci.yml`) runs `moodle-plugin-ci` against a real
Moodle across PHP 8.2–8.4 × Moodle 5.0–5.2 × pgsql/mariadb/mysqli. Reproduce it
locally with:

```
tooling/local-ci.sh            # provision (first run) + run every CI check
tooling/local-ci.sh phpunit    # just PHPUnit, reusing the installed Moodle
tooling/local-ci.sh --reinstall
```

It provisions `moodle-plugin-ci` + Moodle + a local PostgreSQL under
`~/.moodle-plugin-ci` (no Docker daemon needed). **Run it before pushing changes
to anything under `classes/local/build/`** — builders call `add_moduleinfo()`,
whose required field sets are easy to get wrong and only surface against a real
Moodle. After provisioning, the Moodle source lives at
`~/.moodle-plugin-ci/moodle`; grep `mod/<name>/` there (db/install.xml,
locallib.php `save_instance`, mod_form.php) to confirm a module's expected fields
instead of guessing.

## Conventions

- Lines ≤ 132 chars; full PHPDoc on every function. `@param` types must be plain
  (`array`, not `array<string, string>`) — the Moodle PHPDoc checker rejects
  generics in `@param`.
- Don't `mtrace()` on a path exercised by unit tests (it marks them risky); gate
  diagnostics to non-test runs or drop them.
- Keep `parser`/`model`/`report` free of Moodle so their tests stay fast.
