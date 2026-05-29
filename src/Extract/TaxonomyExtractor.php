<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Extract;

final class TaxonomyExtractor {

    /** @return array<string, mixed> */
    public function emit(): array {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'https://schemas.parisek.dev/acf/taxonomy.schema.json',
            'title' => 'ACF Taxonomy',
            'type' => 'object',
            'required' => ['key', 'title', 'taxonomy', 'object_type', 'active'],
            'properties' => $this->buildProperties(),
        ];
    }

    /**
     * Hand-curated property map matching the ACF Pro 6.x Taxonomy JSON shape.
     *
     * Boolean-flag TYPING NOTE: unlike the post-type class, ACF's taxonomy class
     * (ACF_Taxonomy) has no "Convert types" normalisation in validate_post(), so
     * it writes these WordPress boolean args to JSON as integers (0/1), not
     * true/false — verified against ACF Pro 6.8 source. We accept both forms:
     * ACF's real output is integer, but the boolean form stays valid for files
     * authored via the API/WP-CLI. `active` is the exception — the parent
     * internal-post-type class normalises it to a real boolean.
     *
     * @return array<string, mixed>
     */
    private function buildProperties(): array {
        // ACF writes these WordPress boolean args to JSON as integers (0/1), but
        // the boolean form stays valid for files authored via the API/WP-CLI.
        // Constrain to exactly those forms rather than the broad `integer` type,
        // so malformed values like 2 or -1 are still rejected.
        $boolFlag = ['enum' => [true, false, 0, 1]];
        return [
            'key' => ['type' => 'string', 'pattern' => '^taxonomy_'],
            'title' => ['type' => 'string', 'minLength' => 1],
            'taxonomy' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_-]*$'],
            'active' => ['type' => 'boolean'],
            'advanced_configuration' => $boolFlag,
            'object_type' => ['type' => 'array', 'items' => ['type' => 'string']],
            'labels' => ['type' => 'object'],
            'public' => $boolFlag,
            'publicly_queryable' => $boolFlag,
            'hierarchical' => $boolFlag,
            'show_ui' => $boolFlag,
            'show_in_menu' => $boolFlag,
            'show_in_nav_menus' => $boolFlag,
            'show_in_rest' => $boolFlag,
            'show_admin_column' => $boolFlag,
            'show_tagcloud' => $boolFlag,
            'show_in_quick_edit' => $boolFlag,
            'rewrite' => ['$ref' => 'refs/permalink-rewrite.schema.json'],
            'single_value' => ['enum' => [true, false, 0, 1, null]],
            'modified' => ['type' => 'integer'],
        ];
    }
}
