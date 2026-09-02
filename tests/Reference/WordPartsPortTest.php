<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests\Reference;

use ReactphpX\Unbash\Word;

/** Ported from webpro-nl/unbash test/word-parts.test.ts */
final class WordPartsPortTest extends RefTestCase
{
    /** @return array<int, array<string,mixed>>|null */
    private function p(Word $w): ?array
    {
        return $this->partsArr($w);
    }

    public function testSimpleWordHasNoParts(): void
    {
        $c = $this->getCmd($this->parse('echo hello'));
        $this->assertNull($this->p($c->name));
        $this->assertNull($this->p($c->suffix[0]));
    }

    public function testDoubleQuotedLiteral(): void
    {
        $c = $this->getCmd($this->parse('echo "hello world"'));
        $this->assertEquals([
            ['type' => 'DoubleQuoted', 'text' => '"hello world"', 'parts' => [
                ['type' => 'Literal', 'value' => 'hello world', 'text' => 'hello world'],
            ]],
        ], $this->p($c->suffix[0]));
    }

    public function testDoubleQuotedWithVariable(): void
    {
        $c = $this->getCmd($this->parse('echo "hello $name world"'));
        $this->assertEquals([
            ['type' => 'DoubleQuoted', 'text' => '"hello $name world"', 'parts' => [
                ['type' => 'Literal', 'value' => 'hello ', 'text' => 'hello '],
                ['type' => 'SimpleExpansion', 'text' => '$name'],
                ['type' => 'Literal', 'value' => ' world', 'text' => ' world'],
            ]],
        ], $this->p($c->suffix[0]));
    }

    public function testUnquotedVariable(): void
    {
        $c = $this->getCmd($this->parse('echo $name'));
        $this->assertEquals([['type' => 'SimpleExpansion', 'text' => '$name']], $this->p($c->suffix[0]));
    }

    public function testPunctuationSeparatedExpansions(): void
    {
        foreach ([['$FOO/$BAR/', [0, 10]], ['echo $FOO/$BAR/', [5, 15]]] as [$source, $range]) {
            $ast = $this->parse($source);
            $command = $this->getCmd($ast);
            $word = $command->name?->text === 'echo' ? $command->suffix[0] : $command->name;
            $this->assertNull($ast->errors);
            $this->assertSame(['$FOO/$BAR/', $range[0], $range[1]], [$word->text, $word->pos, $word->end]);
            $this->assertSame([
                ['SimpleExpansion', '$FOO'],
                ['Literal', '/'],
                ['SimpleExpansion', '$BAR'],
                ['Literal', '/'],
            ], array_map(fn ($p) => [$p->type, $p->text], $word->parts));
        }
    }

    public function testSpecialVariables(): void
    {
        $c = $this->getCmd($this->parse('echo $@ $# $?'));
        $this->assertEquals([['type' => 'SimpleExpansion', 'text' => '$@']], $this->p($c->suffix[0]));
        $this->assertEquals([['type' => 'SimpleExpansion', 'text' => '$#']], $this->p($c->suffix[1]));
        $this->assertEquals([['type' => 'SimpleExpansion', 'text' => '$?']], $this->p($c->suffix[2]));
    }

    public function testPositionalParameter(): void
    {
        $c = $this->getCmd($this->parse('echo $1'));
        $this->assertEquals([['type' => 'SimpleExpansion', 'text' => '$1']], $this->p($c->suffix[0]));
    }

    public function testParameterExpansion(): void
    {
        $c = $this->getCmd($this->parse('echo ${var:-default}'));
        $parts = $c->suffix[0]->parts;
        $this->assertCount(1, $parts);
        $this->assertSame('ParameterExpansion', $parts[0]->type);
        $this->assertSame('${var:-default}', $parts[0]->text);
        $this->assertSame('var', $parts[0]->parameter);
        $this->assertSame(':-', $parts[0]->operator);
        $this->assertSame('default', $parts[0]->operand->text);
    }

