<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests\Reference;

use ReactphpX\Unbash\Node;

/** Ported from webpro-nl/unbash test/compound-commands.test.ts */
final class CompoundCommandsPortTest extends RefTestCase
{
    public function testSubshell(): void
    {
        $sub = $this->parse('(cmd1; cmd2)')->commands[0]->command;
        $this->assertSame('Subshell', $sub->type);
        $this->assertCount(2, $sub->body->commands);
    }

    public function testBraceGroup(): void
    {
        $bg = $this->parse('{ echo a; echo b; }')->commands[0]->command;
        $this->assertSame('BraceGroup', $bg->type);
        $this->assertCount(2, $bg->body->commands);
    }

    public function testReservedTerminatorFollowsCompoundNoSeparator(): void
    {
        foreach ([
            '{ (echo a) }', '{ [[ x == x ]] }', '{ { echo a; } }',
            '{ if true; then echo a; fi }', '{ for i in a; do echo $i; done }',
            '{ while false; do echo a; done }', '{ case x in x) echo a;; esac }',
            '{ ((1 + 1)) }', '{ f() { echo a; } }', '{ echo a | (cat) }',
            '{ true && (echo a) }', '{ ! (echo a) }',
            'if true; then (echo a) fi', 'while false; do (echo a) done',
        ] as $source) {
            $this->assertNull($this->parse($source)->errors, $source);
        }
    }

    public function testReservedTerminatorStillNeedsSeparatorAfterSimpleCommand(): void
    {
        foreach (['{ echo a }', '{ (echo a) (echo b) }', '{ (echo a) echo b }'] as $source) {
            $this->assertNotNull($this->parse($source)->errors, $source);
        }
    }

    public function testDeeplyNestedCommandSubstitutions(): void
    {
        $this->assertGreaterThan(0, count($this->parse('(echo $(echo $(echo deep)))')->commands));
    }

    public function testPipedSubshellsWithRead(): void
    {
        $ast = $this->parse('(echo start; cat file) | grep pattern | (read line; echo "Got: $line")');
        $this->assertGreaterThan(0, count($ast->commands));
    }

    public function testIfThenFi(): void
    {
        $if = $this->parse('if test cond; then echo yes; fi')->commands[0]->command;
        $this->assertSame('If', $if->type);
        $this->assertNotNull($if->then);
        $this->assertNull($if->else);
    }

    public function testIfElifElse(): void
    {
        $if = $this->parse('if a; then b; elif c; then d; else e; fi')->commands[0]->command;
        $this->assertSame('If', $if->type);
        $this->assertNotNull($if->else);
        $this->assertSame('If', $if->else->type);
        $this->assertNotNull($if->else->else);
    }

    public function testRedirectedPipelinesDoNotMergeIfs(): void
    {
        $source = "#!/usr/bin/env bash\n\nset -eo pipefail\nshopt -s inherit_errexit\n\nif ! rg -xF '^refs/heads/*-deploy' < <(git config --get-all remote.origin.fetch) >/dev/null &&\n    rg -- '-deploy\$' < <(git branch -r -l) >/dev/null; then\n    git config --add remote.origin.fetch '^refs/heads/*-deploy'\n    git branch -lr | awk '\$1 ~ /-deploy\$/ { printf \"%s%s\", \$1, \"\\0\" }' | xargs -0 -- git branch -r -d\nfi\n\nif ! rg -xF '^refs/tags/*-deploy' < <(git config --get-all remote.origin.fetch) >/dev/null &&\n    rg -- '-deploy\$' < <(git tag -l) >/dev/null; then\n    git config --add remote.origin.fetch '^refs/tags/*-deploy'\n    git tag -l | rg -- '-deploy\$' | tr '\\n' '\\0' | xargs -0 -- git tag -d\nfi";
        $ast = $this->parse($source);
        $this->assertNull($ast->errors);
        $this->assertSame([
            ['Command', 21, 37],
            ['Command', 38, 62],
            ['If', 64, 387],
            ['If', 389, 676],
        ], array_map(fn ($s) => [$s->command->type, $s->pos, $s->end], $ast->commands));
    }

