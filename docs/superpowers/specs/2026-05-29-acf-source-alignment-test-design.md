# ACF Source Alignment Test — Design

**Date:** 2026-05-29
**Status:** Approved (design), pending implementation plan
**Package:** `parisek/acf-json-schema`

## Goal

A targeted, runtime test that validates the package's hand-curated JSON Schemas against a **live ACF Pro install**, so that every schema edit — and every ACF version bump — is checked against what the ACF plugin actually defines and serialises. Catches the four drift classes that bit us during the fellows / keypers / perfectaparasols scans:

1. ACF adds a **new field type** the schema doesn't know about (e.g. `icon_picker` in 6.8).
2. ACF adds a **new property** to a field/CPT/taxonomy that `unevaluatedProperties: false` would then reject.
3. The schema constrains a **stale property** ACF has removed or renamed.
4. An ACF default's **type** is incompatible with the schema's declared type for that property.

## Guiding principle

**The ACF plugin is the source of truth.** The schema must match how ACF actually stores data — never a guess, never over-fitted to one downstream project. This test mechanises that principle: it asks the live plugin what it defines, and asserts the committed schemas are consistent with it.

## Scope decisions (from brainstorming)

| Decision | Choice |
| --- | --- |
| ACF access model | **Live WP+ACF runtime** — bootstraps WP like `SnapshotTest`, skipped without env |
| Checks performed | **All four**: new-field-type, coverage-gap, stale-constraint, type-consistency |
| Stale handling | **Allowlist** of known-intentional extras; hard-fail otherwise |
| Code structure | **Test-only** (no `src/Audit` class yet) — audit logic in a `tests/` helper |
| Targets | Field types **and** CPT/taxonomy root schemas |

## Architecture

### Environment gate

Reuse the `SnapshotTest` convention exactly:

- Env var `ACF_SCHEMA_TEST_WP_ROOT` points at a WP install whose `wp-load.php` boots ACF Pro.
- `setUp()` skips the whole test when the env var is unset → CI (which has no WP) skips it; it runs locally and on the pre-release / ACF-upgrade check.
- Bootstrap WP through the existing `Generator::bootstrapWordPress()` path (or a shared bootstrap helper extracted from it) so the buffering / fatal-forwarding behaviour is identical.

### Files

- **Create** `tests/AcfSourceAlignmentTest.php` — the PHPUnit test (data-provider per field type + CPT + taxonomy).
- **Create** `tests/helpers/AcfSourceIntrospector.php` — wraps the runtime queries: list field types, read a field type's `defaults`, read CPT/taxonomy default settings, resolve a schema's declared property-name set. Keeps the test body declarative.
- **Create** `tests/acf-source-allowlist.php` — returns a structured allowlist of known-intentional extras, keyed by schema. Plain PHP returning an array (no parsing, IDE-navigable, commentable).

### Key insight — one validation pass covers checks 2 + 4

Checks **coverage-gap** and **type-consistency** collapse into a single assertion by routing through the package's real validation path:

> For each field type, build a synthetic field object from ACF's `defaults` (plus the base-required keys `key`, `label`, `name`, `type`, `allow_in_bindings`), wrap it in a minimal valid field group, and validate against `acf.schema.json` using the existing `tests/helpers/Validator`.

- `unevaluatedProperties: false` rejects any default property the schema doesn't model → **coverage gap** caught.
- Each property's sub-schema rejects a default value of the wrong type → **type-consistency** caught.

This reuses the production validation behaviour instead of re-encoding type rules in the test — the test cannot drift from how real validation works.

## The four checks

### Check 1 — New field type

```
acf_types   = array_keys( acf_get_field_types() )
known_types = SchemaEmitter::fieldTypeOrder()
```

Assert `acf_types ⊆ known_types`, and that a `schemas/refs/field-<type>.schema.json` file exists for each. A new ACF field type (absent from `FIELD_TYPE_ORDER` or missing a ref) fails with a message naming the type and the add-a-type workflow.

### Check 2 + 4 — Coverage + type-consistency (combined)

For each field type:

