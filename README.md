# parisek/acf-json-schema

JSON Schema bundle for [Advanced Custom Fields](https://www.advancedcustomfields.com/) JSON exports — field groups (`acf.json`), Custom Post Types (`<cpt>.json`), Taxonomies (`<tax>.json`), and ACF Blocks (`block.json`).

**Target:** ACF Pro 6.8.x. Free edition partially supported (field-group + field-type schemas only).

## What this is

Comprehensive JSON Schema (draft 2020-12) coverage for every JSON file the ACF Pro Admin Sync UI emits. Schemas are hand-curated against a live WP+ACF Pro install — the generator (`bin/acf-schema-gen`) reproduces the canonical baseline, and a snapshot test asserts byte-equality on every CI run.

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

## For maintainers

Regenerate / verify schemas against a live WP+ACF Pro install:

```bash
ddev exec "php vendor/bin/acf-schema-gen --wp-root /var/www/html --output /tmp/acf-schemas-out/"
diff -r /tmp/acf-schemas-out/ vendor/parisek/acf-json-schema/schemas/
```

See [`RELEASING.md`](RELEASING.md) for the full release flow.

## License

GPL-3.0-or-later
