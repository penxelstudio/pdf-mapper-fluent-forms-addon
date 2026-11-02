# Changelog

All notable changes to this plugin are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] - 2026-08-29

First stable release. No functional change from 0.3.2 beyond the hardening below; the 0.x series was development.
- Harden: generated document file names now carry 32 random characters instead of 8, matching what templates already used. The readable part of a document name is predictable — an admin-set pattern plus a sequential submission id — and on nginx the file name is the only barrier in front of the file, so the documents holding personal data should not have had weaker names than the blank templates. Existing documents keep their names and remain downloadable.
- Harden: storage directories now also get a `web.config` deny rule for IIS, alongside the existing `.htaccess`.
- Document the nginx gap honestly. nginx reads neither file and WordPress cannot write nginx config, so on nginx the protection is unguessable paths rather than access control. The readme FAQ, README.md and a new SECURITY.md all carry the location block to add for a real deny rule.
- Add `languages/pdf-mapper-for-fluent-forms.pot` so the declared `Domain Path` is no longer an empty promise. 141 translatable strings.
- Add SECURITY.md with a private disclosure route and supported-version policy.

## [0.3.2]

- Remove the Settings screen and its menu entry. It has been an empty placeholder since 0.1.0 — every option the plugin has lives on the feed itself — so it only added a dead end to the menu.
- Add a support card to the Templates screen with a CHIP donation link and a scannable QR code.
- Fix a PHPCS error introduced in 0.3.0: the `script_loader_tag` filter that emits the mapper as an ES module tripped `WordPress.WP.EnqueuedResources.NonEnqueuedScript`. Annotated, since rewriting the tag of an already-enqueued handle is exactly what that filter is for.

## [0.3.1]

- Fix: "Remove binding" could become permanently dead in the mapper. It was gated on `window.confirm()`, and a browser that has had "prevent this page from creating additional dialogs" triggered — easy to hit while placing many pins — makes `confirm()` return false silently for the rest of the tab's life. The button then did nothing, with nothing in the console to explain it. Removing a binding is now immediate, with an inline confirmation message; nothing is persisted until Save either way.
- Fix: the same failure could block saving entirely. The repeater row-height warning also gated on `window.confirm()`, so a tab with dialogs suppressed could not save a mapping at all. It is now a two-step inline prompt — the first click warns, the second saves — and the warning resets when the row height or rows-per-page changes.

## [0.3.0]

- Security: upgrade bundled PDF.js from 3.11.174 to 6.2.108. Version 6 compiles glyph outlines to a Path2D rather than a `new Function()` body, so the CVE-2024-4367 injection sink no longer exists — 0.2.1 only disabled it via the `isEvalSupported` flag, which version 6 has removed along with the code path.
- The mapper is now an ES module and imports PDF.js itself. PDF.js 6 is ESM-only, and the dynamic import means the 500 KB library is fetched only on the screen that renders a PDF, not on every Feeds page view.
- Fix: clamp the render scale. A mapper pane that had not been laid out yet produced a negative scale, and a canvas silently falls back to 300x150 for a negative width, so the template rendered blank with no error.
- The bundled PDF.js files keep their `.js` extension despite holding ES module source: module scripts and module workers are rejected unless the host serves a JavaScript MIME type, and `.mjs` is still served as application/octet-stream on plenty of shared hosting.

## [0.2.1]

- Security: disable the PDF.js eval-based glyph compiler in the mapper. A crafted font matrix in an uploaded template could otherwise execute arbitrary JavaScript in the admin session (CVE-2024-4367) — a font matrix is not active content, so neither the client-side sanitiser nor the server-side token scan removed it.
- Security: the build no longer ships FPDF's `doc/`, `tutorial/` and `makefont/` directories. Those contain directly-reachable PHP files with no ABSPATH guard, one of which writes into the plugin directory when requested.
- Harden: CSV export escapes cells that a spreadsheet would evaluate as a formula.
- Harden: the uploads containment check now compares on a path separator, so a sibling directory whose name merely starts with the uploads path can no longer pass.
- Fix: file name patterns containing consecutive dots produced documents that could never be downloaded or deleted.
- Fix: `wp_die()` on a missing file now returns a real 404 instead of 200.
- Fix: any output buffer left open by another plugin is discarded before streaming a PDF, which could previously corrupt downloads.
- Uninstall now cleans every site on a multisite network, not just the current one.
- Requires WordPress 6.5 (for the `Requires Plugins` header); tested up to 7.1.

## [0.2.0]

- Image bindings: map file-upload fields (signature, company stamp) onto the PDF; transparent PNGs preserved.
- Support Fluent Forms "Repeat Container" (`repeater_container`), including multiple fields stacked per column.
- Mapper shows the fields detected inside a selected repeater so each can be bound to a PDF column.
- Robustness: the repeater source is derived from the repeater bindings, so a mapping still generates rows even if its repeater block was not saved; the mapper now warns when repeater columns are mapped but row height is 0.
- Fix: template upload no longer rejects clean PDFs whose font/image streams happen to contain bytes like "/AA" — the active-content scan now inspects only the PDF structure, not binary stream payloads.

## [0.1.1]

- Recognise Fluent Forms Pro "Repeat Field" element (`input_repeat`), not only the legacy `repeater_field` alias, so its columns are detected in the mapper.
- Harden storage: protected directory now uses an unguessable per-site name (defends servers that ignore .htaccess, e.g. nginx).

## [0.1.0]

- Initial development release.
