<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests\Reference;

use PHPUnit\Framework\TestCase;
use ReactphpX\Unbash\Node;
use ReactphpX\Unbash\Word;

use function ReactphpX\Unbash\parse as unbashParse;

/**
 * Base class for tests ported from the reference (TypeScript) unbash suite
 * (webpro-nl/unbash). Provides the same helpers the reference tests use
 * (`getCmd`, `args`) plus a PHP port of the `verify()` round-trip checker and
 * conversion utilities for deep-equality assertions.
 */
abstract class RefTestCase extends TestCase
{
    protected function parse(string $source): Node
    {
        return unbashParse($source);
    }

    protected function getCmd(Node $ast, int $i = 0): Node
    {
        return $ast->commands[$i]->command;
    }

    /** @return string[] */
    protected function args(Node $cmd): array
    {
        return array_map(static fn (Word $w) => $w->text, $cmd->suffix);
    }

    /** Convert a Node/Word/array tree into plain arrays for deep comparison. */
    protected function toArray(mixed $v): mixed
    {
        if ($v instanceof Word) {
            $o = ['text' => $v->text, 'pos' => $v->pos, 'end' => $v->end];
            if ($v->parts !== null) {
                $o['parts'] = array_map([$this, 'toArray'], $v->parts);
            }
            $o['value'] = $v->value;

            return $o;
        }
        if ($v instanceof Node) {
            $o = ['type' => $v->type];
            foreach ($v->properties() as $k => $val) {
                $o[$k] = $this->toArray($val);
            }

            return $o;
        }
        if (is_array($v)) {
            return array_map([$this, 'toArray'], $v);
        }

        return $v;
    }

    /** @return array<int, array<string, mixed>>|null */
    protected function partsArr(Word $w): ?array
    {
        if ($w->parts === null) {
            return null;
        }

        return array_map([$this, 'toArray'], $w->parts);
    }

    /**
     * @return array<int, array{message: string, pos: int}>|null
     */
    protected function errorsArr(Node $ast): ?array
    {
        if ($ast->errors === null) {
            return null;
        }

        return array_map(static fn ($e) => ['message' => $e->message, 'pos' => $e->pos], $ast->errors);
    }

    // ── verify(): AST round-trip checker ported from test/verify.ts ──────

    private const CHILDREN = [
        'Script' => ['commands'],
        'Statement' => ['command', 'redirects'],
        'Command' => ['prefix', 'name', 'suffix', 'redirects'],
        'Pipeline' => ['commands'],
        'AndOr' => ['commands'],
        'If' => ['clause', 'then', 'else'],
        'For' => ['name', 'wordlist', 'body'],
        'ArithmeticFor' => ['body'],
        'ArithmeticCommand' => ['expression'],
        'While' => ['clause', 'body'],
        'Case' => ['word', 'items'],
        'CaseItem' => ['pattern', 'body'],
        'Select' => ['name', 'wordlist', 'body'],
        'Function' => ['name', 'body', 'redirects'],
        'Subshell' => ['body'],
        'BraceGroup' => ['body'],
        'CompoundList' => ['commands'],
        'Coproc' => ['name', 'body', 'redirects'],
        'TestCommand' => ['expression'],
        'TestUnary' => ['operand'],
        'TestBinary' => ['left', 'right'],
        'TestLogical' => ['left', 'right'],
        'TestNot' => ['operand'],
        'TestGroup' => ['expression'],
        'ArithmeticBinary' => ['left', 'right'],
        'ArithmeticUnary' => ['operand'],
        'ArithmeticTernary' => ['test', 'consequent', 'alternate'],
        'ArithmeticGroup' => ['expression'],
    ];

    protected function verify(string $source, Node|Word $node): string
    {
        return $this->doVerify($source, $node);
    }

    private function nodePos(Node|Word $n): int
    {
        return $n instanceof Word ? $n->pos : $n->pos;
    }

    private function nodeEnd(Node|Word $n): int
    {
        return $n instanceof Word ? $n->end : $n->end;
    }

