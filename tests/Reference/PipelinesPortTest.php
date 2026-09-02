<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests\Reference;

use ReactphpX\Unbash\Node;

/** Ported from webpro-nl/unbash test/pipelines.test.ts */
final class PipelinesPortTest extends RefTestCase
{
    /** @param Node[] $nodes @return array<int,?string> */
    private function names(array $nodes): array
    {
        return array_map(static fn (Node $n) => $n->name?->text, $nodes);
    }

    public function testLogicalAnd(): void
    {
        $expr = $this->parse('cmd1 && cmd2')->commands[0]->command;
        $this->assertSame(['&&'], $expr->operators);
        $this->assertSame('cmd1', $expr->commands[0]->name->text);
        $this->assertSame('cmd2', $expr->commands[1]->name->text);
    }

    public function testLogicalOr(): void
    {
        $this->assertSame(['||'], $this->parse('cmd1 || cmd2')->commands[0]->command->operators);
    }

    public function testChained(): void
    {
        $expr = $this->parse('a && b || c')->commands[0]->command;
        $this->assertSame(['&&', '||'], $expr->operators);
        $this->assertCount(3, $expr->commands);
    }

    public function testPipelinePreservesAll(): void
    {
        $p = $this->parse('cat file | grep pattern | sort -u')->commands[0]->command;
        $this->assertCount(3, $p->commands);
        $this->assertSame(['cat', 'grep', 'sort'], $this->names($p->commands));
        $this->assertSame(['-u'], $this->args($p->commands[2]));
    }

    public function testPipelineInLogical(): void
    {
        $expr = $this->parse('cmd1 | cmd2 && cmd3')->commands[0]->command;
        $this->assertSame('Pipeline', $expr->commands[0]->type);
    }

    public function testNegatedPipeline(): void
    {
        $this->assertTrue($this->parse('! cmd1 | cmd2')->commands[0]->command->negated);
    }

    public function testRepeatedNegationIsError(): void
    {
        foreach ([['! ! cmd', 2], ['! ! ! cmd', 2], ['! !', 2], ['time ! ! cmd', 7]] as [$source, $pos]) {
            $ast = $this->parse($source);
            $pipeline = $ast->commands[0]->command;
            $this->assertSame('Pipeline', $pipeline->type, $source);
            $this->assertTrue($pipeline->negated, $source);
            $this->assertSame(0, $pipeline->pos, $source);
            $this->assertEquals([['message' => "unexpected token '!'", 'pos' => $pos]], $this->errorsArr($ast), $source);
        }
    }

    public function testPipeBothStreams(): void
    {
        $p = $this->parse('cmd1 |& cmd2')->commands[0]->command;
        $this->assertSame('Pipeline', $p->type);
        $this->assertCount(2, $p->commands);
        $this->assertSame(['|&'], $p->operators);
    }

    public function testPlainPipeOps(): void
    {
        $this->assertSame(['|', '|'], $this->parse('cmd1 | cmd2 | cmd3')->commands[0]->command->operators);
    }

    public function testMixedPipeOps(): void
    {
        $this->assertSame(['|', '|&'], $this->parse('cmd1 | cmd2 |& cmd3')->commands[0]->command->operators);
    }

    public function testBackgroundCommand(): void
    {
        $this->assertTrue($this->parse('sleep 10 &')->commands[0]->background);
    }

    public function testBackgroundFirstInList(): void
    {
        $ast = $this->parse('cmd1 & cmd2');
        $this->assertTrue($ast->commands[0]->background);
        $this->assertNull($ast->commands[1]->background);
    }

    public function testBackgroundPipeline(): void
    {
        $this->assertTrue($this->parse('cmd1 | cmd2 &')->commands[0]->background);
    }

    public function testNoBackgroundOnSemicolon(): void
    {
        $ast = $this->parse('cmd1; cmd2');
        $this->assertNull($ast->commands[0]->background);
        $this->assertNull($ast->commands[1]->background);
    }

    public function testNegatedCompoundWithRedirectAndBackground(): void
    {
        $ast = $this->parse('! if foo; then bar; fi >/dev/null &');
        $this->assertGreaterThan(0, count($ast->commands));
    }

