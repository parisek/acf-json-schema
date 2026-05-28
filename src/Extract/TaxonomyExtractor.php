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
     * Hand-curated property map matching the ACF Pro 6.2+ Taxonomy JSON shape.
     * Runtime introspection via acf_get_instance('ACF_Taxonomy')->defaults is
     * not available in static analysis (no stub), so the map is maintained here.
     * Task 24's alignment phase reconciles this against a live ACF export.
     *
     * @return array<string, mixed>
     */
    private function buildProperties(): array {
        return [
            'key' => ['type' => 'string', 'pattern' => '^taxonomy_'],
            'title' => ['type' => 'string', 'minLength' => 1],
            'taxonomy' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_-]*$'],
            'active' => ['type' => 'boolean'],
            'advanced_configuration' => ['type' => 'boolean'],
            'object_type' => ['type' => 'array', 'items' => ['type' => 'string']],
            'labels' => ['type' => 'object'],
            'public' => ['type' => 'boolean'],
            'publicly_queryable' => ['type' => 'boolean'],
            'hierarchical' => ['type' => 'boolean'],
            'show_ui' => ['type' => 'boolean'],
            'show_in_menu' => ['type' => 'boolean'],
            'show_in_nav_menus' => ['type' => 'boolean'],
            'show_in_rest' => ['type' => 'boolean'],
            'show_admin_column' => ['type' => 'boolean'],
            'show_tagcloud' => ['type' => 'boolean'],
            'show_in_quick_edit' => ['type' => 'boolean'],
            'rewrite' => ['$ref' => 'refs/permalink-rewrite.schema.json'],
            'single_value' => ['type' => 'boolean'],
            'modified' => ['type' => 'integer'],
        ];
    }
}
