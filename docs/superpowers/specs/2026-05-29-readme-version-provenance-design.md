# README Version Provenance + WPML-Optional Keys — Design

**Date:** 2026-05-29
**Status:** Approved (design), pending implementation
**Package:** `parisek/acf-json-schema`

## Goal

Two related WPML-provenance changes:

1. **Record** the exact ACF + WPML + ACFML versions the canonical schemas were
   curated and snapshot-tested against, in a human-readable place — so a reader
   can tell at a glance which live environment the committed schemas reflect.
2. **Loosen** the schemas so WPML/ACFML-only keys are required *only when
   present* (i.e. when the plugin that emits them is active), letting non-WPML
   ACF exports validate too — while still fully validating those keys' values
   when an export does include them.

## Why

The bundled schemas hard-encode WPML/ACFML assumptions:

- `acf.schema.json` makes `acfml_field_group_mode` **required** with `const: "advanced"`.
- `refs/field.schema.json` constrains `wpml_cf_preferences` to `enum: [0, 1, 2, 3]`.

So "which WPML/ACFML version was this built against" is genuine provenance, not
trivia. README already states a **Target** range (`ACF Pro 6.8.x`) — that is the
*supported range* (intent). This adds a distinct **Generated against** line — the
*concrete snapshot* (provenance). The two are kept separate on purpose.

## Scope decisions (from brainstorming)

| Decision | Choice |
| --- | --- |
| Where recorded | **README** — human-readable line, no machine metadata |
| Granularity | **ACF + WPML core + ACFML** (three numbers) |
| Source of numbers | Read from the `fellows` project plugin headers (live source of truth) |
| WPML keys | **Required only when present** — drop hard requirement, keep value constraints |

## The versions (read from `~/Sites/wordpress/fellows/wp-content/plugins/`)

| Component | Plugin | Version |
| --- | --- | --- |
| ACF Pro | `advanced-custom-fields-pro` (`ACF_VERSION`) | **6.8.2** |
| WPML core | `sitepress-multilingual-cms` (`ICL_SITEPRESS_VERSION`) | **4.9.4** |
| ACFML | `acfml` (`ACFML_VERSION`) | **2.2.4** |

## Changes

### 1. Add a provenance line (`README.md`, under the existing `**Target:**` line)

```
**Generated against:** ACF Pro 6.8.2 · WPML 4.9.4 · ACFML 2.2.4 — the live install the canonical schemas were curated and snapshot-tested against.
```

### 2. Accuracy / reframe (`README.md`, "What this is" paragraph)

The line currently reads "hand-curated against a live **WP+ACF Pro** install",
which omits WPML. Reframe so it states the curation environment *and* that
non-WPML exports are accepted:

> "...hand-curated against a live **WP + ACF Pro + WPML** install; WPML/ACFML
> keys are optional, so plain ACF (non-WPML) exports validate too..."

## The WPML-optional schema change

### JSON Schema reality

JSON Schema validates a static JSON file and has no knowledge of which WP plugins
are active. "Required only when the plugin is active" is therefore not a runtime
condition. But ACF writes these keys into the export **iff** the emitting plugin
is active, and omits them otherwise. So the faithful encoding is:

> **Optional, but value-constrained when present.**

A non-WPML export omits the key → optional means it validates. A WPML export
includes the key → its value constraint still applies.

### Current state

| Key | Location | Now |
| --- | --- | --- |
| `acfml_field_group_mode` | `acf.schema.json` root | **hard-required** (in `required`) + `const: "advanced"` in `properties` |
| `wpml_cf_preferences` | `field.schema.json`, `field-image`, `field-gallery` | **already optional** (not in any `required`); value-constrained (`enum [0,1,2,3]`; `const 1` on image/gallery) |

### Changes

1. **`src/Emit/SchemaEmitter.php`** (line ~66) — remove `'acfml_field_group_mode'`
   from the root `required` array.
2. **`schemas/acf.schema.json`** (line ~13) — same removal, so the committed
   schema stays byte-equal to the emitter output (snapshot test).
3. **Keep** `acfml_field_group_mode`'s `const: "advanced"` in `properties` — WPML
   exports stay fully validated; non-WPML exports (key absent) now pass.
4. **`wpml_cf_preferences`** — no change. Already optional; value constraints
   (`enum`/`const 1`) are retained ("optional" ≠ "unchecked when present").

### Why this is safe

- All committed valid field-group fixtures include `acfml_field_group_mode`, so
  they still validate.
- No test asserts that a group *missing* `acfml_field_group_mode` is rejected
  (`ValidatorTest` only builds groups *with* it), so loosening `required` breaks
  nothing.

## Out of scope (YAGNI)

- No change to `Generator::writeMetaSidecar()` or `_meta.json` (README was chosen, not a committed machine sidecar).
- No `composer.json` `extra` field for WPML.
- No cross-check wired into the ACF source-alignment test.
- No relaxing of WPML key *values* — only the presence requirement is dropped.
- The `6.8.x` **Target** range and badge stay as a range — correct as a support statement; the new line carries the exact `6.8.2`.

## Testing strategy

- README change: re-read and confirm numbers match `fellows` plugin headers.
- Schema change: run the suite (`composer test`) — existing valid fixtures must
  still pass; invalid fixtures are unaffected. Optional manual smoke check:
  validate a synthetic field group with `acfml_field_group_mode` removed and
  confirm it now passes, and one with `acfml_field_group_mode: "standard"` and
  confirm it still fails (const guard intact).
