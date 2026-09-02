<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests;

use PHPUnit\Framework\TestCase;
use ReactphpX\Unbash\Node;

use function ReactphpX\Unbash\parse;

final class PositionTest extends TestCase
{
    public function testWordPositionsSliceSource(): void
    {
        $src = 'echo hello world';
        $cmd = parse($src)->commands[0]->command;
        foreach ([$cmd->name, ...$cmd->suffix] as $w) {
            $this->assertSame($w->text, substr($src, $w->pos, $w->end - $w->pos));
        }
    }

    public function testNestedCommandExpansionPositionsAreAbsolute(): void
    {
        $src = 'echo a$(id)b';
        $part = parse($src)->commands[0]->command->suffix[0]->parts[1];
        $inner = $part->script->commands[0]->command;
        $this->assertSame('id', substr($src, $inner->pos, $inner->end - $inner->pos));
    }

    public function testCommandPositionsSpanSource(): void
    {
        $src = 'if true; then echo hi; fi';
        $node = parse($src)->commands[0]->command;
        $this->assertSame('If', $node->type);
        $this->assertSame(0, $node->pos);
        $this->assertSame(strlen($src), $node->end);
    }

    public function testScriptEndCoversTrailingContent(): void
    {
        $src = "a\nb\nc";
        $ast = parse($src);
        $this->assertSame(0, $ast->pos);
        $this->assertGreaterThanOrEqual(strlen($src) - 1, $ast->end);
    }

    public function testArithmeticNodePositions(): void
    {
        $src = 'echo $((1+20))';
        $expr = parse($src)->commands[0]->command->suffix[0]->parts[0]->expression;
        $this->assertSame('1', substr($src, $expr->left->pos, $expr->left->end - $expr->left->pos));
        $this->assertSame('20', substr($src, $expr->right->pos, $expr->right->end - $expr->right->pos));
    }
}
