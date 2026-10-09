# Release flow for parisek/acf-json-schema

## Prerequisites

- Access to the `parisek/acf-json-schema` GitHub repository
- Packagist maintainer role (or submit PR to the package page)
- A local DDEV environment with ACF Pro 6.8.x active (for schema verification)

## Steps

### 1. Verify schemas against a live WP install

Run the generator from a checkout of the release branch (with `composer install` done). The checkout must be visible inside the DDEV container. Run the commands below in a shell inside the container (`ddev ssh`), from the checkout.

`acf-schema-gen` boots WordPress. If the site's theme requires this package, the theme's Composer autoloader registers first and loads the **installed** copy of the classes. The generator then copies the installed refs, not the refs under release. A preload file loads the checkout's classes before WordPress boots, so they win:

```bash
cat > /tmp/acf-preload.php <<'PHP'
<?php
require getcwd() . '/vendor/autoload.php';
foreach (['Generator', 'Json', 'Emit\SchemaEmitter', 'Extract\BlockExtractor', 'Extract\CptExtractor', 'Extract\TaxonomyExtractor'] as $class) {
    class_exists("Parisek\\AcfJsonSchema\\$class");
}
PHP

php -d auto_prepend_file=/tmp/acf-preload.php bin/acf-schema-gen --wp-root /var/www/html --output /tmp/acf-schemas-out/
diff -r -x _meta.json -x .gitkeep /tmp/acf-schemas-out/ schemas/
diff -r /tmp/acf-schemas-out/refs/ src/templates/refs/
```

- The first `diff` compares the output with the `schemas/` of the checkout, **not** with `vendor/parisek/acf-json-schema/schemas/`. A package checkout has no such path. In a theme, that path is the installed copy.
- The second `diff` must print nothing. `copyStaticRefs()` copies the package's own templates verbatim, so the refs are the same on both sides by design. A difference here means the installed copy ran, not the checkout. Stop and fix the setup.
- This step can catch ACF drift only in the generated files: the extractor-backed roots (`block`, `cpt`, `taxonomy`), the `acf.schema.json` and `field-item.schema.json` composition, and the `verifyAcfPro()` check. It does not check hand-curated refs against ACF. `SchemaConsistencyTest` only keeps `schemas/refs/` identical to the templates.
- `_meta.json` is not tracked, so `-x _meta.json` hides the expected extra file.

If `diff` shows changes, review them. If they represent intentional ACF version drift, update the hand-curated refs and commit before tagging.

### 2. Run the full test suite

```bash
composer check
```

All tests must pass (1 skip for `SnapshotTest` is expected without `ACF_SCHEMA_TEST_WP_ROOT`).

PHPStan must report `[OK] No errors`.

### 3. Make sure changes sit under `[Unreleased]`

Behaviour-affecting changes belong under `## [Unreleased]` in `CHANGELOG.md` (Keep a Changelog: `### Added`, `### Changed`, `### Fixed`, `### Removed`) — normally added by their own PR. **Don't hand-stamp a version heading** — the workflow does that.

### 4. Trigger the Stamp Release workflow

Actions tab → **Stamp Release** → Run workflow → enter `X.Y.Z` (no `v` prefix).

It validates the version, requires a non-empty `[Unreleased]`, runs `composer test` + `composer phpstan` as guards, stamps `[Unreleased]` → `[X.Y.Z] - DATE`, commits `Release X.Y.Z`, tags `vX.Y.Z`, pushes, and dispatches `release.yml` — which builds the GitHub Release from the tag's CHANGELOG section + merged PRs.

> Schema verification (step 1) is the one thing CI can't run (no live WP env), so keep doing it locally before you trigger the release.

### 5. Packagist

Packagist auto-updates via the GitHub webhook. Verify the new version appears at `https://packagist.org/packages/parisek/acf-json-schema` within a few minutes.

If the webhook isn't configured: go to `https://packagist.org/packages/parisek/acf-json-schema` and click "Force Update".
