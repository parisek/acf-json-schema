<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Emit;

final class SchemaEmitter {

    /**
     * Assembles the top-level acf.schema.json (Field Group root schema).
     *
     * @param list<string> $fieldTypes  Ordered list of ACF field type slugs,
     *                                  typically from FieldExtractor::emitAll().
     * @return array<string, mixed>
     */
    public function emitAcfSchema(array $fieldTypes): array {
        $branches = [];
        foreach ($fieldTypes as $type) {
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
     * Copies the four static utility ref schemas from the bundled templates
     * directory into the generator output directory.
     *
     * These files do not change per ACF version — they are hand-curated and
     * kept verbatim. IMPORTANT: any hand-edit to the committed
     * schemas/refs/{field,icon,location-rule,permalink-rewrite}.schema.json
     * files MUST be mirrored into src/templates/refs/ so the copies survive
     * the next generator run.
     */
    public function copyStaticRefs(string $output): void {
        $templates = __DIR__ . '/../templates/refs';
        $files = [
            'field.schema.json',
            'icon.schema.json',
            'location-rule.schema.json',
            'permalink-rewrite.schema.json',
        ];
        $refDir = "{$output}/refs";
        if (!is_dir($refDir)) {
            mkdir($refDir, 0755, true);
        }
        foreach ($files as $file) {
            copy("{$templates}/{$file}", "{$refDir}/{$file}");
        }
    }
}
