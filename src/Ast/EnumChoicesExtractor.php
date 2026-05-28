<?php
declare(strict_types=1);

namespace Parisek\AcfJsonSchema\Ast;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

final class EnumChoicesExtractor {

    /**
     * Parse a PHP file and return a map of ACF field-setting names → enum choice keys.
     *
     * Scans all `acf_render_field_setting($field, [...])` calls in the file,
     * collecting the `name` and `choices` keys from the settings array.
     *
     * @return array<string, list<string>>
     */
    public function extract(string $phpFile): array {
        $source = file_get_contents($phpFile);
        if ($source === false) {
            throw new \RuntimeException("Cannot read {$phpFile}");
        }

        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($source) ?? [];

        $visitor = new class extends NodeVisitorAbstract {
            /** @var array<string, list<string>> */
            public array $choices = [];

            public function enterNode(Node $node): int|null {
                if (!$node instanceof Node\Expr\FuncCall) {
                    return null;
                }
                if (!$node->name instanceof Node\Name) {
                    return null;
                }
                if ($node->name->toString() !== 'acf_render_field_setting') {
                    return null;
                }
                if (count($node->args) < 2) {
                    return null;
                }

                $secondArg = $node->args[1];
                if (!$secondArg instanceof Node\Arg) {
                    return null;
                }
                $settingArg = $secondArg->value;
                if (!$settingArg instanceof Node\Expr\Array_) {
                    return null;
                }

                $name = null;
                $choiceKeys = null;
                foreach ($settingArg->items as $item) {
                    if (!$item->key instanceof Node\Scalar\String_) {
                        continue;
                    }
                    if ($item->key->value === 'name' && $item->value instanceof Node\Scalar\String_) {
                        $name = $item->value->value;
                    }
                    if ($item->key->value === 'choices' && $item->value instanceof Node\Expr\Array_) {
                        $choiceKeys = $this->extractChoiceKeys($item->value);
                    }
                }

                if ($name !== null && $choiceKeys !== null) {
                    $this->choices[$name] = $choiceKeys;
                }

                return null;
            }

            /** @return list<string> */
            private function extractChoiceKeys(Node\Expr\Array_ $array): array {
                $keys = [];
                foreach ($array->items as $item) {
                    if ($item->key instanceof Node\Scalar\String_) {
                        $keys[] = $item->key->value;
                    } elseif ($item->key instanceof Node\Scalar\Int_) {
                        $keys[] = (string) $item->key->value;
                    }
                }
                return $keys;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->choices;
    }
}
