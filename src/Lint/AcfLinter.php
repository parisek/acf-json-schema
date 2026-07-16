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
            // Only ACF blocks are ours to validate. A native Gutenberg
            // block.json (no `acf` key) must be skipped, not failed — a
            // recursive scan over a theme with native blocks would otherwise
            // produce guaranteed false positives.
            return isset($json->acf) ? self::SCHEMA_BASE . 'block.schema.json' : null;
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

    /**
     * Validate a single JSON file. Read failures / invalid JSON return a
     * valid=false result with a synthetic error so the caller still surfaces
     * them. Unrecognized shapes return skipped=true.
     */
    public function lintFile(string $path, bool $fix, bool $requireWpml = false): FileLintResult {
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
            return new FileLintResult($path, null, false, [], false, true);
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

        if ($requireWpml && $kind === 'acf') {
            $errors = array_merge($errors, $this->wpmlPresenceFindings($json));
        }

        return new FileLintResult($path, $kind, $result->isValid() && $errors === [], $errors, $fixed, false);
    }

    /**
     * --wpml opt-in: the package schemas treat WPML/ACFML translation keys as
     * optional (ACF-faithful). This enforces their PRESENCE on field groups for
     * multilingual projects that require them. Values stay schema-governed.
     *
     * @return array<string, string> JSON-pointer => message
     */
    public function wpmlPresenceFindings(object $json): array {
        $out = [];
        if (!isset($json->acfml_field_group_mode)) {
            $out['/acfml_field_group_mode'] = 'required by --wpml: field-group translation mode is missing';
        }
        $fields = $json->fields ?? null;
        if (is_array($fields)) {
            $this->walkFieldsWpml($fields, '/fields', $out);
        }
        return $out;
    }

    /**
     * Recurse fields + nested sub_fields (repeater/group) + flexible-content
     * layouts, flagging any field object missing `wpml_cf_preferences`.
     *
     * @param array<int|string, mixed> $fields
     * @param array<string, string>    $out
     */
    private function walkFieldsWpml(array $fields, string $base, array &$out): void {
        // Pure-presentational field types hold no translatable value, so ACF
        // never attaches a translation preference to them — don't require one.
        $valueless = ['tab', 'message', 'accordion'];

        foreach ($fields as $i => $field) {
            if (!$field instanceof \stdClass) {
                continue;
            }
            $ptr = $base . '/' . $i;
            $type = is_string($field->type ?? null) ? $field->type : '';
            if (!in_array($type, $valueless, true) && !isset($field->wpml_cf_preferences)) {
                $out[$ptr . '/wpml_cf_preferences'] = 'required by --wpml: missing on field';
            }
            if (isset($field->sub_fields) && is_array($field->sub_fields)) {
                $this->walkFieldsWpml($field->sub_fields, $ptr . '/sub_fields', $out);
            }
            $layouts = $field->layouts ?? null;
            if ($layouts instanceof \stdClass) {
                $layouts = (array) $layouts;
            }
            if (is_array($layouts)) {
                foreach ($layouts as $lk => $layout) {
                    if ($layout instanceof \stdClass && isset($layout->sub_fields) && is_array($layout->sub_fields)) {
                        $this->walkFieldsWpml($layout->sub_fields, $ptr . '/layouts/' . $lk . '/sub_fields', $out);
                    }
                }
            }
        }
    }

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
                if (!$file instanceof \SplFileInfo) {
                    continue;
                }
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
}
