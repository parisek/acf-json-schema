<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Extract;

final class BlockExtractor {

    /** @return array<string, mixed> */
    public function emit(): array {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'https://schemas.parisek.dev/acf/block.schema.json',
            'title' => 'Gutenberg block.json with ACF extensions',
            'type' => 'object',
            'required' => ['apiVersion', 'name', 'title', 'category', 'icon', 'supports', 'example', 'acf'],
            'properties' => [
                'apiVersion' => ['const' => 3],
                'name' => ['type' => 'string', 'pattern' => '^acf/[a-z][a-z0-9-]*$'],
                'title' => ['type' => 'string', 'minLength' => 1],
                'description' => ['type' => ['string', 'null']],
                'category' => ['type' => 'string'],
                'icon' => [
                    // block.json `icon` is more permissive than CPT `menu_icon`:
                    // Gutenberg accepts a BARE Dashicon slug (e.g. "star-filled")
                    // and prepends "dashicons-" itself. The shared icon ref only
                    // allows the full "dashicons-…" class (correct for menu_icon),
                    // so widen here with anyOf rather than loosening the ref.
                    'allOf' => [
                        [
                            'anyOf' => [
                                ['$ref' => 'refs/icon.schema.json'],
                                [
                                    'type' => 'string',
                                    'pattern' => '^[a-z][a-z0-9-]+$',
                                    'description' => "Bare Dashicon slug (e.g. 'star-filled'); Gutenberg prepends 'dashicons-'.",
                                ],
                            ],
                        ],
                        [
                            'if' => ['type' => 'string', 'pattern' => '^<svg'],
                            'then' => ['pattern' => 'currentColor'],
                        ],
                    ],
                ],
                'keywords' => ['type' => ['null', 'array']],
                'supports' => ['type' => 'object'],
                'attributes' => ['type' => 'object'],
                'example' => ['type' => ['null', 'object']],
                'acf' => [
                    'type' => 'object',
                    'required' => ['mode', 'renderCallback'],
                    'properties' => [
                        'mode' => ['enum' => ['edit', 'preview', 'auto']],
                        'renderCallback' => ['type' => 'string'],
                        'renderTemplate' => ['type' => 'string'],
                        'postTypes' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'parent' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'additionalProperties' => true,
                ],
            ],
        ];
    }
}
