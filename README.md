# PDF Mapper for Fluent Forms

Map Fluent Forms fields onto any uploaded PDF template and auto-generate filled PDFs on submission, attached to notification emails.

[![WordPress](https://img.shields.io/badge/WordPress-6.5%E2%80%937.1-21759b)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)](LICENSE)

Upload any PDF as a template, visually place each Fluent Forms field on it, and every submission produces a completed PDF — attached to the notification email and stored where only capability-checked downloads can reach it.

> **Plugin slug:** `pdf-mapper-for-fluent-forms`. The repository name differs; the slug is what WordPress and the plugin directory use.

## Features

- Visual drag-and-map editor powered by PDF.js
- AcroForm widgets auto-detected, or place pins by hand on a completely flat PDF
- Text, checkbox, and image bindings — signatures and company stamps included, transparency preserved
- Repeater support for both **Repeat Field** and **Repeat Container**, with automatic page overflow
- Several templates per form, each with its own conditional rule
- Protected document storage, plus regenerate / resend / CSV export from the admin
- Mapping export and import as JSON, so a layout can be reused across sites

## Requirements

| | |
|---|---|
| WordPress | 6.5 or newer |
| PHP | 8.1 or newer |
| Fluent Forms | Free or Pro, installed and active |
| Browser (admin only) | ES module support — the mapper screen loads PDF.js as a module |

Repeater bindings need the Repeat Field or Repeat Container element, which Fluent Forms Pro provides. Everything else works on the free version.

The plugin declares `Requires Plugins: fluentform`, so WordPress refuses activation without Fluent Forms rather than failing at runtime.

## Installation

Grab a zip from [Releases](https://github.com/penxelstudio/pdf-mapper-fluent-forms-addon/releases) and upload it through **Plugins → Add New → Upload Plugin**.

To build one from source:

```bash
composer install
bin/build.sh
```

That writes `dist/pdf-mapper-for-fluent-forms-<version>.zip` — production dependencies only, installed from `composer.lock` so the vendor tree is reproducible, with dev files and FPDF's demo directories stripped.

## How it works

```
Template upload → sanitised in-browser (pdf-lib) → server-side validation → protected storage
                                                                                    │
Form submission → resolve bindings → FPDI/FPDF overlay ─────────────────────────────┘
                                            │
                                            ├→ protected storage (capability-checked downloads)
                                            └→ notification email attachment
```

A template is rewritten by **pdf-lib** in the browser before it is ever uploaded: OpenAction, the JavaScript name tree, `/AA` additional actions, embedded files and XFA are stripped, and the file is saved without object streams so the free FPDI parser can read it. The server then re-checks the structure independently and refuses anything that still carries active content.

Generation runs on `fluentform/submission_inserted` at priority 5, ahead of Fluent Forms' own notification handlers at 10, so the document exists by the time `fluentform/filter_email_attachments` fires.

Output PDFs carry no AcroForm, annotations or scripts — FPDI imports page content only, so the result is flattened by construction.

## Security model

Worth knowing before you trust it with applicant data:

- **Every endpoint is authenticated.** All twelve AJAX handlers check a nonce *and* `manage_options`. There is no `wp_ajax_nopriv` handler, no REST route, no shortcode, and no front-end hook.
- **Uploads are validated three ways** — libmagic MIME, `%PDF-` signature, and a full FPDI parse of every page — then stored under a server-generated random name, so an upload can never land as an executable file.
- **Generated documents live outside the guessable web path**, in `wp-content/uploads/ffpm-<random>/`, with deny-all `.htaccess` (Apache/LiteSpeed) and `web.config` (IIS) rules written on activation. Every stored file name carries 32 random characters on top of the 16-character directory segment. Files are served only through capability-checked endpoints. See the nginx note below.
- **Submitted values cannot point the generator anywhere.** An image binding resolves only to a path contained inside this site's uploads directory, so a crafted value cannot reach an arbitrary file or a remote host.
- **Uninstall removes everything** — tables, options, and every generated document, on every site of a multisite network. Generated PDFs hold personal data, so deactivation keeps it and uninstall deliberately leaves nothing.

**If you run nginx, read this.** nginx honours neither `.htaccess` nor
`web.config`, and WordPress cannot write nginx configuration. On nginx the only
barrier in front of a stored document is that its path is unguessable. Add a
real deny rule to your server block:

```nginx
location ~* /wp-content/uploads/ffpm-[A-Za-z0-9]+/ {
    deny all;
    return 404;
}
```

Downloads through **PDF Mapper → Documents** are unaffected — they are streamed
by PHP after a capability check and never touch the static path.

See [SECURITY.md](SECURITY.md) for the full policy and for how to report a
vulnerability privately.

## Development

```bash
composer install     # dev toolchain
composer lint        # PHPCS against phpcs.xml
composer lint:fix    # PHPCBF
bin/build.sh         # distributable zip
```

`phpcs.xml` runs `WordPress-Extra` with `WordPress.Files.FileName` excluded, since `src/` is PSR-4 autoloaded and cannot use hyphenated file names. It also pins `minimum_wp_version` and `testVersion`, so PHPCS flags anything newer than the declared support floor.

Layout:

```
src/Admin/         admin screens and their AJAX endpoints
src/Integration/   Fluent Forms hooks, form reading, value resolution
src/Templates/     upload validation and template persistence
src/Pdf/           FPDI/FPDF overlay generation
src/Support/       protected storage
src/Install/       schema and activation
assets/admin/      mapper UI (mapper.js is an ES module)
assets/vendor/     unmodified upstream builds of PDF.js and pdf-lib
```

## Bundled libraries

Shipped unmodified, exactly as published upstream:

| Library | Version | Licence |
|---|---|---|
| [FPDI](https://github.com/Setasign/FPDI) | 2.6.8 | MIT |
| [FPDF](https://github.com/Setasign/FPDF) | 1.9.0 | Permissive (FPDF licence) |
| [PDF.js](https://github.com/mozilla/pdf.js) | 6.2.108 | Apache-2.0 |
| [pdf-lib](https://github.com/Hopding/pdf-lib) | 1.17.1 | MIT |

PDF.js and pdf-lib are the official minified distributions published to npm; their checksums match those releases byte for byte.

The `.js` extension on the bundled PDF.js files is deliberate even though they contain ES module source: browsers reject a module script or module worker unless the host serves a JavaScript MIME type, and `.mjs` is still served as `application/octet-stream` on a lot of shared hosting.

## Licence

GPL-2.0-or-later — see [LICENSE](LICENSE).

PDF.js is Apache-2.0, which is compatible with GPLv3 but not with GPLv2 on its own. Because this plugin is licensed *or-later*, the combined work can be distributed under GPLv3, which resolves it. The bundled libraries stay under their own licences; nothing here relicenses them.

---

Built by [Penxel Studio](https://penxelstudio.com).