    /**
     * @return array<int, Node|Word>
     */
    private function getChildren(Node $node): array
    {
        $fields = self::CHILDREN[$node->type] ?? null;
        if ($fields === null) {
            return [];
        }
        $children = [];
        foreach ($fields as $field) {
            $value = $node->$field;
            if ($value === null) {
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    if (($item instanceof Node || $item instanceof Word)
                        && $this->nodePos($item) >= $node->pos && $this->nodeEnd($item) <= $node->end) {
                        $children[] = $item;
                    }
                }
            } elseif (($value instanceof Node || $value instanceof Word)
                && $this->nodePos($value) >= $node->pos && $this->nodeEnd($value) <= $node->end) {
                $children[] = $value;
            }
        }
        usort($children, fn ($a, $b) => $this->nodePos($a) <=> $this->nodePos($b));

        return $children;
    }

    private function checkContent(string $src, Node $node): void
    {
        $span = substr($src, $node->pos, $node->end - $node->pos);
        switch ($node->type) {
            case 'Assignment':
                if ($node->name !== null && !str_starts_with($span, $node->name)) {
                    $this->fail("Assignment.name mismatch at {$node->pos}");
                }
                break;
            case 'ArithmeticWord':
                $this->assertSame($span, $node->value, "ArithmeticWord.value at {$node->pos}");
                break;
            case 'ArithmeticCommandExpansion':
                $this->assertSame($span, $node->text, "ArithmeticCommandExpansion.text at {$node->pos}");
                break;
            case 'ArithmeticCommand':
                if (str_starts_with($span, '((') && str_ends_with($span, '))')) {
                    $this->assertSame(substr($span, 2, -2), $node->body, "ArithmeticCommand.body at {$node->pos}");
                }
                break;
            case 'While':
                $this->assertTrue(str_starts_with($span, $node->kind), "While.kind at {$node->pos}");
                break;
        }
    }

    private function doVerify(string $source, Node|Word $node): string
    {
        if ($node instanceof Word) {
            $this->verifyParts($source, $node);

            return substr($source, $node->pos, $node->end - $node->pos);
        }

        $this->checkContent($source, $node);
        $children = $this->getChildren($node);
        if ($children === []) {
            return substr($source, $node->pos, $node->end - $node->pos);
        }
        $result = '';
        $cursor = $node->pos;
        foreach ($children as $child) {
            $result .= substr($source, $cursor, $this->nodePos($child) - $cursor);
            $result .= $this->doVerify($source, $child);
            $cursor = $this->nodeEnd($child);
        }
        $result .= substr($source, $cursor, $node->end - $cursor);

        return $result;
    }

    private function verifyParts(string $source, Word $word): void
    {
        $parts = $word->parts;
        if ($parts === null) {
            return;
        }
        $span = substr($source, $word->pos, $word->end - $word->pos);
        $concat = '';
        foreach ($parts as $p) {
            $concat .= $p->text;
        }
        $this->assertSame($span, $concat, "Parts text concat mismatch at {$word->pos}");
        foreach ($parts as $part) {
            $this->verifyPartChildren($source, $part);
        }
    }

    private function verifyPartChildren(string $source, Node $part): void
    {
        if ($part->type === 'DoubleQuoted' || $part->type === 'LocaleString') {
            $prefix = $part->type === 'LocaleString' ? 2 : 1;
            $inner = substr($part->text, $prefix, -1);
            $childConcat = '';
            foreach ($part->parts as $c) {
                $childConcat .= $c->text;
            }
            $this->assertSame($inner, $childConcat, "{$part->type} children concat mismatch");
            foreach ($part->parts as $child) {
                $this->verifyPartChildren($source, $child);
            }
        }

        if (($part->type === 'CommandExpansion' || $part->type === 'ProcessSubstitution') && $part->script !== null) {
            $script = $part->script;
            $expected = substr($source, $script->pos, $script->end - $script->pos);
            $rebuilt = $this->doVerify($source, $script);
            $this->assertSame($expected, $rebuilt, "{$part->type} inner script verify failed");
        }
    }
}
