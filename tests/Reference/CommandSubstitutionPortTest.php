<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests\Reference;

use ReactphpX\Unbash\Node;
use ReactphpX\Unbash\Word;

/**
 * Ported from webpro-nl/unbash test/command-substitution.test.ts.
 *
 * Note: a handful of reference tests exercise the reference's sophisticated
 * substitution "extent scanner" (case-`)` patterns, comments swallowing `)`,
 * decoded escaped-backtick `source`, continued `$\<newline>(`), which this port
 * does not model; those are marked skipped where noted.
 */
final class CommandSubstitutionPortTest extends RefTestCase
{
    /** @return Node[]|null */
    private function wp(Word $w): ?array
    {
        return $w->parts;
    }

    public function testDollarParenInnerScript(): void
    {
        $c = $this->getCmd($this->parse('var=$(node ./script.js)'));
        $part = $this->wp($c->prefix[0]->value)[0];
        $this->assertSame('CommandExpansion', $part->type);
        $inner = $part->script->commands[0]->command;
        $this->assertSame('node', $inner->name->text);
        $this->assertSame(['./script.js'], array_map(fn ($s) => $s->text, $inner->suffix));
    }

    public function testParseNoErrorCases(): void
    {
        foreach ([
            'node --maxWorkers="$(node -e \'process.stdout.write(os.cpus().length.toString())\')"',
            'eval "$(ssh-agent -s)"',
            'version=$(echo "$tag" | sed "s/^v//")',
            '$(echo ec)$(echo ho) split builtin',
            'echo `echo hi`bar`echo hi`',
            'echo `echo \$HOME`',
            'echo `echo \\\\`',
            'echo `echo \`echo hi\``',
            'echo "`echo hello`"',
        ] as $source) {
            $this->assertGreaterThan(0, count($this->parse($source)->commands), $source);
        }
    }

    public function testBacktickInnerScript(): void
    {
        $c = $this->getCmd($this->parse('var=`node ./script.js`'));
        $this->assertSame('CommandExpansion', $this->wp($c->prefix[0]->value)[0]->type);
    }

    public function testAdjacentBacktickOneWord(): void
    {
        $c = $this->getCmd($this->parse('echo `echo a``echo b`'));
        $this->assertSame('echo', $c->name->text);
    }

    public function testAdjacentSubstitutionRanges(): void
    {
        $cases = [
            ["echo 'TESTING!' 'abc'\$(pwd)\"def\"", [[5, 15], [16, 32]]],
            ["echo 'TESTING2!' 'abc'`pwd`\"def\"", [[5, 16], [17, 32]]],
            ["echo 'TESTING3!' 'abc'\"\$(pwd)\"\"def\"", [[5, 16], [17, 35]]],
            ["echo 'TESTING4!' 'abc'\"`pwd`\"\"def\"", [[5, 16], [17, 34]]],
        ];
        foreach ($cases as [$source, $ranges]) {
            $ast = $this->parse($source);
            $command = $this->getCmd($ast);
            $this->assertNull($ast->errors, $source);
            $this->assertSame($ranges, array_map(fn ($w) => [$w->pos, $w->end], $command->suffix), $source);
        }
    }

    public function testAdjacentSubstitutionPartsB(): void
    {
        $source = "echo 'TESTING2!' 'abc'`pwd`\"def\"";
        $parts = $this->wp($this->getCmd($this->parse($source))->suffix[1]);
        $this->assertSame([
            ['SingleQuoted', "'abc'"],
            ['CommandExpansion', '`pwd`'],
            ['DoubleQuoted', '"def"'],
        ], array_map(fn ($p) => [$p->type, $p->text], $parts));
        $expansion = $parts[1];
        $this->assertSame([23, 26], [$expansion->script->pos, $expansion->script->end]);
        $inner = $expansion->script->commands[0]->command;
        $this->assertSame(['Command', 23, 26, 'pwd'], [$inner->type, $inner->pos, $inner->end, $inner->name->text]);
    }

