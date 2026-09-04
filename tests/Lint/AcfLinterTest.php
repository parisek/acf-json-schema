<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Tests\Lint;

use Parisek\AcfJsonSchema\Lint\AcfLinter;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * PR #29 review finding — `enum: [1, 2]` on image/gallery fixed the
     * options-page false positive but opened a false negative in the FAR
     * more common post/block context: an image field mistakenly set to
     * `wpml_cf_preferences: 2` (the options-page-only value) now
     * validates silently under a `post_type` location, and translators
     * lose per-language image swapping. Nothing in the schema (which is
     * deliberately context-free — see field-image.schema.json's own
     * description) catches this; it must be the --wpml linter's job,
     * since only the linter sees `location` alongside `fields`.
     */
    public function test_wpml_on_image_field_with_options_page_value_under_post_type_location_fails(): void {
        $r = $this->lintAcf(self::group(['acfml_field_group_mode' => 'advanced'], [
            [
                'key' => 'field_img', 'label' => 'Img', 'name' => 'img', 'type' => 'image',
                'allow_in_bindings' => 0, 'return_format' => 'array', 'wpml_cf_preferences' => 2,
            ],
        ]), true);
        self::assertFalse($r->valid);
        self::assertArrayHasKey('/fields/0/wpml_cf_preferences', $r->errors);
    }

    /**
     * Sanity control — the canonical value for a post/block-context image
     * field (1) must keep passing; the new check must not be over-broad.
     */
    public function test_wpml_on_image_field_with_post_type_value_under_post_type_location_passes(): void {
        $r = $this->lintAcf(self::group(['acfml_field_group_mode' => 'advanced'], [
            [
                'key' => 'field_img', 'label' => 'Img', 'name' => 'img', 'type' => 'image',
                'allow_in_bindings' => 0, 'return_format' => 'array', 'wpml_cf_preferences' => 1,
            ],
        ]), true);
        self::assertTrue($r->valid, (string) json_encode($r->errors));
    }

    /**
     * Same false-negative class, gallery field, `block` location instead
     * of `post_type` — the "more common" context isn't limited to CPTs.
     */
    public function test_wpml_on_gallery_field_with_options_page_value_under_block_location_fails(): void {
        $r = $this->lintAcf(self::group([
            'acfml_field_group_mode' => 'advanced',
            'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'acf/hero']]],
        ], [
            [
                'key' => 'field_gal', 'label' => 'Gal', 'name' => 'gal', 'type' => 'gallery',
                'allow_in_bindings' => 0, 'return_format' => 'array', 'wpml_cf_preferences' => 2,
            ],
        ]), true);
        self::assertFalse($r->valid);
        self::assertArrayHasKey('/fields/0/wpml_cf_preferences', $r->errors);
    }

    /**
     * The documented, legitimate options-page case must keep passing —
     * this is the false positive PR #29 already fixed; the new
     * location-aware check must not regress it.
     */
    public function test_wpml_on_image_field_with_options_page_value_under_options_page_location_passes(): void {
        $r = $this->lintAcf(self::group([
            'acfml_field_group_mode' => 'advanced',
            'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => 'options_social']]],
        ], [
            [
                'key' => 'field_img', 'label' => 'Img', 'name' => 'img', 'type' => 'image',
                'allow_in_bindings' => 0, 'return_format' => 'array', 'wpml_cf_preferences' => 2,
            ],
        ]), true);
        self::assertTrue($r->valid, (string) json_encode($r->errors));
    }

    /**
     * Inverse of the options-page case — value 1 (the post/block-context
     * value) under an options_page-only location is the mirror-image
     * mistake (ACFML permanently locks it to the default-language value
     * with no post to copy from), so it's flagged too, for the same
     * reason the forward direction is.
     */
    public function test_wpml_on_image_field_with_post_type_value_under_options_page_location_fails(): void {
        $r = $this->lintAcf(self::group([
            'acfml_field_group_mode' => 'advanced',
            'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => 'options_social']]],
        ], [
            [
                'key' => 'field_img', 'label' => 'Img', 'name' => 'img', 'type' => 'image',
                'allow_in_bindings' => 0, 'return_format' => 'array', 'wpml_cf_preferences' => 1,
            ],
        ]), true);
        self::assertFalse($r->valid);
        self::assertArrayHasKey('/fields/0/wpml_cf_preferences', $r->errors);
    }

    /**
     * Copy-once (3) is a legitimate post/block value for an image the editor
     * RE-AUTHORS per language — an e-book cover with the headline baked into
     * the artwork, a localised screenshot. Copy (1) re-syncs from the default
     * language on every save and overwrites that work, so demanding 1 here
     * does not merely warn: it prescribes an edit that destroys the
     * translated asset.
     *
     * Found downstream on `sloneek`, where five image fields sat on 3 and the
     * rule left the project unable to be lint-clean without losing
     * per-language e-book covers. The package has no ignore mechanism, so
     * "silence it locally" was not available either.
     */
    public function test_wpml_on_image_field_with_copy_once_under_post_type_location_passes(): void {
        $r = $this->lintAcf(self::group(['acfml_field_group_mode' => 'advanced'], [
            [
                'key' => 'field_img', 'label' => 'Img', 'name' => 'img', 'type' => 'image',
                'allow_in_bindings' => 0, 'return_format' => 'array', 'wpml_cf_preferences' => 3,
            ],
        ]), true);
        self::assertTrue($r->valid, (string) json_encode($r->errors));
    }

    /** Same allowance for a gallery, under a `block` location. */
    public function test_wpml_on_gallery_field_with_copy_once_under_block_location_passes(): void {
        $r = $this->lintAcf(self::group([
            'acfml_field_group_mode' => 'advanced',
            'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'acf/hero']]],
        ], [
            [
                'key' => 'field_gal', 'label' => 'Gal', 'name' => 'gal', 'type' => 'gallery',
                'allow_in_bindings' => 0, 'return_format' => 'array', 'wpml_cf_preferences' => 3,
            ],
        ]), true);
        self::assertTrue($r->valid, (string) json_encode($r->errors));
    }

    /**
     * Copy-once must be available to an image ANYWHERE it can legitimately be
     * re-authored per language, not only under post_type/block — a menu item's
     * icon, a term image, a user field.
     *
     * It reaches those through the schema, not through this check: `taxonomy`,
     * `nav_menu_item`, `user_form`, `widget` and `nav_menu` classify as
     * 'other', so classifyLocationContext() returns null and the location
     * cross-check never runs for them. The `enum` is what governs there, and it
     * has no notion of location. Pinned here because the two halves of this fix
     * are easy to conflate, and a future narrowing of the enum would break menus
     * silently — nothing else in the suite covers an image outside the two
     * classified contexts.
     */
    public function test_wpml_on_image_field_with_copy_once_under_nav_menu_location_passes(): void {
        $r = $this->lintAcf(self::group([
            'acfml_field_group_mode' => 'advanced',
            'location' => [[['param' => 'nav_menu_item', 'operator' => '==', 'value' => 'all']]],
        ], [
            [
                'key' => 'field_img', 'label' => 'Img', 'name' => 'img', 'type' => 'image',
                'allow_in_bindings' => 0, 'return_format' => 'array', 'wpml_cf_preferences' => 3,
            ],
        ]), true);
        self::assertTrue($r->valid, (string) json_encode($r->errors));
    }

    /**
     * The allowance is context-scoped, not blanket. An Options Page has no
     * post duplication, so copy-once has nothing to seed a translation FROM —
     * 2 remains the only value that holds a per-language value there, and 3
     * must still be flagged.
     */
    public function test_wpml_on_image_field_with_copy_once_under_options_page_location_fails(): void {
        $r = $this->lintAcf(self::group([
            'acfml_field_group_mode' => 'advanced',
            'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => 'options_social']]],
        ], [
            [
                'key' => 'field_img', 'label' => 'Img', 'name' => 'img', 'type' => 'image',
                'allow_in_bindings' => 0, 'return_format' => 'array', 'wpml_cf_preferences' => 3,
            ],
        ]), true);
        self::assertFalse($r->valid);
        self::assertArrayHasKey('/fields/0/wpml_cf_preferences', $r->errors);
    }

    /**
     * The message must describe the value that was ACTUALLY found. Before
     * this, every post/block finding read "value 2 is the options-page-only
     * carve-out" — including for a field carrying 0, which was then told why a
     * value it does not have is wrong.
     */
    public function test_wpml_image_location_message_names_the_offending_value(): void {
        $r = $this->lintAcf(self::group(['acfml_field_group_mode' => 'advanced'], [
            [
                'key' => 'field_img', 'label' => 'Img', 'name' => 'img', 'type' => 'image',
                'allow_in_bindings' => 0, 'return_format' => 'array', 'wpml_cf_preferences' => 0,
            ],
        ]), true);
        self::assertFalse($r->valid);
        $message = $r->errors['/fields/0/wpml_cf_preferences'] ?? '';
        self::assertStringContainsString('ignore (0)', $message);
        self::assertStringNotContainsString('value 2 is the options-page-only', $message);
    }

    /**
     * Mixed location (an OR-group targeting BOTH an options page and a
     * post type) is genuinely ambiguous — there is no single "correct"
     * value the linter can demand without false-flagging a legitimate
     * dual-context field group. Must NOT be flagged either way.
     */
    public function test_wpml_on_image_field_under_mixed_location_is_not_flagged_for_value(): void {
        $r = $this->lintAcf(self::group([
            'acfml_field_group_mode' => 'advanced',
            'location' => [
                [['param' => 'post_type', 'operator' => '==', 'value' => 'post']],
                [['param' => 'options_page', 'operator' => '==', 'value' => 'options_social']],
            ],
        ], [
            [
                'key' => 'field_img', 'label' => 'Img', 'name' => 'img', 'type' => 'image',
                'allow_in_bindings' => 0, 'return_format' => 'array', 'wpml_cf_preferences' => 2,
            ],
        ]), true);
        self::assertTrue($r->valid, (string) json_encode($r->errors));
    }

    /**
     * Nested image field (inside a repeater) must be checked against the
     * FIELD GROUP's own root location, not skipped just because it's not
     * top-level — the bug class applies equally to a repeater's gallery
     * sub-field.
     */
    public function test_wpml_on_nested_image_field_in_repeater_is_checked_against_root_location(): void {
        $r = $this->lintAcf(self::group(['acfml_field_group_mode' => 'advanced'], [
            [
                'key' => 'field_r', 'label' => 'R', 'name' => 'r', 'type' => 'repeater',
                'allow_in_bindings' => 0, 'wpml_cf_preferences' => 3,
                'sub_fields' => [
                    [
                        'key' => 'field_img', 'label' => 'Img', 'name' => 'img', 'type' => 'image',
                        'allow_in_bindings' => 0, 'return_format' => 'array', 'wpml_cf_preferences' => 2,
                    ],
                ],
            ],
        ]), true);
        self::assertFalse($r->valid);
        self::assertArrayHasKey('/fields/0/sub_fields/0/wpml_cf_preferences', $r->errors);
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

    /**
     * classifyLocationContext() regression — a heterogeneous OR group
     * mixing `options_page` with an UNRECOGNIZED param (`taxonomy`) was
     * misclassified as pure `options_page` context, because the
     * classifier only tracks `options_page` vs `post_type`/`block` and
     * is blind to every other ACF `param` (`taxonomy`, `nav_menu_item`,
     * `user_form`, `attachment`, `widget`, `comment`, `page_template`,
     * …). Per the method's own documented policy — a group genuinely
     * targeting BOTH an options page and a post type is left alone,
     * because there is no single correct value to demand — the same
     * must hold for `options_page` + `taxonomy`: no single correct
     * value, so the check must NOT fire.
     */
    public function test_wpml_on_image_field_under_options_page_or_taxonomy_location_is_not_flagged_for_value(): void {
        // Value 1 is deliberate — before the fix, the classifier saw
        // `options_page` and ignored the unrecognized `taxonomy` param,
        // collapsed to pure `options_page` context, and demanded value 2
        // — wrongly flagging this legitimate dual-context field group.
        $r = $this->lintAcf(self::group([
            'acfml_field_group_mode' => 'advanced',
            'location' => [
                [['param' => 'options_page', 'operator' => '==', 'value' => 'options_social']],
                [['param' => 'taxonomy', 'operator' => '==', 'value' => 'category']],
            ],
        ], [
            [
                'key' => 'field_img', 'label' => 'Img', 'name' => 'img', 'type' => 'image',
                'allow_in_bindings' => 0, 'return_format' => 'array', 'wpml_cf_preferences' => 1,
            ],
        ]), true);
        self::assertTrue($r->valid, (string) json_encode($r->errors));
    }

    /**
     * Same ambiguity, mirrored on the post_type/block side — an
     * unrecognized param (`user_form`) alongside `post_type` must also
     * be left alone, not silently classified as pure `post_type_or_block`.
     */
    public function test_wpml_on_image_field_under_post_type_or_user_form_location_is_not_flagged_for_value(): void {
        $r = $this->lintAcf(self::group([
            'acfml_field_group_mode' => 'advanced',
            'location' => [
                [['param' => 'post_type', 'operator' => '==', 'value' => 'post']],
                [['param' => 'user_form', 'operator' => '==', 'value' => 'all']],
            ],
        ], [
            [
                'key' => 'field_img', 'label' => 'Img', 'name' => 'img', 'type' => 'image',
                'allow_in_bindings' => 0, 'return_format' => 'array', 'wpml_cf_preferences' => 2,
            ],
        ]), true);
        self::assertTrue($r->valid, (string) json_encode($r->errors));
    }

    /**
     * Passing-control — pure `options_page` location (no other params
     * at all) must still flag value 1 as wrong. Proves the fix isn't a
     * blanket silencer that stops single-context detection from working.
     */
    public function test_wpml_on_image_field_with_post_type_value_under_pure_options_page_location_fails(): void {
        $r = $this->lintAcf(self::group([
            'acfml_field_group_mode' => 'advanced',
            'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => 'options_social']]],
        ], [
            [
                'key' => 'field_img', 'label' => 'Img', 'name' => 'img', 'type' => 'image',
                'allow_in_bindings' => 0, 'return_format' => 'array', 'wpml_cf_preferences' => 1,
            ],
        ]), true);
        self::assertFalse($r->valid);
        self::assertArrayHasKey('/fields/0/wpml_cf_preferences', $r->errors);
    }

    /**
     * Passing-control — pure `block` location must still flag value 2 as
     * wrong. Same rationale as above, mirrored on the post/block side.
     */
    public function test_wpml_on_image_field_with_options_page_value_under_pure_block_location_fails(): void {
        $r = $this->lintAcf(self::group([
            'acfml_field_group_mode' => 'advanced',
            'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'acf/hero']]],
        ], [
            [
                'key' => 'field_img', 'label' => 'Img', 'name' => 'img', 'type' => 'image',
                'allow_in_bindings' => 0, 'return_format' => 'array', 'wpml_cf_preferences' => 2,
            ],
        ]), true);
        self::assertFalse($r->valid);
        self::assertArrayHasKey('/fields/0/wpml_cf_preferences', $r->errors);
    }

    /**
     * Regression for the 15b3ca6 fix — `$hasOther` was a single GLOBAL
     * flag, so `post_type` AND `page_template` inside the SAME OR-group
     * (a very common real ACF shape: a field group scoped to a post type
     * AND a specific page template) got misclassified as ambiguous and
     * silently skipped. `page_template` is a post-context qualifier, not
     * a competing context — it must not blind the classifier to the
     * `post_type` it's paired with in the same AND-group.
     */
    public function test_wpml_on_image_field_under_post_type_and_page_template_single_group_fails(): void {
        $r = $this->lintAcf(self::group([
            'acfml_field_group_mode' => 'advanced',
            'location' => [
                [
                    ['param' => 'post_type', 'operator' => '==', 'value' => 'page'],
                    ['param' => 'page_template', 'operator' => '==', 'value' => 'templates/homepage.php'],
                ],
            ],
        ], [
            [
                'key' => 'field_img', 'label' => 'Img', 'name' => 'img', 'type' => 'image',
                'allow_in_bindings' => 0, 'return_format' => 'array', 'wpml_cf_preferences' => 2,
            ],
        ]), true);
        self::assertFalse($r->valid);
        self::assertArrayHasKey('/fields/0/wpml_cf_preferences', $r->errors);
    }

    /**
     * Same regression, `post_status` instead of `page_template` — another
     * common single-group AND qualifier that must not blind the
     * classifier to the `post_type` it's paired with.
     */
    public function test_wpml_on_image_field_under_post_type_and_post_status_single_group_fails(): void {
        $r = $this->lintAcf(self::group([
            'acfml_field_group_mode' => 'advanced',
            'location' => [
                [
                    ['param' => 'post_type', 'operator' => '==', 'value' => 'post'],
                    ['param' => 'post_status', 'operator' => '==', 'value' => 'publish'],
                ],
            ],
        ], [
            [
                'key' => 'field_img', 'label' => 'Img', 'name' => 'img', 'type' => 'image',
                'allow_in_bindings' => 0, 'return_format' => 'array', 'wpml_cf_preferences' => 2,
            ],
        ]), true);
        self::assertFalse($r->valid);
        self::assertArrayHasKey('/fields/0/wpml_cf_preferences', $r->errors);
    }

    // ---------------------------------------------------------------
    // Issue #30 — type -> value check (Layers 1-3)
    // ---------------------------------------------------------------

    /**
     * Layer 1 — `repeater` / `flexible_content` are forcibly overridden to
     * `3` at runtime by ACFML (`Helper\Fields::WRAPPER_FIELDS` +
     * `field_should_be_set_to_copy_once()`). Any other configured value is
     * provably dead. Value `3` must stay silent.
     */
    public function test_wpml_repeater_with_value_3_passes(): void {
        $r = $this->lintAcf(self::group(['acfml_field_group_mode' => 'advanced'], [
            [
                'key' => 'field_r', 'label' => 'R', 'name' => 'r', 'type' => 'repeater',
                'allow_in_bindings' => 0, 'wpml_cf_preferences' => 3,
                'sub_fields' => [],
            ],
        ]), true);
        self::assertTrue($r->valid, (string) json_encode($r->errors));
    }

    public function test_wpml_repeater_with_value_1_passes(): void {
        // 1 (copy) is correct when the rows are identical in every language.
        // 3 (copy once) omits the row-count meta ACF reads first, which empties
        // the field on every translation -- the defect behind #39.
        $r = $this->lintAcf(self::group(['acfml_field_group_mode' => 'advanced'], [
            [
                'key' => 'field_r', 'label' => 'R', 'name' => 'r', 'type' => 'repeater',
                'allow_in_bindings' => 0, 'wpml_cf_preferences' => 1,
                'sub_fields' => [],
            ],
        ]), true);
        self::assertTrue($r->valid, (string) json_encode($r->errors));
    }

    public function test_wpml_flexible_content_with_value_1_passes(): void {
        $r = $this->lintAcf(self::group(['acfml_field_group_mode' => 'advanced'], [
            [
                'key' => 'field_fc', 'label' => 'FC', 'name' => 'fc', 'type' => 'flexible_content',
                'allow_in_bindings' => 0, 'wpml_cf_preferences' => 1,
                'layouts' => [],
            ],
        ]), true);
        self::assertTrue($r->valid, (string) json_encode($r->errors));
    }

    /** @return iterable<string, array{0: int|null}> */
    public static function wrongRepeaterValues(): iterable {
        yield 'zero' => [0];
        yield 'two' => [2];
        yield 'absent' => [null];
        // 1 is deliberately absent: it is valid for a container whose rows are
        // identical in every language. See test_wpml_repeater_with_value_1_passes().
    }

    /**
     */
    #[DataProvider('wrongRepeaterValues')]
    public function test_wpml_repeater_with_wrong_value_fails(?int $value): void {
        $field = [
            'key' => 'field_r', 'label' => 'R', 'name' => 'r', 'type' => 'repeater',
            'allow_in_bindings' => 0, 'sub_fields' => [],
        ];
        if ($value !== null) {
            $field['wpml_cf_preferences'] = $value;
        }
        $r = $this->lintAcf(self::group(['acfml_field_group_mode' => 'advanced'], [$field]), true);
        self::assertFalse($r->valid, "value " . var_export($value, true) . " must be flagged for repeater");
        self::assertArrayHasKey('/fields/0/wpml_cf_preferences', $r->errors);
        self::assertStringContainsString('doctrine', $r->errors['/fields/0/wpml_cf_preferences']);
    }

    /**
     */
    #[DataProvider('wrongRepeaterValues')]
    public function test_wpml_flexible_content_with_wrong_value_fails(?int $value): void {
        $field = [
            'key' => 'field_fc', 'label' => 'FC', 'name' => 'fc', 'type' => 'flexible_content',
            'allow_in_bindings' => 0, 'layouts' => [],
        ];
        if ($value !== null) {
            $field['wpml_cf_preferences'] = $value;
        }
        $r = $this->lintAcf(self::group(['acfml_field_group_mode' => 'advanced'], [$field]), true);
        self::assertFalse($r->valid, "value " . var_export($value, true) . " must be flagged for flexible_content");
        self::assertArrayHasKey('/fields/0/wpml_cf_preferences', $r->errors);
    }

    public function test_wpml_flexible_content_with_value_3_passes(): void {
        $r = $this->lintAcf(self::group(['acfml_field_group_mode' => 'advanced'], [
            [
                'key' => 'field_fc', 'label' => 'FC', 'name' => 'fc', 'type' => 'flexible_content',
                'allow_in_bindings' => 0, 'wpml_cf_preferences' => 3, 'layouts' => [],
            ],
        ]), true);
        self::assertTrue($r->valid, (string) json_encode($r->errors));
    }

    /**
     * Layer 3 — UI pseudo-fields (`accordion`, `tab`, `message`) hold no
     * translatable value. `1` must be flagged.
     */
    public function test_wpml_accordion_with_value_1_fails(): void {
        $r = $this->lintAcf(self::group(['acfml_field_group_mode' => 'advanced'], [
            ['key' => 'field_acc', 'label' => 'Acc', 'name' => 'acc', 'type' => 'accordion', 'allow_in_bindings' => 0, 'wpml_cf_preferences' => 1],
        ]), true);
        self::assertFalse($r->valid);
        self::assertArrayHasKey('/fields/0/wpml_cf_preferences', $r->errors);
    }

    public function test_wpml_tab_with_value_1_fails(): void {
        $r = $this->lintAcf(self::group(['acfml_field_group_mode' => 'advanced'], [
            ['key' => 'field_tab', 'label' => 'Tab', 'name' => 'tab', 'type' => 'tab', 'allow_in_bindings' => 0, 'wpml_cf_preferences' => 1],
        ]), true);
        self::assertFalse($r->valid);
        self::assertArrayHasKey('/fields/0/wpml_cf_preferences', $r->errors);
    }

    public function test_wpml_message_with_value_1_fails(): void {
        $r = $this->lintAcf(self::group(['acfml_field_group_mode' => 'advanced'], [
            ['key' => 'field_msg', 'label' => 'Msg', 'name' => 'msg', 'type' => 'message', 'allow_in_bindings' => 0, 'wpml_cf_preferences' => 1],
        ]), true);
        self::assertFalse($r->valid);
        self::assertArrayHasKey('/fields/0/wpml_cf_preferences', $r->errors);
    }

    public function test_wpml_accordion_with_value_0_passes(): void {
        $r = $this->lintAcf(self::group(['acfml_field_group_mode' => 'advanced'], [
            ['key' => 'field_acc', 'label' => 'Acc', 'name' => 'acc', 'type' => 'accordion', 'allow_in_bindings' => 0, 'wpml_cf_preferences' => 0],
        ]), true);
        self::assertTrue($r->valid, (string) json_encode($r->errors));
    }

    public function test_wpml_accordion_absent_passes(): void {
        $r = $this->lintAcf(self::group(['acfml_field_group_mode' => 'advanced'], [
            ['key' => 'field_acc', 'label' => 'Acc', 'name' => 'acc', 'type' => 'accordion', 'allow_in_bindings' => 0],
        ]), true);
        self::assertTrue($r->valid, (string) json_encode($r->errors));
    }

    /**
     * Layer 2 — `group` should carry `3` per this project's own doctrine
     * (gutenberg.md: "`3` only on group containers whose nested leaves
     * carry their own preference"). ACFML does NOT force this — softer
     * certainty than layer 1, worded distinctly in the message.
     */
    public function test_wpml_group_with_value_3_passes(): void {
        $r = $this->lintAcf(self::group(['acfml_field_group_mode' => 'advanced'], [
            [
                'key' => 'field_g', 'label' => 'G', 'name' => 'g', 'type' => 'group',
                'allow_in_bindings' => 0, 'wpml_cf_preferences' => 3,
                'sub_fields' => [
                    ['key' => 'field_gs', 'label' => 'GS', 'name' => 'gs', 'type' => 'text', 'allow_in_bindings' => 0, 'wpml_cf_preferences' => 2],
                ],
            ],
        ]), true);
        self::assertTrue($r->valid, (string) json_encode($r->errors));
    }

    /** @return iterable<string, array{0: int|null}> */
    public static function wrongGroupValues(): iterable {
        yield 'zero' => [0];
        yield 'one' => [1];
        yield 'two' => [2];
        yield 'absent' => [null];
    }

    /**
     */
    #[DataProvider('wrongGroupValues')]
    public function test_wpml_group_with_wrong_value_fails(?int $value): void {
        $field = [
            'key' => 'field_g', 'label' => 'G', 'name' => 'g', 'type' => 'group',
            'allow_in_bindings' => 0,
            'sub_fields' => [
                ['key' => 'field_gs', 'label' => 'GS', 'name' => 'gs', 'type' => 'text', 'allow_in_bindings' => 0, 'wpml_cf_preferences' => 2],
            ],
        ];
        if ($value !== null) {
            $field['wpml_cf_preferences'] = $value;
        }
        $r = $this->lintAcf(self::group(['acfml_field_group_mode' => 'advanced'], [$field]), true);
        self::assertFalse($r->valid, "value " . var_export($value, true) . " must be flagged for group");
        self::assertArrayHasKey('/fields/0/wpml_cf_preferences', $r->errors);
        // Layer 2 message must read as project doctrine, not plugin fact.
        self::assertStringContainsString('doctrine', $r->errors['/fields/0/wpml_cf_preferences']);
    }

    /**
     * Layer 4 (OUT OF SCOPE) — leaf value types keep ONLY the pre-existing
     * presence check. Neither `1` nor `2` may produce a NEW finding.
     */
    public function test_wpml_leaf_text_field_with_value_1_stays_silent(): void {
        $r = $this->lintAcf(self::group(['acfml_field_group_mode' => 'advanced'], [
            ['key' => 'field_a', 'label' => 'A', 'name' => 'a', 'type' => 'text', 'allow_in_bindings' => 0, 'wpml_cf_preferences' => 1],
        ]), true);
        self::assertTrue($r->valid, (string) json_encode($r->errors));
    }

    public function test_wpml_leaf_text_field_with_value_2_stays_silent(): void {
        $r = $this->lintAcf(self::group(['acfml_field_group_mode' => 'advanced'], [
            ['key' => 'field_a', 'label' => 'A', 'name' => 'a', 'type' => 'text', 'allow_in_bindings' => 0, 'wpml_cf_preferences' => 2],
        ]), true);
        self::assertTrue($r->valid, (string) json_encode($r->errors));
    }

    /**
     * No double-reporting — an `image` field under an ambiguous (mixed)
     * location that would ALSO be a bad value for one of the new type
     * buckets can never happen because `image` never belongs to those
     * buckets (type-bucketed, mutually exclusive by construction). This
     * proves an image field flagged by the existing location-value check
     * gets exactly ONE finding, not two.
     */
    public function test_wpml_image_field_gets_single_finding_not_double_reported(): void {
        $r = $this->lintAcf(self::group([
            'acfml_field_group_mode' => 'advanced',
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
        ], [
            [
                'key' => 'field_img', 'label' => 'Img', 'name' => 'img', 'type' => 'image',
                'allow_in_bindings' => 0, 'return_format' => 'array', 'wpml_cf_preferences' => 2,
            ],
        ]), true);
        self::assertFalse($r->valid);
        // Exactly one finding for the whole field group.
        self::assertCount(1, $r->errors);
        self::assertArrayHasKey('/fields/0/wpml_cf_preferences', $r->errors);
    }

    /**
     * Nested coverage — a wrong-valued `repeater` nested inside a `group`
     * nested inside a `flexible_content` layout must still be found by the
     * walker (same recursive shape as the pre-existing presence check).
     */
    public function test_wpml_type_value_check_recurses_through_flexible_content_group_repeater(): void {
        $r = $this->lintAcf(self::group(['acfml_field_group_mode' => 'advanced'], [
            [
                'key' => 'field_fc', 'label' => 'FC', 'name' => 'fc', 'type' => 'flexible_content',
                'allow_in_bindings' => 0, 'wpml_cf_preferences' => 3,
                'layouts' => [
                    'layout_a' => [
                        'key' => 'layout_a', 'name' => 'layout_a', 'label' => 'Layout A',
                        'sub_fields' => [
                            [
                                'key' => 'field_g', 'label' => 'G', 'name' => 'g', 'type' => 'group',
                                'allow_in_bindings' => 0, 'wpml_cf_preferences' => 3,
                                'sub_fields' => [
                                    [
                                        // 2 rather than 1: this test proves the walker RECURSES,
                                        // so the innermost value only has to be an invalid one.
                                        // 1 became valid for containers in #39.
                                        'key' => 'field_r', 'label' => 'R', 'name' => 'r', 'type' => 'repeater',
                                        'allow_in_bindings' => 0, 'wpml_cf_preferences' => 2,
                                        'sub_fields' => [],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]), true);
        self::assertFalse($r->valid);
        self::assertArrayHasKey('/fields/0/layouts/layout_a/sub_fields/0/sub_fields/0/wpml_cf_preferences', $r->errors);
    }
}
