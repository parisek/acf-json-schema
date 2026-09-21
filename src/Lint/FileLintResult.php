<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Lint;

final class FileLintResult {

    /**
     * `notices` are findings that never change `valid`, and therefore never
     * change the exit code under `--strict`. They exist for a rule that cannot
     * be decided from the file alone — the reader has to answer a question the
     * linter can only ask. An error a project cannot act on mechanically is an
     * error a project learns to ignore, which is worse than a notice.
     *
     * @param array<int|string, mixed> $errors
     * @param array<string, string>    $notices
     */
    public function __construct(
        public readonly string $path,
        public readonly ?string $kind,
        public readonly bool $valid,
        public readonly array $errors,
        public readonly bool $fixed,
        public readonly bool $skipped,
        public readonly array $notices = [],
    ) {}

    /** Short kind label (e.g. "acf") derived from the schema $id, or null. */
    public static function kindFromSchemaId(?string $schemaId): ?string {
        if ($schemaId === null) {
            return null;
        }
        return str_replace('.schema.json', '', basename($schemaId));
    }
}
