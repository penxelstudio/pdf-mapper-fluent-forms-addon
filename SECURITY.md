# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| 1.0.x | Yes |
| < 1.0 | No — pre-release development builds |

## Reporting a vulnerability

Please report privately rather than opening a public issue.

- GitHub: [open a private security advisory](https://github.com/penxelstudio/pdf-mapper-fluent-forms-addon/security/advisories/new)
- Email: the address published at [penxelstudio.com](https://penxelstudio.com)

Include the plugin version, WordPress and PHP versions, and the steps to
reproduce. A proof-of-concept PDF or form export helps a great deal.

We aim to acknowledge within 5 working days and to ship a fix or a mitigation
plan within 30 days. Please give us that window before disclosing publicly.

## What this plugin holds

Generated PDFs contain whatever the form collected, which for most deployments
is personal data. Treat any finding that exposes a stored document, a template,
or a mapping as high severity.

## Storage protection — read this if you run nginx

Templates and generated documents live in
`wp-content/uploads/ffpm-<random>/`, and the plugin writes `.htaccess` and
`web.config` deny rules into that directory on activation.

**nginx honours neither file, and WordPress cannot write nginx configuration.**
On nginx the directory name (16 random characters) and the file names (each
carrying 32 random characters) are what keep documents unreachable. That is
unguessable in practice, but it is obscurity, not access control.

If you run nginx and want a real deny rule, add this to your server block:

```nginx
location ~* /wp-content/uploads/ffpm-[A-Za-z0-9]+/ {
    deny all;
    return 404;
}
```

Downloads through **PDF Mapper → Documents** keep working — those are streamed
by PHP after a capability check and never touch the static file path.

## Known design decisions

These are deliberate, not oversights:

- Every plugin screen and AJAX endpoint requires `manage_options`. There is no
  front-end surface, no REST route, and no shortcode.
- Uploaded templates are rewritten in the browser to strip active content, then
  re-validated server-side; anything still carrying JavaScript, launch actions,
  embedded files or XFA is rejected outright.
- Generated PDFs are flattened by construction — FPDI imports page content only,
  so no AcroForm, annotations or scripts survive into the output.
- Uninstall destroys all plugin data on every site of a network, because those
  documents hold personal data. Deactivation preserves everything.
