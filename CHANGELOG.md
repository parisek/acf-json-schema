# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.7.7] - 2026-09-04

### Fixed

- **`repeater` / `flexible_content` now accept `wpml_cf_preferences: 1` as well
  as `3`, and the finding is labelled doctrine rather than a plugin fact.** The
  rule claimed ACFML forces wrappers to `3` at runtime and that anything else is
  dead configuration. It does not: `field_should_be_set_to_copy_once()` only
  widens `is_field_parsable()`, and `save_field_settings()` writes the
  configured value. On options pages `EditorHooks::maybeCopyWrapperToTranslations()`
  fires at `1`, not `3`, so `3` is not privileged there either — and ACFML's own
  block-preference migration writes `1` to wrappers. Both values are legitimate:
  `3` when translations should diverge, `1` when the rows are identical in every
  language, because `1` carries the row-count meta ACF reads first and `3` omits
  it, emptying the field on every translation. Reported in #39 after the rule's
  advice caused a production defect downstream and then flagged its fix.

## [0.7.6] - 2026-09-04

### Fixed

- **`readonly` and `disabled` are now valid on the field types that render
  them.** ACF Pro passes both into the rendered input for `text`, `textarea`,
  `number`, `range`, `email`, `url`, `password`, `select`, `date_picker`,
  `date_time_picker` and `time_picker`, but no schema declared either key and
  `field-item.schema.json` sets `unevaluatedProperties: false` — so a field
  group using ACF's own read-only inputs failed validation, with no local
  ignore to fall back on. Grounded in
  `includes/fields/class-acf-field-text.php:71` (which `password` reaches via
  `class-acf-field-password.php:52`, delegating its whole render to `text`),
  `class-acf-field-select.php:283`, and the `$keys2` list each remaining type
  passes to `acf_esc_attrs()`. Added per type rather than to the base field
  schema: ACF ignores both on `image`, `repeater`, `true_false` and the rest,
  and accepting them there would let dead configuration ship silently. Found
  downstream on `fellows`, whose `flat` group marks two import-owned fields
  read-only.
  Note for future ACF upgrades: this is a per-type list and it will need
  re-checking when ACF changes which types render these attributes.

## [0.7.5] - 2026-08-12

### Fixed

- **`wpml_cf_preferences: 3` is now valid on an image or gallery under a
  `post_type`/`block` location.** Copy-once is the correct mode for an image the
  editor re-authors per language — an e-book cover with the headline baked into
  the artwork, a localised screenshot. Copy (`1`) re-syncs from the default
  language on every save, so the previous "must be 1" left such a project unable
  to be lint-clean without overwriting the translated asset, and the package has
  no ignore mechanism to silence it locally. `2` stays rejected outside options
  pages, and `3` stays rejected on an options page, which has no post
  duplication to seed a copy-once value from.
- **The `--wpml` image/gallery message now names the value it actually found.**
  Every post/block finding used to read "value 2 is the options-page-only
  carve-out", including for a field carrying `0` — which was then told why a
  value it does not have is wrong.

## [0.7.4] - 2026-08-10

### Fixed

- **`keywords` entries must now be strings.** The block schema declared
  `keywords` as `["null", "array"]` and stopped there, so `"keywords": [1, 2, 3]`
  passed both the validator and `acf-lint --strict`. WordPress block metadata
  requires an array of strings; a numeric entry reaches the block registry and is
  not usable as a search keyword. A regression fixture pins the violation at
  `/keywords/0`.

  Surfaced while reviewing the `parisek/definition-kit` fix that stopped its
  generator dropping `description` and `keywords` — the key the generator was
  losing turned out never to have been constrained here either.

  The same untyped-items gap exists on `acf.hide_on_screen`,
  `cpt.capability_type`, `cpt.taxonomies` and a number of field-schema arrays.
  Those are **deliberately left alone**: several accept mixed scalar shapes, and
  typing them correctly needs real ACF serialization evidence rather than a
  guess. Tracked for a follow-up.

## [0.7.3] - 2026-08-09

### Fixed

- **`flexible_content.layouts` now accepts both container shapes ACF itself
  produces.** The ref required a JSON array, matching ACF Pro 6.8.6's own
  published field schema (`schemas/fields/v1/flexible_content.json`). But ACF's
  field-group admin renders each layout's settings under
  `[layouts][<layout_key>]` (`pro/fields/class-acf-field-flexible-content.php:348`),
  so a save in wp-admin writes local JSON with `layouts` as an **object keyed by
  layout key** — a file ACF just wrote, that this package then rejected.

  ACF is internally inconsistent here, and it normalises neither shape: an
  import→export round-trip through `acf_prepare_field_group_for_import()` /
  `_for_export()` returns whichever shape it was handed. So `layouts` is now a
  `oneOf` over an array of layouts and a `^layout_`-keyed map of them, with the
  layout constraints factored into `$defs/layout` and applied identically to
  both. Key/name/label requirements are unchanged — a map keyed by anything that
  is not layout-key-shaped still fails (new invalid fixture).

  Scope of the keyed branch, stated precisely: it constrains `propertyNames` to
  `^layout_` and validates every value as a layout. It does **not** assert that a
  map key equals its layout's own `key` — JSON Schema 2020-12 cannot compare a
  property name against a nested value, so `{"layout_a": {"key": "layout_b"}}`
  passes. ACF does not rely on that equality either (it reads `key` from the
  layout body), so this is a documented limit, not a gap left to close.

  Found downstream on `sloneek`, where `composer lint:acf-json` failed on a
  hand-authored component whose `layouts` followed the legacy keyed shape.

