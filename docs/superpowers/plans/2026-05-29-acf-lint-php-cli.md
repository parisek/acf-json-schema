# ACF-JSON PHP Linter (`bin/acf-lint`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a PHP/Composer CLI to `parisek/acf-json-schema` that validates a project's ACF/CPT/taxonomy/block JSON against the bundled schemas, so consumers (e.g. the `starter_theme` in wordpress-base) lint via Composer/PHP instead of a duplicated Node/ajv toolchain.

**Architecture:** A reusable `AcfLinter` class (opis/json-schema) does schema-dispatch + validation + optional `modified`-timestamp auto-fix; a thin `bin/acf-lint` wraps it for CLI use. `opis/json-schema` moves from `require-dev` to `require` so it ships at runtime. Consumers call `vendor/bin/acf-lint`. The Node ACF-JSON linter is then removed from both wordpress-base and tailwind-base. Logic is ported 1:1 from the existing `lint.mjs` (dispatch rules, `--fix`, `--strict`).

**Tech Stack:** PHP 8.3, opis/json-schema ^2.3, PHPUnit, PHPStan level 8.

---

## File structure

Package `parisek/acf-json-schema`:
- Create `src/Lint/AcfLinter.php` — core: opis resolver, `dispatch()`, `validateFile()`, `needsModifiedBump()`, `collectJsonFiles()`.
- Create `src/Lint/FileLintResult.php` — readonly result value (path, kind, valid, errors, fixed, skipped).
- Create `bin/acf-lint` — arg parsing (`--strict`, `--fix`, paths), runs `AcfLinter`, prints report, exit code.
- Create `tests/Lint/AcfLinterTest.php` — dispatch / validation / fix unit + corpus tests.
- Modify `composer.json` — move opis to `require`; add `bin/acf-lint` to `bin`.
- Create `.gitattributes` — `export-ignore` non-dist files (resolves the "docs/tests not relevant in the package" cleanup).
- Modify `README.md`, `CHANGELOG.md`.

Consumer `wordpress-base` (`wp-content/themes/starter_theme`):
- Modify `composer.json` scripts; delete `tests/json-schemas/` + `static/tests/json-schemas/` ACF schema files; update `static/package.json`.

Consumer `tailwind-base`:
- Delete `static/tests/json-schemas/`; remove `lint:json*` from `static/package.json`.

---

## PHASE 1 — Package: PHP linter

### Task 1: Promote opis to runtime + register the CLI bin

**Files:**
- Modify: `composer.json`

- [ ] **Step 1: Move `opis/json-schema` from `require-dev` to `require`, add the bin**

Edit `composer.json` so the relevant blocks read:

```json
    "require": {
        "php": "^8.3",
        "nikic/php-parser": "^5.0",
        "opis/json-schema": "^2.3"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0 || ^12.0",
        "phpstan/phpstan": "^2.1",
        "php-stubs/acf-pro-stubs": "^6.5"
    },
```

and:

```json
    "bin": ["bin/acf-schema-gen", "bin/acf-lint"],
```

- [ ] **Step 2: Refresh the lockfile / autoloader**

Run: `composer update opis/json-schema --no-interaction`
Expected: opis listed under "Package operations" moving to require; `composer validate` clean.

- [ ] **Step 3: Commit**

```bash
git add composer.json composer.lock
git commit -m "build: promote opis/json-schema to a runtime dependency + register bin/acf-lint"
```

---

### Task 2: `AcfLinter::dispatch()` — pick the schema for a file (TDD)

