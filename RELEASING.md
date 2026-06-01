# Release flow for parisek/acf-json-schema

## Prerequisites

- Access to the `parisek/acf-json-schema` GitHub repository
- Packagist maintainer role (or submit PR to the package page)
- A local DDEV environment with ACF Pro 6.8.x active (for schema verification)

## Steps

### 1. Verify schemas against a live WP install

```bash
ddev exec "php vendor/bin/acf-schema-gen --wp-root /var/www/html --output /tmp/acf-schemas-out/"
diff -r /tmp/acf-schemas-out/ vendor/parisek/acf-json-schema/schemas/
```

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
