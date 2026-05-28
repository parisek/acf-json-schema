<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Tests;

use Parisek\AcfJsonSchema\Tests\Helpers\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase {

    private Validator $validator;

    protected function setUp(): void {
        $schemasRoot = realpath(__DIR__ . '/../schemas/') . '/';
        $this->validator = new Validator($schemasRoot);
    }

    public function test_menu_icon_junk_repr_fails_icon_schema(): void {
        $junk = "{'type': 'dashicons', 'value': 'dashicons-building'}";
        $result = $this->validator->validate(
            'https://schemas.parisek.dev/acf/refs/icon.schema.json',
            $junk
        );
        $this->assertFalse(
            $result->isValid(),
            'Python str(dict) repr must not pass the icon schema. Got: ' . json_encode($this->validator->formatErrors($result))
        );
    }

    public function test_menu_icon_valid_dashicon_passes(): void {
        $result = $this->validator->validate(
            'https://schemas.parisek.dev/acf/refs/icon.schema.json',
            'dashicons-building'
        );
        $this->assertTrue(
            $result->isValid(),
            'Valid dashicon class must pass. Errors: ' . json_encode($this->validator->formatErrors($result))
        );
    }

    public function test_location_rule_valid_post_type_eq_passes(): void {
        $rule = ['param' => 'post_type', 'operator' => '==', 'value' => 'page'];
        $result = $this->validator->validate(
            'https://schemas.parisek.dev/acf/refs/location-rule.schema.json',
            (object) $rule
        );
        $this->assertTrue($result->isValid(), 'post_type == page must pass');
    }

    public function test_location_rule_unknown_operator_fails(): void {
        $rule = ['param' => 'post_type', 'operator' => 'BAD_OPERATOR', 'value' => 'page'];
        $result = $this->validator->validate(
            'https://schemas.parisek.dev/acf/refs/location-rule.schema.json',
            (object) $rule
        );
        $this->assertFalse($result->isValid(), 'Unknown operator must fail');
    }

    public function test_permalink_rewrite_custom_passes(): void {
        $rw = (object) [
            'permalink_rewrite' => 'custom_permalink',
            'slug' => 'projekt',
            'with_front' => '0',
            'feeds' => '0',
            'pages' => '1'
        ];
        $result = $this->validator->validate(
            'https://schemas.parisek.dev/acf/refs/permalink-rewrite.schema.json',
            $rw
        );
        $this->assertTrue($result->isValid(), 'custom permalink config must pass');
    }

    public function test_permalink_rewrite_empty_object_fails(): void {
        $result = $this->validator->validate(
            'https://schemas.parisek.dev/acf/refs/permalink-rewrite.schema.json',
            (object) []
        );
        $this->assertFalse($result->isValid(), 'Empty object must fail (permalink_rewrite required)');
    }

    public function test_permalink_rewrite_unknown_value_fails(): void {
        $result = $this->validator->validate(
            'https://schemas.parisek.dev/acf/refs/permalink-rewrite.schema.json',
            (object) ['permalink_rewrite' => 'BOGUS_STRATEGY']
        );
        $this->assertFalse($result->isValid(), 'Unknown permalink strategy must fail enum');
    }

    public function test_permalink_rewrite_extra_property_fails(): void {
        $result = $this->validator->validate(
            'https://schemas.parisek.dev/acf/refs/permalink-rewrite.schema.json',
            (object) ['permalink_rewrite' => 'custom_permalink', 'slug' => 'x', 'unknown' => 'y']
        );
        $this->assertFalse($result->isValid(), 'Extra property must fail additionalProperties:false');
    }

    public function test_location_rule_unknown_param_fails(): void {
        $result = $this->validator->validate(
            'https://schemas.parisek.dev/acf/refs/location-rule.schema.json',
            (object) ['param' => 'NOT_A_REAL_PARAM', 'operator' => '==', 'value' => 'x']
        );
        $this->assertFalse($result->isValid(), 'Unknown param must fail enum');
    }

    public function test_field_base_text_valid_passes(): void {
        $field = (object) [
            'key' => 'field_title',
            'label' => 'Title',
            'name' => 'title',
            'type' => 'text',
            'allow_in_bindings' => 0
        ];
        $result = $this->validator->validate(
            'https://schemas.parisek.dev/acf/refs/field.schema.json',
            $field
        );
        $this->assertTrue($result->isValid(), 'minimal text field must pass base');
    }

    public function test_field_base_missing_allow_in_bindings_fails(): void {
        $field = (object) [
            'key' => 'field_title',
            'label' => 'Title',
            'name' => 'title',
            'type' => 'text'
        ];
        $result = $this->validator->validate(
            'https://schemas.parisek.dev/acf/refs/field.schema.json',
            $field
        );
        $this->assertFalse($result->isValid(), 'missing allow_in_bindings must fail');
    }

    public function test_field_base_unknown_type_fails(): void {
        $field = (object) [
            'key' => 'field_x',
            'label' => 'X',
            'name' => 'x',
            'type' => 'NOT_A_REAL_TYPE',
            'allow_in_bindings' => 0
        ];
        $result = $this->validator->validate(
            'https://schemas.parisek.dev/acf/refs/field.schema.json',
            $field
        );
        $this->assertFalse($result->isValid(), 'unknown field type must fail enum');
    }

    public function test_image_field_return_format_url_fails(): void {
        $field = (object) [
            'return_format' => 'url'
        ];
        $result = $this->validator->validate(
            'https://schemas.parisek.dev/acf/refs/field-image.schema.json',
            $field
        );
        $this->assertFalse($result->isValid(), 'image must reject return_format=url');
    }

    public function test_image_field_return_format_array_passes(): void {
        $field = (object) [
            'return_format' => 'array',
            'preview_size' => 'medium',
            'wpml_cf_preferences' => 1
        ];
        $result = $this->validator->validate(
            'https://schemas.parisek.dev/acf/refs/field-image.schema.json',
            $field
        );
        $this->assertTrue($result->isValid(), 'errors: ' . json_encode($this->validator->formatErrors($result)));
    }

    public function test_link_field_return_format_array_passes(): void {
        $result = $this->validator->validate(
            'https://schemas.parisek.dev/acf/refs/field-link.schema.json',
            (object) ['return_format' => 'array']
        );
        $this->assertTrue($result->isValid());
    }

    public function test_link_field_return_format_url_passes(): void {
        $result = $this->validator->validate(
            'https://schemas.parisek.dev/acf/refs/field-link.schema.json',
            (object) ['return_format' => 'url']
        );
        $this->assertTrue($result->isValid());
    }

    public function test_select_field_value_return_format_passes(): void {
        $field = (object) [
            'return_format' => 'value',
            'choices' => (object) ['a' => 'A', 'b' => 'B'],
            'multiple' => 0
        ];
        $result = $this->validator->validate(
            'https://schemas.parisek.dev/acf/refs/field-select.schema.json',
            $field
        );
        $this->assertTrue($result->isValid(), 'errors: ' . json_encode($this->validator->formatErrors($result)));
    }

    public function test_select_field_unknown_return_format_fails(): void {
        $field = (object) ['return_format' => 'BAD'];
        $result = $this->validator->validate(
            'https://schemas.parisek.dev/acf/refs/field-select.schema.json',
            $field
        );
        $this->assertFalse($result->isValid());
    }

    public function test_gallery_field_url_return_format_fails(): void {
        $field = (object) ['return_format' => 'url'];
        $result = $this->validator->validate(
            'https://schemas.parisek.dev/acf/refs/field-gallery.schema.json',
            $field
        );
        $this->assertFalse($result->isValid(), 'gallery must require return_format const "array"');
    }

    public function test_flexible_content_with_layouts_passes(): void {
        $field = (object) [
            'layouts' => [
                (object) [
                    'key' => 'layout_hero',
                    'name' => 'hero',
                    'label' => 'Hero',
                    'sub_fields' => [
                        (object) ['key' => 'field_title', 'label' => 'Title', 'name' => 'title', 'type' => 'text', 'allow_in_bindings' => 0]
                    ]
                ]
            ]
        ];
        $result = $this->validator->validate(
            'https://schemas.parisek.dev/acf/refs/field-flexible_content.schema.json',
            $field
        );
        $this->assertTrue($result->isValid(), 'errors: ' . json_encode($this->validator->formatErrors($result)));
    }

    public function test_flexible_content_layout_missing_name_fails(): void {
        $field = (object) [
            'layouts' => [
                (object) [
                    'key' => 'layout_hero',
                    'label' => 'Hero'
                ]
            ]
        ];
        $result = $this->validator->validate(
            'https://schemas.parisek.dev/acf/refs/field-flexible_content.schema.json',
            $field
        );
        $this->assertFalse($result->isValid(), 'layout missing required name must fail');
    }

    public function test_repeater_with_sub_fields_passes(): void {
        $field = (object) [
            'sub_fields' => [
                (object) ['key' => 'field_x', 'label' => 'X', 'name' => 'x', 'type' => 'text', 'allow_in_bindings' => 0]
            ],
            'layout' => 'block'
        ];
        $result = $this->validator->validate(
            'https://schemas.parisek.dev/acf/refs/field-repeater.schema.json',
            $field
        );
        $this->assertTrue($result->isValid(), 'errors: ' . json_encode($this->validator->formatErrors($result)));
    }

    public function test_field_group_minimal_passes(): void {
        $data = (object) [
            'key' => 'group_test',
            'title' => 'Test Group',
            'fields' => [
                (object) [
                    'key' => 'field_title',
                    'label' => 'Title',
                    'name' => 'title',
                    'type' => 'text',
                    'allow_in_bindings' => 0
                ]
            ],
            'location' => [
                [ (object) ['param' => 'post_type', 'operator' => '==', 'value' => 'page'] ]
            ],
            'menu_order' => 0,
            'active' => true,
            'modified' => 1716000000,
            'acfml_field_group_mode' => 'advanced'
        ];
        $result = $this->validator->validate(
            'https://schemas.parisek.dev/acf/acf.schema.json',
            $data
        );
        $this->assertTrue($result->isValid(), 'minimal field group must pass. Errors: ' . json_encode($this->validator->formatErrors($result)));
    }

    public function test_field_group_image_with_url_return_format_fails(): void {
        $data = (object) [
            'key' => 'group_test',
            'title' => 'Test',
            'fields' => [
                (object) [
                    'key' => 'field_img',
                    'label' => 'Image',
                    'name' => 'image',
                    'type' => 'image',
                    'allow_in_bindings' => 0,
                    'return_format' => 'url'
                ]
            ],
            'location' => [
                [ (object) ['param' => 'post_type', 'operator' => '==', 'value' => 'page'] ]
            ],
            'modified' => 1716000000,
            'active' => true,
            'acfml_field_group_mode' => 'advanced'
        ];
        $result = $this->validator->validate(
            'https://schemas.parisek.dev/acf/acf.schema.json',
            $data
        );
        $this->assertFalse($result->isValid(), 'image with return_format:url must fail');
    }

    public function test_cpt_minimal_passes(): void {
        $data = (object) [
            'key' => 'post_type_project',
            'title' => 'Projects',
            'post_type' => 'project',
            'menu_icon' => 'dashicons-portfolio',
            'active' => true,
            'public' => true,
            'menu_position' => 25,
            'taxonomies' => [],
            'supports' => ['title', 'editor', 'revisions']
        ];
        $result = $this->validator->validate(
            'https://schemas.parisek.dev/acf/cpt.schema.json',
            $data
        );
        $this->assertTrue($result->isValid(), 'errors: ' . json_encode($this->validator->formatErrors($result)));
    }

    public function test_cpt_fixture_menu_icon_junk_fails(): void {
        $path = __DIR__ . '/fixtures/invalid/menu_icon-junk-repr/cpt.json';
        $contents = file_get_contents($path);
        $this->assertIsString($contents, 'Fixture file must be readable: ' . $path);
        $data = json_decode($contents);
        $result = $this->validator->validate(
            'https://schemas.parisek.dev/acf/cpt.schema.json',
            $data
        );
        $this->assertFalse(
            $result->isValid(),
            'menu_icon junk Python repr must fail cpt.schema.json. Fixture: ' . $path
        );
    }

    public function test_taxonomy_minimal_passes(): void {
        $data = (object) [
            'key' => 'taxonomy_project_category',
            'title' => 'Project Categories',
            'taxonomy' => 'project_category',
            'object_type' => ['project'],
            'active' => true,
            'hierarchical' => true
        ];
        $result = $this->validator->validate(
            'https://schemas.parisek.dev/acf/taxonomy.schema.json',
            $data
        );
        $this->assertTrue($result->isValid(), 'errors: ' . json_encode($this->validator->formatErrors($result)));
    }

    public function test_block_minimal_passes(): void {
        $data = (object) [
            'apiVersion' => 3,
            'name' => 'acf/hero',
            'title' => 'Hero',
            'category' => 'theme',
            'icon' => 'dashicons-star-filled',
            'supports' => (object) ['align' => ['full']],
            'example' => null,
            'acf' => (object) [
                'mode' => 'preview',
                'renderCallback' => 'timber_block_render_callback',
                'postTypes' => ['page']
            ]
        ];
        $result = $this->validator->validate(
            'https://schemas.parisek.dev/acf/block.schema.json',
            $data
        );
        $this->assertTrue($result->isValid(), 'errors: ' . json_encode($this->validator->formatErrors($result)));
    }

    public function test_block_icon_svg_without_currentColor_fails(): void {
        $data = (object) [
            'apiVersion' => 3,
            'name' => 'acf/x',
            'title' => 'X',
            'category' => 'theme',
            'icon' => '<svg fill="#000"><path d=""/></svg>',
            'supports' => (object) [],
            'example' => null,
            'acf' => (object) ['mode' => 'preview', 'renderCallback' => 'r']
        ];
        $result = $this->validator->validate(
            'https://schemas.parisek.dev/acf/block.schema.json',
            $data
        );
        $this->assertFalse($result->isValid(), 'block icon SVG must contain currentColor');
    }
}