**Files:**
- Create: `src/Lint/AcfLinter.php`
- Test: `tests/Lint/AcfLinterTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Tests\Lint;

use Parisek\AcfJsonSchema\Lint\AcfLinter;
use PHPUnit\Framework\TestCase;

final class AcfLinterTest extends TestCase {

    private AcfLinter $linter;

    protected function setUp(): void {
        $this->linter = new AcfLinter(__DIR__ . '/../../schemas');
    }

    private const BASE = 'https://schemas.parisek.dev/acf/';

    public function test_dispatch_block_by_filename(): void {
        self::assertSame(self::BASE . 'block.schema.json', $this->linter->dispatch('a/block.json', (object) []));
    }

    public function test_dispatch_acf_by_filename(): void {
        self::assertSame(self::BASE . 'acf.schema.json', $this->linter->dispatch('a/acf.json', (object) []));
    }

    public function test_dispatch_cpt_by_post_type(): void {
        self::assertSame(self::BASE . 'cpt.schema.json', $this->linter->dispatch('x/foo.json', (object) ['post_type' => 'event']));
    }

    public function test_dispatch_taxonomy_by_taxonomy_and_object_type(): void {
        $json = (object) ['taxonomy' => 'genre', 'object_type' => ['post']];
        self::assertSame(self::BASE . 'taxonomy.schema.json', $this->linter->dispatch('x/foo.json', $json));
    }

    public function test_dispatch_acf_by_shape(): void {
        $json = (object) ['fields' => [], 'location' => []];
        self::assertSame(self::BASE . 'acf.schema.json', $this->linter->dispatch('x/options.json', $json));
    }

    public function test_dispatch_unrecognized_returns_null(): void {
        self::assertNull($this->linter->dispatch('x/random.json', (object) ['foo' => 'bar']));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Lint/AcfLinterTest.php -v`
Expected: FAIL — `Class "Parisek\AcfJsonSchema\Lint\AcfLinter" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `src/Lint/AcfLinter.php`:

```php
<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Lint;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\Validator as OpisValidator;

/**
 * Validates ACF / CPT / taxonomy / block JSON against the bundled schemas.
 *
 * Dispatch + auto-fix rules are ported from the historical Node `lint.mjs`
 * so behaviour is identical across the PHP and (now-retired) JS runners.
 */
final class AcfLinter {

    public const SCHEMA_BASE = 'https://schemas.parisek.dev/acf/';

    private OpisValidator $opis;

    public function __construct(string $schemasRoot) {
        $resolver = new SchemaResolver();
        // Lazy-resolves every $ref (incl. per-type field refs) from disk.
        $resolver->registerPrefix(self::SCHEMA_BASE, rtrim($schemasRoot, '/'));

        $this->opis = new OpisValidator();
        $this->opis->setMaxErrors(PHP_INT_MAX);
        $this->opis->setResolver($resolver);
    }