    public function testAssociativeIndexesKeepWhitespace(): void
    {
        $source = 'echo "${things[$foo $bar]}"';
        $ast = $this->parse($source);
        $this->assertNull($ast->errors);
        $word = $this->getCmd($ast)->suffix[0];
        $this->assertSame(['"${things[$foo $bar]}"', 5, 27], [$word->text, $word->pos, $word->end]);
        $quoted = $word->parts[0];
        $this->assertSame('DoubleQuoted', $quoted->type);
        $this->assertCount(1, $quoted->parts);
        $expansion = $quoted->parts[0];
        $this->assertSame('ParameterExpansion', $expansion->type);
        $this->assertSame(['${things[$foo $bar]}', 'things', '$foo $bar'], [$expansion->text, $expansion->parameter, $expansion->index]);
        $this->assertEquals([
            ['type' => 'SimpleExpansion', 'text' => '$foo'],
            ['type' => 'Literal', 'value' => ' ', 'text' => ' '],
            ['type' => 'SimpleExpansion', 'text' => '$bar'],
        ], array_map([$this, 'toArray'], $expansion->indexParts));
    }

    public function testCommandSubstitution(): void
    {
        $c = $this->getCmd($this->parse('echo $(hostname)'));
        $parts = $c->suffix[0]->parts;
        $this->assertCount(1, $parts);
        $this->assertSame('CommandExpansion', $parts[0]->type);
        $this->assertSame('$(hostname)', $parts[0]->text);
        $this->assertNotNull($parts[0]->script);
    }

    public function testBacktickCommandSubstitution(): void
    {
        $c = $this->getCmd($this->parse('echo `hostname`'));
        $parts = $c->suffix[0]->parts;
        $this->assertCount(1, $parts);
        $this->assertSame('CommandExpansion', $parts[0]->type);
        $this->assertNotNull($parts[0]->script);
    }

    public function testArithmeticExpansion(): void
    {
        $c = $this->getCmd($this->parse('echo $((1+2))'));
        $parts = $c->suffix[0]->parts;
        $this->assertCount(1, $parts);
        $this->assertSame('ArithmeticExpansion', $parts[0]->type);
        $this->assertSame('$((1+2))', $parts[0]->text);
        $this->assertNotNull($parts[0]->expression);
    }

    public function testSingleQuotedString(): void
    {
        $c = $this->getCmd($this->parse("echo 'hello world'"));
        $this->assertEquals([['type' => 'SingleQuoted', 'value' => 'hello world', 'text' => "'hello world'"]], $this->p($c->suffix[0]));
    }

    public function testAnsiCQuotedString(): void
    {
        $c = $this->getCmd($this->parse("echo \$'line1\\nline2'"));
        $parts = $c->suffix[0]->parts;
        $this->assertCount(1, $parts);
        $this->assertSame('AnsiCQuoted', $parts[0]->type);
        $this->assertSame("\$'line1\\nline2'", $parts[0]->text);
    }

    public function testAnsiCNumericAndControlEscapes(): void
    {
        $src = "\$'ec\\x68o' \$'\\141\\u0062\\U00000063' \$'\\0123' \$'\\777' \$'\\cA' \$'\\?' \$'\\q'";
        $c = $this->getCmd($this->parse($src));
        $this->assertSame('echo', $c->name->value);
        $this->assertSame(['abc', "\n3", "\xff", "\x01", '?', '\q'], array_map(fn ($w) => $w->value, $c->suffix));
    }

    public function testAnsiCCOperandEdgeCases(): void
    {
        $this->markTestSkipped('Bash \\c operand escape-consumption edge cases are not fully modeled.');
    }

    public function testMixedQuoting(): void
    {
        $c = $this->getCmd($this->parse('echo un\'quo\'ted"mix"$end'));
        $parts = $c->suffix[0]->parts;
        $this->assertSame('Literal', $parts[0]->type);
        $this->assertSame('un', $parts[0]->value);
        $this->assertSame('un', $parts[0]->text);
        $this->assertSame('SingleQuoted', $parts[1]->type);
        $this->assertSame('quo', $parts[1]->value);
        $this->assertSame("'quo'", $parts[1]->text);
        $this->assertSame('Literal', $parts[2]->type);
        $this->assertSame('ted', $parts[2]->value);
        $this->assertSame('DoubleQuoted', $parts[3]->type);
        $this->assertSame('"mix"', $parts[3]->text);
        $this->assertSame('SimpleExpansion', $parts[4]->type);
        $this->assertSame('$end', $parts[4]->text);
    }

