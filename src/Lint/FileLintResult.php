<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Lint;

final class FileLintResult {

    /** @param array<int|string, mixed> $errors */
    public function __construct(
        public readonly string $path,
        public readonly ?string $kind,
        public readonly bool $valid,
        public readonly array $errors,
        public readonly bool $fixed,
        public readonly bool $skipped,
    ) {}

    /** Short kind label (e.g. "acf") derived from the schema $id, or null. */
    public static function kindFromSchemaId(?string $schemaId): ?string {
        if ($schemaId === null) {
            return null;
        }
        return str_replace('.schema.json', '', basename($schemaId));
    }
}
