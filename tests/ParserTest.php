<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests;

use PHPUnit\Framework\TestCase;
use ReactphpX\Unbash\Node;
use ReactphpX\Unbash\Word;

use function ReactphpX\Unbash\parse;

final class ParserTest extends TestCase
{
    public function testEmptyInput(): void
    {
        $ast = parse('');
        $this->assertSame('Script', $ast->type);
        $this->assertSame([], $ast->commands);
        $this->assertSame([], $ast->errors);
    }

    public function testSimpleCommand(): void
    {
        $ast = parse('echo hello world');
        $cmd = $ast->commands[0]->command;
        $this->assertSame('Command', $cmd->type);
        $this->assertSame('echo', $cmd->name->text);
        $this->assertSame(['hello', 'world'], array_map(static fn (Word $w) => $w->text, $cmd->suffix));
    }

    public function testShebang(): void
    {
        $ast = parse("#!/usr/bin/env bash\necho hi");
        $this->assertSame('#!/usr/bin/env bash', $ast->shebang);
        $this->assertSame('echo', $ast->commands[0]->command->name->text);
    }

    public function testComment(): void
    {
        $ast = parse("echo a # trailing comment\necho b");
        $this->assertCount(2, $ast->commands);
        $this->assertSame('a', $ast->commands[0]->command->suffix[0]->text);
        $this->assertSame('b', $ast->commands[1]->command->suffix[0]->text);
    }

    public function testPipeline(): void
    {
        $ast = parse('a | b | c');
        $p = $ast->commands[0]->command;
        $this->assertSame('Pipeline', $p->type);
        $this->assertCount(3, $p->commands);
        $this->assertSame(['|', '|'], $p->operators);
    }

    public function testPipelineBothStreams(): void
    {
        $p = parse('make |& tee log')->commands[0]->command;
        $this->assertSame('Pipeline', $p->type);
        $this->assertSame(['|&'], $p->operators);
    }

    public function testNegatedPipeline(): void
    {
        $p = parse('! grep -q x file')->commands[0]->command;
        $this->assertSame('Pipeline', $p->type);
        $this->assertTrue($p->negated);
    }

    public function testTimedPipeline(): void
    {
        $p = parse('time sleep 1')->commands[0]->command;
        $this->assertSame('Pipeline', $p->type);
        $this->assertTrue($p->time);
    }

    public function testAndOr(): void
    {
        $a = parse('a && b || c')->commands[0]->command;
        $this->assertSame('AndOr', $a->type);
        $this->assertSame(['&&', '||'], $a->operators);
        $this->assertCount(3, $a->commands);
    }

    public function testBackground(): void
    {
        $stmt = parse('sleep 5 &')->commands[0];
        $this->assertTrue($stmt->background);
    }

    public function testSemicolonSeparation(): void
    {
        $ast = parse('a; b; c');
        $this->assertCount(3, $ast->commands);
    }

    public function testAssignmentPrefix(): void
    {
        $cmd = parse('FOO=bar BAZ=qux env')->commands[0]->command;
        $this->assertCount(2, $cmd->prefix);
        $this->assertSame('FOO', $cmd->prefix[0]->name);
        $this->assertSame('bar', $cmd->prefix[0]->value->value);
        $this->assertSame('env', $cmd->name->text);
    }

    public function testStandaloneAssignment(): void
    {
        $cmd = parse('count=0')->commands[0]->command;
        $this->assertNull($cmd->name);
        $this->assertSame('count', $cmd->prefix[0]->name);
        $this->assertSame('0', $cmd->prefix[0]->value->value);
    }

    public function testAppendAssignment(): void
    {
        $asg = parse('PATH+=:/opt/bin')->commands[0]->command->prefix[0];
        $this->assertTrue($asg->append);
        $this->assertSame('PATH', $asg->name);
    }

    public function testArrayAssignment(): void
    {
        $asg = parse('arr=(a b c)')->commands[0]->command->prefix[0];
        $this->assertSame('arr', $asg->name);
        $this->assertNull($asg->value);
        $this->assertCount(3, $asg->array);
        $this->assertSame(['a', 'b', 'c'], array_map(static fn (Word $w) => $w->text, $asg->array));
    }

    public function testIndexedAssignment(): void
    {
        $asg = parse('arr[2]=x')->commands[0]->command->prefix[0];
        $this->assertSame('arr', $asg->name);
        $this->assertSame('2', $asg->index);
        $this->assertSame('x', $asg->value->value);
    }

    public function testRedirectOut(): void
    {
        $r = parse('echo hi > out.txt')->commands[0]->command->redirects[0];
        $this->assertSame('>', $r->operator);
        $this->assertSame('out.txt', $r->target->text);
        $this->assertNull($r->fileDescriptor);
    }

    public function testRedirectAppendWithFd(): void
    {
        $r = parse('cmd 2>> err.log')->commands[0]->command->redirects[0];
        $this->assertSame('>>', $r->operator);
        $this->assertSame(2, $r->fileDescriptor);
        $this->assertSame('err.log', $r->target->text);
    }

    public function testRedirectFdDup(): void
    {
        $r = parse('cmd 2>&1')->commands[0]->command->redirects[0];
        $this->assertSame('>&', $r->operator);
        $this->assertSame(2, $r->fileDescriptor);
        $this->assertSame('1', $r->target->text);
    }

    public function testVariableFdRedirect(): void
    {
        $r = parse('exec {fd}> file')->commands[0]->command->redirects[0];
        $this->assertSame('fd', $r->variableName);
        $this->assertSame('>', $r->operator);
    }

