<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Tests\Helpers;

use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\Validator as OpisValidator;

final class Validator {

    private OpisValidator $opis;

    public function __construct(string $schemasRoot) {
        $resolver = new SchemaResolver();
        // Map our $id base prefix to the on-disk path so $refs resolve.
        $resolver->registerPrefix(
            'https://schemas.parisek.dev/acf/',
            $schemasRoot
        );

        $this->opis = new OpisValidator();
        $this->opis->setMaxErrors(PHP_INT_MAX);
        $this->opis->setResolver($resolver);
    }

    public function validate(string $schemaId, mixed $data): ValidationResult {
        return $this->opis->validate($data, $schemaId);
    }

    /** @return array<string, mixed> */
    public function formatErrors(ValidationResult $result): array {
        $error = $result->error();
        if ($error === null) {
            return [];
        }
        $formatter = new ErrorFormatter();
        return $formatter->format($error, false);
    }
}