1. `$defaults = acf_get_field_types()[<type>]->defaults`.
2. Drop ACF-internal keys (those beginning with `_`, e.g. `_name`, `_valid`) — they are never serialised to field JSON.
3. Build `field = array_merge( base_required_stub, $defaults )` where `base_required_stub = { key: 'field_x', label: 'x', name: 'x', type: <type>, allow_in_bindings: 0 }` (only filling base-required keys the defaults don't already provide).
4. Validate `{ …minimal group…, fields: [ field ] }` against `acf.schema.json`. The group wrapper must itself satisfy `acf.schema.json`'s root `required` (`key`, `title`, `fields`, `location`, `modified`, `active`, `acfml_field_group_mode`) with known-valid values, so the **only** thing under test is the synthetic field — a wrapper-level error would be a test bug, not a finding.
5. Assert valid. On failure, the validator's error pointers distinguish a coverage gap (`unevaluatedProperties`) from a type mismatch (a property sub-schema error).

### Check 3 — Stale constraint

For each `field-<type>.schema.json`:

```
schema_props = keys( ref.properties )
acf_props    = keys( $defaults )  ∪  keys( base field.schema.json .properties )
allowlisted  = allowlist['field-<type>']  (default [])
```

Assert every `schema_props` entry is in `acf_props ∪ allowlisted`. Anything else is a stale constraint and fails, naming the property and pointing at the allowlist file for a deliberate exception.

### CPT / taxonomy

`ACF_Post_Type` / `ACF_Taxonomy` have **no `$defaults` property** (this is why `CptExtractor` / `TaxonomyExtractor` are hand-curated). The runtime default set comes instead from ACF's validation helpers, which merge the full default set:

- `acf_get_instance('ACF_Post_Type')` and `acf_get_instance('ACF_Taxonomy')` for the instance.
- The default-merging accessor — candidate `acf_get_valid_post_type( [] )` / `acf_get_valid_taxonomy( [] )` (or the instance `get_settings_array()`), **confirmed during implementation**.

The same three checks (coverage+type, stale; new-type is field-only) run against `cpt.schema.json` / `taxonomy.schema.json`. If no clean full-default accessor exists at runtime, the CPT/taxonomy arm **degrades gracefully**: it validates a committed representative real-export fixture against the schema instead of a defaults-derived synthetic, and logs that it used the fallback. This keeps the test useful without blocking on an accessor that may not exist.

## Allowlist format

`tests/acf-source-allowlist.php`:

```php
<?php
// Properties the schema intentionally constrains that are NOT in ACF's runtime
// `defaults` — typically settings that only surface via render_field_settings,
// or deliberately-documented extras. Each entry must carry a reason comment so a
// future reader knows it is intentional, not forgotten drift.
return [
    'field-<type>' => [
        '<property>',   // why it is intentionally modelled despite being absent from defaults
    ],
    'cpt'      => [ /* … */ ],
    'taxonomy' => [ /* … */ ],
];
```

A stale-check exception requires a conscious edit here, so intentional divergence stays visible and reviewed.

## Failure model

| Check | On violation |
| --- | --- |
| New field type | **hard-fail** |
| Coverage gap | **hard-fail** |
| Type-consistency | **hard-fail** |
| Stale (not allowlisted) | **hard-fail** |
| No env set | **skip** (whole test) |
| CPT/tax accessor missing | **fallback** to fixture validation + notice |

## Testing strategy

- The test is itself the test; its own correctness is verified by running it against the live ACF the package already targets (ACF Pro 6.8.x) and confirming it passes on the current, ACF-faithful schemas.
- Negative self-check during implementation: temporarily remove a known property from one ref and confirm the stale check (or coverage) fires; temporarily narrow a type and confirm type-consistency fires. These are manual smoke checks, not committed tests.
- Runs in the same `composer test` suite; contributes 0 assertions in CI (skipped), N assertions locally with env set.

## Out of scope (YAGNI)

- A standalone `bin/acf-schema-audit` CLI / drift report — deferred; the test-only structure refactors into an `src/Audit` class cleanly if a CLI is later wanted.
- Enum-value drift derived from `render_field_settings` — the existing `EnumChoicesExtractor` already covers enum extraction; wiring it into this alignment test is a possible follow-up, not part of v1.
- Serialised-export type quirks (e.g. taxonomy bool→int) are **not** re-derived here — they are already hand-encoded in the schemas and guarded by the real-export fixture corpus (fellows/keypers/perfectaparasols). This test guards the *defaults/property/type* surface, not the export serialiser.

## Open items for the plan

1. Confirm the exact runtime accessor for CPT/taxonomy full defaults (`acf_get_valid_post_type` vs `get_settings_array` vs other); wire the graceful fallback if none is clean.
2. Decide whether to extract a shared `bootstrapWordPress()` helper from `Generator` or duplicate the minimal bootstrap in the test helper (DRY vs coupling).
3. Seed the initial allowlist by running the stale check once and recording the legitimate extras (e.g. select `create_options`/`save_options`, group `sub_fields` if absent from defaults, etc.).