    public function testForLoop(): void
    {
        $f = $this->parse('for x in a b c; do echo $x; done')->commands[0]->command;
        $this->assertSame('For', $f->type);
        $this->assertSame('x', $f->name->text);
        $this->assertCount(3, $f->wordlist);
        $this->assertCount(1, $f->body->commands);
    }

    public function testBraceGroupAsLoopBody(): void
    {
        foreach ([
            'for x in a b; { echo $x; }',
            "for x in a b\n{ echo \$x; }",
            'for (( i=0; i<2; i++ )); { echo $i; }',
            'select x in a b; { echo $x; }',
        ] as $source) {
            $ast = $this->parse($source);
            $command = $ast->commands[0]->command;
            $this->assertNull($ast->errors, $source);
            $this->assertCount(1, $command->body->commands, $source);
            $this->assertSame(strlen($source), $command->end, $source);
        }
    }

    public function testBraceGroupNotLoopBodyForWhileUntilIf(): void
    {
        foreach (['while true; { break; }', 'until true; { break; }', 'if true; { :; }'] as $source) {
            $this->assertNotNull($this->parse($source)->errors, $source);
        }
    }

    public function testCStyleForProducesArithmeticFor(): void
    {
        $af = $this->parse('for (( c=1; c<=5; c++ )); do echo $c; done')->commands[0]->command;
        $this->assertSame('ArithmeticFor', $af->type);
        $this->assertNotNull($af->initialize);
        $this->assertNotNull($af->test);
        $this->assertNotNull($af->update);
        $this->assertSame('echo', $af->body->commands[0]->command->name->text);
    }

    public function testCStyleForInfinite(): void
    {
        $af = $this->parse('for (( ; ; )); do echo forever; done')->commands[0]->command;
        $this->assertSame('ArithmeticFor', $af->type);
        $this->assertNull($af->initialize);
        $this->assertNull($af->test);
        $this->assertNull($af->update);
    }

    public function testWhileLoop(): void
    {
        $w = $this->parse('while true; do echo hi; done')->commands[0]->command;
        $this->assertSame('While', $w->type);
        $this->assertCount(1, $w->body->commands);
        $this->assertSame('while', $w->kind);
    }

    public function testUntilLoop(): void
    {
        $w = $this->parse('until false; do echo hi; done')->commands[0]->command;
        $this->assertSame('While', $w->type);
        $this->assertSame('until', $w->kind);
    }

    public function testUntilWithArithmetic(): void
    {
        $w = $this->parse("until [ \"\$count\" -ge 10 ]; do\n    count=\$((count + 1))\ndone")->commands[0]->command;
        $this->assertSame('until', $w->kind);
    }

    public function testCase(): void
    {
        $c = $this->parse("case \"\$x\" in\n  a) echo a ;;\n  b) echo b ;;\nesac")->commands[0]->command;
        $this->assertSame('Case', $c->type);
        $this->assertCount(2, $c->items);
        $this->assertSame('a', $c->items[0]->pattern[0]->text);
        $this->assertSame('echo', $c->items[0]->body->commands[0]->command->name->text);
        $this->assertSame('b', $c->items[1]->pattern[0]->text);
    }

    public function testCasePatternWords(): void
    {
        foreach ([
            ["#!/bin/bash\n\ncase \"\$1\" in\n  -first) echo 1;;\n  second) echo 2;;\n  third) echo 3;;\n  *) echo wildcard;;\nesac\n", 0, ['-first', 28, 34]],
            ["#!/bin/bash\n\ncase \"\$1\" in\n  first) echo 1;;\n  second) echo 2;;\n  third) echo 3;;\n  *) echo wildcard;;\nesac\n", 0, ['first', 28, 33]],
            ["#!/bin/bash\n\ncase \"\$1\" in\n  first) echo 1;;\n  s_econd) echo 2;;\n  third) echo 3;;\n  *) echo wildcard;;\nesac\n", 1, ['s_econd', 46, 53]],
        ] as [$source, $idx, $expected]) {
            $ast = $this->parse($source);
            $this->assertNull($ast->errors, $source);
            $command = $ast->commands[0]->command;
            $this->assertSame('Case', $command->type, $source);
            $this->assertSame([$expected], array_map(fn ($w) => [$w->text, $w->pos, $w->end], $command->items[$idx]->pattern), $source);
        }
    }

