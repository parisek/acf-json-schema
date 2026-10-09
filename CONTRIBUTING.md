# Contributing

Thank you for your interest. This file is a short summary. [AGENTS.md](AGENTS.md) holds the full rules, and AI coding agents must read it first.

## Set up

```bash
composer install
```

PHP 8.3 or newer is required.

## Checks

Run this before every push:

```bash
composer check   # phpunit, phpstan (level 8) and the ADR index check
```

CI also runs these in a `composer` job:

```bash
composer validate --strict
composer audit --abandoned=report
composer normalize --dry-run
```

One `SnapshotTest` is skipped without a live WordPress install. This is expected.

## Schemas

- `src/templates/refs/` is the source of truth. `schemas/refs/` must stay byte-identical to it.
- Read "Schema source-of-truth rules" in [AGENTS.md](AGENTS.md) before you change a schema or add a field type.

## Commits and pull requests

- Use [Conventional Commits](https://www.conventionalcommits.org/) for commit messages and PR titles.
- A behavior-affecting PR adds an entry under `## [Unreleased]` in `CHANGELOG.md`. Do not write a version heading. The release workflow does that (see [RELEASING.md](RELEASING.md)).
- The maintainer squash-merges. The merge commit title ends with the PR number, for example `feat(lint): add a check (#46)`.

## Decisions

Record a significant decision as an ADR in `docs/adr/`. Add it to the index in `docs/adr/README.md`. `composer adr` checks the index. See the ADR section in [AGENTS.md](AGENTS.md).

## Security

Do not report a vulnerability in a public issue. Follow [SECURITY.md](SECURITY.md).
