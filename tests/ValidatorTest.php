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
}
