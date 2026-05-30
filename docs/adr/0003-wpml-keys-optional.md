# 0003. WPML/ACFML keys are optional, not required

## Context

The canonical schemas were curated against a live install that had WPML + ACFML
active, so the ACF exports carried translation keys — `acfml_field_group_mode`
on field groups, `wpml_cf_preferences` on fields, and similar. The naive schema
would mark these `required`.

But the package's whole use case is linting *any* project's ACF JSON in CI, and
most projects don't run WPML. A schema that requires ACFML keys would reject
every plain-ACF export — the common case — as invalid.

## Decision

Treat WPML/ACFML keys as **"required only when present."** They are not in any
root `required` list, but they keep their value constraints (`const`/`enum`)
when they do appear. ACF emits these keys only when ACFML/WPML is active, so:

- plain-ACF exports validate (keys absent → fine),
- WPML exports validate *and* still get their values checked.

The `acf-lint` CLI exposes an opt-in `--wpml` flag for projects that *want* to
enforce the keys' presence.

## Consequences

- One schema set serves both WPML and non-WPML projects; no separate "WPML
  profile" to maintain.
- Provenance matters: the README and `_meta.json` record the exact ACF / WPML /
  ACFML versions the schemas were curated against, because "optional" is only
  safe if you know which version's key shapes you modelled.
- A project that genuinely requires WPML keys gets no enforcement by default —
  it must pass `--wpml`. That trade-off favours the common case over the
  WPML-everywhere case.
