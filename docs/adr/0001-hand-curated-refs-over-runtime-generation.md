# 0001. Ship hand-curated field-type refs, not runtime-generated ones

## Context

The original design generated every field-type ref at runtime: `FieldExtractor`
merged ACF's PHP runtime defaults with enum choices parsed out of
`render_field_settings` via `nikic/php-parser` (`EnumChoicesExtractor`). The
generator was the source of truth; the committed schemas were just its output.

That coupling was fragile. The schema for a field type was only as good as what
ACF happened to expose at runtime plus what the AST walk could recover — and
both shift between ACF releases in ways that are silent and hard to diff. It also
made the schemas un-reviewable in isolation: you couldn't reason about a
constraint without re-running a live WP + ACF Pro boot.

## Decision

Pivot to **hand-curated static templates**. `src/templates/refs/field-*.schema.json`
is now the authored source of truth. `SchemaEmitter` assembles the root schema
and copies these refs verbatim; it does not synthesise field constraints.

The runtime extraction machinery (`FieldExtractor`, `EnumChoicesExtractor`) and
the generator stay — but as a **verification baseline**, not the source. The
generator reproduces the canonical output and `SnapshotTest` asserts byte
equality, so drift against a live ACF install is caught rather than absorbed.

## Consequences

- Schemas are readable, reviewable, and diffable without booting WordPress.
- Field constraints can encode real-world shape (from the fixture corpus) that
  ACF's runtime defaults don't reveal.
- The cost: curation is manual. Adding or upgrading a field type is a multi-file
  edit (see AGENTS.md, "Adding / upgrading a field type"), and the schemas can
  drift from ACF unless verified against a live install before release.
- `composer.json`'s description had to drop its "runtime-generated" claim — it
  was no longer true.
- `SnapshotTest` is the guard, but it only runs with `ACF_SCHEMA_TEST_WP_ROOT`
  set; in plain CI it skips, so the byte-equality check is a release-time gate,
  not an every-commit one.
