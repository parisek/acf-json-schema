<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Tests;

use Parisek\AcfJsonSchema\Emit\SchemaEmitter;
use PHPUnit\Framework\TestCase;

/**
 * Cheap, WordPress-free guards that run in CI (unlike SnapshotTest, which is
 * skipped without a live WP install). They lock in two invariants the generator
 * relies on but cannot enforce at runtime.
 */
final class SchemaConsistencyTest extends TestCase {

    private const TEMPLATES_REFS = __DIR__ . '/../src/templates/refs';
    private const DIST_REFS       = __DIR__ . '/../schemas/refs';

    /**
     * Every per-type ref shipped in src/templates/refs/ must have a matching
     * entry in SchemaEmitter::FIELD_TYPE_ORDER, and vice versa. A ref without an
     * order entry gets no discriminator branch in acf.schema.json — its
     * constraints are never applied (the field type silently validates against
     * the base field schema only). An order entry without a ref would emit a
     * dangling $ref. This guard fires the moment the two drift.
     */
    public function test_field_type_order_matches_template_refs(): void {
        $refFiles = glob(self::TEMPLATES_REFS . '/field-*.schema.json') ?: [];
        $typesFromRefs = array_map(
            static fn (string $path): string =>
                (string) preg_replace('/^field-(.+)\.schema\.json$/', '$1', basename($path)),
            $refFiles,
        );
        sort($typesFromRefs);

        $typesFromOrder = (new SchemaEmitter())->fieldTypeOrder();
        sort($typesFromOrder);

        $this->assertSame(
            $typesFromOrder,
            $typesFromRefs,
            'FIELD_TYPE_ORDER and src/templates/refs/field-*.schema.json have diverged. '
            . 'Every field type needs both a ref file and an order entry — see SchemaEmitter::copyStaticRefs() workflow.'
        );
    }

    /**
     * src/templates/refs/ is the source of truth; schemas/refs/ is the committed
     * distribution copy that copyStaticRefs() reproduces. They must stay
     * byte-identical, otherwise a hand-edit to one tree ships unmirrored — and
     * SnapshotTest (the only other guard) is skipped in CI without a WP env.
     */
    public function test_template_refs_match_distribution_refs(): void {
        $templates = $this->collectRefs(self::TEMPLATES_REFS);
        $dist      = $this->collectRefs(self::DIST_REFS);

        $this->assertSame(
            array_keys($templates),
            array_keys($dist),
            'The set of *.schema.json files in src/templates/refs/ and schemas/refs/ differs.'
        );

        foreach ($templates as $name => $contents) {
            $this->assertSame(
                $contents,
                $dist[$name],
                "schemas/refs/{$name} is not byte-identical to src/templates/refs/{$name}. "
                . 'Mirror the edit: src/templates/refs/ is the source of truth.'
            );
        }
    }

    /**
     * @return array<string, string> filename → file contents, sorted by name
     */
    private function collectRefs(string $dir): array {
        $out = [];
        foreach (glob("{$dir}/*.schema.json") ?: [] as $path) {
            $contents = file_get_contents($path);
            $this->assertNotFalse($contents, "Could not read {$path}");
            $out[basename($path)] = $contents;
        }
        ksort($out);
        return $out;
    }
}
