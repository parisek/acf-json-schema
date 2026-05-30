# 0002. Keep `src/templates/refs/` and `schemas/refs/` as byte-identical copies

## Context

After [0001](0001-hand-curated-refs-over-runtime-generation.md), the curated
field-type refs are authored source. But consumers validate against `schemas/`
— that directory is the shipped, self-contained distribution that gets resolved
out of `vendor/parisek/acf-json-schema/schemas/`. So the refs need to exist in
two places: the source tree (`src/templates/refs/`) and the distribution
(`schemas/refs/`).

Options considered:

- **Symlink** `schemas/refs/` → `src/templates/refs/`. Symlinks don't survive
  Composer/zip distribution reliably and break on some consumer filesystems.
- **Build step** that copies source → dist on demand. Adds a mandatory generate
  step before the repo is usable and a way for the committed dist to lag source.
- **Plain duplication**, with a test enforcing equality.

## Decision

Duplicate. `src/templates/refs/` is canonical; `schemas/refs/` is a
byte-identical copy. Any edit to one must be mirrored to the other in the same
change. `SchemaConsistencyTest::test_template_refs_match_distribution_refs`
fails the instant they diverge.

## Consequences

- The distribution is fully static — no build step, no symlink, works wherever
  Composer puts it.
- Every ref edit is a two-file edit. Easy to forget, which is exactly why the
  consistency test exists and runs in plain CI (unlike `SnapshotTest`).
- The same class of "two things must stay in sync" applies to the discriminator:
  `SchemaEmitter::FIELD_TYPE_ORDER`, the set of `field-*.schema.json` refs, and
  the `type` enum in `field.schema.json` are all cross-checked by the same test.
