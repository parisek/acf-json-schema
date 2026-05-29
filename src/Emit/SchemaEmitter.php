<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Emit;

final class SchemaEmitter {

    /**
     * Canonical ACF field-type slug order for the acf.schema.json discriminator.
     * This list is hand-curated and intentionally stable across ACF versions —
     * it matches the committed schemas/acf.schema.json. New field types added by
     * ACF upgrades should be appended here in a deliberate PR rather than
     * silently reordered by runtime discovery.
     *
     * @var list<string>
     */
    private const FIELD_TYPE_ORDER = [
        'text', 'textarea', 'wysiwyg', 'number', 'range',
        'email', 'url', 'password',
        'image', 'gallery', 'file',
        'link', 'post_object', 'page_link', 'relationship', 'taxonomy', 'user',
        'select', 'checkbox', 'radio', 'true_false', 'button_group',
        'color_picker', 'icon_picker',
        'date_picker', 'time_picker', 'date_time_picker',
        'google_map',
        'repeater', 'group', 'flexible_content', 'clone',
        'tab', 'accordion', 'message', 'oembed',
    ];

    /**
     * Assembles the top-level acf.schema.json (Field Group root schema).
     *
     * Uses the static FIELD_TYPE_ORDER list rather than the runtime-discovered
     * type list — this ensures stable, deterministic output that matches the
     * committed schemas/acf.schema.json regardless of ACF's internal iteration
     * order. To add a new field type: append to FIELD_TYPE_ORDER, add the
     * corresponding field-<type>.schema.json to src/templates/refs/, and
     * add the entry to schemas/acf.schema.json and schemas/refs/.
     *
     * @return array<string, mixed>
     */
    public function emitAcfSchema(): array {
        // Discriminator: each if/then pair sits directly in the items `allOf`.
        // For a field whose `type` does not match a branch's `if`, that branch is
        // vacuously satisfied (if-false ⇒ pass); the single branch whose `if`
        // matches has its `then` ref ENFORCED. This is the correct JSON Schema
        // 2020-12 discriminated-union construct.
        //
        // Do NOT wrap these in `anyOf`: under `anyOf` the non-matching branches
        // are vacuously valid, so `anyOf` succeeds regardless of whether the
        // matching `then` ref holds — silently disabling all per-type validation
        // (missing required props and base-overlapping constraints slip through).
        $branches = [];
        foreach (self::FIELD_TYPE_ORDER as $type) {
            $branches[] = [
                'if'   => ['properties' => ['type' => ['const' => $type]]],
                'then' => ['$ref' => "refs/field-{$type}.schema.json"],
            ];
        }

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id'     => 'https://schemas.parisek.dev/acf/acf.schema.json',
            'title'   => 'ACF Field Group',
            'type'    => 'object',
            // acfml_field_group_mode is intentionally NOT required: ACF only emits
            // it when ACFML (WPML) is active, so plain-ACF exports omit it. It is
            // still value-constrained in 'properties' below, so when present it
            // must be "advanced" (required-only-when-present semantics).
            'required' => ['key', 'title', 'fields', 'location', 'modified', 'active'],
            'properties' => [
                'key'    => ['type' => 'string', 'pattern' => '^group_'],
                'title'  => ['type' => 'string', 'minLength' => 1],
                'fields' => [
                    'type'  => 'array',
                    'items' => [
                        'unevaluatedProperties' => false,
                        'allOf' => array_merge(
                            [['$ref' => 'refs/field.schema.json']],
                            $branches,
                        ),
                    ],
                ],
                'location' => [
                    'type'  => 'array',
                    'items' => [
                        'type'  => 'array',
                        'items' => ['$ref' => 'refs/location-rule.schema.json'],
                    ],
                ],
                'menu_order'            => ['type' => 'integer'],
                'position'              => ['enum' => ['normal', 'side', 'acf_after_title']],
                'style'                 => ['enum' => ['default', 'seamless']],
                'label_placement'       => ['enum' => ['top', 'left']],
                'instruction_placement' => ['enum' => ['label', 'field']],
                'hide_on_screen'        => ['type' => ['string', 'array']],
                'active'                => ['type' => 'boolean'],
                'description'           => ['type' => 'string', 'maxLength' => 0],
                'modified'              => ['type' => 'integer', 'minimum' => 0],
                'acfml_field_group_mode' => ['const' => 'advanced'],
                'show_in_rest'          => ['enum' => [0, 1, true, false]],
            ],
        ];
    }

    /**
     * Returns the canonical field type order used in acf.schema.json.
     *
     * @return list<string>
     */
    public function fieldTypeOrder(): array {
        return self::FIELD_TYPE_ORDER;
    }

    /**
     * Copies ALL hand-curated ref schemas from the bundled templates directory
     * into the generator output directory. This includes both the four utility
     * refs (field, icon, location-rule, permalink-rewrite) and all 36 per-type
     * field refs (field-text, field-select, …).
     *
     * These files are hand-curated and ship verbatim — they carry richer
     * constraints (patterns, $refs, enums, nested objects) than runtime
     * extraction can produce. When ACF adds a new field type, the workflow is:
     *   1. Add field-<type>.schema.json to src/templates/refs/
     *   2. Add field-<type>.schema.json to schemas/refs/ (distribution copy)
     *   3. Add the type slug to SchemaEmitter::FIELD_TYPE_ORDER
     *   4. Add the anyOf entry to schemas/acf.schema.json
     *
     * IMPORTANT: any edit to schemas/refs/ files MUST be mirrored back into
     * src/templates/refs/ — the template directory is the source of truth;
     * schemas/refs/ is the distribution copy committed for downstream consumers.
     */
    public function copyStaticRefs(string $output): void {
        $templates = __DIR__ . '/../templates/refs';
        $refDir = "{$output}/refs";
        if (!is_dir($refDir)) {
            mkdir($refDir, 0755, true);
        }
        foreach (glob("{$templates}/*.schema.json") ?: [] as $src) {
            copy($src, "{$refDir}/" . basename($src));
        }
    }
}
