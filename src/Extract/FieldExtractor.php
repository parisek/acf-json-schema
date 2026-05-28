<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Extract;

use Parisek\AcfJsonSchema\Ast\EnumChoicesExtractor;

final class FieldExtractor {

    private EnumChoicesExtractor $ast;

    public function __construct() {
        $this->ast = new EnumChoicesExtractor();
    }

    /** @return array<string, array<string, mixed>> Map type-name → schema array */
    public function emitAll(): array {
        /** @var array<string, \acf_field> $fieldTypes */
        $fieldTypes = acf_get_field_types();
        $schemas = [];
        foreach ($fieldTypes as $name => $instance) {
            $schemas[$name] = $this->emitOne($name, $instance);
        }
        return $schemas;
    }

    /** @return array<string, mixed> */
    private function emitOne(string $type, \acf_field $instance): array {
        $defaults = $instance->defaults;
        $reflection = new \ReflectionClass($instance);
        $classFile = $reflection->getFileName();
        $enums = $classFile !== false ? $this->ast->extract($classFile) : [];

        $properties = [];
        foreach (array_keys($defaults) as $propName) {
            $key = (string) $propName;
            $properties[$key] = $this->describeProperty(
                $key,
                $defaults[$propName],
                $enums[$key] ?? null,
            );
        }

        $label = $instance->label;
        $titleLabel = (is_string($label) && $label !== '') ? $label : $type;
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => "https://schemas.parisek.dev/acf/refs/field-{$type}.schema.json",
            'title' => 'ACF Field — ' . ucwords($titleLabel),
            'type' => 'object',
            'properties' => $properties,
        ];
    }

    /**
     * @param list<string>|null $enum
     * @return array<string, mixed>
     */
    private function describeProperty(string $name, mixed $defaultValue, ?array $enum): array {
        if ($enum !== null && count($enum) > 0) {
            return ['enum' => $enum];
        }
        return ['type' => $this->inferType($defaultValue)];
    }

    /** @return string|list<string> */
    private function inferType(mixed $value): string|array {
        if (is_bool($value))   return 'boolean';
        if (is_int($value))    return ['integer', 'string'];  // ACF accepts both for many numeric settings
        if (is_float($value))  return 'number';
        if (is_string($value)) return ['string', 'null'];
        if (is_array($value))  return 'array';
        if ($value === null)   return ['null', 'string'];
        return 'string';
    }
}