    public function testCaseFallthroughTerminators(): void
    {
        $c = $this->parse('case a in a) echo A ;& *) echo star ;; esac')->commands[0]->command;
        $this->assertCount(2, $c->items);
        $this->assertSame(';&', $c->items[0]->terminator);
        $this->assertSame(';;', $c->items[1]->terminator);

        $c2 = $this->parse('case x in a) echo a ;;& b) echo b ;; esac')->commands[0]->command;
        $this->assertSame(';;&', $c2->items[0]->terminator);
        $this->assertSame(';;', $c2->items[1]->terminator);
    }

    public function testCaseMultiplePatterns(): void
    {
        $c = $this->parse("case \"\$Z\" in\n  ab*|cd*) ef ;;\nesac")->commands[0]->command;
        $this->assertCount(2, $c->items[0]->pattern);
    }

    public function testCaseEmptyStringPattern(): void
    {
        $c = $this->parse("case \$empty in ''|foo) echo match ;; esac")->commands[0]->command;
        $this->assertCount(2, $c->items[0]->pattern);
    }

    public function testCaseFallthroughRealWorld(): void
    {
        $c = $this->parse("case \$cmd in\n  start) systemctl start app ;;&\n  *) echo \"done\" ;;\nesac")->commands[0]->command;
        $this->assertSame(';;&', $c->items[0]->terminator);
    }

    public function testSelect(): void
    {
        $s = $this->parse('select i in 1 2 3; do echo $i; done')->commands[0]->command;
        $this->assertSame('Select', $s->type);
        $this->assertSame('i', $s->name->text);
        $this->assertCount(3, $s->wordlist);
        $this->assertSame('echo', $s->body->commands[0]->command->name->text);
    }

    public function testSelectWithoutIn(): void
    {
        $s = $this->parse('select opt; do echo $opt; done')->commands[0]->command;
        $this->assertSame('Select', $s->type);
        $this->assertSame('opt', $s->name->text);
    }

    public function testSelectWithNestedCase(): void
    {
        $ast = $this->parse("select opt in \"Option 1\" \"Option 2\" \"Quit\"; do\n    case \$opt in\n        Quit) break;;\n        *) echo \$opt;;\n    esac\ndone");
        $this->assertSame('Select', $ast->commands[0]->command->type);
    }

    public function testFunctionDefinition(): void
    {
        $fn = $this->parse('f() { echo hello; }')->commands[0]->command;
        $this->assertSame('Function', $fn->type);
        $this->assertSame('f', $fn->name->text);
    }

    public function testFunctionKeyword(): void
    {
        $fn = $this->parse('function f { echo hello; }')->commands[0]->command;
        $this->assertSame('Function', $fn->type);
        $this->assertSame('f', $fn->name->text);
    }

    public function testParenthesizedFunctionBody(): void
    {
        foreach (['function f ( echo hi )', 'function f (echo hi)', 'function f () ( echo hi )'] as $source) {
            $ast = $this->parse($source);
            $fn = $ast->commands[0]->command;
            $this->assertNull($ast->errors, $source);
            $this->assertSame('Function', $fn->type, $source);
            $this->assertSame('f', $fn->name->text, $source);
            $this->assertSame('Subshell', $fn->body->type, $source);
            $this->assertCount(1, $fn->body->body->commands, $source);
            $this->assertSame(strlen($source), $fn->end, $source);
        }
    }

