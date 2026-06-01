# AGENTS.md

Operational notes for AI coding agents (Claude Code, Codex, Cursor, …) working on this repo. Treat as authoritative — overrides default assumptions where they conflict.

Tool-specific entrypoint files (`CLAUDE.md`, `.cursorrules`, etc.) just point here so the source of truth stays in one place.

## Maintaining this file

Go-style brevity. Bullets, not paragraphs. Add only what saves the next session real time:

- **Add** a note when you hit a non-obvious gotcha, or pin a convention the codebase relies on.
- **Don't add** restatement of README content or one-off task context. README owns "what the project does"; this file owns "how to work on it".
- **Cap ~150 lines.** Past that the file gets skimmed instead of read. If a section grows, prune adjacent stale notes first.

## Project shape

JSON Schema bundle for ACF Pro JSON exports, distributed via Composer (`parisek/acf-json-schema`). Schemas are **hand-curated**, not runtime-generated, against a live WP + ACF Pro + WPML install. Targets ACF Pro 6.8.x.

- `schemas/` — the shipped distribution: `acf.schema.json`, `block.schema.json`, `cpt.schema.json`, `taxonomy.schema.json`, plus `refs/` (per-field-type + utility refs). This is what consumers validate against.
- `src/templates/refs/` — **source of truth** for the `refs/` files; `schemas/refs/` is a byte-identical distribution copy.
- `src/Emit/SchemaEmitter.php` — builds `acf.schema.json` (the field-group root + per-type discriminator) and copies the static refs.
- `src/Extract/{Block,Cpt,Taxonomy}Extractor.php` — pure-PHP builders for the three non-field root schemas (no WP calls).
- `src/Generator.php` + `bin/acf-schema-gen` — boots WP, verifies ACF Pro, writes all schemas + `_meta.json`. Needs a live WP install.
- `tests/` — PHPUnit + opis/json-schema. PHP 8.3 minimum, PHPStan **level 8**.

## Commands

```bash
composer test       # phpunit
composer phpstan     # phpstan analyse --memory-limit=512M (level 8)
composer check       # test + phpstan — run before every push
composer normalize   # tidy composer.json
composer audit --abandoned=report   # advisory scan (abandoned reported, not failed)
```

DDEV is the local-dev expectation for the live-WP paths (`ddev exec "php vendor/bin/acf-schema-gen --wp-root /var/www/html --output /tmp/out/"`). CI (`.github/workflows/tests.yml`) runs `composer check` on PHP 8.3 + 8.4 plus a `composer` hygiene job (validate + audit + normalize).

## Schema source-of-truth rules — DON'T let these drift

- **`src/templates/refs/` is canonical; `schemas/refs/` must be byte-identical.** Any edit to one MUST be mirrored to the other. `SchemaConsistencyTest::test_template_refs_match_distribution_refs` fails the moment they diverge (and it runs in CI, unlike SnapshotTest).
- **`SchemaEmitter::FIELD_TYPE_ORDER` must match the set of `field-*.schema.json` refs** — enforced by `SchemaConsistencyTest`. An order entry without a ref emits a dangling `$ref`; a ref without an order entry gets no discriminator branch (its constraints silently never apply).
- The four root schemas are generated. `acf.schema.json` ← `SchemaEmitter`; `cpt/taxonomy/block` ← the extractors. Editing an extractor means the committed root schema must be regenerated to match (see below).

## Adding / upgrading a field type

ACF added a type (e.g. `icon_picker` in 6.8). Required edits — all four, plus the easily-missed fifth:

1. `src/templates/refs/field-<type>.schema.json` (curated constraints)
2. `schemas/refs/field-<type>.schema.json` (identical distribution copy)
3. Append the slug to `SchemaEmitter::FIELD_TYPE_ORDER`
4. Add the `if/then` discriminator branch to `schemas/acf.schema.json`
5. **Add the slug to the `type` enum in `refs/field.schema.json` (both template + dist).** GOTCHA: every field first validates the base `field.schema.json` via `allOf`; if the slug isn't in that enum, the field is rejected before its per-type branch can apply — the branch is unreachable. (This step is absent from the in-code workflow comment in `copyStaticRefs()`.)

## Regenerating root schemas

