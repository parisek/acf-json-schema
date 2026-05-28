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
}
