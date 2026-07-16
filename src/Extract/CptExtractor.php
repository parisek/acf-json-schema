<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Extract;

final class CptExtractor {

    /** @return array<string, mixed> */
    public function emit(): array {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'https://schemas.parisek.dev/acf/cpt.schema.json',
            'title' => 'ACF Custom Post Type',
            'type' => 'object',
            'required' => ['key', 'title', 'post_type', 'active'],
            'properties' => $this->buildProperties(),
        ];
    }

    /**
     * Hand-curated property map matching the ACF Pro 6.2+ CPT JSON shape.
     * Runtime introspection via acf_get_instance('ACF_Post_Type')->defaults is
     * not available in static analysis (no stub), so the map is maintained here.
     * Task 24's alignment phase reconciles this against a live ACF export.
     *
     * @return array<string, mixed>
     */
    private function buildProperties(): array {
        return [
            'key' => ['type' => 'string', 'pattern' => '^post_type_'],
            'title' => ['type' => 'string', 'minLength' => 1],
            'post_type' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_-]*$'],
            'active' => ['type' => 'boolean'],
            'advanced_configuration' => ['type' => 'boolean'],
            'labels' => ['type' => 'object'],
            'public' => ['type' => 'boolean'],
            'publicly_queryable' => ['type' => 'boolean'],
            'hierarchical' => ['type' => 'boolean'],
            'show_ui' => ['type' => 'boolean'],
            'show_in_menu' => ['type' => ['boolean', 'string']],
            'show_in_admin_bar' => ['type' => 'boolean'],
            'show_in_nav_menus' => ['type' => 'boolean'],
            'show_in_rest' => ['type' => 'boolean'],
            'rest_base' => ['type' => 'string'],
            'rest_namespace' => ['type' => 'string'],
            'menu_position' => ['type' => ['number', 'string', 'null'], 'description' => 'ACF default is null; the UI stores an unset value as "".'],
            'menu_icon' => ['$ref' => 'refs/icon.schema.json'],
            'capability_type' => ['type' => ['string', 'array']],
            'capabilities' => ['type' => 'object'],
            'map_meta_cap' => ['type' => 'boolean'],
            // Open set of strings, not a closed enum: ACF 6.8's CPT UI ships
            // 12 stock checkboxes (incl. notes, post-formats) plus an
            // "Add Custom" input (allow_custom) whose values are merged into
            // supports verbatim, and the acf/post_type/available_supports
            // filter can add more (issue #18, verified against 6.8.2 source).
            'supports' => [
                'type' => 'array',
                // minLength 1: ACF strips empty strings itself (array_filter
                // in class-acf-post-type.php), so "" never appears in exports.
                'items' => ['type' => 'string', 'minLength' => 1],
                'description' => 'Editor features. Stock ACF 6.8 checkboxes: title, editor, notes, thumbnail, author, trackbacks, revisions, custom-fields, comments, excerpt, page-attributes, post-formats; custom values allowed (UI "Add Custom").',
            ],
            'taxonomies' => ['type' => ['array', 'string'], 'description' => 'Array of taxonomy slugs; ACF serializes "none selected" as an empty string.'],
            'has_archive' => ['type' => ['boolean', 'string']],
            'rewrite' => ['$ref' => 'refs/permalink-rewrite.schema.json'],
            'query_var' => ['type' => ['boolean', 'string']],
            'can_export' => ['type' => 'boolean'],
            'delete_with_user' => ['type' => 'boolean'],
            'exclude_from_search' => ['type' => 'boolean'],
            'modified' => ['type' => 'integer'],
            'menu_order' => ['type' => 'integer'],
        ];
    }
}
