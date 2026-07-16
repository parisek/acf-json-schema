<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Tests;

use Parisek\AcfJsonSchema\Emit\SchemaEmitter;
use PHPUnit\Framework\TestCase;

/**
 * WP-free guard that the two generated root schemas committed in schemas/
 * (acf.schema.json + field-item.schema.json) match what SchemaEmitter emits.
 * SnapshotTest covers a full live-WP regeneration but is skipped in CI; this
 * keeps the emitter and the committed files from drifting without a WP env.
 */
final class EmitterConsistencyTest extends TestCase {

    private const SCHEMAS = __DIR__ . '/../schemas';

    /**
     * Mirror Generator::writeJson exactly (shared Json encoder).
     *
     * @param array<string, mixed> $data
     */
    private static function encode(array $data): string {
        return \Parisek\AcfJsonSchema\Json::encode($data);
    }

    public function test_committed_field_item_matches_emitter(): void {
        $expected = self::encode((new SchemaEmitter())->emitFieldItem());
        $actual = file_get_contents(self::SCHEMAS . '/field-item.schema.json');
        $this->assertSame($expected, $actual,
            'schemas/field-item.schema.json is stale — regenerate from SchemaEmitter::emitFieldItem().');
    }

    public function test_committed_acf_schema_matches_emitter(): void {
        $expected = self::encode((new SchemaEmitter())->emitAcfSchema());
        $actual = file_get_contents(self::SCHEMAS . '/acf.schema.json');
        $this->assertSame($expected, $actual,
            'schemas/acf.schema.json is stale — regenerate from SchemaEmitter::emitAcfSchema().');
    }
}
