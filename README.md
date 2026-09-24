# parisek/acf-json-schema

[![Packagist Version](https://img.shields.io/packagist/v/parisek/acf-json-schema.svg)](https://packagist.org/packages/parisek/acf-json-schema)
[![PHP Version](https://img.shields.io/packagist/php-v/parisek/acf-json-schema.svg)](https://packagist.org/packages/parisek/acf-json-schema)
[![ACF Pro](https://img.shields.io/badge/ACF_Pro-6.8.x-blue.svg)](https://www.advancedcustomfields.com/pro/)
[![Tests](https://github.com/parisek/acf-json-schema/actions/workflows/tests.yml/badge.svg)](https://github.com/parisek/acf-json-schema/actions/workflows/tests.yml)
[![License](https://img.shields.io/packagist/l/parisek/acf-json-schema.svg)](LICENSE)

JSON Schema bundle for [Advanced Custom Fields](https://www.advancedcustomfields.com/) JSON exports — field groups (`acf.json`), Custom Post Types (`<cpt>.json`), Taxonomies (`<tax>.json`), and ACF Blocks (`block.json`).

**Target:** ACF Pro 6.8.x. Free edition partially supported (field-group + field-type schemas only).

**Generated against:** ACF Pro 6.8.2 · WPML 4.9.4 · ACFML 2.2.4 — the live install the canonical schemas were curated and snapshot-tested against.

## What this is

Comprehensive JSON Schema (draft 2020-12) coverage for every JSON file the ACF Pro Admin Sync UI emits. Schemas are hand-curated against a live WP + ACF Pro + WPML install — the generator (`bin/acf-schema-gen`) reproduces the canonical baseline, and a snapshot test asserts byte-equality on every CI run. WPML/ACFML keys (e.g. `acfml_field_group_mode`, `wpml_cf_preferences`) are optional, so plain ACF (non-WPML) exports validate too.

Use case: lint your project's ACF JSON files in CI. Catch typos, type drift, and shape regressions before they ship to production.

## Install

```bash
composer require --dev parisek/acf-json-schema
```

Point your Ajv-based JSON validator at `vendor/parisek/acf-json-schema/schemas/`:

```js
import path from "node:path";
import { createRequire } from "node:module";
const require = createRequire(import.meta.url);
const pkgPath = require.resolve("parisek/acf-json-schema/composer.json");
const SCHEMAS_ROOT = path.join(path.dirname(pkgPath), "schemas");
```

Load `acf.schema.json`, `block.schema.json`, `cpt.schema.json`, or `taxonomy.schema.json` from `SCHEMAS_ROOT`, register the `refs/` directory, and validate your project's ACF JSON files against them.

## Bundled schemas

| File | Validates |
|---|---|
| `schemas/acf.schema.json` | ACF Field Group JSON — discriminates on `type` to per-field-type refs |
| `schemas/block.schema.json` | ACF Block JSON (block.json with `acf` section) |
| `schemas/cpt.schema.json` | ACF Custom Post Type JSON (Pro 6.2+ JSON-Sync) |
| `schemas/taxonomy.schema.json` | ACF Taxonomy JSON (Pro 6.2+ JSON-Sync) |
| `schemas/refs/field-<36 types>.schema.json` | Per-field-type closed-shape constraints (35 stable + `icon_picker` new in 6.8) |
| `schemas/refs/{field,icon,location-rule,permalink-rewrite}.schema.json` | Shared utility refs |

(`_meta.json` carries generator provenance — ACF version, timestamp — and is intentionally not part of the canonical schemas.)

## Linting your project's ACF JSON (PHP)

```bash
composer require --dev parisek/acf-json-schema
vendor/bin/acf-lint --strict path/to/templates path/to/blocks
```

`acf-lint` walks the given files/dirs, dispatches each JSON to the right bundled schema (block / acf / cpt / taxonomy), and reports findings. Files of an unrecognized shape are skipped.

| Flag | Effect |
|---|---|
| `--strict` | Exit non-zero on any **error** (CI gate). Notices never affect the exit code — see below. |
| `--fix` | Bump stale/missing `modified` timestamps. |
| `--wpml` | Require WPML/ACFML translation keys to be **present**: `acfml_field_group_mode` on each field group and `wpml_cf_preferences` on every value-holding field (recurses into repeater/group/flexible-content; `tab`/`message`/`accordion` are exempt). In a `translation`/`localization` group it also requires each preference to equal ACFML's mode default, and it notices an `advanced` post/term group that holds a repeater or flexible content. Opt-in — the schemas keep these keys optional so non-WPML projects are unaffected. |
| `--format=<f>` | `text` (default; findings on stderr, summary on stdout), `json` (one machine-readable document on stdout), or `github` (GitHub Actions `::error` / `::notice` annotations — findings appear inline on the PR diff). |
| `--max-errors=<N>` | Cap schema errors collected per file (default 50). |

### Notices

A **notice** is a finding the linter can only raise as a question, so it never
makes a file invalid and never changes the exit code — not even under
`--strict`. It prints alongside the errors (`•` in text, `notices` in the JSON
document, `::notice` in GitHub Actions).

One exists today, under `--wpml`: a `link` field set to
`wpml_cf_preferences: 1` (Copy). What `1` and `2` then do to a link is decided
by the theme that renders the block, not by this package — the description
below is `parisek/timber-kit`'s, which is where the measurement comes from. At
`1` its Copy sync replaces the whole stored value — url, title, target — with
the **source language's**, and nothing translates it afterwards, so a
translation's own correct URL is discarded. At `2` the URL is resolved to a
post and rebuilt in the language being rendered.
Which one is right depends on the values an editor will put in the field, and
the field definition does not carry them: the same `type: link` accepts an
internal URL, an external one, an anchor and a `mailto:`. So the linter asks
instead of deciding — keep `1` when the whole rendered link is identical in
every language, use `2` when the URL can point at translatable site content or
the title differs per language.

Two properties of that theme are worth knowing while answering. A link rendered
with `target="_blank"` is left exactly as stored at `2` — the theme uses the new
tab as an opt-out from the URL rewrite — while at `1` the Copy sync replaces it
like any other field, target included, because the sync runs before the
formatter and never looks at it. And a link's title is never translated at
render, so a title that differs per language needs its own value per language
regardless of the preference.

Text output is colored only on a TTY; set `NO_COLOR` to force plain output. Example CI step with inline PR annotations:

```yaml
- run: vendor/bin/acf-lint --strict --format=github static/templates
```

## For maintainers

Regenerate / verify schemas against a live WP+ACF Pro install:

```bash
ddev exec "php vendor/bin/acf-schema-gen --wp-root /var/www/html --output /tmp/acf-schemas-out/"
diff -r /tmp/acf-schemas-out/ vendor/parisek/acf-json-schema/schemas/
```

See [`RELEASING.md`](RELEASING.md) for the full release flow.

## License

GPL-3.0-or-later
