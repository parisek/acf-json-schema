<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Tests;

use Parisek\AcfJsonSchema\Tests\Helpers\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The direct regression test for ADR 0005: a field must validate identically
 * whether it sits at the top level or nested in a repeater's sub_fields. Before
 * the unification, nested fields were validated against the base schema only,
 * so the same field could pass nested yet fail at the top level.
 */
final class NestingSymmetryTest extends TestCase {

    private const ACF = 'https://schemas.parisek.dev/acf/acf.schema.json';

    private Validator $validator;

    protected function setUp(): void {
        $root = realpath(__DIR__ . '/../schemas/');
        $this->assertNotFalse($root);
        $this->validator = new Validator($root . '/');
    }

    /**
     * A representative field per previously-stubbed type, with genuine props.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function fields(): iterable {
        yield 'file'    => [['key' => 'field_s_file', 'label' => 'F', 'name' => 'f', 'type' => 'file', 'allow_in_bindings' => 0, 'return_format' => 'array', 'library' => 'all', 'mime_types' => 'mp4']];
        yield 'oembed'  => [['key' => 'field_s_oe', 'label' => 'O', 'name' => 'o', 'type' => 'oembed', 'allow_in_bindings' => 0, 'width' => '', 'height' => 480]];
        yield 'user'    => [['key' => 'field_s_u', 'label' => 'U', 'name' => 'u', 'type' => 'user', 'allow_in_bindings' => 0, 'role' => '', 'allow_null' => 0, 'multiple' => 1, 'return_format' => 'array']];
        yield 'page_link' => [['key' => 'field_s_pl', 'label' => 'P', 'name' => 'p', 'type' => 'page_link', 'allow_in_bindings' => 0, 'post_type' => [], 'taxonomy' => '', 'allow_null' => 0, 'allow_archives' => 0, 'multiple' => 0]];
        yield 'relationship' => [['key' => 'field_s_rel', 'label' => 'R', 'name' => 'r', 'type' => 'relationship', 'allow_in_bindings' => 0, 'post_type' => [], 'taxonomy' => [], 'filters' => ['search'], 'elements' => '', 'min' => '', 'max' => 0, 'return_format' => 'object', 'bidirectional_target' => []]];
        yield 'tab'     => [['key' => 'field_s_tab', 'label' => 'T', 'name' => '', 'type' => 'tab', 'allow_in_bindings' => 0, 'placement' => 'top', 'endpoint' => 0, 'selected' => 0]];
        yield 'time_picker' => [['key' => 'field_s_tp', 'label' => 'T', 'name' => 't', 'type' => 'time_picker', 'allow_in_bindings' => 0, 'display_format' => 'g:i a', 'return_format' => 'g:i a']];
        yield 'date_picker' => [['key' => 'field_s_dp', 'label' => 'D', 'name' => 'd', 'type' => 'date_picker', 'allow_in_bindings' => 0, 'display_format' => 'd/m/Y', 'return_format' => 'd/m/Y', 'first_day' => 1]];
        yield 'date_time_picker' => [['key' => 'field_s_dtp', 'label' => 'D', 'name' => 'd2', 'type' => 'date_time_picker', 'allow_in_bindings' => 0, 'display_format' => 'd/m/Y g:i a', 'return_format' => 'Y-m-d H:i:s', 'first_day' => 1]];
        yield 'button_group' => [['key' => 'field_s_bg', 'label' => 'B', 'name' => 'b', 'type' => 'button_group', 'allow_in_bindings' => 0, 'choices' => ['l' => 'L'], 'allow_null' => 0, 'layout' => 'horizontal', 'return_format' => 'value']];
    }

    /** @param list<array<string, mixed>> $fields */
    private function group(array $fields): object {
        return json_decode((string) json_encode([
            'key' => 'group_sym', 'title' => 'Sym', 'fields' => $fields,
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
            'modified' => 1756303996, 'active' => true,
        ]));
    }

    /**
     * @param array<string, mixed> $field
     */
    #[DataProvider('fields')]
    public function test_top_level_and_nested_agree(array $field): void {
        $topLevel = $this->validator->validate(self::ACF, $this->group([$field]))->isValid();

        $repeater = [
            'key' => 'field_sym_rep', 'label' => 'Rep', 'name' => 'rep', 'type' => 'repeater',
            'allow_in_bindings' => 0, 'sub_fields' => [$field],
        ];
        $nested = $this->validator->validate(self::ACF, $this->group([$repeater]))->isValid();

        $this->assertTrue($topLevel, 'Top-level should accept the field.');
        $this->assertSame($topLevel, $nested, 'Nested validation must agree with top-level (ADR 0005 symmetry).');
    }
}