    public function testHereString(): void
    {
        $r = parse('cat <<< "hello"')->commands[0]->command->redirects[0];
        $this->assertSame('<<<', $r->operator);
        $this->assertSame('"hello"', $r->target->text);
    }

    public function testHeredoc(): void
    {
        $r = parse("cat <<EOF\nhello \$NAME\nEOF\n")->commands[0]->command->redirects[0];
        $this->assertSame('<<', $r->operator);
        $this->assertFalse($r->heredocQuoted);
        $this->assertSame("hello \$NAME\n", $r->body->value);
        $types = array_map(static fn (Node $p) => $p->type, $r->body->parts);
        $this->assertSame(['Literal', 'SimpleExpansion', 'Literal'], $types);
    }

    public function testHeredocQuotedIsLiteral(): void
    {
        $r = parse("cat <<'EOF'\n\$x is literal\nEOF\n")->commands[0]->command->redirects[0];
        $this->assertTrue($r->heredocQuoted);
        $this->assertNull($r->body->parts);
        $this->assertSame("\$x is literal\n", $r->body->value);
    }

    public function testHeredocDashStripsTabs(): void
    {
        $r = parse("cat <<-END\n\t\tindented\n\tEND\n")->commands[0]->command->redirects[0];
        $this->assertSame('<<-', $r->operator);
        $this->assertStringContainsString('indented', $r->body->value);
    }

    public function testSubshell(): void
    {
        $s = parse('(cd /tmp && ls)')->commands[0]->command;
        $this->assertSame('Subshell', $s->type);
        $this->assertSame('CompoundList', $s->body->type);
        $this->assertSame('AndOr', $s->body->commands[0]->command->type);
    }

    public function testBraceGroup(): void
    {
        $b = parse('{ echo a; echo b; }')->commands[0]->command;
        $this->assertSame('BraceGroup', $b->type);
        $this->assertCount(2, $b->body->commands);
    }

    public function testIfElifElse(): void
    {
        $node = parse('if a; then b; elif c; then d; else e; fi')->commands[0]->command;
        $this->assertSame('If', $node->type);
        $this->assertSame('If', $node->else->type);
        $this->assertSame('CompoundList', $node->else->else->type);
    }

    public function testForIn(): void
    {
        $f = parse('for i in a b c; do echo $i; done')->commands[0]->command;
        $this->assertSame('For', $f->type);
        $this->assertSame('i', $f->name->text);
        $this->assertCount(3, $f->wordlist);
    }

    public function testCStyleFor(): void
    {
        $f = parse('for ((i=0; i<10; i++)); do echo $i; done')->commands[0]->command;
        $this->assertSame('ArithmeticFor', $f->type);
        $this->assertNotNull($f->initialize);
        $this->assertNotNull($f->test);
        $this->assertNotNull($f->update);
    }

    public function testWhileUntil(): void
    {
        $w = parse('while true; do echo x; done')->commands[0]->command;
        $this->assertSame('While', $w->type);
        $this->assertSame('while', $w->kind);

        $u = parse('until false; do echo x; done')->commands[0]->command;
        $this->assertSame('until', $u->kind);
    }

    public function testCase(): void
    {
        $c = parse('case $x in a) echo 1;; b|c) echo 2;; *) echo 3;; esac')->commands[0]->command;
        $this->assertSame('Case', $c->type);
        $this->assertCount(3, $c->items);
        $this->assertSame(';;', $c->items[0]->terminator);
        $this->assertSame(['b', 'c'], array_map(static fn (Word $w) => $w->text, $c->items[1]->pattern));
    }

    public function testSelect(): void
    {
        $s = parse('select opt in a b c; do echo $opt; done')->commands[0]->command;
        $this->assertSame('Select', $s->type);
        $this->assertSame('opt', $s->name->text);
    }

    public function testFunctionParenForm(): void
    {
        $f = parse('greet() { echo hi; }')->commands[0]->command;
        $this->assertSame('Function', $f->type);
        $this->assertSame('greet', $f->name->text);
        $this->assertSame('BraceGroup', $f->body->type);
    }

    public function testFunctionKeywordForm(): void
    {
        $f = parse('function greet { echo hi; }')->commands[0]->command;
        $this->assertSame('Function', $f->type);
        $this->assertSame('greet', $f->name->text);
    }

    public function testTestCommand(): void
    {
        $t = parse('[[ -f foo && $x == bar ]]')->commands[0]->command;
        $this->assertSame('TestCommand', $t->type);
        $this->assertSame('TestLogical', $t->expression->type);
        $this->assertSame('&&', $t->expression->operator);
        $this->assertSame('-f', $t->expression->left->operator);
        $this->assertSame('==', $t->expression->right->operator);
    }

    public function testArithmeticCommand(): void
    {
        $a = parse('(( x = 1 + 2 * 3 ))')->commands[0]->command;
        $this->assertSame('ArithmeticCommand', $a->type);
        $this->assertSame('=', $a->expression->operator);
        $this->assertSame('+', $a->expression->right->operator);
        $this->assertSame('*', $a->expression->right->right->operator);
    }

    public function testCompoundRedirect(): void
    {
        $stmt = parse('{ echo a; } > out.log')->commands[0];
        $this->assertSame('BraceGroup', $stmt->command->type);
        $this->assertCount(1, $stmt->redirects);
        $this->assertSame('out.log', $stmt->redirects[0]->target->text);
    }
}
