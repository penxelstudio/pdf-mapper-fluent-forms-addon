=== PDF Mapper for Fluent Forms ===
Contributors: penxelstudio
Tags: fluent forms, pdf, form to pdf, pdf generator, email attachment
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Map Fluent Forms fields onto any uploaded PDF template and auto-generate filled PDFs on submission, attached to notification emails.

== Description ==

Upload any PDF document as a template, visually map your Fluent Forms fields onto
its coordinates (AcroForm fields are auto-detected), and every submission
generates a completed PDF — attached to the notification email and stored in a
protected document manager.

* Visual drag-and-map editor powered by PDF.js
* AcroForm field auto-detection, or manual pin placement on flat PDFs
* Text, checkbox, and image (signature / stamp) bindings
* Repeater support — Repeat Field and Repeat Container — with automatic page overflow
* Multiple PDF templates per form, with conditional generation
* Email attachments for admin and submitter acknowledgement
* Protected document storage with capability-checked downloads
* Mapping export/import (JSON) for reuse across sites

Fluent Forms (free or Pro) must be installed and active. Repeater bindings need
the Repeat Field or Repeat Container element, which Fluent Forms Pro provides.

= Bundled third-party libraries =

This plugin ships unmodified releases of four open-source libraries:

* FPDI 2.6.8 (MIT) — https://github.com/Setasign/FPDI
* FPDF 1.9.0 (permissive) — https://github.com/Setasign/FPDF
* PDF.js 6.2.108 (Apache-2.0) — https://github.com/mozilla/pdf.js
* pdf-lib 1.17.1 (MIT) — https://github.com/Hopding/pdf-lib

PDF.js and pdf-lib are the official minified distributions published to npm.

== Installation ==

1. Install and activate Fluent Forms first — this plugin will not activate without it.
2. Upload the plugin zip via Plugins → Add New → Upload Plugin, then activate.
3. Go to PDF Mapper → Templates and upload a PDF. It is sanitised in your browser
   before upload, so keep JavaScript enabled on that screen.
4. Go to PDF Mapper → Feeds, create a feed linking a form to that template, and
   open the mapper to place your field bindings.
5. Save the mapping. Every new submission to that form now generates a PDF.

== Frequently Asked Questions ==

= Does the PDF need to be a fillable AcroForm? =

No. AcroForm widgets are auto-detected and offered as ready-made boxes, but you
can place bindings anywhere on a completely flat PDF using "+ Place field".

= Why was my PDF rejected on upload? =

Two reasons. Password-protected PDFs must have the password removed first. PDFs
carrying active content — JavaScript, launch actions, embedded files, XFA — are
rejected outright, because a document template has no legitimate need for them.

= Where are the generated PDFs stored? =

In a protected directory under wp-content/uploads with an unguessable per-site
name, served only through capability-checked download endpoints. They are never
linked publicly.

= Are the PDFs attached to notification emails? =

Yes, when "Attach PDF to form notification emails" is enabled on the feed. This
relies on Fluent Forms sending the notification within the same request as the
submission, which is the default. If your site is configured to process
notification feeds asynchronously, the attachment may not be included.

= I run nginx. Is the document storage actually protected? =

Not by the deny rules, no. The plugin writes `.htaccess` and `web.config` into
its storage directory on activation, and nginx honours neither — WordPress
cannot write nginx configuration. On nginx a stored document is reachable by
URL, and what keeps it unreachable in practice is that nothing about the path is
guessable: a 16-character random directory plus 32 random characters in every
file name.

If you want a real deny rule, add this to your server block:

`location ~* /wp-content/uploads/ffpm-[A-Za-z0-9]+/ { deny all; return 404; }`

Downloads from PDF Mapper - Documents keep working either way; those are
streamed by PHP after a capability check and never touch the static path.

= What happens to my data if I uninstall? =

Everything is removed: the three plugin tables, all options, and the entire
protected storage directory including every generated document. Because those
documents hold applicant personal data, uninstall deliberately leaves nothing
behind. Deactivating alone keeps all data intact.

== Upgrade Notice ==

= 1.0.0 =
First stable release. Generated documents now carry 32 random characters in
their file name instead of 8 — existing documents keep their current names and
stay downloadable. Read the new nginx note in the FAQ if you host on nginx.

= 0.3.1 =
Fixes "Remove binding" and Save silently doing nothing in the mapper once a
browser has suppressed dialogs for the tab. Recommended for anyone editing
mappings.

= 0.3.0 =
Upgrades the bundled PDF.js to 6.2.108, which removes the eval-based glyph
compiler behind CVE-2024-4367 entirely rather than gating it behind a flag. The
mapper screen now needs a browser with ES module support — every browser
WordPress 6.5 supports already has it.

= 0.2.1 =
Security release. Closes an arbitrary-code-execution path (CVE-2024-4367) that a
crafted PDF template could trigger in the mapper screen. Update immediately if
you accept PDF templates from anyone outside your organisation.

== Changelog ==

