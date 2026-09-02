<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests\Reference;

use ReactphpX\Unbash\Node;

/**
 * Ported from webpro-nl/unbash test/deep-nesting.test.ts (round-trip and
 * structural cases). The reference's explicit per-construct depth-budget error
 * messages ("maximum ... nesting depth exceeded") are not modeled and those
 * tests are omitted.
 */
final class DeepNestingPortTest extends RefTestCase
{
    private function roundtrip(string $src): Node
    {
        $ast = $this->parse($src);
        $this->assertSame($src, $this->verify($src, $ast));

        return $ast;
    }

    private function repeatNest(int $depth, string $open, string $body, string $close): string
    {
        return str_repeat($open, $depth) . $body . str_repeat($close, $depth);
    }

    public function testNestedParamDefaults(): void
    {
        $this->roundtrip('echo "${a:-${b:-${c:-${d}}}}"');
    }

    public function testNestedParamInReplacement(): void
    {
        $this->roundtrip('echo "${path//${prefix}/}"');
    }

    public function testParamWithNestedCommandSubstitution(): void
    {
        $this->roundtrip('echo "${var:-$(echo ${fallback:-default})}"');
    }

    public function testNormalExpansionNestingStructure(): void
    {
        $ast = $this->parse('echo "${a:-${b:-$(x)}}"');
        $this->assertNull($ast->errors);
        $dq = $this->getCmd($ast)->suffix[0]->parts[0];
        $outer = $dq->parts[0];
        $this->assertSame('a', $outer->parameter);
        $inner = $outer->operand->parts[0];
        $this->assertSame('b', $inner->parameter);
        $sub = $inner->operand->parts[0];
        $this->assertSame('CommandExpansion', $sub->type);
        $this->assertSame('x', $sub->script->commands[0]->command->name->value);
    }

    public function testParameterNestingAtLimitLossless(): void
    {
        $src = $this->repeatNest(256, '${a:-', 'x', '}');
        $ast = $this->roundtrip($src);
        $this->assertNull($ast->errors);
        $part = $this->getCmd($ast)->name->parts[0];
        $levels = 1;
        while ($part->operand !== null && $part->operand->parts !== null) {
            $part = $part->operand->parts[0];
            $levels++;
        }
        $this->assertSame(256, $levels);
        $this->assertSame('x', $part->operand->text);
    }

    public function testNestedCommandSubstitutions(): void
    {
        $this->roundtrip('echo $(cat $(dirname $(readlink -f $0))/file)');
        $this->roundtrip('result=$(echo "prefix_$(basename "$file")_suffix")');
        $this->roundtrip('echo $(echo `hostname`)');
    }

    public function testNestedIf(): void
    {
        $ast = $this->roundtrip('if true; then if false; then echo a; else echo b; fi; fi');
        $outer = $ast->commands[0]->command;
        $inner = $outer->then->commands[0]->command;
        $this->assertSame('If', $inner->type);
        $this->assertNotNull($inner->else);
        $this->roundtrip('if a; then if b; then if c; then echo deep; fi; fi; fi');
    }

    public function testNestedForInWhile(): void
    {
        $ast = $this->roundtrip('while true; do for x in a b; do echo $x; done; done');
        $wh = $ast->commands[0]->command;
        $fr = $wh->body->commands[0]->command;
        $this->assertSame('For', $fr->type);
    }

    public function testNestedSubshellInPipeline(): void
    {
        $this->roundtrip('( ( echo inner ) | cat ) | grep x');
    }

    public function testCompoundNestingAtLimitLossless(): void
    {
        foreach ([
            $this->repeatNest(256, '( ', 'echo inner', ' )'),
            $this->repeatNest(256, '{ ', 'echo inner', '; }'),
            $this->repeatNest(256, 'if :; then ', 'echo inner', '; fi'),
            '[[ ' . $this->repeatNest(256, '( ', 'inner', ' )') . ' ]]',
        ] as $src) {
            $ast = $this->roundtrip($src);
            $this->assertNull($ast->errors);
        }
    }

    public function testLoopSelectCaseNestingAtLimitLossless(): void
    {
        foreach ([
            $this->repeatNest(256, 'for x; do ', 'echo inner', '; done'),
            $this->repeatNest(256, 'for ((;;)); do ', 'echo inner', '; done'),
            $this->repeatNest(256, 'while :; do ', 'echo inner', '; done'),
            $this->repeatNest(256, 'until :; do ', 'echo inner', '; done'),
            $this->repeatNest(256, 'select x; do ', 'echo inner', '; done'),
            $this->repeatNest(256, 'case x in x) ', 'echo inner', ';; esac'),
        ] as $src) {
            $ast = $this->roundtrip($src);
            $this->assertNull($ast->errors);
        }
    }

    public function testDepthLimitErrorMessagesSkipped(): void
    {
        $this->markTestSkipped('Per-construct depth-budget error messages and 2000-level recovery are not modeled.');
    }
}