### Changed

- **ADR practice unified across the four Composer packages.** `docs/adr/README.md`
  and the `AGENTS.md` § *Architecture decisions* section now carry the same rules
  as `parisek/styleguide`, `parisek/timber-kit` and `parisek/definition-kit`,
  gaining two this repo lacked: an ADR of a sibling repo is cited qualified
  (`tailwind-base ADR-0007`, never a bare number — the numbering spaces are
  per-repo), and every ADR must appear in the index.

  `scripts/check-adr-index.py` (`composer adr`, CI job *docs/adr/ index is in
  sync*, also folded into `composer check`) enforces the second: it fails on an
  ADR missing from the index, a duplicate number, a dangling index entry, or an
  off-convention filename. This repo is already clean on all four — the check is
  a guard against future drift, which a sibling had already accumulated.

## [0.7.2] - 2026-07-28

### Fixed

- `refs/permalink-rewrite.schema.json` now accepts both encodings of the four boolean rewrite flags (`with_front`, `feeds`, `pages`, `rewrite_hierarchical`). They were pinned to the string enum `"0"`/`"1"`, which rejected ACF Pro's own native export: `acf_prepare_internal_post_type_for_export()` emits real JSON booleans for values coming from ACF's defaults, and the string form only for values coming from the Admin Sync form. Both round-trip — ACF's consumer casts with `(bool)` — so pinning either one alone made `acf-lint` fail on files ACF itself had just written ([#32](https://github.com/parisek/acf-json-schema/pull/32)).

### Added

- Fixture coverage for the rewrite flags in both encodings, including the first taxonomy fixtures in the suite — `rewrite_hierarchical` is taxonomy-only and had no coverage at all.

## [0.7.1] - 2026-07-25

### Added

