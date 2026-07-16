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

    public function test_dispatch_block_with_acf_key(): void {
        $json = (object) ['name' => 'acf/hero', 'acf' => (object) ['mode' => 'preview']];
        self::assertSame(self::BASE . 'block.schema.json', $this->linter->dispatch('a/block.json', $json));
    }

    public function test_dispatch_block_with_explicit_null_acf_is_validated_not_skipped(): void {
        $json = (object) ['name' => 'acf/broken', 'acf' => null];
        self::assertSame(self::BASE . 'block.schema.json', $this->linter->dispatch('a/block.json', $json));
    }

    public function test_dispatch_skips_native_block_json_without_acf_key(): void {
        $json = (object) ['name' => 'core-ish/native', 'title' => 'Native block'];
        self::assertNull($this->linter->dispatch('a/block.json', $json));
    }

    public function test_lintfile_native_block_json_is_skipped_not_failed(): void {
        $tmp = sys_get_temp_dir() . '/block.json';
        file_put_contents($tmp, '{"apiVersion": 3, "name": "myplugin/native", "title": "Native"}');
        try {
            $result = $this->linter->lintFile($tmp, false);
            self::assertTrue($result->skipped);
            self::assertNull($result->kind);
        } finally {
            unlink($tmp);
        }
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

    public function test_max_errors_caps_reported_findings(): void {
        // 40 fields, each missing `type` → one finding per field uncapped.
        $fields = [];
        for ($i = 0; $i < 40; $i++) {
            $fields[] = ['key' => "field_{$i}", 'label' => 'A', 'name' => "a{$i}", 'allow_in_bindings' => 0];
        }
        $doc = [
            'key' => 'group_x', 'title' => 'T', 'fields' => $fields,
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
            'modified' => 1, 'active' => true,
        ];
        $dir = sys_get_temp_dir() . '/acf-cap-' . getmypid();
        @mkdir($dir);
        $file = $dir . '/acf.json';
        file_put_contents($file, (string) json_encode($doc));
        try {
            $capped = (new AcfLinter(__DIR__ . '/../../schemas', maxErrors: 5))->lintFile($file, false);
            $uncapped = (new AcfLinter(__DIR__ . '/../../schemas', maxErrors: 1000))->lintFile($file, false);
            self::assertFalse($capped->valid);
            self::assertNotEmpty($capped->errors);
            self::assertLessThan(count($uncapped->errors), count($capped->errors));
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
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

    public function test_collect_json_files_walks_dirs_and_ignores_vendor(): void {
        $root = __DIR__ . '/../fixtures/valid/fellows';
        $files = $this->linter->collectJsonFiles([$root]);
        self::assertNotEmpty($files);
        foreach ($files as $f) {
            self::assertStringEndsWith('.json', $f);
            self::assertStringNotContainsString('/vendor/', $f);
            self::assertStringNotContainsString('/node_modules/', $f);
        }
    }

    /**
     * Write $data as a temp acf.json, lint it, return the result.
     *
     * @param array<string, mixed> $data
     */
    private function lintAcf(array $data, bool $requireWpml): \Parisek\AcfJsonSchema\Lint\FileLintResult {
        $dir = sys_get_temp_dir() . '/acf-lint-wpml-' . getmypid() . '-' . substr(md5(serialize($data)), 0, 8);
        @mkdir($dir);
        $file = $dir . '/acf.json';
        file_put_contents($file, (string) json_encode($data));
        try {
            return $this->linter->lintFile($file, false, $requireWpml);
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }

    /**
     * @param array<string, mixed>                  $overrides
     * @param list<array<string, mixed>>|null        $fields
     * @return array<string, mixed> a structurally-valid field group (acfml/wpml omitted)
     */
    private static function group(array $overrides = [], ?array $fields = null): array {
        return array_merge([
            'key' => 'group_x', 'title' => 'T',
            'fields' => $fields ?? [
                ['key' => 'field_a', 'label' => 'A', 'name' => 'a', 'type' => 'text', 'allow_in_bindings' => 0],
            ],
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
            'modified' => 1, 'active' => true,
        ], $overrides);
    }

    public function test_wpml_off_missing_translation_keys_still_valid(): void {
        $r = $this->lintAcf(self::group(), false);
        self::assertTrue($r->valid, (string) json_encode($r->errors));
    }

    public function test_wpml_on_missing_acfml_mode_fails(): void {
        // field carries wpml, but group root lacks acfml_field_group_mode
        $r = $this->lintAcf(self::group(fields: [
            ['key' => 'field_a', 'label' => 'A', 'name' => 'a', 'type' => 'text', 'allow_in_bindings' => 0, 'wpml_cf_preferences' => 2],
        ]), true);
        self::assertFalse($r->valid);
        self::assertArrayHasKey('/acfml_field_group_mode', $r->errors);
    }

    public function test_wpml_on_missing_field_pref_fails(): void {
        $r = $this->lintAcf(self::group(['acfml_field_group_mode' => 'advanced']), true);
        self::assertFalse($r->valid);
        self::assertArrayHasKey('/fields/0/wpml_cf_preferences', $r->errors);
    }

    public function test_wpml_on_all_present_passes(): void {
        $r = $this->lintAcf(self::group(['acfml_field_group_mode' => 'advanced'], [
            ['key' => 'field_a', 'label' => 'A', 'name' => 'a', 'type' => 'text', 'allow_in_bindings' => 0, 'wpml_cf_preferences' => 2],
        ]), true);
        self::assertTrue($r->valid, (string) json_encode($r->errors));
    }

    public function test_wpml_recurses_into_repeater_sub_fields(): void {
        $r = $this->lintAcf(self::group(['acfml_field_group_mode' => 'advanced'], [
            [
                'key' => 'field_r', 'label' => 'R', 'name' => 'r', 'type' => 'repeater', 'allow_in_bindings' => 0,
                'wpml_cf_preferences' => 3,
                'sub_fields' => [
                    ['key' => 'field_s', 'label' => 'S', 'name' => 's', 'type' => 'text', 'allow_in_bindings' => 0],
                ],
            ],
        ]), true);
        self::assertFalse($r->valid);
        self::assertArrayHasKey('/fields/0/sub_fields/0/wpml_cf_preferences', $r->errors);
    }

    public function test_wpml_excludes_presentational_field_types(): void {
        // a `tab` (valueless) without wpml must NOT be flagged; the text field has it.
        $r = $this->lintAcf(self::group(['acfml_field_group_mode' => 'advanced'], [
            ['key' => 'field_t', 'label' => 'T', 'name' => 't', 'type' => 'tab', 'allow_in_bindings' => 0],
            ['key' => 'field_a', 'label' => 'A', 'name' => 'a', 'type' => 'text', 'allow_in_bindings' => 0, 'wpml_cf_preferences' => 2],
        ]), true);
        self::assertTrue($r->valid, (string) json_encode($r->errors));
    }

    public function test_wpml_ignores_non_acf_files(): void {
        // a CPT file — --wpml must not invent acfml/wpml findings here
        $dir = sys_get_temp_dir() . '/acf-lint-wpml-cpt-' . getmypid();
        @mkdir($dir);
        $file = $dir . '/foo.json';
        file_put_contents($file, (string) json_encode([
            'key' => 'post_type_x', 'title' => 'X', 'post_type' => 'x', 'active' => true,
        ]));
        try {
            $r = $this->linter->lintFile($file, false, true);
            self::assertSame('cpt', $r->kind);
            self::assertTrue($r->valid, (string) json_encode($r->errors));
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }

    public function test_whole_valid_corpus_lints_clean(): void {
        $root = __DIR__ . '/../fixtures/valid';
        $files = $this->linter->collectJsonFiles([$root]);
        $failures = [];
        foreach ($files as $f) {
            $r = $this->linter->lintFile($f, false);
            if (!$r->skipped && !$r->valid) {
                $failures[] = $r->path . ' → ' . json_encode($r->errors);
            }
        }
        self::assertSame([], $failures, "Valid corpus must lint clean:\n" . implode("\n", $failures));
    }
}
