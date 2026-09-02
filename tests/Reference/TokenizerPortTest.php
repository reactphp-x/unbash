<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests\Reference;

use ReactphpX\Unbash\Lexer;

/** Ported from webpro-nl/unbash test/tokenizer.test.ts (adapted to this lexer's API). */
final class TokenizerPortTest extends RefTestCase
{
    /** @return array<int, array{type:string, op?:string, word?:mixed, pos:int, end:int}> */
    private function tokens(string $src): array
    {
        $lx = new Lexer($src);
        $out = [];
        while (true) {
            $t = $lx->next();
            if ($t['type'] === 'eof') {
                break;
            }
            $out[] = $t;
        }

        return $out;
    }

    private function opAt(string $src, int $i): ?string
    {
        $t = $this->tokens($src)[$i];

        return $t['type'] === 'op' ? $t['op'] : null;
    }

    public function testAmpDisambiguation(): void
    {
        $this->assertSame('&', $this->opAt('cmd &', 1));
        $this->assertSame('&&', $this->opAt('a && b', 1));
        $this->assertSame('&>', $this->opAt('cmd &> file', 1));
        $this->assertSame('&>>', $this->opAt('cmd &>> file', 1));
    }

    public function testPipeDisambiguation(): void
    {
        $this->assertSame('|', $this->opAt('a | b', 1));
        $this->assertSame('||', $this->opAt('a || b', 1));
        $this->assertSame('|&', $this->opAt('a |& b', 1));
    }

    public function testSemiDisambiguation(): void
    {
        $this->assertSame(';', $this->opAt('a; b', 1));
        $this->assertSame(';;', $this->opAt('a ;; b', 1));
        $this->assertSame(';&', $this->opAt('a ;& b', 1));
        $this->assertSame(';;&', $this->opAt('a ;;& b', 1));
    }

    public function testLtDisambiguation(): void
    {
        $this->assertSame('<', $this->opAt('cmd < file', 1));
        $this->assertSame('<<', $this->opAt('cmd << EOF', 1));
        $this->assertSame('<<-', $this->opAt('cmd <<- EOF', 1));
        $this->assertSame('<<<', $this->opAt('cmd <<< word', 1));
        $this->assertSame('<&', $this->opAt('cmd <& 3', 1));
        $this->assertSame('<>', $this->opAt('cmd <> file', 1));
    }

    public function testGtDisambiguation(): void
    {
        $this->assertSame('>', $this->opAt('cmd > file', 1));
        $this->assertSame('>>', $this->opAt('cmd >> file', 1));
        $this->assertSame('>&', $this->opAt('cmd >& 2', 1));
        $this->assertSame('>|', $this->opAt('cmd >| file', 1));
    }

    public function testOperatorSplitsAdjacentWords(): void
    {
        $c = $this->getCmd($this->parse('echo>file'));
        $this->assertSame('echo', $c->name->text);
        $this->assertSame('>', $c->redirects[0]->operator);
    }

    public function testAndOrPipeWithoutSpaces(): void
    {
        $expr = $this->parse('foo&&bar')->commands[0]->command;
        $this->assertSame(['&&'], $expr->operators);
        $this->assertSame('foo', $expr->commands[0]->name->text);
        $this->assertSame('bar', $expr->commands[1]->name->text);
        $this->assertSame(['||'], $this->parse('foo||bar')->commands[0]->command->operators);
        $this->assertCount(2, $this->parse('foo|bar')->commands[0]->command->commands);
        $this->assertCount(2, $this->parse('foo;bar')->commands);
    }

    public function testComments(): void
    {
        $c = $this->getCmd($this->parse('echo hello #this is a comment'));
        $this->assertCount(1, $c->suffix);
        $this->assertSame('hello', $c->suffix[0]->text);
        $this->assertSame("'# not a comment'", $this->getCmd($this->parse("echo '# not a comment'"))->suffix[0]->text);
        $this->assertSame('"# not a comment"', $this->getCmd($this->parse('echo "# not a comment"'))->suffix[0]->text);
        $this->assertCount(1, $this->parse("# comment\necho hello")->commands);
        $this->assertCount(2, $this->parse("foo |\n#comment\nbar")->commands[0]->command->commands);
    }

    public function testExpansionBoundaries(): void
    {
        $this->assertSame('$', $this->getCmd($this->parse('echo $'))->suffix[0]->text);
        $this->assertSame('$a-b', $this->getCmd($this->parse('echo $a-b'))->suffix[0]->text);
        $this->assertSame('$a.b', $this->getCmd($this->parse('echo $a.b'))->suffix[0]->text);
        $this->assertSame('$a/b', $this->getCmd($this->parse('echo $a/b'))->suffix[0]->text);
        $this->assertSame('$a_b2c', $this->getCmd($this->parse('echo $a_b2c'))->suffix[0]->text);
    }

    public function testSpecialParametersSingleChar(): void
    {
        foreach (['$@', '$*', '$#', '$$', '$?', '$!', '$-'] as $p) {
            $this->assertSame("{$p}x", $this->getCmd($this->parse("echo {$p}x"))->suffix[0]->text, "Failed for $p");
        }
        $this->assertSame('$11', $this->getCmd($this->parse('echo $11'))->suffix[0]->text);
    }

