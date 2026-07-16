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

    public function test_accepts_stdclass_documents(): void {
        $doc = json_decode('{"title": "Škola", "modified": 1}');
        $this->assertInstanceOf(\stdClass::class, $doc);
        $out = Json::encode($doc);
        $this->assertSame("{\n    \"title\": \"Škola\",\n    \"modified\": 1\n}\n", $out);
    }
}
