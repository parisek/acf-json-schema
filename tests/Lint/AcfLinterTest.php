<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Tests\Lint;

use Parisek\AcfJsonSchema\Lint\AcfLinter;
use PHPUnit\Framework\TestCase;

final class AcfLinterTest extends TestCase {

    private AcfLinter $linter;

    protected function setUp(): void {
        $this->linter = new AcfLinter(__DIR__ . '/../../schemas');
    }

    private const BASE = 'https://schemas.parisek.dev/acf/';

    public function test_dispatch_block_by_filename(): void {
        self::assertSame(self::BASE . 'block.schema.json', $this->linter->dispatch('a/block.json', (object) []));
    }

    public function test_dispatch_acf_by_filename(): void {
        self::assertSame(self::BASE . 'acf.schema.json', $this->linter->dispatch('a/acf.json', (object) []));
    }

    public function test_dispatch_cpt_by_post_type(): void {
        self::assertSame(self::BASE . 'cpt.schema.json', $this->linter->dispatch('x/foo.json', (object) ['post_type' => 'event']));
    }

    public function test_dispatch_taxonomy_by_taxonomy_and_object_type(): void {
        $json = (object) ['taxonomy' => 'genre', 'object_type' => ['post']];
        self::assertSame(self::BASE . 'taxonomy.schema.json', $this->linter->dispatch('x/foo.json', $json));
    }

    public function test_dispatch_acf_by_shape(): void {
        $json = (object) ['fields' => [], 'location' => []];
        self::assertSame(self::BASE . 'acf.schema.json', $this->linter->dispatch('x/options.json', $json));
    }

    public function test_dispatch_unrecognized_returns_null(): void {
        self::assertNull($this->linter->dispatch('x/random.json', (object) ['foo' => 'bar']));
    }

    public function test_lintfile_valid_acf_fixture_passes(): void {
        $path = __DIR__ . '/../fixtures/valid/fellows/component-apartment-list/acf.json';
        $result = $this->linter->lintFile($path, false);
        self::assertSame('acf', $result->kind);
        self::assertTrue($result->valid, (string) json_encode($result->errors));
        self::assertFalse($result->skipped);
    }

    public function test_lintfile_unrecognized_is_skipped(): void {
        $tmp = sys_get_temp_dir() . '/acf-lint-skip-' . getmypid() . '.json';
        file_put_contents($tmp, '{"foo":"bar"}');
        try {
            $result = $this->linter->lintFile($tmp, false);
            self::assertTrue($result->skipped);
            self::assertNull($result->kind);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_lintfile_invalid_acf_reports_errors(): void {
        $dir = sys_get_temp_dir() . '/acf-lint-bad-acf-' . getmypid();
        @mkdir($dir);
        $file = $dir . '/acf.json';
        // acf.json by filename, but missing required keys → invalid.
        file_put_contents($file, '{"key":"group_x"}');
        try {
            $result = $this->linter->lintFile($file, false);
            self::assertSame('acf', $result->kind);
            self::assertFalse($result->valid);
            self::assertNotEmpty($result->errors);
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }

    public function test_fix_bumps_stale_modified(): void {
        $dir = sys_get_temp_dir() . '/acf-lint-fix-' . getmypid();
        @mkdir($dir);
        $file = $dir . '/foo.json';
        // CPT shape (post_type) with a pre-2020 modified → should be bumped.
        file_put_contents($file, (string) json_encode([
            'key' => 'post_type_x', 'title' => 'X', 'post_type' => 'x',
            'modified' => 0,
        ]));
        try {
            $before = time();
            $result = $this->linter->lintFile($file, true);
            self::assertTrue($result->fixed);
            $after = json_decode((string) file_get_contents($file));
            self::assertInstanceOf(\stdClass::class, $after);
            self::assertIsInt($after->modified);
            self::assertGreaterThanOrEqual($before, $after->modified);
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }
}
