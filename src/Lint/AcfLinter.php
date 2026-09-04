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
            $errors = array_merge($errors, $this->wpmlTypeValueFindings($json));
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
     * Issue #30 — type -> value check (Layers 1-3 of the layered proposal;
     * Layer 4, leaf value types, is deliberately OUT of scope and untouched
     * here — the existing presence check + the image/gallery location-value
     * check above are the only leaf-type checks).
     *
     * Bucketing is purely by `$field['type']`, and the four buckets below
     * (`repeater`/`flexible_content`, `group`, `accordion`/`tab`/`message`)
     * are pairwise disjoint AND disjoint from `image`/`gallery` (the types
     * {@see walkFieldsWpmlLocationValue()} covers) — a field can never be
     * flagged by two of these checks at once, so there is no double-report
     * risk to guard against structurally.
     *
     * Layer 1 — `repeater` / `flexible_content` MUST be `3`. This is a
     * plugin FACT, not doctrine: ACFML forcibly overrides these two types
     * to `WPML_COPY_ONCE_CUSTOM_FIELD` (`= 3`, defined in
     * sitepress-multilingual-cms/inc/constants.php) at runtime regardless
     * of the configured value — see `ACFML\Helper\Fields::WRAPPER_FIELDS`
     * (acfml/classes/Helper/Fields.php:10) and
     * `WPML_ACF_Field_Settings::field_should_be_set_to_copy_once()`
     * (acfml/classes/class-wpml-acf-field-settings.php:335-341). Any other
     * configured value is provably dead configuration.
     *
     * Layer 2 — `group` SHOULD be `3` per this project's own doctrine
     * (gutenberg.md § Key Requirements: "`3` only on group containers
     * whose nested leaves carry their own preference"). ACFML does NOT
     * force this one — it's a project convention, not a plugin fact, so
     * the message is worded as doctrine rather than plugin behaviour. No
     * separate severity mechanism exists in this linter yet (checked —
     * neither a warning/error level nor an opt-in-flag concept is present
     * anywhere in src/), so this stays inside the same `--wpml` findings
     * list with a message that reads unmistakably as project-convention,
     * not plugin fact — the lightest correct option instead of inventing
     * a new severity subsystem for one rule.
     *
     * Layer 3 — `accordion` / `tab` / `message` are ACF UI/layout
     * pseudo-fields holding no translatable value; only `0` or absent are
     * sensible (mirrors the existing valueless-type presence exemption).
     *
     * @return array<string, string> JSON-pointer => message
     */
    public function wpmlTypeValueFindings(object $json): array {
        $out = [];
        $fields = $json->fields ?? null;
        if (is_array($fields)) {
            $this->walkFieldsWpmlTypeValue($fields, '/fields', $out);
        }
        return $out;
    }

    /**
     * Recurse fields + nested sub_fields (repeater/group) + flexible-content
     * layouts — same walker shape as {@see walkFieldsWpml()} — checking
     * type-bucketed value correctness per {@see wpmlTypeValueFindings()}.
     *
     * @param array<int|string, mixed> $fields
     * @param array<string, string>    $out
     */
    private function walkFieldsWpmlTypeValue(array $fields, string $base, array &$out): void {
        // Wrapper containers. NOT a plugin fact: ACFML does not force these to
        // any value. `field_should_be_set_to_copy_once()` only widens
        // `is_field_parsable()`; `save_field_settings()` then writes the
        // CONFIGURED preference. On options pages
        // `EditorHooks::maybeCopyWrapperToTranslations()` fires at 1, not 3, so
        // 3 is not privileged there either. See #39.
        $wrapperContainers = ['repeater', 'flexible_content'];
        $uiPseudoFields = ['accordion', 'tab', 'message'];

        foreach ($fields as $i => $field) {
            if (!$field instanceof \stdClass) {
                continue;
            }
            $ptr = $base . '/' . $i;
            $type = is_string($field->type ?? null) ? $field->type : '';
            $pref = $field->wpml_cf_preferences ?? null;

            if (in_array($type, $wrapperContainers, true) && !in_array($pref, [1, 3], true)) {
                $out[$ptr . '/wpml_cf_preferences'] = sprintf(
                    'required by --wpml (doctrine, not a plugin fact): %s containers take 3 when '
                        . 'translations should diverge, or 1 when the rows are identical in every '
                        . 'language — 1 carries the row-count meta ACF reads first, which 3 omits, '
                        . 'emptying the field on every translation — got %s',
                    $type,
                    $pref === null ? 'absent' : var_export($pref, true),
                );
            } elseif ($type === 'group' && $pref !== 3) {
                $out[$ptr . '/wpml_cf_preferences'] = sprintf(
                    'required by --wpml (doctrine, not a plugin fact): group containers should be 3 per '
                        . 'gutenberg.md § Key Requirements ("3 only on group containers whose nested leaves '
                        . 'carry their own preference") — got %s',
                    $pref === null ? 'absent' : var_export($pref, true),
                );
            } elseif (in_array($type, $uiPseudoFields, true) && $pref !== null && $pref !== 0) {
                $out[$ptr . '/wpml_cf_preferences'] = sprintf(
                    'required by --wpml: %s is an ACF UI/layout pseudo-field with no translatable value — '
                        . 'only 0 or absent is valid (got %s)',
                    $type,
                    var_export($pref, true),
                );
            }

            if (isset($field->sub_fields) && is_array($field->sub_fields)) {
                $this->walkFieldsWpmlTypeValue($field->sub_fields, $ptr . '/sub_fields', $out);
            }
            $layouts = $field->layouts ?? null;
            if ($layouts instanceof \stdClass) {
                $layouts = (array) $layouts;
            }
            if (is_array($layouts)) {
                foreach ($layouts as $lk => $layout) {
                    if ($layout instanceof \stdClass && isset($layout->sub_fields) && is_array($layout->sub_fields)) {
                        $this->walkFieldsWpmlTypeValue($layout->sub_fields, $ptr . '/layouts/' . $lk . '/sub_fields', $out);
                    }
                }
            }
        }
    }

    /**
     * PR #29 review finding — `enum: [1, 2, 3]` on image/gallery
     * (field-image.schema.json / field-gallery.schema.json) is
     * deliberately context-free: the schema has no visibility into a
     * field's own field group's `location`, so it accepts the common
     * post/block value (1), the options-page-only value (2) and the
     * re-authored-per-language value (3) everywhere, unconditionally.
     * That closed the options-page false positive but opened the inverse
     * false negative in the far more common post/block context: an
     * image/gallery field mistakenly authored with
     * `wpml_cf_preferences: 2` under a `post_type`/`block`
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
    /**
     * `param` values that only ever qualify a post/page context — they
     * narrow WHICH posts a rule matches (a specific template, status,
     * format, category, taxonomy term, parent, or the `attachment`
     * screen), but never compete with `post_type`/`block` for the
     * group's context. Their presence alongside `post_type`/`block` in
     * the SAME OR-group is the common "field group scoped to a post
     * type AND a page template" shape — it must still resolve to
     * `post_type_or_block`, not bail out to null. Standing alone (no
     * `post_type`/`block` in the group) they still only ever apply to
     * posts/pages, so they resolve to `post_type_or_block` by themselves
     * too.
     *
     * `post_template` is included alongside `page_template` — same
     * post-context-qualifier role, just the newer (any-post-type)
     * template-selection param.
     */
    private const POST_CONTEXT_QUALIFIER_PARAMS = [
        'post_template', 'post_status', 'post_format', 'post_category',
        'post_taxonomy', 'post', 'page_template', 'page_type', 'page_parent',
        'page', 'attachment',
    ];

    /**
     * `param` values that never establish (or conflict with) a context
     * by themselves — `current_user`/`current_user_role` gate WHO is
     * looking, not WHAT is being looked at, so they coexist silently
     * with any other param in the same group.
     */
    private const NEUTRAL_PARAMS = ['current_user', 'current_user_role'];

    /**
     * Classifies a single OR-group's `param`s into one of three buckets:
     * `'options_page'`, `'post_type_or_block'`, `'other'` (a genuinely
     * distinct, unhandled ACF context — taxonomy, user_form, user_role,
     * user, comment, widget, nav_menu, nav_menu_item), or `null` when
     * the group carries no context-bearing param at all (empty, or only
     * {@see NEUTRAL_PARAMS}).
     *
     * A group mixing two incompatible primary contexts in one AND-group
     * (e.g. `options_page` + `post_type` together, or either alongside
     * an `'other'` param) has no single correct context either — that
     * also resolves to `null` so the caller treats it as ambiguous.
     *
     * @param array<int|string, mixed> $rules
     * @return 'options_page'|'post_type_or_block'|'other'|null
     */
    private function classifyOrGroup(array $rules): ?string {
        $hasOptionsPage = false;
        $hasPostOrBlock = false;
        $hasPostContextQualifier = false;
        $hasOther = false;
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
            } elseif (in_array($param, self::POST_CONTEXT_QUALIFIER_PARAMS, true)) {
                $hasPostContextQualifier = true;
            } elseif (in_array($param, self::NEUTRAL_PARAMS, true) || $param === null) {
                // contributes nothing either way
            } else {
                // taxonomy, nav_menu_item, user_form, user_role, user,
                // comment, widget, nav_menu — a genuinely distinct
                // context this classifier doesn't demand a value for.
                $hasOther = true;
            }
        }
        if ($hasOptionsPage && ($hasPostOrBlock || $hasPostContextQualifier || $hasOther)) {
            return null; // options_page mixed with a competing context in one AND-group
        }
        if ($hasOther && ($hasPostOrBlock || $hasPostContextQualifier)) {
            return null; // an unrecognized context mixed with post/block in one AND-group
        }
        if ($hasOptionsPage) {
            return 'options_page';
        }
        if ($hasPostOrBlock || $hasPostContextQualifier) {
            return 'post_type_or_block';
        }
        if ($hasOther) {
            return 'other';
        }
        return null; // empty group, or only neutral params — no context info
    }

    /**
     * Classifies a field group's `location` (ACF's array-of-OR-groups of
     * `{param, operator, value}` rules) into a single WPML value context,
     * or null when the location doesn't resolve unambiguously.
     *
     * Classification runs per OR-group ({@see classifyOrGroup()}) so
     * that `post_type` AND `page_template` inside the SAME group (a
     * common real ACF shape) doesn't get conflated with two SEPARATE
     * OR-groups genuinely targeting different contexts (`options_page`
     * OR `taxonomy`). Groups that resolve to `'other'`, or resolved
     * groups that disagree with each other (`options_page` OR
     * `post_type` across two groups), leave the whole location
     * ambiguous — a field group an editor genuinely targets at BOTH an
     * options page and a post type (or at a context this classifier
     * doesn't recognize) is left alone: there is no single correct value
     * to demand without false-flagging a legitimate dual-context group.
     *
     * @param array<int|string, mixed> $location
     * @return 'options_page'|'post_type_or_block'|null
     */
    private function classifyLocationContext(array $location): ?string {
        $sawOptionsPage = false;
        $sawPostOrBlock = false;
        foreach ($location as $orGroup) {
            $rules = $orGroup instanceof \stdClass ? (array) $orGroup : (is_array($orGroup) ? $orGroup : []);
            $groupContext = $this->classifyOrGroup($rules);
            if ($groupContext === 'other') {
                return null; // a genuinely distinct, unhandled context — ambiguous
            }
            if ($groupContext === 'options_page') {
                $sawOptionsPage = true;
            } elseif ($groupContext === 'post_type_or_block') {
                $sawPostOrBlock = true;
            }
            // null ($groupContext) — no context info from this group, doesn't affect the verdict
        }
        if ($sawOptionsPage && $sawPostOrBlock) {
            return null; // two groups disagree — genuinely dual-context, ambiguous
        }
        if ($sawOptionsPage) {
            return 'options_page';
        }
        if ($sawPostOrBlock) {
            return 'post_type_or_block';
        }
        return null; // no group carried any context info
    }

    /**
     * Recurse fields + nested sub_fields + flexible-content layouts (same
     * shape as {@see walkFieldsWpml()}), flagging any `image`/`gallery`
     * field whose `wpml_cf_preferences` is not valid for $context.
     * Non-image/gallery field types are untouched here — their
     * `wpml_cf_preferences` enum (`[0, 1, 2, 3]` in field.schema.json) is
     * already context-free by design, only image/gallery narrowed to
     * `[1, 2, 3]` for the carve-outs this check exists to make precise.
     *
     * @param array<int|string, mixed> $fields
     * @param 'options_page'|'post_type_or_block' $context
     * @param array<string, string> $out
     */
    private function walkFieldsWpmlLocationValue(array $fields, string $base, string $context, array &$out): void {
        // Options pages have no post duplication, so copy-once (3) has nothing
        // to seed a translation FROM — 2 stays the only correct value there.
        //
        // Under post_type/block both 1 and 3 are defensible and the choice is
        // editorial, not mechanical: 1 shares one asset across every language
        // and re-syncs it on each save; 3 seeds the translation once and then
        // lets the editor re-author it. An image carrying text — an e-book
        // cover, a localised screenshot, artwork with a headline baked in — is
        // the 3 case, and demanding 1 there does not merely warn: it prescribes
        // an edit that overwrites the translated artwork the next time the
        // default-language post is saved.
        //
        // 2 stays rejected outside options pages: it is the carve-out for a
        // context where 1 cannot work, and elsewhere it disables the
        // per-language remapping that makes an image field multilingual at all.
        $allowed = $context === 'options_page' ? [2] : [1, 3];

        foreach ($fields as $i => $field) {
            if (!$field instanceof \stdClass) {
                continue;
            }
            $ptr = $base . '/' . $i;
            $type = is_string($field->type ?? null) ? $field->type : '';
            $pref = $field->wpml_cf_preferences ?? null;
            if (in_array($type, ['image', 'gallery'], true) && is_int($pref) && !in_array($pref, $allowed, true)) {
                $out[$ptr . '/wpml_cf_preferences'] = sprintf(
                    'required by --wpml: %s under a %s location must be %s (got %d) — %s',
                    $type,
                    $context === 'options_page' ? 'options_page' : 'post_type/block',
                    count($allowed) === 1 ? (string) $allowed[0] : implode(' or ', $allowed),
                    $pref,
                    $this->wpmlLocationValueReason($context, $pref),
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
     * Explains the value that was ACTUALLY found, rather than restating the
     * rule. The previous single-sentence-per-context message always described
     * `2`, so a field carrying `0` or `3` was told why a value it does not have
     * is wrong — the reader then has to guess which half of the sentence
     * applies to them.
     *
     * @param 'options_page'|'post_type_or_block' $context
     */
    private function wpmlLocationValueReason(string $context, int $pref): string {
        if ($context === 'options_page') {
            return match ($pref) {
                1 => 'ACFML locks a copy-flagged (1) field to its default-language value on Options Pages',
                3 => 'copy-once (3) seeds a translation from its source post, and an Options Page has no post duplication to seed from',
                0 => 'ignore (0) keeps the field out of translation entirely, so an Options Page can hold only one language\'s value',
                default => 'only 2 holds a per-language value on an Options Page',
            };
        }

        return match ($pref) {
            2 => 'value 2 is the options-page-only carve-out and disables per-language translation here',
            0 => 'ignore (0) keeps the field out of translation entirely — use 1 to share one asset across languages, or 3 when the editor re-authors the image per language',
            default => 'use 1 to share one asset across languages, or 3 when the editor re-authors the image per language',
        };
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
