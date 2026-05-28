# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] — 2026-05-28

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
