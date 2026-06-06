# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- Top-level `file` fields no longer fail validation for their standard ACF properties. `refs/field-file.schema.json` was an empty stub, so `return_format`, `library`, `min_size`, `max_size`, and `mime_types` were treated as unevaluated and rejected by `acf.schema.json`'s `unevaluatedProperties: false` (the same field validated fine when nested in `sub_fields`, which doesn't apply that keyword). The stub is now populated with the file field's properties. ([#8](https://github.com/parisek/acf-json-schema/issues/8))
- Top-level fields of 10 further types no longer reject their valid ACF properties — `oembed`, `user`, `page_link`, `relationship`, `clone`, `tab`, `time_picker`, `date_picker`, `date_time_picker`, `button_group` had empty per-type stubs (same root cause as `file`). Property sets curated from real ACF Pro exports. ([#8](https://github.com/parisek/acf-json-schema/issues/8))

### Changed

- Field validation is now uniform across nesting depth. A new generated `field-item.schema.json` (base schema + per-type discriminator + `unevaluatedProperties: false`) is referenced from `acf.schema.json` `fields[]` and every `sub_fields` / flexible-content `layouts[].sub_fields`. Previously nested fields were validated against the base schema only (and `group` sub-fields not at all), so the same field could pass nested yet fail at the top level. See ADR 0005. ([#8](https://github.com/parisek/acf-json-schema/issues/8))

## [0.4.1] - 2026-06-01

### Changed

- Adopted the shared parisek QA tooling: `composer validate --strict` + `composer audit` + `composer normalize` CI gates (`ergebnis/composer-normalize` added to dev). No code-style formatter — this package's same-line-brace style isn't PER-CS, which would reformat it wholesale. Dev-only; no consumer impact.

## [0.4.0] - 2026-05-29

### Added

- `acf-lint --wpml` — opt-in flag that requires WPML/ACFML translation keys to be **present**: `acfml_field_group_mode` on each field group and `wpml_cf_preferences` on every value-holding field (recurses into repeater/group/flexible-content sub-fields; `tab`/`message`/`accordion` are exempt as they hold no translatable value). The bundled schemas keep these keys optional (ACF-faithful); `--wpml` lets multilingual projects enforce their house rule without forking the schemas.

## [0.3.0] - 2026-05-29

### Added

- `bin/acf-lint` — PHP CLI that validates ACF / CPT / taxonomy / block JSON against the bundled schemas (`--strict` CI gate, `--fix` for stale `modified` timestamps). Lets consumers lint via Composer/PHP without a Node/ajv toolchain.
- `src/Lint/AcfLinter` + `FileLintResult` — reusable validation core (schema dispatch, file collection, opis-backed validation).
- `.gitattributes` `export-ignore` so dev-only files (tests, docs, CI config, agent notes) are excluded from the Composer dist.

### Changed

- `opis/json-schema` promoted from `require-dev` to `require` (runtime dependency for `acf-lint`).

## [0.2.0] - 2026-05-29

### Added

- README **Generated against** provenance line: ACF Pro 6.8.2 · WPML 4.9.4 · ACFML 2.2.4 (the live install the schemas were curated against).
- `CLAUDE.md` + `AGENTS.md` — operational notes for AI coding agents (schema source-of-truth rules, add-a-field-type checklist, regeneration, conventions).

### Changed

- WPML/ACFML field-group keys are now **required only when present**: `acfml_field_group_mode` dropped from `acf.schema.json`'s root `required` (its value is still constrained to `"advanced"` when present), so plain ACF (non-WPML) exports validate too. `wpml_cf_preferences` was already optional.
- Tightened "boolean-ish" flags from the broad `integer` type to `enum: [true, false, 0, 1]` — taxonomy bool flags + `single_value`, and color_picker `enable_opacity`/`show_color_wheel` — so malformed values like `2`/`-1` are rejected.

### Fixed

- `icon_picker` (ACF 6.8) added to the base `refs/field.schema.json` `type` enum; its discriminator branch was unreachable, so real `icon_picker` fields were wrongly rejected by the base `$ref`.
- `Generator` bootstrap shutdown guard scoped with a `$bootstrapComplete` flag so a post-bootstrap fatal is no longer mislabelled as a "WordPress bootstrap failed" diagnostic.
- Renamed `tests/helpers` → `tests/Helpers` to match the PSR-4 namespace; case-sensitive Linux CI was failing to autoload the test `Validator` helper.

## [0.1.0] - 2026-05-28

### Added

- Initial release.
- Bundled JSON Schemas (draft 2020-12) for ACF Pro JSON exports targeting ACF Pro 6.8.x:
  - `schemas/acf.schema.json` — Field Group root with per-field-type `anyOf` discriminator
  - `schemas/block.schema.json` — block.json with ACF section; SVG icons must contain `currentColor`
  - `schemas/cpt.schema.json` — CPT JSON with closed `menu_icon` patterns (dashicons, SVG, data URI)
  - `schemas/taxonomy.schema.json` — Taxonomy JSON
  - 36 per-field-type refs in `schemas/refs/field-*.schema.json` (35 stable + `icon_picker` new in ACF 6.8)
  - Shared utility refs: `field`, `icon`, `location-rule`, `permalink-rewrite`
- `bin/acf-schema-gen` — PHP CLI that bootstraps a live WP+ACF Pro install + AST-parses `render_field_settings()` for enum extraction, then assembles `acf.schema.json` and copies static refs into the output directory
- Test corpus: 15 real ACF exports from `starter_theme` + `fellows` (post-PR#43), 4 invalid-corpus regression guards
- `SnapshotTest` ensures generator output matches committed `schemas/` byte-for-byte (skipped unless `ACF_SCHEMA_TEST_WP_ROOT` env is set)
- `EnumChoicesExtractor` (AST via `nikic/php-parser`) — currently used only as a discovery aid; v0.1.0 ships hand-curated field-type refs, with the AST extractor retained for v0.1.x drift detection against new ACF versions
