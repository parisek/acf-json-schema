<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Tests;

use Parisek\AcfJsonSchema\Lint\AcfLinter;
use PHPUnit\Framework\TestCase;

/**
 * ACF 6.8's CPT UI offers `notes` and `post-formats` checkboxes and an
 * "Add Custom" input (allow_custom: true) whose comma-separated values are
 * merged verbatim into `supports` — plus the acf/post_type/available_supports
 * filter. `supports` is therefore an open set of strings, not a closed enum
 * (verified against the ACF 6.8.2 source, issue #18).
 */
final class CptSupportsTest extends TestCase {

    /** @param list<mixed> $supports */
    private function lintCpt(array $supports): \Parisek\AcfJsonSchema\Lint\FileLintResult {
        $doc = [
            'key' => 'post_type_x', 'title' => 'X', 'post_type' => 'x',
            'active' => true, 'supports' => $supports, 'modified' => 1700000000,
        ];
        $dir = sys_get_temp_dir() . '/acf-cpt-supports-' . getmypid();
        @mkdir($dir);
        $file = $dir . '/x.json';
        file_put_contents($file, (string) json_encode($doc));
        try {
            return (new AcfLinter(__DIR__ . '/../schemas'))->lintFile($file, false);
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }

    public function test_stock_ui_checkboxes_including_notes_and_post_formats_validate(): void {
        $r = $this->lintCpt([
            'title', 'editor', 'notes', 'thumbnail', 'author', 'trackbacks',
            'revisions', 'custom-fields', 'comments', 'excerpt',
            'page-attributes', 'post-formats',
        ]);
        self::assertTrue($r->valid, (string) json_encode($r->errors));
    }

    public function test_custom_supports_value_validates(): void {
        $r = $this->lintCpt(['title', 'my-custom-feature']);
        self::assertTrue($r->valid, (string) json_encode($r->errors));
    }

    public function test_non_string_supports_entry_is_rejected(): void {
        $r = $this->lintCpt(['title', 123]);
        self::assertFalse($r->valid);
        self::assertArrayHasKey('/supports/1', $r->errors, (string) json_encode($r->errors));
    }

    public function test_empty_string_supports_entry_is_rejected(): void {
        $r = $this->lintCpt(['title', '']);
        self::assertFalse($r->valid);
        self::assertArrayHasKey('/supports/1', $r->errors, (string) json_encode($r->errors));
    }
}
