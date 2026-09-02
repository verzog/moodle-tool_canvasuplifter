# Large file repository (`repository_largefile`)

A standalone Moodle **repository plugin** that adds two ways to bring a file that
is too big for a normal upload into any Moodle file picker — including the
**course backup restore** "upload a backup file" screen:

- **Import from a URL** — the site fetches the file server-side (following
  redirects, size-capped), so the browser upload size never applies.
- **Chunked large-file upload** — the browser uploads the file in small chunks
  that are reassembled on the server, so PHP's `upload_max_filesize` /
  `post_max_size` never apply.

Because it is a repository plugin, both appear **everywhere the file picker is
used** (assignments, resources, the course restore upload, …) with no per-place
configuration.

> This plugin lives in the [`tool_canvasuplifter`](../README.md) repository for
> convenience, but it is a **separate, self-contained plugin**. It installs to
> `repository/largefile/` in a Moodle tree, not under `admin/tool/`.

## How it works

Both paths produce a *staged file*: one `repository_largefile_chunks` row plus a
file under `$CFG->dataroot/repository_largefile/chunks/`. The file picker lists a
user's staged files and, when one is selected, the repository hands its bytes to
the draft area via `get_file()`. Delivery happens through the picker's *download*
action rather than a multipart upload, which is why neither PHP upload limit
applies.

The upload/URL dialogue is launched from the file picker's **"Upload a file"**
toolbar button: `get_listing()` advertises `uploadfile`/`uploadevent`, the bundled
`repository_largefile/upload` AMD module subscribes to that event, opens a
dialogue (a tab to upload from the computer in chunks, a tab to fetch from a URL),
and re-lists the repository when a file has been staged. This is the same
supported extension point core's Google Docs repository uses.

Stale staged files are removed by the `cleanup_chunks` scheduled task, whose
retention windows are configurable.

## Install

1. Copy the **contents of this folder** to `repository/largefile/` in your Moodle
   tree (so `repository/largefile/version.php` exists).
2. Visit *Site administration > Notifications* to complete installation.
3. Enable it at *Site administration > Plugins > Repositories > Manage
   repositories* — set **Large file** to "Enabled and visible".
4. (Optional) Tune the chunk size and retention at *Site administration >
   Plugins > Repositories > Large file*.

Requires Moodle 5.0+ and PHP 8.2–8.4.

## Security — server-side URL fetch (SSRF)

The URL-import path fetches the given URL server-side through Moodle's `\curl`
wrapper, so it is subject to the site's **cURL security settings** at *Site
administration > Security > HTTP security*. On Moodle 5.0+ those ship secure by
default (loopback, private ranges, `localhost`, the cloud-metadata address and
non-80/443 ports are blocked), so a user-supplied URL cannot reach internal
services out of the box. Keep those defaults in place, and extend the blocklist
for your network if needed (for example IPv6 link-local/unique-local ranges).

## Settings

| Setting | Default | Purpose |
|---|---|---|
| Chunk size (MB) | 20 | Bytes per chunk. Lower it if large uploads fail behind a proxy/WAF that rejects big request bodies. |
| Keep unused upload tokens for | 1 hour | Retention for a token that was created but never used. |
| Keep unfinished uploads for | 1 hour | Retention for a partially uploaded file. |
| Keep completed uploads for | 1 day | Retention for a completed upload that was never selected. |

## Developing / validating

This plugin is excluded from `tool_canvasuplifter`'s `moodle-plugin-ci` run (see
the repo-root `.moodle-plugin-ci.yml`) because it is a different component. To
validate it on its own, install it as `repository/largefile/` in a Moodle tree
and run the standard checks against that path (`phplint`, `phpcs`, `phpdoc`,
`validate`, `mustache`, `phpunit`).

After editing `amd/src/upload.js`, rebuild `amd/build/` with `grunt amd`.

## Licence

GPL-3.0-or-later. The chunked-upload logic derives from `local_chunkupload`
(2020 Justus Dieckmann WWU).
