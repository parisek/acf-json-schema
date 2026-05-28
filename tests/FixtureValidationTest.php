<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Tests;

use Parisek\AcfJsonSchema\Tests\Helpers\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FixtureValidationTest extends TestCase {

    private const SCHEMAS_BASE = 'https://schemas.parisek.dev/acf/';

    private Validator $validator;

    protected function setUp(): void {
        $schemasRoot = realpath(__DIR__ . '/../schemas/');
        if ($schemasRoot === false) {
            $this->fail('schemas/ directory not found');
        }
        $this->validator = new Validator($schemasRoot . '/');
    }

    public static function validFixturesProvider(): iterable {
        $root = __DIR__ . '/fixtures/valid';
        if (!is_dir($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }
            yield $file->getPathname() => [$file->getPathname()];
        }
    }

    #[DataProvider('validFixturesProvider')]
    public function test_valid_fixture_passes_its_schema(string $path): void {
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, "Could not read {$path}");
        $data = json_decode($contents);
        $this->assertNotNull($data, "Fixture {$path} is not valid JSON");

        $schemaId = self::detectSchema($path, $data);
        $this->assertNotNull($schemaId, "Could not detect schema for {$path}");

        $result = $this->validator->validate($schemaId, $data);
        $this->assertTrue(
            $result->isValid(),
            "Fixture {$path} should pass {$schemaId}.\nErrors: " . json_encode($this->validator->formatErrors($result), JSON_PRETTY_PRINT)
        );
    }

    private static function detectSchema(string $path, mixed $data): ?string {
        if (isset($data->apiVersion) && isset($data->acf)) {
            return self::SCHEMAS_BASE . 'block.schema.json';
        }
        if (isset($data->key) && is_string($data->key)) {
            if (str_starts_with($data->key, 'group_'))     return self::SCHEMAS_BASE . 'acf.schema.json';
            if (str_starts_with($data->key, 'post_type_')) return self::SCHEMAS_BASE . 'cpt.schema.json';
            if (str_starts_with($data->key, 'taxonomy_'))  return self::SCHEMAS_BASE . 'taxonomy.schema.json';
        }
        return null;
    }

    public static function invalidFixturesProvider(): iterable {
        $root = __DIR__ . '/fixtures/invalid';
        if (!is_dir($root)) {
            return;
        }
        foreach (new \DirectoryIterator($root) as $dir) {
            if ($dir->isDot() || !$dir->isDir()) continue;
            $name = $dir->getFilename();
            $assertPath = $dir->getPathname() . '/assert.json';
            if (!file_exists($assertPath)) continue;
            yield $name => [$dir->getPathname()];
        }
    }

    #[DataProvider('invalidFixturesProvider')]
    public function test_invalid_fixture_fails_as_expected(string $fixtureDir): void {
        $assertContents = file_get_contents($fixtureDir . '/assert.json');
        $this->assertNotFalse($assertContents);
        $assert = json_decode($assertContents);
        $this->assertNotNull($assert, "assert.json malformed in {$fixtureDir}");

        $dataFiles = array_values(array_filter(
            glob($fixtureDir . '/*.json') ?: [],
            fn($f) => basename($f) !== 'assert.json'
        ));
        $this->assertCount(1, $dataFiles, "Expected exactly one data file in {$fixtureDir}");
        $dataContents = file_get_contents($dataFiles[0]);
        $this->assertNotFalse($dataContents);
        $data = json_decode($dataContents);

        $result = $this->validator->validate($assert->schema, $data);
        $this->assertFalse(
            $result->isValid(),
            "Invalid fixture {$fixtureDir} should fail {$assert->schema} but passed."
        );

        if (isset($assert->min_violations)) {
            $errors = $this->validator->formatErrors($result);
            $this->assertGreaterThanOrEqual(
                $assert->min_violations,
                count($errors),
                "Expected at least {$assert->min_violations} violations but got " . count($errors)
            );
        }
    }
}