- `acf-lint --wpml` now checks `wpml_cf_preferences` VALUE by field type, not just its presence, extending the 0.7.0 location-context check with three more layers ([#30](https://github.com/parisek/acf-json-schema/issues/30)):
  - **`repeater` / `flexible_content` must be `3`.** This is a plugin fact, not doctrine: ACFML forcibly overrides both types to `3` (`WPML_COPY_ONCE_CUSTOM_FIELD`) at runtime regardless of the configured value — authority is `ACFML\Helper\Fields::WRAPPER_FIELDS` and `WPML_ACF_Field_Settings::field_should_be_set_to_copy_once()`. Any other configured value is provably dead configuration.
  - **`group` should be `3`.** Realized in the *same* `--wpml` findings list as the plugin-forced check above (the linter has no severity/warning-level concept to reuse, and adding one for a single rule would be over-engineering) — the softer certainty is conveyed entirely through message wording: this check's message explicitly reads "(doctrine, not a plugin fact)" and cites `gutenberg.md` § Key Requirements, vs. the plugin-forced check's message which cites the ACFML source files as authority.
  - **`accordion` / `tab` / `message` must be `0` or absent.** These are ACF UI/layout pseudo-fields holding no translatable value; any other configured value is meaningless.
  - Leaf value types (text, wysiwyg, image, link, select, url, …) are unchanged — they keep only the pre-existing presence check; the 1-vs-2 split there is genuine author intent this linter still does not second-guess (Layer 4 of the issue's proposal, deliberately out of scope).

  **⚠️ Existing projects will very likely surface brand-new findings that did not exist before** — a fleet census across 1769 `acf.json` files found ~837 `repeater`/`flexible_content` fields already configured with a value the plugin discards at runtime. This is intentional and expected, not a regression: those findings were always true (the plugin has always overridden the value), the linter simply couldn't see it until now.

## [0.7.0] - 2026-07-25

### Fixed

- `field-image.schema.json` / `field-gallery.schema.json` no longer reject `wpml_cf_preferences: 2` on image/gallery fields. The schemas were curated against a live install in a `post_type`/`block` location context, where `1` ("copy") is correct — but on an ACF Options Page, ACFML permanently locks a `1`-flagged field to its default-language value (there's no post duplication to copy from), so `tailwind-base`'s `wordpress/gutenberg.md` and `wordpress/wpml.md` both document `2` for every value field on an options page, image/gallery included. Static per-type refs carry no location context to conditionally enforce `1`-only-outside-options-pages, so both canonical values are now accepted unconditionally on these two refs (matching how `field-link`/`field-select`/`field-url` already fall through to the general `field.schema.json` `enum: [0,1,2,3]` with no per-type override).

- `--wpml` location classification no longer misreads field groups whose `location` mixes object types. It tracked only `options_page` versus `post_type`/`block`, so every other ACF `param` was invisible and a group located at `options_page OR taxonomy` collapsed to a pure options-page context — demanding the options-page value from fields that also render on a term screen, contradicting the classifier's own documented policy of leaving genuinely dual-context groups alone.
- `--wpml` location classification now runs per OR-group instead of through one global flag, which could not distinguish two AND-rules inside one OR-group from two separate OR-groups. That silenced `post_type AND page_template` — among the most common real ACF shapes — trading a false positive on a rare shape for a false negative on a common one. All 24 params from `location-rule.schema.json` are now assigned deliberately: `post_type`/`block` and `options_page` as primary contexts; `page_template`, `post_template`, `post_status`, `post_format`, `post_category`, `post_taxonomy`, `post`, `page_type`, `page_parent`, `page` and `attachment` as post-context qualifiers; `current_user`/`current_user_role` as neutral; the remainder as distinct contexts that force ambiguity when mixed. Note this also newly activates **standalone qualifier groups** — a group located solely at e.g. `page_template == x.php`, with no `post_type`, previously resolved to ambiguous and was skipped, and is now checked. The `operator` is deliberately ignored: `post_type != page` is still a post-type context, so negation does not change the demanded value.

### Added

- `acf-lint --wpml` now cross-checks a field's `wpml_cf_preferences` against its field group's ACF `location`, not just its presence. The `enum: [1, 2]` widening above fixed an options-page false positive but opened a false negative in the far more common post/block context: an image/gallery field mistakenly set to `2` validated silently there, and translators lose per-language image swapping. `AcfLinter` is the only place with both `fields` and `location` in view at once, so the check lives there and the schemas stay context-neutral by design.

## [0.6.0] - 2026-07-16

### Fixed

- CPT `supports` is no longer a closed enum — ACF 6.8's CPT UI ships 12 stock checkboxes including `notes` and `post-formats` (both previously rejected) plus an "Add Custom" input whose values land in `supports` verbatim, so items are now validated as strings. Verified against the ACF 6.8.2 source. ([#18](https://github.com/parisek/acf-json-schema/issues/18))
- `acf-lint` no longer silently ignores unknown options — a typo like `--stric` now exits 1 with an error instead of degrading the `--strict` CI gate into an always-green no-op. A missing Composer autoloader also reports an actionable message instead of a raw PHP fatal. ([#13](https://github.com/parisek/acf-json-schema/issues/13))
- `acf-lint` no longer validates native (non-ACF) Gutenberg `block.json` files against the ACF block schema. Dispatch was purely filename-based, so a recursive scan over a theme with native blocks produced guaranteed false positives; a `block.json` without an `acf` key is now reported as skipped. ([#14](https://github.com/parisek/acf-json-schema/issues/14))
- `field-item.schema.json` discriminator branches now carry `required: ["type"]` in their `if`. `properties` alone passes vacuously on a missing key, so a field without `type` matched every branch and all 36 per-type refs were enforced at once — an avalanche of confusing errors instead of the single "required properties (type) are missing". ([#15](https://github.com/parisek/acf-json-schema/issues/15))
- `_meta.json` `generator_version` now reports the actually installed package version via Composer's runtime API instead of a hardcoded `0.1.0`. ([#16](https://github.com/parisek/acf-json-schema/issues/16))
- The schema generator and `acf-lint --fix` now share one JSON writer (`Json::encode()`) with canonical flags matching ACF's own local-JSON export style (4-space pretty print, unescaped slashes and unicode). Previously the two write paths disagreed on `JSON_UNESCAPED_UNICODE`, so a fixed file and a generated file escaped non-ASCII differently. Byte-neutral for the committed schemas (ASCII-only). ([#17](https://github.com/parisek/acf-json-schema/issues/17))

### Added

- `acf-lint --version` prints the installed package version; `--` terminates option parsing so paths starting with `-` can be linted. ([#13](https://github.com/parisek/acf-json-schema/issues/13))
- `acf-lint --format=<text|json|github>` — `json` emits one machine-readable document on stdout; `github` emits GitHub Actions `::error` workflow commands so findings annotate the PR diff. ANSI colors in the default text format are now auto-disabled when stderr is not a TTY or `NO_COLOR` is set. ([#19](https://github.com/parisek/acf-json-schema/issues/19))
- `acf-lint --max-errors=<N>` caps schema errors collected per file (new default 50, previously unbounded — a badly broken file could generate pathological error trees across the 36 discriminator branches). ([#19](https://github.com/parisek/acf-json-schema/issues/19))

## [0.5.0] - 2026-06-08

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