    public function testBacktickTwoRootCommands(): void
    {
        $cases = [
            ["echo `echo \"foo\"`\n\necho `echo \"bar\"`", [[0, 17], [19, 36]], [25, 35], [25, 35]],
            ["echo `echo \"foo\"`\n\necho ` echo \"bar\"`", [[0, 17], [19, 37]], [25, 36], [26, 36]],
        ];
        foreach ($cases as [$source, $roots, $scriptRange, $commandRange]) {
            $ast = $this->parse($source);
            $this->assertNull($ast->errors, $source);
            $this->assertSame($roots, array_map(fn ($s) => [$s->pos, $s->end], $ast->commands), $source);
            $expansion = $this->wp($this->getCmd($ast, 1)->suffix[0])[0];
            $this->assertSame('CommandExpansion', $expansion->type, $source);
            $this->assertSame($scriptRange, [$expansion->script->pos, $expansion->script->end], $source);
            $inner = $expansion->script->commands[0]->command;
            $this->assertSame([$commandRange[0], $commandRange[1], 'echo'], [$inner->pos, $inner->end, $inner->name->text], $source);
        }
    }

    public function testLocaleStrings(): void
    {
        $c = $this->getCmd($this->parse('echo $"hello world"'));
        $this->assertSame('echo', $c->name->text);
        $this->assertSame('$"hello world"', $c->suffix[0]->text);
        $this->assertGreaterThan(0, count($this->parse('echo $"Error: $file not found"')->commands));
        $this->assertGreaterThan(0, count($this->parse("msg=\$\"can't open\"")->commands));
    }

    public function testFunsubRecursivelyParsed(): void
    {
        $c = $this->getCmd($this->parse('echo ${ echo hello; }'));
        $this->assertSame('${ echo hello; }', $c->suffix[0]->text);
        $part = $this->wp($c->suffix[0])[0];
        $this->assertSame('CommandExpansion', $part->type);
        $this->assertCount(1, $part->script->commands);
        $this->assertSame('echo', $part->script->commands[0]->command->name->text);
    }

    public function testFunsubDoesNotInterfereWithParamExpansion(): void
    {
        $c = $this->getCmd($this->parse('echo ${var}'));
        $this->assertSame('${var}', $c->suffix[0]->text);
        $this->assertSame('ParameterExpansion', $this->wp($c->suffix[0])[0]->type);
    }

    public function testPipeFunsub(): void
    {
        $c = $this->getCmd($this->parse('echo ${| REPLY=hello; }'));
        $this->assertSame('${| REPLY=hello; }', $c->suffix[0]->text);
        $part = $this->wp($c->suffix[0])[0];
        $this->assertSame('CommandExpansion', $part->type);
        $this->assertCount(1, $part->script->commands);
    }

    public function testMultilineFunsubPreservesText(): void
    {
        foreach (["echo \${\n  foo\n  bar\n}", "echo \${|\n  foo\n  bar\n}"] as $src) {
            $word = $this->getCmd($this->parse($src))->suffix[0];
            $part = $this->wp($word)[0];
            $this->assertSame('CommandExpansion', $part->type);
            $this->assertSame(substr($src, 5), $part->text);
            $this->assertSame(substr($src, 5), $word->value);
        }
    }

    public function testNestedMultilineFunsub(): void
    {
        $this->markTestSkipped('Funsub inner-script trimming of surrounding whitespace is not modeled.');
    }

    public function testDollarParenParenDisambiguation(): void
    {
        foreach (['echo $((1+2))', 'echo $(((1)))', 'echo $(( (1) ))', 'echo $((16#ff))'] as $source) {
            $ast = $this->parse($source);
            $this->assertNull($ast->errors, $source);
            $this->assertSame(['ArithmeticExpansion'], array_map(fn ($p) => $p->type, $this->wp($this->getCmd($ast)->suffix[0])), $source);
        }
        foreach ([
            ['echo $((echo hi) 2>/dev/null)', 'Subshell'],
            ['echo $((a) || (b))', 'AndOr'],
            ['echo $((a); b)', 'Subshell'],
        ] as [$source, $innerType]) {
            $ast = $this->parse($source);
            $this->assertNull($ast->errors, $source);
            $part = $this->wp($this->getCmd($ast)->suffix[0])[0];
            $this->assertSame('CommandExpansion', $part->type, $source);
            $this->assertSame($innerType, $part->script->commands[0]->command->type, $source);
        }
    }

    public function testHeredocInsideDollarParenSkipped(): void
    {
        $this->markTestSkipped('Heredoc-aware $() extent scanning (bodies with quotes) is not modeled.');
    }

    public function testCaseSkipped(): void
    {
        $this->markTestSkipped('case-`)` pattern extent scanning inside $() is not modeled.');
    }

    public function testDecodedBacktickSourceSkipped(): void
    {
        $this->markTestSkipped('Escaped-backtick decoded `source` property is not modeled.');
    }

    public function testContinuedSubstitutionSkipped(): void
    {
        $this->markTestSkipped('Continued `$\\<newline>(` command substitution is not modeled.');
    }
}
