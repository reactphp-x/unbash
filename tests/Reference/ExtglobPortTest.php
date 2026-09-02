<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests\Reference;

use ReactphpX\Unbash\Node;
use ReactphpX\Unbash\Word;

/** Ported from webpro-nl/unbash test/extglob.test.ts */
final class ExtglobPortTest extends RefTestCase
{
    /** @return Node[]|null */
    private function wp(Word $w): ?array
    {
        return $w->parts;
    }

    public function testBang(): void
    {
        $part = $this->wp($this->getCmd($this->parse('echo !(*.txt)'))->suffix[0])[0];
        $this->assertSame('ExtendedGlob', $part->type);
        $this->assertSame('!', $part->operator);
        $this->assertSame('*.txt', $part->pattern);
    }

    public function testAt(): void
    {
        $part = $this->wp($this->getCmd($this->parse('echo @(foo|bar|baz)'))->suffix[0])[0];
        $this->assertSame('ExtendedGlob', $part->type);
        $this->assertSame('@', $part->operator);
        $this->assertSame('foo|bar|baz', $part->pattern);
    }

    public function testQuestion(): void
    {
        $parts = $this->wp($this->getCmd($this->parse('echo ?(pre)fix'))->suffix[0]);
        $this->assertSame('ExtendedGlob', $parts[0]->type);
        $this->assertSame('?', $parts[0]->operator);
        $this->assertSame('Literal', $parts[1]->type);
        $this->assertSame('fix', $parts[1]->value);
    }

    public function testPlus(): void
    {
        $part = $this->wp($this->getCmd($this->parse('echo +(digit)'))->suffix[0])[0];
        $this->assertSame('ExtendedGlob', $part->type);
        $this->assertSame('+', $part->operator);
    }

    public function testStar(): void
    {
        $part = $this->wp($this->getCmd($this->parse('echo *(any)thing'))->suffix[0])[0];
        $this->assertSame('ExtendedGlob', $part->type);
        $this->assertSame('*', $part->operator);
    }

    public function testTextPreserved(): void
    {
        $this->assertSame('!(*.log|*.tmp)', $this->getCmd($this->parse('echo !(*.log|*.tmp)'))->suffix[0]->text);
    }

    public function testLiteralPrefix(): void
    {
        $parts = $this->wp($this->getCmd($this->parse('echo file_!(*.bak)'))->suffix[0]);
        $this->assertSame('Literal', $parts[0]->type);
        $this->assertSame('file_', $parts[0]->value);
        $this->assertSame('ExtendedGlob', $parts[1]->type);
    }

    public function testPathnamePrefixedExtglobNestedInRm(): void
    {
        $source = "function check_pools() { (\n    shopt -s nullglob extglob\n    rm -fv /etc/php/*/fpm/pool.d/!(*.conf|*.orig|*.dpkg-dist)\n); }";
        $ast = $this->parse($source);
        $this->assertNull($ast->errors);
        $fn = $ast->commands[0]->command;
        $this->assertSame(['Function', 0, 123], [$fn->type, $fn->pos, $fn->end]);
        $this->assertSame('BraceGroup', $fn->body->type);
        $subshell = $fn->body->body->commands[0]->command;
        $this->assertSame(['Subshell', 25, 120], [$subshell->type, $subshell->pos, $subshell->end]);
        $this->assertCount(2, $subshell->body->commands);
        $rm = $subshell->body->commands[1]->command;
        $this->assertSame(['Command', 61, 118, 'rm'], [$rm->type, $rm->pos, $rm->end, $rm->name->text]);
        $path = $rm->suffix[1];
        $this->assertSame(['/etc/php/*/fpm/pool.d/!(*.conf|*.orig|*.dpkg-dist)', 68, 118], [$path->text, $path->pos, $path->end]);
        $this->assertEquals([
            ['type' => 'Literal', 'value' => '/etc/php/*/fpm/pool.d/', 'text' => '/etc/php/*/fpm/pool.d/'],
            ['type' => 'ExtendedGlob', 'text' => '!(*.conf|*.orig|*.dpkg-dist)', 'operator' => '!', 'pattern' => '*.conf|*.orig|*.dpkg-dist', 'parts' => null],
        ], $this->partsArr($path));
    }

