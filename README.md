# Bomedia Forms

Generic WordPress forms plugin with native AgileCRM integration, built to
run independently across multiple Bomedia websites (boprint, mboprinters,
fluxlasers, artisjet-printers, mqeurope, bomedia.net).

- PHP 7.4+ / WordPress 6.0+
- Vanilla JS frontend, no build step (no npm/webpack)
- One Custom Post Type (`bf_form`) per form
- AES-256-CBC encryption of secrets, keyed off `AUTH_KEY`
- Multi-install: each site keeps its own forms and submissions

## Shortcode

```
[bomedia_form id="123"]
[bomedia_form slug="contacto-boprint"]
[bomedia_form slug="contacto-boprint" lang="en"]
```

`lang="xx"` forces a language (useful for previews); otherwise the
language is detected via WPML → Polylang → browser `Accept-Language` →
site locale.

## Multilingual

The plugin separates **technical configuration** (shared across
languages) from **translatable strings** (per language):

| Translated per language        | Inherited (single value)        |
| ------------------------------ | ------------------------------- |
| Field label                    | AgileCRM credentials & tags     |
| Field placeholder              | Captcha provider & keys         |
| Select/radio option labels     | Notification recipients/Reply-To|
| Email subject & body           | Field types & widths            |
| Post-submit success message    | Field validation patterns       |
| Submit button text             | Anti-spam / rate-limit settings |

There are two supported integration routes:

### 1. WPML (classic — translate the post)

`wpml-config.xml` (plugin root) registers `bf_form` as translatable.

- Technical meta (`_bf_agilecrm`, `_bf_captcha`, `_bf_antispam`,
  `_bf_retention`) is **copied** to every translation, so credentials
  and anti-spam settings stay identical.
- The mixed serialized meta (`_bf_fields`, `_bf_notifications`,
  `_bf_post_submit`) is **not** copied — translate the post and edit the
  labels/messages for that language. **Keep the field `type`, `width`
  and `pattern` identical** to the original when translating; only the
  human-readable strings should differ. (A future release may split
  these into discrete meta for finer WPML control — see
  `// TODO v1.x` markers.)

Put the shortcode (by `slug`) on each translated page; WPML serves the
translated `bf_form` automatically.

### 2. Polylang (translate the strings)

The plugin registers labels, placeholders, option labels, success
message, submit label and the email subject/body via
`pll_register_string()` under the **"Bomedia Forms"** string group.
Translate them in *Languages → String translations*. A single `bf_form`
post is reused across languages — no post duplication needed.

## Logs

Written to `wp-content/uploads/bf-logs/` (web access denied):
`agilecrm.log`, `email.log`, `spam.log`, `admin.log`. Only metadata is
logged — never submission/contact bodies.

## Releases

Packaged by `.github/workflows/release.yml` (manual `workflow_dispatch`
or `v*` tag push). This repository is built up release by release on the
`claude/create-bomedia-forms-plugin-*` branch.
