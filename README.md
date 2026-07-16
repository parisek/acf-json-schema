# parisek/acf-json-schema

[![Packagist Version](https://img.shields.io/packagist/v/parisek/acf-json-schema.svg)](https://packagist.org/packages/parisek/acf-json-schema)
[![PHP Version](https://img.shields.io/packagist/php-v/parisek/acf-json-schema.svg)](https://packagist.org/packages/parisek/acf-json-schema)
[![ACF Pro](https://img.shields.io/badge/ACF_Pro-6.8.x-blue.svg)](https://www.advancedcustomfields.com/pro/)
[![Tests](https://github.com/parisek/acf-json-schema/actions/workflows/test.yml/badge.svg)](https://github.com/parisek/acf-json-schema/actions/workflows/test.yml)
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
| `--strict` | Exit non-zero on any finding (CI gate). |
| `--fix` | Bump stale/missing `modified` timestamps. |
| `--wpml` | Require WPML/ACFML translation keys to be **present**: `acfml_field_group_mode` on each field group and `wpml_cf_preferences` on every value-holding field (recurses into repeater/group/flexible-content; `tab`/`message`/`accordion` are exempt). Opt-in — the schemas keep these keys optional so non-WPML projects are unaffected. |
| `--format=<f>` | `text` (default; findings on stderr, summary on stdout), `json` (one machine-readable document on stdout), or `github` (GitHub Actions `::error` annotations — findings appear inline on the PR diff). |
| `--max-errors=<N>` | Cap schema errors collected per file (default 50). |

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