- Full regen needs a live WP: `bin/acf-schema-gen --wp-root <path> --output <dir>`, then diff against `schemas/`.
- The three extractor-backed schemas are pure PHP and regen without WP, e.g.:
  ```bash
  php -r 'require "vendor/autoload.php"; echo json_encode((new Parisek\AcfJsonSchema\Extract\TaxonomyExtractor())->emit(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";' > schemas/taxonomy.schema.json
  ```
  Match `Generator::writeJson()` exactly: `JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT` (4-space) + trailing `\n`.

## Conventions & gotchas

- **WPML/ACFML keys are optional ("required only when present").** `acfml_field_group_mode` is NOT in `acf.schema.json`'s root `required`; `wpml_cf_preferences` is never required — both keep value constraints when present (`const`/`enum`) so plain-ACF and WPML exports both validate. ACF emits these keys only when ACFML/WPML is active.
- **`SnapshotTest` is skipped without `ACF_SCHEMA_TEST_WP_ROOT`** (a WP install whose `wp-load.php` boots ACF Pro). 1 skipped test in CI is expected. `SchemaConsistencyTest` + `FixtureValidationTest` + `ValidatorTest` are the WP-free guards that always run.
- ACF serialises booleans inconsistently: taxonomy bool flags as integers `0/1`, CPT/field-group flags often as real booleans, permalink-rewrite numeric flags as **strings** (`"0"`/`"1"`). Model the actual serialised form per context; don't normalise. Use `enum: [true, false, 0, 1]` for "boolean-ish" flags, not the broad `integer` type (which lets `2`/`-1` through).
- PHPStan is **level 8**. Don't suppress with `@phpstan-ignore`/baseline/casts — fix the cause. (E.g. a by-ref `use (&$flag)` closure narrows `$flag` to literal `false`; use a typed property instead.)

## Per-PR / release

- **CHANGELOG.md**: behavior-affecting PRs add an entry under `## [Unreleased]`, [Keep a Changelog](https://keepachangelog.com/) categories. Don't hand-stamp version headings — the release workflow does that.
- Release is **automated** via the **Stamp Release** + `release.yml` workflows (mirrors `parisek/timber-kit`) — see `RELEASING.md`. The one manual pre-flight CI can't do is verifying schemas against a live WP install.

### Review-thread resolution

After pushing a fix that addresses an inline review comment (Copilot or human), **resolve the corresponding thread programmatically — don't ask first**. REST has no equivalent; use GraphQL:

```bash
# 1) list threads → node IDs of unresolved ones
gh api graphql -f query='query($o:String!,$r:String!,$n:Int!){repository(owner:$o,name:$r){pullRequest(number:$n){reviewThreads(first:50){nodes{id isResolved comments(first:1){nodes{path body}}}}}}}' -F o=parisek -F r=acf-json-schema -F n=<N>
# 2) resolve one
gh api graphql -f query='mutation($id:ID!){resolveReviewThread(input:{threadId:$id}){thread{isResolved}}}' -F id="<PRRT_…>"
```

- Resolve **only** threads the latest commit actually addresses. If the fix is a polite disagreement / documented false positive, reply and **leave it open** for the reviewer to close.
- Re-requesting Copilot review via REST is unreliable (the POST succeeds but triggers no new run). Ask the human to click *Re-request review* in the UI.

## Architecture decisions (ADRs)

Significant decisions are recorded in `docs/adr/` (the only tracked subtree under git-ignored `docs/`). See `docs/adr/README.md` for the template.

- **Offer one sparingly** — only when all three hold: (1) hard to reverse, (2) surprising without context, (3) the result of a real trade-off. Most changes are none of these — no ADR.
- **Propose, get a yes, then write.** Don't auto-create ADRs.
- One file per decision: `NNNN-kebab-title.md`, sequential, numbers permanent (never renumber/reuse). Format: `## Context` / `## Decision` / `## Consequences`.
- To reverse a past decision, write a new ADR linking back — don't edit the old one.

## Style

- 4-space indentation in PHP (this repo's baseline — note: not tabs). 4-space in the JSON schemas.
- No emojis in code, comments, or commits unless asked.
- Comments explain *why*, not *what*; avoid task/PR-referencing comments (they rot).