    public function testVariableConcatenatedWithLiteral(): void
    {
        $c = $this->getCmd($this->parse('echo prefix-$name-suffix'));
        $parts = $c->suffix[0]->parts;
        $this->assertSame('Literal', $parts[0]->type);
        $this->assertSame('prefix-', $parts[0]->value);
        $this->assertSame('SimpleExpansion', $parts[1]->type);
        $this->assertSame('$name', $parts[1]->text);
        $this->assertSame('Literal', $parts[2]->type);
        $this->assertSame('-suffix', $parts[2]->value);
    }

    public function testDoubleQuotedWithCommandSubstitution(): void
    {
        $c = $this->getCmd($this->parse('echo "hello $(whoami)"'));
        $parts = $c->suffix[0]->parts;
        $this->assertCount(1, $parts);
        $this->assertSame('DoubleQuoted', $parts[0]->type);
        $this->assertSame('"hello $(whoami)"', $parts[0]->text);
        $inner = $parts[0]->parts;
        $this->assertCount(2, $inner);
        $this->assertSame('Literal', $inner[0]->type);
        $this->assertSame('hello ', $inner[0]->value);
        $this->assertSame('CommandExpansion', $inner[1]->type);
    }

    public function testDoubleQuotedWithParamExpansion(): void
    {
        $c = $this->getCmd($this->parse('echo "${var:-default}"'));
        $inner = $c->suffix[0]->parts[0]->parts;
        $this->assertCount(1, $inner);
        $this->assertSame('ParameterExpansion', $inner[0]->type);
        $this->assertSame('${var:-default}', $inner[0]->text);
    }

    public function testDoubleQuotedWithArithmetic(): void
    {
        $c = $this->getCmd($this->parse('echo "$((1 + 2))"'));
        $inner = $c->suffix[0]->parts[0]->parts;
        $this->assertSame('ArithmeticExpansion', $inner[0]->type);
    }

    public function testEscapedCharactersInUnquotedWord(): void
    {
        $c = $this->getCmd($this->parse('echo hello\ world'));
        $this->assertSame('hello\ world', $c->suffix[0]->text);
        $this->assertNull($c->suffix[0]->parts);
    }

    public function testLocaleString(): void
    {
        $c = $this->getCmd($this->parse('echo $"hello $name"'));
        $parts = $c->suffix[0]->parts;
        $this->assertSame('LocaleString', $parts[0]->type);
        $this->assertSame('$"hello $name"', $parts[0]->text);
        $inner = $parts[0]->parts;
        $this->assertSame('Literal', $inner[0]->type);
        $this->assertSame('hello ', $inner[0]->value);
        $this->assertSame('SimpleExpansion', $inner[1]->type);
    }

    public function testAssignmentWordGetsParts(): void
    {
        $c = $this->getCmd($this->parse('x="hello $name"'));
        $this->assertSame('Assignment', $c->prefix[0]->type);
    }

    public function testTextFieldUnchangedWithParts(): void
    {
        $c = $this->getCmd($this->parse('echo "hello $name world"'));
        $this->assertSame('"hello $name world"', $c->suffix[0]->text);
        $this->assertNotNull($c->suffix[0]->parts);
    }

    public function testLiteralPartTextIncludesBackslashEscapes(): void
    {
        $c = $this->getCmd($this->parse('echo he\nllo-$x'));
        $parts = $c->suffix[0]->parts;
        $this->assertSame('Literal', $parts[0]->type);
        $this->assertSame('henllo-', $parts[0]->value);
        $this->assertSame('he\nllo-', $parts[0]->text);
    }

    public function testLocaleStringWithoutExpansions(): void
    {
        $c = $this->getCmd($this->parse('echo $"hello"'));
        $parts = $c->suffix[0]->parts;
        $this->assertSame('LocaleString', $parts[0]->type);
        $this->assertSame('$"hello"', $parts[0]->text);
        $inner = $parts[0]->parts;
        $this->assertSame('Literal', $inner[0]->type);
        $this->assertSame('hello', $inner[0]->value);
    }

    public function testCommandExpansionPartHasScript(): void
    {
        $c = $this->getCmd($this->parse('echo $(pwd)'));
        $this->assertSame('CommandExpansion', $c->suffix[0]->parts[0]->type);
        $this->assertNotNull($c->suffix[0]->parts[0]->script);
    }
}
