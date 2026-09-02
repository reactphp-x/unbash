<?php

declare(strict_types=1);

namespace ReactphpX\Unbash\Tests;

use PHPUnit\Framework\TestCase;

use function ReactphpX\Unbash\parse;

/**
 * Regression tests for tricky constructs found in real-world scripts
 * (nvm-install, rustup-init, neofetch, git-completion).
 */
final class RealWorldTest extends TestCase
{
    public function testWholeScriptBraceGroupWrapper(): void
    {
        // nvm-install.sh wraps the whole script in { ... } so it runs only when
        // fully downloaded.
        $ast = parse("{\nfoo() { echo hi; }\nbar\n} # guarded");
        $this->assertSame([], $ast->errors);
        $this->assertCount(1, $ast->commands);
        $this->assertSame('BraceGroup', $ast->commands[0]->command->type);
    }

    public function testBraceFormForLoop(): void
    {
        // Bash `for ((...)) { ...; }` alternative to do/done (used by neofetch).
        $ast = parse('for ((i = 0; i < 10; i++)) { echo $i; }');
        $this->assertSame([], $ast->errors);
        $f = $ast->commands[0]->command;
        $this->assertSame('ArithmeticFor', $f->type);
        $this->assertSame('CompoundList', $f->body->type);
        $this->assertCount(1, $f->body->commands);
    }

    public function testBraceFormForIn(): void
    {
        $ast = parse('for x in a b c { echo $x; }');
        $this->assertSame([], $ast->errors);
        $this->assertSame('For', $ast->commands[0]->command->type);
    }

    public function testTestRegexWithParens(): void
    {
        // `=~` right-hand side is a regex: parens/pipes are regex syntax.
        $ast = parse('[[ $os =~ (AIX|IRIX) ]] && echo yes');
        $this->assertSame([], $ast->errors);
        $test = $ast->commands[0]->command->commands[0];
        $this->assertSame('TestCommand', $test->type);
        $this->assertSame('=~', $test->expression->operator);
        $this->assertSame('(AIX|IRIX)', $test->expression->right->text);
    }

    public function testArithmeticWithQuotedParen(): void
    {
        // A quoted ')' inside $(( ... $(...) ... )) must not desync the scan.
        $ast = parse('n=$(($(awk -F "\\\\/ |)" \'{print $2}\' file) / 1024))');
        $this->assertSame([], $ast->errors);
        $asg = $ast->commands[0]->command->prefix[0];
        $this->assertSame('n', $asg->name);
        $this->assertSame('ArithmeticExpansion', $asg->value->parts[0]->type);
    }

    public function testTestLineContinuationAfterOperator(): void
    {
        $ast = parse("[[ -f a ||\n   -f b ]]");
        $this->assertSame([], $ast->errors);
        $this->assertSame('TestLogical', $ast->commands[0]->command->expression->type);
    }

    public function testDeeplyNestedCommandSubstitution(): void
    {
        $ast = parse('a=$(b=$(c=$(echo deep)))');
        $this->assertSame([], $ast->errors);
        $this->assertSame('CommandExpansion', $ast->commands[0]->command->prefix[0]->value->parts[0]->type);
    }
}
