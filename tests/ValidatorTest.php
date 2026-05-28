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
}
