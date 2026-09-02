<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests\Reference;

use ReactphpX\Unbash\Node;
use ReactphpX\Unbash\Word;

/** Ported from webpro-nl/unbash test/positions.test.ts */
final class PositionsPortTest extends RefTestCase
{
    private function slice(string $src, Node|Word $n): string
    {
        return substr($src, $n->pos, $n->end - $n->pos);
    }

    public function testWordPositionsSimple(): void
    {
        $src = 'echo hello world';
        $cmd = $this->getCmd($this->parse($src));
        $this->assertSame('echo', $this->slice($src, $cmd->name));
        $this->assertSame('hello', $this->slice($src, $cmd->suffix[0]));
        $this->assertSame('world', $this->slice($src, $cmd->suffix[1]));
    }

    public function testWordPositionsWithQuotes(): void
    {
        $src = 'echo "hello world"';
        $this->assertSame('"hello world"', $this->slice($src, $this->getCmd($this->parse($src))->suffix[0]));
    }

    public function testWordPositionsWithVariable(): void
    {
        $src = 'echo $HOME';
        $this->assertSame('$HOME', $this->slice($src, $this->getCmd($this->parse($src))->suffix[0]));
    }

    public function testStatementPositions(): void
    {
        $src = 'echo hello';
        $this->assertSame('echo hello', $this->slice($src, $this->parse($src)->commands[0]));
    }

    public function testStatementWithBackground(): void
    {
        $src = 'sleep 5 &';
        $stmt = $this->parse($src)->commands[0];
        $this->assertTrue($stmt->background);
        $this->assertSame('sleep 5 &', $this->slice($src, $stmt));
    }

    public function testWholeSpanCompoundCommands(): void
    {
        foreach ([
            'if true; then echo yes; fi',
            'if a; then b; elif c; then d; else e; fi',
            'for x in a b c; do echo $x; done',
            'while true; do echo loop; done',
            'until false; do echo loop; done',
            'case $x in a) echo a;; b) echo b;; esac',
            'select x in a b c; do echo $x; done',
            '(echo hello; echo world)',
            '{ echo hello; echo world; }',
            '[[ -f /etc/passwd ]]',
            '(( x + 1 ))',
            'echo hello | grep h | wc -l',
            'time echo hello | cat',
            '! echo hello | cat',
            'true && echo yes || echo no',
            'function greet { echo hi; }',
            'greet() { echo hi; }',
        ] as $src) {
            $node = $this->parse($src)->commands[0]->command;
            $this->assertSame($src, $this->slice($src, $node), $src);
        }
    }

    public function testIfBranchListsGroupMultiple(): void
    {
        $src = 'if true; false; true; then echo foo; echo bar; echo then; else echo baz; echo flux; echo else; fi';
        $ast = $this->parse($src);
        $this->assertNull($ast->errors);
        $if = $ast->commands[0]->command;
        $this->assertSame('CompoundList', $if->else->type);
        $this->assertSame([
            [3, 20, 3],
            [27, 56, 3],
            [63, 93, 3],
        ], array_map(fn (Node $c) => [$c->pos, $c->end, count($c->commands)], [$if->clause, $if->then, $if->else]));
    }

    public function testRedirectPositions(): void
    {
        $src = 'echo hello > /tmp/out';
        $this->assertSame('> /tmp/out', $this->slice($src, $this->getCmd($this->parse($src))->redirects[0]));
    }

    public function testRedirectWithFdPositions(): void
    {
        $src = 'cmd 2>/dev/null';
        $this->assertSame('2>/dev/null', $this->slice($src, $this->getCmd($this->parse($src))->redirects[0]));
    }

    public function testArithmeticExpressionPositions(): void
    {
        $src = '(( x + 1 ))';
        $expr = $this->getCmd($this->parse($src))->expression;
        $this->assertSame('ArithmeticBinary', $expr->type);
        $this->assertSame('x + 1', $this->slice($src, $expr));
    }

    public function testArithmeticWordPositions(): void
    {
        $src = '(( x ))';
        $expr = $this->getCmd($this->parse($src))->expression;
        $this->assertSame('ArithmeticWord', $expr->type);
        $this->assertSame('x', $this->slice($src, $expr));
    }

