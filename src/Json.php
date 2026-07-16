<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema;

/**
 * The package's single JSON writer convention, shared by the schema generator
 * and the linter's --fix path so the two write paths can't drift apart.
 *
 * Canonical flags match ACF's own local-JSON export style (verified against
 * the real exports in tests/fixtures/valid/): 4-space pretty print, slashes
 * and unicode unescaped. Generator-written schemas are ASCII-only, so
 * JSON_UNESCAPED_UNICODE is byte-neutral there while keeping --fix rewrites
 * of ACF exports faithful to what ACF itself would write.
 */
final class Json {

    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /** @param array<int|string, mixed>|\stdClass $data */
    public static function encode(array|\stdClass $data, bool $pretty = true): string {
        $flags = self::FLAGS | ($pretty ? JSON_PRETTY_PRINT : 0);
        return json_encode($data, $flags | JSON_THROW_ON_ERROR) . "\n";
    }
}
