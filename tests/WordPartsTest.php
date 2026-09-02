<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests;

use PHPUnit\Framework\TestCase;
use ReactphpX\Unbash\Node;
use ReactphpX\Unbash\Word;

use function ReactphpX\Unbash\parse;

final class WordPartsTest extends TestCase
{
    private function firstSuffix(string $src, int $i = 0): Word
    {
        return parse($src)->commands[0]->command->suffix[$i];
    }

    public function testPlainWordHasNoParts(): void
    {
        $w = $this->firstSuffix('echo hello');
        $this->assertNull($w->parts);
        $this->assertSame('hello', $w->value);
    }

    public function testBackslashEscapeInBareWord(): void
    {
        $w = $this->firstSuffix('echo a\\ b');
        // The escaped space keeps the word together; value drops the backslash.
        $this->assertSame('a b', $w->value);
        $this->assertNull($w->parts);
    }

    public function testSingleQuoted(): void
    {
        $w = $this->firstSuffix("echo 'a\$b\"c'");
        $this->assertSame('SingleQuoted', $w->parts[0]->type);
        $this->assertSame('a$b"c', $w->parts[0]->value);
        $this->assertSame('a$b"c', $w->value);
    }

    public function testDoubleQuotedWithExpansion(): void
    {
        $w = $this->firstSuffix('echo "$HOME/bin"');
        $this->assertSame('DoubleQuoted', $w->parts[0]->type);
        $inner = $w->parts[0]->parts;
        $this->assertSame('SimpleExpansion', $inner[0]->type);
        $this->assertSame('$HOME', $inner[0]->text);
        $this->assertSame('Literal', $inner[1]->type);
        $this->assertSame('$HOME/bin', $w->value);
    }

    public function testMixedLiteralAndCommandExpansion(): void
    {
        $w = $this->firstSuffix('echo a$(id)b');
        $types = array_map(static fn (Node $p) => $p->type, $w->parts);
        $this->assertSame(['Literal', 'CommandExpansion', 'Literal'], $types);
        $this->assertSame('id', $w->parts[1]->script->commands[0]->command->name->text);
    }

    public function testAnsiCQuoting(): void
    {
        $w = $this->firstSuffix("printf \$'a\\tb\\n'", 0);
        $this->assertSame('AnsiCQuoted', $w->parts[0]->type);
        $this->assertSame("a\tb\n", $w->parts[0]->value);
    }

    public function testLocaleString(): void
    {
        $w = $this->firstSuffix('echo $"hello"');
        $this->assertSame('LocaleString', $w->parts[0]->type);
    }

    public function testSimpleExpansionSpecialParams(): void
    {
        $w = $this->firstSuffix('echo $?$#$@');
        $texts = array_map(static fn (Node $p) => $p->text, $w->parts);
        $this->assertSame(['$?', '$#', '$@'], $texts);
    }

    public function testParameterExpansionDefault(): void
    {
        $p = $this->firstSuffix('echo ${NAME:-anon}')->parts[0];
        $this->assertSame('ParameterExpansion', $p->type);
        $this->assertSame('NAME', $p->parameter);
        $this->assertSame(':-', $p->operator);
        $this->assertSame('anon', $p->operand->value);
    }

    public function testParameterExpansionLength(): void
    {
        $p = $this->firstSuffix('echo ${#var}')->parts[0];
        $this->assertTrue($p->length);
        $this->assertSame('var', $p->parameter);
    }

    public function testParameterExpansionIndirect(): void
    {
        $p = $this->firstSuffix('echo ${!ref}')->parts[0];
        $this->assertTrue($p->indirect);
        $this->assertSame('ref', $p->parameter);
    }

    public function testParameterExpansionSlice(): void
    {
        $p = $this->firstSuffix('echo ${var:2:5}')->parts[0];
        $this->assertNotNull($p->slice);
        $this->assertSame('2', $p->slice['offset']->value);
        $this->assertSame('5', $p->slice['length']->value);
    }

    public function testParameterExpansionReplace(): void
    {
        $p = $this->firstSuffix('echo ${path//\//_}')->parts[0];
        $this->assertNotNull($p->replace);
    }

    public function testArithmeticExpansion(): void
    {
        $p = $this->firstSuffix('echo $((1 + 2))')->parts[0];
        $this->assertSame('ArithmeticExpansion', $p->type);
        $this->assertSame('ArithmeticBinary', $p->expression->type);
        $this->assertSame('+', $p->expression->operator);
    }

    public function testProcessSubstitution(): void
    {
        $w = $this->firstSuffix('diff <(sort a)');
        $this->assertSame('ProcessSubstitution', $w->parts[0]->type);
        $this->assertSame('<', $w->parts[0]->operator);
        $this->assertSame('sort', $w->parts[0]->script->commands[0]->command->name->text);
    }

    public function testBraceExpansion(): void
    {
        $w = $this->firstSuffix('echo {a,b,c}');
        $this->assertSame('BraceExpansion', $w->parts[0]->type);
    }

    public function testBraceRange(): void
    {
        $w = $this->firstSuffix('echo {1..5}');
        $this->assertSame('BraceExpansion', $w->parts[0]->type);
    }

    public function testNotBraceExpansionWithoutCommaOrRange(): void
    {
        $w = $this->firstSuffix('echo {single}');
        $this->assertNull($w->parts);
    }

    public function testExtendedGlob(): void
    {
        $w = $this->firstSuffix('ls @(foo|bar)');
        $this->assertSame('ExtendedGlob', $w->parts[0]->type);
        $this->assertSame('@', $w->parts[0]->operator);
        $this->assertSame('foo|bar', $w->parts[0]->pattern);
    }

    public function testBacktickCommandExpansion(): void
    {
        $w = $this->firstSuffix('echo `whoami`');
        $this->assertSame('CommandExpansion', $w->parts[0]->type);
        $this->assertSame('whoami', $w->parts[0]->script->commands[0]->command->name->text);
    }

    public function testNestedCommandSubstitution(): void
    {
        $w = $this->firstSuffix('echo $(echo $(id -u))');
        $outer = $w->parts[0];
        $this->assertSame('CommandExpansion', $outer->type);
        $innerCmd = $outer->script->commands[0]->command;
        $innerWord = $innerCmd->suffix[0];
        $this->assertSame('CommandExpansion', $innerWord->parts[0]->type);
        $this->assertSame('id', $innerWord->parts[0]->script->commands[0]->command->name->text);
    }
}