= 1.0.0 =
First stable release. No functional change from 0.3.2 beyond the hardening below; the 0.x series was development.
* Harden: generated document file names now carry 32 random characters instead of 8, matching what templates already used. The readable part of a document name is predictable — an admin-set pattern plus a sequential submission id — and on nginx the file name is the only barrier in front of the file, so the documents holding personal data should not have had weaker names than the blank templates. Existing documents keep their names and remain downloadable.
* Harden: storage directories now also get a `web.config` deny rule for IIS, alongside the existing `.htaccess`.
* Document the nginx gap honestly. nginx reads neither file and WordPress cannot write nginx config, so on nginx the protection is unguessable paths rather than access control. The readme FAQ, README.md and a new SECURITY.md all carry the location block to add for a real deny rule.
* Add `languages/pdf-mapper-for-fluent-forms.pot` so the declared `Domain Path` is no longer an empty promise. 141 translatable strings.
* Add SECURITY.md with a private disclosure route and supported-version policy.

= 0.3.2 =
* Remove the Settings screen and its menu entry. It has been an empty placeholder since 0.1.0 — every option the plugin has lives on the feed itself — so it only added a dead end to the menu.
* Add a support card to the Templates screen with a CHIP donation link and a scannable QR code.
* Fix a PHPCS error introduced in 0.3.0: the `script_loader_tag` filter that emits the mapper as an ES module tripped `WordPress.WP.EnqueuedResources.NonEnqueuedScript`. Annotated, since rewriting the tag of an already-enqueued handle is exactly what that filter is for.

= 0.3.1 =
* Fix: "Remove binding" could become permanently dead in the mapper. It was gated on `window.confirm()`, and a browser that has had "prevent this page from creating additional dialogs" triggered — easy to hit while placing many pins — makes `confirm()` return false silently for the rest of the tab's life. The button then did nothing, with nothing in the console to explain it. Removing a binding is now immediate, with an inline confirmation message; nothing is persisted until Save either way.
* Fix: the same failure could block saving entirely. The repeater row-height warning also gated on `window.confirm()`, so a tab with dialogs suppressed could not save a mapping at all. It is now a two-step inline prompt — the first click warns, the second saves — and the warning resets when the row height or rows-per-page changes.

= 0.3.0 =
* Security: upgrade bundled PDF.js from 3.11.174 to 6.2.108. Version 6 compiles glyph outlines to a Path2D rather than a `new Function()` body, so the CVE-2024-4367 injection sink no longer exists — 0.2.1 only disabled it via the `isEvalSupported` flag, which version 6 has removed along with the code path.
* The mapper is now an ES module and imports PDF.js itself. PDF.js 6 is ESM-only, and the dynamic import means the 500 KB library is fetched only on the screen that renders a PDF, not on every Feeds page view.
* Fix: clamp the render scale. A mapper pane that had not been laid out yet produced a negative scale, and a canvas silently falls back to 300x150 for a negative width, so the template rendered blank with no error.
* The bundled PDF.js files keep their `.js` extension despite holding ES module source: module scripts and module workers are rejected unless the host serves a JavaScript MIME type, and `.mjs` is still served as application/octet-stream on plenty of shared hosting.

= 0.2.1 =
* Security: disable the PDF.js eval-based glyph compiler in the mapper. A crafted font matrix in an uploaded template could otherwise execute arbitrary JavaScript in the admin session (CVE-2024-4367) — a font matrix is not active content, so neither the client-side sanitiser nor the server-side token scan removed it.
* Security: the build no longer ships FPDF's `doc/`, `tutorial/` and `makefont/` directories. Those contain directly-reachable PHP files with no ABSPATH guard, one of which writes into the plugin directory when requested.
* Harden: CSV export escapes cells that a spreadsheet would evaluate as a formula.
* Harden: the uploads containment check now compares on a path separator, so a sibling directory whose name merely starts with the uploads path can no longer pass.
* Fix: file name patterns containing consecutive dots produced documents that could never be downloaded or deleted.
* Fix: `wp_die()` on a missing file now returns a real 404 instead of 200.
* Fix: any output buffer left open by another plugin is discarded before streaming a PDF, which could previously corrupt downloads.
* Uninstall now cleans every site on a multisite network, not just the current one.
* Requires WordPress 6.5 (for the `Requires Plugins` header); tested up to 7.1.

= 0.2.0 =
* Image bindings: map file-upload fields (signature, company stamp) onto the PDF; transparent PNGs preserved.
* Support Fluent Forms "Repeat Container" (`repeater_container`), including multiple fields stacked per column.
* Mapper shows the fields detected inside a selected repeater so each can be bound to a PDF column.
* Robustness: the repeater source is derived from the repeater bindings, so a mapping still generates rows even if its repeater block was not saved; the mapper now warns when repeater columns are mapped but row height is 0.
* Fix: template upload no longer rejects clean PDFs whose font/image streams happen to contain bytes like "/AA" — the active-content scan now inspects only the PDF structure, not binary stream payloads.

= 0.1.1 =
* Recognise Fluent Forms Pro "Repeat Field" element (`input_repeat`), not only the legacy `repeater_field` alias, so its columns are detected in the mapper.
* Harden storage: protected directory now uses an unguessable per-site name (defends servers that ignore .htaccess, e.g. nginx).

= 0.1.0 =
* Initial development release.