    public function testBackgroundInLogical(): void
    {
        $this->assertGreaterThanOrEqual(2, count($this->parse('a && b & c')->commands));
    }

    public function testTimeSimpleCommand(): void
    {
        $p = $this->parse('time sleep 1')->commands[0]->command;
        $this->assertTrue($p->time);
        $this->assertCount(1, $p->commands);
        $this->assertSame('sleep', $p->commands[0]->name->text);
    }

    public function testTimePipeline(): void
    {
        $p = $this->parse('time cmd1 | cmd2')->commands[0]->command;
        $this->assertTrue($p->time);
        $this->assertCount(2, $p->commands);
    }

    public function testTimePFlagConsumed(): void
    {
        $p = $this->parse('time -p cmd')->commands[0]->command;
        $this->assertTrue($p->time);
        $this->assertSame('cmd', $p->commands[0]->name->text);
    }

    public function testTimeWithNegation(): void
    {
        $p = $this->parse('time ! cmd')->commands[0]->command;
        $this->assertTrue($p->time);
        $this->assertTrue($p->negated);
    }

    public function testTimeAllowsLineContinuations(): void
    {
        $cases = [
            ["ti\\\nme echo", null, 1],
            ["time\\\n -p echo", null, 1],
            ["ti\\\nme ! echo", true, 1],
            ["ti\\\nme echo | cat", null, 2],
        ];
        foreach ($cases as [$source, $negated, $count]) {
            $ast = $this->parse($source);
            $p = $ast->commands[0]->command;
            $this->assertSame('Pipeline', $p->type, $source);
            $this->assertTrue($p->time, $source);
            $this->assertSame($negated, $p->negated, $source);
            $this->assertCount($count, $p->commands, $source);
            $this->assertNull($ast->errors, $source);
        }
    }

    public function testQuotedTimeSpellingsAreCommandNames(): void
    {
        foreach ([
            "'time' echo", '"time" echo', "\$'time' echo", '$"time" echo',
            "ti\$''me echo", '\time echo', 't\ime echo', "ti'me' echo", 'ti"me" echo',
        ] as $source) {
            $command = $this->parse($source)->commands[0]->command;
            $this->assertSame('Command', $command->type, $source);
            $this->assertSame('time', $command->name->value, $source);
            $this->assertSame(['echo'], array_map(fn ($w) => $w->value, $command->suffix), $source);
            $this->assertNull($this->parse($source)->errors, $source);
        }
    }

    public function testTimeAloneProducesNode(): void
    {
        $ast = $this->parse('time');
        $this->assertCount(1, $ast->commands);
        $p = $ast->commands[0]->command;
        $this->assertSame('Pipeline', $p->type);
        $this->assertTrue($p->time);
        $this->assertCount(0, $p->commands);
        $this->assertSame(0, $p->pos);
        $this->assertSame(4, $p->end);
    }

    public function testTimePAloneProducesNode(): void
    {
        $ast = $this->parse('time -p');
        $this->assertCount(1, $ast->commands);
        $p = $ast->commands[0]->command;
        $this->assertTrue($p->time);
        $this->assertCount(0, $p->commands);
        $this->assertSame(0, $p->pos);
        $this->assertSame(7, $p->end);
    }

    public function testTimeOnOwnLine(): void
    {
        $ast = $this->parse("time\nfoo");
        $this->assertCount(2, $ast->commands);
        $this->assertTrue($ast->commands[0]->command->time);
        $this->assertSame('foo', $ast->commands[1]->command->name->text);
    }

    public function testQuotedTimeFlagsStayOperands(): void
    {
        foreach (["time '-p' echo hi", 'time "-p" echo hi', 'time \-p echo hi'] as $source) {
            $p = $this->parse($source)->commands[0]->command;
            $this->assertSame('Pipeline', $p->type, $source);
            $this->assertTrue($p->time, $source);
            $this->assertSame('-p', $p->commands[0]->name->value, $source);
        }
    }

    public function testUnquotedTimePIsFlag(): void
    {
        $p = $this->parse('time -p echo hi')->commands[0]->command;
        $this->assertTrue($p->time);
        $this->assertSame('echo', $p->commands[0]->name->value);
    }
}