    public function testPositionalExpansionInPath(): void
    {
        $c = $this->getCmd($this->parse('rm -f $COMMON_CONFDIR/ifaces/$1'));
        $this->assertNull($this->parse('rm -f $COMMON_CONFDIR/ifaces/$1')->errors);
        $this->assertSame([
            ['-f', 3, 5],
            ['$COMMON_CONFDIR/ifaces/$1', 6, 31],
        ], array_map(fn ($w) => [$w->text, $w->pos, $w->end], $c->suffix));
        $this->assertEquals([
            ['type' => 'SimpleExpansion', 'text' => '$COMMON_CONFDIR'],
            ['type' => 'Literal', 'value' => '/ifaces/', 'text' => '/ifaces/'],
            ['type' => 'SimpleExpansion', 'text' => '$1'],
        ], $this->partsArr($c->suffix[1]));
    }

    public function testHashEdgeCases(): void
    {
        $this->assertGreaterThanOrEqual(2, count($this->parse('loop=; var=& here=;;')->commands));
        $this->assertSame("'word'#not-comment", $this->getCmd($this->parse("echo 'word'#not-comment"))->suffix[0]->text);
        $this->assertStringContainsString('#not-comment', $this->getCmd($this->parse('echo $(uname)#not-comment'))->suffix[0]->text);
        $this->assertStringContainsString('#', $this->getCmd($this->parse('echo $hey#not-comment'))->suffix[0]->text);
        $c = $this->getCmd($this->parse('var=#not-comment'));
        $found = false;
        foreach ($c->prefix as $p) {
            if ($p->type === 'Assignment' && $p->text === 'var=#not-comment') {
                $found = true;
            }
        }
        $this->assertTrue($found);
        $this->assertSame('fi#etc', $this->getCmd($this->parse('echo fi#etc'))->suffix[0]->text);
    }

    public function testGnarlyTokenization(): void
    {
        $this->assertGreaterThan(0, count($this->parse("A=\${B//:;;/\$'\\n'}")->commands));
        $this->assertGreaterThan(0, count($this->parse('echo "${kw}? ( ${cond:+${cond}? (} ${baseuri}-${ver} ${cond:+) })"')->commands));
        $this->assertSame('hello\ world', $this->getCmd($this->parse('echo hello\ world'))->suffix[0]->text);
    }

    public function testEscapedHorizontalWhitespaceStandalone(): void
    {
        $ast = $this->parse("printf \"<%s>\\n\" x \\  \\\t x");
        $this->assertNull($ast->errors);
        $this->assertSame([
            ['\\ ', ' ', 18, 20],
            ["\\\t", "\t", 21, 23],
        ], array_map(fn ($w) => [$w->text, $w->value, $w->pos, $w->end], array_slice($this->getCmd($ast)->suffix, 2, 2)));
    }

    public function testSingleBracketGlobOneCommand(): void
    {
        $source = '[ -e "${EROOT}"/usr/lib/gtk-2.0/2.[^1]* ]';
        $ast = $this->parse($source);
        $this->assertNull($ast->errors);
        $this->assertCount(1, $ast->commands);
        $command = $this->getCmd($ast);
        $this->assertSame([
            ['[', 0, 1],
            ['-e', 2, 4],
            ['"${EROOT}"/usr/lib/gtk-2.0/2.[^1]*', 5, 39],
            [']', 40, 41],
        ], array_map(fn ($w) => [$w->text, $w->pos, $w->end], [$command->name, ...$command->suffix]));
    }

    public function testEscapedParenthesesBuiltinArgs(): void
    {
        $source = "[ \\( 'aaa' = 'bbb' \\) -o \\( 'ccc' = 'ccc' \\) ]";
        $ast = $this->parse($source);
        $this->assertNull($ast->errors);
        $this->assertCount(1, $ast->commands);
        $command = $this->getCmd($ast);
        $this->assertSame(['[', 0, 46], [$command->name->text, $command->pos, $command->end]);
        $this->assertSame([
            ['\(', '(', 2, 4],
            ["'aaa'", 'aaa', 5, 10],
            ['=', '=', 11, 12],
            ["'bbb'", 'bbb', 13, 18],
            ['\)', ')', 19, 21],
            ['-o', '-o', 22, 24],
            ['\(', '(', 25, 27],
            ["'ccc'", 'ccc', 28, 33],
            ['=', '=', 34, 35],
            ["'ccc'", 'ccc', 36, 41],
            ['\)', ')', 42, 44],
            [']', ']', 45, 46],
        ], array_map(fn ($w) => [$w->text, $w->value, $w->pos, $w->end], $command->suffix));
    }

    public function testEscapedWhitespaceKeepsHashLiteral(): void
    {
        $ast = $this->parse('echo \ # hi');
        $this->assertNull($ast->errors);
        $this->assertSame([
            ['\ #', ' #'],
            ['hi', 'hi'],
        ], array_map(fn ($w) => [$w->text, $w->value], $this->getCmd($ast)->suffix));
    }

    public function testMiscParseNoCrash(): void
    {
        foreach ([
            'echo "x $(echo "hi")"',
            'pnpm exec "cat package.json | jq -r \'\\"\\\\(.name)@\\\\(.version)\\"\'" | sort',
            "echo \"asd\"`\n`\"fgh\"",
            'echo ${x:-1}',
            'echo ${x: -1}',
            'echo ${cdir:+#}',
            'echo ${dict_langs:+;}',
            'echo ${BRANDING/(/(Gentoo ${PVR}, }',
            "some-command \${foo:+--arg <(printf '%s\\n' \"\$foo\")}",
        ] as $source) {
            $this->assertGreaterThan(0, count($this->parse($source)->commands), $source);
        }
    }
}