    public function testEqualsParenIsNotExtglob(): void
    {
        $c = $this->getCmd($this->parse('x=(a b c)'));
        $this->assertCount(1, $c->prefix);
        $this->assertSame('Assignment', $c->prefix[0]->type);
    }

    public function testPreservedInWord(): void
    {
        foreach ([
            ['ls ?(foo|bar)', '?(foo|bar)'],
            ['ls @(a|b|c)', '@(a|b|c)'],
            ['ls *(pat)', '*(pat)'],
            ['ls +(x|y)', '+(x|y)'],
            ['ls !(bad)', '!(bad)'],
            ['ls @(a|+(b|c))', '@(a|+(b|c))'],
        ] as [$source, $text]) {
            $this->assertSame([$text], array_map(fn ($s) => $s->text, $this->getCmd($this->parse($source))->suffix), $source);
        }
    }

    public function testCaseLiteralInsideExtglob(): void
    {
        $c = $this->getCmd($this->parse('echo @(case|foo) tail'));
        $this->assertSame(['@(case|foo)', 'tail'], array_map(fn ($s) => $s->text, $c->suffix));
    }

    public function testExtglobInTest(): void
    {
        $this->assertGreaterThan(0, count($this->parse('[[ ${f} != */@(default).vim ]]')->commands));
    }

    public function testCommandSubstitutionsInsideExtglob(): void
    {
        $part = $this->wp($this->getCmd($this->parse('echo @($(one)|safe$(two))'))->suffix[0])[0];
        $this->assertSame('ExtendedGlob', $part->type);
        $names = [];
        foreach ($part->parts as $child) {
            if ($child->type === 'CommandExpansion') {
                $names[] = $child->script->commands[0]->command->name->value;
            }
        }
        $this->assertSame(['one', 'two'], $names);
    }

    public function testProcessSubstitutionsInsideExtglob(): void
    {
        $part = $this->wp($this->getCmd($this->parse('echo @(<(danger)|safe)'))->suffix[0])[0];
        $this->assertSame('ExtendedGlob', $part->type);
        $found = null;
        foreach ($part->parts as $child) {
            if ($child->type === 'ProcessSubstitution') {
                $found = $child;
            }
        }
        $this->assertNotNull($found);
        $this->assertSame('danger', $found->script->commands[0]->command->name->value);
    }

    public function testClosingParensInsideSubstitutions(): void
    {
        $part = $this->wp($this->getCmd($this->parse('echo @($(printf ")")|safe)'))->suffix[0])[0];
        $this->assertSame('ExtendedGlob', $part->type);
        $this->assertSame('$(printf ")")|safe', $part->pattern);
        $found = null;
        foreach ($part->parts as $child) {
            if ($child->type === 'CommandExpansion') {
                $found = $child;
            }
        }
        $this->assertSame('printf', $found->script->commands[0]->command->name->value);
    }

    public function testUnterminatedExtglob(): void
    {
        $src = 'echo @(safe|$(danger)';
        $ast = $this->parse($src);
        $part = $this->wp($this->getCmd($ast)->suffix[0])[0];
        $this->assertSame('ExtendedGlob', $part->type);
        $found = null;
        foreach ($part->parts as $child) {
            if ($child->type === 'CommandExpansion') {
                $found = $child;
            }
        }
        $this->assertSame('danger', $found->script->commands[0]->command->name->value);
        $this->assertNotNull($ast->errors);
        $messages = array_map(fn ($e) => $e->message, $ast->errors);
        $this->assertContains('unterminated extended glob', $messages);
    }
}
