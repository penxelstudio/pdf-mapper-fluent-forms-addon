=== PDF Mapper for Fluent Forms ===
Contributors: penxelstudio
Tags: fluent forms, pdf, form to pdf, pdf generator, email attachment
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.3.0
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

= What happens to my data if I uninstall? =

Everything is removed: the three plugin tables, all options, and the entire
protected storage directory including every generated document. Because those
documents hold applicant personal data, uninstall deliberately leaves nothing
behind. Deactivating alone keeps all data intact.

== Upgrade Notice ==

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
