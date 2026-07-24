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

    public function __construct(string $schemasRoot, int $maxErrors = CliOptions::DEFAULT_MAX_ERRORS) {
        $resolver = new SchemaResolver();
        // Lazy-resolves every $ref (incl. per-type field refs) from disk.
        $resolver->registerPrefix(self::SCHEMA_BASE, rtrim($schemasRoot, '/'));

        $this->opis = new OpisValidator();
        // Capped: with 36 discriminator branches a badly broken file can
        // otherwise generate pathological error trees (was PHP_INT_MAX).
        $this->opis->setMaxErrors($maxErrors);
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
            // produce guaranteed false positives. Key PRESENCE decides
            // (property_exists, not isset): an explicit "acf": null is an
            // ACF-authored file with a malformed section and must be
            // validated (and fail), not skipped.
            return property_exists($json, 'acf') ? self::SCHEMA_BASE . 'block.schema.json' : null;
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
            file_put_contents($path, \Parisek\AcfJsonSchema\Json::encode($json));
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
            $errors = array_merge($errors, $this->wpmlLocationValueFindings($json));
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
     * PR #29 review finding — `enum: [1, 2]` on image/gallery
     * (field-image.schema.json / field-gallery.schema.json) is
     * deliberately context-free: the schema has no visibility into a
     * field's own field group's `location`, so it accepts both the
     * common post/block value (1) and the options-page-only value (2)
     * everywhere, unconditionally. That closed the options-page false
     * positive but opened the inverse false negative in the far more
     * common post/block context: an image/gallery field mistakenly
     * authored with `wpml_cf_preferences: 2` under a `post_type`/`block`
     * location now validates silently, and translators lose per-language
     * image swapping on that field. This is the ONLY place in the
     * package with both `fields` and `location` in view at once — the
     * schema layer never sees them together — so the cross-check lives
     * here, gated behind the same `--wpml` opt-in as the presence check
     * above (both are WPML/ACFML-specific concerns; schema validation
     * proper stays context-neutral per field-image.schema.json's own
     * description).
     *
     * Only fires when the field group's OWN root `location` resolves
     * unambiguously to one context (options-page-only, or
     * post_type/block-only) — see {@see classifyLocationContext()}. A
     * field group location an editor genuinely targets at BOTH an
     * options page and a post type is left alone: there is no single
     * correct value to demand without false-flagging a legitimate
     * dual-context group.
     *
     * @return array<string, string> JSON-pointer => message
     */
    public function wpmlLocationValueFindings(object $json): array {
        $out = [];
        $location = $json->location ?? null;
        if (!is_array($location)) {
            return $out;
        }
        $context = $this->classifyLocationContext($location);
        if ($context === null) {
            return $out; // mixed or unrecognized location — ambiguous, don't guess
        }

        $fields = $json->fields ?? null;
        if (is_array($fields)) {
            $this->walkFieldsWpmlLocationValue($fields, '/fields', $context, $out);
        }
        return $out;
    }

    /**
     * Classifies a field group's `location` (ACF's array-of-OR-groups of
     * `{param, operator, value}` rules) into a single WPML value context,
     * or null when the location doesn't resolve unambiguously.
     *
     * @param array<int|string, mixed> $location
     * @return 'options_page'|'post_type_or_block'|null
     */
    private function classifyLocationContext(array $location): ?string {
        $hasOptionsPage = false;
        $hasPostOrBlock = false;
        $hasOther = false;
        foreach ($location as $orGroup) {
            $rules = $orGroup instanceof \stdClass ? (array) $orGroup : (is_array($orGroup) ? $orGroup : []);
            foreach ($rules as $rule) {
                $param = null;
                if ($rule instanceof \stdClass) {
                    $param = $rule->param ?? null;
                } elseif (is_array($rule)) {
                    $param = $rule['param'] ?? null;
                }
                // NOTE: `operator` (e.g. `!=`) is deliberately ignored here.
                // `post_type != page` still targets a post_type context (all
                // post types except `page`) — negation doesn't change WHICH
                // context a param belongs to, only which values within that
                // context match. If a future rule shape is found where
                // ignoring the operator produces a wrong classification,
                // that's a new defect to raise, not something to guess at.
                if ($param === 'options_page') {
                    $hasOptionsPage = true;
                } elseif ($param === 'post_type' || $param === 'block') {
                    $hasPostOrBlock = true;
                } elseif ($param !== null) {
                    // Any other recognized ACF `param` (taxonomy,
                    // nav_menu_item, user_form, attachment, widget,
                    // comment, page_template, …) — the classifier has no
                    // demanded value for these contexts, so their presence
                    // alongside options_page/post_type/block makes the
                    // group's overall context ambiguous, exactly like the
                    // options_page+post_type mixed case below.
                    $hasOther = true;
                }
            }
        }
        if ($hasOther) {
            return null; // an unrecognized context coexists — ambiguous, don't guess
        }
        if ($hasOptionsPage && !$hasPostOrBlock) {
            return 'options_page';
        }
        if ($hasPostOrBlock && !$hasOptionsPage) {
            return 'post_type_or_block';
        }
        return null; // mixed (both), or neither param recognized — ambiguous
    }

    /**
     * Recurse fields + nested sub_fields + flexible-content layouts (same
     * shape as {@see walkFieldsWpml()}), flagging any `image`/`gallery`
     * field whose `wpml_cf_preferences` doesn't match the ONE value valid
     * for $context. Non-image/gallery field types are untouched here —
     * their `wpml_cf_preferences` enum (`[0, 1, 2, 3]` in
     * field.schema.json) is already context-free by design, only
     * image/gallery narrowed to `[1, 2]` for the options-page carve-out
     * this check exists to make precise.
     *
     * @param array<int|string, mixed> $fields
     * @param 'options_page'|'post_type_or_block' $context
     * @param array<string, string> $out
     */
    private function walkFieldsWpmlLocationValue(array $fields, string $base, string $context, array &$out): void {
        $required = $context === 'options_page' ? 2 : 1;

        foreach ($fields as $i => $field) {
            if (!$field instanceof \stdClass) {
                continue;
            }
            $ptr = $base . '/' . $i;
            $type = is_string($field->type ?? null) ? $field->type : '';
            $pref = $field->wpml_cf_preferences ?? null;
            if (in_array($type, ['image', 'gallery'], true) && is_int($pref) && $pref !== $required) {
                $out[$ptr . '/wpml_cf_preferences'] = sprintf(
                    'required by --wpml: %s under a %s location must be %d (got %d) — %s',
                    $type,
                    $context === 'options_page' ? 'options_page' : 'post_type/block',
                    $required,
                    $pref,
                    $context === 'options_page'
                        ? 'ACFML locks a copy-flagged (1) field to its default-language value on Options Pages'
                        : 'value 2 is the options-page-only carve-out and disables per-language translation here',
                );
            }
            if (isset($field->sub_fields) && is_array($field->sub_fields)) {
                $this->walkFieldsWpmlLocationValue($field->sub_fields, $ptr . '/sub_fields', $context, $out);
            }
            $layouts = $field->layouts ?? null;
            if ($layouts instanceof \stdClass) {
                $layouts = (array) $layouts;
            }
            if (is_array($layouts)) {
                foreach ($layouts as $lk => $layout) {
                    if ($layout instanceof \stdClass && isset($layout->sub_fields) && is_array($layout->sub_fields)) {
                        $this->walkFieldsWpmlLocationValue($layout->sub_fields, $ptr . '/layouts/' . $lk . '/sub_fields', $context, $out);
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
