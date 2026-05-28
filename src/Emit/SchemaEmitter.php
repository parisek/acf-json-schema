<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Emit;

final class SchemaEmitter {

    /**
     * Canonical ACF field-type slug order for the acf.schema.json anyOf
     * discriminator array. This list is hand-curated and intentionally stable
     * across ACF versions — it matches the committed schemas/acf.schema.json.
     * New field types added by ACF upgrades should be appended here in a
     * deliberate PR rather than silently reordered by runtime discovery.
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
            'required' => ['key', 'title', 'fields', 'location', 'modified', 'active', 'acfml_field_group_mode'],
            'properties' => [
                'key'    => ['type' => 'string', 'pattern' => '^group_'],
                'title'  => ['type' => 'string', 'minLength' => 1],
                'fields' => [
                    'type'  => 'array',
                    'items' => [
                        'unevaluatedProperties' => false,
                        'allOf' => [
                            ['$ref' => 'refs/field.schema.json'],
                            ['anyOf' => $branches],
                        ],
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
                'show_in_rest'          => ['enum' => [0, 1]],
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