    public function testTestExpressionPositions(): void
    {
        foreach ([
            ['[[ -f file ]]', 'TestUnary', '-f file'],
            ['[[ a == b ]]', 'TestBinary', 'a == b'],
            ['[[ -f a && -d b ]]', 'TestLogical', '-f a && -d b'],
            ['[[ ! -f a ]]', 'TestNot', '! -f a'],
            ['[[ ( -f a ) ]]', 'TestGroup', '( -f a )'],
        ] as [$src, $type, $span]) {
            $expr = $this->getCmd($this->parse($src))->expression;
            $this->assertSame($type, $expr->type, $src);
            $this->assertSame($span, $this->slice($src, $expr), $src);
        }
    }

    public function testScriptPosEnd(): void
    {
        $src = "echo hello\necho world\n";
        $ast = $this->parse($src);
        $this->assertSame(0, $ast->pos);
        $this->assertSame(strlen($src), $ast->end);
    }

    public function testCompoundListPositionsInForBody(): void
    {
        $src = 'for x in a b; do echo $x; echo done; done';
        $body = $this->getCmd($this->parse($src))->body;
        $this->assertCount(2, $body->commands);
        $this->assertGreaterThanOrEqual(0, $body->pos);
        $this->assertLessThanOrEqual(strlen($src), $body->end);
    }

    public function testAssignmentPrefixPositions(): void
    {
        $src = 'FOO=bar cmd';
        $this->assertSame('FOO=bar', $this->slice($src, $this->getCmd($this->parse($src))->prefix[0]));
    }

    public function testCoprocPositions(): void
    {
        $src = 'coproc cat';
        $cop = $this->getCmd($this->parse($src));
        $this->assertSame(0, $cop->pos);
        $this->assertLessThanOrEqual(strlen($src), $cop->end);
    }

    /** @dataProvider invariantScripts */
    public function testInvariant(string $script): void
    {
        $ast = $this->parse($script);
        $this->assertSame(0, $ast->pos);
        $this->assertSame(strlen($script), $ast->end);
        foreach ($ast->commands as $stmt) {
            $this->walk($stmt, $script);
        }
    }

    private function walk(Node $node, string $src): void
    {
        $this->assertGreaterThanOrEqual(0, $node->pos);
        $this->assertGreaterThanOrEqual($node->pos, $node->end);
        $this->assertLessThanOrEqual(strlen($src), $node->end);
        foreach ($node->properties() as $val) {
            $this->walkValue($val, $src);
        }
    }

    private function walkValue(mixed $val, string $src): void
    {
        if ($val instanceof Node) {
            $this->walk($val, $src);
        } elseif ($val instanceof Word) {
            $this->assertGreaterThanOrEqual(0, $val->pos);
            $this->assertGreaterThanOrEqual($val->pos, $val->end);
            $this->assertLessThanOrEqual(strlen($src), $val->end);
        } elseif (is_array($val)) {
            foreach ($val as $item) {
                $this->walkValue($item, $src);
            }
        }
    }

    /** @return array<int, array{0:string}> */
    public static function invariantScripts(): array
    {
        $scripts = [
            'echo hello world', '  echo   spaced  ', 'echo hello; echo world',
            'echo hello & echo world', 'echo a | grep a | wc -l', 'true && echo yes || echo no',
            'if true; then echo yes; fi', 'if a; then b; elif c; then d; else e; fi',
            'for x in a b c; do echo $x; done', 'while true; do echo loop; done',
            'until false; do echo loop; done', 'case $x in a) echo a;; b) echo b;; esac',
            'select x in a b c; do echo $x; done', '(echo hello; echo world)',
            '{ echo hello; echo world; }', '[[ -f /etc/passwd ]]', '[[ a == b ]]',
            '[[ -f a && -d b ]]', '[[ ! -f a ]]', '[[ ( -f a ) ]]', '(( x + 1 ))', '(( x++ ))',
            '(( x > 0 ? 1 : 0 ))', 'function greet { echo hi; }', 'greet() { echo hi; }',
            'echo hello > /tmp/out', 'cmd 2>/dev/null', 'echo hello >> /tmp/out', 'cat < /tmp/in',
            'FOO=bar cmd', 'FOO=bar BAZ=qux cmd', 'coproc cat', 'time echo hello | cat',
            '! echo hello | cat', 'echo "hello world"', "echo 'hello world'", 'echo $HOME',
            'echo "${HOME}/bin"', 'echo $(date)', 'echo $((1+2))',
        ];

        return array_map(static fn ($s) => [$s], $scripts);
    }
}
