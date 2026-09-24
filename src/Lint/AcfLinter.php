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

        $notices = [];
        if ($requireWpml && $kind === 'acf') {
            $errors = array_merge($errors, $this->wpmlPresenceFindings($json));
            $errors = array_merge($errors, $this->wpmlLocationValueFindings($json));
            $errors = array_merge($errors, $this->wpmlTypeValueFindings($json));
            $errors = array_merge($errors, $this->wpmlModeDefaultFindings($json));
            $notices = array_merge($this->wpmlLinkPreferenceNotices($json), $this->wpmlModeNotices($json));
        }

        return new FileLintResult($path, $kind, $result->isValid() && $errors === [], $errors, $fixed, false, $notices);
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
     * Layer 1 — `repeater` / `flexible_content` take `1` or `3`. This is
     * doctrine, not a plugin fact: ACFML does not force either value on a
     * post/block field group (`field_should_be_set_to_copy_once()` only
     * widens `is_field_parsable()`; `save_field_settings()` writes the
     * configured value). `1` carries the row-count meta ACF reads first;
     * `3` omits it. Whether either keeps translated rows aligned depends on
     * the group's mode — see {@see wpmlModeNotices()}.
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
        // Layer 2 is doctrine for Expert groups only. In a mode that manages
        // preferences ACFML sets `group` itself, and
        // {@see wpmlModeDefaultFindings()} already demands that value.
        $mode = $json->acfml_field_group_mode ?? null;
        $expert = !is_string($mode) || AcfmlModeDefaults::preference($mode, 'group') === null;
        if (is_array($fields)) {
            $this->walkFieldsWpmlTypeValue($fields, '/fields', $expert, $out);
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
    private function walkFieldsWpmlTypeValue(array $fields, string $base, bool $expert, array &$out): void {
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
            } elseif ($expert && $type === 'group' && $pref !== 3) {
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
                $this->walkFieldsWpmlTypeValue($field->sub_fields, $ptr . '/sub_fields', $expert, $out);
            }
            $layouts = $field->layouts ?? null;
            if ($layouts instanceof \stdClass) {
                $layouts = (array) $layouts;
            }
            if (is_array($layouts)) {
                foreach ($layouts as $lk => $layout) {
                    if ($layout instanceof \stdClass && isset($layout->sub_fields) && is_array($layout->sub_fields)) {
                        $this->walkFieldsWpmlTypeValue($layout->sub_fields, $ptr . '/layouts/' . $lk . '/sub_fields', $expert, $out);
                    }
                }
            }
        }
    }

    /**
     * `link` leaves at `1` (Copy) — a question, not a verdict.
     *
     * WHOSE BEHAVIOUR THIS DESCRIBES. Not ACFML's: the plugin has no
     * render-time sync for block attributes. It is the consuming theme's, and
     * the description below is `parisek/timber-kit`'s
     * (`WpmlBlockOverride` + `Helpers::formatLink()`), which is where the
     * measurement comes from. A project on a different renderer keeps the
     * notice's question — is this link identical in every language? — and
     * should read its own package for the mechanics.
     *
     * A link's preference decides two different things at render time, and a
     * field definition shows neither:
     *
     * - `2` sends the URL through the consuming theme's link formatter, which
     *   resolves it to a post and rebuilds the permalink in the language being
     *   rendered. A URL that names translatable site content therefore follows
     *   the visitor's language.
     * - `1` is Copy: the block-render sync replaces the WHOLE stored value —
     *   url, title, target — with the source language's, and nothing
     *   translates it afterwards. A correct per-language value in a
     *   translation is discarded.
     *
     * So `1` is right only when every rendered part of the link is identical
     * in every language. Measured on a five-language site: two link fields at
     * `1`, 8 links across 4 translated pages, all pointing into the source
     * language while the database held the right URL for each one. Nothing
     * errored; the data was correct and only the render was wrong.
     *
     * NOT an error, deliberately. `1` stays legitimate — an external profile,
     * an app-store listing, an on-page anchor — and no static check can tell
     * those from the broken case: the same `type: link` definition accepts an
     * internal URL, an external one, an anchor and a `mailto:`. The fleet
     * census in #30 shows the shape (`685 × 2` against `7 × 1`), which is a
     * reason to ask, not to fail. Hence {@see FileLintResult::$notices}.
     *
     * Two things worth knowing while answering, both from that theme rather
     * than from doctrine. At `2`, `target: "_blank"` is an opt-out from the
     * URL rewrite by design — the formatter returns on it before the
     * preference is read, which is how an editor keeps a link exactly as
     * stored. At `1` it is no protection at all: the Copy sync runs before the
     * formatter and never looks at the target, so it replaces a `_blank` link
     * like any other. And a link's title is never translated at render, so a
     * title that has to differ per language needs `2` and its own per-language
     * value whatever the URL does.
     *
     * @return array<string, string> JSON-pointer => message
     */
    public function wpmlLinkPreferenceNotices(object $json): array {
        $out = [];
        $fields = $json->fields ?? null;
        if (is_array($fields)) {
            $this->walkFieldsWpmlLinkPreference($fields, '/fields', $out);
        }
        return $out;
    }

    /**
     * Recurse fields + nested sub_fields + flexible-content layouts — same
     * walker shape as {@see walkFieldsWpmlTypeValue()} — collecting the
     * `link`-at-`1` notices described there.
     *
     * @param array<int|string, mixed> $fields
     * @param array<string, string>    $out
     */
    private function walkFieldsWpmlLinkPreference(array $fields, string $base, array &$out): void {
        foreach ($fields as $i => $field) {
            if (!$field instanceof \stdClass) {
                continue;
            }
            $ptr = $base . '/' . $i;

            if (($field->type ?? null) === 'link' && ($field->wpml_cf_preferences ?? null) === 1) {
                $out[$ptr . '/wpml_cf_preferences'] = 'notice: with a theme that syncs Copy fields at '
                    . 'render (parisek/timber-kit and the like), link at 1 (Copy) renders the SOURCE '
                    . 'language\'s url, title and target on every translation, and nothing translates them '
                    . 'afterwards. Keep 1 only when the whole rendered link is identical in every language '
                    . '(an external profile, an app-store listing, an on-page anchor). Use 2 when the URL '
                    . 'can point at translatable site content, or when the title differs per language — a '
                    . 'title is never translated at render, so it needs its own value per language either way.';
            }

            if (isset($field->sub_fields) && is_array($field->sub_fields)) {
                $this->walkFieldsWpmlLinkPreference($field->sub_fields, $ptr . '/sub_fields', $out);
            }
            $layouts = $field->layouts ?? null;
            if ($layouts instanceof \stdClass) {
                $layouts = (array) $layouts;
            }
            if (is_array($layouts)) {
                foreach ($layouts as $lk => $layout) {
                    if ($layout instanceof \stdClass && isset($layout->sub_fields) && is_array($layout->sub_fields)) {
                        $this->walkFieldsWpmlLinkPreference($layout->sub_fields, $ptr . '/layouts/' . $lk . '/sub_fields', $out);
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
        // In a mode that manages preferences ACFML sets image/gallery itself,
        // and {@see wpmlModeDefaultFindings()} demands that value. Checking
        // the Expert-only location rule on top would make every value fail.
        $mode = $json->acfml_field_group_mode ?? null;
        if (is_string($mode) && AcfmlModeDefaults::preference($mode, 'image') !== null) {
            return $out;
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
     * A field group in `translation` or `localization` mode hands the field
     * preferences to ACFML: saving the group in wp-admin rewrites every
     * `wpml_cf_preferences` to the mode's default ({@see AcfmlModeDefaults}).
     * A JSON value that differs describes behaviour the site keeps only until
     * that save, so it is an error, not a notice. A field that genuinely has
     * to differ belongs in an `advanced` group.
     *
     * @return array<string, string> JSON-pointer => message
     */
    public function wpmlModeDefaultFindings(object $json): array {
        $out = [];
        $mode = $json->acfml_field_group_mode ?? null;
        if (!is_string($mode) || AcfmlModeDefaults::preference($mode, 'text') === null) {
            return $out;
        }
        $fields = $json->fields ?? null;
        if (is_array($fields)) {
            $this->walkFieldsWpmlModeDefault($fields, '/fields', $mode, $out);
        }
        return $out;
    }

    /**
     * @param array<int|string, mixed> $fields
     * @param array<string, string>    $out
     */
    private function walkFieldsWpmlModeDefault(array $fields, string $base, string $mode, array &$out): void {
        foreach ($fields as $i => $field) {
            if (!$field instanceof \stdClass) {
                continue;
            }
            $ptr = $base . '/' . $i;
            $type = is_string($field->type ?? null) ? $field->type : '';
            $pref = $field->wpml_cf_preferences ?? null;
            $expected = AcfmlModeDefaults::preference($mode, $type);

            if ($expected !== null && is_int($pref) && $pref !== $expected) {
                $out[$ptr . '/wpml_cf_preferences'] = sprintf(
                    'required by --wpml: in a "%s" field group ACFML rewrites %s to %d on the next field-group '
                        . 'save in wp-admin, unless the site filters acfml_field_group_mode_field_translation_preference '
                        . '(got %d) — use %d, or move the field to an "advanced" group if it has to differ',
                    $mode,
                    $type,
                    $expected,
                    $pref,
                    $expected,
                );
            }

            if (isset($field->sub_fields) && is_array($field->sub_fields)) {
                $this->walkFieldsWpmlModeDefault($field->sub_fields, $ptr . '/sub_fields', $mode, $out);
            }
            $layouts = $field->layouts ?? null;
            if ($layouts instanceof \stdClass) {
                $layouts = (array) $layouts;
            }
            if (is_array($layouts)) {
                foreach ($layouts as $lk => $layout) {
                    if ($layout instanceof \stdClass && isset($layout->sub_fields) && is_array($layout->sub_fields)) {
                        $this->walkFieldsWpmlModeDefault($layout->sub_fields, $ptr . '/layouts/' . $lk . '/sub_fields', $mode, $out);
                    }
                }
            }
        }
    }

    /**
     * An `advanced` (Expert) field group on posts or terms that holds a
     * repeater or flexible content — a question, not a verdict.
     *
     * ACFML keeps a translation's rows in step with the original (a row
     * moved or removed in the original moves or goes in every translation,
     * with the translated text kept) only in `translation` mode, or in Expert
     * mode on the single posts or terms where an editor ticked "Synchronise
     * translations". Without it, both container preferences fail:
     *
     * - `3` (Copy once) freezes the row list at the first translation, so a
     *   row added to the original later never reaches it;
     * - `1` (Copy) copies the new row list over rows that did not move, so
     *   translated text lands under the wrong row.
     *
     * Measured on fellows (ACFML 5.0.0): an inserted layout left the Czech
     * room type unchanged at `3`, and at `1` put every Czech block after the
     * insert one row off. `translation` mode kept the Czech rows aligned on a
     * reorder and held the insert back for the translator.
     *
     * NOT an error: an Expert group can carry a field that must differ from
     * ACFML's default (an external ID at Copy, say), and only the author knows
     * whether the synchronisation matters for its containers. Options pages
     * are out of scope (they follow their own per-field rule), and so are
     * blocks, whose rows live in post content rather than in post meta — not
     * measured there.
     *
     * @return array<string, string> JSON-pointer => message
     */
    public function wpmlModeNotices(object $json): array {
        if (($json->acfml_field_group_mode ?? null) !== 'advanced') {
            return [];
        }
        $location = $json->location ?? null;
        if (!is_array($location) || !$this->targetsOnlyPostsOrTerms($location)) {
            return [];
        }
        $fields = $json->fields ?? null;
        if (!is_array($fields) || !$this->hasRowContainer($fields)) {
            return [];
        }
        return [
            '/acfml_field_group_mode' => 'notice: this "advanced" group holds a repeater or flexible content on posts '
                . 'or terms. ACFML keeps translated rows in step with the original only in "translation" mode, or on '
                . 'posts or terms where an editor ticked "Synchronise translations". Without it, a container at 3 (Copy once) '
                . 'never receives rows added later, and a container at 1 (Copy) puts translated text under the wrong '
                . 'row. Use "translation" unless a field in the group must differ from ACFML\'s default.',
        ];
    }

    /**
     * True when every OR-group of $location targets posts or taxonomy terms,
     * and none targets a block, an options page or another context.
     *
     * @param array<int|string, mixed> $location
     */
    private function targetsOnlyPostsOrTerms(array $location): bool {
        $allowed = array_merge(['post_type', 'taxonomy'], self::POST_CONTEXT_QUALIFIER_PARAMS, self::NEUTRAL_PARAMS);
        $sawTarget = false;
        foreach ($location as $orGroup) {
            $rules = $orGroup instanceof \stdClass ? (array) $orGroup : (is_array($orGroup) ? $orGroup : []);
            foreach ($rules as $rule) {
                $param = $rule instanceof \stdClass ? ($rule->param ?? null) : (is_array($rule) ? ($rule['param'] ?? null) : null);
                if (!is_string($param) || !in_array($param, $allowed, true)) {
                    return false;
                }
                if (!in_array($param, self::NEUTRAL_PARAMS, true)) {
                    $sawTarget = true;
                }
            }
        }
        return $sawTarget;
    }

    /**
     * @param array<int|string, mixed> $fields
     */
    private function hasRowContainer(array $fields): bool {
        foreach ($fields as $field) {
            if (!$field instanceof \stdClass) {
                continue;
            }
            if (in_array($field->type ?? null, ['repeater', 'flexible_content'], true)) {
                return true;
            }
            if (isset($field->sub_fields) && is_array($field->sub_fields) && $this->hasRowContainer($field->sub_fields)) {
                return true;
            }
        }
        return false;
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