    /**
     * Returns the schema $id that validates $json, or null if the file shape
     * is unrecognized (skip it). Mirrors lint.mjs `dispatch()`.
     */
    public function dispatch(string $filename, object $json): ?string {
        $base = basename($filename);
        if ($base === 'block.json') {
            return self::SCHEMA_BASE . 'block.schema.json';
        }
        if ($base === 'acf.json') {
            return self::SCHEMA_BASE . 'acf.schema.json';
        }
        if (is_string($json->post_type ?? null) && !isset($json->taxonomy)) {
            return self::SCHEMA_BASE . 'cpt.schema.json';
        }
        if (is_string($json->taxonomy ?? null) && is_array($json->object_type ?? null)) {
            return self::SCHEMA_BASE . 'taxonomy.schema.json';
        }
        if (is_array($json->fields ?? null) && is_array($json->location ?? null)) {
            return self::SCHEMA_BASE . 'acf.schema.json';
        }
        return null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Lint/AcfLinterTest.php -v`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Lint/AcfLinter.php tests/Lint/AcfLinterTest.php
git commit -m "feat(lint): AcfLinter::dispatch — schema selection per file shape"
```

---

### Task 3: `AcfLinter::lintFile()` + `FileLintResult` — validate one file (TDD)

**Files:**
- Create: `src/Lint/FileLintResult.php`
- Modify: `src/Lint/AcfLinter.php`
- Test: `tests/Lint/AcfLinterTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `AcfLinterTest.php`:

```php
    public function test_lintfile_valid_acf_fixture_passes(): void {
        $path = __DIR__ . '/../fixtures/valid/fellows/component-apartment-list/acf.json';
        $result = $this->linter->lintFile($path, false);
        self::assertSame('acf', $result->kind);
        self::assertTrue($result->valid, json_encode($result->errors));
        self::assertFalse($result->skipped);
    }

    public function test_lintfile_unrecognized_is_skipped(): void {
        $tmp = sys_get_temp_dir() . '/acf-lint-skip-' . getmypid() . '.json';
        file_put_contents($tmp, '{"foo":"bar"}');
        try {
            $result = $this->linter->lintFile($tmp, false);
            self::assertTrue($result->skipped);
            self::assertNull($result->kind);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_lintfile_invalid_acf_reports_errors(): void {
        $tmp = sys_get_temp_dir() . '/acf-lint-bad-' . getmypid() . '.json';
        // acf.json by filename, but missing required keys → invalid.
        file_put_contents($tmp . '.dir.acf.json', '{}');
        $bad = sys_get_temp_dir() . '/acf-lint-bad-acf-' . getmypid();
        @mkdir($bad);
        $file = $bad . '/acf.json';
        file_put_contents($file, '{"key":"group_x"}');
        try {
            $result = $this->linter->lintFile($file, false);
            self::assertSame('acf', $result->kind);
            self::assertFalse($result->valid);
            self::assertNotEmpty($result->errors);
        } finally {
            @unlink($file);
            @rmdir($bad);
            @unlink($tmp . '.dir.acf.json');
        }
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Lint/AcfLinterTest.php -v`
Expected: FAIL — `lintFile` / `FileLintResult` undefined.

- [ ] **Step 3: Implement**

Create `src/Lint/FileLintResult.php`:

```php
<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Lint;

final class FileLintResult {

    /** @param array<int, array<string, mixed>> $errors */
    public function __construct(
        public readonly string $path,
        public readonly ?string $kind,
        public readonly bool $valid,
        public readonly array $errors,
        public readonly bool $fixed,
        public readonly bool $skipped,
    ) {}

    /** Short kind label (e.g. "acf") derived from the schema $id, or null. */
    public static function kindFromSchemaId(?string $schemaId): ?string {
        if ($schemaId === null) {
            return null;
        }
        $base = basename($schemaId);                 // e.g. acf.schema.json
        return str_replace('.schema.json', '', $base);
    }
}
```

Add to `AcfLinter` (new methods + the ErrorFormatter import is already present):

```php
    /**
     * Validate a single JSON file. Read failures / invalid JSON return a
     * skipped=false, valid=false result with a synthetic error so the caller
     * still surfaces them. Unrecognized shapes return skipped=true.
     */
    public function lintFile(string $path, bool $fix): FileLintResult {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return new FileLintResult($path, null, false, [['error' => 'could not read file']], false, false);
        }

        try {
            $json = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return new FileLintResult($path, null, false, [['error' => 'invalid JSON: ' . $e->getMessage()]], false, false);
        }
        if (!$json instanceof \stdClass) {
            return new FileLintResult($path, null, false, false ? [] : [], false, true);
        }

        $schemaId = $this->dispatch($path, $json);
        if ($schemaId === null) {
            return new FileLintResult($path, null, false, [], false, true);
        }
        $kind = FileLintResult::kindFromSchemaId($schemaId);

        $fixed = false;
        if ($fix && $this->needsModifiedBump($json)) {
            $json->modified = time();
            $encoded = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            file_put_contents($path, $encoded);
            $fixed = true;
        }

        $result = $this->opis->validate($json, $schemaId);
        $errors = [];
        if (!$result->isValid()) {
            $error = $result->error();
            if ($error !== null) {
                $errors = (new ErrorFormatter())->format($error, false);
            }
        }

        return new FileLintResult($path, $kind, $result->isValid(), $errors, $fixed, false);
    }

    /** Mirrors lint.mjs `needsModifiedBump()`. */
    public function needsModifiedBump(object $json): bool {
        if (!isset($json->fields) && !isset($json->post_type) && !isset($json->taxonomy)) {
            return false; // block.json has no `modified`
        }
        $m = $json->modified ?? null;
        if (!is_int($m)) {
            return true;
        }
        return $m < 1577836800; // pre-2020-01-01
    }
```

NOTE: `ErrorFormatter::format()` returns `array<string,mixed>` keyed by pointer; the `FileLintResult::$errors` docblock is `array<int, array<string,mixed>>` for the synthetic-error path. Relax the docblock to `array<int|string, mixed>` to satisfy PHPStan level 8.

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Lint/AcfLinterTest.php -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Lint/AcfLinter.php src/Lint/FileLintResult.php tests/Lint/AcfLinterTest.php
git commit -m "feat(lint): validate a single file, structured FileLintResult"
```

---

### Task 4: `--fix` bumps `modified` (TDD)

**Files:**
- Modify: `tests/Lint/AcfLinterTest.php`

- [ ] **Step 1: Write the failing test**

```php
    public function test_fix_bumps_stale_modified(): void {
        $dir = sys_get_temp_dir() . '/acf-lint-fix-' . getmypid();
        @mkdir($dir);
        $file = $dir . '/foo.json';
        // CPT shape (post_type) with a pre-2020 modified → should be bumped.
        file_put_contents($file, json_encode([
            'key' => 'post_type_x', 'title' => 'X', 'post_type' => 'x',
            'modified' => 0,
        ]));
        try {
            $before = time();
            $result = $this->linter->lintFile($file, true);
            self::assertTrue($result->fixed);
            $after = json_decode((string) file_get_contents($file));
            self::assertIsInt($after->modified);
            self::assertGreaterThanOrEqual($before, $after->modified);
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }
```

- [ ] **Step 2: Run to verify it fails or passes**

Run: `vendor/bin/phpunit tests/Lint/AcfLinterTest.php::test_fix_bumps_stale_modified -v`
Expected: PASS (logic already implemented in Task 3 — this test pins the behaviour). If it fails, fix `needsModifiedBump`/`lintFile` until green.

- [ ] **Step 3: Commit**

```bash
git add tests/Lint/AcfLinterTest.php
git commit -m "test(lint): pin --fix modified-bump behaviour"
```

---

### Task 5: `collectJsonFiles()` + corpus integration test (TDD)

**Files:**
- Modify: `src/Lint/AcfLinter.php`
- Modify: `tests/Lint/AcfLinterTest.php`

- [ ] **Step 1: Write the failing test**

```php
    public function test_collect_json_files_walks_dirs_and_ignores_vendor(): void {
        $root = __DIR__ . '/../fixtures/valid/fellows';
        $files = $this->linter->collectJsonFiles([$root]);
        self::assertNotEmpty($files);
        foreach ($files as $f) {
            self::assertStringEndsWith('.json', $f);
            self::assertStringNotContainsString('/vendor/', $f);
            self::assertStringNotContainsString('/node_modules/', $f);
        }
    }

    public function test_whole_valid_corpus_lints_clean(): void {
        $root = __DIR__ . '/../fixtures/valid';
        $files = $this->linter->collectJsonFiles([$root]);
        $failures = [];
        foreach ($files as $f) {
            $r = $this->linter->lintFile($f, false);
            if (!$r->skipped && !$r->valid) {
                $failures[] = $r->path . ' → ' . json_encode($r->errors);
            }
        }
        self::assertSame([], $failures, "Valid corpus must lint clean:\n" . implode("\n", $failures));
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Lint/AcfLinterTest.php -v`
Expected: FAIL — `collectJsonFiles` undefined.

- [ ] **Step 3: Implement**

Add to `AcfLinter`:

```php
    /**
     * Recursively collect *.json paths from the given files/dirs, ignoring
     * vendor/ and node_modules/. Mirrors lint.mjs glob behaviour.
     *
     * @param array<int, string> $paths
     * @return array<int, string> sorted absolute paths
     */
    public function collectJsonFiles(array $paths): array {
        $out = [];
        foreach ($paths as $p) {
            if (is_file($p)) {
                if (str_ends_with($p, '.json')) {
                    $out[] = $p;
                }
                continue;
            }
            if (!is_dir($p)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($p, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($it as $file) {
                /** @var \SplFileInfo $file */
                $abs = $file->getPathname();
                if (!str_ends_with($abs, '.json')) {
                    continue;
                }
                if (str_contains($abs, '/vendor/') || str_contains($abs, '/node_modules/')) {
                    continue;
                }
                $out[] = $abs;
            }
        }
        sort($out);
        return $out;
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Lint/AcfLinterTest.php -v`
Expected: PASS. If `test_whole_valid_corpus_lints_clean` fails, the bundled schema rejects a fixture that the JS linter accepted — investigate the specific error before continuing (it is a real finding).

- [ ] **Step 5: Commit**

```bash
git add src/Lint/AcfLinter.php tests/Lint/AcfLinterTest.php
git commit -m "feat(lint): recursive JSON file collection + valid-corpus guard"
```

---

### Task 6: `bin/acf-lint` CLI wrapper

**Files:**
- Create: `bin/acf-lint`

- [ ] **Step 1: Write the CLI**

```php
#!/usr/bin/env php
<?php
declare(strict_types=1);

// Resolve the autoloader whether installed as a dependency (vendor/bin) or run
// from the package root.
$autoloads = [
    __DIR__ . '/../vendor/autoload.php',            // standalone checkout
    __DIR__ . '/../../../autoload.php',             // installed in a consumer's vendor/
];
foreach ($autoloads as $a) {
    if (file_exists($a)) { require_once $a; break; }
}

use Parisek\AcfJsonSchema\Lint\AcfLinter;

$args = array_slice($argv, 1);
$strict = in_array('--strict', $args, true);
$fix = in_array('--fix', $args, true);
$paths = array_values(array_filter($args, static fn (string $a): bool => !str_starts_with($a, '-')));

if (in_array('--help', $args, true) || $paths === []) {
    fwrite(STDERR, <<<USAGE
    acf-lint — validate ACF / CPT / taxonomy / block JSON against parisek/acf-json-schema.

    Usage:
      acf-lint [--strict] [--fix] <path>...

      <path>     File or directory (directories are walked recursively for *.json)
      --strict   Exit 1 if any file has findings (CI gate)
      --fix      Auto-bump stale/missing `modified` timestamps

    Exit codes: 0 ok (or findings without --strict), 1 findings under --strict / bad args.

    USAGE);
    exit($paths === [] ? 1 : 0);
}

$linter = new AcfLinter(__DIR__ . '/../schemas');
$files = $linter->collectJsonFiles($paths);

$errorFiles = 0; $okFiles = 0; $skipped = 0; $fixedFiles = 0; $totalErrors = 0;
foreach ($files as $file) {
    $r = $linter->lintFile($file, $fix);
    if ($r->fixed) { $fixedFiles++; }
    if ($r->skipped) { $skipped++; continue; }
    if ($r->valid) { $okFiles++; continue; }

    $errorFiles++;
    $totalErrors += max(1, count($r->errors));
    fwrite(STDERR, "\033[31m✗\033[0m {$r->path} ({$r->kind})\n");
    foreach ($r->errors as $pointer => $message) {
        $line = is_array($message) ? json_encode($message) : (string) $message;
        fwrite(STDERR, "    \033[33m{$pointer}\033[0m — {$line}\n");
    }
}

$summary = count($files) . " files scanned, \033[32m{$okFiles} OK\033[0m";
if ($errorFiles) { $summary .= ", \033[31m{$errorFiles} with errors ({$totalErrors})\033[0m"; }
if ($fixedFiles) { $summary .= ", \033[33m{$fixedFiles} fixed\033[0m"; }
if ($skipped)    { $summary .= ", {$skipped} skipped"; }
fwrite(STDOUT, $summary . "\n");

exit(($strict && $errorFiles > 0) ? 1 : 0);
```

- [ ] **Step 2: Make executable**

Run: `chmod +x bin/acf-lint`

- [ ] **Step 3: Smoke-test against the valid corpus**

Run: `php bin/acf-lint tests/fixtures/valid`
Expected: summary line with `0 with errors`, exit 0.

Run: `php bin/acf-lint --strict tests/fixtures/invalid; echo "exit=$?"`
Expected: lists findings, `exit=1`.

- [ ] **Step 4: Commit**

```bash
git add bin/acf-lint
git commit -m "feat(lint): bin/acf-lint CLI (dispatch, --strict, --fix)"
```

---

### Task 7: `.gitattributes`, README, CHANGELOG, full check

**Files:**
- Create: `.gitattributes`
- Modify: `README.md`, `CHANGELOG.md`

- [ ] **Step 1: Create `.gitattributes` (keep the Composer dist lean)**

```gitattributes
/docs            export-ignore
/tests           export-ignore
/stubs           export-ignore
/.github         export-ignore
/.gitattributes  export-ignore
/.gitignore      export-ignore
/phpstan.neon    export-ignore
/phpunit.xml     export-ignore
/CLAUDE.md       export-ignore
/AGENTS.md       export-ignore
/RELEASING.md    export-ignore
```

- [ ] **Step 2: Document the CLI in README**

Add a section after "For maintainers" (adapt wording to the file):

```markdown
## Linting your project's ACF JSON (PHP)

```bash
composer require --dev parisek/acf-json-schema
vendor/bin/acf-lint --strict path/to/templates path/to/blocks
```

`acf-lint` walks the given files/dirs, dispatches each JSON to the right bundled
schema (block / acf / cpt / taxonomy), and reports findings. `--fix` bumps stale
`modified` timestamps; `--strict` exits non-zero on any finding (CI gate).
```

- [ ] **Step 3: CHANGELOG entry**

Add under a new `## [0.3.0] — 2026-05-29` heading above `[0.2.0]`:

```markdown
## [0.3.0] — 2026-05-29

### Added

- `bin/acf-lint` — PHP CLI that validates ACF / CPT / taxonomy / block JSON against the bundled schemas (`--strict` CI gate, `--fix` for stale `modified` timestamps). Lets consumers lint via Composer/PHP without a Node/ajv toolchain.
- `src/Lint/AcfLinter` + `FileLintResult` — reusable validation core.
- `.gitattributes` `export-ignore` so dev-only files (tests, docs, CI config, agent notes) are excluded from the Composer dist.

### Changed

- `opis/json-schema` promoted from `require-dev` to `require` (runtime dependency for `acf-lint`).
```

- [ ] **Step 4: Full check**

Run: `composer check`
Expected: all tests pass (snapshot skipped), PHPStan `[OK] No errors`.

- [ ] **Step 5: Commit**

```bash
git add .gitattributes README.md CHANGELOG.md
git commit -m "docs(lint): document acf-lint; gitattributes export-ignore; changelog 0.3.0"
```

---

### Task 8: Release v0.3.0

**Files:** none (git tag + GitHub release)

- [ ] **Step 1: Branch, PR, merge** (follow AGENTS.md: feature branch → PR → green CI → squash-merge `(#N)`)

```bash
git switch -c feat/acf-lint-cli
git push -u origin feat/acf-lint-cli
gh pr create --fill --base main
# after CI green:
gh pr merge --squash --delete-branch
```

- [ ] **Step 2: Tag + release from main** (per RELEASING.md)

```bash
git switch main && git pull --ff-only
git tag -a v0.3.0 -m "Release 0.3.0"
git push origin v0.3.0
gh release create v0.3.0 --title "v0.3.0" --notes-from-tag --latest
```

Expected: release shows as Latest. Verify Packagist picks up 0.3.0 (webhook).

---

## PHASE 2 — wordpress-base theme (`wp-content/themes/starter_theme`)

### Task 9: Wire `acf-lint`, remove the Node ACF linter + schema copies

**Files:**
- Modify: `composer.json` (theme)
- Delete: `tests/json-schemas/` (theme), `static/tests/json-schemas/`
- Modify: `static/package.json`, `README.md`

- [ ] **Step 1: Pull the new package into the theme**

Run (from theme root): `composer update parisek/acf-json-schema`
Expected: `vendor/bin/acf-lint` exists (symlinked path repo already configured).

- [ ] **Step 2: Add a composer lint script**

In theme `composer.json` `scripts`, add:

```json
        "lint:acf-json": "acf-lint --strict static/templates/component templates",
        "lint:acf-json:fix": "acf-lint --fix static/templates/component templates",
```

and replace the json part of `lint:npm` / `lint:all` so JSON-schema linting runs via composer, not `cd static && npm run lint:json`:

```json
        "lint:npm": "cd static && npm run lint:js && npm run lint:css",
        "lint:all": [
            "@lint:php",
            "@lint:phpcs",
            "@lint:twig",
            "@lint:acf-json",
            "@lint:npm"
        ],
```

(Update `lint:all:fix` similarly: add `@lint:acf-json:fix`, drop the `npm run lint:json:fix`.)

- [ ] **Step 3: Run the linter against the theme's real JSON**

Run (from theme root): `composer lint:acf-json`
Expected: either clean, or a list of findings. **If findings appear** (the bundled schema is stricter than the old project copy), triage each: fix the JSON, or if it is a legitimate ACF shape the schema doesn't model, record it and open an issue on `parisek/acf-json-schema` — do not loosen blindly.

- [ ] **Step 4: Delete the duplicated schema trees**

```bash
git rm -r tests/json-schemas static/tests/json-schemas
```

- [ ] **Step 5: Remove `lint:json*` from `static/package.json`** and drop now-unused `ajv` / `ajv-formats` / `glob` from its `devDependencies` if nothing else uses them (grep first: `grep -rn "ajv\|glob" static/ --include=*.mjs --include=*.js | grep -v node_modules`).

- [ ] **Step 6: Update theme README** schema-lint row to reference `composer lint:acf-json` + `vendor/parisek/acf-json-schema`.

- [ ] **Step 7: Commit on a branch + PR** (theme repo conventions, `(#N)` squash).

```bash
git switch -c feat/acf-lint-via-package
git add -A
git commit -m "build(lint): lint ACF JSON via parisek/acf-json-schema (acf-lint), drop Node ajv copies"
git push -u origin feat/acf-lint-via-package
gh pr create --fill --base main
```

---

## PHASE 3 — tailwind-base (`portadesign/tailwind-base`)

### Task 10: Remove the ACF JSON linter from the static toolchain

**Files:**
- Delete: `static/tests/json-schemas/` (or `tests/json-schemas/` at that repo's root layout — confirm path on checkout)
- Modify: `static/package.json` (or root `package.json`)

- [ ] **Step 1: Confirm the path** in the standalone repo (`/Users/pari/Sites/tailwind-base`): `ls tests/json-schemas static/tests/json-schemas 2>/dev/null`.

- [ ] **Step 2: Delete the schema-lint dir**

```bash
git rm -r <confirmed-json-schemas-path>
```

- [ ] **Step 3: Remove `lint:json`, `lint:json:fix`, `lint:json:ci`** from the package.json scripts; drop `ajv`/`ajv-formats`/`glob` from devDependencies if unused elsewhere (grep first).

- [ ] **Step 4: Update that repo's README** to note ACF JSON linting moved to the consuming theme via `parisek/acf-json-schema`.

- [ ] **Step 5: Commit on a branch + PR** (`(#N)` squash per that repo's conventions).

```bash
git switch -c chore/drop-acf-json-lint
git add -A
git commit -m "chore(lint): drop ACF JSON schema linter — moved to parisek/acf-json-schema"
git push -u origin chore/drop-acf-json-lint
gh pr create --fill --base main
```

---

## Self-review notes

- **Spec coverage:** PHP/opis linter (Tasks 2-6), opis→runtime (Task 1), CLI (Task 6), release (Task 8), theme wiring + dedupe (Task 9), tailwind-base removal (Task 10), docs-not-shipped via `.gitattributes` (Task 7). All approved-design points covered.
- **Stricter-schema migration** is explicitly handled as a triage step (Task 9 Step 3), not assumed away.
- **Indentation note:** `--fix` re-encodes with 4-space `JSON_PRETTY_PRINT` (ACF Pro's native indent); tab-indented hand-edited files are normalized. This is a deliberate, documented simplification vs lint.mjs's indent-detection — acceptable because ACF Pro emits 4-space and `--fix` only runs on demand.
- **Path coupling:** `bin/acf-lint` resolves the autoloader from both standalone and consumer-vendored locations (Task 6 Step 1).
```
