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

### 3. Update CHANGELOG.md

Add a new `## [x.y.z] — YYYY-MM-DD` heading above the previous release. Follow the [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format — `### Added`, `### Changed`, `### Fixed`, `### Removed`.

### 4. Commit

```bash
git add CHANGELOG.md  # plus any schema/src changes
git commit -m "chore(release): vX.Y.Z"
```

### 5. Tag

```bash
git tag -a vX.Y.Z -m "Release vX.Y.Z"
```

### 6. Push

```bash
git push origin main --follow-tags
```

### 7. GitHub release

Create a GitHub release from the tag. Paste the relevant CHANGELOG section as the release body.

### 8. Packagist

Packagist auto-updates via the GitHub webhook. Verify the new version appears at `https://packagist.org/packages/parisek/acf-json-schema` within a few minutes.

If the webhook isn't configured: go to `https://packagist.org/packages/parisek/acf-json-schema` and click "Force Update".
