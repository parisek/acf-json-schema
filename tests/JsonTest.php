<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Tests;

use Parisek\AcfJsonSchema\Json;
use PHPUnit\Framework\TestCase;

final class JsonTest extends TestCase {

    public function test_encodes_in_acf_export_style(): void {
        // Real ACF exports (see tests/fixtures/valid/) use 4-space pretty
        // print with unescaped slashes AND unescaped unicode.
        $out = Json::encode(['label' => 'Nadpis č. 1', 'url' => 'https://example.com/a']);
        $this->assertSame(
            "{\n    \"label\": \"Nadpis č. 1\",\n    \"url\": \"https://example.com/a\"\n}\n",
            $out,
        );
    }

    public function test_compact_mode_keeps_escaping_rules(): void {
        $out = Json::encode(['a' => 'č/x'], pretty: false);
        $this->assertSame("{\"a\":\"č/x\"}\n", $out);
    }

    public function test_fix_rewrite_preserves_acf_export_bytes(): void {
        $linter = new \Parisek\AcfJsonSchema\Lint\AcfLinter(__DIR__ . '/../schemas');
        $dir = sys_get_temp_dir() . '/acf-fix-bytes-' . getmypid();
        @mkdir($dir);
        $file = $dir . '/foo.json';
        // CPT shape with stale modified → --fix rewrites the whole file.
        file_put_contents($file, (string) json_encode([
            'key' => 'post_type_x', 'title' => 'Škola', 'post_type' => 'x',
            'description' => 'https://example.com/a', 'modified' => 0,
        ]));
        try {
            $result = $linter->lintFile($file, true);
            $this->assertTrue($result->fixed);
            $raw = (string) file_get_contents($file);
            $this->assertStringContainsString('"Škola"', $raw, 'unicode must stay unescaped');
            $this->assertStringContainsString('https://example.com/a', $raw, 'slashes must stay unescaped');
            $this->assertStringContainsString("{\n    \"key\"", $raw, '4-space pretty print');
            $this->assertStringEndsWith("}\n", $raw, 'trailing newline');
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }

    public function test_accepts_stdclass_documents(): void {
        $doc = json_decode('{"title": "Škola", "modified": 1}');
        $this->assertInstanceOf(\stdClass::class, $doc);
        $out = Json::encode($doc);
        $this->assertSame("{\n    \"title\": \"Škola\",\n    \"modified\": 1\n}\n", $out);
    }
}