    public function testFunctionThenCall(): void
    {
        $ast = $this->parse('f() { vite build "$@"; }; f');
        $this->assertCount(2, $ast->commands);
        $this->assertSame('f', $ast->commands[0]->command->name->text);
        $this->assertSame('f', $this->getCmd($ast, 1)->name->text);
    }

    public function testCoprocSimpleCommand(): void
    {
        $cp = $this->parse('coproc cat')->commands[0]->command;
        $this->assertSame('Coproc', $cp->type);
        $this->assertSame('Command', $cp->body->type);
        $this->assertNull($cp->name);
    }

    public function testCoprocWithCompoundCommand(): void
    {
        $cp = $this->parse('coproc { read line; echo "$line"; }')->commands[0]->command;
        $this->assertSame('Coproc', $cp->type);
        $this->assertSame('BraceGroup', $cp->body->type);
    }

    public function testCoprocWithNameAndCompound(): void
    {
        $cp = $this->parse('coproc mycoproc { cat; }')->commands[0]->command;
        $this->assertSame('Coproc', $cp->type);
        $this->assertSame('mycoproc', $cp->name->text);
        $this->assertSame('BraceGroup', $cp->body->type);
    }

    public function testCoprocWithNameAndSubshell(): void
    {
        $cp = $this->parse('coproc mycoproc ( cat )')->commands[0]->command;
        $this->assertSame('Coproc', $cp->type);
        $this->assertSame('mycoproc', $cp->name->text);
        $this->assertSame('Subshell', $cp->body->type);
    }

    public function testCoprocSimpleWithArguments(): void
    {
        $cp = $this->parse('coproc foo bar')->commands[0]->command;
        $this->assertSame('Coproc', $cp->type);
        $this->assertNull($cp->name);
        $this->assertSame('Command', $cp->body->type);
        $this->assertSame('foo', $cp->body->name->text);
        $this->assertSame(['bar'], array_map(fn ($s) => $s->text, $cp->body->suffix));
    }

    public function testCoprocSimpleMultipleArguments(): void
    {
        $cp = $this->parse('coproc foo bar baz')->commands[0]->command;
        $this->assertNull($cp->name);
        $this->assertSame('foo', $cp->body->name->text);
        $this->assertSame(['bar', 'baz'], array_map(fn ($s) => $s->text, $cp->body->suffix));
    }

    public function testCoprocWithoutNamePipeGoesOuter(): void
    {
        $ast = $this->parse('coproc foo | bar');
        $this->assertCount(1, $ast->commands);
        $pl = $ast->commands[0]->command;
        $this->assertSame('Pipeline', $pl->type);
        $this->assertCount(2, $pl->commands);
        $this->assertSame('Coproc', $pl->commands[0]->type);
        $this->assertSame('bar', $pl->commands[1]->name->text);
    }

    public function testArithmeticOnlyWhenInnerPairCloses(): void
    {
        $cases = [
            ['((a))', 'ArithmeticCommand'],
            ['(( a ))', 'ArithmeticCommand'],
            ['(((a)))', 'ArithmeticCommand'],
            ['(( (a) ))', 'ArithmeticCommand'],
            ['((a) )', 'Subshell'],
            ['((a) || (b))', 'Subshell'],
            ['((a); b)', 'Subshell'],
            ['((a)|(b))', 'Subshell'],
            ['((echo 1); echo 2)', 'Subshell'],
        ];
        foreach ($cases as [$head, $type]) {
            $source = "$head; z";
            $ast = $this->parse($source);
            $this->assertNull($ast->errors, $source);
            $this->assertSame($type, $ast->commands[0]->command->type, $source);
            $this->assertSame(strlen($head), $ast->commands[0]->end, $source);
            $this->assertCount(2, $ast->commands, $source);
        }
    }

    public function testArithmeticSubshellsInClauseHead(): void
    {
        foreach (['if ((a) || (b)); then :; fi', 'while ((t) ); do :; done'] as $source) {
            $ast = $this->parse($source);
            $this->assertNull($ast->errors, $source);
            $this->assertSame(strlen($source), $ast->commands[0]->end, $source);
        }
    }
}
