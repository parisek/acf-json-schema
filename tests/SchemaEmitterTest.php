<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Tests;

use Parisek\AcfJsonSchema\Emit\SchemaEmitter;
use PHPUnit\Framework\TestCase;

final class SchemaEmitterTest extends TestCase {

    public function test_field_missing_type_yields_single_required_error(): void {
        $linter = new \Parisek\AcfJsonSchema\Lint\AcfLinter(__DIR__ . '/../schemas');
        $dir = sys_get_temp_dir() . '/acf-no-type-' . getmypid();
        @mkdir($dir);
        $file = $dir . '/acf.json';
        file_put_contents($file, (string) json_encode([
            'key' => 'group_x', 'title' => 'T',
            'fields' => [['key' => 'field_a', 'label' => 'A', 'name' => 'a', 'allow_in_bindings' => 0]],
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
            'modified' => 1, 'active' => true,
        ]));
        try {
            $result = $linter->lintFile($file, false);
            $this->assertFalse($result->valid);
            $this->assertSame(
                ['/fields/0' => 'The required properties (type) are missing'],
                $result->errors,
                'a field without `type` must produce one clear error, not per-type branch noise',
            );
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }

    public function test_field_with_unknown_type_fails_via_base_enum(): void {
        $linter = new \Parisek\AcfJsonSchema\Lint\AcfLinter(__DIR__ . '/../schemas');
        $dir = sys_get_temp_dir() . '/acf-bad-type-' . getmypid();
        @mkdir($dir);
        $file = $dir . '/acf.json';
        file_put_contents($file, (string) json_encode([
            'key' => 'group_x', 'title' => 'T',
            'fields' => [['key' => 'field_a', 'label' => 'A', 'name' => 'a', 'type' => 'no_such_type', 'allow_in_bindings' => 0]],
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
            'modified' => 1, 'active' => true,
        ]));
        try {
            $result = $linter->lintFile($file, false);
            $this->assertFalse($result->valid);
            $this->assertArrayHasKey('/fields/0/type', $result->errors);
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }

    public function test_every_discriminator_branch_requires_type(): void {
        $fieldItem = (new SchemaEmitter())->emitFieldItem();
        $this->assertIsArray($fieldItem['allOf']);

        $branches = array_slice($fieldItem['allOf'], 1); // [0] is the base field.schema.json $ref
        $this->assertNotEmpty($branches);

        foreach ($branches as $i => $branch) {
            $this->assertIsArray($branch['if'], "branch {$i}");
            $this->assertSame(
                ['type'],
                $branch['if']['required'] ?? null,
                "branch {$i}: without required:[\"type\"], a field missing `type` matches every `if` vacuously and all then-refs are enforced at once",
            );
        }
    }
}
